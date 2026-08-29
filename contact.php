<?php
/**
 * 추영준(인생설계가이드) 홍보 웹사이트 - 문의 폼 처리 스크립트
 * 호스팅케이알(hosting.kr) cPanel PHP + MySQL 환경에서 동작합니다.
 *
 * 처리 순서: 입력값 검증 -> DB 저장(inquiries 테이블) -> 이메일 발송(Brevo API)
 * 이 호스팅 요금제는 PHP mail() 함수가 비활성화되어 있어, Brevo(무료 이메일 API)를
 * 통해 이메일을 발송합니다. db_config.php에 BREVO_API_KEY, BREVO_SENDER_EMAIL이
 * 필요합니다. (설정이 없거나 실패해도 DB 저장은 계속 진행됩니다.)
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

// ---- Brevo API로 이메일 발송 -------------------------------------------
// db_config.php에 BREVO_API_KEY / BREVO_SENDER_EMAIL 이 정의되어 있어야 동작합니다.
function sendViaBrevo(string $toEmail, string $toName, string $subject, string $textBody, ?string $replyTo = null): bool
{
    if (!defined('BREVO_API_KEY') || !defined('BREVO_SENDER_EMAIL')) {
        error_log('[contact.php] BREVO_API_KEY 또는 BREVO_SENDER_EMAIL이 설정되지 않았습니다.');
        return false;
    }

    $payload = [
        'sender'      => ['name' => SITE_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $subject,
        'textContent' => $textBody,
    ];
    if ($replyTo !== null) {
        $payload['replyTo'] = ['email' => $replyTo];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = [
        'api-key: ' . BREVO_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('[contact.php] Brevo cURL 오류: ' . $curlError);
            return false;
        }
    } else {
        // cURL 확장이 없을 경우를 대비한 대체 수단
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", $headers) . "\r\n",
                'content' => $jsonPayload,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
        $statusCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $statusCode = (int) $m[1];
        }
        if ($response === false) {
            error_log('[contact.php] Brevo 요청 실패 (file_get_contents)');
            return false;
        }
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        error_log('[contact.php] Brevo 발송 실패 (HTTP ' . $statusCode . '): ' . $response);
        return false;
    }

    return true;
}

// ---- 요청 방식 확인 ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, '잘못된 접근입니다.', 405);
}

// ---- 스팸 방지: 허니팟 필드 -------------------------------------------
// 사람 눈에는 보이지 않는 필드이므로, 값이 채워져 있으면 봇으로 간주하고
// 스팸을 보낸 쪽에게는 성공한 것처럼 보이도록 조용히 종료합니다.
if (!empty($_POST['website'])) {
    respond(true, '제안과 요청이 접수되었습니다.');
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

// ---- 관리자에게 알림 메일 발송 (Brevo) -----------------------------------
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

$sent = sendViaBrevo(RECIPIENT_EMAIL, '추영준', $subject, $body, $email);

// ---- 접수자 본인에게 확인 메일 발송 (실패해도 전체 처리는 계속 진행) ------------
if ($sent) {
    $confirmSubject = '[' . SITE_NAME . '] 제안과 요청이 접수되었습니다';

    $confirmBodyLines = [
        $name . '님, 안녕하세요.',
        '',
        '인생설계가이드 추영준입니다.',
        '',
        '보내주신 제안과 요청이 정상적으로 접수되었습니다.',
        '소중한 의견 남겨주셔서 진심으로 감사드립니다.',
        '내용을 꼼꼼히 확인한 후, 빠른 시일 내에 답변드리겠습니다.',
        '',
        '프로그램에 대해 보다 궁금한 사항이 있으시면, ' . RECIPIENT_EMAIL . '로 메모를 남겨주시면',
        '확인하는 대로 바로 회신드리겠습니다.',
        '',
        '문의 주셔서 다시 한번 고맙습니다.',
        '',
        '추영준 드림',
        '',
        '----------------------------------------',
        '[접수하신 내용]',
        '문의 유형 : ' . $inquiryType,
        '이름      : ' . $name,
        '소속      : ' . ($organization !== '' ? $organization : '(미입력)'),
        '연락처    : ' . $phone,
        '',
        '문의 내용',
        $message,
        '----------------------------------------',
        '',
        '본 메일은 발신 전용입니다.',
    ];
    $confirmBody = implode("\n", $confirmBodyLines);

    sendViaBrevo($email, $name, $confirmSubject, $confirmBody, RECIPIENT_EMAIL);

    respond(true, '제안과 요청이 접수되었습니다. 빠른 시일 내에 답변드리겠습니다.');
}

respond(false, '메일 발송 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.', 500);
