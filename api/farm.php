<?php
/**
 * 像素农场
 *
 * GET  ?action=state            我的农场
 * GET  ?action=shop             种子商店
 * GET  ?action=users            可访问的农场列表
 * GET  ?action=visit&id=2       逛别人的农场
 * POST { action:"plant", plot_index:0, crop:"clover" }
 * POST { action:"harvest", plot_id:1 }
 * POST { action:"steal", plot_id:2 }
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);

$user = require_login($pdo);
$action = (string) ($_GET['action'] ?? '');
$input = getInput();
if ($action === '') {
    $action = (string) ($input['action'] ?? 'state');
}

function farm_crops() {
    return defined('FARM_CROPS') ? FARM_CROPS : array(
        'clover' => array('name' => '四叶草', 'emoji' => '🍀', 'cost' => 50, 'sell' => 90, 'grow' => 3600),
        'tomato' => array('name' => '番茄', 'emoji' => '🍅', 'cost' => 100, 'sell' => 190, 'grow' => 7200),
        'pumpkin' => array('name' => '南瓜', 'emoji' => '🎃', 'cost' => 180, 'sell' => 360, 'grow' => 14400),
        'star' => array('name' => '星星花', 'emoji' => '🌟', 'cost' => 300, 'sell' => 620, 'grow' => 28800),
    );
}

function farm_crop($key) {
    $crops = farm_crops();
    return isset($crops[$key]) ? $crops[$key] : null;
}

function farm_plot_view($row, $own = true) {
    $crop = $row ? farm_crop($row['crop']) : null;
    if (!$row || !$crop) {
        return array('empty' => true);
    }

    $readyTs = strtotime($row['ready_at']);
    $ready = $readyTs <= time();
    $stolen = (int) $row['stolen'] === 1;
    $sell = (float) $crop['sell'];

    $out = array(
        'empty' => false,
        'id' => (int) $row['id'],
        'plot_index' => (int) $row['plot_index'],
        'crop' => $row['crop'],
        'name' => $crop['name'],
        'emoji' => $crop['emoji'],
        'grow' => (int) $crop['grow'],
        'sell' => $sell,
        'ready' => $ready,
        'remaining' => max(0, $readyTs - time()),
        'stolen' => $stolen,
        'planted_at' => $row['planted_at'],
        'ready_at' => $row['ready_at'],
    );

    if ($own) {
        $out['payout'] = round($sell * ($stolen ? 0.7 : 1));
    } else {
        $out['steal_reward'] = round($sell * (defined('FARM_STEAL_RATE') ? FARM_STEAL_RATE : 0.2));
        $out['can_steal'] = $ready && !$stolen;
    }

    return $out;
}

function farm_slots($rows, $own = true) {
    $total = defined('FARM_PLOTS') ? (int) FARM_PLOTS : 4;
    $byIndex = array();
    foreach ($rows as $row) {
        $byIndex[(int) $row['plot_index']] = $row;
    }

    $slots = array();
    for ($i = 0; $i < $total; $i++) {
        $slot = isset($byIndex[$i]) ? farm_plot_view($byIndex[$i], $own) : array('empty' => true);
        $slot['index'] = $i;
        $slot['plot_index'] = $i;
        $slots[] = $slot;
    }
    return $slots;
}

function farm_heat($pdo, $uid) {
    $st = $pdo->prepare('SELECT heat FROM users WHERE id = ?');
    $st->execute(array($uid));
    return round((float) $st->fetchColumn(), 2);
}

function farm_user_view($row) {
    return array(
        'id' => (int) $row['id'],
        'nickname' => $row['nickname'],
        'avatar_url' => user_avatar_url($row),
        'ripe' => isset($row['ripe']) ? (int) $row['ripe'] : 0,
    );
}

function farm_steal_remaining($pdo, $uid) {
    $cap = defined('FARM_STEAL_DAILY_CAP') ? (float) FARM_STEAL_DAILY_CAP : 500;
    $st = $pdo->prepare('SELECT COALESCE(SUM(heat), 0) FROM farm_steals WHERE thief_id = ? AND DATE(created_at) = CURDATE()');
    $st->execute(array($uid));
    return max(0, $cap - (float) $st->fetchColumn());
}

// ---------- 我的农场 ----------
if ($action === 'state') {
    $st = $pdo->prepare('SELECT * FROM farm_plots WHERE user_id = ? ORDER BY plot_index ASC');
    $st->execute(array($user['id']));

    $stealRemaining = farm_steal_remaining($pdo, $user['id']);
    json_ok(array(
        'user' => array(
            'id' => (int) $user['id'],
            'nickname' => $user['nickname'],
            'avatar_url' => user_avatar_url($user),
            'heat' => farm_heat($pdo, $user['id']),
        ),
        'plots' => farm_slots($st->fetchAll(), true),
        'crops' => farm_crops(),
        'steal_remaining' => round($stealRemaining, 2),
        'steal_cap' => defined('FARM_STEAL_DAILY_CAP') ? FARM_STEAL_DAILY_CAP : 500,
        'server_time' => date('Y-m-d H:i:s'),
    ));
}

if ($action === 'shop') {
    json_ok(array('crops' => farm_crops(), 'plots' => defined('FARM_PLOTS') ? FARM_PLOTS : 4));
}

// ---------- 别人家 ----------
if ($action === 'users') {
    $st = $pdo->prepare(
        "SELECT u.id, u.nickname, u.avatar,
                (SELECT COUNT(*) FROM farm_plots p
                  WHERE p.user_id = u.id AND p.ready_at <= NOW() AND p.stolen = 0) AS ripe
         FROM users u
         WHERE u.role <> 'super' AND u.id <> ?
         ORDER BY ripe DESC, u.id DESC LIMIT 40"
    );
    $st->execute(array($user['id']));
    $items = array();
    foreach ($st->fetchAll() as $row) {
        $items[] = farm_user_view($row);
    }
    json_ok(array('items' => $items));
}

if ($action === 'visit') {
    $id = (int) ($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT id, nickname, avatar FROM users WHERE id = ? AND role <> 'super'");
    $st->execute(array($id));
    $target = $st->fetch();
    if (!$target) {
        json_fail('这个农场不存在', 404);
    }

    $ps = $pdo->prepare('SELECT * FROM farm_plots WHERE user_id = ? ORDER BY plot_index ASC');
    $ps->execute(array($id));
    json_ok(array(
        'user' => farm_user_view($target),
        'plots' => farm_slots($ps->fetchAll(), false),
        'steal_remaining' => round(farm_steal_remaining($pdo, $user['id']), 2),
    ));
}

// ---------- 写操作 ----------
require_post();

if ($action === 'plant') {
    $index = (int) ($input['plot_index'] ?? -1);
    $cropKey = (string) ($input['crop'] ?? '');
    $crop = farm_crop($cropKey);
    $total = defined('FARM_PLOTS') ? (int) FARM_PLOTS : 4;

    if ($index < 0 || $index >= $total || !$crop) {
        json_fail('地块或种子不正确');
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM farm_plots WHERE user_id = ? AND plot_index = ?');
        $st->execute(array($user['id'], $index));
        if ($st->fetchColumn()) {
            throw new RuntimeException('这块地已经种了');
        }

        $cost = (float) $crop['cost'];
        $up = $pdo->prepare('UPDATE users SET heat = heat - ? WHERE id = ? AND heat >= ?');
        $up->execute(array($cost, $user['id'], $cost));
        if ($up->rowCount() < 1) {
            throw new RuntimeException('热力值不够买这个种子');
        }

        $ready = date('Y-m-d H:i:s', time() + (int) $crop['grow']);
        $pdo->prepare(
            'INSERT INTO farm_plots (user_id, plot_index, crop, planted_at, ready_at)
             VALUES (?, ?, ?, NOW(), ?)'
        )->execute(array($user['id'], $index, $cropKey, $ready));

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_fail($e->getMessage());
    }

    json_ok(array(
        'plot_index' => $index,
        'crop' => $crop,
        'heat' => farm_heat($pdo, $user['id']),
    ), '种下了 ' . $crop['name']);
}

if ($action === 'harvest') {
    $plotId = (int) ($input['plot_id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM farm_plots WHERE id = ? AND user_id = ?');
    $st->execute(array($plotId, $user['id']));
    $plot = $st->fetch();
    if (!$plot) {
        json_fail('这块地没有作物');
    }
    if (strtotime($plot['ready_at']) > time()) {
        json_fail('还没熟，再等等');
    }

    $crop = farm_crop($plot['crop']);
    if (!$crop) {
        json_fail('作物数据异常');
    }
    $payout = round((float) $crop['sell'] * ((int) $plot['stolen'] === 1 ? 0.7 : 1));

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM farm_plots WHERE id = ? AND user_id = ?');
        $del->execute(array($plotId, $user['id']));
        if ($del->rowCount() < 1) {
            throw new RuntimeException('这块地已经被收过了');
        }

        $pdo->prepare('UPDATE users SET heat = heat + ?, total_earned = total_earned + ? WHERE id = ?')
            ->execute(array($payout, $payout, $user['id']));

        add_activity(
            $pdo,
            array('id' => $user['id'], 'nickname' => $user['nickname']),
            'farm',
            '收获了 ' . $crop['name'] . '，卖出 ' . number_format($payout, 0) . ' 热力值',
            $payout,
            null
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_fail($e->getMessage());
    }

    json_ok(array('payout' => $payout, 'heat' => farm_heat($pdo, $user['id'])), '卖出 ' . number_format($payout, 0) . ' 热力值');
}

if ($action === 'steal') {
    $plotId = (int) ($input['plot_id'] ?? 0);

    $st = $pdo->prepare(
        'SELECT p.*, u.nickname AS owner_name, u.role AS owner_role
         FROM farm_plots p JOIN users u ON u.id = p.user_id
         WHERE p.id = ?'
    );
    $st->execute(array($plotId));
    $plot = $st->fetch();

    if (!$plot) {
        json_fail('这块地没有作物', 404);
    }
    if ((int) $plot['user_id'] === (int) $user['id']) {
        json_fail('不能偷自己的菜');
    }
    if ($plot['owner_role'] === 'super') {
        json_fail('这块地不能偷', 403);
    }
    if (strtotime($plot['ready_at']) > time()) {
        json_fail('还没熟，偷不了');
    }
    if ((int) $plot['stolen'] === 1) {
        json_fail('已经被偷过了');
    }

    $remaining = farm_steal_remaining($pdo, $user['id']);
    if ($remaining < 1) {
        json_fail('今天偷菜的热力值已经到上限了');
    }

    $crop = farm_crop($plot['crop']);
    if (!$crop) {
        json_fail('作物数据异常');
    }
    $reward = min($remaining, round((float) $crop['sell'] * (defined('FARM_STEAL_RATE') ? FARM_STEAL_RATE : 0.2)));

    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('UPDATE farm_plots SET stolen = 1 WHERE id = ? AND stolen = 0');
        $up->execute(array($plotId));
        if ($up->rowCount() < 1) {
            throw new RuntimeException('慢了一步，已经被别人偷了');
        }

        $pdo->prepare('UPDATE users SET heat = heat + ?, total_earned = total_earned + ? WHERE id = ?')
            ->execute(array($reward, $reward, $user['id']));
        $pdo->prepare('INSERT INTO farm_steals (thief_id, owner_id, plot_id, crop, heat) VALUES (?, ?, ?, ?, ?)')
            ->execute(array($user['id'], $plot['user_id'], $plotId, $plot['crop'], $reward));

        add_activity(
            $pdo,
            array('id' => $user['id'], 'nickname' => $user['nickname']),
            'farm',
            '偷偷摘走了 ' . $plot['owner_name'] . ' 的 ' . $crop['name'] . '，+ ' . number_format($reward, 0) . ' 热力值',
            $reward,
            null
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_fail($e->getMessage());
    }

    json_ok(array(
        'reward' => $reward,
        'heat' => farm_heat($pdo, $user['id']),
        'steal_remaining' => round(farm_steal_remaining($pdo, $user['id']), 2),
    ), '偷到 ' . number_format($reward, 0) . ' 热力值');
}

json_fail('未知操作', 404);
