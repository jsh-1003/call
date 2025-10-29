<?php
// /adm/call/call_config.php
$sub_menu = "700770";
require_once './_common.php';

// -----------------------------
// 접근 권한: 레벨 7 미만 금지 + 메뉴 권한
// -----------------------------
if ((int)$member['mb_level'] < 7) alert('접근 권한이 없습니다.');
auth_check_menu($auth, $sub_menu, 'w');

$g5['title'] = '환경 설정';
require_once G5_ADMIN_PATH.'/admin.head.php';

// -----------------------------
// 내 권한/조직
// -----------------------------
$mb_level      = (int)($member['mb_level'] ?? 0);
$my_group      = (int)($member['mb_group'] ?? 0);     // 지점ID(=지점장 mb_no)
$my_company_id = (int)($member['company_id'] ?? 0);

// -----------------------------
// 회사/지점 선택 (변경된 권한 구조 적용)
//   - 9+: 회사/지점 자유 선택 (회사 0=전체, 지점 0=미선택)
//   - 8 : 회사 고정(본인 회사), 지점 선택(0=회사 내 미선택)
//   - 7 : 회사 고정(본인 회사), 지점 고정(본인 지점)
// -----------------------------
if ($mb_level >= 9) {
    $sel_company_id = (int)($_REQUEST['company_id'] ?? 0);  // 0=전체 (리스트/표시용)
    $sel_mb_group   = (int)($_REQUEST['mb_group']   ?? 0);  // 0=미선택
} elseif ($mb_level >= 8) {
    $sel_company_id = $my_company_id;
    $sel_mb_group   = (int)($_REQUEST['mb_group'] ?? 0);
} else { // 7
    $sel_company_id = $my_company_id;
    $sel_mb_group   = $my_group;
}

// -----------------------------
// 회사 옵션(레벨9+만)
// -----------------------------
$company_options = [];
if ($mb_level >= 9) {
    $rco = sql_query("
        SELECT m.mb_no AS company_id
        FROM {$g5['member_table']} m
        WHERE m.mb_level = 8
        ORDER BY COALESCE(NULLIF(m.company_name,''), CONCAT('회사-', m.mb_no)) ASC, m.mb_no ASC
    ");
    while ($r = sql_fetch_array($rco)) {
        $cid = (int)$r['company_id'];
        $company_options[] = [
            'company_id'   => $cid,
            'company_name' => get_company_name_cached($cid),
            'group_count'  => count_groups_by_company_cached($cid),
        ];
    }
}

// -----------------------------
// 지점(지점장=레벨7) 목록
//   - 9+: 선택된 회사가 있으면 해당 회사 지점만, 없으면 전체
//   - 8 : 내 회사 지점만
//   - 7 : 셀렉트 미노출(고정)
// -----------------------------
$leaders = [];
if ($mb_level >= 8) {
    $where_g = " WHERE m.mb_level = 7 ";
    if ($mb_level >= 9) {
        if ($sel_company_id > 0) $where_g .= " AND m.company_id='{$sel_company_id}' ";
    } else {
        $where_g .= " AND m.company_id='{$my_company_id}' ";
    }
    $res = sql_query("
        SELECT m.mb_no AS mb_group,
               COALESCE(NULLIF(m.mb_group_name,''), CONCAT('지점-', m.mb_no)) AS org_name,
               m.mb_id, m.mb_name, m.company_id
          FROM {$g5['member_table']} m
        {$where_g}
        ORDER BY org_name ASC, m.mb_no ASC
    ");
    while ($r = sql_fetch_array($res)) $leaders[] = $r;
}

// -----------------------------
// 조회 대상 결정 (폼 렌더링/로드용)
// -----------------------------
$view_company_id = $sel_company_id;
$view_mb_group   = $sel_mb_group;

// 레벨7은 고정
if ($mb_level == 7) {
    $view_company_id = $my_company_id;
    $view_mb_group   = $my_group;
}

// -----------------------------
// 현재 설정 로드
// -----------------------------
function load_config_row($company_id, $mb_group) {
    if ($mb_group <= 0) return null;
    $row = sql_fetch("SELECT * FROM call_config WHERE company_id='".(int)$company_id."' AND mb_group='".(int)$mb_group."' LIMIT 1");
    return $row ?: null;
}
$cfg = ($view_mb_group > 0) ? load_config_row($view_company_id, $view_mb_group) : null;

// 기본값
$default_api_cnt   = $cfg ? (int)$cfg['call_api_count']     : 3;
$default_lease_min = $cfg ? (int)$cfg['call_lease_min']     : 90;
$default_skip_sec  = $cfg ? (int)$cfg['call_auto_skip_sec'] : 30;

// -----------------------------
// 저장 처리 (POST)
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_admin_token();

    $post_company_id   = (int)($_POST['company_id'] ?? 0);
    $post_mb_group     = (int)($_POST['mb_group']   ?? 0);
    $post_api_cnt      = max(0, (int)($_POST['call_api_count']   ?? 3));
    $post_lease_min    = max(0, (int)($_POST['call_lease_min']   ?? 90));
    $post_skip_sec     = max(0, (int)($_POST['call_auto_skip_sec'] ?? 30));

    // 공통 권한
    if ($mb_level < 7) alert('접근 권한이 없습니다.');

    // 회사/지점 존재 검증 및 스코프 확인
    if ($mb_level >= 9) {
        // 회사 유효성
        if ($post_company_id <= 0) alert('회사를 선택하세요.');
        $comp = sql_fetch("SELECT 1 FROM {$g5['member_table']} WHERE mb_level=8 AND mb_no='{$post_company_id}' LIMIT 1");
        if (!$comp) alert('유효한 회사가 아닙니다.');

        // 지점 유효성: 해당 회사 소속 레벨7인지
        if ($post_mb_group <= 0) alert('지점을 선택하세요.');
        $chk = sql_fetch("SELECT 1 FROM {$g5['member_table']} WHERE mb_level=7 AND mb_no='{$post_mb_group}' AND company_id='{$post_company_id}' LIMIT 1");
        if (!$chk) alert('선택한 회사에 속한 유효한 지점이 아닙니다.');
    } elseif ($mb_level >= 8) {
        // 회사 고정
        $post_company_id = $my_company_id;
        if ($post_mb_group <= 0) alert('지점을 선택하세요.');
        $chk = sql_fetch("SELECT 1 FROM {$g5['member_table']} WHERE mb_level=7 AND mb_no='{$post_mb_group}' AND company_id='{$post_company_id}' LIMIT 1");
        if (!$chk) alert('자신의 회사 소속 지점만 설정할 수 있습니다.');
    } else { // 7
        $post_company_id = $my_company_id;
        $post_mb_group   = $my_group;
    }

    // upsert
    $exists = sql_fetch("SELECT cf_id FROM call_config WHERE company_id='{$post_company_id}' AND mb_group='{$post_mb_group}' LIMIT 1");
    if ($exists && isset($exists['cf_id'])) {
        sql_query("
            UPDATE call_config
               SET call_api_count='{$post_api_cnt}',
                   call_lease_min='{$post_lease_min}',
                   call_auto_skip_sec='{$post_skip_sec}'
             WHERE cf_id='".(int)$exists['cf_id']."'
             LIMIT 1
        ");
    } else {
        sql_query("
            INSERT INTO call_config
                   (company_id, mb_group, call_api_count, call_lease_min, call_auto_skip_sec)
            VALUES ('{$post_company_id}', '{$post_mb_group}', '{$post_api_cnt}', '{$post_lease_min}', '{$post_skip_sec}')
        ");
    }

    // 완료 후 현재 페이지로 (선택 유지)
    goto_url('./call_config.php?company_id='.$post_company_id.'&mb_group='.$post_mb_group);
    exit;
}

// -----------------------------
// 표기용 조직명
// -----------------------------
function _company_name_cached($cid){ return get_company_name_cached((int)$cid); }
function _group_name_cached($gid){ return get_group_name_cached((int)$gid); }

$disp_company = $view_company_id > 0 ? _company_name_cached($view_company_id) : ($mb_level>=9 ? '선택 안 함' : '-');
$disp_group   = $view_mb_group   > 0 ? _group_name_cached($view_mb_group)     : ($mb_level>=7 ? '선택 안 함' : '-');

// -----------------------------
// 출력
// -----------------------------
?>
<style>
    .tbl_frm01 .help { color:#666; font-size:12px; margin-top:6px; }
</style>

<form id="fconfig_select" method="get" action="./call_config.php" autocomplete="off" style="margin-bottom:10px;">
    <div class="tbl_frm01 tbl_wrap">
        <table>
            <caption>조직 선택</caption>
            <colgroup><col class="grid_4"><col></colgroup>
            <tbody>
            <?php if ($mb_level >= 9) { ?>
                <tr>
                    <th scope="row">회사</th>
                    <td>
                        <select name="company_id" id="company_id" onchange="this.form.submit();">
                            <option value="0"<?php echo $sel_company_id===0?' selected':'';?>>-- 전체 회사 --</option>
                            <?php foreach ($company_options as $c) { ?>
                                <option value="<?php echo (int)$c['company_id']; ?>" <?php echo get_selected($sel_company_id, (int)$c['company_id']); ?>>
                                    <?php echo get_text($c['company_name']); ?> (지점 <?php echo (int)$c['group_count']; ?>)
                                </option>
                            <?php } ?>
                        </select>
                        <div class="help">회사를 선택하면 지점 목록이 해당 회사 기준으로 표시됩니다.</div>
                    </td>
                </tr>
            <?php } else { ?>
                <input type="hidden" name="company_id" value="<?php echo (int)$sel_company_id; ?>">
            <?php } ?>

            <?php if ($mb_level >= 8) { ?>
                <tr>
                    <th scope="row">지점</th>
                    <td>
                        <select name="mb_group" id="mb_group" onchange="this.form.submit();">
                            <option value="0">-- 지점 선택 --</option>
                            <?php foreach ($leaders as $g): ?>
                                <option value="<?php echo (int)$g['mb_group']; ?>" <?php echo ($view_mb_group==(int)$g['mb_group'])?'selected':''; ?>>
                                    <?php
                                    $label = get_text($g['org_name']).' (리더: '.get_text($g['mb_name']).')';
                                    if ($mb_level>=9 && !empty($g['company_id'])) $label = '['.get_text(_company_name_cached((int)$g['company_id'])).'] '.$label;
                                    echo $label;
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">지점을 선택하면 해당 지점의 콜 설정을 편집할 수 있습니다.</div>
                    </td>
                </tr>
            <?php } else { // 7레벨 고정 ?>
                <input type="hidden" name="mb_group" value="<?php echo (int)$view_mb_group; ?>">
                <tr>
                    <th scope="row">대상 조직</th>
                    <td>
                        <b><?php echo get_text($disp_company); ?></b> / <?php echo get_text($disp_group); ?>
                        <div class="help">7레벨 관리자는 자신의 지점만 설정할 수 있습니다.</div>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</form>

<form id="fconfig" method="post" action="./call_config.php" autocomplete="off">
    <input type="hidden" name="token" value="<?php echo get_token(); ?>">
    <input type="hidden" name="company_id" value="<?php echo (int)$view_company_id; ?>">
    <input type="hidden" name="mb_group"   value="<?php echo (int)$view_mb_group; ?>">

    <div class="tbl_frm01 tbl_wrap">
        <table>
            <caption><?php echo $g5['title']; ?></caption>
            <colgroup>
                <col class="grid_4"><col>
                <col class="grid_4"><col>
            </colgroup>
            <tbody>
                <tr>
                    <th scope="row">대상 조직</th>
                    <td colspan="3">
                        <?php
                        if ($view_mb_group <= 0 || ($mb_level>=9 && $view_company_id<=0)) {
                            echo '<span style="color:#999">조직을 먼저 선택하세요.</span>';
                        } else {
                            $org = sql_fetch("
                                SELECT COALESCE(NULLIF(mb_group_name,''), CONCAT('지점-', mb_no)) AS org_name, mb_name, company_id
                                  FROM {$g5['member_table']}
                                 WHERE mb_no='{$view_mb_group}' AND mb_level=7
                                 LIMIT 1
                            ");
                            $cname = ($org && (int)$org['company_id']>0) ? _company_name_cached((int)$org['company_id']) : '-';
                            echo '<b>'.get_text($cname).'</b> / <b>'.get_text($org['org_name']).'</b> (리더: '.get_text($org['mb_name']).')';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="call_api_count">할당갯수</label></th>
                    <td>
                        <input type="number" name="call_api_count" id="call_api_count"
                               class="frm_input" style="width:120px;"
                               min="0" step="1"
                               value="<?php echo (int)$default_api_cnt; ?>">
                        <div class="help">앱 요청 시 상담원에게 한 번에 배정하는 기본 갯수 (기본: 3)</div>
                    </td>
                    <th scope="row"><label for="call_lease_min">유지시간(분)</label></th>
                    <td>
                        <input type="number" name="call_lease_min" id="call_lease_min"
                               class="frm_input" style="width:120px;"
                               min="0" step="1"
                               value="<?php echo (int)$default_lease_min; ?>">
                        <div class="help">배정 후 자동 회수까지의 시간(분) (기본: 90)</div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="call_auto_skip_sec">자동 스킵(초)</label></th>
                    <td>
                        <input type="number" name="call_auto_skip_sec" id="call_auto_skip_sec"
                               class="frm_input" style="width:120px;"
                               min="0" step="1"
                               value="<?php echo (int)$default_skip_sec; ?>">
                        <div class="help">앱에서 통화 연결되지 않을 때 자동 스킵까지 대기(초) (기본: 30)</div>
                    </td>
                    <th scope="row">상태</th>
                    <td>
                        <?php
                        if ($view_mb_group > 0 && ($mb_level<9 || $view_company_id>0)) {
                            echo $cfg ? '설정 있음' : '<span style="color:#999">설정 없음(저장 시 생성)</span>';
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="btn_fixed_top">
        <?php if ($mb_level >= 8) { ?>
            <a href="./call_config.php" class="btn btn_02">초기화</a>
        <?php } ?>
        <input type="submit" value="저장" class="btn_submit btn"
            <?php echo ($mb_level>=9 ? (($view_company_id>0 && $view_mb_group>0)?'':'disabled')
                                     : (($view_mb_group>0)?'':'disabled')); ?>>
    </div>
</form>

<?php require_once G5_ADMIN_PATH.'/admin.tail.php';
