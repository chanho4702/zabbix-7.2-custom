<?php declare(strict_types = 0);
/*
 * 일일점검 화면 전용 스크립트.
 *
 * 불러오는 곳: views/reports/dailycheck.list.php 의 $this->includeJsFile('reports/dailycheck.list.js.php')
 *
 * 하는 일: [CPU 사용률 Top 10] / [Memory 사용률 Top 10] 버튼을 누르면 Top 10 팝업을 연다.
 *
 * ── 동작 순서 ──
 *   1) 뷰가 화면 맨 끝에서 dmoveDailyCheckList.init({top_action: 'dailycheck.top'}) 을 부른다.
 *   2) init() 이 화면 전체에 클릭 감시를 건다.
 *   3) 사용자가 Top 10 버튼을 누른다. 버튼의 data-metric 값('cpu' 또는 'memory')을 읽는다.
 *   4) 코어 함수 PopUp() 이 zabbix.php?action=dailycheck.top&metric=cpu 를 호출한다.
 *   5) 응답(JSON 의 header, body, buttons)으로 팝업 창이 그려진다.
 *
 * ── 관련 파일 ──
 *   views/reports/dailycheck.list.php               버튼을 그리고 init() 을 부르는 뷰
 *   actions/reports/dailycheck/DailyCheckTop.php    팝업 컨트롤러
 *   views/reports/dailycheck.top.php                팝업 내용(JSON)을 만드는 뷰
 *
 * 화면 전용 스크립트 파일 규칙
 *   - 위치: views/js/<카테고리>/  파일 이름: <뷰 이름>.js.php
 *   - 내용 전체를 <script> 태그로 감싼다. 이 파일의 내용이 화면 HTML 안에 그대로 들어간다.
 *   - 다른 화면의 스크립트와 이름이 겹치지 않게 객체 이름을 dmove 로 시작한다.
 *   - PHP 값은 여기서 직접 쓰지 않고, 뷰에서 init({...}) 의 인자로 넘겨받는다.
 */
?>

<script>
	const dmoveDailyCheckList = {

		/**
		 * 뷰가 화면을 다 그린 뒤 한 번 부른다.
		 *
		 * @param {string} top_action  Top 10 팝업의 액션 이름. (dailycheck.top)
		 *
		 * init({top_action}) 의 중괄호: 객체로 넘어온 인자에서 top_action 값만 꺼내 쓰는 자바스크립트 문법이다.
		 * 예) init({top_action: 'dailycheck.top'}) 로 부르면 함수 안에서 top_action === 'dailycheck.top' 이다.
		 */
		init({top_action}) {
			/*
			 * 화면 전체에 클릭 감시를 하나만 걸고, 눌린 것이 Top 10 버튼(.js-dailycheck-top)일 때만 처리한다.
			 *   (e) => { ... } : 화살표 함수. 클릭할 때마다 실행된다. e 는 클릭 정보이고 e.target 이 눌린 요소다.
			 */
			document.addEventListener('click', (e) => {
				// closest(): 눌린 요소 자신 또는 그 바깥쪽에서 조건에 맞는 가장 가까운 요소를 찾는다. 없으면 null.
				const button = e.target.closest('.js-dailycheck-top');

				if (button === null) {
					return;
				}

				/*
				 * PopUp(액션, 넘길 값, 옵션): 코어 함수. 액션을 호출해서 받은 내용을 팝업 창으로 띄운다.
				 *   button.dataset.metric : 버튼의 data-metric 속성 값. 'cpu' 또는 'memory'.
				 *   dialogue_class        : 팝업 창에 붙일 CSS 클래스. modal-popup-generic 은 코어의 일반 팝업 모양이다.
				 *   trigger_element       : 팝업을 닫았을 때 커서(포커스)를 돌려줄 요소.
				 */
				PopUp(top_action, {metric: button.dataset.metric}, {
					dialogue_class: 'modal-popup-generic',
					trigger_element: button
				});
			});
		}
	};
</script>
