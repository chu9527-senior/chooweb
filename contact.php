<?php
/**
 * 추영준(인생설계가이드) 홍보 웹사이트 - 문의 폼 메일 발송 스크립트
 * 호스팅케이알(hosting.kr) cPanel PHP 환경의 mail() 함수를 사용합니다.
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
$phone        = isset($_POST['phone']) ? cleanInput((string) $_POST['phone']) : '';
$inquiryType  = isset($_POST['inquiry_type']) ? cleanInput((string) $_POST['inquiry_type']) : '';
$message      = isset($_POST['message']) ? trim((string) $_POST['message']) : '';
$message      = mb_substr($message, 0, MAX_FIELD_LEN);

// ---- 필수값 검증 -------------------------------------------------------
$allowedTypes = ['강연문의', '저서문의', '기타'];

if ($name === '' || $phone === '' || $message === '') {
    respond(false, '이름, 연락처, 문의 내용을 모두 입력해 주세요.', 422);
}

if (!in_array($inquiryType, $allowedTypes, true)) {
    respond(false, '문의 유형을 올바르게 선택해 주세요.', 422);
}

if (mb_strlen($name) > 100 || mb_strlen($phone) > 100) {
    respond(false, '입력값이 너무 깁니다. 다시 확인해 주세요.', 422);
}

// ---- 메일 발송 ---------------------------------------------------------
$subject = '[' . SITE_NAME . '] 새 문의: ' . $inquiryType . ' - ' . $name;

$bodyLines = [
    '홈페이지를 통해 새로운 문의가 접수되었습니다.',
    '',
    '문의 유형 : ' . $inquiryType,
    '이름      : ' . $name,
    '연락처    : ' . $phone,
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
    'Reply-To: ' . RECIPIENT_EMAIL,
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
