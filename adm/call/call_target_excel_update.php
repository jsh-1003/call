<?php
// /adm/call_target_excel_update.php
$sub_menu = '700700';
include_once('./_common.php');

set_time_limit(360);
ini_set('memory_limit', '512M');
$MAX_ROWS = 100000;

auth_check_menu($auth, $sub_menu, "w");

function only_number($n) { return preg_replace('/[^0-9]/', '', (string)$n); }
function sql_quote_or_null($v) { return ($v === null || $v === '') ? "NULL" : "'".sql_escape_string($v)."'"; }

// ===== CSRF 체크 =====
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['call_upload_token'] ?? '')) {
    alert_close('잘못된 요청입니다.(CSRF)');
}
$chk = check_admin_token();
if(!$chk) {
    goto_url('/adm/call/call_target_excel.php');
}

if (empty($is_validate)) {
    $is_validate = 0;
}
// ===== 이름 검증 설정 =====
// 허용 성씨 화이트리스트 (필요하면 추가/수정)
$CALL_ALLOWED_SURNAMES = [
    '김','이','박','최','정','강','조','윤','장','임','한','오',
    '서','신','권','황','안','송','전','홍','유','고','문','양',
    '손','배','백','허','남','노','심','하','곽','성','차','주',
    '우','구','민','류','나','진','엄','채','원','천','방','공',
    '현','함','변','염','여','추','도','소','석','선','설','마',
    '길','연','위','표','명','기','반','라','왕','금','옥','육',
    '인','지','어','탁','국','모','맹','봉','호','형','예','음'
];

// 언더바(_) 기준 오른쪽 잘라서 검증용 이름을 만드는 함수
function call_clean_name_for_check($name_raw) {
    $name = trim((string)$name_raw);
    if ($name === '') return '';

    // 언더바 기준 왼쪽만 사용
    $pos = mb_strpos($name, '_');
    if ($pos !== false) {
        $name = mb_substr($name, 0, $pos);
    }
    return trim($name);
}

/**
 * 이름 기본 검증
 * - 언더바 오른쪽은 버리고 검증
 * - 5글자 이상이면 오류
 * - 특수문자 들어가면 오류 (한글/영문/공백 이외)
 * - 허용 성씨 목록에 없는 성이면 오류
 *
 * @param string $name_raw  저장용 원본 이름
 * @param string|null $reason_out  실패 사유 텍스트 (참조)
 * @return bool true=정상, false=이상
 */
function call_validate_name_basic($name_raw, &$reason_out = null) {
    global $CALL_ALLOWED_SURNAMES;

    $nm = call_clean_name_for_check($name_raw);
    // 이름이 비어 있으면 검증 통과 (이름 없는 데이터 허용)
    if ($nm === '') return true;

    // 1) 글자수 5자 이상
    if (mb_strlen($nm, 'UTF-8') >= 5) {
        $reason_out = '이름 글자 수 5자 이상';
        return false;
    }

    // 2) 특수문자 포함 (한글/영문/공백 이외는 모두 특수문자로 간주)
    if (preg_match('/[^가-힣A-Za-z\s]/u', $nm)) {
        $reason_out = '이름에 특수문자 포함';
        return false;
    }

    // 3) 허용 성씨 체크 (첫 글자만)
    $surname = mb_substr($nm, 0, 1, 'UTF-8');
    if ($surname !== '' && !in_array($surname, $CALL_ALLOWED_SURNAMES, true)) {
        $reason_out = "허용되지 않은 성씨({$surname})";
        return false;
    }

    return true;
}


$my_level = (int)$member['mb_level'];
$my_group = isset($member['mb_group']) ? (int)$member['mb_group'] : 0;
$mb_group = ($my_level >= 8) ? (int)($_POST['mb_group'] ?? 0) : $my_group;
if (!$mb_group) alert_close('지점이 선택되지 않았습니다.');

// 회사 ID (지점→회사 캐시 헬퍼 사용)
$company_id = (int)get_company_id_from_group_id_cached($mb_group);

// ===== is_open_number 결정(캠페인 플래그) =====
// 레벨 9+만 체크박스 허용: 체크되면 0(1차 비공개), 미체크=1(공개)
// 레벨 9 미만은 항상 1(공개) 강제
$is_open_number = 1;
$had_is_open_checkbox = false;
if ($my_level >= 9) {
    if (isset($_POST['is_open_number0'])) {
        $is_open_number = 0;
        $had_is_open_checkbox = true;
    } else {
        $is_open_number = 1;
    }
}

// 메모도 NFC로
$memo = isset($_POST['memo']) ? k_nfc(strip_tags(clean_xss_attributes($_POST['memo']))) : '';

// 현재 스텝: preview(1차 검증/미리보기) or commit(실제 등록)
$step = (isset($_POST['step']) && $_POST['step'] === 'commit') ? 'commit' : 'preview';

$max_preview = 20;

// ===== 성별 변환 유틸 =====
// 0=모름, 1=남, 2=여
function normalize_sex($v) {
    $v = trim(mb_strtolower((string)$v));
    if ($v === '') return 0;
    // 숫자 직접 입력 케이스
    if ($v === '1' || $v === '남' || $v === '남자' || $v === '남성' || $v === 'm' || $v === 'male') return 1;
    if ($v === '2' || $v === '여' || $v === '여자' || $v === '여성' || $v === 'f' || $v === 'female') return 2;
    return 0;
}

// ===== 생년월일(+성별) 파싱 =====
// 반환: [birth_date|null, sex(0/1/2)]
function parse_birth_and_sex($s) {
    $s = trim((string)$s);
    if ($s === '') return [null, 0];

    // Excel serial number
    if (is_numeric($s)) {
        $ival = (int)$s;
        if ($ival > 30000 && $ival < 90000) {
            $base = new DateTime('1899-12-30');
            $base->modify("+{$ival} days");
            return [$base->format('Y-m-d'), 0];
        }
    }

    // 주민번호형 yymmddX 또는 yymmdd-X (예: 8310031, 831003-1)
    $digits = preg_replace('/\D+/', '', $s); // 하이픈 제거
    if ($digits !== '') {
        // yyyymmdd
        if (strlen($digits) === 8) {
            $y=(int)substr($digits,0,4);
            $m=(int)substr($digits,4,2);
            $d=(int)substr($digits,6,2);
            if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), 0];
        }
        // yymmdd
        if (strlen($digits) === 6) {
            $yy=(int)substr($digits,0,2);
            $y=($yy>=70)?(1900+$yy):(2000+$yy);
            $m=(int)substr($digits,2,2);
            $d=(int)substr($digits,4,2);
            if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), 0];
        }
        // yymmddX (7자리) -> 성별 포함 패턴
        if (strlen($digits) === 7) {
            $yy=(int)substr($digits,0,2);
            $m =(int)substr($digits,2,2);
            $d =(int)substr($digits,4,2);
            $x =(int)substr($digits,6,1); // 성별/세기 코드
            // 세기 결정
            if (in_array($x, [1,2,5,6,7,8], true)) $y = 1900 + $yy;
            elseif (in_array($x, [3,4,7,8], true)) $y = 2000 + $yy; // 7,8은 외국인 코드(세부 구분 무시)
            else $y = ($yy>=70)?(1900+$yy):(2000+$yy); // fallback
            $sex = ($x===1 || $x===3 || $x===5 || $x===7) ? 1 : (($x===2 || $x===4 || $x===6 || $x===8) ? 2 : 0);
            if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), $sex];
        }
        // 6+1 with hyphen은 위에서 하이픈 제거로 동일 처리
    }

    // 일반 구분자 파싱(yyyy-mm-dd / yy-mm-dd / yyyy.mm.dd 등)
    $s2 = str_replace(['.','년','월','일'], ['-','','-',''], $s);
    $s2 = preg_replace('/[\/\.]/','-',$s2);
    $parts = array_values(array_filter(explode('-', $s2), fn($v)=>$v!==''));
    if (count($parts) === 3) {
        $y=(int)$parts[0]; $m=(int)$parts[1]; $d=(int)$parts[2];
        if ($y < 100) $y = ($y>=70)?(1900+$y):(2000+$y);
        if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), 0];
    }
    return [null, 0];
}

// 기존 호환 위해 wrapper 유지(다른 코드에서 호출 가능)
function parse_birth_date($s) {
    [$date, $sex] = parse_birth_and_sex($s);
    return $date;
}

// ===== 캠페인 생성 (파일명 기반 + NFC + is_open_number 반영) =====
function create_campaign_from_filename($mb_group, $orig_name, $memo, $is_open_number) {
    $base = pathinfo($orig_name, PATHINFO_FILENAME);
    $base = k_nfc(trim(preg_replace('/\s+/', ' ', $base)));
    $stamp = date('ymd_Hi');
    $name  = mb_substr($base, 0, 80).'_'.$stamp;   // 저장명도 NFC
    $sql = "INSERT INTO call_campaign (mb_group, name, campaign_memo, is_open_number, status, created_at, updated_at)
            VALUES ('{$mb_group}', '".sql_escape_string($name)."', '".sql_escape_string(k_nfc($memo))."', '{$is_open_number}', 1, NOW(), NOW())";
    sql_query($sql);
    return sql_insert_id();
}

// ===== mid4 화이트리스트 로드 =====
$mid4_whitelist = [];
$mid4_rs = sql_query("SELECT mid4 FROM call_phone_mid4"); // mid4는 CHAR(4) 가정
while ($r = sql_fetch_array($mid4_rs)) {
    $k = isset($r['mid4']) ? trim((string)$r['mid4']) : '';
    if ($k !== '' && preg_match('/^[0-9]{4}$/', $k)) {
        $mid4_whitelist[$k] = true;
    }
}
unset($mid4_rs);

// ===== 파일 경로 결정 (1단계: 업로드 & 저장 / 2단계: 저장된 파일 재사용) =====
$upload_dir = G5_DATA_PATH . '/call_upload';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0707, true);
    @chmod($upload_dir, 0707);
}

if ($step === 'commit') {
    // 2단계: 저장된 파일로부터 처리
    $saved_basename = preg_replace('/[^0-9A-Za-z_\.\-]/', '', (string)($_POST['saved_file'] ?? ''));
    if ($saved_basename === '') {
        alert_close('업로드 파일 정보가 없습니다.');
    }
    $file = $upload_dir . '/' . $saved_basename;
    if (!is_file($file)) {
        alert_close('업로드 파일을 찾을 수 없습니다. 다시 업로드해 주세요.');
    }
    $orig_name = k_nfc((string)($_POST['orig_name'] ?? $saved_basename));
} else {
    // 1단계: 실제 파일 업로드
    if (empty($_FILES['excelfile']['tmp_name'])) {
        alert_close("엑셀 파일을 업로드해 주세요.");
    }
    if (!is_uploaded_file($_FILES['excelfile']['tmp_name'])) {
        alert_close('정상적인 업로드가 아닙니다.');
    }

    $ext = strtolower(pathinfo($_FILES['excelfile']['name'], PATHINFO_EXTENSION));
    if ($ext === '') $ext = 'xlsx';

    $saved_basename = 'call_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
    $file = $upload_dir . '/' . $saved_basename;

    if (!move_uploaded_file($_FILES['excelfile']['tmp_name'], $file)) {
        alert_close('업로드 파일을 저장하지 못했습니다.');
    }
    // 원본 파일명도 NFC로
    $orig_name = k_nfc($_FILES['excelfile']['name']);
}

include_once(G5_LIB_PATH.'/PHPExcel/IOFactory.php');

try {
    // $objPHPExcel = PHPExcel_IOFactory::load($file);
    $reader = PHPExcel_IOFactory::createReaderForFile($file);

    // CSV인 경우 구체적 옵션 설정
    if ($reader instanceof PHPExcel_Reader_CSV) {
        $csvRaw = file_get_contents($file);
        // CP949 의심되면 CP949로 설정
        if (!mb_check_encoding($csvRaw, 'UTF-8')) {
            $reader->setInputEncoding('CP949');
        } else {
            $reader->setInputEncoding('UTF-8');
        }
        // $reader->setInputEncoding('UTF-8');     // 필요하면 'CP949'로 변경 가능
        $reader->setDelimiter(',');             // 기본 구분자
        $reader->setEnclosure('"');             // 큰따옴표 처리
        $reader->setSheetIndex(0);
    }

    $reader->setReadDataOnly(true); // 스타일/서식은 무시, 값만
    $objPHPExcel = $reader->load($file);
} catch (Exception $e) {
    alert_close('엑셀 파일을 읽을 수 없습니다: '.$e->getMessage());
}

$sheet = $objPHPExcel->getSheet(0);
$num_rows = $sheet->getHighestRow();
if ($num_rows > $MAX_ROWS) {
    alert_close("엑셀 행 수가 너무 많습니다 ({$estimated_rows}행). ".number_format($MAX_ROWS)."행 이하로 나누어서 업로드해 주세요.");
}

$highestColumn = $sheet->getHighestColumn();

// 수식 계산하지 않고 "보이는 값" 그대로 읽기
$firstRowArr = $sheet->rangeToArray('A1:'.$highestColumn.'1', NULL, false, false);
// 방어: 결과가 비정상이면 빈 배열로
$firstRowRaw = isset($firstRowArr[0]) && is_array($firstRowArr[0]) ? $firstRowArr[0] : [];

$firstRow = array_map('k_nfc', array_map(fn($v)=>trim((string)$v), $firstRowRaw));

// A열이 전화번호 패턴인지 확인 (헤더 유무 감지)
$is_headerless = false;
$first_col = trim((string)$firstRow[0]);
if (preg_match('/^0\d{1,2}-?\d{3,4}-?\d{4}$/', $first_col) || preg_match('/^01\d{8,9}$/', preg_replace('/\D/','',$first_col))) {
    $is_headerless = true;
}

if ($is_headerless) {
    // 헤더 없이 데이터부터 시작
    $start_row = 1;
    $header = [];
    $idx_hp = 0;
    $idx_name = 1;
    $idx_birth = 2;
    $idx_sex = 3;
} else {
    // 정상 헤더 (모두 NFC 정규화됨)
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

    if ($idx_hp === null) {
        alert_close('헤더에 전화번호 열이 필요합니다.');
    }
}

/* =========================================================
 * 1단계: 상위 10개 행 사전 검증 & 컨펌 화면
 * =======================================================*/
if ($step === 'preview') {
    $preview_rows = [];
    $ok_cnt = 0;
    $err_cnt = 0;

    for ($i = $start_row, $cnt = 0; $i <= $num_rows && $cnt < $max_preview; $i++) {
        $rowData = $sheet->rangeToArray('A'.$i.':'.$highestColumn.$i, NULL, FALSE, FALSE);
        if (!isset($rowData[0])) continue;
        $row = array_map('k_nfc', array_map(fn($v)=>trim((string)$v), $rowData[0]));

        // 완전 빈 행은 스킵 (카운트에도 안 넣음)
        $all_join = implode('', $row);
        if ($all_join === '') continue;

        $cnt++;

        // 전화번호
        if ($is_headerless) {
            $raw_hp  = (string)$row[0];
        } else {
            $raw_hp  = (string)($row[$idx_hp] ?? '');
        }
        $call_hp = preg_replace('/\D+/', '', $raw_hp);

        $status = '정상';
        $reason = '';

        if (!$call_hp || !preg_match('/^[0-9]{10,12}$/', $call_hp)) {
            $status = '오류';
            $reason = "잘못된 전화번호 형식";
            $err_cnt++;
        } else {
            // 010 번호 + mid4 / last4 필터
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

        // 이름
        $name = $is_headerless
            ? (isset($row[1]) ? k_nfc((string)$row[1]) : '')
            : (($idx_name !== null && isset($row[$idx_name])) ? k_nfc((string)$row[$idx_name]) : '');

        // 생년월일 + 성별
        $birth_date = null;
        $sex = 0;
        if ($is_headerless) {
            if (isset($row[2])) {
                [$birth_date, $sex_from_birth] = parse_birth_and_sex((string)$row[2]);
                $sex = $sex_from_birth;
            }
        } else {
            if ($idx_sex !== null && isset($row[$idx_sex])) {
                $sex = normalize_sex($row[$idx_sex]);
            }
            if ($idx_birth !== null && isset($row[$idx_birth])) {
                [$parsed_birth, $sex_from_birth] = parse_birth_and_sex((string)$row[$idx_birth]);
                if ($parsed_birth) $birth_date = $parsed_birth;
                if ($sex === 0 && $sex_from_birth > 0) $sex = $sex_from_birth;
            }
        }
        // ===== 이름 검증 =====
        if ($status === '정상' && $is_validate) {
            $name_reason = '';
            if (!call_validate_name_basic($name, $name_reason)) {
                $status = '오류';
                // 기존 이유가 있으면 이어붙이기
                if ($reason !== '') {
                    $reason .= ' / ';
                }
                $reason .= '이름 이상'.($name_reason ? ' - '.$name_reason : '');
                $err_cnt++; // 전화번호는 정상인데 이름 때문에 오류로 바뀐 경우만 카운트
            }
        }

        if ($status === '정상') {
            $ok_cnt++;
        }

        // ===== 부가정보 요약 =====
        if ($is_headerless) {
            // 헤더가 없으면 col4 이후를 몇 개만 요약
            $extras = [];
            for ($k = 3; $k < count($row) && count($extras) < 3; $k++) {
                if ($row[$k] === '') continue;
                $extras[] = 'col'.($k+1).': '.$row[$k];
            }
        } else {
            // 헤더가 있으면 hp/name/birth/sex 빼고 앞에서부터 1~2개만
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
        if ($extra_str !== '' && mb_strlen($extra_str) > 40) {
            $extra_str = mb_substr($extra_str, 0, 40).'…';
        }

        $preview_rows[] = [
            'rownum'      => $i,
            'raw_hp'      => $raw_hp,
            'call_hp'     => $call_hp,
            'name'        => $name,
            'birth_date'  => $birth_date,
            'sex'         => $sex,
            'status'      => $status,
            'reason'      => $reason,
            'extra'       => $extra_str,   // ★ 추가
        ];
    }

    $g5['title'] = '엑셀 등록 사전 검증';
    include_once(G5_PATH.'/head.sub.php');
    ?>
    <div class="new_win">
        <h1><?php echo $g5['title']; ?></h1>

        <div class="local_desc01 local_desc">
            <p>업로드된 엑셀의 상위 <?php echo $max_preview ?>개 데이터 행에 대해 형식 검증을 수행했습니다.</p>
            <p>
                <strong>정상 행:</strong> <?php echo number_format($ok_cnt); ?>건 /
                <strong>오류·제외 행:</strong> <?php echo number_format($err_cnt); ?>건
            </p>
            <p>
                아래 내용을 확인한 뒤, 문제가 없으면 업로드를 진행해 주세요.<br>오류가 있다면 창을 닫고 엑셀 파일을 수정한 후 다시 업로드하는 것을 권장합니다.<br>
                추천필드명 : 전화번호 / 이름 / 생년월일 / 성별
            </p>
        </div>

        <div class="tbl_head01 tbl_wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">엑셀 행</th>
                        <!-- <th scope="col">전화번호(원본)</th> -->
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
                    <tr>
                        <td colspan="8" class="text-center">검증할 데이터 행이 없습니다.</td>
                    </tr>
                <?php } else { ?>
                    <?php foreach ($preview_rows as $r) {
                        $sex_txt = ($r['sex'] === 1 ? '남' : ($r['sex'] === 2 ? '여' : ''));
                        ?>
                        <tr>
                            <td class="text-center"><?php echo (int)$r['rownum']; ?></td>
                            <!-- <td><?php echo get_text($r['raw_hp']); ?></td> -->
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

        <form id="excel_step1" method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn_win01 win_btn" style="margin-top:15px;">
            <!-- CSRF 토큰 재전달 -->
            <input type="hidden" name="csrf_token" value="<?php echo get_text($_POST['csrf_token']); ?>">
            <input type="hidden" name="token" value="<?php echo get_admin_token(); ?>">
            <input type="hidden" name="is_validate" value="<?php echo $is_validate ?>">
            <input type="hidden" name="step" value="commit">
            <input type="hidden" name="mb_group" value="<?php echo (int)$mb_group; ?>">
            <input type="hidden" name="memo" value="<?php echo htmlspecialchars($memo, ENT_QUOTES); ?>">
            <input type="hidden" name="saved_file" value="<?php echo htmlspecialchars($saved_basename, ENT_QUOTES); ?>">
            <input type="hidden" name="orig_name" value="<?php echo htmlspecialchars($orig_name, ENT_QUOTES); ?>">
            <?php if ($my_level >= 9 && $had_is_open_checkbox) { ?>
                <input type="hidden" name="is_open_number0" value="1">
            <?php } ?>

            <button id="btn_submit" type="submit"<?php echo empty($preview_rows) ? ' disabled' : ''; ?> class="btn_submit btn">검증 완료, 업로드 진행</button>&nbsp;&nbsp;
            <button type="button" onclick="window.close();" class="btn_close btn">취소</button>
        </form>
        <script>
        $(function(){
            $('#excel_step1').on('submit', function(){
                $('#btn_submit').prop('disabled', true).val('처리 중...');
            });
        });
        </script>
    </div>

    <?php include_once(G5_PATH.'/tail.sub.php'); ?>
    <?php
    // 1단계에서는 여기서 종료
    exit;
}

/* =========================================================
 * 2단계: 실제 스테이징 적재 + 본 테이블 인서트
 *  - 아래는 기존 로직을 그대로 사용하되, 파일입력만 변경된 상태
 * =======================================================*/

// 여기부터는 실제 DB 인서트 단계
$campaign_id = create_campaign_from_filename($mb_group, $orig_name, $memo, $is_open_number);

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
        // 각 셀을 문자열화 + 트림 + NFC
        $row = array_map('k_nfc', array_map(fn($v)=>trim((string)$v), $rowData[0]));

        // 완전 빈 행은 카운트/적재 모두 스킵
        if (implode('', $row) === '') continue;

        $total_count++;

        // 전화번호
        if ($is_headerless) {
            $raw_hp  = (string)$row[0];
        } else {
            $raw_hp  = (string)($row[$idx_hp] ?? '');
        }
        $call_hp = preg_replace('/\D+/', '', $raw_hp);
        if (!$call_hp || !preg_match('/^[0-9]{9,12}$/', $call_hp)) {
            $skip_count++;
            if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 잘못된 전화번호 '{$raw_hp}'";
            continue;
        }

        // 010 번호대만 중간대역(mid4) 화이트리스트 점검
        if (strpos($call_hp, '010') === 0 && $is_validate) {
            $mid4 = substr($call_hp, 3, 4); // 010 뒤의 4자리 추출
            if (!isset($mid4_whitelist[$mid4])) {
                $skip_count++;
                if (count($fail_msgs) < 20) {
                    $fail_msgs[] = "행 {$i}: 미허용 중간대역({$mid4}) - '{$raw_hp}'";
                }
                continue; // 스테이징 적재도 하지 않음
            }
            // 마지막 4자리 단순 패턴 필터
            $last4 = substr($call_hp, -4);
            if (in_array($last4, ['0000','1111','1234','2222','3333','4444','5555','6666','7777','8888','9999'], true)) {
                $skip_count++;
                if (count($fail_msgs) < 20) {
                    $fail_msgs[] = "행 {$i}: 비정상 마지막4자리({$last4}) - '{$raw_hp}'";
                }
                continue; // 스테이징에도 올리지 않음
            }
        }

        // 이름
        $name = $is_headerless
            ? (isset($row[1]) ? k_nfc((string)$row[1]) : null)
            : (($idx_name !== null) ? k_nfc((string)$row[$idx_name]) : null);

        // 생년월일 + 성별
        $birth_date = null;
        $sex = 0;

        if ($is_headerless) {
            // 2열에 생년월일 가정
            if (isset($row[2])) {
                [$birth_date, $sex_from_birth] = parse_birth_and_sex((string)$row[2]);
                $sex = $sex_from_birth;
            }
        } else {
            // 1) 성별 열이 있으면 우선 사용
            if ($idx_sex !== null && isset($row[$idx_sex])) {
                $sex = normalize_sex($row[$idx_sex]);
            }
            // 2) 생년월일 열 파싱 (성별 미제공/모름인 경우 생년월일 패턴에서 보강)
            if ($idx_birth !== null && isset($row[$idx_birth])) {
                [$parsed_birth, $sex_from_birth] = parse_birth_and_sex((string)$row[$idx_birth]);
                if ($parsed_birth) $birth_date = $parsed_birth;
                if ($sex === 0 && $sex_from_birth > 0) $sex = $sex_from_birth;
            }
        }

        // ===== 이름 검증 =====
        $name_reason = '';
        if($is_validate) {
            if (!call_validate_name_basic($name, $name_reason)) {
                $skip_count++;
                if (count($fail_msgs) < 20) {
                    $fail_msgs[] = "행 {$i}: 이름 이상 '".(string)$name."' - ".$name_reason;
                }
                continue; // 이름 이상이면 스테이징/본테이블 모두 적재 안 함
            }
        }

        // 기타정보(meta): 헤더풀일 때 나머지 컬럼을 JSON으로
        if ($is_headerless) {
            $meta = [];
            for ($k = 3; $k < count($row); $k++) {
                $meta['col'.($k+1)] = k_nfc((string)$row[$k]);
            }
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

        // 스테이징 적재
        $sql = "INSERT INTO call_stg_target_upload
                (batch_id, campaign_id, mb_group, call_hp, name, birth_date, sex, meta_json, created_at)
                VALUES
                ('{$batch_id}', '{$campaign_id}', '{$mb_group}', '{$call_hp}', ".sql_quote_or_null($name).", ".sql_quote_or_null($birth_date).", '{$sex}', ".sql_quote_or_null($meta_json).", NOW())";
        $res = sql_query($sql, false);
        if ($res) $stg_count++;
        else {
            $skip_count++;
            if (count($fail_msgs) < 20) $fail_msgs[] = "행 {$i}: 스테이징 실패";
        }

        if ($rows_in_tx >= $BATCH_SIZE) {
            sql_query("COMMIT");
            sql_query("START TRANSACTION");
            $rows_in_tx = 0;
        }
    }

    // 남은 것들 커밋
    sql_query("COMMIT");

    // 최종 적재 (블랙리스트 제외)
    $ins_sql = "
    INSERT IGNORE INTO call_target
    (campaign_id, mb_group, call_hp, name, birth_date, sex, meta_json, created_at, updated_at)
    SELECT s.campaign_id, s.mb_group, s.call_hp, s.name, s.birth_date, s.sex, s.meta_json, NOW(), NOW()
        FROM call_stg_target_upload s
    WHERE s.batch_id   = '{$batch_id}'
        AND s.mb_group   = '{$mb_group}'
        AND s.campaign_id= '{$campaign_id}'
        AND NOT EXISTS (
            SELECT 1
                FROM call_blacklist b
                WHERE b.company_id = {$company_id}
                AND b.call_hp    = s.call_hp
        )
    ";

    // 별도 트랜잭션으로 본 테이블 인서트
    sql_query("START TRANSACTION");
    sql_query($ins_sql);
    $ins_count = max(0, (int)mysqli_affected_rows($g5['connect_db']));
    $dup_count = max(0, $stg_count - $ins_count);
    sql_query("COMMIT");

} catch (Exception $e) {
    sql_query("ROLLBACK");
    alert_close('처리 중 오류: '.$e->getMessage());
} finally {
    // 임시 업로드 파일은 최대한 제거 (실패해도 무시)
    if (isset($file) && is_file($file)) {
        @unlink($file);
    }
}

if (isset($sheet)) {
    unset($sheet);
}
if (isset($objPHPExcel)) {
    if (method_exists($objPHPExcel, 'disconnectWorksheets')) {
        $objPHPExcel->disconnectWorksheets();
    }
    unset($objPHPExcel);
}
if (function_exists('gc_collect_cycles')) {
    gc_collect_cycles();
}

$g5['title'] = '엑셀 등록 결과';
include_once(G5_PATH.'/head.sub.php');
?>

<div class="new_win">
    <h1><?php echo $g5['title']; ?></h1>
    <div class="local_desc01 local_desc">
        <p>대상 추가 완료.</p>
        <p>
            <strong>캠페인ID:</strong> <?php echo (int)$campaign_id; ?> /
            <strong>배치ID:</strong> <?php echo (int)$batch_id; ?> /
            <strong>공개여부:</strong> <?php echo ((int)$is_open_number===0 ? '1차 비공개' : '공개'); ?>
        </p>
    </div>

    <dl id="excelfile_result">
        <dt>총 데이터 행</dt><dd><?php echo number_format($total_count); ?></dd>
        <dt>엑셀 처리 성공</dt><dd><?php echo number_format($stg_count); ?></dd>
        <dt>신규 등록</dt><dd><?php echo number_format($ins_count); ?></dd>
        <dt>중복&블랙</dt><dd><?php echo number_format($dup_count); ?></dd>
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

    // 1) window.opener (새 창/팝업으로 열린 경우)
    try {
      if (window.opener && !window.opener.closed) {
        window.opener.location.reload();
        done = true;
      }
    } catch (e) {}

    // 2) iframe/modal 안에서 열렸을 가능성 (동일 오리진일 때만)
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

  // 버튼에서 호출
  window.closeAndRefresh = function(){
    refreshOpener();
    window.close();
  };

  // 사용자가 X 버튼으로 닫거나 브라우저 제스처로 종료할 때도 시도
  window.addEventListener('unload', function(){
    refreshOpener();
  });
})();
</script>

<?php include_once(G5_PATH.'/tail.sub.php'); ?>
