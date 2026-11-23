<?php declare(strict_types = 0);
/*
 * SW 인벤토리 화면의 뷰. (화면을 그리는 파일)
 *
 * 5버전 대응: 커스텀 폴더의 views/inventory/swinventories.php
 *
 * ── 이 파일이 하는 일 ──
 *   컨트롤러가 조회해서 넘긴 값($data)으로 필터와 표를 조립해서 화면에 출력한다.
 *   여기서는 DB 나 API 를 읽지 않는다. 조회는 모두 컨트롤러가 끝낸다.
 *
 *   화면     : zabbix.php?action=swinventory.list
 *   컨트롤러 : actions/inventory/swinventory/SwInventoryList.php
 *
 * ── 화면 구성 ──
 *   제목 오른쪽 : [CSV 내보내기]
 *   필터        : 왼쪽 단에 글자 입력 3개, 오른쪽 단에 글자 입력 3개 + Agent 체크박스(설치 / 미설치 / 예외)
 *   경고 상자   : ITSM 설정이 비어 있어 Zabbix 호스트로 대신 보여 줄 때만 나온다
 *   표          : 열 제목을 누르면 정렬된다. 아래쪽에 페이지 번호가 붙는다
 *
 * ── 받는 값 ($data) ──
 *   action         이 화면의 액션 이름. 'swinventory.list'  (필터, 정렬 링크의 주소에 쓴다)
 *   export_action  내보내기 액션 이름. 'swinventory.export'
 *   columns        열 정의. [정렬 키 => 제목]
 *   filter         현재 필터 값. ['itsm' => '', 'os' => '', ..., 'agent_install' => 1, ...]
 *   active_tab     필터가 펼쳐져 있는지 (지난번 상태)
 *   sort           현재 정렬 열
 *   sortorder      현재 정렬 방향. 'ASC' 또는 'DESC'
 *   agent_labels   [Agent 상태 값 => 화면에 표시할 글자]
 *   itsm_fallback  true 면 ITSM 대신 Zabbix 호스트를 보여 주는 중이다 (경고 상자를 띄운다)
 *   paging         페이지 번호 줄 (컨트롤러가 CPagerHelper 로 만든 것)
 *   rows           표의 행. 현재 페이지 분량만 들어 있다
 *
 * ── 관련 파일 ──
 *   actions/inventory/swinventory/SwInventoryList.php   컨트롤러
 *   includes/SwInventoryService.php                     행 조회, Agent 상태 상수
 *   includes/export/ExportButtons.php                   내보내기 버튼
 *   conf/itsm.conf.php                                  ITSM 설정
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

/*
 * 이 화면 전용 스크립트를 불러온다. (Agent 상태 입력 창을 열고 저장하는 코드가 들어 있다)
 */
$this->includeJsFile('inventory/swinventory.list.js.php');

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
// CFormList: "라벨 + 입력창" 줄을 차례로 쌓는 폼. addRow(라벨, 입력창) 으로 한 줄을 넣는다.
$filter_columns = [new CFormList(), new CFormList()];
$index = 0;

foreach ($text_filter_labels as $key => $label) {
	// $index < 3 ? 0 : 1 : 앞의 3개는 왼쪽 단(0), 나머지는 오른쪽 단(1)에 넣는다.
	// CTextBox(입력 이름, 현재 값): 한 줄 글자 입력창. 입력 이름은 filter_itsm, filter_os ... 가 된다.
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
	// CCheckBox(입력 이름): 체크박스. 저장된 필터 값이 1 이면 체크된 상태로 그린다.
	$agent_checkboxes[] = (new CCheckBox('filter_'.$key))
		->setLabel($label)
		->setChecked($data['filter'][$key] == 1);
}

// CHorList: 항목을 가로로 나란히 배치한다. 체크박스 3개가 한 줄에 놓인다.
$filter_columns[1]->addRow('Agent', new CHorList($agent_checkboxes));

// CFilter: 화면 위쪽의 접었다 펼 수 있는 필터 상자. [적용] / [초기화] 버튼은 CFilter 가 만들어 준다.
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

// CTableInfo: 코어의 목록 표. 행이 하나도 없으면 "데이터 없음" 안내 줄을 스스로 표시한다.
$table = (new CTableInfo())
	->setHeader($header)
	// 표 아래에 페이지 번호 줄을 붙인다.
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

	/*
	 * 표에 한 줄을 넣는다. 배열의 순서가 열의 순서다.
	 *   CCol(값)             : 표의 한 칸. CSS 클래스를 붙이고 싶을 때만 쓴다. 그냥 값만 넣어도 칸이 된다.
	 *   ZBX_STYLE_NOWRAP     : 칸 안에서 줄바꿈을 하지 않는다. (호스트 이름, IP)
	 *   ZBX_STYLE_WORDBREAK  : 긴 글자를 칸 너비에 맞춰 줄바꿈한다. (설명)
	 *   CSpan(값)            : 글자에 색 같은 CSS 클래스를 입힐 때 쓰는 <span>.
	 */
	/*
	 * Agent 칸. (5버전과 같다)
	 *   설치                     : 글자만
	 *   미설치 / 예외 (입력 가능한 줄) : 누르면 상태 입력 창이 열리는 링크. 마우스를 올리면 마지막 사유가 보인다.
	 *     js-swinventory-agent : 스크립트(views/js/inventory/swinventory.list.js.php)가 이 링크를 찾는 표시
	 *     data-key, data-name  : 창에 넘길 줄의 키와 서버 이름
	 */
	$agent_label = $data['agent_labels'][$row['agent']];

	if ($row['agent_editable']) {
		$agent_cell = (new CLinkAction($agent_label))
			->addClass('js-swinventory-agent')
			->addClass($agent_styles[$row['agent']])
			->setAttribute('data-key', $row['rowid'])
			->setAttribute('data-name', $row['host']);

		if ($row['agent_note'] !== '') {
			// setHint(내용): 마우스를 올리면 작은 말풍선으로 보인다.
			$agent_cell->setHint($row['agent_note']);
		}
	}
	else {
		$agent_cell = (new CSpan($agent_label))->addClass($agent_styles[$row['agent']]);
	}

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
		$agent_cell
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

// CHtmlPage: 화면 한 장. 제목, 제목 오른쪽 버튼, 본문(필터, 표)을 차례로 넣고 마지막에 show() 로 출력한다.
$html_page = (new CHtmlPage())
	->setTitle(Lang::t('SW inventory'))
	// 제목 오른쪽 버튼 영역: [CSV 내보내기]. 현재 저장된 필터/정렬 그대로 내려받는다.
	->setControls(ExportButtons::create($data['export_action']))
	->addItem($filter);

if (!$data['itsm_fallback'] && !$data['agent_status_available']) {
	// Agent 상태 표(extends_agent)가 없으면 Zabbix 에 없는 서버는 모두 "미설치"로 나온다. 이유를 알려 준다.
	$html_page->addItem(
		makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('The Agent status table does not exist.').' '
			.Lang::t('Run sql/extends_agent.sql in the Zabbix database, or check conf/swinventory.conf.php.'), false
		)
	);
}

if ($data['itsm_fallback']) {
	// conf/itsm.conf.php 에 ITSM 스키마/테이블 이름을 채우면 이 경고는 사라진다.
	// makeMessageBox(종류, 상세 목록, 제목 글자, 닫기 버튼 여부): 코어 함수. 노란 경고 상자를 만든다.
	$html_page->addItem(
		makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('ITSM table is not configured (conf/itsm.conf.php). Showing Zabbix hosts instead.'),
			false
		)
	);
}

// 표를 <form> 으로 감싸서 넣고 화면을 출력한다. show() 를 부르지 않으면 아무것도 나오지 않는다.
$html_page
	->addItem(
		(new CForm('get'))
			->setName('dmove_swinventory')
			->addItem($table)
	)
	->show();

/*
 * 스크립트에 값을 넘기고 시작시킨다.
 *   json_encode : PHP 값을 자바스크립트가 읽을 수 있는 글자로 바꾼다.
 *   setOnDocumentReady : 화면이 다 그려진 뒤에 실행한다.
 */
(new CScriptTag('dmoveSwInventoryList.init('.json_encode([
	'agent_action' => 'swinventory.agent',
	'save_action' => 'swinventory.agent.save'
]).');'))
	->setOnDocumentReady()
	->show();
