<?php
if (!defined('_GNUBOARD_')) exit;

define('FILE_TO_DB_URL_ENDPOINT', 'https://www.promydirect.com/DTAS/action/INTERFACE');
// define('FILE_TO_DB_URL_ENDPOINT', 'https://callpro.kr');
define('FILE_TO_DB_URL_DISPATCH_METHOD', 'insCmCustomer');
define('FILE_TO_DB_URL_JEHUSA_CODE', 'C1205');
define('FILE_TO_DB_URL_AES_KEY', 'dbfire!#dbfire@$dbfire!makelife!');
define('FILE_TO_DB_URL_RESULT_PREFIX', 'db_url_result_');

function file_to_db_url_only_digits($value)
{
    return preg_replace('/\D+/', '', (string)$value);
}

function file_to_db_url_clean_field($value)
{
    $value = trim((string)$value);
    $value = str_replace(array("\r\n", "\r", "\n", "\t", '|'), ' ', $value);
    $value = preg_replace('/\s{2,}/u', ' ', $value);
    return trim($value);
}

function file_to_db_url_normalize_header($value)
{
    $value = trim((string)$value);
    $value = preg_replace('/[\s\-_()\/]+/u', '', $value);
    return mb_strtolower($value, 'UTF-8');
}

function file_to_db_url_find_header_index($headers, $candidates)
{
    $normalized_candidates = array();
    foreach ($candidates as $candidate) {
        $normalized_candidates[] = file_to_db_url_normalize_header($candidate);
    }

    foreach ($headers as $index => $header) {
        $normalized_header = file_to_db_url_normalize_header($header);
        if (in_array($normalized_header, $normalized_candidates, true)) {
            return $index;
        }

        foreach ($normalized_candidates as $normalized_candidate) {
            if ($normalized_candidate !== '' && strpos($normalized_header, $normalized_candidate) !== false) {
                return $index;
            }
        }
    }

    return null;
}

function file_to_db_url_normalize_phone($value)
{
    $digits = file_to_db_url_only_digits($value);
    if ($digits === '') {
        return '';
    }

    if (strlen($digits) === 10 && preg_match('/^1[016789]/', $digits)) {
        return '0'.$digits;
    }

    return $digits;
}

function file_to_db_url_normalize_personal_id($value)
{
    $digits = file_to_db_url_only_digits($value);
    if ($digits !== '' && strlen($digits) < 7) {
        $digits = str_pad($digits, 7, '0', STR_PAD_LEFT);
    }

    return $digits;
}

function file_to_db_url_encrypt($plain_text)
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL 확장이 설치되어 있지 않습니다.');
    }

    // AES-256 direct keys are 32 bytes. The provided setting is kept above as-is,
    // and the OpenSSL-compatible 32-byte key is made explicit here.
    $key = substr(FILE_TO_DB_URL_AES_KEY, 0, 32);
    if (strlen($key) !== 32) {
        throw new RuntimeException('AES256 암호화 키는 32바이트여야 합니다.');
    }

    $encrypted = openssl_encrypt(
        (string)$plain_text,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        str_repeat("\0", 16)
    );

    if ($encrypted === false) {
        throw new RuntimeException('AES256 암호화 실패');
    }

    return base64_encode($encrypted);
}

function file_to_db_url_build_request_url($row)
{
    $params = array(
        'dispatchMethod' => FILE_TO_DB_URL_DISPATCH_METHOD,
        'PERSONAL_ID' => rawurlencode(file_to_db_url_encrypt($row['personal_id'])),
        'CSTM_NM_KR' => rawurlencode(file_to_db_url_encrypt($row['name'])),
        'MOBILE_PHN_NUM' => rawurlencode(file_to_db_url_encrypt($row['phone'])),
        'INIT_MSG' => rawurlencode(file_to_db_url_encrypt($row['memo'])),
        'JEHUSA_CD' => FILE_TO_DB_URL_JEHUSA_CODE,
    );

    $query = array();
    foreach ($params as $key => $value) {
        $query[] = $key.'='.$value;
    }

    return FILE_TO_DB_URL_ENDPOINT.'?'.implode('&', $query);
}

function file_to_db_url_parse_response($response)
{
    $parsed = array(
        'ERROR_CD' => '',
        'MSG' => '',
        'raw' => (string)$response,
    );

    foreach (explode('|', (string)$response) as $part) {
        $pieces = explode(':', $part, 2);
        if (count($pieces) !== 2) {
            continue;
        }

        $key = trim($pieces[0]);
        $value = trim($pieces[1]);
        if ($key !== '') {
            $parsed[$key] = $value;
        }
    }

    return $parsed;
}

function file_to_db_url_send_row($row)
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL 확장이 설치되어 있지 않습니다.');
    }

    $url = file_to_db_url_build_request_url($row);
    $ch = curl_init($url);
    // echo $url;
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPGET, true);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return array(
            'success' => false,
            'error_cd' => 'FALSE',
            'msg' => 'cURL 오류 - '.$error,
            'http_code' => $http_code,
            'raw' => '',
            'url' => $url,
        );
    }

    $parsed = file_to_db_url_parse_response($response);
    $success = (strtoupper($parsed['ERROR_CD']) === 'TRUE');

    return array(
        'success' => $success,
        'error_cd' => $parsed['ERROR_CD'],
        'msg' => $parsed['MSG'],
        'http_code' => $http_code,
        'raw' => $parsed['raw'],
        'url' => $url,
    );
}

function file_to_db_url_get_test_row()
{
    return array(
        'personal_id' => '0001013',
        'name' => '홍길동',
        'phone' => '01100000000',
        'memo' => '99991231일 상담희망',
    );
}
