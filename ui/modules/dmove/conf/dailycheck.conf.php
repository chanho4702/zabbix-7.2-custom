<?php declare(strict_types = 0);
/*
 * 일일점검 화면의 설정.
 *
 * 읽는 곳: actions/reports/dailycheck/DailyCheckList.php
 *         (화면: zabbix.php?action=dailycheck.list   메뉴: 보고서 > 일일점검)
 *
 * 일일점검은 점검 항목마다 "이 태그가 달린 트리거가 점검 구간 안에 울렸는가"로 호스트를 센다.
 * 그 태그의 이름과 항목별 값을 여기에 적는다.
 *
 * ── 이 파일의 형식 ──
 *   실행되는 코드는 없다. "return [ ... ];" 로 배열 하나를 돌려주기만 한다.
 *   컨트롤러가 require 로 이 파일을 읽으면 그 배열을 그대로 받는다. 화면을 열 때마다 다시 읽으므로
 *   값을 고치면 다음 화면 조회부터 바로 반영된다.
 *
 * ── 값이 쓰이는 흐름 ──
 *   컨트롤러가 항목 일곱 개를 차례로 돌면서
 *     ├─ tag_name 과 그 항목의 tag_values 값이 둘 다 있으면 -> 그 태그(이름 + 값)가 달린 트리거로 호스트 수를 센다
 *     └─ 하나라도 비어 있으면 -> 그 항목은 세지 않고 '-' 로 표시한다
 *
 * ── 적는 형식 ──
 *   'tag_name' => '태그이름',
 *   'tag_values' => ['os' => '값1', 'work' => '값2', ...]
 *   작은따옴표 안에 적는다. 앞뒤 빈칸은 컨트롤러가 지우고 쓴다.
 *
 * ── 값의 출처 (2026-11-23 채움) ──
 *   5버전 일일점검 트레잇(...Daily.php)의 $tag_os, $tag_work, $tag_disk, $tag_disk30, $tag_cpu, $tag_paging,
 *   $tag_duplex 각각의 'name'(태그 이름, 모두 같다)과 'value'(항목별 값)
 *
 *   7버전 서버에서 확인: 데이터 수집 > 호스트(또는 템플릿) > 트리거 의 태그에 같은 이름/값이 달려 있어야 한다.
 *   비워 둔 항목은 숫자 대신 '-' 로 나오고 "설정이 필요합니다" 안내가 표시된다.
 */

return [
	/** 점검 항목을 구분하는 트리거 태그의 이름. 일곱 항목이 모두 같은 이름을 쓴다. */
	// 5버전 일일점검 트레잇의 $tag_os['name'] 등. 일곱 항목 모두 같은 태그 이름을 쓴다.
	'tag_name' => 'REPORTS',

	/**
	 * 항목별 태그 값. 왼쪽 키는 컨트롤러의 CHECKS 에 있는 항목 키와 같아야 한다.
	 *
	 *   os     : OS기동상태
	 *   work   : 업무솔루션 프로세스 기동여부
	 *   disk90 : Disk Usage 90% 초과
	 *   disk30 : Disk Usage 전일 대비 30% 급증
	 *   cpu20  : CPU Usage(업무시간) 전일 대비 20% 급증
	 *   paging : Paging space 기준 초과(1%)
	 *   duplex : 이중화 솔루션 상태
	 */
	'tag_values' => [
		'os' => 'OS_REBOOT',
		'work' => 'PROC_DOWN',
		'disk90' => 'DISK_USAGE',
		'disk30' => 'FS_DIFF',
		'cpu20' => 'CPU_DIFF',
		'paging' => 'PAGING_SPACE',
		'duplex' => 'HA_SOLUTION'
	]
];
