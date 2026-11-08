<?php
/**
 * 관리자 — 「나의 인생 2막 캐릭터 진단」 대시보드
 * admin.php?view=diagnoses 에서 로그인 확인 뒤 include 됩니다. ($pdo 사용)
 * 직접 접근은 막습니다.
 */

declare(strict_types=1);

if (!isset($pdo) || empty($_SESSION['admin_ok'])) {
    http_response_code(403);
    exit('잘못된 접근입니다.');
}

require_once __DIR__ . '/diagnosis_data.php';

try {
    dxEnsureTable($pdo);
} catch (Throwable $e) {
    // 권한 문제 등은 아래 조회에서 안내
}

// ---- 삭제 ----------------------------------------------------------------
if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    try {
        $pdo->prepare('DELETE FROM diagnoses WHERE id = :id')->execute([':id' => (int) $_GET['id']]);
    } catch (Throwable $e) {
        // 조용히 무시
    }
    header('Location: admin.php?view=diagnoses');
    exit;
}

$typeNames = array_map(fn($t) => $t['name'], dxTypes());
$label = fn(array $map, $code): string => (string) ($map[$code] ?? $code);
$wpLabel = fn($csv): string => implode(', ', array_map(fn($w) => DX_WORKPLACES[$w] ?? $w, explode(',', (string) $csv)));

$where = '';

// ---- CSV 내려받기 (엑셀에서 한글이 깨지지 않도록 BOM 포함) ----------------------
if (($_GET['export'] ?? '') === 'csv') {
    try {
        $rows = $pdo->query("SELECT * FROM diagnoses $where ORDER BY created_at DESC")->fetchAll();
    } catch (Throwable $e) {
        http_response_code(500);
        exit('내보내기 중 오류: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="diagnoses_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = ['일시', '이메일', '유형', '연령대', '일한 곳', '가장 오래 일한 곳', '경력', '퇴직 시점', '관심 분야'];
    if (DX_SEND_EMAIL) $head[] = '메일 발송';
    fputcsv($out, $head);
    foreach ($rows as $r) {
        $line = [
            $r['created_at'], $r['email'], $typeNames[$r['type_code']] ?? $r['type_code'],
            $label(DX_AGES, $r['age_group']), $wpLabel($r['workplaces']), $label(DX_WORKPLACES, $r['main_workplace']),
            $label(DX_YEARS, $r['career_years']), $label(DX_RETIRE, $r['retire_timing']), $label(DX_INTERESTS, $r['interest']),
        ];
        if (DX_SEND_EMAIL) $line[] = (int) $r['email_sent'] ? '완료' : '실패';
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

// ---- 집계 ------------------------------------------------------------------
try {
    $today = date('Y-m-d 00:00:00');
    $week  = date('Y-m-d H:i:s', time() - 7 * 86400);
    $count = function (string $sql, array $p = []) use ($pdo): int {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return (int) $s->fetchColumn();
    };
    $stats = [
        'total'     => $count('SELECT COUNT(*) FROM diagnoses'),
        'today'     => $count('SELECT COUNT(*) FROM diagnoses WHERE created_at >= :d', [':d' => $today]),
        'week'      => $count('SELECT COUNT(*) FROM diagnoses WHERE created_at >= :d', [':d' => $week]),
        'failed'    => DX_SEND_EMAIL ? $count('SELECT COUNT(*) FROM diagnoses WHERE email_sent = 0') : 0,
    ];
    $group = function (string $col) use ($pdo): array {
        $out = [];
        foreach ($pdo->query("SELECT $col AS k, COUNT(*) AS c FROM diagnoses GROUP BY $col")->fetchAll() as $r) {
            $out[(string) $r['k']] = (int) $r['c'];
        }
        return $out;
    };
    $byType     = $group('type_code');
    $byAge      = $group('age_group');
    $byInterest = $group('interest');
    $byRetire   = $group('retire_timing');
    $byMain     = $group('main_workplace');

    $rows = $pdo->query("SELECT * FROM diagnoses $where ORDER BY created_at DESC LIMIT 500")->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('진단 응답 조회 중 오류가 발생했습니다: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$hx = fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** 항목별 가로 막대 */
function dxBars(array $labels, array $counts): string
{
    $total = max(1, array_sum($counts));
    $html = '';
    foreach ($labels as $code => $name) {
        $c = $counts[$code] ?? 0;
        $pct = (int) round($c / $total * 100);
        $html .= '<div class="bar-row"><span class="bar-label">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>'
               . '<span class="bar-track"><span class="bar-fill" style="width:' . $pct . '%"></span></span>'
               . '<span class="bar-num">' . $c . '명 · ' . $pct . '%</span></div>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>진단 대시보드 | 인생설계가이드</title>
<meta name="robots" content="noindex, nofollow">
<style>
    :root{--navy:#1c2b45;--gold:#b8934a;--bg:#faf7f2;--alt:#f1ece2;}
    *{box-sizing:border-box;}
    body{font-family:-apple-system,'Malgun Gothic',sans-serif;background:var(--bg);margin:0;color:#2b2b2b;}
    header{background:var(--navy);color:#fff;padding:20px 24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;}
    header h1{font-size:1.15rem;margin:0;}
    header a{color:#e8c98a;text-decoration:underline;font-size:0.9rem;}
    .wrap{padding:24px;max-width:1200px;margin:0 auto;}
    .tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
    .tabs a{padding:7px 16px;border-radius:999px;font-size:0.85rem;font-weight:700;text-decoration:none;background:#fff;color:var(--navy);border:1px solid #ddd;}
    .tabs a.active{background:var(--navy);color:#fff;border-color:var(--navy);}
    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:20px;}
    .card{background:#fff;border-radius:12px;padding:16px 18px;box-shadow:0 4px 14px rgba(28,43,69,0.06);}
    .card p{margin:0;font-size:0.85rem;color:#6b6b6b;font-weight:600;}
    .card b{display:block;font-size:1.8rem;color:var(--navy);margin-top:4px;}
    .card.warn b{color:#a33a3a;}
    .panels{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-bottom:24px;}
    .panel{background:#fff;border-radius:12px;padding:18px 20px;box-shadow:0 4px 14px rgba(28,43,69,0.06);}
    .panel h2{font-size:0.98rem;color:var(--navy);margin:0 0 12px;}
    .bar-row{display:grid;grid-template-columns:150px 1fr 86px;align-items:center;gap:10px;margin-bottom:8px;font-size:0.86rem;}
    .bar-label{font-weight:600;line-height:1.35;}
    td.nowrap{white-space:nowrap;}
    .bar-track{height:10px;background:var(--alt);border-radius:999px;overflow:hidden;}
    .bar-fill{display:block;height:100%;background:var(--gold);border-radius:999px;}
    .bar-num{color:#6b6b6b;text-align:right;white-space:nowrap;}
    .toolbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;}
    .toolbar h2{font-size:1rem;color:var(--navy);margin:0;}
    .toolbar .right{display:flex;gap:8px;flex-wrap:wrap;}
    .toolbar a{padding:7px 14px;border-radius:999px;font-size:0.82rem;font-weight:700;text-decoration:none;background:#fff;color:var(--navy);border:1px solid #ddd;}
    .toolbar a.active{background:var(--navy);color:#fff;border-color:var(--navy);}
    .toolbar a.csv{background:var(--gold);color:#fff;border-color:var(--gold);}
    .table-wrap{overflow-x:auto;}
    table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 14px rgba(28,43,69,0.08);min-width:1080px;}
    th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #eee;font-size:0.86rem;vertical-align:top;}
    th{background:var(--alt);color:var(--navy);font-weight:700;white-space:nowrap;}
    .tag{display:inline-block;padding:3px 10px;border-radius:999px;font-size:0.78rem;font-weight:700;background:var(--navy);color:#fff;white-space:nowrap;}
    .ok{color:#276b3a;font-weight:700;}
    .fail{color:#a33a3a;font-weight:700;}
    .delete{font-size:0.8rem;font-weight:700;text-decoration:none;padding:5px 10px;border-radius:6px;background:#fbeaea;color:#a33a3a;white-space:nowrap;}
    .result-link{font-size:0.8rem;font-weight:700;color:var(--navy);white-space:nowrap;}
    .empty{padding:60px 20px;text-align:center;color:#999;background:#fff;border-radius:12px;}
    .note{font-size:0.82rem;color:#6b6b6b;margin:10px 0 0;}
</style>
</head>
<body>
<header>
    <h1>인생 2막 진단 대시보드 (인생설계가이드)</h1>
    <a href="admin.php?logout=1">로그아웃</a>
</header>
<div class="wrap">
    <div class="tabs">
        <a href="admin.php">문의 내역</a>
        <a href="admin.php?view=diagnoses" class="active">인생 2막 진단</a>
        <a href="admin.php?view=lifenote">인생노트 점검</a>
    </div>

    <div class="cards">
        <div class="card"><p>전체 진단</p><b><?= $stats['total'] ?></b></div>
        <div class="card"><p>오늘</p><b><?= $stats['today'] ?></b></div>
        <div class="card"><p>최근 7일</p><b><?= $stats['week'] ?></b></div>
        <?php if (DX_SEND_EMAIL): ?>
        <div class="card<?= $stats['failed'] > 0 ? ' warn' : '' ?>"><p>메일 발송 실패</p><b><?= $stats['failed'] ?></b></div>
        <?php endif; ?>
    </div>

    <?php if ($stats['total'] > 0): ?>
    <div class="panels">
        <div class="panel"><h2>유형별</h2><?= dxBars($typeNames, $byType) ?></div>
        <div class="panel"><h2>연령대별</h2><?= dxBars(DX_AGES, $byAge) ?></div>
        <div class="panel"><h2>관심 분야별</h2><?= dxBars(DX_INTERESTS, $byInterest) ?></div>
        <div class="panel"><h2>퇴직 시점별</h2><?= dxBars(DX_RETIRE, $byRetire) ?></div>
        <div class="panel"><h2>가장 오래 일한 곳</h2><?= dxBars(DX_WORKPLACES, $byMain) ?></div>
    </div>
    <?php endif; ?>

    <div class="toolbar">
        <h2>진단 참여자 목록</h2>
        <div class="right">
            <a href="admin.php?view=diagnoses&export=csv" class="csv">엑셀(CSV) 내려받기</a>
        </div>
    </div>

    <?php if (count($rows) === 0): ?>
        <div class="empty">아직 진단 참여자가 없습니다. 방문자가 진단 후 이메일을 남기고 상세 결과를 보면 여기에 쌓입니다.</div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>일시</th><th>이메일</th><th>유형</th><th>연령대</th><th>일한 곳 (가장 오래)</th>
                <th>경력</th><th>퇴직 시점</th><th>관심 분야</th><?php if (DX_SEND_EMAIL): ?><th>메일</th><?php endif; ?><th>결과</th><th>작업</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap"><?= $hx(substr((string) $r['created_at'], 0, 16)) ?></td>
                <td><?= $hx($r['email']) ?></td>
                <td><span class="tag"><?= $hx($typeNames[$r['type_code']] ?? $r['type_code']) ?></span></td>
                <td><?= $hx($label(DX_AGES, $r['age_group'])) ?></td>
                <td><?= $hx($wpLabel($r['workplaces'])) ?> (<?= $hx($label(DX_WORKPLACES, $r['main_workplace'])) ?>)</td>
                <td><?= $hx($label(DX_YEARS, $r['career_years'])) ?></td>
                <td><?= $hx($label(DX_RETIRE, $r['retire_timing'])) ?></td>
                <td><?= $hx($label(DX_INTERESTS, $r['interest'])) ?></td>
                <?php if (DX_SEND_EMAIL): ?>
                <td><?= (int) $r['email_sent'] ? '<span class="ok">완료</span>' : '<span class="fail">실패</span>' ?></td>
                <?php endif; ?>
                <td><a class="result-link" href="result.php?t=<?= $hx($r['token']) ?>" target="_blank" rel="noopener">보기</a></td>
                <td><a class="delete" href="admin.php?view=diagnoses&action=delete&id=<?= (int) $r['id'] ?>" onclick="return confirm('이 참여자의 정보를 삭제하시겠습니까? 결과 링크도 더 이상 열리지 않습니다.');">삭제</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="note">최근 500건까지 표시합니다. 전체는 엑셀(CSV)로 내려받으세요. 삭제 요청을 받으면 해당 행을 삭제해 주세요.</p>
    <?php endif; ?>
</div>
</body>
</html>
