<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 현황 화면 전용 스크립트.
 *
 * 불러오는 곳: views/reports/winpatch.list.php 의 $this->includeJsFile('reports/winpatch.list.js.php')
 *
 * 하는 일: 표의 "상세보기" 링크를 누르면 그 호스트의 패치 상세정보 팝업을 연다.
 *
 * ── 동작 순서 ──
 *   1) 뷰가 화면 맨 끝에서 dmoveWinPatchList.init({detail_action: 'winpatch.detail', month: '필터의 수집월'}) 을 부른다.
 *   2) init() 이 화면 전체에 클릭 감시를 건다.
 *   3) 사용자가 상세보기 링크를 누른다. 링크의 data-hostid 값(호스트 ID)을 읽는다.
 *   4) 코어 함수 PopUp() 이 zabbix.php?action=winpatch.detail&hostid=..&month=.. 를 호출한다.
 *   5) 응답(JSON 의 header, body, buttons)으로 팝업 창이 그려진다.
 *
 * ── 관련 파일 ──
 *   views/reports/winpatch.list.php               링크를 그리고 init() 을 부르는 뷰
 *   actions/reports/winpatch/WinPatchDetail.php   팝업 컨트롤러
 *   views/reports/winpatch.detail.php             팝업 내용(JSON)을 만드는 뷰
 *
 * 화면 전용 스크립트 파일 규칙
 *   - 위치: views/js/<카테고리>/  파일 이름: <뷰 이름>.js.php
 *   - 내용 전체를 <script> 태그로 감싼다. 이 파일의 내용이 화면 HTML 안에 그대로 들어간다.
 *   - 다른 화면의 스크립트와 이름이 겹치지 않게 객체 이름을 dmove 로 시작한다.
 *   - PHP 값은 여기서 직접 쓰지 않고, 뷰에서 init({...}) 의 인자로 넘겨받는다.
 */
?>

<script>
	const dmoveWinPatchList = {

		/**
		 * 뷰가 화면을 다 그린 뒤 한 번 부른다.
		 *
		 * @param {string} detail_action  상세보기 팝업의 액션 이름. (winpatch.detail)
		 * @param {string} month          필터의 수집월 날짜. (YYYY-MM-DD)
		 *
		 * init({detail_action, month}) 의 중괄호: 객체로 넘어온 인자에서 두 값만 꺼내 쓰는 자바스크립트 문법이다.
		 */
		init({detail_action, month}) {
			/*
			 * 화면 전체에 클릭 감시를 하나만 걸고, 눌린 것이 상세보기 링크(.js-winpatch-detail)일 때만 처리한다.
			 * 링크마다 따로 걸지 않아도 되고, 줄이 많아도 느려지지 않는다.
			 */
			document.addEventListener('click', (e) => {
				// closest(): 눌린 요소 자신 또는 그 바깥쪽에서 조건에 맞는 가장 가까운 요소를 찾는다. 없으면 null.
				const link = e.target.closest('.js-winpatch-detail');

				if (link === null) {
					return;
				}

				/*
				 * PopUp(액션, 넘길 값, 옵션): 코어 함수. 액션을 호출해서 받은 내용을 팝업 창으로 띄운다.
				 *   link.dataset.hostid : 링크의 data-hostid 속성 값.
				 *   month               : init() 이 받은 수집월. 화면의 필터와 같은 달의 패치를 보여 주기 위해 같이 넘긴다.
				 *   dialogue_class      : 팝업 창에 붙일 CSS 클래스. modal-popup-generic 은 코어의 일반 팝업 모양이다.
				 *   trigger_element     : 팝업을 닫았을 때 커서(포커스)를 돌려줄 요소.
				 */
				PopUp(detail_action, {hostid: link.dataset.hostid, month: month}, {
					dialogue_class: 'modal-popup-generic',
					trigger_element: link
				});
			});
		}
	};
</script>
