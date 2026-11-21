<?php declare(strict_types = 0);
/*
 * 일일점검 화면.
 *
 * 컨트롤러: actions/reports/dailycheck/DailyCheckList.php
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Lang;

// ── 1) 날짜 줄: 오늘 날짜  [D-1] [D-2]  (시작일 ~ 종료일) ───────────────────

$base_url = (new CUrl('zabbix.php'))->setArgument('action', $data['action']);

/*
 * [D-1] [D-2] 버튼. 누르면 period=1 / period=2 를 붙여 같은 화면을 다시 연다.
 * 지금 보고 있는 구간의 버튼을 한 번 더 누르면 기본 구간(period 없음)으로 돌아간다.
 *
 * clone: 객체를 복사한다. $base_url 에 직접 setArgument 를 하면 다음 버튼의 주소까지 바뀌기 때문이다.
 */
$period_buttons = [];

foreach ([1 => 'D-1', 2 => 'D-2'] as $period => $label) {
	$url = clone $base_url;

	if ($data['period'] != $period) {
		$url->setArgument('period', $period);
	}

	$button = new CRedirectButton($label, $url);

	if ($data['period'] == $period) {
		// 선택된 버튼은 흐린 버튼(ZBX_STYLE_BTN_ALT)이 아닌 기본 파란 버튼으로 표시한다.
		$period_buttons[] = $button;
	}
	else {
		$period_buttons[] = $button->addClass(ZBX_STYLE_BTN_ALT);
	}
}

// CHorList: 항목을 가로로 나란히 배치한다.
$date_line = new CHorList([
	new CTag('strong', true, $data['today']),
	$period_buttons[0],
	$period_buttons[1],
	'('.$data['date_from'].' ~ '.$data['date_till'].')'
]);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

$table = (new CTableInfo())
	->setHeader([
		Lang::t('Check item'),
		Lang::t('Normal hosts'),
		Lang::t('Failed hosts'),
		Lang::t('Checked hosts (*all hosts)')
	]);

foreach ($data['rows'] as $row) {
	$fail = $row['fail'];

	if ($row['fail_hosts']) {
		/*
		 * 장애발생 호스트 수를 누르면 호스트 이름 목록이 말풍선으로 뜬다. (5버전의 팝업)
		 *   setHint(내용, CSS클래스, 고정여부) : 세 번째 인자를 true 로 주면 클릭했을 때 고정되어 스크롤/클릭이 된다.
		 *   ZBX_STYLE_LINK_ACTION            : 점선 밑줄 링크 모양.
		 */
		$host_list = (new CTableInfo())->setHeader([_('Host name')]);

		foreach ($row['fail_hosts'] as $host) {
			/*
			 * 호스트 이름을 누르면 코어의 "모니터링 > 장애" 화면을, 그 호스트 + 이 항목의 태그 + 점검 구간으로
			 * 걸러서 연다. 어떤 장애 때문에 숫자에 잡혔는지 바로 볼 수 있다.
			 *   show               : TRIGGERS_OPTION_ALL = 이미 해결된 장애도 포함(이력)
			 *   filter_custom_time : 1 = 아래 from / to 기간을 사용
			 *   filter_set         : 1 = 이 조건을 필터에 적용
			 */
			$problems_url = (new CUrl('zabbix.php'))
				->setArgument('action', 'problem.view')
				->setArgument('show', TRIGGERS_OPTION_ALL)
				->setArgument('hostids', [$host['hostid']])
				->setArgument('tags', [
					['tag' => $data['tag_name'], 'operator' => TAG_OPERATOR_EQUAL, 'value' => $row['tag']]
				])
				->setArgument('filter_custom_time', 1)
				->setArgument('from', $data['time_from'])
				->setArgument('to', $data['time_till'])
				->setArgument('filter_set', 1);

			$host_list->addRow(new CLink($host['name'], $problems_url));
		}

		$fail = (new CSpan($fail))
			->addClass(ZBX_STYLE_LINK_ACTION)
			->setHint($host_list, '', true);
	}

	// 설정이 비어 있어 집계하지 못한 항목(null)은 '-' 로 표시한다.  ?? : 왼쪽이 null 이면 오른쪽.
	$table->addRow([
		$row['label'],
		$row['ok'] ?? '-',
		$fail ?? '-',
		$row['total'] ?? '-'
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

$html_page = (new CHtmlPage())->setTitle($data['title']);

/*
 * TODO(고객환경): 5버전에는 제목 오른쪽에 [CPU 사용률 Top 10] [Memory 사용률 Top 10] 버튼이 있었다.
 * 버튼이 여는 화면을 확인한 뒤 아래처럼 추가한다.
 *
 *   $html_page->setControls(
 *       (new CTag('nav', true, (new CList())
 *           ->addItem(new CRedirectButton('CPU 사용률 Top 10', (new CUrl('zabbix.php'))->setArgument('action', '...')))
 *           ->addItem(new CRedirectButton('Memory 사용률 Top 10', (new CUrl('zabbix.php'))->setArgument('action', '...')))
 *       ))->setAttribute('aria-label', _('Content controls'))
 *   );
 */

// CDiv: <div> 한 덩어리. dm-toolbar 는 assets/css/dmove.css 에 정의한 여백용 클래스다.
$html_page->addItem((new CDiv($date_line))->addClass('dm-toolbar'));

if ($data['config_missing']) {
	$html_page->addItem(
		makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('Settings are required: %1$s', 'conf/dailycheck.conf.php'),
			false
		)
	);
}

$html_page
	->addItem($table)
	->show();
