<?php declare(strict_types = 0);
/*
 * Peak 이벤트 분석 화면 컨트롤러.
 *
 *   화면 : zabbix.php?action=peakevent.list
 *   메뉴 : 보고서 > Peak 이벤트 분석   (Module.php 의 REPORT_MENUS)
 *   5버전: peak_event.php  (zabbix.php?action= 형식이 아닌 독립 PHP 파일이었다)
 *
 * 5버전 화면 구성
 *   필터 : 기간 선택(코어의 시간 선택기. 기본 "최신 1 시간")
 *   표   : 호스트, IP 주소, Description, Peak이벤트 유형(트리거 이름), 심각도, 상태 변경 횟수
 *          상태 변경 횟수가 많은 순서로 정렬
 *
 * 무엇을 보여 주나
 *   선택한 기간 동안 "장애(PROBLEM) 이벤트가 몇 번 발생했는지"를 트리거별로 센 것이다.
 *   같은 트리거가 장애 <-> 정상을 자주 오갈수록 숫자가 커진다.
 *   코어의 "보고서 > 상위 100 트리거" 와 같은 집계에 IP 와 Description 열을 더한 화면이다.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ TODO(고객환경) 집계 기준 확인                                           │
 * │ 5버전 peak_event.php 소스는 확인하지 못했다. 화면의 열 이름이 코어의    │
 * │ "상위 100 트리거" 보고서와 같아서 같은 방식(기간 내 PROBLEM 이벤트 수)  │
 * │ 으로 구현했다. 5버전 소스에서 아래를 확인해 다르면 getRows() 를 고친다. │
 * │   - 세는 대상: PROBLEM 이벤트만인지, 복구(OK) 이벤트도 포함하는지       │
 * │   - 특정 트리거만 대상인지 (이름이 "[Zabbix|...]" 로 시작하는 것 등)    │
 * │   - 표시 건수 제한 (여기서는 상위 MAX_ROWS 개)                          │
 * │   - Description 의 출처 (여기서는 Zabbix 호스트 설명)                   │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * 이 화면에 속한 파일
 *   actions/reports/peakevent/PeakEventList.php   컨트롤러 (이 파일)
 *   views/reports/peakevent.list.php              화면 뷰
 *   includes/export/                              내보내기 플러그인 (CSV / Excel)
 */

namespace Modules\DMove\Actions\Reports\PeakEvent;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CProfile;
use CSeverityHelper;
use Modules\DMove\Includes\Export\ExportTrait;
use Modules\DMove\Includes\HostHelper;
use Modules\DMove\Includes\Lang;

class PeakEventList extends CController {

	// 내보내기 플러그인. (includes/export/ExportTrait.php)
	use ExportTrait;

	/**
	 * 시간 선택기가 기간을 저장하는 프로파일 키. 뷰의 addTimeSelector 와 같은 값을 써야 한다.
	 * (다른 화면의 PROFILE_PREFIX 와 달리 끝에 점이 없고 '.filter' 까지 포함한다. 코어 시간 선택기의 규칙이다)
	 */
	private const TIMELINE_PROFILE = 'web.dmove.peakevent.filter';

	/** 화면과 내보내기에 표시할 최대 트리거 수. */
	private const MAX_ROWS = 100;

	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			/*
			 * 시간 선택기가 보내는 기간. 'range_time' = "now-1h", "now", "2026-01-02 03:04:05" 같은 시간 표현.
			 * 아래 validateTimeSelectorPeriod() 가 시작 < 종료, 최소/최대 기간을 추가로 검사한다.
			 */
			'from' =>	'range_time',
			'to' =>		'range_time'
		] + self::exportInputFields();

		$ret = $this->validateInput($fields) && $this->validateTimeSelectorPeriod();

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 * 표시되는 트리거와 호스트는 API 가 사용자 권한에 맞게 걸러 준다.
	 */
	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		/*
		 * ── 1) 기간 ──
		 * 시간 선택기에서 고른 기간은 프로파일에 저장되고, 다음에 들어와도 유지된다.
		 *   getInputs()                : 요청에 from / to 가 있으면 배열에 채운다. (없으면 null 그대로)
		 *   updateTimeSelectorPeriod() : from / to 가 있으면 프로파일에 저장한다.
		 *   getTimeSelectorPeriod()    : 저장된 기간을 읽어 계산한다. 저장된 값이 없으면 코어 기본 기간(최신 1시간).
		 *                                결과의 from_ts / to_ts 가 유닉스 시간(초)이다.
		 * (ui/include/func.inc.php)
		 */
		$timeselector_options = [
			'profileIdx' => self::TIMELINE_PROFILE,
			'profileIdx2' => 0,
			'from' => null,
			'to' => null
		];
		$this->getInputs($timeselector_options, ['from', 'to']);
		updateTimeSelectorPeriod($timeselector_options);

		$timeline = getTimeSelectorPeriod($timeselector_options);

		// ── 2) 데이터 ──
		$rows = $this->getRows((int) $timeline['from_ts'], (int) $timeline['to_ts']);

		$title = Lang::t('Peak event analysis');

		// ── 3) 내보내기 요청이면 파일로 응답하고 끝낸다 ──
		if ($this->isExportRequest()) {
			foreach ($rows as &$row) {
				// 심각도 숫자(0~5)를 이름으로 바꾼다. 관리 > 일반 > 트리거 표시 옵션에서 정한 이름이 나온다.
				$row['severity'] = CSeverityHelper::getName((int) $row['severity']);
			}
			unset($row);

			$this->setExportResponse($title, 'peak_event', self::getColumns(), $rows);

			return;
		}

		// ── 4) 화면 응답 ──
		$response = new CControllerResponseData([
			'action' => 'peakevent.list',
			'export_action' => 'peakevent.export',
			'title' => $title,
			'columns' => self::getColumns(),
			'timeline' => $timeline,
			'timeline_profile' => self::TIMELINE_PROFILE,
			'active_tab' => CProfile::get(self::TIMELINE_PROFILE.'.active', 1),
			'rows' => $rows
		]);
		$response->setTitle($title);

		$this->setResponse($response);
	}

	/**
	 * 표의 열 정의. 화면의 제목 줄과 내보내기 파일이 같이 쓴다.
	 */
	private static function getColumns(): array {
		return [
			'host' => _('Host'),
			'ip' => _('IP address'),
			'description' => _('Description'),
			'trigger' => Lang::t('Peak event type'),
			'severity' => _('Severity'),
			// 숫자 열로 지정하면 엑셀에 숫자로 들어가 정렬/합계가 된다.
			'count' => ['label' => _('Number of status changes'), 'type' => 'number']
		];
	}

	/**
	 * 기간 내 장애 이벤트 수를 트리거별로 세어, 많은 순서로 돌려준다.
	 *
	 * @param int $time_from  시작 시각(유닉스 초)
	 * @param int $time_till  종료 시각(유닉스 초)
	 *
	 * @return array  [['host' => .., 'ip' => .., 'description' => .., 'trigger' => .., 'severity' => 0~5, 'count' => N], ...]
	 */
	private function getRows(int $time_from, int $time_till): array {
		/*
		 * 1) 이벤트를 트리거(objectid)별로 묶어 개수만 센다. DB 가 세어 주므로 이벤트가 많아도 빠르다.
		 *    결과: [['objectid' => 트리거ID, 'rowscount' => 개수], ...]
		 *
		 *    source / object : "트리거가 만든 이벤트"만.
		 *    value           : TRIGGER_VALUE_TRUE = 장애(PROBLEM) 이벤트만. 복구 이벤트는 세지 않는다.
		 */
		$counts = API::Event()->get([
			'countOutput' => true,
			'groupBy' => ['objectid'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'value' => TRIGGER_VALUE_TRUE,
			'time_from' => $time_from,
			'time_till' => $time_till,
			'sortfield' => ['rowscount'],
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => self::MAX_ROWS
		]);

		if (!$counts) {
			return [];
		}

		// array_column(배열, 값 키, 색인 키): [트리거ID => 개수]
		$count_by_triggerid = array_column($counts, 'rowscount', 'objectid');

		/*
		 * 2) 트리거 이름, 심각도, 소속 호스트.
		 *    expandDescription : 이름 안의 매크로({HOST.NAME} 등)를 실제 값으로 바꾼다.
		 *    selectHosts       : 각 트리거에 'hosts' => [[hostid, name], ...] 를 붙인다.
		 */
		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'priority'],
			'selectHosts' => ['hostid', 'name'],
			'expandDescription' => true,
			'triggerids' => array_keys($count_by_triggerid),
			'preservekeys' => true
		]);

		// 3) 그 호스트들의 설명과 IP 를 한 번에 조회한다. (호스트마다 조회하면 느리다)
		$hostids = [];

		foreach ($triggers as $trigger) {
			foreach ($trigger['hosts'] as $host) {
				// 키로 넣으면 중복이 자동으로 없어진다.
				$hostids[$host['hostid']] = true;
			}
		}

		$hosts = $hostids
			? API::Host()->get([
				'output' => ['hostid', 'description'],
				'selectInterfaces' => HostHelper::INTERFACE_FIELDS,
				'hostids' => array_keys($hostids),
				'preservekeys' => true
			])
			: [];

		// 4) 행 만들기. 개수가 많은 순서($count_by_triggerid 의 순서)를 유지한다.
		$rows = [];

		foreach ($count_by_triggerid as $triggerid => $count) {
			// 그 사이에 삭제됐거나 사용자가 볼 권한이 없는 트리거는 건너뛴다.
			if (!array_key_exists($triggerid, $triggers)) {
				continue;
			}

			$trigger = $triggers[$triggerid];
			// 트리거는 보통 호스트 하나에 속한다. 여러 호스트에 걸친 트리거는 첫 번째 호스트로 표시한다.
			$host = $trigger['hosts'] ? reset($trigger['hosts']) : null;
			$host_detail = ($host !== null && array_key_exists($host['hostid'], $hosts)) ? $hosts[$host['hostid']] : null;

			$rows[] = [
				'host' => $host !== null ? $host['name'] : '',
				'ip' => $host_detail !== null ? HostHelper::mainIp($host_detail['interfaces']) : '',
				'description' => $host_detail !== null ? $host_detail['description'] : '',
				'trigger' => $trigger['description'],
				'severity' => (int) $trigger['priority'],
				'count' => (int) $count
			];
		}

		return $rows;
	}
}
