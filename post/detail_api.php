<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$postId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($postId <= 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => '无效的帖子编号。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT
        p.*,
        u.username,
        u.role AS user_role,
        u.school AS user_school,
        u.phone AS user_phone
     FROM posts p
     JOIN users u ON u.id = p.user_id
     WHERE p.id = :id AND p.status <> :deleted
     LIMIT 1'
);
$stmt->execute([
    ':id' => $postId,
    ':deleted' => 'deleted',
]);
$post = $stmt->fetch();

if (!$post) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => '帖子不存在或已删除。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$periodMap = [
    'short' => '6个月以下',
    'medium' => '6个月-1年',
    'long' => '1年及以上',
];
$genderMap = ['male' => '仅限男生', 'female' => '仅限女生', 'any' => '男女不限'];
$renewableMap = ['yes' => '可续租', 'no' => '不可续租'];
$roleLabelMap = ['landlord' => '🏢 房源供给方', 'admin' => '⚙️ 管理员', 'student' => '🎓 港硕学生'];
$roleLabel = $roleLabelMap[$post['user_role'] ?? 'student'] ?? '🎓 港硕学生';
$postType = (string) ($post['type'] ?? 'rent');
$hideContact = in_array($postType, ['roommate-source', 'roommate-nosource', 'sublet'], true);
$contactText = $hideContact
    ? '📞 如需联系，请申请'
    : (!empty($post['user_phone']) ? ('📞 联系方式：' . $post['user_phone']) : '📞 联系方式：登录用户可见');

$images = parse_post_images($post['images'] ?? null);
$fallback1 = 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=600&h=400&fit=crop';
$fallback2 = 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=300&h=200&fit=crop';
$fallback3 = 'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?w=300&h=200&fit=crop';
$imgMain = resolve_post_image_url($images[0] ?? '') ?: $fallback1;
$img1 = resolve_post_image_url($images[1] ?? '') ?: ($imgMain ?: $fallback2);
$img2 = resolve_post_image_url($images[2] ?? '') ?: ($img1 ?: $fallback3);

echo json_encode([
    'success' => true,
    'data' => [
        'id' => (int) $post['id'],
        'user_id' => (int) $post['user_id'],
        'title' => (string) ($post['title'] ?? ''),
        'type' => $postType,
        'price' => number_format((float) ($post['price'] ?? 0), 0),
        'price_label' => $postType === 'roommate-nosource' ? '预算HKD/月' : 'HKD/月',
        'region' => (string) ($post['region'] ?: '-'),
        'metro' => (string) ($post['metro_stations'] ?: '-'),
        'school' => (string) ($post['school_scope'] ?: '-'),
        'floor' => (string) ($post['floor'] ?: '-'),
        'period' => $periodMap[$post['rent_period'] ?? 'long'] ?? '1年及以上',
        'content' => (string) ($post['content'] ?: '暂无描述。'),
        'author' => (string) ($post['username'] ?: '匿名用户'),
        'author_initial' => mb_substr((string) ($post['username'] ?: '匿'), 0, 1, 'UTF-8'),
        'author_role' => $roleLabel . (!empty($post['user_school']) ? (' · ' . school_display_name((string) $post['user_school'])) : ''),
        'created_date' => !empty($post['created_at']) ? date('Y-m-d', strtotime((string) $post['created_at'])) : '',
        'contact' => $contactText,
        'gender_req' => $genderMap[$post['gender_requirement'] ?? ''] ?? '-',
        'need_count' => !empty($post['need_count']) ? ((int) $post['need_count'] . ' 人') : '-',
        'remaining_months' => !empty($post['remaining_months']) ? ((int) $post['remaining_months'] . ' 个月') : '-',
        'move_in_date' => (string) ($post['move_in_date'] ?: '-'),
        'renewable' => $renewableMap[$post['renewable'] ?? ''] ?? '-',
        'image_main' => $imgMain,
        'image_thumb1' => $img1,
        'image_thumb2' => $img2,
    ],
], JSON_UNESCAPED_UNICODE);
