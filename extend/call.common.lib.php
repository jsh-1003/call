<?php
if (!defined('_GNUBOARD_')) exit; // 개별 페이지 접근 불가

/**
 * DB권역
 */
function get_db_area_from_area1($area1) {
    if(in_array($area1, ['서울','경기','인천']))
        return '수도권';
    if(in_array($area1, ['대전','충남']))
        return '충남권';
    if(in_array($area1, ['충북','세종']))
        return '충북권';
    if(in_array($area1, ['광주','전남']))
        return '전남권';
    if(in_array($area1, ['전북']))
        return '전북권';
    if(in_array($area1, ['대구','경북']))
        return '경북권';
    if(in_array($area1, ['부산','울산','경남']))
        return '경남권';
    if(in_array($area1, ['강원']))
        return '강원권';
    if(in_array($area1, ['제주']))
        return '제주권';
    return '-';
}
/**
 * DB타입
 */
function get_db_type_from_man_age(int $man_age) {
    if($man_age < 62)
        return '일반DB';
    return '실버DB';
}

// 프록시(사인 URL/스트리밍)
function make_recording_url($recording_id){ return './rec_proxy.php?rid='.(int)$recording_id; }

/**
 * 현재월 기준 회사/개인 잠금 여부 빠른판정 (APCu 60s 캐시)
 * true  = 사용 허용(언락)
 * false = 차단(락)
 */
function is_unlocked_fast(int $company_id, int $mb_no = 0): bool
{
    $month = date('Y-m');
    $ckey  = "lock:{$company_id}:{$mb_no}:{$month}";

    // 0) 캐시 히트 시 즉시 반환
    if (function_exists('apcu_fetch')) {
        $hit = apcu_fetch($ckey, $success);
        if ($success) return (bool)$hit;
    }

    // 1) 개인 기준: 스냅샷이 있으면 '무조건' 그 값을 우선 (관리자 강제잠금 포함)
    if ($mb_no > 0) {
        $snap = sql_fetch("
            SELECT locked
              FROM billing_member_snapshot
             WHERE company_id = {$company_id}
               AND month = '" . sql_escape_string($month) . "'
               AND mb_no = {$mb_no}
             LIMIT 1
        ");
        if ($snap) {
            $ok = ((int)$snap['locked'] === 1) ? false : true;
            if (function_exists('apcu_store')) apcu_store($ckey, $ok, 60);
            return $ok;
        }
        // 스냅샷이 없으면 회사 정책으로 폴백
    }

    // 2) 회사 월 정책: 결제완료 or 임시해제면 허용, 그 외 차단
    $row = sql_fetch("
        SELECT payment_status, manual_unlock
          FROM billing_company_month
         WHERE company_id = {$company_id}
           AND month = '" . sql_escape_string($month) . "'
         LIMIT 1
    ");

    $ok = false;
    if ($row && ($row['payment_status'] === 'paid' || (int)$row['manual_unlock'] === 1)) {
        $ok = true;
    } else {
        $ok = false;
    }

    if (function_exists('apcu_store')) apcu_store($ckey, $ok, 60);
    return $ok;
}

/**
 * 결제/잠금 검증 (회사/개인)
 *
 * @param int      $company_id 회사 ID
 * @param int|null $mb_no      회원 번호(옵션) - 주면 개인 스냅샷 기준으로 최종 판정
 * @return array{is_paid:bool, month:?string, paid_at:?string}
 *
 * 정책 요약:
 *  - 회사 단위: billing_company_month.payment_status='paid' 이거나 manual_unlock=1 이면 "사용 허용".
 *  - 개인 단위: billing_member_snapshot.locked 가 1이면 "차단", 0이면 "허용".
 *    (개인 스냅샷이 없을 경우 회사 단위 결과를 따른다)
 *  - 이 함수의 is_paid 는 "해당 월에 사용 가능 여부(unlocked)" 의미로 해석한다.
 */
function billing_is_company_paid($company_id, $mb_no = null) {
    $company_id = (int)$company_id;
    $mb_no      = (int)$mb_no;

    if ($company_id <= 0) {
        return ['is_paid' => false, 'month' => null, 'paid_at' => null];
    }

    // 이번 달 (서버가 KST라 가정)
    $month = date('Y-m');

    // 회사 월 정책 조회
    $row = sql_fetch("
        SELECT payment_status, paid_at, manual_unlock
          FROM billing_company_month
         WHERE company_id = {$company_id}
           AND month = '" . sql_escape_string($month) . "'
         LIMIT 1
    ");

    $paid_at = $row['paid_at'] ?? null;

    // 회사 단위 허용 여부(결제완료 or 임시해제)
    $company_unlocked = false;
    if ($row) {
        if ($row['payment_status'] === 'paid' || (int)$row['manual_unlock'] === 1) {
            $company_unlocked = true;
        }
    }

    // 기본값: 회사 단위 결과
    $is_paid = $company_unlocked;

    // 개인 단위 스냅샷 확인 (있으면 개인 스냅샷이 최종 우선)
    if ($mb_no > 0) {
        $snap = sql_fetch("
            SELECT locked
              FROM billing_member_snapshot
             WHERE company_id = {$company_id}
               AND month = '" . sql_escape_string($month) . "'
               AND mb_no = {$mb_no}
             LIMIT 1
        ");

        if ($snap) {
            // 스냅샷이 있으면 그 값이 최종
            $is_paid = ((int)$snap['locked'] === 1) ? false : true;
        }
        // 스냅샷이 없으면 회사 단위 결과를 그대로 사용
    }

    return [
        'is_paid' => $is_paid,
        'month'   => $month,
        'paid_at' => $paid_at
    ];
}

function get_company_info(int $mb_no) {
    $sql = "SELECT * FROM g5_member WHERE mb_no = '{$mb_no}' ";
    $row = sql_fetch($sql);
    if($row) {
        unset($row['mb_password']);
    }
    return $row;
}

/**
 * 접수db 상세 정보 불러오기
 */
function get_aftercall_db_info(int $target_id) {
    $return = array();
    
    $sql = "SELECT * FROM call_aftercall_db_info WHERE target_id = {$target_id} ";
    $row = sql_fetch($sql);
    $return['detail'] = $row;
    
    $sql = "SELECT * FROM call_target WHERE target_id = {$target_id} ";
    $row = sql_fetch($sql);
    $return['basic'] = $row;

    $sql = "SELECT r.recording_id, r.s3_key, r.content_type
        FROM call_recording as r 
        JOIN call_log as l ON l.call_id=r.call_id
        WHERE l.target_id = {$target_id} 
        ORDER BY l.call_id DESC";
    $row = sql_fetch($sql);
    $return['recording'] = $row;
    return $return;
}

/**
 * 블랙리스트 등록(회사 공통 적용)
 * - 입력: mb_group(등록 지점), call_hp(전화번호), 옵션배열
 *   옵션: company_id(없으면 지점→회사 맵핑), reason, memo, created_by(mb_no),
 *        update_on_dup(bool, 기본 false) — true면 reason/memo 갱신
 *
 * @return array [ ok, action(insert|update|skip), blacklist_id, company_id, error ]
 */
function blacklist_register(int $mb_group, string $call_hp, array $opt = []): array
{
    global $g5;

    $result = ['ok'=>false,'action'=>null,'blacklist_id'=>null,'company_id'=>null,'error'=>null];

    // 1) 전화번호 정규화/검증
    $hp = preg_replace('/\D+/', '', $call_hp);
    if (!preg_match('/^[0-9]{10,12}$/', $hp)) {
        $result['error'] = '전화번호는 숫자만 10~12자리여야 합니다.';
        return $result;
    }

    // 2) company_id 결정
    $company_id = isset($opt['company_id']) ? (int)$opt['company_id'] : 0;
    if ($company_id <= 0) {
        // 지점장(mb_level=7) 레코드에서 company_id 가져오기
        $sql = "SELECT company_id FROM {$g5['member_table']} 
                WHERE mb_no=".(int)$mb_group." AND mb_level=7 LIMIT 1";
        $row = sql_fetch($sql);
        $company_id = (int)($row['company_id'] ?? 0);
        if ($company_id <= 0) {
            $result['error'] = '회사 식별에 실패했습니다.';
            return $result;
        }
    }
    $result['company_id'] = $company_id;

    // 3) 기타 파라미터
    $reason      = isset($opt['reason']) ? trim((string)$opt['reason']) : '';
    $memo        = isset($opt['memo'])   ? trim((string)$opt['memo'])   : '';
    $created_by  = isset($opt['created_by']) ? (int)$opt['created_by'] : 0;
    $update_on_dup = !empty($opt['update_on_dup']);

    $reason_esc = sql_escape_string($reason);
    $memo_esc   = sql_escape_string($memo);
    $hp_esc     = sql_escape_string($hp);

    // 4) INSERT 처리 (경쟁조건 대비)
    if ($update_on_dup) {
        // 중복 시 reason/memo 덮어쓰기(공란이면 기존 유지)
        $sql = "INSERT INTO call_blacklist
                    (company_id, mb_group, call_hp, reason, memo, created_by, created_at)
                VALUES
                    ('{$company_id}', '".(int)$mb_group."', '{$hp_esc}', '{$reason_esc}', '{$memo_esc}', '".(int)$created_by."', NOW())
                ON DUPLICATE KEY UPDATE
                    reason = IF(VALUES(reason)='', reason, VALUES(reason)),
                    memo   = IF(VALUES(memo)  ='', memo,   VALUES(memo))";
        $ok = sql_query($sql, false);
        if (!$ok) {
            $result['error'] = 'DB 오류(INSERT/UPDATE)'; 
            return $result;
        }
        // 영향행 수로 insert/update 판정
        $aff = mysqli_affected_rows($g5['connect_db']);
        if ($aff === 1) $result['action'] = 'insert';
        elseif ($aff > 1) $result['action'] = 'update';
        else $result['action'] = 'skip'; // 드물게 0
    } else {
        // 중복 무시
        $sql = "INSERT IGNORE INTO call_blacklist
                    (company_id, mb_group, call_hp, reason, memo, created_by, created_at)
                VALUES
                    ('{$company_id}', '".(int)$mb_group."', '{$hp_esc}', '{$reason_esc}', '{$memo_esc}', '".(int)$created_by."', NOW())";
        $ok = sql_query($sql, false);
        if (!$ok) {
            $result['error'] = 'DB 오류(INSERT IGNORE)'; 
            return $result;
        }
        $aff = mysqli_affected_rows($g5['connect_db']);
        $result['action'] = ($aff === 1) ? 'insert' : 'skip';
    }

    // 5) blacklist_id 조회(공통)
    $row2 = sql_fetch("SELECT blacklist_id FROM call_blacklist 
                       WHERE company_id='{$company_id}' AND call_hp='{$hp_esc}' LIMIT 1");
    if (!empty($row2['blacklist_id'])) {
        $result['blacklist_id'] = (int)$row2['blacklist_id'];
    }

    $result['ok'] = true;
    return $result;
}

/**
 * 상태코드 기반 자동 블랙리스트 등록
 * - call_status_code에서 is_do_not_call=1 이면 등록
 * - 지점 커스터마이징(동일 status) 우선: (mb_group=요청지점) → (mb_group=0 공통)
 *
 * @return array [ triggered(bool), register(array|null), status_row(array|null) ]
 */
function blacklist_register_if_dnc(int $mb_group, string $call_hp, int $call_status, int $created_by, array $opt = []): array
{
    $out = ['triggered'=>false, 'register'=>null, 'status_row'=>null];

    // 상태코드 조회 (지점우선 → 공통)
    $q = "SELECT call_status, mb_group, name_ko, is_do_not_call
          FROM call_status_code
          WHERE call_status=".(int)$call_status." 
            AND (mb_group=".(int)$mb_group." OR mb_group=0)
          ORDER BY (mb_group=".(int)$mb_group.") DESC
          LIMIT 1";
    $row = sql_fetch($q);
    $out['status_row'] = $row ?: null;

    if (!$row || (int)$row['is_do_not_call'] !== 1) {
        return $out; // 트리거 아님
    }

    // 블랙리스트 등록 사유 기본값
    $reason = $opt['reason'] ?? ("상태코드 {$call_status} - ".(string)($row['name_ko'] ?? 'DNC'));
    $memo   = $opt['memo']   ?? 'API auto-blacklist';

    $reg = blacklist_register($mb_group, $call_hp, [
        'company_id'   => $opt['company_id'] ?? null, // 있으면 사용
        'reason'       => $reason,
        'memo'         => $memo,
        'created_by'   => $created_by,
        'update_on_dup'=> !empty($opt['update_on_dup']),
    ]);

    $out['triggered'] = true;
    $out['register']  = $reg;
    return $out;
}
/* 샘플 : 특정이벤트에서 직접 등록 
require_once __DIR__ . '/_lib_call_blacklist.php';
$r = blacklist_register($mb_group, $call_hp, [
    'reason'       => '사용자 요청 차단',
    'memo'         => '앱 내 신고',
    'created_by'   => $mb_no,
    'update_on_dup'=> true, // 기존이면 reason/memo 갱신
]);
// $r['action'] 으로 insert/update/skip 구분 가능
*/

function get_campaign_list($mb_group=0) {
    if(!$mb_group) {
        $where = ' WHERE status <> 9 ';
    } else {
        $where = " WHERE mb_group in ({$mb_group}) AND status <> 9 ";
    }
    $sql = "SELECT * from call_campaign {$where} ORDER BY campaign_id DESC";
    $res = sql_query($sql);
    $list = [];
    while ($row=sql_fetch_array($res)) {
        $list[$row['campaign_id']] = $row;
    }
    return $list;
}

function get_campaign_from_cached(int $campaign_id) {
    static $campaign = [];
    if(!empty($campaign[$campaign_id])) return $campaign[$campaign_id];
    $sql = "SELECT * from call_campaign where campaign_id = '{$campaign_id}' ";
    $row = sql_fetch($sql);
    if($row) {
        $campaign[$campaign_id] = $row;
    } else {
        $campaign[$campaign_id] = [];
    }
    return $campaign[$campaign_id];
}

function get_company_name_from_cached(int $company_id) {
    static $company_name = [];
    if(!empty($company_name[$company_id])) return $company_name[$company_id];
    $sql = "SELECT company_name from g5_member where mb_no = '{$company_id}' ";
    $row = sql_fetch($sql);
    if($row) {
        $company_name[$company_id] = $row['company_name'];
    } else {
        $company_name[$company_id] = '회사-???';
    }
    return $company_name[$company_id];
}

// 지점ID로 회사명 가져오기
function get_company_name_from_group_id_cached(int $group_id) {
    static $company_name = [];
    if(!empty($company_name[$group_id])) return $company_name[$group_id];
    $sql = "SELECT company_id, company_name from g5_member where mb_no = ( SELECT company_id FROM g5_member WHERE mb_no = '{$group_id}' ) ";
    $row = sql_fetch($sql);
    if($row) {
        $company_name[$group_id] = $row['company_name'];
    } else {
        $company_name[$group_id] = '회사-???';
    }
    return $company_name[$group_id];
}

// 지점ID로 회사ID 가져오기
function get_company_id_from_group_id_cached(int $group_id) {
    static $company_id = [];
    if(!empty($company_id[$group_id])) return $company_id[$group_id];
    $sql = "SELECT company_id FROM g5_member WHERE mb_no = '{$group_id}'";
    $row = sql_fetch($sql);
    if($row) {
        $company_id[$group_id] = $row['company_id'];
    } else {
        $company_id[$group_id] = null;
    }
    return $company_id[$group_id];
}

// 에이전트 드롭다운용 HTML 조각 준비 (지점 구분 포함)
function render_agent_options($agent_options, $sel_agent_no){
    if (empty($agent_options)) return '<option value="" disabled>담당자가 없습니다</option>';
    $html = '';
    $last_gid = null;
    foreach ($agent_options as $a) {
        if ($last_gid !== $a['mb_group']) {
            $html .= '<option value="" disabled class="opt-sep">── '.get_text($a['mb_group_name']).' ──</option>';
            $last_gid = $a['mb_group'];
        }
        $sel = ($sel_agent_no === (int)$a['mb_no']) ? ' selected' : '';
        $html .= '<option value="'.$a['mb_no'].'"'.$sel.'>'.get_text($a['mb_name']).'</option>';
    }
    return $html;
}

// 회사명 캐시 조회
function get_company_name_cached($company_id){
    static $cache = [];
    $cid = (int)$company_id;
    if ($cid <= 0) return '회사 미지정';
    if (isset($cache[$cid])) return $cache[$cid];

    global $g5;
    $row = sql_fetch("
        SELECT COALESCE(NULLIF(company_name,''), CONCAT('회사-', mb_no)) AS company_name
        FROM {$g5['member_table']}
        WHERE mb_no = '{$cid}' AND mb_level = 8
        LIMIT 1
    ");
    $cache[$cid] = ($row && $row['company_name']) ? $row['company_name'] : '회사-'.$cid;
    return $cache[$cid];
}

function get_call_config(int $mb_no) {
    static $cache = [];
    if(!empty($cache[$mb_no])) 
        return $cache[$mb_no];
    $sql = "SELECT company_id, mb_group FROM g5_member WHERE mb_no = {$mb_no} ";
    $row = sql_fetch($sql);
    if(!$row) {
        $company_id = 0;
        $mb_group = 0;
    } else {
        $company_id = $row['company_id'];
        $mb_group = $row['mb_group'];
    }
    $sql = "SELECT * FROM call_config 
        WHERE 
            company_id in (0, {$company_id})
            AND mb_group in (0, {$mb_group})
        ORDER BY
            mb_group desc, company_id desc
        LIMIT 1
    ";
    $row = sql_fetch($sql);
    $cache[$mb_no] = $row;
    return $row;
}
function get_member_name_cached(int $mb_no) {
    static $cache = [];
    $mb_no = (int)$mb_no;
    if ($mb_no <= 0) return '-';
    if (isset($cache[$mb_no])) return $cache[$mb_no];

    global $g5;
    $row = sql_fetch("
        SELECT mb_name AS nm
        FROM {$g5['member_table']}
        WHERE mb_no = '{$mb_no}'
        LIMIT 1
    ");
    $cache[$mb_no] = $row && $row['nm'] ? $row['nm'] : '지점-'.$mb_no;
    return $cache[$mb_no];
}
function get_group_name_cached($group_id) {
    static $cache = [];
    $gid = (int)$group_id;
    if ($gid <= 0) return '-';
    if (isset($cache[$gid])) return $cache[$gid];

    global $g5;
    $row = sql_fetch("
        SELECT COALESCE(NULLIF(mb_group_name,''), CONCAT('지점-', mb_no)) AS nm
        FROM {$g5['member_table']}
        WHERE mb_no = '{$gid}' AND mb_level = 7
        LIMIT 1
    ");
    $cache[$gid] = $row && $row['nm'] ? $row['nm'] : '지점-'.$gid;
    return $cache[$gid];
}
// 회사명 캐시 조회
function get_agent_name_cached($mb_no){
    static $cache = [];
    $cid = (int)$mb_no;
    if ($cid <= 0) return '회사 미지정';
    if (isset($cache[$cid])) return $cache[$cid];

    global $g5;
    $row = sql_fetch("
        SELECT mb_name
        FROM {$g5['member_table']}
        WHERE mb_no = '{$cid}'
        LIMIT 1
    ");
    $cache[$cid] = ($row && $row['mb_name']) ? $row['mb_name'] : '상담원-'.$cid;
    return $cache[$cid];
}

// 회사별 지점 수(레벨7 수)
function count_groups_by_company_cached($company_id) {
    static $cache = [];
    $cid = (int)$company_id;
    if ($cid <= 0) return 0;
    if (isset($cache[$cid])) return $cache[$cid];

    global $g5;
    $row = sql_fetch("
        SELECT COUNT(*) AS c
        FROM {$g5['member_table']}
        WHERE mb_level = 7 AND company_id = '{$cid}'
    ");
    $cache[$cid] = (int)($row['c'] ?? 0);
    return $cache[$cid];
}

// 지점별 상담원 수(레벨3, 차단/탈퇴 제외)
function count_members_by_group_cached($group_id) {
    static $cache = [];
    $gid = (int)$group_id;
    if ($gid <= 0) return 0;
    if (isset($cache[$gid])) return $cache[$gid];

    global $g5;
    $row = sql_fetch("
        SELECT COUNT(*) AS c
        FROM {$g5['member_table']}
        WHERE mb_level = 3
          AND mb_group = '{$gid}'
          AND IFNULL(mb_leave_date,'') = ''
          AND IFNULL(mb_intercept_date,'') = ''
    ");
    $cache[$gid] = (int)($row['c'] ?? 0);
    return $cache[$gid];
}

/**
 * 조직 셀렉트 옵션(회사/지점/상담사) 생성
 * - mb_level 은 global $member['mb_level'] 사용
 * - 반환: ['company_options'=>[], 'group_options'=>[], 'agent_options'=>[]]
 *
 * @param int      $sel_company_id 선택한 회사ID (9+만 의미, 나머지는 회사 고정)
 * @param int      $sel_mb_group   선택한 지점ID (0=전체)
 * @param int      $my_company_id  내 회사ID (8 이하 권한에서 고정 범위)
 * @param int      $my_group       내 지점ID (7 권한에서 고정 범위)
 * @param null|str $member_table   g5 member 테이블명 (null이면 $g5['member_table'])
 * @return array{company_options: array<int, array>, group_options: array<int, array>, agent_options: array<int, array>}
 */
function build_org_select_options($sel_company_id=0, $sel_mb_group=0) {
    global $member, $g5;

    $member_table   = $g5['member_table'];
    $mb_level       = (int)($member['mb_level'] ?? 0);
    $my_group       = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
    $my_company_id  = isset($member['company_id']) ? (int)$member['company_id'] : 0;

    /* --------------------------
       회사 옵션(9+)
       -------------------------- */
    $company_options = [];
    if ($mb_level >= 9) {
        $res = sql_query("
            SELECT m.mb_no AS company_id
              FROM {$member_table} m
             WHERE m.mb_level = 8
                AND IFNULL(mb_leave_date,'') = ''
                AND IFNULL(mb_intercept_date,'') = ''
             ORDER BY COALESCE(NULLIF(m.company_name,''), CONCAT('회사-', m.mb_no)) ASC, m.mb_no ASC
        ");
        while ($r = sql_fetch_array($res)) {
            $cid   = (int)$r['company_id'];
            $cname = get_company_name_cached($cid);
            $gcnt  = count_groups_by_company_cached($cid);
            $company_options[] = [
                'company_id'   => $cid,
                'company_name' => $cname,
                'group_count'  => $gcnt,
            ];
        }
    }

    /* --------------------------
       지점 옵션(8+)
       -------------------------- */
    $group_options = [];
    if ($mb_level >= 8) {
        $where_g = " WHERE m.mb_level = 7 ";
        if ($mb_level >= 9) {
            if ((int)$sel_company_id > 0) $where_g .= " AND m.company_id = '".(int)$sel_company_id."' ";
        } else {
            $where_g .= " AND m.company_id = '".(int)$my_company_id."' ";
            $where_g .= " AND IFNULL(m.mb_leave_date,'') = '' AND IFNULL(m.mb_intercept_date,'') = '' ";
        }
        $res = sql_query("SELECT m.mb_no AS mb_group, m.company_id FROM {$member_table} m {$where_g}
             ORDER BY m.company_id ASC,
                      COALESCE(NULLIF(m.mb_group_name,''), CONCAT('지점-', m.mb_no)) ASC,
                      m.mb_no ASC
        ");
        while ($r = sql_fetch_array($res)) {
            $gid   = (int)$r['mb_group'];
            $cid   = (int)$r['company_id'];
            $gname = get_group_name_cached($gid);
            $cname = get_company_name_cached($cid);
            $mcnt  = count_members_by_group_cached($gid);
            $group_options[] = [
                'mb_group'      => $gid,
                'company_id'    => $cid,
                'company_name'  => $cname,
                'mb_group_name' => $gname,
                'member_count'  => $mcnt,
            ];
        }
    }

    /* --------------------------
       상담사 옵션(회사/지점 필터 반영) — 상담원 레벨(3)만
       -------------------------- */
    $agent_options = [];
    $aw = [];
    if ($mb_level >= 8) {
        if ((int)$sel_mb_group > 0) {
            $aw[] = "mb_group = ".(int)$sel_mb_group;
        } else {
            if ($mb_level >= 9 && (int)$sel_company_id > 0) {
                $aw[] = "mb_group IN (SELECT mb_no FROM {$member_table} WHERE mb_level=7 AND company_id='".(int)$sel_company_id."')";
            } elseif ($mb_level == 8) {
                $aw[] = "mb_group IN (SELECT mb_no FROM {$member_table} WHERE mb_level=7 AND company_id='".(int)$my_company_id."')";
            } else {
                $aw[] = "mb_group > 0";
            }
        }
    } else { // 7
        $aw[] = "mb_group = ".(int)$my_group;
    }
    $aw[] = " mb_level = 3 AND IFNULL(mb_leave_date,'') = '' AND IFNULL(mb_intercept_date,'') = '' ";
    $aw_sql = 'WHERE '.implode(' AND ', $aw);

    $ar = sql_query("SELECT mb_no, mb_name, company_id, mb_group FROM {$member_table} {$aw_sql} ORDER BY company_id ASC, mb_group ASC, mb_name ASC, mb_no ASC");
    while ($r = sql_fetch_array($ar)) {
        $cid   = (int)$r['company_id'];
        $gid   = (int)$r['mb_group'];
        $cname = get_company_name_cached($cid);
        $gname = get_group_name_cached($gid);
        $mcnt  = count_members_by_group_cached($gid);
        $agent_options[] = [
            'mb_no'        => (int)$r['mb_no'],
            'mb_name'      => get_text($r['mb_name']),
            'company_id'    => $cid,
            'company_name'  => $cname,            
            'mb_group'     => $gid,
            'mb_group_name'=> $gname,
        ];
    }

    return [
        'company_options' => $company_options,
        'group_options'   => $group_options,
        'agent_options'   => $agent_options,
    ];
}


// --------------------------------------------------------
// 상태코드 헤더 구성
// - mb_group가 선택된 경우: 해당 지점 우선, 없으면 0(공통)
// - mb_group 미선택(0)인 경우: 0(공통)만 사용
// - 각 지점 내부 sort_order ASC, 출력 순서는 "지점(>0) 먼저, 그다음 0"
// --------------------------------------------------------
function get_code_list($sel_mb_group=0) {
    $code_map = [];
    $code_list = [];

    if ($sel_mb_group > 0) {
        $sql = "
        SELECT c.call_status, c.mb_group, c.name_ko, c.sort_order, c.ui_type
        FROM call_status_code c
        WHERE c.status=1 AND (c.mb_group='{$sel_mb_group}' OR c.mb_group=0)
        ORDER BY (c.mb_group='{$sel_mb_group}') DESC, c.sort_order ASC, c.call_status ASC
        ";
    } else {
        // 지점 선택이 없으면 공통(0)만
        $sql = "
        SELECT c.call_status, c.mb_group, c.name_ko, c.sort_order, c.ui_type
        FROM call_status_code c
        WHERE c.status=1 AND c.mb_group=0
        ORDER BY c.sort_order ASC, c.call_status ASC
        ";
    }
    $res = sql_query($sql);
    while ($r = sql_fetch_array($res)) {
        $cs = (int)$r['call_status'];
        if (!isset($code_map[$cs])) {
            $code_map[$cs] = [
                'name' => $r['name_ko'],
                'mb_group' => (int)$r['mb_group'],
                'sort_order' => (int)$r['sort_order'],
                'ui_type' => $r['ui_type'],
            ];
        }
    }
    foreach ($code_map as $cs=>$info) {
        $code_list[] = ['call_status'=>$cs,'name'=>$info['name'],'mb_group'=>$info['mb_group'],'sort_order'=>$info['sort_order'],'ui_type'=>$info['ui_type']];
    }
    usort($code_list, function($a,$b){
        if ($a['mb_group'] !== $b['mb_group']) return ($a['mb_group'] === 0) ? 1 : -1; // 지점>0 먼저
        if ($a['sort_order'] === $b['sort_order']) return $a['call_status'] <=> $b['call_status'];
        return $a['sort_order'] <=> $b['sort_order'];
    });
    return $code_list;
}

function status_label($code){
    static $status_cache;
    $code = (int)$code;
    if ($code <= 0) return '';
    if (!isset($status_cache[$code])) {
        $r = sql_fetch("SELECT name_ko FROM call_status_code WHERE call_status={$code} AND mb_group=0 LIMIT 1");
        $status_cache[$code] = $r ? $r['name_ko'] : ('코드 '.$code);
    }
    return $status_cache[$code];
}
