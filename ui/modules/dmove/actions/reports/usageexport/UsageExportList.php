<?php declare(strict_types = 0);
/*
 * CPU/Memory 전체 사용률 추출 화면 컨트롤러. (화면 구성 완료, 추출 기능은 TODO)
 *
 *   화면 : zabbix.php?action=usageexport.list
 *   메뉴 : 보고서 > CPU/Memory 전체 사용률 추출   (Module.php 의 REPORT_MENUS)
 *   5버전: zabbix.php?action=perf.analysis.view   (화면 제목 "CPU/Mem 사용률 추출")
 *
 * 5버전 화면 구성
 *   필터 : 호스트 그룹(선택창), 호스트(선택창)
 *          항목 = CPU Util & Mem Util / CPU Util only / Mem Util only   (라디오)
 *          시간 = 업무시간 (09:00 ~ 18:00) / 전체사용현황               (라디오)
 *   안내 : ※실시간 Data 출력은 전일 ~ 3개월 기간 출력이 가능합니다.
 *          ※다량의 Data export시 1분 이상 소요될 수 있습니다.
 *   표   : 없음. 결과를 화면에 보여 주지 않고 CSV 파일로만 내려받는 화면이다.
 *          한 번 실행하면 export_<날짜시각><순번>.csv 파일이 여러 개(확인된 것은 3개) 내려온다.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ TODO(고객환경) 추출 기능이 비어 있다.                                   │
 * │                                                                         │
 * │ 5버전 컨트롤러에서 확인할 것                                            │
 * │   - CPU / Memory 사용률을 읽는 아이템 키 (OS 별로 다를 수 있다)         │
 * │   - 값을 history 에서 읽는지 trends 에서 읽는지, 집계 단위(시간/일)     │
 * │   - 기간을 어떻게 정하는가 (화면에 날짜 입력이 없다. "전일 ~ 3개월")    │
 * │   - 파일이 여러 개로 나뉘는 기준 (항목별인지, 행 수 제한인지)           │
 * │   - CSV 의 열 구성                                                      │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * 구현할 때
 *   - 내보내기는 includes/export/ 의 플러그인을 쓴다. (template/README.md 의 "내보내기" 절)
 *     manifest.json 에 "usageexport.export" 액션을 추가하고, 이 컨트롤러에 use ExportTrait; 를 꽂는다.
 *   - 브라우저는 요청 한 번에 파일 하나만 받을 수 있다. 5버전처럼 파일을 여러 개 주려면
 *     버튼을 파일 수만큼 두거나(권장), 한 파일에 합친다.
 *   - 데이터가 많으면 PHP 실행 시간/메모리 제한에 걸린다. 호스트를 나눠 조회하고 trend.get 을 우선 검토한다.
 *
 * 이 화면에 속한 파일
 *   actions/reports/usageexport/UsageExportList.php   컨트롤러 (이 파일)
 *   views/reports/usageexport.list.php                화면 뷰
 *   includes/filter/HostFilter.php                    호스트 그룹/호스트 선택 필터 (여러 화면 공용)
 */

namespace Modules\DMove\Actions\Reports\UsageExport;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CProfile;
use CWebUser;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;

class UsageExportList extends CController {

	private const PROFILE_PREFIX = 'web.dmove.usageexport.';

	/** 항목 선택 값. */
	public const ITEMS_CPU_MEM = 0;
	public const ITEMS_CPU = 1;
	public const ITEMS_MEM = 2;

	/** 시간 선택 값. */
	public const TIME_BUSINESS = 0;	// 업무시간 09:00 ~ 18:00
	public const TIME_ALL = 1;		// 전체

	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_set' =>		'in 1',
			'filter_rst' =>		'in 1',
			'filter_items' =>	'in '.implode(',', [self::ITEMS_CPU_MEM, self::ITEMS_CPU, self::ITEMS_MEM]),
			'filter_time' =>	'in '.implode(',', [self::TIME_BUSINESS, self::TIME_ALL])
		] + HostFilter::INPUT_FIELDS;

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	protected function doAction(): void {
		// ── 1) 필터 저장/초기화 ──
		if ($this->hasInput('filter_set')) {
			HostFilter::save(self::PROFILE_PREFIX, $this->getInput('filter_groupids', []),
				$this->getInput('filter_hostids', [])
			);
			CProfile::update(self::PROFILE_PREFIX.'filter.items',
				(int) $this->getInput('filter_items', self::ITEMS_CPU_MEM), PROFILE_TYPE_INT
			);
			CProfile::update(self::PROFILE_PREFIX.'filter.time',
				(int) $this->getInput('filter_time', self::TIME_BUSINESS), PROFILE_TYPE_INT
			);
		}
		elseif ($this->hasInput('filter_rst')) {
			HostFilter::reset(self::PROFILE_PREFIX);
			CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.items');
			CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.time');
		}

		// ── 2) 저장된 필터 읽기 ──
		$host_filter = HostFilter::load(self::PROFILE_PREFIX);

		$title = Lang::t('CPU/Memory usage export');

		$response = new CControllerResponseData([
			'action' => $this->getAction(),
			'title' => $title,
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'filter.active', 1),
			'host_filter' => HostFilter::toViewData($host_filter),
			'filter' => [
				'items' => (int) CProfile::get(self::PROFILE_PREFIX.'filter.items', self::ITEMS_CPU_MEM),
				'time' => (int) CProfile::get(self::PROFILE_PREFIX.'filter.time', self::TIME_BUSINESS)
			],
			// 뷰에서 라디오 버튼 값으로 쓴다.
			'items_options' => [
				self::ITEMS_CPU_MEM => 'CPU Util & Mem Util',
				self::ITEMS_CPU => 'CPU Util only',
				self::ITEMS_MEM => 'Mem Util only'
			],
			'time_options' => [
				self::TIME_BUSINESS => Lang::t('Business hours (09:00 ~ 18:00)'),
				self::TIME_ALL => Lang::t('All hours')
			],
			'data_ready' => false	// TODO(고객환경): 추출 기능을 구현하면 true 로 바꾼다.
		]);
		$response->setTitle($title);

		$this->setResponse($response);
	}
}
