<?php declare(strict_types = 0);
/*
 * CPU/Memory 전체 사용률 추출의 데이터 처리.
 *
 * ── 이 파일이 하는 일 ──
 *   서버들의 CPU / 메모리 사용률 "수집값 원본"을 CSV 로 내보낸다. 수집값 한 건이 CSV 한 줄이다.
 *
 *     "HOST","ITEM","CLOCK","VALUE"
 *     "web-01","CPU utilization","2026-11-22 09:00:00","12.5"
 *     "web-01","CPU utilization","2026-11-22 09:03:00","13.1"
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
 *       1) 줄 수를 미리 어림해서 한도(max_rows)를 넘으면 내보내지 않는다. (5버전과 같은 방식)
 *       2) 아이템을 몇 개씩 묶어 나눠 조회하고, 읽는 대로 바로 내려보낸다. 전체를 메모리에 올리지 않는다.
 *   - 이력을 DB 에서 직접 읽는 것은 history.get API 가 결과를 한꺼번에 배열로 돌려줘서 이 분량을 감당할 수 없기 때문이다.
 *     대신 "어느 호스트/아이템을 볼 수 있는가"는 API 로 걸러서 사용자 권한을 지킨다.
 */

namespace Modules\DMove\Includes;

use API;
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

	/** 나눠 받기일 때 지금 파일에 쓴 값 줄 수와 파일 번호, zip 안 파일명의 앞부분. */
	private int $part_rows = 0;
	private int $part_no = 0;
	private string $part_base = '';

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
			'business_hours' => [(int) ($hours[0] ?? 9), (int) ($hours[1] ?? 18)]
		];
	}

	/** 한 번에 내보낼 수 있는 최대 기간(일). 예) 93 */
	public function getMaxPeriodDays(): int {
		return $this->config['max_period_days'];
	}

	/** 어림한 줄 수의 한도. 예) 10000000 */
	public function getMaxRows(): int {
		return $this->config['max_rows'];
	}

	/** 파일 하나에 넣을 값 줄 수. 넘으면 나눠서 zip 으로 묶는다. 예) 1000000 */
	public function getSplitRows(): int {
		return $this->config['split_rows'];
	}

	/** 업무시간 [시작 시, 끝 시]. 예) [9, 18] */
	public function getBusinessHours(): array {
		return $this->config['business_hours'];
	}

	/**
	 * 고른 항목에 필요한 아이템 키 설정(conf/serverusage.conf.php)이 채워져 있는가.
	 * 비어 있으면 화면에 "설정이 필요합니다" 안내를 띄운다.
	 *
	 * @param int $items  ITEMS_CPU_MEM / ITEMS_CPU / ITEMS_MEM
	 */
	public function isConfigured(int $items): bool {
		foreach (self::metricsFor($items) as $metric) {
			if (!(new ServerUsageService($metric))->isConfigured()) {
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
	 *   2) 그 호스트의 CPU / 메모리 아이템을 찾는다. (키는 conf/serverusage.conf.php)
	 *   3) 줄 수를 어림한다. 기간(초) / 수집 간격(초) x 아이템 수. (5버전과 같은 계산)
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
	 *     'estimated_rows' => 480,          // 어림한 줄 수
	 *     'too_large'      => false,        // 어림한 줄 수가 한도를 넘는가
	 *     'split'          => false         // 어림한 줄 수가 split_rows 를 넘어 zip 으로 나눠 받는가
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
		$host_options = HostFilter::toApiOptions($host_filter);

		$hosts = API::Host()->get($host_options + [
			'output' => ['hostid', 'name'],
			'preservekeys' => true
		]);

		// 2) 아이템. 고른 항목(CPU, 메모리)마다 찾아서 합친다. [itemid => hostid]
		$hostid_by_itemid = [];

		foreach (self::metricsFor($items) as $metric) {
			// 배열 + 배열: 왼쪽에 없는 키만 오른쪽에서 더한다. itemid 가 키라서 겹치지 않는다.
			$hostid_by_itemid += (new ServerUsageService($metric))->findItems(array_keys($hosts));
		}

		$plan_items = [];

		if ($hostid_by_itemid) {
			/*
			 * 아이템 이름과 값 형식.
			 *   value_type : 0 = 소수(history 표), 3 = 정수(history_uint 표). 그 밖의 형식(문자 등)은 사용률이 아니므로 뺀다.
			 */
			$db_items = API::Item()->get([
				// delay: 수집 간격. 줄 수를 어림하는 데 쓴다. (예: '1m', '30s', '5m;wd1-5,9-18')
				'output' => ['itemid', 'hostid', 'name', 'value_type', 'delay'],
				'itemids' => array_keys($hostid_by_itemid),
				'filter' => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]]
			]);

			foreach ($db_items as $item) {
				$plan_items[$item['itemid']] = [
					'host' => $hosts[$item['hostid']]['name'],
					'name' => $item['name'],
					'value_type' => (int) $item['value_type'],
					// 이 아이템의 어림 줄 수 = 기간(초) / 수집 간격(초). intdiv(a, b): 나눗셈의 몫(정수).
					'est_rows' => intdiv(max(0, $time_till - $time_from), $this->intervalOf($item['delay']))
				];
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
			'too_large' => $estimated_rows > $this->config['max_rows'],
			'split' => $estimated_rows > $this->config['split_rows']
		];
	}

	/**
	 * 계획대로 CSV 를 만들어 바로 출력한다. (views/export/layout.stream.php 가 부른다)
	 *
	 * 전체를 문자열로 모으지 않고, 아이템을 몇 개씩 묶어 조회한 뒤 읽는 대로 echo 한다.
	 * 그래서 줄이 수백만 개여도 PHP 메모리는 한 묶음 분량만 쓴다.
	 *
	 * 나눠 받기($plan['split'])이면 split_rows 줄마다 CSV 파일을 새로 시작해 zip 하나로 묶는다.
	 * 파일마다 BOM 과 제목 줄이 들어간다. (5버전: split -l 1000000 으로 나눈 export_..00.csv, 01.csv ...)
	 *
	 * @param array  $plan       buildPlan() 의 결과.
	 * @param string $part_base  나눠 받기일 때 zip 안 파일명의 앞부분. 예) 'usage_cpu_all_20261124_101530'
	 *                           -> usage_cpu_all_20261124_101530_00.csv, _01.csv ...
	 */
	public function stream(array $plan, string $part_base = 'usage'): void {
		// 지금까지 쓴 값 줄 수. 한도(max_rows)를 넘으면 멈춘다. (어림이 틀렸을 때를 대비한 마지막 안전장치)
		$this->written = 0;
		$this->part_rows = 0;
		$this->part_no = 0;
		$this->part_base = $part_base;
		$this->zip = !empty($plan['split']) ? new ZipStreamWriter() : null;

		$this->startPart();
		$this->streamValues($plan);

		if ($this->zip !== null) {
			$this->zip->finish();
			flush();
		}
	}

	/**
	 * 새 CSV 파일(나눠 받기면 zip 안의 다음 파일)을 시작하고 BOM 과 제목 줄을 쓴다.
	 */
	private function startPart(): void {
		if ($this->zip !== null) {
			// sprintf('%02d', 0) -> '00'. 5버전 split -d 와 같은 두 자리 번호.
			$this->zip->startFile($this->part_base.'_'.sprintf('%02d', $this->part_no).'.csv');
			$this->part_no++;
		}

		$this->part_rows = 0;
		// UTF-8 BOM. 엑셀이 한글을 깨뜨리지 않게 파일 맨 앞에 붙인다. (CsvWriter 와 같은 처리)
		$this->out("\xEF\xBB\xBF");
		$this->out(self::csvLine(['HOST', 'ITEM', 'CLOCK', 'VALUE']));
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
	 */
	private function streamValues(array $plan): void {

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
					return;
				}
			}
		}
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
	 * @return array  [['host' => 'web-01', 'item' => 'CPU utilization', 'clock' => '2026-11-22 09:00:00', 'value' => '12.5'], ...]
	 */
	public function preview(array $plan, int $limit): array {
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

				while ($result !== false && $row = DBfetch($result)) {
					$item = $plan['items'][$row['itemid']];

					$rows[] = [
						'host' => $item['host'],
						'item' => $item['name'],
						'clock' => date('Y-m-d H:i:s', (int) $row['clock']),
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
	 *   ORDER BY itemid, clock : 아이템별로, 시간 순서대로. 이력 표의 색인(itemid, clock) 순서라서 빠르다.
	 *
	 * @param string   $table    'history' 또는 'history_uint'
	 * @param array    $itemids  읽을 아이템 ID.
	 * @param int|null $from     이번 조회의 시작. null 이면 계획의 시작. (긴 기간을 나눠 읽을 때 쓴다)
	 * @param int|null $till     이번 조회의 끝. null 이면 계획의 끝.
	 */
	private function historySql(string $table, array $itemids, array $plan, ?int $from = null,
			?int $till = null): string {
		return 'SELECT itemid,clock,value'.
			' FROM '.$table.
			' WHERE '.dbConditionId('itemid', $itemids).
				' AND clock>='.(int) ($from ?? $plan['time_from']).
				' AND clock<='.(int) ($till ?? $plan['time_till']).
				$this->hoursCondition((int) $plan['hours']).
			' ORDER BY itemid,clock';
	}

	/**
	 * 아이템 한 묶음, 한 기간 구간의 값을 조회해서 CSV 줄로 출력한다.
	 *
	 * 조회에 실패하거나 줄 수 한도를 넘으면 파일 끝에 이유를 한 줄 쓰고 false 를 돌려준다. (더 읽지 않는다)
	 * 이미 앞부분을 브라우저로 보낸 뒤라서 오류 화면으로 바꿀 수 없기 때문이다.
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

		// DBselect 는 실패하면 false 를 돌려준다. 그대로 두면 앞부분만 있는 파일이 "성공"처럼 보이므로 이유를 남긴다.
		if ($result === false) {
			$this->out(self::csvLine([Lang::t('* A database error occurred. The file is incomplete.')]));
			flush();

			return false;
		}

		while ($row = DBfetch($result)) {
			if ($this->written >= $this->config['max_rows']) {
				$this->out(self::csvLine([Lang::t('* Stopped at the row limit (%1$s rows). The file is incomplete.',
					number_format($this->config['max_rows'])
				)]));
				flush();

				return false;
			}

			// 나눠 받기: 지금 파일이 split_rows 줄을 채웠으면 다음 파일을 시작한다.
			if ($this->zip !== null && $this->part_rows >= $this->config['split_rows']) {
				$this->startPart();
			}

			$item = $plan['items'][$row['itemid']];

			$this->part_rows++;
			$this->out(self::csvLine([
				$item['host'],
				$item['name'],
				// date() 는 로그인한 사용자의 시간대로 표시한다. (5버전 형식: YYYY-MM-DD HH24:MI:SS)
				date('Y-m-d H:i:s', (int) $row['clock']),
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
	 * clock 은 UTC 기준 유닉스 초다. 사용자 시간대의 "몇 시인가"를 알려면 시간대 차이를 더한 뒤 하루(86400초)로 나눈 나머지를 본다.
	 *   date('Z') : 사용자 시간대와 UTC 의 차이(초). 한국은 32400 (9시간).
	 *   예) 한국 09:00 = UTC 00:00 -> (clock + 32400) % 86400 = 32400 (= 9 x 3600)
	 *
	 * @return string  예) ' AND ((clock+32400)%86400)>=32400 AND ((clock+32400)%86400)<64800'
	 */
	private function hoursCondition(int $hours): string {
		if ($hours !== self::TIME_BUSINESS) {
			return '';
		}

		$second_of_day = '((clock+'.(int) date('Z').')%'.SEC_PER_DAY.')';
		[$hour_from, $hour_till] = $this->config['business_hours'];

		return ' AND '.$second_of_day.'>='.($hour_from * SEC_PER_HOUR).
			' AND '.$second_of_day.'<'.($hour_till * SEC_PER_HOUR);
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
	 * 값 여러 개를 CSV 한 줄로 만든다.
	 *
	 *   - 모든 값을 큰따옴표로 감싼다. 값 안의 큰따옴표는 두 번 써서("") 표시한다. (CSV 규칙)
	 *   - 값이 = + - @ 탭 CR 로 시작하면 앞에 ' 를 붙인다. 엑셀이 수식으로 실행하지 못하게 하는 처리다. (CsvWriter 와 같다)
	 *     숫자(예: -5)는 수식이 아니므로 그대로 둔다.
	 *
	 * 예) ['web-01', 'CPU "util"', '2026-11-22 09:00:00', '12.5'] -> "web-01","CPU ""util""","2026-11-22 09:00:00","12.5"\n
	 */
	private static function csvLine(array $values): string {
		$cells = [];

		foreach ($values as $value) {
			$value = (string) $value;

			if ($value !== '' && !is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
				$value = "'".$value;
			}

			$cells[] = '"'.str_replace('"', '""', $value).'"';
		}

		return implode(',', $cells)."\n";
	}

	/**
	 * 항목 선택 값을 ServerUsageService 의 집계 대상 목록으로 바꾼다.
	 *
	 * @return array  예) ITEMS_CPU_MEM -> [METRIC_CPU, METRIC_MEMORY]
	 */
	private static function metricsFor(int $items): array {
		switch ($items) {
			case self::ITEMS_CPU:
				return [ServerUsageService::METRIC_CPU];

			case self::ITEMS_MEM:
				return [ServerUsageService::METRIC_MEMORY];

			default:
				return [ServerUsageService::METRIC_CPU, ServerUsageService::METRIC_MEMORY];
		}
	}
}
