<?php declare(strict_types = 0);
/*
 * 장기 미접속 계정 현황 화면의 뷰. (화면을 그리는 파일)
 *
 * 컨트롤러: actions/users/userunconnect/UserUnconnectList.php
 *
 * ── 이 파일이 하는 일 ──
 *   컨트롤러가 조회해서 넘긴 값($data)으로 필터와 표를 조립해서 화면에 출력한다.
 *   여기서는 DB 나 API 를 읽지 않는다.
 *
 *   화면 : zabbix.php?action=userunconnect.list
 *
 * ── 화면 구성 ──
 *   필터 왼쪽   : 로그인 이름, 이름, 성 (입력)
 *   필터 오른쪽 : 유저 종류(전부 / User / Admin / Super admin), 유저 그룹(드롭다운)
 *   기준 안내문 : 표 위 오른쪽의 빨간 글씨. 며칠 이상 접속하지 않은 계정인지 알려 준다
 *   경고 상자   : 감사 로그 보관 기간이 기준 일수보다 짧을 때만 나온다
 *   표          : 로그인 이름, 이름, 성, 유저 종류(여기까지 정렬 가능), 그룹, 최근접속일시
 *
 * ── 받는 값 ($data) ──
 *   action                 이 화면의 액션 이름. 'userunconnect.list'
 *   title                  화면 제목
 *   active_tab             필터가 펼쳐져 있는지 (지난번 상태)
 *   filter                 ['username' => '', 'name' => '', 'surname' => '', 'user_type' => -1, 'usrgrpid' => 0]
 *   user_groups            유저 그룹 드롭다운의 항목. [usrgrpid => 이름]
 *   user_type_any          유저 종류 필터의 "전부" 값 (-1)
 *   sort                   현재 정렬 열. 'username', 'name', 'surname', 'user_type' 중 하나
 *   sortorder              현재 정렬 방향. 'ASC' 또는 'DESC'
 *   inactive_days          미접속 기준 일수. 예) 60
 *   created_check          만든 날짜를 따지는 방식. 'exclude_recent' / 'required' / 'none'
 *   audit_retention_short  true 면 감사 로그 보관 기간이 기준 일수보다 짧다 (경고 상자를 띄운다)
 *   paging                 페이지 번호 줄
 *   rows                   행 1개 = 계정 1개.
 *                          ['username' => 'user01', 'name' => '길동', 'surname' => '홍', 'user_type' => 1,
 *                           'groups' => [['usrgrpid' => '7', 'name' => '그룹A', ...]], 'last_access' => '2026-01-02 03:04:05']
 *                          역할이 없는 유저는 user_type 이 null
 *   max_in_table           그룹 칸에 보여 줄 그룹 수 한도
 *   allowed_ui_user_groups true 면 그룹 이름을 그룹 편집 링크로
 *   session_retention_short true 면 세션 보관 기간이 기준 일수보다 짧다 (경고 상자)
 *                          접속 기록이 없으면 last_access 는 ''
 *
 * ── 관련 파일 ──
 *   actions/users/userunconnect/UserUnconnectList.php   컨트롤러
 *   conf/userunconnect.conf.php                         설정 (미접속 기준 일수 등)
 *   assets/css/dmove.css                                dm-criteria 클래스
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
// CSelect(입력 이름): 드롭다운. addOption(new CSelectOption(값, 표시할 글자)) 로 항목을 넣는다.
// setFocusableElementId: 라벨(CLabel)을 눌렀을 때 커서가 갈 요소의 ID. 아래 CLabel 의 두 번째 인자와 같아야 한다.
$user_group_select = (new CSelect('filter_usrgrpid'))
	->setValue($data['filter']['usrgrpid'])
	->addOption(new CSelectOption(0, _('All')))
	->setFocusableElementId('filter_usrgrpid_select');

foreach ($data['user_groups'] as $usrgrpid => $name) {
	$user_group_select->addOption(new CSelectOption($usrgrpid, $name));
}

// 필터를 좌/우 2단으로 배치한다. addFilterTab 에 CFormList 를 2개 넣으면 2단이 된다.
// CFilter: 화면 위쪽의 접었다 펼 수 있는 필터 상자. [적용] / [초기화] 버튼은 CFilter 가 만들어 준다.
$filter = (new CFilter())
	// [초기화] 버튼이 이동할 주소. CFilter 가 filter_rst=1 을 붙여 준다.
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	// 필터 접힘/펼침 상태를 저장할 프로파일 키. 컨트롤러의 PROFILE_PREFIX.'filter' 와 같아야 한다.
	->setProfile('web.dmove.userunconnect.filter')
	->setActiveTab($data['active_tab'])
	// [적용] 을 눌렀을 때 같은 액션으로 돌아오도록 숨은 값으로 넣는다.
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

/**
 * 그룹 칸을 만든다. 5버전과 코어 유저 목록 화면(administration.user.list.php)과 같은 모양이다.
 *   - 그룹 이름은 그룹 편집 링크. (그룹 화면 권한이 없으면 글자만)
 *   - GUI 접근이 막혔거나 그룹이 비활성이면 빨강, 아니면 초록.
 *   - 그룹이 $max 개를 넘으면 나머지는 "…" 로 줄인다.
 *
 * @param array $groups   [['usrgrpid', 'name', 'gui_access', 'users_status'], ...] 이름순
 * @param int   $max      보여 줄 그룹 수 한도
 * @param bool  $as_link  그룹 이름을 링크로 만들지
 */
function makeDmoveUserGroupList(array $groups, int $max, bool $as_link): array {
	$items = [];

	foreach ($groups as $i => $group) {
		if ($i >= $max) {
			$items[] = [' ', HELLIP()];
			break;
		}

		if ($items) {
			$items[] = ', ';
		}

		$item = $as_link
			? (new CLink($group['name'], (new CUrl('zabbix.php'))
				->setArgument('action', 'usergroup.edit')
				->setArgument('usrgrpid', $group['usrgrpid'])
				->getUrl()
			))->addClass(ZBX_STYLE_LINK_ALT)
			: new CSpan($group['name']);

		$items[] = $item->addClass(
			($group['gui_access'] == GROUP_GUI_ACCESS_DISABLED || $group['users_status'] == GROUP_STATUS_DISABLED)
				? ZBX_STYLE_RED
				: ZBX_STYLE_GREEN
		);
	}

	return $items;
}

// 제목을 누르면 그 열로 정렬된다. make_sorting_header(제목, 정렬 키, 현재 정렬 키, 현재 방향, 이 화면 주소): 코어 함수.
$sort_url = (new CUrl('zabbix.php'))->setArgument('action', $data['action'])->getUrl();

$table = (new CTableInfo())
	->setHeader([
		make_sorting_header(_('Username'), 'username', $data['sort'], $data['sortorder'], $sort_url),
		make_sorting_header(_x('Name', 'user first name'), 'name', $data['sort'], $data['sortorder'], $sort_url),
		make_sorting_header(_('Last name'), 'surname', $data['sort'], $data['sortorder'], $sort_url),
		make_sorting_header(Lang::t('User type'), 'user_type', $data['sort'], $data['sortorder'], $sort_url),
		_('Groups'),
		Lang::t('Last access')
	])
	->setPageNavigation($data['paging']);

foreach ($data['rows'] as $row) {
	$table->addRow([
		// 아이디를 누르면 그 유저의 편집 화면으로 간다. (5버전과 같다)
		(new CCol(new CLink($row['username'],
			(new CUrl('zabbix.php'))
				->setArgument('action', 'user.edit')
				->setArgument('userid', $row['userid'])
				->getUrl()
		)))->addClass(ZBX_STYLE_NOWRAP),
		$row['name'],
		$row['surname'],
		// 역할이 없는 유저는 종류가 없다.
		$row['user_type'] !== null ? user_type2str($row['user_type']) : '-',
		(new CCol(makeDmoveUserGroupList($row['groups'], $data['max_in_table'], $data['allowed_ui_user_groups'])))
			->addClass(ZBX_STYLE_WORDBREAK),
		// 접속 기록이 한 번도 없는 계정은 '-' 로 표시한다. 모두 장기 미접속이라 5버전처럼 빨간색이다.
		(new CCol($row['last_access'] !== '' ? $row['last_access'] : '-'))
			->addClass(ZBX_STYLE_NOWRAP)
			->addClass(ZBX_STYLE_RED)
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

// CHtmlPage: 화면 한 장. 제목과 본문(필터, 안내문, 표)을 차례로 넣고 마지막에 show() 로 출력한다.
$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->addItem($filter);

// 기준 안내. sprintf 의 %1$d 자리에 기준 일수가 들어간다.
$html_page->addItem(
	(new CDiv(sprintf(Lang::t('* Accounts that have not logged in for %1$d days or more.'), $data['inactive_days'])))
		->addClass('dm-criteria')
		->addClass(ZBX_STYLE_RED)
);

/*
 * 감사 로그 보관 기간이 기준 일수보다 짧으면 결과가 틀릴 수 있어 이유를 알려 준다. (컨트롤러 isAuditRetentionShorterThan)
 * 만든 날짜를 따지는 방식(created_check)에 따라 틀리는 방향이 달라서 문구도 다르다.
 */
if ($data['audit_retention_short']) {
	$message = $data['created_check'] === 'required'
		? Lang::t('The audit log is kept for less than %1$d days, so account creation dates cannot be checked and the list may be empty. Check conf/userunconnect.conf.php.')
		: Lang::t('The audit log is kept for less than %1$d days, so recently created accounts that never logged in may also be listed. Set the audit log storage period to at least %1$d days.');

	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [], sprintf($message, $data['inactive_days']), false));
}

// 세션 보관 기간이 짧으면 최근 접속 기록이 지워져, 최근에 접속한 계정도 미접속으로 나온다.
if ($data['session_retention_short']) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [],
		sprintf(Lang::t('Sessions are kept for less than %1$d days, so accounts that logged in recently may also be listed. Set the session storage period to at least %1$d days.'), $data['inactive_days']),
		false
	));
}

$html_page
	->addItem($table)
	->show();
