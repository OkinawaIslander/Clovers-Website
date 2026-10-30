<?php
/**
 * 登录
 * POST: { "email", "password" }
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);

$input = getInput();
$raw      = trim($input['email'] ?? '');
$email    = strtolower($raw);
$password = $input['password'] ?? '';

// 支持管理员账号 admin（没有 @），以及正常邮箱登录
if ($email !== 'admin' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
    json_fail('请输入正确的邮箱或账号');
}
if ($password === '') {
    json_fail('请输入密码');
}

try {
    $stmt = $pdo->prepare('SELECT id, nickname, email, password, role, heat FROM users WHERE email = ?');
    $stmt->execute(array($email));
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        json_fail('邮箱或密码错误');
    }

    if (is_admin($user) && $user['role'] !== 'admin') {
        $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute(array($user['id']));
        $user['role'] = 'admin';
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];

    json_ok(array(
        'user' => array(
            'id' => (int) $user['id'],
            'nickname' => $user['nickname'],
            'email' => $user['email'],
            'role' => $user['role'],
            'heat' => (float) $user['heat'],
            'is_admin' => $user['role'] === 'admin',
        ),
    ), '登录成功');

} catch (PDOException $e) {
    json_fail('数据库错误：' . $e->getMessage(), 500);
}
