<?php
/**
 * 创建管理员（CLI）
 *
 * 用法：
 *   php scripts/create_admin.php admin@example.com 'password' 系统管理员 super
 *   php scripts/create_admin.php editor@example.com 'password' 编辑 admin
 *
 * 最后一个参数是角色：super（系统管理员）或 admin（次级管理员）。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('只允许命令行运行');
}

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/_lib.php';

$email = trim((string) ($argv[1] ?? ''));
$password = (string) ($argv[2] ?? '');
$nickname = trim((string) ($argv[3] ?? '管理员'));
$role = strtolower(trim((string) ($argv[4] ?? 'admin')));

if ($email === '' || $password === '') {
    fwrite(STDERR, "用法：php scripts/create_admin.php <email> <password> [nickname] [super|admin]\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "邮箱格式不正确\n");
    exit(1);
}
if (strlen($password) < 6 || strlen($password) > 72) {
    fwrite(STDERR, "密码长度需要 6 - 72 位\n");
    exit(1);
}
if ($nickname === '' || mb_strlen($nickname) > 16) {
    $nickname = '管理员';
}
if (!in_array($role, array('super', 'admin'), true)) {
    $role = 'admin';
}

try {
    $pdo = pdo();
    ensure_schema($pdo);

    $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute(array($email));
    $id = (int) $st->fetchColumn();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($id > 0) {
        $pdo->prepare('UPDATE users SET password = ?, nickname = ?, role = ? WHERE id = ?')
            ->execute(array($hash, mb_substr($nickname, 0, 16), $role, $id));
        echo "已更新管理员：{$email}（id={$id}, role={$role}）\n";
    } else {
        $pdo->prepare(
            'INSERT INTO users (nickname, email, password, role, heat, total_earned)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(array(mb_substr($nickname, 0, 16), $email, $hash, $role, START_HEAT, START_HEAT));
        echo "已创建管理员：{$email}（id=" . (int) $pdo->lastInsertId() . ", role={$role}）\n";
    }
    echo "请登录后到「我的 → 设置」修改密码。\n";
} catch (Throwable $e) {
    fwrite(STDERR, '创建失败：' . $e->getMessage() . "\n");
    exit(1);
}
