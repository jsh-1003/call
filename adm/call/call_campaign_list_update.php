<?php
// /adm/call_campaign_list_update.php
$sub_menu = '700700';
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, "w");
check_admin_token();

$my_level = (int)$member['mb_level'];
$my_group = (int)($member['mb_group'] ?? 0);

$chk = $_POST['chk'] ?? [];
$act_button = $_POST['act_button'] ?? '';
$action = $_POST['action'] ?? '';
$qstr = isset($_POST['qstr']) ? preg_replace('#[^a-z0-9_=&\-%]#i','', $_POST['qstr']) : '';

/**
 * 상태 업데이트
 * - 삭제는 status=9, deleted_at=NOW()
 * - 활성(1)/비활성(0)은 updated_at 갱신
 * - 권한 조건: 레벨7은 자신의 mb_group만, 레벨8+는 제한없음(요청 사양 유지)
 */
function update_campaign_status($ids, $status, $is_delete=false){
    global $member;

    if (!$ids) return;
    $ids_csv = implode(',', array_map('intval', $ids));

    // 권한별 그룹 제한
    $my_level = (int)$member['mb_level'];
    $my_group = (int)($member['mb_group'] ?? 0);
    $grp_cond = ($my_level < 8) ? " AND mb_group='{$my_group}' " : "";

    if ($is_delete) {
        $sql = "UPDATE call_campaign
                SET status=9, deleted_at=NOW()
                WHERE campaign_id IN ({$ids_csv}) {$grp_cond}";
    } else {
        $status = (int)$status;
        $sql = "UPDATE call_campaign
                SET status={$status}, updated_at=NOW()
                WHERE campaign_id IN ({$ids_csv}) {$grp_cond}";
    }
    sql_query($sql);
}

if ($action) {
    // 단일행 버튼 처리: activate:ID or deactivate:ID or delete:ID
    [$act, $cid] = explode(':', $action);
    $cid = (int)$cid;
    if ($act === 'activate') update_campaign_status([$cid], 1);
    elseif ($act === 'deactivate') update_campaign_status([$cid], 0);
    elseif ($act === 'delete') update_campaign_status([$cid], 9, true);

    goto_url('./call_campaign_list.php'.($qstr ? '?'.$qstr : ''));
    exit;
}

if ($chk && $act_button) {
    switch($act_button) {
        case '선택활성화':
            update_campaign_status($chk, 1);
            break;
        case '선택비활성화':
            update_campaign_status($chk, 0);
            break;
        case '선택삭제':
            update_campaign_status($chk, 9, true);
            break;
        default:
            alert_close('잘못된 요청입니다.');
    }
}

goto_url('./call_campaign_list.php'.($qstr ? '?'.$qstr : ''));
