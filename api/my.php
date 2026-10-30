<?php
/**
 * 我的主页数据：热力值 / 小游戏 / 持仓 / 留言 / 评论 / 投稿 / 投票
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
boot($pdo);

$user = require_login($pdo);
$uid = (int) $user['id'];
$prices = market_prices($pdo);
$ranks = rank_map($pdo);
$mine = $ranks[$uid] ?? null;

// 小游戏统计
$st = $pdo->prepare('SELECT COUNT(*) AS runs, COALESCE(SUM(heat),0) AS total_heat, COALESCE(MAX(score),0) AS best FROM game_runs WHERE user_id = ?');
$st->execute(array($uid));
$game = $st->fetch();
$st = $pdo->prepare('SELECT COALESCE(SUM(heat),0) FROM game_runs WHERE user_id = ? AND DATE(created_at) = CURDATE()');
$st->execute(array($uid));
$gameToday = (float) $st->fetchColumn();

// 留言
$st = $pdo->prepare('SELECT id, content, is_deleted, created_at FROM posts WHERE user_id = ? ORDER BY id DESC LIMIT 50');
$st->execute(array($uid));
$posts = array();
foreach ($st->fetchAll() as $p) {
    $posts[] = array(
        'id' => (int) $p['id'],
        'content' => $p['content'],
        'is_deleted' => (int) $p['is_deleted'] === 1,
        'created_at' => $p['created_at'],
        'time_ago' => time_ago($p['created_at']),
    );
}

// 番剧评论
$st = $pdo->prepare(
    'SELECT c.id, c.subject_id, c.content, c.is_deleted, c.created_at, a.title, a.title_cn
     FROM anime_comments c LEFT JOIN anime a ON a.id = c.subject_id
     WHERE c.user_id = ? ORDER BY c.id DESC LIMIT 50'
);
$st->execute(array($uid));
$comments = array();
foreach ($st->fetchAll() as $c) {
    $comments[] = array(
        'id' => (int) $c['id'],
        'subject_id' => (int) $c['subject_id'],
        'title' => $c['title_cn'] ?: $c['title'],
        'content' => $c['content'],
        'is_deleted' => (int) $c['is_deleted'] === 1,
        'created_at' => $c['created_at'],
        'time_ago' => time_ago($c['created_at']),
    );
}

// 投稿
$st = $pdo->prepare('SELECT * FROM submissions WHERE user_id = ? ORDER BY id DESC LIMIT 50');
$st->execute(array($uid));
$submissions = array();
foreach ($st->fetchAll() as $s) {
    $submissions[] = array(
        'id' => (int) $s['id'],
        'title' => $s['title'],
        'category' => $s['category'],
        'status' => $s['status'],
        'admin_note' => $s['admin_note'],
        'original_name' => $s['original_name'],
        'file_size' => (int) $s['file_size'],
        'created_at' => $s['created_at'],
        'time_ago' => time_ago($s['created_at']),
        'download_url' => 'api/submission_file.php?id=' . (int) $s['id'],
        'can_delete' => $s['status'] === 'pending',
    );
}

// 我的投票
$st = $pdo->prepare(
    'SELECT p.id, p.title, p.starts_at, p.ends_at, p.is_closed, o.label, v.created_at
     FROM poll_votes v
     JOIN polls p ON p.id = v.poll_id
     JOIN poll_options o ON o.id = v.option_id
     WHERE v.user_id = ? AND p.is_deleted = 0 ORDER BY v.id DESC LIMIT 50'
);
$st->execute(array($uid));
$votes = array();
foreach ($st->fetchAll() as $v) {
    $now = time();
    $status = ((int) $v['is_closed'] === 1 || $now > strtotime($v['ends_at'])) ? 'closed' : ($now < strtotime($v['starts_at']) ? 'upcoming' : 'open');
    $votes[] = array(
        'poll_id' => (int) $v['id'],
        'title' => $v['title'],
        'option' => $v['label'],
        'status' => $status,
        'created_at' => $v['created_at'],
    );
}

// 交易记录
$hist = $pdo->prepare(
    "SELECT p.*, m.name AS market_name, m.icon
     FROM positions p JOIN market m ON m.symbol = p.symbol
     WHERE p.user_id = ? AND p.status <> 'open'
     ORDER BY p.closed_at DESC LIMIT 30"
);
$hist->execute(array($uid));
$history = array();
foreach ($hist->fetchAll() as $h) {
    $history[] = array(
        'id' => (int) $h['id'],
        'name' => $h['market_name'],
        'icon' => $h['icon'],
        'direction' => $h['direction'],
        'leverage' => (int) $h['leverage'],
        'pnl' => (float) $h['pnl'],
        'status' => $h['status'],
        'closed_at' => $h['closed_at'],
    );
}

json_ok(array(
    'user' => array(
        'id' => $uid,
        'nickname' => $user['nickname'],
        'email' => $user['email'],
        'role' => $user['role'],
        'created_at' => $user['created_at'],
        'heat' => round((float) $user['heat'], 2),
        'signature' => $user['signature'],
        'avatar_url' => user_avatar_url($user),
        'departments' => user_departments($user),
    ),
    'is_admin' => is_admin($user),
    'is_super' => is_super($user),
    'net_worth' => user_net_worth($pdo, $user, $prices),
    'rank' => $mine ? $mine['rank'] : null,
    'profit' => $mine ? $mine['profit'] : 0,
    'profit_pct' => $mine ? $mine['profit_pct'] : 0,
    'checkin' => checkin_info($user),
    'game' => array(
        'runs' => (int) $game['runs'],
        'total_heat' => round((float) $game['total_heat'], 2),
        'today_heat' => round($gameToday, 2),
        'best' => (int) $game['best'],
    ),
    'positions' => user_positions($pdo, $uid, $prices),
    'history' => $history,
    'posts' => $posts,
    'comments' => $comments,
    'submissions' => $submissions,
    'votes' => $votes,
    'activities' => array(),
));
