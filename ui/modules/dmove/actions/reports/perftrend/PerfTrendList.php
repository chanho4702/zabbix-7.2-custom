<?php declare(strict_types = 0);
/*
 * 성능 데이터 추세 분석 화면 컨트롤러. (화면과 CSV 내보내기가 같이 쓴다)
 *
 * ── 이 화면이 하는 일 ──
 *   호스트 패턴과 아이템 패턴으로 고른 아이템들을 한 그래프에 그려서 추세를 본다.
 *   [CSV 내보내기] 로 그래프에 나온 아이템의 값을 파일로 받는다.
 *
 *   화면     : zabbix.php?action=perftrend.list
 *   내보내기 : zabbix.php?action=perftrend.export&format=csv
 *   메뉴     : 보고서 > 성능 데이터 추세 분석   (Module.php 의 REPORT_MENUS)
 *   5버전    : zabbix.php?action=data.analysis   (CSV: data.analysis.csv)
 *              컨트롤러 CControllerDataAnalysis.php, 뷰 monitoring.data.analysis.php
 *
 * ── 화면 구성 (5버전과 같은 순서) ──
 *   제목 오른쪽 : [CSV 내보내기]
 *   위쪽       : 그래프 (아래에 범례)
 *   아래쪽     : 탭 [데이터 셋] [기간]
 *                데이터 셋 = 줄마다 색, 호스트 패턴, 아이템 패턴, 그래프 형식(선/포인트/계단/막대), 삭제.
 *                            [+ 데이터 셋 추가] 로 줄을 늘린다. (최대 10줄)
 *                기간      = 코어의 시간 선택기 (최신 1시간, 어제, 지난주 ... 또는 날짜 직접 입력)
 *
 * ── 데이터 흐름 ──
 *   doAction()
 *     ├─ 기간: 시간 선택기 값을 저장/읽기
 *     ├─ 데이터 셋: [적용] 이면 저장, [리셋] 이면 지움 (사용자별 프로파일, JSON 글자로)
 *     ├─ 화면이면 PerfTrendService::buildGraph() 로 그래프를 그려 뷰로 넘긴다
 *     └─ 내보내기면 PerfTrendService::resolveItems() -> countCsvRows() -> buildCsv() 로 파일을 만든다
 *
 * ── 5버전과 달라진 점 ──
 *   - 5버전은 대시보드 위젯 코드를 복사해 빈 대시보드에 위젯 하나를 띄웠다. 7버전은 코어 그래프 그리기 함수를 부른다.
 *     그래서 데이터 셋의 세부 옵션(너비, 투명도, 채우기, 결손 데이터, Y축, 타임 시프트, 집계)은 화면에 없고 기본값을 쓴다.
 *     5버전 화면의 기본값도 같은 값이었다(너비 1, 투명도 5, 채우기 3, 결손 데이터 없음, Y축 왼쪽, 집계 없음).
 *   - 그래프 위에 마우스를 올렸을 때 값이 뜨는 말풍선은 없다. (대시보드 위젯의 자바스크립트에 묶여 있다)
 *   - 데이터 셋은 사용자별로 저장되어 다음에 들어와도 남아 있다. 5버전은 매번 빈 화면에서 시작했다.
 *   - CSV 의 아이템 찾는 규칙과 권한은 includes/PerfTrendService.php 머리말 참고.
 *
 * ── 이 화면에 속한 파일 ──
 *   actions/reports/perftrend/PerfTrendList.php   컨트롤러 (이 파일)
 *   views/reports/perftrend.list.php              화면 뷰
 *   views/js/reports/perftrend.list.js.php        데이터 셋 줄 추가/삭제 스크립트
 *   includes/PerfTrendService.php                 그래프 그리기, CSV 만들기
 *   conf/perftrend.conf.php                       CSV 줄 수 한도
 */

namespace Modules\DMove\Actions\Reports\PerfTrend;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CMessageHelper;
use CProfile;
use CUrl;
use CWebUser;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\PerfTrendService;

class PerfTrendList extends CController {

	/** 시간 선택기와 필터 탭 상태를 저장하는 프로파일 키. 뷰의 CFilter::setProfile() 값과 같아야 한다. */
	private const TIMELINE_PROFILE = 'web.dmove.perftrend.filter';

	/** 데이터 셋을 JSON 글자로 저장하는 프로파일 키. */
	private const DATA_SETS_PROFILE = 'web.dmove.perftrend.datasets';

	/**
	 * 조회만 하는 화면이라 CSRF 토큰 검사를 끈다. (데이터 셋 저장은 그 사용자의 화면 설정일 뿐이다)
	 */
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	/**
	 * 주소로 들어온 값 검사.
	 *   from / to  : 시간 선택기가 보내는 기간 표현. 예) "now-1h", "2026-11-23 00:00:00"
	 *   ds         : 데이터 셋 배열. ds[0][hosts], ds[0][items], ds[0][color], ds[0][type] ...
	 *                모양 검사는 PerfTrendService::normalizeDataSets() 가 한다.
	 *   format     : 내보내기 형식. csv 만.
	 */
	protected function checkInput(): bool {
		$fields = [
			'from' =>		'range_time',
			'to' =>			'range_time',
			'filter_set' =>	'in 1',
			'filter_rst' =>	'in 1',
			'ds' =>			'array',
			'format' =>		'in csv'
		];

		$ret = $this->validateInput($fields) && $this->validateTimeSelectorPeriod();

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 * 그래프와 CSV 에는 사용자가 볼 수 있는 호스트의 아이템만 나온다. (API 로 찾는다)
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 화면(perftrend.list)과 내보내기(perftrend.export)의 본체.
	 */
	protected function doAction(): void {
		$service = new PerfTrendService();

		/*
		 * ── 1) 기간 (시간 선택기) ──
		 *   getInputs()                : 요청에 from / to 가 있으면 배열에 채운다.
		 *   updateTimeSelectorPeriod() : from / to 가 있으면 프로파일에 저장한다.
		 *   getTimeSelectorPeriod()    : 저장된 기간을 읽어 계산한다. from_ts / to_ts 가 유닉스 초다. 기본은 최신 1시간.
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
		$time_to = (int) $timeline['to_ts'];

		// ── 2) 데이터 셋 저장/초기화. 사용자별 프로파일에 JSON 글자로 저장한다. ──
		if ($this->hasInput('filter_set')) {
			$data_sets = PerfTrendService::normalizeDataSets($this->getInput('ds', []));

			// 패턴이 둘 다 빈 줄은 저장하지 않는다.
			$data_sets = array_values(array_filter($data_sets,
				static fn(array $ds): bool => $ds['hosts'] || $ds['items']
			));

			CProfile::update(self::DATA_SETS_PROFILE, json_encode($data_sets), PROFILE_TYPE_STR);
		}
		elseif ($this->hasInput('filter_rst')) {
			CProfile::delete(self::DATA_SETS_PROFILE);
		}

		$saved = json_decode((string) CProfile::get(self::DATA_SETS_PROFILE, '[]'), true);
		$data_sets = PerfTrendService::normalizeDataSets(is_array($saved) ? $saved : []);

		$title = Lang::t('Performance trend analysis');

		// ── 3) 내보내기 ──
		if (str_ends_with($this->getAction(), '.export')) {
			$this->export($service, $data_sets, $time_from, $time_to, $title);

			return;
		}

		// ── 4) 화면 ──
		$has_patterns = (bool) array_filter($data_sets, static fn(array $ds): bool => $ds['hosts'] && $ds['items']);
		$graph = $has_patterns ? $service->buildGraph($data_sets, $time_from, $time_to) : null;

		$response = new CControllerResponseData([
			'action' => 'perftrend.list',
			'export_action' => 'perftrend.export',
			'title' => $title,
			'timeline' => $timeline,
			'timeline_profile' => self::TIMELINE_PROFILE,
			'active_tab' => CProfile::get(self::TIMELINE_PROFILE.'.active', 1),
			// 화면에 그릴 데이터 셋 줄. 저장된 것이 없으면 빈 줄 하나를 보여 준다.
			'data_sets' => $data_sets ?: PerfTrendService::normalizeDataSets([[]]),
			'max_data_sets' => PerfTrendService::MAX_DATA_SETS,
			'graph' => $graph
		]);
		$response->setTitle($title);

		$this->setResponse($response);
	}

	/**
	 * CSV 를 만들어 응답한다. 문제가 있으면 화면으로 돌려보내고 이유를 띄운다.
	 *
	 *   CMessageHelper::setErrorTitle() : 다음 화면 위에 빨간 오류 상자로 보일 문구를 남긴다.
	 *   CControllerResponseRedirect     : 브라우저를 다른 주소로 보낸다.
	 */
	private function export(PerfTrendService $service, array $data_sets, int $time_from, int $time_to,
			string $title): void {
		$items = $service->resolveItems($data_sets);
		$back = (new CUrl('zabbix.php'))->setArgument('action', 'perftrend.list');

		if (!$items) {
			CMessageHelper::setErrorTitle(Lang::t('No items match the data sets.'));
			$this->setResponse(new CControllerResponseRedirect($back));

			return;
		}

		$rows = $service->countCsvRows($items, $time_from, $time_to);

		if ($rows > $service->getMaxCsvRows()) {
			CMessageHelper::setErrorTitle(Lang::t('About %1$s rows are expected, which exceeds the limit of %2$s rows. Choose fewer hosts or a shorter period.',
				number_format($rows), number_format($service->getMaxCsvRows())
			));
			$this->setResponse(new CControllerResponseRedirect($back));

			return;
		}

		$csv = $service->buildCsv($items, $time_from, $time_to);

		// 코어의 CSV 레이아웃(layout.csv)이 main_block 을 그대로 내보낸다.
		$response = new CControllerResponseData(['main_block' => $csv['body']]);
		$response->setTitle($title);
		// 파일명 예) data_history_20261123_101530.csv  (5버전: data_<종류>_<날짜시각>.csv)
		$response->setFileName('data_'.$csv['type'].'_'.date('Ymd_His').'.csv');

		$this->setResponse($response);
	}
}
