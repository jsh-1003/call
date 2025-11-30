<?php
// /adm/call/call_after_db_sub_form.php
?>
<style>
/* 상세정보 그리드 */
.ac-grid-row { display:flex; gap:10px; margin-bottom:8px; }
.ac-col { flex:1; display:flex; flex-direction:column; gap:2px; }
.ac-col label { font-size:11px; color:#666; font-weight:bold; }
.full-width { width:100%; box-sizing:border-box; }
</style>
    <!-- 상세 정보 입력 섹션 -->
    <div id="acDetailSection" hidden style="background:#f9fafb; padding:10px; border:1px solid #e5e7eb; border-radius:4px; margin-bottom:15px;">
        <form id="acDetailForm" method="post" action="./ajax_call_after_db_list.php" autocomplete="off">
        <input type="hidden" name="ajax" value="save">
        <input type="hidden" name="token" value="<?php echo get_token();?>">
        <input type="hidden" name="target_id" id="f_d_target_id" value="">

        <div class="ac-grid-row">
            <div class="ac-col">
                <label>고객명</label>
                <input type="text" name="detail_name" class="frm_input full-width">
            </div>
            <div class="ac-col">
                <label>생년월일</label>
                <input type="text" name="detail_birth" class="frm_input full-width" placeholder="YYYY-MM-DD">
            </div>
            <div class="ac-col">
                <label>나이</label>
                <input type="number" name="detail_age" class="frm_input full-width">
            </div>
            <div class="ac-col">
                <label>성별</label>
                <div style="display:flex; gap:10px; align-items:center; height:30px;">
                    <label><input type="radio" name="detail_sex" value="1"> 남</label>
                    <label><input type="radio" name="detail_sex" value="2"> 여</label>
                </div>
            </div>
        </div>
        <div class="ac-grid-row">
            <div class="ac-col">
                <label>연락처</label>
                <input type="text" name="detail_hp" class="frm_input full-width">
            </div>
            <div class="ac-col">
                <label>납입보험료</label>
                <input type="text" name="detail_month_pay" class="frm_input full-width">
            </div>
            <div class="ac-col" style="flex:2;">
                <label>방문희망일시</label>
                <input type="datetime-local" name="detail_scheduled_at" class="frm_input full-width">
            </div>
        </div>
        <div class="ac-grid-row">
            <div class="ac-col">
                <label>주소 (지역1)</label>
                <select name="detail_region1" class="frm_input full-width">
                    <option value="">선택</option>
                    <option value="서울">서울</option>
                    <option value="경기">경기</option>
                    <option value="인천">인천</option>
                    <option value="강원">강원</option>
                    <option value="충북">충북</option>
                    <option value="충남">충남</option>
                    <option value="대전">대전</option>
                    <option value="세종">세종</option>
                    <option value="전북">전북</option>
                    <option value="전남">전남</option>
                    <option value="광주">광주</option>
                    <option value="경북">경북</option>
                    <option value="경남">경남</option>
                    <option value="대구">대구</option>
                    <option value="울산">울산</option>
                    <option value="부산">부산</option>
                    <option value="제주">제주</option>
                </select>
            </div>
            <div class="ac-col">
                <label>주소 (지역2)</label>
                <select name="detail_region2" class="frm_input full-width">
                    <option value="">선택</option>
                    <!-- JS로 동적 처리 예정이나 우선 기본값 -->
                </select>
            </div>
            <div class="ac-col" style="flex:2;">
                <label>상세주소</label>
                <input type="text" name="detail_addr_etc" class="frm_input full-width">
            </div>
        </div>
        <div class="ac-grid-row">
            <div class="ac-col" style="flex:1;">
                <label>기타 / 메모</label>
                <textarea name="detail_memo" class="frm_input full-width" rows="4" style="padding:3px;line-height:1.5em"></textarea>
            </div>
        </div>

        <div class="ac-actions">
            <button type="submit" class="btn btn_01">상세정보저장</button>
            <!-- <button type="button" class="btn btn_02" id="acCancel">닫기</button> -->
        </div>
        </form>
    </div>

<script>
// ============================================================
// [전역 변수 및 초기화]
// ============================================================
var detailForm = document.getElementById(AC_SETTINGS.ids.detailForm);

// 2. 지역1 변경 시 지역2 업데이트
var r1Sel = document.querySelector('select[name="'+AC_SETTINGS.names.detail_region1+'"]');
if (r1Sel) {
    r1Sel.addEventListener('change', function() {
        after_db_updateRegion2(this.value);
    });
}

// 5. 디테일 폼 저장
if (detailForm) {
    detailForm.addEventListener('submit', function(e){
        e.preventDefault();
        var fd = new FormData(detailForm);
        // // ✅ FormData 내용 출력
        // console.group('FormData Dump');
        // for (const [key, value] of fd.entries()) {
        //     console.log(key, value);
        // }
        // console.groupEnd();

        fetch('./ajax_call_after_db_list.php', {method:'POST', body:fd, credentials:'same-origin'})
            .then(r=>r.json())
            .then(j=>{
            if (j && j.success) {
                // location.reload();
                alert('저장 완료!');
            } else {
                alert('저장 실패: '+(j && j.message ? j.message : ''));
            }
            })
            .catch(err=>{ console.error(err); alert('저장 중 오류'); });
    });
}
</script>
