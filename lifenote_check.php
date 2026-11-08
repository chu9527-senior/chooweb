<?php
/**
 * 「만일 대비 준비도 점검」 — 상세 결과 요청 처리
 * 입력 검증 -> 점수 계산 -> DB 저장(lifenote_checks, 결과 페이지용 토큰) -> 결과 페이지 주소 반환
 * 응답: JSON { ok, message, resultUrl }
 */

declare(strict_types=1);

function respond(bool $ok, string $message, int $httpCode = 200, ?string $resultUrl = null): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message, 'resultUrl' => $resultUrl], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, '잘못된 접근입니다.', 405);
}
if (!empty($_POST['website'])) { // 허니팟
    respond(true, '접수되었습니다.');
}

$dbConfigPath = __DIR__ . '/db_config.php';
if (file_exists($dbConfigPath)) {
    require $dbConfigPath;
}
require __DIR__ . '/lifenote_data.php';

$str = fn(string $k): string => isset($_POST[$k]) ? trim((string) $_POST[$k]) : '';
$email     = $str('email');
$consent   = $str('consent') === '1';
$age       = $str('age');
$household = $str('household');
$answers   = $str('answers');
$focus     = $str('focus');

if (!$consent) {
    respond(false, '개인정보 수집·이용에 동의해 주셔야 상세 결과를 보여 드릴 수 있습니다.', 422);
}
if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, '이메일 주소를 정확히 입력해 주세요.', 422);
}
if (!isset(LN_AGES[$age]) || !isset(LN_HOUSEHOLDS[$household]) || !preg_match('/^[1-4]{8}$/', $answers) || !isset(LN_FOCUS[$focus])) {
    respond(false, '응답 내용에 문제가 있습니다. 점검을 처음부터 다시 해 주세요.', 422);
}

if (!defined('DB_DSN') && !defined('DB_HOST')) {
    respond(false, '결과 저장 설정이 되어 있지 않습니다. 잠시 후 다시 시도해 주세요.', 500);
}

$sc = lnScore($answers, $focus);

try {
    $pdo = lnPdo();
    lnEnsureTable($pdo);

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM lifenote_checks WHERE email = :email AND created_at >= :since');
    $cnt->execute([':email' => $email, ':since' => date('Y-m-d H:i:s', time() - 86400)]);
    if ((int) $cnt->fetchColumn() >= 10) {
        respond(false, '오늘은 이 이메일로 이미 여러 번 점검하셨습니다. 내일 다시 시도해 주세요.', 429);
    }

    $token = bin2hex(random_bytes(16));
    $pdo->prepare(
        'INSERT INTO lifenote_checks (token, email, age_group, household, answers, focus, score, level_code, weakest, created_at)
         VALUES (:token, :email, :age, :household, :answers, :focus, :score, :level, :weakest, :created_at)'
    )->execute([
        ':token'      => $token,
        ':email'      => $email,
        ':age'        => $age,
        ':household'  => $household,
        ':answers'    => $answers,
        ':focus'      => $focus,
        ':score'      => $sc['score'],
        ':level'      => $sc['level'],
        ':weakest'    => $sc['weakest'],
        ':created_at' => date('Y-m-d H:i:s'),
    ]);

    respond(true, '상세 결과 페이지로 이동합니다.', 200, SITE_URL . '/lifenote_result.php?t=' . $token);
} catch (Throwable $e) {
    error_log('[lifenote_check.php] 저장 실패: ' . $e->getMessage());
    respond(false, '결과 저장 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.', 500);
}
