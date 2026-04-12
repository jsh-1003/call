<?php
// /adm/paid/paid_campaign_list_update.php
$sub_menu = '200765';
include_once('./_common.php');

if (empty($is_admin_pay)) {
    alert('접근 권한이 없습니다.');
    exit;
}

//check_admin_token();

$chk        = isset($_POST['chk']) ? (array)$_POST['chk'] : [];
$act_button = (string)($_POST['act_button'] ?? '');
$action     = (string)($_POST['action'] ?? '');
$qstr       = isset($_POST['qstr']) ? preg_replace('#[^a-z0-9_=&\-%]#i','', $_POST['qstr']) : '';
$ajax       = (string)($_POST['ajax'] ?? '');

/**
 * 유료DB 캠페인만 상태 변경
 * - 활성/비활성: status 1/0
 * - 삭제: status=9, deleted_at=NOW()
 */
function update_paid_campaign_status(array $ids, int $status, bool $is_delete=false) {
    if (empty($ids)) return;

    $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
    if (!$ids) return;

    $ids_csv = implode(',', $ids);

    if ($is_delete) {
        $sql = "UPDATE call_campaign c
               SET c.status=9,
                   c.deleted_at=NOW(),
                   c.updated_at=NOW()
             WHERE c.campaign_id IN ({$ids_csv})
               AND c.is_paid_db=1
               AND c.deleted_at IS NULL
        ";
        sql_query($sql);
        return;
    }

    if (!in_array($status, [0,1], true)) return;

    $sql = "UPDATE call_campaign c
           SET c.status={$status},
               c.updated_at=NOW()
         WHERE c.campaign_id IN ({$ids_csv})
           AND c.is_paid_db=1
           AND c.deleted_at IS NULL
    ";
    sql_query($sql);
}

if ($ajax === 'save_campaign_targets') {
    header('Content-Type: application/json; charset=utf-8');

    $campaign_id = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
    $use_scope   = (string)($_POST['use_scope'] ?? 'all');
    $company_ids = isset($_POST['company_ids']) ? (array)$_POST['company_ids'] : [];

    if (!in_array($use_scope, ['all', 'selected', 'exclude'], true)) {
        echo json_encode(['success' => false, 'message' => '사용 범위 값이 올바르지 않습니다.']);
        exit;
    }

    if ($use_scope === 'all') {
        $company_ids = [];
    }

    $result = sync_paid_campaign_target_companies($campaign_id, $company_ids, (int)($member['mb_no'] ?? 0), $use_scope);
    if (empty($result['ok'])) {
        echo json_encode([
            'success' => false,
            'message' => (string)($result['message'] ?? '저장에 실패했습니다.')
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => '대상 회사 설정이 저장되었습니다.',
        'state' => [
            'mode' => $result['mode'],
            'company_ids' => $result['company_ids'],
            'company_count' => $result['company_count'],
            'summary_text' => $result['summary_text'],
        ]
    ]);
    exit;
}

// 단일행 액션
if ($action) {
    $parts = explode(':', $action, 2);
    $act = $parts[0] ?? '';
    $cid = isset($parts[1]) ? (int)$parts[1] : 0;

    if ($cid > 0) {
        if ($act === 'activate') {
            update_paid_campaign_status([$cid], 1, false);
        } elseif ($act === 'deactivate') {
            update_paid_campaign_status([$cid], 0, false);
        } elseif ($act === 'delete') {
            update_paid_campaign_status([$cid], 9, true);
        } else {
            alert('잘못된 요청입니다.');
        }
    }
    goto_url('./paid_campaign_list.php'.($qstr ? '?'.$qstr : ''));
    exit;
}

// 선택 액션
if (!empty($chk) && $act_button !== '') {
    if ($act_button === '선택활성화') {
        update_paid_campaign_status($chk, 1, false);
    } elseif ($act_button === '선택비활성화') {
        update_paid_campaign_status($chk, 0, false);
    } elseif ($act_button === '선택삭제') {
        update_paid_campaign_status($chk, 9, true);
    } else {
        alert('잘못된 요청입니다.');
    }
}

goto_url('./paid_campaign_list.php'.($qstr ? '?'.$qstr : ''));
