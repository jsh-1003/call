<?php
// /adm/call/tmp_load_mid4_only.php
// 목적: /mnt/data/phone.xlsx 전체 셀에서 4자리 숫자 토큰(mid4)만 추출 → call_phone_mid4(mid4) 에 적재
// 특징: 한 셀에 "1234/5678"처럼 여러 개여도 각 토큰을 개별 row로 저장. 중복은 PK로 무시.
// 실행: 브라우저에서 1회 실행하고 삭제 권장
// 환경: 그누보드5, PHPExcel(내장)
require_once './_common.php';
require_once G5_LIB_PATH.'/call.lib.php';

$tmp = rand01();
var_dump($tmp);
// $t = paid_db_use(11, 149119, 246411, null, 18);
// var_dump($t);
// $tmp = get_member_from_mb_no(48);
// var_dump($tmp);


// ===== 생년월일(+성별) 파싱 =====
// 반환: [birth_date|null, sex(0/1/2)]
function parse_birth_and_sex($s) {
    $s = trim((string)$s);
    if ($s === '') return [null, 0];

    // Excel serial number
    if (is_numeric($s) && strlen($s) == 5) {
        $ival = (int)$s;
        if ($ival > 10000 && $ival < 90000) {
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
            $y=($yy>=40)?(1900+$yy):(2000+$yy);
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
            else $y = ($yy>=40)?(1900+$yy):(2000+$yy); // fallback
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
        if ($y < 100) $y = ($y>=40)?(1900+$y):(2000+$y);
        if (checkdate($m,$d,$y)) return [sprintf('%04d-%02d-%02d',$y,$m,$d), 0];
    }
    return [null, 0];
}

var_dump(parse_birth_and_sex(10279));
//var_dump(parse_birth_and_sex('30592'));
