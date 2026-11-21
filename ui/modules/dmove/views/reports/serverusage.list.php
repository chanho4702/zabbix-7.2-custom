<?php declare(strict_types = 0);
/*
 * 저사용 / 고사용 서버 사용현황 화면. 두 화면이 이 뷰 하나를 같이 쓴다.
 *
 * 컨트롤러: actions/reports/serverusage/ServerUsageController.php
 *           (화면별 차이는 actions/reports/lowserver/, actions/reports/highserver/ 의 컨트롤러가 정한다)
 *
 * 뷰는 어느 화면인지 구분하지 않는다. 제목, 액션 이름, 기준 안내문을 모두 $data 로 받는다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Export\ExportButtons;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\ServerUsageService;

// ── 1) 필터 영역 ────────────────────────────────────────────────────────────

// 시간대 선택. 업무시간은 설정(conf/serverusage.conf.php)의 값을 문구에 넣어 보여 준다. 예) 09시~18시
[$business_from, $business_till] = $data['business_hours'];
// sprintf('%02d', 9) = '09' : 두 자리로 맞추고 빈 자리는 0 으로 채운다.
$business_from = sprintf('%02d', $business_from);
$business_till = sprintf('%02d', $business_till);

/*
 * 라디오 버튼 묶음. makeVertical() 을 부르면 세로로 한 줄에 하나씩 놓인다. (5버전 화면과 같은 배치)
 */
$hours_radio = (new CRadioButtonList('filter_hours', $data['filter']['hours']))
	->addValue(Lang::t('Business hours usage (%1$s:00 ~ %2$s:00)', $business_from, $business_till),
		ServerUsageService::HOURS_BUSINESS
	)
	->addValue(Lang::t('Off hours usage (%1$s:00 ~ %2$s:00)', $business_till, $business_from),
		ServerUsageService::HOURS_OFF
	)
	->addValue(Lang::t('All hours'), ServerUsageService::HOURS_ALL)
	->makeVertical();

// 왼쪽 단: 호스트 그룹 / 호스트 (공용 부품. includes/filter/HostFilter.php)
$filter_left = HostFilter::addRows(new CFormList(), $data['host_filter']);

// 오른쪽 단: 기간과 시간대
$filter_right = (new CFormList())
	/*
	 * 날짜 입력 + 달력 버튼.
	 *   setDateFormat(ZBX_DATE) : 날짜만(YYYY-MM-DD) 받는다.
	 */
	->addRow(new CLabel(Lang::t('Start'), 'filter_from'),
		(new CDateSelector('filter_from', $data['filter']['from']))
			->setDateFormat(ZBX_DATE)
			->setPlaceholder(_('YYYY-MM-DD'))
	)
	->addRow(new CLabel(Lang::t('End'), 'filter_to'),
		(new CDateSelector('filter_to', $data['filter']['to']))
			->setDateFormat(ZBX_DATE)
			->setPlaceholder(_('YYYY-MM-DD'))
	)
	->addRow('', Lang::t('* The period can be up to 1 year.'))
	->addRow(Lang::t('Hours'), $hours_radio);

$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	->setProfile($data['filter_profile'])
	->setActiveTab($data['active_tab'])
	->addVar('action', $data['action'])
	// CFormList 를 2개 넣으면 필터가 좌/우 2단으로 배치된다.
	->addFilterTab(_('Filter'), [$filter_left, $filter_right]);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

$view_url = (new CUrl('zabbix.php'))
	->setArgument('action', $data['action'])
	->getUrl();

// 숫자 열은 ['label' => .., 'type' => ..] 형태라서 label 을 꺼낸다.
$labels = [];

foreach ($data['columns'] as $key => $column) {
	$labels[$key] = is_array($column) ? $column['label'] : $column;
}

$table = (new CTableInfo())
	->setHeader([
		make_sorting_header($labels['name'], 'name', $data['sort'], $data['sortorder'], $view_url),
		$labels['ip'],
		$labels['description'],
		make_sorting_header($labels['avg'], 'avg', $data['sort'], $data['sortorder'], $view_url),
		$labels['peak']
	])
	->setPageNavigation($data['paging']);

foreach ($data['rows'] as $row) {
	$table->addRow([
		(new CCol($row['name']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['ip']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['description']))->addClass(ZBX_STYLE_WORDBREAK),
		// 소수 둘째 자리까지 + %. 예) 2.29%   (number_format(값, 자릿수, 소수점, 천단위 구분))
		number_format($row['avg'], 2, '.', '').'%',
		number_format($row['peak'], 2, '.', '').'%'
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	// 제목 오른쪽: [CSV 내보내기] [Excel 내보내기]. 현재 필터/정렬 그대로 전체 행을 내려받는다.
	->setControls(ExportButtons::create($data['export_action']))
	->addItem($filter);

if ($data['config_missing']) {
	// 아이템 키가 설정되지 않아 조회할 수 없는 상태.
	$html_page->addItem(
		makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('Settings are required: %1$s', 'conf/serverusage.conf.php'),
			false
		)
	);
}

if ($data['period_error'] !== '') {
	// 기간이 잘못되어 조회하지 않은 경우. 빨간 오류 상자로 이유를 보여 준다.
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [], $data['period_error'], false));
}

$html_page
	// 표 위 오른쪽의 판정 기준 안내문. dm-criteria 는 assets/css/dmove.css 에 있다.
	->addItem((new CDiv($data['criteria']))->addClass('dm-criteria')->addClass(ZBX_STYLE_RED))
	->addItem($table)
	->show();
