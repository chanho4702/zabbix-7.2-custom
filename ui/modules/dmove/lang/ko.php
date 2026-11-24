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
	'Agent status' => 'Agent 상태',
	'Reason' => '사유',
	'Entered by' => '입력자',
	'Recent records' => '최근 기록',
	'Saved.' => '저장했습니다.',
	'Save' => '저장',
	'Cannot save.' => '저장하지 못했습니다.',
	'Invalid server key.' => '서버 키가 올바르지 않습니다.',
	'The reason can be up to %1$d characters.' => '사유는 %1$d자까지 입력할 수 있습니다.',
	'The Agent status table does not exist.' => 'Agent 상태 표가 없습니다.',
	'Run sql/extends_agent.sql in the Zabbix database, or check conf/swinventory.conf.php.' =>
		'Zabbix DB 에서 sql/extends_agent.sql 을 실행하거나 conf/swinventory.conf.php 를 확인하세요.',
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
	'Criteria settings' => '기준설정',
	'Collection criteria settings' => '수집기준 설정',
	'Low usage average CPU usage (%)' => '저사용 평균 CPU 사용률(%)',
	'Low usage Peak criterion' => '저사용 Peak기준',
	'High usage average CPU usage (%)' => '고사용 평균 CPU 사용률(%)',
	'High usage Peak criterion' => '고사용 Peak기준',
	'% or less' => '% 이하',
	'% or more' => '% 이상',
	'Enter numbers between %1$d and %2$d only.' => '%1$d~%2$d 사이의 숫자만 입력해 주세요.',
	'All fields are required.' => '모든 항목은 필수입력값입니다.',
	'Set' => '설정',
	'Enter numbers between %1$d and %2$d in all fields.' => '모든 항목에 %1$d~%2$d 사이의 숫자를 입력해 주세요.',
	'The criteria have been saved.' => '기준을 저장했습니다.',
	'* High usage criteria: average usage %1$s%% or more / Peak %2$s%% or more' =>
		'※고사용 기준: 평균사용률 %1$s%% 이상 / Peak %2$s%% 이상',

	'CPU total cores' => 'CPU 전체 Core',
	'CPU allocated cores' => 'CPU 할당 Core',
	'Total memory' => '전체 메모리',
	'Allocated memory' => '할당 메모리',
	'Total disk' => '전체 Disk',
	'Allocated disk' => '할당 Disk',
	'Latest data' => '최근 데이터',

	// ── 성능 데이터 추세 분석 ──
	'Data set' => '데이터 셋',
	'Host pattern' => '호스트 패턴',
	'Item pattern' => '아이템 패턴',
	'host pattern' => '호스트 패턴',
	'item pattern' => '아이템 패턴',
	'Graph type' => '그래프 형식',
	'Line' => '선',
	'Points' => '포인트',
	'Staircase' => '계단',
	'Bar' => '막대 그래프',
	'Add new data set' => '+ 새로운 데이터 셋을 추가',
	'Separate several patterns with commas. * matches any text. Example: web-*, db-01' =>
		'여러 개는 쉼표로 나눕니다. * 는 아무 글자를 뜻합니다. 예) web-*, db-01',
	'Enter a host pattern and an item pattern in the data set and press Apply.' =>
		'데이터 셋에 호스트 패턴과 아이템 패턴을 넣고 [적용] 을 누르세요.',
	'No items match the data sets.' => '데이터 셋에 맞는 아이템이 없습니다.',
	'Collected at' => '수집시간',
	'Value' => '값(value)',
	'Aggregated at' => '집계시간',
	'Min' => '최소',
	'Avg' => '평균',
	'Max' => '최대',

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
	'* A database error occurred. The file is incomplete.' => '※ DB 조회 오류로 파일이 끝까지 만들어지지 않았습니다.',
	'* Stopped at the row limit (%1$s rows). The file is incomplete.' => '※ 줄 수 한도(%1$s줄)에 닿아 여기서 멈췄습니다. 파일이 끝까지 만들어지지 않았습니다.',
	'Preview: the first %1$d rows. Download the file to get all rows.' => '미리보기: 앞에서 %1$d줄만 표시합니다. 전체는 파일로 내려받으세요.',
	'There are no CPU/Memory items for the selected hosts.' => '선택한 호스트에 CPU/Memory 아이템이 없습니다.',
	'Values before %1$s may have been deleted because the trend storage period has passed. The average covers only the remaining period.' =>
		'%1$s 이전 값은 요약 데이터 보관 기간이 지나 지워졌을 수 있습니다. 평균은 남아 있는 기간으로만 계산됩니다.',
	'Values before %1$s may have been deleted because the history storage period has passed. The file contains only the remaining values.' =>
		'%1$s 이전 값은 원본 데이터 보관 기간이 지나 지워졌을 수 있습니다. 파일에는 남아 있는 값만 들어갑니다.',
	'Enter the reason for Agent not installed or exception.' =>
		'Agent 미설치 및 예외 사유를 입력해 주세요',
	'The file is split every %1$s rows and downloaded as one ZIP file.' =>
		'%1$s 줄마다 파일을 나눠 ZIP 파일 하나로 받습니다.',
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
	'The audit log is kept for less than %1$d days, so recently created accounts that never logged in may also be listed. Set the audit log storage period to at least %1$d days.' =>
		'감사 로그 보관 기간이 %1$d일보다 짧아, 최근에 만들고 아직 접속하지 않은 계정도 목록에 나올 수 있습니다. 관리 > 감사 로그에서 보관 기간을 %1$d일 이상으로 늘리세요.',
	'Sessions are kept for less than %1$d days, so accounts that logged in recently may also be listed. Set the session storage period to at least %1$d days.' =>
		'세션 보관 기간이 %1$d일보다 짧아, 최근에 접속한 계정도 목록에 나올 수 있습니다. 관리 > 하우스키핑 > 세션에서 보관 기간을 %1$d일 이상으로 늘리세요.',

	// ── 공통 오류 ──
	'Invalid date. Enter the date as YYYY-MM-DD.' => '날짜 형식이 올바르지 않습니다. YYYY-MM-DD 형식으로 입력하세요.',
];
