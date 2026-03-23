<?php
// /adm/call/call_stats_excel.php
// call_stats.php "상세목록" 엑셀 추출용
$sub_menu = '700200';
require_once './_common.php';
/* -----------------------------------------------------------
 * 0) 권한
 * --------------------------------------------------------- */
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

/* -----------------------------------------------------------
 * 1) 메모리/타임아웃 (대량 추출 대비)
 * --------------------------------------------------------- */
@ini_set('memory_limit', '1024M');
@set_time_limit(0);

// PHPExcel (그누보드 내장 구형 라이브러리)
include_once(G5_LIB_PATH.'/PHPExcel.php');
include_once(G5_LIB_PATH.'/PHPExcel/Cell/AdvancedValueBinder.php');
PHPExcel_Cell::setValueBinder(new PHPExcel_Cell_AdvancedValueBinder());

/* -----------------------------------------------------------
 * 2) 파라미터(= call_stats.php와 동일)
 * --------------------------------------------------------- */
$mb_no         = (int)($member['mb_no'] ?? 0);
$mb_level      = (int)($member['mb_level'] ?? 0);
$my_group      = (int)($member['mb_group'] ?? 0);
$my_company_id = (int)($member['company_id'] ?? 0);
$member_table  = $g5['member_table'];

$default_start = date('Y-m-d').'T08:00';
$default_end   = date('Y-m-d').'T19:00';

$start_date = _g('start', $default_start);
$end_date   = _g('end',   $default_end);

// 회사/지점/담당자 선택값(권한 스코프)
if ($mb_level >= 9) {
    $sel_company_id = (int)($_GET['company_id'] ?? 0); // 0=전체 회사
} else {
    $sel_company_id = $my_company_id; // 8/7 고정
}
$sel_mb_group = ($mb_level >= 8) ? (int)($_GET['mb_group'] ?? 0) : $my_group; // 8+=선택, 7=고정
$sel_agent_no = (int)($_GET['agent'] ?? 0);

// 검색/필터
$q        = _g('q', '');
$q_type   = _g('q_type', ''); // name | last4 | full | all
$f_status = isset($_GET['status']) ? (int)$_GET['status'] : 0; // 0=전체

// mode: screen(현재 페이지 50건) | condition(조건 전체)
$mode     = isset($_GET['mode']) ? trim((string)$_GET['mode']) : 'condition';
$page     = max(1, (int)(_g('page', '1')));
$page_rows = 50;
$offset   = ($page - 1) * $page_rows;

/* -----------------------------------------------------------
 * 3) 유틸(필요한 것만 최소 포함)
 * --------------------------------------------------------- */
if (!function_exists('fmt_hms')) {
    function fmt_hms($sec) {
        $sec = (int)$sec;
        if ($sec <= 0) return '00:00:00';
        $h = (int)floor($sec / 3600);
        $m = (int)floor(($sec % 3600) / 60);
        $s = (int)($sec % 60);
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}

function build_where_and_flags_stats_excel(array $params): array {
    $mb_level = (int)$params['mb_level'];
    $mb_no = (int)$params['mb_no'];
    $my_group = (int)$params['my_group'];
    $my_company_id = (int)$params['my_company_id'];

    $sel_company_id = (int)$params['sel_company_id'];
    $sel_mb_group = (int)$params['sel_mb_group'];
    $sel_agent_no = (int)$params['sel_agent_no'];

    $start_date = (string)$params['start_date'];
    $end_date   = (string)$params['end_date'];

    $q = (string)$params['q'];
    $q_type = (string)$params['q_type'];
    $f_status = (int)$params['f_status'];

    $where = [];
    $start_esc = sql_escape_string($start_date);
    $end_esc   = sql_escape_string($end_date);
    $where[]   = "l.call_start BETWEEN '{$start_esc}' AND '{$end_esc}'";

    if ($f_status > 0) {
        $where[] = "l.call_status = {$f_status}";
    }

    // where에서 call_target(t) JOIN 필요한지
    $need_target_join = false;

    if ($q !== '' && $q_type !== '') {
        if ($q_type === 'name') {
            $q_esc = sql_escape_string($q);
            $where[] = "t.name LIKE '%{$q_esc}%'";
            $need_target_join = true;
        } elseif ($q_type === 'last4') {
            $q4 = preg_replace('/\D+/', '', $q);
            $q4 = substr($q4, -4);
            if ($q4 !== '') {
                $where[] = "t.hp_last4 = '".sql_escape_string($q4)."'";
                $need_target_join = true;
            }
        } elseif ($q_type === 'full') {
            $hp = preg_replace('/\D+/', '', $q);
            if ($hp !== '') {
                $where[] = "l.call_hp = '".sql_escape_string($hp)."'";
            }
        } elseif ($q_type === 'all') {
            $q_esc = sql_escape_string($q);
            $q4    = substr(preg_replace('/\D+/', '', $q), -4);
            $hp    = preg_replace('/\D+/', '', $q);
            $conds = ["t.name LIKE '%{$q_esc}%'"];
            $need_target_join = true;
            if ($q4 !== '') $conds[] = "t.hp_last4 = '".sql_escape_string($q4)."'";
            if ($hp !== '') $conds[] = "l.call_hp = '".sql_escape_string($hp)."'";
            if ($conds) $where[] = '(' . implode(' OR ', $conds) . ')';
        }
    }

    // 권한/선택 스코프
    if ($mb_level == 7) {
        $where[] = "l.mb_group = {$my_group}";
    } elseif ($mb_level < 7) {
        $where[] = "l.mb_no = {$mb_no}";
    } else {
        // 8/9+ 회사필터는 m.company_id라서 member join 필요
        if ($mb_level == 8) {
            $where[] = "m.company_id = {$my_company_id}";
        } elseif ($mb_level >= 9 && $sel_company_id > 0) {
            $where[] = "m.company_id = {$sel_company_id}";
        }
        if ($sel_mb_group > 0) $where[] = "l.mb_group = {$sel_mb_group}";
    }
    if ($sel_agent_no > 0) {
        $where[] = "l.mb_no = {$sel_agent_no}";
    }

    $where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';
    $need_member_filter = (strpos($where_sql, 'm.company_id') !== false);

    return [
        'where_sql' => $where_sql,
        'need_member_filter' => $need_member_filter,
        'need_target_join' => $need_target_join,
    ];
}

function build_scope_joins_for_where_stats_excel(string $member_table, bool $need_member_filter, bool $need_target_join): string {
    $j = [];
    if ($need_target_join) {
        $j[] = 'JOIN call_target t ON t.target_id = l.target_id';
    }
    if ($need_member_filter) {
        $j[] = "JOIN {$member_table} m ON m.mb_no = l.mb_no";
    }
    return implode("\n", $j);
}

function build_list_sql_optimized_stats_excel(array $args): string {
    $member_table = (string)$args['member_table'];
    $where_sql = (string)$args['where_sql'];
    $need_member_filter = (bool)$args['need_member_filter'];
    $need_target_join = (bool)$args['need_target_join'];
    $mode = (string)$args['mode'];
    $offset = (int)$args['offset'];
    $page_rows = (int)$args['page_rows'];

    $sub_joins = build_scope_joins_for_where_stats_excel($member_table, $need_member_filter, $need_target_join);
    $limit_sql = '';
    if ($mode === 'screen') {
        $limit_sql = "LIMIT {$offset}, {$page_rows}";
    }

    $sub = "
        SELECT l.call_id
          FROM call_log l
          {$sub_joins}
          {$where_sql}
         ORDER BY l.call_start DESC, l.call_id DESC
         {$limit_sql}
    ";

    return "SELECT
            l.call_id,
            l.mb_group,
            l.mb_no                                                        AS agent_id,
            m.mb_name                                                      AS agent_name,
            m.mb_id                                                        AS agent_mb_id,
            l.call_status,
            sc.name_ko                                                     AS status_label,
            sc.is_after_call                                               AS sc_is_after_call,
            l.campaign_id,
            l.target_id,
            l.call_start,
            l.call_end,
            l.call_time,
            l.agent_phone,
            rec.duration_sec                                               AS talk_time,
            t.name                                                         AS target_name,
            t.birth_date,
            CASE
              WHEN t.birth_date IS NULL THEN NULL
              ELSE TIMESTAMPDIFF(YEAR, t.birth_date, CURDATE())
                   - (DATE_FORMAT(CURDATE(),'%m%d') < DATE_FORMAT(t.birth_date,'%m%d'))
            END AS man_age,
            l.call_hp,
            t.meta_json,
            cc.name                                                        AS campaign_name,
            cc.is_open_number                                              AS cc_is_open_number
        FROM (
            {$sub}
        ) pick
        JOIN call_log l
          ON l.call_id = pick.call_id
        JOIN call_target t
          ON t.target_id = l.target_id
        LEFT JOIN {$member_table} m
          ON m.mb_no = l.mb_no
        LEFT JOIN call_status_code sc
          ON sc.call_status = l.call_status AND sc.mb_group = 0
        LEFT JOIN call_recording rec
          ON rec.call_id = l.call_id
         AND rec.mb_group = l.mb_group
         AND rec.campaign_id = l.campaign_id
        JOIN call_campaign cc
          ON cc.campaign_id = l.campaign_id
          AND (
                cc.mb_group = l.mb_group
                OR (cc.is_paid_db = 1 AND cc.mb_group = 0)
            )
        ORDER BY l.call_start DESC, l.call_id DESC";
}

/* -----------------------------------------------------------
 * 4) WHERE + LIST SQL
 * --------------------------------------------------------- */
$wf = build_where_and_flags_stats_excel([
    'member_table' => $member_table,
    'mb_level' => $mb_level,
    'mb_no' => $mb_no,
    'my_group' => $my_group,
    'my_company_id' => $my_company_id,
    'sel_company_id' => $sel_company_id,
    'sel_mb_group' => $sel_mb_group,
    'sel_agent_no' => $sel_agent_no,
    'start_date' => $start_date,
    'end_date' => $end_date,
    'q' => $q,
    'q_type' => $q_type,
    'f_status' => $f_status,
]);

$where_sql = $wf['where_sql'];
$need_member_filter = (bool)$wf['need_member_filter'];
$need_target_join = (bool)$wf['need_target_join'];

$sql_export = build_list_sql_optimized_stats_excel([
    'member_table' => $member_table,
    'where_sql' => $where_sql,
    'need_member_filter' => $need_member_filter,
    'need_target_join' => $need_target_join,
    'mode' => $mode,
    'offset' => $offset,
    'page_rows' => $page_rows,
]);
$res = sql_query($sql_export);

/* -----------------------------------------------------------
 * 5) 엑셀 생성
 * --------------------------------------------------------- */
$xls = new PHPExcel();
$xls->getProperties()
    ->setCreator('콜프로그램')
    ->setTitle('통화통계 상세목록')
    ->setSubject('통화통계 상세목록 내보내기');

$sheet = $xls->getActiveSheet();
$sheet->setTitle('상세목록');

$headers = [
    '지점명','아이디','상담원명','발신번호','통화결과','통화시작','통화종료','통화시간','상담시간',
    '고객명','생년월일','만나이','전화번호','추가정보','캠페인명'
];
for ($i = 0; $i < count($headers); $i++) {
    $sheet->setCellValueExplicitByColumnAndRow($i, 1, (string)$headers[$i], PHPExcel_Cell_DataType::TYPE_STRING);
}
$sheet->getStyle('A1:O1')->getFont()->setBold(true);

$rownum = 2;
while ($row = sql_fetch_array($res)) {
    $gname = get_group_name_cached((int)$row['mb_group']);
    $agent = $row['agent_name'] ? get_text($row['agent_name']) : (string)$row['agent_mb_id'];
    $status = $row['status_label'] ?: ('코드 '.$row['call_status']);
    
    // 발신번호(상담원)
    $agent_phone = '';
    if (!empty($row['agent_phone'])) {
        $agent_phone = get_text(format_korean_phone($row['agent_phone']));
        // 화면과 동일 규칙(13자리면 국번 제거)
        if (strlen($agent_phone) == 13) $agent_phone = substr($agent_phone, 4, 9);
    }

    $call_sec = is_null($row['call_time']) ? '' : fmt_hms((int)$row['call_time']);
    $talk_sec = is_null($row['talk_time']) ? '' : fmt_hms((int)$row['talk_time']);

    $bday = empty($row['birth_date']) ? '' : get_text($row['birth_date']);
    $man_age = is_null($row['man_age']) ? '' : ((int)$row['man_age']).'세';

    // 캠페인 공개 여부에 따른 전화번호 블럭 처리(중요)
    if ((int)$row['cc_is_open_number'] === 0 && (int)$row['sc_is_after_call'] !== 1 && $mb_level < 9) {
        $hp_display = '(숨김처리)';
    } else {
        $hp_display = get_text(format_korean_phone($row['call_hp']));
    }

    // 추가정보(meta_json)
    $meta = '';
    if (!is_null($row['meta_json']) && $row['meta_json'] !== '') {
        $decoded = json_decode($row['meta_json'], true);
        if (is_array($decoded)) {
            $meta = implode(',', $decoded);
        } else {
            $meta = get_text((string)$row['meta_json']);
        }
    }

    $cols = [
        (string)$gname,
        (string)$row['agent_mb_id'],
        (string)$agent,
        (string)$agent_phone,
        (string)get_text($status),
        (string)get_text($row['call_start']),
        (string)get_text($row['call_end']),
        (string)$call_sec,
        (string)$talk_sec,
        (string)get_text($row['target_name'] ?: ''),
        (string)$bday,
        (string)$man_age,
        (string)$hp_display,
        (string)$meta,
        (string)get_text($row['campaign_name'] ?: ''),
    ];

    for ($c = 0; $c < count($cols); $c++) {
        $sheet->setCellValueExplicitByColumnAndRow($c, $rownum, (string)$cols[$c], PHPExcel_Cell_DataType::TYPE_STRING);
    }
    $rownum++;
}

// 보기 좋게: 기본 폰트 10 / 줄바꿈 OFF(한 줄 표시)
$sheet->getDefaultStyle()->getFont()->setName('맑은 고딕')->setSize(10);
$sheet->getDefaultStyle()->getAlignment()->setWrapText(false);

/* -----------------------------------------------------------
 * 6) 다운로드 출력 (xlsx)
 * --------------------------------------------------------- */
$filename = 'call_stats_detail_'.date('Ymd_His').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');

$writer = PHPExcel_IOFactory::createWriter($xls, 'Excel2007');
$writer->save('php://output');
exit;
