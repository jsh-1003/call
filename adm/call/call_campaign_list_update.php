<?php
// /adm/call_campaign_list_update.php
$sub_menu = '700700';
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, "w");
check_admin_token();

// 접근 레벨 가드 (목록/화면과 동일 정책: 최소 7)
if ((int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

$my_level      = (int)($member['mb_level'] ?? 0);
$my_group      = (int)($member['mb_group'] ?? 0);
$my_company_id = (int)($member['company_id'] ?? 0);

$chk        = isset($_POST['chk']) ? (array)$_POST['chk'] : [];
$act_button = (string)($_POST['act_button'] ?? '');
$action     = (string)($_POST['action'] ?? '');
$qstr       = isset($_POST['qstr']) ? preg_replace('#[^a-z0-9_=&\-%]#i','', $_POST['qstr']) : '';

/**
 * 권한 스코프용 지점 조건 생성
 *  - 9+: 제한 없음
 *  - 8 : 본인 회사의 모든 지점(mb_level=7, company_id = my_company_id)
 *  - 7 : 본인 지점만
 * 반환: " AND {alias}.mb_group IN (...)" 또는 " AND {alias}.mb_group = N" 또는 "" (9+)
 *      지점이 전혀 없으면 " AND 1=0"
 */
function build_scope_group_cond($alias='c') {
    global $g5, $my_level, $my_group, $my_company_id;

    if ($my_level >= 9) {
        return ''; // 전사
    }
    if ($my_level == 8) {
        // 내 회사 소속 지점(레벨7 계정들)을 집합으로 제한
        $grp_ids = [];
        $res = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$my_company_id."'");
        while ($r = sql_fetch_array($res)) $grp_ids[] = (int)$r['mb_no'];
        if (!$grp_ids) return " AND 1=0 ";
        return " AND {$alias}.mb_group IN (".implode(',', $grp_ids).") ";
    }
    // 레벨 7: 자기 지점
    return " AND {$alias}.mb_group='".(int)$my_group."' ";
}

/**
 * 캠페인 상태 업데이트
 * - $ids: 캠페인ID 배열
 * - $status: 0/1(비/활성) 또는 9(삭제예약)
 * - $is_delete=true면 status=9 + deleted_at=NOW()
 * - 스코프 조건: build_scope_group_cond()로 제한
 */
function update_campaign_status(array $ids, $status, $is_delete=false) {
    if (empty($ids)) return;

    $ids_csv = implode(',', array_map('intval', $ids));
    $scope   = build_scope_group_cond('c'); // call_campaign 별칭을 c로 가정

    if ($is_delete) {
        // 삭제: status=9, deleted_at 갱신
        $sql = "
            UPDATE call_campaign c
               SET c.status=9,
                   c.deleted_at=NOW()
             WHERE c.campaign_id IN ({$ids_csv})
             {$scope}
        ";
    } else {
        $status = (int)$status;
        if (!in_array($status, [0,1], true)) return; // 안전장치
        $sql = "
            UPDATE call_campaign c
               SET c.status={$status},
                   c.updated_at=NOW()
             WHERE c.campaign_id IN ({$ids_csv})
             {$scope}
        ";
    }

    sql_query($sql);
}

// ---------------------------
// 단일행 액션(행 버튼)
// ---------------------------
if ($action) {
    // 형식: "activate:123", "deactivate:123", "delete:123"
    $parts = explode(':', $action, 2);
    $act = $parts[0] ?? '';
    $cid = isset($parts[1]) ? (int)$parts[1] : 0;

    if ($cid > 0) {
        if ($act === 'activate') {
            update_campaign_status([$cid], 1, false);
        } elseif ($act === 'deactivate') {
            update_campaign_status([$cid], 0, false);
        } elseif ($act === 'delete') {
            update_campaign_status([$cid], 9, true);
        } else {
            alert('잘못된 요청입니다.');
        }
    }

    goto_url('./call_campaign_list.php'.($qstr ? '?'.$qstr : ''));
    exit;
}

// ---------------------------
// 선택 액션(상단 버튼)
// ---------------------------
if (!empty($chk) && $act_button !== '') {
    // chk는 문자열일 수도 있으므로 정수화
    $ids = array_map('intval', $chk);
    $ids = array_values(array_filter($ids, function($v){ return $v > 0; }));

    if (!$ids) {
        alert('선택된 항목이 없습니다.');
    }

    if ($act_button === '선택활성화') {
        update_campaign_status($ids, 1, false);
    } elseif ($act_button === '선택비활성화') {
        update_campaign_status($ids, 0, false);
    } elseif ($act_button === '선택삭제') {
        update_campaign_status($ids, 9, true);
    } else {
        alert_close('잘못된 요청입니다.');
    }
}

goto_url('./call_campaign_list.php'.($qstr ? '?'.$qstr : ''));
