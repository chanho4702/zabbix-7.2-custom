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
	 * @param array $interfaces  host.get 의 'interfaces' 값. [['ip' => .., 'main' => .., 'type' => ..], ...]
	 */
	public static function mainIp(array $interfaces): string {
		$best_ip = '';
		$best_rank = 0;

		foreach ($interfaces as $interface) {
			if ($interface['ip'] === '') {
				continue;
			}

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
