<?php
/**
 * 排行榜
 * GET: 净资产榜 + 收益率榜
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
boot($pdo);

$ranks = rank_map($pdo);

$net = array_values($ranks);
usort($net, function ($a, $b) {
    return $b['net_worth'] <=> $a['net_worth'];
});
$net = array_slice($net, 0, 20);

$profit = array_values($ranks);
usort($profit, function ($a, $b) {
    return $b['profit_pct'] <=> $a['profit_pct'];
});
$profit = array_slice($profit, 0, 20);

json_ok(array(
    'net_worth' => $net,
    'profit' => $profit,
));
