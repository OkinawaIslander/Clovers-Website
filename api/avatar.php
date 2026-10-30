<?php
/**
 * 用户头像
 * GET ?id=1
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$st = $pdo->prepare('SELECT avatar, avatar_mime FROM users WHERE id = ?');
$st->execute(array($id));
$row = $st->fetch();

if (!$row || empty($row['avatar'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'no avatar';
    exit;
}

$path = dirname(__DIR__) . '/' . $row['avatar'];
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'avatar missing';
    exit;
}

$mime = !empty($row['avatar_mime']) ? $row['avatar_mime'] : 'image/jpeg';
$mtime = filemtime($path);
$etag = '"' . md5($row['avatar'] . $mtime) . '"';

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('Content-Length: ' . filesize($path));
readfile($path);
