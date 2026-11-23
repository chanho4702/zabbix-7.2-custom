<?php declare(strict_types = 0);
/*
 * OS / WEB / WAS / DB / 이중화 솔루션 값을 결정한다.
 *
 * ── 이 파일이 하는 일 ──
 *   SW 인벤토리 화면의 한 행에 표시할 다섯 가지 값(os, web, was, db, duplex)을 정한다.
 *   ITSM 행에 값이 있으면 그것을 쓰고, 없으면 Zabbix 호스트 인벤토리의 값을 쓴다.
 *
 * ── 누가 쓰는가 ──
 *   includes/SwInventoryService.php 한 곳.
 *     getInventoryFields()  DB 에서 읽을 인벤토리 열을 정할 때
 *     resolve()             행을 만들 때 (행마다 한 번)
 *
 * 5버전 대응: 인벤토리 트레잇(...Inventoris.php)의 middle_inventory()
 *
 * 값의 출처는 Zabbix 호스트 인벤토리다. 어느 컬럼이 어느 인벤토리 필드인지는 아래 INVENTORY_FIELDS 에 있다.
 * (5버전 소스에서 확인한 매핑이다. 바뀌었으면 그 상수만 고치면 된다)
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ TODO(고객환경)                                                          │
 * │ 5버전에는 아이템 값(history_str)에서 읽는 경로도 코드에 있었다.         │
 * │ 실제 화면의 값이 인벤토리가 아니라 아이템에서 오는 것으로 확인되면      │
 * │ 아래 resolve() 안에 표시된 위치에 조회 로직을 추가한다.                 │
 * │ (확인할 자료: docs/04_남은_작업/고객환경_체크리스트.md 1-6, 1-7)                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

namespace Modules\DMove\Includes;

class SoftwareResolver {

	/**
	 * 화면 컬럼 키 => Zabbix 호스트 인벤토리 필드 이름.
	 *
	 * 인벤토리 필드 이름은 DB host_inventory 테이블의 컬럼 이름과 같다.
	 * 화면(데이터 수집 > 호스트 > 인벤토리 탭)의 라벨과 대응:
	 *   os             = OS
	 *   software_app_a = 소프트웨어 애플리케이션 A   (5버전에서 WEB 으로 사용)
	 *   software_app_b = 소프트웨어 애플리케이션 B   (WAS)
	 *   software_app_c = 소프트웨어 애플리케이션 C   (DB)
	 *   software_app_d = 소프트웨어 애플리케이션 D   (이중화 솔루션)
	 *
	 * 컬럼을 추가하려면 여기에 한 줄 넣고, 컨트롤러 getColumns() 와 뷰의 addRow 에도 추가한다.
	 */
	public const INVENTORY_FIELDS = [
		'os' => 'os',
		'web' => 'software_app_a',
		'was' => 'software_app_b',
		'db' => 'software_app_c',
		'duplex' => 'software_app_d'
	];

	/**
	 * 읽어야 하는 인벤토리 필드 이름 목록. INVENTORY_FIELDS 의 오른쪽 값만 모은 것이다.
	 *
	 * SwInventoryService::fetchAllHosts() 가 이 목록으로 host_inventory 표에서 읽을 열을 정한다.
	 * (host.get 을 쓴다면 selectInventory 옵션에 그대로 넘길 수 있는 형태다)
	 *
	 *   array_unique : 같은 필드가 두 번 있으면 하나만 남긴다.
	 *   array_values : 키를 0 부터 다시 매긴다.
	 *
	 * @return array  ['os', 'software_app_a', 'software_app_b', 'software_app_c', 'software_app_d']
	 */
	public function getInventoryFields(): array {
		return array_values(array_unique(self::INVENTORY_FIELDS));
	}

	/**
	 * 한 행의 os/web/was/db/duplex 값을 만든다.
	 *
	 * 값마다 순서대로 찾는다: 1순위 ITSM 행의 값 -> 2순위 Zabbix 호스트 인벤토리 -> 둘 다 없으면 빈 문자열.
	 *
	 * 예) ITSM 행에 os 가 없고 호스트 인벤토리의 os 가 'Linux' 이면 -> ['os' => 'Linux', 'web' => '', ...]
	 *
	 * @param array      $itsm_row  ItsmRepository::fetch() 의 행.
	 * @param array|null $zbx_host  매칭된 Zabbix 호스트(host.get 결과와 같은 모양). 매칭이 안 됐으면 null.
	 *
	 * @return array  ['os' => '', 'web' => '', 'was' => '', 'db' => '', 'duplex' => '']
	 */
	public function resolve(array $itsm_row, ?array $zbx_host): array {
		$values = [];

		// 인벤토리가 비활성인 호스트는 inventory 가 빈 배열이다. (host.get 도, SwInventoryService::fetchAllHosts() 도 그렇게 준다)
		$inventory = ($zbx_host !== null && is_array($zbx_host['inventory'] ?? null)) ? $zbx_host['inventory'] : [];

		foreach (self::INVENTORY_FIELDS as $column => $inventory_field) {
			// 1순위: ITSM 이 준 값.
			$value = trim((string) ($itsm_row[$column] ?? ''));

			// 2순위: Zabbix 호스트 인벤토리 필드.
			if ($value === '') {
				// ?? '' : 그 필드가 없으면 경고 없이 빈 문자열을 쓴다.
				$value = trim((string) ($inventory[$inventory_field] ?? ''));
			}

			/*
			 * TODO(고객환경): 아이템 값으로 판별하는 규칙이 있으면 여기에 추가한다.
			 * 호스트마다 API 를 호출하면 느리므로, SwInventoryService::getRows() 에서
			 * API::Item()->get() 을 "한 번" 호출해 hostid 로 묶어 둔 뒤 여기로 넘겨받는 방식으로 만든다.
			 */

			$values[$column] = $value;
		}

		return $values;
	}
}
