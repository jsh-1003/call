<?php
if (!defined('_GNUBOARD_')) exit; // 개별 페이지 접근 불가

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

// 에이전트 드롭다운용 HTML 조각 준비 (그룹 구분 포함)
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

function get_group_name(int $mb_no): ?string {
    static $cache = []; // 요청(스크립트) 동안만 유지되는 메모이제이션 캐시
    // null 도 캐시로 인정하려면 array_key_exists 사용
    if (array_key_exists($mb_no, $cache)) {
        return $cache[$mb_no]; // string|null
    }
    $rowFirstCol = current(sql_fetch("SELECT mb_group_name FROM g5_member WHERE mb_no = {$mb_no} LIMIT 1"));
    // current()가 false를 줄 수 있으니 null로 정규화
    $name = ($rowFirstCol === false) ? null : $rowFirstCol;
    $cache[$mb_no] = $name; // string|null 캐싱
    return $name;
}
function get_group_name_cached($group_id) {
    static $cache = [];
    $gid = (int)$group_id;
    if ($gid <= 0) return '-';
    if (isset($cache[$gid])) return $cache[$gid];

    global $g5;
    $row = sql_fetch("
        SELECT COALESCE(NULLIF(mb_group_name,''), CONCAT('그룹-', mb_no)) AS nm
        FROM {$g5['member_table']}
        WHERE mb_no = '{$gid}' AND mb_level = 7
        LIMIT 1
    ");
    $cache[$gid] = $row && $row['nm'] ? $row['nm'] : '그룹-'.$gid;
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

// 회사별 그룹 수(레벨7 수)
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

// 그룹별 상담원 수(레벨3, 차단/탈퇴 제외)
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
 * 조직 셀렉트 옵션(회사/그룹/상담사) 생성
 * - mb_level 은 global $member['mb_level'] 사용
 * - 반환: ['company_options'=>[], 'group_options'=>[], 'agent_options'=>[]]
 *
 * @param int      $sel_company_id 선택한 회사ID (9+만 의미, 나머지는 회사 고정)
 * @param int      $sel_mb_group   선택한 그룹ID (0=전체)
 * @param int      $my_company_id  내 회사ID (8 이하 권한에서 고정 범위)
 * @param int      $my_group       내 그룹ID (7 권한에서 고정 범위)
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
       그룹 옵션(8+)
       -------------------------- */
    $group_options = [];
    if ($mb_level >= 8) {
        $where_g = " WHERE m.mb_level = 7 ";
        if ($mb_level >= 9) {
            if ((int)$sel_company_id > 0) $where_g .= " AND m.company_id = '".(int)$sel_company_id."' ";
        } else {
            $where_g .= " AND m.company_id = '".(int)$my_company_id."' ";
        }
        $res = sql_query("SELECT m.mb_no AS mb_group, m.company_id FROM {$member_table} m {$where_g}
             ORDER BY m.company_id ASC,
                      COALESCE(NULLIF(m.mb_group_name,''), CONCAT('그룹-', m.mb_no)) ASC,
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
       상담사 옵션(회사/그룹 필터 반영) — 상담원 레벨(3)만
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
    $aw[] = "mb_level = 3";
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
// - mb_group가 선택된 경우: 해당 그룹 우선, 없으면 0(공통)
// - mb_group 미선택(0)인 경우: 0(공통)만 사용
// - 각 그룹 내부 sort_order ASC, 출력 순서는 "그룹(>0) 먼저, 그다음 0"
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
        // 그룹 선택이 없으면 공통(0)만
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
        if ($a['mb_group'] !== $b['mb_group']) return ($a['mb_group'] === 0) ? 1 : -1; // 그룹>0 먼저
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
