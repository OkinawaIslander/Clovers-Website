<?php
/**
 * 个人主页
 * GET ?id=1  不传则看自己（需要登录）
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
boot($pdo);

$me = current_user($pdo);
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    if (!$me) {
        json_fail('请先登录', 401);
    }
    $id = (int) $me['id'];
}

$st = $pdo->prepare('SELECT id, nickname, role, heat, total_earned, created_at, signature, avatar, avatar_mime, departments FROM users WHERE id = ?');
$st->execute(array($id));
$user = $st->fetch();
if (!$user) {
    json_fail('用户不存在', 404);
}
if ($user['role'] === 'super') {
    json_fail('用户不存在', 404);
}

$isSelf = $me && (int) $me['id'] === $id;
$ranks = rank_map($pdo);
$rank = $ranks[$id] ?? null;

$data = array(
    'user' => array(
        'id' => (int) $user['id'],
        'nickname' => $user['nickname'],
        'role' => $user['role'],
        'created_at' => $user['created_at'],
        'signature' => $user['signature'],
        'avatar_url' => user_avatar_url($user),
        'departments' => user_departments($user),
    ),
    'stats' => array(
        'net_worth' => $rank ? $rank['net_worth'] : 0,
        'rank' => $rank ? $rank['rank'] : null,
        'profit' => $rank ? $rank['profit'] : 0,
        'profit_pct' => $rank ? $rank['profit_pct'] : 0,
    ),
    'is_self' => $isSelf,
    'activities' => array(),
);

if ($isSelf) {
    $data['user']['email'] = $me['email'];
    $data['user']['heat'] = round((float) $me['heat'], 2);
    $data['checkin'] = checkin_info($me);
    $data['positions'] = user_positions($pdo, $id);
    $data['is_admin'] = is_admin($me);

    $hist = $pdo->prepare(
        "SELECT p.*, m.name AS market_name, m.icon
         FROM positions p JOIN market m ON m.symbol = p.symbol
         WHERE p.user_id = ? AND p.status <> 'open'
         ORDER BY p.closed_at DESC LIMIT 20"
    );
    $hist->execute(array($id));
    $history = array();
    foreach ($hist->fetchAll() as $h) {
        $history[] = array(
            'id' => (int) $h['id'],
            'name' => $h['market_name'],
            'icon' => $h['icon'],
            'direction' => $h['direction'],
            'leverage' => (int) $h['leverage'],
            'margin' => (float) $h['margin'],
            'entry_price' => (float) $h['entry_price'],
            'exit_price' => (float) $h['exit_price'],
            'pnl' => (float) $h['pnl'],
            'status' => $h['status'],
            'reason' => $h['close_reason'],
            'closed_at' => $h['closed_at'],
        );
    }
    $data['history'] = $history;
}

$st = $pdo->prepare('SELECT * FROM activities WHERE user_id = ? ORDER BY id DESC LIMIT 20');
$st->execute(array($id));
$labels = array(
    'open' => '开仓', 'close' => '平仓', 'liquidation' => '强平',
    'checkin' => '签到', 'game' => '小游戏', 'join' => '加入',
    'post' => '留言', 'submit' => '投稿', 'comment' => '评论',
    'admin' => '管理', 'system' => '公告',
);
foreach ($st->fetchAll() as $a) {
    $data['activities'][] = array(
        'type' => $a['type'],
        'type_label' => $labels[$a['type']] ?? $a['type'],
        'user_id' => $id,
        'nickname' => $user['nickname'],
        'text' => $a['text'],
        'amount' => $a['amount'] !== null ? (float) $a['amount'] : null,
        'created_at' => $a['created_at'],
        'time_ago' => time_ago($a['created_at']),
    );
}

json_ok($data);
