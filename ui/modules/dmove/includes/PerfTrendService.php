<?php declare(strict_types = 0);
/*
 * 성능 데이터 추세 분석의 데이터 처리. (그래프 그리기, CSV 만들기)
 *
 * ── 이 파일이 하는 일 ──
 *   사용자가 정한 "데이터 셋"(호스트 패턴 + 아이템 패턴 + 색, 그래프 형식, Y축, 타임 시프트, 집계 등)과 기간으로
 *     1) Zabbix 코어의 그래프 위젯과 같은 SVG 그래프를 그리고
 *     2) 같은 아이템의 값을 5버전과 같은 모양의 CSV 로 만든다.
 *
 * ── 누가 쓰는가 ──
 *   actions/reports/perftrend/PerfTrendList.php   화면(perftrend.list)과 CSV(perftrend.export)
 *
 * ── 5버전 대응 ──
 *   5버전은 기본 대시보드의 그래프 위젯 코드를 복사해서 독립 화면으로 만들었다. (CControllerDataAnalysis.php)
 *   7버전은 코드를 복사하지 않고, 코어 그래프 위젯이 쓰는 그리기 함수(CSvgGraphHelper::get)를 그대로 부른다.
 *   그래서 그래프 모양과 호스트/아이템 패턴 규칙이 Zabbix 기본 그래프 위젯과 같다.
 *
 *   CSV 는 5버전 컨트롤러와 같은 규칙이다.
 *     - 기간이 2시간 미만이면 원본값(history), 2시간 이상이면 1시간 요약값(trends)
 *     - 원본값 열 : "HOST","ITEM","수집시간","값(value)"
 *     - 요약값 열 : "HOST","ITEM","집계시간","최소","평균","최대"
 *     - 아이템마다 [제목 줄 + 값 줄들 + 빈 줄]
 *
 * ── 5버전과 달라진 점 ──
 *   - 5버전 CSV 는 호스트 이름과 아이템 이름을 "완전 일치"로 찾았다(SQL IN). 화면은 "패턴"인데 CSV 만 달랐다.
 *     7버전은 그래프와 같은 패턴 규칙(* 사용 가능)으로 찾는다. 그래서 그래프에 나온 아이템과 CSV 의 아이템이 같다.
 *   - 5버전 CSV 는 권한을 보지 않고 DB 에서 바로 읽었다. 7버전은 API 로 아이템을 찾아서 볼 수 있는 호스트의 값만 나온다.
 *   - 5버전은 호스트/아이템 이름을 SQL 글자에 그대로 이어 붙였다. 7버전은 그렇지 않다.
 *   - 줄 수 한도(conf/perftrend.conf.php)를 넘으면 파일을 만들지 않는다. 5버전은 한도가 없었다.
 */

namespace Modules\DMove\Includes;

use API;
use Widgets\SvgGraph\Includes\CSvgGraphHelper;
use Widgets\SvgGraph\Includes\CWidgetFieldDataSet;
use Widgets\SvgGraph\Includes\WidgetForm;

class PerfTrendService {

	/** 데이터 셋의 최대 개수. */
	public const MAX_DATA_SETS = 10;

	/** 그래프 형식. 코어 상수와 같은 값이다. (선 / 포인트 / 계단 / 막대) */
	public const GRAPH_TYPES = [SVG_GRAPH_TYPE_LINE, SVG_GRAPH_TYPE_POINTS, SVG_GRAPH_TYPE_STAIRCASE, SVG_GRAPH_TYPE_BAR];

	/** 이 길이(초)보다 짧은 기간이면 CSV 에 원본값을, 같거나 길면 1시간 요약값을 넣는다. (5버전: 3600 * 2) */
	public const HISTORY_RANGE = 7200;

	/** 그래프 크기(픽셀). */
	private const GRAPH_WIDTH = 1200;
	private const GRAPH_HEIGHT = 400;

	/** @var array conf/perftrend.conf.php 의 내용 */
	private array $config;

	/**
	 * 설정 파일을 읽는다. 없거나 값이 빠지면 기본값.
	 */
	public function __construct() {
		$file = dirname(__DIR__).'/conf/perftrend.conf.php';
		$config = is_readable($file) ? require $file : [];
		$config = is_array($config) ? $config : [];

		$this->config = [
			'max_csv_rows' => max(1, (int) ($config['max_csv_rows'] ?? 1000000))
		];
	}

	/** CSV 줄 수 한도. */
	public function getMaxCsvRows(): int {
		return $this->config['max_csv_rows'];
	}

	/** 결손 데이터 처리. 5버전 화면의 세 가지 (없음 / 접속됨 / 0 으로 처리). 코어 상수와 같은 값이다. */
	public const MISSING_DATA_FUNCS = [SVG_GRAPH_MISSING_DATA_NONE, SVG_GRAPH_MISSING_DATA_CONNECTED,
		SVG_GRAPH_MISSING_DATA_TREAT_AS_ZERO
	];

	/** 집계 함수. 5버전 화면과 같은 목록이다. (없음, min, max, avg, count, sum, first, last) */
	public const AGGREGATE_FUNCTIONS = [AGGREGATE_NONE, AGGREGATE_MIN, AGGREGATE_MAX, AGGREGATE_AVG, AGGREGATE_COUNT,
		AGGREGATE_SUM, AGGREGATE_FIRST, AGGREGATE_LAST
	];

	/** [기간] 탭에서 "사용자 정의 기간"을 고르지 않았을 때의 기간. 5버전처럼 최신 1시간. */
	public const DEFAULT_PERIOD = ['custom' => 0, 'from' => 'now-1h', 'to' => 'now'];

	/**
	 * 데이터 셋 한 줄의 기본값. 5버전 화면(5.0 그래프 위젯)의 기본값과 같다.
	 *   색은 줄마다 다르게 normalizeDataSets() 가 기본 색상표에서 고른다.
	 */
	public static function defaultDataSet(): array {
		return [
			'hosts' => [],
			'items' => [],
			'color' => CWidgetFieldDataSet::DEFAULT_COLOR_PALETTE[0],
			'type' => SVG_GRAPH_TYPE_LINE,
			'width' => SVG_GRAPH_DEFAULT_WIDTH,
			'pointsize' => SVG_GRAPH_DEFAULT_POINTSIZE,
			'transparency' => SVG_GRAPH_DEFAULT_TRANSPARENCY,
			'fill' => SVG_GRAPH_DEFAULT_FILL,
			'missingdatafunc' => SVG_GRAPH_MISSING_DATA_NONE,
			'axisy' => GRAPH_YAXIS_SIDE_LEFT,
			'timeshift' => '',
			'aggregate_function' => AGGREGATE_NONE,
			'aggregate_interval' => GRAPH_AGGREGATE_DEFAULT_INTERVAL,
			'aggregate_grouping' => GRAPH_AGGREGATE_BY_ITEM
		];
	}

	/**
	 * 화면/저장값에서 온 데이터 셋을 올바른 모양으로 고른다. 잘못된 값은 기본값으로 바꾼다.
	 *
	 * @param array $data_sets  화면의 ds[n][...] 값. hosts / items 는 배열(패턴 선택창) 또는 쉼표로 나눈 글자.
	 *   예) [['hosts' => ['web-*'], 'items' => ['CPU utilization'], 'color' => 'FF465C', 'type' => '0',
	 *         'width' => '1', 'timeshift' => '1w', ...], ...]
	 *
	 * @return array  defaultDataSet() 과 같은 키의 배열 목록. 최대 MAX_DATA_SETS 개.
	 *                호스트나 아이템이 비어 있어도 줄은 남긴다(화면에서 고칠 수 있게).
	 */
	public static function normalizeDataSets(array $data_sets): array {
		$result = [];
		$palette = CWidgetFieldDataSet::DEFAULT_COLOR_PALETTE;

		foreach (array_values($data_sets) as $index => $data_set) {
			if (!is_array($data_set) || count($result) >= self::MAX_DATA_SETS) {
				continue;
			}

			$default = self::defaultDataSet();

			// 색은 16진수 6글자. 아니면 코어 기본 색상표에서 순서대로 고른다.
			$color = strtoupper(ltrim(trim((string) ($data_set['color'] ?? '')), '#'));

			if (preg_match('/^[0-9A-F]{6}$/', $color) !== 1) {
				$color = $palette[$index % count($palette)];
			}

			// 타임 시프트: "1h", "-1d", "1w" 같은 시간 단위. 틀리거나 범위(약 ±10년) 밖이면 비운다(= 없음).
			$timeshift = trim((string) ($data_set['timeshift'] ?? ''));
			$seconds = $timeshift !== '' ? timeUnitToSeconds($timeshift, true) : null;

			if ($seconds === null || $seconds < ZBX_MIN_TIMESHIFT || $seconds > ZBX_MAX_TIMESHIFT) {
				$timeshift = '';
			}

			// 집계 간격: "1h" 같은 시간 단위. 틀리면 기본 1h.
			$interval = trim((string) ($data_set['aggregate_interval'] ?? ''));
			$seconds = $interval !== '' ? timeUnitToSeconds($interval, true) : null;

			if ($seconds === null || $seconds < 1 || $seconds > ZBX_MAX_TIMESHIFT) {
				$interval = GRAPH_AGGREGATE_DEFAULT_INTERVAL;
			}

			$result[] = [
				'hosts' => self::splitPatterns($data_set['hosts'] ?? []),
				'items' => self::splitPatterns($data_set['items'] ?? []),
				'color' => $color,
				'type' => self::pick($data_set, 'type', self::GRAPH_TYPES, $default['type']),
				'width' => self::range($data_set, 'width', 0, 10, $default['width']),
				'pointsize' => self::range($data_set, 'pointsize', 1, 10, $default['pointsize']),
				'transparency' => self::range($data_set, 'transparency', 0, 10, $default['transparency']),
				'fill' => self::range($data_set, 'fill', 0, 10, $default['fill']),
				'missingdatafunc' => self::pick($data_set, 'missingdatafunc', self::MISSING_DATA_FUNCS,
					$default['missingdatafunc']
				),
				'axisy' => self::pick($data_set, 'axisy', [GRAPH_YAXIS_SIDE_LEFT, GRAPH_YAXIS_SIDE_RIGHT],
					$default['axisy']
				),
				'timeshift' => $timeshift,
				'aggregate_function' => self::pick($data_set, 'aggregate_function', self::AGGREGATE_FUNCTIONS,
					$default['aggregate_function']
				),
				'aggregate_interval' => $interval,
				'aggregate_grouping' => self::pick($data_set, 'aggregate_grouping',
					[GRAPH_AGGREGATE_BY_ITEM, GRAPH_AGGREGATE_BY_DATASET], $default['aggregate_grouping']
				)
			];
		}

		return $result;
	}

	/**
	 * [기간] 탭의 값을 고른다.
	 *
	 * @param array $period  ['custom' => '1', 'from' => 'now-1d', 'to' => 'now']
	 *
	 * @return array  ['custom' => 0 또는 1, 'from' => .., 'to' => ..]. 글자가 비면 기본값.
	 */
	public static function normalizePeriod(array $period): array {
		$from = trim((string) ($period['from'] ?? ''));
		$to = trim((string) ($period['to'] ?? ''));

		return [
			'custom' => (int) ($period['custom'] ?? 0) === 1 ? 1 : 0,
			'from' => $from !== '' ? $from : self::DEFAULT_PERIOD['from'],
			'to' => $to !== '' ? $to : self::DEFAULT_PERIOD['to']
		];
	}

	/**
	 * 기간을 유닉스 초로 바꾼다. "사용자 정의 기간"이 꺼져 있으면 최신 1시간.
	 *
	 *   CRangeTimeParser : 코어의 시간 표현 해석기. "now-1d/d", "2026-11-25 00:00:00" 같은 글자를 읽는다.
	 *
	 * @param array $period  normalizePeriod() 의 결과.
	 *
	 * @return array  ['from_ts' => 초, 'to_ts' => 초, 'error' => 오류 문구 또는 null]
	 *                오류면 from_ts / to_ts 는 최신 1시간이다.
	 */
	public static function resolvePeriod(array $period): array {
		$source = $period['custom'] ? $period : self::DEFAULT_PERIOD;
		$parser = new \CRangeTimeParser();

		$from_ts = $parser->parse($source['from']) === \CParser::PARSE_SUCCESS
			? $parser->getDateTime(true)->getTimestamp()
			: null;
		$to_ts = $parser->parse($source['to']) === \CParser::PARSE_SUCCESS
			? $parser->getDateTime(false)->getTimestamp()
			: null;

		$error = null;

		if ($from_ts === null) {
			$error = _s('Invalid parameter "%1$s": %2$s.', Lang::t('From'), _('a time is expected'));
		}
		elseif ($to_ts === null) {
			$error = _s('Invalid parameter "%1$s": %2$s.', Lang::t('To'), _('a time is expected'));
		}
		elseif ($from_ts >= $to_ts) {
			$error = Lang::t('"From" must be earlier than "To".');
		}

		if ($error !== null) {
			return ['from_ts' => time() - SEC_PER_HOUR, 'to_ts' => time(), 'error' => $error];
		}

		return ['from_ts' => $from_ts, 'to_ts' => $to_ts, 'error' => null];
	}

	/**
	 * 데이터 셋으로 그래프를 그린다. 코어 그래프 위젯의 그리기 함수를 그대로 쓴다.
	 *
	 * @param array $data_sets  normalizeDataSets() 의 결과.
	 * @param int   $time_from  시작(유닉스 초)
	 * @param int   $time_to    끝(유닉스 초)
	 * @param int   $width      그래프 너비(픽셀). 화면 너비에 맞춰 스크립트가 보낸다.
	 * @param int   $height     그래프 높이(픽셀). 범례 포함.
	 *
	 * @return array  ['svg' => SVG 그래프 HTML, 'legend' => 범례 HTML 또는 '', 'errors' => [오류 문구, ...],
	 *                 'data' => 마우스를 올렸을 때 값 말풍선을 띄우는 데 필요한 값(그래프 크기 등)]
	 */
	public function buildGraph(array $data_sets, int $time_from, int $time_to, int $width = self::GRAPH_WIDTH,
			int $height = self::GRAPH_HEIGHT): array {
		$ds = [];

		foreach ($data_sets as $data_set) {
			// 코어 데이터 셋의 기본값(쌓기 없음, 근사값 평균 등)에 화면의 값을 덮어쓴다.
			$ds[] = [
				'dataset_type' => CWidgetFieldDataSet::DATASET_TYPE_PATTERN_ITEM
			] + $data_set + CWidgetFieldDataSet::getDefaults();
		}

		/*
		 * 코어 그래프 위젯(ui/widgets/svggraph/actions/WidgetView.php)이 CSvgGraphHelper::get() 에 넘기는 값과 같은 모양이다.
		 * 위젯 설정 화면의 기본값을 그대로 썼다. (Y축 자동, 범례 켬, 문제 표시 끔, 데이터 출처 자동)
		 */
		$graph = CSvgGraphHelper::get([
			'data_sets' => $ds,
			'data_source' => SVG_GRAPH_DATA_SOURCE_AUTO,
			// 이 화면의 기간은 아래 시간 선택기로만 바꾼다. 그래프 위를 끌어서 기간을 바꾸는 기능은 쓰지 않는다.
			'fix_time_period' => true,
			'displaying' => [
				'show_simple_triggers' => false,
				'show_working_time' => false,
				'show_percentile_left' => false,
				'percentile_left_value' => null,
				'show_percentile_right' => false,
				'percentile_right_value' => null
			],
			'time_period' => ['time_from' => $time_from, 'time_to' => $time_to],
			'axes' => [
				'show_left_y_axis' => true,
				'left_y_min' => null,
				'left_y_max' => null,
				'left_y_units' => null,
				'show_right_y_axis' => true,
				'right_y_min' => null,
				'right_y_max' => null,
				'right_y_units' => null,
				'show_x_axis' => true
			],
			'legend' => [
				'show_legend' => WidgetForm::LEGEND_ON,
				'legend_columns' => 4,
				'legend_lines' => 3,
				'legend_lines_mode' => WidgetForm::LEGEND_LINES_MODE_VARIABLE,
				'legend_statistic' => 0,
				'show_aggregation' => false
			],
			'problems' => [
				'show_problems' => false,
				'graph_item_problems' => false,
				'problemhosts' => '',
				'severities' => [],
				'problem_name' => '',
				'evaltype' => TAG_EVAL_TYPE_AND_OR,
				'tags' => []
			],
			'overrides' => [],
			'templateid' => '',
			'override_hostid' => ''
		], $width, $height);

		return [
			'svg' => (string) $graph['svg'],
			'legend' => (string) $graph['legend'],
			'errors' => (array) ($graph['errors'] ?? []),
			'data' => (array) ($graph['data'] ?? [])
		];
	}

	/**
	 * 데이터 셋의 아이템을 찾는다. 그래프와 같은 규칙이다. (코어 CSvgGraphHelper::getMetricsPattern 과 같다)
	 *
	 *   - 호스트 패턴: 호스트 "표시 이름"에서 찾는다. * 는 "아무 글자". 패턴에 * 하나만 있으면 전체.
	 *   - 아이템 패턴: 아이템 이름(매크로를 푼 이름)에서 찾는다. 규칙은 같다.
	 *   - 숫자 아이템(소수, 정수)만. 문자/로그 아이템은 그래프로 그릴 수 없어 뺀다.
	 *   - API 로 찾으므로 사용자가 볼 수 있는 호스트의 아이템만 나온다.
	 *
	 * @return array  [itemid => ['host' => 호스트 이름, 'name' => 아이템 이름, 'value_type' => 0 또는 3], ...]
	 */
	public function resolveItems(array $data_sets): array {
		$items = [];

		foreach ($data_sets as $data_set) {
			if (!$data_set['hosts'] || !$data_set['items']) {
				continue;
			}

			// in_array('*', ...): 패턴에 * 하나만 있으면 조건 없음(null) = 전체. 코어와 같은 처리.
			$host_search = in_array('*', $data_set['hosts'], true) ? null : $data_set['hosts'];
			$item_search = in_array('*', $data_set['items'], true) ? null : $data_set['items'];

			$hosts = API::Host()->get([
				'output' => ['name'],
				'search' => ['name' => $host_search],
				'searchWildcardsEnabled' => true,
				'searchByAny' => true,
				'preservekeys' => true
			]);

			if (!$hosts) {
				continue;
			}

			$db_items = API::Item()->get([
				'output' => ['itemid', 'hostid', 'name_resolved', 'value_type'],
				'hostids' => array_keys($hosts),
				'webitems' => true,
				'filter' => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]],
				'search' => ['name_resolved' => $item_search],
				'searchWildcardsEnabled' => true,
				'searchByAny' => true,
				'sortfield' => 'name',
				'limit' => SVG_GRAPH_MAX_NUMBER_OF_METRICS
			]);

			foreach ($db_items as $item) {
				$items[$item['itemid']] = [
					'host' => $hosts[$item['hostid']]['name'],
					'name' => $item['name_resolved'],
					'value_type' => (int) $item['value_type']
				];
			}
		}

		return $items;
	}

	/**
	 * CSV 에 들어갈 값 줄 수를 센다. 한도를 넘는지 미리 보려고 쓴다.
	 *
	 * @param array $items  resolveItems() 의 결과.
	 */
	public function countCsvRows(array $items, int $time_from, int $time_to): int {
		$count = 0;

		foreach (self::groupByTable($items, $time_to - $time_from) as $table => $itemids) {
			$result = DBselect(
				'SELECT COUNT(*) AS cnt FROM '.$table.
				' WHERE '.dbConditionId('itemid', $itemids).
					' AND clock>='.$time_from.' AND clock<='.$time_to
			);

			if ($result !== false && $row = DBfetch($result)) {
				$count += (int) $row['cnt'];
			}
		}

		return $count;
	}

	/**
	 * CSV 본문을 만든다. (5버전과 같은 모양)
	 *
	 * 예) 원본값(2시간 미만)
	 *   "HOST","ITEM","수집시간","값(value)"
	 *   "web-01","CPU utilization","2026-11-23 10:00:00","12.5"
	 *   (빈 줄)
	 *
	 * @param array $items  resolveItems() 의 결과.
	 *
	 * @return array  ['body' => CSV 글, 'type' => 'history' 또는 'trends']
	 */
	public function buildCsv(array $items, int $time_from, int $time_to): array {
		$use_history = ($time_to - $time_from) < self::HISTORY_RANGE;
		$header = $use_history
			? ['HOST', 'ITEM', Lang::t('Collected at'), Lang::t('Value')]
			: ['HOST', 'ITEM', Lang::t('Aggregated at'), Lang::t('Min'), Lang::t('Avg'), Lang::t('Max')];

		$tables = self::groupByTable($items, $time_to - $time_from);
		$lines = [];

		// 5버전처럼 아이템 하나마다 [제목 줄 + 값 줄 + 빈 줄]. 아이템 이름순.
		uasort($items, static fn(array $a, array $b): int =>
			strnatcasecmp($a['host'].' '.$a['name'], $b['host'].' '.$b['name'])
		);

		foreach ($items as $itemid => $item) {
			$table = self::tableFor($item['value_type'], $use_history);
			$columns = $use_history ? 'clock,value' : 'clock,value_min,value_avg,value_max';

			$lines[] = $header;

			$result = DBselect(
				'SELECT '.$columns.' FROM '.$table.
				' WHERE itemid='.zbx_dbstr($itemid).
					' AND clock>='.$time_from.' AND clock<='.$time_to.
				' ORDER BY clock'
			);

			while ($result !== false && $row = DBfetch($result)) {
				$time = date('Y-m-d H:i:s', (int) $row['clock']);

				$lines[] = $use_history
					? [$item['host'], $item['name'], $time, $row['value']]
					: [$item['host'], $item['name'], $time, $row['value_min'], $row['value_avg'], $row['value_max']];
			}

			// 아이템 사이의 빈 줄. CsvWriter 가 빈 칸 하나짜리 줄로 쓴다.
			$lines[] = [''];
		}

		unset($tables);

		return [
			'body' => Export\CsvWriter::toCsv(array_shift($lines) ?? $header, $lines),
			'type' => $use_history ? 'history' : 'trends'
		];
	}

	/**
	 * 아이템을 읽을 표별로 나눈다. [표 이름 => [itemid, ...]]
	 */
	private static function groupByTable(array $items, int $range): array {
		$tables = [];

		foreach ($items as $itemid => $item) {
			$tables[self::tableFor($item['value_type'], $range < self::HISTORY_RANGE)][] = $itemid;
		}

		return $tables;
	}

	/**
	 * 값이 저장된 표. 정수 아이템은 *_uint 표에 있다. (5버전: value_type == 3 이면 _uint)
	 */
	private static function tableFor(int $value_type, bool $use_history): string {
		$base = $use_history ? 'history' : 'trends';

		return $value_type == ITEM_VALUE_TYPE_UINT64 ? $base.'_uint' : $base;
	}

	/**
	 * $values[$key] 가 $allowed 중 하나면 그 정수를, 아니면 $default 를 돌려준다.
	 */
	private static function pick(array $values, string $key, array $allowed, int $default): int {
		$value = $values[$key] ?? null;

		return (is_numeric($value) && in_array((int) $value, $allowed, true)) ? (int) $value : $default;
	}

	/**
	 * $values[$key] 가 $min ~ $max 사이의 정수면 그 값을, 아니면 $default 를 돌려준다. (슬라이더 값)
	 */
	private static function range(array $values, string $key, int $min, int $max, int $default): int {
		$value = $values[$key] ?? null;

		return (is_numeric($value) && (int) $value >= $min && (int) $value <= $max) ? (int) $value : $default;
	}

	/**
	 * 쉼표로 나눈 패턴 글자를 배열로. 빈 값은 버린다.  예) 'web-*, db-01' -> ['web-*', 'db-01']
	 */
	private static function splitPatterns($value): array {
		$parts = is_array($value) ? $value : explode(',', (string) $value);
		$parts = array_map(static fn($part): string => trim((string) $part), $parts);

		return array_values(array_unique(array_filter($parts, static fn(string $part): bool => $part !== '')));
	}
}
