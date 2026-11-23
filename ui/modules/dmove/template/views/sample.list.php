<?php declare(strict_types = 0);
/*
 * ★ 새 페이지용 화면 뷰 템플릿 ★  (복사해서 쓴다. 방법: template/README.md)
 *
 * 컨트롤러: actions/reports/sample/SampleList.php  (여기서 넘긴 값을 $data['키'] 로 읽는다)
 *
 * ── 이 뷰가 그리는 것 ──
 *   위에서부터 제목 줄(오른쪽에 내보내기 버튼) -> 필터(호스트, 이름) -> 표(호스트, 이름) -> 페이지 이동 줄.
 *   이 파일은 값을 계산하지 않는다. 컨트롤러가 준 값을 화면 부품에 넣어서 출력만 한다.
 *
 * ── 컨트롤러가 넘겨 주는 값 ($data) ──
 *   action         이 화면의 액션 이름. 예) 'sample.list'   (필터/정렬 링크의 주소에 쓴다)
 *   export_action  내보내기 액션 이름. 예) 'sample.export'
 *   title          화면 제목
 *   columns        열 제목. ['host' => '호스트', 'name' => '이름']
 *   filter         필터 입력창에 다시 채울 값. ['host' => 'web', 'name' => '']
 *   active_tab     필터 영역이 펼쳐져 있는지 (1 = 펼침)
 *   sort           지금 정렬 중인 열. 예) 'host'
 *   sortorder      정렬 방향. 'ASC' 또는 'DESC'
 *   paging         페이지 이동 줄 (컨트롤러가 CPagerHelper::paginate() 로 만든 것)
 *   rows           현재 페이지의 행. [['hostid' => '10084', 'host' => .., 'name' => ..], ...]
 *
 * 뷰 파일 규칙
 *   - 경로 = views/ + manifest.json 액션의 "view" 값 + ".php".  예) "view": "reports/sample.list"
 *     사이드바 카테고리 폴더(views/reports/ 등) 아래에 둔다.
 *   - HTML 을 직접 쓰지 않고 코어의 화면 클래스(CTableInfo, CFilter, CTextBox ...)를 조립한다.
 *     이 클래스들이 출력할 때 HTML 이스케이프를 해 주므로 값은 문자열 그대로 넘기면 된다.
 *     (클래스별 파일: ui/include/classes/html/)
 *   - 뷰 파일은 네임스페이스가 없다. 모듈 클래스는 아래처럼 use 로 선언하고 쓴다.
 *
 * 문구 규칙
 *   - 코어에 이미 있는 문구(Filter, Host, Name ...)는 _('Host') 를 쓴다.
 *   - 새 문구는 Lang::t('English text') 를 쓰고 lang/ko.php 에 한국어를 넣는다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Export\ExportButtons;
use Modules\DMove\Includes\Lang;

// ── 1) 필터 영역 ────────────────────────────────────────────────────────────
// [바꿀 곳] 입력창 이름(filter_xxx)은 컨트롤러 TEXT_FILTERS 의 키에 'filter_' 를 붙인 것과 같아야 한다.
//
// 다른 종류의 입력 예시
//   체크박스     : (new CCheckBox('filter_x'))->setLabel('라벨')->setChecked($값 == 1)
//   라디오 버튼  : views/users/userunconnect.list.php
//   드롭다운     : views/users/userunconnect.list.php
//   호스트/호스트 그룹 선택창 : views/reports/uptime.list.php

// CFormList: "라벨 + 입력창" 줄을 세로로 쌓는 틀. addRow(라벨, 입력창) 한 번이 한 줄이다.
// CTextBox(이름, 현재 값): 한 줄 글자 입력창. setWidth 로 너비(픽셀)를 정한다.
$filter_form = (new CFormList())
	->addRow(_('Host'),
		(new CTextBox('filter_host', $data['filter']['host']))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow(_('Name'),
		(new CTextBox('filter_name', $data['filter']['name']))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	);

// CFilter: 접었다 펼 수 있는 필터 상자. [적용] / [리셋] 버튼은 CFilter 가 알아서 붙인다.
$filter = (new CFilter())
	// [리셋] 버튼이 이동할 주소. CFilter 가 filter_rst=1 을 붙여 준다.
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	// [바꿀 곳] 필터 접힘/펼침 상태를 저장할 프로파일 키. 컨트롤러의 PROFILE_PREFIX + 'filter' 와 같아야 한다.
	->setProfile('web.dmove.sample.filter')
	->setActiveTab($data['active_tab'])
	// [적용] 을 눌렀을 때 같은 액션으로 돌아오도록 숨은 값으로 넣는다.
	->addVar('action', $data['action'])
	// 배열에 CFormList 를 2개 넣으면 필터가 좌/우 2단으로 배치된다.
	->addFilterTab(_('Filter'), [$filter_form]);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

// 정렬 헤더가 만들 링크의 기준 주소.
$view_url = (new CUrl('zabbix.php'))
	->setArgument('action', $data['action'])
	->getUrl();

// CTableInfo: 코어의 목록 표. 행이 하나도 없으면 "데이터가 없습니다" 문구를 알아서 보여 준다.
$table = (new CTableInfo())
	// [바꿀 곳] 표 제목 줄.
	//   make_sorting_header(라벨, 정렬키, ...) : 클릭하면 정렬되는 제목. 정렬키는 컨트롤러 SORT_FIELDS 에 있어야 한다.
	//   정렬이 필요 없는 컬럼은 라벨 문자열만 넣는다.
	//   제목 글자는 컨트롤러 getColumns() 의 값을 쓴다. (내보내기 파일과 같은 제목이 된다)
	->setHeader([
		make_sorting_header($data['columns']['host'], 'host', $data['sort'], $data['sortorder'], $view_url),
		make_sorting_header($data['columns']['name'], 'name', $data['sort'], $data['sortorder'], $view_url)
	])
	// 표 아래에 페이지 이동 줄을 붙인다.
	->setPageNavigation($data['paging']);

foreach ($data['rows'] as $row) {
	// [바꿀 곳] 제목 줄과 같은 순서로 값을 넣는다.
	//   링크 : new CLink('글자', (new CUrl('zabbix.php'))->setArgument('action', '...')->setArgument('id', $값))
	//   색   : (new CSpan('글자'))->addClass(ZBX_STYLE_GREEN)      // ZBX_STYLE_RED, ZBX_STYLE_GREY ...
	//   줄바꿈 금지 : (new CCol($값))->addClass(ZBX_STYLE_NOWRAP)
	$table->addRow([
		(new CCol($row['host']))->addClass(ZBX_STYLE_NOWRAP),
		$row['name']
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────
// CHtmlPage: 화면 한 장. addItem() 한 순서대로 위에서 아래로 놓인다. 마지막의 show() 가 실제로 출력한다.

(new CHtmlPage())
	->setTitle($data['title'])
	// [내보내기] 제목 오른쪽에 [CSV 내보내기] 버튼. 필요 없으면 이 줄을 지운다.
	->setControls(ExportButtons::create($data['export_action']))
	->addItem($filter)
	->addItem($table)
	->show();
