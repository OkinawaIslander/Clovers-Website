<?php
/**
 * Clovers' 官网 - 配置示例
 *
 * 复制为 api/config.php 后再修改；api/config.php 已被 .gitignore 忽略。
 * Linux 下数据库名区分大小写，必须和实际数据库完全一致。
 */

// ========== 数据库 ==========
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'clovers');
define('DB_USER', 'clovers');
define('DB_PASS', 'change-me');
define('DB_CHARSET', 'utf8mb4');

// ========== 邮箱 SMTP（可选，注册验证码需要） ==========
define('SMTP_HOST', 'smtp.qq.com');
define('SMTP_PORT', 465);
define('SMTP_FALLBACK_PORT', 587);
define('SMTP_USER', 'your@qq.com');
define('SMTP_PASS', 'your-smtp-auth-code');
define('SMTP_FROM_NAME', "Clovers' 动漫协会");
define('SMTP_TIMEOUT', 20);

// ========== 业务配置 ==========
define('CODE_EXPIRE_MINUTES', 10);
define('CODE_LENGTH', 6);
define('MIN_PASSWORD_LEN', 6);

// ========== 自检页密钥 ==========
define('DIAG_KEY', 'change-this-diag-key');

// ========== 站点 / 权限 ==========
define('ADMIN_EMAILS', array('admin@example.com'));
define('ADMIN_API_KEY', 'change-this-admin-key');

// ========== 热力值经济 ==========
define('START_HEAT', 1000);
define('MARKET_BASE_PRICE', 1000);
define('GAME_DAILY_CAP', 3000);
define('CHECKIN_BASE', 100);
define('MAX_OPEN_POSITIONS', 8);
define('MIN_MARGIN', 10);
define('MAX_MARGIN', 100000);
define('TRADE_OPEN_START', 12);
define('TRADE_OPEN_END', 18);
define('MARKET_DAILY_VOL', 0.065);
define('MARKET_TRADE_IMPACT', 0.02);
define('MARKET_IMPACT_CAP', 0.12);
define('UPLOAD_MAX_MB', 20);
define('AVATAR_MAX_MB', 2);
define('SUBMISSION_CATEGORIES', array('cosplay摄影', '文章', '绘画插画', '其他'));

// ========== 小游戏 ==========
define('SURVIVORS_DAILY_CAP', 1200);
define('FARM_PLOTS', 4);
define('FARM_STEAL_RATE', 0.2);
define('FARM_STEAL_DAILY_CAP', 500);
define('FARM_CROPS', array(
    'clover' => array('name' => '四叶草', 'emoji' => '🍀', 'cost' => 50, 'sell' => 90, 'grow' => 3600),
    'tomato' => array('name' => '番茄', 'emoji' => '🍅', 'cost' => 100, 'sell' => 190, 'grow' => 7200),
    'pumpkin' => array('name' => '南瓜', 'emoji' => '🎃', 'cost' => 180, 'sell' => 360, 'grow' => 14400),
    'star' => array('name' => '星星花', 'emoji' => '🌟', 'cost' => 300, 'sell' => 620, 'grow' => 28800),
));

// ========== Bangumi 新番 ==========
define('BANGUMI_ENABLED', true);
define('BANGUMI_API_BASE', 'https://api.bgm.tv');
define('BANGUMI_LOCAL_FIRST', true);
define('BANGUMI_DATA_MIRRORS', array(
    'https://cdn.jsdelivr.net/gh/bangumi-data/bangumi-data@master/dist/data.json',
    'https://fastly.jsdelivr.net/gh/bangumi-data/bangumi-data@master/dist/data.json',
    'https://gcore.jsdelivr.net/gh/bangumi-data/bangumi-data@master/dist/data.json',
    'https://unpkg.com/bangumi-data@latest/dist/data.json',
    'https://raw.githubusercontent.com/bangumi-data/bangumi-data/master/dist/data.json',
));

// 允许跨域（前后端同域可忽略）
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

date_default_timezone_set('Asia/Shanghai');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        try {
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (Throwable $e) {
            // 个别主机没有时区权限，忽略
        }
    }
    return $pdo;
}

function jsonResponse(bool $ok, string $msg = '', $data = null, int $code = 200): void {
    http_response_code($code);
    echo json_encode([
        'ok'   => $ok,
        'msg'  => $msg,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST ?: [];
}
