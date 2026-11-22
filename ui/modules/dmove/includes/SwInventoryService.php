<?php declare(strict_types = 0);
/*
 * SW 인벤토리 화면의 데이터 처리(조회 -> 매칭 -> 필터 -> 정렬).
 *
 * 5버전 대응: CControllerSwinventories::doAction() 의 데이터 부분 +
 *            C*Filter 트레잇(isFilter / filtering)
 *
 * 컨트롤러(actions/)에는 "요청/응답" 만 두고, 데이터 로직은 이 클래스에 둔다.
 * 화면(list)과 CSV 가 같은 로직을 쓰기 때문이다.
 */

namespace Modules\DMove\Includes;

/*
 * use 문: 네임스페이스가 없는 코어 클래스를 이 파일에서 짧은 이름으로 쓰겠다는 선언이다.
 * 네임스페이스가 있는 파일에서는 이 선언이 없으면 "Class Modules\DMove\Includes\API not found" 오류가 난다.
 * (함수와 상수는 선언 없이 그대로 쓸 수 있다. 예: mb_strtolower(), ZBX_SORT_UP)
 *
 *   API           Zabbix API 를 PHP 안에서 직접 호출하는 클래스. ui/include/classes/api/API.php
 *   CArrayHelper  배열 정렬 등 도우미.                        ui/include/classes/helpers/CArrayHelper.php
 */
use API;
use CArrayHelper;

class SwInventoryService {

	/*
	 * Agent 설치 상태. 5버전의 비트마스크 값(설치 1 / 미설치 2 / 예외 4)을 그대로 유지했다.
	 *
	 * 값이 1, 2, 4 인 이유: 2진수로 001, 010, 100 이라 서로 겹치지 않는다.
	 * 그래서 "설치 + 예외"를 체크하면 1 | 4 = 5 (101) 하나의 숫자로 표현할 수 있고,
	 * 행의 상태가 거기에 포함되는지는 (상태 & 5) != 0 으로 한 번에 검사한다. (아래 filter() 참고)
	 */
	public const AGENT_INSTALLED = 1;
	public const AGENT_NOT_INSTALLED = 2;
	public const AGENT_EXCEPTION = 4;

	/**
	 * 텍스트 필터 키 => 비교할 행 컬럼. (5버전: filter_itms -> name, filter_os -> osname ...)
	 *
	 * 왼쪽 키에 'filter_' 를 붙인 것이 화면 입력창 이름이다. 예) 'os' -> 입력창 filter_os -> 행의 'os' 컬럼과 비교.
	 * 텍스트 필터를 하나 추가하려면 여기에 한 줄 넣고, 뷰의 $text_filter_labels 에 라벨을 넣으면 된다.
	 * (컨트롤러의 입력 검증/프로파일 저장은 이 상수를 돌면서 자동 처리한다.)
	 */
	public const TEXT_FILTERS = [
		'itsm' => 'host',
		'os' => 'os',
		'web' => 'web',
		'was' => 'was',
		'db' => 'db',
		'duplex' => 'duplex'
	];

	/** Agent 체크박스 필터 키 => 상태 값. */
	public const AGENT_FILTERS = [
		'agent_install' => self::AGENT_INSTALLED,
		'agent_uninstall' => self::AGENT_NOT_INSTALLED,
		'agent_exception' => self::AGENT_EXCEPTION
	];

	/** 정렬 가능한 컬럼. 컨트롤러의 입력 검증과 뷰의 헤더가 이 목록을 쓴다. */
	public const SORT_FIELDS = ['host', 'name', 'ip', 'description', 'os', 'web', 'was', 'db', 'duplex', 'agent'];

	/*
	 * 프로퍼티는 반드시 타입과 함께 선언한다.
	 * 선언 없이 $this->xxx = ... 로 쓰면(동적 프로퍼티) PHP 8.2 부터 Deprecated 경고가 난다.
	 */
	private ItsmRepository $itsm;
	private SoftwareResolver $software;

	/** ITSM 미연동이라 Zabbix 호스트 목록으로 대체했는지 여부. */
	private bool $itsm_fallback = false;

	/**
	 * 인자를 주지 않으면 기본 구현을 쓴다. 인자는 테스트할 때 가짜 ITSM 목록을 끼워 넣기 위한 것이다.
	 *
	 *   ?ItsmRepository  : 타입 앞의 ? 는 "null 도 허용" 이라는 뜻.
	 *   $a ?? $b         : $a 가 null 이면 $b 를 쓴다.
	 */
	public function __construct(?ItsmRepository $itsm = null, ?SoftwareResolver $software = null) {
		$this->itsm = $itsm ?? new ItsmRepository();
		$this->software = $software ?? new SoftwareResolver();
	}

	public function isItsmFallback(): bool {
		return $this->itsm_fallback;
	}

	public static function getAgentStatusLabels(): array {
		return [
			self::AGENT_INSTALLED => Lang::t('Installed'),
			self::AGENT_NOT_INSTALLED => Lang::t('Not installed'),
			self::AGENT_EXCEPTION => Lang::t('Exception')
		];
	}

	/**
	 * 필터/정렬 전의 전체 행 목록을 만든다.
	 *
	 * 행 형식:
	 *   rowid       화면용 고유키
	 *   hostid      매칭된 Zabbix hostid (매칭 안 되면 null)
	 *   has_inventory  Zabbix 호스트의 인벤토리 사용 여부 (상세 화면 링크 표시용)
	 *   host        호스트 (ITSM ci_name)
	 *   name        표시명 (Zabbix 표시 이름. 매칭 안 되면 '')
	 *   ip, description, os, web, was, db, duplex
	 *   agent       AGENT_* 상수 값
	 */
	public function getRows(): array {
		/*
		 * 1) Zabbix 호스트 조회. 로그인한 사용자의 호스트 권한과 관계없이 전체 호스트를 읽는다. (5버전과 같다)
		 *
		 *    이 화면은 "ITSM 서버 목록 중 Zabbix 에 등록된 것/안 된 것"을 보는 화면이고, ITSM 목록은 권한과 관계없이
		 *    전부 나온다. Zabbix 호스트만 권한으로 줄이면, 권한 밖 호스트가 실제로는 등록돼 있는데도 "미설치"로 보인다.
		 *    그래서 Zabbix 호스트도 전체를 읽는다. 결정: 2026-11-23, 5버전 동작 유지.
		 *
		 *    단, 코어의 인벤토리 상세 화면 링크는 사용자가 볼 수 있는 호스트에만 단다. (권한 밖이면 코어가 오류를 띄운다)
		 */
		$zbx_hosts = $this->fetchAllHosts();
		$visible_hostids = API::Host()->get([
			'output' => ['hostid'],
			'preservekeys' => true
		]);

		/*
		 * 2) 호스트명(소문자) => hostid 색인.
		 *    5버전은 ITSM 행마다 전체 호스트를 다시 도는 이중 반복문이었다(ITSM 수 x 호스트 수).
		 *    색인을 만들어 한 번에 찾는다.
		 */
		$hostid_by_name = [];

		foreach ($zbx_hosts as $hostid => $zbx_host) {
			// mb_strtolower: 한글 등 멀티바이트 문자도 안전하게 소문자로 바꾼다. (5버전의 strtolower 대체)
			$hostid_by_name[mb_strtolower($zbx_host['host'])] = $hostid;
		}

		// 3) ITSM 서버 목록.
		$itsm_rows = $this->itsm->fetch();

		if ($itsm_rows === null) {
			// ITSM 미연동: 개발/검증을 위해 Zabbix 호스트를 ITSM 목록처럼 취급한다.
			$this->itsm_fallback = true;
			$itsm_rows = [];

			foreach ($zbx_hosts as $hostid => $zbx_host) {
				$itsm_rows[] = [
					'id' => $hostid,
					'ci_name' => $zbx_host['host'],
					'ip_address' => '',
					'description' => $zbx_host['description']
				];
			}
		}

		// 4) ITSM 행 기준으로 Zabbix 호스트를 매칭해 화면 행을 만든다.
		$rows = [];

		foreach ($itsm_rows as $itsm_row) {
			$ci_name = trim((string) ($itsm_row['ci_name'] ?? ''));
			$zbx_host = null;

			// 호스트명이 비어 있는 ITSM 행은 표시할 수 없으므로 건너뛴다.
			if ($ci_name === '') {
				continue;
			}

			$key = mb_strtolower($ci_name);

			if (array_key_exists($key, $hostid_by_name)) {
				$zbx_host = $zbx_hosts[$hostid_by_name[$key]];
			}

			/*
			 * 5버전 버그 수정: 매칭이 안 된 행에서 $host_name 을 초기화하지 않아
			 * 바로 앞 행의 표시명이 그대로 표시됐다. 여기서는 매칭이 안 되면 빈 값이다.
			 */
			$ip = trim((string) ($itsm_row['ip_address'] ?? ''));

			if ($ip === '' && $zbx_host !== null) {
				$ip = HostHelper::mainIp($zbx_host['interfaces']);
			}

			$description = trim((string) ($itsm_row['description'] ?? ''));

			if ($description === '' && $zbx_host !== null) {
				$description = $zbx_host['description'];
			}

			$rows[] = [
				'rowid' => $zbx_host !== null ? $zbx_host['hostid'] : 'itsm_'.($itsm_row['id'] ?? $ci_name),
				'hostid' => $zbx_host !== null ? $zbx_host['hostid'] : null,
				// 인벤토리가 켜져 있고 사용자가 볼 수 있는 호스트만 코어의 인벤토리 상세 화면으로 링크한다.
				'has_inventory' => $zbx_host !== null && $zbx_host['inventory_mode'] != HOST_INVENTORY_DISABLED
					&& array_key_exists($zbx_host['hostid'], $visible_hostids),
				'host' => $ci_name,
				'name' => $zbx_host !== null ? $zbx_host['name'] : '',
				'ip' => $ip,
				'description' => $description,
				'agent' => $this->resolveAgentStatus($itsm_row, $zbx_host)
			// 배열 + 배열: 오른쪽 배열의 키(os, web, was, db, duplex)를 왼쪽 배열에 합친다.
			] + $this->software->resolve($itsm_row, $zbx_host);
		}

		return $rows;
	}

	/**
	 * 권한과 관계없이 전체 Zabbix 호스트를 읽는다. host.get 결과와 같은 모양으로 돌려준다.
	 *
	 * host.get 은 항상 로그인한 사용자의 권한으로 걸러서 여기서는 DB 를 직접 읽는다.
	 * (5버전은 swinventory DB 뷰를 읽었다. 그 뷰는 인터페이스가 여러 개면 행이 중복되고 템플릿이 섞였다.
	 *  여기서는 호스트 한 줄에 인터페이스를 따로 붙여 중복이 없고, 템플릿은 빼고 읽는다.)
	 *
	 *   status IN (0, 1)  : 모니터링 중 / 중지된 호스트. 템플릿(3)은 뺀다.
	 *   flags IN (0, 4)   : 일반 호스트 / 디스커버리로 만들어진 호스트. host.get 기본값과 같다. (호스트 프로토타입 2 는 뺀다)
	 *   host_inventory    : 인벤토리가 꺼진 호스트는 이 표에 줄이 없다. 그래서 LEFT JOIN 하고 없으면 -1(꺼짐)로 본다.
	 *
	 * @return array  [hostid => ['hostid', 'host', 'name', 'description', 'inventory_mode',
	 *                            'inventory' => [필드 => 값], 'interfaces' => [[ip, main, type], ...]]]
	 */
	private function fetchAllHosts(): array {
		/*
		 * 인벤토리 필드 이름은 host_inventory 표의 열 이름이다. (SoftwareResolver::INVENTORY_FIELDS)
		 * 쿼리에 이어 붙이므로 영문자/숫자/밑줄로 된 이름만 쓴다.
		 */
		$inventory_fields = array_values(array_filter($this->software->getInventoryFields(),
			static fn($field): bool => preg_match('/^[a-z_][a-z0-9_]*$/', (string) $field) === 1
		));

		$inventory_columns = '';

		foreach ($inventory_fields as $field) {
			$inventory_columns .= ',hi.'.$field.' AS inv_'.$field;
		}

		$result = DBselect(
			'SELECT h.hostid,h.host,h.name,h.description,'.
				'COALESCE(hi.inventory_mode,'.HOST_INVENTORY_DISABLED.') AS inventory_mode'.$inventory_columns.
			' FROM hosts h'.
			' LEFT JOIN host_inventory hi ON hi.hostid=h.hostid'.
			' WHERE '.dbConditionInt('h.status', [HOST_STATUS_MONITORED, HOST_STATUS_NOT_MONITORED]).
				' AND '.dbConditionInt('h.flags', [ZBX_FLAG_DISCOVERY_NORMAL, ZBX_FLAG_DISCOVERY_CREATED])
		);

		$hosts = [];

		while ($result !== false && $row = DBfetch($result)) {
			$inventory = [];

			// host.get 처럼 인벤토리가 꺼진 호스트는 빈 배열.
			if ($row['inventory_mode'] != HOST_INVENTORY_DISABLED) {
				foreach ($inventory_fields as $field) {
					$inventory[$field] = (string) $row['inv_'.$field];
				}
			}

			$hosts[$row['hostid']] = [
				'hostid' => $row['hostid'],
				'host' => $row['host'],
				'name' => $row['name'],
				'description' => $row['description'],
				'inventory_mode' => $row['inventory_mode'],
				'inventory' => $inventory,
				'interfaces' => []
			];
		}

		if ($hosts) {
			$result = DBselect(
				'SELECT hostid,ip,main,type FROM interface WHERE '.dbConditionId('hostid', array_keys($hosts))
			);

			while ($result !== false && $row = DBfetch($result)) {
				$hosts[$row['hostid']]['interfaces'][] = [
					'ip' => $row['ip'],
					'main' => $row['main'],
					'type' => $row['type']
				];
			}
		}

		return $hosts;
	}

	/**
	 * Agent 설치 상태 판정.
	 *
	 * 5버전 규칙: ITSM 호스트명이 Zabbix 에 등록돼 있으면 "설치".
	 *
	 * TODO(고객환경): "예외" 기준은 확인하지 못해서 ITSM 행의 agent_exception 값으로 가정했다.
	 *                 실제 기준(예외 테이블, 호스트 태그, 호스트 그룹 등)에 맞게 이 메소드만 고치면 된다.
	 *                 "Zabbix 에 등록" 이 아니라 "agent 가 실제 응답 중" 을 설치로 보려면
	 *                 getRows() 의 host.get 에 'selectInterfaces' => ['ip', 'main', 'available'] 를 주고
	 *                 여기서 available 값을 본다.
	 */
	private function resolveAgentStatus(array $itsm_row, ?array $zbx_host): int {
		if ($zbx_host !== null) {
			return self::AGENT_INSTALLED;
		}

		if (!empty($itsm_row['agent_exception'])) {
			return self::AGENT_EXCEPTION;
		}

		return self::AGENT_NOT_INSTALLED;
	}

	/**
	 * 필터 적용.
	 *
	 * @param array $rows
	 * @param array $filter  ['itsm' => '', 'os' => '', ..., 'agent_install' => 1, 'agent_uninstall' => 1, ...]
	 */
	public function filter(array $rows, array $filter): array {
		// 텍스트 필터: 대소문자 무시, 부분 일치. 여러 조건은 AND.
		foreach (self::TEXT_FILTERS as $filter_key => $column) {
			$needle = trim((string) ($filter[$filter_key] ?? ''));

			if ($needle === '') {
				continue;
			}

			/*
			 * array_filter($배열, 함수): 함수가 true 를 돌려준 행만 남긴다.
			 * fn(...) => 식   : 한 줄짜리 익명 함수(화살표 함수). 바깥 변수 $column, $needle 을 그대로 쓸 수 있다.
			 * mb_stripos(a, b) : a 안에서 b 를 대소문자 무시하고 찾는다. 못 찾으면 false.
			 *                    찾은 위치가 0(맨 앞)일 수 있으므로 반드시 !== false 로 비교한다.
			 */
			$rows = array_filter($rows,
				static fn(array $row): bool => mb_stripos((string) $row[$column], $needle) !== false
			);
		}

		// Agent 필터: 체크된 상태들의 비트마스크. (5버전과 동일하게, 하나도 체크 안 하면 전체 표시)
		$mask = 0;

		foreach (self::AGENT_FILTERS as $filter_key => $status) {
			// !empty(): 키가 없거나 0, '', null 이면 false. 키가 없어도 경고가 나지 않는다.
			if (!empty($filter[$filter_key])) {
				$mask |= $status;	// 비트 OR 로 누적. 예) 설치(1) + 예외(4) = 5
			}
		}

		if ($mask != 0) {
			$rows = array_filter($rows, static fn(array $row): bool => ($row['agent'] & $mask) != 0);
		}

		// array_filter 는 원래 키(0, 3, 7 ...)를 유지하므로 0 부터 다시 번호를 매긴다.
		return array_values($rows);
	}

	/**
	 * 정렬. 화면의 컬럼 헤더를 클릭하면 컨트롤러가 이 메소드에 정렬 컬럼과 방향을 넘긴다.
	 *
	 * @param string $sort_field  SORT_FIELDS 중 하나.
	 * @param string $sort_order  ZBX_SORT_UP('ASC') 또는 ZBX_SORT_DOWN('DESC').
	 */
	public function sort(array $rows, string $sort_field, string $sort_order): array {
		if (!in_array($sort_field, self::SORT_FIELDS, true)) {
			$sort_field = 'host';
		}

		// 코어의 정렬 도우미. 대소문자를 무시하고 숫자가 섞인 문자열(web2 < web10)도 자연스럽게 정렬한다.
		CArrayHelper::sort($rows, [['field' => $sort_field, 'order' => $sort_order]]);

		return array_values($rows);
	}
}
