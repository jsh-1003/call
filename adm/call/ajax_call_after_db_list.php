<?php
// /adm/call/call_after_list.php
$sub_menu = '700400';
require_once './_common.php';

// 접근 권한: 관리자 레벨 5 이상
if ($is_admin !== 'super' && (int)$member['mb_level'] < 5) {
    die('접근 권한이 없습니다~!'.$member['mb_level']);
}

$mb_no          = (int)($member['mb_no'] ?? 0);
$mb_level       = (int)($member['mb_level'] ?? 0);
$my_group       = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$my_company_id  = isset($member['company_id']) ? (int)$member['company_id'] : 0;
$member_table   = $g5['member_table'];


/* ==========================
   AJAX: 단건 조회, 저장, 후보목록
   ========================== */
if (isset($_GET['ajax']) && $_GET['ajax']==='get') {
    header('Content-Type: application/json; charset=utf-8');
    $target_id   = (int)($_GET['target_id'] ?? 0);
    if ($target_id<=0) { echo json_encode(['success'=>false,'message'=>'대상확인 실패!'], JSON_UNESCAPED_UNICODE); exit; }

    $info = get_aftercall_db_info($target_id);
    if(!empty($info['detail'])) {
        $mb_group = $info['detail']['mb_group'];
    } else if(!empty($info['basic'])) {
        $mb_group = $info['basic']['mb_group'];
    } else {
        echo json_encode(['success'=>false,'message'=>'invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $detail = array();
    
    $company_id = get_company_id_from_group_id_cached($mb_group);

    if ($mb_level <= 7 && $mb_group !== $my_group) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    if ($mb_level == 8) {
        $own_grp = sql_fetch("SELECT 1 FROM {$member_table} WHERE mb_no={$mb_group} AND mb_level=7 AND company_id='{$my_company_id}' LIMIT 1");
        if (!$own_grp) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    }
    if ($mb_level == 5) {
        $own = sql_fetch("SELECT 1 FROM call_aftercall_ticket WHERE target_id = {$target_id} and assigned_after_mb_no = {$mb_no} LIMIT 1 ");
        if (!$own) { echo json_encode(['success'=>false,'message'=>'denied/3']); exit; }
    } else if ($mb_level < 7) {
        $own = sql_fetch("SELECT 1 FROM call_log WHERE mb_group={$mb_group} AND campaign_id={$campaign_id} AND target_id={$target_id} AND mb_no={$mb_no} LIMIT 1");
        if (!$own) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    }

    // 디테일
    $detail = array();
    $company_info = get_company_info($company_id, 'is_after_db_use');
    $useDetail = $company_info['is_after_db_use'];
    if(!$useDetail) {
        echo json_encode(['success'=>true,'useDetail'=>$useDetail], JSON_UNESCAPED_UNICODE); exit;
        exit;
    }
    
    $detail['target_id'] = $target_id;

    $detail['name'] = !empty($info['detail']['db_name']) ? $info['detail']['db_name'] : $info['basic']['name'];
    $detail['birth'] = !empty($info['detail']['db_birth_date']) ? $info['detail']['db_birth_date'] : $info['basic']['birth_date'];
    $detail['age'] = calc_age_years($detail['birth']);
    $detail['sex'] = !empty($info['detail']['sex']) ? $info['detail']['sex'] : $info['basic']['sex'];
    $hp = !empty($info['detail']['db_hp']) ? $info['detail']['db_hp'] : $info['basic']['call_hp'];
    $detail['hp'] = format_korean_phone($hp);
    $detail['month_pay'] = !empty($info['detail']['month_pay']) ? $info['detail']['month_pay'] : '';
    $detail['scheduled_at'] = !empty($info['detail']['db_scheduled_at']) ? $info['detail']['db_scheduled_at'] : '';
    $detail['region1'] = !empty($info['detail']['area1']) ? $info['detail']['area1'] : '';
    $detail['region2'] = !empty($info['detail']['area2']) ? $info['detail']['area2'] : '';
    $detail['addr_etc'] = !empty($info['detail']['area3']) ? $info['detail']['area3'] : '';
    $detail['memo'] = !empty($info['detail']['memo']) ? get_text($info['detail']['memo']) : '';
    $detail['recording'] = '';
    if(!empty($info['recording']['recording_id'])) {
        $dl_url   = make_recording_url($info['recording']['recording_id']);
        $mime     = guess_audio_mime($info['recording']['s3_key'], $info['recording']['content_type']);
        $detail['recording'] = '<audio controls preload="none" class="audio"><source src="'.$dl_url.'" type="'.get_text($mime).'"></audio>';
    }
    echo json_encode(['success'=>true,'use_detail'=>$useDetail,'data'=>$detail], JSON_UNESCAPED_UNICODE); exit;
}

/* ==========================
   AJAX: 상세DB내용 저장
   ========================== */
if (isset($_POST['ajax']) && $_POST['ajax']==='save') {
    check_admin_token();
    header('Content-Type: application/json; charset=utf-8');
    $target_id   = (int)($_POST['target_id'] ?? 0);
    if ($target_id<=0) { echo json_encode(['success'=>false,'message'=>'대상확인 실패!'], JSON_UNESCAPED_UNICODE); exit; }

    $info = get_aftercall_db_info($target_id);
    $w = '';
    if(!empty($info['detail'])) {
        $w = 'u';
        $db_id = $info['detail']['db_id'];
        $mb_group = $info['basic']['mb_group'];
    } else if(!empty($info['basic'])) {
        $mb_group = $info['basic']['mb_group'];
    } else {
        echo json_encode(['success'=>false,'message'=>'invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($mb_level <= 7 && $mb_group !== $my_group) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    if ($mb_level == 8) {
        $own_grp = sql_fetch("SELECT 1 FROM {$member_table} WHERE mb_no={$mb_group} AND mb_level=7 AND company_id='{$my_company_id}' LIMIT 1");
        if (!$own_grp) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    }
    if ($mb_level == 5) {
        $own = sql_fetch("SELECT 1 FROM call_aftercall_ticket WHERE target_id = {$target_id} and assigned_after_mb_no = {$mb_no} LIMIT 1 ");
        if (!$own) { echo json_encode(['success'=>false,'message'=>'denied/3']); exit; }
    } else if ($mb_level < 7) {
        $own = sql_fetch("SELECT 1 FROM call_log WHERE mb_group={$mb_group} AND campaign_id={$campaign_id} AND target_id={$target_id} AND mb_no={$mb_no} LIMIT 1");
        if (!$own) { echo json_encode(['success'=>false,'message'=>'denied'], JSON_UNESCAPED_UNICODE); exit; }
    }
    
    // 필드 정리
    $db_hp = preg_replace('/\D+/', '', $_POST['detail_hp']);
    $db_name = sql_escape_string($_POST['detail_name']);
    $db_birth_date = sql_escape_string($_POST['detail_birth']);
    $d = DateTime::createFromFormat('Y-m-d', $db_birth_date);
    // 형식이 맞고 실제 존재하는 날짜인지 검사
    if (!($d && $d->format('Y-m-d') === $db_birth_date)) {
        $db_birth_date = ''; // 잘못된 날짜인 경우 삭제 처리
    }
    $sex = (int)$_POST['detail_sex'];
    $month_pay = (int)$_POST['detail_month_pay'];
    $db_scheduled_at = sql_escape_string($_POST['detail_scheduled_at']);
    $area1 = sql_escape_string($_POST['detail_region1']);
    $area2 = sql_escape_string($_POST['detail_region2']);
    $area3 = sql_escape_string($_POST['detail_addr_etc']);
    $memo = sql_escape_string($_POST['detail_memo']);

    // DB 입력
    $sql_common = "
        db_hp = '{$db_hp}',
        db_name = '{$db_name}',
        db_birth_date = '{$db_birth_date}',
        sex = '{$sex}',
        month_pay = '{$month_pay}',
        db_scheduled_at = '{$db_scheduled_at}',
        area1 = '{$area1}',
        area2 = '{$area2}',
        area3 = '{$area3}',
        memo = '{$memo}',
        updated_by = '{$mb_no}'
    ";
    
    $tb = 'call_aftercall_db_info';
    if(!$w) {
        $sql = "INSERT INTO {$tb} SET
            mb_group = '{$mb_group}',
            target_id = '{$target_id}',
            {$sql_common}
        ";
    } else {
        $sql = "UPDATE {$tb} SET
            {$sql_common}
        ";
    }
    sql_query($sql);
    echo json_encode(['success'=>true,'message'=>'saved'], JSON_UNESCAPED_UNICODE); exit;
}
