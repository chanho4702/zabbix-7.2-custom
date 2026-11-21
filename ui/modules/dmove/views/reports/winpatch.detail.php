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
 * @var CView $this
 * @var array $data
 */

$table = (new CTableInfo())->setHeader($data['columns']);

foreach ($data['rows'] as $row) {
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

echo json_encode($output);
