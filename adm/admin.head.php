<?php
include_once(G5_LIB_PATH.'/latest.lib.php');
if (!defined('_GNUBOARD_')) {
    exit;
}

$g5_debug['php']['begin_time'] = $begin_time = get_microtime();

$files = glob(G5_ADMIN_PATH . '/css/admin_extend_*');
if (is_array($files)) {
    foreach ((array) $files as $k => $css_file) {

        $fileinfo = pathinfo($css_file);
        $ext = $fileinfo['extension'];

        if ($ext !== 'css') {
            continue;
        }

        $css_file = str_replace(G5_ADMIN_PATH, G5_ADMIN_URL, $css_file);
        add_stylesheet('<link rel="stylesheet" href="' . $css_file . '">', $k);
    }
}

require_once G5_PATH . '/head.sub.php';

function print_menu1($key, $no = '')
{
    global $menu;

    $str = print_menu2($key, $no);

    return $str;
}

function print_menu2($key, $no = '')
{
    global $menu, $auth_menu, $is_admin, $auth, $g5, $sub_menu;

    $str = "<ul>";
    for ($i = 1; $i < count($menu[$key]); $i++) {
        if (!isset($menu[$key][$i])) {
            continue;
        }

        if ($is_admin != 'super' && (!array_key_exists($menu[$key][$i][0], $auth) || !strstr($auth[$menu[$key][$i][0]], 'r'))) {
            //continue;
        }

        $gnb_grp_div = $gnb_grp_style = '';

        if (isset($menu[$key][$i][4])) {
            if (($menu[$key][$i][4] == 1 && $gnb_grp_style == false) || ($menu[$key][$i][4] != 1 && $gnb_grp_style == true)) {
                $gnb_grp_div = 'gnb_grp_div';
            }

            if ($menu[$key][$i][4] == 1) {
                $gnb_grp_style = 'gnb_grp_style';
            }
        }

        $current_class = '';

        if ($menu[$key][$i][0] == $sub_menu) {
            $current_class = ' on';
        }

        $str .= '<li data-menu="' . $menu[$key][$i][0] . '"><a href="' . $menu[$key][$i][2] . '" class="gnb_2da ' . $gnb_grp_style . ' ' . $gnb_grp_div . $current_class . '">' . $menu[$key][$i][1] . '</a></li>';

        $auth_menu[$menu[$key][$i][0]] = $menu[$key][$i][1];
    }
    $str .= "</ul>";

    return $str;
}

$adm_menu_cookie = array(
    'container' => '',
    'gnb'       => '',
    'btn_gnb'   => '',
);

if (!empty($_COOKIE['g5_admin_btn_gnb'])) {
    $adm_menu_cookie['container'] = 'container-small';
    $adm_menu_cookie['gnb'] = 'gnb_small';
    $adm_menu_cookie['btn_gnb'] = 'btn_gnb_open';
}
?>
<script src="<?php echo G5_ADMIN_URL ?>/admin.js?ver=<?php echo G5_JS_VER; ?>"></script>
<script>
    var g5_admin_csrf_token_key = "<?php echo (function_exists('admin_csrf_token_key')) ? admin_csrf_token_key() : ''; ?>";
    var tempX = 0;
    var tempY = 0;

    function imageview(id, w, h) {

        menu(id);

        var el_id = document.getElementById(id);

        //submenu = eval(name+".style");
        submenu = el_id.style;
        submenu.left = tempX - (w + 11);
        submenu.top = tempY - (h / 2);

        selectBoxVisible();

        if (el_id.style.display != 'none')
            selectBoxHidden(id);
    }
</script>
<style>
body, #hd_top, #wrapper {min-width:1100px}
#hd_top {background:#344a57 !important}
#logo {background:#212c33 !important;padding:0;padding-left:50px}
#logo img {height:50px;}
.top_after_badge {float:right;padding:14px;}
/* 토글 버튼 */
.toggle-is-paid-db, .toggle-after {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:54px; padding:2px 8px; border-radius:12px; font-size:12px; line-height:1.6;
    cursor:pointer; border:0; color:#fff;
}
.toggle-is-paid-db.on, .toggle-after.on  { background:#16a34a; } /* green */
.toggle-is-paid-db.off, .toggle-after.off { background:#9ca3af; } /* gray */
.toggle-is-paid-db[disabled], .toggle-after[disabled] { opacity:.5; cursor:not-allowed; }

</style>
<?php if(empty($is_popup_page)) { ?>

<div id="to_content"><a href="#container">본문 바로가기</a></div>

<header id="hd">
    <h1><?php echo $config['cf_title'] ?></h1>
    <div id="hd_top">
        <button type="button" id="btn_gnb" class="btn_gnb_close <?php echo $adm_menu_cookie['btn_gnb']; ?>">메뉴</button>
        <div id="logo"><a href="<?php echo correct_goto_url(G5_ADMIN_URL); ?>"><img src="<?php echo G5_ADMIN_URL ?>/img/admin_logo.png" alt="<?php echo get_text($config['cf_title']); ?> 관리자"></a></div>
        <?php 
        if(!$member['member_type']) {
            echo latest('basic', 'notice', 2, 50);
        }
        ?>
        <?php if($is_company_leader && is_paid_db_use_company($member['mb_no'])) { ?>
        <div class="top_after_badge">
            <span style="color:#fff">잔여포인트 : <?php echo number_format($member['mb_point']) ?>점</span>
        </div>
        <?php } ?>
        <?php
        if(in_array($member['mb_level'], array(5,7)) && $member['member_type']==0) {
            $is_after = (int)$member['is_after_call'] === 1;
        ?>
        <div class="top_after_badge">
            <span style="color:#fff">2차콜할당</span>
            <button type="button"
                    class="toggle-after <?php echo $is_after ? 'on':'off'; ?>"
                    data-mb-no="<?php echo (int)$member['mb_no']; ?>"
                    data-value="<?php echo $is_after ? 1:0; ?>">
                <?php echo $is_after ? 'ON':'OFF'; ?>
            </button>
        </div>
        <?php } ?>
        <?php if($member['mb_level'] >= 5) { ?>
        <script>
        // 2차콜담당 토글
        document.addEventListener('click', function(e){
        var btn = e.target.closest('.toggle-after');
        if (!btn) return;

        if (btn.hasAttribute('disabled')) return;
        
        if (!confirm('2차콜온오프 상태를 정말 변경하시겠습니까?')) return;

        var mbNo = parseInt(btn.getAttribute('data-mb-no') || '0', 10) || 0;
        var cur  = parseInt(btn.getAttribute('data-value') || '0', 10) || 0;
        var want = cur ? 0 : 1;

        var fd = new FormData();
        fd.append('ajax','toggle_after');
        fd.append('mb_no', String(mbNo));
        fd.append('want', String(want));
        btn.setAttribute('disabled','disabled');

        fetch('/adm/call/call_member_list.php', { method:'POST', body:fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(j){
            if (!j || j.success === false) throw new Error((j && j.message) || '실패');
            // UI 반영
            if (typeof j.value !== 'undefined') {
                var v = parseInt(j.value,10)===1;
                btn.classList.toggle('on', v);
                btn.classList.toggle('off', !v);
                btn.textContent = v ? 'ON':'OFF';
                btn.setAttribute('data-value', v ? '1':'0');
            }
            })
            .catch(function(err){
            alert('변경 실패: ' + err.message);
            })
            .finally(function(){
            btn.removeAttribute('disabled');
            });
        });            
        </script>            
        <?php } ?>
        <div id="tnb">
            <ul>
                <?php if (defined('G5_USE_SHOP') && G5_USE_SHOP) { ?>
                    <li class="tnb_li"><a href="<?php echo G5_SHOP_URL ?>/" class="tnb_shop" target="_blank" title="쇼핑몰 바로가기">쇼핑몰 바로가기</a></li>
                <?php } ?>
                <!-- <li class="tnb_li"><a href="<?php echo G5_URL ?>/" class="tnb_community" target="_blank" title="커뮤니티 바로가기">커뮤니티 바로가기</a></li> -->
                <!-- <li class="tnb_li"><a href="<?php echo G5_ADMIN_URL ?>/service.php" class="tnb_service">부가서비스</a></li> -->
                <li class="tnb_li"><button type="button" class="tnb_mb_btn">관리자<span class="./img/btn_gnb.png">메뉴열기</span></button>
                    <ul class="tnb_mb_area">
                        <!-- <li><a href="<?php echo G5_ADMIN_URL ?>/member_form.php?w=u&amp;mb_id=<?php echo $member['mb_id'] ?>">관리자정보</a></li> -->
                        <li id="tnb_logout"><a href="<?php echo G5_BBS_URL ?>/logout.php">로그아웃</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
    <nav id="gnb" class="gnb_large <?php echo $adm_menu_cookie['gnb']; ?>">
        <h2>관리자 주메뉴</h2>
        <ul class="gnb_ul">
            <?php
            $jj = 1;
            foreach ($amenu as $key => $value) {
                $href1 = $href2 = '';

                if (isset($menu['menu' . $key][0][2]) && $menu['menu' . $key][0][2]) {
                    $href1 = '<a href="' . $menu['menu' . $key][0][2] . '" class="gnb_1da">';
                    $href2 = '</a>';
                } else {
                    continue;
                }

                $current_class = "";
                if (isset($sub_menu) && (substr($sub_menu, 0, 3) == substr($menu['menu' . $key][0][0], 0, 3))) {
                    $current_class = " on";
                }

                $button_title = $menu['menu' . $key][0][1];
            ?>
                <li class="gnb_li<?php echo $current_class; ?>">
                    <button type="button" class="btn_op menu-<?php echo $key; ?> menu-order-<?php echo $jj; ?>" title="<?php echo $button_title; ?>"><?php echo $button_title; ?></button>
                    <div class="gnb_oparea_wr">
                        <div class="gnb_oparea">
                            <h3><?php echo $menu['menu' . $key][0][1]; ?></h3>
                            <?php echo print_menu1('menu' . $key, 1); ?>
                        </div>
                    </div>
                </li>
            <?php
                $jj++;
            }     //end foreach
            ?>
        </ul>
    </nav>

</header>
<script>
    jQuery(function($) {

        var menu_cookie_key = 'g5_admin_btn_gnb';

        $(".tnb_mb_btn").click(function() {
            $(".tnb_mb_area").toggle();
        });

        $("#btn_gnb").click(function() {

            var $this = $(this);

            try {
                if (!$this.hasClass("btn_gnb_open")) {
                    set_cookie(menu_cookie_key, 1, 60 * 60 * 24 * 365);
                } else {
                    delete_cookie(menu_cookie_key);
                }
            } catch (err) {}

            $("#container").toggleClass("container-small");
            $("#gnb").toggleClass("gnb_small");
            $this.toggleClass("btn_gnb_open");

        });

        $(".gnb_ul li .btn_op").click(function() {
            $(this).parent().addClass("on").siblings().removeClass("on");
        });

    });
</script>


<div id="wrapper">

    <div id="container" class="<?php echo $adm_menu_cookie['container']; ?>">

        <h1 id="container_title"><?php echo $g5['title'] ?></h1>
        <div class="container_wr">

<?php } ?>