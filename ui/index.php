<?php
/*
** Copyright (C) 2001-2025 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


require_once dirname(__FILE__).'/include/classes/user/CWebUser.php';

require_once dirname(__FILE__).'/include/config.inc.php';
require_once dirname(__FILE__).'/include/forms.inc.php';

$page['file'] = 'index.php';

// VAR	TYPE	OPTIONAL	FLAGS	VALIDATION	EXCEPTION
$fields = [
	'name' =>		[T_ZBX_STR, O_NO,	null,	null,	'isset({enter}) && {enter} != "'.ZBX_GUEST_USER.'"', _('Username')],
	'password' =>	[T_ZBX_STR, O_OPT, P_NO_TRIM,	null,	'isset({enter}) && {enter} != "'.ZBX_GUEST_USER.'"'],
	'sessionid' =>	[T_ZBX_STR, O_OPT, null,	null,	null],
	'reconnect' =>	[T_ZBX_INT, O_OPT, P_SYS|P_ACT,	null,	null],
	'enter' =>		[T_ZBX_STR, O_OPT, P_SYS,	null,	null],
	'autologin' =>	[T_ZBX_INT, O_OPT, null,	null,	null],
	'request' =>	[T_ZBX_STR, O_OPT, null,	null,	null],
	'form' =>		[T_ZBX_STR, O_OPT, null,	null,	null]
];
// [DM 해설] 로그인 화면의 진입 파일(옛 방식 페이지). 로그인 폼 표시와 로그인 처리(POST)를 모두 여기서 한다.
// [DM 해설] 위 $fields 가 로그인 폼에서 받을 수 있는 값 목록이다. 폼에 입력창(OTP 번호 등)을 추가하면 여기에도 추가해야 getRequest() 로 읽힌다.
// [DM 해설] 주의: 로그인하기 전에는 모듈이 로드되지 않는다. 그래서 이 화면 자체는 모듈로 바꿀 수 없다. (docs/LOGIN_CUSTOMIZATION.md)
check_fields($fields);

if (hasRequest('reconnect') && CWebUser::isLoggedIn()) {
	if (CAuthenticationHelper::get(CAuthenticationHelper::SAML_AUTH_ENABLED) == ZBX_AUTH_SAML_ENABLED) {
		$provisioning = CProvisioning::forUserDirectoryId(CAuthenticationHelper::getSamlUserdirectoryid());
		$saml_config = $provisioning->getIdpConfig();

		if ($saml_config['slo_url'] !== '' && CSessionHelper::has('saml_data')) {
			redirect('index_sso.php?slo');
		}
	}

	CWebUser::logout();
	redirect('index.php');
}

$autologin = hasRequest('enter') ? getRequest('autologin', 0) : getRequest('autologin', 1);
$request = getRequest('request', '');

if ($request !== '' && !CHtmlUrlValidator::validateSameSite($request)) {
	$request = '';
}

if (!hasRequest('form')
		&& CAuthenticationHelper::getPublic(CAuthenticationHelper::HTTP_AUTH_ENABLED) == ZBX_AUTH_HTTP_ENABLED
		&& CAuthenticationHelper::getPublic(CAuthenticationHelper::HTTP_LOGIN_FORM) == ZBX_AUTH_FORM_HTTP
		&& !hasRequest('enter')) {
	redirect('index_http.php');
}

// login via form
// [DM 해설] 로그인 처리의 핵심. [Sign in] 버튼(enter)이 눌렸으면 CWebUser::login() 으로 아이디/비밀번호를 검증한다.
// [DM 해설] 성공하면 이 블록 안으로 들어온다. 이 시점에 세션은 이미 만들어져 있다.
if (hasRequest('enter') && CWebUser::login(getRequest('name', ZBX_GUEST_USER), getRequest('password', ''))) {
	CSessionHelper::set('sessionid', CWebUser::$data['sessionid']);

	if (CWebUser::$data['autologin'] != $autologin) {
		API::User()->update([
			'userid' => CWebUser::$data['userid'],
			'autologin' => $autologin
		]);
	}

	// [DM 해설] 내장 2단계 인증(MFA). 사용자 그룹에 MFA 가 걸려 있으면 대시보드가 아니라 index_mfa.php 로 보낸다.
	// [DM 해설] 이때 세션은 '확인 필요' 상태라서 2단계를 통과하기 전에는 다른 화면에 들어갈 수 없다.
	// [DM 해설] 5버전에서 직접 만들었던 OTP 단계가 표준 TOTP 방식이면 이 내장 기능으로 대체할 수 있다.
	if (CWebUser::$data['mfaid']) {
		CSessionHelper::set('confirmid', CWebUser::$data['sessionid']);

		// In case user has not finished their Duo authentication, need to unset data saved into session by Duo.
		if (CSessionHelper::has('state')) {
			CSessionHelper::unset(['state', 'username']);
		}

		$mfa_url = (new CUrl('index_mfa.php'));

		if ($request !== '') {
			$mfa_url->setArgument('request', $request);
		}

		redirect($mfa_url->toString());
	}

	// [DM 해설] 로그인 성공 후 이동할 주소 결정: 원래 가려던 주소 > 사용자 설정 URL > 첫 번째 메뉴.
	// [DM 해설] 5버전처럼 코어를 고쳐 외부 인증(OTP 서버 등) 검증을 넣는다면 이 줄 앞이 그 자리다.
	$redirect = array_filter([$request, CWebUser::$data['url'], CMenuHelper::getFirstUrl()]);
	redirect(reset($redirect));
}

if (CWebUser::isLoggedIn() && !CWebUser::isGuest()) {
	redirect(CWebUser::$data['url'] ? : CMenuHelper::getFirstUrl());
}

$messages = get_and_clear_messages();

// [DM 해설] 로그인 화면 출력. 화면 모양은 include/views/general.login.php 에 있다.
// [DM 해설] 'error' 에 담긴 메시지가 아이디 입력창 아래 빨간 글씨로 표시된다.
echo (new CView('general.login', [
	'http_login_url' => (CAuthenticationHelper::getPublic(CAuthenticationHelper::HTTP_AUTH_ENABLED) == ZBX_AUTH_HTTP_ENABLED)
		? (new CUrl('index_http.php'))->setArgument('request', getRequest('request'))
		: '',
	'saml_login_url' => (CAuthenticationHelper::getPublic(CAuthenticationHelper::SAML_AUTH_ENABLED) == ZBX_AUTH_SAML_ENABLED)
		? (new CUrl('index_sso.php'))->setArgument('request', getRequest('request'))
		: '',
	'guest_login_url' => CWebUser::isGuestAllowed() ? (new CUrl())->setArgument('enter', ZBX_GUEST_USER) : '',
	'autologin' => $autologin == 1,
	'error' => (hasRequest('enter') && $messages) ? array_pop($messages) : null
]))->getOutput();

session_write_close();
