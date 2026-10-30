<?php
/**
 * 全站动态
 * GET ?limit=30&user_id=1
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
boot($pdo);

$limit = sql_limit($_GET['limit'] ?? 30, 100);
$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

if ($userId > 0) {
    $st = $pdo->prepare(
        "SELECT a.*, u.avatar FROM activities a LEFT JOIN users u ON u.id = a.user_id
         WHERE a.user_id = ? AND (a.user_id IS NULL OR u.role <> 'super')
         ORDER BY a.id DESC LIMIT " . $limit
    );
    $st->execute(array($userId));
} else {
    $st = $pdo->query(
        "SELECT a.*, u.avatar FROM activities a LEFT JOIN users u ON u.id = a.user_id
         WHERE a.user_id IS NULL OR u.role <> 'super'
         ORDER BY a.id DESC LIMIT " . $limit
    );
}

$labels = array(
    'open' => '开仓',
    'close' => '平仓',
    'liquidation' => '强平',
    'checkin' => '签到',
    'game' => '小游戏',
    'join' => '加入',
    'post' => '留言',
    'submit' => '投稿',
    'comment' => '评论',
    'farm' => '农场',
    'admin' => '管理',
    'system' => '公告',
);

$items = array();
foreach ($st->fetchAll() as $a) {
    $items[] = array(
        'id' => (int) $a['id'],
        'user_id' => $a['user_id'] ? (int) $a['user_id'] : null,
        'nickname' => $a['nickname'],
        'avatar_url' => $a['user_id'] ? user_avatar_url(array('id' => (int) $a['user_id'], 'avatar' => $a['avatar'] ?? null)) : null,
        'type' => $a['type'],
        'type_label' => $labels[$a['type']] ?? $a['type'],
        'text' => $a['text'],
        'amount' => $a['amount'] !== null ? (float) $a['amount'] : null,
        'symbol' => $a['symbol'],
        'created_at' => $a['created_at'],
        'time_ago' => time_ago($a['created_at']),
    );
}

json_ok(array('items' => $items));
