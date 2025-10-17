<?php
// /adm/call/index.php
$sub_menu = '700100';
require_once './_common.php';

// 접근 권한
if ($is_admin !== 'super' && (int)$member['mb_level'] < 3) {
    alert('접근 권한이 없습니다.');
}

/** JSON 미리보기(1줄 요약 + 펼치기 토글) */
function pretty_json_preview($json_str){
    if ($json_str === null || $json_str === '') return '';
    $data = json_decode($json_str, true);
    if ($data === null) return '<span class="small-muted">'. _h($json_str) .'</span>';
    $pretty = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $firstKeys = array_slice(array_keys($data), 0, 3);
    $summary = implode(', ', $firstKeys);
    return '<details><summary>'. _h($summary ?: 'json') .'</summary><pre class="json">'. _h($pretty) .'</pre></details>';
}

// ----------------------------------------------------------------------------------
// 멤버/권한
// ----------------------------------------------------------------------------------
global $g5;
$mb_no    = (int)($member['mb_no'] ?? 0);
$mb_level = (int)($member['mb_level'] ?? 0);
$my_group = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;

// ----------------------------------------------------------------------------------
// 검색/필터 파라미터
// ----------------------------------------------------------------------------------
$q       = _g('q', '');
$q_type  = _g('q_type', ''); // name | last4 | full
$f_dnc   = _g('dnc', '');    // '', '0', '1'
$f_asgn  = _g('as', '');     // 배정상태 필터: '', '0','1','2','3'
$page    = max(1, (int)_g('page','1'));
$rows    = max(10, min(200, (int)_g('rows','50')));
$offset  = ($page-1) * $rows;

// 배정상태 라벨
$ASSIGN_LABEL = [
    0=>'미배정', 1=>'배정', 2=>'진행중', 3=>'완료'
];

// ----------------------------------------------------------------------------------
// WHERE 구성 (+ 삭제 캠페인 제외 조건)
// ----------------------------------------------------------------------------------
$where = [];
// 권한별 범위
if ($mb_level >= 8) {
    // no where
} elseif ($mb_level == 7) {
    $where[] = "t.mb_group = {$my_group}";
} else {
    $where[] = "t.assigned_mb_no = {$mb_no}";
}

// 검색
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
// DNC 필터
if ($f_dnc === '0' || $f_dnc === '1') {
    $where[] = "t.do_not_call = ".(int)$f_dnc;
}
// 배정상태 필터
if ($f_asgn !== '' && in_array($f_asgn, ['0','1','2','3'], true)) {
    $where[] = "t.assigned_status = ".(int)$f_asgn;
}

// ★ 삭제 캠페인 제외
$where[] = "c.status <> 9";

$where_sql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// ----------------------------------------------------------------------------------
// count (캠페인 조인 포함)
// ----------------------------------------------------------------------------------
$sql_cnt = "
    SELECT COUNT(*) AS cnt
    FROM call_target t
    JOIN call_campaign c ON c.campaign_id = t.campaign_id
    {$where_sql}
";
$row_cnt = sql_fetch($sql_cnt);
$total_count = (int)($row_cnt['cnt'] ?? 0);

// ----------------------------------------------------------------------------------
// 목록 (캠페인 조인 + 상태/이름 가져오기)
// ----------------------------------------------------------------------------------
$sql_list = "
    SELECT
        t.target_id, t.campaign_id, t.mb_group, t.call_hp, t.hp_last4,
        t.name, t.birth_date, t.meta_json,
        t.assigned_status, t.assigned_mb_no, t.assigned_at, t.assign_lease_until, t.assign_batch_id,
        t.do_not_call, t.last_call_at, t.last_result, t.attempt_count, t.next_try_at,
        t.created_at, t.updated_at,
        c.status AS campaign_status,
        c.name AS campaign_name
    FROM call_target t
    JOIN call_campaign c ON c.campaign_id = t.campaign_id
    {$where_sql}
    ORDER BY t.target_id DESC
    LIMIT {$offset}, {$rows}
";
$res = sql_query($sql_list);

// 상태코드 라벨 캐시 (last_result 표시용)
$status_cache = [];
function status_label($code){
    global $status_cache;
    $code = (int)$code;
    if ($code <= 0) return '';
    if (!isset($status_cache[$code])) {
        $r = sql_fetch("SELECT name_ko FROM call_status_code WHERE call_status={$code} AND mb_group=0 LIMIT 1");
        $status_cache[$code] = $r ? $r['name_ko'] : ('코드 '.$code);
    }
    return $status_cache[$code];
}

// ----------------------------------------------------------------------------------
// 화면
// ----------------------------------------------------------------------------------
include_once(G5_ADMIN_PATH.'/admin.head.php');
?>
<style>
.form-row { margin:10px 0; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.tbl_head01 th, .tbl_head01 td { text-align:center; vertical-align:middle; }
.badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; }
.badge-dnc { background:#dc3545; color:#fff; }
.badge-ok  { background:#28a745; color:#fff; }
.badge-warn{ background:#ffc107; color:#222; }
.badge-camp-inactive { background:#eaeaea; color:#666; border:1px solid #d0d0d0; }
.small-muted { color:#888; font-size:12px; }
pre.json { text-align:left; white-space:pre-wrap; background:#f8f9fa; padding:8px; border:1px solid #eee; border-radius:4px; }
td.meta { text-align:left !important; }
td.camp-cell { text-align:left !important; }
tr.camp-inactive td { background-image: linear-gradient(to right, rgba(0,0,0,0.025), rgba(0,0,0,0.025)); }
</style>

<div class="local_ov01 local_ov">
    <h2>통화 대상 목록</h2>
</div>

<div class="local_sch01 local_sch">
    <form method="get" action="./index.php" class="form-row">
        <label for="q_type">검색구분</label>
        <select name="q_type" id="q_type">
            <option value="name"  <?php echo $q_type==='name'?'selected':'';?>>이름</option>
            <option value="last4" <?php echo $q_type==='last4'?'selected':'';?>>전화번호(끝4자리)</option>
            <option value="full"  <?php echo $q_type==='full'?'selected':'';?>>전화번호(전체)</option>
        </select>
        <input type="text" name="q" value="<?php echo _h($q);?>" class="frm_input" style="width:220px" placeholder="검색어 입력">

        <label for="dnc">DNC</label>
        <select name="dnc" id="dnc">
            <option value=""  <?php echo $f_dnc===''?'selected':'';?>>전체</option>
            <option value="0" <?php echo $f_dnc==='0'?'selected':'';?>>N</option>
            <option value="1" <?php echo $f_dnc==='1'?'selected':'';?>>Y</option>
        </select>

        <label for="as">배정상태</label>
        <select name="as" id="as">
            <option value=""  <?php echo $f_asgn===''?'selected':'';?>>전체</option>
            <?php foreach ([0=>'미배정',1=>'배정',2=>'진행중',3=>'완료'] as $k=>$v){ ?>
                <option value="<?php echo $k;?>" <?php echo $f_asgn!=='' && (int)$f_asgn===$k?'selected':'';?>><?php echo _h($v);?></option>
            <?php } ?>
        </select>

        <label for="rows">표시건수</label>
        <select name="rows" id="rows">
            <?php foreach ([20,50,100,200] as $opt){ ?>
                <option value="<?php echo $opt;?>" <?php echo $rows==$opt?'selected':'';?>><?php echo $opt;?></option>
            <?php } ?>
        </select>

        <button type="submit" class="btn btn_01">검색</button>
        <?php if ($where_sql){ ?><a href="./index.php" class="btn btn_02">초기화</a><?php } ?>

        <span class="small-muted" style="margin-left:auto">
        권한:
        <?php
            if ($mb_level >= 8) echo '전사 조회';
            elseif ($mb_level == 7) echo '그룹 제한 (mb_group='.$my_group.')';
            else echo '개인 제한 (assigned_mb_no='.$mb_no.')';
        ?>
        </span>
    </form>
</div>

<div class="tbl_head01 tbl_wrap">
    <table class="table-fixed">
        <thead>
            <tr>
                <!-- <th style="width:70px">ID</th> -->
                <th style="width:90px">그룹</th>
                <th style="width:220px">캠페인</th> <!-- 추가 -->
                <th style="width:140px">전화번호</th>
                <th style="width:140px">이름/나이</th>
                <th style="width:110px">배정상태</th>
                <th style="width:120px">담당자</th>
                <th style="width:80px">DNC</th>
                <th style="width:130px">통화결과</th>
                <!-- <th style="width:80px">시도수</th>
                <th style="width:160px">다음시도</th> -->
                <th>추가정보</th>
                <th style="width:150px">업데이트</th>
            </tr>
        </thead>
        <tbody>
        <?php
        if ($total_count === 0){
            echo '<tr><td colspan="10" class="empty_table">데이터가 없습니다.</td></tr>';
        } else {
            $cached_group_name = [];
            while ($row = sql_fetch_array($res)) {
                $hp_fmt = format_korean_phone($row['call_hp']);
                $age = calc_age_years($row['birth_date']);
                $age_txt = is_null($age) ? '-' : ($age.'세(만)');
                $as = (int)$row['assigned_status'];
                $as_label = isset($ASSIGN_LABEL[$as]) ? $ASSIGN_LABEL[$as] : (string)$as;
                $dnc = (int)$row['do_not_call']===1;
                $last_result = (int)$row['last_result'];
                $last_label  = $last_result ? status_label($last_result) : '';
                $group_name = get_group_name($row['mb_group']);
                // meta (짧게)
                // $meta_arr = json_decode($row['meta_json'], true);
                // $meta_html = '';
                // if (is_array($meta_arr) && $meta_arr) {
                //     $pairs = [];
                //     $i=0;
                //     foreach ($meta_arr as $k=>$v) { $pairs[] = _h($k).': '._h($v); if (++$i>=5) break; }
                //     $meta_html = implode(', ', $pairs);
                // }
                // $meta_html   = implode(', ', json_decode($row['meta_json'], true));
                $meta_html   = implode(', ', json_decode($row['meta_json'], true));
                $agent_txt   = $row['assigned_mb_no'] ? (int)$row['assigned_mb_no'] : '-';

                $camp_inactive = ((int)$row['campaign_status'] === 0);
                $tr_class = $camp_inactive ? 'camp-inactive' : '';
                ?>
                <tr class="<?php echo $tr_class; ?>">
                    <!-- <td><?php // echo (int)$row['target_id'];?></td> -->
                    <td><?php echo $group_name; ?></td>
                    <!-- 캠페인 표시(denorm 우선, 없으면 c.name) + 비활성 배지 -->
                    <td class="camp-cell">
                        <?php echo _h($row['campaign_name']); ?>
                        <?php if ($camp_inactive) { ?>
                            <span class="badge badge-camp-inactive">비활성 캠페인</span>
                        <?php } ?>
                    </td>
                    <td><?php echo _h($hp_fmt);?></td>

                    <td><?php echo _h($row['name']);?> / <?php echo _h($age_txt);?></td>
                    <td><?php echo _h($as_label);?></td>
                    <td><?php echo _h($agent_txt);?></td>
                    <td>
                        <?php if ($dnc){ ?>
                            <span class="badge badge-dnc">Y</span>
                        <?php } else { ?>
                            <span class="badge badge-ok">N</span>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if ($last_result){ echo (int)$last_result.' - '._h($last_label); } ?>
                        <?php if ($row['last_call_at']){ echo '<div class="small-muted">'._h($row['last_call_at']).'</div>'; } ?>
                    </td>
                    <!-- <td><?php echo (int)$row['attempt_count'];?></td>
                    <td><?php echo _h($row['next_try_at'] ?: ''); ?></td> -->
                    <td class="meta"><?php echo $meta_html; ?></td>
                    <td><?php echo substr(_h($row['updated_at']), 2, 17);?></td>
                </tr>
                <?php
            }
        }
        ?>
        </tbody>
    </table>
</div>

<?php
// 페이징 (그누보드 get_paging 사용)
$total_page = max(1, (int)ceil($total_count / $rows));

// 기존 쿼리에서 page만 제거해 보존
$qstr_arr = $_GET;
unset($qstr_arr['page']);
$qstr = http_build_query($qstr_arr);

// get_paging 출력 (모바일/웹 설정값 반영)
echo '<div class="pg_wrap">';
echo get_paging(
    $config['cf_write_pages'],
    $page,
    $total_page,
    "./index.php?{$qstr}&amp;page="
);
echo '</div>';
?>


<?php
include_once(G5_ADMIN_PATH.'/admin.tail.php');
