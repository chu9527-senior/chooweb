<?php
/**
 * 추영준(인생설계가이드) 홍보 웹사이트 - 문의 내역 관리자 페이지
 *
 * 비밀번호로 보호되며, inquiries 테이블의 문의 내역을 최신순으로 보여줍니다.
 * 읽음/안읽음 표시, 삭제 기능을 지원합니다.
 * 접속: https://lifetimes.kr/admin.php
 * 비밀번호는 db_config.php의 ADMIN_PASSWORD 값입니다.
 */

declare(strict_types=1);
session_start();

$dbConfigPath = __DIR__ . '/db_config.php';

if (!file_exists($dbConfigPath)) {
    http_response_code(500);
    exit('db_config.php가 없습니다. 먼저 DB 설정을 완료해 주세요.');
}
require $dbConfigPath;

if (!defined('ADMIN_PASSWORD')) {
    http_response_code(500);
    exit('db_config.php에 ADMIN_PASSWORD가 설정되어 있지 않습니다.');
}

// ---- 로그아웃 ----------------------------------------------------------
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ---- 로그인 처리 --------------------------------------------------------
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (hash_equals(ADMIN_PASSWORD, (string) $_POST['password'])) {
        $_SESSION['admin_ok'] = true;
    } else {
        $loginError = '비밀번호가 올바르지 않습니다.';
    }
}

$isLoggedIn = !empty($_SESSION['admin_ok']);

// ---- 로그인 화면 --------------------------------------------------------
if (!$isLoggedIn) {
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>관리자 로그인 | 인생설계가이드</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        body{font-family:-apple-system,'Malgun Gothic',sans-serif;background:#f1ece2;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
        form{background:#fff;padding:36px 32px;border-radius:14px;box-shadow:0 10px 30px rgba(28,43,69,0.12);width:100%;max-width:340px;}
        h1{font-size:1.15rem;color:#1c2b45;margin:0 0 20px;}
        input[type=password]{width:100%;padding:12px 14px;border:1px solid #ccc;border-radius:8px;font-size:1rem;box-sizing:border-box;margin-bottom:14px;}
        button{width:100%;padding:12px;background:#1c2b45;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-size:0.98rem;}
        .error{color:#a33a3a;font-size:0.88rem;margin:-6px 0 14px;}
    </style>
    </head>
    <body>
        <form method="POST">
            <h1>관리자 로그인</h1>
            <?php if ($loginError !== ''): ?>
                <p class="error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <input type="password" name="password" placeholder="비밀번호" autofocus required>
            <button type="submit">로그인</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// ---- DB 연결 ------------------------------------------------------------
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    exit('DB 연결 중 오류가 발생했습니다: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// ---- 읽음/안읽음/삭제 처리 ------------------------------------------------
if (isset($_GET['action'], $_GET['id'])) {
    $id = (int) $_GET['id'];
    $action = (string) $_GET['action'];

    if ($id > 0) {
        try {
            if ($action === 'read') {
                $pdo->prepare('UPDATE inquiries SET is_read = 1 WHERE id = :id')->execute([':id' => $id]);
            } elseif ($action === 'unread') {
                $pdo->prepare('UPDATE inquiries SET is_read = 0 WHERE id = :id')->execute([':id' => $id]);
            } elseif ($action === 'delete') {
                $pdo->prepare('DELETE FROM inquiries WHERE id = :id')->execute([':id' => $id]);
            }
        } catch (Throwable $e) {
            // 조용히 무시하고 목록으로 돌아감
        }
    }

    $redirectQuery = isset($_GET['filter']) ? '?filter=' . urlencode((string) $_GET['filter']) : '';
    header('Location: admin.php' . $redirectQuery);
    exit;
}

// ---- 목록 조회 -----------------------------------------------------------
$filter = $_GET['filter'] ?? 'all';
$where = $filter === 'unread' ? 'WHERE is_read = 0' : '';

try {
    $rows = $pdo->query("SELECT * FROM inquiries $where ORDER BY created_at DESC")->fetchAll();
    $unreadCount = (int) $pdo->query('SELECT COUNT(*) FROM inquiries WHERE is_read = 0')->fetchColumn();
    $totalCount = (int) $pdo->query('SELECT COUNT(*) FROM inquiries')->fetchColumn();
} catch (Throwable $e) {
    http_response_code(500);
    exit('DB 조회 중 오류가 발생했습니다: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>문의 내역 관리 | 인생설계가이드</title>
<meta name="robots" content="noindex, nofollow">
<style>
    :root{--navy:#1c2b45;--gold:#b8934a;--bg:#faf7f2;}
    *{box-sizing:border-box;}
    body{font-family:-apple-system,'Malgun Gothic',sans-serif;background:var(--bg);margin:0;color:#2b2b2b;}
    header{background:var(--navy);color:#fff;padding:20px 24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;}
    header h1{font-size:1.15rem;margin:0;}
    header a{color:#e8c98a;text-decoration:underline;font-size:0.9rem;}
    .wrap{padding:24px;max-width:1200px;margin:0 auto;overflow-x:auto;}
    .toolbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px;}
    .tabs{display:flex;gap:8px;}
    .tabs a{padding:7px 16px;border-radius:999px;font-size:0.85rem;font-weight:700;text-decoration:none;background:#fff;color:var(--navy);border:1px solid #ddd;}
    .tabs a.active{background:var(--navy);color:#fff;border-color:var(--navy);}
    .count{color:#6b6b6b;font-size:0.9rem;}
    table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 14px rgba(28,43,69,0.08);min-width:1000px;}
    th,td{padding:12px 14px;text-align:left;border-bottom:1px solid #eee;font-size:0.9rem;vertical-align:top;}
    th{background:#f1ece2;color:var(--navy);font-weight:700;white-space:nowrap;}
    tr:last-child td{border-bottom:none;}
    tr.unread{background:#fdf6e8;}
    tr.unread td:first-child{border-left:3px solid var(--gold);}
    .type-tag{display:inline-block;padding:3px 10px;border-radius:999px;font-size:0.78rem;font-weight:700;background:var(--navy);color:#fff;white-space:nowrap;}
    .status-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:0.78rem;font-weight:700;white-space:nowrap;}
    .status-badge.new{background:var(--gold);color:#fff;}
    .status-badge.done{background:#e5e5e5;color:#777;}
    .msg-cell{max-width:300px;white-space:pre-wrap;}
    .actions{display:flex;flex-direction:column;gap:6px;white-space:nowrap;}
    .actions a{font-size:0.8rem;font-weight:700;text-decoration:none;padding:5px 10px;border-radius:6px;text-align:center;}
    .actions .toggle{background:#eef1f6;color:var(--navy);}
    .actions .delete{background:#fbeaea;color:#a33a3a;}
    .empty{padding:60px 20px;text-align:center;color:#999;}
</style>
</head>
<body>
<header>
    <h1>문의 내역 관리 (인생설계가이드)</h1>
    <a href="admin.php?logout=1">로그아웃</a>
</header>
<div class="wrap">
    <div class="toolbar">
        <div class="tabs">
            <a href="admin.php" class="<?= $filter === 'all' ? 'active' : '' ?>">전체 (<?= $totalCount ?>)</a>
            <a href="admin.php?filter=unread" class="<?= $filter === 'unread' ? 'active' : '' ?>">안읽음 (<?= $unreadCount ?>)</a>
        </div>
        <p class="count">총 <?= count($rows) ?>건 표시 중</p>
    </div>

    <?php if (count($rows) === 0): ?>
        <div class="empty"><?= $filter === 'unread' ? '안읽은 문의가 없습니다.' : '아직 접수된 문의가 없습니다.' ?></div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>상태</th>
                <th>접수일시</th>
                <th>유형</th>
                <th>이름</th>
                <th>소속</th>
                <th>연락처</th>
                <th>이메일</th>
                <th>문의 내용</th>
                <th>작업</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row):
                $isUnread = (int) $row['is_read'] === 0;
                $qs = '&filter=' . urlencode($filter);
            ?>
            <tr class="<?= $isUnread ? 'unread' : '' ?>">
                <td><span class="status-badge <?= $isUnread ? 'new' : 'done' ?>"><?= $isUnread ? '안읽음' : '확인함' ?></span></td>
                <td><?= h((string) $row['created_at']) ?></td>
                <td><span class="type-tag"><?= h((string) $row['inquiry_type']) ?></span></td>
                <td><?= h((string) $row['name']) ?></td>
                <td><?= h((string) ($row['organization'] ?? '')) ?: '-' ?></td>
                <td><?= h((string) $row['phone']) ?></td>
                <td><?= h((string) $row['email']) ?></td>
                <td class="msg-cell"><?= h((string) $row['message']) ?></td>
                <td>
                    <div class="actions">
                        <?php if ($isUnread): ?>
                            <a class="toggle" href="admin.php?action=read&id=<?= (int) $row['id'] ?><?= $qs ?>">확인함으로 표시</a>
                        <?php else: ?>
                            <a class="toggle" href="admin.php?action=unread&id=<?= (int) $row['id'] ?><?= $qs ?>">안읽음으로 표시</a>
                        <?php endif; ?>
                        <a class="delete" href="admin.php?action=delete&id=<?= (int) $row['id'] ?><?= $qs ?>" onclick="return confirm('이 문의를 삭제하시겠습니까? 되돌릴 수 없습니다.');">삭제</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</body>
</html>
