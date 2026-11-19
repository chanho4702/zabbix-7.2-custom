<?php declare(strict_types = 0);
/*
 * ITSM 테이블 위치 설정. (SW 인벤토리 화면이 사용한다. 읽는 곳: includes/ItsmRepository.php)
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ TODO(고객환경) ★ 고객 서버에 올린 뒤 아래 두 값을 채운다 ★              │
 * │                                                                         │
 * │ 이 저장소는 공개 저장소라서 실제 값은 비워 두었다.                      │
 * │ 값이 비어 있으면 "ITSM 미연동" 상태로 동작한다.                         │
 * │ (화면은 Zabbix 호스트 목록 기준으로 나오고 상단에 경고가 표시된다)      │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * 5버전에서 값이 있던 곳
 *   커스텀 폴더의 controllers/ 아래 "...Itms.php" 트레잇 맨 위 두 줄
 *       private $itsmSchema = "....";     <- 아래 'schema' 에 넣는다
 *       private $itsmTable  = "....";     <- 아래 'table' 에 넣는다
 *
 * 서버에서 직접 확인하는 방법 (PostgreSQL)
 *   psql -U <DB계정> <DB이름> -c "\dt *.itsm_info"
 *   -> 결과의 Schema 열이 'schema' 값, Name 열이 'table' 값이다.
 *
 * 이 테이블에서 읽는 컬럼: id, ci_name, ip_address, description  (5버전과 동일)
 * Zabbix 가 접속하는 DB 계정에 이 테이블의 SELECT 권한이 있어야 한다.
 *
 * 값에는 영문자, 숫자, 밑줄(_)만 쓸 수 있다. 그 밖의 글자가 있으면 무시하고 미연동으로 처리한다.
 * 7버전 DB 로 옮기면서 스키마를 따로 두지 않았다면 'schema' 에는 'public' 을 넣는다.
 */

return [
	'schema' => '',
	'table' => ''
];
