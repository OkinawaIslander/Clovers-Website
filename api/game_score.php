<?php
/**
 * 小游戏得分兑换热力值
 * POST: { "game": "survivors", "score": 123 }
 *
 * 1 分 = 1 热力值；每个游戏有独立每日上限。
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);
$user = require_login($pdo);
require_post();

$input = getInput();
$game = (string) ($input['game'] ?? 'clover');
$score = (int) ($input['score'] ?? 0);

$games = array(
    'clover' => array(
        'name' => '四叶草小游戏',
        'cap' => defined('GAME_DAILY_CAP') ? GAME_DAILY_CAP : 3000,
        'max' => 10000,
    ),
    'survivors' => array(
        'name' => '四叶草幸存者',
        'cap' => defined('SURVIVORS_DAILY_CAP') ? SURVIVORS_DAILY_CAP : 1200,
        'max' => 6000,
    ),
);

if (!isset($games[$game])) {
    json_fail('未知的小游戏');
}
$cfg = $games[$game];

if ($score < 0 || $score > $cfg['max']) {
    json_fail('分数不合法');
}
if ($score === 0) {
    json_fail('本局没有得分，再来一局吧');
}

// 防刷：同一游戏 10 秒内只能提交一次
$st = $pdo->prepare('SELECT created_at FROM game_runs WHERE user_id = ? AND game = ? ORDER BY id DESC LIMIT 1');
$st->execute(array($user['id'], $game));
$last = $st->fetchColumn();
if ($last && time() - strtotime($last) < 10) {
    json_fail('提交太快了，休息一下再来');
}

$st = $pdo->prepare('SELECT COALESCE(SUM(heat), 0) FROM game_runs WHERE user_id = ? AND game = ? AND DATE(created_at) = CURDATE()');
$st->execute(array($user['id'], $game));
$earnedToday = (float) $st->fetchColumn();

$remaining = $cfg['cap'] - $earnedToday;
if ($remaining <= 0) {
    json_fail('今天的「' . $cfg['name'] . '」热力值已经拿满了（' . $cfg['cap'] . '），明天再来');
}

$earn = (float) min($score, $remaining);

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO game_runs (user_id, game, score, heat) VALUES (?, ?, ?, ?)')
        ->execute(array($user['id'], $game, $score, $earn));
    $pdo->prepare('UPDATE users SET heat = heat + ?, total_earned = total_earned + ? WHERE id = ?')
        ->execute(array($earn, $earn, $user['id']));

    add_activity(
        $pdo,
        array('id' => $user['id'], 'nickname' => $user['nickname']),
        'game',
        $cfg['name'] . ' ' . $score . ' 分，兑换 ' . number_format($earn, 0) . ' 热力值',
        $earn,
        null
    );
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_fail('兑换失败：' . $e->getMessage(), 500);
}

$freshSt = $pdo->prepare('SELECT heat FROM users WHERE id = ?');
$freshSt->execute(array($user['id']));
$freshHeat = (float) $freshSt->fetchColumn();

json_ok(array(
    'game' => $game,
    'game_name' => $cfg['name'],
    'earned' => $earn,
    'score' => $score,
    'cap' => $cfg['cap'],
    'remaining_cap' => max(0, $remaining - $earn),
    'heat' => round($freshHeat, 2),
), '本局兑换 ' . number_format($earn, 0) . ' 热力值');
