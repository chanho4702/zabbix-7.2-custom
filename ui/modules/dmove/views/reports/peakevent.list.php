<?php declare(strict_types = 0);
/*
 * Peak 이벤트 분석 화면의 뷰. (화면을 그리는 파일)
 *
 * 컨트롤러: actions/reports/peakevent/PeakEventList.php
 *
 * ── 이 파일이 하는 일 ──
 *   컨트롤러가 조회해서 넘긴 값($data)으로 시간 선택기와 표를 조립해서 화면에 출력한다.
 *   여기서는 DB 나 API 를 읽지 않는다.
 *
 *   화면 : zabbix.php?action=peakevent.list
 *
 * ── 화면 구성 ──
 *   제목 오른쪽 : [CSV 내보내기] [Excel 내보내기]
 *   필터        : 호스트 그룹, 호스트, Peak이벤트 유형(트리거 이름의 일부), 심각도 + 시간 선택기 (조회 기간)
 *   표          : 호스트, IP, Description, 트리거 이름, 심각도, 횟수   (정렬 헤더와 페이지 나누기는 없다)
 *
 * ── 받는 값 ($data) ──
 *   action            이 화면의 액션 이름. 'peakevent.list'
 *   export_action     내보내기 액션 이름. 'peakevent.export'
 *   title             화면 제목
 *   columns           열 정의. [행의 키 => 제목 글자] 또는 [행의 키 => ['label' => 제목, 'type' => 'number']]
 *   timeline          시간 선택기에 넘길 기간 정보. ['from' => 'now-1h', 'to' => 'now', ...]
 *   timeline_profile  기간을 저장하는 프로파일 키
 *   active_tab        필터가 펼쳐져 있는지 (지난번 상태)
 *   host_filter       호스트 그룹/호스트 선택창에 표시할 값. ['groups' => [...], 'hosts' => [...]]
 *   filter            ['name' => 'CPU', 'severities' => [2, 4]]
 *   rows              [['host' => .., 'ip' => .., 'description' => .., 'trigger' => .., 'severity' => 0~5, 'count' => N], ...]
 *
 * ── 관련 파일 ──
 *   actions/reports/peakevent/PeakEventList.php   컨트롤러
 *   includes/export/ExportButtons.php             내보내기 버튼
 *
 * 이 화면은 코어의 "시간 선택기"(최신 1시간, 어제, 이번 달 ... 을 고르는 부품)를 쓴다.
 * 시간 선택기를 붙이려면 아래 (가), (나) 두 곳이 필요하다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Export\ExportButtons;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;

// ── 1) 필터 영역: 필터 탭(호스트 그룹, 호스트, Peak이벤트 유형, 심각도) + 시간 선택기 ──────────

/*
 * 필터 탭의 내용. 5버전 화면과 같은 네 가지다.
 *   HostFilter::addRows()  호스트 그룹 / 호스트 선택창 두 줄을 만들어 주는 공용 부품. (includes/filter/HostFilter.php)
 *   CCheckBoxList          체크박스 묶음. 심각도 이름과 색은 코어 설정(관리 > 일반 > 트리거 표시 옵션)을 따른다.
 *                          하나도 체크하지 않으면 전체 심각도를 본다.
 */
$filter_form = HostFilter::addRows(new CFormList(), $data['host_filter'])
	->addRow(Lang::t('Peak event type'),
		(new CTextBox('filter_name', $data['filter']['name']))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
	)
	->addRow(_('Severity'),
		(new CCheckBoxList('filter_severities'))
			->setOptions(CSeverityHelper::getSeverities())
			->setChecked($data['filter']['severities'])
			->setColumns(3)
			->setVertical()
			->showTitles()
	);


$filter = (new CFilter())
	// 필터 탭의 접힘/펼침 상태와 기간을 저장하는 프로파일 키. 컨트롤러의 TIMELINE_PROFILE 과 같아야 한다.
	->setProfile($data['timeline_profile'])
	->setActiveTab($data['active_tab'])
	->addVar('action', $data['action'])
	// [리셋] 버튼이 이동할 주소. CFilter 가 filter_rst=1 을 붙여 준다.
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	->addFilterTab(_('Filter'), [$filter_form])
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
	// 조건 ? A : B  : 조건이 참이면 A, 아니면 B.  배열이면 그 안의 label 을, 글자면 그대로 쓴다.
	$header[] = is_array($column) ? $column['label'] : $column;
}

// CTableInfo: 코어의 목록 표. 행이 하나도 없으면 "데이터 없음" 안내 줄을 스스로 표시한다.
$table = (new CTableInfo())->setHeader($header);

foreach ($data['rows'] as $row) {
	// ZBX_STYLE_NOWRAP: 칸 안에서 줄바꿈을 하지 않는다.  ZBX_STYLE_WORDBREAK: 긴 글자를 칸 너비에 맞춰 줄바꿈한다.
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
 *
 *      timeControl : 코어의 자바스크립트 객체. 시간 선택기에서 기간이 바뀌면 화면을 다시 불러온다.
 *      json_encode : PHP 배열을 자바스크립트가 읽을 수 있는 글자로 바꾼다.
 *      CScriptTag  : <script> 태그 하나. 안의 글자가 브라우저에서 실행된다.
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
