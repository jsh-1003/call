<?php
include_once('./_common.php');

ini_set('display_errors', '1');
error_reporting(E_ALL);

$FILE_BASIC_SEND_DEBUG = false;

$debug_logs = array();
$bo_table = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_POST['bo_table'] ?? $_GET['bo_table'] ?? ''));
$wr_id = (int)($_POST['wr_id'] ?? $_GET['wr_id'] ?? 0);
$debug_title = $FILE_BASIC_SEND_DEBUG ? '엑셀 전송 디버그' : '엑셀 전송 결과';

function file_basic_send_only_digits($value)
{
    return preg_replace('/\D+/', '', (string)$value);
}


function file_basic_send_debug($message)
{
    global $debug_logs;
    $debug_logs[] = '['.date('H:i:s').'] '.$message;
}

function file_basic_send_write_result($write_table, $wr_id, $message)
{
    $sent_at = date('Y-m-d H:i:s');
    $message = trim((string)$message);
    if ($message === '') {
        $message = '결과 없음';
    }

    $sql = " update {$write_table}
                set wr_1 = '".sql_escape_string($sent_at)."',
                    wr_2 = '".sql_escape_string($message)."'
              where wr_id = '{$wr_id}' ";
    sql_query($sql, false);

    return $sent_at;
}

function file_basic_send_render($title, $success, $bo_table, $wr_id)
{
    global $debug_logs, $FILE_BASIC_SEND_DEBUG;

    $back_url = ($bo_table && $wr_id) ? get_pretty_url($bo_table, $wr_id) : G5_URL;
    $status_text = $success ? '성공' : '실패';
    $status_color = $success ? '#0f766e' : '#b91c1c';
    $headline = $success ? '엑셀 전송이 완료되었습니다.' : '엑셀 전송에 실패했습니다.';
    $summary = '';

    if (!empty($debug_logs)) {
        $last_line = end($debug_logs);
        $summary = preg_replace('/^\[[0-9:]+\]\s*/', '', (string)$last_line);
        reset($debug_logs);
    }

    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</title>';
    echo '<style>
    body{margin:0;padding:24px;font-family:Arial,sans-serif;background:#f5f7fb;color:#111827}
    .wrap{max-width:980px;margin:0 auto;background:#fff;border:1px solid #dbe3ef;border-radius:16px;padding:24px}
    h1{margin:0 0 12px;font-size:28px}
    .status{display:inline-block;margin-bottom:18px;padding:8px 14px;border-radius:999px;font-weight:700;color:#fff;background:'.$status_color.'}
    .summary{margin:0 0 16px;font-size:16px;line-height:1.7;color:#374151}
    .log{margin:0;padding:0;list-style:none}
    .log li{padding:12px 14px;border-top:1px solid #eef2f7;font-family:Consolas,Monaco,monospace;font-size:14px;white-space:pre-wrap;word-break:break-word}
    .log li:first-child{border-top:0}
    .btns{margin-top:20px}
    .btn{display:inline-block;padding:12px 18px;border-radius:10px;background:#111827;color:#fff;text-decoration:none}
    </style></head><body><div class="wrap">';
    echo '<h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>';
    echo '<div class="status">전송 '.$status_text.'</div>';
    echo '<p class="summary">'.htmlspecialchars($headline, ENT_QUOTES, 'UTF-8').'</p>';
    if ($summary !== '') {
        echo '<p class="summary"><strong>결과:</strong> '.htmlspecialchars($summary, ENT_QUOTES, 'UTF-8').'</p>';
    }
    if ($FILE_BASIC_SEND_DEBUG) {
        echo '<ul class="log">';
        foreach ($debug_logs as $line) {
            echo '<li>'.htmlspecialchars($line, ENT_QUOTES, 'UTF-8').'</li>';
        }
        echo '</ul>';
    }
    echo '<div class="btns"><a class="btn" href="'.htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8').'">게시물로 돌아가기</a></div>';
    echo '</div></body></html>';
    exit;
}

function file_basic_send_fail($message, $write_table = '', $wr_id = 0, $bo_table = '', $title = '엑셀 전송 디버그')
{
    file_basic_send_debug('실패: '.$message);

    if ($write_table && $wr_id > 0) {
        $sent_at = file_basic_send_write_result($write_table, $wr_id, '실패 - '.$message);
        file_basic_send_debug('wr_1 저장: '.$sent_at);
        file_basic_send_debug('wr_2 저장: 실패 - '.$message);
    }

    file_basic_send_render($title, false, $bo_table, $wr_id);
}

function file_basic_send_normalize_header($value)
{
    $value = trim((string)$value);
    $value = preg_replace('/[\s\-_()\/]+/u', '', $value);
    return mb_strtolower($value, 'UTF-8');
}

function file_basic_send_find_header_index($headers, $candidates)
{
    $normalized_candidates = array();
    foreach ($candidates as $candidate) {
        $normalized_candidates[] = file_basic_send_normalize_header($candidate);
    }

    foreach ($headers as $index => $header) {
        $normalized_header = file_basic_send_normalize_header($header);
        if (in_array($normalized_header, $normalized_candidates, true)) {
            return $index;
        }

        foreach ($normalized_candidates as $normalized_candidate) {
            if ($normalized_candidate !== '' && strpos($normalized_header, $normalized_candidate) !== false) {
                return $index;
            }
        }

        foreach ($normalized_candidates as $normalized_candidate) {
            if ($normalized_header !== '' && strpos($normalized_candidate, $normalized_header) !== false) {
                return $index;
            }
        }

    }

    return null;
}

function file_basic_send_normalize_phone($value)
{
    $digits = file_basic_send_only_digits($value);
    if ($digits === '') {
        return '';
    }

    // Excel 숫자 셀로 읽히면 01011112222가 1011112222처럼 앞의 0이 빠질 수 있다.
    if (strlen($digits) === 10 && preg_match('/^1[016789]/', $digits)) {
        return '0'.$digits;
    }

    return $digits;
}

function file_basic_send_excel_serial_to_datetime($value)
{
    if (!is_numeric($value)) {
        return null;
    }

    $numeric = (float)$value;

    // 19930306, 20260306140000 같은 일반 날짜 숫자가 serial date로 오인되지 않도록 제한한다.
    // Excel serial 60000은 2064년대라 현재 업무 데이터 범위로 충분하다.
    if ($numeric <= 0 || $numeric > 60000) {
        return null;
    }

    $days = (int)floor($numeric);
    $seconds = (int)round(($numeric - $days) * 86400);
    $base = new DateTime('1899-12-30 00:00:00');
    $base->modify('+'.$days.' days');
    if ($seconds > 0) {
        $base->modify('+'.$seconds.' seconds');
    }

    return $base;
}

function file_basic_send_normalize_birth($value)
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }

    $digits = file_basic_send_only_digits($raw);

    // 19930306 형식은 Excel serial보다 먼저 처리해야 한다.
    if (strlen($digits) === 8) {
        $year = (int)substr($digits, 0, 4);
        $month = (int)substr($digits, 4, 2);
        $day = (int)substr($digits, 6, 2);
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d%02d%02d', $year, $month, $day);
        }
    }

    if (strlen($digits) >= 7) {
        $birth_digits = substr($digits, 0, 6);
        $gender_code = substr($digits, 6, 1);
        $yy = (int)substr($birth_digits, 0, 2);
        $year = in_array($gender_code, array('1', '2', '5', '6'), true) ? 1900 + $yy : 2000 + $yy;
        $month = (int)substr($birth_digits, 2, 2);
        $day = (int)substr($birth_digits, 4, 2);
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d%02d%02d', $year, $month, $day);
        }
    }

    if (strlen($digits) === 6) {
        $yy = (int)substr($digits, 0, 2);
        $year = ($yy >= 40) ? 1900 + $yy : 2000 + $yy;
        $month = (int)substr($digits, 2, 2);
        $day = (int)substr($digits, 4, 2);
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d%02d%02d', $year, $month, $day);
        }
    }

    $excel_dt = file_basic_send_excel_serial_to_datetime($raw);
    if ($excel_dt !== null) {
        return $excel_dt->format('Ymd');
    }

    $converted = str_replace(array('.', '/', '년', '월', '일'), array('-', '-', '-', '-', ''), $raw);
    $converted = preg_replace('/-+/', '-', $converted);
    $parts = array_values(array_filter(explode('-', $converted), 'strlen'));
    if (count($parts) === 3) {
        $year = (int)$parts[0];
        if ($year < 100) {
            $year = ($year >= 40) ? 1900 + $year : 2000 + $year;
        }
        $month = (int)$parts[1];
        $day = (int)$parts[2];
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d%02d%02d', $year, $month, $day);
        }
    }

    return '';
}

function file_basic_send_normalize_datetime($value, $default_datetime = '')
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return $default_datetime;
    }

    $digits = file_basic_send_only_digits($raw);

    // 20260306140000 형식은 Excel serial보다 먼저 처리해야 한다.
    if (strlen($digits) === 14) {
        $year = (int)substr($digits, 0, 4);
        $month = (int)substr($digits, 4, 2);
        $day = (int)substr($digits, 6, 2);
        $hour = (int)substr($digits, 8, 2);
        $minute = (int)substr($digits, 10, 2);
        $second = (int)substr($digits, 12, 2);
        if (checkdate($month, $day, $year) && $hour < 24 && $minute < 60 && $second < 60) {
            return $digits;
        }
    }

    if (strlen($digits) === 12) {
        $candidate = $digits.'00';
        $year = (int)substr($candidate, 0, 4);
        $month = (int)substr($candidate, 4, 2);
        $day = (int)substr($candidate, 6, 2);
        $hour = (int)substr($candidate, 8, 2);
        $minute = (int)substr($candidate, 10, 2);
        if (checkdate($month, $day, $year) && $hour < 24 && $minute < 60) {
            return $candidate;
        }
    }

    if (strlen($digits) === 8) {
        $year = (int)substr($digits, 0, 4);
        $month = (int)substr($digits, 4, 2);
        $day = (int)substr($digits, 6, 2);
        if (checkdate($month, $day, $year)) {
            $default_time = (strlen($default_datetime) === 14) ? substr($default_datetime, 8, 6) : '000000';
            return $digits.$default_time;
        }
    }

    $excel_dt = file_basic_send_excel_serial_to_datetime($raw);
    if ($excel_dt !== null) {
        return $excel_dt->format('YmdHis');
    }

    $converted = str_replace(array('.', '/', '년', '월', '일', '시', '분', '초', 'T'), array('-', '-', '-', '-', ' ', ':', ':', '', ' '), $raw);
    $timestamp = strtotime($converted);
    if ($timestamp !== false) {
        return date('YmdHis', $timestamp);
    }

    return '';
}

function file_basic_send_clean_field($value)
{
    $value = trim((string)$value);
    $value = str_replace(array("\r\n", "\r", "\n", "\t", '|'), ' ', $value);
    $value = preg_replace('/\s{2,}/u', ' ', $value);
    return trim($value);
}

function file_basic_send_extract_gender_code($gender_value, $birth_value)
{
    $gender_digits = file_basic_send_only_digits($gender_value);
    if ($gender_digits !== '') {
        $gender_code = substr($gender_digits, 0, 1);
        if (in_array($gender_code, array('1', '2', '3', '4'), true)) {
            return $gender_code;
        }
    }

    $birth_digits = file_basic_send_only_digits($birth_value);
    if (strlen($birth_digits) >= 7) {
        $gender_code = substr($birth_digits, 6, 1);
        if (in_array($gender_code, array('1', '2', '3', '4'), true)) {
            return $gender_code;
        }
    }

    $normalized_gender = mb_strtolower(trim((string)$gender_value), 'UTF-8');
    if ($normalized_gender === '') {
        return '';
    }

    $birth = file_basic_send_normalize_birth($birth_value);
    $birth_year = $birth !== '' ? (int)substr($birth, 0, 4) : 0;

    if (in_array($normalized_gender, array('남', '남자', '남성', 'm', 'male'), true)) {
        return ($birth_year >= 2000) ? '3' : '1';
    }

    if (in_array($normalized_gender, array('여', '여자', '여성', 'f', 'female'), true)) {
        return ($birth_year >= 2000) ? '4' : '2';
    }

    return '';
}


function file_basic_send_build_end_datetime($consent_datetime)
{
    if ($consent_datetime === '' || strlen($consent_datetime) !== 14) {
        return '';
    }

    $dt = DateTime::createFromFormat('YmdHis', $consent_datetime);
    if (!$dt) {
        return '';
    }

    $dt->modify('+1 year');
    $dt->modify('-1 day');

    return $dt->format('YmdHis');
}

function file_basic_send_find_excel_file($bo_table, $wr_id, $file_no)
{
    global $g5;

    $where = '';
    if ($file_no >= 0) {
        $where = " and bf_no = '{$file_no}' ";
    }

    $sql = " select * from {$g5['board_file_table']}
              where bo_table = '".sql_escape_string($bo_table)."'
                and wr_id = '{$wr_id}'
                {$where}
              order by bf_no asc ";
    $result = sql_query($sql);

    while ($row = sql_fetch_array($result)) {
        $source = isset($row['bf_source']) ? (string)$row['bf_source'] : '';
        if (preg_match('/\.(xlsx|xls|csv)$/i', $source)) {
            return $row;
        }
    }

    return null;
}

function file_basic_send_load_sheet($file_path)
{
    file_basic_send_debug('엑셀 로더 진입: '.$file_path);

    $vendor_autoload = G5_PATH.'/vendor/autoload.php';
    if (is_file($vendor_autoload)) {
        require_once $vendor_autoload;
        file_basic_send_debug('vendor/autoload.php 로드 성공');
    } else {
        file_basic_send_debug('vendor/autoload.php 없음, PHPExcel fallback 시도');
    }

    if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        file_basic_send_debug('PhpSpreadsheet 사용');
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file_path);
        if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv) {
            $csv_raw = @file_get_contents($file_path);
            if ($csv_raw !== false) {
                $reader->setInputEncoding(mb_check_encoding($csv_raw, 'UTF-8') ? 'UTF-8' : 'CP949');
            }
            $reader->setDelimiter(',');
            $reader->setEnclosure('"');
        }
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file_path);
        return $spreadsheet->getSheet(0);
    }

    $php_excel_loader = G5_LIB_PATH.'/PHPExcel/IOFactory.php';
    if (!is_file($php_excel_loader)) {
        throw new RuntimeException('엑셀 라이브러리를 찾을 수 없습니다.');
    }

    require_once $php_excel_loader;
    file_basic_send_debug('PHPExcel 사용');
    $reader = PHPExcel_IOFactory::createReaderForFile($file_path);
    if ($reader instanceof PHPExcel_Reader_CSV) {
        $csv_raw = @file_get_contents($file_path);
        if ($csv_raw !== false) {
            $reader->setInputEncoding(mb_check_encoding($csv_raw, 'UTF-8') ? 'UTF-8' : 'CP949');
        }
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->setSheetIndex(0);
    }
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($file_path);

    return $spreadsheet->getSheet(0);
}

function file_basic_send_upload_sftp($local_path, $remote_path)
{
    file_basic_send_debug('SFTP 전송 준비: '.$remote_path);

    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL 확장이 설치되어 있지 않습니다.');
    }

    if (!defined('CURLPROTO_SFTP')) {
        throw new RuntimeException('현재 서버 cURL이 SFTP를 지원하지 않습니다.');
    }

    $fp = fopen($local_path, 'r');
    if (!$fp) {
        throw new RuntimeException('생성된 TXT 파일을 읽을 수 없습니다.');
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'sftp://124.60.228.152'.$remote_path);
    curl_setopt($ch, CURLOPT_PORT, 9922);
    curl_setopt($ch, CURLOPT_USERPWD, 'hftp:!@Ubuntu260415#$');
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP);
    curl_setopt($ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD);
    curl_setopt($ch, CURLOPT_INFILE, $fp);
    curl_setopt($ch, CURLOPT_INFILESIZE, filesize($local_path));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    $error_no = curl_errno($ch);
    $error_msg = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    file_basic_send_debug('curl errno: '.$error_no);
    file_basic_send_debug('curl message: '.($error_msg !== '' ? $error_msg : '없음'));
    file_basic_send_debug('curl http code: '.(string)$http_code);

    if ($error_no) {
        throw new RuntimeException('SFTP 전송 실패 - '.$error_msg);
    }

    return $response;
}

file_basic_send_debug('전송 액션 시작');
file_basic_send_debug('요청 메소드: '.($_SERVER['REQUEST_METHOD'] ?? ''));
file_basic_send_debug('bo_table: '.($bo_table !== '' ? $bo_table : '(빈값)'));
file_basic_send_debug('wr_id: '.(string)$wr_id);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    file_basic_send_fail('POST 요청이 아닙니다.', '', 0, $bo_table, $debug_title);
}

if ($bo_table === '' || $wr_id < 1) {
    file_basic_send_fail('게시물 정보가 올바르지 않습니다.', '', 0, $bo_table, $debug_title);
}

$debug_title = '['.$bo_table.' #'.$wr_id.'] '.($FILE_BASIC_SEND_DEBUG ? '엑셀 전송 디버그' : '엑셀 전송 결과');
$write_table = $g5['write_prefix'].$bo_table;
file_basic_send_debug('write_table: '.$write_table);

$file_no = isset($_POST['file_no']) ? (int)$_POST['file_no'] : -1;
$send_token = (string)($_POST['send_token'] ?? '');
$session_token_name = 'ss_file_basic_send_'.$bo_table.'_'.$wr_id;
$session_token = (string)get_session($session_token_name);
set_session($session_token_name, '');

file_basic_send_debug('file_no: '.(string)$file_no);
file_basic_send_debug('전송 토큰 존재: '.($send_token !== '' ? 'Y' : 'N'));
file_basic_send_debug('세션 토큰 존재: '.($session_token !== '' ? 'Y' : 'N'));

if ($session_token === '' || $send_token === '' || !hash_equals($session_token, $send_token)) {
    file_basic_send_fail('전송 토큰이 일치하지 않습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

if (!get_session('ss_view_'.$bo_table.'_'.$wr_id)) {
    file_basic_send_fail('게시물 보기 세션이 없습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

$board = sql_fetch(" select * from {$g5['board_table']} where bo_table = '".sql_escape_string($bo_table)."' ");
file_basic_send_debug('게시판 조회: '.(!empty($board['bo_table']) ? '성공' : '실패'));
if (empty($board['bo_table'])) {
    file_basic_send_fail('게시판 정보를 찾을 수 없습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

$write = get_write($write_table, $wr_id);
file_basic_send_debug('게시물 조회: '.(!empty($write['wr_id']) ? '성공' : '실패'));
if (empty($write['wr_id'])) {
    file_basic_send_fail('게시물을 찾을 수 없습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

$excel_file = file_basic_send_find_excel_file($bo_table, $wr_id, $file_no);
if (!$excel_file) {
    file_basic_send_fail('전송할 엑셀 첨부파일을 찾지 못했습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

file_basic_send_debug('첨부파일 선택: '.$excel_file['bf_source']);
$excel_path = G5_DATA_PATH.'/file/'.$bo_table.'/'.$excel_file['bf_file'];
file_basic_send_debug('첨부파일 경로: '.$excel_path);
if (!is_file($excel_path)) {
    file_basic_send_fail('첨부파일이 서버에 존재하지 않습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

try {
    $sheet = file_basic_send_load_sheet($excel_path);
} catch (Exception $e) {
    file_basic_send_fail('엑셀 로드 실패 - '.$e->getMessage(), $write_table, $wr_id, $bo_table, $debug_title);
}

$highest_row = $sheet->getHighestRow();
$highest_column = $sheet->getHighestColumn();
file_basic_send_debug('엑셀 행 수: '.$highest_row);
file_basic_send_debug('엑셀 마지막 컬럼: '.$highest_column);

$first_row_data = $sheet->rangeToArray('A1:'.$highest_column.'1', null, false, false);
$headers = isset($first_row_data[0]) && is_array($first_row_data[0]) ? $first_row_data[0] : array();
$headers = array_map('file_basic_send_clean_field', $headers);
file_basic_send_debug('헤더: '.implode(' | ', $headers));

$headerless = false;
if (isset($headers[0]) && preg_match('/^[가-힣A-Za-z0-9]+$/u', $headers[0]) && !preg_match('/(이름|성명|name|휴대폰|핸드폰|전화|생년월일|동의)/iu', $headers[0])) {
    $headerless = true;
}
file_basic_send_debug('헤더 여부: '.($headerless ? '헤더 없음' : '헤더 있음'));

if ($headerless) {
    $start_row = 1;
    $idx_name = 0;
    $idx_birth = 1;
    $idx_gender = 2;
    $idx_phone = 3;
    $idx_consent = 4;
    $idx_end = 5;
    $idx_region = 6;
    $address_indexes = array();
} else {
    $start_row = 2;
    $idx_name = file_basic_send_find_header_index($headers, array('이름', '성명', '성함', '고객명', 'name'));
    $idx_birth = file_basic_send_find_header_index($headers, array('생년월일', '생일', '주민번호', '주민등록번호', 'birth', 'birthdate', 'dob'));
    $idx_gender = file_basic_send_find_header_index($headers, array('성별', '남녀', 'sex', 'gender', '주민번호7번째자리', '주민번호7번째'));
    $idx_phone = file_basic_send_find_header_index($headers, array('핸드폰번호', '휴대폰번호', '핸드폰', '휴대폰', '전화번호', '연락처', 'phone', 'hp', 'tel', 'callhp'));
    $idx_consent = file_basic_send_find_header_index($headers, array('마케팅동의일', '동의일', '동의일시', '마케팅수신동의일', '수집동의일', 'consentdate', 'marketingconsentdate'));
    $idx_end = file_basic_send_find_header_index($headers, array('마케팅동의종료일', '동의종료일', '종료일', '만료일', '마케팅수신종료일', 'consentenddate', 'marketingenddate'));
    $idx_region = file_basic_send_find_header_index($headers, array('지역또는주소', '지역', '주소', '거주지', '시도', '지역명', 'address'));
    $address_indexes = array();

    foreach ($headers as $index => $header) {
        if (file_basic_send_find_header_index(array($header), array('주소', '주소1', '주소2', '상세주소', '지역', '거주지', '시도', '시군구', '도로명주소')) !== null) {
            $address_indexes[] = $index;
        }
    }
}

file_basic_send_debug('매핑 결과 - 이름: '.($idx_name === null ? '없음' : $idx_name).', 생년월일: '.($idx_birth === null ? '없음' : $idx_birth).', 성별: '.($idx_gender === null ? '없음' : $idx_gender).', 연락처: '.($idx_phone === null ? '없음' : $idx_phone).', 동의일: '.($idx_consent === null ? '없음' : $idx_consent).', 종료일: '.($idx_end === null ? '없음' : $idx_end).', 지역: '.($idx_region === null ? '없음' : $idx_region));

if ($idx_name === null || $idx_birth === null || $idx_phone === null) {
    file_basic_send_fail('필수 헤더(이름/생년월일/휴대폰번호)를 찾지 못했습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

$default_consent_datetime = date('YmdHis', strtotime($write['wr_datetime']));
$txt_lines = array('이름|생년월일|성별(주민번호 7번째 자리)|핸드폰번호|마케팅동의일|마케팅동의종료일|지역또는주소');
$preview_rows = array();
$success_rows = 0;

for ($row_no = $start_row; $row_no <= $highest_row; $row_no++) {
    $row_data = $sheet->rangeToArray('A'.$row_no.':'.$highest_column.$row_no, null, false, false);
    if (!isset($row_data[0]) || !is_array($row_data[0])) {
        continue;
    }

    $row = array_map('file_basic_send_clean_field', $row_data[0]);
    if (implode('', $row) === '') {
        continue;
    }

    $name = file_basic_send_clean_field($row[$idx_name] ?? '');
    $birth_raw = $row[$idx_birth] ?? '';
    $gender_raw = ($idx_gender !== null) ? ($row[$idx_gender] ?? '') : '';
    $phone = file_basic_send_normalize_phone($row[$idx_phone] ?? '');
    $consent_datetime = ($idx_consent !== null) ? file_basic_send_normalize_datetime($row[$idx_consent] ?? '', $default_consent_datetime) : $default_consent_datetime;
    $end_datetime = ($idx_end !== null) ? file_basic_send_normalize_datetime($row[$idx_end] ?? '', '') : '';
    $birth = file_basic_send_normalize_birth($birth_raw);
    $gender_code = file_basic_send_extract_gender_code($gender_raw, $birth_raw);

    if ($idx_region !== null) {
        $region = file_basic_send_clean_field($row[$idx_region] ?? '');
    } else {
        $address_parts = array();
        foreach ($address_indexes as $address_index) {
            $part = file_basic_send_clean_field($row[$address_index] ?? '');
            if ($part !== '') {
                $address_parts[] = $part;
            }
        }
        $region = implode(' ', array_unique($address_parts));
    }

    if ($end_datetime === '') {
        $end_datetime = file_basic_send_build_end_datetime($consent_datetime);
    }

    if (count($preview_rows) < 5) {
        $preview_rows[] = '행 '.$row_no.' => '.$name.' | '.$birth.' | '.$gender_code.' | '.$phone.' | '.$consent_datetime.' | '.$end_datetime.' | '.$region;
    }

    if ($name === '' || $birth === '' || $gender_code === '' || $phone === '' || $consent_datetime === '' || $end_datetime === '') {
        file_basic_send_debug('실패 행 원본값: '.implode(' | ', $row));
        file_basic_send_debug('실패 행 변환값: '.$name.' | '.$birth.' | '.$gender_code.' | '.$phone.' | '.$consent_datetime.' | '.$end_datetime.' | '.$region);
        file_basic_send_fail($row_no.'행 데이터 변환 실패', $write_table, $wr_id, $bo_table, $debug_title);
    }

    $txt_lines[] = implode('|', array($name, $birth, $gender_code, $phone, $consent_datetime, $end_datetime, $region));
    $success_rows++;
}

foreach ($preview_rows as $preview_row) {
    file_basic_send_debug('미리보기: '.$preview_row);
}

if ($success_rows < 1) {
    file_basic_send_fail('변환된 데이터가 없습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

file_basic_send_debug('TXT 변환 완료: '.$success_rows.'건');

$local_dir = G5_DATA_PATH.'/file_basic_send';
if (!is_dir($local_dir)) {
    @mkdir($local_dir, G5_DIR_PERMISSION, true);
    file_basic_send_debug('로컬 디렉터리 생성: '.$local_dir);
}

$remote_year = date('Y');
$remote_filename = date('Ymd').'.txt';
$local_filename = $bo_table.'_'.$wr_id.'_'.$remote_filename;
$local_path = $local_dir.'/'.$local_filename;
$remote_path = '/data/'.$remote_year.'/'.$remote_filename; // /home/hftp
$file_content = implode("\n", $txt_lines)."\n";

if (file_put_contents($local_path, $file_content) === false) {
    file_basic_send_fail('TXT 파일 저장 실패', $write_table, $wr_id, $bo_table, $debug_title);
}

file_basic_send_debug('로컬 TXT 저장: '.$local_path);
file_basic_send_debug('원격 대상 경로: '.$remote_path);

try {
    file_basic_send_upload_sftp($local_path, $remote_path);
} catch (Exception $e) {
    file_basic_send_fail($e->getMessage(), $write_table, $wr_id, $bo_table, $debug_title);
}

$result_message = '성공 - '.$success_rows.'건 전송 / '.$remote_filename;
$sent_at = file_basic_send_write_result($write_table, $wr_id, $result_message);
file_basic_send_debug('wr_1 저장: '.$sent_at);
file_basic_send_debug('wr_2 저장: '.$result_message);
file_basic_send_debug('전송 완료');

file_basic_send_render($debug_title, true, $bo_table, $wr_id);
