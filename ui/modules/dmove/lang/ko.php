<?php declare(strict_types = 0);
/*
 * 한국어 번역 표. 형식: '코드에 적은 영어 원문' => '한국어 문구'
 *
 * 읽는 곳: includes/Lang.php 의 Lang::t()
 * 왼쪽 영어 원문은 코드의 Lang::t('...') 안의 글자와 완전히 같아야 한다. (대소문자, 띄어쓰기 포함)
 * 여기에 없는 문구는 화면에 영어 원문 그대로 나온다.
 */

return [
	// ── 메뉴 / 화면 제목 ──
	'SW inventory' => 'SW 인벤토리',
	'Server time check' => '서버 시간 확인',
	'Low usage servers' => '저사용 서버 사용현황',
	'High usage servers' => '고사용 서버 사용현황',
	'Performance trend analysis' => '성능 데이터 추세 분석',
	'Daily check' => '일일점검',
	'Peak event analysis' => 'Peak 이벤트 분석',
	'Number of status changes' => '상태 변경 횟수',
	'Windows patch status' => '윈도우즈 패치 현황',
	'CPU/Memory usage export' => 'CPU/Memory 전체 사용률 추출',
	'Long-term inactive accounts' => '장기 미접속 계정 현황',

	// ── 공통 ──
	'This page is under construction.' => '이 화면은 아직 구현되지 않았습니다.',
	'Data retrieval is not implemented yet.' => '데이터 조회가 아직 구현되지 않았습니다.',
	'Export to Excel' => 'Excel 내보내기',
	'Settings are required: %1$s' => '설정이 필요합니다: %1$s',

	// ── SW 인벤토리 ──
	'ITSM host' => 'ITSM HOST',
	'OS/Version' => 'OS/Version',
	'Redundancy' => '이중화',
	'Redundancy solution' => '이중화 솔루션',
	'Display name' => '표시명',
	'Agent installed' => 'Agent 설치',
	'Installed' => '설치',
	'Not installed' => '미설치',
	'Exception' => '예외',
	'ITSM table is not configured (conf/itsm.conf.php). Showing Zabbix hosts instead.' =>
		'ITSM 테이블이 설정되지 않아(conf/itsm.conf.php) Zabbix 호스트 목록 기준으로 표시 중입니다.',

	// ── 서버 시간 확인 ──
	'Server uptime' => '서버 Uptime',
	'days or more' => 'day 이상',
	'Server time' => '서버 Time',
	'Server start' => '서버 start',

	// ── 저사용 / 고사용 서버 사용현황 ──
	'Start' => '시작',
	'End' => '종료',
	'* The period can be up to 1 year.' => '※최대 1년까지 기간 지정이 가능합니다.',
	'The start date must not be later than the end date.' => '시작일이 종료일보다 늦을 수 없습니다.',
	'Business hours usage (%1$s:00 ~ %2$s:00)' => '업무시간 사용현황(%1$s시~%2$s시)',
	'Off hours usage (%1$s:00 ~ %2$s:00)' => '업무외시간 사용현황(%1$s시~%2$s시)',
	'Average CPU usage (%)' => '평균CPU사용률(%)',
	'* Low usage criteria: average usage %1$s%% or less / Peak %2$s%% or less' =>
		'※저사용 기준: 평균사용률 %1$s%% 이하 / Peak %2$s%% 이하',
	'* High usage criteria: average usage %1$s%% or more / Peak %2$s%% or more' =>
		'※고사용 기준: 평균사용률 %1$s%% 이상 / Peak %2$s%% 이상',

	// ── Box 사용현황 ──
	'Box usage' => 'Box 사용현황',
	'CPU total cores' => 'CPU 전체 Core',
	'CPU allocated cores' => 'CPU 할당 Core',
	'Total memory' => '전체 메모리',
	'Allocated memory' => '할당 메모리',
	'Total disk' => '전체 Disk',
	'Allocated disk' => '할당 Disk',
	'Latest data' => '최근 데이터',

	// ── 일일점검 ──
	'Check item' => '점검항목',
	'Normal hosts' => '정상호스트',
	'Failed hosts' => '장애발생 호스트',
	'Checked hosts (*all hosts)' => '점검대상(*전체호스트)',
	'OS running state' => 'OS기동상태',
	'Business solution processes running' => '업무솔루션 프로세스 기동여부',
	'Disk usage over 90%' => 'Disk Usage 90% 초과',
	'Disk usage up 30% from previous day' => 'Disk Usage 전일 대비 30% 급증',
	'CPU usage (business hours) up 20% from previous day' => 'CPU Usage(업무시간) 전일 대비 20% 급증',
	'Paging space over threshold (1%)' => 'Paging space 기준 초과(1%)',
	'Redundancy solution state' => '이중화 솔루션 상태',

	// ── 윈도우즈 패치 현황 ──
	'Collection month' => '수집월선택',
	'Patch file name' => 'Patch File명',
	'View details' => '상세보기',
	'Windows patch details' => 'Windows 패치 상세정보',

	// ── CPU/Memory 전체 사용률 추출 ──
	'Items' => '항목',
	'Hours' => '시간',
	'Business hours (09:00 ~ 18:00)' => '업무시간 (09:00 ~ 18:00)',
	'All hours' => '전체사용현황',
	'* Real-time data can be exported for the previous day up to 3 months.' =>
		'※실시간 Data 출력은 전일 ~ 3개월 기간 출력이 가능합니다.',
	'* Exporting a large amount of data may take more than a minute.' =>
		'※다량의 Data export시 1분 이상 소요될 수 있습니다.',

	// ── Peak 이벤트 분석 ──
	'Peak event type' => 'Peak이벤트 유형',

	// ── 장기 미접속 계정 현황 ──
	'User type' => '유저 종류',
	'Last access' => '최근접속일시'
];
