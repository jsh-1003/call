<?php
// /paid/paid_target_excel_update.php
$sub_menu = '200760';
include_once('./_common.php');

set_time_limit(360);
ini_set('memory_limit', '512M');
$MAX_ROWS = 500000;

// 1) 권한체크는 $is_admin_pay 으로만
if (!$is_admin_pay) {
    alert_close('접근 권한이 없습니다.');
}

function only_number($n) { return preg_replace('/[^0-9]/', '', (string)$n); }
function sql_quote_or_null($v) { return ($v === null || $v === '') ? "NULL" : "'".sql_escape_string($v)."'"; }

// ===== CSRF 체크(유료DB 전용 세션키) =====
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['paid_upload_token'] ?? '')) {
    alert_close('잘못된 요청입니다.(CSRF)');
}

// (선택) gnuboard admin token 유지
$chk = check_admin_token();
if (!$chk) {
    goto_url('./paid_target_excel.php');
}

$is_validate = (isset($_POST['is_validate']) && (int)$_POST['is_validate'] === 1) ? 1 : 0;

// 유료DB 캠페인 소스 정보
$db_agency = isset($_POST['db_agency']) ? (int)$_POST['db_agency'] : 0;
$db_vendor = isset($_POST['db_vendor']) ? (int)$_POST['db_vendor'] : 0;

// 유료DB 고정값
$mb_group     = 0;
$company_id   = 0;
$is_open_number = 0; // call_campaign 고정

// 메모 NFC
$memo = isset($_POST['memo']) ? k_nfc(strip_tags(clean_xss_attributes($_POST['memo']))) : '';

// 현재 스텝: preview or commit
$step = (isset($_POST['step']) && $_POST['step'] === 'commit') ? 'commit' : 'preview';
$max_preview = 20;

// ===== 이름 검증 설정 =====
$CALL_ALLOWED_SURNAMES = [
    '김','이','박','최','정','강','조','윤','장','임','한','오',
    '서','신','권','황','안','송','전','홍','유','고','문','양',
    '손','배','백','허','남','노','심','하','곽','성','차','주',
    '우','구','민','류','나','진','엄','채','원','천','방','공',
    '현','함','변','염','여','추','도','소','석','선','설','마',
    '길','연','위','표','명','기','반','라','왕','금','옥','육',
    '인','지','어','탁','국','모','맹','봉','호','형','예','음',
    '용','편','음','사','경','빈'    
];

function call_clean_name_for_check($name_raw) {
    $name = trim((string)$name_raw);
    if ($name === '') return '';
    $pos = mb_strpos($name, '_');
    if ($pos !== false) {
        $name = mb_substr($name, 0, $pos);
    }
    return trim($name);
}

function call_validate_name_basic($name_raw, &$reason_out = null) {
    global $CALL_ALLOWED_SURNAMES;

    $nm = call_clean_name_for_check($name_raw);
    if ($nm === '') return true;

    if (mb_strlen($nm, 'UTF-8') >= 5) {
        $reason_out = '이름 글자 수 5자 이상';
        return false;
    }
    if (preg_match('/[^가-힣A-Za-z\s]/u', $nm)) {
        $reason_out = '이름에 특수문자 포함';
        return false;
    }
    $surname = mb_substr($nm, 0, 1, 'UTF-8');
    if ($surname !== '' && !in_array($surname, $CALL_ALLOWED_SURNAMES, true)) {
        $reason_out = "허용되지 않은 성씨({$surname})";
        return false;
    }
    return true;
}

// ===== 성별 변환 =====
function normalize_sex($v) {
    $v = trim(mb_strtolower((string)$v));
    if ($v === '') return 0;
    if ($v === '1' || $v === '남' || $v === '남자' || $v === '남성' || $v === 'm' || $v === 'male') return 1;
    if ($v === '2' || $v === '여' || $v === '여자' || $v === '여성' || $v === 'f' || $v === 'female') return 2;
    return 0;
}

// ===== 생년월일(+성별) 파싱 =====
function parse_birth_and_sex($s) {
    $s = trim((string)$s);
    if ($s === '') return [null, 0];

    if (is_numeric($s) && strlen($s) == 5) {
        $ival = (int)$s;
        if ($ival > 10000 && $ival < 90000) {
            $base = new DateTime('1899-12-30');
            $base->modify("+{$ival} days");
            return [$base->format('Y-m-d'), 0];
        }
    }

    $digits = preg_replace('/\D+/', '', $s);
    if ($digits !== '') {
        if (strlen($digits) === 8) {
            $y=(int)substr($digits,0,4);
            $m=(int)substr($digits,4,2);
            $d=(int)substr($digits,6,2);
            if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), 0];
        }
        if (strlen($digits) === 6) {
            $yy=(int)substr($digits,0,2);
            $y=($yy>=40)?(1900+$yy):(2000+$yy);
            $m=(int)substr($digits,2,2);
            $d=(int)substr($digits,4,2);
            if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), 0];
        }
        if (strlen($digits) === 7) {
            $yy=(int)substr($digits,0,2);
            $m =(int)substr($digits,2,2);
            $d =(int)substr($digits,4,2);
            $x =(int)substr($digits,6,1);
            if (in_array($x, [1,2,5,6,7,8], true)) $y = 1900 + $yy;
            elseif (in_array($x, [3,4,7,8], true)) $y = 2000 + $yy;
            else $y = ($yy>=40)?(1900+$yy):(2000+$yy);
            $sex = ($x===1 || $x===3 || $x===5 || $x===7) ? 1 : (($x===2 || $x===4 || $x===6 || $x===8) ? 2 : 0);
            if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), $sex];
        }
    }

    $s2 = str_replace(['.','년','월','일'], ['-','','-',''], $s);
    $s2 = preg_replace('/[\/\.]/','-',$s2);
    $parts = array_values(array_filter(explode('-', $s2), fn($v)=>$v!==''));
    if (count($parts) === 3) {
        $y=(int)$parts[0]; $m=(int)$parts[1]; $d=(int)$parts[2];
        if ($y < 100) $y = ($y>=40)?(1900+$y):(2000+$y);
        if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), 0];
    }
    return [null, 0];
}

// ===== 유료DB 캠페인 생성: name='유료DB' 고정, 기존 name은 paid_db_name =====
function create_paid_campaign_from_filename($orig_name, $memo, $db_agency, $db_vendor) {
    $base = pathinfo($orig_name, PATHINFO_FILENAME);
    $base = k_nfc(trim(preg_replace('/\s+/', ' ', $base)));
    $stamp = date('ymd_Hi');
    $paid_db_name = mb_substr($base, 0, 80) . '_' . $stamp; // 기존 로직의 name 생성분을 paid_db_name으로

    $sql = "
        INSERT INTO call_campaign
            (db_agency, db_vendor, is_paid_db, mb_group, name, paid_db_name, campaign_memo, is_open_number, status, created_at, updated_at)
        VALUES
            ('".(int)$db_agency."',
             '".(int)$db_vendor."',
             1,
             0,
             '유료DB',
             '".sql_escape_string($paid_db_name)."',
             '".sql_escape_string(k_nfc($memo))."',
             0,
             1,
             NOW(),
             NOW()
            )
    ";
    sql_query($sql);
    return sql_insert_id();
}

// ===== mid4 화이트리스트 로드 =====
$mid4_whitelist = [];
$mid4_rs = sql_query("SELECT mid4 FROM call_phone_mid4");
while ($r = sql_fetch_array($mid4_rs)) {
    $k = isset($r['mid4']) ? trim((string)$r['mid4']) : '';
    if ($k !== '' && preg_match('/^[0-9]{4}$/', $k)) {
        $mid4_whitelist[$k] = true;
    }
}
unset($mid4_rs);

// ===== 파일 경로 결정 =====
$upload_dir = G5_DATA_PATH . '/call_upload';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0707, true);
    @chmod($upload_dir, 0707);
}

if ($step === 'commit') {
    $saved_basename = preg_replace('/[^0-9A-Za-z_\.\-]/', '', (string)($_POST['saved_file'] ?? ''));
    if ($saved_basename === '') alert_close('업로드 파일 정보가 없습니다.');
    $file = $upload_dir . '/' . $saved_basename;
    if (!is_file($file)) alert_close('업로드 파일을 찾을 수 없습니다. 다시 업로드해 주세요.');
    $orig_name = k_nfc((string)($_POST['orig_name'] ?? $saved_basename));

    // commit 단계에서 db_agency/vendor 재수신(히든)
    $db_agency = isset($_POST['db_agency']) ? (int)$_POST['db_agency'] : $db_agency;
    $db_vendor = isset($_POST['db_vendor']) ? (int)$_POST['db_vendor'] : $db_vendor;

} else {
    if (empty($_FILES['excelfile']['tmp_name'])) alert_close('엑셀 파일을 업로드해 주세요.');
    if (!is_uploaded_file($_FILES['excelfile']['tmp_name'])) alert_close('정상적인 업로드가 아닙니다.');

    $ext = strtolower(pathinfo($_FILES['excelfile']['name'], PATHINFO_EXTENSION));
    if ($ext === '') $ext = 'xlsx';

    $saved_basename = 'paid_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
    $file = $upload_dir . '/' . $saved_basename;

    if (!move_uploaded_file($_FILES['excelfile']['tmp_name'], $file)) {
        alert_close('업로드 파일을 저장하지 못했습니다.');
    }
    $orig_name = k_nfc($_FILES['excelfile']['name']);
}

include_once(G5_LIB_PATH.'/PHPExcel/IOFactory.php');

try {
    $reader = PHPExcel_IOFactory::createReaderForFile($file);

    if ($reader instanceof PHPExcel_Reader_CSV) {
        $csvRaw = file_get_contents($file);
        if (!mb_check_encoding($csvRaw, 'UTF-8')) $reader->setInputEncoding('CP949');
        else $reader->setInputEncoding('UTF-8');

        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->setSheetIndex(0);
    }

    $reader->setReadDataOnly(true);
    $objPHPExcel = $reader->load($file);
} catch (Exception $e) {
    alert_close('엑셀 파일을 읽을 수 없습니다: '.$e->getMessage());
}

$sheet = $objPHPExcel->getSheet(0);
$num_rows = $sheet->getHighestRow();
if ($num_rows > $MAX_ROWS) {
    alert_close("엑셀 행 수가 너무 많습니다 ({$num_rows}행). ".number_format($MAX_ROWS)."행 이하로 나누어서 업로드해 주세요.");
}

$highestColumn = $sheet->getHighestColumn();
$firstRowArr = $sheet->rangeToArray('A1:'.$highestColumn.'1', NULL, false, false);
$firstRowRaw = isset($firstRowArr[0]) && is_array($firstRowArr[0]) ? $firstRowArr[0] : [];
$firstRow = array_map('k_nfc', array_map(fn($v)=>trim((string)$v), $firstRowRaw));

// 헤더 유무 감지
$is_headerless = false;
$first_col = trim((string)($firstRow[0] ?? ''));
if (preg_match('/^0\d{1,2}-?\d{3,4}-?\d{4}$/', $first_col) || preg_match('/^01\d{8,9}$/', preg_replace('/\D/','',$first_col))) {
    $is_headerless = true;
}

if ($is_headerless) {
    $start_row = 1;
    $header = [];
    $idx_hp = 0;
    $idx_name = 1;
    $idx_birth = 2;
    $idx_sex = 3;
} else {
    $start_row = 2;
    $header = $firstRow;

    $name_keys  = ['이름','name','성명','성함','고객명'];
    $hp_keys    = ['전화번호','연락처','휴대폰','핸드폰','폰','전화','tel','phone','hp','call_hp'];
    $birth_keys = ['생년월일','생일','주민번호','birth','birth_date','생년','dob'];
    $sex_keys   = ['성별','남녀','sex','gender'];

    $idx_name = $idx_hp = $idx_birth = $idx_sex = null;
    $header_lc = array_map('mb_strtolower', $header);
    foreach ($header_lc as $i=>$h) {
        if ($idx_name === null  && in_array($h, array_map('mb_strtolower',$name_keys), true))  $idx_name  = $i;
        if ($idx_hp   === null  && in_array($h, array_map('mb_strtolower',$hp_keys), true))    $idx_hp    = $i;
        if ($idx_birth=== null  && in_array($h, array_map('mb_strtolower',$birth_keys), true)) $idx_birth = $i;
        if ($idx_sex  === null  && in_array($h, array_map('mb_strtolower',$sex_keys), true))   $idx_sex   = $i;
    }
    if ($idx_hp === null) alert_close('헤더에 전화번호 열이 필요합니다.');
}

/* =========================================================
 * 1단계: 상위 20개 사전 검증
 * =======================================================*/
if ($step === 'preview') {
    $preview_rows = [];
    $ok_cnt = 0;
    $err_cnt = 0;

    for ($i = $start_row, $cnt = 0; $i <= $num_rows && $cnt < $max_preview; $i++) {
        $rowData = $sheet->rangeToArray('A'.$i.':'.$highestColumn.$i, NULL, FALSE, FALSE);
        if (!isset($rowData[0])) continue;
        $row = array_map('k_nfc', array_map(fn($v)=>trim((string)$v), $rowData[0]));

        if (implode('', $row) === '') continue;
        $cnt++;

        $raw_hp = $is_headerless ? (string)$row[0] : (string)($row[$idx_hp] ?? '');
        $call_hp = preg_replace('/\D+/', '', $raw_hp);

        $status = '정상';
        $reason = '';

        if (!$call_hp || !preg_match('/^[0-9]{10,12}$/', $call_hp)) {
            $status = '오류';
            $reason = "잘못된 전화번호 형식";
            $err_cnt++;
        } else {
            if (strpos($call_hp, '010') === 0 && $is_validate) {
                $mid4 = substr($call_hp, 3, 4);
                if (!isset($mid4_whitelist[$mid4])) {
                    $status = '제외';
                    $reason = "미허용 중간대역({$mid4})";
                    $err_cnt++;
                } else {
                    $last4 = substr($call_hp, -4);
                    if (in_array($last4, ['0000','1111','1234','2222','3333','4444','5555','6666','7777','8888','9999'], true)) {
                        $status = '제외';
                        $reason = "비정상 마지막4자리({$last4})";
                        $err_cnt++;
                    }
                }
            }
        }

        $name = $is_headerless
            ? (isset($row[1]) ? k_nfc((string)$row[1]) : '')
            : (($idx_name !== null && isset($row[$idx_name])) ? k_nfc((string)$row[$idx_name]) : '');

        $birth_date = null;
        $sex = 0;
        if ($is_headerless) {
            if (isset($row[2])) {
                [$birth_date, $sex_from_birth] = parse_birth_and_sex((string)$row[2]);
                $sex = $sex_from_birth;
            }
        } else {
            if ($idx_sex !== null && isset($row[$idx_sex])) $sex = normalize_sex($row[$idx_sex]);
            if ($idx_birth !== null && isset($row[$idx_birth])) {
                [$parsed_birth, $sex_from_birth] = parse_birth_and_sex((string)$row[$idx_birth]);
                if ($parsed_birth) $birth_date = $parsed_birth;
                if ($sex === 0 && $sex_from_birth > 0) $sex = $sex_from_birth;
            }
        }

        if ($status === '정상' && $is_validate) {
            $name_reason = '';
            if (!call_validate_name_basic($name, $name_reason)) {
                $status = '오류';
                if ($reason !== '') $reason .= ' / ';
                $reason .= '이름 이상'.($name_reason ? ' - '.$name_reason : '');
                $err_cnt++;
            }
        }

        if ($status === '정상') $ok_cnt++;

        // 부가정보 요약
        if ($is_headerless) {
            $extras = [];
            for ($k = 3; $k < count($row) && count($extras) < 3; $k++) {
                if ($row[$k] === '') continue;
                $extras[] = 'col'.($k+1).': '.$row[$k];
            }
        } else {
            $extras = [];
            foreach ($header as $k => $h) {
                if ($k === $idx_hp || $k === $idx_name || $k === $idx_birth || $k === $idx_sex) continue;
                $h_label = trim((string)$h);
                if ($h_label === '') continue;
                $val = isset($row[$k]) ? $row[$k] : '';
                if ($val === '') continue;
                $extras[] = $h_label.': '.$val;
                if (count($extras) >= 2) break;
            }
        }

        $extra_str = implode(', ', $extras);
        if ($extra_str !== '' && mb_strlen($extra_str) > 40) $extra_str = mb_substr($extra_str, 0, 40).'…';

        $preview_rows[] = [
            'rownum'      => $i,
            'call_hp'     => $call_hp,
            'name'        => $name,
            'birth_date'  => $birth_date,
            'sex'         => $sex,
            'status'      => $status,
            'reason'      => $reason,
            'extra'       => $extra_str,
        ];
    }

    $g5['title'] = '유료DB 엑셀 등록 사전 검증';
    include_once(G5_PATH.'/head.sub.php');
    ?>
    <div class="new_win">
        <h1><?php echo $g5['title']; ?></h1>

        <div class="local_desc01 local_desc">
            <p>상위 <?php echo $max_preview ?>개 데이터 행에 대해 형식 검증을 수행했습니다.</p>
            <p>
                <strong>정상 행:</strong> <?php echo number_format($ok_cnt); ?>건 /
                <strong>오류·제외 행:</strong> <?php echo number_format($err_cnt); ?>건
            </p>
            <p>
                문제가 없으면 업로드를 진행해 주세요.<br>
                추천필드명 : 전화번호 / 이름 / 생년월일 / 성별
            </p>
        </div>

        <div class="tbl_head01 tbl_wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">엑셀 행</th>
                        <th scope="col">전화번호(숫자만)</th>
                        <th scope="col">이름</th>
                        <th scope="col">생년월일</th>
                        <th scope="col">성별</th>
                        <th scope="col">부가정보 일부</th>
                        <th scope="col">상태</th>
                        <th scope="col">메시지</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($preview_rows)) { ?>
                    <tr><td colspan="8" class="text-center">검증할 데이터 행이 없습니다.</td></tr>
                <?php } else { ?>
                    <?php foreach ($preview_rows as $r) {
                        $sex_txt = ($r['sex'] === 1 ? '남' : ($r['sex'] === 2 ? '여' : ''));
                        ?>
                        <tr>
                            <td class="text-center"><?php echo (int)$r['rownum']; ?></td>
                            <td><?php echo get_text($r['call_hp']); ?></td>
                            <td><?php echo get_text($r['name']); ?></td>
                            <td class="text-center"><?php echo get_text($r['birth_date']); ?></td>
                            <td class="text-center"><?php echo get_text($sex_txt); ?></td>
                            <td><?php echo get_text($r['extra']); ?></td>
                            <td class="text-center">
                                <?php if ($r['status']==='정상') { ?>
                                    <span style="color:green;font-weight:bold;">정상</span>
                                <?php } else { ?>
                                    <span style="color:#d00;font-weight:bold;"><?php echo get_text($r['status']); ?></span>
                                <?php } ?>
                            </td>
                            <td><?php echo get_text($r['reason']); ?></td>
                        </tr>
                    <?php } ?>
                <?php } ?>
                </tbody>
            </table>
        </div>

        <form id="excel_step1" method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>"
              class="btn_win01 win_btn" style="margin-top:15px;">
            <input type="hidden" name="csrf_token" value="<?php echo get_text($_POST['csrf_token']); ?>">
            <input type="hidden" name="token" value="<?php echo get_admin_token(); ?>">
            <input type="hidden" name="is_validate" value="<?php echo (int)$is_validate; ?>">
            <input type="hidden" name="step" value="commit">
            <input type="hidden" name="memo" value="<?php echo htmlspecialchars($memo, ENT_QUOTES); ?>">
            <input type="hidden" name="saved_file" value="<?php echo htmlspecialchars($saved_basename, ENT_QUOTES); ?>">
            <input type="hidden" name="orig_name" value="<?php echo htmlspecialchars($orig_name, ENT_QUOTES); ?>">
            <input type="hidden" name="db_agency" value="<?php echo (int)$db_agency; ?>">
            <input type="hidden" name="db_vendor" value="<?php echo (int)$db_vendor; ?>">

            <button id="btn_submit" type="submit"<?php echo empty($preview_rows) ? ' disabled' : ''; ?>
                    class="btn_submit btn">검증 완료, 업로드 진행</button>&nbsp;&nbsp;
            <button type="button" onclick="window.close();" class="btn_close btn">취소</button>
        </form>
        <script>
        $(function(){
            $('#excel_step1').on('submit', function(){
                $('#btn_submit').prop('disabled', true).text('처리 중...');
            });
        });
        </script>
    </div>
    <?php include_once(G5_PATH.'/tail.sub.php'); ?>
    <?php
    exit;
}

/* =========================================================
 * 2단계: 실제 적재
 * =======================================================*/

// 유료DB 캠페인 생성 (name 고정, paid_db_name에 기존 로직명 저장)
$campaign_id = create_paid_campaign_from_filename($orig_name, $memo, $db_agency, $db_vendor);

$batch_id    = (int)(microtime(true)*1000) + random_int(1, 999);
$total_count = $stg_count = $skip_count = $ins_count = $dup_count = 0;
$fail_msgs   = [];

$BATCH_SIZE = 2000;
$rows_in_tx = 0;

sql_query("START TRANSACTION");

try {
    for ($i = $start_row; $i <= $num_rows; $i++) {
        $rowData = $sheet->rangeToArray('A'.$i.':'.$highestColumn.$i, NULL, FALSE, FALSE);
        if (!isset($rowData[0])) continue;

        $row = array_map('k_nfc', array_map(fn($v)=>trim((string)$v), $rowData[0]));
        if (implode('', $row) === '') continue;

        $total_count++;

        $raw_hp  = $is_headerless ? (string)$row[0] : (string)($row[$idx_hp] ?? '');
        $call_hp = preg_replace('/\D+/', '', $raw_hp);

        if (!$call_hp || !preg_match('/^[0-9]{10,12}$/', $call_hp)) {
            $skip_count++;
            if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 잘못된 전화번호 '{$raw_hp}'";
            continue;
        }

        if (strpos($call_hp, '010') === 0 && $is_validate) {
            $mid4 = substr($call_hp, 3, 4);
            if (!isset($mid4_whitelist[$mid4])) {
                $skip_count++;
                if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 미허용 중간대역({$mid4}) - '{$raw_hp}'";
                continue;
            }
            $last4 = substr($call_hp, -4);
            if (in_array($last4, ['0000','1111','1234','2222','3333','4444','5555','6666','7777','8888','9999'], true)) {
                $skip_count++;
                if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 비정상 마지막4자리({$last4}) - '{$raw_hp}'";
                continue;
            }
        }

        $name = $is_headerless
            ? (isset($row[1]) ? k_nfc((string)$row[1]) : null)
            : (($idx_name !== null) ? k_nfc((string)$row[$idx_name]) : null);

        $birth_date = null;
        $sex = 0;

        if ($is_headerless) {
            if (isset($row[2])) {
                [$birth_date, $sex_from_birth] = parse_birth_and_sex((string)$row[2]);
                $sex = $sex_from_birth;
            }
        } else {
            if ($idx_sex !== null && isset($row[$idx_sex])) $sex = normalize_sex($row[$idx_sex]);
            if ($idx_birth !== null && isset($row[$idx_birth])) {
                [$parsed_birth, $sex_from_birth] = parse_birth_and_sex((string)$row[$idx_birth]);
                if ($parsed_birth) $birth_date = $parsed_birth;
                if ($sex === 0 && $sex_from_birth > 0) $sex = $sex_from_birth;
            }
        }

        $name_reason = '';
        if ($is_validate) {
            if (!call_validate_name_basic($name, $name_reason)) {
                $skip_count++;
                if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 이름 이상 '".(string)$name."' - ".$name_reason;
                continue;
            }
        }

        // meta_json
        if ($is_headerless) {
            $meta = [];
            for ($k = 3; $k < count($row); $k++) $meta['col'.($k+1)] = k_nfc((string)$row[$k]);
        } else {
            $meta = [];
            foreach ($header as $k=>$h) {
                if ($k === $idx_hp || $k === $idx_name || $k === $idx_birth || $k === $idx_sex) continue;
                $key = k_nfc(trim((string)$h));
                if ($key === '') continue;
                $val = isset($row[$k]) ? k_nfc((string)$row[$k]) : '';
                if ($val !== '') $meta[$key] = $val;
            }
        }
        $meta_json = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

        // db_age_type (기존 로직 유지)
        $db_age_type = 0;
        if ($birth_date) {
            $man_age = calc_age_years($birth_date);
            if ($man_age < 62 && $man_age > 10) $db_age_type = 1;
            else if ($man_age <= 70 && $man_age >= 62) $db_age_type = 2;
        }

        $rand_score = rand01();

        // 스테이징 적재 (company_id=0, mb_group=0 고정)
        $sql = "INSERT INTO call_stg_target_upload
                (batch_id, rand_score, campaign_id, company_id, mb_group, call_hp, name,
                 birth_date, is_paid_db, db_age_type, sex, meta_json, created_at)
                VALUES
                ('{$batch_id}', '{$rand_score}', '{$campaign_id}', '0', '0', '{$call_hp}', ".sql_quote_or_null($name).",
                 ".sql_quote_or_null($birth_date).", 1, '{$db_age_type}', '{$sex}', ".sql_quote_or_null($meta_json).", NOW())";
        $res = sql_query($sql, false);
        if ($res) $stg_count++;
        else {
            $skip_count++;
            if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 스테이징 실패";
        }

        $rows_in_tx++;
        if ($rows_in_tx >= $BATCH_SIZE) {
            sql_query("COMMIT");
            sql_query("START TRANSACTION");
            $rows_in_tx = 0;
        }
    }

    sql_query("COMMIT");

    // 본 테이블 적재: 유료DB 고정값 반영 (is_paid_db=1, company_id=0, mb_group=0)
    $black_sql = ""; // company_id=0 이라 블랙리스트 조건 무의미 -> 생략(성능)
    $ins_sql = "
        INSERT IGNORE INTO call_target
            (rand_score, campaign_id, company_id, mb_group, call_hp, name, birth_date, db_age_type, sex, meta_json, is_paid_db, created_at, updated_at)
        SELECT
            s.rand_score, s.campaign_id, 0, 0, s.call_hp, s.name, s.birth_date, s.db_age_type, s.sex, s.meta_json, s.is_paid_db, NOW(), NOW()
          FROM call_stg_target_upload s
         WHERE s.batch_id    = '{$batch_id}'
           AND s.campaign_id = '{$campaign_id}'
           AND s.mb_group    = '0'
           {$black_sql}
    ";

    sql_query("START TRANSACTION");
    sql_query($ins_sql);
    $ins_count = max(0, (int)mysqli_affected_rows($g5['connect_db']));
    $dup_count = max(0, $stg_count - $ins_count);
    sql_query("COMMIT");

} catch (Exception $e) {
    sql_query("ROLLBACK");
    alert_close('처리 중 오류: '.$e->getMessage());
} finally {
    if (isset($file) && is_file($file)) @unlink($file);
}

if (isset($sheet)) unset($sheet);
if (isset($objPHPExcel)) {
    if (method_exists($objPHPExcel, 'disconnectWorksheets')) $objPHPExcel->disconnectWorksheets();
    unset($objPHPExcel);
}
if (function_exists('gc_collect_cycles')) gc_collect_cycles();

$g5['title'] = '유료DB 엑셀 등록 결과';
include_once(G5_PATH.'/head.sub.php');
?>
<div class="new_win">
    <h1><?php echo $g5['title']; ?></h1>
    <div class="local_desc01 local_desc">
        <p>유료DB 대상 추가 완료.</p>
        <p>
            <strong>캠페인ID:</strong> <?php echo (int)$campaign_id; ?> /
            <strong>배치ID:</strong> <?php echo (int)$batch_id; ?> /
            <strong>공개여부:</strong> 1차 비공개
        </p>
    </div>

    <dl id="excelfile_result">
        <dt>총 데이터 행</dt><dd><?php echo number_format($total_count); ?></dd>
        <dt>엑셀 처리 성공</dt><dd><?php echo number_format($stg_count); ?></dd>
        <dt>신규 등록</dt><dd><?php echo number_format($ins_count); ?></dd>
        <dt>중복</dt><dd><?php echo number_format($dup_count); ?></dd>
        <dt>실패(정보 이상)</dt><dd><?php echo number_format($skip_count); ?></dd>
        <?php if (!empty($fail_msgs)) { ?>
        <dt>실패 샘플(최대 20건)</dt>
        <dd><ul style="margin:0;padding-left:18px;"><?php foreach ($fail_msgs as $m) echo '<li>'.get_text($m).'</li>'; ?></ul></dd>
        <?php } ?>
    </dl>

    <div class="btn_win01 win_btn">
        <button type="button" onclick="closeAndRefresh();">창 닫기</button>
    </div>
</div>

<script>
(function(){
  function refreshOpener() {
    var done = false;
    try {
      if (window.opener && !window.opener.closed) {
        window.opener.location.reload();
        done = true;
      }
    } catch (e) {}
    if (!done) {
      try {
        if (window.parent && window.parent !== window && window.parent.location) {
          window.parent.location.reload();
          done = true;
        }
      } catch (e) {}
    }
    return done;
  }
  window.closeAndRefresh = function(){
    refreshOpener();
    window.close();
  };
  window.addEventListener('unload', function(){
    refreshOpener();
  });
})();
</script>

<?php include_once(G5_PATH.'/tail.sub.php'); ?>
