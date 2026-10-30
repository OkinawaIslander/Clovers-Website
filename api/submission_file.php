<?php
/**
 * 下载 / 预览投稿文件
 * GET ?id=1&inline=1
 *
 * 已通过审核的投稿任何人都可以看；待审核/驳回的只有作者本人和管理员能看。
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);

$user = current_user($pdo);
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$st = $pdo->prepare('SELECT * FROM submissions WHERE id = ?');
$st->execute(array($id));
$row = $st->fetch();

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '文件不存在';
    exit;
}

$isOwner = $user && (int) $row['user_id'] === (int) $user['id'];
$isPublic = $row['status'] === 'accepted';
if (!$isPublic && !$isOwner && !is_admin($user)) {
    http_response_code($user ? 403 : 401);
    header('Content-Type: text/plain; charset=utf-8');
    echo $user ? '没有权限查看这个文件' : '请先登录';
    exit;
}

$path = dirname(__DIR__) . '/' . $row['file_path'];
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '文件已经被删除';
    exit;
}

$name = $row['original_name'];
$fallback = preg_replace('/[^\x20-\x7e]/', '_', $name);
if ($fallback === '' || $fallback === null) {
    $fallback = 'submission.bin';
}

$inline = isset($_GET['inline']) && $_GET['inline'] === '1';
$mime = (string) ($row['mime'] ?? '');
$previewable = ($mime !== '' && (strpos($mime, 'image/') === 0 || $mime === 'application/pdf'));

header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=0');

if ($inline && $previewable) {
    header('Content-Type: ' . $mime);
    header("Content-Disposition: inline; filename=\"{$fallback}\"; filename*=UTF-8''" . rawurlencode($name));
} else {
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename=\"{$fallback}\"; filename*=UTF-8''" . rawurlencode($name));
}

readfile($path);
