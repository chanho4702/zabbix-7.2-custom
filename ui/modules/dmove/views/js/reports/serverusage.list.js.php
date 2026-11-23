<?php declare(strict_types = 0);
/*
 * 저사용 / 고사용 서버 사용현황 화면 전용 스크립트.
 *
 * 불러오는 곳: views/reports/serverusage.list.php 의 $this->includeJsFile('reports/serverusage.list.js.php')
 *
 * 하는 일:
 *   1) [기준설정] 버튼을 누르면 "수집기준 설정" 창을 연다.
 *   2) 창의 [설정] 버튼을 누르면 입력한 값을 서버에 저장하고, 성공하면 창을 닫고 목록을 다시 불러온다.
 *
 * ── 동작 순서 ──
 *   1) 뷰가 화면 맨 끝에서 dmoveServerUsageList.init({...}) 을 부른다.
 *   2) 사용자가 [기준설정] 을 누른다 -> PopUp() 이 zabbix.php?action=serverusage.threshold 를 호출해 창을 그린다.
 *   3) 사용자가 값을 고치고 [설정] 을 누른다 -> 창의 버튼이 submitThreshold(overlay) 를 부른다.
 *   4) submitThreshold 가 폼의 값을 zabbix.php?action=serverusage.threshold.save 로 보낸다. (POST, JSON)
 *   5) 성공이면 창을 닫고 화면을 새로 고친다. 실패면 창 안에 이유를 표시한다.
 *
 * ── 관련 파일 ──
 *   views/reports/serverusage.list.php                    버튼을 그리고 init() 을 부르는 뷰
 *   actions/reports/serverusage/ThresholdEdit.php         창을 여는 컨트롤러
 *   views/reports/serverusage.threshold.php               창 내용(JSON)을 만드는 뷰
 *   actions/reports/serverusage/ThresholdSave.php         값을 저장하는 컨트롤러
 */
?>

<script>
	const dmoveServerUsageList = {

		/** 저장 액션 이름. init() 에서 채운다. (serverusage.threshold.save) */
		save_action: null,

		/**
		 * 뷰가 화면을 다 그린 뒤 한 번 부른다.
		 *
		 * @param {string} threshold_action  [기준설정] 창의 액션 이름. (serverusage.threshold)
		 * @param {string} save_action       저장 액션 이름. (serverusage.threshold.save)
		 */
		init({threshold_action, save_action}) {
			this.save_action = save_action;

			// 화면 전체에 클릭 감시를 하나만 걸고, 눌린 것이 [기준설정] 버튼(.js-serverusage-threshold)일 때만 처리한다.
			document.addEventListener('click', (e) => {
				// closest(): 눌린 요소 자신 또는 그 바깥쪽에서 조건에 맞는 가장 가까운 요소를 찾는다. 없으면 null.
				const button = e.target.closest('.js-serverusage-threshold');

				if (button === null) {
					return;
				}

				/*
				 * PopUp(액션, 넘길 값, 옵션): 코어 함수. 액션을 호출해서 받은 내용을 팝업 창으로 띄운다.
				 *   dialogue_class  : 팝업 창에 붙일 CSS 클래스.
				 *   trigger_element : 팝업을 닫았을 때 커서(포커스)를 돌려줄 요소.
				 */
				PopUp(threshold_action, {}, {
					dialogue_class: 'modal-popup-generic',
					trigger_element: button
				});
			});
		},

		/**
		 * 창의 [설정] 버튼이 부른다. 입력한 기준값을 저장한다.
		 *
		 * @param {Overlay} overlay  이 팝업 창 객체. 코어가 버튼의 action 을 실행할 때 넘겨 준다.
		 */
		submitThreshold(overlay) {
			const form = overlay.$dialogue.$body[0].querySelector('form');

			// 이전에 창 안에 표시한 오류 상자가 있으면 지운다.
			for (const element of form.parentNode.querySelectorAll('.msg-good, .msg-bad, .msg-warning')) {
				element.parentNode.removeChild(element);
			}

			// getFormFields(): 코어 함수. 폼의 입력값을 {이름: 값} 객체로 모은다. (CSRF 토큰도 같이 들어 있다)
			const fields = getFormFields(form);

			// Curl: 코어의 주소 조립 도우미. zabbix.php?action=serverusage.threshold.save 를 만든다.
			const curl = new Curl('zabbix.php');
			curl.setArgument('action', this.save_action);

			// fetch(): 화면을 옮기지 않고 서버를 호출한다. 응답이 올 때까지 창에 "처리 중" 표시가 돈다.
			fetch(curl.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify(fields)
			})
				.then((response) => response.json())
				.then((response) => {
					if ('error' in response) {
						// 아래 catch 로 넘긴다.
						throw {error: response.error};
					}

					// 성공: 창을 닫고, 새 기준으로 목록을 다시 조회한다.
					overlayDialogueDestroy(overlay.dialogueid);
					location.href = location.href;
				})
				.catch((exception) => {
					let title;
					let messages = [];

					if (typeof exception === 'object' && 'error' in exception) {
						title = exception.error.title;
						messages = exception.error.messages;
					}
					else {
						title = <?= json_encode(_('Unexpected server error.')) ?>;
					}

					// makeMessageBox(): 코어 함수. 빨간 오류 상자를 만든다. 폼 위에 끼워 넣는다.
					const message_box = makeMessageBox('bad', messages, title)[0];

					form.parentNode.insertBefore(message_box, form);
				})
				.finally(() => {
					// "처리 중" 표시를 끈다.
					overlay.unsetLoading();
				});
		}
	};
</script>
