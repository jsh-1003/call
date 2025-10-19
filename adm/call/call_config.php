<?php
// /adm/call/call_config.php
$sub_menu = "700770"; // 적절히 메뉴코드 배정
require_once './_common.php';

// -----------------------------
// 접근권한: 레벨 7 미만 금지
// -----------------------------
if ((int)$member['mb_level'] < 7) alert('접근 권한이 없습니다.');
auth_check_menu($auth, $sub_menu, 'w');

$g5['title'] = '환경 설정';
require_once G5_ADMIN_PATH.'/admin.head.php';

// -----------------------------
// 현재 관리자 정보
// -----------------------------
$my_level = (int)$member['mb_level'];
$my_mb_no = (int)$member['mb_no'];

// -----------------------------
// 회사(준비중) 고정
// -----------------------------
$company_id = 0; // 회사 기능 추가시 파라미터화 예정

// -----------------------------
// 그룹(=그룹장 mb_no) 목록: 레벨 8만 노출
// -----------------------------
$leaders = [];
if ($my_level >= 8) {
    $sql = "SELECT m.mb_no AS mb_group,
                   COALESCE(NULLIF(m.mb_group_name,''), CONCAT('그룹-', m.mb_no)) AS org_name,
                   m.mb_id, m.mb_name
            FROM {$g5['member_table']} m
            WHERE m.mb_level = 7
            ORDER BY org_name ASC, m.mb_no ASC";
    $res = sql_query($sql);
    while ($r = sql_fetch_array($res)) $leaders[] = $r;
}

// -----------------------------
// 조회 기준 그룹 결정
//   - 8레벨: GET/POST의 mb_group 선택값 (없으면 0)
//   - 7레벨: 내 그룹(=내 mb_no)
// -----------------------------
$req_mb_group = isset($_REQUEST['mb_group']) ? (int)$_REQUEST['mb_group'] : 0;
if ($my_level >= 8) {
    $view_mb_group = $req_mb_group;
} else { // 7레벨
    // 본인이 그룹장이어야 하므로 mb_group=자신의 mb_no
    $view_mb_group = $my_mb_no;
}

// -----------------------------
// 현재 설정 로드
// -----------------------------
function load_config($company_id, $mb_group) {
    if ($mb_group <= 0) return null;
    $row = sql_fetch("SELECT * FROM call_config WHERE company_id='{$company_id}' AND mb_group='{$mb_group}'");
    return $row ?: null;
}

$cfg = ($view_mb_group > 0) ? load_config($company_id, $view_mb_group) : null;

// 기본값
$default_api_cnt   = $cfg ? (int)$cfg['call_api_count']     : 3;
$default_lease_min = $cfg ? (int)$cfg['call_lease_min']     : 90;
$default_skip_sec  = $cfg ? (int)$cfg['call_auto_skip_sec'] : 20;

// -----------------------------
// 저장 처리(POST)
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 토큰(그누보드 기본 토큰 쓰는 경우 여기에 check 추가 가능)
    // check_admin_token(); // 프로젝트에서 사용 중이라면 주석 해제

    $post_mb_group = (int)($_POST['mb_group'] ?? 0);
    $post_api_cnt   = max(0, (int)($_POST['call_api_count'] ?? 3));
    $post_lease_min = max(0, (int)($_POST['call_lease_min'] ?? 90));
    $post_skip_sec  = max(0, (int)($_POST['call_auto_skip_sec'] ?? 20));

    // 권한 검증
    if ($my_level < 7) alert('접근 권한이 없습니다.');

    if ($my_level == 7) {
        // 7레벨은 자기 조직만
        if ($post_mb_group !== $my_mb_no) alert('자신의 그룹만 설정할 수 있습니다.');
    } else { // 8레벨
        if ($post_mb_group <= 0) alert('그룹을 선택하세요.');
        // 존재하는 그룹장인지(레벨7) 방어적 검증
        $chk = sql_fetch("SELECT COUNT(*) AS c FROM {$g5['member_table']} WHERE mb_no='{$post_mb_group}' AND mb_level=7");
        if ((int)$chk['c'] === 0) alert('유효한 그룹장이 아닙니다.');
    }

    // upsert (테이블에 unique가 없으므로 수동 분기)
    $exists = sql_fetch("SELECT cf_id FROM call_config WHERE company_id='{$company_id}' AND mb_group='{$post_mb_group}'");
    if ($exists && isset($exists['cf_id'])) {
        $sql = "UPDATE call_config
                   SET call_api_count='{$post_api_cnt}',
                       call_lease_min='{$post_lease_min}',
                       call_auto_skip_sec='{$post_skip_sec}'
                 WHERE cf_id='".(int)$exists['cf_id']."'";
        sql_query($sql);
    } else {
        $sql = "INSERT INTO call_config
                   SET company_id='{$company_id}',
                       mb_group='{$post_mb_group}',
                       call_api_count='{$post_api_cnt}',
                       call_lease_min='{$post_lease_min}',
                       call_auto_skip_sec='{$post_skip_sec}'";
        sql_query($sql);
    }

    // 완료 후 현재 페이지로 리다이렉트(선택 그룹 유지)
    goto_url('./call_config.php?mb_group='.$post_mb_group);
}

// -----------------------------
// 출력
// -----------------------------
?>
<style>
    .tbl_frm01 .help { color:#666; font-size:12px; margin-top:6px; }
</style>

<form id="fconfig_select" method="get" action="./call_config.php" autocomplete="off" style="margin-bottom:10px;">
    <?php if ($my_level >= 8) { ?>
        <div class="tbl_frm01 tbl_wrap">
            <table>
                <caption>그룹 선택</caption>
                <colgroup><col class="grid_4"><col></colgroup>
                <tbody>
                <tr>
                    <th scope="row">그룹</th>
                    <td>
                        <select name="mb_group" id="mb_group" onchange="this.form.submit();">
                            <option value="0">-- 그룹 선택 --</option>
                            <?php foreach ($leaders as $g): ?>
                                <option value="<?php echo (int)$g['mb_group']; ?>" <?php echo ($view_mb_group==(int)$g['mb_group'])?'selected':''; ?>>
                                    <?php echo get_text($g['org_name']); ?> (리더: <?php echo get_text($g['mb_name']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">그룹을 선택하면 해당 그룹의 콜 설정을 편집할 수 있습니다.</div>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    <?php } else { ?>
        <input type="hidden" name="mb_group" value="<?php echo (int)$view_mb_group; ?>">
    <?php } ?>
</form>

<form id="fconfig" method="post" action="./call_config.php" autocomplete="off">
    <input type="hidden" name="mb_group" value="<?php echo (int)$view_mb_group; ?>">
    <div class="tbl_frm01 tbl_wrap">
        <table>
            <caption><?php echo $g5['title']; ?></caption>
            <colgroup>
                <col class="grid_4"><col>
                <col class="grid_4"><col>
            </colgroup>
            <tbody>
                <tr>
                    <th scope="row">대상 그룹</th>
                    <td colspan="3">
                        <?php
                        if ($view_mb_group <= 0) {
                            echo '<span style="color:#999">조직을 먼저 선택하세요.</span>';
                        } else {
                            // 표기용 이름
                            $org = sql_fetch("SELECT COALESCE(NULLIF(mb_group_name,''), CONCAT('그룹-', mb_no)) AS org_name, mb_name
                                              FROM {$g5['member_table']} WHERE mb_no='{$view_mb_group}'");
                            echo '<b>'.get_text($org['org_name']).'</b> (리더: '.get_text($org['mb_name']).')';
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
                        if ($view_mb_group > 0) {
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
        <?php if ($my_level >= 8) { ?>
            <a href="./call_config.php" class="btn btn_02">초기화</a>
        <?php } ?>
        <input type="submit" value="저장" class="btn_submit btn" <?php echo ($view_mb_group<=0)?'disabled':''; ?>>
    </div>
</form>

<?php require_once G5_ADMIN_PATH.'/admin.tail.php';
