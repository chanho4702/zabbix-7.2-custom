<?php declare(strict_types = 0);
/*
 * 성능 데이터 추세 분석 화면.
 *
 * 컨트롤러: actions/reports/perftrend/PerfTrendList.php
 * 5버전   : monitoring.data.analysis.php
 *
 * ── 이 파일이 하는 일 ──
 *   위쪽에 그래프(컨트롤러가 코어 함수로 그려 준 SVG)를, 아래쪽에 탭 [데이터 셋] [기간] 을 그린다.
 *
 *   화면 : zabbix.php?action=perftrend.list
 *
 * ── 화면 구성 ──
 *   제목 오른쪽 : [CSV 내보내기]
 *   그래프      : 데이터 셋이 없거나 패턴이 비어 있으면 안내 문구
 *   탭          : [데이터 셋] 줄마다 색 / 호스트 패턴 / 아이템 패턴 / 그래프 형식 / 삭제, [+ 데이터 셋 추가]
 *                 [기간]     코어 시간 선택기
 *                 [적용] 을 누르면 데이터 셋이 저장되고 그래프가 다시 그려진다. [리셋] 은 데이터 셋을 지운다.
 *
 * ── 받는 값 ($data) ──
 *   action, export_action, title
 *   timeline, timeline_profile, active_tab   시간 선택기와 필터 탭 상태
 *   data_sets       [['hosts' => [...], 'items' => [...], 'color' => 'FF465C', 'type' => 0], ...]
 *   max_data_sets   데이터 셋 최대 개수 (10)
 *   graph           ['svg' => .., 'legend' => .., 'errors' => [...]] 또는 null(그릴 것이 없음)
 *
 * ── 관련 파일 ──
 *   views/js/reports/perftrend.list.js.php   데이터 셋 줄 추가/삭제
 *   includes/PerfTrendService.php            그래프 그리기, CSV
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Export\ExportButtons;
use Modules\DMove\Includes\Lang;

/*
 * 시간 선택기에 필요한 코어 스크립트. 코어 보고서 화면(reports.auditlog.list.php)과 같다.
 *   gtlc.js            : 시간 선택기 동작(timeControl). 없으면 기간 버튼을 눌러도 아무 일도 일어나지 않는다.
 *   class.calendar.js  : 날짜 입력칸 옆 달력.
 */
$this->addJsFile('gtlc.js');
$this->addJsFile('class.calendar.js');

// 데이터 셋 줄을 추가/삭제하는 스크립트.
$this->includeJsFile('reports/perftrend.list.js.php');

// ── 1) 데이터 셋 줄 ─────────────────────────────────────────────────────────

/*
 * 그래프 형식 이름. 값은 코어 상수와 같다. (코어 번역에 일부 한국어가 없어 모듈 번역을 쓴다)
 *   선 = SVG_GRAPH_TYPE_LINE(0), 포인트 = POINTS(1), 계단 = STAIRCASE(2), 막대 = BAR(3)
 */
$type_labels = [
	SVG_GRAPH_TYPE_LINE => Lang::t('Line'),
	SVG_GRAPH_TYPE_POINTS => Lang::t('Points'),
	SVG_GRAPH_TYPE_STAIRCASE => Lang::t('Staircase'),
	SVG_GRAPH_TYPE_BAR => Lang::t('Bar')
];

/*
 * 데이터 셋 한 줄을 만든다. 같은 함수로 "새 줄 견본"(<template>)도 만든다. 견본은 번호 자리에 #{n} 을 넣는다.
 *
 *   ds[n][color] : 색. <input type="color"> 는 "#rrggbb" 를 보내고, 컨트롤러가 # 을 떼고 쓴다.
 *   ds[n][hosts] : 호스트 패턴. 쉼표로 여러 개. * 는 "아무 글자".  예) web-*, db-01
 *   ds[n][items] : 아이템 패턴. 규칙은 같다.                          예) CPU utilization
 *   ds[n][type]  : 그래프 형식.
 */
$make_row = static function (string $n, array $data_set) use ($type_labels): CRow {
	$type = new CSelect('ds['.$n.'][type]');

	foreach ($type_labels as $value => $label) {
		$type->addOption(new CSelectOption($value, $label));
	}

	$type->setValue($data_set['type']);

	return (new CRow([
		(new CInput('color', 'ds['.$n.'][color]', '#'.$data_set['color']))->addClass('dm-ds-color'),
		(new CTextBox('ds['.$n.'][hosts]', implode(', ', $data_set['hosts'])))
			->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
			->setAttribute('placeholder', Lang::t('host pattern')),
		(new CTextBox('ds['.$n.'][items]', implode(', ', $data_set['items'])))
			->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
			->setAttribute('placeholder', Lang::t('item pattern')),
		$type,
		// 줄 삭제 버튼. 스크립트가 js-ds-remove 를 찾아 처리한다.
		(new CSimpleButton(_('Remove')))->addClass(ZBX_STYLE_BTN_LINK)->addClass('js-ds-remove')
	]))->addClass('js-ds-row');
};

$ds_table = (new CTable())
	->setId('dm-ds-table')
	->setHeader(['', Lang::t('Host pattern'), Lang::t('Item pattern'), Lang::t('Graph type'), ''])
	->addClass('dm-ds-table');

foreach (array_values($data['data_sets']) as $n => $data_set) {
	$ds_table->addRow($make_row((string) $n, $data_set));
}

// 새 줄 견본. 화면에는 보이지 않고, [+ 데이터 셋 추가] 를 누르면 스크립트가 복사해 번호를 채운다.
$ds_template = (new CTag('template', true, $make_row('#{n}', [
	'hosts' => [], 'items' => [], 'color' => 'FF465C', 'type' => SVG_GRAPH_TYPE_LINE
])))->setId('dm-ds-row-tmpl');

$ds_tab = [
	$ds_table,
	$ds_template,
	(new CSimpleButton(Lang::t('Add new data set')))->addClass(ZBX_STYLE_BTN_LINK)->setId('dm-ds-add'),
	(new CDiv(Lang::t('Separate several patterns with commas. * matches any text. Example: web-*, db-01')))
		->addClass(ZBX_STYLE_GREY)
];

// ── 2) 필터 영역: [데이터 셋] 탭 + [기간] 시간 선택기 ─────────────────────────────

$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	->setProfile($data['timeline_profile'])
	->setActiveTab($data['active_tab'])
	->addVar('action', $data['action'])
	->addFilterTab(Lang::t('Data set'), [$ds_tab])
	// 시간 선택기 탭. 인자: 시작, 종료, 탭 표시 여부, 기간을 저장할 프로파일 키.
	->addTimeSelector($data['timeline']['from'], $data['timeline']['to'], true, $data['timeline_profile']);

// ── 3) 그래프 ───────────────────────────────────────────────────────────────

if ($data['graph'] === null) {
	$graph = (new CDiv(Lang::t('Enter a host pattern and an item pattern in the data set and press Apply.')))
		->addClass(ZBX_STYLE_NO_DATA_MESSAGE);
}
else {
	// SVG 와 범례는 코어가 만든 HTML 이다. 값의 이스케이프도 코어가 했다.
	$graph = (new CDiv(new CJsScript($data['graph']['svg'].$data['graph']['legend'])))->addClass('dm-graph');
}

// ── 4) 페이지 조립 ──────────────────────────────────────────────────────────

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->setControls(ExportButtons::create($data['export_action']));

if ($data['graph'] !== null && $data['graph']['errors']) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [], implode(' ', $data['graph']['errors']), false));
}

$html_page
	->addItem($graph)
	->addItem($filter)
	->show();

/*
 * 시간 선택기를 동작시키는 스크립트. (views/reports/peakevent.list.php 의 설명 참고)
 * 그리고 데이터 셋 스크립트를 시작한다.
 */
$timeline_object = [
	'id' => 'timeline_1',
	'domid' => 'perftrend',
	'loadSBox' => 0,
	'loadImage' => 0,
	'dynamic' => 0
];

(new CScriptTag(
	'timeControl.addObject("perftrend", '.json_encode($data['timeline']).', '.json_encode($timeline_object).');'.
	'timeControl.processObjects();'
))->show();

// 데이터 셋 줄 추가/삭제 스크립트를 시작한다. setOnDocumentReady: 화면이 다 그려진 뒤에 실행한다.
(new CScriptTag('dmovePerfTrendList.init('.json_encode(['max' => $data['max_data_sets']]).');'))
	->setOnDocumentReady()
	->show();
