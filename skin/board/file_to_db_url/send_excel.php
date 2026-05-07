<?php
include_once('./_common.php');
include_once('./db_url.lib.php');
ini_set('display_errors', '1');
// error_reporting(E_ALL);
error_reporting(E_ALL & ~E_DEPRECATED);
set_time_limit(360);

$FILE_TO_DB_URL_DEBUG = false;
$debug_logs = array();
$bo_table = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_POST['bo_table'] ?? $_GET['bo_table'] ?? ''));
$wr_id = (int)($_POST['wr_id'] ?? $_GET['wr_id'] ?? 0);
$debug_title = $FILE_TO_DB_URL_DEBUG ? 'DB손보 URL 전송 디버그' : 'DB손보 URL 전송 결과';

function file_to_db_url_debug($message)
{
    global $debug_logs;
    $debug_logs[] = '['.date('H:i:s').'] '.$message;
}

function file_to_db_url_write_result($write_table, $wr_id, $message)
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

function file_to_db_url_render($title, $success, $bo_table, $wr_id)
{
    global $debug_logs, $FILE_TO_DB_URL_DEBUG;

    $back_url = ($bo_table && $wr_id) ? get_pretty_url($bo_table, $wr_id) : G5_URL;
    $status_text = $success ? '성공' : '실패';
    $status_color = $success ? '#0f766e' : '#b91c1c';
    $headline = $success ? 'URL 전송이 완료되었습니다.' : 'URL 전송에 실패했습니다.';
    $summary = '';

    if (!empty($debug_logs)) {
        $last_line = end($debug_logs);
        $summary = preg_replace('/^\[[0-9:]+\]\s*/', '', (string)$last_line);
        reset($debug_logs);
    }

    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</title>';
    echo '<style>body{margin:0;padding:24px;font-family:Arial,sans-serif;background:#f5f7fb;color:#111827}.wrap{max-width:980px;margin:0 auto;background:#fff;border:1px solid #dbe3ef;border-radius:12px;padding:24px}h1{margin:0 0 12px;font-size:28px}.status{display:inline-block;margin-bottom:18px;padding:8px 14px;border-radius:999px;font-weight:700;color:#fff;background:'.$status_color.'}.summary{margin:0 0 16px;font-size:16px;line-height:1.7;color:#374151}.log{margin:0;padding:0;list-style:none}.log li{padding:12px 14px;border-top:1px solid #eef2f7;font-family:Consolas,Monaco,monospace;font-size:14px;white-space:pre-wrap;word-break:break-word}.btns{margin-top:20px}.btn{display:inline-block;padding:12px 18px;border-radius:8px;background:#111827;color:#fff;text-decoration:none}</style>';
    echo '</head><body><div class="wrap">';
    echo '<h1>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>';
    echo '<div class="status">전송 '.$status_text.'</div>';
    echo '<p class="summary">'.htmlspecialchars($headline, ENT_QUOTES, 'UTF-8').'</p>';
    if ($summary !== '') {
        echo '<p class="summary"><strong>결과:</strong> '.htmlspecialchars($summary, ENT_QUOTES, 'UTF-8').'</p>';
    }
    if ($FILE_TO_DB_URL_DEBUG) {
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

function file_to_db_url_fail($message, $write_table = '', $wr_id = 0, $bo_table = '', $title = 'DB손보 URL 전송 결과')
{
    file_to_db_url_debug('실패: '.$message);

    if ($write_table && $wr_id > 0) {
        file_to_db_url_write_result($write_table, $wr_id, '실패 - '.$message);
    }

    file_to_db_url_render($title, false, $bo_table, $wr_id);
}

function file_to_db_url_find_excel_file($bo_table, $wr_id, $file_no)
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
        if (strpos($source, FILE_TO_DB_URL_RESULT_PREFIX) === 0) {
            continue;
        }
        if (preg_match('/\.(xlsx|xls|csv)$/i', $source)) {
            return $row;
        }
    }

    return null;
}

function file_to_db_url_load_sheet($file_path)
{
    $vendor_autoload = G5_PATH.'/vendor/autoload.php';
    if (is_file($vendor_autoload)) {
        require_once $vendor_autoload;
    }

    if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
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

function file_to_db_url_create_result_excel($result_rows, $save_path)
{
    include_once(G5_LIB_PATH.'/PHPExcel/IOFactory.php');

    $xls = new PHPExcel();
    $sheet = $xls->setActiveSheetIndex(0);
    $sheet->setTitle('send_result');

    $headers = array('행번호', '주민등록번호', '고객명', '휴대폰', '고객메모', 'ERROR_CD', 'MSG', 'HTTP_CODE', '처리결과', '처리일시', '응답원문');
    foreach ($headers as $i => $header) {
        $sheet->setCellValueExplicitByColumnAndRow($i, 1, $header, PHPExcel_Cell_DataType::TYPE_STRING);
    }

    $r = 2;
    foreach ($result_rows as $row) {
        $sheet->setCellValueExplicitByColumnAndRow(0, $r, (string)$row['row_no'], PHPExcel_Cell_DataType::TYPE_STRING);
        $sheet->setCellValueExplicitByColumnAndRow(1, $r, (string)$row['personal_id'], PHPExcel_Cell_DataType::TYPE_STRING);
        $sheet->setCellValueExplicitByColumnAndRow(2, $r, (string)$row['name'], PHPExcel_Cell_DataType::TYPE_STRING);
        $sheet->setCellValueExplicitByColumnAndRow(3, $r, (string)$row['phone'], PHPExcel_Cell_DataType::TYPE_STRING);
        $sheet->setCellValueExplicitByColumnAndRow(4, $r, (string)$row['memo'], PHPExcel_Cell_DataType::TYPE_STRING);
        $sheet->setCellValueExplicitByColumnAndRow(5, $r, (string)$row['error_cd'], PHPExcel_Cell_DataType::TYPE_STRING);
        $sheet->setCellValueExplicitByColumnAndRow(6, $r, (string)$row['msg'], PHPExcel_Cell_DataType::TYPE_STRING);
        $sheet->setCellValueExplicitByColumnAndRow(7, $r, (string)$row['http_code'], PHPExcel_Cell_DataType::TYPE_STRING);
        $sheet->setCellValueExplicitByColumnAndRow(8, $r, $row['success'] ? '성공' : '실패', PHPExcel_Cell_DataType::TYPE_STRING);
        $sheet->setCellValueExplicitByColumnAndRow(9, $r, (string)$row['sent_at'], PHPExcel_Cell_DataType::TYPE_STRING);
        $sheet->setCellValueExplicitByColumnAndRow(10, $r, (string)$row['raw'], PHPExcel_Cell_DataType::TYPE_STRING);
        $r++;
    }

    foreach (range('A', 'K') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $writer = PHPExcel_IOFactory::createWriter($xls, 'Excel2007');
    $writer->save($save_path);
}

function file_to_db_url_attach_result_file($bo_table, $wr_id, $write_table, $source_path, $source_name)
{
    global $g5;

    $upload_dir = G5_DATA_PATH.'/file/'.$bo_table;
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, G5_DIR_PERMISSION, true);
    }

    $row = sql_fetch(" select max(bf_no) as max_bf_no from {$g5['board_file_table']} where bo_table = '".sql_escape_string($bo_table)."' and wr_id = '{$wr_id}' ");
    $bf_no = isset($row['max_bf_no']) ? ((int)$row['max_bf_no'] + 1) : 0;

    $safe_name = preg_replace("/\.(php|pht|phtm|htm|cgi|pl|exe|jsp|asp|inc|phar)/i", "$0-x", $source_name);
    $safe_name = function_exists('replace_filename') ? replace_filename($safe_name) : preg_replace('/[^A-Za-z0-9_.-]+/', '_', $safe_name);
    $stored_name = md5(sha1($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')).'_'.substr(md5(uniqid('', true)), 0, 8).'_'.$safe_name;
    $dest_path = $upload_dir.'/'.$stored_name;

    if (!@copy($source_path, $dest_path)) {
        throw new RuntimeException('결과 엑셀 첨부파일 저장 실패');
    }
    @chmod($dest_path, G5_FILE_PERMISSION);

    $filesize = filesize($dest_path);
    $sql = " insert into {$g5['board_file_table']}
                set bo_table = '".sql_escape_string($bo_table)."',
                    wr_id = '{$wr_id}',
                    bf_no = '{$bf_no}',
                    bf_source = '".sql_escape_string($source_name)."',
                    bf_file = '".sql_escape_string($stored_name)."',
                    bf_content = 'DB손보 URL 전송 결과',
                    bf_fileurl = '',
                    bf_thumburl = '',
                    bf_storage = '',
                    bf_download = 0,
                    bf_filesize = '".(int)$filesize."',
                    bf_width = '0',
                    bf_height = '0',
                    bf_type = '0',
                    bf_datetime = '".G5_TIME_YMDHIS."' ";
    sql_query($sql);

    $count = sql_fetch(" select count(*) as cnt from {$g5['board_file_table']} where bo_table = '".sql_escape_string($bo_table)."' and wr_id = '{$wr_id}' ");
    sql_query(" update {$write_table} set wr_file = '".(int)$count['cnt']."' where wr_id = '{$wr_id}' ");

    return $source_name;
}

file_to_db_url_debug('전송 액션 시작');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    file_to_db_url_fail('POST 요청이 아닙니다.', '', 0, $bo_table, $debug_title);
}

if ($bo_table === '' || $wr_id < 1) {
    file_to_db_url_fail('게시물 정보가 올바르지 않습니다.', '', 0, $bo_table, $debug_title);
}

$debug_title = '['.$bo_table.' #'.$wr_id.'] '.($FILE_TO_DB_URL_DEBUG ? 'DB손보 URL 전송 디버그' : 'DB손보 URL 전송 결과');
$write_table = $g5['write_prefix'].$bo_table;
$file_no = isset($_POST['file_no']) ? (int)$_POST['file_no'] : -1;
$send_token = (string)($_POST['send_token'] ?? '');
$session_token_name = 'ss_file_to_db_url_send_'.$bo_table.'_'.$wr_id;
$session_token = (string)get_session($session_token_name);
set_session($session_token_name, '');

if ($session_token === '' || $send_token === '' || !hash_equals($session_token, $send_token)) {
    file_to_db_url_fail('전송 토큰이 일치하지 않습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

if (!get_session('ss_view_'.$bo_table.'_'.$wr_id)) {
    file_to_db_url_fail('게시물 보기 세션이 없습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

$board = sql_fetch(" select * from {$g5['board_table']} where bo_table = '".sql_escape_string($bo_table)."' ");
if (empty($board['bo_table'])) {
    file_to_db_url_fail('게시판 정보를 찾을 수 없습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

$write = get_write($write_table, $wr_id);
if (empty($write['wr_id'])) {
    file_to_db_url_fail('게시물을 찾을 수 없습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

$excel_file = file_to_db_url_find_excel_file($bo_table, $wr_id, $file_no);
if (!$excel_file) {
    file_to_db_url_fail('전송할 엑셀 첨부파일을 찾지 못했습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

$excel_path = G5_DATA_PATH.'/file/'.$bo_table.'/'.$excel_file['bf_file'];
if (!is_file($excel_path)) {
    file_to_db_url_fail('첨부파일이 서버에 존재하지 않습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

try {
    $sheet = file_to_db_url_load_sheet($excel_path);
} catch (Exception $e) {
    file_to_db_url_fail('엑셀 로드 실패 - '.$e->getMessage(), $write_table, $wr_id, $bo_table, $debug_title);
}

$highest_row = $sheet->getHighestRow();
$highest_column = $sheet->getHighestColumn();
$first_row_data = $sheet->rangeToArray('A1:'.$highest_column.'1', null, false, false);
$headers = isset($first_row_data[0]) && is_array($first_row_data[0]) ? array_map('file_to_db_url_clean_field', $first_row_data[0]) : array();

$idx_personal_id = file_to_db_url_find_header_index($headers, array('주민등록번호', '주민번호', 'personalid', 'personal_id'));
$idx_name = file_to_db_url_find_header_index($headers, array('고객명', '이름', '성명', '성함', 'name'));
$idx_phone = file_to_db_url_find_header_index($headers, array('휴대폰', '휴대폰번호', '핸드폰', '핸드폰번호', '전화번호', '연락처', 'phone', 'mobile'));
$idx_memo = file_to_db_url_find_header_index($headers, array('고객메모', '메모', '상담메모', 'initmsg', 'init_msg', 'memo'));

if ($idx_personal_id === null && $idx_name === null && $idx_phone === null && $idx_memo === null) {
    $start_row = 1;
    $idx_personal_id = 0;
    $idx_name = 1;
    $idx_phone = 2;
    $idx_memo = 3;
} else {
    $start_row = 2;
}

if ($idx_personal_id === null || $idx_name === null || $idx_phone === null || $idx_memo === null) {
    file_to_db_url_fail('필수 헤더(주민등록번호/고객명/휴대폰/고객메모)를 찾지 못했습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

$result_rows = array();
$total_rows = 0;
$success_rows = 0;
$fail_rows = 0;

for ($row_no = $start_row; $row_no <= $highest_row; $row_no++) {
    $row_data = $sheet->rangeToArray('A'.$row_no.':'.$highest_column.$row_no, null, false, false);
    if (!isset($row_data[0]) || !is_array($row_data[0])) {
        continue;
    }

    $row = array_map('file_to_db_url_clean_field', $row_data[0]);
    if (implode('', $row) === '') {
        continue;
    }

    $send_row = array(
        'personal_id' => file_to_db_url_normalize_personal_id($row[$idx_personal_id] ?? ''),
        'name' => file_to_db_url_clean_field($row[$idx_name] ?? ''),
        'phone' => file_to_db_url_normalize_phone($row[$idx_phone] ?? ''),
        'memo' => file_to_db_url_clean_field($row[$idx_memo] ?? ''),
    );

    $total_rows++;
    $sent_at = date('Y-m-d H:i:s');

    if ($send_row['personal_id'] === '' || $send_row['name'] === '' || $send_row['phone'] === '') {
        $send_result = array(
            'success' => false,
            'error_cd' => 'FALSE',
            'msg' => '필수값 누락',
            'http_code' => '',
            'raw' => '',
        );
    } else {
        try {
            $send_result = file_to_db_url_send_row($send_row);
        } catch (Exception $e) {
            $send_result = array(
                'success' => false,
                'error_cd' => 'FALSE',
                'msg' => $e->getMessage(),
                'http_code' => '',
                'raw' => '',
            );
        }
    }

    if ($send_result['success']) {
        $success_rows++;
    } else {
        $fail_rows++;
    }

    $result_rows[] = array_merge($send_row, array(
        'row_no' => $row_no,
        'error_cd' => $send_result['error_cd'],
        'msg' => $send_result['msg'],
        'http_code' => $send_result['http_code'],
        'success' => $send_result['success'],
        'sent_at' => $sent_at,
        'raw' => $send_result['raw'],
    ));
}

if ($total_rows < 1) {
    file_to_db_url_fail('전송할 데이터가 없습니다.', $write_table, $wr_id, $bo_table, $debug_title);
}

$result_dir = G5_DATA_PATH.'/file_to_db_url_result';
if (!is_dir($result_dir)) {
    @mkdir($result_dir, G5_DIR_PERMISSION, true);
}

$result_source = FILE_TO_DB_URL_RESULT_PREFIX.$bo_table.'_'.$wr_id.'_'.date('Ymd_His').'.xlsx';
$result_path = $result_dir.'/'.$result_source;

try {
    file_to_db_url_create_result_excel($result_rows, $result_path);
    file_to_db_url_attach_result_file($bo_table, $wr_id, $write_table, $result_path, $result_source);
} catch (Exception $e) {
    file_to_db_url_fail('결과 엑셀 생성/첨부 실패 - '.$e->getMessage(), $write_table, $wr_id, $bo_table, $debug_title);
}

$result_message = '완료 - 총 '.$total_rows.'건 / 성공 '.$success_rows.'건 / 실패 '.$fail_rows.'건 / 결과파일 '.$result_source;
file_to_db_url_write_result($write_table, $wr_id, $result_message);
file_to_db_url_debug($result_message);

file_to_db_url_render($debug_title, $fail_rows === 0, $bo_table, $wr_id);
