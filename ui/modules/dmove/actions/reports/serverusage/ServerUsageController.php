<?php declare(strict_types = 0);
/*
 * 저사용 / 고사용 서버 사용현황 화면의 공통 컨트롤러.
 *
 * 두 화면은 필터, 표, 계산 방법이 같고 "어떤 서버를 남기는가"만 다르다.
 * 그래서 공통 부분을 이 클래스에 두고, 화면별 컨트롤러는 다른 점만 적는다.
 *
 *   actions/reports/lowserver/LowServerList.php     저사용: 평균 <= 기준  그리고  Peak <= 기준
 *   actions/reports/highserver/HighServerList.php   고사용: 평균 >= 기준  그리고  Peak >= 기준
 *
 * abstract class: 그대로는 화면으로 쓸 수 없고, 상속한 클래스가 abstract 메소드를 채워야 하는 클래스다.
 * manifest.json 에는 이 클래스가 아니라 위 두 클래스를 등록한다.
 *
 * 5버전 화면 구성 (두 화면 공통)
 *   필터 : 호스트 그룹(선택창), 호스트(선택창), 시작(날짜), 종료(날짜),
 *          시간 = 업무시간 사용현황(09시~18시) / 업무외시간 사용현황(18시~09시) / 전체사용현황
 *   안내 : ※최대 1년까지 기간 지정이 가능합니다.
 *   버튼 : CSV 내보내기, 기준설정
 *   표   : 호스트(정렬), IP, description, 평균CPU사용률(%)(정렬), Peak(%)
 *          표 위 오른쪽에 빨간 글씨로 판정 기준 표시
 *
 * 5버전과 달라진 점
 *   - 평균/Peak 계산을 추가 테이블 없이 한다. (includes/ServerUsageService.php 머리말 참고)
 *   - [기준설정] 버튼은 옮기지 않았다. 기준 값은 conf/serverusage.conf.php 에 있다.
 *   - Description 은 ITSM 값을 먼저 쓰고, 없으면 Zabbix 호스트 설명을 쓴다. (5버전은 ITSM 값만 썼다)
 *   - Excel 내보내기가 추가됐다.
 *
 * 이 화면들에 속한 파일
 *   actions/reports/serverusage/ServerUsageController.php   공통 컨트롤러 (이 파일)
 *   actions/reports/lowserver/LowServerList.php             저사용 화면
 *   actions/reports/highserver/HighServerList.php           고사용 화면
 *   views/reports/serverusage.list.php                      두 화면이 같이 쓰는 뷰
 *   includes/ServerUsageService.php                         평균 / Peak 계산
 *   conf/serverusage.conf.php                               아이템 키, 판정 기준, 업무시간
 */

namespace Modules\DMove\Actions\Reports\ServerUsage;

use API;
use CArrayHelper;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CUrl;
use CWebUser;
use Modules\DMove\Includes\Export\ExportTrait;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\HostHelper;
use Modules\DMove\Includes\ItsmRepository;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\ServerUsageService;

abstract class ServerUsageController extends CController {

	// 내보내기 플러그인. (includes/export/ExportTrait.php)
	use ExportTrait;

	/** 정렬할 수 있는 열. */
	private const SORT_FIELDS = ['name', 'avg'];

	// ── 화면별 컨트롤러가 채워야 하는 것 ─────────────────────────────────────

	/** 화면 이름. 액션 이름과 프로파일 키에 쓴다. 예) 'lowserver' -> 액션 lowserver.list / lowserver.export */
	abstract protected function getPageKey(): string;

	/** 화면 제목(번역된 문구). */
	abstract protected function getPageTitle(): string;

	/** 표 위에 빨간 글씨로 보여 줄 판정 기준 안내문. $thresholds 는 conf/serverusage.conf.php 의 thresholds. */
	abstract protected function getCriteriaText(array $thresholds): string;

	/** 이 서버를 목록에 남길 것인가. $avg = 기간 평균(%), $peak = 기간 최대(%). */
	abstract protected function matches(float $avg, float $peak, array $thresholds): bool;

	// ── 공통 처리 ────────────────────────────────────────────────────────────

	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'page' =>			'ge 1',
			'filter_set' =>		'in 1',
			'filter_rst' =>		'in 1',
			'sort' =>			'in '.implode(',', self::SORT_FIELDS),
			'sortorder' =>		'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN,
			// 날짜 입력은 빈 칸으로도 넘어오므로 문자열로 받고 normalizeDate() 에서 형식을 확인한다.
			'filter_from' =>	'string',
			'filter_to' =>		'string',
			'filter_hours' =>	'in '.implode(',', ServerUsageService::HOURS_MODES)
		] + HostFilter::INPUT_FIELDS + self::exportInputFields();

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

	protected function doAction(): void {
		$page_key = $this->getPageKey();
		$profile_prefix = 'web.dmove.'.$page_key.'.';
		$list_action = $page_key.'.list';
		$export_action = $page_key.'.export';

		$service = new ServerUsageService();
		$thresholds = $service->getThresholds();

		// 날짜를 입력했는데 형식이 틀리면 true. 조회하지 않고 화면에 이유를 보여 준다. (저장값은 비워서 다음부터는 기본 기간)
		$invalid_date = false;

		// ── 1) 필터 저장/초기화 ──
		if ($this->hasInput('filter_set')) {
			foreach (['filter_from', 'filter_to'] as $field) {
				$value = trim($this->getInput($field, ''));

				if ($value !== '' && self::normalizeDate($value) === '') {
					$invalid_date = true;
				}
			}

			HostFilter::save($profile_prefix, $this->getInput('filter_groupids', []),
				$this->getInput('filter_hostids', [])
			);
			CProfile::update($profile_prefix.'filter.from', self::normalizeDate($this->getInput('filter_from', '')),
				PROFILE_TYPE_STR
			);
			CProfile::update($profile_prefix.'filter.to', self::normalizeDate($this->getInput('filter_to', '')),
				PROFILE_TYPE_STR
			);
			CProfile::update($profile_prefix.'filter.hours',
				$this->getInput('filter_hours', ServerUsageService::HOURS_ALL), PROFILE_TYPE_STR
			);
		}
		elseif ($this->hasInput('filter_rst')) {
			HostFilter::reset($profile_prefix);

			foreach (['from', 'to', 'hours'] as $key) {
				CProfile::deleteIdx($profile_prefix.'filter.'.$key);
			}
		}

		// ── 2) 저장된 필터 읽기 ──
		$host_filter = HostFilter::load($profile_prefix);

		/*
		 * 기간 기본값: 지난달 1일 ~ 지난달 말일. (5버전과 같다)
		 * 저장된 값이 비어 있으면('') 기본값을 쓴다.  ?: 는 "왼쪽이 비어 있으면 오른쪽".
		 */
		$filter = [
			'from' => CProfile::get($profile_prefix.'filter.from', '') ?: date('Y-m-d', strtotime('first day of last month')),
			'to' => CProfile::get($profile_prefix.'filter.to', '') ?: date('Y-m-d', strtotime('last day of last month')),
			'hours' => CProfile::get($profile_prefix.'filter.hours', ServerUsageService::HOURS_ALL)
		];

		$time_from = strtotime($filter['from'].' 00:00:00');
		$time_till = strtotime($filter['to'].' 23:59:59');

		// 기간이 잘못됐으면 조회하지 않고 화면에 이유를 보여 준다.
		$period_error = '';

		if ($invalid_date) {
			$period_error = Lang::t('Invalid date. Enter the date as YYYY-MM-DD.');
		}
		elseif ($time_from > $time_till) {
			$period_error = Lang::t('The start date must not be later than the end date.');
		}
		elseif ($time_till - $time_from > $service->getMaxPeriodDays() * 86400) {
			$period_error = Lang::t('* The period can be up to 1 year.');
		}

		// ── 3) 정렬 값 ──
		$sort_field = $this->getInput('sort', CProfile::get($profile_prefix.'sort', 'name'));
		$sort_order = $this->getInput('sortorder', CProfile::get($profile_prefix.'sortorder', ZBX_SORT_UP));

		CProfile::update($profile_prefix.'sort', $sort_field, PROFILE_TYPE_STR);
		CProfile::update($profile_prefix.'sortorder', $sort_order, PROFILE_TYPE_STR);

		// ── 4) 데이터 ──
		$rows = $period_error === ''
			? $this->getRows($service, $host_filter, $time_from, $time_till, $filter['hours'], $thresholds)
			: [];

		$rows = self::sortRows($rows, $sort_field, $sort_order);

		$title = $this->getPageTitle();

		// ── 5) 내보내기 요청이면 파일로 응답하고 끝낸다 ──
		if ($this->isExportRequest()) {
			$this->setExportResponse($title, $page_key, self::getColumns(), $rows);

			return;
		}

		// ── 6) 화면 응답 ──
		$data = [
			'action' => $list_action,
			'export_action' => $export_action,
			'title' => $title,
			'columns' => self::getColumns(),
			'filter_profile' => $profile_prefix.'filter',
			'active_tab' => CProfile::get($profile_prefix.'filter.active', 1),
			'host_filter' => HostFilter::toViewData($host_filter),
			'filter' => $filter,
			'business_hours' => $service->getBusinessHours(),
			'criteria' => $this->getCriteriaText($thresholds),
			'period_error' => $period_error,
			// 아이템 키 설정이 비어 있으면 뷰가 안내를 띄운다.
			'config_missing' => !$service->isConfigured(),
			'sort' => $sort_field,
			'sortorder' => $sort_order
		];

		/*
		 * 페이징. 세 번째 인자는 항상 ZBX_SORT_UP 이다. $rows 는 위에서 이미 정렬을 끝낸 전체 목록이다.
		 * ZBX_SORT_DOWN 을 넘기면 코어가 "API 가 결과 제한만큼만 가져온 목록"으로 보고 (전체 건수 - 결과 제한)
		 * 만큼 건너뛴다. 그러면 1000건이 넘을 때 내림차순 1페이지가 501번째 줄부터 나온다. (include/classes/helpers/CPagerHelper.php)
		 */
		$data['paging'] = CPagerHelper::paginate($this->getInput('page', 1), $rows, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', $list_action)
		);
		$data['rows'] = $rows;

		$response = new CControllerResponseData($data);
		$response->setTitle($title);

		$this->setResponse($response);
	}

	/**
	 * 목록 정렬.
	 *
	 * 숫자 열(avg)은 숫자로 비교한다. 코어 CArrayHelper::sort() 는 strnatcasecmp(자연 정렬)라서
	 * 소수점 뒤를 정수처럼 비교한다. 예) "2.29" 와 "2.3" 을 29 > 3 으로 보고 2.29 를 더 큰 값으로 놓는다.
	 * 값이 같으면 호스트 이름 순으로 놓아서 페이지를 넘겨도 순서가 흔들리지 않게 한다.
	 *
	 * <=> : 왼쪽이 작으면 -1, 같으면 0, 크면 1.
	 */
	private static function sortRows(array $rows, string $sort_field, string $sort_order): array {
		if ($sort_field !== 'avg') {
			CArrayHelper::sort($rows, [['field' => $sort_field, 'order' => $sort_order]]);

			return array_values($rows);
		}

		usort($rows, static function (array $a, array $b) use ($sort_order): int {
			$cmp = (float) $a['avg'] <=> (float) $b['avg'];

			if ($sort_order === ZBX_SORT_DOWN) {
				$cmp = -$cmp;
			}

			return $cmp !== 0 ? $cmp : strnatcasecmp($a['name'], $b['name']);
		});

		return $rows;
	}

	/**
	 * 표의 열 정의. 화면의 제목 줄과 내보내기 파일이 같이 쓴다.
	 */
	private static function getColumns(): array {
		return [
			'name' => _('Host'),
			'ip' => _('IP'),
			'description' => _('Description'),
			// 숫자 열. 엑셀에 숫자로 들어가 정렬/평균을 낼 수 있다.
			'avg' => ['label' => Lang::t('Average CPU usage (%)'), 'type' => 'number'],
			'peak' => ['label' => 'Peak(%)', 'type' => 'number']
		];
	}

	/**
	 * 판정 기준에 맞는 서버 목록.
	 *
	 * @return array  [['hostid' => .., 'name' => .., 'ip' => .., 'description' => .., 'avg' => 12.34, 'peak' => 56.78], ...]
	 */
	private function getRows(ServerUsageService $service, array $host_filter, int $time_from, int $time_till,
			string $hours, array $thresholds): array {
		// 1) 필터에 맞는 호스트. (사용자가 볼 수 있는 호스트만 나온다)
		$hosts = API::Host()->get(HostFilter::toApiOptions($host_filter) + [
			'output' => ['hostid', 'host', 'name', 'description'],
			'selectInterfaces' => HostHelper::INTERFACE_FIELDS,
			'preservekeys' => true
		]);

		if (!$hosts) {
			return [];
		}

		// 2) 호스트별 평균 / Peak. [hostid => ['avg' => .., 'peak' => ..]]
		$usage = $service->getUsage(array_keys($hosts), $time_from, $time_till, $hours);

		// 3) ITSM 의 Description. [소문자 호스트명 => 설명]  ITSM 미연동이면 빈 배열.
		$itsm_descriptions = (new ItsmRepository())->fetchDescriptions();

		$rows = [];

		foreach ($usage as $hostid => $values) {
			// 기준에 맞지 않는 서버는 뺀다. 값이 없는 서버는 $usage 에 없으므로 처음부터 빠져 있다. (5버전과 같다)
			if (!$this->matches($values['avg'], $values['peak'], $thresholds)) {
				continue;
			}

			$host = $hosts[$hostid];

			// ITSM 설명을 표시명 -> 호스트명 순서로 찾고, 없으면 Zabbix 호스트 설명을 쓴다.
			$description = $itsm_descriptions[mb_strtolower($host['name'])]
				?? $itsm_descriptions[mb_strtolower($host['host'])]
				?? $host['description'];

			$rows[] = [
				'hostid' => $hostid,
				'name' => $host['name'],
				'ip' => HostHelper::mainIp($host['interfaces']),
				'description' => $description,
				// round(값, 2): 소수 둘째 자리까지 반올림. 화면과 내보내기 파일에 같은 값이 나오게 여기서 맞춘다.
				'avg' => round($values['avg'], 2),
				'peak' => round($values['peak'], 2)
			];
		}

		return $rows;
	}

	/**
	 * 날짜 문자열이 YYYY-MM-DD 형식이고 실제로 있는 날짜면 그대로, 아니면 빈 문자열을 돌려준다.
	 */
	private static function normalizeDate(string $value): string {
		$value = trim($value);

		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
			return '';
		}

		// checkdate(월, 일, 연): 실제로 있는 날짜인지 검사한다.
		return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
	}
}
