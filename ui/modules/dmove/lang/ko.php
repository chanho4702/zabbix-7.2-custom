<?php declare(strict_types = 0);
/*
 * 한국어 번역 표. 형식: '코드에 적은 영어 원문' => '한국어 문구'
 *
 * ── 이 파일이 하는 일 ──
 *   모듈에서 새로 만든 문구(메뉴 이름, 화면 제목, 표 제목, 안내문)를 한국어로 바꿔 주는 표다.
 *   실행되는 코드는 없다. 배열 하나를 돌려주기만 한다. ("return [ ... ];")
 *
 * 읽는 곳: includes/Lang.php 의 Lang::t()
 * 왼쪽 영어 원문은 코드의 Lang::t('...') 안의 글자와 완전히 같아야 한다. (대소문자, 띄어쓰기 포함)
 * 여기에 없는 문구는 화면에 영어 원문 그대로 나온다.
 *
 * ── 쓰이는 흐름 ──
 *   컨트롤러/뷰/Module.php 가 Lang::t('Server time check') 를 부른다
 *     ├─ 사용자 언어가 한국어면 Lang 이 이 파일을 읽어서 '서버 시간 확인' 을 돌려준다
 *     └─ 다른 언어면 이 파일을 읽지 않고 영어 원문을 그대로 돌려준다
 *
 * ── 적는 법 ──
 *   - 한 줄에 '영어 원문' => '한국어 문구', 하나씩 적는다. 줄 끝의 쉼표를 빼먹지 않는다.
 *   - %1$s, %2$s, %1$d 는 값이 들어갈 자리다. 숫자는 몇 번째 값인지, s 는 글자, d 는 정수라는 뜻이다.
 *     예) Lang::t('%1$d days, %2$s', 158, '13:38:45')  ->  '158 일, 13:38:45'
 *     한국어 문구에도 같은 자리표시를 그대로 넣어야 값이 나온다.
 *   - %% 는 퍼센트 기호(%) 한 글자를 뜻한다. 자리표시를 쓰는 문구에서 % 를 그대로 보이려면 %% 로 적는다.
 *   - 코어에 이미 있는 문구(Host, Filter 등)는 여기에 넣지 않는다. 코어의 _() 가 번역한다.
 *
 * ── 관련 파일 ──
 *   includes/Lang.php   이 표를 읽어서 번역하는 클래스
 *   Module.php          메뉴 라벨 (아래 "메뉴 / 화면 제목" 묶음)
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
	'%1$d days, %2$s' => '%1$d 일, %2$s',
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

	'CPU total cores' => 'CPU 전체 Core',
	'CPU allocated cores' => 'CPU 할당 Core',
	'Total memory' => '전체 메모리',
	'Allocated memory' => '할당 메모리',
	'Total disk' => '전체 Disk',
	'Allocated disk' => '할당 Disk',
	'Latest data' => '최근 데이터',

	// ── 일일점검 ──
	'CPU usage Top 10' => 'CPU 사용률 Top 10',
	'Memory usage Top 10' => 'Memory 사용률 Top 10',
	'CPU usage (%)' => 'CPU 사용률(%)',
	'Memory usage (%)' => 'Memory 사용률(%)',
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
	'Business hours (%1$s:00 ~ %2$s:00)' => '업무시간 (%1$s:00 ~ %2$s:00)',
	'The period can be up to %1$d days. Choose a shorter period.' => '기간은 최대 %1$d일까지 고를 수 있습니다. 기간을 줄여 주세요.',
	'Preview: the first %1$d rows. Download the file to get all rows.' => '미리보기: 앞에서 %1$d줄만 표시합니다. 전체는 파일로 내려받으세요.',
	'There are no CPU/Memory items for the selected hosts.' => '선택한 호스트에 CPU/Memory 아이템이 없습니다.',
	'About %1$s rows are expected, which exceeds the limit of %2$s rows. Choose fewer hosts or a shorter period.' =>
		'예상 줄 수가 약 %1$s줄로 한도(%2$s줄)를 넘습니다. 호스트를 줄이거나 기간을 줄여 주세요.',
	'Target items: %1$s, expected rows: about %2$s. Press [Export to CSV] to download.' =>
		'대상 아이템 %1$s개, 예상 줄 수 약 %2$s줄. [CSV 내보내기] 를 누르면 내려받습니다.',
	'All hours' => '전체사용현황',
	'* Real-time data can be exported for the previous day up to 3 months.' =>
		'※실시간 Data 출력은 전일 ~ 3개월 기간 출력이 가능합니다.',
	'* Exporting a large amount of data may take more than a minute.' =>
		'※다량의 Data export시 1분 이상 소요될 수 있습니다.',

	// ── Peak 이벤트 분석 ──
	'Peak event type' => 'Peak이벤트 유형',

	// ── 장기 미접속 계정 현황 ──
	'User type' => '유저 종류',
	'Last access' => '최근접속일시',
	'* Accounts that have not logged in for %1$d days or more.' => '※ %1$d일 이상 접속하지 않은 계정입니다.',
	'The audit log is kept for less than %1$d days, so account creation dates cannot be checked and the list may be empty. Check conf/userunconnect.conf.php.' => '감사 로그 보관 기간이 %1$d일보다 짧아 계정을 만든 날짜를 확인할 수 없습니다. 목록이 비어 있을 수 있습니다. conf/userunconnect.conf.php 의 created_check 설명을 확인하세요.',

	// ── 공통 오류 ──
	'Invalid date. Enter the date as YYYY-MM-DD.' => '날짜 형식이 올바르지 않습니다. YYYY-MM-DD 형식으로 입력하세요.',
];
