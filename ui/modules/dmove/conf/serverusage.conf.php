<?php declare(strict_types = 0);
/*
 * 저사용 / 고사용 서버 사용현황 화면의 설정.
 *
 * 읽는 곳: includes/ServerUsageService.php, actions/reports/serverusage/ServerUsageController.php
 *
 * ── 이 파일이 하는 일 ──
 *   "어느 아이템을 사용률로 볼지", "몇 % 부터 저사용/고사용인지", "업무시간이 몇 시부터 몇 시인지"를 정한다.
 *   실행되는 코드는 없다. "return [ ... ];" 로 배열 하나를 돌려주기만 한다.
 *   ServerUsageService 가 만들어질 때마다 require 로 다시 읽으므로, 값을 고치면 다음 화면 조회부터 반영된다.
 *
 * ── 이 설정을 쓰는 화면 ──
 *   저사용 서버 사용현황 (lowserver.list)    cpu_item_keys, thresholds 의 low_*, 업무시간 설정, max_period_days
 *   고사용 서버 사용현황 (highserver.list)   cpu_item_keys, thresholds 의 high_*, 업무시간 설정, max_period_days
 *   일일점검의 Top 10 창 (dailycheck.top)    cpu_item_keys, memory_item_keys
 *
 * ── 값이 쓰이는 흐름 ──
 *   1) 대상 호스트에서 cpu_item_keys(또는 memory_item_keys)에 맞는 아이템을 찾는다.
 *   2) 그 아이템의 시간별 요약 값(평균, 최대)을 DB 에서 읽어 호스트별 평균과 Peak 를 구한다.
 *      소수 형식 아이템의 요약(trends)과 정수 형식 아이템의 요약(trends_uint)을 모두 읽는다.
 *   3) 저사용/고사용 화면은 thresholds 의 기준에 맞는 호스트만 남긴다.
 *
 * ── 값의 출처 (2026-11-23 채움) ──
 *   cpu_item_keys    : 5버전 키 클래스(...Key.php)의 serverCpuUsage() 가 돌려주던 키 3개 (리눅스, 유닉스, 윈도우)
 *   memory_item_keys : 같은 클래스의 serverMemoryUsage() 키 + 5버전 "CPU/Mem 사용률 추출" 화면이 읽던 키
 *   thresholds       : 5버전 화면의 [기준설정] 창 기본값
 *
 *   TODO(고객환경): 유닉스 CPU 키는 5버전 소스에서 끝까지 확인하지 못해 앞부분만 적었다. (아래 prefix 설명)
 *   7버전 서버에서 확인: 데이터 수집 > 호스트 > (서버 종류별로 하나씩) > 아이템 에서 CPU / 메모리 사용률 아이템의 "키"
 *   키가 하나도 맞지 않으면 목록이 비어 나온다. 키를 모두 지우면 화면에 "설정이 필요합니다" 안내가 나온다.
 */

return [
	/*
	 * CPU 사용률(%) 아이템의 키. 호스트마다 이 중 하나를 가지고 있으면 그 값을 쓴다.
	 *
	 *   exact  : 키가 정확히 같은 아이템
	 *   prefix : 키가 이 글자로 "시작"하는 아이템. 키 뒷부분이 서버마다 다르거나 너무 길 때 쓴다.
	 *
	 * 값은 0~100 사이의 숫자여야 한다. 소수(실수) 형식과 정수 형식의 아이템 모두 집계된다.
	 */
	'cpu_item_keys' => [
		'exact' => [
			// 리눅스 (5버전 키 클래스 serverCpuUsage() 의 linux)
			'100-system.cpu.util[all,idle,avg1]',
			// 윈도우 (serverCpuUsage() 의 windows)
			'system.cpu.util'
		],
		'prefix' => [
			/*
			 * 유닉스 (serverCpuUsage() 의 unix). 키 뒷부분(awk 명령)은 5버전 소스에서 끝까지 확인하지 못해서
			 * 확실한 앞부분만 적고 "이 글자로 시작하는 키"로 찾는다.
			 * TODO(고객환경): 7버전 서버의 유닉스 호스트에서 실제 키를 확인한다.
			 */
			'system.run[cat /zabbix/script/res_vmstat.log'
		]
	],

	/*
	 * 메모리 사용률(%) 아이템의 키. 형식은 cpu_item_keys 와 같다.
	 * 일일점검 화면의 [Memory 사용률 Top 10] 창에서만 쓴다. 비어 있으면 그 창에 "설정이 필요합니다" 안내가 나온다.
	 *
	 * 넣을 값: 5버전 키 클래스(...Key.php)의 serverMemoryUsage() 가 돌려주는 키 (유닉스, 윈도우 등)
	 */
	'memory_item_keys' => [
		'exact' => [
			// 윈도우 (5버전 키 클래스 serverMemoryUsage() 의 windows. linux 줄은 5버전에서 주석 처리돼 있었다)
			'vm.memory.size[pused]',
			// 유닉스 (serverMemoryUsage() 의 unix)
			'system.run[/zabbix/shells/MEM_COMP.sh]',
			// 5버전 "CPU/Mem 사용률 추출" 화면이 메모리 값으로 읽던 키 (monitoring.perf.view.php)
			'system.run[/zabbix/script/MEMORY_USAGE.sh]'
		],
		'prefix' => [
		]
	],

	/*
	 * 판정 기준(%). 화면의 빨간 안내문에도 이 값이 표시된다.
	 *
	 *   저사용 : 평균이 low_avg 이하  그리고  Peak 가 low_peak 이하인 서버
	 *   고사용 : 평균이 high_avg 이상 그리고  Peak 가 high_peak 이상인 서버
	 *
	 * 5버전은 이 값을 화면의 [기준설정] 버튼으로 사용자마다 바꿀 수 있었다. (저장 화면은 확인하지 못해 옮기지 않았다)
	 * 지금은 이 파일의 값을 모든 사용자가 같이 쓴다.
	 */
	'thresholds' => [
		'low_avg' => 30,
		'low_peak' => 70,
		'high_avg' => 50,
		'high_peak' => 70
	],

	/*
	 * 업무시간. 시(0~23)로 적는다. 시작 시는 포함, 끝 시는 포함하지 않는다.
	 *   [9, 18] = 09:00 ~ 18:00  ->  09시대 ~ 17시대의 값  (5버전과 같다)
	 * 업무외시간은 그 나머지다. 업무외시간은 요일을 가리지 않는다. (5버전과 같다)
	 */
	'business_hours' => [9, 18],

	/*
	 * 업무일. "업무시간" 을 고르면 이 요일의 값만 집계한다. ISO 요일 번호: 월 1, 화 2, ... 토 6, 일 7
	 * 5버전은 토, 일요일을 뺐다. ([] 로 두면 모든 요일)
	 * 공휴일은 구분하지 않는다. (5버전과 같다)
	 */
	'business_days' => [1, 2, 3, 4, 5],

	/*
	 * "업무시간" 의 Peak 를 그날 전체 시간의 최대로 할지.
	 *   true  : 5버전과 같다. 업무시간 평균은 09~18시 값으로, Peak 는 그 업무일 하루 전체의 최대로 계산한다.
	 *           (5버전 집계 코드가 그렇게 되어 있었다. 의도인지 실수인지는 알 수 없다)
	 *   false : Peak 도 업무시간 안의 최대로 계산한다.
	 * 5버전 화면과 숫자를 비교할 때는 true 로 둔다.
	 */
	'business_peak_whole_day' => true,

	/** 조회할 수 있는 최대 기간(일). 5버전 화면 안내문: "최대 1년까지 기간 지정이 가능합니다." */
	'max_period_days' => 366
];
