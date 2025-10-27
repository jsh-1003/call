<?php
if (!defined('_GNUBOARD_')) exit; // 개별 페이지 접근 불가

// add_stylesheet('css 구문', 출력순서); 숫자가 작을 수록 먼저 출력됨
// add_stylesheet('<link rel="stylesheet" href="'.$latest_skin_url.'/style.css">', 0);
$list_count = (is_array($list) && $list) ? count($list) : 0;
?>
<style>
.top_latest {float:left;padding:3px 20px;line-height:1.8em;}
.basic_li a {color:#fff;overflow-x:hidden;}
.basic_li a:hover {color:#f1f1f1}
.lt_info {display:inline-block;width:60px;color:#fff}
.top_latest li .new_icon {display:inline-block;width:16px;line-height:16px;font-size:0.833em;color:#23db79;background:#b9ffda;text-align:center;border-radius:2px;margin-left:2px;font-weight:bold;vertical-align:middle}
</style>
<div class="top_latest">
    <ul>
    <?php for ($i=0; $i<$list_count; $i++) {  ?>
        <li class="basic_li">
            <div class="lt_info">
                <span class="lt_date">[<?php echo $list[$i]['datetime2'] ?>]</span>
            </div>
            <?php
            if ($list[$i]['icon_new']) echo "<span class=\"new_icon\">N<span class=\"sound_only\">새글</span></span>&nbsp;";

            echo "<a href=\"".get_pretty_url($bo_table, $list[$i]['wr_id'])."\"> ";
            if ($list[$i]['is_notice'])
                echo "<strong>".$list[$i]['subject']."</strong>";
            else
                echo $list[$i]['subject'];

            echo "</a>";
            

            ?>
        </li>
    <?php }  ?>
    </ul>
</div>