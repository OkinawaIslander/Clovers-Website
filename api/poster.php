<?php
/**
 * 招新海报缩略图
 * GET ?w=900
 *
 * 用 GD 把 9MB 的 PNG 海报缩成几百 KB 的 JPEG；服务器没装 GD 时直接输出原图。
 */
$w = isset($_GET['w']) ? (int) $_GET['w'] : 900;
$w = max(320, min(1400, $w));

$src = dirname(__DIR__) . '/images/海报.png';
if (!is_file($src)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'poster missing';
    exit;
}

$cacheDir = dirname(__DIR__) . '/uploads';
$cache = $cacheDir . '/poster-' . $w . '-' . filemtime($src) . '.jpg';

if (!is_file($cache) && function_exists('imagecreatefrompng') && function_exists('imagejpeg')) {
    @ini_set('memory_limit', '256M');
    $img = @imagecreatefrompng($src);
    if ($img) {
        $sw = imagesx($img);
        $sh = imagesy($img);
        $nh = (int) round($sh * ($w / $sw));
        $thumb = imagecreatetruecolor($w, $nh);
        imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $w, $nh, $sw, $sh);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @imagejpeg($thumb, $cache, 85);
        imagedestroy($thumb);
        imagedestroy($img);
    }
}

header('Cache-Control: public, max-age=604800');

if (is_file($cache)) {
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($cache));
    readfile($cache);
    exit;
}

// 没装 GD：直接返回原图
header('Content-Type: image/png');
header('Content-Length: ' . filesize($src));
readfile($src);
