<?php declare(strict_types = 0);
/*
 * SW 인벤토리 화면.
 *
 * 5버전 대응: 커스텀 폴더의 views/inventory/swinventories.php
 *
 * 뷰 파일 규칙
 *   - 파일명 = manifest.json 액션의 "view" 값 + ".php"
 *   - 컨트롤러가 넘긴 값은 $data['키'] 로 읽는다. (actions/inventory/swinventory/SwInventoryList.php 의 $data 배열)
 *   - HTML 을 직접 쓰지 않고 코어의 화면 클래스(CTableInfo, CFilter, CTextBox ...)를 조립한다.
 *     이 클래스들이 출력 시 자동으로 HTML 이스케이프를 해 주므로 XSS 걱정이 없다.
 *     (ui/include/classes/html/ 아래에 클래스별 파일이 있다.)
 *   - 뷰 파일은 네임스페이스가 없다. 모듈 클래스를 쓰려면 \Modules\DMove\... 전체 이름으로 쓴다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Export\ExportButtons;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\SwInventoryService;

/*
 * ── CSS / JS ────────────────────────────────────────────────────────────────
 * 모듈 공통 CSS(assets/css/dmove.css)는 manifest.json 의 "assets" 에 등록되어 모든 화면에 자동 로드된다.
 *
 * 이 화면에서만 쓰는 JS 가 필요하면:
 *   1) views/js/swinventory.list.js.php 파일을 만들고 (<script> 태그로 감싼 JS)
 *   2) 아래 주석을 푼다.
 *
 * $this->includeJsFile('swinventory.list.js.php');
 */

// ── 1) 필터 영역 ────────────────────────────────────────────────────────────
// 입력창 이름(filter_xxx)은 컨트롤러 checkInput() 의 키와 같아야 한다.

$text_filter_labels = [
	// Lang::t('영어 문구'): 사용자 언어가 한국어면 lang/ko.php 의 한국어로 바뀐다. (includes/Lang.php)
	'itsm' => Lang::t('ITSM host'),
	'os' => Lang::t('OS/Version'),
	'web' => 'WEB',
	'was' => 'WAS',
	'db' => 'DB',
	'duplex' => Lang::t('Redundancy')
];

// 필터는 좌/우 2단으로 배치한다. 왼쪽 3개, 오른쪽 3개 + Agent 체크박스.
$filter_columns = [new CFormList(), new CFormList()];
$index = 0;

foreach ($text_filter_labels as $key => $label) {
	$filter_columns[$index < 3 ? 0 : 1]->addRow($label,
		(new CTextBox('filter_'.$key, $data['filter'][$key]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	);
	$index++;
}

$agent_filter_labels = [
	'agent_install' => Lang::t('Installed'),
	'agent_uninstall' => Lang::t('Not installed'),
	'agent_exception' => Lang::t('Exception')
];

$agent_checkboxes = [];

foreach ($agent_filter_labels as $key => $label) {
	$agent_checkboxes[] = (new CCheckBox('filter_'.$key))
		->setLabel($label)
		->setChecked($data['filter'][$key] == 1);
}

$filter_columns[1]->addRow('Agent', new CHorList($agent_checkboxes));

$filter = (new CFilter())
	// [초기화] 버튼이 이동할 주소. CFilter 가 filter_rst=1 을 붙여 준다.
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	// 필터 접힘/펼침 상태를 저장할 프로파일 키. 컨트롤러의 PROFILE_PREFIX.'filter' 와 같아야 한다.
	->setProfile('web.dmove.swinventory.filter')
	->setActiveTab($data['active_tab'])
	// [적용] 을 눌렀을 때 같은 액션으로 돌아오도록 숨은 값으로 넣는다.
	->addVar('action', $data['action'])
	->addFilterTab(_('Filter'), $filter_columns);

// ── 2) 테이블 ───────────────────────────────────────────────────────────────

// 정렬 헤더가 만들 링크의 기준 주소.
$view_url = (new CUrl('zabbix.php'))
	->setArgument('action', $data['action'])
	->getUrl();

// 열 정의(정렬키 => 제목)는 컨트롤러의 getColumns() 한 곳에 있다. 내보내기 파일도 같은 정의를 쓴다.
$columns = $data['columns'];

$header = [];

foreach ($columns as $sort_key => $label) {
	// make_sorting_header: 클릭하면 sort / sortorder 파라미터를 붙여 다시 조회하는 헤더를 만든다.
	$header[] = make_sorting_header($label, $sort_key, $data['sort'], $data['sortorder'], $view_url);
}

$table = (new CTableInfo())
	->setHeader($header)
	->setPageNavigation($data['paging']);

// Agent 상태별 글자색. 코어 테마 클래스를 써서 어떤 테마(blue/dark)에서도 맞게 나온다.
$agent_styles = [
	SwInventoryService::AGENT_INSTALLED => ZBX_STYLE_GREEN,
	SwInventoryService::AGENT_NOT_INSTALLED => ZBX_STYLE_RED,
	SwInventoryService::AGENT_EXCEPTION => ZBX_STYLE_GREY
];

foreach ($data['rows'] as $row) {
	// Zabbix 에 등록되어 있고 인벤토리가 켜진 호스트면 코어의 인벤토리 상세 화면으로 링크한다.
	$host = $row['has_inventory']
		? new CLink($row['host'], (new CUrl('hostinventories.php'))->setArgument('hostid', $row['hostid']))
		: $row['host'];

	$table->addRow([
		(new CCol($host))->addClass(ZBX_STYLE_NOWRAP),
		$row['name'],
		(new CCol($row['ip']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['description']))->addClass(ZBX_STYLE_WORDBREAK),
		$row['os'],
		$row['web'],
		$row['was'],
		$row['db'],
		$row['duplex'],
		(new CSpan($data['agent_labels'][$row['agent']]))->addClass($agent_styles[$row['agent']])
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

$html_page = (new CHtmlPage())
	->setTitle(Lang::t('SW inventory'))
	// 제목 오른쪽 버튼 영역: [CSV 내보내기] [Excel 내보내기]. 현재 저장된 필터/정렬 그대로 내려받는다.
	->setControls(ExportButtons::create($data['export_action']))
	->addItem($filter);

if ($data['itsm_fallback']) {
	// conf/itsm.conf.php 에 ITSM 스키마/테이블 이름을 채우면 이 경고는 사라진다.
	$html_page->addItem(
		makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('ITSM table is not configured (conf/itsm.conf.php). Showing Zabbix hosts instead.'),
			false
		)
	);
}

$html_page
	->addItem(
		(new CForm('get'))
			->setName('dmove_swinventory')
			->addItem($table)
	)
	->show();
