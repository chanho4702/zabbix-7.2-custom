<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 현황 화면의 뷰. (화면을 그리는 파일)
 *
 * 컨트롤러: actions/reports/winpatch/WinPatchList.php
 *
 * ── 이 파일이 하는 일 ──
 *   컨트롤러가 조회해서 넘긴 값($data)으로 필터와 표를 조립해서 화면에 출력한다.
 *   여기서는 DB 나 API 를 읽지 않는다.
 *
 *   화면 : zabbix.php?action=winpatch.list
 *
 * ── 화면 구성 ──
 *   제목 오른쪽 : [CSV 내보내기]
 *   필터        : 호스트 그룹, 호스트 (선택창), HotFix ID(입력), 수집월(날짜 입력 + 달력)
 *   경고 상자   : 아이템 키 설정(conf/winpatch.conf.php)이 비어 있을 때만 나온다
 *   표          : 호스트(정렬 가능), IP, Description, 패치 파일명("상세보기" 링크)
 *                 "상세보기" 를 누르면 그 호스트의 패치 상세정보 팝업이 뜬다. 기간 안에 값이 없으면 빨간 "없음"
 *
 * ── 받는 값 ($data) ──
 *   action          이 화면의 액션 이름. 'winpatch.list'
 *   export_action   내보내기 액션 이름. 'winpatch.export'
 *   detail_action   상세보기 팝업의 액션 이름. 'winpatch.detail'
 *   title           화면 제목
 *   columns         열 제목. ['name' => .., 'ip' => .., 'description' => ..]
 *   active_tab      필터가 펼쳐져 있는지 (지난번 상태)
 *   host_filter     호스트 그룹/호스트 선택창에 표시할 값. HostFilter::toViewData() 의 결과
 *   filter          ['hotfixid' => '', 'month' => '2026-10-01']
 *   sort            현재 정렬 열
 *   sortorder       현재 정렬 방향. 'ASC' 또는 'DESC'
 *   config_missing  true 면 아이템 키 설정이 비어 있다 (경고 상자를 띄운다)
 *   paging          페이지 번호 줄
 *   rows            [['hostid' => .., 'name' => .., 'ip' => .., 'description' => .., 'description_error' => ..,
 *                     'patch' => 'ok' / 'no_item' / 'no_value'], ...]   (WinPatchService::getRows() 참고)
 *
 * ── 관련 파일 ──
 *   actions/reports/winpatch/WinPatchList.php     컨트롤러
 *   views/js/reports/winpatch.list.js.php         "상세보기" 를 누르면 팝업을 여는 스크립트
 *   actions/reports/winpatch/WinPatchDetail.php   상세보기 팝업 컨트롤러
 *   views/reports/winpatch.detail.php             상세보기 팝업 내용
 *   includes/filter/HostFilter.php                호스트 그룹/호스트 선택 필터 (여러 화면 공용)
 *   conf/winpatch.conf.php                        아이템 키, 열 이름 설정
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

// CFilter: 화면 위쪽의 접었다 펼 수 있는 필터 상자. [적용] / [초기화] 버튼은 CFilter 가 만들어 준다.
$filter = (new CFilter())
	// [초기화] 버튼이 이동할 주소. CFilter 가 filter_rst=1 을 붙여 준다.
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	// 필터 접힘/펼침 상태를 저장할 프로파일 키. 컨트롤러의 PROFILE_PREFIX.'filter' 와 같아야 한다.
	->setProfile('web.dmove.winpatch.filter')
	->setActiveTab($data['active_tab'])
	// [적용] 을 눌렀을 때 같은 액션으로 돌아오도록 숨은 값으로 넣는다.
	->addVar('action', $data['action'])
	->addFilterTab(_('Filter'), [$filter_form]);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

// 정렬 헤더가 만들 링크의 기준 주소. 예) zabbix.php?action=winpatch.list
$view_url = (new CUrl('zabbix.php'))
	->setArgument('action', $data['action'])
	->getUrl();

// make_sorting_header(제목, 정렬 키, 현재 정렬 키, 현재 방향, 이 화면 주소): 코어 함수. 누르면 정렬되는 제목 칸을 만든다.
// 앞의 세 열 제목은 컨트롤러 getColumns() 의 값을 쓴다. 내보내기 파일도 같은 제목을 쓴다.
$table = (new CTableInfo())
	->setHeader([
		make_sorting_header($data['columns']['name'], 'name', $data['sort'], $data['sortorder'], $view_url),
		$data['columns']['ip'],
		$data['columns']['description'],
		Lang::t('Patch file name')
	])
	->setPageNavigation($data['paging']);

/*
 * 값이 없을 때 쓰는 빨간 글자. 마우스를 올리면 안내 문구(hint)가 뜬다. (5버전 윈도우 트레잇, ITSM 트레잇과 같은 모양)
 */
$red_hint = static fn(string $text, string $hint): CSpan => (new CSpan($text))
	->addClass(ZBX_STYLE_RED)
	->addClass(ZBX_STYLE_CURSOR_POINTER)
	->setHint($hint);

foreach ($data['rows'] as $row) {
	// Description: ITSM 값이 없으면 빨간 "-". 테이블이 없을 때와 그 호스트의 데이터가 없을 때 문구가 다르다.
	if ($row['description_error'] === 'table') {
		$description = $red_hint('-', Lang::t('No ITSM table for host (%1$s).', $row['hostid']));
	}
	elseif ($row['description_error'] === 'data') {
		$description = $red_hint('-', Lang::t('No ITSM data for host (%1$s).', $row['hostid']));
	}
	else {
		$description = $row['description'];
	}

	/*
	 * "상세보기" 링크. 기간 안에 값이 있을 때만 나온다. 누르면 패치 상세정보 팝업이 뜬다.
	 *   CLinkAction        : 주소로 이동하지 않고 스크립트로 동작하는 링크(점선 밑줄).
	 *   js-winpatch-detail : 스크립트(views/js/reports/winpatch.list.js.php)가 이 링크를 찾는 표시.
	 *   data-hostid        : 어느 호스트인지 스크립트에 알려 주는 값. HTML 의 data-* 속성이다.
	 * 값이 없으면 빨간 "없음". 아이템이 없을 때와 기간 안에 값이 없을 때 문구가 다르다. (5버전은 둘 다 "item이 없습니다")
	 */
	if ($row['patch'] === 'ok') {
		$patch = (new CLinkAction(Lang::t('View details')))
			->addClass('js-winpatch-detail')
			->setAttribute('data-hostid', $row['hostid']);
	}
	elseif ($row['patch'] === 'no_item') {
		$patch = $red_hint(Lang::t('No patch data'), Lang::t('Host (%1$s) has no item.', $row['hostid']));
	}
	else {
		$patch = $red_hint(Lang::t('No patch data'),
			Lang::t('Host (%1$s) has no collected value in the selected period.', $row['hostid'])
		);
	}

	$table->addRow([
		(new CCol($row['name']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['ip']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($description))->addClass(ZBX_STYLE_WORDBREAK),
		$patch
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->setControls(ExportButtons::create($data['export_action']))
	->addItem($filter);

// 설정이 비어 있으면 노란 경고 상자로 어느 파일을 채워야 하는지 알려 준다.
// makeMessageBox(종류, 상세 목록, 제목 글자, 닫기 버튼 여부): 코어 함수.  %1$s 자리에 파일 이름이 들어간다.
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
