<?php
/**
 * 投票
 * GET  : 列表 + 选项 + 票数 + 我的选择
 * POST : action=create（管理员）/ vote / close / delete
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);

$user = current_user($pdo);
$admin = is_admin($user);
$input = getInput();
$action = (string) ($input['action'] ?? '');

function poll_status($poll) {
    $now = time();
    if ((int) $poll['is_closed'] === 1) {
        return 'closed';
    }
    if ($now < strtotime($poll['starts_at'])) {
        return 'upcoming';
    }
    if ($now > strtotime($poll['ends_at'])) {
        return 'closed';
    }
    return 'open';
}

function poll_payload($pdo, $poll, $userId) {
    $st = $pdo->prepare(
        'SELECT o.id, o.label, COUNT(v.id) AS votes
         FROM poll_options o LEFT JOIN poll_votes v ON v.option_id = o.id
         WHERE o.poll_id = ? GROUP BY o.id, o.label, o.sort ORDER BY o.sort ASC, o.id ASC'
    );
    $st->execute(array($poll['id']));
    $options = array();
    $total = 0;
    foreach ($st->fetchAll() as $o) {
        $total += (int) $o['votes'];
        $options[] = array(
            'id' => (int) $o['id'],
            'label' => $o['label'],
            'votes' => (int) $o['votes'],
        );
    }

    $myOption = null;
    if ($userId) {
        $st = $pdo->prepare('SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ?');
        $st->execute(array($poll['id'], $userId));
        $v = $st->fetchColumn();
        if ($v !== false) {
            $myOption = (int) $v;
        }
    }

    foreach ($options as &$o) {
        $o['percent'] = $total > 0 ? round($o['votes'] / $total * 100, 1) : 0;
    }
    unset($o);

    return array(
        'id' => (int) $poll['id'],
        'title' => $poll['title'],
        'description' => $poll['description'],
        'starts_at' => $poll['starts_at'],
        'ends_at' => $poll['ends_at'],
        'status' => poll_status($poll),
        'total_votes' => $total,
        'options' => $options,
        'my_option' => $myOption,
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $rows = $pdo->query('SELECT * FROM polls WHERE is_deleted = 0 ORDER BY id DESC LIMIT 30')->fetchAll();
    $items = array();
    foreach ($rows as $p) {
        $items[] = poll_payload($pdo, $p, $user ? (int) $user['id'] : 0);
    }
    json_ok(array(
        'items' => $items,
        'can_manage' => $admin,
        'logged_in' => (bool) $user,
        'server_time' => date('Y-m-d H:i:s'),
    ));
}

if ($action === 'create') {
    if (!$admin) {
        json_fail('只有管理员可以发起投票', 403);
    }
    $title = trim((string) ($input['title'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $options = $input['options'] ?? array();
    if (!is_array($options)) {
        $options = array();
    }
    $options = array_values(array_filter(array_map(function ($x) {
        return trim((string) $x);
    }, $options), function ($x) {
        return $x !== '';
    }));

    if ($title === '' || mb_strlen($title) > 160) {
        json_fail('标题不能为空且不超过 160 字');
    }
    if (mb_strlen($description) > 500) {
        json_fail('说明不能超过 500 字');
    }
    if (count($options) < 2 || count($options) > 10) {
        json_fail('选项需要 2 - 10 个');
    }
    foreach ($options as $o) {
        if (mb_strlen($o) > 120) {
            json_fail('单个选项不能超过 120 字');
        }
    }

    $startsRaw = trim((string) ($input['starts_at'] ?? ''));
    $endsRaw = trim((string) ($input['ends_at'] ?? ''));
    $starts = $startsRaw !== '' ? strtotime($startsRaw) : time();
    $ends = $endsRaw !== '' ? strtotime($endsRaw) : time() + 7 * 86400;
    if ($starts === false || $ends === false) {
        json_fail('开始/截止时间格式不正确');
    }
    if ($ends <= $starts) {
        json_fail('截止时间必须晚于开始时间');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO polls (title, description, starts_at, ends_at, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute(array($title, $description, date('Y-m-d H:i:s', $starts), date('Y-m-d H:i:s', $ends), $user['id']));
        $pollId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO poll_options (poll_id, label, sort) VALUES (?, ?, ?)');
        foreach ($options as $i => $o) {
            $ins->execute(array($pollId, $o, $i));
        }
        add_activity($pdo, $user, 'system', '发起投票：' . $title, null, null);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_fail('创建投票失败：' . $e->getMessage(), 500);
    }

    $st = $pdo->prepare('SELECT * FROM polls WHERE id = ?');
    $st->execute(array($pollId));
    json_ok(array('poll' => poll_payload($pdo, $st->fetch(), $user['id'])), '投票已创建');
}

if ($action === 'vote') {
    if (!$user) {
        json_fail('请先登录再投票', 401);
    }
    $pollId = (int) ($input['poll_id'] ?? 0);
    $optionId = (int) ($input['option_id'] ?? 0);

    $st = $pdo->prepare('SELECT * FROM polls WHERE id = ? AND is_deleted = 0');
    $st->execute(array($pollId));
    $poll = $st->fetch();
    if (!$poll) {
        json_fail('投票不存在');
    }
    if (poll_status($poll) !== 'open') {
        json_fail('投票还没开始或已经结束');
    }

    $st = $pdo->prepare('SELECT id FROM poll_options WHERE id = ? AND poll_id = ?');
    $st->execute(array($optionId, $pollId));
    if (!$st->fetch()) {
        json_fail('选项不存在');
    }

    $st = $pdo->prepare('SELECT id FROM poll_votes WHERE poll_id = ? AND user_id = ?');
    $st->execute(array($pollId, $user['id']));
    if ($st->fetch()) {
        json_fail('你已经投过票了');
    }

    try {
        $pdo->prepare('INSERT INTO poll_votes (poll_id, option_id, user_id) VALUES (?, ?, ?)')
            ->execute(array($pollId, $optionId, $user['id']));
    } catch (PDOException $e) {
        json_fail('你已经投过票了');
    }

    $st = $pdo->prepare('SELECT * FROM polls WHERE id = ?');
    $st->execute(array($pollId));
    json_ok(array('poll' => poll_payload($pdo, $st->fetch(), $user['id'])), '投票成功');
}

if ($action === 'close' || $action === 'delete') {
    if (!$admin) {
        json_fail('需要管理员权限', 403);
    }
    $pollId = (int) ($input['poll_id'] ?? $input['id'] ?? 0);
    if ($pollId <= 0) {
        json_fail('投票不存在');
    }
    if ($action === 'close') {
        $pdo->prepare('UPDATE polls SET is_closed = 1 WHERE id = ?')->execute(array($pollId));
        json_ok(array('poll_id' => $pollId), '投票已截止');
    }
    $pdo->prepare('UPDATE polls SET is_deleted = 1 WHERE id = ?')->execute(array($pollId));
    json_ok(array('poll_id' => $pollId), '投票已删除');
}

json_fail('未知操作', 404);
