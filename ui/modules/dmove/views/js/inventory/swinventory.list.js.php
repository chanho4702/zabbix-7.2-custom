<?php declare(strict_types = 0);
/*
 * SW 인벤토리 화면 전용 스크립트.
 *
 * 불러오는 곳: views/inventory/swinventory.list.php 의 $this->includeJsFile('inventory/swinventory.list.js.php')
 *
 * 하는 일:
 *   1) Agent 열의 "미설치" / "예외" 글자를 누르면 상태 입력 창을 연다. (5버전: popup.agent)
 *   2) 창의 [저장] 을 누르면 입력한 상태와 사유를 저장하고, 성공하면 창을 닫고 목록을 다시 불러온다.
 *
 * ── 관련 파일 ──
 *   actions/inventory/swinventory/AgentEdit.php   창을 여는 컨트롤러
 *   views/inventory/swinventory.agent.php         창 내용
 *   actions/inventory/swinventory/AgentSave.php   저장하는 컨트롤러
 */
?>

<script>
	const dmoveSwInventoryList = {

		/** 저장 액션 이름. init() 에서 채운다. */
		save_action: null,

		/**
		 * 뷰가 화면을 다 그린 뒤 한 번 부른다.
		 *
		 * @param {string} agent_action  상태 입력 창의 액션 이름. (swinventory.agent)
		 * @param {string} save_action   저장 액션 이름. (swinventory.agent.save)
		 */
		init({agent_action, save_action}) {
			this.save_action = save_action;

			// 화면 전체에 클릭 감시를 하나만 걸고, 눌린 것이 Agent 상태 링크(.js-swinventory-agent)일 때만 처리한다.
			document.addEventListener('click', (e) => {
				const link = e.target.closest('.js-swinventory-agent');

				if (link === null) {
					return;
				}

				// 링크의 기본 동작(주소 이동)을 막는다.
				e.preventDefault();

				// link.dataset.key / name : 링크의 data-key, data-name 속성 값.
				PopUp(agent_action, {key: link.dataset.key, name: link.dataset.name}, {
					dialogue_class: 'modal-popup-generic',
					trigger_element: link
				});
			});
		},

		/**
		 * 창의 [저장] 버튼이 부른다.
		 *
		 * @param {Overlay} overlay  이 팝업 창 객체. 코어가 버튼의 action 을 실행할 때 넘겨 준다.
		 */
		submitAgent(overlay) {
			const form = overlay.$dialogue.$body[0].querySelector('form');

			// 이전에 표시한 오류 상자가 있으면 지운다.
			for (const element of form.parentNode.querySelectorAll('.msg-good, .msg-bad')) {
				element.parentNode.removeChild(element);
			}

			// getFormFields(): 코어 함수. 폼의 입력값을 {이름: 값} 객체로 모은다. (CSRF 토큰, key 도 같이 들어 있다)
			const fields = getFormFields(form);
			const curl = new Curl('zabbix.php');
			curl.setArgument('action', this.save_action);

			fetch(curl.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify(fields)
			})
				.then((response) => response.json())
				.then((response) => {
					if ('error' in response) {
						throw {error: response.error};
					}

					// 성공: 창을 닫고 목록을 다시 불러온다. (바뀐 상태가 표에 보인다)
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
					form.parentNode.insertBefore(makeMessageBox('bad', messages, title)[0], form);
				})
				.finally(() => {
					overlay.unsetLoading();
				});
		}
	};
</script>
