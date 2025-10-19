<?php
if (!defined('_GNUBOARD_')) exit; // 개별 페이지 접근 불가

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
