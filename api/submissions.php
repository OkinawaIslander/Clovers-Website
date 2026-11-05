<?php
/**
 * 社刊投稿
 * GET  ?scope=mine|all（all 仅管理员）
 * POST multipart: action=create, title, category, description, file
 * POST JSON: action=delete, id / action=review, id, status, note
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);

$user = current_user($pdo);
$admin = is_admin($user);
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

function submission_item($row, $user, $admin) {
    $isOwner = $user && (int) $row['user_id'] === (int) $user['id'];
    return array(
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'nickname' => $row['nickname'] ?? '',
        'avatar_url' => user_avatar_url(array('id' => (int) $row['user_id'], 'avatar' => $row['avatar'] ?? null)),
        'title' => $row['title'],
        'category' => $row['category'],
        'description' => $row['description'],
        'status' => $row['status'],
        'admin_note' => $row['admin_note'],
        'original_name' => $row['original_name'],
        'file_size' => (int) $row['file_size'],
        'created_at' => $row['created_at'],
        'time_ago' => time_ago($row['created_at']),
        'download_url' => 'api/submission_file.php?id=' . (int) $row['id'],
        'can_delete' => $admin || ($isOwner && $row['status'] === 'pending'),
        'can_review' => $admin,
    );
}

if (!$isPost) {
    if (!$user) {
        json_fail('请先登录', 401);
    }
    $scope = (string) ($_GET['scope'] ?? 'mine');
    if ($scope === 'all' && $admin) {
        $sql = 'SELECT s.*, u.nickname, u.avatar FROM submissions s
                LEFT JOIN users u ON u.id = s.user_id';
        if (!is_super($user)) {
            $sql .= " WHERE s.user_id NOT IN (SELECT id FROM users WHERE role = 'super')";
        }
        $sql .= ' ORDER BY s.id DESC LIMIT 200';
        $st = $pdo->query($sql);
    } else {
        $st = $pdo->prepare(
            'SELECT s.*, u.nickname, u.avatar FROM submissions s
             LEFT JOIN users u ON u.id = s.user_id
             WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 100'
        );
        $st->execute(array($user['id']));
    }

    $items = array();
    foreach ($st->fetchAll() as $row) {
        $items[] = submission_item($row, $user, $admin);
    }
    json_ok(array('items' => $items, 'can_review' => $admin, 'scope' => $scope));
}

// POST：multipart 时 $_POST 有值，JSON 时用 getInput
$input = getInput();
$action = (string) ($_POST['action'] ?? $input['action'] ?? '');

if ($action === 'create') {
    if (!$user) {
        json_fail('请先登录再投稿', 401);
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? '其他'));
    $description = trim((string) ($_POST['description'] ?? ''));

    $categories = defined('SUBMISSION_CATEGORIES') ? SUBMISSION_CATEGORIES : array('cosplay摄影', '文章', '绘画插画', '其他');
    if (!in_array($category, $categories, true)) {
        $category = '其他';
    }

    if ($title === '' || mb_strlen($title) > 160) {
        json_fail('标题不能为空且不超过 160 字');
    }
    if (mb_strlen($description) > 1000) {
        json_fail('说明不能超过 1000 字');
    }
    if (!isset($_FILES['file'])) {
        json_fail('请选择要上传的文件');
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $messages = array(
            UPLOAD_ERR_INI_SIZE => '文件超过服务器 upload_max_filesize 限制',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单大小限制',
            UPLOAD_ERR_PARTIAL => '文件只上传了一部分，请重试',
            UPLOAD_ERR_NO_FILE => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
            UPLOAD_ERR_EXTENSION => '上传被 PHP 扩展拦截',
        );
        json_fail($messages[$file['error']] ?? ('上传失败（错误码 ' . $file['error'] . '）'));
    }

    $maxMb = defined('UPLOAD_MAX_MB') ? (int) UPLOAD_MAX_MB : 20;
    if ($file['size'] > $maxMb * 1024 * 1024) {
        json_fail('文件不能超过 ' . $maxMb . 'MB');
    }

    $original = basename((string) $file['name']);
    $original = preg_replace('/[\x00-\x1f\x7f]/u', '', $original);
    $original = mb_substr($original, 0, 200);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

    $allowed = array('pdf', 'doc', 'docx', 'txt', 'md', 'zip', 'rar', '7z', 'jpg', 'jpeg', 'png', 'webp', 'psd', 'ai', 'ppt', 'pptx', 'xls', 'xlsx');
    if (!in_array($ext, $allowed, true)) {
        json_fail('不支持 .' . $ext . ' 格式，支持：' . implode(' / ', $allowed));
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $detected = finfo_file($fi, $file['tmp_name']);
            if ($detected) {
                $mime = $detected;
            }
            finfo_close($fi);
        }
    }

    $dir = dirname(__DIR__) . '/uploads/submissions';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        json_fail('服务器无法创建 uploads/submissions 目录，请检查网站根目录写权限', 500);
    }
    if (!is_writable($dir)) {
        json_fail('uploads/submissions 目录不可写，请把目录权限设为 755、属主 www', 500);
    }

    $stored = date('Ym') . '-' . bin2hex(random_bytes(12)) . '.bin';
    $target = $dir . '/' . $stored;
    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        json_fail('保存文件失败，请检查目录权限', 500);
    }

    $pdo->prepare(
        'INSERT INTO submissions (user_id, title, category, description, file_path, original_name, file_size, mime, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")'
    )->execute(array(
        $user['id'],
        $title,
        mb_substr($category !== '' ? $category : '其他', 0, 30),
        $description,
        'uploads/submissions/' . $stored,
        $original,
        (int) $file['size'],
        mb_substr($mime, 0, 100),
    ));
    $id = (int) $pdo->lastInsertId();

    add_activity($pdo, $user, 'submit', '投稿社刊：' . $title, null, null);

    $st = $pdo->prepare('SELECT s.*, u.nickname, u.avatar FROM submissions s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ?');
    $st->execute(array($id));
    json_ok(array('item' => submission_item($st->fetch(), $user, $admin)), '投稿成功，等待管理员审核');
}

if (!$user) {
    json_fail('请先登录', 401);
}

if ($action === 'delete') {
    $id = (int) ($input['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM submissions WHERE id = ?');
    $st->execute(array($id));
    $row = $st->fetch();
    if (!$row) {
        json_fail('投稿不存在');
    }
    $isOwner = (int) $row['user_id'] === (int) $user['id'];
    if (!$admin && !($isOwner && $row['status'] === 'pending')) {
        json_fail('只能删除自己待审核的投稿', 403);
    }

    $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute(array($id));
    $path = dirname(__DIR__) . '/' . $row['file_path'];
    if (is_file($path)) {
        @unlink($path);
    }
    json_ok(array('id' => $id), '已删除');
}

if ($action === 'review') {
    if (!$admin) {
        json_fail('需要管理员权限', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    $status = (string) ($input['status'] ?? '');
    $note = trim((string) ($input['note'] ?? ''));
    if (!in_array($status, array('pending', 'accepted', 'rejected'), true)) {
        json_fail('状态不正确');
    }
    if (mb_strlen($note) > 500) {
        json_fail('审核备注不能超过 500 字');
    }
    $pdo->prepare('UPDATE submissions SET status = ?, admin_note = ?, updated_at = NOW() WHERE id = ?')
        ->execute(array($status, $note, $id));
    json_ok(array('id' => $id, 'status' => $status), '审核状态已更新');
}

json_fail('未知操作', 404);
