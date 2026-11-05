<?php
/**
 * 热力交易所行情
 * GET: 当前价格、24h 涨跌、迷你走势、我的持仓、交易时段
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
boot($pdo);

$user = current_user($pdo);
$prices = market_prices($pdo);

$myPositions = array();
if ($user) {
    foreach (user_positions($pdo, $user['id'], $prices) as $p) {
        if (!isset($myPositions[$p['symbol']])) {
            $myPositions[$p['symbol']] = $p;
        }
    }
}

$symbols = array();
foreach ($pdo->query('SELECT * FROM market ORDER BY base_price DESC')->fetchAll() as $m) {
    $price = (float) $m['price'];
    $half = market_spread_half($m);
    $symbols[] = array(
        'symbol' => $m['symbol'],
        'name' => $m['name'],
        'icon' => $m['icon'],
        'price' => round($price, 2),
        'bid' => round($price * (1 - $half), 2),
        'ask' => round($price * (1 + $half), 2),
        'spread_bps' => round((float) $m['spread_bps'], 2),
        'volatility' => round(sqrt((float) $m['vol'] * 1440) * 100, 2),
        'base_price' => (float) $m['base_price'],
        'change' => price_change($pdo, $m['symbol'], $price),
        'history' => price_history($pdo, $m['symbol'], 48),
        'position' => $myPositions[$m['symbol']] ?? null,
    );
}

$cooldowns = array();
foreach (leverage_list() as $lev) {
    $cooldowns[(string) $lev] = leverage_hours($lev);
}

json_ok(array(
    'symbols' => $symbols,
    'window' => trade_window(),
    'leverage' => leverage_list(),
    'cooldowns' => $cooldowns,
    'heat' => $user ? round((float) $user['heat'], 2) : null,
    'server_time' => date('Y-m-d H:i:s'),
));
