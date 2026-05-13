<?php
if (!defined('_GNUBOARD_')) exit; // 개별 페이지 접근 불가

// 기존 스타일시트 로드 (필요시 유지)
// add_stylesheet('<link rel="stylesheet" href="'.$member_skin_url.'/style.css">', 0);
?>

<style>
/* ====================================================================
  [ CALLPRO 맞춤형 그누보드 로그인 스킨 CSS ]
====================================================================
*/

/* 폰트 및 기본 초기화 */
#mb_login * { box-sizing: border-box; }
#mb_login { 
    max-width: 400px; 
    margin: 80px auto; 
    font-family: 'Pretendard', 'Apple SD Gothic Neo', 'Noto Sans KR', sans-serif; 
    color: #333; 
}

/* 스크린리더용 숨김 텍스트 */
#mb_login .sound_only { 
    position: absolute; top: -9999px; left: -9999px; width: 0; height: 0; overflow: hidden; 
}

/* 로그인 메인 박스 */
.mbskin_box { 
    background: #ffffff; 
    border-radius: 16px; 
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08); 
    padding: 50px 35px 40px; 
    border: 1px solid #f2f4f6; 
}

/* 로고 영역 */
.login_logo { 
    text-align: center; 
    margin: 0 0 35px 0; 
}
.login_logo img { 
    max-width: 240px; /* 로고 크기에 맞게 조절 가능 */
    height: auto; 
}

/* 폼 영역 초기화 */
#login_fs { padding: 0; margin: 0; border: none; }
#login_fs legend { display: none; }

/* 입력창 (Input) */
.frm_input { 
    width: 100%; 
    padding: 15px 16px; 
    margin-bottom: 12px; 
    border: 1px solid #e1e5eb; 
    border-radius: 10px; 
    font-size: 15px; 
    color: #333; 
    background-color: #fafbfc; 
    transition: all 0.2s ease-in-out; 
}
.frm_input:focus { 
    border-color: #F26A21; /* 로고 오렌지 컬러 */
    background-color: #ffffff; 
    outline: none; 
    box-shadow: 0 0 0 3px rgba(242, 106, 33, 0.12); 
}
.frm_input::placeholder { color: #a0a5b1; }

/* 제출 버튼 */
.btn_submit { 
    width: 100%; 
    padding: 16px; 
    background: #1F355E; /* 로고 네이비 컬러 */
    color: #fff; 
    border: none; 
    border-radius: 10px; 
    font-size: 16px; 
    font-weight: 700; 
    cursor: pointer; 
    transition: all 0.3s ease; 
    margin-top: 10px; 
    letter-spacing: 0.5px;
}
.btn_submit:hover { 
    background: #F26A21; /* 마우스 오버 시 로고 오렌지 컬러로 전환 */
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(242, 106, 33, 0.2);
}

/* 자동로그인 및 링크 영역 */
#login_info { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-top: 15px; 
    font-size: 14px; 
    color: #666; 
}
.chk_box { display: flex; align-items: center; }
.chk_box input[type="checkbox"] { 
    width: 18px; 
    height: 18px; 
    accent-color: #F26A21; /* 체크박스 오렌지 컬러 */
    margin: 0 6px 0 0; 
    cursor: pointer; 
}
.chk_box label { cursor: pointer; user-select: none; }

/* 고객센터 정보 영역 */
.cs_info_box {
    margin-top: 35px;
    padding: 18px;
    background: #f8f9fa;
    border-radius: 10px;
    text-align: center;
    font-size: 14px;
    color: #555;
    border: 1px solid #eef1f4;
}
.cs_info_box b {
    color: #F26A21; /* 로고 오렌지 컬러 */
    font-size: 18px;
    font-weight: 800;
    margin-left: 6px;
    letter-spacing: 0.5px;
}
</style>

<div id="mb_login" class="mbskin">
    <div class="mbskin_box">
        
        <h1 class="login_logo">
            <a href="<?php echo G5_URL ?>">
                <img src="<?php echo G5_IMG_URL ?>/logo_callpro.png" alt="<?php echo $g5['title'] ?>">
            </a>
        </h1>
        
        <form name="flogin" action="<?php echo $login_action_url ?>" onsubmit="return flogin_submit(this);" method="post">
        <input type="hidden" name="url" value="<?php echo $login_url ?>">
        
        <fieldset id="login_fs">
            <legend>회원로그인</legend>
            
            <label for="login_id" class="sound_only">회원아이디<strong class="sound_only"> 필수</strong></label>
            <input type="text" name="mb_id" id="login_id" required class="frm_input required" size="20" maxLength="20" placeholder="아이디">
            
            <label for="login_pw" class="sound_only">비밀번호<strong class="sound_only"> 필수</strong></label>
            <input type="password" name="mb_password" id="login_pw" required class="frm_input required" size="20" maxLength="20" placeholder="비밀번호">
            
            <button type="submit" class="btn_submit">로그인</button>
            
            <div id="login_info">
                <div class="login_if_auto chk_box">
                    <input type="checkbox" name="auto_login" id="login_auto_login" class="selec_chk">
                    <label for="login_auto_login"><span></span> 자동로그인</label>  
                </div>
                <div class="login_if_lpl">
                    </div>
            </div>
            
            <div class="cs_info_box">
                고객센터 <b>1566-7374</b>
            </div>
        </fieldset> 
        </form>
        
        <?php @include_once(get_social_skin_path().'/social_login.skin.php'); // 소셜로그인 사용시 소셜로그인 버튼 ?>
    </div>
</div>

<script>
jQuery(function($){
    $("#login_auto_login").click(function(){
        if (this.checked) {
            this.checked = confirm("자동로그인을 사용하시면 다음부터 회원아이디와 비밀번호를 입력하실 필요가 없습니다.\n\n공공장소에서는 개인정보가 유출될 수 있으니 사용을 자제하여 주십시오.\n\n자동로그인을 사용하시겠습니까?");
        }
    });
});

function flogin_submit(f)
{
    if( $( document.body ).triggerHandler( 'login_sumit', [f, 'flogin'] ) !== false ){
        return true;
    }
    return false;
}
</script>