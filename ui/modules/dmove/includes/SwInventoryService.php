<?php declare(strict_types = 0);
/*
 * SW 인벤토리 화면의 데이터 처리(조회 -> 매칭 -> 필터 -> 정렬).
 *
 * ── 이 파일이 하는 일 ──
 *   ITSM 서버 목록과 Zabbix 호스트 목록을 호스트명으로 맞춰 보고, 표의 행을 만든다.
 *   행마다 OS / WEB / WAS / DB / 이중화 솔루션 값과 Agent 설치 상태(설치 / 미설치 / 예외)를 붙인다.
 *   화면을 그리지 않는다. 행 배열만 만들어 컨트롤러에 돌려준다.
 *
 * ── 누가 쓰는가 ──
 *   actions/inventory/swinventory/SwInventoryList.php   SW 인벤토리 화면 (zabbix.php?action=swinventory.list)
 *   views/inventory/swinventory.list.php                필터 이름과 Agent 상태 상수를 읽는다
 *
 * ── 데이터 흐름 ──
 *   컨트롤러
 *     ├─ getRows()   전체 행을 만든다
 *     │    ├─ fetchAllHosts()          DB 에서 Zabbix 호스트 + 인벤토리 (호스트 그룹/호스트 필터가 있으면 그 안에서만)
 *     │    ├─ ItsmRepository::fetch()  ITSM 서버 목록 (읽을 수 없으면 행 0개, isItsmUnavailable() 이 true)
 *     │    ├─ SoftwareResolver::resolve()  행마다 os/web/was/db/duplex 값
 *     │    └─ applyAgentStatus()       행마다 Agent 설치 상태 (Agent 상태 표 extends_agent 의 최신 기록)
 *     ├─ filter()    필터 입력에 맞는 행만 남긴다
 *     └─ sort()      정렬한다
 *
 * 5버전 대응: CControllerSwinventories::doAction() 의 데이터 부분 +
 *            C*Filter 트레잇(isFilter / filtering)
 *
 * 컨트롤러(actions/)에는 "요청/응답" 만 두고, 데이터 로직은 이 클래스에 둔다.
 * 화면(list)과 내보내기 파일(CSV / Excel)이 같은 로직을 쓰기 때문이다.
 *
 * ── 관련 파일 ──
 *   includes/ItsmRepository.php     ITSM 서버 목록 조회
 *   includes/SoftwareResolver.php   OS / WEB / WAS / DB / 이중화 값 결정
 *   includes/HostHelper.php         대표 IP 고르기
 *   conf/itsm.conf.php              ITSM 테이블 위치 설정
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

	/**
	 * Agent 체크박스 필터 키 => 상태 값.
	 * 왼쪽 키에 'filter_' 를 붙인 것이 화면 체크박스 이름이다. 예) 'agent_install' -> 체크박스 filter_agent_install
	 */
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
	private AgentStatusRepository $agent_status;
	private SoftwareResolver $software;

	/** ITSM 표를 읽지 못했는지 여부. (설정이 비었다 / 표가 없다 / 조회 실패) */
	private bool $itsm_unavailable = false;

	/**
	 * 인자를 주지 않으면 기본 구현을 쓴다. 인자는 테스트할 때 가짜 ITSM 목록을 끼워 넣기 위한 것이다.
	 *
	 *   ?ItsmRepository  : 타입 앞의 ? 는 "null 도 허용" 이라는 뜻.
	 *   $a ?? $b         : $a 가 null 이면 $b 를 쓴다.
	 */
	public function __construct(?ItsmRepository $itsm = null, ?SoftwareResolver $software = null,
			?AgentStatusRepository $agent_status = null) {
		$this->itsm = $itsm ?? new ItsmRepository();
		$this->software = $software ?? new SoftwareResolver();
		$this->agent_status = $agent_status ?? new AgentStatusRepository();
	}

	/**
	 * Agent 상태 표(extends_agent)를 쓸 수 있는가. 없으면 화면에 안내를 띄운다.
	 */
	public function isAgentStatusAvailable(): bool {
		return $this->agent_status->isAvailable();
	}

	/**
	 * ITSM 표를 읽지 못했는지 알려 준다. 이때 getRows() 는 빈 배열이다.
	 * (5버전도 ITSM 표가 없으면 목록이 비었다. Zabbix 호스트로 대신 채우지 않는다)
	 *
	 * getRows() 를 부른 "뒤에" 불러야 맞는 값이 나온다. getRows() 가 ITSM 목록을 읽어 보고 이 값을 정하기 때문이다.
	 * 컨트롤러는 이 값을 뷰에 넘기고, 뷰는 true 일 때 화면 위에 오류 상자를 띄운다.
	 *
	 * @return bool  설정이 비었거나, 표가 없거나, 조회에 실패했으면 true.
	 */
	public function isItsmUnavailable(): bool {
		return $this->itsm_unavailable;
	}

	/**
	 * Agent 상태 값을 화면에 보여 줄 글자로 바꾸는 표.
	 *
	 * 행의 'agent' 에는 숫자(1 / 2 / 4)가 들어 있다. 화면과 내보내기 파일에는 숫자 대신 이 글자를 쓴다.
	 * static 메소드라서 객체를 만들지 않고 SwInventoryService::getAgentStatusLabels() 로 부른다.
	 *
	 * @return array  [상태 값 => 글자]. 한국어 사용자라면 예) [1 => '설치', 2 => '미설치', 4 => '예외']
	 */
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
	 * 행 1개 = ITSM 서버 1대다. 같은 이름(대소문자 무시)의 Zabbix 호스트가 있으면 그 호스트의 값을 붙인다.
	 * ITSM 표를 읽지 못하면 빈 배열이다. (isItsmUnavailable() 이 true 가 된다)
	 *
	 * 호스트 그룹/호스트 필터는 "맞춰 볼 Zabbix 호스트"만 줄인다. (5버전 CControllerSwinventories.php:184-208)
	 * ITSM 행은 모두 나오고, 필터 밖 호스트와 이름이 같은 ITSM 행은 매칭이 안 된 줄(미설치/예외)로 나온다.
	 *
	 * @param array $host_options  HostFilter::toApiOptions() 의 결과. ['groupids' => [...]|null, 'hostids' => [...]|null]
	 *                             null 이면 그 조건이 없다. (하위 그룹은 toApiOptions() 가 이미 넣어 준다)
	 *
	 * @return array  행 목록. 예) [['rowid' => '10084', 'hostid' => '10084', 'has_inventory' => true,
	 *                'host' => 'web-server-01', 'name' => 'web-server-01', 'ip' => '10.0.0.1', 'description' => '...',
	 *                'agent' => 1, 'os' => 'Linux', 'web' => '', 'was' => '', 'db' => '', 'duplex' => ''], ...]
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
	public function getRows(array $host_options = []): array {
		/*
		 * 1) Zabbix 호스트 조회. 로그인한 사용자의 호스트 권한과 관계없이 전체 호스트를 읽는다.
		 *    (주의: 5버전은 API::Host()->get() 이라 사용자 권한이 적용됐다. Super admin 이면 결과가 같다.
		 *     v5 CControllerSwinventories.php:200-208. 이 차이를 알고도 전체로 두기로 다시 정함, 2026-11-24)
		 *
		 *    이 화면은 "ITSM 서버 목록 중 Zabbix 에 등록된 것/안 된 것"을 보는 화면이고, ITSM 목록은 권한과 관계없이
		 *    전부 나온다. Zabbix 호스트만 권한으로 줄이면, 권한 밖 호스트가 실제로는 등록돼 있는데도 "미설치"로 보인다.
		 *    그래서 Zabbix 호스트도 전체를 읽는다. 결정: 2026-11-23.
		 *
		 *    단, 코어의 인벤토리 상세 화면 링크는 사용자가 볼 수 있는 호스트에만 단다. (권한 밖이면 코어가 오류를 띄운다)
		 *
		 *    호스트 그룹/호스트 필터를 고르면 그 안의 호스트만 읽는다. (5버전: host.get 의 groupids, hostids)
		 */
		$zbx_hosts = $this->fetchAllHosts($host_options['groupids'] ?? null, $host_options['hostids'] ?? null);
		// 사용자가 볼 수 있는 호스트의 ID. preservekeys 라서 배열의 키가 hostid 다. (아래에서 array_key_exists 로 찾는다)
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
			// strtolower: 5버전과 같은 함수. 영문자만 소문자로 바꾸고 한글 등은 그대로 둔다.
			$hostid_by_name[strtolower($zbx_host['host'])] = $hostid;
		}

		// 3) ITSM 서버 목록. 읽지 못하면 행을 만들지 않는다. (뷰가 오류 상자를 띄운다)
		$itsm_rows = $this->itsm->fetch();

		if ($itsm_rows === null) {
			$this->itsm_unavailable = true;

			return [];
		}

		/*
		 * 4) ITSM 행 기준으로 Zabbix 호스트를 매칭해 화면 행을 만든다.
		 *    5버전은 줄을 $rowdatas[키] 에 넣었다. 그래서 키가 같은 ITSM 행(같은 Zabbix 호스트에 맞은 행,
		 *    또는 매칭이 안 되고 id 가 같은 행)은 한 줄로 합쳐지고 뒤에 읽은 행이 남는다. 여기서도 같다.
		 */
		$rows = [];

		foreach ($itsm_rows as $itsm_row) {
			// ci_name, id 는 앞뒤 빈칸을 떼지 않고 그대로 쓴다. (5버전과 같다)
			$ci_name = (string) ($itsm_row['ci_name'] ?? '');
			$zbx_host = null;

			$key = strtolower($ci_name);

			if (array_key_exists($key, $hostid_by_name)) {
				$zbx_host = $zbx_hosts[$hostid_by_name[$key]];
			}

			/*
			 * 5버전 버그 수정: 매칭이 안 된 행에서 $host_name 을 초기화하지 않아
			 * 바로 앞 행의 표시명이 그대로 표시됐다. 여기서는 매칭이 안 되면 빈 값이다.
			 */
			// IP, Description 은 ITSM 값만 쓴다. (5버전 CControllerSwinventories.php:228-229)
			$ip = (string) ($itsm_row['ip_address'] ?? '');
			$description = (string) ($itsm_row['description'] ?? '');

			/*
			 * 줄의 키. 매칭된 줄은 hostid, 매칭이 안 된 줄은 'itsm_' + ITSM ID. (5버전과 같다: "itsm_".$row['id'])
			 * Agent 상태 표(extends_agent)에 이 키로 저장하고 읽는다.
			 */
			$itsm_id = (string) ($itsm_row['id'] ?? '');
			$rowid = $zbx_host !== null ? $zbx_host['hostid'] : 'itsm_'.$itsm_id;

			/*
			 * 5버전 버그 수정: 매칭이 안 되고 ITSM ID 가 빈 행은 키가 모두 'itsm_' 라서 5버전은 한 줄로 합쳐졌다.
			 * 여기서는 합치지 않고 행마다 따로 보인다. (상태 입력 링크는 달지 않는다. 아래 agent_editable)
			 */
			// 배열 키 앞에 글자를 붙여 hostid('10084')가 숫자 키로 바뀌지 않게 한다. 마지막에 array_values() 로 키를 버린다.
			$row_index = ($zbx_host === null && $itsm_id === '') ? 'noid_'.count($rows) : 'k'.$rowid;

			$rows[$row_index] = [
				'rowid' => $rowid,
				'hostid' => $zbx_host !== null ? $zbx_host['hostid'] : null,
				// 인벤토리가 켜져 있고 사용자가 볼 수 있는 호스트만 코어의 인벤토리 상세 화면으로 링크한다.
				'has_inventory' => $zbx_host !== null && $zbx_host['inventory_mode'] != HOST_INVENTORY_DISABLED
					&& array_key_exists($zbx_host['hostid'], $visible_hostids),
				'host' => $ci_name,
				'name' => $zbx_host !== null ? $zbx_host['name'] : '',
				'ip' => $ip,
				'description' => $description,
				// Agent 상태는 아래 applyAgentStatus() 에서 한 번에 채운다. 여기서는 자리만 만든다.
				'agent' => self::AGENT_INSTALLED,
				'agent_note' => '',
				/*
				 * 상태를 사용자가 입력할 수 있는 줄인가. Zabbix 에 등록되지 않은 ITSM 서버만 입력한다. (5버전과 같다)
				 * 다만 아래 두 경우는 링크를 만들지 않는다. 이런 줄은 "미설치" 글자만 보인다.
				 *   - ITSM ID 가 비어 있다: 키가 모두 'itsm_' 로 같아져 서로의 상태를 덮어쓴다. (5버전은 그대로 덮어썼다)
				 *   - 키가 20자를 넘는다: 상태 표의 칸(varchar(20))에 들어가지 않는다. ITSM ID 가 15자리를 넘을 때만 생긴다.
				 * 가장 최근 기록이 1(설치)인 줄도 링크를 달지 않는다. (applyAgentStatus() 에서 정한다)
				 */
				'agent_editable' => $zbx_host === null && $itsm_id !== '' && mb_strlen($rowid) <= 20
			// 배열 + 배열: 오른쪽 배열의 키(os, web, was, db, duplex)를 왼쪽 배열에 합친다.
			] + $this->software->resolve($itsm_row, $zbx_host);
		}

		return $this->applyAgentStatus(array_values($rows));
	}

	/**
	 * 권한과 관계없이 Zabbix 호스트를 읽는다. host.get 결과와 같은 모양으로 돌려준다.
	 *
	 * host.get 은 항상 로그인한 사용자의 권한으로 걸러서 여기서는 DB 를 직접 읽는다.
	 * (5버전은 swinventory DB 뷰를 읽었다. 그 뷰는 인터페이스가 여러 개면 행이 중복되고 템플릿이 섞였다.
	 *  여기서는 템플릿은 빼고 호스트 한 줄씩 읽는다.)
	 *
	 *   status IN (0, 1)  : 모니터링 중 / 중지된 호스트. 템플릿(3)은 뺀다.
	 *   flags IN (0, 4)   : 일반 호스트 / 디스커버리로 만들어진 호스트. host.get 기본값과 같다. (호스트 프로토타입 2 는 뺀다)
	 *   host_inventory    : 인벤토리가 꺼진 호스트는 이 표에 줄이 없다. 그래서 LEFT JOIN 하고 없으면 -1(꺼짐)로 본다.
	 *   groupids          : 이 그룹들 중 하나에 들어 있는 호스트만. (hosts_groups 표)
	 *   hostids           : 이 호스트만. 둘 다 주면 둘 다 맞는 호스트만. (host.get 과 같다)
	 *
	 * @param array|null $groupids  호스트 그룹 ID (하위 그룹 포함). null 이면 조건 없음.
	 * @param array|null $hostids   호스트 ID. null 이면 조건 없음.
	 *
	 * @return array  [hostid => ['hostid', 'host', 'name', 'description', 'inventory_mode',
	 *                            'inventory' => [필드 => 값]]]
	 */
	private function fetchAllHosts(?array $groupids = null, ?array $hostids = null): array {
		/*
		 * 인벤토리 필드 이름은 host_inventory 표의 열 이름이다. (SoftwareResolver::INVENTORY_FIELDS)
		 * 쿼리에 이어 붙이므로 영문자/숫자/밑줄로 된 이름만 쓴다.
		 */
		// 정규식 /^[a-z_][a-z0-9_]*$/ : 첫 글자는 영문 소문자나 밑줄, 그 뒤는 영문 소문자/숫자/밑줄만. ^ 는 처음, $ 는 끝.
		$inventory_fields = array_values(array_filter($this->software->getInventoryFields(),
			static fn($field): bool => preg_match('/^[a-z_][a-z0-9_]*$/', (string) $field) === 1
		));

		$inventory_columns = '';

		// 예) ',hi.os AS inv_os,hi.software_app_a AS inv_software_app_a'. 호스트 표의 열과 이름이 겹치지 않게 inv_ 를 붙인다.
		foreach ($inventory_fields as $field) {
			$inventory_columns .= ',hi.'.$field.' AS inv_'.$field;
		}

		// COALESCE(a, b): a 가 NULL 이면 b. 인벤토리 줄이 없는 호스트는 inventory_mode 가 -1(꺼짐)이 된다.
		$result = DBselect(
			'SELECT h.hostid,h.host,h.name,h.description,'.
				'COALESCE(hi.inventory_mode,'.HOST_INVENTORY_DISABLED.') AS inventory_mode'.$inventory_columns.
			' FROM hosts h'.
			' LEFT JOIN host_inventory hi ON hi.hostid=h.hostid'.
			' WHERE '.dbConditionInt('h.status', [HOST_STATUS_MONITORED, HOST_STATUS_NOT_MONITORED]).
				' AND '.dbConditionInt('h.flags', [ZBX_FLAG_DISCOVERY_NORMAL, ZBX_FLAG_DISCOVERY_CREATED]).
				($groupids !== null
					? ' AND h.hostid IN (SELECT hg.hostid FROM hosts_groups hg WHERE '
						.dbConditionId('hg.groupid', $groupids).')'
					: ''
				).
				($hostids !== null ? ' AND '.dbConditionId('h.hostid', $hostids) : '')
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
				'inventory' => $inventory
			];
		}

		return $hosts;
	}

	/**
	 * 줄마다 Agent 설치 상태를 채운다. (5버전 Agent 판정 클래스 ...HostAgent.php 와 같은 규칙)
	 *
	 *   Zabbix 에 등록된 서버(ITSM 이름과 Zabbix 호스트 이름이 같다)      -> 설치
	 *   등록되지 않은 서버: 상태 표(extends_agent)의 가장 최근 기록을 본다
	 *       기록의 agent 가 2                                           -> 미설치
	 *       기록의 agent 가 4                                           -> 예외
	 *       기록의 agent 가 그 밖의 값                                   -> 설치   (5버전 코드 그대로)
	 *       기록이 없음                                                  -> 미설치
	 *
	 * 미설치/예외 줄에는 마지막 기록의 사유와 입력자/시각을 agent_note 에 담는다. 화면에서 글자에 마우스를 올리면 보인다.
	 * 상태 표를 쓸 수 없으면(표가 없음) 등록되지 않은 서버는 모두 미설치다.
	 *
	 * @param array $rows  getRows() 가 만든 줄. 'rowid', 'agent_editable' 을 쓴다.
	 *
	 * @return array  'agent', 'agent_note' 가 채워진 줄.
	 */
	private function applyAgentStatus(array $rows): array {
		// 상태를 입력하는 줄(등록되지 않은 서버)의 키만 모아 한 번에 읽는다. 키 예) 'itsm_1001'
		$keys = [];

		foreach ($rows as $row) {
			if ($row['agent_editable']) {
				$keys[] = $row['rowid'];
			}
		}

		$latest = $this->agent_status->latestByKeys($keys);

		foreach ($rows as &$row) {
			// Zabbix 에 등록된 서버는 설치. 키를 만들 수 없는 줄(ID 가 비었거나 너무 길다)은 기록을 읽지 않고 미설치.
			if ($row['hostid'] !== null) {
				continue;
			}

			$record = $row['agent_editable'] ? ($latest[$row['rowid']] ?? null) : null;

			if ($record === null) {
				$row['agent'] = self::AGENT_NOT_INSTALLED;
				continue;
			}

			switch ($record['agent']) {
				case AgentStatusRepository::STATUS_NOT_INSTALLED:
					$row['agent'] = self::AGENT_NOT_INSTALLED;
					break;

				case AgentStatusRepository::STATUS_EXCEPTION:
					$row['agent'] = self::AGENT_EXCEPTION;
					break;

				default:
					$row['agent'] = self::AGENT_INSTALLED;
			}

			// 기록이 1(설치)이면 글자만 보이고 입력 링크를 달지 않는다. (5버전 Agent 트레잇 ...HostAgent.php 105-123행)
			if ($record['agent'] == AgentStatusRepository::STATUS_INSTALLED) {
				$row['agent_editable'] = false;
			}

			// 예) "사유 (Admin, 2026-11-23 10:15)"  zbx_date2str: 사용자 시간대로 날짜를 글자로 바꾸는 코어 함수.
			$row['agent_note'] = trim($record['explanation'].' ('.$record['username'].', '
				.zbx_date2str(DATE_TIME_FORMAT, $record['clock']).')');
		}
		// &$row 로 돈 뒤에는 연결을 끊는다. 안 하면 뒤에서 $row 를 쓸 때 마지막 줄이 바뀐다.
		unset($row);

		return $rows;
	}

	/**
	 * 필터 적용. 필터 입력에 맞는 행만 남긴다.
	 *
	 *   글자 필터(호스트, OS, WEB ...) : 입력한 글자가 그 열에 들어 있는 행만. 대소문자 무시. 여러 개면 모두 맞아야 한다.
	 *   Agent 체크박스                : 체크한 상태 중 하나에 해당하는 행만. 하나도 체크하지 않으면 전체.
	 *
	 * 예) $filter = ['os' => 'linux', 'agent_install' => 1]
	 *     -> OS 에 'linux' 가 들어 있고 Agent 가 "설치"인 행만 남는다.
	 *
	 * @param array $rows    getRows() 의 결과.
	 * @param array $filter  ['itsm' => '', 'os' => '', ..., 'agent_install' => 1, 'agent_uninstall' => 1, ...]
	 *
	 * @return array  남은 행. 키는 0 부터 다시 매긴다.
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

		// & (비트 AND): 행의 상태가 $mask 에 들어 있으면 0 이 아닌 값이 나온다. 예) 4 & 5 = 4 (남김), 2 & 5 = 0 (뺌)
		if ($mask != 0) {
			$rows = array_filter($rows, static fn(array $row): bool => ($row['agent'] & $mask) != 0);
		}

		// array_filter 는 원래 키(0, 3, 7 ...)를 유지하므로 0 부터 다시 번호를 매긴다.
		return array_values($rows);
	}

	/**
	 * 정렬. 화면의 컬럼 헤더를 클릭하면 컨트롤러가 이 메소드에 정렬 컬럼과 방향을 넘긴다.
	 *
	 * @param array  $rows        filter() 의 결과.
	 * @param string $sort_field  SORT_FIELDS 중 하나. 목록에 없는 값이면 'host' 로 정렬한다.
	 * @param string $sort_order  ZBX_SORT_UP('ASC') 또는 ZBX_SORT_DOWN('DESC').
	 *
	 * @return array  정렬된 행. 키는 0 부터 다시 매긴다.
	 */
	public function sort(array $rows, string $sort_field, string $sort_order): array {
		// in_array(값, 배열, true): 배열에 그 값이 있는지. 세 번째 true 는 타입까지 같아야 한다는 뜻.
		if (!in_array($sort_field, self::SORT_FIELDS, true)) {
			$sort_field = 'host';
		}

		// 코어의 정렬 도우미. 대소문자를 무시하고 숫자가 섞인 문자열(web2 < web10)도 자연스럽게 정렬한다.
		CArrayHelper::sort($rows, [['field' => $sort_field, 'order' => $sort_order]]);

		return array_values($rows);
	}
}
