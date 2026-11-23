<?php declare(strict_types = 0);
/*
 * Peak 이벤트 분석 화면 컨트롤러.
 *
 *   화면 : zabbix.php?action=peakevent.list
 *   메뉴 : 보고서 > Peak 이벤트 분석   (Module.php 의 REPORT_MENUS)
 *   5버전: peak_event.php  (zabbix.php?action= 형식이 아닌 독립 PHP 파일이었다)
 *
 * ── 화면 구성 (5버전 기준) ──
 *   필터 : 호스트 그룹, 호스트, Peak이벤트 유형(입력), 심각도(체크박스)
 *          기간 선택(코어의 시간 선택기. 기본 "최신 1 시간")
 *   표   : 호스트, IP 주소, Description, Peak이벤트 유형(트리거 이름), 심각도, 상태 변경 횟수
 *          상태 변경 횟수가 많은 순서로 정렬
 *   이 모듈에서 더한 것: [CSV 내보내기] 버튼
 *          (zabbix.php?action=peakevent.export&format=csv 또는 xlsx)
 *
 * ── 이 화면이 하는 일 ──
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
 * ── 데이터 흐름 ──
 *   doAction()
 *     ├─ 시간 선택기의 기간을 저장하고 읽는다 (코어 함수 updateTimeSelectorPeriod / getTimeSelectorPeriod)
 *     ├─ getRows()
 *     │    ├─ event.get   : 기간 안의 장애 이벤트를 트리거별로 세고, 많은 순으로 100개
 *     │    ├─ trigger.get : 그 트리거의 이름, 심각도, 소속 호스트
 *     │    └─ host.get    : 그 호스트의 설명과 IP
 *     ├─ 내보내기 요청(peakevent.export)이면 파일로 응답하고 끝
 *     └─ 뷰(views/reports/peakevent.list.php)로 넘긴다   (페이지를 나누지 않는다. 최대 100줄)
 *
 * ── 이 화면에 속한 파일 ──
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
use CWebUser;
use Modules\DMove\Includes\Export\ExportTrait;
use Modules\DMove\Includes\Filter\HostFilter;
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

	/** 필터 값(호스트 그룹, 호스트, Peak이벤트 유형, 심각도)을 사용자별로 저장하는 프로파일 키 접두어. */
	private const PROFILE_PREFIX = 'web.dmove.peakevent.';

	/** 화면과 내보내기에 표시할 최대 트리거 수. */
	private const MAX_ROWS = 100;

	/**
	 * 컨트롤러가 만들어질 때 한 번 불린다.
	 * 조회만 하는 화면이라 CSRF 토큰 검사를 끈다. (값을 바꾸는 화면에서는 끄면 안 된다)
	 */
	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

	/**
	 * 주소로 들어온 값 검사. 여기에 적지 않은 이름의 값이 오거나 형식이 틀리면 화면을 열지 않는다.
	 *
	 *   from, to : 시간 선택기가 보내는 기간의 시작과 끝.
	 *   format   : 내보내기 형식(csv 또는 xlsx). self::exportInputFields() 가 더해 준다.
	 *
	 * 예) zabbix.php?action=peakevent.list&from=now-1h&to=now
	 *
	 * @return bool  검사를 통과하면 true.
	 */
	protected function checkInput(): bool {
		$fields = [
			/*
			 * 시간 선택기가 보내는 기간. 'range_time' = "now-1h", "now", "2026-01-02 03:04:05" 같은 시간 표현.
			 * 아래 validateTimeSelectorPeriod() 가 시작 < 종료, 최소/최대 기간을 추가로 검사한다.
			 */
			'from' =>	'range_time',
			'to' =>		'range_time',
			// 필터. [적용] -> filter_set=1, [리셋] -> filter_rst=1
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1',
			// Peak이벤트 유형(트리거 이름의 일부) 입력.
			'filter_name' =>		'string',
			// 심각도 체크박스. 체크한 심각도 숫자의 배열로 온다. 예) [2, 4]
			'filter_severities' =>	'array'
		// 호스트 그룹/호스트 선택 필터의 규칙(filter_groupids, filter_hostids)을 더한다. (includes/filter/HostFilter.php)
		] + HostFilter::INPUT_FIELDS + self::exportInputFields();

		// && : 앞의 검사가 통과했을 때만 뒤의 검사를 한다. validateTimeSelectorPeriod() 는 부모 CController 의 메소드다.
		$ret = $this->validateInput($fields) && $this->validateTimeSelectorPeriod();

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 * 표시되는 트리거와 호스트는 API 가 사용자 권한에 맞게 걸러 준다.
	 *
	 * @return bool  화면을 열어도 되면 true.
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 화면을 만드는 본체. 목록 화면(peakevent.list)과 내보내기(peakevent.export)가 같이 쓴다.
	 *
	 * 하는 일:
	 *   1) 시간 선택기의 기간을 저장하고, 시작/끝 시각을 구한다.
	 *   2) getRows() 로 목록을 구한다.
	 *   3) 내보내기 요청이면 심각도를 이름으로 바꿔서 파일로 응답한다.
	 *   4) 아니면 뷰로 넘긴다.
	 *
	 * 뷰가 받는 $data:
	 *   [
	 *     'action'           => 'peakevent.list',
	 *     'export_action'    => 'peakevent.export',              // 내보내기 버튼이 부를 액션 이름
	 *     'title'            => 화면 제목,
	 *     'columns'          => getColumns() 의 결과,            // 표의 열 정의
	 *     'timeline'         => getTimeSelectorPeriod() 의 결과, // 시간 선택기에 표시할 기간
	 *     'timeline_profile' => 'web.dmove.peakevent.filter',    // 시간 선택기가 기간을 저장하는 프로파일 키
	 *     'active_tab'       => 1,                               // 필터 영역에서 열려 있는 탭
	 *     'rows'             => getRows() 의 결과                // 심각도는 숫자(0~5) 그대로
	 *   ]
	 */
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

		/*
		 * ── 1-2) 필터 저장/초기화 (5버전 화면의 필터: 호스트 그룹, 호스트, Peak이벤트 유형, 심각도) ──
		 *   CProfile::updateArray() : 배열 값을 사용자 프로파일에 저장한다. 심각도는 숫자라서 PROFILE_TYPE_INT.
		 *   array_map('intval', ...) : 값을 모두 정수로 바꾼다.
		 */
		if ($this->hasInput('filter_set')) {
			HostFilter::save(self::PROFILE_PREFIX, $this->getInput('filter_groupids', []),
				$this->getInput('filter_hostids', [])
			);
			CProfile::update(self::PROFILE_PREFIX.'filter.name', trim($this->getInput('filter_name', '')),
				PROFILE_TYPE_STR
			);
			CProfile::updateArray(self::PROFILE_PREFIX.'filter.severities',
				self::normalizeSeverities($this->getInput('filter_severities', [])), PROFILE_TYPE_INT
			);
		}
		elseif ($this->hasInput('filter_rst')) {
			HostFilter::reset(self::PROFILE_PREFIX);
			CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.name');
			CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.severities');
		}

		// 저장된 필터 읽기. 내보내기 요청도 이 값을 읽으므로 화면과 같은 조건으로 파일이 만들어진다.
		$host_filter = HostFilter::load(self::PROFILE_PREFIX);	// ['groupids' => [...], 'hostids' => [...]]
		$filter = [
			'name' => CProfile::get(self::PROFILE_PREFIX.'filter.name', ''),
			'severities' => self::normalizeSeverities(CProfile::getArray(self::PROFILE_PREFIX.'filter.severities', []))
		];

		// ── 2) 데이터 ──
		$rows = $this->getRows((int) $timeline['from_ts'], (int) $timeline['to_ts'], $host_filter, $filter);

		$title = Lang::t('Peak event analysis');

		// ── 3) 내보내기 요청이면 파일로 응답하고 끝낸다 ──
		if ($this->isExportRequest()) {
			// &$row : 복사본이 아니라 배열 안의 원본을 가리킨다. 그래서 $row 를 바꾸면 $rows 가 바뀐다.
			// 반복이 끝나면 unset($row) 로 연결을 끊는다. (끊지 않으면 뒤에서 $row 를 쓸 때 마지막 줄이 바뀐다)
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
			// 선택창에 표시할 값(ID + 이름)으로 바꿔 넘긴다.
			'host_filter' => HostFilter::toViewData($host_filter),
			'filter' => $filter,
			'rows' => $rows
		]);
		$response->setTitle($title);

		$this->setResponse($response);
	}

	/**
	 * 심각도 입력값을 0~5 사이의 정수 배열로 정리한다. 범위 밖의 값은 버린다.
	 *
	 * 예) ['2', '4', '9', 'x'] -> [2, 4]
	 *
	 *   array_map('intval', ...) : 각 값을 정수로 바꾼다.
	 *   array_filter(..., 함수)  : 함수가 true 를 돌려준 값만 남긴다.
	 *   array_unique             : 같은 값은 하나만 남긴다.
	 *   array_values             : 키를 0 부터 다시 매긴다.
	 *
	 * @return array  예) [2, 4]
	 */
	private static function normalizeSeverities(array $severities): array {
		// is_numeric 으로 숫자 모양인 값만 먼저 남긴다. ('x' 를 intval 하면 0 이 되어 "미분류"로 잘못 들어간다)
		$severities = array_map('intval', array_filter($severities, 'is_numeric'));

		return array_values(array_unique(array_filter($severities,
			static fn(int $severity): bool => $severity >= TRIGGER_SEVERITY_NOT_CLASSIFIED
				&& $severity < TRIGGER_SEVERITY_COUNT
		)));
	}

	/**
	 * 표의 열 정의. 화면의 제목 줄과 내보내기 파일이 같이 쓴다.
	 *
	 * 키는 getRows() 가 만드는 행의 키와 같다. 값은 열 제목이다.
	 *   '글자'                                    텍스트 열
	 *   ['label' => '글자', 'type' => 'number']   숫자 열
	 *
	 *   _('Host')          코어의 번역 함수. Zabbix 가 가진 번역을 쓴다.
	 *   Lang::t('...')     이 모듈의 번역 함수. lang/ko.php 의 번역을 쓴다.
	 *
	 * @return array  [행의 키 => 열 제목 또는 ['label' => .., 'type' => ..]]
	 */
	private static function getColumns(): array {
		return [
			'host' => _('Host'),
			'ip' => _('IP address'),
			'description' => _('Description'),
			'trigger' => Lang::t('Peak event type'),
			'severity' => _('Severity'),
			// 숫자 열로 지정하면 엑셀에 숫자로 들어가 정렬/합계가 된다.
			'count' => ['label' => Lang::t('Number of status changes'), 'type' => 'number']
		];
	}

	/**
	 * 기간 내 장애 이벤트 수를 트리거별로 세어, 많은 순서로 돌려준다. 최대 MAX_ROWS(100)줄.
	 * 행 1개 = 트리거 1개.
	 *
	 * @param int   $time_from    시작 시각(유닉스 초)
	 * @param int   $time_till    종료 시각(유닉스 초)
	 * @param array $host_filter  ['groupids' => [...], 'hostids' => [...]]  비어 있으면 볼 수 있는 전체 호스트.
	 * @param array $filter       ['name' => 'CPU', 'severities' => [2, 4]]  비어 있으면 조건 없음.
	 *
	 * @return array  [['host' => .., 'ip' => .., 'description' => .., 'trigger' => .., 'severity' => 0~5, 'count' => N], ...]
	 *   예) ['host' => 'web-server-01', 'ip' => '10.0.0.1', 'description' => '...',
	 *        'trigger' => 'CPU 사용률 높음', 'severity' => 4, 'count' => 12]
	 *   severity 는 트리거의 심각도 숫자다. 0(미분류) ~ 5(가장 심각).
	 */
	private function getRows(int $time_from, int $time_till, array $host_filter, array $filter): array {
		/*
		 * 1) 이벤트를 트리거(objectid)별로 묶어 개수만 센다. DB 가 세어 주므로 이벤트가 많아도 빠르다.
		 *    결과: [['objectid' => 트리거ID, 'rowscount' => 개수], ...]
		 *
		 *    source / object : "트리거가 만든 이벤트"만.
		 *    value           : TRIGGER_VALUE_TRUE = 장애(PROBLEM) 이벤트만. 복구 이벤트는 세지 않는다.
		 *    countOutput + groupBy : 이벤트를 그대로 받지 않고, objectid 별 개수(rowscount)만 받는다.
		 *    sortfield / sortorder : 개수가 많은 순서로.
		 *    limit           : 위에서 MAX_ROWS 개까지만.
		 */
		$options = [
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
		// 호스트 그룹/호스트 필터. 선택하지 않으면 null 이라 조건이 붙지 않는다. (하위 그룹 포함)
		] + HostFilter::toApiOptions($host_filter);

		/*
		 * Peak이벤트 유형 필터: 이벤트 이름(= 그때의 트리거 이름)에 입력한 글자가 들어 있는 것만.
		 *   search : 부분 일치(대소문자 무시).
		 */
		if ($filter['name'] !== '') {
			$options['search'] = ['name' => $filter['name']];
		}

		// 심각도 필터: 체크한 심각도의 이벤트만. 하나도 체크하지 않으면 전체.
		if ($filter['severities']) {
			$options['severities'] = $filter['severities'];
		}

		$counts = API::Event()->get($options);

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
			// reset(배열): 배열의 첫 번째 원소를 돌려준다.
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
