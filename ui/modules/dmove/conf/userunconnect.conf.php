<?php declare(strict_types = 0);
/*
 * 장기 미접속 계정 현황 화면의 설정.
 *
 * 읽는 곳: actions/users/userunconnect/UserUnconnectList.php  (loadConfig() 메소드)
 *         (화면: zabbix.php?action=userunconnect.list   메뉴: 유저 > 장기 미접속 계정 현황)
 *
 * ── 이 파일의 형식 ──
 *   실행되는 코드는 없다. "return [ ... ];" 로 배열 하나를 돌려주기만 한다.
 *   컨트롤러가 화면을 열 때마다 require 로 읽으므로, 값을 고치면 다음 화면 조회부터 반영된다.
 *   이 파일이 없거나 어떤 값이 빠져 있으면 컨트롤러가 아래와 같은 기본값(60, 'exclude_recent', [1])을 쓴다.
 *
 * ── 값이 쓰이는 흐름 ──
 *   컨트롤러가 이 값으로 DB 조회 조건을 만든다.
 *     inactive_days            "최근 N일 안에 접속 기록이 없는 계정" 의 N
 *     created_check            방금 만든 계정을 목록에서 빼는 방법 (required / exclude_recent / none)
 *     exclude_userids          여기 적은 ID 의 계정은 결과에서 뺀다
 *
 * 미접속 기준 일수와 제외 계정은 5버전 소스(CControllerUserunconnect.php)의 값 그대로다.
 * created_check 만 5버전과 다르게 정했다. (2026-11-23 결정, 아래 설명)
 * 고객 정책이 바뀌면 이 파일의 값만 고치면 된다.
 */

return [
	/*
	 * 며칠 동안 접속하지 않으면 "장기 미접속"으로 보는가.
	 * 5버전: 60일 (쿼리에 interval '60 day' 로 적혀 있었다)
	 * 1 이상의 정수를 적는다. 0 이나 음수를 적으면 컨트롤러가 1 로 본다.
	 */
	'inactive_days' => 60,

	/*
	 * 방금 만든 계정(아직 한 번도 로그인하지 않은 새 계정)을 목록에서 빼는 방법.
	 *
	 * Zabbix 에는 "계정을 만든 날짜"를 적어 두는 칸이 없다. 그래서 감사 로그(auditlog)에 남는
	 * "유저 추가" 기록의 시각을 만든 날짜로 쓴다. 아래 세 값 중 하나를 적는다.
	 *
	 *   'required'        (5버전과 같다)
	 *       "inactive_days 일보다 전에 만든 기록이 있는 계정만" 보여 준다.
	 *       기록이 없는 계정은 아무리 오래 접속하지 않아도 목록에서 빠진다.
	 *
	 *   'exclude_recent'  (지금 값. 2026-11-23 결정)
	 *       "최근 inactive_days 일 안에 만든 기록이 있는 계정만" 뺀다.
	 *       기록이 없는 계정은 오래된 계정으로 보고 보여 준다.
	 *       기록이 모두 남아 있는 환경에서는 'required' 와 결과가 같다.
	 *
	 *   'none'
	 *       만든 날짜를 따지지 않는다. 방금 만든 계정도 접속 기록이 없으면 목록에 나온다.
	 *
	 * ┌──────────────────────────────────────────────────────────────────────────────┐
	 * │ 왜 5버전과 다르게 'exclude_recent' 로 정했는가, 고객 서버에서 확인할 것
	 * │
	 * │ 왜 확인하는가
	 * │   'required' 는 감사 로그에 유저 추가 기록이 남아 있어야 계정이 목록에 나온다.
	 * │   기록이 없어지는 경우가 두 가지 있다.
	 * │     - 감사 로그 보관 기간이 지나 지워진 경우 (5버전에서도 해당)
	 * │     - 5버전 DB 를 올린 경우. Zabbix 6.0 단계에서 감사 로그 표가 새로 바뀌면서
	 * │       예전 기록은 넘어오지 않는다. 5 에서 7 로 바로 올려도 마찬가지다.
	 * │         (Zabbix 공식 문서 "Upgrade notes for 6.0.0")
	 * │
	 * │ 확인 1) 가장 오래된 유저 추가 기록은 언제인가
	 * │     SELECT to_timestamp(MIN(clock)) FROM auditlog
	 * │      WHERE action = 0 AND resourcetype = 0;
	 * │
	 * │ 확인 2) 추가 기록이 없는 계정은 몇 개인가
	 * │     SELECT COUNT(*) FROM users u
	 * │      WHERE NOT EXISTS (SELECT 1 FROM auditlog a
	 * │                         WHERE a.action = 0 AND a.resourcetype = 0
	 * │                           AND a.resourcename = u.username);
	 * │
	 * │ 확인 3) 감사 로그를 며칠 보관하는가 (hk_audit_mode 1 = 지움, 0 = 지우지 않음)
	 * │     SELECT hk_audit_mode, hk_audit FROM config;
	 * │     화면에서는 관리 > 감사 로그.
	 * │
	 * │ 결과에 따라 할 일
	 * │   가) 확인 2 가 0 이고, 확인 3 이 "지우지 않음"
	 * │       -> 'required' 와 'exclude_recent' 의 결과가 같다. 어느 쪽이든 문제없다.
	 * │   나) 확인 2 가 0 이고, 확인 3 의 보관 기간이 inactive_days 보다 길다
	 * │       -> 지금은 문제없다. 하지만 보관 기간보다 오래된 계정은 나중에 기록이
	 * │          지워져 목록에서 빠진다. 'exclude_recent' 로 바꾸는 것이 안전하다.
	 * │   다) 확인 2 가 1 이상 (확인 1 의 날짜가 버전을 올린 날 이후면 보통 이 경우다)
	 * │       -> 기록이 없는 계정이 목록에서 빠지고 있다. 'exclude_recent' 로 바꾼다.
	 * │   라) 확인 3 의 보관 기간이 inactive_days 보다 짧다 (화면에 노란 경고가 나온다)
	 * │       -> 'required' 는 목록이 항상 빈다. 'exclude_recent' 로 바꾸고,
	 * │          보관 기간도 inactive_days 이상으로 늘린다. 늘리지 않으면 만든 지
	 * │          (보관 기간 ~ inactive_days) 사이인 새 계정이 조금 일찍 목록에 나온다.
	 * │
	 * │ 어느 값이 이득인가
	 * │   'exclude_recent' 가 어느 경우에도 손해가 없다. 기록이 다 있으면 'required'
	 * │   와 같은 결과이고, 기록이 없으면 오래된 계정을 놓치지 않는다.
	 * │   그래서 'exclude_recent' 를 쓰기로 했다. 아래 확인은 결과를 검증하는 용도다.
	 * │   5버전과 똑같은 목록이 꼭 필요하면 'required' 로 되돌린다.
	 * └──────────────────────────────────────────────────────────────────────────────┘
	 */
	'created_check' => 'exclude_recent',

	/*
	 * 목록에서 항상 빼는 유저 ID.
	 * 5버전: 1 (기본 관리자 계정 Admin. 쿼리에 userid != 1 로 적혀 있었다)
	 * 여러 개를 빼려면 [1, 5, 9] 처럼 쉼표로 이어 적는다. [] 로 두면 아무도 빼지 않는다.
	 */
	'exclude_userids' => [1]
];
