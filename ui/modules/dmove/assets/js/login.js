/*
 * 로그인 화면 스크립트. 5버전 js/loginzbx.js + index_hidfrm.php 의 대기 동작을 옮긴 것이다.
 *
 * 불러오는 곳: modules/dmove/includes/LoginAuthView.php (로그인 화면에 <script> 로 붙는다)
 * 로그인 화면은 Zabbix 의 공통 스크립트(jQuery 등)를 불러오지 않으므로 순수 자바스크립트로 쓴다.
 *
 * 하는 일:
 *   1) [MPASS] 를 체크하면 OTP 칸을 비우고 막는다. (5버전과 같다)
 *   2) MPASS 없이 [로그인] 인데 OTP 가 비었으면 "OTP 번호를 입력하십시오." (5버전과 같다)
 *   3) MPASS 로 [로그인] 이면 폼을 보내지 않고:
 *        index.php 에 dmove_mpass=request (아이디, 비밀번호)   -> 휴대폰 푸시. 비밀번호는 이때 한 번만 보낸다.
 *        대기 창을 띄우고 1초마다 남은 시간을 줄인다.
 *        5초마다 dmove_mpass=result                          -> 승인되면 받은 주소로 이동
 *        30초가 지나거나 [취소] 면 dmove_mpass=cancel          -> 창을 닫고 처음 화면으로
 */
(() => {
	'use strict';

	const form = document.querySelector('form[aria-label]');
	const mpass = document.getElementById('mpass');
	const otp = document.getElementById('otp');
	const popup = document.getElementById('dm-mpass-popup');

	if (form === null || mpass === null || otp === null || popup === null) {
		return;
	}

	const wait = parseInt(popup.dataset.wait, 10) || 30;
	const progress = popup.querySelector('progress');
	const seconds = popup.querySelector('.dm-mpass-seconds');
	let timer = null;
	let busy = false;

	// 1) MPASS 체크 -> OTP 칸 막기
	mpass.addEventListener('change', () => {
		if (mpass.checked) {
			otp.value = '';
		}

		otp.readOnly = mpass.checked;
		otp.disabled = mpass.checked;
	});

	// 오류 문구. 코어 로그인 화면과 같은 자리(아이디 칸 아래), 같은 모양(빨간 글씨)으로 보인다.
	const showError = (message) => {
		let box = form.querySelector('.dm-login-error');

		if (box === null) {
			box = document.createElement('div');
			box.className = 'red dm-login-error';
			document.getElementById('name').insertAdjacentElement('afterend', box);
		}

		box.textContent = message;
	};

	// index.php 에 MPASS 단계를 보낸다. 응답은 JSON.
	const call = (step, extra = {}) => {
		const body = new URLSearchParams({dmove_mpass: step, ...extra});
		const request = form.querySelector('input[name="request"]');

		if (request !== null) {
			body.set('request', request.value);
		}

		return fetch('index.php', {method: 'POST', body, credentials: 'same-origin'})
			.then((response) => response.json());
	};

	const closePopup = () => {
		clearInterval(timer);
		timer = null;
		popup.style.display = 'none';
		busy = false;
	};

	const cancel = (message) => {
		closePopup();
		call('cancel').catch(() => {});

		if (message) {
			showError(message);
		}
	};

	document.getElementById('dm-mpass-cancel').addEventListener('click', () => cancel(null));

	form.addEventListener('submit', (e) => {
		// 2) OTP 로 로그인: 폼을 그대로 보낸다.
		if (!mpass.checked) {
			if (otp.value.trim() === '') {
				e.preventDefault();
				alert('OTP 번호를 입력하십시오.');
			}

			return;
		}

		// 3) MPASS 로 로그인
		e.preventDefault();

		if (busy) {
			return;
		}

		busy = true;

		call('request', {
			// enter 를 같이 보내야 index.php 의 입력 검사가 name 을 받아 준다. (MPASS 처리는 일반 로그인보다 먼저 끝난다)
			enter: 'Sign in',
			name: document.getElementById('name').value,
			password: document.getElementById('password').value
		})
			.then((response) => {
				if (response.error !== undefined) {
					busy = false;
					showError(response.error);

					return;
				}

				// 대기 창
				let elapsed = 0;

				progress.value = 0;
				seconds.textContent = wait + '초';
				popup.style.display = '';

				timer = setInterval(() => {
					elapsed++;
					progress.value = elapsed;
					seconds.textContent = (wait - elapsed) + '초';

					if (elapsed >= wait) {
						cancel('MPASS 인증 시간이 지났습니다.');

						return;
					}

					if (elapsed % 5 !== 0) {
						return;
					}

					call('result')
						.then((result) => {
							if (timer === null) {
								return;
							}

							if (result.status === 'ok') {
								closePopup();
								window.location.href = result.redirect;
							}
							else if (result.error !== undefined) {
								closePopup();
								showError(result.error);
							}
						})
						.catch(() => {});
				}, 1000);
			})
			.catch(() => {
				busy = false;
				showError('MPASS 인증요청을 실패하였습니다.');
			});
	});
})();
