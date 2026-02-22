<?php
require_once './_common.php';
/*
 * 목적: 비공개 캠페인(is_open_number=0) 중 녹취 15초 이상 통화 내역을
 *       CSV로 "스트리밍 다운로드" (36만 행 이상 대응)
 *
 * 사용 예:
 * /adm/call/export_private_15sec.php?mb_group=80&date_from=2026-01-01&date_to=2026-02-22
 * /adm/call/export_private_15sec.php?mb_group=80&campaign_id=123
 */

// -----------------------------
// 권한(필요에 맞게 조정)
// -----------------------------
if ($is_admin !== 'super' && (int)$member['mb_level'] < 10) {
    alert('접근 권한이 없습니다.');
}

@set_time_limit(0);
@ignore_user_abort(true);

// (선택) PHP 버퍼/압축 끄기: 대용량 스트리밍 안정화
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_flush(); }
@ob_implicit_flush(true);

// -----------------------------
// 쿼리 (녹취에서 먼저 거르는 형태가 보통 유리)
// -----------------------------
$sql = "SELECT
    -- l.call_id,
    -- l.mb_group,
    -- l.campaign_id,
    c.name AS campaign_name,

    t.name AS target_name,
    t.birth_date,
    CASE
        WHEN t.sex = 1 THEN '남'
        WHEN t.sex = 2 THEN '여'
        ELSE ''
    END AS sex,

    /* meta_json: 값만 뽑아서 콤마 연결 (키 제거, 순서 유지: JSON_EXTRACT 결과 배열 순서) */
    (
      SELECT GROUP_CONCAT(j.val SEPARATOR ',')
      FROM JSON_TABLE(
             JSON_EXTRACT(t.meta_json, '$.*'),
             '$[*]' COLUMNS (
               val VARCHAR(2000) PATH '$'
             )
           ) AS j
    ) AS etc_info,

    /* 전화번호 하이픈 */
    CONCAT(
        SUBSTRING(l.call_hp, 1, 3), '-',
        SUBSTRING(l.call_hp, 4, 4), '-',
        SUBSTRING(l.call_hp, 8)
    ) AS call_hp,

    r.duration_sec,
    l.call_start

FROM call_recording r
JOIN call_log l
  ON l.call_id     = r.call_id
 AND l.campaign_id = r.campaign_id
 AND l.mb_group    = r.mb_group
JOIN call_campaign c
  ON c.campaign_id = l.campaign_id
 AND c.mb_group    = l.mb_group
JOIN call_target t
  ON t.target_id   = l.target_id
 AND t.campaign_id = l.campaign_id
 AND t.mb_group    = l.mb_group

WHERE
    c.is_open_number = 0
    AND r.duration_sec >= 15

ORDER BY l.call_start DESC;
";

// -----------------------------
// 다운로드 헤더
// -----------------------------
$filename = 'private_15sec_calls.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM (엑셀 호환용)
echo "\xEF\xBB\xBF";

// CSV 출력 핸들
$out = fopen('php://output', 'w');
if (!$out) {
    die('Cannot open output stream');
}

// 컬럼 헤더
// fputcsv($out, ['call_id','mb_group','campaign_id','campaign_name','call_hp','duration_sec','call_start']);
fputcsv($out, ['캠페인명','이름','전화번호','생년월일','성별','기타정보','통화시간','통화일시']);

// -----------------------------
// unbuffered query로 한 줄씩 스트리밍
// -----------------------------
// 그누보드 DB 커넥션: 보통 $g5['connect_db'] 또는 $link 가 존재
// 환경마다 다를 수 있어 안전하게 처리

$res = sql_query($sql);
while ($row = sql_fetch_array($res)) {
    fputcsv($out, [
        $row['campaign_name'],
        $row['target_name'],
        $row['call_hp'],
        $row['birth_date'],
        $row['sex'],
        $row['etc_info'],
        $row['duration_sec'],
        $row['call_start'],
    ]);
}
fclose($out);
exit;
