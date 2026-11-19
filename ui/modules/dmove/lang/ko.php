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
	'Windows patch status' => '윈도우즈 패치 현황',
	'CPU/Memory usage export' => 'CPU/Memory 전체 사용률 추출',
	'Long-term inactive accounts' => '장기 미접속 계정 현황',

	// ── 공통 ──
	'This page is under construction.' => '이 화면은 아직 구현되지 않았습니다.',
	'Data retrieval is not implemented yet.' => '데이터 조회가 아직 구현되지 않았습니다.',
	'Export to Excel' => 'Excel 내보내기',

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

	// ── 장기 미접속 계정 현황 ──
	'User type' => '유저 종류',
	'Last access' => '최근접속일시'
];
