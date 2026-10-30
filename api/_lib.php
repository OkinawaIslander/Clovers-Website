<?php
/**
 * Clovers 站点核心库
 * 会话、权限、数据库升级、行情引擎、热力值交易、动态流。
 *
 * 所有 api/*.php 都先 require 这个文件，再 require_once config.php。
 */
require_once __DIR__ . '/config.php';

define('CL_SCHEMA_VERSION', '11');

// ---------- 会话 ----------
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('clovers_sid');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $secure,
        ));
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
    session_start();
}

// 任何未捕获的异常都返回 JSON，避免前端只看到 HTML 的 Fatal error
set_exception_handler(function ($e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array(
        'ok' => false,
        'msg' => '服务器错误：' . $e->getMessage(),
        'data' => null,
    ), JSON_UNESCAPED_UNICODE);
});

// ---------- 基础 ----------
// 万一主机没装 mbstring，用字节函数兜底，避免整站 500
if (!function_exists('mb_strlen')) {
    function mb_strlen($str, $encoding = null) {
        return strlen((string) $str);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($str, $start, $length = null, $encoding = null) {
        return $length === null ? substr((string) $str, $start) : substr((string) $str, $start, $length);
    }
}

function pdo() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $err = $e->getMessage();
            $hint = '无法连接数据库：请检查 api/config.php 的 DB_NAME / DB_USER / DB_PASS。';
            if (stripos($err, '1044') !== false || stripos($err, 'Access denied') !== false) {
                $hint = '数据库拒绝访问：库名大小写不对，或该用户没有这个库的权限。'
                    . '宝塔里显示的是小写 members，api/config.php 里就写 members，不要写 Members；'
                    . '如果还不行，去宝塔「数据库 → 权限」给用户授权这个库。';
            } elseif (stripos($err, '1045') !== false) {
                $hint = '数据库用户名或密码不对，请对照宝塔「数据库」列表修改 api/config.php。';
            } elseif (stripos($err, '1049') !== false || stripos($err, 'Unknown database') !== false) {
                $hint = '数据库不存在：请确认宝塔里的库名（例如 members）和 api/config.php 里的 DB_NAME 完全一致。';
            }
            jsonResponse(false, $hint, array('error' => $err), 500);
        }
    }
    return $pdo;
}

function json_ok($data = null, $msg = '') {
    jsonResponse(true, $msg, $data);
}

function json_fail($msg, $code = 400, $data = null) {
    jsonResponse(false, $msg, $data, $code);
}

function is_post() {
    return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
}

function require_post() {
    if (!is_post()) {
        json_fail('请使用 POST 请求', 405);
    }
}

function now() {
    return time();
}

function time_ago($datetime) {
    $t = strtotime((string) $datetime);
    if (!$t) {
        return '';
    }
    $d = time() - $t;
    if ($d < 60) {
        return '刚刚';
    }
    if ($d < 3600) {
        return floor($d / 60) . ' 分钟前';
    }
    if ($d < 86400) {
        return floor($d / 3600) . ' 小时前';
    }
    if ($d < 86400 * 30) {
        return floor($d / 86400) . ' 天前';
    }
    return date('Y-m-d', $t);
}

function admin_email($email) {
    return in_array(strtolower((string) $email), array_map('strtolower', ADMIN_EMAILS), true);
}

function is_super($user) {
    return $user && (($user['role'] ?? '') === 'super');
}

function is_admin($user) {
    if (!$user) {
        return false;
    }
    return in_array(($user['role'] ?? ''), array('admin', 'super'), true) || admin_email($user['email'] ?? '');
}

function user_avatar_url($user) {
    if (empty($user['avatar'])) {
        return null;
    }
    return 'api/avatar.php?id=' . (int) $user['id'] . '&v=' . substr(md5($user['avatar']), 0, 8);
}

function user_departments($user) {
    if (empty($user['departments'])) {
        return array();
    }
    return array_values(array_filter(array_map('trim', explode(',', $user['departments']))));
}

function current_user($pdo) {
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    if (empty($_SESSION['uid'])) {
        return $cached = null;
    }
    $st = $pdo->prepare('SELECT id, nickname, email, role, heat, total_earned, last_checkin, checkin_streak, created_at, signature, avatar, avatar_mime, departments FROM users WHERE id = ?');
    $st->execute(array((int) $_SESSION['uid']));
    $u = $st->fetch();
    if (!$u) {
        unset($_SESSION['uid']);
        return $cached = null;
    }
    if (admin_email($u['email']) && $u['role'] === 'user') {
        $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute(array($u['id']));
        $u['role'] = 'admin';
    }
    return $cached = $u;
}

function require_login($pdo) {
    $u = current_user($pdo);
    if (!$u) {
        json_fail('请先登录', 401);
    }
    return $u;
}

function require_admin($pdo) {
    $u = require_login($pdo);
    if (!is_admin($u)) {
        json_fail('需要管理员权限', 403);
    }
    return $u;
}

function client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ---------- 数据库升级 ----------
function ensure_column($pdo, $table, $column, $ddl) {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $st->execute(array($table, $column));
    if ((int) $st->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN ' . $ddl);
    }
}

function ensure_schema($pdo) {
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS `settings` (
        `k` VARCHAR(64) NOT NULL,
        `v` TEXT NULL,
        PRIMARY KEY (`k`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $version = null;
    try {
        $st = $pdo->query("SELECT v FROM settings WHERE k = 'schema_version'");
        $version = $st->fetchColumn();
    } catch (Throwable $e) {
        $version = null;
    }

    if ($version === CL_SCHEMA_VERSION) {
        $done = true;
        return;
    }

    // 基础表（新库直接建全；老库由下面的 ensure_column 补字段）
    $pdo->exec('CREATE TABLE IF NOT EXISTS `users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `nickname` VARCHAR(32) NOT NULL,
        `email` VARCHAR(120) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(16) NOT NULL DEFAULT "user",
        `heat` DECIMAL(14,2) NOT NULL DEFAULT ' . START_HEAT . ',
        `total_earned` DECIMAL(14,2) NOT NULL DEFAULT ' . START_HEAT . ',
        `last_checkin` DATE NULL,
        `checkin_streak` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `email_codes` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `email` VARCHAR(120) NOT NULL,
        `code` VARCHAR(10) NOT NULL,
        `purpose` VARCHAR(20) NOT NULL DEFAULT "register",
        `expires_at` DATETIME NOT NULL,
        `used` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_email_purpose` (`email`, `purpose`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 用户扩展字段
    ensure_column($pdo, 'users', 'role', "`role` VARCHAR(16) NOT NULL DEFAULT 'user'");
    ensure_column($pdo, 'users', 'heat', '`heat` DECIMAL(14,2) NOT NULL DEFAULT ' . START_HEAT);
    ensure_column($pdo, 'users', 'total_earned', '`total_earned` DECIMAL(14,2) NOT NULL DEFAULT ' . START_HEAT);
    ensure_column($pdo, 'users', 'last_checkin', '`last_checkin` DATE NULL');
    ensure_column($pdo, 'users', 'checkin_streak', '`checkin_streak` INT NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'signature', '`signature` VARCHAR(30) NULL');
    ensure_column($pdo, 'users', 'avatar', '`avatar` VARCHAR(255) NULL');
    ensure_column($pdo, 'users', 'avatar_mime', '`avatar_mime` VARCHAR(50) NULL');
    ensure_column($pdo, 'users', 'departments', '`departments` VARCHAR(100) NULL');

    // 管理员账号不再自动创建，首次部署请运行 scripts/create_admin.php

    // 帖子（留言板）
    $pdo->exec('CREATE TABLE IF NOT EXISTS `posts` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NULL,
        `nickname` VARCHAR(32) NOT NULL,
        `content` VARCHAR(300) NOT NULL,
        `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
        `deleted_by` INT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_created` (`created_at`),
        KEY `idx_deleted` (`is_deleted`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 公告
    $pdo->exec('CREATE TABLE IF NOT EXISTS `announcements` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(120) NOT NULL,
        `content` TEXT NOT NULL,
        `user_id` INT UNSIGNED NULL,
        `nickname` VARCHAR(32) NOT NULL DEFAULT "管理员",
        `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 投票
    $pdo->exec('CREATE TABLE IF NOT EXISTS `polls` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(160) NOT NULL,
        `description` VARCHAR(500) NULL,
        `starts_at` DATETIME NOT NULL,
        `ends_at` DATETIME NOT NULL,
        `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
        `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
        `created_by` INT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_time` (`starts_at`, `ends_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `poll_options` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `poll_id` INT UNSIGNED NOT NULL,
        `label` VARCHAR(120) NOT NULL,
        `sort` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_poll` (`poll_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `poll_votes` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `poll_id` INT UNSIGNED NOT NULL,
        `option_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_poll_user` (`poll_id`, `user_id`),
        KEY `idx_option` (`option_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 社刊投稿
    $pdo->exec('CREATE TABLE IF NOT EXISTS `submissions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `title` VARCHAR(160) NOT NULL,
        `category` VARCHAR(30) NOT NULL DEFAULT "其他",
        `description` VARCHAR(1000) NULL,
        `file_path` VARCHAR(255) NOT NULL,
        `original_name` VARCHAR(255) NOT NULL,
        `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
        `mime` VARCHAR(100) NULL,
        `status` ENUM("pending","accepted","rejected") NOT NULL DEFAULT "pending",
        `admin_note` VARCHAR(500) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user` (`user_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 行情
    $pdo->exec('CREATE TABLE IF NOT EXISTS `market` (
        `symbol` VARCHAR(24) NOT NULL,
        `name` VARCHAR(40) NOT NULL,
        `icon` VARCHAR(8) NOT NULL DEFAULT "🍀",
        `base_price` DECIMAL(14,4) NOT NULL DEFAULT 1000,
        `price` DECIMAL(14,4) NOT NULL DEFAULT 1000,
        `anchor` DECIMAL(14,4) NULL,
        `vol` DECIMAL(20,12) NOT NULL DEFAULT 0.000000336100,
        `last_shock` DECIMAL(20,12) NOT NULL DEFAULT 0,
        `spread_bps` DECIMAL(8,2) NOT NULL DEFAULT 6.00,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`symbol`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    ensure_column($pdo, 'market', 'anchor', '`anchor` DECIMAL(14,4) NULL');
    ensure_column($pdo, 'market', 'vol', '`vol` DECIMAL(20,12) NOT NULL DEFAULT 0.000000336100');
    ensure_column($pdo, 'market', 'last_shock', '`last_shock` DECIMAL(20,12) NOT NULL DEFAULT 0');
    ensure_column($pdo, 'market', 'spread_bps', '`spread_bps` DECIMAL(8,2) NOT NULL DEFAULT 6.00');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `market_history` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `symbol` VARCHAR(24) NOT NULL,
        `price` DECIMAL(14,4) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_symbol_id` (`symbol`, `id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 持仓
    $pdo->exec('CREATE TABLE IF NOT EXISTS `positions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `symbol` VARCHAR(24) NOT NULL,
        `direction` ENUM("long","short") NOT NULL,
        `margin` DECIMAL(14,2) NOT NULL,
        `leverage` TINYINT UNSIGNED NOT NULL,
        `entry_price` DECIMAL(14,4) NOT NULL,
        `close_after` DATETIME NOT NULL,
        `status` ENUM("open","closed","liquidated") NOT NULL DEFAULT "open",
        `exit_price` DECIMAL(14,4) NULL,
        `pnl` DECIMAL(14,2) NULL,
        `opened_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `closed_at` DATETIME NULL,
        `close_reason` VARCHAR(32) NULL,
        PRIMARY KEY (`id`),
        KEY `idx_user_status` (`user_id`, `status`),
        KEY `idx_status_symbol` (`status`, `symbol`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 动态流
    $pdo->exec('CREATE TABLE IF NOT EXISTS `activities` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NULL,
        `nickname` VARCHAR(32) NOT NULL,
        `type` VARCHAR(24) NOT NULL,
        `text` VARCHAR(255) NOT NULL,
        `amount` DECIMAL(14,2) NULL,
        `symbol` VARCHAR(24) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_created` (`created_at`),
        KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 四叶草小游戏记录（用于每日上限）
    $pdo->exec('CREATE TABLE IF NOT EXISTS `game_runs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `game` VARCHAR(20) NOT NULL DEFAULT "clover",
        `score` INT NOT NULL,
        `heat` DECIMAL(14,2) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_time` (`user_id`, `created_at`),
        KEY `idx_game_user_time` (`game`, `user_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    ensure_column($pdo, 'game_runs', 'game', '`game` VARCHAR(20) NOT NULL DEFAULT "clover"');

    // 像素农场
    $pdo->exec('CREATE TABLE IF NOT EXISTS `farm_plots` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `plot_index` TINYINT UNSIGNED NOT NULL,
        `crop` VARCHAR(20) NOT NULL,
        `planted_at` DATETIME NOT NULL,
        `ready_at` DATETIME NOT NULL,
        `stolen` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_user_plot` (`user_id`, `plot_index`),
        KEY `idx_ready` (`ready_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `farm_steals` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `thief_id` INT UNSIGNED NOT NULL,
        `owner_id` INT UNSIGNED NOT NULL,
        `plot_id` INT UNSIGNED NOT NULL,
        `crop` VARCHAR(20) NOT NULL,
        `heat` DECIMAL(14,2) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_thief_day` (`thief_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 番剧
    $pdo->exec('CREATE TABLE IF NOT EXISTS `anime` (
        `id` INT UNSIGNED NOT NULL,
        `season` VARCHAR(8) NOT NULL,
        `title` VARCHAR(160) NOT NULL,
        `title_cn` VARCHAR(160) NULL,
        `air_date` DATE NULL,
        `image` VARCHAR(255) NULL,
        `summary` TEXT NULL,
        `sort` INT NOT NULL DEFAULT 0,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_season` (`season`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `anime_comments` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `subject_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `nickname` VARCHAR(32) NOT NULL,
        `content` VARCHAR(500) NOT NULL,
        `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_subject` (`subject_id`, `id`),
        KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 新番评分 0-5
    $pdo->exec('CREATE TABLE IF NOT EXISTS `anime_ratings` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `subject_id` INT UNSIGNED NOT NULL,
        `user_id` INT UNSIGNED NOT NULL,
        `score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_user_subject` (`user_id`, `subject_id`),
        KEY `idx_subject` (`subject_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 通用缓存（Bangumi 等）
    $pdo->exec('CREATE TABLE IF NOT EXISTS `cache` (
        `k` VARCHAR(64) NOT NULL,
        `v` MEDIUMTEXT NOT NULL,
        `expires_at` DATETIME NOT NULL,
        PRIMARY KEY (`k`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // 行情初始品种（每个部门基础热力值 1000）
    $basePrice = defined('MARKET_BASE_PRICE') ? (float) MARKET_BASE_PRICE : 1000.0;
    $seed = array(
        array('xuanbian', '宣编部', '📝', $basePrice),
        array('zhaiwu', '宅舞部', '💃', $basePrice),
        array('wota', 'WOTA艺部', '🎤', $basePrice),
        array('zhuoyou', '桌游部', '🎲', $basePrice),
    );
    $ins = $pdo->prepare('INSERT IGNORE INTO market (symbol, name, icon, base_price, price, updated_at) VALUES (?, ?, ?, ?, ?, NOW())');
    foreach ($seed as $s) {
        $ins->execute(array($s[0], $s[1], $s[2], $s[3], $s[3]));
    }

    // 老行情统一到「基础热力值 1000」：按比例缩放价格、历史和持仓入场价，
    // 这样已有仓位的盈亏比例不变，不会被重置。
    $marketRows = $pdo->query('SELECT symbol, base_price FROM market')->fetchAll();
    foreach ($marketRows as $m) {
        $oldBase = (float) $m['base_price'];
        if ($oldBase <= 0 || abs($oldBase - $basePrice) < 0.001) {
            continue;
        }
        $factor = $basePrice / $oldBase;
        $pdo->prepare('UPDATE market SET base_price = ?, price = ROUND(price * ?, 4) WHERE symbol = ?')
            ->execute(array($basePrice, $factor, $m['symbol']));
        $pdo->prepare('UPDATE market SET anchor = ROUND(anchor * ?, 4) WHERE symbol = ? AND anchor IS NOT NULL')
            ->execute(array($factor, $m['symbol']));
        $pdo->prepare('UPDATE market_history SET price = ROUND(price * ?, 4) WHERE symbol = ?')
            ->execute(array($factor, $m['symbol']));
        $pdo->prepare('UPDATE positions
                       SET entry_price = ROUND(entry_price * ?, 4),
                           exit_price = IF(exit_price IS NULL, NULL, ROUND(exit_price * ?, 4))
                       WHERE symbol = ?')
            ->execute(array($factor, $factor, $m['symbol']));
    }

    // 新字段初始化：锚点=当前价，波动率=长期均值（放在价格缩放之后）
    $pdo->exec('UPDATE market SET anchor = price WHERE anchor IS NULL OR anchor <= 0');
    $pdo->exec('UPDATE market SET vol = 0.000000336100 WHERE vol IS NULL OR vol <= 0');

    // 投稿分类调整：漫画合并进其他，插画/摄影改成新分类
    $pdo->exec("UPDATE submissions SET category = '其他' WHERE category = '漫画'");
    $pdo->exec("UPDATE submissions SET category = '绘画插画' WHERE category = '插画'");
    $pdo->exec("UPDATE submissions SET category = 'cosplay摄影' WHERE category = '摄影'");

    $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
        ->execute(array(CL_SCHEMA_VERSION));

    $done = true;
}

// ---------- 动态流 ----------
function add_activity($pdo, $user, $type, $text, $amount = null, $symbol = null) {
    $uid = $user ? (int) $user['id'] : null;
    $nick = $user ? $user['nickname'] : '系统';
    $pdo->prepare('INSERT INTO activities (user_id, nickname, type, text, amount, symbol) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(array($uid, $nick, $type, mb_substr($text, 0, 200), $amount, $symbol));
}

// ---------- 行情 ----------
/**
 * 标准正态随机数（Box-Muller）
 */
function fx_gauss() {
    static $spare = null;
    if ($spare !== null) {
        $v = $spare;
        $spare = null;
        return $v;
    }
    $u1 = mt_rand(1, 1000000) / 1000000;
    $u2 = mt_rand(1, 1000000) / 1000000;
    $r = sqrt(-2 * log($u1));
    $spare = $r * sin(2 * M_PI * $u2);
    return $r * cos(2 * M_PI * $u2);
}

/**
 * 外汇市场时段波动系数（北京时间，近似伦敦/纽约开盘节奏）
 */
function fx_session_multiplier($ts) {
    $h = (int) date('G', $ts);
    $d = (int) date('N', $ts);
    if ($d >= 6) {          // 周末流动性差
        return 0.45;
    }
    if ($h < 7) {           // 亚洲早盘
        return 0.75;
    }
    if ($h < 12) {          // 东京/香港
        return 0.95;
    }
    if ($h < 16) {          // 伦敦开盘前
        return 1.15;
    }
    if ($h < 21) {          // 伦敦 + 纽约重叠
        return 1.35;
    }
    return 0.85;            // 纽约午后
}

/**
 * 长期每分钟方差：按“日波动约 2.2%”换算
 */
function fx_base_variance() {
    $daily = defined('MARKET_DAILY_VOL') ? (float) MARKET_DAILY_VOL : 0.065;
    return ($daily * $daily) / 1440;
}

/**
 * 点差的一半（基点 -> 比例）
 */
function market_spread_half($market) {
    $bps = isset($market['spread_bps']) ? (float) $market['spread_bps'] : 6.0;
    $bps = max(0.0, min(100.0, $bps));
    return $bps / 10000 / 2;
}

/**
 * 成交价：买入/开多付卖价，卖出/平多拿买价
 */
function market_execution_price($mid, $market, $direction, $isOpen) {
    $half = market_spread_half($market);
    $mid = (float) $mid;
    if ($isOpen) {
        return $direction === 'long' ? $mid * (1 + $half) : $mid * (1 - $half);
    }
    return $direction === 'long' ? $mid * (1 - $half) : $mid * (1 + $half);
}

/**
 * 人为交易对价格的冲击：仓位名义价值越大，推动越明显。
 * 30 人左右的模拟盘，单笔 1 万热力名义约 2%，10 万约 6%，上限 12%。
 */
function market_impact_ratio($notional) {
    $coef = defined('MARKET_TRADE_IMPACT') ? (float) MARKET_TRADE_IMPACT : 0.02;
    $cap = defined('MARKET_IMPACT_CAP') ? (float) MARKET_IMPACT_CAP : 0.12;
    $notional = max(0.0, (float) $notional);
    return min($cap, $coef * sqrt($notional / 10000));
}

function market_apply_impact($pdo, $symbol, $sign, $notional) {
    $impact = market_impact_ratio($notional);
    if ($impact <= 0) {
        return 0.0;
    }

    $st = $pdo->prepare('SELECT price, anchor, base_price FROM market WHERE symbol = ? FOR UPDATE');
    $st->execute(array($symbol));
    $m = $st->fetch();
    if (!$m) {
        return 0.0;
    }

    $sign = $sign >= 0 ? 1 : -1;
    $price = max(1.0, (float) $m['price'] * (1 + $sign * $impact));
    $anchor = (float) $m['anchor'];
    if ($anchor <= 0) {
        $anchor = (float) $m['price'];
    }
    // 让冲击有一部分留在基本面里，不会被下一分钟立刻拉回去
    $anchor = max(1.0, $anchor * (1 + $sign * $impact * 0.35));

    $base = max(1.0, (float) $m['base_price']);
    $price = max($base * 0.3, min($base * 3.5, $price));
    $anchor = max($base * 0.5, min($base * 2.0, $anchor));

    $pdo->prepare('UPDATE market SET price = ?, anchor = ? WHERE symbol = ?')
        ->execute(array(round($price, 4), round($anchor, 4), $symbol));

    return $impact;
}

function market_tick($pdo) {
    $lock = false;
    try {
        $lock = $pdo->query("SELECT GET_LOCK('clovers_market_tick', 3)")->fetchColumn();
    } catch (Throwable $e) {
        $lock = false;
    }
    if (!$lock) {
        return;
    }

    try {
        $rows = $pdo->query('SELECT * FROM market')->fetchAll();
        $now = time();

        $upd = $pdo->prepare(
            'UPDATE market SET price = ?, anchor = ?, vol = ?, last_shock = ?, updated_at = FROM_UNIXTIME(?)
             WHERE symbol = ?'
        );
        $hist = $pdo->prepare('INSERT INTO market_history (symbol, price, created_at) VALUES (?, ?, FROM_UNIXTIME(?))');

        // GARCH(1,1)：alpha 小、beta 大 => 波动率聚集（大波动后面跟大波动）
        $v0 = fx_base_variance();
        $alpha = 0.08;
        $beta = 0.90;
        $omega = $v0 * (1 - $alpha - $beta);

        foreach ($rows as $m) {
            $last = $m['updated_at'] ? strtotime($m['updated_at']) : $now;
            $steps = (int) floor(($now - $last) / 60);
            if ($steps <= 0) {
                continue;
            }
            $steps = min($steps, 180);       // 最多补 3 小时
            $start = $now - $steps * 60;

            $base = max(1.0, (float) $m['base_price']);
            $price = max(1.0, (float) $m['price']);
            $anchor = (float) $m['anchor'];
            if ($anchor <= 0) {
                $anchor = $price;
            }
            $var = (float) $m['vol'];
            if ($var <= 0) {
                $var = $v0;
            }
            $var = max($v0 * 0.25, min($v0 * 9, $var));

            $lnP = log($price);
            $lnA = log($anchor);
            $eps = 0.0;

            for ($i = 0; $i < $steps; $i++) {
                $ts = $start + $i * 60;
                $sess = fx_session_multiplier($ts);

                // 慢速基本面锚：自身随机游走，价格只弱回归到它，所以会有趋势
                $lnA += 0.00060 * fx_gauss();
                $lnA = max(log($base * 0.5), min(log($base * 2.0), $lnA));

                // GARCH 波动率（不含时段放大，避免时段污染方差）
                $sigma = sqrt($var);
                $eps = $sigma * fx_gauss();

                // 弱均值回归 + 随机冲击 + 时段放大
                $r = 0.00018 * ($lnA - $lnP) + $eps * $sess;

                // 新闻式跳空：平均每天约 1.7 次
                if (mt_rand(1, 1000000) <= 1200) {
                    $r += 0.018 * fx_gauss() * $sess;
                }

                $lnP += $r;
                $lnP = max(log($base * 0.3), min(log($base * 3.5), $lnP));

                // 更新下一分钟的方差
                $var = $omega + $alpha * $eps * $eps + $beta * $var;
                $var = max($v0 * 0.25, min($v0 * 9, $var));

                $stepPrice = round(exp($lnP), 4);
                $hist->execute(array($m['symbol'], $stepPrice, $ts));
            }

            $upd->execute(array(
                round(exp($lnP), 4),   // price
                round(exp($lnA), 4),   // anchor
                $var,
                $eps,
                $now,
                $m['symbol'],
            ));
        }

        // 偶尔清理旧历史（保留 120 天，够画日线）
        if (mt_rand(1, 50) === 1) {
            $pdo->exec('DELETE FROM market_history WHERE created_at < DATE_SUB(NOW(), INTERVAL 120 DAY)');
        }
    } finally {
        try {
            $pdo->query("SELECT RELEASE_LOCK('clovers_market_tick')");
        } catch (Throwable $e) {
            // ignore
        }
    }
}

function market_prices($pdo) {
    $out = array();
    foreach ($pdo->query('SELECT symbol, price FROM market')->fetchAll() as $r) {
        $out[$r['symbol']] = (float) $r['price'];
    }
    return $out;
}

function position_pnl($p, $price) {
    $entry = (float) $p['entry_price'];
    if ($entry <= 0) {
        return 0.0;
    }
    $dir = $p['direction'] === 'long' ? 1 : -1;
    return ($price - $entry) / $entry * (float) $p['margin'] * (int) $p['leverage'] * $dir;
}

function position_equity($p, $price) {
    return (float) $p['margin'] + position_pnl($p, $price);
}

function leverage_list() {
    return array(1, 2, 4, 5, 10);
}

function leverage_hours($lev) {
    $map = array(1 => 24, 2 => 36, 4 => 48, 5 => 60, 10 => 72);
    $lev = (int) $lev;
    return $map[$lev] ?? 24;
}

function trade_window() {
    $hour = (int) date('G');
    $open = ($hour >= TRADE_OPEN_START && $hour < TRADE_OPEN_END);
    return array(
        'open' => $open,
        'start' => TRADE_OPEN_START,
        'end' => TRADE_OPEN_END,
        'server_time' => date('Y-m-d H:i:s'),
    );
}

function liquidate_positions($pdo) {
    $rows = $pdo->query(
        "SELECT p.*, m.price AS cur_price, m.name AS market_name, m.spread_bps
         FROM positions p JOIN market m ON m.symbol = p.symbol
         WHERE p.status = 'open'"
    )->fetchAll();

    foreach ($rows as $p) {
        $mid = (float) $p['cur_price'];
        // 强平按实际能成交的方向价（含点差）结算
        $exitPrice = market_execution_price($mid, $p, $p['direction'], false);
        $margin = (float) $p['margin'];
        $equity = position_equity($p, $exitPrice);
        if ($equity > $margin * 0.5) {
            continue;
        }

        $payout = max(0.0, $equity);
        $pnl = $payout - $margin;
        $label = $p['direction'] === 'long' ? '做多' : '做空';

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                "UPDATE positions
                 SET status = 'liquidated', exit_price = ?, pnl = ?, closed_at = NOW(), close_reason = '触发强平'
                 WHERE id = ? AND status = 'open'"
            );
            $st->execute(array(round($exitPrice, 4), round($pnl, 2), $p['id']));

            if ($st->rowCount() === 1) {
                $pdo->prepare('UPDATE users SET heat = heat + ? WHERE id = ?')
                    ->execute(array(round($payout, 2), $p['user_id']));
                $u = $pdo->prepare('SELECT id, nickname FROM users WHERE id = ?');
                $u->execute(array($p['user_id']));
                $user = $u->fetch() ?: null;
                add_activity(
                    $pdo,
                    $user,
                    'liquidation',
                    '爆仓强平：' . $p['market_name'] . ' ' . $label . ' ' . $p['leverage'] . 'x，损失 ' . number_format(abs($pnl), 0) . ' 热力值',
                    round($pnl, 2),
                    $p['symbol']
                );
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('liquidation failed: ' . $e->getMessage());
        }
    }
}

function boot($pdo) {
    ensure_schema($pdo);
    market_tick($pdo);
    liquidate_positions($pdo);
}

// ---------- 用户数据 ----------
function user_positions($pdo, $userId, $prices = null) {
    if ($prices === null) {
        $prices = market_prices($pdo);
    }
    $st = $pdo->prepare("SELECT p.*, m.name AS market_name, m.icon FROM positions p
                         JOIN market m ON m.symbol = p.symbol
                         WHERE p.user_id = ? AND p.status = 'open' ORDER BY p.id DESC");
    $st->execute(array($userId));
    $out = array();
    foreach ($st->fetchAll() as $p) {
        $price = $prices[$p['symbol']] ?? (float) $p['entry_price'];
        $pnl = position_pnl($p, $price);
        $closeAt = strtotime($p['close_after']);
        $out[] = array(
            'id' => (int) $p['id'],
            'symbol' => $p['symbol'],
            'name' => $p['market_name'],
            'icon' => $p['icon'],
            'direction' => $p['direction'],
            'margin' => (float) $p['margin'],
            'leverage' => (int) $p['leverage'],
            'entry_price' => (float) $p['entry_price'],
            'price' => round($price, 4),
            'pnl' => round($pnl, 2),
            'equity' => round((float) $p['margin'] + $pnl, 2),
            'liq_price' => round($p['direction'] === 'long'
                ? (float) $p['entry_price'] * (1 - 0.5 / (int) $p['leverage'])
                : (float) $p['entry_price'] * (1 + 0.5 / (int) $p['leverage']), 4),
            'close_after' => $p['close_after'],
            'close_in' => max(0, $closeAt - time()),
            'opened_at' => $p['opened_at'],
        );
    }
    return $out;
}

function user_net_worth($pdo, $user, $prices = null) {
    if ($prices === null) {
        $prices = market_prices($pdo);
    }
    $positions = user_positions($pdo, $user['id'], $prices);
    $nw = (float) $user['heat'];
    foreach ($positions as $p) {
        $nw += $p['equity'];
    }
    return round($nw, 2);
}

function rank_map($pdo) {
    $prices = market_prices($pdo);
    $users = $pdo->query("SELECT id, nickname, avatar, heat, total_earned FROM users WHERE role <> 'super'")->fetchAll();
    // 盈亏只统计模拟股票：已平仓/强平的 pnl + 持仓浮盈亏；农场、签到、小游戏都不算
    $positions = $pdo->query(
        "SELECT user_id, symbol, direction, margin, leverage, entry_price, exit_price, pnl, status
         FROM positions WHERE status IN ('open', 'closed', 'liquidated')"
    )->fetchAll();

    $byUser = array();
    foreach ($positions as $p) {
        $byUser[(int) $p['user_id']][] = $p;
    }

    $rows = array();
    foreach ($users as $u) {
        $nw = (float) $u['heat'];
        $tradePnl = 0.0;
        $stake = 0.0;

        foreach (($byUser[(int) $u['id']] ?? array()) as $p) {
            $margin = (float) $p['margin'];
            $stake += $margin;

            if ($p['status'] === 'open') {
                $price = $prices[$p['symbol']] ?? (float) $p['entry_price'];
                $nw += position_equity($p, $price);
                $tradePnl += position_pnl($p, $price);
            } else {
                // 已平仓 / 强平：直接使用当时结算的 pnl；旧数据没有 pnl 时用成交价补算
                if ($p['pnl'] !== null) {
                    $tradePnl += (float) $p['pnl'];
                } elseif ((float) $p['exit_price'] > 0) {
                    $tradePnl += position_pnl($p, (float) $p['exit_price']);
                }
            }
        }

        $rows[(int) $u['id']] = array(
            'id' => (int) $u['id'],
            'nickname' => $u['nickname'],
            'avatar_url' => user_avatar_url($u),
            'net_worth' => round($nw, 2),
            'profit' => round($tradePnl, 2),
            'profit_pct' => $stake > 0 ? round($tradePnl / $stake * 100, 1) : 0,
        );
    }

    uasort($rows, function ($a, $b) {
        return $b['net_worth'] <=> $a['net_worth'];
    });

    $rank = 1;
    foreach ($rows as &$r) {
        $r['rank'] = $rank++;
    }
    unset($r);
    return $rows;
}

function checkin_info($user) {
    $today = date('Y-m-d');
    $available = ($user['last_checkin'] !== $today);
    $streak = (int) $user['checkin_streak'];
    $nextStreak = $available ? $streak + 1 : $streak;
    $reward = CHECKIN_BASE + max(0, min($nextStreak - 1, 5)) * 20;
    return array(
        'available' => $available,
        'streak' => $streak,
        'next_reward' => $reward,
        'last_checkin' => $user['last_checkin'],
    );
}

// ---------- 通用工具 ----------
function price_change($pdo, $symbol, $price) {
    $st = $pdo->prepare('SELECT price FROM market_history WHERE symbol = ? AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 1');
    $st->execute(array($symbol));
    $old = $st->fetchColumn();
    if ($old === false || $old === null) {
        return 0.0;
    }
    $old = (float) $old;
    if ($old <= 0) {
        return 0.0;
    }
    return round(($price - $old) / $old * 100, 2);
}

function price_history($pdo, $symbol, $limit = 60) {
    $st = $pdo->prepare('SELECT price FROM market_history WHERE symbol = ? ORDER BY id DESC LIMIT ' . (int) $limit);
    $st->execute(array($symbol));
    $rows = array_reverse($st->fetchAll(PDO::FETCH_COLUMN));
    return array_map('floatval', $rows);
}

function sql_limit($n, $max = 100) {
    return max(1, min((int) $n, $max));
}

function season_current() {
    $y = (int) date('Y');
    $q = (int) ceil((int) date('n') / 3);
    return $y . 'Q' . $q;
}

function season_label($season) {
    if (!preg_match('/^(\d{4})Q([1-4])$/', (string) $season, $m)) {
        return (string) $season;
    }
    $month = ((int) $m[2] - 1) * 3 + 1;
    return $m[1] . '年' . $month . '月';
}

function season_quarters($count = 6) {
    $out = array();
    $y = (int) date('Y');
    $q = (int) ceil((int) date('n') / 3);
    for ($i = 0; $i < $count; $i++) {
        $out[] = $y . 'Q' . $q;
        $q--;
        if ($q < 1) {
            $q = 4;
            $y--;
        }
    }
    return $out;
}

function media_url($path) {
    return $path ?: '';
}
