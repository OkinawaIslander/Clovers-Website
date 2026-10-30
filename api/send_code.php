<?php
/**
 * 发送邮箱验证码
 * POST: { "email": "xxx@qq.com", "purpose": "register" }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../lib/SmtpMailer.php';

@set_time_limit(60);

$input = getInput();
$email = trim(strtolower($input['email'] ?? ''));
$purpose = $input['purpose'] ?? 'register';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, '邮箱格式不正确');
}

if (!in_array($purpose, ['register', 'reset'], true)) {
    jsonResponse(false, '无效的用途');
}

try {
    $pdo = getDB();

    // 注册时检查邮箱是否已存在
    if ($purpose === 'register') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(false, '该邮箱已注册，请直接登录');
        }
    }

    // 限制：同一邮箱 60 秒内只能发一次
    // 用 MySQL 自己的 NOW() 计算间隔，避免 PHP 与 MySQL 时区不一致时算错
    $stmt = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS diff
         FROM email_codes WHERE email = ? AND purpose = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$email, $purpose]);
    $last = $stmt->fetch();
    if ($last) {
        $diff = max(0, (int) $last['diff']);
        if ($diff < 60) {
            jsonResponse(false, '发送太频繁，请 ' . (60 - $diff) . ' 秒后再试');
        }
    }

    // 生成验证码
    $code = '';
    for ($i = 0; $i < CODE_LENGTH; $i++) {
        $code .= random_int(0, 9);
    }
    $expires = date('Y-m-d H:i:s', time() + CODE_EXPIRE_MINUTES * 60);

    // 先发信，成功了再写库（避免发信失败却把 60 秒冷却锁住）
    $mailer = new SmtpMailer(
        SMTP_HOST,
        SMTP_PORT,
        SMTP_USER,
        SMTP_PASS,
        SMTP_FROM_NAME,
        defined('SMTP_FALLBACK_PORT') ? SMTP_FALLBACK_PORT : 0,
        defined('SMTP_TIMEOUT') ? SMTP_TIMEOUT : 15
    );

    $subject = "【Clovers'动漫协会】邮箱验证码";
    $body = '
    <div style="font-family: sans-serif; max-width: 480px; margin: 0 auto; padding: 24px; background: #f0f7f2; border-radius: 12px;">
      <h2 style="color: #1f6b3a; margin-top: 0;">🍀 Clovers\' 动漫协会</h2>
      <p>您好！您正在进行<strong>' . ($purpose === 'register' ? '注册' : '找回密码') . '</strong>操作。</p>
      <p>您的验证码是：</p>
      <p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #2d8f4e; text-align: center; background: #fff; padding: 16px; border-radius: 8px;">' . $code . '</p>
      <p style="color: #666; font-size: 14px;">验证码 <strong>' . CODE_EXPIRE_MINUTES . ' 分钟</strong>内有效，请勿泄露给他人。</p>
      <p style="color: #999; font-size: 12px; margin-bottom: 0;">如非本人操作，请忽略本邮件。</p>
    </div>';

    if (!$mailer->send($email, $subject, $body, true)) {
        jsonResponse(false, '邮件发送失败：' . $mailer->getLastError(), [
            'hint' => '可以打开 /api/health.php?key=你的自检密钥 做一次完整检查',
        ]);
    }

    // 把旧验证码作废，写入新验证码
    $pdo->prepare('UPDATE email_codes SET used = 1 WHERE email = ? AND purpose = ? AND used = 0')
        ->execute([$email, $purpose]);

    $pdo->prepare(
        'INSERT INTO email_codes (email, code, purpose, expires_at) VALUES (?, ?, ?, ?)'
    )->execute([$email, $code, $purpose, $expires]);

    jsonResponse(true, '验证码已发送，请查收邮箱（含垃圾箱）', [
        'via' => $mailer->getEndpoint(),
    ]);

} catch (PDOException $e) {
    $err = $e->getMessage();
    $state = (string) $e->getCode();
    // 表不存在
    if ($state === '42S02' || strpos($err, '42S02') !== false || stripos($err, "doesn't exist") !== false) {
        jsonResponse(false, '数据库里没有 users / email_codes 表，请先在 phpMyAdmin 里选中数据库并导入 database.sql', null, 500);
    }
    // 连接失败 / 库不存在 / 账号密码错误
    if ($state === '1049' || stripos($err, 'Unknown database') !== false
        || stripos($err, 'Access denied') !== false || stripos($err, 'Connection refused') !== false
        || stripos($err, 'getaddrinfo') !== false) {
        jsonResponse(false, '数据库连接失败，请检查 api/config.php 里的库名 / 用户名 / 密码（库名区分大小写）', null, 500);
    }
    jsonResponse(false, '数据库错误：' . $err, null, 500);
} catch (Throwable $e) {
    jsonResponse(false, '服务器错误：' . $e->getMessage(), null, 500);
}
