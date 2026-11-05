<?php
/**
 * 每日答题 + 用户出题
 *
 * GET  ?action=state                    今日状态、题目、历史
 * POST { action:"start", category }     选一个板块抽 3 题
 * POST { action:"submit", answers:{id:"A"} }
 * POST { action:"create_question", category, question, options:["A","B","C","D"], correct:"A" }
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);

$user = require_login($pdo);
$input = getInput();
$action = (string) ($_GET['action'] ?? $input['action'] ?? 'state');

$categories = array('动漫', '游戏', '其他');
$perCorrect = defined('QUIZ_REWARD_PER_CORRECT') ? (int) QUIZ_REWARD_PER_CORRECT : 30;
$dailySubmit = defined('QUIZ_DAILY_SUBMIT') ? (int) QUIZ_DAILY_SUBMIT : 3;
$today = date('Y-m-d');

function quiz_options($row) {
    return array(
        'A' => $row['option_a'],
        'B' => $row['option_b'],
        'C' => $row['option_c'],
        'D' => $row['option_d'],
    );
}

function quiz_number($id) {
    return 'Q' . str_pad((string) (int) $id, 4, '0', STR_PAD_LEFT);
}

function quiz_validate_question_input($input, $categories) {
    $category = trim((string) ($input['category'] ?? ''));
    $question = trim((string) ($input['question'] ?? ''));
    $options = $input['options'] ?? array();
    $correct = strtoupper(trim((string) ($input['correct'] ?? '')));

    if (!in_array($category, $categories, true)) {
        json_fail('分类只能是动漫 / 游戏 / 其他');
    }
    if (mb_strlen($question) < 5 || mb_strlen($question) > 500) {
        json_fail('题目需要 5 - 500 字');
    }
    if (!is_array($options) || count($options) !== 4) {
        json_fail('需要 4 个选项');
    }
    $options = array_values($options);
    foreach ($options as $index => $opt) {
        $opt = trim((string) $opt);
        if ($opt === '' || mb_strlen($opt) > 200) {
            json_fail('选项不能为空，且每个不超过 200 字');
        }
        $options[$index] = $opt;
    }
    if (count(array_unique($options)) < 4) {
        json_fail('4 个选项不能重复');
    }
    if (!in_array($correct, array('A', 'B', 'C', 'D'), true)) {
        json_fail('请标注正确选项 A / B / C / D');
    }

    return array(
        'category' => $category,
        'question' => $question,
        'options' => $options,
        'correct' => $correct,
    );
}

function quiz_can_edit_question($user, $row, $today) {
    if (is_admin($user)) {
        return true;
    }
    return (int) $row['user_id'] === (int) $user['id']
        && substr((string) $row['created_at'], 0, 10) === $today;
}

function quiz_question_view($row, $withAnswer = false) {
    $out = array(
        'id' => (int) $row['id'],
        'number' => quiz_number($row['id']),
        'category' => $row['category'],
        'question' => $row['question'],
        'options' => quiz_options($row),
    );
    if ($withAnswer) {
        $out['correct'] = $row['correct'];
    }
    return $out;
}

function quiz_load_questions($pdo, $ids) {
    if (!$ids) {
        return array();
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM quiz_questions WHERE id IN ($in) AND status = 'approved'");
    $st->execute($ids);
    $map = array();
    foreach ($st->fetchAll() as $row) {
        $map[(int) $row['id']] = $row;
    }
    $out = array();
    foreach ($ids as $id) {
        if (isset($map[$id])) {
            $out[] = $map[$id];
        }
    }
    return $out;
}

function quiz_attempt_view($pdo, $attempt) {
    if (!$attempt) {
        return null;
    }
    $ids = json_decode((string) $attempt['question_ids'], true);
    if (!is_array($ids)) {
        $ids = array();
    }
    $rows = quiz_load_questions($pdo, $ids);
    $completed = $attempt['status'] === 'completed';

    if ($completed) {
        $answers = json_decode((string) $attempt['answers'], true);
        if (!is_array($answers)) {
            $answers = array();
        }
        $results = array();
        foreach ($rows as $row) {
            $selected = strtoupper((string) ($answers[(string) $row['id']] ?? ''));
            $results[] = array(
                'id' => (int) $row['id'],
                'number' => quiz_number($row['id']),
                'question' => $row['question'],
                'options' => quiz_options($row),
                'selected' => $selected,
                'correct' => $row['correct'],
                'is_correct' => $selected === $row['correct'],
            );
        }
        return array(
            'status' => 'completed',
            'category' => $attempt['category'],
            'correct_count' => (int) $attempt['correct_count'],
            'heat' => (int) $attempt['heat'],
            'results' => $results,
        );
    }

    $questions = array();
    foreach ($rows as $row) {
        $questions[] = quiz_question_view($row, false);
    }
    return array(
        'status' => 'pending',
        'category' => $attempt['category'],
        'questions' => $questions,
    );
}

function quiz_heat($pdo, $uid) {
    $st = $pdo->prepare('SELECT heat FROM users WHERE id = ?');
    $st->execute(array($uid));
    return round((float) $st->fetchColumn(), 2);
}

// ---------- 状态 ----------
if ($action === 'state') {
    $cs = $pdo->query("SELECT category, COUNT(*) AS c FROM quiz_questions WHERE status = 'approved' GROUP BY category");
    $counts = array('动漫' => 0, '游戏' => 0, '其他' => 0);
    foreach ($cs->fetchAll() as $row) {
        if (isset($counts[$row['category']])) {
            $counts[$row['category']] = (int) $row['c'];
        }
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM quiz_questions WHERE user_id = ? AND DATE(created_at) = CURDATE()');
    $st->execute(array($user['id']));
    $submitCount = (int) $st->fetchColumn();

    $st = $pdo->prepare('SELECT * FROM quiz_attempts WHERE user_id = ? AND quiz_date = ? LIMIT 1');
    $st->execute(array($user['id'], $today));
    $attempt = $st->fetch();

    $st = $pdo->prepare('SELECT * FROM quiz_questions WHERE user_id = ? ORDER BY id DESC LIMIT 8');
    $st->execute(array($user['id']));
    $mine = array();
    foreach ($st->fetchAll() as $row) {
        $item = quiz_question_view($row, true);
        $item['time_ago'] = time_ago($row['created_at']);
        $item['created_date'] = substr((string) $row['created_at'], 0, 10);
        $item['can_edit'] = is_admin($user) || substr((string) $row['created_at'], 0, 10) === $today;
        $mine[] = $item;
    }

    json_ok(array(
        'heat' => quiz_heat($pdo, $user['id']),
        'today' => $today,
        'is_admin' => is_admin($user),
        'categories' => $counts,
        'submit_count' => $submitCount,
        'submit_remaining' => max(0, $dailySubmit - $submitCount),
        'reward_per_correct' => $perCorrect,
        'attempt' => quiz_attempt_view($pdo, $attempt),
        'my_questions' => $mine,
    ));
}

// ---------- 开始答题 ----------
if ($action === 'start') {
    require_post();
    $category = trim((string) ($input['category'] ?? ''));
    if (!in_array($category, $categories, true)) {
        json_fail('请选择动漫 / 游戏 / 其他');
    }

    $st = $pdo->prepare('SELECT id FROM quiz_attempts WHERE user_id = ? AND quiz_date = ?');
    $st->execute(array($user['id'], $today));
    if ($st->fetchColumn()) {
        json_fail('今天已经抽过题了，明天再来');
    }

    // 优先抽别人出的题，题库不够时再补自己的
    $st = $pdo->prepare(
        "SELECT * FROM quiz_questions
         WHERE status = 'approved' AND category = ? AND user_id <> ?
         ORDER BY RAND() LIMIT 3"
    );
    $st->execute(array($category, $user['id']));
    $rows = $st->fetchAll();

    if (count($rows) < 3) {
        $taken = array();
        foreach ($rows as $row) {
            $taken[] = (int) $row['id'];
        }
        $need = 3 - count($rows);
        $sql = "SELECT * FROM quiz_questions WHERE status = 'approved' AND category = ?";
        $params = array($category);
        if ($taken) {
            $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($taken), '?')) . ')';
            $params = array_merge($params, $taken);
        }
        $sql .= ' ORDER BY RAND() LIMIT ' . (int) $need;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = array_merge($rows, $st->fetchAll());
    }

    if (count($rows) < 3) {
        json_fail('这个板块的题库还不够 3 题，先去出题吧');
    }

    $ids = array();
    foreach (array_slice($rows, 0, 3) as $row) {
        $ids[] = (int) $row['id'];
    }

    try {
        $pdo->prepare(
            'INSERT INTO quiz_attempts (user_id, quiz_date, category, question_ids, status)
             VALUES (?, ?, ?, ?, "pending")'
        )->execute(array($user['id'], $today, $category, json_encode($ids)));
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            json_fail('今天已经抽过题了，明天再来');
        }
        throw $e;
    }

    $st = $pdo->prepare('SELECT * FROM quiz_attempts WHERE user_id = ? AND quiz_date = ?');
    $st->execute(array($user['id'], $today));
    json_ok(array('attempt' => quiz_attempt_view($pdo, $st->fetch())), '抽题成功');
}

// ---------- 交卷 ----------
if ($action === 'submit') {
    require_post();
    $answers = $input['answers'] ?? array();
    if (!is_array($answers)) {
        json_fail('答案格式不正确');
    }

    $st = $pdo->prepare("SELECT * FROM quiz_attempts WHERE user_id = ? AND quiz_date = ? AND status = 'pending'");
    $st->execute(array($user['id'], $today));
    $attempt = $st->fetch();
    if (!$attempt) {
        json_fail('没有待交卷的答题，可能已经交过了');
    }

    $ids = json_decode((string) $attempt['question_ids'], true);
    if (!is_array($ids) || count($ids) < 3) {
        json_fail('题目数据异常，请明天再试');
    }
    $rows = quiz_load_questions($pdo, $ids);
    if (count($rows) < 3) {
        json_fail('题目已被删除，请联系管理员');
    }

    $clean = array();
    $correctCount = 0;
    foreach ($rows as $row) {
        $id = (string) $row['id'];
        $selected = strtoupper(trim((string) ($answers[$id] ?? '')));
        if (!in_array($selected, array('A', 'B', 'C', 'D'), true)) {
            json_fail('请把三题都选完');
        }
        $clean[$id] = $selected;
        if ($selected === $row['correct']) {
            $correctCount++;
        }
    }

    $heat = $correctCount * $perCorrect;

    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare(
            "UPDATE quiz_attempts
             SET answers = ?, correct_count = ?, heat = ?, status = 'completed', completed_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $up->execute(array(json_encode($clean, JSON_UNESCAPED_UNICODE), $correctCount, $heat, $attempt['id']));
        if ($up->rowCount() < 1) {
            throw new RuntimeException('已经交过卷了');
        }

        if ($heat > 0) {
            $pdo->prepare('UPDATE users SET heat = heat + ?, total_earned = total_earned + ? WHERE id = ?')
                ->execute(array($heat, $heat, $user['id']));
            add_activity(
                $pdo,
                $user,
                'quiz',
                '每日答题答对 ' . $correctCount . ' 题，获得 ' . number_format($heat, 0) . ' 热力值',
                $heat,
                null
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_fail($e->getMessage());
    }

    $st = $pdo->prepare('SELECT * FROM quiz_attempts WHERE id = ?');
    $st->execute(array($attempt['id']));
    json_ok(array(
        'attempt' => quiz_attempt_view($pdo, $st->fetch()),
        'earned' => $heat,
        'heat' => quiz_heat($pdo, $user['id']),
    ), '答对 ' . $correctCount . ' 题，获得 ' . $heat . ' 热力值');
}

// ---------- 出题 ----------
if ($action === 'create_question') {
    require_post();
    $category = trim((string) ($input['category'] ?? ''));
    $question = trim((string) ($input['question'] ?? ''));
    $options = $input['options'] ?? array();
    $correct = strtoupper(trim((string) ($input['correct'] ?? '')));

    if (!in_array($category, $categories, true)) {
        json_fail('分类只能是动漫 / 游戏 / 其他');
    }
    if (mb_strlen($question) < 5 || mb_strlen($question) > 500) {
        json_fail('题目需要 5 - 500 字');
    }
    if (!is_array($options) || count($options) !== 4) {
        json_fail('需要 4 个选项');
    }
    $options = array_values($options);
    foreach ($options as $index => $opt) {
        $opt = trim((string) $opt);
        if ($opt === '' || mb_strlen($opt) > 200) {
            json_fail('选项不能为空，且每个不超过 200 字');
        }
        $options[$index] = $opt;
    }
    if (count(array_unique($options)) < 4) {
        json_fail('4 个选项不能重复');
    }
    if (!in_array($correct, array('A', 'B', 'C', 'D'), true)) {
        json_fail('请标注正确选项 A / B / C / D');
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM quiz_questions WHERE user_id = ? AND DATE(created_at) = CURDATE()');
    $st->execute(array($user['id']));
    $count = (int) $st->fetchColumn();
    if ($count >= $dailySubmit) {
        json_fail('今天已经出满 ' . $dailySubmit . ' 道题了，明天再来');
    }

    $pdo->prepare(
        'INSERT INTO quiz_questions
         (user_id, category, question, option_a, option_b, option_c, option_d, correct, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "approved")'
    )->execute(array(
        $user['id'], $category, $question,
        $options[0], $options[1], $options[2], $options[3],
        $correct,
    ));

    $newId = (int) $pdo->lastInsertId();
    $item = array(
        'id' => $newId,
        'number' => quiz_number($newId),
        'category' => $category,
        'question' => $question,
        'options' => array('A' => $options[0], 'B' => $options[1], 'C' => $options[2], 'D' => $options[3]),
        'correct' => $correct,
        'time_ago' => '刚刚',
        'created_date' => $today,
        'can_edit' => true,
    );

    json_ok(array(
        'item' => $item,
        'submit_count' => $count + 1,
        'submit_remaining' => max(0, $dailySubmit - $count - 1),
    ), '题目已加入题库');
}

// ---------- 管理员：题库列表 ----------
if ($action === 'manage') {
    if (!is_admin($user)) {
        json_fail('需要管理员权限', 403);
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per = 20;
    $offset = ($page - 1) * $per;
    $category = trim((string) ($_GET['category'] ?? ''));
    $keyword = trim((string) ($_GET['q'] ?? ''));

    $where = array('1=1');
    $params = array();
    if (in_array($category, $categories, true)) {
        $where[] = 'q.category = ?';
        $params[] = $category;
    }
    if ($keyword !== '') {
        if (preg_match('/^[Qq]?0*(\d+)$/', $keyword, $m)) {
            $where[] = 'q.id = ?';
            $params[] = (int) $m[1];
        } else {
            $where[] = '(q.question LIKE ? OR q.option_a LIKE ? OR q.option_b LIKE ? OR q.option_c LIKE ? OR q.option_d LIKE ?)';
            $like = '%' . $keyword . '%';
            for ($i = 0; $i < 5; $i++) {
                $params[] = $like;
            }
        }
    }
    $whereSql = implode(' AND ', $where);

    $st = $pdo->prepare("SELECT COUNT(*) FROM quiz_questions q WHERE $whereSql");
    $st->execute($params);
    $total = (int) $st->fetchColumn();

    $sql = "SELECT q.*, u.nickname AS author
            FROM quiz_questions q LEFT JOIN users u ON u.id = q.user_id
            WHERE $whereSql ORDER BY q.id DESC LIMIT $per OFFSET $offset";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $items = array();
    foreach ($st->fetchAll() as $row) {
        $item = quiz_question_view($row, true);
        $item['author'] = $row['author'] ?: '已注销用户';
        $item['status'] = $row['status'];
        $item['created_at'] = $row['created_at'];
        $item['time_ago'] = time_ago($row['created_at']);
        $item['can_edit'] = true;
        $items[] = $item;
    }

    json_ok(array(
        'questions' => $items,
        'page' => $page,
        'per' => $per,
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $per)),
    ));
}

// ---------- 修改题目（管理员任意题；普通用户当天自己出的题） ----------
if ($action === 'edit_question') {
    require_post();
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        json_fail('题目编号不正确');
    }

    $st = $pdo->prepare('SELECT * FROM quiz_questions WHERE id = ?');
    $st->execute(array($id));
    $row = $st->fetch();
    if (!$row) {
        json_fail('题目不存在', 404);
    }
    if (!quiz_can_edit_question($user, $row, $today)) {
        json_fail('只能修改自己今天提交的题目', 403);
    }

    $data = quiz_validate_question_input($input, $categories);
    $pdo->prepare(
        'UPDATE quiz_questions
         SET category = ?, question = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct = ?
         WHERE id = ?'
    )->execute(array(
        $data['category'], $data['question'],
        $data['options'][0], $data['options'][1], $data['options'][2], $data['options'][3],
        $data['correct'], $id,
    ));

    $st = $pdo->prepare('SELECT * FROM quiz_questions WHERE id = ?');
    $st->execute(array($id));
    $fresh = $st->fetch();
    $item = quiz_question_view($fresh, true);
    $item['status'] = $fresh['status'];
    $item['created_at'] = $fresh['created_at'];
    $item['time_ago'] = time_ago($fresh['created_at']);
    $item['can_edit'] = true;

    json_ok(array('item' => $item), '题目已保存');
}

// ---------- 管理员：隐藏 / 恢复题目 ----------
if ($action === 'hide_question') {
    require_post();
    if (!is_admin($user)) {
        json_fail('需要管理员权限', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM quiz_questions WHERE id = ?');
    $st->execute(array($id));
    $row = $st->fetch();
    if (!$row) {
        json_fail('题目不存在', 404);
    }
    $next = $row['status'] === 'approved' ? 'hidden' : 'approved';
    $pdo->prepare('UPDATE quiz_questions SET status = ? WHERE id = ?')->execute(array($next, $id));
    json_ok(array('id' => $id, 'status' => $next), $next === 'approved' ? '题目已恢复' : '题目已隐藏');
}

json_fail('未知操作', 404);
