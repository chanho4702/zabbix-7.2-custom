<?php declare(strict_types = 0);
/*
 * 사이드바 아래쪽 메뉴(지원, 도움말, 사용자 설정이 있는 곳)에 넣을 링크.
 *
 * 읽는 곳: Module.php 의 addSidebarLinks()
 *
 * ── 5버전 ──
 *   5버전 menu.inc.php 의 _getUserMenu() 가 사이드바 아래쪽 메뉴를 이렇게 바꿨다.
 *     - 코어의 "Support", "Share" 를 빼고 그 자리에 "Agent 설치파일" (/zabbix/agent/agent.zip)
 *     - 코어의 "Help" 대신 "사용자매뉴얼" (/zabbix/user_manual.zip)
 *   7.0 의 같은 메뉴에는 Support, Integrations(5.0 의 Share 에 해당), Help 가 있다.
 *
 * ── 파일 두는 곳 ──
 *   url 은 Zabbix 웹 폴더(zabbix.php 가 있는 폴더) 기준의 상대 주소다.
 *     'agent/agent.zip'  ->  <웹 폴더>/agent/agent.zip
 *     'user_manual.zip'  ->  <웹 폴더>/user_manual.zip
 *   5버전 서버의 같은 파일을 7.0 웹 폴더의 같은 자리에 복사한다. 파일이 없으면 누르면 404 가 난다.
 *   (웹 폴더 예: 패키지 설치면 보통 /usr/share/zabbix. 고객 서버에서 zabbix.php 위치로 확인한다)
 */

return [
	/*
	 * 넣을 링크. 위에서부터 순서대로 "사용자 설정" 앞에 놓인다.
	 *   label : 메뉴 이름
	 *   url   : 웹 폴더 기준 상대 주소, 또는 http(s):// 로 시작하는 전체 주소
	 *   icon  : 코어 아이콘 이름 (include/defines.inc.php 의 ZBX_ICON_...). 비우면 아이콘 없음
	 */
	'links' => [
		['label' => 'Agent 설치파일', 'url' => 'agent/agent.zip', 'icon' => 'ZBX_ICON_SUPPORT'],
		['label' => '사용자매뉴얼', 'url' => 'user_manual.zip', 'icon' => 'ZBX_ICON_HELP_CIRCLED']
	],

	/*
	 * 뺄 코어 메뉴 (영문 라벨). 5버전처럼 Support, Integrations(Share), Help 를 뺀다.
	 * 코어 메뉴를 그대로 두려면 [] 로 둔다.
	 */
	'remove_core' => ['Support', 'Integrations', 'Help']
];
