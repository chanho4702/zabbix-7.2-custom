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
	 *   'agent_exception' => true           : Agent 설치 "예외" 대상으로 표시한다.
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

		while ($row = DBfetch($result)) {
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * ITSM 의 호스트명 => Description 표. (저사용/고사용 서버 화면 등, Description 열에 ITSM 값을 쓰는 화면용)
	 *
	 * 5버전 대응: ITSM 트레잇의 itsm_description()
	 *   5버전은 호스트마다 쿼리를 한 번씩 실행했다. 여기서는 한 번에 읽어 이름으로 찾는다.
	 *
	 * @return array  [소문자로 바꾼 ci_name => description]  ITSM 을 쓸 수 없으면 빈 배열.
	 *
	 * 사용 예:
	 *   $itsm_descriptions = (new ItsmRepository())->fetchDescriptions();
	 *   $description = $itsm_descriptions[mb_strtolower($host['name'])] ?? $host['description'];
	 */
	public function fetchDescriptions(): array {
		$descriptions = [];

		// ?? [] : fetch() 가 null(ITSM 미연동)을 돌려주면 빈 배열로 바꿔 반복문을 건너뛴다.
		foreach ($this->fetch() ?? [] as $row) {
			$name = mb_strtolower(trim((string) ($row['ci_name'] ?? '')));

			if ($name !== '') {
				$descriptions[$name] = (string) ($row['description'] ?? '');
			}
		}

		return $descriptions;
	}

	/**
	 * 설정을 읽어 "스키마.테이블" 문자열을 만든다. 쓸 수 없는 상태면 null.
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
		 */
		$pattern = '/^[A-Za-z_][A-Za-z0-9_]*$/';

		if (preg_match($pattern, $schema) !== 1 || preg_match($pattern, $table) !== 1) {
			return null;
		}

		if (!$this->tableExists($schema, $table)) {
			return null;
		}

		return $schema.'.'.$table;
	}

	/**
	 * 테이블(또는 뷰)이 실제로 있는지 확인한다. 없는 테이블을 조회하면 화면에 DB 오류가 뜨기 때문이다.
	 *
	 * information_schema.tables 는 PostgreSQL, MySQL 모두에 있는 표준 목록이다.
	 * zbx_dbstr() 은 값을 따옴표로 감싸고 특수문자를 처리해 준다. (SQL 인젝션 방지)
	 */
	private function tableExists(string $schema, string $table): bool {
		$result = DBselect(
			'SELECT 1'.
			' FROM information_schema.tables'.
			' WHERE table_schema='.zbx_dbstr($schema).
				' AND table_name='.zbx_dbstr($table)
		);

		return $result !== false && DBfetch($result) !== false;
	}
}
