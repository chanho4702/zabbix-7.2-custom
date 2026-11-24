<?php declare(strict_types = 0);
/*
 * CPU/Memory 전체 사용률 추출 화면의 설정.
 *
 * 읽는 곳: includes/UsageExportService.php
 *         (화면: zabbix.php?action=usageexport.list   메뉴: 보고서 > CPU/Memory 전체 사용률 추출)
 *
 * ── 이 파일의 형식 ──
 *   실행되는 코드는 없다. "return [ ... ];" 로 배열 하나를 돌려주기만 한다.
 *   값을 고치면 다음 화면 조회부터 반영된다.
 *
 * ── 어느 아이템을 내보내는가 ──
 *   아래 cpu_item_keys / memory_item_keys 와 키가 정확히 같은 아이템. (5버전: itemkey in (...))
 *   저사용/고사용 화면의 키(conf/serverusage.conf.php)와는 다르다. 5버전도 두 화면의 키가 달랐다.
 *   비어 있으면 이 화면에 "설정이 필요합니다" 안내가 나온다.
 *
 * 숫자와 키는 모두 5버전 소스(monitoring.perf.view.php)와 화면 안내문에 있던 값이다.
 */

return [
	/*
	 * CPU 사용률 아이템의 키. 5버전 monitoring.perf.view.php 113~117행의 값 그대로다.
	 * (유닉스 CPU 키는 5버전 이 화면에도 없었다)
	 */
	'cpu_item_keys' => [
		'system.cpu.util',
		'100-system.cpu.util[all,idle,avg1]'
	],

	/* 메모리 사용률 아이템의 키. 5버전 monitoring.perf.view.php 114, 120행의 값 그대로다. */
	'memory_item_keys' => [
		'vm.memory.size[pused]',
		'system.run[/zabbix/script/MEMORY_USAGE.sh]'
	],

	/*
	 * 한 번에 내보낼 수 있는 최대 기간(일).
	 * 5버전 화면 안내문: "실시간 Data 출력은 전일 ~ 3개월 기간 출력이 가능합니다"
	 * 3개월을 넉넉히 93일로 잡았다. (31일 x 3)
	 */
	'max_period_days' => 93,

	/*
	 * 줄 수를 어림할 때 쓰는 수집 간격(초).
	 * 5버전은 "기간(초) / 180 x 아이템 수" 로 줄 수를 어림했다. (3분마다 한 번 수집한다고 본 것)
	 * 실제 수집 간격이 다르면 이 값을 그 간격으로 바꾼다.
	 */
	'assumed_interval' => 180,

	/*
	 * 값 줄 수가 이 값을 넘으면 내보내지 않고 안내만 한다. (서버 메모리와 시간을 지키기 위한 한도)
	 * [CSV 내보내기] 를 누르면 실제 줄 수를 먼저 세어(COUNT) 이 값과 비교한다. 화면의 예상 줄 수는 참고용이다.
	 * 5버전: 1000만 줄
	 */
	'max_rows' => 10000000,

	/*
	 * 파일 하나의 줄 수. 제목 줄도 한 줄로 센다. (5버전: export_csv.sh 의 split -l 1000000)
	 * 제목 줄을 더한 전체 줄 수가 이 값을 넘으면 나눠서 zip 하나로 받는다.
	 *   첫 파일(00) = 제목 줄 + 값 999,999줄,  다음 파일(01, 02 ...) = 값 1,000,000줄 (제목 줄 없음)
	 */
	'split_rows' => 1000000,

	/*
	 * "업무시간" 을 고르면 이 시간대의 값만 내보낸다. [시작 시, 끝 시]. 끝 시는 포함하지 않는다.
	 * 5버전: 9시 이상 18시 미만 (화면 문구 "업무시간 (09:00 ~ 18:00)"). 요일은 가리지 않는다.
	 * 시각은 5버전처럼 DB 서버의 시간대로 본다. (CLOCK 열도 같다. 로그인한 사용자의 시간대가 아니다)
	 */
	'business_hours' => [9, 18]
];
