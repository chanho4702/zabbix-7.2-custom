<?php declare(strict_types = 0);
/*
 * SW 인벤토리의 Agent "미설치 / 예외" 상태 표(extends_agent)를 읽고 쓴다.
 *
 * ── 이 파일이 하는 일 ──
 *   Zabbix 에 등록되지 않은 ITSM 서버는 사용자가 상태(미설치 / 예외)와 사유를 직접 입력한다.
 *   그 기록을 별도 표에 쌓아 두고, 화면을 열 때 서버별 "가장 최근 기록"을 읽어 상태로 쓴다.
 *   표의 위치와 열 설명은 conf/swinventory.conf.php 에 있다.
 *
 * ── 누가 쓰는가 ──
 *   includes/SwInventoryService.php                  목록을 만들 때 latestByKeys()
 *   actions/inventory/swinventory/AgentEdit.php      입력 창을 열 때 history()
 *   actions/inventory/swinventory/AgentSave.php      [저장] 을 눌렀을 때 insert()
 *
 * ── 5버전 대응 ──
 *   Agent 판정 클래스(...HostAgent.php)
 *     _hostAgent_DB()      hostid 로 최신 줄 1개를 읽어 상태를 정했다. (서버마다 쿼리 1번)
 *     _hostAgent_select()  최근 5줄을 읽었다. (입력 창의 이력으로 보인다)
 *     _hostAgent_insert()  한 줄을 추가했다. 고치거나 지우지 않고 계속 쌓는다.
 *
 * ── 5버전과 달라진 점 ──
 *   - 서버마다 쿼리하지 않고, 목록에 있는 서버의 최신 상태를 한 번에 읽는다.
 *   - 값을 쿼리 글자에 그대로 이어 붙이지 않고 zbx_dbstr() 로 감싼다. (SQL 인젝션 방지)
 *   - 표가 없을 때 화면을 여는 도중에 표를 만들지 않는다. 대신 sql/extends_agent.sql 을 둔다.
 */

namespace Modules\DMove\Includes;

class AgentStatusRepository {

	/** 상태 값. 5버전 표에 저장된 값과 같다. 1(설치)은 이 모듈이 넣지 않지만 5버전 기록에 있을 수 있다. */
	public const STATUS_INSTALLED = 1;
	public const STATUS_NOT_INSTALLED = 2;
	public const STATUS_EXCEPTION = 4;

	/*
	 * "가장 최근 기록"을 고르는 순서. 목록(latestByKeys)과 입력 창(history)이 같은 순서를 쓴다.
	 * 표에 일련번호 열이 없어서 같은 초(clock)에 저장된 줄은 나머지 열로 순서를 정한다. (매번 같은 줄이 고르게 된다)
	 */
	private const LATEST_ORDER = 'clock DESC,agent DESC,userid DESC,explanation DESC';

	/** 설정 파일 위치. 모듈 폴더 기준. */
	private const CONFIG_FILE = '/conf/swinventory.conf.php';

	/** "스키마.표" 또는 "표". 쓸 수 없으면 null. 처음 한 번만 계산한다. */
	private ?string $table = null;
	private bool $resolved = false;

	/**
	 * 표를 쓸 수 있는가. (설정 이름이 올바르고 DB 에 표가 있다)
	 */
	public function isAvailable(): bool {
		return $this->tableName() !== null;
	}

	/**
	 * 여러 서버의 "가장 최근 기록"을 한 번에 읽는다.
	 *
	 * @param array $keys  줄의 키 목록. 예) ['itsm_1001', 'itsm_1002']
	 *
	 * @return array  [키 => ['agent' => 2, 'explanation' => '사유', 'clock' => 1795000000, 'username' => 'Admin']]
	 *                기록이 없는 키는 결과에 없다. 표를 쓸 수 없으면 빈 배열.
	 *
	 * 쿼리 설명:
	 *   안쪽 SELECT 가 키별 가장 큰 clock 을 구하고, 바깥이 그 clock 의 줄을 고른다.
	 *   같은 키, 같은 clock 의 줄이 둘 이상이면(같은 초에 두 번 저장) LATEST_ORDER 순서로 첫 줄을 남긴다.
	 *   입력 창의 history() 첫 줄과 같은 줄이다.
	 */
	public function latestByKeys(array $keys): array {
		$table = $this->tableName();

		if ($table === null || !$keys) {
			return [];
		}

		// zbx_dbstr(): 값을 따옴표로 감싸고 특수문자를 처리한다. array_map 으로 모든 키에 적용한다.
		$in = implode(',', array_map('zbx_dbstr', array_values($keys)));

		$result = DBselect(
			'SELECT hostid,agent,explanation,clock,username,useralias FROM '.$table.
			' WHERE hostid IN ('.$in.')'.
				' AND (hostid,clock) IN ('.
					'SELECT hostid,MAX(clock) FROM '.$table.' WHERE hostid IN ('.$in.') GROUP BY hostid'.
				')'.
			' ORDER BY hostid,'.self::LATEST_ORDER
		);

		$latest = [];

		while ($result !== false && $row = DBfetch($result)) {
			// 키마다 첫 줄만 남긴다.
			if (array_key_exists($row['hostid'], $latest)) {
				continue;
			}

			$latest[$row['hostid']] = [
				'agent' => (int) $row['agent'],
				'explanation' => (string) $row['explanation'],
				'clock' => (int) $row['clock'],
				'username' => self::enteredBy($row)
			];
		}

		return $latest;
	}

	/**
	 * 한 서버의 최근 기록. 입력 창 아래의 이력 표에 쓴다. (5버전: 최근 5줄)
	 *
	 * @param string $key    줄의 키. 예) 'itsm_1001'
	 * @param int    $limit  읽을 줄 수.
	 *
	 * @return array  최근 것이 먼저. [['agent' => 2, 'explanation' => .., 'clock' => .., 'username' => ..], ...]
	 */
	public function history(string $key, int $limit = 5): array {
		$table = $this->tableName();

		if ($table === null) {
			return [];
		}

		// DBselect(쿼리, N): 앞에서 N 줄만 읽는다.
		$result = DBselect(
			'SELECT agent,explanation,clock,username,useralias FROM '.$table.
			' WHERE hostid='.zbx_dbstr($key).
			' ORDER BY '.self::LATEST_ORDER,
			$limit
		);

		$rows = [];

		while ($result !== false && $row = DBfetch($result)) {
			$rows[] = [
				'agent' => (int) $row['agent'],
				'explanation' => (string) $row['explanation'],
				'clock' => (int) $row['clock'],
				'username' => self::enteredBy($row)
			];
		}

		return $rows;
	}

	/**
	 * 기록 한 줄을 추가한다. (5버전처럼 고치지 않고 쌓는다. 가장 최근 줄이 지금 상태다)
	 *
	 * @param string $key          줄의 키. 예) 'itsm_1001'  (20자 이하. 표의 열 크기)
	 * @param int    $agent        STATUS_NOT_INSTALLED(2) 또는 STATUS_EXCEPTION(4)
	 * @param string $explanation  사유. 2048자 이하.
	 * @param array  $user         ['userid' => .., 'username' => .., 'name' => ..]  입력한 사용자
	 *
	 * @return bool  저장했으면 true. 표를 쓸 수 없거나 실패하면 false.
	 */
	public function insert(string $key, int $agent, string $explanation, array $user): bool {
		$table = $this->tableName();

		if ($table === null) {
			return false;
		}

		/*
		 * 5버전 열 구성 그대로 넣는다.
		 *   username  : 5버전은 사용자 이름(이름)을, useralias 에는 로그인 이름을 넣은 것으로 보인다.
		 *               7버전 사용자 정보에는 로그인 이름(username)과 이름(name)이 있어 같은 뜻으로 넣는다.
		 */
		$sql = 'INSERT INTO '.$table.' (hostid,agent,explanation,clock,userid,username,useralias) VALUES ('.
			zbx_dbstr($key).','.
			(int) $agent.','.
			zbx_dbstr($explanation).','.
			time().','.
			(int) $user['userid'].','.
			zbx_dbstr((string) $user['name']).','.
			zbx_dbstr((string) $user['username']).
		')';

		// DBexecute(): 값을 바꾸는 쿼리를 실행한다. 실패하면 false.
		return (bool) DBexecute($sql);
	}

	/**
	 * 화면에 보일 입력자 이름. 로그인 이름(useralias)이 있으면 그것을, 없으면 이름(username)을 쓴다.
	 * (5버전 표는 username 에 사용자 이름, useralias 에 로그인 이름을 넣은 것으로 보인다)
	 */
	private static function enteredBy(array $row): string {
		$alias = trim((string) ($row['useralias'] ?? ''));

		return $alias !== '' ? $alias : (string) $row['username'];
	}

	/**
	 * 설정을 읽어 표 이름("스키마.표" 또는 "표")을 만든다. 쓸 수 없으면 null.
	 *
	 * 표 이름은 쿼리에 값으로 넣을 수 없어 글자로 이어 붙인다. 그래서 영문자/숫자/밑줄만 허용하고,
	 * 실제로 있는 표인지 information_schema 로 확인한 뒤에만 쓴다.
	 */
	private function tableName(): ?string {
		if ($this->resolved) {
			return $this->table;
		}

		$this->resolved = true;

		$file = dirname(__DIR__).self::CONFIG_FILE;
		$config = is_readable($file) ? require $file : [];
		$config = is_array($config) ? $config : [];

		$schema = trim((string) ($config['agent_table_schema'] ?? ''));
		$table = trim((string) ($config['agent_table'] ?? 'extends_agent'));
		$pattern = '/^[A-Za-z_][A-Za-z0-9_]*$/';

		if (preg_match($pattern, $table) !== 1 || ($schema !== '' && preg_match($pattern, $schema) !== 1)) {
			return null;
		}

		/*
		 * 스키마를 비워 두면 DB 접속 계정의 기본 스키마에서 찾는다.
		 *   PostgreSQL : current_schema()      MySQL : DATABASE()
		 */
		global $DB;
		$default_schema = ($DB['TYPE'] ?? '') === ZBX_DB_POSTGRESQL ? 'current_schema()' : 'DATABASE()';
		$schema_condition = 'table_schema='.($schema !== '' ? zbx_dbstr($schema) : $default_schema);

		$result = DBselect(
			'SELECT 1 FROM information_schema.tables'.
			' WHERE '.$schema_condition.
				' AND table_name='.zbx_dbstr($table)
		);

		if ($result === false || DBfetch($result) === false) {
			return null;
		}

		$this->table = $schema !== '' ? $schema.'.'.$table : $table;

		return $this->table;
	}
}
