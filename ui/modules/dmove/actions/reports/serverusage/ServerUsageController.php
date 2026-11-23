<?php declare(strict_types = 0);
/*
 * 저사용 / 고사용 서버 사용현황 화면의 공통 컨트롤러.
 *
 * ── 이 파일이 하는 일 ──
 *   고른 기간 동안 서버별 CPU 사용률의 평균과 Peak(최대)를 구하고, 기준에 맞는 서버만 표로 보여 준다.
 *   두 화면은 필터, 표, 계산 방법이 같고 "어떤 서버를 남기는가"만 다르다.
 *   그래서 공통 부분을 이 클래스에 두고, 화면별 컨트롤러는 다른 점만 적는다.
 *
 *   actions/reports/lowserver/LowServerList.php     저사용: 평균 <= 기준  그리고  Peak <= 기준
 *   actions/reports/highserver/HighServerList.php   고사용: 평균 >= 기준  그리고  Peak >= 기준
 *
 * abstract class: 그대로는 화면으로 쓸 수 없고, 상속한 클래스가 abstract 메소드를 채워야 하는 클래스다.
 * manifest.json 에는 이 클래스가 아니라 위 두 클래스를 등록한다.
 *
 * ── 화면 구성 (5버전 기준. 두 화면 공통) ──
 *   필터 : 호스트 그룹(선택창), 호스트(선택창), 시작(날짜), 종료(날짜),
 *          시간 = 업무시간 사용현황(09시~18시) / 업무외시간 사용현황(18시~09시) / 전체사용현황
 *   안내 : ※최대 1년까지 기간 지정이 가능합니다.
 *   버튼 : CSV 내보내기, 기준설정
 *   표   : 호스트(정렬), IP, description, 평균CPU사용률(%)(정렬), Peak(%)
 *          표 위 오른쪽에 빨간 글씨로 판정 기준 표시
 *
 * ── 데이터 흐름 ──
 *   doAction()
 *     ├─ 필터 저장/읽기 (CProfile, HostFilter). 날짜 형식과 기간 길이를 확인한다
 *     ├─ getRows()
 *     │    ├─ host.get                         : 필터에 맞는 호스트
 *     │    ├─ ServerUsageService::getUsage()   : 호스트별 평균 / Peak (trends, trends_uint 테이블을 집계)
 *     │    ├─ ItsmRepository                   : ITSM 설명
 *     │    └─ matches()                        : 화면별 기준에 맞는 서버만 남긴다
 *     ├─ sortRows() 정렬
 *     ├─ 내보내기 요청(액션 이름이 .export 로 끝남)이면 파일로 응답하고 끝
 *     └─ 페이징 후 뷰(views/reports/serverusage.list.php)로 넘긴다
 *
 * ── 5버전과 달라진 점 ──
 *   - 평균/Peak 계산을 추가 테이블 없이 한다. (includes/ServerUsageService.php 머리말 참고)
 *   - [기준설정] 버튼은 사용자별 값으로 옮겼다 (includes/ServerUsageThresholds.php). 값이 없으면 conf/serverusage.conf.php 의 기본값.
 *   - Description 은 ITSM 값을 먼저 쓰고, 없으면 Zabbix 호스트 설명을 쓴다. (5버전은 ITSM 값만 썼다)
 *   - 내보내기는 CSV 만 (5버전과 같다).
 *
 * ── 이 화면들에 속한 파일 ──
 *   actions/reports/serverusage/ServerUsageController.php   공통 컨트롤러 (이 파일)
 *   actions/reports/lowserver/LowServerList.php             저사용 화면
 *   actions/reports/highserver/HighServerList.php           고사용 화면
 *   views/reports/serverusage.list.php                      두 화면이 같이 쓰는 뷰
 *   includes/ServerUsageService.php                         평균 / Peak 계산
 *   conf/serverusage.conf.php                               아이템 키, 판정 기준, 업무시간
 *   includes/filter/HostFilter.php                          호스트 그룹/호스트 선택 필터 (여러 화면 공용)
 *   includes/export/                                        내보내기 플러그인 (CSV / Excel)
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
use Modules\DMove\Includes\ServerUsageThresholds;

abstract class ServerUsageController extends CController {

	// 내보내기 플러그인. (includes/export/ExportTrait.php)
	use ExportTrait;

	/** 정렬할 수 있는 열. name = 호스트 이름, avg = 평균 CPU 사용률. */
	private const SORT_FIELDS = ['name', 'avg'];

	// ── 화면별 컨트롤러가 채워야 하는 것 ─────────────────────────────────────

	/**
	 * 화면 이름. 액션 이름과 프로파일 키에 쓴다.
	 *
	 * @return string  예) 'lowserver' -> 액션 lowserver.list / lowserver.export, 프로파일 키 'web.dmove.lowserver.'
	 */
	abstract protected function getPageKey(): string;

	/**
	 * 화면 제목(번역된 문구). 화면 위쪽, 브라우저 탭, 내보내기 파일의 시트 이름에 쓴다.
	 *
	 * @return string  예) Lang::t('Low usage servers') 의 결과.
	 */
	abstract protected function getPageTitle(): string;

	/**
	 * 표 위에 빨간 글씨로 보여 줄 판정 기준 안내문.
	 *
	 * @param array $thresholds  conf/serverusage.conf.php 의 thresholds.
	 *                           예) ['low_avg' => 30, 'low_peak' => 70, 'high_avg' => 50, 'high_peak' => 70]
	 *
	 * @return string  기준 값이 들어간 안내문.
	 */
	abstract protected function getCriteriaText(array $thresholds): string;

	/**
	 * 이 서버를 목록에 남길 것인가. getRows() 가 서버 한 대마다 부른다.
	 *
	 * @param float $avg         기간 평균(%). 예) 12.34
	 * @param float $peak        기간 최대(%). 예) 56.78
	 * @param array $thresholds  conf/serverusage.conf.php 의 thresholds.
	 *
	 * @return bool  true 면 목록에 남긴다.
	 */
	abstract protected function matches(float $avg, float $peak, array $thresholds): bool;

	// ── 공통 처리 ────────────────────────────────────────────────────────────

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
	 *   'ge 1'      : 1 이상의 숫자
	 *   'in 1'      : 값이 1 이어야 한다
	 *   'in a,b,c'  : 나열한 값 중 하나
	 *   'string'    : 글자
	 *
	 *   filter_hours : 시간대. work(업무시간) / night(업무외시간) / all(전체) 중 하나. (ServerUsageService::HOURS_MODES)
	 *
	 * 배열 + 배열 : 두 규칙 배열을 합친다.
	 *   HostFilter::INPUT_FIELDS    호스트 그룹/호스트 선택 필터의 규칙 (filter_groupids, filter_hostids)
	 *   self::exportInputFields()   내보내기용 규칙 (format = csv 또는 xlsx)
	 *
	 * @return bool  검사를 통과하면 true.
	 */
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
	 *
	 * @return bool  화면을 열어도 되면 true.
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 화면을 만드는 본체. 목록 화면(xxx.list)과 내보내기(xxx.export)가 같이 쓴다.
	 *
	 * 하는 일:
	 *   1) [적용] / [리셋] 을 눌렀으면 필터 값을 저장하거나 지운다.
	 *   2) 저장된 필터를 읽는다. 기간이 잘못됐으면 조회하지 않고 이유($period_error)만 정한다.
	 *   3) 정렬 값을 읽고 저장한다.
	 *   4) getRows() 로 목록을 구하고 정렬한다.
	 *   5) 내보내기 요청이면 전체 목록을 파일로 응답한다.
	 *   6) 아니면 페이지를 나눠 뷰로 넘긴다.
	 *
	 * 뷰가 받는 $data (주요 키):
	 *   'action'         => 'lowserver.list'           // 이 화면의 액션 이름
	 *   'export_action'  => 'lowserver.export'         // 내보내기 버튼이 부를 액션 이름
	 *   'columns'        => getColumns() 의 결과       // 표의 열 정의
	 *   'host_filter'    => HostFilter::toViewData()   // 선택창에 표시할 그룹/호스트
	 *   'filter'         => ['from' => '2026-10-01', 'to' => '2026-10-31', 'hours' => 'all']
	 *   'business_hours' => [9, 18]                    // 설정의 업무시간 [시작 시, 끝 시]
	 *   'criteria'       => 판정 기준 안내문
	 *   'period_error'   => ''                         // 기간이 잘못됐을 때의 안내문. 문제없으면 ''
	 *   'config_missing' => false                      // 아이템 키 설정이 비어 있으면 true
	 *   'sort', 'sortorder', 'paging', 'rows'
	 */
	protected function doAction(): void {
		// 화면별 이름으로 프로파일 키와 액션 이름을 만든다. 예) 'lowserver' -> 'web.dmove.lowserver.', 'lowserver.list'
		$page_key = $this->getPageKey();
		$profile_prefix = 'web.dmove.'.$page_key.'.';
		$list_action = $page_key.'.list';
		$export_action = $page_key.'.export';

		// 인자 없이 만들면 CPU 사용률을 집계한다. (includes/ServerUsageService.php)
		$service = new ServerUsageService();
		/*
		 * 판정 기준값. 사용자가 [기준설정] 창에서 바꾼 값이 있으면 그 값, 없으면 설정 파일의 기본값이다.
		 * (5버전도 사용자별 프로파일에서 읽고, 없으면 기본값을 썼다. includes/ServerUsageThresholds.php)
		 */
		$thresholds = ServerUsageThresholds::get($service->getThresholds());

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

		// 시작일 00:00:00 ~ 종료일 23:59:59 를 유닉스 시간(초)으로 바꾼다. 종료일 하루 전체가 들어간다.
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
		// 86400 = 하루의 초. 설정의 max_period_days(기본 366일)보다 긴 기간은 받지 않는다.
		elseif ($time_till - $time_from > $service->getMaxPeriodDays() * 86400) {
			$period_error = Lang::t('* The period can be up to 1 year.');
		}

		// ── 3) 정렬 값 ──
		$sort_field = $this->getInput('sort', CProfile::get($profile_prefix.'sort', 'name'));
		$sort_order = $this->getInput('sortorder', CProfile::get($profile_prefix.'sortorder', ZBX_SORT_UP));

		CProfile::update($profile_prefix.'sort', $sort_field, PROFILE_TYPE_STR);
		CProfile::update($profile_prefix.'sortorder', $sort_order, PROFILE_TYPE_STR);

		// ── 4) 데이터 ──
		// 조건 ? A : B : 조건이 참이면 A, 아니면 B. 기간이 잘못됐으면 조회하지 않고 빈 목록을 쓴다.
		$rows = $period_error === ''
			? $this->getRows($service, $host_filter, $time_from, $time_till, $filter['hours'], $thresholds)
			: [];

		$rows = self::sortRows($rows, $sort_field, $sort_order);

		$title = $this->getPageTitle();

		// ── 5) 내보내기 요청이면 파일로 응답하고 끝낸다 ──
		// 페이지를 나누기 전의 전체 목록을 넘긴다. 파일명은 '<화면 이름>_날짜_시각.csv(xlsx)' 가 된다.
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
	 *
	 * @param array  $rows        getRows() 의 결과.
	 * @param string $sort_field  'name' 또는 'avg'.
	 * @param string $sort_order  ZBX_SORT_UP('ASC') 또는 ZBX_SORT_DOWN('DESC').
	 *
	 * @return array  정렬된 목록. 키는 0, 1, 2 ... 로 다시 매겨진다.
	 */
	private static function sortRows(array $rows, string $sort_field, string $sort_order): array {
		if ($sort_field !== 'avg') {
			CArrayHelper::sort($rows, [['field' => $sort_field, 'order' => $sort_order]]);

			return array_values($rows);
		}

		// usort(배열, 비교 함수): 비교 함수가 돌려주는 값(음수 / 0 / 양수)으로 정렬한다.
		// use ($sort_order): 바깥 변수를 함수 안에서 쓰겠다는 선언이다.
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
			'name' => _('Host'),
			'ip' => _('IP'),
			'description' => _('Description'),
			// 숫자 열. 엑셀에 숫자로 들어가 정렬/평균을 낼 수 있다.
			'avg' => ['label' => Lang::t('Average CPU usage (%)'), 'type' => 'number'],
			'peak' => ['label' => 'Peak(%)', 'type' => 'number']
		];
	}

	/**
	 * 판정 기준에 맞는 서버 목록. 행 1개 = 서버 1대.
	 *
	 * @param ServerUsageService $service      평균 / Peak 를 계산하는 객체.
	 * @param array              $host_filter  ['groupids' => [...], 'hostids' => [...]]  (HostFilter::load() 의 결과)
	 * @param int                $time_from    시작 시각(유닉스 초). 시작일 00:00:00
	 * @param int                $time_till    끝 시각(유닉스 초). 종료일 23:59:59
	 * @param string             $hours        시간대. 'work'(업무시간) / 'night'(업무외시간) / 'all'(전체)
	 * @param array              $thresholds   conf/serverusage.conf.php 의 thresholds.
	 *
	 * @return array  [['hostid' => .., 'name' => .., 'ip' => .., 'description' => .., 'avg' => 12.34, 'peak' => 56.78], ...]
	 */
	private function getRows(ServerUsageService $service, array $host_filter, int $time_from, int $time_till,
			string $hours, array $thresholds): array {
		/*
		 * 1) 필터에 맞는 호스트. (사용자가 볼 수 있는 호스트만 나온다) host.get 옵션:
		 *    HostFilter::toApiOptions()  선택한 그룹/호스트를 groupids, hostids 조건으로 바꿔 준다.
		 *    output                      host = 호스트명(기술 이름), name = 표시명.
		 *    selectInterfaces            각 호스트에 'interfaces' => [[ip, main, type], ...] 를 붙인다. (대표 IP 용)
		 *    preservekeys                결과 배열의 키를 hostid 로 한다.
		 */
		/*
		 * 주의: HostFilter::toApiOptions() 는 반드시 API 호출 "앞"에서 변수에 담아 둔다.
		 * API::Host()->get(HostFilter::toApiOptions(...) + [...]) 처럼 괄호 안에 직접 쓰면 안 된다.
		 * 코어의 API::Host(), API::Item() 등은 같은 객체 하나를 돌려쓰면서 "지금 부를 API 이름"만 바꾼다.
		 * 괄호 안의 toApiOptions() 가 호스트 그룹을 조회하면(API::HostGroup()) 그 이름이 hostgroup 으로 바뀌어,
		 * 바깥의 get() 이 엉뚱하게 hostgroup.get 으로 실행된다. (호스트 그룹 필터를 골랐을 때만 생기는 오류다)
		 */
		$host_options = HostFilter::toApiOptions($host_filter);

		$hosts = API::Host()->get($host_options + [
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
	 *
	 * 예) '2026-10-01' -> '2026-10-01',  ' 2026-10-01 ' -> '2026-10-01',
	 *     '2026-02-30' -> '' (없는 날짜),  '2026/10/01' -> '' (형식이 다르다),  '' -> ''
	 *
	 * @param string $value  필터에 입력한 날짜.
	 *
	 * @return string  바른 날짜면 그 값(앞뒤 공백 제거), 아니면 ''.
	 */
	private static function normalizeDate(string $value): string {
		$value = trim($value);

		/*
		 * 정규식: ^ 처음, \d{4} 숫자 4개, - 하이픈, \d{2} 숫자 2개, $ 끝. 괄호로 묶은 부분이 $m 에 들어간다.
		 *   $m[1] = 연, $m[2] = 월, $m[3] = 일.   preg_match 는 맞으면 1 을 돌려준다.
		 */
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
			return '';
		}

		// checkdate(월, 일, 연): 실제로 있는 날짜인지 검사한다.
		return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
	}
}
