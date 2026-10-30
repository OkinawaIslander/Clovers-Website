<?php
/**
 * 邮箱验证码注册
 * POST: { "nickname", "email", "password", "code" }
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);

$input = getInput();
$nickname = trim($input['nickname'] ?? '');
$email    = trim(strtolower($input['email'] ?? ''));
$password = $input['password'] ?? '';
$code     = trim($input['code'] ?? '');

if ($nickname === '' || mb_strlen($nickname) > 16) {
    json_fail('昵称不能为空且不超过 16 字');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_fail('邮箱格式不正确');
}
if (strlen($password) < MIN_PASSWORD_LEN) {
    json_fail('密码至少 ' . MIN_PASSWORD_LEN . ' 位');
}
if (!preg_match('/^\d{' . CODE_LENGTH . '}$/', $code)) {
    json_fail('验证码格式错误');
}

try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute(array($email));
    if ($stmt->fetch()) {
        json_fail('该邮箱已注册');
    }

    $stmt = $pdo->prepare(
        'SELECT id, code, expires_at, used FROM email_codes
         WHERE email = ? AND purpose = ? AND used = 0
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(array($email, 'register'));
    $row = $stmt->fetch();

    if (!$row) {
        json_fail('请先获取验证码');
    }
    if ((int) $row['used'] === 1) {
        json_fail('验证码已使用');
    }
    if (strtotime($row['expires_at']) < time()) {
        json_fail('验证码已过期，请重新获取');
    }
    if ($row['code'] !== $code) {
        json_fail('验证码错误');
    }

    $pdo->prepare('UPDATE email_codes SET used = 1 WHERE id = ?')->execute(array($row['id']));

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $role = admin_email($email) ? 'admin' : 'user';
    $pdo->prepare(
        'INSERT INTO users (nickname, email, password, role, heat, total_earned) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute(array($nickname, $email, $hash, $role, START_HEAT, START_HEAT));

    $userId = (int) $pdo->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;

    add_activity($pdo, array('id' => $userId, 'nickname' => $nickname), 'join', '加入了热力交易所', null, null);

    json_ok(array(
        'user' => array(
            'id' => $userId,
            'nickname' => $nickname,
            'email' => $email,
            'role' => $role,
            'heat' => (float) START_HEAT,
            'is_admin' => $role === 'admin',
        ),
    ), '注册成功');

} catch (PDOException $e) {
    $err = $e->getMessage();
    $state = (string) $e->getCode();
    if ($state === '23000' || strpos($err, '23000') !== false || stripos($err, 'Duplicate entry') !== false) {
        json_fail('该邮箱已注册');
    }
    if ($state === '42S02' || strpos($err, '42S02') !== false || stripos($err, "doesn't exist") !== false) {
        json_fail('数据库里没有 users / email_codes 表，请先在 phpMyAdmin 里选中数据库并导入 database.sql', 500);
    }
    json_fail('数据库错误：' . $err, 500);
}
