<?php
/**
 * 数据库升级 / 修复页
 * 访问 /api/migrate.php?key=你的DIAG_KEY
 *
 * 会自动：给 users 补 role / heat 等字段、建交易/留言/新番相关表、
 * 初始化四个部门的行情。不会删除已有数据。
 */
require_once __DIR__ . '/_lib.php';

if (!isset($_GET['key']) || !hash_equals(DIAG_KEY, (string) $_GET['key'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><body style="font-family:sans-serif;padding:40px;">'
        . '<h2>403 无权限</h2><p>请在网址后面加上 <code>?key=你的自检密钥</code>（密钥在 api/config.php 的 DIAG_KEY）。</p></body>';
    exit;
}

$pdo = pdo();
$error = '';
$tables = array();

try {
    ensure_schema($pdo);
    market_tick($pdo);
    liquidate_positions($pdo);

    $names = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($names as $n) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM `' . $n . '`')->fetchColumn();
        $tables[$n] = $count;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'ok' => $error === '',
        'error' => $error,
        'tables' => $tables,
        'schema_version' => CL_SCHEMA_VERSION,
        'server_time' => date('Y-m-d H:i:s'),
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>数据库升级 · Clovers</title>
<style>
  body { font-family: "PingFang SC","Microsoft YaHei",sans-serif; background:#f0f7f2; color:#1a2e22; margin:0; padding:30px 16px; }
  .wrap { max-width: 720px; margin: 0 auto; background:#fff; border-radius:16px; padding:26px; box-shadow:0 10px 30px rgba(45,143,78,.1); }
  h1 { color:#1f6b3a; font-size:1.35rem; margin-top:0; }
  .ok { color:#1f8f4d; font-weight:700; }
  .err { color:#c62828; font-weight:700; }
  table { width:100%; border-collapse:collapse; margin-top:16px; }
  td { padding:9px 12px; border-bottom:1px solid #e8f1ea; font-size:.9rem; }
  td:last-child { text-align:right; color:#5a7260; }
  code { background:#e8f5e9; padding:2px 6px; border-radius:6px; }
  a { color:#1f6b3a; }
</style>
</head>
<body>
<div class="wrap">
  <h1>🍀 数据库升级 / 修复</h1>
  <?php if ($error): ?>
    <p class="err">升级失败：<?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <p>请检查 <code>api/config.php</code> 里的库名、用户名、密码，以及数据库用户是否有 ALTER / CREATE 权限。</p>
  <?php else: ?>
    <p class="ok">升级完成（结构版本 <?php echo htmlspecialchars(CL_SCHEMA_VERSION, ENT_QUOTES, 'UTF-8'); ?>）。已有数据没有改动。</p>
    <table>
      <?php foreach ($tables as $name => $count): ?>
        <tr><td><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int) $count; ?> 行</td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <p style="margin-top:20px;font-size:.86rem;color:#5a7260;">
    下一步：<a href="health.php?key=<?php echo urlencode(DIAG_KEY); ?>">打开站点自检</a> ·
    <a href="../game.html">去热力交易所</a>
  </p>
</div>
</body>
</html>
