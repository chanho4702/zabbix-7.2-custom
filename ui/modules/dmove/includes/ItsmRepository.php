<?php declare(strict_types = 0);
/*
 * ITSM 서버 목록 조회.
 *
 * 5버전 대응: ITSM 트레잇(...Itms.php)의 itsm_hostList(), isTable()
 *
 * 어디서 읽는가
 *   Zabbix 와 같은 DB 안에 있는 ITSM 테이블(5버전 기준: <스키마>.itsm_info).
 *   스키마/테이블 이름은 conf/itsm.conf.php 에서 읽는다. 값이 비어 있으면 "ITSM 미연동"으로 동작한다.
 *
 * 5버전과 달라진 점
 *   - 스키마/테이블 이름을 소스에 직접 쓰지 않고 설정 파일로 뺐다.
 *   - 테이블이 없거나 조회에 실패해도 PHP 경고가 나지 않게 했다.
 *     (5버전 isTable() 은 조회가 실패하면 false['exists'] 를 읽어서 PHP 8 에서 경고가 난다)
 *   - 이름은 정해진 글자만 허용하고, 값 비교는 zbx_dbstr() 로 감싸 SQL 인젝션을 막았다.
 *
 * ── 누가 쓰는가 ──
 *   fetch()             includes/SwInventoryService.php (SW 인벤토리 화면의 행 기준 목록)
 *   fetchDescriptions() Description 열에 ITSM 설명을 쓰는 화면:
 *                       actions/reports/uptime/UptimeList.php, actions/reports/serverusage/ServerUsageController.php,
 *                       actions/reports/dailycheck/DailyCheckTop.php, includes/WinPatchService.php
 *   idExists()          actions/inventory/swinventory/AgentSave.php, AgentEdit.php (줄의 키 확인)
 *
 * ── 데이터 흐름 ──
 *   fetch()
 *     ├─ getQualifiedTableName()
 *     │    ├─ conf/itsm.conf.php 를 읽는다 (schema, table)
 *     │    ├─ 이름에 허용된 글자만 있는지 검사한다
 *     │    └─ tableExists() 로 그 테이블이 정말 있는지 확인한다
 *     └─ 테이블을 읽어 행 배열로 돌려준다 (쓸 수 없으면 null)
 *
 * ── 관련 파일 ──
 *   conf/itsm.conf.php   ITSM 테이블 위치 설정
 */

namespace Modules\DMove\Includes;

class ItsmRepository {

	/** 설정 파일 위치. __DIR__ 는 이 파일이 있는 폴더(includes), dirname(__DIR__) 은 모듈 폴더다. */
	private const CONFIG_FILE = '/conf/itsm.conf.php';

	/**
	 * ITSM 서버 목록을 돌려준다.
	 *
	 * 반환 형식 (행 1개 = ITSM 서버 1대). 키 이름은 5버전과 동일하다.
	 *
	 *   [
	 *     [
	 *       'id'          => '1001',          // ITSM 쪽 고유 ID
	 *       'ci_name'     => 'web-server-01', // 호스트명. Zabbix hosts.host 와 대소문자 무시하고 비교한다.
	 *       'ip_address'  => '10.0.0.1',
	 *       'description' => '...'
	 *     ],
	 *     ...
	 *   ]
	 *
	 * 행에 아래 키를 더 넣으면 SwInventoryService / SoftwareResolver 가 알아서 사용한다. (지금은 넣지 않는다)
	 *   'os', 'web', 'was', 'db', 'duplex'  : 넣으면 Zabbix 인벤토리 값보다 우선한다.
	 *
	 * @return array|null  ITSM 을 쓸 수 없으면(설정이 비었거나 테이블이 없으면) null.
	 */
	public function fetch(): ?array {
		$table = $this->getQualifiedTableName();

		if ($table === null) {
			return null;
		}

		/*
		 * DBselect / DBfetch 는 Zabbix 코어 함수이며 Zabbix 의 DB 연결을 그대로 쓴다. (ui/include/db.inc.php)
		 * DBselect 는 실패하면 false 를 돌려준다. 그대로 DBfetch 에 넘기면 경고가 나므로 먼저 확인한다.
		 */
		$result = DBselect('SELECT id,ci_name,ip_address,description FROM '.$table);

		if ($result === false) {
			return null;
		}

		$rows = [];

		/*
		 * DBfetch(커서, false): NULL 값을 0 으로 바꾸지 않고 null 그대로 받는다.
		 * (기본값 true 면 NULL 인 id 가 '0' 이 되어 키가 'itsm_0' 으로 합쳐지고, 빈 설명이 '0' 으로 보인다)
		 */
		while ($row = DBfetch($result, false)) {
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * ITSM 의 호스트명 => Description 표. (저사용/고사용 서버 화면 등, Description 열에 ITSM 값을 쓰는 화면용)
	 *
	 * 5버전 대응: ITSM 트레잇의 itsm_description()  (...Itms.php 44-75행)
	 *   5버전은 호스트마다 "ci_name ilike <호스트 표시 이름>" 쿼리를 한 번 실행하고 첫 줄의 description 을 썼다.
	 *   여기서는 한 번에 읽어 이름으로 찾는다. 찾는 이름은 호스트 표시 이름(name) 하나뿐이다. (5버전과 같다)
	 *   - ci_name 은 앞뒤 빈칸을 떼지 않고 그대로 비교한다. (대소문자만 무시한다)
	 *   - 같은 ci_name 이 여러 줄이면 먼저 읽은 줄을 쓴다. (5버전의 "첫 줄")
	 *   - description 이 비어 있어도 그 값(빈 문자열)을 그대로 돌려준다. Zabbix 호스트 설명으로 넘어가지 않는다.
	 *
	 * @return array|null  [소문자로 바꾼 ci_name => description(문자열, 빈 값일 수 있다)]
	 *                     ITSM 표를 읽을 수 없으면(설정이 비었다 / 표가 없다 / 조회 실패) null.
	 *                     null 이면 5버전의 "ITSM 테이블이 없습니다", 키가 없으면 "ITSM 데이터가 없습니다" 에 해당한다.
	 *
	 * 사용 예:
	 *   $itsm_descriptions = (new ItsmRepository())->fetchDescriptions();
	 *   $description = $itsm_descriptions[mb_strtolower($host['name'])] ?? '';
	 */
	public function fetchDescriptions(): ?array {
		$rows = $this->fetch();

		if ($rows === null) {
			return null;
		}

		$descriptions = [];

		foreach ($rows as $row) {
			$name = mb_strtolower((string) ($row['ci_name'] ?? ''));

			// 먼저 읽은 줄을 남긴다. (5버전은 쿼리 결과의 첫 줄을 썼다)
			if (!array_key_exists($name, $descriptions)) {
				$descriptions[$name] = (string) ($row['description'] ?? '');
			}
		}

		return $descriptions;
	}

	/**
	 * ITSM 에 그 id 의 줄이 있는가. (SW 인벤토리 Agent 상태 저장 전 확인용)
	 *
	 * id 는 앞뒤 빈칸을 떼지 않고 그대로 비교한다. (줄의 키 'itsm_' + id 도 그대로 만든다)
	 *
	 * @param string $id  ITSM id. 예) '1001'
	 *
	 * @return bool  있으면 true. ITSM 을 쓸 수 없거나 조회에 실패해도 false.
	 */
	public function idExists(string $id): bool {
		$table = $this->getQualifiedTableName();

		if ($table === null) {
			return false;
		}

		$result = DBselect('SELECT 1 FROM '.$table.' WHERE id='.zbx_dbstr($id), 1);

		return $result !== false && DBfetch($result) !== false;
	}

	/**
	 * 설정을 읽어 "스키마.테이블" 문자열을 만든다. 쓸 수 없는 상태면 null.
	 *
	 * null 이 되는 경우: 설정 파일이 없다 / 값이 비었다 / 이름에 허용되지 않은 글자가 있다 / 그 테이블이 DB 에 없다.
	 * 어느 경우든 오류를 내지 않고 "ITSM 미연동"으로 처리한다.
	 *
	 * @return string|null  쿼리의 FROM 뒤에 그대로 붙일 수 있는 이름.
	 *                      예) PostgreSQL 이면 "스키마"."테이블", MySQL 이면 `스키마`.`테이블`
	 */
	private function getQualifiedTableName(): ?string {
		$file = dirname(__DIR__).self::CONFIG_FILE;

		if (!is_readable($file)) {
			return null;
		}

		// 설정 파일은 "return [ ... ];" 형태다. require 가 그 배열을 그대로 돌려준다.
		$config = require $file;

		$schema = is_array($config) ? trim((string) ($config['schema'] ?? '')) : '';
		$table = is_array($config) ? trim((string) ($config['table'] ?? '')) : '';

		/*
		 * 테이블 이름은 쿼리에 값(?)으로 넣을 수 없고 문자열로 이어 붙여야 한다.
		 * 그래서 영문자/숫자/밑줄로만 된 이름인지 여기서 검사한다. (그 밖의 글자가 있으면 사용하지 않는다)
		 *
		 * 정규식 읽는 법: ^ 처음, [A-Za-z_] 영문자나 밑줄 한 글자, [A-Za-z0-9_]* 영문자/숫자/밑줄이 0개 이상, $ 끝.
		 * 빈 문자열도 여기서 걸러진다. preg_match 는 맞으면 1, 안 맞으면 0 을 돌려준다.
		 */
		$pattern = '/^[A-Za-z_][A-Za-z0-9_]*$/';

		if (preg_match($pattern, $schema) !== 1 || preg_match($pattern, $table) !== 1) {
			return null;
		}

		if (!$this->tableExists($schema, $table)) {
			return null;
		}

		/*
		 * 이름을 따옴표로 감싸서 설정에 적은 글자 그대로(대소문자 포함) 찾게 한다.
		 * 따옴표가 없으면 PostgreSQL 은 이름을 소문자로 바꿔서 찾는다. 그러면 위 tableExists() 는 통과했는데
		 * 실제 조회에서는 테이블을 못 찾는 경우가 생긴다. (예: 대문자로 만든 테이블)
		 * 따옴표 글자는 DB 종류마다 다르다. PostgreSQL 은 ", MySQL 은 ` 이다.
		 */
		// global $DB : 코어가 DB 접속 정보를 담아 둔 전역 변수를 이 메소드 안에서 쓰겠다는 선언. $DB['TYPE'] 이 DB 종류다.
		global $DB;
		$quote = ($DB['TYPE'] ?? '') === ZBX_DB_POSTGRESQL ? '"' : '`';

		return $quote.$schema.$quote.'.'.$quote.$table.$quote;
	}

	/**
	 * 테이블(또는 뷰)이 실제로 있는지 확인한다. 없는 테이블을 조회하면 화면에 DB 오류가 뜨기 때문이다.
	 *
	 * information_schema.tables 는 PostgreSQL, MySQL 모두에 있는 표준 목록이다.
	 * zbx_dbstr() 은 값을 따옴표로 감싸고 특수문자를 처리해 준다. (SQL 인젝션 방지)
	 *
	 * @param string $schema  스키마 이름. (설정의 schema)
	 * @param string $table   테이블 이름. (설정의 table)
	 *
	 * @return bool  목록에 한 줄이라도 있으면 true. 조회가 실패해도 false.
	 */
	private function tableExists(string $schema, string $table): bool {
		$result = DBselect(
			'SELECT 1'.
			' FROM information_schema.tables'.
			' WHERE table_schema='.zbx_dbstr($schema).
				' AND table_name='.zbx_dbstr($table)
		);

		// DBfetch 는 읽을 줄이 없으면 false 를 돌려준다. 한 줄이라도 있으면 테이블이 있는 것이다.
		return $result !== false && DBfetch($result) !== false;
	}
}
