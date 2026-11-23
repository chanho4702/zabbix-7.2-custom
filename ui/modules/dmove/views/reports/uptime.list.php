<?php declare(strict_types = 0);
/*
 * 서버 시간 확인 화면의 뷰. (화면을 그리는 파일)
 *
 * 컨트롤러: actions/reports/uptime/UptimeList.php  (여기서 넘긴 값을 $data['키'] 로 읽는다)
 *
 * ── 이 파일이 하는 일 ──
 *   컨트롤러가 조회해서 넘긴 값($data)으로 필터와 표를 조립해서 화면에 출력한다.
 *   여기서는 DB 나 API 를 읽지 않는다.
 *
 *   화면 : zabbix.php?action=uptime.list
 *
 * ── 화면 구성 ──
 *   필터 : 호스트 그룹(선택창), 호스트(선택창), OS/Version(입력), 서버 Uptime(입력, "day 이상")
 *   표   : 호스트(정렬 가능), IP, Description, OS, 서버 Time(정렬 가능), 서버 start
 *
 * ── 받는 값 ($data) ──
 *   action       이 화면의 액션 이름. 'uptime.list'
 *   title        화면 제목
 *   active_tab   필터가 펼쳐져 있는지 (지난번 상태)
 *   host_filter  호스트 그룹/호스트 선택창에 표시할 값. HostFilter::toViewData() 의 결과
 *   filter       ['os' => 'linux', 'uptime_days' => '30']   (빈 문자열이면 조건 없음)
 *   sort         현재 정렬 열. 'host' 또는 'uptime'
 *   sortorder    현재 정렬 방향. 'ASC' 또는 'DESC'
 *   paging       페이지 번호 줄
 *   rows         행 1개 = 호스트 1대.
 *                ['host' => .., 'ip' => .., 'description' => .., 'os' => ..,
 *                 'server_time' => '158 일, 13:38:45', 'server_start' => '2026/06/14 05:13:26']
 *
 * ── 관련 파일 ──
 *   actions/reports/uptime/UptimeList.php   컨트롤러
 *   includes/filter/HostFilter.php          호스트 그룹/호스트 선택 필터 (여러 화면 공용)
 *   lang/ko.php                             모듈 문구의 한국어 번역
 *
 * 문구 규칙
 *   - 코어에 이미 있는 문구는 _('Host groups') 처럼 코어 번역 함수를 쓴다. (한국어 UI 에서 자동 번역)
 *   - 모듈에서 새로 만든 문구는 Lang::t('Server uptime') 를 쓰고 lang/ko.php 에 번역을 넣는다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;

// ── 1) 필터 영역 ────────────────────────────────────────────────────────────
// 입력 이름(filter_xxx)은 컨트롤러 checkInput() 의 키와 같아야 한다.

// 호스트 그룹 / 호스트 선택창 두 줄은 공용 부품이 만들어 준다. (includes/filter/HostFilter.php)
// CFormList: "라벨 + 입력창" 줄을 차례로 쌓는 폼. addRow(라벨, 입력창) 으로 한 줄을 넣는다.
// CTextBox(입력 이름, 현재 값): 한 줄 글자 입력창.
$filter_form = HostFilter::addRows(new CFormList(), $data['host_filter'])
	->addRow(Lang::t('OS/Version'),
		(new CTextBox('filter_os', $data['filter']['os']))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow(Lang::t('Server uptime'),
		(new CTextBox('filter_uptime_days', $data['filter']['uptime_days']))
			->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
			// placeholder: 비어 있을 때 흐리게 보이는 안내 글자.
			->setAttribute('placeholder', Lang::t('days or more'))
	);

$filter = (new CFilter())
	// [리셋] 버튼이 이동할 주소. CFilter 가 filter_rst=1 을 붙여 준다.
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	// 필터 접힘/펼침 상태를 저장할 프로파일 키. 컨트롤러의 PROFILE_PREFIX.'filter' 와 같아야 한다.
	->setProfile('web.dmove.uptime.filter')
	->setActiveTab($data['active_tab'])
	// [적용] 을 눌렀을 때 같은 액션으로 돌아오도록 숨은 값으로 넣는다.
	->addVar('action', $data['action'])
	->addFilterTab(_('Filter'), [$filter_form]);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

// 제목을 누르면 그 열로 정렬된다. make_sorting_header(제목, 정렬 키, 현재 정렬 키, 현재 방향, 이 화면 주소): 코어 함수.
$sort_url = (new CUrl('zabbix.php'))->setArgument('action', $data['action'])->getUrl();

$table = (new CTableInfo())
	->setHeader([
		make_sorting_header(_('Host'), 'host', $data['sort'], $data['sortorder'], $sort_url),
		_('IP'),
		_('Description'),
		_('OS'),
		// 서버 Time 은 가동 시간으로 정렬한다. (정렬 키 uptime)
		make_sorting_header(Lang::t('Server time'), 'uptime', $data['sort'], $data['sortorder'], $sort_url),
		Lang::t('Server start')
	])
	->setPageNavigation($data['paging']);

foreach ($data['rows'] as $row) {
	// 값은 문자열 그대로 넘긴다. 화면 클래스가 출력할 때 HTML 이스케이프를 해 준다.
	$table->addRow([
		(new CCol($row['host']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['ip']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['description']))->addClass(ZBX_STYLE_WORDBREAK),
		$row['os'],
		(new CCol($row['server_time']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['server_start']))->addClass(ZBX_STYLE_NOWRAP)
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

// CHtmlPage: 화면 한 장. 제목과 본문(필터, 표)을 차례로 넣고 마지막에 show() 로 출력한다.
(new CHtmlPage())
	->setTitle($data['title'])
	->addItem($filter)
	->addItem($table)
	->show();
