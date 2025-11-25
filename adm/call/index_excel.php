<?php
// /adm/call/index_excel.php
$sub_menu = '700100';
require_once './_common.php';

// 접근 권한
if ((int)$member['mb_level'] < 3) alert('접근 권한이 없습니다.');

// ---------- PHPExcel (그누보드 내장 구형 라이브러리) ----------
@ini_set('memory_limit','2048M');
@set_time_limit(0);

// 고급 바인더 + 캐시 세팅
include_once(G5_LIB_PATH.'/PHPExcel.php');
include_once(G5_LIB_PATH.'/PHPExcel/Cell/AdvancedValueBinder.php');
include_once(G5_LIB_PATH.'/PHPExcel/IOFactory.php');
include_once(G5_LIB_PATH.'/PHPExcel/CachedObjectStorageFactory.php');
include_once(G5_LIB_PATH.'/PHPExcel/Settings.php');

PHPExcel_Cell::setValueBinder(new PHPExcel_Cell_AdvancedValueBinder());
$cacheMethod   = PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
$cacheSettings = array('memoryCacheSize' => '512MB'); // 상황에 따라 512MB로 올려도 됨
PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);

// ---------- 멤버/권한 ----------
$mb_no         = (int)($member['mb_no'] ?? 0);
$mb_level      = (int)($member['mb_level'] ?? 0);
$my_group      = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$my_company_id = isset($member['company_id']) ? (int)$member['company_id'] : 0;
$member_table  = $g5['member_table'];

// ---------- 파라미터 ----------
$mode           = isset($_GET['mode']) ? trim($_GET['mode']) : 'screen'; // screen|condition|all
$q              = _g('q','');
$q_type         = _g('q_type',''); // name|last4|full
$f_dnc          = _g('dnc','');    // '', '0','1'
$f_asgn         = _g('as','');     // '', '0','1','2','3','4'
$rows           = max(10, min(200, (int)_g('rows','50')));
$page           = max(1, (int)_g('page','1'));
$offset         = ($page - 1) * $rows;

if ($mb_level >= 9) {
    $sel_company_id = (int)_g('company_id', 0);
    $sel_mb_group   = (int)_g('mb_group',   0);
} elseif ($mb_level >= 8) {
    $sel_company_id = $my_company_id;
    $sel_mb_group   = (int)_g('mb_group', 0);
} else {
    $sel_company_id = $my_company_id;
    $sel_mb_group   = $my_group;
}

// 배정상태 라벨
$ASSIGN_LABEL = [ 0=>'미배정', 1=>'배정', 2=>'저장중', 3=>'완료', 4=>'거절', 8=>'번호오류', 9=>'블랙' ];

// ---------- WHERE ----------
$where = [];

// 권한별 범위
if ($mb_level >= 8) {
    // 상단 조직 필터에서 제한
} elseif ($mb_level == 7) {
    $where[] = "t.mb_group = {$my_group}";
} else {
    $where[] = "t.mb_group = {$my_group}";
    $where[] = "t.assigned_mb_no = {$mb_no}";
}

// 조직 필터
if ($mb_level >= 8) {
    if ($sel_mb_group > 0) {
        $where[] = "t.mb_group = {$sel_mb_group}";
    } else {
        if ($mb_level >= 9 && $sel_company_id > 0) {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$member_table} m WHERE m.mb_level=7 AND m.company_id='".(int)$sel_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $where[] = $grp_ids ? "t.mb_group IN (".implode(',', $grp_ids).")" : "1=0";
        } elseif ($mb_level == 8) {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$member_table} m WHERE m.mb_level=7 AND m.company_id='".(int)$my_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $where[] = $grp_ids ? "t.mb_group IN (".implode(',', $grp_ids).")" : "1=0";
        }
    }
}

// 검색/필터 (mode === all 은 무시)
if ($mode === 'screen' || $mode === 'condition') {
    if ($q !== '' && $q_type !== '') {
        if ($q_type === 'name') {
            $q_esc = sql_escape_string($q);
            $where[] = "t.name LIKE '%{$q_esc}%'";
        } elseif ($q_type === 'last4') {
            $last4 = substr(preg_replace('/\D+/', '', $q), -4);
            if ($last4 !== '') {
                $l4 = sql_escape_string($last4);
                $where[] = "t.hp_last4 = '{$l4}'";
            }
        } elseif ($q_type === 'full') {
            $full = preg_replace('/\D+/', '', $q);
            if ($full !== '') {
                $full_esc = sql_escape_string($full);
                $where[] = "t.call_hp = '{$full_esc}'";
            }
        }
    }

    // 캠페인ID
    if (!empty($campaign_id)) {
        $q_esc = sql_escape_string($campaign_id);
        $where[] = "t.campaign_id = '{$q_esc}'";
    } else {
        $campaign_id = '';
    }

    if ($f_dnc === '0' || $f_dnc === '1') {
        $where[] = "t.do_not_call = ".(int)$f_dnc;
    }
    if ($f_asgn !== '' && in_array($f_asgn, ['0','1','2','3','4'], true)) {
        $where[] = "t.assigned_status = ".(int)$f_asgn;
    }
}

// ★ 삭제 캠페인 제외
// $where[] = "c.status <> 9";
$where[] = "
  NOT EXISTS (
        SELECT 1
          FROM call_campaign c
         WHERE c.campaign_id = t.campaign_id
           AND c.mb_group    = t.mb_group
           AND c.status      = 9
  )
";

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// ---------- SQL ----------
$sql_base = "
    SELECT
        t.target_id, t.campaign_id, t.mb_group, t.call_hp, t.hp_last4,
        t.name, t.birth_date, t.meta_json, t.sex,
        t.assigned_status, t.assigned_mb_no, t.assigned_at, t.assign_lease_until, t.assign_batch_id,
        t.do_not_call, t.last_call_at, t.last_result, t.attempt_count, t.next_try_at,
        t.created_at, t.updated_at,
        /*
        c.status AS campaign_status,
        c.name   AS campaign_name,
        c.is_open_number,
        */

        g.mv_group_name,
        g.company_id AS gx_company_id,

        sc2.name_ko AS last_result_label
    FROM call_target t
    /* JOIN call_campaign c ON c.campaign_id = t.campaign_id */
    LEFT JOIN (
        SELECT mb_group,
               MAX(COALESCE(NULLIF(mb_group_name,''), CONCAT('지점 ', mb_group))) AS mv_group_name,
               MAX(company_id) AS company_id
          FROM {$member_table}
         WHERE mb_group > 0
         GROUP BY mb_group
    ) g ON g.mb_group = t.mb_group
    LEFT JOIN call_status_code sc2
           ON sc2.mb_group = 0
          AND sc2.call_status = t.last_result
    {$where_sql}
    ORDER BY t.target_id DESC
";

// mode=screen 만 LIMIT
$sql_export = $sql_base;
if ($mode === 'screen') $sql_export .= " LIMIT {$offset}, {$rows}";
$res = sql_query($sql_export);

// ---------- 엑셀 생성 ----------
$xls = new PHPExcel();
$xls->getProperties()
    ->setCreator('콜프로그램')
    ->setTitle('DB리스트')
    ->setSubject('DB리스트 내보내기');

$sheet = $xls->getActiveSheet();
$sheet->setTitle('DB리스트');

// ✅ 헤더: 이름/만나이 분리 + 생년월일 추가
$headers = [
    '회사','지점','캠페인','전화번호',
    '이름','만나이','생년월일','성별',
    '추가정보','배정상태','담당자','블랙','통화결과','업데이트'
];
for ($i=0; $i<count($headers); $i++) {
    $sheet->setCellValueExplicitByColumnAndRow($i, 1, (string)$headers[$i], PHPExcel_Cell_DataType::TYPE_STRING);
}
$sheet->getStyle('A1:M1')->getFont()->setBold(true);

// 유틸
function _company_name_from_group($gid, $fallback_company_id=null){
    if (function_exists('get_company_name_from_group_id_cached')) {
        return (string)get_company_name_from_group_id_cached((int)$gid);
    }
    if ($fallback_company_id) return '회사 '.$fallback_company_id;
    return '-';
}
function _group_name_from_mv($mv){ return $mv ? (string)$mv : '-'; }
function _agent_name_by_no($mb_no){
    if (!$mb_no) return '-';
    if (function_exists('get_agent_name_cached')) return (string)get_agent_name_cached((int)$mb_no);
    return '상담원#'.$mb_no;
}
function _age_years($birth_date){
    if (!$birth_date) return null;
    $bd = strtotime($birth_date);
    if (!$bd) return null;
    $y  = (int)date('Y') - (int)date('Y', $bd);
    $md_now = (int)date('md');
    $md_bd  = (int)date('md', $bd);
    if ($md_now < $md_bd) $y--;
    return max(0, $y);
}

$rownum = 2;
while ($row = sql_fetch_array($res)) {
    // 캠페인 정보를 지우고 가져와서 보여줌.
    $campaign_info = get_campaign_from_cached($row['campaign_id']);
    $row['campaign_status'] = $campaign_info['status'];
    $row['campaign_name'] = $campaign_info['name'];
    $row['is_open_number'] = $campaign_info['is_open_number'];

    $gid       = (int)$row['mb_group'];
    $company   = _company_name_from_group($gid, (int)($row['gx_company_id'] ?? 0));
    $group     = _group_name_from_mv($row['mv_group_name']);
    $camp_name = (string)$row['campaign_name'];

    // 전화번호: is_open_number=0 && 레벨<9 → (숨김처리)
    if ((int)$row['is_open_number'] === 0 && $mb_level < 9) {
        $phone = '(숨김처리)';
    } else {
        $phone = (string)$row['call_hp']; // 앞자리 0 보존
    }

    $name = (string)$row['name'];
    $age  = _age_years($row['birth_date']);
    $age_txt = is_null($age) ? '' : (string)$age; // 숫자만
    // 생년월일은 DB값 그대로(YYYY-MM-DD 가정). 비어있으면 빈칸.
    $birth = $row['birth_date'] ? (string)$row['birth_date'] : '';

    // 추가정보(meta)
    $sex_txt = '';
    if ((int)$row['sex'] === 1) $sex_txt = '남성';
    elseif ((int)$row['sex'] === 2) $sex_txt = '여성';
    $meta_txt = '';
    $mj = $row['meta_json'];
    if (is_string($mj)) {
        $j = json_decode($mj, true);
        if (json_last_error() === JSON_ERROR_NONE && $j && is_array($j)) {
            foreach($j as $kkk => $vvv) {
                if(!$vvv) unset($j[$kkk]);
            }
            $meta_txt .= ($meta_txt ? ', ' : '').implode(', ', $j);
        }
    } elseif (is_array($mj)) {
        foreach($mj as $kkk => $vvv) {
            if(!$vvv) unset($mj[$kkk]);
        }
        $meta_txt .= ($meta_txt ? ', ' : '').implode(', ', array_values($mj));
    }

    $as = (int)$row['assigned_status'];
    $as_label = isset($ASSIGN_LABEL[$as]) ? $ASSIGN_LABEL[$as] : (string)$as;
    $agent_txt = _agent_name_by_no((int)$row['assigned_mb_no']);
    $dnc = ((int)$row['do_not_call'] === 1) ? 'Y' : 'N';

    if (isset($row['last_result_label']) && $row['last_result_label']) {
        $last_label = $row['last_result'].' - '.$row['last_result_label'];
    } else {
        if (function_exists('status_label') && (int)$row['last_result'] > 0) {
            $last_label = (int)$row['last_result'].' - '.status_label((int)$row['last_result']);
        } else {
            $last_label = (int)$row['last_result'] > 0 ? (string)$row['last_result'] : '';
        }
    }
    $updated = $row['updated_at'] ? substr((string)$row['updated_at'], 2, 17) : '';

    // ----- 셀 입력 (모두 명시 타입) -----
    $c = 0; $r = $rownum;
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $company,  PHPExcel_Cell_DataType::TYPE_STRING); // 회사
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $group,    PHPExcel_Cell_DataType::TYPE_STRING); // 지점
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $camp_name,PHPExcel_Cell_DataType::TYPE_STRING); // 캠페인
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $phone,    PHPExcel_Cell_DataType::TYPE_STRING); // 전화번호
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $name,     PHPExcel_Cell_DataType::TYPE_STRING); // 이름
    // 만나이: 있으면 숫자, 없으면 빈 문자열
    if ($age_txt === '') $sheet->setCellValueExplicitByColumnAndRow($c++, $r, '', PHPExcel_Cell_DataType::TYPE_STRING);
    else                 $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $age_txt, PHPExcel_Cell_DataType::TYPE_NUMERIC);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $birth,    PHPExcel_Cell_DataType::TYPE_STRING); // 생년월일
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $sex_txt, PHPExcel_Cell_DataType::TYPE_STRING);  // 성별
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $meta_txt, PHPExcel_Cell_DataType::TYPE_STRING); // 추가정보
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $as_label, PHPExcel_Cell_DataType::TYPE_STRING); // 배정상태
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $agent_txt,PHPExcel_Cell_DataType::TYPE_STRING); // 담당자
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $dnc,      PHPExcel_Cell_DataType::TYPE_STRING); // DNC
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $last_label,PHPExcel_Cell_DataType::TYPE_STRING);// 통화결과
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $updated,  PHPExcel_Cell_DataType::TYPE_STRING); // 업데이트

    $rownum++;
}

// 오토사이즈 (13열)
for ($col = 0; $col < 13; $col++) {
    $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
}

// 출력
$fname = 'DB리스트_'.date('Ymd_His').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$fname.'"');
header('Cache-Control: max-age=0');

$writer = PHPExcel_IOFactory::createWriter($xls, 'Excel2007');
$writer->save('php://output');
exit;
