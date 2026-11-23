<?php declare(strict_types = 0);
/*
 * 성능 데이터 추세 분석 화면의 설정.
 *
 * 읽는 곳: includes/PerfTrendService.php
 *         (화면: zabbix.php?action=perftrend.list   메뉴: 보고서 > 성능 데이터 추세 분석)
 *
 * 실행되는 코드는 없다. "return [ ... ];" 로 배열 하나를 돌려주기만 한다.
 */

return [
	/*
	 * CSV 에 넣을 값 줄 수의 한도. 넘으면 파일을 만들지 않고 화면에 안내를 띄운다. (서버 메모리 보호)
	 * 5버전은 한도가 없었다. CSV 는 파일 전체를 메모리에서 만든 뒤 내려보낸다.
	 */
	'max_csv_rows' => 1000000
];
