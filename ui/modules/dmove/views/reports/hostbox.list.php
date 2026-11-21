<?php declare(strict_types = 0);
/*
 * Box 사용현황 화면.
 *
 * 컨트롤러: actions/reports/hostbox/HostBoxList.php
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Export\ExportButtons;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;

// ── 1) 필터 영역: 호스트 그룹 / 호스트 (공용 부품. includes/filter/HostFilter.php) ──

$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	->setProfile('web.dmove.hostbox.filter')
	->setActiveTab($data['active_tab'])
	->addVar('action', $data['action'])
	->addFilterTab(_('Filter'), [HostFilter::addRows(new CFormList(), $data['host_filter'])]);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

$view_url = (new CUrl('zabbix.php'))
	->setArgument('action', $data['action'])
	->getUrl();

// 제목 줄: 호스트(정렬) + 컨트롤러가 정한 값 열들 + 최근 데이터
$header = [make_sorting_header($data['columns']['name'], 'name', $data['sort'], $data['sortorder'], $view_url)];
$value_columns = array_slice(array_keys($data['columns']), 1);

foreach ($value_columns as $column) {
	$header[] = $data['columns'][$column];
}

$header[] = Lang::t('Latest data');

$table = (new CTableInfo())
	->setHeader($header)
	->setPageNavigation($data['paging']);

foreach ($data['rows'] as $row) {
	$cells = [(new CCol($row['name']))->addClass(ZBX_STYLE_NOWRAP)];

	foreach ($value_columns as $column) {
		$cells[] = (new CCol($row[$column]))->addClass(ZBX_STYLE_NOWRAP);
	}

	/*
	 * "상세보기" 링크: 코어의 "모니터링 > 최신 데이터" 화면을 이 호스트로 걸러서 연다. (5버전과 같다)
	 *   hostids    : 호스트 선택 조건 (배열)
	 *   filter_set : 1 = 이 조건을 필터에 적용
	 */
	$cells[] = new CLink(Lang::t('View details'),
		(new CUrl('zabbix.php'))
			->setArgument('action', 'latest.view')
			->setArgument('hostids', [$row['hostid']])
			->setArgument('filter_set', 1)
	);

	$table->addRow($cells);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->setControls(ExportButtons::create($data['export_action']))
	->addItem($filter);

if ($data['config_missing']) {
	$html_page->addItem(
		makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('Settings are required: %1$s', 'conf/hostbox.conf.php'),
			false
		)
	);
}

$html_page
	->addItem($table)
	->show();
