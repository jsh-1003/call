<?php
// /adm/call_member_form.php
$sub_menu = "700750";
require_once './_common.php';

// 접근: 레벨 7 미만 금지
if ((int)$member['mb_level'] < 7) alert('접근 권한이 없습니다.');
auth_check_menu($auth, $sub_menu, 'w');

$g5['title'] = '약식 회원 등록/수정';
require_once G5_ADMIN_PATH.'/admin.head.php';

// -----------------------------
// 파라미터/현재 관리자
// -----------------------------
$w      = isset($_REQUEST['w']) ? trim($_REQUEST['w']) : '';
$mb_id  = isset($_REQUEST['mb_id']) ? trim($_REQUEST['mb_id']) : '';

$my_level = (int)$member['mb_level'];
$my_mb_no = (int)$member['mb_no'];

// -----------------------------
// 역할 <-> 레벨 매핑
// -----------------------------
function role_to_level($role) {
    switch ($role) {
        case 'admin':  return 8;
        case 'leader': return 7;
        case 'member': return 3;
        default:       return 0;
    }
}
function level_to_role($lv) {
    if ($lv >= 8) return 'admin';
    if ($lv == 7) return 'leader';
    return 'member';
}

// 내가 생성/수정 시 허용되는 역할
$allowed_roles = [];
if ($my_level >= 10)      $allowed_roles = ['admin','leader','member'];
elseif ($my_level >= 8)   $allowed_roles = ['leader','member'];
else /* ==7 */            $allowed_roles = ['member'];

// -----------------------------
// 대상 조회(수정) 및 보호
// -----------------------------
$mb = [];
$is_new = ($w !== 'u');
if (!$is_new) {
    $mb = get_member($mb_id);
    if (!(isset($mb['mb_id']) && $mb['mb_id'])) alert('존재하지 않는 회원입니다.');
    if ($my_level < (int)$mb['mb_level']) alert('해당 회원을 수정할 권한이 없습니다.');
}

// -----------------------------
// 레벨 8+: 조직(그룹장) 목록(드롭다운 표기용)
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
// 기본값
// -----------------------------
$default_role = $is_new ? 'member' : level_to_role((int)$mb['mb_level']);
$default_mb_group = $is_new ? (($my_level >= 8) ? 0 : $my_mb_no) : (int)$mb['mb_group'];
$default_mb_group_name = $is_new ? ($my_level==7 ? (string)$member['mb_group_name'] : '') : (string)$mb['mb_group_name'];
$default_call_api_count = $is_new ? 3 : (int)$mb['call_api_count'];   // 기본 3
$default_call_lease_min = $is_new ? 90 : (int)$mb['call_lease_min'];  // 기본 90

// -----------------------------
// 닉/메일 유니크 자동 생성(폼 비노출)
// -----------------------------
function email_valid($email) {
    if (!$email) return false;
    if (function_exists('get_email_address')) {
        $filtered = get_email_address(trim($email));
        if ($filtered !== '') return (bool)filter_var($filtered, FILTER_VALIDATE_EMAIL);
        return false;
    }
    return (bool)filter_var(trim($email), FILTER_VALIDATE_EMAIL);
}
function nick_exists($nick, $exclude_mb_id = '') {
    global $g5;
    $nick = sql_escape_string($nick);
    $cond = $exclude_mb_id ? " AND mb_id <> '".sql_escape_string($exclude_mb_id)."'" : "";
    $row = sql_fetch("SELECT COUNT(*) AS c FROM {$g5['member_table']} WHERE mb_nick='{$nick}'{$cond}");
    return ((int)$row['c'] > 0);
}
function email_exists($email, $exclude_mb_id = '') {
    global $g5;
    $email = sql_escape_string($email);
    $cond = $exclude_mb_id ? " AND mb_id <> '".sql_escape_string($exclude_mb_id)."'" : "";
    $row = sql_fetch("SELECT COUNT(*) AS c FROM {$g5['member_table']} WHERE mb_email='{$email}'{$cond}");
    return ((int)$row['c'] > 0);
}
function gen_unique_nick($base_hint = 'user', $exclude_mb_id = '') {
    $base = preg_replace('/[^가-힣a-zA-Z0-9]/u', '', $base_hint);
    if ($base === '') $base = 'user';
    for ($i=0; $i<50; $i++) {
        $nick = '닉'.$base.substr((string)mt_rand(1000,9999), -4);
        if (!nick_exists($nick, $exclude_mb_id)) return $nick;
    }
    return '닉user'.time();
}
function gen_unique_email($id_hint = 'user', $exclude_mb_id = '') {
    $local = preg_replace('/[^a-z0-9._-]/i', '', strtolower($id_hint));
    if ($local === '') $local = 'user';
    for ($i=0; $i<50; $i++) {
        $candidate = $local.'-'.mt_rand(1000,9999).'-'.substr((string)time(), -6).'@example.com';
        if (email_valid($candidate) && !email_exists($candidate, $exclude_mb_id)) return $candidate;
    }
    return 'user-'.time().'@example.com';
}

// -----------------------------
// 저장 처리
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_w   = isset($_POST['w']) ? trim($_POST['w']) : '';
    $post_id  = isset($_POST['mb_id']) ? trim($_POST['mb_id']) : '';
    $post_pw  = isset($_POST['mb_password']) ? trim($_POST['mb_password']) : '';
    $post_name= isset($_POST['mb_name']) ? trim($_POST['mb_name']) : '';

    // 역할 결정
    if ($my_level >= 8) {
        // UI는 8+에서만 노출, 단 관리자 옵션은 10+
        $post_role = isset($_POST['role']) ? trim($_POST['role']) : 'member';
    } else { // 레벨7
        $post_role = 'member';
    }
    $post_level = role_to_level($post_role);

    // 허용 역할 검증
    if (!in_array($post_role, $allowed_roles, true)) {
        if ($post_role === 'admin') alert('관리자 추가는 레벨 10만 가능합니다.');
        if ($post_role === 'leader') alert('조직장 추가는 레벨 8 이상만 가능합니다.');
        if ($post_role === 'member') alert('회원 추가는 레벨 7 이상만 가능합니다.');
        alert('권한이 없습니다.');
    }
    if ($post_level <= 0) alert('잘못된 권한 선택입니다.');

    // 조직/파라미터
    $post_group_name   = trim($_POST['mb_group_name'] ?? '');
    $post_call_api_cnt = (int)($_POST['call_api_count'] ?? 3);
    $post_lease_min    = (int)($_POST['call_lease_min'] ?? 90);

    // 그룹 결정(레벨7=내 그룹 고정)
    if ($my_level >= 8) $post_mb_group = (int)($_POST['mb_group'] ?? 0);
    else $post_mb_group = $my_mb_no;

    if ($post_w === '') {
        // 신규
        if ($post_id === '' || $post_pw === '' || $post_name === '') {
            alert('아이디 / 비밀번호 / 이름은 필수입니다.');
        }
        if (($dup = get_member($post_id)) && $dup['mb_id']) alert('이미 존재하는 아이디입니다.');

        // 닉/메일 자동 생성
        $auto_nick  = gen_unique_nick($post_name ?: $post_id);
        $auto_email = gen_unique_email($post_id ?: 'user');

        // 리더가 아닌 경우에는 조직명 저장하지 않고, 콜 설정은 기본값으로
        if ($post_level != 7) {
            $post_group_name   = null;
            $post_call_api_cnt = 3;
            $post_lease_min    = 90;
        }

        $sql = "INSERT INTO {$g5['member_table']}
                   SET mb_id = '".sql_escape_string($post_id)."',
                       mb_password = '".get_encrypt_string($post_pw)."',
                       mb_name = '".sql_escape_string($post_name)."',
                       mb_nick = '".sql_escape_string($auto_nick)."',
                       mb_email = '".sql_escape_string($auto_email)."',
                       mb_level = '{$post_level}',
                       mb_group = '{$post_mb_group}',
                       mb_group_name = ".($post_group_name!==null ? ($post_group_name!==''?"'".sql_escape_string($post_group_name)."'":"NULL") : "NULL").",
                       call_api_count = '{$post_call_api_cnt}',
                       call_lease_min = '{$post_lease_min}',
                       mb_datetime = '".G5_TIME_YMDHIS."',
                       mb_today_login = '0000-00-00 00:00:00',
                       mb_email_certify = '".G5_TIME_YMDHIS."' ";
        sql_query($sql);
        $new_mb_no = sql_insert_id();

        // 조직장으로 생성 시 본인 mb_no를 그룹ID로
        if ($post_level == 7) {
            sql_query("UPDATE {$g5['member_table']} SET mb_group='{$new_mb_no}' WHERE mb_id='".sql_escape_string($post_id)."'");
        }

        goto_url('./call_member_list.php');

    } elseif ($post_w === 'u') {
        // 수정
        $target = get_member($post_id);
        if (!($target && $target['mb_id'])) alert('수정 대상 회원이 없습니다.');
        if ($my_level < (int)$target['mb_level']) alert('해당 회원을 수정할 권한이 없습니다.');
        if ($my_level == 7) {
            if ((int)$target['mb_group'] !== $my_mb_no && (int)$target['mb_no'] !== $my_mb_no) {
                alert('자신의 그룹 구성원만 수정할 수 있습니다.');
            }
        }

        // 닉/메일 보정(비노출)
        $keep_nick = $target['mb_nick'];
        $keep_mail = $target['mb_email'];
        if ($keep_nick === '' || nick_exists($keep_nick, $target['mb_id'])) $keep_nick = gen_unique_nick($post_name ?: $post_id, $target['mb_id']);
        if (!email_valid($keep_mail) || email_exists($keep_mail, $target['mb_id'])) $keep_mail = gen_unique_email($post_id ?: 'user', $target['mb_id']);

        $set = [];
        $set[] = "mb_name='".sql_escape_string($post_name)."'";
        $set[] = "mb_nick='".sql_escape_string($keep_nick)."'";
        $set[] = "mb_email='".sql_escape_string($keep_mail)."'";
        $set[] = "mb_level='{$post_level}'";
        if ($post_pw !== '') $set[] = "mb_password='".get_encrypt_string($post_pw)."'";

        // 그룹 세팅
        if ($post_level == 7) {
            $set[] = "mb_group = mb_no";
        } else {
            if ($my_level >= 8) $set[] = "mb_group = '{$post_mb_group}'";
            else $set[] = "mb_group = '{$my_mb_no}'";
        }

        // 조직명/콜 설정: 내 레벨이 8+이고, 대상 역할이 리더일 때만 반영
        if ($my_level >= 8 && $post_level == 7) {
            $set[] = "mb_group_name = ".($post_group_name!==''?"'".sql_escape_string($post_group_name)."'":"NULL");
            $set[] = "call_api_count = '".(int)$post_call_api_cnt."'";
            $set[] = "call_lease_min = '".(int)$post_lease_min."'";
        } else {
            // 리더가 아니면 기본값/유지
            $set[] = "mb_group_name = ".(isset($target['mb_group_name']) && $target['mb_group_name']!=='' ? "'".sql_escape_string($target['mb_group_name'])."'" : "NULL");
            $set[] = "call_api_count = '".(int)(isset($target['call_api_count'])?$target['call_api_count']:3)."'";
            $set[] = "call_lease_min = '".(int)(isset($target['call_lease_min'])?$target['call_lease_min']:90)."'";
        }

        $sql = "UPDATE {$g5['member_table']} SET ".implode(',', $set)." WHERE mb_id='".sql_escape_string($post_id)."'";
        sql_query($sql);

        goto_url('./call_member_list.php');

    } else {
        alert('잘못된 요청입니다.');
    }
}

// -----------------------------
// 출력 바인딩
// -----------------------------
$mb_id_val   = $is_new ? '' : get_text($mb['mb_id']);
$mb_name_val = $is_new ? '' : get_text($mb['mb_name']);
$org_name_val= $default_mb_group_name;
$api_cnt_val = $default_call_api_count;
$lease_min_val = $default_call_lease_min;
$stat_label = '';
if (!$is_new) {
    if (!empty($mb['mb_leave_date'])) $stat_label = '탈퇴('.$mb['mb_leave_date'].')';
    elseif (!empty($mb['mb_intercept_date'])) $stat_label = '차단('.$mb['mb_intercept_date'].')';
    else $stat_label = '정상';
}
?>

<form name="fmember" id="fmember" action="./call_member_form.php" method="post" autocomplete="off">
    <input type="hidden" name="w" value="<?php echo $is_new ? '' : 'u'; ?>">
    <?php if ($my_level == 7) { ?>
        <!-- 레벨 7은 역할 고정: member -->
        <input type="hidden" name="role" value="member">
    <?php } ?>

    <div class="tbl_frm01 tbl_wrap">
        <table>
            <caption><?php echo $g5['title']; ?></caption>
            <colgroup>
                <col class="grid_4"><col>
                <col class="grid_4"><col>
            </colgroup>
            <tbody>
                <tr>
                    <th scope="row"><label for="mb_id">아이디<strong class="sound_only">필수</strong></label></th>
                    <td>
                        <input type="text" name="mb_id" id="mb_id" value="<?php echo $mb_id_val; ?>" class="frm_input required alnum_" required maxlength="20" <?php echo $is_new?'':'readonly'; ?>>
                    </td>
                    <th scope="row"><label for="mb_password">비밀번호<?php echo $is_new ? '<strong class="sound_only">필수</strong>':''; ?></label></th>
                    <td>
                        <input type="password" name="mb_password" id="mb_password" class="frm_input <?php echo $is_new?'required':''; ?>" <?php echo $is_new?'required':''; ?> maxlength="64" autocomplete="new-password">
                        <?php if(!$is_new){ ?><div class="help">수정 시에만 입력</div><?php } ?>
                    </td>
                </tr>

                <?php if ($my_level >= 8) { ?>
                <tr>
                    <th scope="row">권한(역할)</th>
                    <td colspan="3">
                        <?php if ($my_level >= 10) { ?>
                        <label style="margin-right:12px;">
                            <input type="radio" name="role" value="admin"
                                   <?php echo ($default_role==='admin'?'checked':''); ?>>
                            관리자
                        </label>
                        <?php } ?>
                        <label style="margin-right:12px;">
                            <input type="radio" name="role" value="leader"
                                   <?php echo ($default_role==='leader'?'checked':''); ?>>
                            조직장
                        </label>
                        <label>
                            <input type="radio" name="role" value="member"
                                   <?php echo ($default_role==='member'?'checked':''); ?>>
                            회원
                        </label>
                    </td>
                </tr>
                <?php } ?>

                <tr>
                    <th scope="row"><label for="mb_name">이름<strong class="sound_only">필수</strong></label></th>
                    <td><input type="text" name="mb_name" id="mb_name" value="<?php echo $mb_name_val; ?>" class="frm_input required" required maxlength="20"></td>
                    <th scope="row">상태</th>
                    <td><?php echo $is_new ? '-' : $stat_label; ?></td>
                </tr>

                <tr>
                    <th scope="row">조직(그룹)</th>
                    <td colspan="3">
                        <?php if ($my_level >= 8) { ?>
                            <label for="mb_group" class="sound_only">조직</label>
                            <select name="mb_group" id="mb_group">
                                <option value="0">-- 조직 선택(그룹장) --</option>
                                <?php foreach ($leaders as $g): ?>
                                    <option value="<?php echo (int)$g['mb_group']; ?>" <?php echo ($default_mb_group==(int)$g['mb_group'])?'selected':''; ?>>
                                        <?php echo get_text($g['org_name']); ?> (리더: <?php echo get_text($g['mb_name']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help">※ 조직장 추가시 선택하지 마세요.</div>
                        <?php } else { ?>
                            <div><?php echo $default_mb_group_name ? get_text($default_mb_group_name) : '조직 미지정'; ?></div>
                            <input type="hidden" name="mb_group" value="<?php echo (int)$my_mb_no; ?>">
                            <input type="hidden" name="mb_group_name" value="<?php echo get_text($default_mb_group_name); ?>">
                        <?php } ?>
                    </td>
                </tr>

                <!-- 조직장 선택 시에만 표시되는 섹션 -->
                <tr id="leader_fields_org" style="display:none;">
                    <th scope="row"><label for="mb_group_name">조직명</label></th>
                    <td>
                        <?php if ($my_level >= 8) { ?>
                            <input type="text" name="mb_group_name" id="mb_group_name" value="<?php echo get_text($default_mb_group_name); ?>" class="frm_input" maxlength="100" placeholder="예) 서울1팀">
                        <?php } else { ?>
                            <!-- 레벨7은 여기 안옴(권한 영역이 안보이고 role=member 고정) -->
                            <div><?php echo get_text($default_mb_group_name); ?></div>
                        <?php } ?>
                    </td>
                    <th scope="row"><label for="call_api_count">할당갯수</label></th>
                    <td>
                        <input type="number" name="call_api_count" id="call_api_count" value="<?php echo (int)$api_cnt_val; ?>" class="frm_input" min="0" step="1" style="width:120px;">
                        <div class="help">앱에서 요청시 담당자에게 할당해주는 갯수 입니다. (기본값: 3)</div>
                    </td>
                </tr>
                <tr id="leader_fields_lease" style="display:none;">
                    <th scope="row"><label for="call_lease_min">유지시간(분)</label></th>
                    <td>
                        <input type="number" name="call_lease_min" id="call_lease_min" value="<?php echo (int)$lease_min_val; ?>" class="frm_input" min="0" step="1" style="width:120px;">
                        <div class="help">할당 후 해당 시간(분단위)이 지나면 자동으로 회수 합니다. (기본값: 90)</div>
                    </td>
                    <th scope="row"></th>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="btn_fixed_top">
        <a href="./call_member_list.php" class="btn btn_02">목록</a>
        <input type="submit" value="저장" class="btn_submit btn" accesskey="s">
    </div>
</form>

<script>
// 권한(역할) 선택에 따라 조직장 전용 필드 토글
(function(){
    function toggleLeaderFields() {
        var role = document.querySelector('input[name="role"]:checked');
        var isLeader = role && role.value === 'leader';
        var rows = [document.getElementById('leader_fields_org'), document.getElementById('leader_fields_lease')];
        rows.forEach(function(r){ if (r) r.style.display = isLeader ? '' : 'none'; });
    }
    // 초기 토글
    toggleLeaderFields();
    // 변경 토글
    var radios = document.querySelectorAll('input[name="role"]');
    radios.forEach(function(rd){ rd.addEventListener('change', toggleLeaderFields); });
})();
</script>

<?php require_once G5_ADMIN_PATH.'/admin.tail.php';
