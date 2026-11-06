<?php
/**
 * 공용 메일 발송 함수 (Brevo API)
 * contact.php, diagnosis.php에서 함께 사용합니다.
 * hosting.kr 요금제는 PHP mail()이 비활성화되어 있어 Brevo를 씁니다.
 * db_config.php에 BREVO_API_KEY, BREVO_SENDER_EMAIL이 정의되어 있어야 동작합니다.
 */

declare(strict_types=1);

if (!defined('SITE_NAME')) {
    define('SITE_NAME', '인생설계가이드');
}

function sendViaBrevo(string $toEmail, string $toName, string $subject, string $textBody, ?string $replyTo = null, ?string $htmlBody = null): bool
{
    if (!defined('BREVO_API_KEY') || !defined('BREVO_SENDER_EMAIL')) {
        error_log('[mail_lib] BREVO_API_KEY 또는 BREVO_SENDER_EMAIL이 설정되지 않았습니다.');
        return false;
    }

    $payload = [
        'sender'      => ['name' => SITE_NAME, 'email' => BREVO_SENDER_EMAIL],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $subject,
        'textContent' => $textBody,
    ];
    if ($htmlBody !== null) {
        $payload['htmlContent'] = $htmlBody;
    }
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
            error_log('[mail_lib] Brevo cURL 오류: ' . $curlError);
            return false;
        }
    } else {
        // cURL 확장이 없을 경우를 대비한 대체 수단
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers) . "\r\n",
                'content'       => $jsonPayload,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
        $statusCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $statusCode = (int) $m[1];
        }
        if ($response === false) {
            error_log('[mail_lib] Brevo 요청 실패 (file_get_contents)');
            return false;
        }
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        error_log('[mail_lib] Brevo 발송 실패 (HTTP ' . $statusCode . '): ' . $response);
        return false;
    }

    return true;
}
