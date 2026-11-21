<?php declare(strict_types = 0);
/*
 * 서버 시간 확인 화면 컨트롤러. (화면 구성 완료, 데이터 조회는 TODO)
 *
 *   화면 : zabbix.php?action=uptime.list
 *   메뉴 : 보고서 > 서버 시간 확인   (Module.php 의 REPORT_MENUS)
 *   5버전: zabbix.php?action=uptime.view  (컨트롤러 CControllerUptimeView.php)
 *
 * 5버전 화면 구성
 *   필터 : 호스트 그룹(선택창), 호스트(선택창), OS/Version(입력), 서버 Uptime(입력, "day 이상")
 *   표   : 호스트, IP, Description, OS, 서버 Time, 서버 start
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ TODO(고객환경) 데이터 조회부가 비어 있다. -> 아래 getRows()             │
 * │                                                                         │
 * │ 5버전 CControllerUptimeView.php 에서 확인할 것                          │
 * │   - 서버 Time / 서버 start 를 어떤 아이템 키에서 읽는가                 │
 * │     (Zabbix 표준 키라면 system.uptime = 가동 초, system.localtime 등)   │
 * │   - OS 는 어디서 읽는가 (인벤토리 os 필드인지, 아이템 값인지)           │
 * │   - Description 은 Zabbix 호스트 설명인지 ITSM description 인지         │
 * │   - "day 이상" 필터의 비교 기준                                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * 이 화면에 속한 파일
 *   actions/reports/uptime/UptimeList.php   컨트롤러 (이 파일)
 *   views/reports/uptime.list.php     화면 뷰
 *   includes/filter/HostFilter.php    호스트 그룹/호스트 선택 필터 (여러 화면 공용)
 */

namespace Modules\DMove\Actions\Reports\Uptime;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CUrl;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;

class UptimeList extends CController {

	/** 필터 값을 사용자별로 저장하는 프로파일 키 접두어. 화면마다 겹치지 않게 'web.dmove.<화면>.' 으로 짓는다. */
	private const PROFILE_PREFIX = 'web.dmove.uptime.';

	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'page' =>				'ge 1',
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
	 * 접근 권한. 지금은 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 * 표시되는 호스트는 API 가 사용자 권한에 맞게 걸러 준다.
	 *
	 * TODO(고객환경): 권한 정책에 맞게 바꾼다.
	 */
	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

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

		// ── 3) 데이터 조회 ──
		$rows = $this->getRows($host_filter, $filter);

		// ── 4) 뷰로 넘길 데이터 ──
		$data = [
			'action' => $this->getAction(),
			'title' => Lang::t('Server time check'),
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'filter.active', 1),
			// 선택창에 표시할 값(ID + 이름)으로 바꿔 넘긴다.
			'host_filter' => HostFilter::toViewData($host_filter),
			'filter' => $filter,
			'data_ready' => false	// TODO(고객환경): getRows() 를 구현하면 true 로 바꾼다. (뷰의 안내 상자가 사라진다)
		];

		// 페이징. paginate() 가 $rows 를 현재 페이지 분량으로 잘라 준다.
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
	 * TODO(고객환경): 구현한다. 뷰가 기대하는 행 형식은 아래와 같다.
	 *
	 *   [
	 *     'hostid'       => '10084',
	 *     'host'         => 'web-server-01',
	 *     'ip'           => '10.0.0.1',
	 *     'description'  => '...',
	 *     'os'           => 'Red Hat Enterprise 7.9',
	 *     'server_time'  => '158 일, 13:18:45',        // 5버전 표시 형식
	 *     'server_start' => '2026/06/14 05:13:26'      // 5버전 표시 형식
	 *   ]
	 *
	 * 구현 순서 예시 (아이템 키는 5버전 소스에서 확인한 값으로 바꾼다)
	 *
	 *   // 1) 필터에 맞는 호스트. HostFilter::toApiOptions() 가 groupids / hostids 조건을 만들어 준다.
	 *   //    (맨 위에 use API; 를 추가해야 한다)
	 *   $hosts = API::Host()->get(HostFilter::toApiOptions($host_filter) + [
	 *       'output' => ['hostid', 'host', 'description'],
	 *       'selectInterfaces' => ['ip', 'main', 'type'],
	 *       'selectInventory' => ['os'],
	 *       'preservekeys' => true
	 *   ]);
	 *
	 *   // 2) 그 호스트들의 가동시간 아이템을 "한 번에" 조회. (호스트마다 조회하면 느리다)
	 *   $items = API::Item()->get([
	 *       'output' => ['itemid', 'hostid', 'lastvalue', 'lastclock'],
	 *       'hostids' => array_keys($hosts),
	 *       'filter' => ['key_' => 'system.uptime']
	 *   ]);
	 *
	 *   // 3) lastvalue(가동 초)로 표시 문자열을 만든다.
	 *   //    서버 start = date('Y/m/d H:i:s', $item['lastclock'] - $item['lastvalue'])
	 *   //    N 일 이상 필터 = $item['lastvalue'] >= $filter['uptime_days'] * 86400
	 *
	 * 5버전은 DBselect 로 zabbix.items / history 테이블을 직접 읽었다. 그러면 사용자 권한이 무시되고
	 * 스키마 이름(zabbix.)이 다르면 깨지므로 위처럼 API 를 쓴다.
	 */
	private function getRows(array $host_filter, array $filter): array {
		return [];
	}

	/** 숫자만 남긴다. 숫자가 아니면 빈 문자열(필터 없음). */
	private function normalizeDays(string $value): string {
		$value = trim($value);

		// ctype_digit: 문자열이 0~9 로만 이루어졌는지 검사한다.
		return ctype_digit($value) ? $value : '';
	}
}
