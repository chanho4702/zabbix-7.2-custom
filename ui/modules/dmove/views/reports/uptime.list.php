<?php declare(strict_types = 0);
/*
 * 서버 시간 확인 화면.
 *
 * 컨트롤러: actions/reports/uptime/UptimeList.php  (여기서 넘긴 값을 $data['키'] 로 읽는다)
 *
 * 문구 규칙
 *   - 코어에 이미 있는 문구는 _('Host groups') 처럼 코어 번역 함수를 쓴다. (한국어 UI 에서 자동 번역)
 *   - 모듈에서 새로 만든 문구는 Lang::t('Server uptime') 를 쓰고 lang/ko.php 에 번역을 넣는다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Lang;

// ── 1) 필터 영역 ────────────────────────────────────────────────────────────
// 입력 이름(filter_xxx)은 컨트롤러 checkInput() 의 키와 같아야 한다.

$filter_form = (new CFormList())
	/*
	 * 선택창(멀티셀렉트). [선택] 버튼을 누르면 코어의 팝업이 떠서 고를 수 있고, 입력하면 자동완성이 된다.
	 *   name         : 폼으로 넘어가는 이름. 여러 개라서 끝에 [] 를 붙인다.
	 *   object_name  : 자동완성 대상 종류. 호스트 그룹은 'hostGroup', 호스트는 'hosts'.
	 *   data         : 이미 선택된 값. [['id' => .., 'name' => ..], ...]
	 *   popup        : [선택] 팝업 설정. srctbl 이 팝업 종류, dstfrm 은 필터 폼 이름(항상 'zbx_filter'),
	 *                  dstfld1 은 name 에서 [] 를 _ 로 바꾼 값이다.
	 * 라벨의 두 번째 인자 'filter_groupids__ms' 는 "name 에서 [] 를 뺀 것 + __ms" 로, 라벨을 누르면 입력창에 커서가 간다.
	 */
	->addRow((new CLabel(_('Host groups'), 'filter_groupids__ms')),
		(new CMultiSelect([
			'multiple' => true,
			'name' => 'filter_groupids[]',
			'object_name' => 'hostGroup',
			'data' => $data['filter']['groups'],
			'popup' => [
				'parameters' => [
					'srctbl' => 'host_groups',
					'srcfld1' => 'groupid',
					'dstfrm' => 'zbx_filter',
					'dstfld1' => 'filter_groupids_',
					'with_hosts' => true,
					'enrich_parent_groups' => true
				]
			]
		]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow((new CLabel(_('Hosts'), 'filter_hostids__ms')),
		(new CMultiSelect([
			'multiple' => true,
			'name' => 'filter_hostids[]',
			'object_name' => 'hosts',
			'data' => $data['filter']['hosts'],
			'popup' => [
				'parameters' => [
					'srctbl' => 'hosts',
					'srcfld1' => 'hostid',
					'dstfrm' => 'zbx_filter',
					'dstfld1' => 'filter_hostids_'
				]
			]
		]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow(Lang::t('OS/Version'),
		(new CTextBox('filter_os', $data['filter']['os']))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow(Lang::t('Server uptime'),
		(new CTextBox('filter_uptime_days', $data['filter']['uptime_days']))
			->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
			// placeholder: 비어 있을 때 흐리게 보이는 안내 글자.
			->setAttribute('placeholder', Lang::t('days or more'))
	);

$filter = (new CFilter())
	// [리셋] 버튼이 이동할 주소. CFilter 가 filter_rst=1 을 붙여 준다.
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	// 필터 접힘/펼침 상태를 저장할 프로파일 키. 컨트롤러의 PROFILE_PREFIX.'filter' 와 같아야 한다.
	->setProfile('web.dmove.uptime.filter')
	->setActiveTab($data['active_tab'])
	// [적용] 을 눌렀을 때 같은 액션으로 돌아오도록 숨은 값으로 넣는다.
	->addVar('action', $data['action'])
	->addFilterTab(_('Filter'), [$filter_form]);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

$table = (new CTableInfo())
	->setHeader([
		_('Host'),
		_('IP'),
		_('Description'),
		_('OS'),
		Lang::t('Server time'),
		Lang::t('Server start')
	])
	->setPageNavigation($data['paging']);

foreach ($data['rows'] as $row) {
	// 값은 문자열 그대로 넘긴다. 화면 클래스가 출력할 때 HTML 이스케이프를 해 준다.
	$table->addRow([
		(new CCol($row['host']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['ip']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['description']))->addClass(ZBX_STYLE_WORDBREAK),
		$row['os'],
		(new CCol($row['server_time']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['server_start']))->addClass(ZBX_STYLE_NOWRAP)
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
