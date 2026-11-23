<?php declare(strict_types = 0);
/*
 * 성능 데이터 추세 분석 화면. (껍데기)
 *
 * 컨트롤러: actions/reports/perftrend/PerfTrendList.php
 *
 * ── 이 파일이 하는 일 ──
 *   아직 구현하지 않은 화면이다. 제목, "준비 중" 안내 상자, 빈 표만 그린다.
 *   컨트롤러도 빈 행 목록을 넘기므로 지금은 표에 아무 줄도 나오지 않는다.
 *
 *   화면 : zabbix.php?action=perftrend.list
 *
 * ── 받는 값 ($data) ──
 *   action  이 화면의 액션 이름. 'perftrend.list'
 *   title   화면 제목
 *   rows    표의 행. 지금은 항상 빈 배열
 *
 * ── 관련 파일 ──
 *   actions/reports/perftrend/PerfTrendList.php   컨트롤러 (껍데기)
 *   template/views/sample.list.php                화면을 채울 때 참고할 예시 뷰
 *
 * TODO(고객환경): 5버전 화면을 보고 필터와 표 컬럼을 추가한다.
 *                 필터/정렬/페이징/CSV 가 모두 들어 있는 예시는 template/views/ 에 있다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Lang;

// CTableInfo: 코어의 목록 표. setHeader(제목 배열) 로 제목 줄을, addRow(값 배열) 로 한 줄씩 넣는다.
$table = (new CTableInfo())
	// TODO(고객환경): 표 컬럼 제목을 넣는다. 예) ->setHeader([_('Host'), 'IP', ...])
	->setHeader([]);

foreach ($data['rows'] as $row) {
	// TODO(고객환경): 컬럼 순서대로 값을 넣는다. 예) $table->addRow([$row['host'], $row['ip']]);
	$table->addRow([]);
}

// CHtmlPage: 화면 한 장. 제목과 본문을 넣고 마지막에 show() 로 출력한다.
// makeMessageBox(종류, 상세 목록, 제목 글자, 닫기 버튼 여부): 코어 함수. 노란 안내 상자를 만든다.
(new CHtmlPage())
	->setTitle($data['title'])
	// 구현이 끝나면 아래 안내 상자(addItem 한 덩어리)는 지운다.
	->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('This page is under construction.'), false))
	->addItem($table)
	->show();
