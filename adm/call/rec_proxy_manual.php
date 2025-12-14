<?php
// /adm/call/rec_proxy_manual.php
require_once './_common.php';

// 접근권한
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    http_response_code(403); exit('denied');
}

$mrid = (int)($_GET['mrid'] ?? 0); // manual_recording_id
if ($mrid <= 0) { http_response_code(400); exit('invalid'); }

$row = sql_fetch("
    SELECT
        mr.manual_recording_id,
        mr.mb_group,
        mr.manual_id,
        mr.s3_bucket,
        mr.s3_key,
        mr.content_type,
        ml.mb_no AS agent_id
    FROM call_manual_recording mr
    JOIN call_manual_log ml
      ON ml.manual_id = mr.manual_id
    WHERE mr.manual_recording_id = {$mrid}
    LIMIT 1
");
if (!$row) { http_response_code(404); exit('not found'); }

$mb_level = (int)$member['mb_level'];
$my_group = (int)($member['mb_group'] ?? 0);
if ($mb_level == 7 && (int)$row['mb_group'] !== $my_group) { http_response_code(403); exit('denied'); }
if ($mb_level < 7 && (int)$row['agent_id'] !== (int)$member['mb_no']) { http_response_code(403); exit('denied'); }

$force_download = (isset($_GET['dl']) && $_GET['dl']=='1');

// 파일명 추출 유틸 (RFC5987 + ASCII fallback)
function build_disposition_filename($s3_key){
    $base = basename($s3_key ?: 'recording');
    $fallback = preg_replace('/[^A-Za-z0-9\.\-\_]/', '_', $base);
    $utf8 = rawurlencode($base);
    return ['fallback'=>$fallback, 'utf8'=>$utf8];
}

// 간단 MIME 추정 (프로젝트 유틸이 있으면 교체)
function guess_audio_mime_simple($key, $ct_hint=null){
    if ($ct_hint) return $ct_hint;
    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'mp3': return 'audio/mpeg';
        case 'm4a': return 'audio/mp4';
        case 'wav': return 'audio/wav';
        case 'ogg': return 'audio/ogg';
        case 'aac': return 'audio/aac';
        default:    return 'application/octet-stream';
    }
}

$mime = guess_audio_mime_simple($row['s3_key'], $row['content_type'] ?? null);

// ── S3 Presigned URL (EC2 IAM Role)
$vendor = G5_PATH . '/vendor/autoload.php';
if (!class_exists(\Aws\S3\S3Client::class)) {
    if (file_exists($vendor)) require_once $vendor;
}
if (!class_exists(\Aws\S3\S3Client::class)) {
    // SDK 미설치 시 임시 공개 URL (운영에서는 반드시 SDK 사용)
    $public_url = 'https://'.$row['s3_bucket'].'.s3.amazonaws.com/'.rawurlencode($row['s3_key']);
    header('Location: '.$public_url, true, 302); exit;
}

try {
    if (!defined('AWS_REGION')) define('AWS_REGION', getenv('AWS_REGION') ?: 'ap-northeast-2');

    $s3 = new \Aws\S3\S3Client([
        'version' => 'latest',
        'region'  => AWS_REGION,
    ]);

    $EXPIRES = 120; // seconds

    // Content-Disposition 구성
    $fn = build_disposition_filename($row['s3_key']);
    $disp_type = $force_download ? 'attachment' : 'inline';
    $content_disposition = $disp_type
        . '; filename="' . $fn['fallback'] . '"'
        . "; filename*=UTF-8''" . $fn['utf8'];

    $cmd = $s3->getCommand('GetObject', [
        'Bucket' => $row['s3_bucket'],
        'Key'    => $row['s3_key'],
        'ResponseContentType'        => $mime,
        'ResponseContentDisposition' => $content_disposition,
    ]);

    $request = $s3->createPresignedRequest($cmd, "+{$EXPIRES} seconds");
    header('Location: '.(string)$request->getUri(), true, 302);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo 'presign failed';
    exit;
}
