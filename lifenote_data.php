<?php
/**
 * 「만일 대비 준비도 점검」(인생노트) — 문항 코드, 점수 계산, 상세 결과 내용
 * lifenote_check.php(저장), lifenote_result.php(결과 페이지), admin_lifenote.php(대시보드)가 함께 사용합니다.
 * 문항 코드·영역 이름·단계 이름은 index.html 진단 스크립트(LN_*)와 같아야 합니다.
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');

if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://lifetimes.kr');
}

const LN_AGES = ['50s' => '50대', '60s' => '60대', '70s' => '70대', '80s' => '80대 이상'];

const LN_HOUSEHOLDS = [
    'couple' => '배우자와 둘이 산다',
    'family' => '자녀 등 가족과 함께 산다',
    'alone'  => '혼자 산다',
    'other'  => '기타(시설 등)',
];

/** 4개 영역 — 문항 8개(영역당 2개)의 답이 이 순서로 저장됩니다. */
const LN_AREAS = [
    'care'    => '건강·돌봄',
    'medical' => '의료 결정',
    'assets'  => '재산·디지털 정리',
    'story'   => '회고·가족 메시지',
];

const LN_FOCUS = [
    'care'    => '건강·돌봄 대비',
    'medical' => '의료 결정(연명의료)',
    'assets'  => '재산·디지털 정리',
    'story'   => '삶의 회고와 가족 메시지',
];

/** DB 연결 (db_config.php 먼저 require). DB_DSN이 있으면 우선 사용(로컬 테스트용) */
function lnPdo(): PDO
{
    $dsn = defined('DB_DSN') ? DB_DSN : 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    return new PDO(
        $dsn,
        defined('DB_USER') ? DB_USER : null,
        defined('DB_PASS') ? DB_PASS : null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/** lifenote_checks 테이블 자동 생성 (MySQL에서만) */
function lnEnsureTable(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `lifenote_checks` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `token` CHAR(32) NOT NULL,
          `email` VARCHAR(150) NOT NULL,
          `age_group` VARCHAR(10) NOT NULL,
          `household` VARCHAR(10) NOT NULL,
          `answers` CHAR(8) NOT NULL,
          `focus` VARCHAR(10) NOT NULL,
          `score` TINYINT UNSIGNED NOT NULL,
          `level_code` TINYINT UNSIGNED NOT NULL,
          `weakest` VARCHAR(10) NOT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_token` (`token`),
          KEY `idx_email_created` (`email`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** 보기 ①~④ → 3, 2, 1, 0점 */
function lnPoint(string $c): int
{
    return ['1' => 3, '2' => 2, '3' => 1, '4' => 0][$c] ?? 0;
}

/**
 * 점수 계산: 영역별 0~100%, 총점 0~100점, 단계(1~4), 가장 먼저 챙길 영역
 * 같은 점수의 영역이 여럿이면 방문자가 고른 관심 영역을 우선합니다.
 */
function lnScore(string $answers, string $focus): array
{
    $areas = [];
    $total = 0;
    $i = 0;
    foreach (array_keys(LN_AREAS) as $key) {
        $s = lnPoint($answers[$i] ?? '4') + lnPoint($answers[$i + 1] ?? '4');
        $areas[$key] = (int) round($s / 6 * 100);
        $total += $s;
        $i += 2;
    }
    $score = (int) round($total / 24 * 100);
    $level = $score < 25 ? 1 : ($score < 50 ? 2 : ($score < 75 ? 3 : 4));

    $min = min($areas);
    $weakest = isset($areas[$focus]) && $areas[$focus] === $min
        ? $focus
        : (string) array_search($min, $areas, true);

    return ['areas' => $areas, 'score' => $score, 'level' => $level, 'weakest' => $weakest];
}

function lnLevels(): array
{
    return [
        1 => ['name' => '첫 장을 펼칠 때',     'desc' => '아직 준비를 시작하지 않으셨습니다. 괜찮습니다. 이 점검을 한 것만으로 인생노트의 첫 장을 펼친 셈입니다. 가장 쉬운 것 하나부터 시작해 보세요.'],
        2 => ['name' => '밑그림을 그리는 중',   'desc' => '필요성은 느끼고 계시지만 구체적인 준비는 아직입니다. 머릿속 생각을 글로 옮기는 순간부터 준비가 시작됩니다.'],
        3 => ['name' => '절반을 넘긴 준비',     'desc' => '중요한 부분은 이미 챙기고 계십니다. 비어 있는 영역 한두 곳만 채우면 훨씬 든든해집니다.'],
        4 => ['name' => '든든하게 준비된 사람', 'desc' => '만일에 대한 준비가 잘 되어 있습니다. 이제 정리한 내용을 가족과 나누고, 해마다 한 번씩 고쳐 쓰세요.'],
    ];
}

function lnAreaStatus(int $pct): string
{
    return $pct >= 67 ? '잘 준비됨' : ($pct >= 34 ? '조금 더' : '먼저 챙길 곳');
}

function lnAdvice(): array
{
    return [
        'care' => [
            '갑자기 아프거나 다칠 때를 대비해 가족과 "돌봄 계획"을 한 번 이야기해 보세요. 누가, 어디서, 어떻게 돌볼지 큰 그림만 정해 두어도 위기 때 덜 흔들립니다.',
            '2026년 3월부터 시행된 통합돌봄(의료·요양 통합지원)은 살던 곳에서 돌봄을 받도록 돕는 제도입니다. 사는 곳 주민센터에 어떤 서비스가 있는지 물어보세요.',
            '노인장기요양보험은 국민건강보험공단에 신청합니다. 지금 당장 필요하지 않더라도 신청 방법을 알아 두면 든든합니다.',
        ],
        'medical' => [
            '사전연명의료의향서는 19세 이상이면 누구나 지정 등록기관(국민건강보험공단 지사 등)에서 작성·등록할 수 있고, 언제든 바꾸거나 철회할 수 있습니다.',
            '위급할 때 내 뜻을 대신 전해 줄 사람을 정하고, 원하는 치료와 원하지 않는 치료를 미리 이야기해 두세요.',
            '복용 중인 약, 지병, 다니는 병원을 한 장에 정리해 지갑과 냉장고에 붙여 두면 응급 상황에서 큰 도움이 됩니다.',
        ],
        'assets' => [
            '통장·보험·연금·부동산·대출을 한 장의 목록으로 정리하고, 보관 장소를 믿을 만한 가족 한 사람에게 알려 두세요.',
            '휴대폰 잠금, 주요 인터넷 계정, 자동이체 내역을 정리해 두면 가족의 수고를 크게 덜 수 있습니다.',
            '유언과 상속에는 법이 정한 형식이 있습니다. 형식을 갖추지 않으면 효력이 없을 수 있으니 중요한 결정은 전문가와 확인하세요.',
        ],
        'story' => [
            '지나온 삶을 10년 단위로 나눠 기억에 남는 일을 하나씩 적어 보세요. 그것이 인생노트의 첫 장이 됩니다.',
            '가족 한 사람 한 사람에게 전하고 싶은 말을 짧은 편지로 남겨 보세요. 고마움과 부탁, 두 가지면 충분합니다.',
            '사진 정리부터 시작해도 좋습니다. 사진 뒤에 날짜와 이야기를 적어 두면 그대로 가족의 기록이 됩니다.',
        ],
    ];
}

/** 응답 한 건 → 상세 결과. $r 키: age, household, answers(8자리), focus */
function lnBuildDetail(array $r): array
{
    $sc = lnScore($r['answers'], $r['focus']);
    $level = lnLevels()[$sc['level']];

    $household = [
        'couple' => '부부가 함께 사신다면 서로의 뜻부터 나눠 보세요. 한 사람만 알고 있는 재산·계정 정보가 없도록 함께 정리하는 것이 좋습니다.',
        'family' => '가족과 함께 사신다면 식사 자리에서 가볍게 시작해 보세요. "만약에"를 이야기하는 것은 가족을 위한 배려입니다.',
        'alone'  => '혼자 사신다면 위급할 때 연락할 사람을 정해 두고, 지자체의 응급안전안심서비스나 통합돌봄 서비스를 꼭 알아 두세요.',
        'other'  => '지금 계신 곳의 담당자나 가까운 가족에게 내 뜻과 연락처를 알려 두는 것부터 시작해 보세요.',
    ][$r['household']];

    $age = [
        '50s' => '50대는 만일 대비를 시작하기 가장 좋은 때입니다. 부모님의 돌봄을 겪으며 내 준비도 함께 시작하는 분이 많습니다.',
        '60s' => '60+ 시기는 건강·재산·관계를 한 번 정리하기 좋은 시기입니다. 지금 정리해 두면 남은 시간을 더 가볍게 누릴 수 있습니다.',
        '70s' => '지금까지 살아온 이야기 자체가 가족에게 가장 큰 선물입니다. 기록과 정리는 지금 시작해도 늦지 않습니다.',
        '80s' => '지금까지 살아온 이야기 자체가 가족에게 가장 큰 선물입니다. 가족과 함께 천천히 정리해 보세요.',
    ][$r['age']];

    // 관심 영역 + 가장 약한 영역 순서로 조언 (중복 없이)
    $order = array_values(array_unique([$sc['weakest'], $r['focus']]));
    foreach (array_keys(LN_AREAS) as $k) {
        if (!in_array($k, $order, true)) $order[] = $k;
    }

    return [
        'score'     => $sc['score'],
        'levelCode' => $sc['level'],
        'level'     => $level,
        'areas'     => $sc['areas'],
        'weakest'   => $sc['weakest'],
        'focus'     => $r['focus'],
        'household' => $household,
        'ageNote'   => $age,
        'order'     => $order,
        'advice'    => lnAdvice(),
    ];
}

/** 상세 결과 본문 HTML (lifenote_result.php) */
function lnDetailHtml(array $d): string
{
    $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $bars = '';
    foreach (LN_AREAS as $k => $name) {
        $p = $d['areas'][$k];
        $bars .= '<div class="area-row' . ($k === $d['weakest'] ? ' weak' : '') . '">'
               . '<span class="area-name">' . $e($name) . '</span>'
               . '<span class="area-track"><span class="area-fill" style="width:' . max(4, $p) . '%"></span></span>'
               . '<span class="area-pct">' . $p . '% · ' . $e(lnAreaStatus($p)) . '</span></div>';
    }

    $areasHtml = '';
    foreach ($d['order'] as $i => $k) {
        $tag = $k === $d['weakest'] ? '<span class="tag">먼저 챙길 곳</span>' : ($k === $d['focus'] ? '<span class="tag soft">관심 영역</span>' : '');
        $areasHtml .= '<h3>' . $e(LN_AREAS[$k]) . ' <small>' . $d['areas'][$k] . '%</small> ' . $tag . '</h3><ol>'
                    . implode('', array_map(fn($s) => '<li>' . $e($s) . '</li>', $d['advice'][$k])) . '</ol>';
    }

    return
        '<h2>영역별 준비도</h2><div class="areas">' . $bars . '</div>' .
        '<h2>나에게 드리는 한마디</h2><ul><li>' . $e($d['ageNote']) . '</li><li>' . $e($d['household']) . '</li></ul>' .
        '<h2>영역별로 지금 할 수 있는 일</h2>' . $areasHtml .
        '<h2>함께 보면 좋은 과정</h2><ul>' .
        '<li><a href="' . SITE_URL . '/lifenote.html">60+ 인생노트 · 만일 대비 인생설계 과정</a> — 지나온 삶을 돌아보고, 건강·돌봄·의료 결정·재산을 정리해 가족에게 남길 인생노트를 완성하는 과정</li>' .
        '<li><a href="' . SITE_URL . '/#check-career">인생 2막 진단</a> — 퇴직·전직을 앞두고 있다면 일의 2막도 함께 점검해 보세요</li>' .
        '</ul>' .
        '<p class="disclaimer">이 점검은 준비 정도를 스스로 돌아보기 위한 참고 자료입니다. 법률·의료·재무에 관한 구체적인 결정은 해당 분야 전문가와 상담하시기 바랍니다.</p>';
}
