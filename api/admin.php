<?php
/**
 * 管理员接口
 *
 * 权限：① 管理员登录后的会话；或 ② 请求头 X-Admin-Key: ADMIN_API_KEY 的值。
 *
 * 已有动作：
 *   ping                         探活
 *   stats                        站点统计
 *   users                        用户列表
 *   adjust_heat                  调整热力值 { user_id, amount, note }
 *   set_role                     设置角色 { user_id, role: user|admin }
 *   delete_post                  删除留言 { id }
 *   delete_comment               删除番剧评论 { id }
 *   market_adjust                手动修正行情 { symbol, price }
 *   broadcast                    全站公告 { text }
 *   export                       导出数据（不含密码）
 *
 * 预留（返回 501，等后续接）：ban_user / reset_market / import_anime / send_mail
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);
market_tick($pdo);

$admin = current_user($pdo);
$viaKey = isset($_SERVER['HTTP_X_ADMIN_KEY']) && hash_equals(ADMIN_API_KEY, (string) $_SERVER['HTTP_X_ADMIN_KEY']);

if (!($admin && is_admin($admin)) && !$viaKey) {
    json_fail('需要管理员权限（或正确的 X-Admin-Key）', 403);
}

$isSuper = $viaKey || is_super($admin);
$input = getInput();
$action = (string) ($_GET['action'] ?? $input['action'] ?? '');

function admin_user($pdo) {
    $u = current_user($pdo);
    return ($u && is_admin($u)) ? $u : array('id' => null, 'nickname' => '管理员');
}

switch ($action) {
    case 'ping':
        json_ok(array('pong' => true, 'time' => date('c')));

    case 'stats':
        $stats = array(
            'users' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'super'")->fetchColumn(),
            'posts' => (int) $pdo->query('SELECT COUNT(*) FROM posts WHERE is_deleted = 0')->fetchColumn(),
            'comments' => (int) $pdo->query('SELECT COUNT(*) FROM anime_comments WHERE is_deleted = 0')->fetchColumn(),
            'open_positions' => (int) $pdo->query("SELECT COUNT(*) FROM positions WHERE status = 'open'")->fetchColumn(),
            'total_heat' => round((float) $pdo->query("SELECT COALESCE(SUM(heat), 0) FROM users WHERE role <> 'super'")->fetchColumn(), 2),
            'activities_today' => (int) $pdo->query('SELECT COUNT(*) FROM activities WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
            'server_time' => date('Y-m-d H:i:s'),
        );
        json_ok($stats);

    case 'users':
        if ($isSuper) {
            $rows = $pdo->query('SELECT id, nickname, avatar, email, role, heat, total_earned, created_at FROM users ORDER BY id ASC LIMIT 200')->fetchAll();
        } else {
            $rows = $pdo->query("SELECT id, nickname, avatar, email, role, heat, total_earned, created_at FROM users WHERE role <> 'super' ORDER BY id ASC LIMIT 200")->fetchAll();
        }
        foreach ($rows as &$row) {
            $row['avatar_url'] = user_avatar_url($row);
            unset($row['avatar']);
        }
        unset($row);
        json_ok(array('items' => $rows));

    case 'adjust_heat':
        $uid = (int) ($input['user_id'] ?? 0);
        $amount = round((float) ($input['amount'] ?? 0), 2);
        $note = trim((string) ($input['note'] ?? '管理员调整'));
        if ($uid <= 0 || $amount == 0.0) {
            json_fail('参数不完整');
        }
        $st = $pdo->prepare('SELECT id, nickname, role FROM users WHERE id = ?');
        $st->execute(array($uid));
        $target = $st->fetch();
        if (!$target) {
            json_fail('用户不存在', 404);
        }
        if ($target['role'] === 'super' && !$isSuper) {
            json_fail('不能调整系统管理员的资产', 403);
        }
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET heat = heat + ?, total_earned = total_earned + ? WHERE id = ?')
            ->execute(array($amount, max(0, $amount), $uid));
        add_activity(
            $pdo,
            admin_user($pdo),
            'admin',
            '给 ' . $target['nickname'] . ' 调整 ' . ($amount > 0 ? '+' : '') . $amount . ' 热力值（' . $note . '）',
            $amount,
            null
        );
        $pdo->commit();
        json_ok(array('user_id' => $uid, 'amount' => $amount), '已调整');

    case 'set_role':
        if (!$isSuper) {
            json_fail('只有系统管理员可以授予或撤销管理员权限', 403);
        }
        $uid = (int) ($input['user_id'] ?? 0);
        $role = (string) ($input['role'] ?? '');
        if ($uid <= 0 || !in_array($role, array('user', 'admin'), true)) {
            json_fail('参数不正确');
        }
        $st = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $st->execute(array($uid));
        $targetRole = $st->fetchColumn();
        if ($targetRole === false) {
            json_fail('用户不存在', 404);
        }
        if ($targetRole === 'super') {
            json_fail('不能修改系统管理员角色', 403);
        }
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(array($role, $uid));
        json_ok(array('user_id' => $uid, 'role' => $role), '已更新角色');

    case 'delete_post':
        $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
        $adminId = $admin ? (int) $admin['id'] : null;
        $pdo->prepare("UPDATE posts SET is_deleted = 1, deleted_by = ? WHERE id = ?")->execute(array($adminId, $id));
        json_ok(array('id' => $id), '已删除留言');

    case 'delete_comment':
        $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
        $pdo->prepare('UPDATE anime_comments SET is_deleted = 1 WHERE id = ?')->execute(array($id));
        json_ok(array('id' => $id), '已删除评论');

    case 'market_adjust':
        $symbol = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($input['symbol'] ?? '')));
        $price = round((float) ($input['price'] ?? 0), 4);
        if ($symbol === '' || $price <= 0) {
            json_fail('参数不正确');
        }
        $pdo->prepare('UPDATE market SET price = ?, updated_at = NOW() WHERE symbol = ?')->execute(array($price, $symbol));
        $pdo->prepare('INSERT INTO market_history (symbol, price) VALUES (?, ?)')->execute(array($symbol, $price));
        json_ok(array('symbol' => $symbol, 'price' => $price), '行情已修正');

    case 'broadcast':
        $text = trim((string) ($input['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 200) {
            json_fail('公告内容 1-200 字');
        }
        add_activity($pdo, null, 'system', $text, null, null);
        json_ok(array('text' => $text), '公告已发布');

    case 'export':
        if (!$isSuper) {
            json_fail('只有系统管理员可以导出数据', 403);
        }
        $users = $pdo->query('SELECT id, nickname, email, role, heat, total_earned, created_at FROM users')->fetchAll();
        $posts = $pdo->query('SELECT id, nickname, content, created_at FROM posts WHERE is_deleted = 0 ORDER BY id DESC LIMIT 200')->fetchAll();
        $positions = $pdo->query('SELECT * FROM positions ORDER BY id DESC LIMIT 500')->fetchAll();
        json_ok(array('users' => $users, 'posts' => $posts, 'positions' => $positions));

    case 'ban_user':
    case 'reset_market':
    case 'import_anime':
    case 'send_mail':
        json_fail('这个高级接口已预留，等后续版本开放', 501);

    default:
        json_fail('未知动作。可用：ping / stats / users / adjust_heat / set_role / delete_post / delete_comment / market_adjust / broadcast / export（预留：ban_user / reset_market / import_anime / send_mail）', 404);
}
