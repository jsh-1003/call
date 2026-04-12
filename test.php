<?php
include_once('./_common.php');
exit;

function call_assign_paid_db_pick_and_lock($mb_group, $mb_no, $need, $lease_min, $batch_id, $opts = [], $campaign_id = 0)
{
    // 0) 유료DB 사용 멤버 아니면 스킵
    $paid_use = (int)is_paid_db_use_member($mb_no);
    if ($paid_use < 1) {
        return null; // 호출부에서 기존 로직 진행
    }

    // 옵션
    $assigned_status_to     = isset($opts['assigned_status_to']) ? (int)$opts['assigned_status_to'] : 1;  // 배정(통화전)
    $assigned_status_filter = isset($opts['assigned_status_filter']) ? (int)$opts['assigned_status_filter'] : 0; // 미배정
    $where_extra            = isset($opts['where_extra']) ? trim($opts['where_extra']) : '';
    $exclude_blacklist      = array_key_exists('exclude_blacklist', $opts) ? (bool)$opts['exclude_blacklist'] : true;
    $exclude_dnc_flag       = array_key_exists('exclude_dnc_flag', $opts) ? (bool)$opts['exclude_dnc_flag'] : false;

    // 동시 재시도 폭주 방지(선택): 동일 mb_no에 대한 유료픽을 1개만 허용
    $use_user_lock = array_key_exists('use_user_lock', $opts) ? (bool)$opts['use_user_lock'] : true;
    $lock_wait_sec = isset($opts['lock_wait_sec']) ? (int)$opts['lock_wait_sec'] : 1;

    // 안전 캐스팅
    $mb_group   = (int)$mb_group;
    $mb_no      = (int)$mb_no;
    $n          = max(0, (int)$need);
    $lease_min  = max(1, (int)$lease_min);
    $batch_id   = (int)$batch_id;
    $campaign_id = (int)$campaign_id;

    if ($n <= 0) {
        return ['ok'=>true, 'picked'=>0, 'ids'=>[], 'err'=>null];
    }

    // 유료DB 과금 타입
    $paid_db_billing_type = (int)get_paid_db_billing_type($mb_no);

    // 블랙리스트 회사 기준: 기존과 동일하게 "그룹->회사" 우선
    $company_id = (int)get_company_id_from_group_id_cached($mb_group);

    // 블랙리스트 적용 범위: 공용(1) + 회사별
    $guard_company_ids = [];
    if ($exclude_blacklist) {
        $guard_company_ids = [1, $company_id];
        $guard_company_ids = array_values(array_unique(array_map('intval', $guard_company_ids)));
    }

    $where_campaign_company = '';
    $campaign_company_table = get_paid_campaign_company_table();
    $where_campaign_company = "
                AND (
                    NOT EXISTS (
                        SELECT 1
                            FROM {$campaign_company_table} pct_any
                            WHERE pct_any.campaign_id = c.campaign_id
                    )
                    OR EXISTS (
                        SELECT 1
                            FROM {$campaign_company_table} pct_me
                            WHERE pct_me.campaign_id = c.campaign_id
                            AND pct_me.company_id = {$company_id}
                    )
                )";


    // 후보를 너무 많이 뽑지 않기 (스캔/부하 제한)
    $candidate_limit = isset($opts['candidate_limit']) ? max(10, (int)$opts['candidate_limit']) : min(100, max(30, $n * 30));

    // 무한루프 방지
    $max_round = isset($opts['max_round']) ? max(20, (int)$opts['max_round']) : 30;

    // rand_score가 0~1이라고 가정하고 랜덤 시드로 핫스팟(항상 최소값 경쟁) 완화
    $seed = isset($opts['seed']) ? (float)$opts['seed'] : (mt_rand() / mt_getrandmax());
    if ($seed < 0) $seed = 0;
    if ($seed > 1) $seed = 1;

    // advisory lock(선택)
    $lock_name = '';
    if ($use_user_lock) {
        $lock_name = "paid_pick_user_{$mb_no}";
        $lrow = sql_fetch("SELECT GET_LOCK('{$lock_name}', {$lock_wait_sec}) AS got");
        if ((int)($lrow['got'] ?? 0) !== 1) {
            // 바쁘면 빠르게 실패(호출부에서 기존 로직/재시도 정책 적용)
            return ['ok'=>false, 'picked'=>0, 'ids'=>[], 'err'=>'paid_pick busy'];
        }
    }

    $picked_ids = [];

    try {

        for ($round = 0; $round < $max_round && count($picked_ids) < $n; $round++) {

            // 1) 후보 목록 조회 (락 없음)
            //    - idx_target_paid_pick 인덱스 (is_paid_db, assigned_status, rand_score, target_id, campaign_id)
            $join_blacklist = '';
            $where_blacklist = '';
            if (!empty($guard_company_ids)) {
                $in_guard = implode(',', $guard_company_ids);
                $join_blacklist = "LEFT JOIN call_blacklist b
                                     ON b.call_hp = t.call_hp
                                    AND b.company_id IN ({$in_guard})";
                $where_blacklist = "\n  AND b.blacklist_id IS NULL";
            }

            $where_campaign = '';
            if ($campaign_id > 0) {
                $where_campaign = "\n  AND t.campaign_id = {$campaign_id}";
            }

            // 랜덤 시드 기반 1차 범위
            $cand_sql_1 = "SELECT t.target_id, c.paid_db_name
                  FROM call_target AS t FORCE INDEX (idx_target_paid_pick)
                  STRAIGHT_JOIN call_campaign AS c
                    ON c.campaign_id = t.campaign_id
                   AND c.status = 1
                   AND c.deleted_at IS NULL
                   AND c.is_paid_db = 1
                  JOIN g5_member AS ag
                    ON ag.mb_no = c.db_agency
                   AND ag.is_paid_db = 1
                  JOIN g5_member AS vd
                    ON vd.mb_no = c.db_vendor
                   AND vd.is_paid_db = 1
                  {$join_blacklist}
                 WHERE t.is_paid_db = 1
                   AND t.assigned_status = {$assigned_status_filter}
                   AND t.rand_score >= {$seed}
                   {$where_campaign}
                   {$where_campaign_company}
                   {$where_blacklist}
                 ORDER BY t.rand_score ASC, t.target_id ASC
                 LIMIT {$candidate_limit}
            ";

            $cands = [];
            $q = sql_query($cand_sql_1);
            // echo $cand_sql_1.'<br>';
            while ($r = sql_fetch_array($q)) {
                $cands[] = $r;
            }
            print_r2($cands);

            // 부족하면 랩어라운드(0~seed) 구간도 추가
            if (count($cands) < $candidate_limit) {
                echo '부족쓰';
                $need_more = $candidate_limit - count($cands);
                $cand_sql_2 = "SELECT t.target_id
                      FROM call_target AS t FORCE INDEX (idx_target_paid_pick)
                      STRAIGHT_JOIN call_campaign AS c
                        ON c.campaign_id = t.campaign_id
                       AND c.status = 1
                       AND c.deleted_at IS NULL
                       AND c.is_paid_db = 1
                      JOIN g5_member AS ag
                        ON ag.mb_no = c.db_agency
                       AND ag.is_paid_db = 1
                      JOIN g5_member AS vd
                        ON vd.mb_no = c.db_vendor
                       AND vd.is_paid_db = 1
                      {$join_blacklist}
                     WHERE t.is_paid_db = 1
                       AND t.assigned_status = {$assigned_status_filter}
                       AND t.rand_score < {$seed}
                       {$where_campaign}
                       {$where_campaign_company}
                       {$where_blacklist}
                     ORDER BY t.rand_score ASC, t.target_id ASC
                     LIMIT {$need_more}
                ";
                $q2 = sql_query($cand_sql_2);
                while ($r2 = sql_fetch_array($q2)) {
                    $cands[] = (int)$r2['target_id'];
                }
            }

            if (empty($cands)) {
                break; // 후보 없음
            }

            // 2) 후보를 PK 조건부 UPDATE로 선점 (락 최소화)
            foreach ($cands as $tid) {
                if (count($picked_ids) >= $n) break;

                $tid = (int)$tid;
                if ($tid <= 0) continue;

                // 트랜잭션은 "성공 가능성 있는 1건"에 대해서만 아주 짧게
                // sql_query("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
                // sql_query("START TRANSACTION");

                // $upd_sql = "UPDATE call_target
                //        SET assigned_status      = {$assigned_status_to},
                //            assigned_mb_no       = {$mb_no},
                //            assigned_at          = NOW(),
                //            assign_lease_until   = DATE_ADD(NOW(), INTERVAL {$lease_min} MINUTE),
                //            assign_batch_id      = {$batch_id},
                //            company_id           = {$company_id},
                //            mb_group             = {$mb_group},
                //            paid_db_billing_type = {$paid_db_billing_type}
                //      WHERE target_id = {$tid}
                //        AND is_paid_db = 1
                //        AND assigned_status = {$assigned_status_filter}
                //      LIMIT 1
                // ";
                // sql_query($upd_sql);
                // $aff = function_exists('sql_affected_rows') ? (int)sql_affected_rows() : 0;

                // if ($aff !== 1) {
                //     sql_query("ROLLBACK");
                //     continue; // 다른 세션이 먼저 가져감(정상)
                // }

                // // 배정 이력
                // $ins_hist_sql = "INSERT INTO call_assignment (campaign_id, mb_group, target_id, mb_no, assigned_at, status)
                //     SELECT t.campaign_id, {$mb_group}, t.target_id, {$mb_no}, NOW(), 1
                //       FROM call_target t
                //      WHERE t.target_id = {$tid}
                //      LIMIT 1
                // ";
                // sql_query($ins_hist_sql);

                // sql_query("COMMIT");

                $picked_ids[] = $tid;
            }

            // 다음 라운드는 시드 새로 줘서 쏠림 완화
            $seed = (mt_rand() / mt_getrandmax());
        }

        if (empty($picked_ids)) {
            return ['ok'=>true, 'picked'=>0, 'ids'=>[], 'err'=>null];
        }

        return ['ok'=>true, 'picked'=>count($picked_ids), 'ids'=>$picked_ids, 'err'=>null];

    } catch (Exception $e) {
        //@sql_query("ROLLBACK");
        return ['ok'=>false, 'picked'=>0, 'ids'=>[], 'err'=>$e->getMessage()];
    } finally {
        if ($use_user_lock && $lock_name !== '') {
            //@sql_query("SELECT RELEASE_LOCK('{$lock_name}')");
        }
    }
}


$mb_group = 620;
$mb_no = 621;
$need = 1;
$lease_min = 90;
$batch_id = 1;
$res = call_assign_paid_db_pick_and_lock($mb_group, $mb_no, $need, $lease_min, $batch_id, $opts = [], $campaign_id = 0);
var_dump($res);