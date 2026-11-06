<?php
/**
 * 「나의 인생 2막 캐릭터 진단」 — 문항 코드, 유형 판정, 상세 결과 내용
 * diagnosis.php(저장·메일 발송)와 result.php(결과 링크 페이지)가 함께 사용합니다.
 *
 * 유형 이름·한 줄 소개와 문항 코드는 index.html 진단 스크립트(DX_TYPES 등)와 같아야 합니다.
 * 한쪽을 고치면 다른 쪽도 함께 고쳐 주세요.
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Seoul'); // 저장 시각·'오늘' 집계를 한국 시간으로

// 상세 결과 메일 발송 여부. 지금은 준비 중이라 false → 메일 없이 상세 결과 페이지로 바로 안내합니다.
// 메일 발송을 시작할 때 true로 바꾸면 됩니다. (db_config.php의 Brevo 설정 필요)
const DX_SEND_EMAIL = false;

if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://lifetimes.kr');
}

/** DB 연결. db_config.php가 먼저 require되어 있어야 합니다. (DB_DSN이 있으면 그것을 우선 사용: 로컬 테스트용) */
function dxPdo(): PDO
{
    $dsn = defined('DB_DSN') ? DB_DSN : 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    return new PDO(
        $dsn,
        defined('DB_USER') ? DB_USER : null,
        defined('DB_PASS') ? DB_PASS : null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/**
 * diagnoses 테이블이 없으면 만듭니다. (phpMyAdmin에서 따로 만들지 않아도 첫 저장 때 자동 생성)
 * schema.sql의 정의와 같습니다. MySQL에서만 실행합니다. (로컬 SQLite 테스트는 직접 생성)
 */
function dxEnsureTable(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `diagnoses` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `token` CHAR(32) NOT NULL,
          `email` VARCHAR(150) NOT NULL,
          `age_group` VARCHAR(10) NOT NULL,
          `workplaces` VARCHAR(60) NOT NULL,
          `main_workplace` VARCHAR(10) NOT NULL,
          `career_years` VARCHAR(10) NOT NULL,
          `answers` CHAR(6) NOT NULL,
          `type_code` VARCHAR(20) NOT NULL,
          `retire_timing` VARCHAR(10) NOT NULL,
          `interest` VARCHAR(10) NOT NULL,
          `marketing_ok` TINYINT(1) NOT NULL DEFAULT 0,
          `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_token` (`token`),
          KEY `idx_email_created` (`email`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

const DX_AGES = ['40s' => '40대', '50s' => '50대', '60s' => '60대', '70s' => '70대 이상'];

const DX_WORKPLACES = [
    'corp'   => '기업',
    'public' => '공공기관',
    'school' => '학교·교육기관',
    'assoc'  => '협단체·비영리',
    'free'   => '프리랜서·자영업',
];

const DX_YEARS = [
    'lt10'   => '10년 미만',
    '10to20' => '10~20년',
    '20to30' => '20~30년',
    'gt30'   => '30년 이상',
];

const DX_RETIRE = [
    'done'    => '이미 퇴직(퇴사)했다',
    'within1' => '1년 안에',
    'within5' => '1~5년 안에',
    'later'   => '아직 정해진 바 없다',
];

const DX_INTERESTS = [
    'job'     => '재취업·전직',
    'startup' => '창업·1인 사업',
    'share'   => '강연·책 쓰기·지식 나눔',
    'leisure' => '배움·봉사·여가',
];

/**
 * 성향 6문항(각 4지선다, 답은 '1'~'4' 여섯 글자)
 * 1~3번은 외향(1) ↔ 내향(4), 4~6번은 도전(1) ↔ 안정(4)
 * 보기 점수 +3, +1, -1, -3 → 세 문항 합은 항상 홀수라 동점이 없습니다.
 */
function dxAxisScore(string $three): int
{
    $map = ['1' => 3, '2' => 1, '3' => -1, '4' => -3];
    $sum = 0;
    foreach (str_split($three) as $c) {
        $sum += $map[$c] ?? 0;
    }
    return $sum;
}

function dxType(string $answers): string
{
    $extro = dxAxisScore(substr($answers, 0, 3)) > 0;
    $challenge = dxAxisScore(substr($answers, 3, 3)) > 0;
    if ($extro && $challenge)  return 'pioneer';
    if ($extro)                return 'connector';
    if ($challenge)            return 'strategist';
    return 'craftsman';
}

function dxTypes(): array
{
    return [
        'pioneer' => [
            'name'     => '무대 위의 개척자',
            'tagline'  => '사람을 만나며 길을 내는 사람',
            'keywords' => ['추진력', '네트워크', '표현력'],
            'summary'  => '사람들 앞에 서고 새 일을 벌일 때 힘이 나는 유형입니다. 인생 2막에서도 가만히 있기보다 무언가를 시작하고, 그 과정을 사람들과 나눌 때 가장 빛납니다.',
            'strengths' => [
                '처음 보는 사람과도 금방 관계를 맺습니다.',
                '결정이 빠르고 실행력이 좋습니다.',
                '지나온 경험을 이야기로 풀어내는 힘이 있습니다.',
            ],
            'cautions' => [
                '벌여 놓은 일이 많아 마무리가 흐려질 수 있습니다. 하나를 끝내고 다음 일을 여세요.',
                '퇴직금처럼 큰돈이 걸린 결정은 바로 뛰어들기보다 작게 시험해 본 뒤 키우세요.',
            ],
            'paths' => ['강연·교육 강사', '소규모 창업', '커뮤니티·모임 운영자'],
        ],
        'connector' => [
            'name'     => '든든한 연결자',
            'tagline'  => '사람과 사람을 잇는 사람',
            'keywords' => ['신뢰', '조율', '배려'],
            'summary'  => '사람들 사이에서 다리 역할을 할 때 보람을 느끼는 유형입니다. 큰 모험보다 믿을 수 있는 관계 속에서 꾸준히 기여하는 2막이 잘 맞습니다.',
            'strengths' => [
                '갈등을 부드럽게 조율합니다.',
                '한번 맺은 관계를 오래 지킵니다.',
                '조직의 규칙과 흐름을 잘 이해합니다.',
            ],
            'cautions' => [
                '남의 부탁을 먼저 챙기다 내 계획이 뒤로 밀릴 수 있습니다.',
                '익숙한 인맥 안에만 머물면 기회가 좁아집니다. 새로운 모임 한 곳에 발을 들여 보세요.',
            ],
            'paths' => ['협회·단체 사무국', '코디네이터·매니저', '멘토·상담 활동'],
        ],
        'strategist' => [
            'name'     => '조용한 전략가',
            'tagline'  => '깊이 생각하고 새 판을 짜는 사람',
            'keywords' => ['분석력', '독립성', '기획력'],
            'summary'  => '혼자 깊이 생각하는 시간에서 좋은 아이디어가 나오는 유형입니다. 지금까지의 경험을 정리해 나만의 콘텐츠나 사업으로 만드는 2막에 강합니다.',
            'strengths' => [
                '복잡한 문제를 구조적으로 정리합니다.',
                '남들이 놓친 기회를 발견합니다.',
                '혼자서도 꾸준히 몰입합니다.',
            ],
            'cautions' => [
                '생각이 완벽해질 때까지 기다리다 시작이 늦어질 수 있습니다.',
                '혼자 준비하면 사람들의 반응을 놓칩니다. 초안 단계에서 주변에 보여 주세요.',
            ],
            'paths' => ['1인 지식 창업', '책 쓰기·콘텐츠 제작', '컨설팅·자문'],
        ],
        'craftsman' => [
            'name'     => '깊이 있는 장인',
            'tagline'  => '한 우물을 깊게 파는 사람',
            'keywords' => ['전문성', '성실함', '꼼꼼함'],
            'summary'  => '익숙한 분야에서 실력을 갈고닦을 때 가장 편안한 유형입니다. 오랜 경력으로 쌓은 전문성을 살려 안정적으로 이어 가는 2막이 잘 맞습니다.',
            'strengths' => [
                '맡은 일을 끝까지 책임집니다.',
                '오랜 경험에서 나온 노하우가 있습니다.',
                '꼼꼼해서 실수가 적습니다.',
            ],
            'cautions' => [
                '변화를 미루다 준비할 시간을 놓칠 수 있습니다.',
                '내 실력을 스스로 낮게 보기 쉽습니다. 경력을 글로 정리해 보면 생각보다 자산이 많다는 걸 알게 됩니다.',
            ],
            'paths' => ['전문 분야 재취업·계약직', '기술·노하우 전수 강사', '시니어 전문 인력 활동'],
        ],
    ];
}

/**
 * 응답 한 건으로 상세 결과를 만듭니다.
 * $r 키: age, workplaces(배열), main, years, answers, retire, interest
 */
function dxBuildDetail(array $r): array
{
    $typeKey = dxType($r['answers']);
    $type = dxTypes()[$typeKey];

    $careerLine = DX_WORKPLACES[$r['main']] . ' ' . DX_YEARS[$r['years']] . ' 경력의';

    $workplaceComment = [
        'corp'   => '기업에서 익힌 성과 관리와 실무 감각은 어디서나 통하는 자산입니다. 경력을 숫자와 결과 중심으로 정리해 두세요.',
        'public' => '공공기관에서 쌓은 행정·규정 이해와 신뢰도는 협단체, 교육, 자문 분야에서 특히 환영받습니다.',
        'school' => '학교·교육기관에서 가르치고 이끈 경험은 평생교육, 강의, 멘토링, 교육 콘텐츠 분야로 그대로 이어집니다.',
        'assoc'  => '협단체·비영리 경험은 사람을 모으고 사업을 꾸려 본 경험입니다. 사회적 경제나 지역 활동으로 넓히기 좋습니다.',
        'free'   => '프리랜서·자영업 경험은 스스로 일을 만들어 본 경험입니다. 이미 2막의 절반은 준비된 셈입니다.',
    ][$r['main']];

    $careerComments = [$workplaceComment];
    if (count($r['workplaces']) >= 2) {
        $careerComments[] = '서로 다른 영역(' . implode(', ', array_map(fn($w) => DX_WORKPLACES[$w], $r['workplaces'])) . ')을 두루 경험한 것은 큰 강점입니다. 서로 다른 조직을 잇는 역할을 맡기 좋습니다.';
    }
    if ($r['years'] === '20to30' || $r['years'] === 'gt30') {
        $careerComments[] = DX_YEARS[$r['years']] . '의 경력 자체가 강의와 책의 좋은 재료입니다. 기억에 남는 일 열 가지를 적어 보는 것부터 시작해 보세요.';
    }

    $firstSteps = [
        'job' => [
            '지난 경력을 "무엇을 해서 어떤 결과를 냈는지" 중심으로 한 장에 정리합니다.',
            '고용24, 지역 50플러스센터 같은 중장년 일자리 창구를 한 곳 정해 정기적으로 살펴봅니다.',
            '다니는 회사에 재취업지원서비스(전직 지원 교육)가 있다면 꼭 활용합니다.',
        ],
        'startup' => [
            '하고 싶은 일을 한 문장으로 적고, 누가 돈을 낼지 세 사람에게 물어봅니다.',
            '가게나 사무실을 얻기 전에 온라인·주말 장터 등으로 작게 시험 판매해 봅니다.',
            '고정비(임대료, 인건비)를 최소로 잡은 계획부터 세웁니다.',
        ],
        'share' => [
            '내가 남들보다 잘 아는 주제를 세 가지 적어 봅니다.',
            '그중 하나로 짧은 글을 매주 한 편씩 블로그나 SNS에 올려 봅니다.',
            '글이 열 편쯤 모이면 한 시간짜리 강의안으로 묶어 봅니다.',
        ],
        'leisure' => [
            '배움 하나, 봉사 하나, 운동 하나를 정해 일주일 일정표에 넣습니다.',
            '동네 복지관이나 평생학습관 프로그램을 한 번 둘러봅니다.',
            '지나온 삶을 기록해 두면 가족에게 남길 이야기가 됩니다.',
        ],
    ][$r['interest']];

    $timing = [
        'done'    => '지금은 오래 탐색하기보다 작게 실행해 볼 시기입니다. 이번 달 안에 위의 첫걸음 중 하나를 시작해 보세요.',
        'within1' => '남은 시간이 길지 않습니다. 퇴직 전에 경력 정리부터 마치고, 회사의 재취업지원서비스(전직 지원 교육)를 꼭 챙기세요.',
        'within5' => '지금이 준비의 골든타임입니다. 재직 중일 때 인맥을 넓히고 필요한 자격과 교육을 챙기세요.',
        'later'   => '시간이 있을 때 준비하는 사람이 가장 유리합니다. 1년에 한 번, 생일이나 연말에 2막 계획을 점검하는 습관을 들이세요.',
    ][$r['retire']];

    // 추천: 관심 분야와 연령대 기준
    $recs = [];
    if ($r['interest'] === 'job') {
        $recs[] = ['title' => '50+ 커리어 설계 과정', 'desc' => '경력을 다시 정리하고 나에게 맞는 다음 단계를 실행 계획으로 만드는 프로그램', 'url' => SITE_URL . '/#programs'];
        $recs[] = ['title' => '『합격 자소서 특강』', 'desc' => '재취업 서류를 쓸 때 바로 써먹는 자기소개서 작성법', 'url' => 'https://product.kyobobook.co.kr/detail/S000000695604'];
    } elseif ($r['interest'] === 'startup') {
        $recs[] = ['title' => '50+ 커리어 설계 과정', 'desc' => '재취업·창업·리스킬링 중 나에게 맞는 길을 함께 점검하는 프로그램', 'url' => SITE_URL . '/#programs'];
        $recs[] = ['title' => '새로운 출발·두 번째 도약 강연', 'desc' => '두려움 없이 새로 시작하는 방법을 다루는 강연', 'url' => SITE_URL . '/#topics'];
    } elseif ($r['interest'] === 'share') {
        $recs[] = ['title' => '『글쓰기가 막막한 당신에게』', 'desc' => '경험을 글로 풀어내는 첫걸음을 돕는 책', 'url' => 'https://product.kyobobook.co.kr/detail/S000201020736'];
        $recs[] = ['title' => '책쓰기 아카데미·글쓰기 특강', 'desc' => '내 이야기를 한 권의 책으로 만드는 과정', 'url' => SITE_URL . '/#contact'];
    } else {
        if ($r['age'] === '60s' || $r['age'] === '70s') {
            $recs[] = ['title' => '65+ 인생노트 프로그램', 'desc' => '지나온 삶을 돌아보고 가족에게 남길 이야기를 기록하는 프로그램', 'url' => SITE_URL . '/#programs'];
        }
        $recs[] = ['title' => '『2026 웰니스 치유 트렌드』', 'desc' => '몸과 마음을 돌보는 최신 웰니스 흐름', 'url' => 'https://product.kyobobook.co.kr/detail/S000217467717'];
    }
    if ($r['age'] === '70s' && $r['interest'] !== 'leisure') {
        $recs[] = ['title' => '65+ 인생노트 프로그램', 'desc' => '지나온 삶을 돌아보고 가족에게 남길 이야기를 기록하는 프로그램', 'url' => SITE_URL . '/#programs'];
    }

    return [
        'typeKey'        => $typeKey,
        'type'           => $type,
        'headline'       => $careerLine . ' ' . $type['name'],
        'careerComments' => $careerComments,
        'interestLabel'  => DX_INTERESTS[$r['interest']],
        'firstSteps'     => $firstSteps,
        'timing'         => $timing,
        'recs'           => $recs,
    ];
}

/** 이메일 본문(텍스트판) */
function dxDetailText(array $d, ?string $resultUrl): string
{
    $t = $d['type'];
    $lines = [
        '안녕하세요, 인생설계가이드 추영준입니다.',
        '「나의 인생 2막 캐릭터 진단」에 참여해 주셔서 고맙습니다.',
        '',
        '■ 나의 유형: ' . $d['headline'],
        '   "' . $t['tagline'] . '"',
        '   키워드: ' . implode(' · ', $t['keywords']),
        '',
        $t['summary'],
        '',
        '■ 나의 강점',
    ];
    foreach ($t['strengths'] as $s) $lines[] = ' - ' . $s;
    $lines[] = '';
    $lines[] = '■ 이것만은 조심하세요';
    foreach ($t['cautions'] as $s) $lines[] = ' - ' . $s;
    $lines[] = '';
    $lines[] = '■ 잘 맞는 2막 경로';
    $lines[] = ' ' . implode(' / ', $t['paths']);
    $lines[] = '';
    $lines[] = '■ 나의 경력이 말해 주는 것';
    foreach ($d['careerComments'] as $s) $lines[] = ' - ' . $s;
    $lines[] = '';
    $lines[] = '■ 관심 분야(' . $d['interestLabel'] . ')를 위한 첫걸음';
    foreach ($d['firstSteps'] as $i => $s) $lines[] = ' ' . ($i + 1) . '. ' . $s;
    $lines[] = '';
    $lines[] = '■ 지금 나의 시기';
    $lines[] = ' ' . $d['timing'];
    $lines[] = '';
    $lines[] = '■ 함께 보면 좋은 책·프로그램';
    foreach ($d['recs'] as $rec) $lines[] = ' - ' . $rec['title'] . ': ' . $rec['desc'] . ' (' . $rec['url'] . ')';
    $lines[] = '';
    if ($resultUrl !== null) {
        $lines[] = '아래 링크에서 언제든 결과를 다시 볼 수 있습니다. (본인만 아는 링크이니 보관해 주세요)';
        $lines[] = $resultUrl;
        $lines[] = '';
    }
    $lines[] = '강연·프로그램 문의: ' . SITE_URL . '/#contact';
    $lines[] = '';
    $lines[] = '추영준 드림';
    $lines[] = '';
    $lines[] = '본 메일은 진단 결과를 요청하신 분께만 발송됩니다. 정보 삭제를 원하시면 이 메일에 회신해 주세요.';
    return implode("\n", $lines);
}

/** 상세 결과 본문 HTML (result.php 페이지와 이메일 HTML판에서 공용) */
function dxDetailHtml(array $d): string
{
    $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $t = $d['type'];
    $li = function (array $items) use ($e): string {
        return implode('', array_map(fn($s) => '<li>' . $e($s) . '</li>', $items));
    };
    $recs = implode('', array_map(
        fn($r) => '<li><a href="' . $e($r['url']) . '">' . $e($r['title']) . '</a> — ' . $e($r['desc']) . '</li>',
        $d['recs']
    ));

    return
        '<h2>나의 강점</h2><ul>' . $li($t['strengths']) . '</ul>' .
        '<h2>이것만은 조심하세요</h2><ul>' . $li($t['cautions']) . '</ul>' .
        '<h2>잘 맞는 2막 경로</h2><p class="paths">' . implode('', array_map(fn($p) => '<span>' . $e($p) . '</span>', $t['paths'])) . '</p>' .
        '<h2>나의 경력이 말해 주는 것</h2><ul>' . $li($d['careerComments']) . '</ul>' .
        '<h2>관심 분야(' . $e($d['interestLabel']) . ')를 위한 첫걸음</h2><ol>' . $li($d['firstSteps']) . '</ol>' .
        '<h2>지금 나의 시기</h2><p>' . $e($d['timing']) . '</p>' .
        '<h2>함께 보면 좋은 책·프로그램</h2><ul>' . $recs . '</ul>';
}

/** 이메일 HTML판: 메일 프로그램은 <style>을 무시하는 경우가 많아 인라인 스타일로 넣습니다. */
function dxEmailHtml(array $d, ?string $resultUrl): string
{
    $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $t = $d['type'];
    $body = strtr(dxDetailHtml($d), [
        '<h2>'               => '<h2 style="font-size:17px;color:#1c2b45;margin:28px 0 10px;padding-top:18px;border-top:1px solid #ece6da;">',
        '<ul>'               => '<ul style="margin:0;padding-left:20px;">',
        '<ol>'               => '<ol style="margin:0;padding-left:20px;">',
        '<li>'               => '<li style="margin:0 0 6px;">',
        '<p>'                => '<p style="margin:0;">',
        '<p class="paths">'  => '<p style="margin:0;">',
        '<span>'             => '<span style="display:inline-block;margin:0 6px 6px 0;padding:5px 12px;border-radius:999px;background:#f1ece2;color:#1c2b45;font-weight:700;font-size:14px;">',
        '<a href='           => '<a style="color:#1c2b45;font-weight:700;" href=',
    ]);

    $link = $resultUrl === null ? '' :
        '<p style="margin:28px 0 0;text-align:center;"><a href="' . $e($resultUrl) . '" style="display:inline-block;padding:13px 26px;border-radius:999px;background:#1c2b45;color:#ffffff;font-weight:700;text-decoration:none;">결과 페이지에서 다시 보기</a></p>' .
        '<p style="margin:10px 0 0;text-align:center;font-size:13px;color:#6b6b6b;">본인만 아는 링크입니다. 이 메일을 보관해 두시면 언제든 다시 볼 수 있습니다.</p>';

    return '<!DOCTYPE html><html lang="ko"><body style="margin:0;padding:0;background:#faf7f2;">' .
        '<div style="max-width:600px;margin:0 auto;padding:28px 18px;font-family:-apple-system,\'Malgun Gothic\',sans-serif;color:#2b2b2b;font-size:15px;line-height:1.7;word-break:keep-all;">' .
        '<p style="margin:0 0 6px;font-weight:800;color:#1c2b45;">인생설계<span style="color:#b8934a;">가이드</span></p>' .
        '<div style="background:#1c2b45;border-radius:14px;padding:26px 22px;margin:14px 0 6px;color:#ffffff;">' .
        '<p style="margin:0 0 6px;font-size:13px;color:#d8b978;font-weight:700;">나의 인생 2막 캐릭터</p>' .
        '<p style="margin:0 0 8px;font-size:22px;font-weight:800;line-height:1.4;">' . $e($d['headline']) . '</p>' .
        '<p style="margin:0 0 12px;color:#d8b978;">"' . $e($t['tagline']) . '"</p>' .
        '<p style="margin:0;font-size:14px;opacity:.9;">' . $e($t['summary']) . '</p>' .
        '</div>' .
        $body . $link .
        '<p style="margin:32px 0 0;font-size:13px;color:#6b6b6b;">강연·프로그램 문의: <a href="' . SITE_URL . '/#contact" style="color:#1c2b45;">' . $e(preg_replace('#^https?://#', '', SITE_URL)) . '</a><br>' .
        '본 메일은 진단 결과를 요청하신 분께만 발송됩니다. 정보 삭제를 원하시면 이 메일에 회신해 주세요.</p>' .
        '</div></body></html>';
}
