<?php declare(strict_types = 0);
/*
 * 일일점검 화면의 "CPU 사용률 Top 10" / "Memory 사용률 Top 10" 팝업 컨트롤러.
 *
 * ── 이 팝업이 하는 일 ──
 *   어제 하루 동안 CPU(또는 메모리) 평균 사용률이 가장 높았던 서버 10대를 보여 준다.
 *   일일점검 화면 오른쪽 위의 [CPU 사용률 Top 10] / [Memory 사용률 Top 10] 버튼을 누르면 열린다.
 *
 *   호출 : zabbix.php?action=dailycheck.top&metric=cpu    (또는 metric=memory)
 *   5버전: zabbix.php?action=popup.topcpu / popup.topmemory
 *          컨트롤러 CControllerPopupTopCpu.php / CControllerPopupTopMemory.php, 뷰 popup.topcpu.php / popup.topmemory.php
 *
 * ── 팝업 구성 (5버전과 같다) ──
 *   제목 : CPU 사용율 Top10 (2026-11-22)        괄호 안은 어제 날짜
 *   표   : No, 호스트, IP, Description, CPU 사용율(%)   (메모리 팝업은 마지막 열이 Memory 사용율(%))
 *
 * ── 데이터 흐름 ──
 *   doAction()
 *     ├─ host.get : 사용자가 볼 수 있는 전체 호스트
 *     ├─ ServerUsageService::getUsage() : 어제 00:00:00 ~ 23:59:59 의 평균 (trends 를 집계)
 *     ├─ 평균이 큰 순으로 정렬해서 위에서 10개
 *     └─ 뷰(views/reports/dailycheck.top.php)가 팝업용 JSON 으로 출력
 *
 * ── 5버전과 달라진 점 ──
 *   - 5버전은 컨트롤러 두 개가 거의 같은 코드였다. 여기서는 metric 값 하나로 나눠 쓴다.
 *   - 5버전은 값이 없는 호스트도 0 으로 넣어서, 값이 있는 호스트가 10대보다 적으면 0 인 줄이 섞여 나왔다.
 *     여기서는 값이 있는 호스트만 보여 준다.
 *   - 5버전의 집계 클래스(...CPU, ...Memory) 소스는 받지 못했다. 5버전 컨트롤러가 "전일 기준"으로 불렀다는 것만 확인했다.
 *     여기서는 저사용/고사용 화면과 같은 집계(ServerUsageService)를 어제 하루, 전체 시간으로 돌린다.
 *   - 어느 아이템을 읽을지는 conf/serverusage.conf.php 의 cpu_item_keys / memory_item_keys 에서 정한다.
 *
 * ── 이 팝업에 속한 파일 ──
 *   actions/reports/dailycheck/DailyCheckTop.php    컨트롤러 (이 파일)
 *   views/reports/dailycheck.top.php                팝업 내용 (JSON 출력)
 *   views/js/reports/dailycheck.list.js.php         버튼을 누르면 팝업을 여는 스크립트
 *   includes/ServerUsageService.php                 평균 집계
 */

namespace Modules\DMove\Actions\Reports\DailyCheck;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CWebUser;
use Modules\DMove\Actions\Reports\ServerUsage\ServerUsageController;
use Modules\DMove\Includes\HostHelper;
use Modules\DMove\Includes\ItsmRepository;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\ServerUsageService;

class DailyCheckTop extends CController {

	/** 보여 줄 줄 수. (5버전: 10개) */
	private const LIMIT = 10;

	/** metric 입력값 => ServerUsageService 의 집계 대상. */
	private const METRICS = [
		'cpu' => ServerUsageService::METRIC_CPU,
		'memory' => ServerUsageService::METRIC_MEMORY
	];

	/**
	 * 조회만 하는 팝업이라 CSRF 토큰 검사를 끈다.
	 */
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	/**
	 * 주소로 들어온 값 검사.
	 *   'required'      : 반드시 있어야 하는 값
	 *   'in cpu,memory' : cpu 또는 memory 만 허용
	 */
	protected function checkInput(): bool {
		$fields = [
			'metric' =>	'required|in '.implode(',', array_keys(self::METRICS))
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 일일점검 화면과 같다. (로그인한 사용자, 게스트 제외)
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 팝업 내용을 만드는 본체. 입력 검사와 권한 검사를 통과하면 불린다.
	 *
	 * 하는 일:
	 *   1) metric 값(cpu / memory)에 맞는 집계 객체(ServerUsageService)를 만든다.
	 *   2) 어제 날짜를 구한다.
	 *   3) 아이템 키 설정이 있으면 getRows() 로 상위 10대를 구한다. 없으면 빈 목록을 넘긴다.
	 *
	 * 뷰가 받는 $data:
	 *   [
	 *     'metric'         => 'cpu',                  // 'cpu' 또는 'memory'
	 *     'day'            => '2026-11-22',           // 어제 날짜
	 *     'config_missing' => false,                  // 아이템 키 설정이 비어 있으면 true
	 *     'rows'           => [...],                  // getRows() 의 결과
	 *     'user'           => ['debug_mode' => 0]
	 *   ]
	 * 뷰(views/reports/dailycheck.top.php)는 이 값을 팝업용 JSON 으로 출력한다.
	 */
	protected function doAction(): void {
		$metric = $this->getInput('metric');
		$service = new ServerUsageService(self::METRICS[$metric]);

		// 어제 날짜. 시간대는 로그인한 사용자의 시간대 설정을 따른다. (5버전: date("Y-m-d", strtotime("-1 day")))
		$day = date('Y-m-d', strtotime('-1 day'));

		// DB 집계가 실패하면 getRows() 가 null 이다. 뷰가 빈 표 대신 오류 상자를 띄운다.
		$rows = $service->isConfigured() ? $this->getRows($service, $day) : [];

		$this->setResponse(new CControllerResponseData([
			'metric' => $metric,
			'day' => $day,
			// 아이템 키 설정이 비어 있으면 뷰가 안내를 띄운다.
			'config_missing' => !$service->isConfigured(),
			'query_error' => $rows === null,
			'rows' => $rows ?? [],
			// 디버그 모드 사용자에게 디버그 정보를 붙일 때 코어가 쓰는 값. 팝업 뷰의 관례다.
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	/**
	 * 평균 사용률이 높은 순으로 최대 10줄.
	 *
	 * @param string $day  집계할 날짜. 예) '2026-11-22'
	 *
	 * @return array|null  [['num' => 1, 'name' => 'web-01', 'ip' => '10.0.0.1', 'description' => '...',
	 *                       'description_hint' => '', 'avg' => 87.65], ...]   DB 집계가 실패하면 null.
	 */
	private function getRows(ServerUsageService $service, string $day): ?array {
		/*
		 * 1) 사용자가 볼 수 있는 전체 호스트.
		 *    selectInterfaces  각 호스트에 'interfaces' => [[ip, main, type], ...] 를 붙인다. (대표 IP 용)
		 *    preservekeys      결과 배열의 키를 hostid 로 한다.
		 */
		$hosts = API::Host()->get([
			'output' => ['hostid', 'name', 'description'],
			'selectInterfaces' => HostHelper::INTERFACE_FIELDS,
			'preservekeys' => true
		]);

		if (!$hosts) {
			return [];
		}

		// 2) 어제 하루(00:00:00 ~ 23:59:59), 전체 시간의 평균. [hostid => ['avg' => .., 'peak' => ..]]
		$usage = $service->getUsage(array_keys($hosts), strtotime($day.' 00:00:00'), strtotime($day.' 23:59:59'),
			ServerUsageService::HOURS_ALL
		);

		if ($usage === null) {
			return null;
		}

		/*
		 * 3) 평균이 큰 순으로 정렬해서 위에서 10개만 남긴다.
		 *    uasort : 배열의 키(hostid)를 유지하면서 값으로 정렬한다.
		 *    $b <=> $a : 큰 값이 앞에 오게(내림차순) 비교한다.
		 *    array_slice(배열, 0, 10, true) : 앞에서 10개. 마지막 true 는 키를 유지하라는 뜻.
		 */
		uasort($usage, static fn(array $a, array $b): int => $b['avg'] <=> $a['avg']);
		$usage = array_slice($usage, 0, self::LIMIT, true);

		// 4) ITSM 설명. [소문자 표시명 => 설명]. ITSM 을 쓸 수 없으면(테이블 없음) null 이다.
		$itsm_descriptions = (new ItsmRepository())->fetchDescriptions();

		$rows = [];
		$num = 1;

		foreach ($usage as $hostid => $values) {
			$host = $hosts[$hostid];

			// ITSM 설명만 쓴다. 없으면 '-' 와 안내 문구. (5버전과 같다. 규칙은 저사용/고사용 화면과 같다)
			[$description, $description_hint] = ServerUsageController::itsmDescription($itsm_descriptions, $hostid,
				$host['name']
			);

			$rows[] = [
				'num' => $num++,
				'name' => $host['name'],
				'ip' => HostHelper::mainIp($host['interfaces']),
				'description' => $description,
				'description_hint' => $description_hint,
				// 소수 둘째 자리까지. (5버전: sprintf("%0.2f", ...))
				'avg' => round($values['avg'], 2)
			];
		}

		return $rows;
	}
}
