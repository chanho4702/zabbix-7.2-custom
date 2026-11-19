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


/**
 * @var CView $this
 */

define('ZBX_PAGE_NO_HEADER', 1);
define('ZBX_PAGE_NO_FOOTER', 1);
define('ZBX_PAGE_NO_MENU', true);
define('ZBX_PAGE_NO_JSLOADER', true);

require_once dirname(__FILE__).'/../page_header.php';
$error = null;

if ($data['error']) {
	// remove debug code for login form message, trimming not in regex to relay only on [ ] in debug message.
	$message = trim(preg_replace('/\[.*\]/', '', $data['error']['message']));
	$error = (new CDiv($message))->addClass(ZBX_STYLE_RED);
}

$guest = $data['guest_login_url']
	? (new CListItem([_('or'), ' ', new CLink(_('sign in as guest'), $data['guest_login_url'])]))
		->addClass(ZBX_STYLE_SIGN_IN_TXT)
	: null;

$http_login_link = $data['http_login_url']
	? (new CListItem(new CLink(_('Sign in with HTTP'), $data['http_login_url'])))->addClass(ZBX_STYLE_SIGN_IN_TXT)
	: null;

$saml_login_link = $data['saml_login_url']
	? (new CListItem(new CLink(_('Sign in with Single Sign-On (SAML)'), $data['saml_login_url'])))
		->addClass(ZBX_STYLE_SIGN_IN_TXT)
	: null;

global $ZBX_SERVER_NAME;

(new CDiv([
	(new CTag('main', true, [
		(isset($ZBX_SERVER_NAME) && $ZBX_SERVER_NAME !== '')
			? (new CDiv($ZBX_SERVER_NAME))->addClass(ZBX_STYLE_SERVER_NAME)
			: null,
		(new CDiv([
			// [DM 해설] 로그인 화면 로고. 로고와 도움말 주소는 이 파일을 고치지 않고 local/conf/brand.conf.php 로 바꿀 수 있다. (CBrandHelper)
			(new CDiv(makeLogo(LOGO_TYPE_NORMAL)))->addClass(ZBX_STYLE_SIGNIN_LOGO),
			// [DM 해설] 로그인 폼. action 을 지정하지 않으므로 index.php 자신에게 POST 된다.
			(new CForm())
				->setAttribute('aria-label', _('Sign in'))
				->addItem(hasRequest('request') ? new CVar('request', getRequest('request')) : null)
				->addItem(
					(new CList())
						->addItem([
							new CLabel(_('Username'), 'name'),
							(new CTextBox('name'))->setAttribute('autofocus', 'autofocus'),
							$error
						])
						->addItem([
							// [DM 해설] 비밀번호 입력 줄. 이 ->addItem([...]) 묶음 다음에 같은 모양으로 한 묶음을 추가하면 입력창이 하나 늘어난다.
							// [DM 해설] 추가한 입력창의 이름은 index.php 의 $fields 에도 넣어야 한다.
							new CLabel(_('Password'), 'password'),
							(new CPassBox('password'))->setAttribute('autocomplete', 'off')
						])
						->addItem(
							(new CCheckBox('autologin'))
								->setLabel(_('Remember me for 30 days'))
								->setChecked($data['autologin'])
						)
						// [DM 해설] [Sign in] 버튼. 이름이 'enter' 라서 index.php 가 hasRequest('enter') 로 로그인 시도를 알아챈다.
						->addItem(new CSubmit('enter', _('Sign in')))
						->addItem($guest)
						->addItem($http_login_link)
						->addItem($saml_login_link)
				)
		]))->addClass(ZBX_STYLE_SIGNIN_CONTAINER),
		(new CDiv([
			(new CLink(_('Help'), CBrandHelper::getHelpUrl()))
				->setTarget('_blank')
				->addClass(ZBX_STYLE_GREY)
				->addClass(ZBX_STYLE_LINK_ALT),
			CBrandHelper::isRebranded() ? null : [NBSP(), NBSP(), BULLET(), NBSP(), NBSP()],
			CBrandHelper::isRebranded()
				? null
				: (new CLink(_('Support'), getSupportUrl(CWebUser::getLang())))
					->setTarget('_blank')
					->addClass(ZBX_STYLE_GREY)
					->addClass(ZBX_STYLE_LINK_ALT)
		]))->addClass(ZBX_STYLE_SIGNIN_LINKS)
	])),
	makePageFooter(false)
]))
	->addClass(ZBX_STYLE_LAYOUT_WRAPPER)
	->show();
?>
</body>
