<?php declare(strict_types = 0);
/*
 * Peak 이벤트 분석 화면. (껍데기)
 *
 * 컨트롤러: actions/reports/peakevent/PeakEventList.php
 *
 * TODO(고객환경): 5버전 화면을 보고 필터와 표 컬럼을 추가한다.
 *                 필터/정렬/페이징/CSV 가 모두 들어 있는 예시는 template/views/ 에 있다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Lang;

$table = (new CTableInfo())
	// TODO(고객환경): 표 컬럼 제목을 넣는다. 예) ->setHeader([_('Host'), 'IP', ...])
	->setHeader([]);

foreach ($data['rows'] as $row) {
	// TODO(고객환경): 컬럼 순서대로 값을 넣는다. 예) $table->addRow([$row['host'], $row['ip']]);
	$table->addRow([]);
}

(new CHtmlPage())
	->setTitle($data['title'])
	// 구현이 끝나면 아래 안내 상자(addItem 한 덩어리)는 지운다.
	->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [], Lang::t('This page is under construction.'), false))
	->addItem($table)
	->show();
