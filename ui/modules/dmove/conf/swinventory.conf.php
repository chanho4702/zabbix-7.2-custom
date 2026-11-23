<?php declare(strict_types = 0);
/*
 * SW 인벤토리 화면의 설정.
 *
 * 읽는 곳: includes/AgentStatusRepository.php
 *         (화면: zabbix.php?action=swinventory.list   메뉴: 인벤토리 > SW 인벤토리)
 *
 * ── 이 파일이 하는 일 ──
 *   Agent "미설치 / 예외" 상태를 저장해 두는 표가 어디에 있는지 알려 준다.
 *
 * ── Agent 상태 표란 ──
 *   5버전 SW 인벤토리는 Zabbix 에 등록되지 않은 ITSM 서버의 Agent 상태(미설치 / 예외)와 사유를
 *   사용자가 화면에서 입력하게 하고, 그 기록을 Zabbix DB 안의 별도 표 extends_agent 에 쌓았다.
 *   5버전 DB 를 7버전으로 올렸다면 이 표와 기존 기록도 그대로 남아 있다. 7버전도 같은 표를 읽고 쓴다.
 *
 *   표의 열 (5버전과 같다)
 *     hostid       줄의 키. ITSM 에만 있는 서버는 "itsm_" + ITSM id. 예) itsm_1001
 *     agent        상태 값. 2 = 미설치, 4 = 예외
 *     explanation  사유
 *     clock        입력한 시각 (유닉스 초)
 *     userid, username, useralias   입력한 사용자
 *   같은 hostid 의 줄이 여러 개면 가장 최근(clock 이 가장 큰) 줄이 지금 상태다.
 *
 *   표가 없으면 화면은 정상으로 뜨고, 그 서버들은 모두 "미설치"로 나온다. 입력 창에서 저장할 때만 안내가 나온다.
 *   표를 새로 만들어야 하면 sql/extends_agent.sql 을 실행한다. (5버전 코드의 표 정의와 같다)
 *   5버전은 표가 없으면 화면을 여는 도중에 표를 만들었다. 7버전은 자동으로 만들지 않는다.
 */

return [
	/*
	 * 표가 들어 있는 스키마 이름. 비워 두면 DB 접속 계정의 기본 스키마(search_path)에서 찾는다.
	 * 5버전은 스키마 이름 없이 extends_agent 로 읽었으므로 보통은 비워 두면 된다.
	 * 영문자, 숫자, 밑줄(_)만 쓸 수 있다.
	 */
	'agent_table_schema' => '',

	// 표 이름. (5버전: extends_agent)
	'agent_table' => 'extends_agent'
];
