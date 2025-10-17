<?php

function _g($key, $def='') { return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $def; }
function _p($key, $def='') { return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $def; }
function _h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function fmt_datetime($s, $end_type='i') {
    if($end_type == 'i')
        return substr($s, 2, 14);
    else if($end_type == 's')
        return substr($s, 2, 17);
    else
        return substr($s, 5, 11);
}

function fmt_hms($s) {
    if ($s === null) return '-';
    $s = max(0, (int)$s);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    return $h > 0 ? sprintf('%02d:%02d:%02d', $h, $m, $sec)
                  : sprintf('%02d:%02d', $m, $sec);
}

function get_group_name(int $mb_no): ?string {
    static $cache = []; // 요청(스크립트) 동안만 유지되는 메모이제이션 캐시
    // null 도 캐시로 인정하려면 array_key_exists 사용
    if (array_key_exists($mb_no, $cache)) {
        return $cache[$mb_no]; // string|null
    }
    $rowFirstCol = current(sql_fetch("SELECT mb_group_name FROM g5_member WHERE mb_no = {$mb_no} LIMIT 1"));
    // current()가 false를 줄 수 있으니 null로 정규화
    $name = ($rowFirstCol === false) ? null : $rowFirstCol;
    $cache[$mb_no] = $name; // string|null 캐싱
    return $name;
}

/**
 * 한국형 전화번호 보기 좋게 포맷
 * - 02 지역번호는 2-4-4
 * - 휴대폰/기타 11자리: 3-4-4
 * - 12자리: 4-4-4
 * - 10자리: 3-3-4 (일반 케이스)
 * - 그 외: 뒤에서 4-4 나누고 앞부분 그대로
 */
function format_korean_phone(string $hp_raw): string {
    $hp = preg_replace('/\D+/', '', $hp_raw);
    $len = strlen($hp);
    if ($len === 0) return $hp_raw;

    // 02 지역번호 케이스(9~10자리)
    if (substr($hp, 0, 2) === '02' && ($len === 9 || $len === 10)) {
        if ($len === 9)  return '02-'.substr($hp, 2, 3).'-'.substr($hp, 5, 4);  // 2-3-4
        if ($len === 10) return '02-'.substr($hp, 2, 4).'-'.substr($hp, 6, 4);  // 2-4-4
    }

    // 11자리(휴대폰 일반): 3-4-4
    if ($len === 11) return substr($hp, 0, 3).'-'.substr($hp, 3, 4).'-'.substr($hp, 7, 4);
    // 12자리: 4-4-4
    if ($len === 12) return substr($hp, 0, 4).'-'.substr($hp, 4, 4).'-'.substr($hp, 8, 4);
    // 10자리: 3-3-4
    if ($len === 10) return substr($hp, 0, 3).'-'.substr($hp, 3, 3).'-'.substr($hp, 6, 4);

    // 그 외: 뒤에서 4-4 자르고 나머지 앞
    if ($len > 8) {
        return substr($hp, 0, $len-8).'-'.substr($hp, $len-8, 4).'-'.substr($hp, $len-4, 4);
    } elseif ($len > 4) {
        return substr($hp, 0, $len-4).'-'.substr($hp, $len-4, 4);
    }
    return $hp;
}

/** 만 나이(생년월일 Y-m-d) */
function calc_age_years($birth_date){
    if (!$birth_date) return null;
    $today = new DateTime('today');
    $b = DateTime::createFromFormat('Y-m-d', $birth_date);
    if (!$b) return null;
    $age = (int)$today->format('Y') - (int)$b->format('Y');
    if ($today->format('md') < $b->format('md')) $age--;
    return $age;
}


// --------------------------------------------------------
// 상태코드 헤더 구성
// - mb_group가 선택된 경우: 해당 그룹 우선, 없으면 0(공통)
// - mb_group 미선택(0)인 경우: 0(공통)만 사용
// - 각 그룹 내부 sort_order ASC, 출력 순서는 "그룹(>0) 먼저, 그다음 0"
// --------------------------------------------------------
function get_code_list($sel_mb_group=0) {
    $code_map = [];
    $code_list = [];

    if ($sel_mb_group > 0) {
        $sql = "
        SELECT c.call_status, c.mb_group, c.name_ko, c.sort_order, c.ui_type
        FROM call_status_code c
        WHERE c.status=1 AND (c.mb_group='{$sel_mb_group}' OR c.mb_group=0)
        ORDER BY (c.mb_group='{$sel_mb_group}') DESC, c.sort_order ASC, c.call_status ASC
        ";
    } else {
        // 그룹 선택이 없으면 공통(0)만
        $sql = "
        SELECT c.call_status, c.mb_group, c.name_ko, c.sort_order, c.ui_type
        FROM call_status_code c
        WHERE c.status=1 AND c.mb_group=0
        ORDER BY c.sort_order ASC, c.call_status ASC
        ";
    }
    $res = sql_query($sql);
    while ($r = sql_fetch_array($res)) {
        $cs = (int)$r['call_status'];
        if (!isset($code_map[$cs])) {
            $code_map[$cs] = [
                'name' => $r['name_ko'],
                'mb_group' => (int)$r['mb_group'],
                'sort_order' => (int)$r['sort_order'],
                'ui_type' => $r['ui_type'],
            ];
        }
    }
    foreach ($code_map as $cs=>$info) {
        $code_list[] = ['call_status'=>$cs,'name'=>$info['name'],'mb_group'=>$info['mb_group'],'sort_order'=>$info['sort_order'],'ui_type'=>$info['ui_type']];
    }
    usort($code_list, function($a,$b){
        if ($a['mb_group'] !== $b['mb_group']) return ($a['mb_group'] === 0) ? 1 : -1; // 그룹>0 먼저
        if ($a['sort_order'] === $b['sort_order']) return $a['call_status'] <=> $b['call_status'];
        return $a['sort_order'] <=> $b['sort_order'];
    });
    return $code_list;
}


// --- Hangul-only Normalizer fallback (NFC <-> NFD) ---
// Normalizer 없으면 한글만 조합/분해. (U+AC00~U+D7A3 <-> choseong/jungseong/jongseong)
if (!function_exists('k_has_normalizer')) {
    function k_has_normalizer(){ return class_exists('Normalizer'); }
}
if (!function_exists('k_nfc')) {
    function k_nfc($s){
        if ($s === null || $s === '') return $s;
        if (!is_string($s)) $s = (string)$s;
        if (k_has_normalizer()) return Normalizer::normalize($s, Normalizer::FORM_C);
        // fallback: compose Hangul from jamo sequences
        $LBase=0x1100; $VBase=0x1161; $TBase=0x11A7; $SBase=0xAC00;
        $LCount=19; $VCount=21; $TCount=28; $NCount=$VCount*$TCount; $SCount=$LCount*$NCount;

        $codepoints = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        $i = 0; $n = count($codepoints);
        $cp = function($ch){ return IntlChar::ord($ch); }; // 없을 수 있어 직접 ord 구현
        if (!function_exists('IntlChar::ord')) {
            $cp = function($ch){
                $u = unpack('N', mb_convert_encoding($ch, 'UCS-4BE', 'UTF-8'));
                return $u ? $u[1] : 0;
            };
        }
        while ($i < $n) {
            $L = $cp($codepoints[$i]);
            // choseong?
            if ($L >= $LBase && $L < $LBase+$LCount) {
                if ($i+1 < $n) {
                    $V = $cp($codepoints[$i+1]);
                    if ($V >= $VBase && $V < $VBase+$VCount) {
                        $i2 = $i+2; $Tindex = 0;
                        if ($i2 < $n) {
                            $T = $cp($codepoints[$i2]);
                            if ($T > $TBase && $T < $TBase+$TCount) { $Tindex = $T - $TBase; $i2++; }
                        }
                        $Lindex = $L - $LBase;
                        $Vindex = $V - $VBase;
                        $Sindex = $Lindex*$NCount + $Vindex*$TCount + $Tindex;
                        if ($Sindex >=0 && $Sindex < $SCount) {
                            $out[] = mb_convert_encoding(pack('N', $SBase + $Sindex), 'UTF-8', 'UCS-4BE');
                            $i = $i2;
                            continue;
                        }
                    }
                }
            }
            $out[] = $codepoints[$i];
            $i++;
        }
        return implode('', $out);
    }
}
if (!function_exists('k_nfd')) {
    function k_nfd($s){
        if ($s === null || $s === '') return $s;
        if (!is_string($s)) $s = (string)$s;
        if (k_has_normalizer()) return Normalizer::normalize($s, Normalizer::FORM_D);
        // fallback: decompose Hangul syllables to jamo
        $LBase=0x1100; $VBase=0x1161; $TBase=0x11A7; $SBase=0xAC00;
        $LCount=19; $VCount=21; $TCount=28; $NCount=$VCount*$TCount; $SCount=$LCount*$NCount;
        $out = '';
        // iterate codepoints
        $len = mb_strlen($s, 'UTF-8');
        for ($i=0;$i<$len;$i++){
            $ch = mb_substr($s, $i, 1, 'UTF-8');
            $u = unpack('N', mb_convert_encoding($ch, 'UCS-4BE', 'UTF-8'))[1];
            $Sindex = $u - $SBase;
            if (0 <= $Sindex && $Sindex < $SCount) {
                $L = intdiv($Sindex, $NCount);
                $V = intdiv($Sindex % $NCount, $TCount);
                $T = $Sindex % $TCount;
                $out .= mb_convert_encoding(pack('N', $LBase+$L), 'UTF-8', 'UCS-4BE');
                $out .= mb_convert_encoding(pack('N', $VBase+$V), 'UTF-8', 'UCS-4BE');
                if ($T != 0) $out .= mb_convert_encoding(pack('N', $TBase+$T), 'UTF-8', 'UCS-4BE');
            } else {
                $out .= $ch;
            }
        }
        return $out;
    }
}
