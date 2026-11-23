<?php declare(strict_types = 0);
/*
 * 호스트 관련 공용 도우미. 여러 화면이 같이 쓴다.
 *
 * 사용 예:
 *   $hosts = API::Host()->get([
 *       'output' => ['hostid', 'host', 'description'],
 *       'selectInterfaces' => HostHelper::INTERFACE_FIELDS
 *   ]);
 *   $ip = HostHelper::mainIp($hosts[0]['interfaces']);
 *
 * ── 이 파일이 하는 일 ──
 *   호스트 한 대에 인터페이스(IP)가 여러 개 있을 때, 표의 IP 열에 보여 줄 "대표 IP" 하나를 고른다.
 *   화면마다 같은 규칙을 쓰도록 한 곳에 모았다.
 *
 * ── 누가 쓰는가 ──
 *   IP 열이 있는 화면의 컨트롤러와 서비스:
 *   actions/reports/uptime/UptimeList.php, actions/reports/serverusage/ServerUsageController.php,
 *   actions/reports/dailycheck/DailyCheckTop.php, actions/reports/peakevent/PeakEventList.php,
 *   includes/SwInventoryService.php, includes/WinPatchService.php
 */

namespace Modules\DMove\Includes;

class HostHelper {

	/** mainIp() 가 필요로 하는 인터페이스 필드. host.get 의 selectInterfaces 에 그대로 넘긴다. */
	public const INTERFACE_FIELDS = ['ip', 'main', 'type'];

	/**
	 * 호스트의 대표 IP.
	 *
	 * 호스트는 인터페이스를 종류별(Agent, SNMP, IPMI, JMX)로 가질 수 있고 종류마다 기본(main)이 하나씩 있다.
	 * 우선순위: Agent 기본 인터페이스 > 다른 종류의 기본 인터페이스 > 아무 인터페이스.
	 * IP 가 하나도 없으면(DNS 로만 등록) 빈 문자열.
	 *
	 * 고르는 방법: 인터페이스마다 점수를 매기고 점수가 가장 높은 것의 IP 를 쓴다.
	 *   3점 = Agent 종류의 기본 인터페이스,  2점 = 다른 종류의 기본 인터페이스,  1점 = 기본이 아닌 인터페이스
	 * 점수가 같으면 먼저 나온 것을 쓴다.
	 *
	 * 예) [['ip' => '10.0.0.2', 'main' => '1', 'type' => '2'],     // SNMP 기본  -> 2점
	 *      ['ip' => '10.0.0.1', 'main' => '1', 'type' => '1']]     // Agent 기본 -> 3점
	 *     -> '10.0.0.1'
	 *
	 * @param array $interfaces  host.get 의 'interfaces' 값. [['ip' => .., 'main' => .., 'type' => ..], ...]
	 *
	 * @return string  대표 IP. 예) '10.0.0.1'  IP 가 하나도 없으면 ''.
	 */
	public static function mainIp(array $interfaces): string {
		$best_ip = '';
		$best_rank = 0;

		foreach ($interfaces as $interface) {
			if ($interface['ip'] === '') {
				continue;
			}

			// INTERFACE_PRIMARY(1) = 그 종류의 기본 인터페이스. INTERFACE_TYPE_AGENT(1) = Agent 종류. (코어 상수)
			if ($interface['main'] == INTERFACE_PRIMARY) {
				$rank = $interface['type'] == INTERFACE_TYPE_AGENT ? 3 : 2;
			}
			else {
				$rank = 1;
			}

			if ($rank > $best_rank) {
				$best_rank = $rank;
				$best_ip = $interface['ip'];
			}
		}

		return $best_ip;
	}
}
