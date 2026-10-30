<?php
/**
 * 新番（Bangumi）
 *
 * GET  ?season=2026Q4            本季番剧列表
 * GET  ?action=comments&subject_id=123  某部番的评论
 * POST { action:"comment", subject_id, content }
 * POST { action:"import", season }      管理员：从 Bangumi 拉取本季新番
 */
require_once __DIR__ . '/_lib.php';

$pdo = pdo();
ensure_schema($pdo);

$user = current_user($pdo);
$admin = is_admin($user);
$input = getInput();
$action = (string) ($_GET['action'] ?? $input['action'] ?? 'list');

// ---------- Bangumi 请求 ----------
function bgm_request($url, $payload = null, $timeout = 12) {
    $ua = 'CloversSite/1.0';
    $headers = array('Content-Type: application/json', 'Accept: application/json');

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_ENCODING => '',
        ));
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return array($code, (string) $body, $err);
    }

    $ctx = stream_context_create(array(
        'http' => array(
            'method' => $payload !== null ? 'POST' : 'GET',
            'header' => "User-Agent: {$ua}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : '',
            'timeout' => $timeout,
            'ignore_errors' => true,
        ),
    ));
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    return array($code, (string) $body, $body === false ? '请求失败' : '');
}

function bgm_api_base() {
    $base = defined('BANGUMI_API_BASE') ? BANGUMI_API_BASE : 'https://api.bgm.tv';
    return rtrim($base, '/');
}

function bgm_data_mirrors() {
    if (defined('BANGUMI_DATA_MIRRORS') && is_array(BANGUMI_DATA_MIRRORS)) {
        return BANGUMI_DATA_MIRRORS;
    }
    return array(
        'https://cdn.jsdelivr.net/gh/bangumi-data/bangumi-data@master/dist/data.json',
        'https://fastly.jsdelivr.net/gh/bangumi-data/bangumi-data@master/dist/data.json',
        'https://unpkg.com/bangumi-data@latest/dist/data.json',
    );
}

function bgm_season_range($season) {
    if (!preg_match('/^(\d{4})Q([1-4])$/', $season, $m)) {
        return null;
    }
    $year = (int) $m[1];
    $q = (int) $m[2];
    $startMonth = ($q - 1) * 3 + 1;
    $endMonth = $startMonth + 3;
    $endYear = $year;
    if ($endMonth > 12) {
        $endMonth -= 12;
        $endYear++;
    }
    return array(
        sprintf('%04d-%02d-01', $year, $startMonth),
        sprintf('%04d-%02d-01', $endYear, $endMonth),
    );
}

function bgm_clean_date($date) {
    $d = substr(trim((string) $date), 0, 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return null;
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return $d;
}

function bgm_fetch_season_api($season, $range) {
    $url = bgm_api_base() . '/v0/search/subjects?limit=50&offset=0';
    $payload = array(
        'keyword' => '',
        'sort' => 'rank',
        'filter' => array(
            'type' => array(2),
            'air_date' => array('>=' . $range[0], '<' . $range[1]),
        ),
    );

    list($code, $body, $err) = bgm_request($url, $payload, 15);
    if ($code < 200 || $code >= 300 || $body === '') {
        return array('ok' => false, 'error' => 'Bangumi 接口请求失败（HTTP ' . $code . '）' . ($err ? '：' . $err : ''));
    }

    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
        return array('ok' => false, 'error' => 'Bangumi 返回的数据格式看不懂');
    }

    $items = array();
    foreach ($json['data'] as $it) {
        if (empty($it['id'])) {
            continue;
        }
        $items[] = array(
            'id' => (int) $it['id'],
            'title' => (string) ($it['name'] ?? ''),
            'title_cn' => (string) ($it['name_cn'] ?? ''),
            'air_date' => bgm_clean_date($it['air_date'] ?? null),
            'image' => (string) ($it['images']['large'] ?? $it['images']['common'] ?? $it['images']['medium'] ?? ''),
            'summary' => (string) ($it['summary'] ?? ''),
        );
    }

    if (!$items) {
        return array('ok' => false, 'error' => '官方 API 返回空列表');
    }

    return array('ok' => true, 'items' => $items, 'source' => 'Bangumi API');
}

/**
 * 国内镜像：bangumi-data 静态数据（没有封面/简介，但有标题、首播日期、Bangumi 链接）
 */
function bgm_parse_season_items($body, $range, $source) {
    $json = json_decode($body, true);
    if (!is_array($json) || empty($json['items']) || !is_array($json['items'])) {
        return array('ok' => false, 'error' => '番剧数据 JSON 解析失败');
    }

    $items = array();
    foreach ($json['items'] as $it) {
        $type = (string) ($it['type'] ?? '');
        if (!in_array($type, array('tv', 'web'), true)) {
            continue;
        }
        $begin = bgm_clean_date($it['begin'] ?? null);
        if ($begin === null || $begin < $range[0] || $begin >= $range[1]) {
            continue;
        }

        $bgmId = 0;
        foreach (($it['sites'] ?? array()) as $site) {
            if (($site['site'] ?? '') === 'bangumi') {
                $bgmId = (int) ($site['id'] ?? 0);
                break;
            }
        }
        if ($bgmId <= 0) {
            continue;
        }

        $title = (string) ($it['title'] ?? '');
        if ($title === '') {
            continue;
        }
        $cn = '';
        if (!empty($it['titleTranslate']['zh-Hans'][0])) {
            $cn = (string) $it['titleTranslate']['zh-Hans'][0];
        }

        $items[] = array(
            'id' => $bgmId,
            'title' => $title,
            'title_cn' => $cn,
            'air_date' => $begin,
            'image' => '',
            'summary' => '',
        );
    }

    if (!$items) {
        return array('ok' => false, 'error' => '数据里没有这一季的番剧');
    }

    usort($items, function ($a, $b) {
        return strcmp((string) $a['air_date'], (string) $b['air_date']);
    });

    return array(
        'ok' => true,
        'items' => array_slice($items, 0, 100),
        'source' => $source,
    );
}

function bgm_fetch_season_data($season, $range, $url) {
    @ini_set('memory_limit', '256M');
    list($code, $body, $err) = bgm_request($url, null, 25);
    if ($code < 200 || $code >= 300 || strlen($body) < 1000) {
        return array('ok' => false, 'error' => '镜像请求失败（HTTP ' . $code . '）' . ($err ? '：' . $err : ''));
    }

    $host = parse_url($url, PHP_URL_HOST);
    return bgm_parse_season_items($body, $range, 'bangumi-data 镜像：' . ($host ?: $url));
}

/**
 * 本地数据兜底：把 bangumi-data 的 data.json 上传到 data/bangumi-data.json
 * 即使服务器完全连不上外网也能导入。
 */
function bgm_fetch_season_local($range) {
    $file = dirname(__DIR__) . '/data/bangumi-data.json';
    if (!is_file($file)) {
        return array('ok' => false, 'error' => '没有本地 data/bangumi-data.json');
    }
    @ini_set('memory_limit', '256M');
    $body = @file_get_contents($file);
    if ($body === false || strlen($body) < 1000) {
        return array('ok' => false, 'error' => '本地 data/bangumi-data.json 读取失败');
    }
    return bgm_parse_season_items($body, $range, '本地番剧数据');
}

function bgm_fetch_season($season) {
    $range = bgm_season_range($season);
    if (!$range) {
        return array('ok' => false, 'error' => '季度格式不正确');
    }

    $errors = array();

    // 有本地快照时优先用本地（上传一次即可离线导入，速度最快）
    $localFirst = defined('BANGUMI_LOCAL_FIRST') ? BANGUMI_LOCAL_FIRST : true;
    if ($localFirst) {
        $local = bgm_fetch_season_local($range);
        if (!empty($local['ok'])) {
            return $local;
        }
        $errors[] = '本地数据：' . $local['error'];
    }

    // 再试官方 API（能通时有封面和简介）
    $api = bgm_fetch_season_api($season, $range);
    if (!empty($api['ok'])) {
        return $api;
    }
    $errors[] = '官方 API：' . $api['error'];

    // 没有开启本地优先时，API 之后再试本地
    if (!$localFirst) {
        $local = bgm_fetch_season_local($range);
        if (!empty($local['ok'])) {
            return $local;
        }
        $errors[] = '本地数据：' . $local['error'];
    }

    // 再按顺序试国内可访问的 bangumi-data 镜像
    foreach (bgm_data_mirrors() as $url) {
        $mirror = bgm_fetch_season_data($season, $range, $url);
        if (!empty($mirror['ok'])) {
            return $mirror;
        }
        $host = parse_url($url, PHP_URL_HOST);
        $errors[] = '镜像 ' . ($host ?: $url) . '：' . $mirror['error'];
    }

    return array('ok' => false, 'error' => implode('；', $errors));
}

function cache_get($pdo, $key, $allowExpired = false) {
    $st = $pdo->prepare('SELECT v, expires_at FROM cache WHERE k = ?');
    $st->execute(array($key));
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    if (!$allowExpired && strtotime($row['expires_at']) < time()) {
        return null;
    }
    return json_decode($row['v'], true);
}

function cache_put($pdo, $key, $value, $minutes = 360) {
    $pdo->prepare('REPLACE INTO cache (k, v, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))')
        ->execute(array($key, json_encode($value, JSON_UNESCAPED_UNICODE), (int) $minutes));
}

// ---------- 列表 ----------
if ($action === 'list') {
    $season = (string) ($_GET['season'] ?? season_current());
    if (!preg_match('/^\d{4}Q[1-4]$/', $season)) {
        $season = season_current();
    }

    $st = $pdo->prepare('SELECT * FROM anime WHERE season = ? ORDER BY sort ASC, air_date ASC, id ASC');
    $st->execute(array($season));
    $rows = $st->fetchAll();

    $counts = array();
    $ratings = array();
    $mine = array();
    if ($rows) {
        $ids = array_map(function ($r) {
            return (int) $r['id'];
        }, $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $cs = $pdo->prepare("SELECT subject_id, COUNT(*) AS c FROM anime_comments
            WHERE is_deleted = 0 AND subject_id IN ($in)
              AND user_id NOT IN (SELECT id FROM users WHERE role = 'super')
            GROUP BY subject_id");
        $cs->execute($ids);
        foreach ($cs->fetchAll() as $c) {
            $counts[(int) $c['subject_id']] = (int) $c['c'];
        }

        $rs = $pdo->prepare("SELECT subject_id, AVG(score) AS avg_score, COUNT(*) AS c
            FROM anime_ratings WHERE subject_id IN ($in) AND score > 0 GROUP BY subject_id");
        $rs->execute($ids);
        foreach ($rs->fetchAll() as $r) {
            $ratings[(int) $r['subject_id']] = array(
                'avg' => round((float) $r['avg_score'], 2),
                'count' => (int) $r['c'],
            );
        }

        if ($user) {
            $ms = $pdo->prepare("SELECT subject_id, score FROM anime_ratings
                WHERE user_id = ? AND subject_id IN ($in)");
            $ms->execute(array_merge(array($user['id']), $ids));
            foreach ($ms->fetchAll() as $m) {
                $mine[(int) $m['subject_id']] = (int) $m['score'];
            }
        }
    }

    $items = array();
    foreach ($rows as $r) {
        $rid = (int) $r['id'];
        $items[] = array(
            'id' => $rid,
            'title' => $r['title'],
            'title_cn' => $r['title_cn'],
            'air_date' => $r['air_date'],
            'image' => str_replace('http://', 'https://', (string) $r['image']),
            'summary' => $r['summary'],
            'comments' => $counts[$rid] ?? 0,
            'rating_avg' => $ratings[$rid]['avg'] ?? null,
            'rating_count' => $ratings[$rid]['count'] ?? 0,
            'my_rating' => $mine[$rid] ?? null,
            'bgm_url' => 'https://bgm.tv/subject/' . $rid,
        );
    }

    json_ok(array(
        'season' => $season,
        'label' => season_label($season),
        'current' => season_current(),
        'quarters' => season_quarters(8),
        'items' => $items,
        'bangumi_enabled' => BANGUMI_ENABLED,
        'can_import' => $admin && BANGUMI_ENABLED,
        'can_manage' => (bool) $admin,
    ));
}

// ---------- 评论 ----------
if ($action === 'comments') {
    $subjectId = (int) ($_GET['subject_id'] ?? 0);
    if ($subjectId <= 0) {
        json_fail('缺少番剧 ID');
    }
    $st = $pdo->prepare("SELECT c.*, u.avatar FROM anime_comments c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.subject_id = ? AND c.is_deleted = 0
          AND c.user_id NOT IN (SELECT id FROM users WHERE role = 'super')
        ORDER BY c.id DESC LIMIT 100");
    $st->execute(array($subjectId));
    $items = array();
    foreach ($st->fetchAll() as $c) {
        $items[] = array(
            'id' => (int) $c['id'],
            'user_id' => (int) $c['user_id'],
            'nickname' => $c['nickname'],
            'avatar_url' => user_avatar_url(array('id' => (int) $c['user_id'], 'avatar' => $c['avatar'] ?? null)),
            'content' => $c['content'],
            'created_at' => $c['created_at'],
            'time_ago' => time_ago($c['created_at']),
            'can_delete' => $admin,
        );
    }
    json_ok(array('items' => $items, 'can_delete' => $admin, 'logged_in' => (bool) $user));
}

if ($action === 'comment') {
    if (!$user) {
        json_fail('请先登录再评论', 401);
    }
    $subjectId = (int) ($input['subject_id'] ?? 0);
    $content = trim((string) ($input['content'] ?? ''));
    if ($subjectId <= 0) {
        json_fail('缺少番剧 ID');
    }
    if ($content === '' || mb_strlen($content) > 500) {
        json_fail('评论 1-500 字');
    }

    $st = $pdo->prepare('SELECT created_at FROM anime_comments WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute(array($user['id']));
    $last = $st->fetchColumn();
    if ($last && time() - strtotime($last) < 8) {
        json_fail('评论太快了，8 秒后再试');
    }

    $pdo->prepare('INSERT INTO anime_comments (subject_id, user_id, nickname, content) VALUES (?, ?, ?, ?)')
        ->execute(array($subjectId, $user['id'], $user['nickname'], $content));
    $id = (int) $pdo->lastInsertId();

    add_activity($pdo, $user, 'comment', '评论了新番：' . mb_substr($content, 0, 50), null, null);

    json_ok(array(
        'item' => array(
            'id' => $id,
            'user_id' => (int) $user['id'],
            'nickname' => $user['nickname'],
            'avatar_url' => user_avatar_url($user),
            'content' => $content,
            'created_at' => date('Y-m-d H:i:s'),
            'time_ago' => '刚刚',
            'can_delete' => $admin,
        ),
    ), '评论成功');
}

// ---------- 评分（0-5） ----------
if ($action === 'rate') {
    if (!$user) {
        json_fail('请先登录再评分', 401);
    }
    $subjectId = (int) ($input['subject_id'] ?? 0);
    $score = (int) ($input['score'] ?? -1);
    if ($subjectId <= 0) {
        json_fail('缺少番剧 ID');
    }
    if ($score < 0 || $score > 5) {
        json_fail('评分只能是 0-5');
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM anime WHERE id = ?');
    $st->execute(array($subjectId));
    if ((int) $st->fetchColumn() < 1) {
        json_fail('这部番不在本季列表里', 404);
    }

    $pdo->prepare(
        'INSERT INTO anime_ratings (subject_id, user_id, score)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()'
    )->execute(array($subjectId, $user['id'], $score));

    $avgSt = $pdo->prepare('SELECT AVG(score) AS avg_score, COUNT(*) AS c FROM anime_ratings WHERE subject_id = ? AND score > 0');
    $avgSt->execute(array($subjectId));
    $agg = $avgSt->fetch();

    json_ok(array(
        'subject_id' => $subjectId,
        'my_rating' => $score,
        'rating_avg' => round((float) $agg['avg_score'], 2),
        'rating_count' => (int) $agg['c'],
    ), '已评分');
}

// ---------- 手动添加（Bangumi 接口不通时的备用方案） ----------
if ($action === 'add') {
    if (!$admin) {
        json_fail('需要管理员权限', 403);
    }
    $season = (string) ($input['season'] ?? season_current());
    if (!preg_match('/^\d{4}Q[1-4]$/', $season)) {
        json_fail('季度格式不正确');
    }
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 160) {
        json_fail('标题不能为空且不超过 160 字');
    }
    $titleCn = trim((string) ($input['title_cn'] ?? ''));
    $airDateRaw = trim((string) ($input['air_date'] ?? ''));
    $airDate = bgm_clean_date($airDateRaw);
    if ($airDateRaw !== '' && $airDate === null) {
        json_fail('首播日期格式应为 YYYY-MM-DD');
    }
    $image = trim((string) ($input['image'] ?? ''));
    $image = str_replace('http://', 'https://', $image);
    $summary = trim((string) ($input['summary'] ?? ''));
    $bgmId = (int) ($input['bgm_id'] ?? 0);

    if ($bgmId <= 0) {
        // 生成一个不会撞车的本地 ID，仍然可以正常评论
        do {
            $bgmId = random_int(10000000, 99999999);
            $st = $pdo->prepare('SELECT COUNT(*) FROM anime WHERE id = ?');
            $st->execute(array($bgmId));
        } while ((int) $st->fetchColumn() > 0);
    }

    $pdo->prepare(
        'INSERT INTO anime (id, season, title, title_cn, air_date, image, summary, sort, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE season = VALUES(season), title = VALUES(title),
           title_cn = IF(VALUES(title_cn) IS NULL OR CHAR_LENGTH(VALUES(title_cn)) = 0, title_cn, VALUES(title_cn)),
           air_date = IF(VALUES(air_date) IS NULL, air_date, VALUES(air_date)),
           image = IF(VALUES(image) IS NULL OR CHAR_LENGTH(VALUES(image)) = 0, image, VALUES(image)),
           summary = IF(VALUES(summary) IS NULL OR CHAR_LENGTH(VALUES(summary)) = 0, summary, VALUES(summary)), updated_at = NOW()'
    )->execute(array(
        $bgmId,
        $season,
        mb_substr($title, 0, 160),
        mb_substr($titleCn, 0, 160),
        $airDate,
        mb_substr($image, 0, 255),
        mb_substr($summary, 0, 2000),
        999,
    ));

    json_ok(array('id' => $bgmId, 'season' => $season), '已添加');
}

// ---------- 从 Bangumi 导入 ----------
if ($action === 'import') {
    if (!$admin) {
        json_fail('需要管理员权限', 403);
    }
    @set_time_limit(180);
    $season = (string) ($input['season'] ?? season_current());
    if (!preg_match('/^\d{4}Q[1-4]$/', $season)) {
        json_fail('季度格式不正确');
    }

    $cacheKey = 'bgm_season_' . $season;
    $cached = cache_get($pdo, $cacheKey, true);

    $result = bgm_fetch_season($season);
    if (!$result['ok']) {
        if ($cached && !empty($cached['items'])) {
            $result = $cached;
        } else {
            json_fail('从 Bangumi 拉取失败：' . $result['error'] . '。可以先把新番手动加到数据库，或稍后再试。', 502);
        }
    } else {
        cache_put($pdo, $cacheKey, $result, 360);
    }

    $upsert = $pdo->prepare(
        'INSERT INTO anime (id, season, title, title_cn, air_date, image, summary, sort, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE season = VALUES(season), title = VALUES(title),
           title_cn = IF(VALUES(title_cn) IS NULL OR CHAR_LENGTH(VALUES(title_cn)) = 0, title_cn, VALUES(title_cn)),
           air_date = IF(VALUES(air_date) IS NULL, air_date, VALUES(air_date)),
           image = IF(VALUES(image) IS NULL OR CHAR_LENGTH(VALUES(image)) = 0, image, VALUES(image)),
           summary = IF(VALUES(summary) IS NULL OR CHAR_LENGTH(VALUES(summary)) = 0, summary, VALUES(summary)), updated_at = NOW()'
    );

    $n = 0;
    foreach ($result['items'] as $i => $it) {
        if (empty($it['id']) || $it['title'] === '') {
            continue;
        }
        $upsert->execute(array(
            (int) $it['id'],
            $season,
            mb_substr($it['title'], 0, 160),
            mb_substr($it['title_cn'], 0, 160),
            bgm_clean_date($it['air_date'] ?? null),
            str_replace('http://', 'https://', mb_substr($it['image'], 0, 255)),
            mb_substr($it['summary'], 0, 2000),
            $i,
        ));
        $n++;
    }

    json_ok(array(
        'season' => $season,
        'count' => $n,
        'source' => $result['source'] ?? '',
    ), '已导入 ' . $n . ' 部新番');
}

json_fail('未知操作', 404);
