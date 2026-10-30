<?php
/**
 * 每日签到
 * POST: {}
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
$user = require_login($pdo);
require_post();
boot($pdo);

$today = date('Y-m-d');
if ($user['last_checkin'] === $today) {
    $info = checkin_info($user);
    json_fail('今天已经签到过了，明天再来', 400, array('checkin' => $info));
}

$yesterday = date('Y-m-d', strtotime('-1 day'));
$streak = ($user['last_checkin'] === $yesterday) ? (int) $user['checkin_streak'] + 1 : 1;
$reward = CHECKIN_BASE + max(0, min($streak - 1, 5)) * 20;

$pdo->beginTransaction();
try {
    $st = $pdo->prepare('UPDATE users SET heat = heat + ?, total_earned = total_earned + ?, last_checkin = ?, checkin_streak = ? WHERE id = ?');
    $st->execute(array($reward, $reward, $today, $streak, $user['id']));

    add_activity(
        $pdo,
        array('id' => $user['id'], 'nickname' => $user['nickname']),
        'checkin',
        '签到成功，连续 ' . $streak . ' 天，获得 ' . $reward . ' 热力值',
        $reward,
        null
    );

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_fail('签到失败：' . $e->getMessage(), 500);
}

$freshSt = $pdo->prepare('SELECT id, nickname, email, role, heat, total_earned, last_checkin, checkin_streak, created_at FROM users WHERE id = ?');
$freshSt->execute(array($user['id']));
$fresh = $freshSt->fetch();
json_ok(array(
    'reward' => $reward,
    'streak' => $streak,
    'heat' => round((float) $fresh['heat'], 2),
    'checkin' => checkin_info($fresh),
), '签到成功，+ ' . $reward . ' 热力值');
