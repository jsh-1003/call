<?php
if (!defined('_GNUBOARD_')) exit;
/**
 * jnjsmart 콜 등록 전송 (POST)
 * endpoint: http://jnjsmart.co.kr/gamang/a_call_regist.asp
 *
 * @return array{ok:bool,http_code:int|null,body:string|null,error:string|null,request:array}
 */
function utf8_to_euckr($str) {
    return iconv('UTF-8', 'EUC-KR//IGNORE', $str);
}

function send_jnjsmart_call_regist(
    string $c_name,
    string $c_tel,
    string $c_consult = '',
    string $c_consult2 = '',
    string $c_bigo = ''
): array {
    $url = 'http://jnjsmart.co.kr/gamang/a_call_regist.asp';

    // 전송 파라미터
    $postFields = [
        'c_name'     => utf8_to_euckr($c_name),
        'c_tel'      => preg_replace('/\D+/', '', $c_tel),
        'c_consult'  => utf8_to_euckr($c_consult),
        'c_consult2' => utf8_to_euckr($c_consult2),
        'c_bigo'     => utf8_to_euckr($c_bigo),
    ];

    $ch = curl_init();
    if ($ch === false) {
        return [
            'ok' => false, 'http_code' => null, 'body' => null,
            'error' => 'curl_init failed', 'request' => $postFields
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($postFields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded; charset=EUC-KR',
            'Accept: */*',
        ],
    ]);

    $body = curl_exec($ch);
    $err  = ($body === false) ? curl_error($ch) : null;
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    curl_close($ch);

    return [
        'ok'        => ($err === null && $code >= 200 && $code < 300),
        'http_code' => $code ?: null,
        'body'      => ($body === false) ? null : $body,
        'error'     => $err,
        'request'   => $postFields,
    ];
}

/* =========================
 * 사용 예시
 * ========================= */
//$res = send_jnjsmart_call_regist('홍길동', '010-1234-5678', '1차상담자', '2차상담자', '기타내용');
//if (!$res['ok']) {
//    error_log('전송 실패: ' . ($res['error'] ?? 'HTTP '.$res['http_code']).' / body='.$res['body']);
//}
