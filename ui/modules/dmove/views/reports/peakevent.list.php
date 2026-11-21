<?php declare(strict_types = 0);
/*
 * Peak 이벤트 분석 화면.
 *
 * 컨트롤러: actions/reports/peakevent/PeakEventList.php
 *
 * 이 화면은 코어의 "시간 선택기"(최신 1시간, 어제, 이번 달 ... 을 고르는 부품)를 쓴다.
 * 시간 선택기를 붙이려면 아래 (가), (나) 두 곳이 필요하다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Export\ExportButtons;

// ── 1) 필터 영역: 시간 선택기만 있다 ────────────────────────────────────────

$filter = (new CFilter())
	// 필터 탭의 접힘/펼침 상태와 기간을 저장하는 프로파일 키. 컨트롤러의 TIMELINE_PROFILE 과 같아야 한다.
	->setProfile($data['timeline_profile'])
	->setActiveTab($data['active_tab'])
	->addVar('action', $data['action'])
	/*
	 * (가) 시간 선택기 탭을 추가한다.
	 *      인자: 시작, 종료, 탭을 표시할지, 기간을 저장할 프로파일 키
	 *      시작/종료는 "now-1h", "now" 같은 표현이다. 컨트롤러의 getTimeSelectorPeriod() 가 만들어 준 값이다.
	 */
	->addTimeSelector($data['timeline']['from'], $data['timeline']['to'], true, $data['timeline_profile']);

// ── 2) 표 ───────────────────────────────────────────────────────────────────

// 제목 글자는 컨트롤러 getColumns() 의 값을 쓴다. 숫자 열은 ['label' => .., 'type' => ..] 형태라서 label 을 꺼낸다.
$header = [];

foreach ($data['columns'] as $column) {
	$header[] = is_array($column) ? $column['label'] : $column;
}

$table = (new CTableInfo())->setHeader($header);

foreach ($data['rows'] as $row) {
	$table->addRow([
		(new CCol($row['host']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['ip']))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($row['description']))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol($row['trigger']))->addClass(ZBX_STYLE_WORDBREAK),
		// 심각도 칸. 코어 도우미가 심각도 이름과 배경색(정보/경고/평균/높음/재해)을 넣어 준다.
		CSeverityHelper::makeSeverityCell($row['severity']),
		$row['count']
	]);
}

// ── 3) 페이지 조립 ──────────────────────────────────────────────────────────

(new CHtmlPage())
	->setTitle($data['title'])
	->setControls(ExportButtons::create($data['export_action']))
	->addItem($filter)
	->addItem($table)
	->show();

/*
 * (나) 시간 선택기를 동작시키는 스크립트.
 *      이것이 없으면 기간을 눌러도 화면이 바뀌지 않는다. 코어의 보고서 화면들이 쓰는 것과 같은 코드다.
 *      (예: ui/app/views/reports.auditlog.list.php 맨 아래)
 *
 *      'peakevent' 는 이 화면을 구분하는 이름이다. 다른 화면에 복사할 때는 그 화면 이름으로 바꾼다.
 */
$timeline_object = [
	'id' => 'timeline_1',
	'domid' => 'peakevent',
	'loadSBox' => 0,
	'loadImage' => 0,
	'dynamic' => 0
];

(new CScriptTag(
	'timeControl.addObject("peakevent", '.json_encode($data['timeline']).', '.json_encode($timeline_object).');'.
	'timeControl.processObjects();'
))->show();
