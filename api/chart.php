<?php
/**
 * 部门热力走势
 * GET ?symbol=xuanbian&period=5m|1h|1d
 *
 * 返回聚合后的时间点，供前端的折线图 + 悬浮提示使用。
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
boot($pdo);

$symbol = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_GET['symbol'] ?? '')));
$period = (string) ($_GET['period'] ?? '5m');

$config = array(
    '5m' => array('bucket' => 300, 'span' => 86400, 'points' => 320),
    '1h' => array('bucket' => 3600, 'span' => 7 * 86400, 'points' => 200),
    '1d' => array('bucket' => 86400, 'span' => 120 * 86400, 'points' => 130),
);
if (!isset($config[$period])) {
    $period = '5m';
}

$st = $pdo->prepare('SELECT symbol, name, icon, price, spread_bps FROM market WHERE symbol = ?');
$st->execute(array($symbol));
$market = $st->fetch();
if (!$market) {
    json_fail('没有这个部门');
}

$bucket = (int) $config[$period]['bucket'];
$span = (int) $config[$period]['span'];

// 按时间桶求平均价；整数来自白名单，不存在注入
$sql = "SELECT FLOOR(UNIX_TIMESTAMP(created_at) / {$bucket}) * {$bucket} AS ts,
               AVG(price) AS price
        FROM market_history
        WHERE symbol = ? AND created_at >= DATE_SUB(NOW(), INTERVAL {$span} SECOND)
        GROUP BY ts
        ORDER BY ts ASC";
$st = $pdo->prepare($sql);
$st->execute(array($symbol));

$points = array();
foreach ($st->fetchAll() as $row) {
    $ts = (int) $row['ts'];
    $points[] = array(
        'ts' => $ts,
        'price' => round((float) $row['price'], 2),
        'label' => date($period === '1d' ? 'm-d' : 'm-d H:i', $ts),
    );
}

// 把“当前价”补在最后，保证图表末端是实时价格
$current = round((float) $market['price'], 2);
$now = time();
if (!$points) {
    $points[] = array('ts' => $now, 'price' => $current, 'label' => date('m-d H:i', $now));
} else {
    $last = end($points);
    if ($now - $last['ts'] > 60) {
        $points[] = array('ts' => $now, 'price' => $current, 'label' => date('m-d H:i', $now));
    } else {
        $points[count($points) - 1]['price'] = $current;
        $points[count($points) - 1]['label'] = date($period === '1d' ? 'm-d' : 'm-d H:i', $last['ts']);
    }
}

// 控制点数
$max = (int) $config[$period]['points'];
if (count($points) > $max) {
    $points = array_slice($points, -$max);
}

$first = $points[0]['price'];
$lastPrice = $points[count($points) - 1]['price'];
$change = $first > 0 ? round(($lastPrice - $first) / $first * 100, 2) : 0;
$min = min(array_column($points, 'price'));
$maxPrice = max(array_column($points, 'price'));

json_ok(array(
    'symbol' => $market['symbol'],
    'name' => $market['name'],
    'icon' => $market['icon'],
    'price' => $current,
    'spread_bps' => round((float) $market['spread_bps'], 2),
    'period' => $period,
    'change' => $change,
    'min' => round((float) $min, 2),
    'max' => round((float) $maxPrice, 2),
    'points' => $points,
));
