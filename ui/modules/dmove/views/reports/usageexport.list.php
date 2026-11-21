<?php declare(strict_types = 0);
/*
 * CPU/Memory 전체 사용률 추출 화면.
 *
 * 컨트롤러: actions/reports/usageexport/UsageExportList.php
 *
 * 표가 없는 화면이다. 필터를 정하고 파일로 내려받는 용도다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;

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
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	->setProfile('web.dmove.usageexport.filter')
	->setActiveTab($data['active_tab'])
	->addVar('action', $data['action'])
	->addFilterTab(_('Filter'), [$filter_form]);

// ── 2) 페이지 조립 ──────────────────────────────────────────────────────────

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	// TODO(고객환경): 추출 기능을 구현한 뒤 내보내기 버튼을 붙인다. (template/README.md 의 "내보내기" 절)
	//   ->setControls(ExportButtons::create('usageexport.export', [ExportFormat::CSV]))
	->addItem($filter);

if (!$data['data_ready']) {
	$html_page->addItem(
		makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('Data retrieval is not implemented yet.'), false)
	);
}

$html_page->show();
