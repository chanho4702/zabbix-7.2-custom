<?php declare(strict_types = 0);
/*
 * 일일점검 화면의 "CPU 사용률 Top 10" / "Memory 사용률 Top 10" 팝업의 내용.
 *
 * 컨트롤러: actions/reports/dailycheck/DailyCheckTop.php
 * 5버전   : popup.topcpu.php / popup.topmemory.php
 *
 * 팝업 뷰는 화면을 그리지 않고(->show() 없음) 아래 형태의 JSON 을 출력한다.
 *   header  : 팝업 제목
 *   body    : 팝업 안에 들어갈 HTML 문자열
 *   buttons : 팝업 아래쪽 버튼 목록
 *
 * ── 이 파일이 하는 일 ──
 *   컨트롤러가 넘긴 Top 10 행으로 표를 만들고, 그 표를 HTML 문자열로 바꿔서 JSON 에 담아 출력한다.
 *   브라우저 쪽에서는 코어 함수 PopUp() 이 이 JSON 을 받아 팝업 창을 그린다.
 *   (manifest.json 에서 이 액션의 layout 이 "layout.json" 이라서 출력이 JSON 으로 나간다)
 *
 *   호출 : zabbix.php?action=dailycheck.top&metric=cpu   (또는 metric=memory)
 *
 * ── 팝업 구성 ──
 *   제목 : CPU 사용율 Top10 (어제 날짜)
 *   경고 : 아이템 키 설정(conf/serverusage.conf.php)이 비어 있을 때만 표 위에 나온다
 *   표   : No, 호스트, IP, Description, CPU 사용율(%)   (메모리 팝업은 마지막 열이 Memory 사용율(%))
 *   버튼 : [닫기]
 *
 * ── 받는 값 ($data) ──
 *   metric          'cpu' 또는 'memory'. 제목과 마지막 열 제목을 고른다
 *   day             집계한 날짜(어제). 예) '2026-11-22'
 *   config_missing  true 면 아이템 키 설정이 비어 있다
 *   rows            [['num' => 1, 'name' => 'web-01', 'ip' => '10.0.0.1', 'description' => '...', 'avg' => 87.65], ...]
 *   user            ['debug_mode' => 0 또는 1]  디버그 정보를 붙일지 정한다
 *
 * ── 관련 파일 ──
 *   actions/reports/dailycheck/DailyCheckTop.php   컨트롤러
 *   views/js/reports/dailycheck.list.js.php        이 팝업을 여는 스크립트
 *   views/reports/dailycheck.list.php              팝업을 여는 버튼이 있는 화면
 *
 * @var CView $this
 * @var array $data   ['metric' => 'cpu'|'memory', 'day' => '2026-11-22', 'config_missing' => bool, 'rows' => [...]]
 */

use Modules\DMove\Includes\Lang;

$is_cpu = $data['metric'] === 'cpu';

// 표의 마지막 열 제목과 팝업 제목. (5버전 문구: "CPU 사용율(%)", "CPU 사용율 Top10 (날짜)")
$value_label = $is_cpu ? Lang::t('CPU usage (%)') : Lang::t('Memory usage (%)');
$title = $is_cpu ? Lang::t('CPU usage Top 10') : Lang::t('Memory usage Top 10');

// _('Host'): 코어 번역 함수. 코어에 이미 번역이 있는 문구에 쓴다. 모듈이 새로 만든 문구는 Lang::t() 를 쓴다.
$table = (new CTableInfo())->setHeader(['No', _('Host'), _('IP'), _('Description'), $value_label]);

foreach ($data['rows'] as $row) {
	$table->addRow([
		$row['num'],
		(new CCol($row['name']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['ip']))->addClass(ZBX_STYLE_NOWRAP),
		// 설명이 길어도 표가 팝업 밖으로 나가지 않게 줄바꿈을 허용한다.
		(new CCol($row['description']))->addClass(ZBX_STYLE_WORDBREAK),
		// number_format(값, 2, '.', ''): 항상 소수 둘째 자리까지 표시한다. 예) 87.5 -> 87.50
		number_format($row['avg'], 2, '.', '')
	]);
}

// 팝업 안에 들어갈 HTML 을 문자열로 모은다.  .= : 뒤에 이어 붙인다.
$body = '';

// 아이템 키 설정이 비어 있으면 표 대신 이유를 알려 준다.
if ($data['config_missing']) {
	$body .= makeMessageBox(ZBX_STYLE_MSG_WARNING, [],
		Lang::t('Settings are required: %1$s', 'conf/serverusage.conf.php'), false
	)->toString();
}

// toString(): 화면 클래스를 HTML 문자열로 바꾼다. 값의 HTML 이스케이프도 이때 된다.
$body .= $table->toString();

$output = [
	// 제목 뒤에 집계한 날짜(어제)를 붙인다. 예) CPU 사용율 Top10 (2026-11-22)
	'header' => $title.' ('.$data['day'].')',
	'body' => $body,
	/*
	 * 팝업 창의 모양(class). 코어 PopUp() 은 응답을 받은 뒤 이 값으로 창 class 를 다시 정한다.
	 * 빠뜨리면 창 class 가 "modal-popup undefined" 가 된다. (코어 팝업 뷰도 같이 적는다)
	 */
	'dialogue_class' => 'modal-popup-generic',
	'buttons' => [
		[
			// 5버전은 스크립트(popup.topcpu.js)로 버튼 글자를 "닫기"로 바꿨다. 여기서는 처음부터 닫기 버튼을 준다.
			'title' => _('Close'),
			// cancel => true : 누르면 팝업을 닫는 버튼.
			'cancel' => true,
			'class' => ZBX_STYLE_BTN_ALT,
			'action' => ''
		]
	]
];

// 디버그 모드인 사용자에게는 코어의 디버그 정보(실행한 SQL 등)를 붙인다. 코어 팝업 뷰와 같은 처리다.
if ($data['user']['debug_mode'] == GROUP_DEBUG_MODE_ENABLED) {
	CProfiler::getInstance()->stop();
	$output['debug'] = CProfiler::getInstance()->make()->toString();
}

// json_encode(): PHP 배열을 JSON 글자로 바꾼다. 예) {"header":"...","body":"<table>...","buttons":[...]}
echo json_encode($output);
