<?php
require_once __DIR__ . '/../includes/auth.php';

$user    = current_user();
$postId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$noticeType = '';
$noticeText = '';

if ($postId <= 0) {
    http_response_code(404);
    echo '无效的房源编号。';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT
        p.*,
        u.username,
        u.role   AS user_role,
        u.school AS user_school,
        u.phone  AS user_phone
     FROM posts p
     JOIN users u ON p.user_id = u.id
     WHERE p.id = :id AND p.status = :status
     LIMIT 1'
);

$stmt->execute([
    ':id'     => $postId,
    ':status' => 'active',
]);

$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    echo '房源不存在或已下架。';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) {
        header('Location: ' . project_base_url('index.php') . '?login=1');
        exit;
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $redirectUrl = project_base_url('post/detail.php?id=' . $postId);
    $role = (string) ($user['role'] ?? '');
    $canStudentActions = in_array($role, ['student', 'admin'], true);

    if ($action === 'toggle_favorite') {
        if (!$canStudentActions) {
            header('Location: ' . $redirectUrl . '&notice_type=error&notice=' . rawurlencode('仅港硕学生或管理员可收藏帖子。'));
            exit;
        }

        $checkFav = $pdo->prepare('SELECT id FROM favorites WHERE user_id = :uid AND post_id = :pid LIMIT 1');
        $checkFav->execute([
            ':uid' => (int) $user['id'],
            ':pid' => $postId,
        ]);
        $existingFav = $checkFav->fetchColumn();

        if ($existingFav) {
            $deleteFav = $pdo->prepare('DELETE FROM favorites WHERE user_id = :uid AND post_id = :pid');
            $deleteFav->execute([
                ':uid' => (int) $user['id'],
                ':pid' => $postId,
            ]);
            header('Location: ' . $redirectUrl . '&notice_type=success&notice=' . rawurlencode('已取消收藏。'));
            exit;
        }

        $insertFav = $pdo->prepare('INSERT INTO favorites (user_id, post_id) VALUES (:uid, :pid)');
        $insertFav->execute([
            ':uid' => (int) $user['id'],
            ':pid' => $postId,
        ]);
        header('Location: ' . $redirectUrl . '&notice_type=success&notice=' . rawurlencode('收藏成功。'));
        exit;
    }

    if ($action === 'send_application') {
        if (!$canStudentActions) {
            header('Location: ' . $redirectUrl . '&notice_type=error&notice=' . rawurlencode('仅港硕学生或管理员可发送申请。'));
            exit;
        }
        if ((int) $post['user_id'] === (int) $user['id']) {
            header('Location: ' . $redirectUrl . '&notice_type=error&notice=' . rawurlencode('不能申请自己的帖子。'));
            exit;
        }

        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '' || mb_strlen($message, 'UTF-8') > 500) {
            header('Location: ' . $redirectUrl . '&notice_type=error&notice=' . rawurlencode('申请留言需为 1-500 字。'));
            exit;
        }

        $pendingStmt = $pdo->prepare(
            "SELECT id FROM applications
             WHERE post_id = :pid AND applicant_user_id = :uid AND status = 'pending'
             LIMIT 1"
        );
        $pendingStmt->execute([
            ':pid' => $postId,
            ':uid' => (int) $user['id'],
        ]);
        if ($pendingStmt->fetchColumn()) {
            header('Location: ' . $redirectUrl . '&notice_type=error&notice=' . rawurlencode('你已有待处理申请，请勿重复提交。'));
            exit;
        }

        $insertApplication = $pdo->prepare(
            'INSERT INTO applications (post_id, applicant_user_id, message, status, owner_unread, applicant_result_unread)
             VALUES (:pid, :uid, :message, :status, 1, 0)'
        );
        $insertApplication->execute([
            ':pid' => $postId,
            ':uid' => (int) $user['id'],
            ':message' => $message,
            ':status' => 'pending',
        ]);

        header('Location: ' . $redirectUrl . '&notice_type=success&notice=' . rawurlencode('申请已发送。'));
        exit;
    }
}

$noticeType = trim((string) ($_GET['notice_type'] ?? ''));
$noticeText = trim((string) ($_GET['notice'] ?? ''));
$role = (string) (($user['role'] ?? '') ?: '');
$canStudentActions = $user && in_array($role, ['student', 'admin'], true);
$isFavorite = false;
if ($canStudentActions) {
    $favStmt = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = :uid AND post_id = :pid LIMIT 1');
    $favStmt->execute([
        ':uid' => (int) $user['id'],
        ':pid' => $postId,
    ]);
    $isFavorite = (bool) $favStmt->fetchColumn();
}

$price        = (float)$post['price'];
$regionText   = $post['region'] ?: '未知区域';
$schoolText   = $post['school_scope'] ?: '不限学校';
$metroText    = $post['metro_stations'] ?: '未提供地铁站';
$author       = $post['username'] ?: '匿名用户';
$authorSchool = school_display_name($post['user_school'] ?? null);
$createdAt    = $post['created_at'] ?? '';
$createdDate  = $createdAt ? date('Y-m-d', strtotime($createdAt)) : '';
$floorText    = $post['floor'] ?: '-';

$typeLabel = match($post['type']) {
    'roommate-source'   => ['class' => 'badge-roommate-source', 'text' => '🏘️ 有房找室友'],
    'roommate-nosource' => ['class' => 'badge-roommate-nosource', 'text' => '🔍 无房找室友'],
    'sublet'            => ['class' => 'badge-sublet', 'text' => '🔁 转租'],
    default             => ['class' => 'badge-rent', 'text' => '🏠 租房'],
};
$priceLabel          = $post['type'] === 'roommate-nosource' ? '月预算 HKD' : 'HKD/月';
$genderMap           = ['male' => '仅限男生', 'female' => '仅限女生', 'any' => '男女不限'];
$genderText          = $genderMap[$post['gender_requirement'] ?? ''] ?? '-';
$needCount           = ($post['need_count'] ?? '') ? $post['need_count'] . ' 人' : '-';
$remainingMonthsText = ($post['remaining_months'] ?? '') ? ((int)$post['remaining_months']) . ' 个月' : '-';
$moveInDateText      = $post['move_in_date'] ?: '-';
$renewableMap        = ['yes' => '可续租', 'no' => '不可续租'];
$renewableText       = $renewableMap[$post['renewable'] ?? ''] ?? '-';
$isRoommateNoSource  = $post['type'] === 'roommate-nosource';
$isRoommateSource    = $post['type'] === 'roommate-source';
$isRoommate          = $isRoommateSource || $isRoommateNoSource;
$isSublet            = $post['type'] === 'sublet';

switch ($post['rent_period']) {
    case 'short':
        $periodLabel = '6个月以下';
        break;
    case 'medium':
        $periodLabel = '6个月-1年';
        break;
    case 'long':
    default:
        $periodLabel = '1年及以上';
        break;
}

$roleLabelMap = ['landlord' => '🏢 房源供给方', 'admin' => '⚙️ 管理员', 'student' => '🎓 港硕学生'];
$roleLabel = $roleLabelMap[$post['user_role'] ?? 'student'] ?? '🎓 港硕学生';
$images = parse_post_images($post['images'] ?? null);
$fallback1 = 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=600&h=400&fit=crop';
$fallback2 = 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=300&h=200&fit=crop';
$fallback3 = 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=300&h=200&fit=crop';
$detailMainImage = resolve_post_image_url($images[0] ?? '') ?: $fallback1;
$detailThumb1 = resolve_post_image_url($images[1] ?? '') ?: ($detailMainImage ?: $fallback2);
$detailThumb2 = resolve_post_image_url($images[2] ?? '') ?: ($detailThumb1 ?: $fallback3);

include __DIR__ . '/../includes/header.php';
?>

<main class="main-container">
    <section class="detail-modal detail-modal-card">
        <?php if ($noticeText !== '' && in_array($noticeType, ['success', 'error'], true)): ?>
            <div class="detail-notice <?php echo $noticeType === 'success' ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($noticeText); ?>
            </div>
        <?php endif; ?>
        <?php if (!$isRoommateNoSource): ?>
        <div class="detail-gallery">
            <div class="detail-main-image">
                <img
                    src="<?php echo htmlspecialchars($detailMainImage); ?>"
                    alt="<?php echo htmlspecialchars($post['title']); ?>"
                >
            </div>
            <div class="detail-thumb-group">
                <div class="detail-thumb">
                    <img src="<?php echo htmlspecialchars($detailThumb1); ?>" alt="房源图片">
                </div>
                <div class="detail-thumb">
                    <img src="<?php echo htmlspecialchars($detailThumb2); ?>" alt="房源图片">
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="detail-info">
            <div class="detail-badge-row">
                <span class="card-badge <?php echo $typeLabel['class']; ?>"><?php echo $typeLabel['text']; ?></span>
                <div class="card-tags">
                    <span class="tag tag-region"><?php echo htmlspecialchars($regionText); ?></span>
                    <span class="tag tag-metro">🚇 <?php echo htmlspecialchars($metroText); ?></span>
                    <span class="tag tag-school"><?php echo htmlspecialchars($schoolText); ?></span>
                </div>
            </div>
            <h3 class="detail-title"><?php echo htmlspecialchars($post['title']); ?></h3>
            <div class="detail-price">
                <?php echo number_format($price, 0); ?>
                <span class="detail-price-unit"><?php echo $priceLabel; ?></span>
            </div>

            <div class="detail-meta">
                <?php if (!$isRoommateNoSource): ?>
                <div class="meta-item">
                    <div class="meta-icon">📍</div>
                    <div>
                        <div class="meta-label">楼层</div>
                        <div class="meta-value"><?php echo htmlspecialchars($floorText); ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!$isSublet): ?>
                <div class="meta-item">
                    <div class="meta-icon">📅</div>
                    <div>
                        <div class="meta-label">租期</div>
                        <div class="meta-value"><?php echo htmlspecialchars($periodLabel); ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($isRoommate || $isSublet): ?>
                <div class="meta-item">
                    <div class="meta-icon">👤</div>
                    <div>
                        <div class="meta-label">性别要求</div>
                        <div class="meta-value"><?php echo htmlspecialchars($genderText); ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($isSublet): ?>
                <div class="meta-item">
                    <div class="meta-icon">⏳</div>
                    <div>
                        <div class="meta-label">剩余租期</div>
                        <div class="meta-value"><?php echo htmlspecialchars($remainingMonthsText); ?></div>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon">🗓️</div>
                    <div>
                        <div class="meta-label">最早入住日期</div>
                        <div class="meta-value"><?php echo htmlspecialchars($moveInDateText); ?></div>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon">♻️</div>
                    <div>
                        <div class="meta-label">是否可续租</div>
                        <div class="meta-value"><?php echo htmlspecialchars($renewableText); ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($isRoommateSource): ?>
                <div class="meta-item">
                    <div class="meta-icon">👥</div>
                    <div>
                        <div class="meta-label">需求人数</div>
                        <div class="meta-value"><?php echo htmlspecialchars($needCount); ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="meta-item">
                    <div class="meta-icon">🏫</div>
                    <div>
                        <div class="meta-label">学校范围</div>
                        <div class="meta-value"><?php echo htmlspecialchars($schoolText); ?></div>
                    </div>
                </div>
                <div class="meta-item">
                    <div class="meta-icon">🚇</div>
                    <div>
                        <div class="meta-label">地铁站</div>
                        <div class="meta-value"><?php echo htmlspecialchars($metroText); ?></div>
                    </div>
                </div>
            </div>

            <div class="detail-desc">
                <?php echo nl2br(htmlspecialchars($post['content'])); ?>
            </div>

            <div class="detail-author">
                <div class="author-info">
                    <div class="author-detail-avatar">
                        <?php echo htmlspecialchars(mb_substr($author, 0, 1, 'UTF-8')); ?>
                    </div>
                    <div>
                        <div class="author-detail-name">
                            <?php echo htmlspecialchars($author); ?>
                        </div>
                        <div class="author-detail-role">
                            <?php
                            $extra = $authorSchool ? ' · ' . $authorSchool : '';
                            echo htmlspecialchars($roleLabel . $extra);
                            ?>
                        </div>
                    </div>
                </div>
                <div class="text-right">
                    <?php if ($createdDate): ?>
                        <div class="text-sm-muted">
                            发布于 <?php echo htmlspecialchars($createdDate); ?>
                        </div>
                    <?php endif; ?>
                    <div class="text-primary-strong">
                        <?php if (($post['type'] ?? 'rent') !== 'rent'): ?>
                            📞 如需联系，请申请
                        <?php elseif ($user): ?>
                            <?php if ($post['user_phone']): ?>
                                📞 联系方式：<?php echo htmlspecialchars($post['user_phone']); ?>
                            <?php else: ?>
                                📞 联系方式：登录用户可见
                            <?php endif; ?>
                        <?php else: ?>
                            📞 登录后查看联系方式
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="detail-actions">
            <?php if (!$user): ?>
                <a class="btn btn-outline" href="<?php echo htmlspecialchars(project_base_url('index.php?login=1')); ?>">登录后收藏</a>
                <a class="btn btn-primary" href="<?php echo htmlspecialchars(project_base_url('index.php?login=1')); ?>">登录后申请</a>
            <?php elseif (!$canStudentActions): ?>
                <button class="btn btn-outline" type="button" disabled>🤍 收藏（学生/管理员可用）</button>
                <button class="btn btn-primary" type="button" disabled>发送申请（学生/管理员可用）</button>
            <?php elseif ((int) $user['id'] === (int) $post['user_id']): ?>
                <button class="btn btn-outline" type="button" disabled>自己的帖子</button>
                <button class="btn btn-primary" type="button" disabled>自己的帖子</button>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="toggle_favorite">
                    <button class="btn btn-outline" type="submit"><?php echo $isFavorite ? '❤️ 已收藏' : '🤍 收藏'; ?></button>
                </form>
                <button class="btn btn-primary" type="button" onclick="openModal('applyModal')">发送申请</button>
            <?php endif; ?>
        </div>
    </section>

    <div class="modal-overlay" id="applyModal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">发送申请</h2>
                <button class="modal-close" onclick="closeModal('applyModal')">×</button>
            </div>
            <div class="modal-body">
                <form id="applyForm" method="post">
                    <input type="hidden" name="action" value="send_application">
                    <div class="form-group">
                        <label class="form-label">申请理由 <span class="required">*</span></label>
                        <textarea class="form-input no-resize" name="message" rows="4" placeholder="请简要介绍自己，说明租房意向（500字以内）" maxlength="500" required></textarea>
                        <div class="form-hint">最多500字</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">提交申请</button>
                </form>
            </div>
        </div>
    </div>
</main>

<style>
.detail-notice { margin: 0 0 14px; padding: 10px 12px; border-radius: 8px; font-size: 14px; }
.detail-notice.success { background: #e7f8ef; color: #207a47; }
.detail-notice.error { background: #fff2f2; color: #b02626; }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>

