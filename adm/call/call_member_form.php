<?php
// /adm/call_member_form.php
$sub_menu = "700750";
require_once './_common.php';

// 접근: 레벨 7 미만 금지
if ((int)$member['mb_level'] < 7) alert('접근 권한이 없습니다.');
auth_check_menu($auth, $sub_menu, 'w');

$g5['title'] = '회원 등록/수정';
require_once G5_ADMIN_PATH.'/admin.head.php';

// -----------------------------
// 파라미터/현재 관리자
// -----------------------------
$w     = isset($_REQUEST['w']) ? trim($_REQUEST['w']) : '';
$mb_id = isset($_REQUEST['mb_id']) ? trim($_REQUEST['mb_id']) : '';

$my_level        = (int)$member['mb_level'];
$my_mb_no        = (int)$member['mb_no'];
$my_company_id   = (int)($member['company_id'] ?? 0);     // 회사ID = 대표이사(8레벨)의 mb_no
$my_company_name = get_company_name_cached($my_company_id);

// -----------------------------
// 역할 <-> 레벨 매핑 (8레벨=대표이사)
// -----------------------------
function role_to_level($role) {
    switch ($role) {
        case 'company':         return 8;  // 대표이사
        case 'leader':          return 7;  // 지점장
        case 'member-after':    return 5;  // 상담원
        case 'member':          return 3;  // 상담원
        case 'member-before':   return 2;  // 상담원-승인전
        case 'admin':           return 10; // (UI 미노출) 플랫폼 슈퍼관리자
        default:                return 0;
    }
}
function level_to_role($lv) {
    if ($lv >= 10) return 'admin';
    if ($lv >= 8)  return 'company';
    if ($lv == 7)  return 'leader';
    if ($lv == 5)  return 'member-after';
    if ($lv == 3)  return 'member';
    return 'member-before';
}

// 내가 생성/수정 시 허용되는 역할(신규일 때만 의미, 수정은 고정)
$allowed_roles = [];
if     ($my_level >= 10) $allowed_roles = ['company','leader','member','member-before','member-after'];
elseif ($my_level >= 8)  $allowed_roles = ['leader','member','member-before','member-after'];
else                     $allowed_roles = ['member','member-before','member-after'];

// -----------------------------
// 회사 스코프 체크
// -----------------------------
function same_company_or_admin($my_level, $my_company_id, $target_company_id){
    if ($my_level >= 10) return true; // 슈퍼관리자
    if ($my_level >= 7)  return ($my_company_id === (int)$target_company_id);
    return false;
}

// -----------------------------
// 대상 조회(수정) 및 보호
// -----------------------------
$mb = [];
$is_new = ($w !== 'u');
$is_after_db_use = 0;
if (!$is_new) {
    $mb = get_member($mb_id);
    if (!(isset($mb['mb_id']) && $mb['mb_id'])) alert('존재하지 않는 회원입니다.');
    if ($my_level < (int)$mb['mb_level']) alert('해당 회원을 수정할 권한이 없습니다.');
    $target_company_id = (int)($mb['company_id'] ?? 0);
    if (!same_company_or_admin($my_level, $my_company_id, $target_company_id)) {
        alert('같은 회사 구성원만 수정할 수 있습니다.');
    }
    $is_after_db_use = $mb['is_after_db_use'];
}

// -----------------------------
// 회사(=대표이사) 목록, 지점장 목록
// -----------------------------
// 회사(대표이사=8레벨) 목록: 10레벨에서 사용 (회사 선택)
$companies = [];
if ($my_level >= 10) {
    $sql = "SELECT mb_no AS company_id, COALESCE(NULLIF(company_name,''), CONCAT('회사-', mb_no)) AS company_name
            FROM {$g5['member_table']}
            WHERE mb_level = 8
            ORDER BY company_name ASC, company_id ASC";
    $res = sql_query($sql);
    while ($r = sql_fetch_array($res)) $companies[] = $r;
}

// 지점장 목록: 8레벨은 본인 회사만, 10레벨은 전체(회사별 필터링 용)
$leaders = [];
if ($my_level >= 8) {
    $where = " WHERE m.mb_level = 7 ";
    if ($my_level < 10) {
        $where .= " AND m.company_id = '{$my_company_id}' ";
    }
    $sql = "SELECT m.mb_no AS mb_group, m.mb_name, COALESCE(NULLIF(m.mb_group_name,''), CONCAT('지점-', m.mb_no)) AS org_name,
                   m.company_id
            FROM {$g5['member_table']} m
            {$where}
            ORDER BY m.company_id ASC, org_name ASC, m.mb_no ASC";
    $res = sql_query($sql);
    while ($r = sql_fetch_array($res)) $leaders[] = $r;
}
$allowed_group_ids = array_map('intval', array_column($leaders, 'mb_group'));
// -----------------------------
// 기본값
// -----------------------------
$default_role           = $is_new ? 'member' : level_to_role((int)$mb['mb_level']);
$default_mb_group       = $is_new ? 0 : (int)$mb['mb_group'];
$default_mb_group_name  = $is_new ? ($my_level==7 ? (string)($member['mb_group_name'] ?? '') : '') : (string)($mb['mb_group_name'] ?? '');
$default_company_id     = $is_new ? ($my_level>=10 ? 0 : (int)$my_company_id) : (int)($mb['company_id'] ?? 0);
$default_company_name   = $is_new ? ($my_level>=10 ? '' : $my_company_name) : (string)($mb['company_name'] ?? '');
$default_company_hp     = $is_new ? '' : $mb['mb_hp'];

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
    $post_w    = isset($_POST['w']) ? trim($_POST['w']) : '';
    $post_id   = isset($_POST['mb_id']) ? trim($_POST['mb_id']) : '';
    $post_pw   = isset($_POST['mb_password']) ? trim($_POST['mb_password']) : '';
    $post_name = isset($_POST['mb_name']) ? trim($_POST['mb_name']) : '';
    $post_is_after_db_use = isset($_POST['is_after_db_use']) ? (int)$_POST['is_after_db_use'] : 0;

    // --- 역할 결정: 수정 시에는 대상의 기존 레벨을 강제 유지(권한 변경 불가) ---
    if ($post_w === 'u') {
        $target_for_role = get_member($post_id);
        if (!($target_for_role && $target_for_role['mb_id'])) alert('수정 대상 회원이 없습니다.');
        $post_level = (int)$target_for_role['mb_level'];
        $post_role  = level_to_role($post_level);
    } else {
        if ($my_level >= 9) $post_role = isset($_POST['role']) ? trim($_POST['role']) : 'member';
        else if ($my_level >= 8) $post_role = isset($_POST['role']) ? trim($_POST['role']) : 'member-before';
        else if($_POST['role'] == 'member-after') {
            $post_role = 'member-after';
        } else {
            $post_role = 'member-before';
        }
        $post_level = role_to_level($post_role);
        // 허용 역할 검증(신규에서만 체크)
        if (!in_array($post_role, $allowed_roles, true)) {
            if ($post_role === 'company') alert('대표이사 추가는 플랫폼관리자만 가능합니다.');
            if ($post_role === 'leader')  alert('지점장 추가는 레벨 8 이상만 가능합니다.');
            if ($post_role === 'member' || $post_role === 'member-before')  alert('상담원 추가는 레벨 7 이상만 가능합니다.');
            if ($post_role === 'member-after')  alert('2차팀장 추가는 레벨 7 이상만 가능합니다.');
            alert('권한이 없습니다.');
        }
        if ($post_level <= 0) alert('잘못된 권한 선택입니다.');
    }

    // 회사/지점 파라미터
    $post_company_id   = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;     // 10레벨에서 회사 선택(=대표이사 mb_no)
    $post_company_name = trim($_POST['company_name'] ?? '');                              // 대표이사 생성/수정 시 사용
    $post_company_hp   = trim($_POST['company_hp'] ?? '');                                // 대표이사 생성/수정 시 사용
    $post_mb_group     = isset($_POST['mb_group']) ? (int)$_POST['mb_group'] : 0;         // 상담원일 때만 의미
    $post_group_name   = trim($_POST['mb_group_name'] ?? '');                             // 리더일 때만 의미

    // 회사 배정 규칙
    if ($post_level == 8) {
        // 대표이사 생성/수정: company_id는 "본인 mb_no", company_name은 입력값
        if ($post_company_name === '' && $post_w === '') {
            alert('회사명을 입력하세요.');
        }
    } else {
        // 리더/상담원
        if ($my_level >= 10) {
            if ($post_w === '' && $post_company_id <= 0) alert('회사를 선택하세요.');
        } else {
            $post_company_id   = $my_company_id;
            $post_company_name = $my_company_name;
        }
    }

    // 지점 선택 제약: 상담원일 때만 필요, 8레벨/10레벨에서만 변경 가능
    if ($post_level <= 5) {
        if ($my_level >= 8 && $post_mb_group <= 0) alert('지점을 선택하세요.');
        if ($my_level == 8 && !in_array($post_mb_group, $allowed_group_ids, true)) alert('같은 회사의 지점으로만 배정할 수 있습니다.');
        if ($my_level >= 10 && $post_company_id > 0) {
            if ($post_mb_group > 0) {
                $chk = sql_fetch("SELECT company_id FROM {$g5['member_table']} WHERE mb_no='".(int)$post_mb_group."' AND mb_level=7");
                if (!($chk && (int)$chk['company_id'] === (int)$post_company_id)) {
                    alert('선택한 회사의 지점만 배정할 수 있습니다.');
                }
            }
        }
    } else {
        $post_mb_group = 0; // 리더/대표이사일 때는 지점 선택 무시
    }

    if ($post_w === '') {
        // ----------------- 신규 -----------------
        if ($post_id === '' || $post_pw === '' || $post_name === '') {
            alert('아이디 / 비밀번호 / 이름은 필수입니다.');
        }
        if (($dup = get_member($post_id)) && $dup['mb_id']) alert('이미 존재하는 아이디입니다.');

        $auto_nick  = gen_unique_nick($post_name ?: $post_id);
        $auto_email = gen_unique_email($post_id ?: 'user');

        $set = [];
        $set[] = "mb_id='".sql_escape_string($post_id)."'";
        $set[] = "mb_password='".get_encrypt_string($post_pw)."'";
        $set[] = "mb_name='".sql_escape_string($post_name)."'";
        $set[] = "mb_nick='".sql_escape_string($auto_nick)."'";
        $set[] = "mb_email='".sql_escape_string($auto_email)."'";
        $set[] = "mb_level='{$post_level}'";
        $set[] = "mb_email_certify='".G5_TIME_YMDHIS."'";

        if ($post_level == 8) {
            // 대표이사: INSERT 후 자신의 mb_no를 company_id로
            $set[] = "company_id='0'";
            $set[] = "company_name='".sql_escape_string($post_company_name)."'";
            $set[] = "mb_group='0'";
            $set[] = "mb_group_name=''";
            $set[] = "mb_hp='".sql_escape_string($post_company_hp)."'";
            $set[] = "is_after_db_use='".(int)$post_is_after_db_use."'";
        } elseif ($post_level == 7) {
            $set[] = "company_id='".(int)$post_company_id."'";
            $set[] = "company_name=".($post_company_name!=='' ? "'".sql_escape_string($post_company_name)."'" : "''");
            $set[] = "mb_group='0'";
            $set[] = "is_after_call='1'";
            $set[] = "mb_group_name=".($post_group_name!=='' ? "'".sql_escape_string($post_group_name)."'" : "''");
        } else {
            $set[] = "company_id='".(int)$post_company_id."'";
            $set[] = "company_name=".($post_company_name!=='' ? "'".sql_escape_string($post_company_name)."'" : "''");
            $set[] = "mb_group='".(int)$post_mb_group."'";
            $set[] = "mb_group_name=''";
        }

        if(!empty($is_admin_pay) && $is_admin_pay) {
            if(!empty($pay_start_date)) {
                $pay_start_date = sql_escape_string($pay_start_date);
                $set[] = " pay_start_date = date_format('{$pay_start_date}', '%Y-%m-%d') ";
            } else {
                $set[] = " pay_start_date = NULL ";
            }
        }

        $sql = "INSERT INTO {$g5['member_table']} SET ".implode(',', $set).", mb_datetime='".G5_TIME_YMDHIS."'";
        sql_query($sql);
        $new_mb_no = sql_insert_id();

        if ($post_level == 8) {
            sql_query("UPDATE {$g5['member_table']}
                       SET company_id='{$new_mb_no}'
                       WHERE mb_no='{$new_mb_no}'");
        } elseif ($post_level == 7) {
            sql_query("UPDATE {$g5['member_table']}
                       SET mb_group='{$new_mb_no}'
                       WHERE mb_no='{$new_mb_no}'");
        }
        goto_url('./call_member_list.php');

    } elseif ($post_w === 'u') {
        // ----------------- 수정 -----------------
        $target = get_member($post_id);
        if (!($target && $target['mb_id'])) alert('수정 대상 회원이 없습니다.');
        if ($my_level < (int)$target['mb_level']) alert('해당 회원을 수정할 권한이 없습니다.');

        $target_company_id = (int)($target['company_id'] ?? 0);
        if (!same_company_or_admin($my_level, $my_company_id, $target_company_id)) {
            alert('같은 회사 구성원만 수정할 수 있습니다.');
        }

        // 7레벨은 자기 지점 구성원만
        if ($my_level == 7) {
            if ((int)$target['mb_group'] !== $my_mb_no && (int)$target['mb_no'] !== $my_mb_no) {
                alert('자신의 지점 구성원만 수정할 수 있습니다.');
            }
        }

        // 닉/메일 보정
        $keep_nick = $target['mb_nick'];
        $keep_mail = $target['mb_email'];
        if ($keep_nick === '' || nick_exists($keep_nick, $target['mb_id'])) $keep_nick = gen_unique_nick($post_name ?: $post_id, $target['mb_id']);
        if (!email_valid($keep_mail) || email_exists($keep_mail, $target['mb_id'])) $keep_mail = gen_unique_email($post_id ?: 'user', $target['mb_id']);

        $set = [];
        $set[] = "mb_name='".sql_escape_string($post_name)."'";
        // 권한(레벨) 변경 불가: 기존 레벨 유지
        if ($post_pw !== '') $set[] = "mb_password='".get_encrypt_string($post_pw)."'";

        if ((int)$target['mb_level'] == 8) {
            // 대표이사: company_id는 항상 본인 mb_no, 회사명은 수정 가능(10+ 또는 본인이 8일 때 허용 로직 유지)
            if ($my_level >= 10 || $target['mb_level'] == 8) {
                $set[] = "company_id = mb_no";
                if ($post_company_name !== '') {
                    $set[] = "company_name='".sql_escape_string($post_company_name)."'";
                }
                if ($post_company_hp !== '') {
                    $set[] = "mb_hp='".sql_escape_string($post_company_hp)."'";
                }
                $set[] = "is_after_db_use='".(int)$post_is_after_db_use."'";
            }
            $set[] = "mb_group='0'";
            $set[] = "mb_group_name=''";
        } elseif ((int)$target['mb_level'] == 7) {
            // 상담원: 회사는 고정(10레벨은 회사 변경 가능), 지점 이동은 8+/10만
            if($my_level > 8 && $post_company_id) {
                $set[] = "company_id='".(int)$post_company_id."'";
            }
            // 대표이사(8+)는 지점명 변경 가능
            $set[] = "mb_group_name = ".($my_level>=8 ? ($post_group_name!==''?"'".sql_escape_string($post_group_name)."'":"''") : (isset($target['mb_group_name']) && $target['mb_group_name']!==''?"'".sql_escape_string($target['mb_group_name'])."'" :"''"));
        } else {
            // 상담원: 회사는 고정(10레벨은 회사 변경 가능), 지점 이동은 8+/10만
            if ($my_level >= 10 && $post_company_id > 0) {
                $set[] = "company_id='".(int)$post_company_id."'";
            }
            if ($my_level >= 8) {
                if ($my_level == 8 && !in_array($post_mb_group, $allowed_group_ids, true)) {
                    alert('같은 회사의 지점으로만 이동할 수 있습니다.');
                }
                if ($my_level >= 10 && $post_company_id > 0) {
                    $chk = sql_fetch("SELECT company_id FROM {$g5['member_table']} WHERE mb_no='".(int)$post_mb_group."' AND mb_level=7");
                    if (!($chk && (int)$chk['company_id'] === (int)$post_company_id)) {
                        alert('선택한 회사의 지점만 배정할 수 있습니다.');
                    }
                }
                if ($post_mb_group > 0) $set[] = "mb_group='".(int)$post_mb_group."'";
            }
            $set[] = "mb_group_name=''";
        }

        if(!empty($is_admin_pay) && $is_admin_pay) {
            if(!empty($pay_start_date)) {
                $pay_start_date = sql_escape_string($pay_start_date);
                $set[] = " pay_start_date = date_format('{$pay_start_date}', '%Y-%m-%d') ";
            } else {
                $set[] = " pay_start_date = NULL ";
            }
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
$stat_label  = '';
if (!$is_new) {
    if (!empty($mb['mb_leave_date']))         $stat_label = '탈퇴('.$mb['mb_leave_date'].')';
    elseif (!empty($mb['mb_intercept_date'])) $stat_label = '차단('.$mb['mb_intercept_date'].')';
    else $stat_label = '정상';
}
?>
<form name="fmember" id="fmember" action="./call_member_form.php" method="post" autocomplete="off">
    <input type="hidden" name="w" value="<?php echo $is_new ? '' : 'u'; ?>">

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
                    <td>
                        <?php if ($is_new) { // 신규만 선택 가능 ?>
                            <?php if ($my_level >= 10) { ?>
                            <label class="mgr12">
                                <input type="radio" name="role" value="company" <?php echo ($default_role==='company'?'checked':''); ?>>
                                대표이사
                            </label>
                            <?php } ?>
                            <label class="mgr12">
                                <input type="radio" name="role" value="leader" <?php echo ($default_role==='leader'?'checked':''); ?>>
                                지점장
                            </label>
                            <label class="mgr12">
                                <input type="radio" name="role" value="member-after" <?php echo ($default_role==='member-after'?'checked':''); ?>>
                                2차팀장
                            </label>
                            <label class="mgr12">
                                <input type="radio" name="role" value="member-before" <?php echo ($default_role==='member'?'checked':''); ?>>
                                상담원
                            </label>
                            <div class="help">※ 지점 선택은 ‘상담원’일 때만 표시됩니다.</div>
                        <?php } else { // 수정 시 고정 표기 ?>
                            <div style="padding:6px 0;">
                                <strong>
                                    <?php
                                        $label = ($default_role==='company'?'대표이사':($default_role==='leader'?'지점장':'상담원'));
                                        echo $label;
                                    ?>
                                </strong>
                            </div>
                            <input type="hidden" name="role" value="<?php echo $default_role; ?>">
                            <div class="help">수정에서는 권한 변경이 불가합니다.</div>
                        <?php } ?>
                    </td>
                    <?php if ($my_level >= 9) { ?>
                    <th scope="row">접수DB상세</th>
                    <td>
                            <label class="mgr12">
                                <input type="radio" name="is_after_db_use" value="1" <?php echo ($is_after_db_use==1?'checked':''); ?>>
                                사용
                            </label>
                            <label class="mgr12">
                                <input type="radio" name="is_after_db_use" value="0" <?php echo ($is_after_db_use==0?'checked':''); ?>>
                                미사용
                            </label>
                    </td>
                    <?php } ?>
                </tr>
                <?php } ?>

                <tr>
                    <th scope="row"><label for="mb_name">이름<strong class="sound_only">필수</strong></label></th>
                    <td><input type="text" name="mb_name" id="mb_name" value="<?php echo $mb_name_val; ?>" class="frm_input required" required maxlength="20"></td>
                    <th scope="row">상태</th>
                    <td><?php echo $is_new ? '-' : $stat_label; ?></td>
                </tr>
                <?php if($is_admin_pay) { ?>
                <tr>
                    <th scope="row"><label for="mb_name">유료시작일</label></th>
                    <td colspan="3">유료시작일 : <input type="date" id="pay_start_date" name="pay_start_date" value="<?php echo $mb['pay_start_date'] ?>" class="frm_input"></td>
                </tr>
                <?php } ?>

                <!-- 회사 섹션 -->
                <?php if ($my_level >= 10) { ?>
                <tr id="row_company_picker" style="display:<?php echo (($is_new && ($default_role==='member' || $default_role==='leader')) || (!$is_new && ($default_role==='member' || $default_role==='leader'))) ? '' : 'none'; ?>;">
                    <th scope="row"><label for="company_id">회사(대표이사)</label></th>
                    <td colspan="3">
                        <select name="company_id" id="company_id" <?php echo (!$is_new && $default_role==='company')?'disabled':''; ?>>
                            <option value="0">-- 회사 선택 --</option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?php echo (int)$c['company_id']; ?>" <?php echo ((int)$default_company_id===(int)$c['company_id'])?'selected':''; ?>>
                                    <?php echo get_text($c['company_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">※ 지점장/2차팀장/상담원은 반드시 소속 회사를 선택하세요.</div>
                    </td>
                </tr>
                <tr id="row_company_name_input" style="display:<?php echo (($is_new && $default_role==='company') || (!$is_new && $default_role==='company'))?'':'none'; ?>;">
                    <th scope="row"><label for="company_name">회사명</label></th>
                    <td>
                        <input type="text" name="company_name" id="company_name" class="frm_input" maxlength="100" value="<?php echo get_text($default_company_name); ?>" placeholder="예) 콜프로(주)">
                        <div class="help">대표이사 생성/수정 시 입력.</div>
                    </td>
                    <th scope="row"><label for="company_hp">대표연락처</label></th>
                    <td>
                        <input type="text" name="company_hp" id="company_hp" class="frm_input" maxlength="100" value="<?php echo get_text($default_company_hp); ?>" placeholder="010-1234-5678">
                        <div class="help">대표이사 생성/수정 시 입력.</div>
                    </td>
                </tr>
                <?php } else if ($my_level == 8) { ?>
                    <!-- 8레벨: 자신의 회사 고정(표시만) -->
                    <tr>
                        <th scope="row">회사</th>
                        <td colspan="3"><?php echo $my_company_id ? get_text($my_company_name) : '회사 미지정'; ?></td>
                    </tr>
                    <input type="hidden" name="company_id" value="<?php echo (int)$my_company_id; ?>">
                    <input type="hidden" name="company_name" value="<?php echo get_text($my_company_name); ?>">
                <?php } else { ?>
                    <!-- 7레벨: 표시만 -->
                    <tr>
                        <th scope="row">회사</th>
                        <td colspan="3"><?php echo $my_company_id ? get_text($my_company_name) : '회사 미지정'; ?></td>
                    </tr>
                    <input type="hidden" name="company_id" value="<?php echo (int)$my_company_id; ?>">
                    <input type="hidden" name="company_name" value="<?php echo get_text($my_company_name); ?>">
                <?php } ?>

                <!-- 지점 섹션: 상담원일 때만 노출(신규/수정 공통), 8레벨/10레벨 화면에서만 의미 -->
                <tr id="row_group_picker" style="display:none;">
                    <th scope="row"><label for="mb_group">지점</label></th>
                    <td colspan="3">
                        <select name="mb_group" id="mb_group">
                            <option value="0">-- 지점 선택 --</option>
                            <?php if ($my_level >= 8): ?>
                                <?php foreach ($leaders as $g): ?>
                                    <option value="<?php echo (int)$g['mb_group']; ?>"
                                        data-company="<?php echo (int)$g['company_id']; ?>"
                                        <?php echo ((int)$default_mb_group==(int)$g['mb_group'])?'selected':''; ?>>
                                        <?php echo get_text($g['org_name']); ?> (리더: <?php echo get_text($g['mb_name']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <div class="help">※ 2차팀장/상담원은 반드시 소속 지점을 선택하세요.</div>
                    </td>
                </tr>

                <!-- 지점장 전용: 지점명 -->
                <tr id="row_group_name" style="display:<?php echo ($default_role==='leader' ? '' : 'none'); ?>;">
                    <th scope="row"><label for="mb_group_name">지점</label></th>
                    <td colspan="3">
                        <?php if ($my_level >= 8) { ?>
                            <input type="text" name="mb_group_name" id="mb_group_name" class="frm_input" maxlength="100" value="<?php echo get_text($default_mb_group_name); ?>" placeholder="예) 서울1팀">
                        <?php } else { ?>
                            <div><?php echo get_text($default_mb_group_name); ?></div>
                        <?php } ?>
                    </td>
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
(function(){
    var myLevel = <?php echo (int)$my_level; ?>;
    var isNew   = <?php echo $is_new ? 'true':'false'; ?>;
    var defaultRole = "<?php echo $default_role; ?>";
    var roleInputs = document.querySelectorAll('input[name="role"]');

    function selectedRole(){
        if (!isNew) return defaultRole; // 수정 시 고정
        var el = document.querySelector('input[name="role"]:checked');
        if(el.value == 'member-after') return 'member';
        return el ? el.value : defaultRole || 'member';
    }
    function show(el, on){ if(!el) return; el.style.display = on ? '' : 'none'; }

    // 회사/지점 행들
    var rowCompanyPicker   = document.getElementById('row_company_picker');   // 10레벨: 회사 선택(리더/상담원)
    var rowCompanyName     = document.getElementById('row_company_name_input'); // 10레벨: 회사명 입력(대표이사)
    var rowGroupPicker     = document.getElementById('row_group_picker');     // 상담원 전용
    var rowGroupName       = document.getElementById('row_group_name');       // 리더 전용(지점명)

    function syncVisibility(){
        var role = selectedRole();
        if (myLevel >= 10) {
            show(rowCompanyPicker, ((role==='member' || role==='member-before')||role==='leader'));
            show(rowCompanyName, (role==='company'));
        }
        show(rowGroupPicker, ((role==='member' || role==='member-before') && myLevel >= 8));
        show(rowGroupName, (role==='leader'));
    }
    roleInputs.forEach(function(r){ r.addEventListener('change', syncVisibility); });
    syncVisibility();

    // 10레벨: 회사 선택에 따라 지점 목록 필터
    var companySelect = document.getElementById('company_id');
    var groupSelect   = document.getElementById('mb_group');
    function filterGroupsByCompany(){
        if (!companySelect || !groupSelect) return;
        var cid = parseInt(companySelect.value || '0', 10);
        var opts = groupSelect.querySelectorAll('option');
        opts.forEach(function(op){
            if (op.value === '0') { op.hidden=false; return; }
            var ocid = parseInt(op.getAttribute('data-company')||'0',10);
            op.hidden = (cid>0 && ocid !== cid);
        });
        if (groupSelect.selectedOptions.length && groupSelect.selectedOptions[0].hidden) {
            groupSelect.value = '0';
        }
    }
    if (companySelect) {
        companySelect.addEventListener('change', filterGroupsByCompany);
        filterGroupsByCompany();
    }
})();
</script>

<style>
.mgr12{ margin-right:12px; }
.help{ color:#777; font-size:0.92em; margin-top:4px; }
</style>

<?php require_once G5_ADMIN_PATH.'/admin.tail.php';
