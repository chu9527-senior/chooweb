<?php
/**
 * 「나의 인생 2막 캐릭터 진단」 — 상세 결과 요청 처리
 *
 * 처리 순서: 입력값 검증 -> 유형 판정 -> DB 저장(diagnoses 테이블, 결과 링크용 토큰 발급)
 *            -> 상세 결과 이메일 발송(Brevo)
 * 응답: JSON { ok, message, resultUrl }
 */

declare(strict_types=1);

const SITE_NAME          = '인생설계가이드';
const ADMIN_EMAIL        = 'chu9527@nate.com';
// 같은 이메일로 하루에 요청할 수 있는 횟수 (메일 발송 시에는 남의 메일함 반복 발송 방지로 더 엄격하게)
// DX_SEND_EMAIL(메일 발송 켜기/끄기)은 diagnosis_data.php에 있습니다.

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

// 허니팟: 봇이면 성공한 것처럼 조용히 종료
if (!empty($_POST['website'])) {
    respond(true, '접수되었습니다.');
}

$dbConfigPath = __DIR__ . '/db_config.php';
if (file_exists($dbConfigPath)) {
    require $dbConfigPath;
}
require __DIR__ . '/mail_lib.php';
require __DIR__ . '/diagnosis_data.php';

// ---- 입력값 수집 ----------------------------------------------------------
$str = fn(string $k): string => isset($_POST[$k]) ? trim((string) $_POST[$k]) : '';

$email      = $str('email');
$consent    = $str('consent') === '1';
$age        = $str('age');
$workplaces = array_values(array_unique(array_filter(explode(',', $str('workplaces')))));
$main       = $str('main');
$years      = $str('years');
$answers    = $str('answers');
$retire     = $str('retire');
$interest   = $str('interest');

// ---- 검증 ----------------------------------------------------------------
if (!$consent) {
    respond(false, '개인정보 수집·이용에 동의해 주셔야 결과를 보내드릴 수 있습니다.', 422);
}
if ($email === '' || mb_strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, '이메일 주소를 정확히 입력해 주세요.', 422);
}

$validWorkplaces = count($workplaces) >= 1
    && count(array_diff($workplaces, array_keys(DX_WORKPLACES))) === 0;
if ($main === '' && count($workplaces) === 1) {
    $main = $workplaces[0];
}

if (
    !isset(DX_AGES[$age]) || !$validWorkplaces || !in_array($main, $workplaces, true)
    || !isset(DX_YEARS[$years]) || !preg_match('/^[1-4]{6}$/', $answers)
    || !isset(DX_RETIRE[$retire]) || !isset(DX_INTERESTS[$interest])
) {
    respond(false, '응답 내용에 문제가 있습니다. 진단을 처음부터 다시 해 주세요.', 422);
}

$response = [
    'age' => $age, 'workplaces' => $workplaces, 'main' => $main, 'years' => $years,
    'answers' => $answers, 'retire' => $retire, 'interest' => $interest,
];
$detail = dxBuildDetail($response);

// ---- DB 저장 (db_config.php가 있을 때만) -----------------------------------
$resultUrl = null;
$pdo = null;
$rowId = null;

if (defined('DB_DSN') || defined('DB_HOST')) {
    try {
        $pdo = dxPdo();
        dxEnsureTable($pdo);

        $since = date('Y-m-d H:i:s', time() - 86400);
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM diagnoses WHERE email = :email AND created_at >= :since');
        $cnt->execute([':email' => $email, ':since' => $since]);
        if ((int) $cnt->fetchColumn() >= (DX_SEND_EMAIL ? 3 : 10)) {
            respond(false, '오늘은 이 이메일로 이미 여러 번 진단하셨습니다. 내일 다시 시도해 주세요.', 429);
        }

        $token = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare(
            'INSERT INTO diagnoses
               (token, email, age_group, workplaces, main_workplace, career_years, answers, type_code,
                retire_timing, interest, marketing_ok, email_sent, created_at)
             VALUES
               (:token, :email, :age, :workplaces, :main, :years, :answers, :type,
                :retire, :interest, :marketing, 0, :created_at)'
        );
        $stmt->execute([
            ':token'      => $token,
            ':email'      => $email,
            ':age'        => $age,
            ':workplaces' => implode(',', $workplaces),
            ':main'       => $main,
            ':years'      => $years,
            ':answers'    => $answers,
            ':type'       => $detail['typeKey'],
            ':retire'     => $retire,
            ':interest'   => $interest,
            ':marketing'  => 0, // 소식 수신(메일레터)은 사용하지 않음
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
        $rowId = (int) $pdo->lastInsertId();
        $resultUrl = SITE_URL . '/result.php?t=' . $token;
    } catch (Throwable $e) {
        error_log('[diagnosis.php] DB 저장 실패: ' . $e->getMessage());
        $pdo = null;
    }
}

// ---- 메일 발송 준비 중: 상세 결과 페이지로 바로 안내 ----------------------------
if (!DX_SEND_EMAIL) {
    if ($resultUrl !== null) {
        respond(true, '상세 결과 페이지로 이동합니다.', 200, $resultUrl);
    }
    respond(false, '결과 저장 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.', 500);
}

// ---- 상세 결과 메일 발송 ----------------------------------------------------
$subject = '[' . SITE_NAME . '] 나의 인생 2막 캐릭터 진단 결과: ' . $detail['type']['name'];
$text = dxDetailText($detail, $resultUrl);
$html = dxEmailHtml($detail, $resultUrl);

$sent = sendViaBrevo($email, '', $subject, $text, ADMIN_EMAIL, $html);

if ($sent && $pdo !== null && $rowId !== null) {
    try {
        $pdo->prepare('UPDATE diagnoses SET email_sent = 1 WHERE id = :id')->execute([':id' => $rowId]);
    } catch (Throwable $e) {
        // 발송 표시 실패는 무시
    }
}

if ($sent) {
    respond(true, '상세 결과를 ' . $email . '(으)로 보내드렸습니다. 메일이 보이지 않으면 스팸함도 확인해 주세요.', 200, $resultUrl);
}
if ($resultUrl !== null) {
    respond(true, '메일 발송이 지연되고 있습니다. 아래 링크에서 상세 결과를 바로 확인하실 수 있습니다.', 200, $resultUrl);
}
respond(false, '결과 발송 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.', 500);
