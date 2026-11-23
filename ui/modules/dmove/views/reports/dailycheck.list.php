<?php declare(strict_types = 0);
/*
 * 일일점검 화면의 뷰. (화면을 그리는 파일)
 *
 * 컨트롤러: actions/reports/dailycheck/DailyCheckList.php
 *
 * ── 이 파일이 하는 일 ──
 *   컨트롤러가 집계해서 넘긴 값($data)으로 날짜 줄과 점검 결과 표를 그린다.
 *   여기서는 DB 나 API 를 읽지 않는다.
 *
 *   화면 : zabbix.php?action=dailycheck.list   (period=1 또는 2 를 붙이면 D-1 / D-2 구간)
 *
 * ── 화면 구성 ──
 *   제목 오른쪽 : [CPU 사용률 Top 10] [Memory 사용률 Top 10]   누르면 팝업이 뜬다
 *   날짜 줄     : 오늘 날짜  [D-1] [D-2]  (시작일 ~ 종료일)
 *   경고 상자   : 설정(conf/dailycheck.conf.php)이 비어 있을 때만 나온다
 *   표          : 점검항목, 정상호스트, 장애발생 호스트, 점검대상(*전체호스트)
 *                 장애발생 호스트 수를 누르면 호스트 이름 목록이 말풍선으로 뜬다
 *
 * ── 받는 값 ($data) ──
 *   action          이 화면의 액션 이름. 'dailycheck.list'
 *   title           화면 제목
 *   period          지금 보고 있는 구간. 0 = 기본, 1 = D-1, 2 = D-2
 *   today           오늘 날짜. 예) '2026-11-23'
 *   date_from       점검 구간의 시작일. 예) '2026-11-22'
 *   date_till       점검 구간의 종료일
 *   time_from       점검 구간의 시작 시각(날짜 + 시:분:초). 장애 화면으로 가는 링크의 from 값
 *   time_till       점검 구간의 종료 시각. 링크의 to 값
 *   tag_name        점검 항목을 구분하는 트리거 태그의 이름. 링크의 태그 조건에 쓴다
 *   config_missing  true 면 설정이 비어 있다 (경고 상자를 띄운다)
 *   rows            표의 행. 행 1개 = 점검 항목 1개
 *     [
 *       'label'      => '항목 이름',
 *       'tag'        => '이 항목의 태그 값',
 *       'ok'         => 12,      // 정상 호스트 수. 집계하지 못했으면 null
 *       'fail'       => 3,       // 장애발생 호스트 수. 집계하지 못했으면 null
 *       'total'      => 15,      // 점검 호스트 수. 집계하지 못했으면 null
 *       'fail_hosts' => [['hostid' => '10084', 'name' => 'web-server-01'], ...]
 *     ]
 *
 * ── 관련 파일 ──
 *   actions/reports/dailycheck/DailyCheckList.php   컨트롤러
 *   views/js/reports/dailycheck.list.js.php         Top 10 버튼을 누르면 팝업을 여는 스크립트
 *   actions/reports/dailycheck/DailyCheckTop.php    Top 10 팝업 컨트롤러
 *   views/reports/dailycheck.top.php                Top 10 팝업 내용
 *   conf/dailycheck.conf.php                        점검 항목 설정
 *   assets/css/dmove.css                            dm-toolbar 클래스
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Lang;

/*
 * 이 화면 전용 스크립트를 불러온다. 스크립트 파일은 views/js/ 아래에 두고, 그 폴더를 기준으로 한 경로를 적는다.
 * (Top 10 버튼을 누르면 팝업을 여는 코드가 들어 있다)
 */
$this->includeJsFile('reports/dailycheck.list.js.php');

// ── 1) 날짜 줄: 오늘 날짜  [D-1] [D-2]  (시작일 ~ 종료일) ───────────────────

// CUrl: 주소를 조립하는 코어 클래스. setArgument(이름, 값) 으로 ?이름=값 을 붙인다. 결과: zabbix.php?action=dailycheck.list
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

	// CRedirectButton(글자, 주소): 누르면 그 주소로 이동하는 버튼.
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
// CTag('strong', true, 글자): <strong>글자</strong>. 오늘 날짜를 굵게 표시한다. 두 번째 인자 true 는 닫는 태그가 있다는 뜻.
$date_line = new CHorList([
	new CTag('strong', true, $data['today']),
	$period_buttons[0],
	$period_buttons[1],
	'('.$data['date_from'].' ~ '.$data['date_till'].')'
]);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

// CTableInfo: 코어의 목록 표. 이 화면은 항목 수가 정해져 있어서 정렬과 페이지 나누기가 없다.
// Lang::t('영어 문구'): 사용자 언어가 한국어면 lang/ko.php 의 한국어로 바뀐다. (includes/Lang.php)
$table = (new CTableInfo())
	->setHeader([
		Lang::t('Check item'),
		Lang::t('Normal hosts'),
		Lang::t('Failed hosts'),
		Lang::t('Checked hosts (*all hosts)')
	]);

foreach ($data['rows'] as $row) {
	// 기본은 숫자 그대로. 장애발생 호스트가 있으면 아래에서 "누를 수 있는 숫자"로 바꾼다.
	$fail = $row['fail'];

	// 빈 배열은 false 로 취급된다. 장애발생 호스트가 한 대라도 있을 때만 들어간다.
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
 * 제목 오른쪽의 [CPU 사용률 Top 10] [Memory 사용률 Top 10] 버튼. (5버전과 같은 위치)
 * 누르면 스크립트(views/js/reports/dailycheck.list.js.php)가 Top 10 팝업(dailycheck.top)을 연다.
 *
 *   CSimpleButton        : 누르면 스크립트가 처리하는 일반 버튼.
 *   js-dailycheck-top    : 스크립트가 이 버튼을 찾는 표시.
 *   data-metric          : 어느 팝업을 열지. 'cpu' 또는 'memory'.
 *   ZBX_STYLE_BTN_ALT    : 테두리만 있는 보조 버튼 모양.
 */
$html_page->setControls(
	(new CTag('nav', true, (new CList())
		->addItem(
			(new CSimpleButton(Lang::t('CPU usage Top 10')))
				->addClass('js-dailycheck-top')
				->addClass(ZBX_STYLE_BTN_ALT)
				->setAttribute('data-metric', 'cpu')
		)
		->addItem(
			(new CSimpleButton(Lang::t('Memory usage Top 10')))
				->addClass('js-dailycheck-top')
				->addClass(ZBX_STYLE_BTN_ALT)
				->setAttribute('data-metric', 'memory')
		)
	))->setAttribute('aria-label', _('Content controls'))
);

// CDiv: <div> 한 덩어리. dm-toolbar 는 assets/css/dmove.css 에 정의한 여백용 클래스다.
$html_page->addItem((new CDiv($date_line))->addClass('dm-toolbar'));

// 설정이 비어 있으면 노란 경고 상자로 어느 파일을 채워야 하는지 알려 준다.
// makeMessageBox(종류, 상세 목록, 제목 글자, 닫기 버튼 여부): 코어 함수.  %1$s 자리에 파일 이름이 들어간다.
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

// 화면이 다 그려진 뒤 스크립트를 시작한다. json_encode 로 PHP 값을 스크립트에 안전하게 넘긴다.
(new CScriptTag('dmoveDailyCheckList.init('.json_encode([
	'top_action' => 'dailycheck.top'
]).');'))
	->setOnDocumentReady()
	->show();
