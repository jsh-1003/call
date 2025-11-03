<?php
// /adm/call/rec_proxy.php
require_once './_common.php';

// 접근권한
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    http_response_code(403); exit('denied');
}

$rid = (int)($_GET['rid'] ?? 0);
if ($rid <= 0) { http_response_code(400); exit('invalid'); }

$row = sql_fetch("
    SELECT r.recording_id, r.mb_group, r.campaign_id, r.call_id,
           r.s3_bucket, r.s3_key, r.content_type,
           l.mb_no AS agent_id
      FROM call_recording r
      JOIN call_log l
        ON l.call_id=r.call_id AND l.campaign_id=r.campaign_id AND l.mb_group=r.mb_group
     WHERE r.recording_id={$rid}
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
    // ASCII fallback (IE/구형 호환)
    $fallback = preg_replace('/[^A-Za-z0-9\.\-\_]/', '_', $base);
    // RFC 5987 filename* (UTF-8 percent-encoding)
    // rawurlencode는 공백을 %20으로, 비ASCII는 %XX로 인코딩
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
    // dl=1 이면 attachment, 아니면 inline (브라우저에서 바로 재생)
    $disp_type = $force_download ? 'attachment' : 'inline';
    // RFC 5987 방식: filename (ASCII fallback) + filename* (UTF-8)
    $content_disposition = $disp_type
        . '; filename="' . $fn['fallback'] . '"'
        . "; filename*=UTF-8''" . $fn['utf8'];

    $cmd = $s3->getCommand('GetObject', [
        'Bucket' => $row['s3_bucket'],
        'Key'    => $row['s3_key'],
        'ResponseContentType'        => $mime,
        'ResponseContentDisposition' => $content_disposition,
        // (선택) 캐시 제어를 하고 싶다면 아래 주석 해제
        // 'ResponseCacheControl' => 'private, max-age=120',
    ]);

    $request = $s3->createPresignedRequest($cmd, "+{$EXPIRES} seconds");
    header('Location: '.(string)$request->getUri(), true, 302);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo 'presign failed';
    // error_log('rec_proxy presign error: '.$e->getMessage());
    exit;
}
