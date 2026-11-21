<?php declare(strict_types = 0);
/*
 * 서버 시간 확인 화면.
 *
 * 컨트롤러: actions/reports/uptime/UptimeList.php  (여기서 넘긴 값을 $data['키'] 로 읽는다)
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

$table = (new CTableInfo())
	->setHeader([
		_('Host'),
		_('IP'),
		_('Description'),
		_('OS'),
		Lang::t('Server time'),
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

$html_page = (new CHtmlPage())
	->setTitle($data['title'])
	->addItem($filter);

if (!$data['data_ready']) {
	$html_page->addItem(
		makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('Data retrieval is not implemented yet.'), false)
	);
}

$html_page
	->addItem($table)
	->show();
