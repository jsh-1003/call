<?php
include_once('./_common.php');
include_once('./db_url.lib.php');

if (!$is_admin_pay) {
    alert('접근 권한이 없습니다.');
}

$row = file_to_db_url_get_test_row();
$do_send = isset($_GET['send']) && $_GET['send'] === '1';
$url = '';
$result = null;
$error = '';

try {
    $url = file_to_db_url_build_request_url($row);
    if ($do_send) {
        $result = file_to_db_url_send_row($row);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>DB손보 테스트 전송</title>';
echo '<style>body{margin:0;padding:24px;font-family:Arial,sans-serif;background:#f5f7fb;color:#111827}.wrap{max-width:980px;margin:0 auto;background:#fff;border:1px solid #dbe3ef;border-radius:12px;padding:24px}h1{margin:0 0 18px}.box{padding:14px;border:1px solid #e5e7eb;background:#f9fafb;white-space:pre-wrap;word-break:break-all}.btn{display:inline-block;margin-top:16px;padding:12px 18px;border-radius:8px;background:#111827;color:#fff;text-decoration:none}</style>';
echo '</head><body><div class="wrap">';
echo '<h1>DB손보 테스트 전송</h1>';
echo '<p><strong>테스트 데이터</strong>: 0001013 / 홍길동 / 01100000000 / 99991231일 상담희망</p>';
if ($error !== '') {
    echo '<div class="box">오류: '.htmlspecialchars($error, ENT_QUOTES, 'UTF-8').'</div>';
} else {
    echo '<div class="box">'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'</div>';
    if ($result) {
        echo '<h2>전송 결과</h2>';
        echo '<div class="box">HTTP: '.(int)$result['http_code']."\nERROR_CD: ".htmlspecialchars($result['error_cd'], ENT_QUOTES, 'UTF-8')."\nMSG: ".htmlspecialchars($result['msg'], ENT_QUOTES, 'UTF-8')."\nRAW: ".htmlspecialchars($result['raw'], ENT_QUOTES, 'UTF-8').'</div>';
    }
    echo '<a class="btn" href="?send=1">테스트 데이터 실제 전송</a>';
}
echo '</div></body></html>';
