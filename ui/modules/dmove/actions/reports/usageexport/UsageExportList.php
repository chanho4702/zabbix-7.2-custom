<?php declare(strict_types = 0);
/*
 * CPU/Memory 전체 사용률 추출 화면 컨트롤러.
 *
 * ── 이 화면이 하는 일 ──
 *   서버들의 CPU / 메모리 사용률 수집값 원본을 CSV 파일로 내려받는다. 화면에 표는 없다.
 *   수집값 한 건이 파일의 한 줄이다. 열: HOST, ITEM, CLOCK, VALUE
 *
 *   화면     : zabbix.php?action=usageexport.list
 *   내보내기 : zabbix.php?action=usageexport.export   (화면의 [CSV 내보내기] 버튼)
 *   메뉴     : 보고서 > CPU/Memory 전체 사용률 추출   (Module.php 의 REPORT_MENUS)
 *   5버전    : zabbix.php?action=perf.analysis.view   (화면 제목 "CPU/Mem 사용률 추출")
 *              컨트롤러 CControllerPerfAnalysis.php (필터 저장만 함), 뷰 monitoring.perf.view.php (추출 로직)
 *
 * ── 화면 구성 (5버전과 같다) ──
 *   필터 : 호스트 그룹(선택창), 호스트(선택창)
 *          항목 = CPU Util & Mem Util / CPU Util only / Mem Util only   (라디오)
 *          시간 = 업무시간 (09:00 ~ 18:00) / 전체사용현황               (라디오)
 *          기간 = 코어의 시간 선택기 (최신 1시간, 어제, 지난달 ... 또는 날짜 직접 입력)
 *   안내 : ※실시간 Data 출력은 전일 ~ 3개월 기간 출력이 가능합니다.
 *          ※다량의 Data export시 1분 이상 소요될 수 있습니다.
 *   버튼 : [CSV 내보내기]
 *   표   : 미리보기. 파일에 들어갈 내용의 앞에서 100줄 (HOST, ITEM, CLOCK, VALUE). 5버전에는 없던 것이다
 *
 * ── 데이터 흐름 ──
 *   화면(usageexport.list)
 *     doAction()
 *       ├─ 필터 저장/읽기 (CProfile, HostFilter), 기간 읽기 (시간 선택기)
 *       ├─ UsageExportService::buildPlan() : 대상 아이템 수와 예상 줄 수를 구한다 (값은 아직 읽지 않는다)
 *       ├─ UsageExportService::preview()   : 미리보기 표에 넣을 앞부분 100줄을 읽는다
 *       └─ 뷰(views/reports/usageexport.list.php) : 필터 + 예상 줄 수 안내 + [CSV 내보내기] 버튼 + 미리보기 표
 *   내보내기(usageexport.export)
 *     doAction()
 *       ├─ 저장된 필터와 기간으로 같은 계획을 다시 만든다
 *       ├─ 문제가 있으면(기간 초과, 줄 수 초과, 대상 없음) 화면으로 되돌린다. 화면에 이유가 나온다
 *       └─ 레이아웃(views/export/layout.stream.php) 이 UsageExportService::stream() 으로 CSV 를 내려보낸다
 *
 * ── 5버전과 달라진 점 ──
 *   - 5버전은 [적용] 을 누르면 바로 파일을 만들었다. 여기서는 [적용] 으로 조건을 정하고(예상 줄 수가 보인다),
 *     [CSV 내보내기] 버튼을 눌러 받는다. 다른 화면의 내보내기와 같은 방식이다.
 *   - 5버전은 따로 만든 복사 표에서 읽고 서버 셸 스크립트로 파일을 만들었다. 여기서는 Zabbix 표준 이력 표에서
 *     바로 읽어 내려보낸다. (자세한 설명: includes/UsageExportService.php 머리말)
 *   - 5버전은 파일이 여러 개로 나뉘어 내려왔다. 나누는 기준이 서버 스크립트에 있어서 확인하지 못했다.
 *     여기서는 파일 하나로 내려보낸다.
 *   - 권한: 5버전은 검사가 없었다. 여기서는 로그인한 사용자(게스트 제외)만 쓰고, 볼 수 있는 호스트의 값만 나온다.
 *
 * ── 알아 둘 점 ──
 *   이력 표에는 아이템의 "이력 보관 기간" 만큼만 값이 있다. 3개월치를 받으려면 CPU/메모리 아이템의
 *   이력 보관 기간이 3개월 이상이어야 한다. (docs/CUSTOMER_ENV_CHECKLIST.md 1-9)
 *
 * ── 이 화면에 속한 파일 ──
 *   actions/reports/usageexport/UsageExportList.php   컨트롤러 (이 파일. 화면과 내보내기가 같이 쓴다)
 *   views/reports/usageexport.list.php                화면 뷰
 *   views/export/layout.stream.php                    CSV 를 흘려보내는 레이아웃
 *   includes/UsageExportService.php                   대상 아이템 찾기, 줄 수 어림, CSV 출력
 *   conf/usageexport.conf.php                         최대 기간, 줄 수 한도, 업무시간
 *   conf/serverusage.conf.php                         CPU / 메모리 아이템 키 (저사용/고사용 화면과 같이 쓴다)
 *   includes/filter/HostFilter.php                    호스트 그룹/호스트 선택 필터 (여러 화면 공용)
 */

namespace Modules\DMove\Actions\Reports\UsageExport;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CProfile;
use CUrl;
use CWebUser;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\UsageExportService;

class UsageExportList extends CController {

	/** 필터 값을 사용자별로 저장하는 프로파일 키 접두어. */
	private const PROFILE_PREFIX = 'web.dmove.usageexport.';

	/** 시간 선택기가 기간을 저장하는 프로파일 키. 뷰의 CFilter::setProfile() 값과 같아야 한다. */
	private const TIMELINE_PROFILE = 'web.dmove.usageexport.filter';

	/** 화면의 미리보기 표에 보여 줄 줄 수. 전체는 [CSV 내보내기] 로 받는다. */
	private const PREVIEW_ROWS = 100;

	/**
	 * 조회만 하는 화면이라 CSRF 토큰 검사를 끈다.
	 */
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	/**
	 * 주소로 들어온 값 검사.
	 *
	 *   'range_time' : 시간 선택기가 보내는 기간 표현. 예) "now-1d", "2026-11-01 00:00:00"
	 *   validateTimeSelectorPeriod() : 시작 < 종료, 최소/최대 기간을 추가로 검사하는 부모 클래스의 메소드.
	 *
	 * @return bool  검사를 통과하면 true.
	 */
	protected function checkInput(): bool {
		$fields = [
			'from' =>			'range_time',
			'to' =>				'range_time',
			'filter_set' =>		'in 1',
			'filter_rst' =>		'in 1',
			'filter_items' =>	'in '.implode(',', [UsageExportService::ITEMS_CPU_MEM, UsageExportService::ITEMS_CPU,
				UsageExportService::ITEMS_MEM
			]),
			'filter_time' =>	'in '.implode(',', [UsageExportService::TIME_BUSINESS, UsageExportService::TIME_ALL]),
			// 내보내기 버튼이 붙여 보내는 값. 이 화면은 CSV 만 지원한다.
			'format' =>			'in csv'
		// 호스트 그룹/호스트 선택 필터의 규칙(filter_groupids, filter_hostids)을 더한다. (includes/filter/HostFilter.php)
		] + HostFilter::INPUT_FIELDS;

		$ret = $this->validateInput($fields) && $this->validateTimeSelectorPeriod();

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 로그인한 사용자(게스트 제외) 모두 허용한다.
	 * 내보내는 값은 사용자가 볼 수 있는 호스트의 것만이다. (UsageExportService 가 API 로 호스트를 찾는다)
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 화면(usageexport.list)과 내보내기(usageexport.export)가 같이 쓰는 본체.
	 */
	protected function doAction(): void {
		$service = new UsageExportService();
		// 액션 이름이 ".export" 로 끝나면 내보내기 요청이다. str_ends_with: PHP 8.0 부터 있는 함수.
		$is_export = str_ends_with($this->getAction(), '.export');

		/*
		 * ── 1) 기간 (시간 선택기) ──
		 *   getInputs()                : 요청에 from / to 가 있으면 배열에 채운다. (없으면 null 그대로)
		 *   updateTimeSelectorPeriod() : from / to 가 있으면 프로파일에 저장한다.
		 *   getTimeSelectorPeriod()    : 저장된 기간을 읽어 계산한다. 결과의 from_ts / to_ts 가 유닉스 시간(초)이다.
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
		$time_from = (int) $timeline['from_ts'];
		$time_till = (int) $timeline['to_ts'];

		// ── 2) 필터 저장/초기화 ([적용] -> filter_set=1, [리셋] -> filter_rst=1) ──
		if ($this->hasInput('filter_set')) {
			HostFilter::save(self::PROFILE_PREFIX, $this->getInput('filter_groupids', []),
				$this->getInput('filter_hostids', [])
			);
			CProfile::update(self::PROFILE_PREFIX.'filter.items',
				(int) $this->getInput('filter_items', UsageExportService::ITEMS_CPU_MEM), PROFILE_TYPE_INT
			);
			CProfile::update(self::PROFILE_PREFIX.'filter.time',
				(int) $this->getInput('filter_time', UsageExportService::TIME_BUSINESS), PROFILE_TYPE_INT
			);
		}
		elseif ($this->hasInput('filter_rst')) {
			HostFilter::reset(self::PROFILE_PREFIX);
			CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.items');
			CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.time');
		}

		// ── 3) 저장된 필터 읽기. 내보내기도 이 값을 읽으므로 화면에 보이는 조건 그대로 파일이 만들어진다. ──
		$host_filter = HostFilter::load(self::PROFILE_PREFIX);	// ['groupids' => [...], 'hostids' => [...]]
		$filter = [
			'items' => (int) CProfile::get(self::PROFILE_PREFIX.'filter.items', UsageExportService::ITEMS_CPU_MEM),
			'time' => (int) CProfile::get(self::PROFILE_PREFIX.'filter.time', UsageExportService::TIME_BUSINESS)
		];

		// ── 4) 내보내기 계획. 대상 아이템과 예상 줄 수를 구한다. 값은 아직 읽지 않는다. ──
		$config_missing = !$service->isConfigured($filter['items']);
		$plan = $config_missing
			? null
			: $service->buildPlan($host_filter, $filter['items'], $filter['time'], $time_from, $time_till);

		// 내보낼 수 없는 이유. 빈 문자열이면 내보낼 수 있다.
		$problem = $this->findProblem($service, $plan, $time_from, $time_till);

		$title = Lang::t('CPU/Memory usage export');

		// ── 5) 내보내기 요청 ──
		if ($is_export) {
			if ($config_missing || $problem !== '') {
				// 내보낼 수 없으면 화면으로 되돌린다. 화면에 이유가 표시된다.
				$this->setResponse(new CControllerResponseRedirect(
					(new CUrl('zabbix.php'))->setArgument('action', 'usageexport.list')
				));

				return;
			}

			// views/export/layout.stream.php 가 이 값을 읽어 CSV 를 내려보낸다.
			$response = new CControllerResponseData(['stream_plan' => $plan]);
			$response->setTitle($title);
			// 파일명 예) usage_cpumem_working_20261123_101530.csv  (5버전도 항목과 시간 구분을 이름에 넣었다)
			$response->setFileName('usage_'.self::fileSuffix($filter).'_'.date('Ymd_His').'.csv');

			$this->setResponse($response);

			return;
		}

		// ── 6) 화면 응답 ──
		[$hour_from, $hour_till] = $service->getBusinessHours();

		$response = new CControllerResponseData([
			'action' => 'usageexport.list',
			'export_action' => 'usageexport.export',
			'title' => $title,
			'timeline' => $timeline,
			'timeline_profile' => self::TIMELINE_PROFILE,
			'active_tab' => CProfile::get(self::TIMELINE_PROFILE.'.active', 1),
			// 선택창에 표시할 값(ID + 이름)으로 바꿔 넘긴다.
			'host_filter' => HostFilter::toViewData($host_filter),
			'filter' => $filter,
			// 뷰에서 라디오 버튼 값으로 쓴다.
			'items_options' => [
				UsageExportService::ITEMS_CPU_MEM => 'CPU Util & Mem Util',
				UsageExportService::ITEMS_CPU => 'CPU Util only',
				UsageExportService::ITEMS_MEM => 'Mem Util only'
			],
			'time_options' => [
				// sprintf('%02d', 9) -> '09'. 두 자리로 맞춘다.
				UsageExportService::TIME_BUSINESS => Lang::t('Business hours (%1$s:00 ~ %2$s:00)',
					sprintf('%02d', $hour_from), sprintf('%02d', $hour_till)
				),
				UsageExportService::TIME_ALL => Lang::t('All hours')
			],
			// 아이템 키 설정이 비어 있으면 뷰가 안내를 띄운다.
			'config_missing' => $config_missing,
			// 내보낼 수 없는 이유. 빈 문자열이면 [CSV 내보내기] 버튼이 나온다.
			'problem' => $problem,
			'item_count' => $plan !== null ? count($plan['items']) : 0,
			// 미리보기 표. 내보낼 수 있을 때만 앞에서 PREVIEW_ROWS 줄을 읽는다.
			'preview_rows' => ($plan !== null && $problem === '') ? $service->preview($plan, self::PREVIEW_ROWS) : [],
			'preview_limit' => self::PREVIEW_ROWS,
			'estimated_rows' => $plan !== null ? $plan['estimated_rows'] : 0
		]);
		$response->setTitle($title);	// 브라우저 탭 제목

		$this->setResponse($response);
	}

	/**
	 * 지금 조건으로 내보낼 수 없는 이유를 찾는다.
	 *
	 * 검사 순서:
	 *   1) 기간이 최대 기간(기본 93일)을 넘는가
	 *   2) 대상 아이템이 하나도 없는가
	 *   3) 예상 줄 수가 한도(기본 1000만)를 넘는가   (5버전은 이때 안내 창만 띄웠다)
	 *
	 * @param array|null $plan  buildPlan() 의 결과. 설정이 비어 있어 계획을 만들지 못했으면 null.
	 *
	 * @return string  이유를 설명하는 문구. 내보낼 수 있으면 빈 문자열.
	 */
	private function findProblem(UsageExportService $service, ?array $plan, int $time_from, int $time_till): string {
		if ($time_till - $time_from > $service->getMaxPeriodDays() * SEC_PER_DAY) {
			return Lang::t('The period can be up to %1$d days. Choose a shorter period.', $service->getMaxPeriodDays());
		}

		if ($plan === null) {
			return '';
		}

		if (!$plan['items']) {
			return Lang::t('There are no CPU/Memory items for the selected hosts.');
		}

		if ($plan['too_large']) {
			// number_format(값): 천 단위마다 쉼표를 넣는다. 예) 10000000 -> 10,000,000
			return Lang::t('About %1$s rows are expected, which exceeds the limit of %2$s rows. Choose fewer hosts or a shorter period.',
				number_format($plan['estimated_rows']), number_format($service->getMaxRows())
			);
		}

		return '';
	}

	/**
	 * 파일명에 넣을 구분 글자. (5버전: cpumem / cpu / mem + working / all)
	 *
	 * 예) ['items' => ITEMS_CPU, 'time' => TIME_ALL] -> 'cpu_all'
	 */
	private static function fileSuffix(array $filter): string {
		$items = [
			UsageExportService::ITEMS_CPU_MEM => 'cpumem',
			UsageExportService::ITEMS_CPU => 'cpu',
			UsageExportService::ITEMS_MEM => 'mem'
		];

		return ($items[$filter['items']] ?? 'cpumem').'_'
			.($filter['time'] === UsageExportService::TIME_BUSINESS ? 'working' : 'all');
	}
}
