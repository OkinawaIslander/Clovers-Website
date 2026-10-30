<?php
/**
 * 交易：开仓 / 平仓
 * POST { action: "open", symbol, direction: "long"|"short", margin, leverage }
 * POST { action: "close", position_id }
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
$user = require_login($pdo);
boot($pdo);

require_post();
$input = getInput();
$action = $input['action'] ?? '';

function user_heat($pdo, $uid) {
    $st = $pdo->prepare('SELECT heat FROM users WHERE id = ?');
    $st->execute(array($uid));
    return round((float) $st->fetchColumn(), 2);
}

function direction_label($d) {
    return $d === 'long' ? '做多' : '做空';
}

try {
    if ($action === 'open') {
        $window = trade_window();
        if (!$window['open']) {
            json_fail('现在不在开仓时间，每天 ' . TRADE_OPEN_START . ':00 - ' . TRADE_OPEN_END . ':00 开放买入和做空');
        }

        $symbol = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($input['symbol'] ?? '')));
        $direction = ($input['direction'] ?? '') === 'short' ? 'short' : 'long';
        $leverage = (int) ($input['leverage'] ?? 1);
        $margin = round((float) ($input['margin'] ?? 0), 2);

        $st = $pdo->prepare('SELECT * FROM market WHERE symbol = ?');
        $st->execute(array($symbol));
        $market = $st->fetch();
        if (!$market) {
            json_fail('没有这个部门');
        }
        if (!in_array($leverage, leverage_list(), true)) {
            json_fail('杠杆只能是 1 / 2 / 4 / 5 / 10 倍');
        }
        if ($margin < MIN_MARGIN) {
            json_fail('保证金至少 ' . MIN_MARGIN . ' 热力值');
        }
        if ($margin > MAX_MARGIN) {
            json_fail('单笔保证金不能超过 ' . MAX_MARGIN);
        }

        $pdo->beginTransaction();

        $st = $pdo->prepare('SELECT id, nickname, heat FROM users WHERE id = ? FOR UPDATE');
        $st->execute(array($user['id']));
        $me = $st->fetch();
        if (!$me) {
            $pdo->rollBack();
            json_fail('用户不存在，请重新登录', 401);
        }
        if ((float) $me['heat'] < $margin) {
            $pdo->rollBack();
            json_fail('热力值不足（当前 ' . round((float) $me['heat'], 2) . '）');
        }

        $st = $pdo->prepare("SELECT COUNT(*) FROM positions WHERE user_id = ? AND status = 'open'");
        $st->execute(array($user['id']));
        if ((int) $st->fetchColumn() >= MAX_OPEN_POSITIONS) {
            $pdo->rollBack();
            json_fail('最多同时持有 ' . MAX_OPEN_POSITIONS . ' 个仓位，先平掉一些吧');
        }

        $entry = market_execution_price($market['price'], $market, $direction, true);
        $hours = leverage_hours($leverage);
        $closeAfter = date('Y-m-d H:i:s', time() + $hours * 3600);

        $pdo->prepare('UPDATE users SET heat = heat - ? WHERE id = ?')->execute(array($margin, $user['id']));
        $pdo->prepare(
            'INSERT INTO positions (user_id, symbol, direction, margin, leverage, entry_price, close_after, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, "open")'
        )->execute(array($user['id'], $symbol, $direction, $margin, $leverage, round($entry, 4), $closeAfter));
        $positionId = (int) $pdo->lastInsertId();

        // 人为开仓推动价格：做多买入推高，做空卖出压低
        market_apply_impact($pdo, $symbol, $direction === 'long' ? 1 : -1, (float) $margin * (int) $leverage);

        add_activity(
            $pdo,
            array('id' => $user['id'], 'nickname' => $user['nickname']),
            'open',
            direction_label($direction) . ' ' . $market['name'] . ' ' . $leverage . 'x，保证金 ' . number_format($margin, 0) . ' 热力值',
            null,
            $symbol
        );

        $pdo->commit();

        json_ok(array(
            'position_id' => $positionId,
            'entry_price' => round($entry, 2),
            'close_after' => $closeAfter,
            'heat' => user_heat($pdo, $user['id']),
        ), '开仓成功');
    }

    if ($action === 'close') {
        $positionId = (int) ($input['position_id'] ?? 0);
        if ($positionId <= 0) {
            json_fail('仓位不存在');
        }

        $pdo->beginTransaction();

        $st = $pdo->prepare("SELECT * FROM positions WHERE id = ? AND user_id = ? AND status = 'open' FOR UPDATE");
        $st->execute(array($positionId, $user['id']));
        $p = $st->fetch();
        if (!$p) {
            $pdo->rollBack();
            json_fail('仓位不存在或已经平仓');
        }

        $closeAt = strtotime($p['close_after']);
        if (time() < $closeAt) {
            $pdo->rollBack();
            json_fail('还在杠杆冷却期，还需等待 ' . ceil(($closeAt - time()) / 3600) . ' 小时');
        }

        $st = $pdo->prepare('SELECT * FROM market WHERE symbol = ?');
        $st->execute(array($p['symbol']));
        $market = $st->fetch();
        if (!$market) {
            $pdo->rollBack();
            json_fail('行情数据缺失');
        }

        $exitPrice = market_execution_price($market['price'], $market, $p['direction'], false);
        $pnl = round(position_pnl($p, $exitPrice), 2);
        $payout = max(0.0, round((float) $p['margin'] + $pnl, 2));

        $pdo->prepare(
            "UPDATE positions SET status = 'closed', exit_price = ?, pnl = ?, closed_at = NOW(), close_reason = '手动平仓'
             WHERE id = ? AND status = 'open'"
        )->execute(array(round($exitPrice, 4), $pnl, $positionId));

        $pdo->prepare('UPDATE users SET heat = heat + ? WHERE id = ?')->execute(array($payout, $user['id']));

        // 平仓也是反向交易：平多卖出压低，平空买入推高
        market_apply_impact($pdo, $p['symbol'], $p['direction'] === 'long' ? -1 : 1, (float) $p['margin'] * (int) $p['leverage']);

        add_activity(
            $pdo,
            array('id' => $user['id'], 'nickname' => $user['nickname']),
            'close',
            '平仓 ' . $market['name'] . ' ' . direction_label($p['direction']) . '，' . ($pnl >= 0 ? '盈利 ' : '亏损 ') . number_format(abs($pnl), 0) . ' 热力值',
            $pnl,
            $p['symbol']
        );

        $pdo->commit();

        json_ok(array(
            'pnl' => $pnl,
            'payout' => $payout,
            'exit_price' => round($exitPrice, 2),
            'heat' => user_heat($pdo, $user['id']),
        ), '平仓成功');
    }

    json_fail('未知操作');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_fail('交易失败：' . $e->getMessage(), 500);
}
