<?php
/**
 * 投稿展示：只列出管理员审核通过的投稿
 * GET ?category=绘画插画
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);

$category = trim((string) ($_GET['category'] ?? ''));
$categories = defined('SUBMISSION_CATEGORIES') ? SUBMISSION_CATEGORIES : array('cosplay摄影', '文章', '绘画插画', '其他');
if ($category !== '' && !in_array($category, $categories, true)) {
    $category = '';
}

$sql = "SELECT s.*, u.nickname, u.avatar, u.avatar_mime
        FROM submissions s
        JOIN users u ON u.id = s.user_id
        WHERE s.status = 'accepted' AND u.role <> 'super'";
$params = array();
if ($category !== '') {
    $sql .= ' AND s.category = ?';
    $params[] = $category;
}
$sql .= ' ORDER BY s.updated_at DESC, s.id DESC LIMIT 120';

$st = $pdo->prepare($sql);
$st->execute($params);

$items = array();
foreach ($st->fetchAll() as $row) {
    $mime = (string) ($row['mime'] ?? '');
    $items[] = array(
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'category' => $row['category'],
        'description' => $row['description'],
        'author' => $row['nickname'],
        'avatar_url' => !empty($row['avatar'])
            ? 'api/avatar.php?id=' . (int) $row['user_id'] . '&v=' . substr(md5($row['avatar']), 0, 8)
            : null,
        'original_name' => $row['original_name'],
        'file_size' => (int) $row['file_size'],
        'mime' => $mime,
        'is_image' => strpos($mime, 'image/') === 0,
        'is_pdf' => $mime === 'application/pdf',
        'preview_url' => 'api/submission_file.php?id=' . (int) $row['id'] . '&inline=1',
        'download_url' => 'api/submission_file.php?id=' . (int) $row['id'],
        'created_at' => $row['created_at'],
        'time_ago' => time_ago($row['updated_at']),
    );
}

json_ok(array('items' => $items, 'count' => count($items)));
