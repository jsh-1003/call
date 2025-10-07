<?php
$sub_menu = '700100';
require_once './_common.php';

auth_check_menu($auth, '700100', 'r', true);

$sql_common = " from {$g5['board_table']} a ";
$sql_search = " where (1) ";

if ($is_admin != "super") {
    $sql_common .= " , {$g5['group_table']} b ";
    $sql_search .= " and (a.gr_id = b.gr_id and b.gr_admin = '{$member['mb_id']}') ";
}

if ($stx) {
    $sql_search .= " and ( ";
    switch ($sfl) {
        case "bo_table":
            $sql_search .= " ($sfl like '$stx%') ";
            break;
        case "a.gr_id":
            $sql_search .= " ($sfl = '$stx') ";
            break;
        default:
            $sql_search .= " ($sfl like '%$stx%') ";
            break;
    }
    $sql_search .= " ) ";
}

if (!$sst) {
    $sst  = "a.gr_id, a.bo_table";
    $sod = "asc";
}
$sql_order = " order by $sst $sod ";

$sql = " select count(*) as cnt {$sql_common} {$sql_search} {$sql_order} ";
$row = sql_fetch($sql);
$total_count = $row['cnt'];

$rows = $config['cf_page_rows'];
$total_page  = ceil($total_count / $rows);  // 전체 페이지 계산
if ($page < 1) {
    $page = 1; // 페이지가 없으면 첫 페이지 (1 페이지)
}
$from_record = ($page - 1) * $rows; // 시작 열을 구함

$sql = " select * {$sql_common} {$sql_search} {$sql_order} limit {$from_record}, {$rows} ";
$result = sql_query($sql);

$listall = '<a href="' . $_SERVER['SCRIPT_NAME'] . '" class="ov_listall">전체목록</a>';

$g5['title'] = '기본관리';
require_once '../admin.head.php';
?>

<div class="local_ov01 local_ov">
    <?php echo $listall ?>
    <span class="btn_ov01"><span class="ov_txt">생성된 게시판수</span><span class="ov_num"> <?php echo number_format($total_count) ?>개</span></span>
</div>

<form name="fsearch" id="fsearch" class="local_sch01 local_sch" method="get">
    <label for="sfl" class="sound_only">검색대상</label>
    <select name="sfl" id="sfl">
        <option value="bo_table" <?php echo get_selected($sfl, "bo_table", true); ?>>TABLE</option>
        <option value="bo_subject" <?php echo get_selected($sfl, "bo_subject"); ?>>제목</option>
        <option value="a.gr_id" <?php echo get_selected($sfl, "a.gr_id"); ?>>그룹ID</option>
    </select>
    <label for="stx" class="sound_only">검색어<strong class="sound_only"> 필수</strong></label>
    <input type="text" name="stx" value="<?php echo $stx ?>" id="stx" required class="required frm_input">
    <input type="submit" value="검색" class="btn_submit">
</form>

<form name="fboardlist" id="fboardlist" action="./board_list_update.php" onsubmit="return fboardlist_submit(this);" method="post">
    <input type="hidden" name="sst" value="<?php echo $sst ?>">
    <input type="hidden" name="sod" value="<?php echo $sod ?>">
    <input type="hidden" name="sfl" value="<?php echo $sfl ?>">
    <input type="hidden" name="stx" value="<?php echo $stx ?>">
    <input type="hidden" name="page" value="<?php echo $page ?>">
    <input type="hidden" name="token" value="<?php echo isset($token) ? $token : ''; ?>">

    <div class="tbl_head01 tbl_wrap">
        <table>
            <caption><?php echo $g5['title']; ?> 목록</caption>
            <thead>
                <tr>
                    <th scope="col">
                        <label for="chkall" class="sound_only">게시판 전체</label>
                        <input type="checkbox" name="chkall" value="1" id="chkall" onclick="check_all(this.form)">
                    </th>
                    <th scope="col"><?php echo subject_sort_link('a.gr_id') ?>상태</a></th>
                    <th scope="col"><?php echo subject_sort_link('bo_table') ?>상담원명</a></th>
                    <th scope="col"><?php echo subject_sort_link('bo_skin', '', 'desc') ?>아이디</a></th>
                    <th scope="col">전화번호</th>
                    <th scope="col"><?php echo subject_sort_link('bo_mobile_skin') ?>통화합계</th>
                    <th scope="col"><?php echo subject_sort_link('bo_mobile_skin') ?>접수</th>
                    <th scope="col"><?php echo subject_sort_link('bo_mobile_skin') ?>관심</th>
                    <th scope="col"><?php echo subject_sort_link('bo_mobile_skin') ?>변심</th>
                    <th scope="col"><?php echo subject_sort_link('bo_mobile_skin') ?>부재</th>
                    <th scope="col"><?php echo subject_sort_link('bo_mobile_skin') ?>결번</th>
                    <th scope="col"><?php echo subject_sort_link('bo_mobile_skin') ?>거부</th>
                    <th scope="col"><?php echo subject_sort_link('bo_mobile_skin') ?>욕설</th>
                </tr>
            </thead>
            <tbody>
                <?php
                for ($i = 0; $row = sql_fetch_array($result); $i++) {
                    $one_update = '<a href="./board_form.php?w=u&amp;bo_table=' . $row['bo_table'] . '&amp;' . $qstr . '" class="btn btn_03">수정</a>';
                    $one_copy = '<a href="./board_copy.php?bo_table=' . $row['bo_table'] . '" class="board_copy btn btn_02" target="win_board_copy">복사</a>';

                    $bg = 'bg' . ($i % 2);
                ?>

                    <tr class="<?php echo $bg; ?>">
                        <td class="td_chk">
                            <label for="chk_<?php echo $i; ?>" class="sound_only"><?php echo get_text($row['bo_subject']) ?></label>
                            <input type="checkbox" name="chk[]" value="<?php echo $i ?>" id="chk_<?php echo $i ?>">
                        </td>
                        <td>
                            <?php if ($is_admin == 'super') { ?>
                                <?php echo get_group_select("gr_id[$i]", $row['gr_id']) ?>
                            <?php } else { ?>
                                <input type="hidden" name="gr_id[<?php echo $i ?>]" value="<?php echo $row['gr_id'] ?>"><?php echo $row['gr_subject'] ?>
                            <?php } ?>
                        </td>
                        <td>
                            <input type="hidden" name="board_table[<?php echo $i ?>]" value="<?php echo $row['bo_table'] ?>">
                            <a href="<?php echo get_pretty_url($row['bo_table']) ?>"><?php echo $row['bo_table'] ?></a>
                        </td>
                        <td>
                            <label for="bo_skin_<?php echo $i; ?>" class="sound_only">스킨</label>
                            <?php echo get_skin_select('board', 'bo_skin_' . $i, "bo_skin[$i]", $row['bo_skin']); ?>
                        </td>
                        <td>
                            <label for="bo_mobile_skin_<?php echo $i; ?>" class="sound_only">모바일 스킨</label>
                            <?php echo get_mobile_skin_select('board', 'bo_mobile_skin_' . $i, "bo_mobile_skin[$i]", $row['bo_mobile_skin']); ?>
                        </td>
                        <td>
                            <label for="bo_subject_<?php echo $i; ?>" class="sound_only">게시판 제목<strong class="sound_only"> 필수</strong></label>
                            <input type="text" name="bo_subject[<?php echo $i ?>]" value="<?php echo get_text($row['bo_subject']) ?>" id="bo_subject_<?php echo $i ?>" required class="required tbl_input bo_subject full_input" size="10">
                        </td>
                        <td class="td_numsmall">
                            <label for="bo_read_point_<?php echo $i; ?>" class="sound_only">읽기 포인트</label>
                            <input type="text" name="bo_read_point[<?php echo $i ?>]" value="<?php echo $row['bo_read_point'] ?>" id="bo_read_point_<?php echo $i; ?>" class="tbl_input" size="2">
                        </td>
                        <td class="td_numsmall">
                            <label for="bo_write_point_<?php echo $i; ?>" class="sound_only">쓰기 포인트</label>
                            <input type="text" name="bo_write_point[<?php echo $i ?>]" value="<?php echo $row['bo_write_point'] ?>" id="bo_write_point_<?php echo $i; ?>" class="tbl_input" size="2">
                        </td>
                        <td class="td_numsmall">
                            <label for="bo_comment_point_<?php echo $i; ?>" class="sound_only">댓글 포인트</label>
                            <input type="text" name="bo_comment_point[<?php echo $i ?>]" value="<?php echo $row['bo_comment_point'] ?>" id="bo_comment_point_<?php echo $i; ?>" class="tbl_input" size="2">
                        </td>
                        <td class="td_numsmall">
                            <label for="bo_download_point_<?php echo $i; ?>" class="sound_only">다운<br>포인트</label>
                            <input type="text" name="bo_download_point[<?php echo $i ?>]" value="<?php echo $row['bo_download_point'] ?>" id="bo_download_point_<?php echo $i; ?>" class="tbl_input" size="2">
                        </td>
                        <td class="td_numsmall">
                            <label for="bo_use_sns_<?php echo $i; ?>" class="sound_only">SNS<br>사용</label>
                            <input type="checkbox" name="bo_use_sns[<?php echo $i ?>]" value="1" id="bo_use_sns_<?php echo $i ?>" <?php echo $row['bo_use_sns'] ? "checked" : "" ?>>
                        </td>
                        <td class="td_numsmall">
                            <label for="bo_use_search_<?php echo $i; ?>" class="sound_only">검색<br>사용</label>
                            <input type="checkbox" name="bo_use_search[<?php echo $i ?>]" value="1" id="bo_use_search_<?php echo $i ?>" <?php echo $row['bo_use_search'] ? "checked" : "" ?>>
                        </td>
                        <td class="td_numsmall">
                            <label for="bo_order_<?php echo $i; ?>" class="sound_only">출력<br>순서</label>
                            <input type="text" name="bo_order[<?php echo $i ?>]" value="<?php echo $row['bo_order'] ?>" id="bo_order_<?php echo $i ?>" class="tbl_input" size="2">
                        </td>
                    </tr>
                <?php
                }
                if ($i == 0) {
                    echo '<tr><td colspan="' . $colspan . '" class="empty_table">자료가 없습니다.</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>

    <div class="btn_fixed_top">
        <input type="submit" name="act_button" value="선택 중지" onclick="document.pressed=this.value" class="btn_01 btn">
        <input type="submit" name="act_button" value="선택 활성" onclick="document.pressed=this.value" class="btn_03 btn">
    </div>

</form>

<?php echo get_paging(G5_IS_MOBILE ? $config['cf_mobile_pages'] : $config['cf_write_pages'], $page, $total_page, $_SERVER['SCRIPT_NAME'] . '?' . $qstr . '&amp;page='); ?>

<script>
    function fboardlist_submit(f) {
        if (!is_checked("chk[]")) {
            alert(document.pressed + " 하실 항목을 하나 이상 선택하세요.");
            return false;
        }

        if (document.pressed == "선택삭제") {
            if (!confirm("선택한 자료를 정말 삭제하시겠습니까?")) {
                return false;
            }
        }

        return true;
    }

    $(function() {
        $(".board_copy").click(function() {
            window.open(this.href, "win_board_copy", "left=100,top=100,width=550,height=450");
            return false;
        });
    });
</script>

<?php
require_once '../admin.tail.php';