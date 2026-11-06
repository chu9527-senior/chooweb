<?php
/**
 * 「나의 인생 2막 캐릭터 진단」 — 결과 다시 보기 페이지 (마이페이지 대신)
 * 진단 후 바로 이동하는 본인 전용 주소(result.php?t=토큰)로 상세 결과를 보여줍니다.
 * (메일 발송을 켜면 같은 주소가 메일에도 들어갑니다)
 */

declare(strict_types=1);

$dbConfigPath = __DIR__ . '/db_config.php';
if (file_exists($dbConfigPath)) {
    require $dbConfigPath;
}
require __DIR__ . '/diagnosis_data.php';

$token = isset($_GET['t']) ? (string) $_GET['t'] : '';
$detail = null;
$createdAt = '';

if (preg_match('/^[a-f0-9]{32}$/', $token) && (defined('DB_DSN') || defined('DB_HOST'))) {
    try {
        $stmt = dxPdo()->prepare('SELECT * FROM diagnoses WHERE token = :t LIMIT 1');
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();
        if ($row) {
            $detail = dxBuildDetail([
                'age'        => $row['age_group'],
                'workplaces' => explode(',', $row['workplaces']),
                'main'       => $row['main_workplace'],
                'years'      => $row['career_years'],
                'answers'    => $row['answers'],
                'retire'     => $row['retire_timing'],
                'interest'   => $row['interest'],
            ]);
            $createdAt = substr((string) $row['created_at'], 0, 10);
        }
    } catch (Throwable $e) {
        error_log('[result.php] 조회 실패: ' . $e->getMessage());
    }
}

if ($detail === null) {
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
<title><?= $detail ? $e($detail['type']['name']) . ' | ' : '' ?>나의 인생 2막 캐릭터 진단 | 인생설계가이드</title>
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
  .nav a.home{font-weight:600;color:var(--color-navy);text-decoration:none;font-size:0.95rem;}
  main{padding:40px 0 72px;}
  .type-card{background:var(--color-navy);color:var(--color-white);border-radius:var(--radius);padding:30px 24px;box-shadow:var(--shadow);}
  .type-card .eyebrow{margin:0 0 6px;font-size:0.85rem;font-weight:700;color:var(--color-gold-light);}
  .type-card h1{margin:0 0 8px;font-size:1.6rem;line-height:1.4;}
  .type-card .tagline{margin:0 0 14px;color:var(--color-gold-light);font-weight:600;}
  .type-card .summary{margin:0;opacity:.92;}
  .keywords{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0 0;padding:0;list-style:none;}
  .keywords li{border:1px solid rgba(216,185,120,0.5);color:var(--color-gold-light);padding:4px 12px;border-radius:999px;font-size:0.85rem;font-weight:700;}
  .detail{background:var(--color-white);border-radius:var(--radius);box-shadow:var(--shadow);padding:6px 24px 28px;margin-top:20px;}
  .detail h2{font-size:1.12rem;color:var(--color-navy);margin:26px 0 10px;}
  .detail ul,.detail ol{margin:0;padding-left:22px;}
  .detail li{margin-bottom:6px;}
  .detail p{margin:0;}
  .detail a{color:var(--color-navy);font-weight:700;}
  .paths{display:flex;flex-wrap:wrap;gap:8px;}
  .paths span{background:var(--color-bg-alt);color:var(--color-navy);font-weight:700;font-size:0.92rem;padding:6px 14px;border-radius:999px;}
  .meta{color:var(--color-text-muted);font-size:0.88rem;margin:14px 0 0;}
  .actions{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:32px;}
  .btn{display:inline-flex;align-items:center;justify-content:center;padding:14px 28px;border-radius:999px;font-weight:700;font-size:0.98rem;text-decoration:none;border:2px solid transparent;transition:background .15s ease;}
  .btn:active{transform:scale(0.97);}
  .btn-primary{background:var(--color-navy);color:var(--color-white);}
  .btn-primary:hover{background:var(--color-navy-light);}
  .btn-outline{border-color:var(--color-navy);color:var(--color-navy);}
  .btn-outline:hover{background:var(--color-navy);color:var(--color-white);}
  .btn:focus-visible,.brand:focus-visible,.home:focus-visible,.detail a:focus-visible{outline:2px solid var(--color-gold);outline-offset:3px;border-radius:6px;}
  .empty{background:var(--color-white);border-radius:var(--radius);box-shadow:var(--shadow);padding:40px 24px;text-align:center;}
  .empty h1{color:var(--color-navy);font-size:1.3rem;margin:0 0 10px;}
  .empty p{color:var(--color-text-muted);margin:0;}
  @media (min-width:768px){.type-card h1{font-size:1.9rem;}.type-card,.detail{padding-left:36px;padding-right:36px;}}
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
<?php if ($detail): $t = $detail['type']; ?>
  <section class="type-card" aria-labelledby="typeName">
    <p class="eyebrow">나의 인생 2막 캐릭터</p>
    <h1 id="typeName"><?= $e($detail['headline']) ?></h1>
    <p class="tagline">"<?= $e($t['tagline']) ?>"</p>
    <p class="summary"><?= $e($t['summary']) ?></p>
    <ul class="keywords" aria-label="키워드">
      <?php foreach ($t['keywords'] as $k): ?><li><?= $e($k) ?></li><?php endforeach; ?>
    </ul>
  </section>
  <section class="detail">
    <?= dxDetailHtml($detail) ?>
  </section>
  <p class="meta">진단일 <?= $e($createdAt) ?> · 이 페이지는 주소를 아는 분만 볼 수 있습니다. 즐겨찾기해 두시면 언제든 다시 보실 수 있습니다.</p>
  <div class="actions">
    <a href="index.html#contact" class="btn btn-primary">강연 문의하기</a>
    <a href="index.html#diagnosis" class="btn btn-outline">다시 진단하기</a>
  </div>
<?php else: ?>
  <section class="empty">
    <h1>결과를 찾을 수 없습니다</h1>
    <p>링크가 잘못되었거나 삭제된 결과입니다. 진단은 3분이면 다시 할 수 있습니다.</p>
    <div class="actions">
      <a href="index.html#diagnosis" class="btn btn-primary">진단 시작하기</a>
    </div>
  </section>
<?php endif; ?>
</main>
</body>
</html>
