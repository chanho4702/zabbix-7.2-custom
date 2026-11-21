<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 현황 화면.
 *
 * 컨트롤러: actions/reports/winpatch/WinPatchList.php
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Export\ExportButtons;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;

/*
 * 이 화면 전용 스크립트를 불러온다. 스크립트 파일은 views/js/ 아래에 두고, 그 폴더를 기준으로 한 경로를 적는다.
 *   뷰       : views/reports/winpatch.list.php
 *   스크립트 : views/js/reports/winpatch.list.js.php
 * (상세보기 링크를 누르면 팝업을 여는 코드가 들어 있다)
 */
$this->includeJsFile('reports/winpatch.list.js.php');

// ── 1) 필터 영역 ────────────────────────────────────────────────────────────

// 호스트 그룹 / 호스트 선택창 두 줄은 공용 부품이 만들어 준다. (includes/filter/HostFilter.php)
$filter_form = HostFilter::addRows(new CFormList(), $data['host_filter'])
	->addRow('HotFix ID',
		(new CTextBox('filter_hotfixid', $data['filter']['hotfixid']))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	/*
	 * 날짜 입력 + 달력 버튼.
	 *   setDateFormat(ZBX_DATE) : 날짜만(YYYY-MM-DD). 시각까지 받으려면 ZBX_DATE_TIME.
	 *   setPlaceholder          : 비어 있을 때 보이는 안내 글자.
	 */
	->addRow(new CLabel(Lang::t('Collection month'), 'filter_month'),
		(new CDateSelector('filter_month', $data['filter']['month']))
			->setDateFormat(ZBX_DATE)
			->setPlaceholder(_('YYYY-MM-DD'))
	);

$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	->setProfile('web.dmove.winpatch.filter')
	->setActiveTab($data['active_tab'])
	->addVar('action', $data['action'])
	->addFilterTab(_('Filter'), [$filter_form]);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

$view_url = (new CUrl('zabbix.php'))
	->setArgument('action', $data['action'])
	->getUrl();

$table = (new CTableInfo())
	->setHeader([
		make_sorting_header($data['columns']['name'], 'name', $data['sort'], $data['sortorder'], $view_url),
		$data['columns']['ip'],
		$data['columns']['description'],
		Lang::t('Patch file name')
	])
	->setPageNavigation($data['paging']);

foreach ($data['rows'] as $row) {
	$table->addRow([
		(new CCol($row['name']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['ip']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['description']))->addClass(ZBX_STYLE_WORDBREAK),
		/*
		 * "상세보기" 링크. 누르면 패치 상세정보 팝업이 뜬다.
		 *   CLinkAction        : 주소로 이동하지 않고 스크립트로 동작하는 링크(점선 밑줄).
		 *   js-winpatch-detail : 스크립트(views/js/reports/winpatch.list.js.php)가 이 링크를 찾는 표시.
		 *   data-hostid        : 어느 호스트인지 스크립트에 알려 주는 값. HTML 의 data-* 속성이다.
		 */
		(new CLinkAction(Lang::t('View details')))
			->addClass('js-winpatch-detail')
			->setAttribute('data-hostid', $row['hostid'])
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->setControls(ExportButtons::create($data['export_action']))
	->addItem($filter);

if ($data['config_missing']) {
	$html_page->addItem(
		makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('Settings are required: %1$s', 'conf/winpatch.conf.php'),
			false
		)
	);
}

$html_page
	->addItem($table)
	->show();

/*
 * 스크립트에 값을 넘기고 시작시킨다.
 *   json_encode : PHP 값을 자바스크립트가 읽을 수 있는 글자로 바꾼다. (따옴표 등 특수문자를 안전하게 처리)
 *   setOnDocumentReady : 화면이 다 그려진 뒤에 실행한다.
 */
(new CScriptTag('dmoveWinPatchList.init('.json_encode([
	'detail_action' => $data['detail_action'],
	'month' => $data['filter']['month']
]).');'))
	->setOnDocumentReady()
	->show();
