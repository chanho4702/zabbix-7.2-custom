<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 상세정보 팝업의 내용.
 *
 * 컨트롤러: actions/reports/winpatch/WinPatchDetail.php
 *
 * 팝업 뷰는 화면을 그리지 않고(->show() 없음) 아래 형태의 JSON 을 출력한다.
 *   header  : 팝업 제목
 *   body    : 팝업 안에 들어갈 HTML 문자열
 *   buttons : 팝업 아래쪽 버튼 목록
 *
 * ── 이 파일이 하는 일 ──
 *   컨트롤러가 넘긴 패치 목록으로 표를 만들고, 그 표를 HTML 문자열로 바꿔서 JSON 에 담아 출력한다.
 *   브라우저 쪽에서는 코어 함수 PopUp() 이 이 JSON 을 받아 팝업 창을 그린다.
 *   (manifest.json 에서 이 액션의 layout 이 "layout.json" 이라서 출력이 JSON 으로 나간다)
 *
 *   호출 : zabbix.php?action=winpatch.detail&hostid=..&month=YYYY-MM-DD
 *          윈도우즈 패치 현황 화면에서 "상세보기" 링크를 누르면 열린다.
 *
 * ── 팝업 구성 ──
 *   제목 : 팝업 제목 + ": 호스트 이름"
 *   표   : 열은 설정(conf/winpatch.conf.php 의 columns)에 적은 이름 그대로다. 행 1개 = 패치 1건
 *   버튼 : [닫기]
 *
 * ── 받는 값 ($data) ──
 *   title    팝업 제목
 *   host     호스트 이름. 볼 수 없는 호스트이거나 패치 아이템이 없으면 ''
 *   columns  열 이름 목록. 표의 제목이면서 행에서 값을 꺼내는 키다. 예) ['Caption', 'HotFixID', ...]
 *   rows     [['Caption' => 값, 'HotFixID' => 값, ...], ...]   그 달에 수집된 값이 없으면 빈 배열
 *   user     ['debug_mode' => 0 또는 1]  디버그 정보를 붙일지 정한다
 *
 * ── 관련 파일 ──
 *   actions/reports/winpatch/WinPatchDetail.php   컨트롤러
 *   includes/WinPatchService.php                  패치 목록 조회
 *   views/js/reports/winpatch.list.js.php         이 팝업을 여는 스크립트
 *   views/reports/winpatch.list.php               "상세보기" 링크가 있는 화면
 *   conf/winpatch.conf.php                        아이템 키, 열 이름 설정
 *
 * @var CView $this
 * @var array $data
 */

// CTableInfo: 코어의 목록 표. 제목 줄은 설정에서 온 열 이름을 그대로 쓴다.
$table = (new CTableInfo())->setHeader($data['columns']);

foreach ($data['rows'] as $row) {
	// 열 이름 순서대로 그 행의 값을 꺼내 한 줄의 칸들을 만든다.
	$cells = [];

	foreach ($data['columns'] as $column) {
		// 값이 길어도 표가 팝업 밖으로 나가지 않게 줄바꿈을 허용한다.
		$cells[] = (new CCol($row[$column]))->addClass(ZBX_STYLE_WORDBREAK);
	}

	$table->addRow($cells);
}

$output = [
	// 제목 뒤에 호스트 이름을 붙인다. 예) Windows 패치 상세정보: HOST-A
	'header' => $data['host'] !== '' ? $data['title'].': '.$data['host'] : $data['title'],
	// toString(): 화면 클래스를 HTML 문자열로 바꾼다. 값의 HTML 이스케이프도 이때 된다.
	'body' => $table->toString(),
	/*
	 * 팝업 창의 모양(class). 코어 PopUp() 은 응답을 받은 뒤 이 값으로 창 class 를 다시 정한다.
	 * 빠뜨리면 창 class 가 "modal-popup undefined" 가 되어 처음 준 modal-popup-generic 이 사라진다. (코어 팝업 뷰도 같이 적는다)
	 */
	'dialogue_class' => 'modal-popup-generic',
	'buttons' => [
		[
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
