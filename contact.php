<?php
/**
 * 추영준(인생설계가이드) 홍보 웹사이트 - 문의 폼 처리 스크립트
 * 호스팅케이알(hosting.kr) cPanel PHP + MySQL 환경에서 동작합니다.
 *
 * 처리 순서: 입력값 검증 -> DB 저장(inquiries 테이블) -> 이메일 발송
 * db_config.php가 없거나 DB 접속에 실패해도 이메일 발송은 계속 진행됩니다.
 */

declare(strict_types=1);

// ---- 설정 ----------------------------------------------------------
const RECIPIENT_EMAIL = 'chu9527@nate.com';
const SITE_NAME       = '인생설계가이드';
const MAX_FIELD_LEN   = 3000;

// ---- 응답 헬퍼 -------------------------------------------------------
function respond(bool $ok, string $message, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 요청 방식 확인 ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, '잘못된 접근입니다.', 405);
}

// ---- 스팸 방지: 허니팟 필드 -------------------------------------------
// 사람 눈에는 보이지 않는 필드이므로, 값이 채워져 있으면 봇으로 간주하고
// 스팸을 보낸 쪽에게는 성공한 것처럼 보이도록 조용히 종료합니다.
if (!empty($_POST['website'])) {
    respond(true, '문의가 정상적으로 접수되었습니다. 감사합니다.');
}

// ---- 입력값 수집 및 정리 ----------------------------------------------
function cleanInput(string $value): string
{
    $value = trim($value);
    $value = str_replace(["\r", "\n"], ' ', $value); // 헤더 인젝션 방지
    return mb_substr($value, 0, MAX_FIELD_LEN);
}

$name         = isset($_POST['name']) ? cleanInput((string) $_POST['name']) : '';
$organization = isset($_POST['organization']) ? cleanInput((string) $_POST['organization']) : '';
$phone        = isset($_POST['phone']) ? cleanInput((string) $_POST['phone']) : '';
$email        = isset($_POST['email']) ? cleanInput((string) $_POST['email']) : '';
$inquiryType  = isset($_POST['inquiry_type']) ? cleanInput((string) $_POST['inquiry_type']) : '';
$message      = isset($_POST['message']) ? trim((string) $_POST['message']) : '';
$message      = mb_substr($message, 0, MAX_FIELD_LEN);

// ---- 필수값 검증 -------------------------------------------------------
$allowedTypes = ['강연문의', '저서문의', '기타'];

if ($name === '' || $phone === '' || $email === '' || $message === '') {
    respond(false, '이름, 연락처, 이메일, 문의 내용을 모두 입력해 주세요.', 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, '이메일 주소 형식이 올바르지 않습니다.', 422);
}

if (!in_array($inquiryType, $allowedTypes, true)) {
    respond(false, '문의 유형을 올바르게 선택해 주세요.', 422);
}

if (mb_strlen($name) > 100 || mb_strlen($phone) > 100 || mb_strlen($organization) > 100) {
    respond(false, '입력값이 너무 깁니다. 다시 확인해 주세요.', 422);
}

// ---- DB 저장 (선택적: db_config.php가 있을 때만 시도) --------------------
$dbConfigPath = __DIR__ . '/db_config.php';

if (file_exists($dbConfigPath)) {
    require $dbConfigPath;

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $stmt = $pdo->prepare(
            'INSERT INTO inquiries (name, organization, phone, email, inquiry_type, message, created_at)
             VALUES (:name, :organization, :phone, :email, :inquiry_type, :message, NOW())'
        );
        $stmt->execute([
            ':name'         => $name,
            ':organization' => $organization !== '' ? $organization : null,
            ':phone'        => $phone,
            ':email'        => $email,
            ':inquiry_type' => $inquiryType,
            ':message'      => $message,
        ]);
    } catch (Throwable $e) {
        // DB 저장에 실패해도 이메일 발송은 계속 진행합니다.
        error_log('[contact.php] DB 저장 실패: ' . $e->getMessage());
    }
}

// ---- 메일 발송 ---------------------------------------------------------
$subject = '[' . SITE_NAME . '] 새 문의: ' . $inquiryType . ' - ' . $name;

$bodyLines = [
    '홈페이지를 통해 새로운 문의가 접수되었습니다.',
    '',
    '문의 유형 : ' . $inquiryType,
    '이름      : ' . $name,
    '소속      : ' . ($organization !== '' ? $organization : '(미입력)'),
    '연락처    : ' . $phone,
    '이메일    : ' . $email,
    '접수 시각 : ' . date('Y-m-d H:i:s'),
    '',
    '문의 내용',
    '----------------------------------------',
    $message,
    '----------------------------------------',
];
$body = implode("\n", $bodyLines);

$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

$headers = [
    'From: ' . SITE_NAME . ' 웹사이트 <no-reply@lifetimes.co.kr>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];

$sent = @mail(
    RECIPIENT_EMAIL,
    $encodedSubject,
    $body,
    implode("\r\n", $headers)
);

if ($sent) {
    respond(true, '문의가 정상적으로 접수되었습니다. 빠른 시일 내에 답변드리겠습니다.');
}

respond(false, '메일 발송 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.', 500);
