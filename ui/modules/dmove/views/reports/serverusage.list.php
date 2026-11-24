<?php declare(strict_types = 0);
/*
 * 저사용 / 고사용 서버 사용현황 화면. 두 화면이 이 뷰 하나를 같이 쓴다.
 *
 * 컨트롤러: actions/reports/serverusage/ServerUsageController.php
 *           (화면별 차이는 actions/reports/lowserver/, actions/reports/highserver/ 의 컨트롤러가 정한다)
 *
 * 뷰는 어느 화면인지 구분하지 않는다. 제목, 액션 이름, 기준 안내문을 모두 $data 로 받는다.
 *
 * ── 이 파일이 하는 일 ──
 *   컨트롤러가 조회해서 넘긴 값($data)으로 필터와 표를 조립해서 화면에 출력한다.
 *   여기서는 DB 나 API 를 읽지 않는다.
 *
 *   화면 : zabbix.php?action=lowserver.list    (저사용 서버)
 *          zabbix.php?action=highserver.list   (고사용 서버)
 *
 * ── 화면 구성 ──
 *   제목 오른쪽 : [CSV 내보내기] [기준설정]
 *   필터 왼쪽   : 호스트 그룹, 호스트 (선택창)
 *   필터 오른쪽 : 시작일, 종료일, 시간대(업무시간 / 업무 외 시간 / 전체 시간)
 *   경고 상자   : 아이템 키 설정(conf/serverusage.conf.php)이 비어 있을 때만 나온다
 *   오류 상자   : 기간이 잘못되었을 때만 나온다
 *   기준 안내문 : 표 위 오른쪽의 빨간 글씨. 어떤 기준으로 서버를 골랐는지 알려 준다
 *   표          : 호스트(정렬 가능), IP, Description, 평균 사용률(정렬 가능), Peak
 *
 * ── 받는 값 ($data) ──
 *   action          이 화면의 액션 이름. 'lowserver.list' 또는 'highserver.list'
 *   export_action   내보내기 액션 이름. 'lowserver.export' 또는 'highserver.export'
 *   title           화면 제목
 *   columns         열 정의. ['name' => 제목, 'ip' => 제목, 'description' => 제목,
 *                             'avg' => ['label' => 제목, 'type' => 'number'], 'peak' => [...]]
 *   filter_profile  필터 접힘/펼침 상태를 저장할 프로파일 키 (화면마다 다르다)
 *   active_tab      필터가 펼쳐져 있는지 (지난번 상태)
 *   host_filter     호스트 그룹/호스트 선택창에 표시할 값. HostFilter::toViewData() 의 결과
 *   filter          ['from' => '2026-10-01', 'to' => '2026-10-31', 'hours' => 시간대 값]
 *   business_hours  업무시간. [시작 시, 끝 시]. 예) [9, 18]
 *   criteria        기준 안내문 글자
 *   period_error    기간 오류 문구. 오류가 없으면 ''
 *   query_error     true 면 DB 집계가 실패했다 (오류 상자를 띄운다)
 *   trends_warning  요약 값 보관 기간 안내 문구. 문제없으면 ''
 *   config_missing  true 면 아이템 키 설정이 비어 있다 (경고 상자를 띄운다)
 *   sort            현재 정렬 열. 'name' 또는 'avg'
 *   sortorder       현재 정렬 방향. 'ASC' 또는 'DESC'
 *   paging          페이지 번호 줄
 *   rows            [['hostid' => .., 'name' => .., 'ip' => .., 'description' => .., 'description_hint' => ..,
 *                     'avg' => 12.34, 'peak' => 56.78], ...]   description_hint 는 ITSM 에 없을 때의 안내. 있으면 ''
 *
 * ── 관련 파일 ──
 *   actions/reports/serverusage/ServerUsageController.php   두 화면의 공통 컨트롤러
 *   actions/reports/lowserver/LowServerList.php             저사용 화면의 차이(제목, 기준)
 *   actions/reports/highserver/HighServerList.php           고사용 화면의 차이(제목, 기준)
 *   includes/ServerUsageService.php                         평균/Peak 집계, 시간대 상수(HOURS_...)
 *   includes/filter/HostFilter.php                          호스트 그룹/호스트 선택 필터 (여러 화면 공용)
 *   conf/serverusage.conf.php                               아이템 키, 업무시간, 기준 값 설정
 *   assets/css/dmove.css                                    dm-criteria 클래스
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\ServerUsageService;

/*
 * 이 화면 전용 스크립트를 불러온다. ([기준설정] 창을 열고 저장하는 코드가 들어 있다)
 * 스크립트 파일은 views/js/ 아래에 두고, 그 폴더를 기준으로 한 경로를 적는다.
 */
$this->includeJsFile('reports/serverusage.list.js.php');

// ── 1) 필터 영역 ────────────────────────────────────────────────────────────

// 시간대 선택. 업무시간은 설정(conf/serverusage.conf.php)의 값을 문구에 넣어 보여 준다. 예) 09시~18시
// [$a, $b] = 배열 : 배열의 첫째, 둘째 값을 변수 두 개에 한 번에 담는다. 예) [9, 18] -> 9, 18
[$business_from, $business_till] = $data['business_hours'];
// sprintf('%02d', 9) = '09' : 두 자리로 맞추고 빈 자리는 0 으로 채운다.
$business_from = sprintf('%02d', $business_from);
$business_till = sprintf('%02d', $business_till);

/*
 * 라디오 버튼 묶음. makeVertical() 을 부르면 세로로 한 줄에 하나씩 놓인다. (5버전 화면과 같은 배치)
 *   CRadioButtonList(입력 이름, 현재 선택된 값) : 여러 개 중 하나만 고르는 버튼 묶음.
 *   addValue(라벨, 값)                          : 항목 하나를 넣는다. 값은 ServerUsageService 의 HOURS_... 상수다.
 *   Lang::t('... %1$s ... %2$s', A, B)          : 번역한 문구의 %1$s 자리에 A, %2$s 자리에 B 를 넣는다.
 *   업무 외 시간은 "끝 시 ~ 시작 시" 라서 인자 순서가 반대다. 예) 18:00 ~ 09:00
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
	 *   CDateSelector(입력 이름, 현재 값) : 날짜 입력창 + 달력 버튼.
	 *   CLabel(글자, 입력 이름)           : 라벨. 라벨을 누르면 그 입력창으로 커서가 간다.
	 *   setPlaceholder                    : 비어 있을 때 흐리게 보이는 안내 글자.
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

// CFilter: 화면 위쪽의 접었다 펼 수 있는 필터 상자. [적용] / [초기화] 버튼은 CFilter 가 만들어 준다.
$filter = (new CFilter())
	// [초기화] 버튼이 이동할 주소. CFilter 가 filter_rst=1 을 붙여 준다.
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	// 필터 접힘/펼침 상태를 저장할 프로파일 키. 두 화면이 뷰를 같이 쓰므로 컨트롤러가 화면별 키를 넘겨 준다.
	->setProfile($data['filter_profile'])
	->setActiveTab($data['active_tab'])
	// [적용] 을 눌렀을 때 같은 액션으로 돌아오도록 숨은 값으로 넣는다.
	->addVar('action', $data['action'])
	// CFormList 를 2개 넣으면 필터가 좌/우 2단으로 배치된다.
	->addFilterTab(_('Filter'), [$filter_left, $filter_right]);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

// 정렬 헤더가 만들 링크의 기준 주소. 예) zabbix.php?action=lowserver.list
$view_url = (new CUrl('zabbix.php'))
	->setArgument('action', $data['action'])
	->getUrl();

// 숫자 열은 ['label' => .., 'type' => ..] 형태라서 label 을 꺼낸다.
$labels = [];

foreach ($data['columns'] as $key => $column) {
	$labels[$key] = is_array($column) ? $column['label'] : $column;
}

// make_sorting_header(제목, 정렬 키, 현재 정렬 키, 현재 방향, 이 화면 주소): 코어 함수. 누르면 정렬되는 제목 칸을 만든다.
// 호스트와 평균 열만 정렬할 수 있다. (컨트롤러의 SORT_FIELDS 와 같아야 한다)
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
		// ITSM 에 없으면 빨간 '-' 에 마우스를 올리면 이유가 보인다. (5버전과 같다)
		(new CCol($row['description_hint'] === ''
			? $row['description']
			: (new CSpan($row['description']))
				->addClass(ZBX_STYLE_RED)
				->addClass(ZBX_STYLE_CURSOR_POINTER)
				->setHint($row['description_hint'])
		))->addClass(ZBX_STYLE_WORDBREAK),
		// 소수 둘째 자리까지 + %. 예) 2.29%   (number_format(값, 자릿수, 소수점, 천단위 구분))
		number_format($row['avg'], 2, '.', '').'%',
		number_format($row['peak'], 2, '.', '').'%'
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	/*
	 * 제목 오른쪽: [CSV 내보내기] [기준설정]. (5버전 화면과 같은 구성)
	 *   CSV 내보내기 : 현재 필터/정렬 그대로 전체 행을 내려받는다.
	 *   기준설정     : 누르면 스크립트(views/js/reports/serverusage.list.js.php)가 "수집기준 설정" 창을 연다.
	 *                  js-serverusage-threshold 는 스크립트가 이 버튼을 찾는 표시다.
	 *
	 * 다른 화면은 ExportButtons::create() 로 내보내기 버튼만 만든다. 이 화면은 버튼이 하나 더 있어서
	 * 같은 모양(<nav><ul>버튼들</ul></nav>)을 직접 만든다.
	 *   CRedirectButton : 누르면 주어진 주소로 이동하는 버튼. 응답이 파일이라 화면은 그대로 있고 다운로드만 된다.
	 *   CSimpleButton   : 누르면 스크립트가 처리하는 일반 버튼.
	 */
	->setControls(
		(new CTag('nav', true, (new CList())
			->addItem(
				(new CRedirectButton(_('Export to CSV'),
					(new CUrl('zabbix.php'))
						->setArgument('action', $data['export_action'])
						->setArgument('format', 'csv')
				))->setId('export_csv')
			)
			->addItem((new CSimpleButton(Lang::t('Criteria settings')))->addClass('js-serverusage-threshold'))
		))->setAttribute('aria-label', _('Content controls'))
	)
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

if ($data['query_error']) {
	// DB 집계가 실패했다. 빈 표가 "해당 서버 없음"으로 보이지 않게 빨간 오류 상자를 띄운다.
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_BAD, [],
		Lang::t('A database error occurred while calculating usage. Try again later.'), false
	));
}

if ($data['trends_warning'] !== '') {
	// 조회 시작일이 요약 값 보관 기간보다 이르다. 숫자는 그대로 보여 주고 노란 상자로 알려만 준다.
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [], $data['trends_warning'], false));
}

$html_page
	// 표 위 오른쪽의 판정 기준 안내문. dm-criteria 는 assets/css/dmove.css 에 있다.
	->addItem((new CDiv($data['criteria']))->addClass('dm-criteria')->addClass(ZBX_STYLE_RED))
	->addItem($table)
	->show();

/*
 * 스크립트에 값을 넘기고 시작시킨다.
 *   json_encode : PHP 값을 자바스크립트가 읽을 수 있는 글자로 바꾼다.
 *   setOnDocumentReady : 화면이 다 그려진 뒤에 실행한다.
 */
(new CScriptTag('dmoveServerUsageList.init('.json_encode([
	'threshold_action' => 'serverusage.threshold',
	'save_action' => 'serverusage.threshold.save'
]).');'))
	->setOnDocumentReady()
	->show();
