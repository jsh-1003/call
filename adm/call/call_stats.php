<?php
// /adm/call/call_stats.php
$sub_menu = '700200';
require_once './_common.php';

// 접근 권한: 관리자 레벨 7 이상만
if ($is_admin !== 'super' && (int)$member['mb_level'] < 7) {
    alert('접근 권한이 없습니다.');
}

// --------------------------------------------------
// 기본/입력 파라미터
// --------------------------------------------------
$mb_no    = (int)($member['mb_no'] ?? 0);
$mb_level = (int)($member['mb_level'] ?? 0);
$my_group = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;

// 기간(기본: 최근 1개월)
$today = date('Y-m-d');
$default_start = date('Y-m-d', strtotime('-1 month', strtotime($today)));
$start_date = _g('start', $default_start);
$end_date   = _g('end',   $today);

// 검색/필터
$q         = _g('q', '');
$q_type    = _g('q_type', '');           // name | last4 | full
$f_status  = isset($_GET['status']) ? (int)$_GET['status'] : 0;  // 0=전체
$page      = max(1, (int)(_g('page', '1')));
$page_rows = max(10, min(200, (int)(_g('rows', '50'))));
$offset    = ($page - 1) * $page_rows;

// --------------------------------------------------
// WHERE 구성
// --------------------------------------------------
$where = [];

// 기간 조건 (종료일 23:59:59 포함)
$start_esc = sql_escape_string($start_date.' 00:00:00');
$end_esc   = sql_escape_string($end_date.' 23:59:59');
$where[] = "l.call_start BETWEEN '{$start_esc}' AND '{$end_esc}'";

// 상태 코드 필터
if ($f_status > 0) {
    $where[] = "l.call_status = {$f_status}";
}

// 검색어
if ($q !== '' && $q_type !== '') {
    if ($q_type === 'name') {
        $q_esc = sql_escape_string($q);
        $where[] = "t.name LIKE '%{$q_esc}%'";
    } elseif ($q_type === 'last4') {
        $q4 = preg_replace('/\D+/', '', $q);
        $q4 = substr($q4, -4);
        if ($q4 !== '') {
            $q4_esc = sql_escape_string($q4);
            $where[] = "t.hp_last4 = '{$q4_esc}'";
        }
    } elseif ($q_type === 'full') {
        $hp = preg_replace('/\D+/', '', $q);
        if ($hp !== '') {
            $hp_esc = sql_escape_string($hp);
            $where[] = "l.call_hp = '{$hp_esc}'";
        }
    }
}

// 권한 필터
// mb_level >= 8: 제한 없음
// mb_level == 7: mb_group만 제한
// mb_level < 7 : mb_no만 제한
if ($mb_level == 7) {
    $where[] = "l.mb_group = {$my_group}";
} elseif ($mb_level < 7) {
    $where[] = "l.mb_no = {$mb_no}";
}

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// --------------------------------------------------
// 상태코드 목록 (셀렉트 박스용, 전역)
// --------------------------------------------------
$codes = [];
$qc = "
    SELECT call_status, name_ko, status
      FROM call_status_code
     WHERE mb_group=0
     ORDER BY sort_order ASC, call_status ASC
";
$rc = sql_query($qc);
while ($r = sql_fetch_array($rc)) $codes[] = $r;

// --------------------------------------------------
// 총 건수
// --------------------------------------------------
$sql_cnt = "
    SELECT COUNT(*) AS cnt
      FROM call_log l
      JOIN call_target t ON t.target_id = l.target_id
    {$where_sql}
";
$row_cnt = sql_fetch($sql_cnt);
$total_count = (int)($row_cnt['cnt'] ?? 0);

// --------------------------------------------------
// 목록 쿼리
// - 담당자 이름: g5_member join (mb_no)
// - 대상자 만 나이: MySQL에서 계산
// --------------------------------------------------
$member_table = $g5['member_table']; // g5_member
$sql_list = "
    SELECT
        l.call_id, l.call_start, l.call_end, l.call_time, l.call_status,
        l.mb_group, l.mb_no, l.call_hp, l.campaign_id,
        t.name AS target_name, t.target_id, t.birth_date,
        m.mb_name AS agent_name,
        CASE
          WHEN t.birth_date IS NULL THEN NULL
          ELSE TIMESTAMPDIFF(YEAR, t.birth_date, CURDATE())
               - (DATE_FORMAT(CURDATE(),'%m%d') < DATE_FORMAT(t.birth_date,'%m%d'))
        END AS age_years
    FROM call_log l
    JOIN call_target t ON t.target_id = l.target_id
    LEFT JOIN {$member_table} m ON m.mb_no = l.mb_no
    {$where_sql}
    ORDER BY l.call_start DESC, l.call_id DESC
    LIMIT {$offset}, {$page_rows}
";
$res_list = sql_query($sql_list);

// --------------------------------------------------
// 요약(집계) - 상태코드별 카운트 + 성공/실패 그룹 카운트
// --------------------------------------------------
$sql_sum = "
    SELECT
        l.call_status,
        COALESCE(c.name_ko, CONCAT('코드 ', l.call_status)) AS label,
        COALESCE(c.result_group, CASE WHEN l.call_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS result_group,
        COUNT(*) AS cnt
    FROM call_log l
    LEFT JOIN call_status_code c
           ON c.call_status = l.call_status AND c.mb_group = 0
    {$where_sql}
    GROUP BY l.call_status, label, result_group
    ORDER BY cnt DESC
";
$res_sum = sql_query($sql_sum);
$stat_by_status = [];
$success_total = 0; $fail_total = 0;
while ($r = sql_fetch_array($res_sum)) {
    $stat_by_status[] = $r;
    if ((int)$r['result_group'] === 1) $success_total += (int)$r['cnt'];
    else $fail_total += (int)$r['cnt'];
}

$token = get_token();
$g5['title'] = '통계확인';
include_once(G5_ADMIN_PATH.'/admin.head.php');
$listall = '<a href="'.$_SERVER['SCRIPT_NAME'].'" class="ov_listall">전체목록</a>';
?>
<style>
.form-row { margin:10px 0; }
.form-row .frm_input { height:28px; }
.tbl_head01 th, .tbl_head01 td { text-align:center; }
.badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:12px; }
.badge-success { background:#28a745; color:#fff; }
.badge-fail    { background:#6c757d; color:#fff; }
.badge-dnc     { background:#dc3545; color:#fff; }
.small-muted { color:#888; font-size:12px; }
.table-fixed td { word-break:break-all; }
</style>

<!-- <div class="local_ov01 local_ov">
    <?php echo $listall; ?>
</div> -->

<!-- 검색/필터 -->
<div class="local_sch01 local_sch">
    <form method="get" action="./call_stats.php" class="form-row">
        <label for="start">기간</label>
        <input type="date" id="start" name="start" value="<?php echo get_text($start_date);?>" class="frm_input" style="width:140px">
        ~
        <input type="date" id="end" name="end" value="<?php echo get_text($end_date);?>" class="frm_input" style="width:140px">

        &nbsp;&nbsp;|&nbsp;&nbsp;

        <label for="q_type">검색구분</label>
        <select name="q_type" id="q_type" style="width:80px">
            <option value="">전체</option>
            <option value="name"  <?php echo $q_type==='name'?'selected':'';?>>이름</option>
            <option value="last4" <?php echo $q_type==='last4'?'selected':'';?>>전화번호(끝4자리)</option>
            <option value="full"  <?php echo $q_type==='full'?'selected':'';?>>전화번호(전체)</option>
        </select>
        <input type="text" name="q" value="<?php echo get_text($q);?>" class="frm_input" style="width:140px" placeholder="검색어 입력">

        &nbsp;&nbsp;|&nbsp;&nbsp;

        <label for="status">상태코드</label>
        <select name="status" id="status">
            <option value="0">전체</option>
            <?php foreach ($codes as $c) { ?>
                <option value="<?php echo (int)$c['call_status'];?>" <?php echo ($f_status===(int)$c['call_status']?'selected':'');?>>
                    <?php echo (int)$c['call_status'].' - '.get_text($c['name_ko']);?><?php echo ((int)$c['status']===1?'':' (비활성)');?>
                </option>
            <?php } ?>
        </select>

        &nbsp;&nbsp;|&nbsp;&nbsp;

        <label for="rows">표시건수</label>
        <select name="rows" id="rows">
            <?php foreach ([20,50,100,200] as $opt) { ?>
                <option value="<?php echo $opt;?>" <?php echo $page_rows==$opt?'selected':'';?>><?php echo $opt;?></option>
            <?php } ?>
        </select>

        <button type="submit" class="btn btn_01">검색</button>
        <?php if ($where_sql) { ?>
        <a href="./call_stats.php" class="btn btn_02">초기화</a>
        <?php } ?>
        <span class="small-muted">권한:
            <?php
            if ($mb_level >= 8) echo '전체';
            elseif ($mb_level == 7) echo '조직';
            else echo '개인';
            ?>
        </span>
    </form>
</div>

<!-- 요약 -->
<div class="local_desc01 local_desc">
    <p>
        총 통화: <b><?php echo number_format($total_count);?></b> 건
        &nbsp;|&nbsp;
        성공: <span class="badge badge-success"><?php echo number_format($success_total);?></span>
        &nbsp; / &nbsp;
        실패: <span class="badge badge-fail"><?php echo number_format($fail_total);?></span>
    </p>
    <?php if ($stat_by_status) { ?>
    <div class="tbl_head01 tbl_wrap" style="margin-top:10px;">
        <table>
            <thead>
                <tr>
                    <th style="width:120px">상태코드</th>
                    <th>라벨</th>
                    <th style="width:120px">그룹</th>
                    <th style="width:120px">건수</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stat_by_status as $s) { ?>
                <tr>
                    <td><?php echo (int)$s['call_status'];?></td>
                    <td><?php echo get_text($s['label']);?></td>
                    <td>
                        <?php if ((int)$s['result_group']===1) { ?>
                            <span class="badge badge-success">성공</span>
                        <?php } else { ?>
                            <span class="badge badge-fail">실패</span>
                        <?php } ?>
                    </td>
                    <td><?php echo number_format((int)$s['cnt']);?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <?php } ?>
</div>

<!-- 목록 -->
<div class="tbl_head01 tbl_wrap">
    <table class="table-fixed">
        <thead>
            <tr>
                <th style="width:90px">Call ID</th>
                <th style="width:160px">통화시작</th>
                <th style="width:80px">길이(초)</th>
                <th style="width:90px">상태코드</th>
                <th>라벨</th>
                <th style="width:110px">그룹</th>
                <th style="width:160px">전화번호</th>
                <th style="width:140px">대상자</th>
                <th style="width:140px">담당자</th>
            </tr>
        </thead>
        <tbody>
        <?php
        if ($total_count === 0) {
            echo '<tr><td colspan="9" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            // call_status 라벨/그룹/DNC 캐시
            $label_cache = [];
            while ($row = sql_fetch_array($res_list)) {
                $status_code = (int)$row['call_status'];
                if (!isset($label_cache[$status_code])) {
                    $r = sql_fetch("
                        SELECT name_ko, result_group, is_do_not_call
                          FROM call_status_code
                         WHERE call_status={$status_code} AND mb_group=0
                         LIMIT 1
                    ");
                    if ($r) $label_cache[$status_code] = $r;
                    else $label_cache[$status_code] = [
                        'name_ko' => "코드 {$status_code}",
                        'result_group' => ($status_code>=200 && $status_code<300?1:0),
                        'is_do_not_call'=>0
                    ];
                }
                $lab = $label_cache[$status_code]['name_ko'];
                $grp = (int)$label_cache[$status_code]['result_group']===1 ? '성공' : '실패';
                $is_dnc = (int)$label_cache[$status_code]['is_do_not_call']===1;

                $hp_fmt = format_korean_phone($row['call_hp']);
                $agent  = $row['agent_name'] ? get_text($row['agent_name']) : (string)$row['mb_no'];
                $age    = is_null($row['age_years']) ? '-' : (int)$row['age_years'].'세(만)';
                $target = trim(($row['target_name'] ?? ''))!=='' ? (get_text($row['target_name']).' / '.$age) : $age;
                ?>
                <tr>
                    <td><?php echo (int)$row['call_id'];?></td>
                    <td><?php echo get_text($row['call_start']);?></td>
                    <td><?php echo (int)$row['call_time'];?></td>
                    <td><?php echo $status_code;?></td>
                    <td>
                        <?php echo get_text($lab);?>
                        <?php if ($is_dnc) { ?><span class="badge badge-dnc" style="margin-left:6px;">DNC</span><?php } ?>
                    </td>
                    <td><?php echo $grp;?></td>
                    <td><?php echo get_text($hp_fmt);?></td>
                    <td><?php echo $target;?></td>
                    <td><?php echo get_text($agent);?></td>
                </tr>
                <?php
            }
        }
        ?>
        </tbody>
    </table>
</div>

<!-- 페이징 -->
<?php
$total_page = max(1, (int)ceil($total_count / $page_rows));
$qstr = $_GET; unset($qstr['page']);
$base = './call_stats.php?'.http_build_query($qstr);
?>
<div class="pg_wrap">
    <span class="pg">
        <?php if ($page > 1) { ?>
            <a href="<?php echo $base.'&page=1';?>" class="pg_page">처음</a>
            <a href="<?php echo $base.'&page='.($page-1);?>" class="pg_page">이전</a>
        <?php } else { ?>
            <span class="pg_page">처음</span><span class="pg_page">이전</span>
        <?php } ?>
        <span class="pg_current"><?php echo $page;?> / <?php echo $total_page;?></span>
        <?php if ($page < $total_page) { ?>
            <a href="<?php echo $base.'&page='.($page+1);?>" class="pg_page">다음</a>
            <a href="<?php echo $base.'&page='.$total_page;?>" class="pg_page">끝</a>
        <?php } else { ?>
            <span class="pg_page">다음</span><span class="pg_page">끝</span>
        <?php } ?>
    </span>
</div>

<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
