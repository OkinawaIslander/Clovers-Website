<?php
/**
 * 留言板
 * GET  : 列表
 * POST : { nickname, content } 或 { action: "delete", id }
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);

$user = current_user($pdo);
$admin = is_admin($user);
$input = getInput();
$action = $input['action'] ?? '';

function post_row($row, $user, $admin) {
    return array(
        'id' => (int) $row['id'],
        'user_id' => $row['user_id'] ? (int) $row['user_id'] : null,
        'nickname' => $row['nickname'],
        'avatar_url' => $row['user_id'] ? user_avatar_url(array('id' => (int) $row['user_id'], 'avatar' => $row['avatar'] ?? null)) : null,
        'content' => $row['content'],
        'created_at' => $row['created_at'],
        'time_ago' => time_ago($row['created_at']),
        'mine' => $user && (int) $row['user_id'] === (int) $user['id'],
        'can_delete' => $admin,
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->query(
        "SELECT p.*, u.avatar FROM posts p LEFT JOIN users u ON u.id = p.user_id
         WHERE p.is_deleted = 0
           AND (p.user_id IS NULL OR p.user_id NOT IN (SELECT id FROM users WHERE role = 'super'))
         ORDER BY p.id DESC LIMIT 50"
    );
    $items = array();
    foreach ($st->fetchAll() as $row) {
        $items[] = post_row($row, $user, $admin);
    }
    json_ok(array('items' => $items, 'can_delete' => $admin, 'logged_in' => (bool) $user));
}

if ($action === 'delete') {
    if (!$admin) {
        json_fail('只有管理员可以删除留言', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        json_fail('留言不存在');
    }
    $st = $pdo->prepare('UPDATE posts SET is_deleted = 1, deleted_by = ? WHERE id = ? AND is_deleted = 0');
    $st->execute(array($user['id'], $id));
    if ($st->rowCount() < 1) {
        json_fail('留言不存在或已删除');
    }
    json_ok(array('id' => $id), '已删除');
}

// 发布留言
$content = trim((string) ($input['content'] ?? ''));
$nickname = $user ? $user['nickname'] : trim((string) ($input['nickname'] ?? ''));

if ($content === '' || mb_strlen($content) > 300) {
    json_fail('留言内容不能为空且不超过 300 字');
}
if ($nickname === '' || mb_strlen($nickname) > 20) {
    json_fail('昵称不能为空且不超过 20 字');
}

if ($user) {
    $st = $pdo->prepare('SELECT created_at FROM posts WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute(array($user['id']));
    $last = $st->fetchColumn();
    if ($last && time() - strtotime($last) < 10) {
        json_fail('发得太快了，10 秒后再试');
    }
}

$pdo->prepare('INSERT INTO posts (user_id, nickname, content) VALUES (?, ?, ?)')
    ->execute(array($user ? $user['id'] : null, $nickname, $content));
$id = (int) $pdo->lastInsertId();

if ($user) {
    add_activity($pdo, $user, 'post', '在留言板留言：' . mb_substr($content, 0, 60), null, null);
}

$st = $pdo->prepare('SELECT p.*, u.avatar FROM posts p LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?');
$st->execute(array($id));
json_ok(array('item' => post_row($st->fetch(), $user, $admin)), '留言成功');
