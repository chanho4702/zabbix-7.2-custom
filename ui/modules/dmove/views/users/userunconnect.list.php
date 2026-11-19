<?php declare(strict_types = 0);
/*
 * 장기 미접속 계정 현황 화면.
 *
 * 컨트롤러: actions/users/userunconnect/UserUnconnectList.php
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Lang;

// ── 1) 필터 영역 ────────────────────────────────────────────────────────────

// 유저 종류: 버튼처럼 생긴 라디오 버튼 묶음. 두 번째 인자가 현재 선택된 값이다.
$user_type_radio = (new CRadioButtonList('filter_user_type', $data['filter']['user_type']))
	->addValue(_('All'), $data['user_type_any'])
	// user_type2str(): 코어 함수. 유저 종류 값을 이름(번역됨)으로 바꾼다. ui/include/users.inc.php
	->addValue(user_type2str(USER_TYPE_ZABBIX_USER), USER_TYPE_ZABBIX_USER)
	->addValue(user_type2str(USER_TYPE_ZABBIX_ADMIN), USER_TYPE_ZABBIX_ADMIN)
	->addValue(user_type2str(USER_TYPE_SUPER_ADMIN), USER_TYPE_SUPER_ADMIN)
	->setModern(true);

// 유저 그룹: 드롭다운. 0 = 모두.
$user_group_select = (new CSelect('filter_usrgrpid'))
	->setValue($data['filter']['usrgrpid'])
	->addOption(new CSelectOption(0, _('All')))
	->setFocusableElementId('filter_usrgrpid_select');

foreach ($data['user_groups'] as $usrgrpid => $name) {
	$user_group_select->addOption(new CSelectOption($usrgrpid, $name));
}

// 필터를 좌/우 2단으로 배치한다.
$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	->setProfile('web.dmove.userunconnect.filter')
	->setActiveTab($data['active_tab'])
	->addVar('action', $data['action'])
	->addFilterTab(_('Filter'), [
		(new CFormList())
			->addRow(_('Username'),
				(new CTextBox('filter_username', $data['filter']['username']))
					->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
			)
			// _x('문구', '문맥'): 같은 영어 단어가 여러 뜻으로 번역될 때 문맥을 지정한다. 여기서는 "사람 이름".
			->addRow(_x('Name', 'user first name'),
				(new CTextBox('filter_name', $data['filter']['name']))->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
			)
			->addRow(_('Last name'),
				(new CTextBox('filter_surname', $data['filter']['surname']))
					->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
			),
		(new CFormList())
			->addRow(Lang::t('User type'), $user_type_radio)
			->addRow(new CLabel(_('User groups'), 'filter_usrgrpid_select'), $user_group_select)
	]);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

$table = (new CTableInfo())
	->setHeader([
		_('Username'),
		_x('Name', 'user first name'),
		_('Last name'),
		Lang::t('User type'),
		_('Groups'),
		Lang::t('Last access')
	])
	->setPageNavigation($data['paging']);

foreach ($data['rows'] as $row) {
	$table->addRow([
		(new CCol($row['username']))->addClass(ZBX_STYLE_NOWRAP),
		$row['name'],
		$row['surname'],
		user_type2str($row['user_type']),
		(new CCol($row['groups']))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol($row['last_access']))->addClass(ZBX_STYLE_NOWRAP)
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->addItem($filter);

if (!$data['data_ready']) {
	$html_page->addItem(
		makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('Data retrieval is not implemented yet.'), false)
	);
}

$html_page
	->addItem($table)
	->show();
