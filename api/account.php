<?php
/**
 * 账户设置
 * POST JSON      : action=password / action=profile
 * POST multipart : action=avatar + file
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);
$user = require_login($pdo);
require_post();

$input = getInput();
$action = (string) ($_POST['action'] ?? $input['action'] ?? '');

if ($action === 'password') {
    $current = (string) ($input['current'] ?? '');
    $new = (string) ($input['new_password'] ?? '');

    if ($current === '') {
        json_fail('请输入当前密码');
    }
    if (strlen($new) < 6 || strlen($new) > 72) {
        json_fail('新密码长度需要 6 - 72 位');
    }

    $st = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $st->execute(array($user['id']));
    $hash = $st->fetchColumn();
    if (!$hash || !password_verify($current, $hash)) {
        json_fail('当前密码不正确');
    }

    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute(array(password_hash($new, PASSWORD_DEFAULT), $user['id']));

    session_regenerate_id(true);
    json_ok(null, '密码已修改，下次请用新密码登录');
}

if ($action === 'profile') {
    $nickname = trim((string) ($input['nickname'] ?? ''));
    $signature = trim((string) ($input['signature'] ?? ''));
    $departments = $input['departments'] ?? array();
    if (!is_array($departments)) {
        $departments = array();
    }

    if ($nickname === '' || mb_strlen($nickname) > 16) {
        json_fail('昵称不能为空且不超过 16 字');
    }
    if (mb_strlen($signature) > 30) {
        json_fail('签名不能超过 30 字');
    }

    $allowed = array('xuanbian', 'zhaiwu', 'wota', 'zhuoyou');
    $departments = array_values(array_intersect($allowed, array_map('strval', $departments)));

    $old = $user['nickname'];
    $pdo->prepare('UPDATE users SET nickname = ?, signature = ?, departments = ? WHERE id = ?')
        ->execute(array($nickname, $signature, implode(',', $departments), $user['id']));

    if ($old !== $nickname) {
        // 同步历史留言/评论/动态里保存的昵称
        $pdo->prepare('UPDATE posts SET nickname = ? WHERE user_id = ?')->execute(array($nickname, $user['id']));
        $pdo->prepare('UPDATE anime_comments SET nickname = ? WHERE user_id = ?')->execute(array($nickname, $user['id']));
        $pdo->prepare('UPDATE activities SET nickname = ? WHERE user_id = ?')->execute(array($nickname, $user['id']));
    }

    json_ok(array(
        'nickname' => $nickname,
        'signature' => $signature,
        'departments' => $departments,
    ), '资料已更新');
}

if ($action === 'avatar') {
    if (!isset($_FILES['file'])) {
        json_fail('请选择头像图片');
    }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_fail('上传失败（错误码 ' . $file['error'] . '）');
    }
    $maxMb = defined('AVATAR_MAX_MB') ? (int) AVATAR_MAX_MB : 2;
    if ($file['size'] > $maxMb * 1024 * 1024) {
        json_fail('头像不能超过 ' . $maxMb . 'MB');
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        json_fail('这个文件不是有效图片');
    }
    $allowedMime = array('image/jpeg', 'image/png', 'image/webp', 'image/gif');
    if (!in_array($info['mime'], $allowedMime, true)) {
        json_fail('只支持 JPG / PNG / WebP / GIF');
    }

    $dir = dirname(__DIR__) . '/uploads/avatars';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        json_fail('无法创建 uploads/avatars 目录，请检查目录权限', 500);
    }
    if (!is_writable($dir)) {
        json_fail('uploads/avatars 目录不可写', 500);
    }

    $stored = $user['id'] . '-' . bin2hex(random_bytes(10)) . '.bin';
    $target = $dir . '/' . $stored;
    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        json_fail('保存头像失败', 500);
    }

    // 删除旧头像
    if (!empty($user['avatar'])) {
        $oldPath = dirname(__DIR__) . '/' . $user['avatar'];
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $pdo->prepare('UPDATE users SET avatar = ?, avatar_mime = ? WHERE id = ?')
        ->execute(array('uploads/avatars/' . $stored, $info['mime'], $user['id']));

    json_ok(array(
        'avatar_url' => 'api/avatar.php?id=' . (int) $user['id'] . '&v=' . substr(md5('uploads/avatars/' . $stored), 0, 8),
    ), '头像已更新');
}

json_fail('未知操作', 404);
