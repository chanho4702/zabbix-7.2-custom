<?php declare(strict_types = 0);
/*
 * 성능 데이터 추세 분석 화면 컨트롤러. (화면과 CSV 내보내기가 같이 쓴다)
 *
 * ── 이 화면이 하는 일 ──
 *   호스트 패턴과 아이템 패턴으로 고른 아이템들을 한 그래프에 그려서 추세를 본다.
 *   [CSV 내보내기] 로 그래프에 나온 아이템의 값을 파일로 받는다.
 *
 *   화면     : zabbix.php?action=perftrend.list
 *   그래프   : zabbix.php?action=perftrend.graph    (화면 스크립트가 값이 바뀔 때마다 부른다. JSON 응답)
 *   내보내기 : zabbix.php?action=perftrend.export   (화면의 값을 POST 로 받아 CSV 파일)
 *   메뉴     : 보고서 > 성능 데이터 추세 분석   (Module.php 의 REPORT_MENUS)
 *   5버전    : zabbix.php?action=data.analysis   (CSV: data.analysis.csv)
 *              컨트롤러 CControllerDataAnalysis.php, 뷰 monitoring.data.analysis.php
 *
 * ── 화면 구성 (5버전 화면과 같다) ──
 *   제목 오른쪽 : [CSV 내보내기]
 *   위쪽       : 그래프 (아래에 범례). 마우스를 올리면 값 말풍선.
 *   아래쪽     : 탭 [데이터 셋] [기간]
 *                데이터 셋 = 줄마다 색, 호스트 패턴(+선택), 아이템 패턴(+선택), 삭제(×).
 *                            줄을 누르면 펼쳐져 세부 옵션이 보인다.
 *                              왼쪽: 기본 색상, 그래프 형식, 너비, 포인트 사이즈, 투명도, 채우기
 *                              오른쪽: 결손 데이터, Y축, 타임 시프트, Aggregation function/interval, Aggregate
 *                            [+ 새로운 데이터 셋을 추가] 로 줄을 늘린다. (최대 10줄)
 *                기간      = [사용자 정의 기간 설정] 체크 + 시작/종료. 체크하지 않으면 최신 1시간.
 *                값을 바꾸면 [적용] 없이 바로 그래프가 다시 그려진다. (5버전 화면은 그래프 위젯의 설정 미리보기였다)
 *
 * ── 데이터 흐름 ──
 *   perftrend.list   : 저장된 데이터 셋과 기간으로 화면을 그린다. 그래프 칸은 비워 두고 스크립트가 채운다.
 *   perftrend.graph  : 화면의 값(ds, period, 그래프 크기)을 받아 저장하고, 그래프 SVG 를 JSON 으로 돌려준다.
 *   perftrend.export : 화면의 값을 받아 저장하고, resolveItems() -> countCsvRows() -> buildCsv() 로 파일을 만든다.
 *
 * ── 5버전과 달라진 점 ──
 *   - 5버전은 5.0 대시보드 위젯 코드를 복사해 빈 대시보드에 위젯 하나를 띄웠다. 7.0 은 대시보드가 바뀌어
 *     그 코드가 돌지 않는다. 화면 모양과 항목은 5버전과 같게 만들고, 그래프는 7.0 코어 그리기 함수로 그린다.
 *   - 데이터 셋과 기간은 사용자별로 저장되어 다음에 들어와도 남아 있다. 5버전은 매번 빈 화면에서 시작했다.
 *   - CSV 의 아이템 찾는 규칙과 권한은 includes/PerfTrendService.php 머리말 참고.
 *
 * ── 이 화면에 속한 파일 ──
 *   actions/reports/perftrend/PerfTrendList.php   컨트롤러 (이 파일. 세 액션이 같이 쓴다)
 *   views/reports/perftrend.list.php              화면 뷰
 *   views/js/reports/perftrend.list.js.php        데이터 셋 펼치기/추가/삭제, 그래프 다시 그리기, CSV 버튼
 *   includes/PerfTrendService.php                 값 정리, 그래프 그리기, CSV 만들기
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
use Widgets\SvgGraph\Includes\CWidgetFieldDataSet;

class PerfTrendList extends CController {

	/** 데이터 셋을 JSON 글자로 저장하는 프로파일 키. */
	private const DATA_SETS_PROFILE = 'web.dmove.perftrend.datasets';

	/** [기간] 탭의 값을 JSON 글자로 저장하는 프로파일 키. */
	private const PERIOD_PROFILE = 'web.dmove.perftrend.period';

	/** 그래프 크기 한계(픽셀). 스크립트가 보낸 크기를 이 안으로 줄인다. */
	private const MIN_WIDTH = 300;
	private const MAX_WIDTH = 4000;
	private const MIN_HEIGHT = 150;
	private const MAX_HEIGHT = 1500;

	/**
	 * 조회만 하는 화면이라 CSRF 토큰 검사를 끈다. (데이터 셋 저장은 그 사용자의 화면 설정일 뿐이다)
	 */
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	/**
	 * 들어온 값 검사.
	 *   ds      : 데이터 셋 배열. ds[n][hosts][], ds[n][items][], ds[n][color], ds[n][type], ds[n][width] ...
	 *             모양 검사는 PerfTrendService::normalizeDataSets() 가 한다.
	 *   period  : [기간] 탭. period[custom], period[from], period[to]
	 *   width / height : 그래프 크기 (perftrend.graph)
	 *   save    : 1 이면 값을 저장한다. 화면 스크립트가 늘 1 을 보낸다.
	 */
	protected function checkInput(): bool {
		$fields = [
			'ds' =>			'array',
			'period' =>		'array',
			'width' =>		'int32',
			'height' =>		'int32',
			'save' =>		'in 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(str_ends_with($this->getAction(), '.graph')
				? new CControllerResponseData(['main_block' => json_encode([
					'error' => ['messages' => array_column(get_and_clear_messages(), 'message')]
				])])
				: new CControllerResponseFatal()
			);
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
	 * 세 액션(perftrend.list / .graph / .export)의 본체.
	 */
	protected function doAction(): void {
		$service = new PerfTrendService();

		// ── 1) 값 저장. 스크립트가 보낸 화면의 값(데이터 셋, 기간)을 사용자별 프로파일에 JSON 글자로 저장한다. ──
		if ($this->hasInput('save')) {
			$data_sets = PerfTrendService::normalizeDataSets($this->getInput('ds', []));

			CProfile::update(self::DATA_SETS_PROFILE, json_encode($data_sets), PROFILE_TYPE_STR);
			CProfile::update(self::PERIOD_PROFILE,
				json_encode(PerfTrendService::normalizePeriod($this->getInput('period', []))), PROFILE_TYPE_STR
			);
		}

		// ── 2) 저장된 값 읽기 ──
		$saved = json_decode((string) CProfile::get(self::DATA_SETS_PROFILE, '[]'), true);
		$data_sets = PerfTrendService::normalizeDataSets(is_array($saved) ? $saved : []);

		$saved = json_decode((string) CProfile::get(self::PERIOD_PROFILE, '[]'), true);
		$period = PerfTrendService::normalizePeriod(is_array($saved) ? $saved : []);
		$resolved = PerfTrendService::resolvePeriod($period);

		$title = Lang::t('Performance trend analysis');

		// ── 3) 그래프 (JSON) ──
		if (str_ends_with($this->getAction(), '.graph')) {
			$this->graph($service, $data_sets, $resolved);

			return;
		}

		// ── 4) 내보내기 ──
		if (str_ends_with($this->getAction(), '.export')) {
			if ($resolved['error'] !== null) {
				CMessageHelper::setErrorTitle($resolved['error']);
				$this->setResponse(new CControllerResponseRedirect(
					(new CUrl('zabbix.php'))->setArgument('action', 'perftrend.list')
				));

				return;
			}

			$this->export($service, $data_sets, $resolved['from_ts'], $resolved['to_ts'], $title);

			return;
		}

		// ── 5) 화면 ──
		$response = new CControllerResponseData([
			'title' => $title,
			// 화면에 그릴 데이터 셋 줄. 저장된 것이 없으면 빈 줄 하나를 보여 준다.
			'data_sets' => $data_sets ?: PerfTrendService::normalizeDataSets([[]]),
			'max_data_sets' => PerfTrendService::MAX_DATA_SETS,
			'period' => $period,
			// 새 줄의 기본값과 색상표. 스크립트가 [+ 새로운 데이터 셋을 추가] 때 쓴다.
			'default_data_set' => PerfTrendService::defaultDataSet(),
			'color_palette' => CWidgetFieldDataSet::DEFAULT_COLOR_PALETTE
		]);
		$response->setTitle($title);

		$this->setResponse($response);
	}

	/**
	 * 그래프를 그려 JSON 으로 응답한다. (perftrend.graph)
	 *
	 * 응답 예)
	 *   {"svg": "<svg ...>", "legend": "<div ...>", "data": {...말풍선용...}, "messages": ["..."], "empty": false}
	 *   empty    : 호스트 패턴과 아이템 패턴이 둘 다 있는 줄이 없어 그릴 것이 없다.
	 *   messages : 기간 오류, 집계 간격 오류 같은 안내. 화면이 그래프 위에 노란 상자로 띄운다.
	 */
	private function graph(PerfTrendService $service, array $data_sets, array $resolved): void {
		$width = max(self::MIN_WIDTH, min(self::MAX_WIDTH, (int) $this->getInput('width', 1200)));
		$height = max(self::MIN_HEIGHT, min(self::MAX_HEIGHT, (int) $this->getInput('height', 300)));

		// 그래프 함수는 호스트 패턴과 아이템 패턴이 둘 다 있는 줄만 쓴다. 빈 줄은 빼고 넘긴다.
		$drawable = array_values(array_filter($data_sets,
			static fn(array $ds): bool => $ds['hosts'] && $ds['items']
		));

		/*
		 * 그릴 줄이 없어도 빈 그래프(가로축 시간 눈금만)를 그린다. 5버전 화면도 처음에는 빈 그래프가 보였다.
		 * 빈 데이터 셋을 넘기면 코어 함수가 축만 그린다.
		 */
		$graph = $service->buildGraph($drawable, $resolved['from_ts'], $resolved['to_ts'], $width, $height);

		$messages = $graph['errors'];

		if ($resolved['error'] !== null) {
			array_unshift($messages, $resolved['error']);
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'svg' => $graph['svg'],
			'legend' => $graph['legend'],
			'data' => $graph['data'] + [
				// class.csvggraph.js 의 말풍선이 읽는 값. 이 화면은 그래프를 끌어서 기간을 바꾸지 않는다(sbox 끔).
				'sbox' => false,
				'show_problems' => false,
				'show_simple_triggers' => false,
				// 말풍선 맨 위의 시각을 from_ts(시작 초) + 마우스 위치로 계산한다.
				'time_period' => [
					'from' => date(ZBX_FULL_DATE_TIME, $resolved['from_ts']),
					'to' => date(ZBX_FULL_DATE_TIME, $resolved['to_ts']),
					'from_ts' => $resolved['from_ts'],
					'to_ts' => $resolved['to_ts']
				],
				'hint_max_rows' => 20
			],
			'messages' => array_values($messages),
			'empty' => !$drawable
		])]));
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
