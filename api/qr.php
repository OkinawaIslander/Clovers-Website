<?php
/**
 * 部门 QQ 群二维码图片代理
 * 用法：api/qr.php?d=xuanbian|zhaiwu|wota|zhuoyou
 *
 * 原始图片是 PNG，但文件名是 .jpg，直接用 <img> 引用时部分浏览器会因为
 * MIME 类型不匹配出问题，所以这里统一按 PNG 输出，并做 7 天缓存。
 */
$map = array(
    'xuanbian' => 'images/宣编部.jpg',
    'zhaiwu'   => 'images/宅舞部.jpg',
    'wota'     => 'images/wota艺部.jpg',
    'zhuoyou'  => 'images/桌游部.jpg',
);

$d = isset($_GET['d']) ? (string) $_GET['d'] : '';
if (!isset($map[$d])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'not found';
    exit;
}

$file = dirname(__DIR__) . '/' . $map[$d];
if (!is_file($file) || !is_readable($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'image missing';
    exit;
}

$mtime = filemtime($file);
$etag = '"' . md5($file . $mtime) . '"';

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Content-Length: ' . filesize($file));
readfile($file);
