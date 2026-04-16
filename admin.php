<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/includes/admin_actions.php';
}

require_admin();

$user = current_user();
$adminUserId = (int) ($user['id'] ?? 0);
$adminApplicantUnread = 0;
$adminOwnerUnread = 0;
if ($user) {
    $adminUnreadStmt = $pdo->prepare(
        "SELECT
            (
                SELECT COUNT(*)
                FROM applications
                WHERE applicant_user_id = :uid_applicant AND applicant_result_unread = 1
            ) AS applicant_unread_count,
            (
                SELECT COUNT(*)
                FROM applications a
                INNER JOIN posts p ON p.id = a.post_id
                WHERE p.user_id = :uid_owner AND a.owner_unread = 1
            ) AS owner_unread_count"
    );
    $adminUnreadStmt->execute([
        ':uid_applicant' => (int) $user['id'],
        ':uid_owner' => (int) $user['id'],
    ]);
    $adminUnreadRow = $adminUnreadStmt->fetch(PDO::FETCH_ASSOC);
    if ($adminUnreadRow) {
        $adminApplicantUnread = (int) ($adminUnreadRow['applicant_unread_count'] ?? 0);
        $adminOwnerUnread = (int) ($adminUnreadRow['owner_unread_count'] ?? 0);
    }
}

$section = isset($_GET['section']) ? trim((string) $_GET['section']) : 'dashboard';
if (!in_array($section, ['dashboard', 'posts', 'users'], true)) {
    $section = 'dashboard';
}

$flash = $_SESSION['admin_flash'] ?? null;
if (isset($_SESSION['admin_flash'])) {
    unset($_SESSION['admin_flash']);
}

function admin_post_type_label(string $type): string
{
    return match ($type) {
        'rent'               => '🏠 租房',
        'roommate-source'    => '🏘️ 有房找室友',
        'roommate-nosource'  => '🧭 无房找室友',
        'sublet'             => '🔄 转租',
        default              => $type,
    };
}

function admin_post_status_label(string $status): string
{
    return match ($status) {
        'active'  => '正常',
        'hidden'  => '已隐藏',
        'deleted' => '已删除',
        default   => $status,
    };
}

function admin_role_label(string $role): string
{
    return match ($role) {
        'student'  => '港硕学生',
        'landlord' => '房源供给方',
        'admin'    => '管理员',
        default    => $role,
    };
}

function admin_user_status_label(string $status): string
{
    return $status === 'banned' ? '已封禁' : '正常';
}

/**
 * 生成分页链接 HTML（上一页 / 页码 / 下一页，页码过多时折叠）。
 *
 * @param array<string, scalar|null> $baseQuery 除 page 外的 GET 参数
 */
function admin_render_pagination(int $currentPage, int $totalPages, array $baseQuery): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $currentPage = max(1, min($currentPage, $totalPages));
    $makeUrl = static function (int $p) use ($baseQuery): string {
        $q = $baseQuery;
        $q['page'] = $p;

        return project_base_url('admin.php?' . http_build_query($q));
    };

    $window = 2;
    $range = [];
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i === 1 || $i === $totalPages || ($i >= $currentPage - $window && $i <= $currentPage + $window)) {
            $range[] = $i;
        }
    }

    $withEllipsis = [];
    $prev = 0;
    foreach ($range as $p) {
        if ($prev && $p - $prev > 1) {
            $withEllipsis[] = null;
        }
        $withEllipsis[] = $p;
        $prev = $p;
    }

    $parts = [];
    if ($currentPage > 1) {
        $parts[] = '<a class="admin-page-link admin-page-link--nav" rel="prev" href="'
            . htmlspecialchars($makeUrl($currentPage - 1)) . '">上一页</a>';
    }

    foreach ($withEllipsis as $p) {
        if ($p === null) {
            $parts[] = '<span class="admin-page-ellipsis" aria-hidden="true">…</span>';
            continue;
        }
        $isCurrent = $p === $currentPage;
        $parts[] = '<a class="admin-page-link' . ($isCurrent ? ' is-current' : '') . '" '
            . ($isCurrent ? 'aria-current="page" ' : '')
            . 'href="' . htmlspecialchars($makeUrl($p)) . '">' . $p . '</a>';
    }

    if ($currentPage < $totalPages) {
        $parts[] = '<a class="admin-page-link admin-page-link--nav" rel="next" href="'
            . htmlspecialchars($makeUrl($currentPage + 1)) . '">下一页</a>';
    }

    return '<nav class="admin-pagination" aria-label="分页">' . implode("\n", $parts) . '</nav>';
}

// —— 数据看板 —— //
$dashboardStats = [
    'total_users'       => 0,
    'total_posts'       => 0,
    'active_posts'      => 0,
    'today_posts'       => 0,
    'today_users'       => 0,
    'today_activity'    => 0,
    'users_wow_pct'     => null,
    'posts_wow_pct'     => null,
    'activity_dod_pct'  => null,
];
$dashboardTrend = [];
$dashboardTypes = [];
$dashboardTopFavorites = [];

// —— 帖子列表 —— //
$postsPage = max(1, (int) ($_GET['page'] ?? 1));
$postsPerPage = 15;
$postFilter = trim((string) ($_GET['post_filter'] ?? 'all'));
if (!in_array($postFilter, ['all', 'active', 'hidden', 'deleted'], true)) {
    $postFilter = 'all';
}
$postQ = trim((string) ($_GET['q'] ?? ''));
$postsRows = [];
$postsTotal = 0;
$postsTotalPages = 1;

// —— 用户列表 —— //
$usersPage = max(1, (int) ($_GET['page'] ?? 1));
$usersPerPage = 15;
$userFilter = trim((string) ($_GET['user_filter'] ?? 'all'));
if (!in_array($userFilter, ['all', 'active', 'banned'], true)) {
    $userFilter = 'all';
}
$userQ = trim((string) ($_GET['q'] ?? ''));
$usersRows = [];
$usersTotal = 0;
$usersTotalPages = 1;

if ($section === 'dashboard') {
    require_once __DIR__ . '/includes/admin_dashboard.php';
    $dash = admin_dashboard_fetch($pdo);
    $dashboardStats = $dash['stats'];
    $dashboardTrend = $dash['trend'];
    $dashboardTypes = $dash['types'];
    $dashboardTopFavorites = $dash['top_favorites'];
}

if ($section === 'posts') {
    $where = ['1=1'];
    $params = [];
    if ($postFilter === 'active') {
        $where[] = "p.status = 'active'";
    } elseif ($postFilter === 'hidden') {
        $where[] = "p.status = 'hidden'";
    } elseif ($postFilter === 'deleted') {
        $where[] = "p.status = 'deleted'";
    }
    if ($postQ !== '') {
        $where[] = '(p.title LIKE :pq1 OR u.username LIKE :pq2 OR u.email LIKE :pq3)';
        $params[':pq1'] = '%' . $postQ . '%';
        $params[':pq2'] = '%' . $postQ . '%';
        $params[':pq3'] = '%' . $postQ . '%';
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM posts p INNER JOIN users u ON u.id = p.user_id WHERE $whereSql"
    );
    $countStmt->execute($params);
    $postsTotal = (int) $countStmt->fetchColumn();

    $postsTotalPages = max(1, (int) ceil($postsTotal / $postsPerPage));
    if ($postsTotal > 0 && $postsPage > $postsTotalPages) {
        $postsPage = $postsTotalPages;
    }

    $offset = ($postsPage - 1) * $postsPerPage;
    $listStmt = $pdo->prepare(
        "SELECT p.id, p.type, p.title, p.status, p.created_at,
                u.username AS author_name, u.email AS author_email
         FROM posts p
         INNER JOIN users u ON u.id = p.user_id
         WHERE $whereSql
         ORDER BY p.created_at DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) {
        $listStmt->bindValue($k, $v);
    }
    $listStmt->bindValue(':lim', $postsPerPage, PDO::PARAM_INT);
    $listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $postsRows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($section === 'users') {
    $where = ['1=1'];
    $params = [];
    if ($userFilter === 'active') {
        $where[] = "u.status = 'active'";
    } elseif ($userFilter === 'banned') {
        $where[] = "u.status = 'banned'";
    }
    if ($userQ !== '') {
        $where[] = '(u.username LIKE :uq1 OR u.email LIKE :uq2)';
        $params[':uq1'] = '%' . $userQ . '%';
        $params[':uq2'] = '%' . $userQ . '%';
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
    $countStmt->execute($params);
    $usersTotal = (int) $countStmt->fetchColumn();

    $usersTotalPages = max(1, (int) ceil($usersTotal / $usersPerPage));
    if ($usersTotal > 0 && $usersPage > $usersTotalPages) {
        $usersPage = $usersTotalPages;
    }

    $offset = ($usersPage - 1) * $usersPerPage;
    $listStmt = $pdo->prepare(
        "SELECT u.id, u.username, u.email, u.phone, u.role, u.status, u.banned_until, u.created_at
         FROM users u
         WHERE $whereSql
         ORDER BY (u.role = 'admin') DESC, u.created_at DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($params as $k => $v) {
        $listStmt->bindValue($k, $v);
    }
    $listStmt->bindValue(':lim', $usersPerPage, PDO::PARAM_INT);
    $listStmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $listStmt->execute();
    $usersRows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
}

$dashboardTypesProto = [
    'rent'      => (int) ($dashboardTypes['rent'] ?? 0),
    'roommate'  => (int) (($dashboardTypes['roommate-source'] ?? 0) + ($dashboardTypes['roommate-nosource'] ?? 0)),
    'sublet'    => (int) ($dashboardTypes['sublet'] ?? 0),
];
$typeTotalForDonut = array_sum($dashboardTypesProto) ?: 1;
$cssV = filemtime(__DIR__ . '/assets/css/style.css');
$adminCssV = filemtime(__DIR__ . '/assets/css/admin.css');
$adminJsV = filemtime(__DIR__ . '/assets/js/admin.js');

?>
<!DOCTYPE html>
<html lang="zh-HK">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理 - HK RentMatch</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(project_base_url('assets/css/style.css')); ?>?v=<?php echo $cssV; ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(project_base_url('assets/css/admin.css')); ?>?v=<?php echo $adminCssV; ?>">
</head>
<body class="admin-body">
    <nav class="navbar">
        <div class="nav-container">
            <a href="<?php echo htmlspecialchars(project_base_url('index.php')); ?>" class="nav-logo">
                <div class="nav-logo-icon">🏠</div>
                <span>HK RentMatch</span>
            </a>

            <div class="nav-links">
                <a href="<?php echo htmlspecialchars(project_base_url('index.php?tab=rent')); ?>" class="nav-link">租房专区</a>
                <a href="<?php echo htmlspecialchars(project_base_url('index.php?tab=roommate')); ?>" class="nav-link">找室友专区</a>
                <a href="<?php echo htmlspecialchars(project_base_url('index.php?tab=sublet')); ?>" class="nav-link">转租专区</a>
                <a href="<?php echo htmlspecialchars(project_base_url('admin.php')); ?>" class="nav-link active">后台管理</a>
            </div>

            <div class="nav-actions">
                <div class="user-menu">
                    <div class="user-avatar-wrap">
                        <div class="user-avatar">
                            <?php echo htmlspecialchars(mb_substr((string) $user['username'], 0, 1, 'UTF-8')); ?>
                        </div>
                    </div>
                    <span class="user-nav-name"><?php echo htmlspecialchars((string) $user['username']); ?></span>
                    <div class="user-dropdown">
                        <div class="dropdown-header">
                            <div class="dropdown-name"><?php echo htmlspecialchars((string) $user['username']); ?></div>
                            <div class="dropdown-role">🛡️ 系统管理员</div>
                        </div>
                        <a href="<?php echo htmlspecialchars(project_base_url('profile.php?section=profile')); ?>" class="dropdown-item">
                            <span>👤</span> 个人中心
                        </a>
                        <a href="<?php echo htmlspecialchars(project_base_url('profile.php?section=posts')); ?>" class="dropdown-item">
                            <span>📝</span> 我的发布
                        </a>
                        <a href="<?php echo htmlspecialchars(project_base_url('profile.php?section=favorites')); ?>" class="dropdown-item">
                            <span>❤️</span> 我的收藏
                        </a>
                        <a href="<?php echo htmlspecialchars(project_base_url('profile.php?section=applications')); ?>" class="dropdown-item">
                            <?php if ($adminApplicantUnread > 0): ?>
                                <span>📋</span>
                                <span class="dropdown-item-grow">我的申请</span>
                                <span class="nav-badge dropdown-item-badge"><?php echo $adminApplicantUnread > 99 ? '99+' : (string) $adminApplicantUnread; ?></span>
                            <?php else: ?>
                                <span>📋</span> 我的申请
                            <?php endif; ?>
                        </a>
                        <a href="<?php echo htmlspecialchars(project_base_url('profile.php?section=received')); ?>" class="dropdown-item">
                            <?php if ($adminOwnerUnread > 0): ?>
                                <span>📨</span>
                                <span class="dropdown-item-grow">收到申请</span>
                                <span class="nav-badge dropdown-item-badge"><?php echo $adminOwnerUnread > 99 ? '99+' : (string) $adminOwnerUnread; ?></span>
                            <?php else: ?>
                                <span>📨</span> 收到申请
                            <?php endif; ?>
                        </a>
                        <a href="<?php echo htmlspecialchars(project_base_url('logout.php')); ?>" class="dropdown-item">
                            <span>🚪</span> 退出登录
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="admin-layout">
        <aside class="admin-sidebar" aria-label="后台导航">
            <div class="admin-side-section">
                <div class="admin-side-heading">管理面板</div>
                <nav class="admin-side-nav">
                    <a href="<?php echo htmlspecialchars(project_base_url('admin.php?section=dashboard')); ?>"
                       class="admin-side-link <?php echo $section === 'dashboard' ? 'is-active' : ''; ?>">
                        <span class="admin-side-icon">📊</span> 数据看板
                    </a>
                    <a href="<?php echo htmlspecialchars(project_base_url('admin.php?section=posts')); ?>"
                       class="admin-side-link <?php echo $section === 'posts' ? 'is-active' : ''; ?>">
                        <span class="admin-side-icon">📋</span> 帖子管理
                    </a>
                    <a href="<?php echo htmlspecialchars(project_base_url('admin.php?section=users')); ?>"
                       class="admin-side-link <?php echo $section === 'users' ? 'is-active' : ''; ?>">
                        <span class="admin-side-icon">👥</span> 用户管理
                    </a>
                </nav>
            </div>
            <div class="admin-side-section admin-side-section--links">
                <div class="admin-side-heading">快速链接</div>
                <nav class="admin-side-nav admin-side-nav--flat">
                    <a href="<?php echo htmlspecialchars(project_base_url('index.php')); ?>" class="admin-side-link admin-side-link--subtle">
                        <span class="admin-side-icon">🏠</span> 返回首页
                    </a>
                </nav>
            </div>
        </aside>

        <main class="admin-main">
            <?php if ($flash && isset($flash['message'], $flash['type'])): ?>
                <div class="admin-alert admin-alert--<?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>" role="alert">
                    <?php echo htmlspecialchars((string) $flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($section === 'dashboard'): ?>
                <?php
                $fmtWow = static function (?float $pct): string {
                    if ($pct === null) {
                        return '';
                    }
                    $arrow = $pct >= 0 ? '↑' : '↓';
                    $abs   = abs($pct);

                    return $arrow . ' ' . $abs . '% 较上周';
                };
                $fmtDod = static function (?float $pct): string {
                    if ($pct === null) {
                        return '';
                    }
                    $arrow = $pct >= 0 ? '↑' : '↓';
                    $abs   = abs($pct);

                    return $arrow . ' ' . $abs . '% 较昨日';
                };
                ?>
                <header class="admin-page-header">
                    <h1 class="admin-page-title">数据看板</h1>
                </header>

                <div class="admin-stat-grid admin-stat-grid--proto">
                    <div class="admin-stat-card">
                        <div class="admin-stat-card-head">
                            <span class="admin-stat-icon admin-stat-icon--users" aria-hidden="true"></span>
                            <span class="admin-stat-label">总用户数</span>
                        </div>
                        <div class="admin-stat-value admin-stat-value--orange"><?php echo number_format($dashboardStats['total_users']); ?></div>
                        <?php if ($dashboardStats['users_wow_pct'] !== null): ?>
                            <div class="admin-stat-trend <?php echo $dashboardStats['users_wow_pct'] >= 0 ? 'admin-stat-trend--up' : 'admin-stat-trend--down'; ?>"><?php echo htmlspecialchars($fmtWow($dashboardStats['users_wow_pct'])); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="admin-stat-card">
                        <div class="admin-stat-card-head">
                            <span class="admin-stat-icon admin-stat-icon--posts" aria-hidden="true"></span>
                            <span class="admin-stat-label">总帖子数</span>
                        </div>
                        <div class="admin-stat-value admin-stat-value--blue"><?php echo number_format($dashboardStats['total_posts']); ?></div>
                        <div class="admin-stat-hint">其中正常 <?php echo number_format($dashboardStats['active_posts']); ?> 条</div>
                        <?php if ($dashboardStats['posts_wow_pct'] !== null): ?>
                            <div class="admin-stat-trend <?php echo $dashboardStats['posts_wow_pct'] >= 0 ? 'admin-stat-trend--up' : 'admin-stat-trend--down'; ?>"><?php echo htmlspecialchars($fmtWow($dashboardStats['posts_wow_pct'])); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="admin-stat-card">
                        <div class="admin-stat-card-head">
                            <span class="admin-stat-icon admin-stat-icon--fire" aria-hidden="true"></span>
                            <span class="admin-stat-label">今日活跃</span>
                        </div>
                        <div class="admin-stat-value admin-stat-value--green"><?php echo number_format($dashboardStats['today_activity']); ?></div>
                        <div class="admin-stat-hint">今日新帖 + 今日新注册</div>
                        <?php if ($dashboardStats['activity_dod_pct'] !== null): ?>
                            <div class="admin-stat-trend <?php echo $dashboardStats['activity_dod_pct'] >= 0 ? 'admin-stat-trend--up' : 'admin-stat-trend--down'; ?>"><?php echo htmlspecialchars($fmtDod($dashboardStats['activity_dod_pct'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="admin-panels-row">
                    <section class="admin-panel">
                        <h2 class="admin-panel-title">近7天新增帖子趋势</h2>
                        <div class="admin-bar-chart" role="img" aria-label="近7天新增帖子柱状图">
                            <?php
                            $maxTrend = max(1, ...array_column($dashboardTrend, 'count'));
                            foreach ($dashboardTrend as $point):
                                $h = round(($point['count'] / $maxTrend) * 100);
                                $label = substr($point['date'], 5);
                                ?>
                                <div class="admin-bar-item">
                                    <div class="admin-bar-track">
                                        <div class="admin-bar-fill" style="height: <?php echo $h; ?>%;"></div>
                                        <span class="admin-bar-count" style="bottom: calc(<?php echo $h; ?>% + 10px);"><?php echo (int) $point['count']; ?></span>
                                    </div>
                                    <span class="admin-bar-date"><?php echo htmlspecialchars($label); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="admin-panel">
                        <h2 class="admin-panel-title">帖子类型分布</h2>
                        <?php
                        $typeColorsProto = [
                            'rent'               => '#FF6B35',
                            'roommate-source'    => '#4ECDC4',
                            'sublet'             => '#A66CFF',
                            'roommate-nosource'  => '#6C5CE7',
                        ];
                        $typeLabelsProto = [
                            'rent'               => '租房',
                            'roommate-source'    => '找室友',
                            'sublet'             => '转租',
                            'roommate-nosource'  => '无房找室友',
                        ];
                        $segments = [];
                        $typesOrderProto = ['rent', 'roommate-source', 'sublet', 'roommate-nosource'];
                        foreach ($typesOrderProto as $tk) {
                            $cnt = (int) ($dashboardTypes[$tk] ?? 0);
                            if ($cnt <= 0) {
                                continue;
                            }
                            $pct = ($cnt / $typeTotalForDonut) * 100;
                            $color = $typeColorsProto[$tk] ?? '#636E72';
                            $segments[] = ['color' => $color, 'pct' => $pct, 'key' => $tk, 'cnt' => $cnt];
                        }
                        $gradParts = [];
                        $start = 0;
                        foreach ($segments as $seg) {
                            $end = $start + $seg['pct'];
                            $gradParts[] = $seg['color'] . ' ' . $start . '% ' . $end . '%';
                            $start = $end;
                        }
                        $gradCss = $gradParts !== [] ? 'conic-gradient(' . implode(', ', $gradParts) . ')' : '#eceff1';
                        ?>
                        <div class="admin-donut-wrap">
                            <div class="admin-donut-ring">
                                <div class="admin-donut" style="background: <?php echo htmlspecialchars($gradCss); ?>;"></div>
                                <div class="admin-donut-center">
                                    <span class="admin-donut-center-num"><?php echo number_format($dashboardStats['total_posts']); ?></span>
                                    <span class="admin-donut-center-label">总帖子</span>
                                </div>
                            </div>
                            <ul class="admin-donut-legend">
                                <?php foreach ($typesOrderProto as $tk):
                                    $cnt = (int) ($dashboardTypes[$tk] ?? 0);
                                    ?>
                                    <li>
                                        <span class="admin-legend-dot" style="background:<?php echo htmlspecialchars($typeColorsProto[$tk] ?? '#999'); ?>"></span>
                                        <?php echo htmlspecialchars($typeLabelsProto[$tk] ?? $tk); ?>
                                        <strong><?php echo number_format($cnt); ?></strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </section>
                </div>

                <section class="admin-panel--full">
                    <h2 class="admin-panel-title">收藏量 Top 10 帖子</h2>
                    <?php if ($dashboardTopFavorites === []): ?>
                        <p class="admin-empty-hint">暂无收藏数据或 favorites 表未就绪。</p>
                    <?php else: ?>
                        <?php foreach ($dashboardTopFavorites as $idx => $fr): ?>
                            <?php
                            $rankCls = match (true) {
                                $idx === 0 => 'admin-top-rank--1',
                                $idx === 1 => 'admin-top-rank--2',
                                $idx === 2 => 'admin-top-rank--3',
                                default    => 'admin-top-rank--other',
                            };
                            ?>
                            <div class="admin-top-list-item">
                                <div class="admin-top-rank <?php echo $rankCls; ?>"><?php echo (int) ($idx + 1); ?></div>
                                <div class="admin-top-title-wrap">
                                    <div class="admin-top-title"><?php echo htmlspecialchars(mb_strimwidth((string) $fr['title'], 0, 52, '…', 'UTF-8')); ?></div>
                                    <div class="admin-top-meta"><?php echo htmlspecialchars(admin_post_type_label((string) $fr['type'])); ?></div>
                                </div>
                                <div class="admin-top-fav">❤️ <?php echo number_format((int) $fr['fav_count']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

            <?php elseif ($section === 'posts'): ?>
                <header class="admin-page-header admin-page-header--row">
                    <div>
                        <h1 class="admin-page-title">帖子管理</h1>
                    </div>
                </header>

                <form class="admin-filters" method="get" action="<?php echo htmlspecialchars(project_base_url('admin.php')); ?>">
                    <input type="hidden" name="section" value="posts">
                    <div class="admin-filter-group admin-filter-group--chips">
                        <?php
                        $postFilterLinks = [
                            'all'     => '全部',
                            'active'  => '正常',
                            'hidden'  => '已隐藏',
                            'deleted' => '已删除',
                        ];
                        foreach ($postFilterLinks as $filterKey => $filterLabel):
                            $isActiveFilter = $postFilter === $filterKey;
                            $filterUrl = project_base_url('admin.php?' . http_build_query([
                                'section'     => 'posts',
                                'post_filter' => $filterKey,
                                'q'           => $postQ,
                            ]));
                            ?>
                            <a href="<?php echo htmlspecialchars($filterUrl); ?>" class="admin-filter-chip <?php echo $isActiveFilter ? 'is-active' : ''; ?>">
                                <?php echo htmlspecialchars($filterLabel); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="admin-filter-grow admin-search-wrap">
                        <span class="admin-search-icon" aria-hidden="true">🔍</span>
                        <input class="admin-input" type="search" name="q" id="q_posts" value="<?php echo htmlspecialchars($postQ); ?>"
                               placeholder="搜索标题、发布者...">
                    </div>
                    <input type="hidden" name="post_filter" value="<?php echo htmlspecialchars($postFilter); ?>">
                    <button type="submit" class="btn btn-primary admin-filter-btn">搜索</button>
                </form>

                <form method="post" action="<?php echo htmlspecialchars(project_base_url('admin.php')); ?>" class="admin-batch-form" id="adminPostsBatchForm">
                    <input type="hidden" name="admin_action" id="adminPostsBatchAction" value="">
                    <input type="hidden" name="redirect_section" value="posts">
                    <input type="hidden" name="redirect_page" value="<?php echo (int) $postsPage; ?>">
                    <input type="hidden" name="redirect_post_filter" value="<?php echo htmlspecialchars($postFilter); ?>">
                    <input type="hidden" name="redirect_q" value="<?php echo htmlspecialchars($postQ); ?>">

                    <div class="admin-batch-bar" id="adminBatchBar" hidden>
                        <span class="admin-batch-count" id="adminBatchCount">已选 0 条</span>
                        <button type="button" class="btn btn-outline btn-small" id="adminBatchHideBtn">下架选中</button>
                        <button type="button" class="btn btn-outline btn-small" id="adminBatchRestoreBtn">恢复选中</button>
                    </div>

                    <div class="admin-table-wrap">
                        <table class="admin-table admin-table--posts">
                            <thead>
                            <tr>
                                <th class="admin-th-check"><input type="checkbox" id="adminCheckAllPosts" aria-label="全选"></th>
                                <th>类型</th>
                                <th>标题</th>
                                <th>发布者</th>
                                <th>创建时间</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($postsRows as $pr): ?>
                                <?php
                                $st = (string) $pr['status'];
                                $tp = (string) $pr['type'];
                                $canHide = $st === 'active';
                                $canRestore = $st === 'hidden' || $st === 'deleted';
                                $typeCls = match ($tp) {
                                    'rent'              => 'admin-type-pill--rent',
                                    'sublet'            => 'admin-type-pill--sublet',
                                    'roommate-source'   => 'admin-type-pill--roommate',
                                    'roommate-nosource' => 'admin-type-pill--roommate-nosource',
                                    default             => 'admin-type-pill--roommate',
                                };
                                ?>
                                <tr data-status="<?php echo htmlspecialchars($st); ?>">
                                    <td>
                                        <input type="checkbox" class="admin-row-check" name="post_ids[]" value="<?php echo (int) $pr['id']; ?>">
                                    </td>
                                    <td><span class="admin-type-pill admin-type-pill--sm <?php echo $typeCls; ?>"><?php echo htmlspecialchars(admin_post_type_label($tp)); ?></span></td>
                                    <td class="admin-td-title"><?php echo htmlspecialchars(mb_strimwidth((string) $pr['title'], 0, 40, '…', 'UTF-8')); ?></td>
                                    <td><?php echo htmlspecialchars((string) $pr['author_name']); ?></td>
                                    <td class="admin-td-muted"><?php echo htmlspecialchars(substr((string) $pr['created_at'], 0, 16)); ?></td>
                                    <td><span class="admin-status-badge admin-status-badge--<?php echo htmlspecialchars($st); ?>"><?php echo htmlspecialchars(admin_post_status_label($st)); ?></span></td>
                                    <td class="admin-td-actions">
                                        <?php if ($canHide): ?>
                                            <button type="button" class="btn btn-text btn-small js-admin-post-hide"
                                                    data-post-id="<?php echo (int) $pr['id']; ?>">下架</button>
                                        <?php endif; ?>
                                        <?php if ($canRestore): ?>
                                            <button type="button" class="btn btn-text btn-small js-admin-post-restore"
                                                    data-post-id="<?php echo (int) $pr['id']; ?>">恢复</button>
                                        <?php endif; ?>
                                        <?php if (!$canHide && !$canRestore): ?>
                                            <span class="admin-td-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($postsRows === []): ?>
                                <tr><td colspan="7" class="admin-empty-cell">暂无帖子</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <form method="post" id="adminPostSingleForm" action="<?php echo htmlspecialchars(project_base_url('admin.php')); ?>" class="admin-hidden">
                    <input type="hidden" name="admin_action" id="adminPostSingleAction" value="">
                    <input type="hidden" name="post_id" id="adminPostSingleId" value="">
                    <input type="hidden" name="redirect_section" value="posts">
                    <input type="hidden" name="redirect_page" value="<?php echo (int) $postsPage; ?>">
                    <input type="hidden" name="redirect_post_filter" value="<?php echo htmlspecialchars($postFilter); ?>">
                    <input type="hidden" name="redirect_q" value="<?php echo htmlspecialchars($postQ); ?>">
                </form>

                <?php
                $postsPaginationHtml = admin_render_pagination($postsPage, $postsTotalPages, ['section' => 'posts', 'post_filter' => $postFilter, 'q' => $postQ]);
                ?>
                <div class="admin-table-footer">
                    <div class="admin-table-info">共 <?php echo number_format($postsTotal); ?> 条</div>
                    <?php echo $postsPaginationHtml; ?>
                </div>

            <?php else: ?>
                <header class="admin-page-header">
                    <h1 class="admin-page-title">用户管理</h1>
                </header>

                <form class="admin-filters" method="get" action="<?php echo htmlspecialchars(project_base_url('admin.php')); ?>">
                    <input type="hidden" name="section" value="users">
                    <div class="admin-filter-group admin-filter-group--chips">
                        <?php
                        $userFilterLinks = [
                            'all'    => '全部',
                            'active' => '正常',
                            'banned' => '已封禁',
                        ];
                        foreach ($userFilterLinks as $filterKey => $filterLabel):
                            $isActiveFilter = $userFilter === $filterKey;
                            $filterUrl = project_base_url('admin.php?' . http_build_query([
                                'section'     => 'users',
                                'user_filter' => $filterKey,
                                'q'           => $userQ,
                            ]));
                            ?>
                            <a href="<?php echo htmlspecialchars($filterUrl); ?>" class="admin-filter-chip <?php echo $isActiveFilter ? 'is-active' : ''; ?>">
                                <?php echo htmlspecialchars($filterLabel); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="admin-filter-grow admin-search-wrap">
                        <span class="admin-search-icon" aria-hidden="true">🔍</span>
                        <input class="admin-input" type="search" name="q" id="q_users" value="<?php echo htmlspecialchars($userQ); ?>"
                               placeholder="搜索昵称、邮箱...">
                    </div>
                    <input type="hidden" name="user_filter" value="<?php echo htmlspecialchars($userFilter); ?>">
                    <button type="submit" class="btn btn-primary admin-filter-btn">搜索</button>
                </form>

                <form method="post" action="<?php echo htmlspecialchars(project_base_url('admin.php')); ?>" class="admin-batch-form" id="adminUsersBatchForm">
                    <input type="hidden" name="admin_action" id="adminUsersBatchActionInput" value="">
                    <input type="hidden" name="ban_duration" id="adminUsersBatchBanDuration" value="">
                    <input type="hidden" name="redirect_section" value="users">
                    <input type="hidden" name="redirect_page" value="<?php echo (int) $usersPage; ?>">
                    <input type="hidden" name="redirect_user_filter" value="<?php echo htmlspecialchars($userFilter); ?>">
                    <input type="hidden" name="redirect_user_q" value="<?php echo htmlspecialchars($userQ); ?>">

                    <div class="admin-batch-bar" id="adminUsersBatchBar" hidden>
                        <span class="admin-batch-count" id="adminUsersBatchCount">已选 0 人</span>
                        <button type="button" class="btn btn-outline btn-small" id="adminUsersBatchBanBtn">封禁选中</button>
                        <button type="button" class="btn btn-outline btn-small" id="adminUsersBatchUnbanBtn">解封选中</button>
                    </div>

                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                            <tr>
                                <th class="admin-th-check"><input type="checkbox" id="adminCheckAllUsers" aria-label="全选可批量操作的用户"></th>
                                <th>昵称</th>
                                <th>邮箱</th>
                                <th>电话</th>
                                <th>角色</th>
                                <th>注册时间</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($usersRows as $ur): ?>
                                <?php
                                $rid = (int) $ur['id'];
                                $role = (string) $ur['role'];
                                $isAdminUser = $role === 'admin';
                                $isBanned = ($ur['status'] ?? '') === 'banned';
                                $until = $ur['banned_until'] ?? null;
                                $banNote = '';
                                if ($isBanned && $until) {
                                    $banNote = '至 ' . substr((string) $until, 0, 16);
                                } elseif ($isBanned && !$until) {
                                    $banNote = '永久';
                                }
                                $canBatch = !$isAdminUser && $rid !== $adminUserId;
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($canBatch): ?>
                                            <input type="checkbox" class="admin-user-row-check" name="user_ids[]" value="<?php echo $rid; ?>">
                                        <?php else: ?>
                                            <span class="admin-td-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars((string) $ur['username']); ?>
                                        <?php if ($isAdminUser): ?>
                                            <span class="admin-mini-badge" title="管理员账号">管</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="admin-td-muted"><?php echo htmlspecialchars((string) $ur['email']); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($ur['phone'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars(admin_role_label($role)); ?></td>
                                    <td class="admin-td-muted"><?php echo htmlspecialchars(substr((string) $ur['created_at'], 0, 16)); ?></td>
                                    <td>
                                        <span class="admin-status-badge admin-status-badge--<?php echo $isBanned ? 'banned' : 'active'; ?>"><?php echo htmlspecialchars(admin_user_status_label((string) $ur['status'])); ?></span>
                                        <?php if ($isBanned && $banNote !== ''): ?>
                                            <div class="admin-ban-note"><?php echo htmlspecialchars($banNote); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="admin-td-actions">
                                        <?php if (!$isAdminUser): ?>
                                            <?php if (!$isBanned): ?>
                                                <button type="button" class="btn btn-text btn-small js-admin-user-ban"
                                                        data-user-id="<?php echo $rid; ?>"
                                                        data-username="<?php echo htmlspecialchars($ur['username'], ENT_QUOTES, 'UTF-8'); ?>">封禁</button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-text btn-small js-admin-user-unban"
                                                        data-user-id="<?php echo $rid; ?>">解封</button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="admin-td-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($usersRows === []): ?>
                                <tr><td colspan="8" class="admin-empty-cell">暂无用户</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <form method="post" id="adminUserBanForm" action="<?php echo htmlspecialchars(project_base_url('admin.php')); ?>" class="admin-hidden">
                    <input type="hidden" name="admin_action" value="user_ban">
                    <input type="hidden" name="user_id" id="adminBanUserId" value="">
                    <input type="hidden" name="ban_duration" id="adminBanDuration" value="">
                    <input type="hidden" name="redirect_section" value="users">
                    <input type="hidden" name="redirect_page" value="<?php echo (int) $usersPage; ?>">
                    <input type="hidden" name="redirect_user_filter" value="<?php echo htmlspecialchars($userFilter); ?>">
                    <input type="hidden" name="redirect_user_q" value="<?php echo htmlspecialchars($userQ); ?>">
                </form>

                <form method="post" id="adminUserUnbanForm" action="<?php echo htmlspecialchars(project_base_url('admin.php')); ?>" class="admin-hidden">
                    <input type="hidden" name="admin_action" value="user_unban">
                    <input type="hidden" name="user_id" id="adminUnbanUserId" value="">
                    <input type="hidden" name="redirect_section" value="users">
                    <input type="hidden" name="redirect_page" value="<?php echo (int) $usersPage; ?>">
                    <input type="hidden" name="redirect_user_filter" value="<?php echo htmlspecialchars($userFilter); ?>">
                    <input type="hidden" name="redirect_user_q" value="<?php echo htmlspecialchars($userQ); ?>">
                </form>

                <?php
                $usersPaginationHtml = admin_render_pagination($usersPage, $usersTotalPages, ['section' => 'users', 'user_filter' => $userFilter, 'q' => $userQ]);
                ?>
                <div class="admin-table-footer">
                    <div class="admin-table-info">共 <?php echo number_format($usersTotal); ?> 条</div>
                    <?php echo $usersPaginationHtml; ?>
                </div>

            <?php endif; ?>
        </main>
    </div>

    <!-- 帖子下架/恢复确认 -->
    <div class="admin-modal" id="adminPostModal" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="admin-modal-backdrop" data-admin-close="1"></div>
        <div class="admin-modal-card">
            <h3 class="admin-modal-title" id="adminPostModalTitle">确认操作</h3>
            <p class="admin-modal-text" id="adminPostModalText"></p>
            <div class="admin-modal-actions">
                <button type="button" class="btn btn-outline" data-admin-close="1">取消</button>
                <button type="button" class="btn btn-primary" id="adminPostModalConfirm">确定</button>
            </div>
        </div>
    </div>

    <!-- 封禁用户 -->
    <div class="admin-modal" id="adminBanModal" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="admin-modal-backdrop" data-admin-close="1"></div>
        <div class="admin-modal-card">
            <h3 class="admin-modal-title">封禁用户</h3>
            <p class="admin-modal-text" id="adminBanModalUserLine"></p>
            <div class="admin-ban-options">
                <label class="admin-radio"><input type="radio" name="ban_duration_ui" value="7" checked> 7 天</label>
                <label class="admin-radio"><input type="radio" name="ban_duration_ui" value="30"> 30 天</label>
                <label class="admin-radio"><input type="radio" name="ban_duration_ui" value="permanent"> 永久</label>
            </div>
            <div class="admin-modal-actions">
                <button type="button" class="btn btn-outline" data-admin-close="1">取消</button>
                <button type="button" class="btn btn-primary" id="adminBanModalConfirm">确认封禁</button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>
    <script>
        window.projectBaseUrl = <?php echo json_encode(rtrim(project_base_url(), '/')); ?>;
    </script>
    <script src="<?php echo htmlspecialchars(project_base_url('assets/js/main.js')); ?>?v=<?php echo filemtime(__DIR__ . '/assets/js/main.js'); ?>"></script>
    <script src="<?php echo htmlspecialchars(project_base_url('assets/js/admin.js')); ?>?v=<?php echo $adminJsV; ?>"></script>
</body>
</html>
