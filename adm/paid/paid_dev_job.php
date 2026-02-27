<?php
exit;

$sub_menu = '200750';
require_once './_common.php';
/* -----------------------------------------------------------
 * 0) 접근권한:
 *  - $is_admin_pay 전용(관리자)
 *  - 에이전시(member_type=1)
 *  - 매체사(member_type=2)
 *  - 사용자 대표(member_type=0 && mb_level=8)
 * --------------------------------------------------------- */
$member_table = $g5['member_table']; // g5_member

$mb_no         = (int)($member['mb_no'] ?? 0);
$mb_level      = (int)($member['mb_level'] ?? 0);
$my_group      = (int)($member['mb_group'] ?? 0);
$my_company_id = (int)($member['company_id'] ?? 0);
$my_type       = (int)($member['member_type'] ?? 0);

$is_admin9      = (bool)$is_admin_pay || ($mb_level >= 9);
$is_agency      = ($my_type === 1); // 에이전시
$is_media       = ($my_type === 2); // 매체사
$is_company_rep = ($my_type === 0 && $mb_level === 8); // 사용자 대표

if (!$is_admin9 && !$is_agency && !$is_media && !$is_company_rep) {
    alert('접근 권한이 없습니다.');
}

// $batch_id = 0;
// if(!$batch_id) {
//     die('대상없음');
// }

// ===== 유료DB 캠페인 생성: name='유료DB' 고정, 기존 name은 paid_db_name =====
function create_paid_campaign_from_filename($orig_name, $memo, $db_agency, $db_vendor) {
    $paid_db_name = $orig_name;

    $sql = "INSERT INTO call_campaign
            (db_agency, db_vendor, is_paid_db, mb_group, name, paid_db_name, campaign_memo, is_open_number, status, created_at, updated_at)
        VALUES
            ('".(int)$db_agency."',
             '".(int)$db_vendor."',
             1,
             0,
             '유료DB',
             '".sql_escape_string($paid_db_name)."',
             '".sql_escape_string(k_nfc($memo))."',
             0,
             1,
             NOW(),
             NOW()
            )
    ";
    sql_query($sql);
    return sql_insert_id();
}


$campaign_id = 1129;
$batch_id = 20260225;
$n = 3; // 몇 등분
$db_agency = 563; // company_id
$db_vendor = 564; // mb_group
$orig_name = 'DB_260225'; // 캠페인명

/**
 * 업데이트는 mb_group 조건 없이 처리
 * 해당 캠페인에 이미 있는 전화번호는 0으로 처리 한다.
 */
$sql = "UPDATE add_mode_call_stg_target_upload s
    JOIN call_target t
        ON t.call_hp = s.call_hp
        AND t.campaign_id = 1129
    SET s.is_ok_data = 0,
        s.error_msg = '기존 캠페인({$campaign_id})에 이미 존재하는 전화번호'
    WHERE s.batch_id = {$batch_id}
        AND s.is_ok_data = 1";
// sql_query($sql);


/**
 * 삭제는 이미 배정된건은 제외 하고 진행
 * 카운트 / 삭제
 */
$sql = "SELECT COUNT(*) AS will_delete
    FROM call_target t
    LEFT JOIN add_mode_call_stg_target_upload s
        ON s.batch_id = {$batch_id}
        AND s.call_hp  = t.call_hp
    WHERE t.mb_group = 0
        AND t.campaign_id = {$campaign_id}
        AND s.call_hp IS NULL";
$sql = "DELETE t
FROM call_target t
LEFT JOIN add_mode_call_stg_target_upload s
  ON s.batch_id = {$batch_id}
 AND s.call_hp  = t.call_hp
WHERE t.mb_group = 0
  AND t.campaign_id = {$campaign_id}
  AND s.call_hp IS NULL;";
// sql_query($sql);


/**
 * 3등분
 */
$sql = "WITH ranked AS (
    SELECT
        upload_id,
        NTILE({$n}) OVER (ORDER BY rand_score ASC) AS grp
    FROM add_mode_call_stg_target_upload
    WHERE batch_id = {$batch_id}
      AND is_ok_data = 1
)
UPDATE add_mode_call_stg_target_upload s
JOIN ranked r ON r.upload_id = s.upload_id
SET s.is_ok_data = r.grp;";
// sql_query($sql);
$sql = '';

// 캠페인 만들고 실제 삽입
for($i==1; $i<=$n+1; $i++) {
    $memo = $i.'차';
    $campaign_id = create_paid_campaign_from_filename($orig_name, $memo, $db_agency, $db_vendor);
    echo $memo.' - '.$campaign_id.'<br>';
    $sql = "START TRANSACTION";
    sql_query($sql);
    // -- 1) call_target에 신규만 INSERT (중복은 제외)
    $in_sql = "INSERT INTO call_target
        (
        rand_score, campaign_id, company_id, mb_group,
        call_hp, name, birth_date, db_age_type, sex, meta_json,
        is_paid_db, created_at, updated_at
        )
        SELECT
        s.rand_score,
        {$campaign_id} AS campaign_id,
        0    AS company_id,
        0    AS mb_group,
        s.call_hp,
        s.name,
        s.birth_date,
        s.db_age_type,
        s.sex,
        s.meta_json,
        1    AS is_paid_db,
        NOW() AS created_at,
        NOW() AS updated_at
        FROM add_mode_call_stg_target_upload s
        LEFT JOIN call_target t
        ON t.campaign_id = {$campaign_id}
        AND t.call_hp     = s.call_hp
        WHERE s.batch_id   = {$batch_id}
        AND s.is_ok_data = {$i}
        AND t.target_id IS NULL";
    sql_query($in_sql);
    // -- 2) 실제로 call_target에 존재하게 된 건만 stg 처리완료 표시
    $up_sql = "UPDATE add_mode_call_stg_target_upload s
        JOIN call_target t
        ON t.campaign_id = {$campaign_id}
        AND t.call_hp     = s.call_hp
        SET s.processed_ok = 1,
            s.processed_at = NOW()
        WHERE s.batch_id   = {$batch_id}
        AND s.is_ok_data = {$i}";
    sql_query($up_sql);
    sql_query("COMMIT");
}

/*
캠페인 ID 교체
UPDATE call_target
SET campaign_id = 1161
WHERE mb_group = 0 AND campaign_id = 1129;
*/




