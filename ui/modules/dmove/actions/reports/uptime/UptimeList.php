<?php declare(strict_types = 0);
/*
 * 서버 시간 확인 화면 컨트롤러.
 *
 * ── 이 화면이 하는 일 ──
 *   서버마다 "켜진 지 얼마나 됐는지(가동 시간)"와 "언제 켜졌는지(부팅 시각)"를 보여 준다.
 *   오래 재부팅하지 않은 서버를 찾을 때 쓴다. (필터: 서버 Uptime N day 이상)
 *
 *   화면 : zabbix.php?action=uptime.list
 *   메뉴 : 보고서 > 서버 시간 확인   (Module.php 의 REPORT_MENUS)
 *   5버전: zabbix.php?action=uptime.view
 *          컨트롤러 CControllerUptimeView.php (+ 부모 CControllerUptime.php 의 prepareData())
 *
 * ── 화면 구성 (5버전과 같다) ──
 *   필터 : 호스트 그룹(선택창), 호스트(선택창), OS/Version(입력), 서버 Uptime(입력, "day 이상")
 *   표   : 호스트, IP, Description, OS, 서버 Time, 서버 start
 *
 * ── 값이 어디서 오는가 (5버전과 같다) ──
 *   가동 시간 : 아이템 system.uptime 의 마지막 값. 단위는 초다. (Zabbix agent 표준 키)
 *   서버 Time : 가동 시간을 "158 일, 13:38:45" 형식으로 표시한 것.
 *   서버 start: 값을 수집한 시각 - 가동 시간 = 부팅 시각.
 *   OS        : 호스트 인벤토리의 os 필드. 필터 OS/Version 도 이 필드를 부분 일치로 찾는다.
 *   IP        : Agent -> SNMP -> JMX -> IPMI 순서로 처음 찾은 인터페이스의 IP (DNS 로 연결하면 DNS 이름). ipOf() 참고.
 *
 * ── 데이터 흐름 ──
 *   doAction()
 *     ├─ 필터 저장/읽기 (CProfile, HostFilter)
 *     ├─ getRows()
 *     │    ├─ host.get : 필터에 맞는 호스트 (그룹/호스트/OS)
 *     │    ├─ item.get : 그 호스트들의 system.uptime 아이템을 한 번에
 *     │    └─ 가동 시간 계산, "N day 이상" 필터
 *     ├─ 정렬 + 페이징
 *     └─ 뷰(views/reports/uptime.list.php)로 넘긴다
 *
 * ── 5버전과 달라진 점 ──
 *   - 5버전은 코어 "최근 데이터" 화면 컨트롤러를 복사해서 고친 것이라, 쓰지 않는 코드(애플리케이션 조회,
 *     매크로 풀기)가 많았다. 여기서는 이 화면에 필요한 조회만 한다.
 *   - 5버전은 "N day 이상" 필터를 위해 아이템마다 SQL 을 한 번씩 실행했다(날짜 차이 계산용).
 *     여기서는 PHP 에서 "가동 초 / 86400" 으로 계산한다. 결과는 같다.
 *   - 5버전은 호스트를 "검색/필터 결과 제한"(기본 1000) + 1 개까지만 읽었다. 여기서는 필터에 맞는 호스트를 모두 읽는다.
 *   - Description 은 다른 보고서 화면과 같게 ITSM 설명을 먼저 쓰고, 없으면 Zabbix 호스트 설명을 쓴다.
 *     (5버전 뷰 파일 monitoring.uptime.view.php 를 받지 못해서, 5버전이 어느 쪽을 썼는지는 확인하지 못했다)
 *
 * ── 알아 둘 점 ──
 *   - item.get 의 lastvalue 는 최근 24시간(코어 설정) 안에 수집된 값만 준다. 5버전도 같은 기간만 읽었다.
 *     24시간 넘게 수집이 끊긴 서버는 값이 '-' 로 나온다.
 *
 * ── 이 화면에 속한 파일 ──
 *   actions/reports/uptime/UptimeList.php   컨트롤러 (이 파일)
 *   views/reports/uptime.list.php           화면 뷰
 *   includes/filter/HostFilter.php          호스트 그룹/호스트 선택 필터 (여러 화면 공용)
 */

namespace Modules\DMove\Actions\Reports\Uptime;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CUrl;
use CWebUser;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\ItsmRepository;
use Modules\DMove\Includes\Lang;

class UptimeList extends CController {

	/** 필터 값을 사용자별로 저장하는 프로파일 키 접두어. 화면마다 겹치지 않게 'web.dmove.<화면>.' 으로 짓는다. */
	private const PROFILE_PREFIX = 'web.dmove.uptime.';

	/** 가동 시간 아이템 키. Zabbix agent 표준 키이며 값의 단위는 초다. (5버전도 이 키를 썼다) */
	private const UPTIME_ITEM_KEY = 'system.uptime';

	/** 정렬할 수 있는 열. host = 호스트 이름, uptime = 가동 시간. */
	private const SORT_FIELDS = ['host', 'uptime'];

	/**
	 * 컨트롤러가 만들어질 때 한 번 불린다.
	 * 조회만 하는 화면이라 CSRF 토큰 검사를 끈다.
	 */
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	/**
	 * 주소로 들어온 값 검사.
	 *
	 * @return bool  검사를 통과하면 true.
	 */
	protected function checkInput(): bool {
		$fields = [
			'page' =>				'ge 1',
			'sort' =>				'in '.implode(',', self::SORT_FIELDS),
			'sortorder' =>			'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN,
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1',
			'filter_os' =>			'string',
			// 숫자 입력이지만 빈 칸으로도 넘어오므로 문자열로 받고 아래에서 숫자만 골라 쓴다.
			'filter_uptime_days' =>	'string'
		// 호스트 그룹/호스트 선택 필터의 규칙(filter_groupids, filter_hostids)을 더한다. (includes/filter/HostFilter.php)
		] + HostFilter::INPUT_FIELDS;

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 * 표시되는 호스트는 API 가 사용자 권한에 맞게 걸러 준다.
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 화면을 만드는 본체.
	 */
	protected function doAction(): void {
		// ── 1) 필터 저장/초기화 ([적용] -> filter_set=1, [리셋] -> filter_rst=1) ──
		if ($this->hasInput('filter_set')) {
			HostFilter::save(self::PROFILE_PREFIX, $this->getInput('filter_groupids', []),
				$this->getInput('filter_hostids', [])
			);
			CProfile::update(self::PROFILE_PREFIX.'filter.os', trim($this->getInput('filter_os', '')),
				PROFILE_TYPE_STR
			);
			CProfile::update(self::PROFILE_PREFIX.'filter.uptime_days',
				$this->normalizeDays($this->getInput('filter_uptime_days', '')), PROFILE_TYPE_STR
			);
		}
		elseif ($this->hasInput('filter_rst')) {
			HostFilter::reset(self::PROFILE_PREFIX);

			foreach (['os', 'uptime_days'] as $key) {
				CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.'.$key);
			}
		}

		// ── 2) 저장된 필터 읽기 ──
		$host_filter = HostFilter::load(self::PROFILE_PREFIX);	// ['groupids' => [...], 'hostids' => [...]]
		$filter = [
			'os' => CProfile::get(self::PROFILE_PREFIX.'filter.os', ''),
			'uptime_days' => CProfile::get(self::PROFILE_PREFIX.'filter.uptime_days', '')
		];

		// ── 3) 정렬 값. 주소에 없으면 지난번 값을 쓰고, 다시 저장한다. ──
		$sort_field = $this->getInput('sort', CProfile::get(self::PROFILE_PREFIX.'sort', 'host'));
		$sort_order = $this->getInput('sortorder', CProfile::get(self::PROFILE_PREFIX.'sortorder', ZBX_SORT_UP));

		CProfile::update(self::PROFILE_PREFIX.'sort', $sort_field, PROFILE_TYPE_STR);
		CProfile::update(self::PROFILE_PREFIX.'sortorder', $sort_order, PROFILE_TYPE_STR);

		// ── 4) 데이터 조회 + 정렬 ──
		$rows = self::sortRows($this->getRows($host_filter, $filter), $sort_field, $sort_order);

		// ── 5) 뷰로 넘길 데이터 ──
		$data = [
			'action' => $this->getAction(),
			'title' => Lang::t('Server time check'),
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'filter.active', 1),
			// 선택창에 표시할 값(ID + 이름)으로 바꿔 넘긴다.
			'host_filter' => HostFilter::toViewData($host_filter),
			'filter' => $filter,
			'sort' => $sort_field,
			'sortorder' => $sort_order
		];

		// 페이징. 세 번째 인자는 항상 ZBX_SORT_UP. $rows 는 이미 정렬을 끝낸 전체 목록이기 때문이다. (CPagerHelper 참고)
		$data['paging'] = CPagerHelper::paginate($this->getInput('page', 1), $rows, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', $data['action'])
		);
		$data['rows'] = $rows;

		$response = new CControllerResponseData($data);
		$response->setTitle($data['title']);

		$this->setResponse($response);
	}

	/**
	 * 표에 표시할 행 목록.
	 *
	 * @param array $host_filter  ['groupids' => [...], 'hostids' => [...]]  (HostFilter::load() 의 결과)
	 * @param array $filter       ['os' => 'linux', 'uptime_days' => '30']   (빈 문자열이면 조건 없음)
	 *
	 * @return array  행 1개 = 호스트 1대.
	 *   [
	 *     'hostid'       => '10084',
	 *     'host'         => 'web-server-01',
	 *     'ip'           => '10.0.0.1',
	 *     'description'  => '...',
	 *     'os'           => 'Red Hat Enterprise 7.9',
	 *     'uptime'       => 13700325,                  // 가동 초. 값이 없으면 null (정렬용)
	 *     'server_time'  => '158 일, 13:38:45',        // 값이 없으면 '-'
	 *     'server_start' => '2026/06/14 05:13:26'      // 값이 없으면 '-'
	 *   ]
	 */
	private function getRows(array $host_filter, array $filter): array {
		/*
		 * 1) 필터에 맞는 호스트. host.get 옵션:
		 *    HostFilter::toApiOptions()  선택한 그룹(하위 그룹 포함)/호스트를 groupids, hostids 조건으로 바꿔 준다.
		 *    with_monitored_items        모니터링 중인 아이템이 하나라도 있는 호스트만. (5버전과 같다)
		 *    searchInventory             인벤토리 필드를 부분 일치(대소문자 무시)로 찾는다. 여기서는 os 필드.
		 *    selectInventory             각 호스트에 'inventory' => ['os' => ..] 를 붙인다. 인벤토리가 꺼져 있으면 빈 배열.
		 *    selectInterfaces            각 호스트에 'interfaces' => [[type, useip, ip, dns], ...] 를 붙인다. (ipOf() 가 쓴다)
		 *    preservekeys                결과 배열의 키를 hostid 로 한다.
		 *
		 * searchInventory 는 OS 입력이 비어 있어도 항상 넣는다. 5버전과 같다. (5버전 CControllerUptime.php)
		 * 이 옵션이 있으면 API 가 인벤토리 표를 묶어서 읽으므로, 인벤토리가 꺼진 호스트는 목록에서 빠진다.
		 * 입력이 빈 글자면 OS 조건은 붙지 않고 "인벤토리가 켜진 호스트"라는 조건만 남는다.
		 */
		$options = HostFilter::toApiOptions($host_filter) + [
			'output' => ['hostid', 'name', 'description'],
			'with_monitored_items' => true,
			'searchInventory' => ['os' => $filter['os']],
			'selectInventory' => ['os'],
			'selectInterfaces' => ['type', 'useip', 'ip', 'dns'],
			'preservekeys' => true
		];

		$hosts = API::Host()->get($options);

		if (!$hosts) {
			return [];
		}

		/*
		 * 2) 그 호스트들의 가동 시간 아이템을 "한 번에" 조회한다. (호스트마다 조회하면 느리다)
		 *    filter      정확히 일치하는 값으로 거른다. 키가 system.uptime 이고 사용 중(활성)인 아이템만.
		 *    lastvalue   마지막 수집 값. lastclock = 그 값을 수집한 시각(유닉스 초). 수집된 적이 없으면 lastclock 이 0 이다.
		 */
		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'lastvalue', 'lastclock'],
			'hostids' => array_keys($hosts),
			'filter' => [
				'key_' => self::UPTIME_ITEM_KEY,
				'status' => ITEM_STATUS_ACTIVE
			]
		]);

		// 3) ITSM 설명. [소문자 호스트명 => 설명]. ITSM 을 쓰지 않으면 빈 배열이다.
		$itsm_descriptions = (new ItsmRepository())->fetchDescriptions();

		// "N day 이상" 필터 값. 빈 문자열이면 null(조건 없음).
		$min_days = $filter['uptime_days'] !== '' ? (int) $filter['uptime_days'] : null;

		// 4) 행 만들기. 5버전처럼 system.uptime 아이템이 있는 호스트만 나온다.
		$rows = [];

		foreach ($items as $item) {
			$host = $hosts[$item['hostid']];
			$has_value = $item['lastclock'] != 0;
			$uptime = $has_value ? (int) $item['lastvalue'] : null;

			/*
			 * "N day 이상" 필터. 가동 일수 = 가동 초를 하루(86400초)로 나눈 몫.
			 * 5버전은 SQL 의 date_part('day', ...) 로 같은 값을 구했다.
			 * 필터를 걸었는데 값이 없는 호스트는 비교할 수 없으므로 뺀다.
			 * intdiv(a, b): 나눗셈의 몫(정수).
			 */
			if ($min_days !== null && ($uptime === null || intdiv($uptime, SEC_PER_DAY) < $min_days)) {
				continue;
			}

			$rows[] = [
				'hostid' => $host['hostid'],
				'host' => $host['name'],
				'ip' => self::ipOf($host['interfaces']),
				// ITSM 설명이 있으면 그것을, 없으면 Zabbix 호스트 설명을 쓴다.
				// 확인 안 됨: 5버전 뷰 파일을 받지 못해 5버전이 이 열에 무엇을 썼는지 모른다. 그래서 바꾸지 않았다.
				'description' => $itsm_descriptions[mb_strtolower($host['name'])] ?? $host['description'],
				// 인벤토리가 꺼진 호스트는 inventory 가 빈 배열이라 os 키가 없다.
				'os' => is_array($host['inventory']) ? ($host['inventory']['os'] ?? '') : '',
				'uptime' => $uptime,
				'server_time' => $has_value ? self::formatUptime($uptime) : '-',
				// 부팅 시각 = 값을 수집한 시각 - 가동 초. date() 는 사용자 시간대로 표시한다.
				'server_start' => $has_value ? date('Y/m/d H:i:s', $item['lastclock'] - $uptime) : '-'
			];
		}

		return $rows;
	}

	/**
	 * 이 화면의 IP 열 값. 5버전 CControllerUptime.php 와 같은 규칙이다. (다른 화면의 HostHelper::mainIp() 와 다르다)
	 *
	 * 인터페이스 종류를 Agent -> SNMP -> JMX -> IPMI 순서로 보고, 처음 찾은 종류의 첫 번째 인터페이스를 쓴다.
	 * 기본(main) 표시는 보지 않는다. 그 인터페이스가 IP 로 연결하면(useip=1) IP 를, 아니면 DNS 이름을 돌려준다.
	 * 인터페이스가 하나도 없으면 빈 문자열.
	 *
	 * @param array $interfaces  host.get 의 'interfaces' 값. [['type' => .., 'useip' => .., 'ip' => .., 'dns' => ..], ...]
	 *
	 * @return string  IP 또는 DNS 이름.
	 */
	private static function ipOf(array $interfaces): string {
		foreach ([INTERFACE_TYPE_AGENT, INTERFACE_TYPE_SNMP, INTERFACE_TYPE_JMX, INTERFACE_TYPE_IPMI] as $type) {
			foreach ($interfaces as $interface) {
				if ($interface['type'] == $type) {
					return $interface['useip'] == INTERFACE_USE_IP ? $interface['ip'] : $interface['dns'];
				}
			}
		}

		return '';
	}

	/**
	 * 가동 초를 "158 일, 13:38:45" 형식으로 바꾼다. (5버전 화면의 표시 형식)
	 *
	 * 예) 13700325초 -> 158 일, 13:38:45
	 *   intdiv(초, 86400)  = 일
	 *   초 % 86400         = 하루로 나눈 나머지 초. gmdate('H:i:s', 나머지) 가 시:분:초로 바꿔 준다.
	 *                        (gmdate 는 시간대를 더하지 않는다. date 를 쓰면 한국 시간 9시간이 더해져 틀린다)
	 */
	private static function formatUptime(int $seconds): string {
		return Lang::t('%1$d days, %2$s', intdiv($seconds, SEC_PER_DAY), gmdate('H:i:s', $seconds % SEC_PER_DAY));
	}

	/**
	 * 목록 정렬.
	 *
	 * 가동 시간(uptime)은 숫자로 비교한다. 값이 없는 호스트(null)는 방향과 관계없이 맨 뒤에 둔다.
	 * 값이 같으면 호스트 이름 순으로 놓아서 페이지를 넘겨도 순서가 흔들리지 않게 한다.
	 *
	 *   <=>            : 왼쪽이 작으면 -1, 같으면 0, 크면 1.
	 *   strnatcasecmp  : 대소문자를 무시하고, 숫자가 섞인 이름(web2 < web10)을 자연스럽게 비교한다.
	 */
	private static function sortRows(array $rows, string $sort_field, string $sort_order): array {
		$direction = $sort_order === ZBX_SORT_DOWN ? -1 : 1;

		usort($rows, static function (array $a, array $b) use ($sort_field, $direction): int {
			if ($sort_field === 'uptime' && $a['uptime'] !== $b['uptime']) {
				if ($a['uptime'] === null) {
					return 1;
				}

				if ($b['uptime'] === null) {
					return -1;
				}

				return $direction * ($a['uptime'] <=> $b['uptime']);
			}

			$cmp = strnatcasecmp($a['host'], $b['host']);

			// 가동 시간이 같아서 이름으로 넘어온 경우는 항상 이름 오름차순이다.
			return $sort_field === 'host' ? $direction * $cmp : $cmp;
		});

		return $rows;
	}

	/**
	 * 필터 입력값에서 숫자만 남긴다. 숫자가 아니면 빈 문자열(필터 없음).
	 *
	 * 예) ' 30 ' -> '30',  'abc' -> '',  '' -> ''
	 */
	private function normalizeDays(string $value): string {
		$value = trim($value);

		// ctype_digit: 문자열이 0~9 로만 이루어졌는지 검사한다.
		return ctype_digit($value) ? $value : '';
	}
}
