<?php
/**
 * 当前登录用户 + 资产 + 持仓 + 签到状态
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
boot($pdo);

$user = current_user($pdo);
$data = array(
    'logged_in' => (bool) $user,
    'window' => trade_window(),
);

if ($user) {
    $prices = market_prices($pdo);
    $positions = user_positions($pdo, $user['id'], $prices);
    $ranks = rank_map($pdo);
    $mine = $ranks[(int) $user['id']] ?? null;

    $data['user'] = array(
        'id' => (int) $user['id'],
        'nickname' => $user['nickname'],
        'avatar_url' => user_avatar_url($user),
        'email' => $user['email'],
        'role' => $user['role'],
        'heat' => round((float) $user['heat'], 2),
        'total_earned' => round((float) $user['total_earned'], 2),
        'created_at' => $user['created_at'],
    );
    $data['is_admin'] = is_admin($user);
    $data['positions'] = $positions;
    $data['net_worth'] = user_net_worth($pdo, $user, $prices);
    $data['rank'] = $mine ? $mine['rank'] : null;
    $data['profit'] = $mine ? $mine['profit'] : 0;
    $data['profit_pct'] = $mine ? $mine['profit_pct'] : 0;
    $data['checkin'] = checkin_info($user);
}

json_ok($data);
