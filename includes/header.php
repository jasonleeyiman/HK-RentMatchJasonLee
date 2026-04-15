<?php
require_once __DIR__ . '/auth.php';
$user = current_user();
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isHomePage = $currentPage === 'index.php';

$headerApplicantUnread = 0;
$headerOwnerUnread = 0;
if ($user) {
    $headerUnreadStmt = $pdo->prepare(
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
    $headerUnreadStmt->execute([
        ':uid_applicant' => $user['id'],
        ':uid_owner' => $user['id'],
    ]);
    $headerUnreadRow = $headerUnreadStmt->fetch();
    if ($headerUnreadRow) {
        $headerApplicantUnread = (int) $headerUnreadRow['applicant_unread_count'];
        $headerOwnerUnread = (int) $headerUnreadRow['owner_unread_count'];
    }
}
$headerTotalUnread = $headerApplicantUnread + $headerOwnerUnread;
?>
<!DOCTYPE html>
<html lang="zh-HK">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HK RentMatch - 香港研究生租房匹配平台</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(project_base_url('assets/css/style.css')); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="<?php echo htmlspecialchars(project_base_url('index.php')); ?>" class="nav-logo">
                <div class="nav-logo-icon">🏠</div>
                <span>HK RentMatch</span>
            </a>

            <div class="nav-links">
                <a href="<?php echo htmlspecialchars(project_base_url('index.php?tab=rent')); ?>"
                   class="nav-link <?php echo (!isset($_GET['tab']) || $_GET['tab'] === 'rent') && $isHomePage ? 'active' : ''; ?>"
                   data-tab="rent">租房专区</a>
                <a href="<?php echo htmlspecialchars(project_base_url('index.php?tab=roommate')); ?>"
                   class="nav-link <?php echo (isset($_GET['tab']) && in_array($_GET['tab'], ['roommate', 'roommate-source', 'roommate-nosource'], true)) && $isHomePage ? 'active' : ''; ?>"
                   data-tab="roommate">找室友专区</a>
                <a href="<?php echo htmlspecialchars(project_base_url('index.php?tab=sublet')); ?>"
                   class="nav-link <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'sublet') && $isHomePage ? 'active' : ''; ?>"
                   data-tab="sublet">转租专区</a>
                <?php if ($user && ($user['role'] ?? '') === 'admin'): ?>
                    <a href="<?php echo htmlspecialchars(project_base_url('admin.php')); ?>"
                       class="nav-link <?php echo $currentPage === 'admin.php' ? 'active' : ''; ?>">后台管理</a>
                <?php endif; ?>
            </div>

            <!-- Logged out state -->
            <div class="nav-actions <?php echo $user ? 'hidden' : ''; ?>" id="loggedOutActions">
                <?php if ($isHomePage): ?>
                    <button type="button" class="btn btn-outline" onclick="openModal('loginModal')">登录</button>
                    <button type="button" class="btn btn-primary" onclick="openModal('registerModal')">注册</button>
                <?php else: ?>
                    <a class="btn btn-outline" href="<?php echo htmlspecialchars(project_base_url('login.php')); ?>">登录</a>
                    <a class="btn btn-primary" href="<?php echo htmlspecialchars(project_base_url('register.php')); ?>">注册</a>
                <?php endif; ?>
            </div>

            <!-- Logged in state -->
            <div class="nav-actions <?php echo $user ? '' : 'hidden'; ?>" id="loggedInActions">
                <?php if ($user): ?>
                    <div class="user-menu">
                        <div class="user-avatar-wrap">
                            <div class="user-avatar" id="headerUserAvatar">
                                <?php echo htmlspecialchars(mb_substr($user['username'], 0, 1, 'UTF-8')); ?>
                            </div>
                            <?php if ($headerTotalUnread > 0): ?>
                                <span class="user-avatar-badge" title="未读提醒" aria-label="未读提醒"><?php echo $headerTotalUnread > 99 ? '99+' : (string) $headerTotalUnread; ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="user-nav-name" id="headerUserNavName"><?php echo htmlspecialchars($user['username']); ?></span>
                        <div class="user-dropdown">
                            <div class="dropdown-header">
                                <div class="dropdown-name" id="headerDropdownName">
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </div>
                                <div class="dropdown-role">
                                    <?php
                                    $roleLabel = match ($user['role'] ?? '') {
                                        'landlord' => '🏢 房源供给方',
                                        'admin'    => '🛡️ 系统管理员',
                                        default    => '🎓 港硕学生',
                                    };
                                    $schoolText = school_display_name($user['school'] ?? null);
                                    $school = (($user['role'] ?? '') === 'admin' || $schoolText === '') ? '' : (' · ' . $schoolText);
                                    echo htmlspecialchars($roleLabel . $school);
                                    ?>
                                </div>
                            </div>
                            <?php if (($user['role'] ?? '') === 'admin'): ?>
                                <a href="<?php echo htmlspecialchars(project_base_url('admin.php')); ?>" class="dropdown-item">
                                    <span>🛡️</span> 后台管理
                                </a>
                            <?php endif; ?>
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
                                <?php if ($headerApplicantUnread > 0): ?>
                                    <span>📋</span>
                                    <span class="dropdown-item-grow">我的申请</span>
                                    <span class="nav-badge dropdown-item-badge"><?php echo $headerApplicantUnread > 99 ? '99+' : (string) $headerApplicantUnread; ?></span>
                                <?php else: ?>
                                    <span>📋</span> 我的申请
                                <?php endif; ?>
                            </a>
                            <a href="<?php echo htmlspecialchars(project_base_url('profile.php?section=received')); ?>" class="dropdown-item">
                                <?php if ($headerOwnerUnread > 0): ?>
                                    <span>📨</span>
                                    <span class="dropdown-item-grow">收到申请</span>
                                    <span class="nav-badge dropdown-item-badge"><?php echo $headerOwnerUnread > 99 ? '99+' : (string) $headerOwnerUnread; ?></span>
                                <?php else: ?>
                                    <span>📨</span> 收到申请
                                <?php endif; ?>
                            </a>
                            <a href="<?php echo htmlspecialchars(project_base_url('logout.php')); ?>" class="dropdown-item">
                                <span>🚪</span> 退出登录
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

