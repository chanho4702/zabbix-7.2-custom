<?php declare(strict_types = 0);
/*
 * CPU/Memory 전체 사용률 추출의 데이터 처리.
 *
 * ── 이 파일이 하는 일 ──
 *   서버들의 CPU / 메모리 사용률 "수집값 원본"을 CSV 로 내보낸다. 수집값 한 건이 CSV 한 줄이다.
 *
 *     host,item,clock,value
 *     web-01,CPU utilization,2026-11-22 09:00:00,12.5
 *     web-01,CPU utilization,2026-11-22 09:03:00,13.1
 *
 *   파일 모양은 5버전과 같다. 5버전은 PostgreSQL 의 \COPY (...) TO ... WITH (FORMAT CSV, header, ENCODING 'UTF8')
 *   로 파일을 만들었다. 그래서 BOM 이 없고, 제목 줄은 SQL 의 열 이름(소문자)이고, 값은 필요할 때만 따옴표로 감싼다.
 *   (csvLine() 설명)
 *
 * ── 누가 쓰는가 ──
 *   actions/reports/usageexport/UsageExportList.php  : buildPlan() 으로 "무엇을 내보낼지" 계획을 만들고,
 *                                                      preview() 로 화면에 보여 줄 앞부분 몇 줄을 읽는다
 *   views/export/layout.stream.php                   : stream() 으로 계획대로 CSV 를 내려보낸다
 *
 * ── 5버전 대응 ──
 *   뷰 monitoring.perf.view.php (화면과 추출 로직이 한 파일에 있었다)
 *
 * ── 5버전과 달라진 점 ──
 *   5버전 : CPU/메모리 값만 따로 복사해 둔 표(Zabbix 표준 표가 아님)에서 읽고,
 *           웹 서버가 셸 스크립트를 실행해 서버 폴더에 CSV 파일을 만든 뒤 내려받게 했다.
 *   7버전 : Zabbix 표준 이력 표(history, history_uint)에서 바로 읽어 브라우저로 흘려보낸다.
 *           DB 에 표를 추가하지 않고, 서버에 스크립트나 임시 파일을 두지 않는다.
 *   CSV 의 열과 한 줄의 뜻(수집값 한 건)은 5버전과 같다.
 *
 * ── 알아 둘 점 ──
 *   - 이력 표에는 아이템마다 정한 "이력 보관 기간" 만큼만 값이 남아 있다. (데이터 수집 > 호스트 > 아이템 > 이력 보관 기간)
 *     3개월치를 받으려면 CPU/메모리 아이템의 이력 보관 기간이 3개월 이상이어야 한다.
 *     보관 기간보다 오래된 날짜를 고르면 그 구간은 파일에 나오지 않는다.
 *   - 줄 수가 많다. 서버 1000대의 3개월치면 수천만 줄이다. 그래서 두 가지를 한다.
 *       1) 내려보내기 전에 실제 줄 수를 세어(countRows) 한도(max_rows)를 넘으면 내보내지 않는다.
 *          (5버전은 어림한 줄 수로 막았다. 어림은 화면 안내로만 쓴다)
 *       2) 아이템을 몇 개씩 묶어 나눠 조회하고, 읽는 대로 바로 내려보낸다. 전체를 메모리에 올리지 않는다.
 *   - CLOCK 과 업무시간 조건은 5버전처럼 SQL 에서 to_timestamp() 로 계산한다. 그래서 PostgreSQL 전용이고,
 *     시각은 DB 서버(접속 세션)의 시간대로 나온다. (5버전 psql 도 같았다)
 *   - 이력을 DB 에서 직접 읽는 것은 history.get API 가 결과를 한꺼번에 배열로 돌려줘서 이 분량을 감당할 수 없기 때문이다.
 *     대신 "어느 호스트/아이템을 볼 수 있는가"는 API 로 걸러서 사용자 권한을 지킨다.
 */

namespace Modules\DMove\Includes;

use API;
use CHousekeepingHelper;
use Modules\DMove\Includes\Export\ZipStreamWriter;
use Modules\DMove\Includes\Filter\HostFilter;

class UsageExportService {

	/** 항목 선택 값. 화면의 라디오 버튼 값이기도 하다. (5버전: 1, 2, 3) */
	public const ITEMS_CPU_MEM = 0;
	public const ITEMS_CPU = 1;
	public const ITEMS_MEM = 2;

	/** 시간 선택 값. (5버전: 1 = 업무시간, 2 = 전체) */
	public const TIME_BUSINESS = 0;
	public const TIME_ALL = 1;

	/** 한 번의 조회로 읽을 줄 수의 목표. 아이템을 이 분량이 되도록 묶어서 나눠 조회한다. */
	private const ROWS_PER_QUERY = 200000;

	/** @var array  conf/usageexport.conf.php 의 내용 (빠진 값은 기본값으로 채움) */
	private array $config;

	/** stream() 이 지금까지 쓴 값 줄 수. */
	private int $written = 0;

	/** 나눠 받기일 때 zip 을 쓰는 도구. 한 파일로 받을 때는 null. */
	private ?ZipStreamWriter $zip = null;

	/** 나눠 받기일 때 지금 파일에 쓴 줄 수(제목 줄 포함)와 파일 번호, zip 안 파일명의 앞부분. */
	private int $part_rows = 0;
	private int $part_no = 0;
	private string $part_base = '';

	/** CSV 제목 줄. 5버전 SQL 의 열 이름(SELECT host, item, ... as clock, value) 그대로다. */
	private const HEADER = ['host', 'item', 'clock', 'value'];

	/**
	 * 설정 파일을 읽는다. 파일이 없거나 값이 빠져 있으면 5버전 기본값을 쓴다.
	 */
	public function __construct() {
		$file = dirname(__DIR__).'/conf/usageexport.conf.php';
		// 설정 파일은 "return [ ... ];" 형태다. require 가 그 배열을 돌려준다.
		$config = is_readable($file) ? require $file : [];

		if (!is_array($config)) {
			$config = [];
		}

		$hours = (array) ($config['business_hours'] ?? [9, 18]);

		$this->config = [
			// max(1, ...): 0 이나 음수가 들어와도 최소 1 로 본다.
			'max_period_days' => max(1, (int) ($config['max_period_days'] ?? 93)),
			'assumed_interval' => max(1, (int) ($config['assumed_interval'] ?? 180)),
			'max_rows' => max(1, (int) ($config['max_rows'] ?? 10000000)),
			'split_rows' => max(1, (int) ($config['split_rows'] ?? 1000000)),
			'business_hours' => [(int) ($hours[0] ?? 9), (int) ($hours[1] ?? 18)],
			// 아이템 키. 비어 있으면 화면에 "설정이 필요합니다" 안내가 나온다.
			'cpu_item_keys' => array_values((array) ($config['cpu_item_keys'] ?? [])),
			'memory_item_keys' => array_values((array) ($config['memory_item_keys'] ?? []))
		];
	}

	/** 한 번에 내보낼 수 있는 최대 기간(일). 예) 93 */
	public function getMaxPeriodDays(): int {
		return $this->config['max_period_days'];
	}

	/** 값 줄 수의 한도. 예) 10000000 */
	public function getMaxRows(): int {
		return $this->config['max_rows'];
	}

	/** 파일 하나의 줄 수(제목 줄 포함). 넘으면 나눠서 zip 으로 묶는다. 예) 1000000 */
	public function getSplitRows(): int {
		return $this->config['split_rows'];
	}

	/** 업무시간 [시작 시, 끝 시]. 예) [9, 18] */
	public function getBusinessHours(): array {
		return $this->config['business_hours'];
	}

	/**
	 * 고른 항목에 필요한 아이템 키 설정(conf/usageexport.conf.php)이 채워져 있는가.
	 * 비어 있으면 화면에 "설정이 필요합니다" 안내를 띄운다.
	 *
	 * @param int $items  ITEMS_CPU_MEM / ITEMS_CPU / ITEMS_MEM
	 */
	public function isConfigured(int $items): bool {
		foreach (self::keyNamesFor($items) as $name) {
			if (!$this->config[$name]) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 내보내기 계획을 만든다. 실제 값은 아직 읽지 않는다.
	 *
	 * 하는 일:
	 *   1) 필터에 맞는 호스트를 API 로 찾는다. (사용자가 볼 수 있는 호스트만 나온다)
	 *      호스트를 하나라도 골랐으면 그룹은 보지 않고 고른 호스트만 쓴다. (5버전 monitoring.perf.view.php 138, 180행)
	 *   2) 그 호스트의 CPU / 메모리 아이템을 찾는다. (키는 conf/usageexport.conf.php, 정확히 같은 키)
	 *   3) 줄 수를 어림한다. 기간(초) / 수집 간격(초) x 아이템 수. (화면 안내용. 실제 판단은 countRows())
	 *
	 * @param array $host_filter  ['groupids' => [...], 'hostids' => [...]]  비어 있으면 볼 수 있는 전체 호스트.
	 * @param int   $items        ITEMS_CPU_MEM / ITEMS_CPU / ITEMS_MEM
	 * @param int   $hours        TIME_BUSINESS / TIME_ALL
	 * @param int   $time_from    시작 시각(유닉스 초)
	 * @param int   $time_till    끝 시각(유닉스 초)
	 *
	 * @return array
	 *   [
	 *     'items'          => [itemid => ['host' => 'web-01', 'name' => 'CPU utilization', 'value_type' => 0], ...],
	 *     'time_from'      => 1795000000,
	 *     'time_till'      => 1795086400,
	 *     'hours'          => TIME_BUSINESS,
	 *     'estimated_rows' => 480,          // 어림한 줄 수 (화면 안내용)
	 *     'split'          => false,        // 어림으로 보아 zip 으로 나눠 받을 것 같은가 (화면 안내용.
	 *                                       // 내보낼 때 컨트롤러가 실제 줄 수로 다시 정한다)
	 *     'history_available_from' => 1792400000  // 이보다 이른 값은 보관 기간이 지나 지워졌을 수 있다. 모르면 null
	 *   ]
	 */
	public function buildPlan(array $host_filter, int $items, int $hours, int $time_from, int $time_till): array {
		/*
		 * 1) 호스트. HostFilter::toApiOptions() 가 선택한 그룹(하위 그룹 포함)/호스트를 조건으로 바꿔 준다.
		 *    preservekeys : 결과 배열의 키를 hostid 로 한다.
		 */
		/*
		 * 주의: HostFilter::toApiOptions() 는 반드시 API 호출 "앞"에서 변수에 담아 둔다.
		 * API::Host()->get(HostFilter::toApiOptions(...) + [...]) 처럼 괄호 안에 직접 쓰면 안 된다.
		 * 코어의 API::Host(), API::Item() 등은 같은 객체 하나를 돌려쓰면서 "지금 부를 API 이름"만 바꾼다.
		 * 괄호 안의 toApiOptions() 가 호스트 그룹을 조회하면(API::HostGroup()) 그 이름이 hostgroup 으로 바뀌어,
		 * 바깥의 get() 이 엉뚱하게 hostgroup.get 으로 실행된다. (호스트 그룹 필터를 골랐을 때만 생기는 오류다)
		 */
		// 이 화면만의 규칙: 호스트를 골랐으면 그룹 조건은 빼고 호스트만 쓴다. (HostFilter 자체는 바꾸지 않는다)
		if ($host_filter['hostids']) {
			$host_filter['groupids'] = [];
		}

		$host_options = HostFilter::toApiOptions($host_filter);

		$hosts = API::Host()->get($host_options + [
			'output' => ['hostid', 'name'],
			'preservekeys' => true
		]);

		// 2) 아이템. 고른 항목(CPU, 메모리)의 키를 모은다. 예) ['system.cpu.util', '100-system.cpu.util[all,idle,avg1]']
		$keys = [];

		foreach (self::keyNamesFor($items) as $name) {
			$keys = array_merge($keys, $this->config[$name]);
		}

		$plan_items = [];
		$item_history = [];

		if ($hosts && $keys) {
			/*
			 * 키가 정확히 같은 아이템과 그 이름, 값 형식. (5버전: itemkey in (...))
			 *   value_type : 0 = 소수(history 표), 3 = 정수(history_uint 표). 그 밖의 형식(문자 등)은 사용률이 아니므로 뺀다.
			 */
			$db_items = API::Item()->get([
				// delay: 수집 간격. 줄 수를 어림하는 데 쓴다. (예: '1m', '30s', '5m;wd1-5,9-18')
				// history: 아이템의 원본 값 보관 기간. (예: '31d') 보관 기간 안내에 쓴다.
				'output' => ['itemid', 'hostid', 'name', 'value_type', 'delay', 'history'],
				'hostids' => array_keys($hosts),
				'filter' => [
					'key_' => $keys,
					'value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]
				]
			]);

			foreach ($db_items as $item) {
				$plan_items[$item['itemid']] = [
					'host' => $hosts[$item['hostid']]['name'],
					'name' => $item['name'],
					'value_type' => (int) $item['value_type'],
					// 이 아이템의 어림 줄 수 = 기간(초) / 수집 간격(초). intdiv(a, b): 나눗셈의 몫(정수).
					'est_rows' => intdiv(max(0, $time_till - $time_from), $this->intervalOf($item['delay']))
				];
				$item_history[] = $item['history'];
			}
		}

		/*
		 * 3) 줄 수 어림. 아이템마다 실제 수집 간격으로 어림해서 더한다.
		 *    (예전에는 모든 아이템을 3분 간격으로 보았다. 그러면 1초마다 수집하는 아이템이 있을 때 줄 수를 크게 낮게 어림했다)
		 *    array_column(배열, 'est_rows'): 각 아이템의 est_rows 만 뽑는다. array_sum: 더한다.
		 */
		$estimated_rows = (int) array_sum(array_column($plan_items, 'est_rows'));

		return [
			'items' => $plan_items,
			'time_from' => $time_from,
			'time_till' => $time_till,
			'hours' => $hours,
			'estimated_rows' => $estimated_rows,
			// 제목 줄까지 센 줄 수가 split_rows 를 넘으면 나눈다. (화면 안내용 어림)
			'split' => $estimated_rows + 1 > $this->config['split_rows'],
			// 이 시각보다 이른 값은 보관 기간이 지나 지워졌을 수 있다. 알 수 없으면 null. (화면 안내용)
			'history_available_from' => self::historyAvailableFrom($item_history)
		];
	}

	/**
	 * 원본 값(history)이 남아 있을 것으로 보는 가장 이른 시각. 화면의 보관 기간 안내에 쓴다.
	 *
	 * Zabbix 는 보관 기간이 지난 원본 값을 하우스키핑(정리 작업)으로 지운다. 그 설정을 따른다.
	 *   - 하우스키핑에서 이력 정리가 꺼져 있으면 지우지 않으므로 null.
	 *   - "아이템 보관 기간 덮어쓰기"가 켜져 있으면 그 기간을 쓴다. (관리 > 일반 > 하우스키핑)
	 *   - 꺼져 있으면 대상 아이템의 보관 기간 중 가장 짧은 것을 쓴다. (가장 먼저 지워지는 아이템 기준)
	 * 정리 작업이 늦게 돌거나 DB 에서 따로 지운 경우는 알 수 없으므로 "지워졌을 수 있다"고만 안내한다.
	 *
	 * @param array $item_history  아이템별 보관 기간 문자열. 예) ['31d', '90d']
	 *
	 * @return int|null  유닉스 초. 알 수 없거나 지울 일이 없으면 null.
	 */
	private static function historyAvailableFrom(array $item_history): ?int {
		if (CHousekeepingHelper::get(CHousekeepingHelper::HK_HISTORY_MODE) != 1) {
			return null;
		}

		if (CHousekeepingHelper::get(CHousekeepingHelper::HK_HISTORY_GLOBAL) == 1) {
			$keep = timeUnitToSeconds(CHousekeepingHelper::get(CHousekeepingHelper::HK_HISTORY));
		}
		else {
			$keep = null;

			foreach ($item_history as $history) {
				// timeUnitToSeconds('31d') -> 2678400. 매크로 등 바꿀 수 없는 값이면 null.
				$seconds = timeUnitToSeconds((string) $history);

				if ($seconds !== null && $seconds > 0 && ($keep === null || $seconds < $keep)) {
					$keep = $seconds;
				}
			}
		}

		return ($keep !== null && $keep > 0) ? time() - (int) $keep : null;
	}

	/**
	 * 계획대로 CSV 를 만들어 바로 출력한다. (views/export/layout.stream.php 가 부른다)
	 *
	 * 전체를 문자열로 모으지 않고, 아이템을 몇 개씩 묶어 조회한 뒤 읽는 대로 echo 한다.
	 * 그래서 줄이 수백만 개여도 PHP 메모리는 한 묶음 분량만 쓴다.
	 *
	 * 나눠 받기($plan['split'])이면 split_rows 줄마다 CSV 파일을 새로 시작해 zip 하나로 묶는다.
	 * 5버전 split -l 1000000 -d 와 같다. 제목 줄은 첫 파일(00)의 첫 줄에만 있고, 줄 수에 함께 센다.
	 *
	 * DB 조회가 중간에 실패하면 멈추고 웹 서버 오류 로그에 남긴다. 파일 안에는 아무것도 덧붙이지 않는다.
	 * zip 이면 끝의 목차를 쓰지 않아 깨진 zip 이 되므로 받은 사람이 불완전한 것을 알 수 있다.
	 *
	 * @param array  $plan       buildPlan() 의 결과 + 컨트롤러가 실제 줄 수로 정한 split.
	 * @param string $part_base  나눠 받기일 때 zip 안 파일명의 앞부분. 예) 'export_20261124101530'
	 *                           -> export_2026112410153000.csv, ...01.csv ...  (5버전 파일명과 같다)
	 */
	public function stream(array $plan, string $part_base = 'export_'): void {
		$this->written = 0;
		$this->part_rows = 0;
		$this->part_no = 0;
		$this->part_base = $part_base;
		$this->zip = !empty($plan['split']) ? new ZipStreamWriter() : null;

		$this->startPart();
		$this->out(self::csvLine(self::HEADER));
		$this->part_rows++;

		// 실패했으면 zip 목차를 쓰지 않고 끝낸다.
		if (!$this->streamValues($plan)) {
			flush();

			return;
		}

		if ($this->zip !== null) {
			$this->zip->finish();
		}

		flush();
	}

	/**
	 * 새 CSV 파일(나눠 받기면 zip 안의 다음 파일)을 시작한다. 한 파일로 받을 때는 할 일이 없다.
	 * BOM 은 쓰지 않는다. (5버전 COPY 결과에 BOM 이 없었다)
	 */
	private function startPart(): void {
		if ($this->zip !== null) {
			// sprintf('%02d', 0) -> '00'. 5버전 split -d 와 같은 두 자리 번호. 앞부분 뒤에 바로 붙는다.
			$this->zip->startFile($this->part_base.sprintf('%02d', $this->part_no).'.csv');
			$this->part_no++;
		}

		$this->part_rows = 0;
	}

	/**
	 * 내보낼 내용을 쓴다. 나눠 받기면 zip 안의 지금 파일에, 아니면 바로 브라우저로.
	 */
	private function out(string $text): void {
		if ($this->zip !== null) {
			$this->zip->write($text);
		}
		else {
			echo $text;
		}
	}

	/**
	 * 값 줄을 표별로, 묶음별로 읽어서 쓴다.
	 *
	 * @return bool  DB 조회가 실패했으면 false.
	 */
	private function streamValues(array $plan): bool {

		// 값 형식별로 표가 다르다. 소수는 history, 정수는 history_uint.
		$tables = [
			ITEM_VALUE_TYPE_FLOAT => 'history',
			ITEM_VALUE_TYPE_UINT64 => 'history_uint'
		];

		foreach ($tables as $value_type => $table) {
			// 이 표에 값이 있는 아이템만 고른다.
			$items = array_filter($plan['items'], static fn(array $item): bool => $item['value_type'] === $value_type);

			foreach ($this->makeQueries($items, $plan) as [$itemids, $from, $till]) {
				if (!$this->streamChunk($table, $itemids, $from, $till, $plan)) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * 계획대로 내보내면 값이 몇 줄인지 센다. (제목 줄은 빼고) 내보내기 전에 컨트롤러가 부른다.
	 *
	 * 한도(max_rows) 검사와 "한 파일 / 나눠 받기" 결정을 어림이 아니라 이 실제 줄 수로 한다.
	 * 조건은 stream() 과 같다. (같은 historySql() 을 COUNT 로 감싼다)
	 *
	 * @return int|null  줄 수. DB 조회가 실패하면 null.
	 */
	public function countRows(array $plan): ?int {
		$count = 0;

		$tables = [
			ITEM_VALUE_TYPE_FLOAT => 'history',
			ITEM_VALUE_TYPE_UINT64 => 'history_uint'
		];

		foreach ($tables as $value_type => $table) {
			$itemids = [];

			foreach ($plan['items'] as $itemid => $item) {
				if ($item['value_type'] === $value_type) {
					$itemids[] = $itemid;
				}
			}

			if (!$itemids) {
				continue;
			}

			$result = DBselect('SELECT COUNT(*) AS cnt'.$this->historyFrom($table, $itemids, $plan));
			$row = $result !== false ? DBfetch($result) : false;

			if ($row === false) {
				return null;
			}

			$count += (int) $row['cnt'];
		}

		return $count;
	}

	/**
	 * 화면에 미리 보여 줄 앞부분 몇 줄을 읽는다. 파일로 받기 전에 어떤 값이 나오는지 확인하는 용도다.
	 *
	 * CSV 와 같은 조건, 같은 순서(아이템별, 시간순)로 앞에서 $limit 줄만 읽는다.
	 * 줄 수가 적어서 여기서는 배열로 돌려준다.
	 *
	 * @param array $plan   buildPlan() 의 결과.
	 * @param int   $limit  읽을 최대 줄 수. 예) 100
	 *
	 * @return array|null  [['host' => 'web-01', 'item' => 'CPU utilization', 'clock' => '2026-11-22 09:00:00', 'value' => '12.5'], ...]
	 *                     값은 파일에 나가는 글자 그대로다. (clock 도 SQL 이 만든 글자) DB 조회가 실패하면 null.
	 */
	public function preview(array $plan, int $limit): ?array {
		$rows = [];

		$tables = [
			ITEM_VALUE_TYPE_FLOAT => 'history',
			ITEM_VALUE_TYPE_UINT64 => 'history_uint'
		];

		foreach ($tables as $value_type => $table) {
			$itemids = [];

			foreach ($plan['items'] as $itemid => $item) {
				if ($item['value_type'] === $value_type) {
					$itemids[] = $itemid;
				}
			}

			// 아이템을 나눠서 조회한다. 필요한 줄 수가 차면 더 읽지 않는다. (미리보기는 앞의 몇 줄만 읽으므로 묶음 크기만 지킨다)
			foreach (array_chunk($itemids, 100) as $chunk) {
				$remaining = $limit - count($rows);

				if ($remaining <= 0) {
					return $rows;
				}

				// DBselect(쿼리, N): 두 번째 인자를 주면 앞에서 N 줄만 읽는다. (DB 종류에 맞게 LIMIT 을 붙여 준다)
				$result = DBselect($this->historySql($table, $chunk, $plan), $remaining);

				if ($result === false) {
					return null;
				}

				while ($row = DBfetch($result)) {
					$item = $plan['items'][$row['itemid']];

					$rows[] = [
						'host' => $item['host'],
						'item' => $item['name'],
						'clock' => $row['clock_text'],
						'value' => $row['value']
					];
				}
			}
		}

		return $rows;
	}

	/**
	 * 이력 표에서 아이템 묶음의 값을 읽는 쿼리를 만든다. stream() 과 preview() 가 같이 쓴다.
	 *
	 * 쿼리에 들어가는 값은 dbConditionId() 가 만든 조건과 이 클래스에서 만든 정수뿐이라 따옴표 처리가 필요 없다.
	 *
	 *   clock_text : 5버전 SQL 과 같은 to_char(to_timestamp(clock), 'YYYY-MM-DD HH24:MI:SS'). DB 세션 시간대로 나온다.
	 *   ORDER BY itemid, clock : 아이템별로, 시간 순서대로. 이력 표의 색인(itemid, clock) 순서라서 빠르다.
	 *                            (5버전 SQL 에는 순서 지정이 없었다. 줄의 내용은 같고 순서만 정해 둔 것이다)
	 *
	 * @param string   $table    'history' 또는 'history_uint'
	 * @param array    $itemids  읽을 아이템 ID.
	 * @param int|null $from     이번 조회의 시작. null 이면 계획의 시작. (긴 기간을 나눠 읽을 때 쓴다)
	 * @param int|null $till     이번 조회의 끝. null 이면 계획의 끝.
	 */
	private function historySql(string $table, array $itemids, array $plan, ?int $from = null,
			?int $till = null): string {
		return 'SELECT itemid,'.
				"to_char(to_timestamp(clock),'YYYY-MM-DD HH24:MI:SS') AS clock_text,".
				'value'.
			$this->historyFrom($table, $itemids, $plan, $from, $till).
			' ORDER BY itemid,clock';
	}

	/**
	 * historySql() 과 countRows() 가 같이 쓰는 FROM ~ WHERE 부분. 두 곳의 조건이 어긋나지 않게 한 곳에서 만든다.
	 *
	 * @return string  예) ' FROM history WHERE itemid IN (..) AND clock>=.. AND clock<=..'
	 */
	private function historyFrom(string $table, array $itemids, array $plan, ?int $from = null,
			?int $till = null): string {
		return ' FROM '.$table.
			' WHERE '.dbConditionId('itemid', $itemids).
				' AND clock>='.(int) ($from ?? $plan['time_from']).
				' AND clock<='.(int) ($till ?? $plan['time_till']).
				$this->hoursCondition((int) $plan['hours']);
	}

	/**
	 * 아이템 한 묶음, 한 기간 구간의 값을 조회해서 CSV 줄로 출력한다.
	 *
	 * 조회에 실패하면 웹 서버 오류 로그에 남기고 false 를 돌려준다. (더 읽지 않는다)
	 * 이미 앞부분을 브라우저로 보낸 뒤라서 오류 화면으로 바꿀 수 없다. 파일 안에 안내 줄을 쓰지 않는 것은
	 * 5버전 파일 모양(값 줄만 있음)을 지키기 위해서다.
	 * 줄 수 한도는 내보내기 전에 countRows() 로 검사했으므로 여기서는 보지 않는다.
	 *
	 * @param string $table    'history' 또는 'history_uint'
	 * @param array  $itemids  이번에 읽을 아이템 ID.
	 * @param int    $from     이번 구간의 시작(유닉스 초)
	 * @param int    $till     이번 구간의 끝(유닉스 초)
	 *
	 * @return bool  계속 읽어도 되면 true.
	 */
	private function streamChunk(string $table, array $itemids, int $from, int $till, array $plan): bool {
		$result = DBselect($this->historySql($table, $itemids, $plan, $from, $till));

		// DBselect 는 실패하면 false 를 돌려준다. 앞부분만 있는 파일이 되므로 로그에 남긴다.
		if ($result === false) {
			error_log('dmove usageexport: DB query failed after '.$this->written.' rows. The file is incomplete.');

			return false;
		}

		while ($row = DBfetch($result)) {
			// 나눠 받기: 지금 파일이 split_rows 줄(첫 파일은 제목 줄 포함)을 채웠으면 다음 파일을 시작한다.
			if ($this->zip !== null && $this->part_rows >= $this->config['split_rows']) {
				$this->startPart();
			}

			$item = $plan['items'][$row['itemid']];

			$this->part_rows++;
			$this->out(self::csvLine([
				$item['host'],
				$item['name'],
				// SQL 이 만든 글자 그대로. (5버전 형식: YYYY-MM-DD HH24:MI:SS, DB 세션 시간대)
				$row['clock_text'],
				$row['value']
			]));

			// 5000줄마다 브라우저로 밀어 보낸다. 보내지 않고 쌓아 두면 메모리가 늘고 브라우저가 "응답 없음"으로 본다.
			if (++$this->written % 5000 === 0) {
				flush();
			}
		}

		flush();

		return true;
	}

	/**
	 * 업무시간 조건(SQL 조각). "전체"를 골랐으면 빈 문자열이다.
	 *
	 * 5버전 SQL 과 같은 식이다: EXTRACT(HOUR FROM to_timestamp(clock)) >= 9 AND ... < 18
	 * to_timestamp() 는 DB 세션 시간대로 시각을 만든다. 그래서 "몇 시인가"는 CLOCK 열과 같은 시간대로 본다.
	 * 요일은 가리지 않는다. (5버전과 같다)
	 *
	 * @return string  예) ' AND EXTRACT(HOUR FROM to_timestamp(clock))>=9 AND EXTRACT(HOUR FROM to_timestamp(clock))<18'
	 */
	private function hoursCondition(int $hours): string {
		if ($hours !== self::TIME_BUSINESS) {
			return '';
		}

		$hour = 'EXTRACT(HOUR FROM to_timestamp(clock))';
		[$hour_from, $hour_till] = $this->config['business_hours'];

		return ' AND '.$hour.'>='.(int) $hour_from.' AND '.$hour.'<'.(int) $hour_till;
	}

	/**
	 * 조회를 몇 번으로 나눌지 정한다. 한 번의 조회가 ROWS_PER_QUERY(20만) 줄 정도가 되게 한다.
	 *
	 * PostgreSQL 은 조회 결과를 한꺼번에 PHP 메모리로 가져온다. 그래서 한 번에 읽는 줄 수를 묶음 크기로 제한해야 한다.
	 *   - 어림 줄 수가 작은 아이템은 여러 개를 한 조회로 묶는다.
	 *   - 한 아이템만으로도 큰 경우(수집 간격이 짧고 기간이 긴 경우)는 기간을 여러 구간으로 나눠 읽는다.
	 *
	 * 예) 3개월, 1분 간격 아이템(약 13만 줄) 100개 -> 아이템 1개씩 100번
	 *     3개월, 1초 간격 아이템(약 800만 줄) 1개  -> 기간을 40 구간으로 나눠 40번
	 *     하루, 3분 간격 아이템(480줄) 1000개      -> 416개씩 3번
	 *
	 * @param array $items  [itemid => ['est_rows' => .., ...]]
	 *
	 * @return array  [[아이템 ID 목록, 시작, 끝], ...]
	 */
	private function makeQueries(array $items, array $plan): array {
		$queries = [];
		$batch = [];
		$batch_rows = 0;

		foreach ($items as $itemid => $item) {
			$rows = max(1, $item['est_rows']);

			// 한 아이템만으로 묶음 크기를 넘으면 기간을 나눈다. intdiv 올림: (a + b - 1) / b
			if ($rows > self::ROWS_PER_QUERY) {
				$parts = intdiv($rows + self::ROWS_PER_QUERY - 1, self::ROWS_PER_QUERY);
				$step = intdiv($plan['time_till'] - $plan['time_from'] + $parts, $parts);

				for ($from = $plan['time_from']; $from <= $plan['time_till']; $from += $step) {
					// 구간이 겹치지 않게 끝은 다음 구간 시작 - 1 초.
					$queries[] = [[$itemid], $from, min($from + $step - 1, $plan['time_till'])];
				}

				continue;
			}

			if ($batch && $batch_rows + $rows > self::ROWS_PER_QUERY) {
				$queries[] = [$batch, $plan['time_from'], $plan['time_till']];
				$batch = [];
				$batch_rows = 0;
			}

			$batch[] = $itemid;
			$batch_rows += $rows;
		}

		if ($batch) {
			$queries[] = [$batch, $plan['time_from'], $plan['time_till']];
		}

		return $queries;
	}

	/**
	 * 아이템의 수집 간격(초). 줄 수를 어림하는 데 쓴다.
	 *
	 *   '1m'              -> 60
	 *   '5m;wd1-5,9-18'   -> 300   (; 뒤의 "요일별 간격"은 무시하고 기본 간격만 본다)
	 *   '{$INTERVAL}'     -> 설정의 assumed_interval (매크로는 여기서 풀 수 없다)
	 *   '0' (트래퍼 등)    -> 설정의 assumed_interval (값이 들어오는 간격을 알 수 없다)
	 *
	 * timeUnitToSeconds(): '1m', '30s' 같은 글자를 초로 바꾸는 코어 함수. 바꿀 수 없으면 null.
	 */
	private function intervalOf(string $delay): int {
		$seconds = timeUnitToSeconds(explode(';', $delay)[0]);

		return ($seconds !== null && $seconds > 0) ? (int) $seconds : $this->config['assumed_interval'];
	}

	/**
	 * 값 여러 개를 CSV 한 줄로 만든다. PostgreSQL COPY ... (FORMAT CSV) 와 같은 규칙이다. (5버전 파일과 같은 모양)
	 *
	 *   - 쉼표, 큰따옴표, 줄바꿈(LF, CR) 중 하나가 들어 있는 값만 큰따옴표로 감싼다. 안의 큰따옴표는 두 번 쓴다("").
	 *   - 빈 글자('')는 "" 로 쓴다. NULL 은 아무것도 쓰지 않는다. (COPY 가 둘을 이렇게 구별한다)
	 *   - 줄 끝은 LF(\n) 하나다.
	 *   - 엑셀 수식 방지용 ' 는 붙이지 않는다. (5버전 파일에 없던 글자라서)
	 *
	 * 예) ['web-01', 'CPU "util", %', '2026-11-22 09:00:00', '12.5'] -> web-01,"CPU ""util"", %",2026-11-22 09:00:00,12.5\n
	 */
	private static function csvLine(array $values): string {
		$cells = [];

		foreach ($values as $value) {
			if ($value === null) {
				$cells[] = '';
			}
			elseif ($value === '' || strpbrk((string) $value, ",\"\n\r") !== false) {
				// strpbrk(글자, 찾을 글자들): 하나라도 있으면 그 위치부터의 글자, 없으면 false.
				$cells[] = '"'.str_replace('"', '""', (string) $value).'"';
			}
			else {
				$cells[] = (string) $value;
			}
		}

		return implode(',', $cells)."\n";
	}

	/**
	 * 항목 선택 값을 설정의 키 이름 목록으로 바꾼다.
	 *
	 * @return array  예) ITEMS_CPU_MEM -> ['cpu_item_keys', 'memory_item_keys']
	 */
	private static function keyNamesFor(int $items): array {
		switch ($items) {
			case self::ITEMS_CPU:
				return ['cpu_item_keys'];

			case self::ITEMS_MEM:
				return ['memory_item_keys'];

			default:
				return ['cpu_item_keys', 'memory_item_keys'];
		}
	}
}
