<?php
// /adm/call/rec_bulk_download.php
// 현재 검색 조건에 해당하는 녹취파일을 S3에서 받아 ZIP 스트리밍 다운로드
require_once './_common.php';

// ─────────────────────────────
// 접근 권한
// ─────────────────────────────
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    http_response_code(403); exit('denied');
}

// CSRF 토큰 검증
// verify_token($_POST['token'] ?? '');   // 프로젝트 토큰 함수 사용. 없으면 아래 주석 블록 참고
/*
// 토큰 함수가 없을 경우 간이 검증:
$token_in = $_POST['token'] ?? '';
if (!$token_in || !check_token($token_in)) {
    http_response_code(403); exit('invalid token');
}
*/

// ─────────────────────────────
// 내 정보
// ─────────────────────────────
$mb_no         = (int)($member['mb_no'] ?? 0);
$mb_level      = (int)($member['mb_level'] ?? 0);
$my_group      = (int)($member['mb_group'] ?? 0);
$my_company_id = (int)($member['company_id'] ?? 0);
$member_table  = $g5['member_table'];

// ─────────────────────────────
// POST 파라미터
// ─────────────────────────────
$start_date = preg_replace('/[^0-9\-]/', '', $_POST['start'] ?? date('Y-m-d'));
$end_date   = preg_replace('/[^0-9\-]/', '', $_POST['end']   ?? date('Y-m-d'));
$q          = trim($_POST['q']      ?? '');
$q_type     = trim($_POST['q_type'] ?? '');
$f_status   = (int)($_POST['status']     ?? 0);

if ($mb_level >= 9) {
    $sel_company_id = (int)($_POST['company_id'] ?? 0);
} else {
    $sel_company_id = $my_company_id;
}
$sel_mb_group = ($mb_level >= 8) ? (int)($_POST['mb_group'] ?? 0) : $my_group;
$sel_agent_no = (int)($_POST['agent'] ?? 0);

// ─────────────────────────────
// WHERE 구성 (call_recordings.php 와 동일 로직)
// ─────────────────────────────
$where = [];

$start_esc = sql_escape_string($start_date . ' 00:00:00');
$end_esc   = sql_escape_string($end_date   . ' 23:59:59');
$where[]   = "r.created_at BETWEEN '{$start_esc}' AND '{$end_esc}'";

if ($f_status > 0) {
    $where[] = "l.call_status = {$f_status}";
}

if ($q !== '' && $q_type !== '') {
    if ($q_type === 'name') {
        $q_esc   = sql_escape_string($q);
        $where[] = "t.name LIKE '%{$q_esc}%'";
    } elseif ($q_type === 'last4') {
        $q4 = substr(preg_replace('/\D+/', '', $q), -4);
        if ($q4 !== '') {
            $where[] = "t.hp_last4 = '" . sql_escape_string($q4) . "'";
        }
    } elseif ($q_type === 'full') {
        $hp = preg_replace('/\D+/', '', $q);
        if ($hp !== '') {
            $where[] = "l.call_hp = '" . sql_escape_string($hp) . "'";
        }
    } elseif ($q_type === 'all') {
        $q_esc = sql_escape_string($q);
        $q4    = substr(preg_replace('/\D+/', '', $q), -4);
        $hp    = preg_replace('/\D+/', '', $q);
        $conds = ["t.name LIKE '%{$q_esc}%'"];
        if ($q4 !== '') $conds[] = "t.hp_last4 = '" . sql_escape_string($q4) . "'";
        if ($hp !== '') $conds[] = "l.call_hp = '"  . sql_escape_string($hp) . "'";
        $where[] = '(' . implode(' OR ', $conds) . ')';
    }
}

if ($mb_level == 7) {
    $where[] = "l.mb_group = {$my_group}";
} elseif ($mb_level < 7) {
    $where[] = "l.mb_no = {$mb_no}";
} else {
    if ($mb_level == 8) {
        $where[] = "m.company_id = {$my_company_id}";
    } elseif ($mb_level >= 9) {
        if ($sel_company_id > 0) {
            $where[] = "m.company_id = {$sel_company_id}";
        }
    }
    if ($sel_mb_group > 0) {
        $where[] = "l.mb_group = {$sel_mb_group}";
    }
}

if ($sel_agent_no > 0) {
    $where[] = "l.mb_no = {$sel_agent_no}";
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ─────────────────────────────
// 건수 사전 확인 (안전장치)
// ─────────────────────────────
$MAX_FILES = 3000; // 한 번에 허용할 최대 파일 수

$cnt_row = sql_fetch("
    SELECT COUNT(*) AS cnt
      FROM call_recording r
      JOIN call_log l
        ON l.call_id = r.call_id
       AND l.campaign_id = r.campaign_id
       AND l.mb_group = r.mb_group
      JOIN call_target t ON t.target_id = l.target_id
      JOIN call_campaign cc
        ON cc.campaign_id = r.campaign_id AND (cc.is_paid_db = 1 OR cc.mb_group = r.mb_group)
      LEFT JOIN {$member_table} m ON m.mb_no = l.mb_no
    {$where_sql}
");
$total = (int)($cnt_row['cnt'] ?? 0);

if ($total === 0) {
    http_response_code(404);
    exit('다운로드할 파일이 없습니다.');
}
if ($total > $MAX_FILES) {
    http_response_code(400);
    exit("파일 수({$total}건)가 최대 허용({$MAX_FILES}건)을 초과합니다. 기간을 줄여주세요.");
}

// ─────────────────────────────
// 파일 목록 조회
// ─────────────────────────────
$res = sql_query("
    SELECT
        r.recording_id,
        r.s3_bucket,
        r.s3_key,
        r.content_type,
        r.mb_group,
        l.mb_no     AS agent_id,
        l.call_start,
        l.call_hp,
        l.call_status,
        sc.is_after_call,
        t.name      AS target_name,
        m.mb_name   AS agent_name,
        cc.is_open_number
      FROM call_recording r
      JOIN call_log l
        ON l.call_id = r.call_id
       AND l.campaign_id = r.campaign_id
       AND l.mb_group = r.mb_group
      JOIN call_target t ON t.target_id = l.target_id
      JOIN call_campaign cc
        ON cc.campaign_id = r.campaign_id AND (cc.is_paid_db = 1 OR cc.mb_group = r.mb_group)
      LEFT JOIN {$member_table} m ON m.mb_no = l.mb_no
      LEFT JOIN call_status_code sc
        ON sc.call_status = l.call_status AND sc.mb_group = 0
    {$where_sql}
    ORDER BY r.created_at ASC, r.recording_id ASC
");

// ─────────────────────────────
// S3 클라이언트 초기화
// ─────────────────────────────
$vendor = G5_PATH . '/vendor/autoload.php';
if (!class_exists(\Aws\S3\S3Client::class) && file_exists($vendor)) {
    require_once $vendor;
}
if (!class_exists(\Aws\S3\S3Client::class)) {
    http_response_code(500);
    exit('AWS SDK를 찾을 수 없습니다.');
}

if (!defined('AWS_REGION')) define('AWS_REGION', getenv('AWS_REGION') ?: 'ap-northeast-2');

try {
    $s3 = new \Aws\S3\S3Client([
        'version' => 'latest',
        'region'  => AWS_REGION,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    exit('S3 클라이언트 초기화 실패: ' . $e->getMessage());
}

// ─────────────────────────────
// 헬퍼
// ─────────────────────────────

/**
 * ZIP 내부 파일명 생성
 * 패턴: YYYYMMDD_HHMMSS_상담원명_고객명_전화번호끝4자리.확장자
 * 번호 숨김 대상은 고객명/번호 자리를 마스킹
 */
function make_zip_entry_name(array $row, int $mb_level): string
{
    $dt  = date('Ymd_His', strtotime($row['call_start'] ?: 'now'));
    $agent = preg_replace('/[\/\\\:*?"<>|]/', '_', $row['agent_name'] ?: 'unknown');

    // 번호 노출 정책 적용
    $hide = ((int)$row['is_open_number'] === 0
          && (int)$row['is_after_call']  !== 1
          && $mb_level < 9);

    if ($hide) {
        $cname = '고객';
        $hp4   = 'XXXX';
    } else {
        $cname = preg_replace('/[\/\\\:*?"<>|]/', '_', $row['target_name'] ?: '고객');
        $raw4  = preg_replace('/\D/', '', $row['call_hp'] ?? '');
        $hp4   = $raw4 ? substr($raw4, -4) : '0000';
    }

    $ext = strtolower(pathinfo($row['s3_key'], PATHINFO_EXTENSION)) ?: 'mp3';

    return "{$dt}_{$agent}_{$cname}_{$hp4}.{$ext}";
}

// ─────────────────────────────
// ZipStream 라이브러리 확인 또는 폴백
// ─────────────────────────────
// 권장: composer require maennchen/zipstream-php
// 없으면 PHP 내장 ZipArchive + 임시파일 폴백 사용
$use_zipstream = class_exists('\ZipStream\ZipStream');

// ─────────────────────────────
// 실행 환경 조정
// ─────────────────────────────
@set_time_limit(0);
@ini_set('memory_limit', '256M');

// 출력 버퍼 비우기 (청크 스트리밍을 위해)
while (ob_get_level()) ob_end_clean();

// ─────────────────────────────
// ZIP 파일명
// ─────────────────────────────
$zip_filename = 'recordings_' . $start_date . '_' . $end_date . '.zip';
$zip_filename_ascii   = preg_replace('/[^A-Za-z0-9\.\-\_]/', '_', $zip_filename);
$zip_filename_encoded = rawurlencode($zip_filename);

// ─────────────────────────────
// 스트리밍 방식 (ZipStream)
// ─────────────────────────────
if ($use_zipstream) {

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename_ascii . '"; filename*=UTF-8\'\'' . $zip_filename_encoded);
    header('Cache-Control: no-cache, no-store');
    header('Pragma: no-cache');
    header('X-Accel-Buffering: no'); // nginx proxy 버퍼 비활성화

    $zip = new \ZipStream\ZipStream(
        outputName: $zip_filename,
        sendHttpHeaders: false,   // 헤더는 위에서 이미 전송
    );

    $seen_names = [];

    while ($row = sql_fetch_array($res)) {
        $entry_name = make_zip_entry_name($row, $mb_level);

        // 중복 파일명 처리
        if (isset($seen_names[$entry_name])) {
            $seen_names[$entry_name]++;
            $info = pathinfo($entry_name);
            $entry_name = $info['filename'] . '_' . $seen_names[$entry_name]
                        . (isset($info['extension']) ? '.' . $info['extension'] : '');
        } else {
            $seen_names[$entry_name] = 0;
        }

        try {
            // S3에서 직접 스트림으로 읽어 ZIP에 추가
            $result = $s3->getObject([
                'Bucket' => $row['s3_bucket'],
                'Key'    => $row['s3_key'],
            ]);
            /** @var \GuzzleHttp\Psr7\Stream $body */
            $body = $result['Body'];
            $zip->addFileFromPsr7Stream(
                fileName: $entry_name,
                stream:   $body,
            );
        } catch (\Throwable $e) {
            // 개별 파일 오류: 오류 로그만 남기고 계속 진행
            // error_log('rec_bulk_download S3 error: rid='.$row['recording_id'].' '.$e->getMessage());
            // 빈 오류 안내 파일을 ZIP에 넣어 누락 사실을 명시
            $zip->addFile(
                fileName: $entry_name . '.ERROR.txt',
                data:     'S3 다운로드 실패: ' . $e->getMessage(),
            );
        }
    }

    $zip->finish();
    exit;
}

// ─────────────────────────────
// 폴백: ZipArchive + 임시파일
// ─────────────────────────────
$tmp_zip  = tempnam(sys_get_temp_dir(), 'rec_zip_');
$zip_arch = new ZipArchive();

if ($zip_arch->open($tmp_zip, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('ZIP 파일 생성 실패');
}

$seen_names = [];

while ($row = sql_fetch_array($res)) {
    $entry_name = make_zip_entry_name($row, $mb_level);

    if (isset($seen_names[$entry_name])) {
        $seen_names[$entry_name]++;
        $info = pathinfo($entry_name);
        $entry_name = $info['filename'] . '_' . $seen_names[$entry_name]
                    . (isset($info['extension']) ? '.' . $info['extension'] : '');
    } else {
        $seen_names[$entry_name] = 0;
    }

    try {
        $result  = $s3->getObject([
            'Bucket' => $row['s3_bucket'],
            'Key'    => $row['s3_key'],
        ]);
        $content = (string)$result['Body'];
        $zip_arch->addFromString($entry_name, $content);
    } catch (\Throwable $e) {
        $zip_arch->addFromString($entry_name . '.ERROR.txt', 'S3 다운로드 실패: ' . $e->getMessage());
    }
}

$zip_arch->close();

// 임시파일을 응답으로 전송
$fsize = filesize($tmp_zip);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_filename_ascii . '"; filename*=UTF-8\'\'' . $zip_filename_encoded);
header('Content-Length: ' . $fsize);
header('Cache-Control: no-cache, no-store');
header('Pragma: no-cache');

readfile($tmp_zip);
unlink($tmp_zip);
exit;