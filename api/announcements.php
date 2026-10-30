<?php
/**
 * 公告栏
 * GET  : 列表
 * POST : { action: "create", title, content } / { action: "delete", id }
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);

$user = current_user($pdo);
$admin = is_admin($user);
$input = getInput();
$action = (string) ($input['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $rows = $pdo->query(
        'SELECT a.*, u.role AS user_role FROM announcements a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.is_deleted = 0 ORDER BY a.id DESC LIMIT 30'
    )->fetchAll();
    $items = array();
    foreach ($rows as $r) {
        $items[] = array(
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'content' => $r['content'],
            'nickname' => ($r['user_role'] ?? '') === 'super' ? '系统' : $r['nickname'],
            'created_at' => $r['created_at'],
            'time_ago' => time_ago($r['created_at']),
        );
    }
    json_ok(array('items' => $items, 'can_manage' => $admin, 'logged_in' => (bool) $user));
}

if (!$admin) {
    json_fail('只有管理员可以管理公告', 403);
}

if ($action === 'create') {
    $title = trim((string) ($input['title'] ?? ''));
    $content = trim((string) ($input['content'] ?? ''));
    if ($title === '' || mb_strlen($title) > 120) {
        json_fail('标题不能为空且不超过 120 字');
    }
    if ($content === '' || mb_strlen($content) > 2000) {
        json_fail('内容不能为空且不超过 2000 字');
    }

    $nick = is_super($user) ? '系统' : $user['nickname'];
    $pdo->prepare('INSERT INTO announcements (title, content, user_id, nickname) VALUES (?, ?, ?, ?)')
        ->execute(array($title, $content, $user['id'], $nick));
    $id = (int) $pdo->lastInsertId();

    add_activity($pdo, is_super($user) ? null : $user, 'system', '发布公告：' . $title, null, null);

    json_ok(array(
        'item' => array(
            'id' => $id,
            'title' => $title,
            'content' => $content,
            'nickname' => $user['nickname'],
            'created_at' => date('Y-m-d H:i:s'),
            'time_ago' => '刚刚',
        ),
    ), '公告已发布');
}

if ($action === 'delete') {
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        json_fail('公告不存在');
    }
    $pdo->prepare('UPDATE announcements SET is_deleted = 1 WHERE id = ?')->execute(array($id));
    json_ok(array('id' => $id), '已删除');
}

json_fail('未知操作', 404);
