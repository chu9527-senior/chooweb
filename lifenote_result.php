<?php
/**
 * 「만일 대비 준비도 점검」 — 상세 결과 페이지 (본인 전용 주소: lifenote_result.php?t=토큰)
 */

declare(strict_types=1);

$dbConfigPath = __DIR__ . '/db_config.php';
if (file_exists($dbConfigPath)) {
    require $dbConfigPath;
}
require __DIR__ . '/lifenote_data.php';

$token = isset($_GET['t']) ? (string) $_GET['t'] : '';
$d = null;
$createdAt = '';

if (preg_match('/^[a-f0-9]{32}$/', $token) && (defined('DB_DSN') || defined('DB_HOST'))) {
    try {
        $stmt = lnPdo()->prepare('SELECT * FROM lifenote_checks WHERE token = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        if ($row) {
            $d = lnBuildDetail([
                'age' => $row['age_group'], 'household' => $row['household'],
                'answers' => $row['answers'], 'focus' => $row['focus'],
            ]);
            $createdAt = substr((string) $row['created_at'], 0, 10);
        }
    } catch (Throwable $e) {
        error_log('[lifenote_result.php] 조회 실패: ' . $e->getMessage());
    }
}
if ($d === null) {
    http_response_code(404);
}
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>만일 대비 준비도 점검 결과 | 인생설계가이드</title>
<meta name="theme-color" content="#faf7f2">
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link rel="stylesheet" as="style" crossorigin href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/static/pretendard.css">
<style>
  :root{
    --color-bg:#faf7f2;--color-bg-alt:#f1ece2;--color-navy:#1c2b45;--color-navy-light:#324566;
    --color-gold:#b8934a;--color-gold-light:#d8b978;--color-text:#2b2b2b;--color-text-muted:#6b6b6b;
    --color-white:#ffffff;--radius:14px;--shadow:0 10px 30px rgba(28,43,69,0.08);font-size:16px;
  }
  *,*::before,*::after{box-sizing:border-box;}
  body{margin:0;font-family:'Pretendard',-apple-system,BlinkMacSystemFont,'Malgun Gothic',sans-serif;color:var(--color-text);background:var(--color-bg);line-height:1.7;word-break:keep-all;}
  a{color:inherit;}
  header{border-bottom:1px solid rgba(28,43,69,0.08);}
  .wrap{max-width:720px;margin:0 auto;padding:0 20px;}
  .nav{height:68px;display:flex;align-items:center;justify-content:space-between;}
  .brand{font-weight:800;font-size:1.15rem;color:var(--color-navy);text-decoration:none;letter-spacing:-0.02em;}
  .brand span{color:var(--color-gold);}
  .home{font-weight:600;color:var(--color-navy);text-decoration:none;font-size:0.95rem;}
  main{padding:40px 0 72px;}
  .score-card{background:var(--color-navy);color:var(--color-white);border-radius:var(--radius);padding:30px 24px;box-shadow:var(--shadow);text-align:center;}
  .score-card .eyebrow{margin:0 0 8px;font-size:0.88rem;font-weight:700;color:var(--color-gold-light);}
  .score-num{margin:0;font-size:3rem;font-weight:800;line-height:1.1;}
  .score-num small{font-size:1.1rem;font-weight:700;opacity:.8;margin-left:4px;}
  .score-card h1{margin:10px 0 12px;font-size:1.6rem;line-height:1.35;}
  .score-card p.desc{margin:0;opacity:.92;text-align:left;}
  .detail{background:var(--color-white);border-radius:var(--radius);box-shadow:var(--shadow);padding:6px 24px 28px;margin-top:20px;}
  .detail h2{font-size:1.15rem;color:var(--color-navy);margin:28px 0 12px;}
  .detail h3{font-size:1.02rem;color:var(--color-navy);margin:20px 0 8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
  .detail h3 small{color:var(--color-text-muted);font-weight:600;}
  .detail ul,.detail ol{margin:0;padding-left:22px;}
  .detail li{margin-bottom:6px;}
  .detail a{color:var(--color-navy);font-weight:700;}
  .tag{font-size:0.75rem;font-weight:700;padding:2px 10px;border-radius:999px;background:var(--color-navy);color:var(--color-white);}
  .tag.soft{background:var(--color-bg-alt);color:var(--color-navy);}
  .areas{display:grid;gap:10px;}
  .area-row{display:grid;grid-template-columns:120px 1fr;gap:4px 12px;align-items:center;}
  .area-name{font-weight:700;color:var(--color-navy);font-size:0.95rem;}
  .area-track{height:12px;background:var(--color-bg-alt);border-radius:999px;overflow:hidden;}
  .area-fill{display:block;height:100%;background:var(--color-navy-light);border-radius:999px;}
  .area-row.weak .area-fill{background:var(--color-gold);}
  .area-pct{grid-column:2;font-size:0.85rem;color:var(--color-text-muted);}
  .disclaimer{margin:24px 0 0;font-size:0.85rem;color:var(--color-text-muted);background:var(--color-bg);padding:12px 14px;border-radius:10px;}
  .meta{color:var(--color-text-muted);font-size:0.88rem;margin:14px 0 0;}
  .actions{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:32px;}
  .btn{display:inline-flex;align-items:center;justify-content:center;padding:14px 28px;border-radius:999px;font-weight:700;font-size:0.98rem;text-decoration:none;border:2px solid transparent;}
  .btn:active{transform:scale(0.97);}
  .btn-primary{background:var(--color-navy);color:var(--color-white);}
  .btn-primary:hover{background:var(--color-navy-light);}
  .btn-outline{border-color:var(--color-navy);color:var(--color-navy);}
  .btn-outline:hover{background:var(--color-navy);color:var(--color-white);}
  .btn:focus-visible,.brand:focus-visible,.home:focus-visible,.detail a:focus-visible{outline:2px solid var(--color-gold);outline-offset:3px;border-radius:6px;}
  .empty{background:var(--color-white);border-radius:var(--radius);box-shadow:var(--shadow);padding:40px 24px;text-align:center;}
  .empty h1{color:var(--color-navy);font-size:1.3rem;margin:0 0 10px;}
  .empty p{color:var(--color-text-muted);margin:0;}
  @media (min-width:768px){.score-card,.detail{padding-left:36px;padding-right:36px;}.score-card h1{font-size:1.9rem;}}
</style>
</head>
<body>
<header>
  <div class="wrap nav">
    <a href="index.html" class="brand">인생설계<span>가이드</span></a>
    <a href="index.html" class="home">홈으로</a>
  </div>
</header>
<main class="wrap">
<?php if ($d): ?>
  <section class="score-card" aria-labelledby="lvName">
    <p class="eyebrow">나의 만일 대비 준비도</p>
    <p class="score-num"><?= (int) $d['score'] ?><small>점</small></p>
    <h1 id="lvName"><?= $e($d['level']['name']) ?></h1>
    <p class="desc"><?= $e($d['level']['desc']) ?></p>
  </section>
  <section class="detail">
    <?= lnDetailHtml($d) ?>
  </section>
  <p class="meta">점검일 <?= $e($createdAt) ?> · 이 페이지는 주소를 아는 분만 볼 수 있습니다. 즐겨찾기해 두시면 언제든 다시 보실 수 있습니다.</p>
  <div class="actions">
    <a href="lifenote.html" class="btn btn-primary">인생노트 과정 보기</a>
    <a href="index.html#contact" class="btn btn-outline">강연 문의하기</a>
  </div>
<?php else: ?>
  <section class="empty">
    <h1>결과를 찾을 수 없습니다</h1>
    <p>주소가 잘못되었거나 삭제된 결과입니다. 점검은 3분이면 다시 할 수 있습니다.</p>
    <div class="actions"><a href="index.html#check-lifenote" class="btn btn-primary">점검 시작하기</a></div>
  </section>
<?php endif; ?>
</main>
</body>
</html>
