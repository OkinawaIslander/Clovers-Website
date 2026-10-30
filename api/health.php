<?php
/**
 * 一键自检页（只给站长自己看）
 *
 * 用法：浏览器打开  https://你的域名/api/health.php?key=DIAG_KEY的值
 *   可选：&test_mail=你的邮箱@qq.com   会真的发一封测试邮件
 *
 * DIAG_KEY 在 api/config.php 里改。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../lib/SmtpMailer.php';

if (!isset($_GET['key']) || !hash_equals(DIAG_KEY, (string) $_GET['key'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><body style="font-family:sans-serif;padding:40px;">'
        . '<h2>403 无权限</h2><p>请在网址后面加上 <code>?key=你的自检密钥</code>（密钥在 api/config.php 的 DIAG_KEY）。</p></body>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
@set_time_limit(90);

$groups = array();
$group = '运行环境';
$add = function ($name, $status, $detail = '', $hint = '') use (&$groups, &$group) {
    if (!isset($groups[$group])) {
        $groups[$group] = array();
    }
    $groups[$group][] = array('name' => $name, 'status' => $status, 'detail' => $detail, 'hint' => $hint);
};

// ================= 1. 运行环境 =================
$add('PHP 版本', version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'fail', PHP_VERSION, '建议 PHP 7.4 / 8.0 / 8.1');

$needExt = array('openssl' => '发邮件必须', 'pdo_mysql' => '连数据库必须', 'mbstring' => '中文昵称必须', 'json' => '接口返回必须');
foreach ($needExt as $ext => $why) {
    $add('扩展 ' . $ext, extension_loaded($ext) ? 'ok' : 'fail', extension_loaded($ext) ? '已启用' : '未启用', $why . '；在宝塔 → 软件商店 → PHP 设置 → 安装扩展里安装');
}

$transports = stream_get_transports();
$add('SSL 传输 (ssl://)', in_array('ssl', $transports, true) ? 'ok' : 'fail', implode(', ', $transports), '没有 ssl 传输时 465 端口无法发信，请启用 openssl 扩展');

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
$blocked = array_intersect(array('stream_socket_client', 'fsockopen', 'pfsockopen'), $disabled);
$add(
    '发信函数未被禁用',
    $blocked ? 'fail' : 'ok',
    $blocked ? ('被禁用：' . implode(', ', $blocked)) : 'stream_socket_client 可用',
    '宝塔 → PHP 设置 → 禁用函数，把 stream_socket_client / fsockopen 删掉'
);

$add('目录可写', is_writable(__DIR__) ? 'ok' : 'warn', __DIR__ . (is_writable(__DIR__) ? ' 可写' : ' 不可写'), '一般不影响发信');

// ================= 2. 数据库 =================
$group = '数据库';
$pdo = null;
try {
    $pdo = getDB();
    $add('连接数据库', 'ok', DB_USER . '@' . DB_HOST . ' / 库名 ' . DB_NAME);
} catch (Throwable $e) {
    $add(
        '连接数据库',
        'fail',
        $e->getMessage(),
        '检查 api/config.php 里的 DB_NAME（必须和宝塔数据库列表一字不差，区分大小写）、DB_USER、DB_PASS'
    );
}

if ($pdo) {
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $hasUsers = in_array('users', $tables, true);
        $hasCodes = in_array('email_codes', $tables, true);

        $add('users 表', $hasUsers ? 'ok' : 'fail', $hasUsers ? '存在' : '不存在', '在 phpMyAdmin 里选中该库，导入 database.sql');
        $add('email_codes 表', $hasCodes ? 'ok' : 'fail', $hasCodes ? '存在' : '不存在', '在 phpMyAdmin 里选中该库，导入 database.sql');

        $needTables = array('posts', 'market', 'positions', 'activities', 'game_runs', 'anime', 'anime_comments', 'announcements', 'polls', 'poll_options', 'poll_votes', 'submissions');
        $missingTables = array_values(array_diff($needTables, $tables));
        $add(
            '交易 / 留言 / 新番数据表',
            $missingTables ? 'warn' : 'ok',
            $missingTables ? ('缺少：' . implode('、', $missingTables)) : '齐全',
            '打开 /api/migrate.php?key=' . DIAG_KEY . ' 自动创建，不会删数据'
        );

        if (!$hasUsers || !$hasCodes) {
            // 帮忙找出表被建到哪个库里了（最常见的坑）
            try {
                $stmt = $pdo->query(
                    "SELECT table_schema, table_name FROM information_schema.tables
                     WHERE table_name IN ('users','email_codes')
                       AND table_schema NOT IN ('information_schema','mysql','performance_schema','sys')"
                );
                $found = $stmt->fetchAll();
                if ($found) {
                    $bySchema = array();
                    foreach ($found as $row) {
                        $bySchema[$row['table_schema']][] = $row['table_name'];
                    }
                    $lines = array();
                    foreach ($bySchema as $schema => $tbs) {
                        $lines[] = $schema . '：' . implode('、', $tbs);
                    }
                    $add('表在哪里', 'warn', implode('；', $lines), '如果表在别的库里，把 config.php 的 DB_NAME 改成那个库名即可');
                }
            } catch (Throwable $e) {
                // 没权限查 information_schema 就跳过
            }
        }

        if ($hasUsers) {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $add('已注册用户', 'ok', $count . ' 人');
        }
    } catch (Throwable $e) {
        $add('读取数据表', 'warn', $e->getMessage());
    }
}

// ================= 3. 邮件 =================
$group = '邮件';
$mailer = new SmtpMailer(
    SMTP_HOST,
    SMTP_PORT,
    SMTP_USER,
    SMTP_PASS,
    SMTP_FROM_NAME,
    defined('SMTP_FALLBACK_PORT') ? SMTP_FALLBACK_PORT : 0,
    defined('SMTP_TIMEOUT') ? SMTP_TIMEOUT : 15
);

$auth = $mailer->testAuth();
$add('SMTP 连接 + 登录', $auth['ok'] ? 'ok' : 'fail', $auth['message'], $auth['ok'] ? '' : '常见原因：授权码错误/过期（去 mail.qq.com 重新生成）、服务器拦了 465/587 出站端口、PHP 没开 openssl');
$add('配置的账号', 'ok', SMTP_USER . '　发件人：' . SMTP_FROM_NAME . '　端口：' . SMTP_PORT . (SMTP_FALLBACK_PORT ? ' / 备用 ' . SMTP_FALLBACK_PORT : ''));

if (!empty($_GET['test_mail'])) {
    $to = trim((string) $_GET['test_mail']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $add('测试邮件', 'fail', '收件邮箱格式不正确');
    } else {
        $body = '<p>这是一封来自 Clovers\' 动漫协会官网的测试邮件。</p><p>收到它说明 SMTP 配置正确，注册验证码也能正常发送。</p><p>时间：' . date('Y-m-d H:i:s') . '</p>';
        $okSend = $mailer->send($to, '【Clovers】SMTP 测试邮件', $body, true);
        $add('发送测试邮件', $okSend ? 'ok' : 'fail', $okSend ? ('已发送到 ' . $to . '（' . $mailer->getEndpoint() . '），请查收（含垃圾箱）') : $mailer->getLastError());
    }
}

// ================= 4. 站点 =================
$group = '站点';
$add('当前访问地址', 'ok', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''));
$add('服务器 IP', 'ok', $_SERVER['SERVER_ADDR'] ?? '未知');
$add('HTTPS', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'ok' : 'warn', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '已启用' : '未启用（443 端口没开证书）', '邮箱验证码不依赖 HTTPS，但建议开启');

// ================= 汇总 & 页面 =================
$counts = array('ok' => 0, 'warn' => 0, 'fail' => 0);
foreach ($groups as $items) {
    foreach ($items as $it) {
        $counts[$it['status']]++;
    }
}
$overall = $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'ok');
$overallText = $overall === 'ok' ? '全部检查通过 🍀' : ($overall === 'fail' ? '有 ' . $counts['fail'] . ' 项未通过，请按下方提示处理' : '全部通过，有 ' . $counts['warn'] . ' 项提醒');

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function badge($status)
{
    $map = array(
        'ok' => array('通过', '#2d8f4e', '#e6f4ea'),
        'warn' => array('提醒', '#b26a00', '#fff4e0'),
        'fail' => array('失败', '#c62828', '#fdecea'),
    );
    $m = $map[$status] ?? $map['warn'];
    return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;color:' . $m[1] . ';background:' . $m[2] . ';">' . $m[0] . '</span>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Clovers 站点自检</title>
<style>
  body { font-family: "PingFang SC","Microsoft YaHei",sans-serif; background:#f0f7f2; color:#1a2e22; margin:0; padding:24px 14px 60px; line-height:1.6; }
  .wrap { max-width: 900px; margin: 0 auto; }
  h1 { color:#1f6b3a; font-size:1.5rem; display:flex; align-items:center; gap:8px; }
  .overall { background:#fff; border-radius:14px; padding:16px 18px; margin:14px 0 22px; border-left:6px solid <?php echo $overall === 'ok' ? '#2d8f4e' : ($overall === 'fail' ? '#c62828' : '#f5a623'); ?>; box-shadow:0 6px 20px rgba(45,143,78,.08); }
  .overall strong { font-size:1.05rem; }
  .overall p { margin:6px 0 0; color:#5a7260; font-size:.9rem; }
  .group { background:#fff; border-radius:14px; margin-bottom:18px; overflow:hidden; box-shadow:0 6px 20px rgba(45,143,78,.08); }
  .group > h2 { margin:0; padding:14px 18px; font-size:1rem; background:#e8f5e9; color:#1f6b3a; }
  table { width:100%; border-collapse:collapse; }
  td { padding:11px 18px; border-top:1px solid #eef4ef; vertical-align:top; font-size:.92rem; }
  td.n { width:190px; color:#1f6b3a; font-weight:600; }
  td.s { width:74px; }
  .detail { word-break:break-word; }
  .hint { color:#b26a00; font-size:.85rem; margin-top:4px; }
  details { margin-top:6px; }
  summary { cursor:pointer; color:#5a7260; font-size:.85rem; }
  pre { background:#f6faf7; padding:10px; border-radius:8px; overflow:auto; font-size:.78rem; }
  .links { margin-top:22px; font-size:.9rem; color:#5a7260; }
  .links a { color:#1f6b3a; }
  .links code { background:#e8f5e9; padding:2px 6px; border-radius:6px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>🍀 Clovers 站点自检</h1>
  <div class="overall">
    <strong><?php echo h($overallText); ?></strong>
    <p>通过 <?php echo $counts['ok']; ?> 项 · 提醒 <?php echo $counts['warn']; ?> 项 · 失败 <?php echo $counts['fail']; ?> 项 · 检查时间 <?php echo date('Y-m-d H:i:s'); ?></p>
  </div>

<?php foreach ($groups as $title => $items): ?>
  <div class="group">
    <h2><?php echo h($title); ?></h2>
    <table>
      <?php foreach ($items as $it): ?>
      <tr>
        <td class="n"><?php echo h($it['name']); ?></td>
        <td class="s"><?php echo badge($it['status']); ?></td>
        <td>
          <div class="detail"><?php echo h($it['detail']); ?></div>
          <?php if ($it['hint'] !== ''): ?><div class="hint">👉 <?php echo h($it['hint']); ?></div><?php endif; ?>
          <?php if ($it['name'] === 'SMTP 连接 + 登录' && !empty($auth['steps'])): ?>
            <details><summary>查看 SMTP 对话过程</summary><pre><?php echo h(implode("\n", $auth['steps'])); ?></pre></details>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endforeach; ?>

  <div class="links">
    <p>测试真实发信：在网址后面加 <code>&amp;test_mail=你的邮箱@qq.com</code></p>
    <p>看完请把 config.php 里的 <code>DIAG_KEY</code> 改成只有你知道的值。</p>
    <p><a href="../index.html">← 返回网站首页</a></p>
  </div>
</div>
</body>
</html>
