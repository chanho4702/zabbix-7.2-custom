<?php declare(strict_types = 0);
/*
 * ITSM 테이블 위치 설정. (SW 인벤토리 화면이 사용한다. 읽는 곳: includes/ItsmRepository.php)
 *
 * ── 이 파일이 하는 일 ──
 *   ITSM 서버 목록이 들어 있는 DB 테이블이 어디에 있는지(스키마 이름, 테이블 이름)를 알려 준다.
 *   실행되는 코드는 없다. "return [ ... ];" 로 배열 하나를 돌려주기만 한다.
 *
 * ── 값이 쓰이는 흐름 ──
 *   ItsmRepository 가 require 로 이 파일을 읽는다
 *     ├─ 두 값이 모두 있고, 그 테이블이 실제로 있으면 -> "스키마.테이블" 을 조회한다
 *     └─ 값이 비었거나, 허용하지 않는 글자가 있거나, 테이블이 없으면 -> ITSM 미연동으로 처리한다
 *
 * ── 이 설정의 영향을 받는 화면 ──
 *   SW 인벤토리                 ITSM 서버 목록이 표의 기준이 된다. 미연동이면 Zabbix 호스트 목록이 기준이 된다.
 *   Description 열이 있는 보고서  ITSM 의 설명을 먼저 쓴다. 미연동이면 Zabbix 호스트 설명을 쓴다.
 *   (서버 시간 확인, 저사용/고사용 서버 사용현황, 일일점검의 Top 10 창, 윈도우즈 패치 현황)
 *
 * ── 값의 출처 (2026-11-23 채움) ──
 *   5버전 ITSM 트레잇(...Itms.php) 맨 위 두 줄의 스키마 이름과 테이블 이름.
 *
 *   TODO(고객환경): 스키마 이름은 5버전 소스에서 글자가 확실하지 않았다. 7버전 DB 에서 확인한다. (아래 설명)
 *   이름이 틀리거나 테이블이 없으면 "ITSM 미연동" 상태로 동작한다.
 *   (화면은 Zabbix 호스트 목록 기준으로 나오고 상단에 경고가 표시된다. 오류는 나지 않는다)
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
	// 테이블이 들어 있는 스키마 이름. 비워 두면 ITSM 미연동.
	// TODO(고객환경): 5버전 소스에서 'eaonl' 로 읽었지만 글자가 확실하지 않다. 7버전 DB 에서 실제 스키마 이름을 확인한다.
	//   확인 쿼리: SELECT table_schema FROM information_schema.tables WHERE table_name = 'itsm_info';
	//   이름이 틀리면 테이블을 찾지 못해 "ITSM 미연동"으로 동작한다. (오류는 나지 않는다)
	'schema' => 'eaonl',
	// 테이블 이름. 비워 두면 ITSM 미연동.
	'table' => 'itsm_info'
];
