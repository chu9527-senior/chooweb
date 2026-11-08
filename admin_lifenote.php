<?php
/**
 * 관리자 — 「만일 대비 준비도 점검」(인생노트) 대시보드
 * admin.php?view=lifenote 에서 로그인 확인 뒤 include 됩니다. ($pdo 사용)
 */

declare(strict_types=1);

if (!isset($pdo) || empty($_SESSION['admin_ok'])) {
    http_response_code(403);
    exit('잘못된 접근입니다.');
}

require_once __DIR__ . '/lifenote_data.php';

try {
    lnEnsureTable($pdo);
} catch (Throwable $e) {
    // 아래 조회에서 안내
}

if (($_GET['action'] ?? '') === 'delete' && isset($_GET['id'])) {
    try {
        $pdo->prepare('DELETE FROM lifenote_checks WHERE id = :id')->execute([':id' => (int) $_GET['id']]);
    } catch (Throwable $e) {
    }
    header('Location: admin.php?view=lifenote');
    exit;
}

$levels = array_map(fn($l) => $l['name'], lnLevels());
$label = fn(array $map, $code): string => (string) ($map[$code] ?? $code);

if (($_GET['export'] ?? '') === 'csv') {
    try {
        $rows = $pdo->query('SELECT * FROM lifenote_checks ORDER BY created_at DESC')->fetchAll();
    } catch (Throwable $e) {
        http_response_code(500);
        exit('내보내기 중 오류: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="lifenote_checks_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['일시', '이메일', '점수', '단계', '연령대', '가족 형태', '관심 영역', '먼저 챙길 영역', '건강·돌봄%', '의료 결정%', '재산·디지털%', '회고·메시지%']);
    foreach ($rows as $r) {
        $a = lnScore((string) $r['answers'], (string) $r['focus'])['areas'];
        fputcsv($out, [
            $r['created_at'], $r['email'], $r['score'], $levels[(int) $r['level_code']] ?? '',
            $label(LN_AGES, $r['age_group']), $label(LN_HOUSEHOLDS, $r['household']),
            $label(LN_FOCUS, $r['focus']), $label(LN_AREAS, $r['weakest']),
            $a['care'], $a['medical'], $a['assets'], $a['story'],
        ]);
    }
    fclose($out);
    exit;
}

try {
    $count = function (string $sql, array $p = []) use ($pdo): int {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return (int) $s->fetchColumn();
    };
    $stats = [
        'total' => $count('SELECT COUNT(*) FROM lifenote_checks'),
        'today' => $count('SELECT COUNT(*) FROM lifenote_checks WHERE created_at >= :d', [':d' => date('Y-m-d 00:00:00')]),
        'week'  => $count('SELECT COUNT(*) FROM lifenote_checks WHERE created_at >= :d', [':d' => date('Y-m-d H:i:s', time() - 7 * 86400)]),
        'avg'   => (int) round((float) $pdo->query('SELECT AVG(score) FROM lifenote_checks')->fetchColumn()),
    ];
    $group = function (string $col) use ($pdo): array {
        $out = [];
        foreach ($pdo->query("SELECT $col AS k, COUNT(*) AS c FROM lifenote_checks GROUP BY $col")->fetchAll() as $r) {
            $out[(string) $r['k']] = (int) $r['c'];
        }
        return $out;
    };
    $byLevel = $group('level_code');
    $byAge   = $group('age_group');
    $byHouse = $group('household');
    $byFocus = $group('focus');
    $byWeak  = $group('weakest');
    $rows = $pdo->query('SELECT * FROM lifenote_checks ORDER BY created_at DESC LIMIT 500')->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    exit('점검 응답 조회 중 오류가 발생했습니다: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$hx = fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

function lnBars(array $labels, array $counts): string
{
    $total = max(1, array_sum($counts));
    $html = '';
    foreach ($labels as $code => $name) {
        $c = $counts[(string) $code] ?? 0;
        $pct = (int) round($c / $total * 100);
        $html .= '<div class="bar-row"><span class="bar-label">' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '</span>'
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
<title>인생노트 점검 대시보드 | 인생설계가이드</title>
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
    .panels{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-bottom:24px;}
    .panel{background:#fff;border-radius:12px;padding:18px 20px;box-shadow:0 4px 14px rgba(28,43,69,0.06);}
    .panel h2{font-size:0.98rem;color:var(--navy);margin:0 0 12px;}
    .bar-row{display:grid;grid-template-columns:150px 1fr 86px;align-items:center;gap:10px;margin-bottom:8px;font-size:0.86rem;}
    .bar-label{font-weight:600;line-height:1.35;}
    .bar-track{height:10px;background:var(--alt);border-radius:999px;overflow:hidden;}
    .bar-fill{display:block;height:100%;background:var(--gold);border-radius:999px;}
    .bar-num{color:#6b6b6b;text-align:right;white-space:nowrap;}
    .toolbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;}
    .toolbar h2{font-size:1rem;color:var(--navy);margin:0;}
    .toolbar a{padding:7px 14px;border-radius:999px;font-size:0.82rem;font-weight:700;text-decoration:none;background:var(--gold);color:#fff;}
    .table-wrap{overflow-x:auto;}
    table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 4px 14px rgba(28,43,69,0.08);min-width:1000px;}
    th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #eee;font-size:0.86rem;vertical-align:top;}
    th{background:var(--alt);color:var(--navy);font-weight:700;white-space:nowrap;}
    td.nowrap{white-space:nowrap;}
    .tag{display:inline-block;padding:3px 10px;border-radius:999px;font-size:0.78rem;font-weight:700;background:var(--navy);color:#fff;white-space:nowrap;}
    .score{font-weight:800;color:var(--navy);font-size:1rem;}
    .delete{font-size:0.8rem;font-weight:700;text-decoration:none;padding:5px 10px;border-radius:6px;background:#fbeaea;color:#a33a3a;white-space:nowrap;}
    .result-link{font-size:0.8rem;font-weight:700;color:var(--navy);white-space:nowrap;}
    .empty{padding:60px 20px;text-align:center;color:#999;background:#fff;border-radius:12px;}
    .note{font-size:0.82rem;color:#6b6b6b;margin:10px 0 0;}
</style>
</head>
<body>
<header>
    <h1>인생노트 점검 대시보드 (인생설계가이드)</h1>
    <a href="admin.php?logout=1">로그아웃</a>
</header>
<div class="wrap">
    <div class="tabs">
        <a href="admin.php">문의 내역</a>
        <a href="admin.php?view=diagnoses">인생 2막 진단</a>
        <a href="admin.php?view=lifenote" class="active">인생노트 점검</a>
    </div>

    <div class="cards">
        <div class="card"><p>전체 점검</p><b><?= $stats['total'] ?></b></div>
        <div class="card"><p>오늘</p><b><?= $stats['today'] ?></b></div>
        <div class="card"><p>최근 7일</p><b><?= $stats['week'] ?></b></div>
        <div class="card"><p>평균 준비도</p><b><?= $stats['total'] > 0 ? $stats['avg'] . '점' : '-' ?></b></div>
    </div>

    <?php if ($stats['total'] > 0): ?>
    <div class="panels">
        <div class="panel"><h2>준비 단계별</h2><?= lnBars($levels, $byLevel) ?></div>
        <div class="panel"><h2>먼저 챙길 영역 (가장 약한 영역)</h2><?= lnBars(LN_AREAS, $byWeak) ?></div>
        <div class="panel"><h2>관심 영역</h2><?= lnBars(LN_FOCUS, $byFocus) ?></div>
        <div class="panel"><h2>연령대별</h2><?= lnBars(LN_AGES, $byAge) ?></div>
        <div class="panel"><h2>가족 형태</h2><?= lnBars(LN_HOUSEHOLDS, $byHouse) ?></div>
    </div>
    <?php endif; ?>

    <div class="toolbar">
        <h2>점검 참여자 목록</h2>
        <a href="admin.php?view=lifenote&export=csv">엑셀(CSV) 내려받기</a>
    </div>

    <?php if (count($rows) === 0): ?>
        <div class="empty">아직 점검 참여자가 없습니다. 방문자가 만일 대비 점검 후 이메일을 남기고 상세 결과를 보면 여기에 쌓입니다.</div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr><th>일시</th><th>이메일</th><th>점수</th><th>단계</th><th>연령대</th><th>가족 형태</th><th>관심 영역</th><th>먼저 챙길 영역</th><th>결과</th><th>작업</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap"><?= $hx(substr((string) $r['created_at'], 0, 16)) ?></td>
                <td><?= $hx($r['email']) ?></td>
                <td><span class="score"><?= (int) $r['score'] ?></span>점</td>
                <td><span class="tag"><?= $hx($levels[(int) $r['level_code']] ?? '') ?></span></td>
                <td><?= $hx($label(LN_AGES, $r['age_group'])) ?></td>
                <td><?= $hx($label(LN_HOUSEHOLDS, $r['household'])) ?></td>
                <td><?= $hx($label(LN_FOCUS, $r['focus'])) ?></td>
                <td><?= $hx($label(LN_AREAS, $r['weakest'])) ?></td>
                <td><a class="result-link" href="lifenote_result.php?t=<?= $hx($r['token']) ?>" target="_blank" rel="noopener">보기</a></td>
                <td><a class="delete" href="admin.php?view=lifenote&action=delete&id=<?= (int) $r['id'] ?>" onclick="return confirm('이 참여자의 정보를 삭제하시겠습니까? 결과 페이지도 더 이상 열리지 않습니다.');">삭제</a></td>
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
