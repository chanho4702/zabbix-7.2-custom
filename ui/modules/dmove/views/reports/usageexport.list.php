<?php declare(strict_types = 0);
/*
 * CPU/Memory 전체 사용률 추출 화면.
 *
 * 컨트롤러: actions/reports/usageexport/UsageExportList.php
 * 5버전   : monitoring.perf.view.php
 *
 * 표가 없는 화면이다. 조건을 정하고 [CSV 내보내기] 로 파일을 내려받는 용도다.
 *
 * ── 이 파일이 하는 일 ──
 *   컨트롤러가 넘긴 값($data)으로 필터, 안내문, 내보내기 버튼을 그린다. 여기서는 DB 나 API 를 읽지 않는다.
 *
 *   화면 : zabbix.php?action=usageexport.list
 *
 * ── 화면 구성 ──
 *   오른쪽 위 : [CSV 내보내기] 버튼 (내보낼 수 있을 때만 나온다)
 *   필터      : 호스트 그룹, 호스트 (선택창), 항목(라디오 버튼), 시간대(라디오 버튼), 빨간 안내문 두 줄
 *               + 시간 선택기 (조회 기간)
 *   안내      : 대상 아이템 수와 예상 줄 수. 내보낼 수 없으면 이유(기간 초과, 줄 수 초과 등)
 *   표        : 미리보기. 파일에 들어갈 내용의 앞에서 100줄 (HOST, ITEM, CLOCK, VALUE)
 *
 * ── 쓰는 순서 ──
 *   1) 필터와 기간을 고르고 [적용] 을 누른다. 대상 아이템 수와 예상 줄 수가 표시된다.
 *   2) [CSV 내보내기] 를 누른다. 화면에 보이는 조건 그대로 파일이 내려온다.
 *
 * ── 받는 값 ($data) ──
 *   action            이 화면의 액션 이름. 'usageexport.list'
 *   export_action     내보내기 액션 이름. 'usageexport.export'
 *   title             화면 제목
 *   timeline          시간 선택기에 넘길 기간 정보. ['from' => 'now-1d', 'to' => 'now', ...]
 *   timeline_profile  기간과 필터 접힘 상태를 저장하는 프로파일 키
 *   active_tab        필터 영역에서 열려 있는 탭 (지난번 상태)
 *   host_filter       호스트 그룹/호스트 선택창에 표시할 값. ['groups' => [...], 'hosts' => [...]]
 *   filter            ['items' => 0|1|2, 'time' => 0|1]   지금 선택된 라디오 값
 *   items_options     항목 라디오의 [값 => 라벨]
 *   time_options      시간 라디오의 [값 => 라벨]
 *   config_missing    true 면 아이템 키 설정(conf/serverusage.conf.php)이 비어 있다
 *   problem           내보낼 수 없는 이유. 빈 문자열이면 내보낼 수 있다
 *   item_count        대상 아이템 수
 *   estimated_rows    예상 줄 수
 *   preview_rows      미리보기 표의 행. [['host' => .., 'item' => .., 'clock' => .., 'value' => ..], ...]
 *   preview_limit     미리보기 줄 수. 100
 *
 * ── 관련 파일 ──
 *   actions/reports/usageexport/UsageExportList.php   컨트롤러
 *   includes/UsageExportService.php                   대상 찾기, 줄 수 어림, CSV 출력
 *   includes/export/ExportButtons.php                 내보내기 버튼
 *   includes/filter/HostFilter.php                    호스트 그룹/호스트 선택창
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Export\ExportButtons;
use Modules\DMove\Includes\Export\ExportFormat;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;

/*
 * 시간 선택기에 필요한 코어 스크립트. 코어 보고서 화면(reports.auditlog.list.php)과 같다.
 *   gtlc.js            : 시간 선택기 동작(timeControl). 없으면 기간 버튼을 눌러도 아무 일도 일어나지 않는다.
 *   class.calendar.js  : 날짜 입력칸 옆 달력.
 */
$this->addJsFile('gtlc.js');
$this->addJsFile('class.calendar.js');

// ── 1) 필터 영역 ────────────────────────────────────────────────────────────

/*
 * 라디오 버튼 묶음. CRadioButtonList(이름, 현재 선택된 값) 에 addValue(라벨, 값) 으로 항목을 넣는다.
 *   setModern(true) : 버튼처럼 붙어 있는 모양. 빼면 동그란 라디오 버튼이 가로로 나열된다.
 */
$items_radio = (new CRadioButtonList('filter_items', $data['filter']['items']))->setModern(true);

foreach ($data['items_options'] as $value => $label) {
	$items_radio->addValue($label, $value);
}

$time_radio = (new CRadioButtonList('filter_time', $data['filter']['time']))->setModern(true);

foreach ($data['time_options'] as $value => $label) {
	$time_radio->addValue($label, $value);
}

// 호스트 그룹 / 호스트 선택창 두 줄은 공용 부품이 만들어 준다. (includes/filter/HostFilter.php)
$filter_form = HostFilter::addRows(new CFormList(), $data['host_filter'])
	->addRow(Lang::t('Items'), $items_radio)
	->addRow(Lang::t('Hours'), $time_radio)
	// 안내문. 라벨 자리를 비우고('') 빨간 글씨(ZBX_STYLE_RED)로 넣는다. CDiv 배열은 줄마다 한 줄씩 표시된다.
	->addRow('', [
		(new CDiv(Lang::t('* Real-time data can be exported for the previous day up to 3 months.')))
			->addClass(ZBX_STYLE_RED),
		(new CDiv(Lang::t('* Exporting a large amount of data may take more than a minute.')))
			->addClass(ZBX_STYLE_RED)
	]);

$filter = (new CFilter())
	// [리셋] 버튼이 이동할 주소. CFilter 가 filter_rst=1 을 붙여 준다.
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	// 필터 접힘/펼침 상태와 기간을 저장하는 프로파일 키. 컨트롤러의 TIMELINE_PROFILE 과 같아야 한다.
	->setProfile($data['timeline_profile'])
	->setActiveTab($data['active_tab'])
	// [적용] 을 눌렀을 때 같은 액션으로 돌아오도록 숨은 값으로 넣는다.
	->addVar('action', $data['action'])
	/*
	 * 탭 순서는 5버전 화면과 같게: [< 줌 아웃 >] [최신 5 일간] [필터]
	 * 탭은 더한 순서대로 왼쪽부터 놓이므로 시간 선택기를 먼저, 필터를 나중에 더한다.
	 *
	 * 시간 선택기 탭. 인자: 시작, 종료, 탭을 표시할지, 기간을 저장할 프로파일 키.
	 * 시작/종료는 "now-5d", "now" 같은 표현이다. 컨트롤러의 getTimeSelectorPeriod() 가 만들어 준 값이다.
	 */
	->addTimeSelector($data['timeline']['from'], $data['timeline']['to'], true, $data['timeline_profile'])
	->addFilterTab(_('Filter'), [$filter_form]);

// ── 2) 페이지 조립 ──────────────────────────────────────────────────────────

$html_page = (new CHtmlPage())->setTitle($data['title']);

// 내보낼 수 있을 때만 [CSV 내보내기] 버튼을 보여 준다. 이 화면은 CSV 만 지원한다.
$can_export = !$data['config_missing'] && $data['problem'] === '';

if ($can_export) {
	$html_page->setControls(ExportButtons::create($data['export_action'], [ExportFormat::CSV]));
}

$html_page->addItem($filter);

if ($data['config_missing']) {
	// 아이템 키 설정이 비어 있다. (conf/serverusage.conf.php 의 cpu_item_keys / memory_item_keys)
	$html_page->addItem(
		makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('Settings are required: %1$s', 'conf/serverusage.conf.php'),
			false
		)
	);
}
elseif ($data['problem'] !== '') {
	// 내보낼 수 없는 이유. (기간 초과, 대상 없음, 줄 수 초과)
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [], $data['problem'], false));
}
else {
	// 내보낼 수 있다. 대상 아이템 수와 예상 줄 수를 알려 준다. number_format: 천 단위 쉼표.
	$html_page->addItem(
		(new CDiv(Lang::t('Target items: %1$s, expected rows: about %2$s. Press [Export to CSV] to download.',
			number_format($data['item_count']), number_format($data['estimated_rows'])
		)))->addClass('dm-toolbar')
	);
}

/*
 * 미리보기 표. 파일에 들어갈 내용의 앞부분만 보여 준다. (열은 CSV 와 같다)
 * CTableInfo 는 행이 하나도 없으면 "데이터 없음" 안내 줄을 스스로 표시한다.
 * 그 기간에 수집값이 없으면(예: 이력 보관 기간이 지난 날짜) 여기서 바로 알 수 있다.
 */
if ($can_export) {
	$table = (new CTableInfo())->setHeader(['HOST', 'ITEM', 'CLOCK', 'VALUE']);

	foreach ($data['preview_rows'] as $row) {
		$table->addRow([
			(new CCol($row['host']))->addClass(ZBX_STYLE_NOWRAP),
			$row['item'],
			(new CCol($row['clock']))->addClass(ZBX_STYLE_NOWRAP),
			$row['value']
		]);
	}

	$html_page
		->addItem(
			(new CDiv(Lang::t('Preview: the first %1$d rows. Download the file to get all rows.', $data['preview_limit'])))
				->addClass('dm-toolbar')
		)
		->addItem($table);
}

$html_page->show();

/*
 * 시간 선택기를 동작시키는 스크립트. 이것이 없으면 기간을 눌러도 화면이 바뀌지 않는다.
 * 코어의 보고서 화면들이 쓰는 것과 같은 코드다. (views/reports/peakevent.list.php 의 설명 참고)
 *
 *   timeControl : 코어의 자바스크립트 객체. 시간 선택기에서 기간이 바뀌면 화면을 다시 불러온다.
 *   json_encode : PHP 배열을 자바스크립트가 읽을 수 있는 글자로 바꾼다.
 */
$timeline_object = [
	'id' => 'timeline_1',
	'domid' => 'usageexport',
	'loadSBox' => 0,
	'loadImage' => 0,
	'dynamic' => 0
];

(new CScriptTag(
	'timeControl.addObject("usageexport", '.json_encode($data['timeline']).', '.json_encode($timeline_object).');'.
	'timeControl.processObjects();'
))->show();
