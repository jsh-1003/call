<?php
// /adm/call/call_after_list_excel.php
$sub_menu = '700400';
require_once './_common.php';

if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

// ---------- PHPExcel (그누보드 내장 구형 라이브러리) ----------
@ini_set('memory_limit','768M');
@set_time_limit(0);

// 반드시 IOFactory 전에 본체/바인더 포함
include_once(G5_LIB_PATH.'/PHPExcel.php');
include_once(G5_LIB_PATH.'/PHPExcel/Cell/AdvancedValueBinder.php');
PHPExcel_Cell::setValueBinder(new PHPExcel_Cell_AdvancedValueBinder());

// ---------- 공통 변수 ----------
$mb_no        = (int)$member['mb_no'];
$mb_level     = (int)$member['mb_level'];
$my_company_id = isset($member['company_id']) ? (int)$member['company_id'] : 0;
$member_table  = $g5['member_table'];

// ---------- 파라미터 ----------
$mode           = isset($_GET['mode']) ? trim($_GET['mode']) : 'screen'; // screen|condition|all
$start_date     = isset($_GET['start']) ? preg_replace('/[^0-9\-]/','', $_GET['start']) : '';
$end_date       = isset($_GET['end'])   ? preg_replace('/[^0-9\-]/','', $_GET['end'])   : '';
$f_status       = isset($_GET['status']) ? (int)$_GET['status'] : 0; // call_status (0=전체)
$f_acstate      = isset($_GET['acstate']) ? (int)$_GET['acstate'] : -1;
$sel_company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
$sel_mb_group   = isset($_GET['mb_group']) ? (int)$_GET['mb_group'] : 0;
$sel_agent_no   = isset($_GET['agent']) ? (int)$_GET['agent'] : 0;

$page      = max(1, (int)($_GET['page'] ?? 1));
$page_rows = 30;
$offset    = ($page - 1) * $page_rows;

// 정렬 파라미터
$cur_sort = isset($_GET['sort']) ? $_GET['sort'] : 'scheduled_at';
$cur_dir  = strtolower((string)($_GET['dir'] ?? 'desc'));
$cur_dir  = in_array($cur_dir, ['asc','desc'], true) ? $cur_dir : 'desc';
$SORT_MAP = [
    'agent_name'   => 'agent_sort',
    'call_start'   => 'b.call_start',
    'call_end'     => 'b.call_end',
    'call_time'    => 'b.call_time',
    'target_name'  => 'target_name',
    'birth_date'   => 't.birth_date',
    'age'          => 'man_age',
    'call_hp'      => 'b.call_hp',
    'ac_state'     => 'ac_state_sort',
    'scheduled_at' => 'tk.scheduled_at',
    'ac_updated'   => 'tk.updated_at',
    'campaign'     => 'cc.name'
];
$order_col = isset($SORT_MAP[$cur_sort]) ? $SORT_MAP[$cur_sort] : 'tk.scheduled_at';
$order_sql = $order_col.' '.$cur_dir.', b.call_start DESC, b.call_id DESC';

// ---------- where 절 구성 ----------
$where = [];

// 날짜
if ($start_date && $end_date) {
    $where[] = "l.call_start BETWEEN '{$start_date} 00:00:00' AND '{$end_date} 23:59:59'";
} elseif ($start_date) {
    $where[] = "l.call_start >= '{$start_date} 00:00:00'";
} elseif ($end_date) {
    $where[] = "l.call_start <= '{$end_date} 23:59:59'";
}

// 통화 상태
if ($f_status > 0) {
    $where[] = "l.call_status = {$f_status}";
}

// 2차콜 상태
if ($f_acstate >= 0) {
    $where[] = "COALESCE(tk.state_id,0) = {$f_acstate}";
}

// 회사/지점/상담원 필터
// - 회사관리자(레벨 8): 자신의 company 내에서만
// - 지점장(레벨 7): 자신의 mb_group 내에서만
// - 그 외 상위 관리자: 선택값으로 제한
if ($mb_level >= 9) {
    // super / 운영자: 파라미터 기준
    if ($sel_company_id > 0) {
        // 회사 기준 지점 추출
        if ($sel_mb_group > 0) {
            $where[] = "l.mb_group = {$sel_mb_group}";
        } else {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$sel_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $where[] = $grp_ids ? ("l.mb_group IN (".implode(',', $grp_ids).")") : "1=0";
        }
    }
    if ($sel_agent_no > 0) {
        $where[] = "l.mb_no = {$sel_agent_no}";
    }
} else {
    // 회사관리자 / 지점장 / 일반 관리자
    if ($mb_level == 8) {
        // 회사관리자: 자기 회사 내 지점만
        if ($sel_mb_group > 0) {
            $where[] = "l.mb_group = {$sel_mb_group}";
        } else {
            $grp_ids = [];
            $gr = sql_query("SELECT m.mb_no FROM {$g5['member_table']} m WHERE m.mb_level=7 AND m.company_id='".(int)$my_company_id."'");
            while ($rr = sql_fetch_array($gr)) $grp_ids[] = (int)$rr['mb_no'];
            $where[] = $grp_ids ? ("l.mb_group IN (".implode(',', $grp_ids).")") : "1=0";
        }
    } elseif ($mb_level == 7) {
        // 지점장: 자기 지점만
        $where[] = "l.mb_group = {$mb_no}";
    } else {
        // 그 외(상담원 등)에게는 다운로드 권한이 없게 차단하는게 안전하지만,
        // 이미 상단에서 레벨7 미만은 막았으므로 여기엔 안 옴.
    }

    // 상담원 선택
    if ($sel_agent_no > 0) {
        $where[] = "l.mb_no = {$sel_agent_no}";
    }
}

// is_after_call 필터: 접수관리 화면과 동일하게, 상태코드 테이블 기준
if ($f_acstate >= 0) {
    // 특정 2차상태 선택 시에도, 원 상태코드는 is_after_call=1 이어야 함
    $where[] = "sc.is_after_call = 1";
} else {
    // all: 조직범위만, is_after_call=1은 유지
    $where[] = "sc.is_after_call = 1";
}

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// ---------- SQL (목록과 동일 구조, rn=1만) ----------
$sql_base = "
  SELECT
    b.call_id, b.mb_group, b.campaign_id, b.target_id, b.mb_no AS agent_id,
    b.call_status, b.call_start, b.call_end, b.call_time, b.call_hp,

    COALESCE(g.mv_group_name, CONCAT('지점 ', b.mb_group))     AS group_name,
    m.mb_name AS agent_name, m.mb_id AS agent_mb_id,
    COALESCE(NULLIF(m.mb_name,''), m.mb_id) AS agent_sort,
    sc.name_ko AS status_label,

    t.name AS target_name, t.birth_date, t.meta_json, t.sex, 
    CASE
      WHEN t.birth_date IS NULL THEN NULL
      ELSE TIMESTAMPDIFF(YEAR, t.birth_date, CURDATE())
           - (DATE_FORMAT(CURDATE(),'%m%d') < DATE_FORMAT(t.birth_date,'%m%d'))
    END AS man_age,

    cc.name AS campaign_name,
    tk.assigned_after_mb_no,
    ma.mb_name AS after_agent_name,
    ma.mb_id   AS after_agent_mb_id,

    COALESCE(tk.state_id,0) AS ac_state_id,
    s.name_ko               AS ac_state_label,
    s.ui_type               AS ac_state_ui,
    s.sort_order            AS ac_state_sort,
    tk.scheduled_at         AS ac_scheduled_at,
    tk.schedule_note        AS ac_schedule_note,
    tk.updated_at      AS ac_updated_at
  FROM (
    SELECT l.*,
           ROW_NUMBER() OVER (PARTITION BY l.mb_group, l.campaign_id, l.target_id
                              ORDER BY l.call_start DESC, l.call_id DESC) AS rn
      FROM call_log l
      JOIN call_target t ON t.target_id = l.target_id
      JOIN call_status_code sc ON sc.call_status = l.call_status AND sc.mb_group=0
      LEFT JOIN call_aftercall_ticket tk
        ON tk.campaign_id = l.campaign_id AND tk.mb_group = l.mb_group AND tk.target_id = l.target_id
      {$where_sql}
  ) AS b
  JOIN call_target t ON t.target_id = b.target_id
  JOIN call_status_code sc ON sc.call_status=b.call_status AND sc.mb_group=0
  LEFT JOIN {$member_table} m ON m.mb_no = b.mb_no
  LEFT JOIN (
      SELECT mb_group, MAX(COALESCE(NULLIF(mb_group_name,''), CONCAT('지점 ', mb_group))) AS mv_group_name
        FROM {$member_table} WHERE mb_group>0 GROUP BY mb_group
  ) g ON g.mb_group = b.mb_group
  JOIN call_campaign cc ON cc.campaign_id=b.campaign_id AND cc.mb_group=b.mb_group
  LEFT JOIN call_aftercall_ticket tk
    ON tk.campaign_id=b.campaign_id AND tk.mb_group=b.mb_group AND tk.target_id=b.target_id
  LEFT JOIN {$member_table} ma ON ma.mb_no = tk.assigned_after_mb_no
  LEFT JOIN call_aftercall_state_code s ON s.state_id=COALESCE(tk.state_id,0)
  WHERE b.rn=1
";

$sql_export = $sql_base . " ORDER BY {$order_sql}";
if ($mode === 'screen') {
    $sql_export .= " LIMIT {$offset}, {$page_rows}";
}
$res = sql_query($sql_export);

// ---------- 엑셀 생성 ----------
$xls = new PHPExcel();
$xls->getProperties()->setCreator('콜프로그램')
                     ->setTitle('접수관리')
                     ->setSubject('접수관리 내보내기');

$sheet = $xls->getActiveSheet();
$sheet->setTitle('접수관리');

// 헤더(셀별 명시 입력: 전부 문자열로)
$headers = [
  '지점명','아이디','상담원명','통화결과','통화시작','통화종료','통화시간(초)',
  '고객명','생년월일','만나이','추가정보','전화번호','2차팀장',
  '처리상태','일정','최근처리시간','캠페인명'
];

for ($i=0; $i<count($headers); $i++) {
    $sheet->setCellValueExplicitByColumnAndRow(
        $i, 1, (string)$headers[$i], PHPExcel_Cell_DataType::TYPE_STRING
    );
}
$sheet->getStyle('A1:Q1')->getFont()->setBold(true);

$rownum = 2;
while ($row = sql_fetch_array($res)) {
    // 가공
    $group_name   = (string)$row['group_name'];
    $agent_id     = (string)$row['agent_mb_id'];
    $agent_name   = (string)($row['agent_name'] ?: $row['agent_mb_id']);
    $status_label = (string)($row['status_label'] ?: ('코드 '.$row['call_status']));
    $call_start   = (string)$row['call_start'];
    $call_end     = (string)$row['call_end'];
    $call_time    = is_null($row['call_time']) ? '' : (int)$row['call_time'];
    $target_name  = (string)($row['target_name'] ?: '');
    $bday         = empty($row['birth_date']) ? '' : substr($row['birth_date'], 2, 8);
    $man_age      = is_null($row['man_age']) ? '' : (int)$row['man_age'];
    $phone        = (string)$row['call_hp']; // 접수관리: 전체 노출

    // 2차 담당자
    $after_agent = '';
    if (!empty($row['assigned_after_mb_no'])) {
        $after_agent = (string)($row['after_agent_name'] ?: $row['after_agent_mb_id']);
    }

    // meta(성별 + meta_json)
    $sex_txt = '';
    if ((int)$row['sex'] === 1) $sex_txt = '남성';
    elseif ((int)$row['sex'] === 2) $sex_txt = '여성';

    $meta_txt = $sex_txt;
    $meta_json = $row['meta_json'];
    if (is_string($meta_json)) {
        $j = json_decode($meta_json, true);
        if (json_last_error() === JSON_ERROR_NONE && $j && is_array($j)) {
            $meta_txt .= ($meta_txt ? ', ' : '').implode(', ', array_values($j));
        }
    } elseif (is_array($meta_json)) {
        $meta_txt .= ($meta_txt ? ', ' : '').implode(', ', array_values($meta_json));
    }

    // 일정
    $sched = '';
    if (!empty($row['ac_scheduled_at']) || !empty($row['ac_schedule_note'])) {
        $tmp = [];
        if (!empty($row['ac_scheduled_at'])) $tmp[] = substr($row['ac_scheduled_at'], 5, 11); // MM-DD HH:MM
        if (!empty($row['ac_schedule_note'])) $tmp[] = $row['ac_schedule_note'];
        $sched = implode(' / ', $tmp);
    }

    $ac_label     = (string)($row['ac_state_label'] ?: '대기');
    $ac_updated   = (string)$row['ac_updated_at'];
    $campaign     = (string)$row['campaign_name'];

    // --- 셀 입력: 타입 명시 ---
    $c = 0; $r = $rownum;

    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $group_name, PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $agent_id,   PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $agent_name, PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $status_label, PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $call_start, PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $call_end,   PHPExcel_Cell_DataType::TYPE_STRING);

    // 통화시간(초): 숫자 or 빈칸
    if ($call_time === '') {
        $sheet->setCellValueExplicitByColumnAndRow($c++, $r, '', PHPExcel_Cell_DataType::TYPE_STRING);
    } else {
        $sheet->setCellValueExplicitByColumnAndRow($c++, $r, (string)$call_time, PHPExcel_Cell_DataType::TYPE_NUMERIC);
    }

    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, $target_name, PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, (string)$bday, PHPExcel_Cell_DataType::TYPE_STRING);

    // 만나이: 숫자 or 빈칸(숫자서식으로 넣되 빈칸이면 문자열)
    if ($man_age === '') {
        $sheet->setCellValueExplicitByColumnAndRow($c++, $r, '', PHPExcel_Cell_DataType::TYPE_STRING);
    } else {
        $sheet->setCellValueExplicitByColumnAndRow($c++, $r, (string)$man_age, PHPExcel_Cell_DataType::TYPE_NUMERIC);
    }

    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, (string)$meta_txt, PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, (string)$phone,    PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, (string)$after_agent, PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, (string)$ac_label, PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, (string)$sched,    PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, (string)$ac_updated, PHPExcel_Cell_DataType::TYPE_STRING);
    $sheet->setCellValueExplicitByColumnAndRow($c++, $r, (string)$campaign, PHPExcel_Cell_DataType::TYPE_STRING);

    $rownum++;
}

// 오토사이즈
for ($col = 0; $col < 17; $col++) {
    $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
}

// 출력 헤더
$fname = '접수관리';
if($mode === 'screen')
    $fname .= '_화면_';
else if($mode === 'condition')
    $fname .= '_조건_';
else 
    $fname .= '_전체_';
$fname .= date('Ymd_His').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$fname.'"');
header('Cache-Control: max-age=0');

$writer = PHPExcel_IOFactory::createWriter($xls, 'Excel2007');
$writer->save('php://output');
exit;
