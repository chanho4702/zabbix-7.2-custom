<?php declare(strict_types = 0);
/*
 * 서버별 CPU(또는 메모리) 사용률의 기간 평균과 Peak 를 계산한다.
 *
 * ── 이 파일이 하는 일 ──
 *   "이 호스트들의, 이 기간의, 이 시간대의 사용률 평균과 최대(Peak)는 얼마인가"에 답하는 클래스다.
 *   화면을 그리지 않는다. 숫자만 계산해서 컨트롤러에 돌려준다.
 *
 * ── 누가 쓰는가 ──
 *   actions/reports/serverusage/ServerUsageController.php   저사용 / 고사용 서버 사용현황 화면 (CPU)
 *   actions/reports/dailycheck/DailyCheckTop.php            일일점검의 CPU / Memory 사용률 Top 10 팝업
 *
 * ── 데이터 흐름 ──
 *   new ServerUsageService(METRIC_CPU 또는 METRIC_MEMORY)
 *     └─ conf/serverusage.conf.php 를 읽는다 (아이템 키, 판정 기준, 업무시간 등)
 *   getUsage(호스트 ID 목록, 시작 시각, 끝 시각, 시간대)
 *     ├─ findCpuItems() : item.get API 로 대상 호스트의 사용률 아이템을 찾는다
 *     ├─ DBselect()     : 그 아이템들의 시간별 요약(trends, trends_uint)을 DB 에서 집계한다
 *     └─ [hostid => ['avg' => 평균, 'peak' => 최대]] 를 돌려준다
 *
 * 5버전 대응: 서버 CPU 집계 클래스(...CPU.php)와 키 클래스(...Key.php)의 serverCpuUsage()
 *
 * 계산 방법
 *   Zabbix 는 숫자 아이템의 값을 1시간 단위로 요약해 저장한다. (시간별 최소 / 평균 / 최대)
 *   소수(float) 아이템은 trends 테이블에, 정수(unsigned) 아이템은 trends_uint 테이블에 쌓인다.
 *   이 클래스는 그 두 표를 합쳐서(UNION ALL) DB 에서 바로 집계한다.
 *
 *     1) 대상 호스트에서 사용률 아이템을 찾는다. (키 목록: conf/serverusage.conf.php)
 *     2) 아이템별, 날짜별로  하루 평균 = 그날 시간별 평균의 평균,  하루 최대 = 그날 시간별 최대의 최대
 *     3) 아이템별로          기간 평균 = 하루 평균들의 평균,       Peak = 하루 최대들의 최대
 *
 *   "업무시간" 을 고르면 2) 에서 업무일(기본: 월~금)의 업무시간대 시간별 값만 쓴다. (5버전과 같다)
 *   "업무외시간" 이면 모든 요일의 나머지 시간대만 쓴다. (5버전과 같다)
 *
 * 5버전과 달라진 점
 *   - 5버전은 하루치 요약을 따로 만든 테이블(extends_trends_day)에 쌓아 두고 읽었다.
 *     여기서는 추가 테이블 없이 trends / trends_uint 를 직접 집계한다. 결과는 같다.
 *     (Zabbix DB 에 테이블을 추가하면 업그레이드와 백업/복구 때 따로 챙겨야 해서 만들지 않았다)
 *   - 5버전은 "업무시간" 의 Peak 에 업무시간이 아닌 "그날 전체의 최대"를 썼다. (CPU, 메모리 집계 모두)
 *     5버전 화면과 숫자를 맞추려면 conf/serverusage.conf.php 의 business_peak_whole_day 를 true 로 둔다.
 *     기본값은 5버전과 같게 true 다. false 로 바꾸면 업무시간 안의 최대가 된다.
 *
 * 주의
 *   - 기간이 길고 호스트가 많으면 읽는 양이 많다. (1시간 1줄 x 아이템 수 x 날짜 수)
 *     1년 x 2000대면 약 1700만 줄이다. DB 가 집계하므로 PHP 메모리는 쓰지 않지만 수 초 ~ 수십 초 걸릴 수 있다.
 *   - 같은 조건의 결과는 잠시(cache_seconds, 기본 300초) 저장해 두고 다시 쓴다. 페이지 이동, 정렬, 내보내기 때
 *     집계를 다시 돌리지 않기 위해서다. (getUsage() 의 "결과 저장" 설명)
 *   - trends / trends_uint 를 직접 읽는 것은 API 로 같은 집계를 할 수 없기 때문이다. (trend.get 은 줄을 그대로
 *     돌려줘서 위 분량을 PHP 로 받아야 한다) 대신 "어느 호스트를 볼 수 있는가"는 API 로 걸러서 권한을 지킨다.
 *
 * ── 관련 파일 ──
 *   conf/serverusage.conf.php                               설정 (아이템 키, 판정 기준, 업무시간, 최대 기간)
 *   actions/reports/serverusage/ServerUsageController.php   저사용 / 고사용 화면 컨트롤러
 *   actions/reports/dailycheck/DailyCheckTop.php            Top 10 팝업 컨트롤러
 */

namespace Modules\DMove\Includes;

use API;
use CHousekeepingHelper;

class ServerUsageService {

	/**
	 * 시간대 구분. 화면의 라디오 버튼 값이기도 하다.
	 *   HOURS_ALL      : 전체 시간
	 *   HOURS_BUSINESS : 업무시간 (업무일의 business_hours 시간대)
	 *   HOURS_OFF      : 업무외시간 (모든 요일의 나머지 시간대)
	 */
	public const HOURS_ALL = 'all';
	public const HOURS_BUSINESS = 'work';
	public const HOURS_OFF = 'night';

	/** 허용하는 시간대 값 전체. 컨트롤러가 입력 검사에 쓴다. */
	public const HOURS_MODES = [self::HOURS_BUSINESS, self::HOURS_OFF, self::HOURS_ALL];

	/** 하루 / 한 시간의 초. SQL 문자열에 숫자로 이어 붙인다. */
	private const DAY = 86400;
	private const HOUR = 3600;

	/**
	 * 어떤 값을 집계할지. 값은 conf/serverusage.conf.php 안의 키 이름이다.
	 *   METRIC_CPU    : CPU 사용률 아이템 (cpu_item_keys)      - 저사용/고사용 화면, CPU Top 10
	 *   METRIC_MEMORY : 메모리 사용률 아이템 (memory_item_keys) - Memory Top 10
	 */
	public const METRIC_CPU = 'cpu_item_keys';
	public const METRIC_MEMORY = 'memory_item_keys';

	/** @var array  conf/serverusage.conf.php 의 내용 */
	private array $config;

	/** @var array  집계할 아이템의 키 설정. ['exact' => [...], 'prefix' => [...]] */
	private array $item_keys;

	/** 어떤 값을 집계하는지 (METRIC_CPU / METRIC_MEMORY). 결과 저장 이름에 넣는다. */
	private string $metric;

	/** findCpuItems() 가 찾은 아이템의 요약 보관 기간 설정. [itemid => '365d'] */
	private array $item_trends = [];

	/**
	 * 마지막 getUsage() 에서 "이 시각 이후는 요약 값이 남아 있다"고 볼 수 있는 시각(유닉스 초).
	 * 보관 기간 때문에 지워졌을 수 있는 구간이 없으면 null. getTrendsAvailableFrom() 으로 읽는다.
	 */
	private ?int $trends_available_from = null;

	/**
	 * 설정 파일을 읽고, 어느 아이템(CPU / 메모리)을 집계할지 정한다.
	 *
	 * @param string $metric  METRIC_CPU(기본) 또는 METRIC_MEMORY.
	 *
	 * 사용 예:
	 *   $cpu = new ServerUsageService();                                    // CPU
	 *   $memory = new ServerUsageService(ServerUsageService::METRIC_MEMORY); // 메모리
	 */
	public function __construct(string $metric = self::METRIC_CPU) {
		// 설정 파일은 "return [ ... ];" 형태다. require 가 그 배열을 돌려준다.
		$this->config = require dirname(__DIR__).'/conf/serverusage.conf.php';
		$this->metric = $metric;

		// 설정에 그 키가 없으면(예: 예전 설정 파일) 빈 값으로 본다. ?? : 왼쪽이 없으면 오른쪽.
		$keys = $this->config[$metric] ?? [];
		// (array) 값 : 글자 하나만 적혀 있어도 배열로 바꿔서 뒤에서 똑같이 다룬다. 'a' -> ['a']
		$this->item_keys = [
			'exact' => (array) ($keys['exact'] ?? []),
			'prefix' => (array) ($keys['prefix'] ?? [])
		];
	}

	/**
	 * 아이템 키 설정이 채워져 있는가. 비어 있으면 화면에 "설정이 필요합니다" 안내를 띄운다.
	 *
	 * exact 와 prefix 중 하나라도 값이 있으면 true 다.
	 * 둘 다 비어 있으면 찾을 아이템이 없어서 getUsage() 의 결과도 항상 빈 배열이다.
	 *
	 * @return bool  설정에 키가 하나라도 있으면 true.
	 */
	public function isConfigured(): bool {
		$keys = $this->item_keys;

		// !empty(): 빈 배열이면 false. 값이 하나라도 있으면 true.
		return !empty($keys['exact']) || !empty($keys['prefix']);
	}

	/**
	 * 저사용 / 고사용 판정 기준(%). conf/serverusage.conf.php 의 thresholds 를 그대로 돌려준다.
	 * 컨트롤러가 "평균이 기준 이하인가/이상인가"를 비교할 때와 화면 안내문에 쓴다.
	 *
	 * @return array  ['low_avg' => 30, 'low_peak' => 70, 'high_avg' => 50, 'high_peak' => 70]
	 */
	public function getThresholds(): array {
		return $this->config['thresholds'];
	}

	/**
	 * 업무시간. conf/serverusage.conf.php 의 business_hours 를 그대로 돌려준다.
	 * 시작 시는 포함하고 끝 시는 포함하지 않는다. [9, 18] 이면 09시대 ~ 17시대의 값이 업무시간이다.
	 *
	 * @return array  [시작 시, 끝 시]. 예) [9, 18]
	 */
	public function getBusinessHours(): array {
		return $this->config['business_hours'];
	}

	/**
	 * 한 번에 조회할 수 있는 최대 기간(일). conf/serverusage.conf.php 의 max_period_days.
	 * 기간이 길수록 DB 가 읽는 양이 많아지므로, 컨트롤러가 이 값보다 긴 기간을 받지 않게 하는 데 쓴다.
	 *
	 * @return int  일 수. 예) 366
	 */
	public function getMaxPeriodDays(): int {
		return (int) $this->config['max_period_days'];
	}

	/**
	 * 호스트별 사용률 평균과 Peak. (생성자에서 고른 CPU 또는 메모리 아이템 기준)
	 *
	 * 하는 일: 아이템 찾기 -> 시간대/요일 조건을 SQL 로 만들기 -> trends / trends_uint 집계 -> 호스트별로 정리.
	 * 이 메소드는 권한을 검사하지 않는다. 넘겨받은 호스트 ID 를 그대로 믿으므로, 부르는 쪽이 host.get 으로
	 * 사용자가 볼 수 있는 호스트만 골라서 넘겨야 한다.
	 *
	 * 사용 예:
	 *   $usage = $service->getUsage(['10084', '10085'], strtotime('2026-11-01 00:00:00'),
	 *       strtotime('2026-11-22 23:59:59'), ServerUsageService::HOURS_BUSINESS);
	 *   // $usage['10084'] = ['avg' => 12.34, 'peak' => 56.78]
	 *
	 * @param array  $hostids    대상 호스트 ID. (API 로 조회한, 사용자가 볼 수 있는 호스트여야 한다)
	 * @param int    $time_from  시작 시각(유닉스 초). 그날 00:00:00
	 * @param int    $time_till  끝 시각(유닉스 초). 그날 23:59:59
	 * @param string $hours      HOURS_ALL / HOURS_BUSINESS / HOURS_OFF
	 *
	 * @return array  [hostid => ['avg' => 12.34, 'peak' => 56.78], ...]
	 *                기간 안에 값이 없는 호스트와 사용률 아이템이 없는 호스트는 결과에 없다.
	 */
	public function getUsage(array $hostids, int $time_from, int $time_till, string $hours): array {
		$this->trends_available_from = null;

		if (!$hostids) {
			return [];
		}

		/*
		 * 결과 저장. 같은 조건이면 cache_seconds(기본 300초) 동안 저장해 둔 결과를 쓴다.
		 *   저사용/고사용 화면은 페이지를 넘기거나 정렬을 바꿀 때마다 이 메소드를 다시 부른다. 집계는 1년 x 2000대면
		 *   수천만 줄을 읽으므로, 같은 조건이면 다시 계산하지 않는다. 저사용과 고사용은 같은 결과를 함께 쓴다.
		 *   조건(호스트 ID 목록, 기간, 시간대, 업무시간 설정, 아이템 키)이 하나라도 다르면 새로 계산한다.
		 *   호스트 ID 목록은 부르는 쪽이 권한으로 걸러 넘기므로, 볼 수 있는 호스트가 다른 사용자끼리는 섞이지 않는다.
		 */
		$cache = new UsageCache((int) ($this->config['cache_seconds'] ?? 300));
		$cache_key = $this->cacheKey($hostids, $time_from, $time_till, $hours);
		$cached = $cache->get($cache_key);

		if ($cached !== null) {
			$this->trends_available_from = $cached['trends_available_from'];

			return $cached['usage'];
		}

		$usage = $this->calculateUsage($hostids, $time_from, $time_till, $hours);

		$cache->set($cache_key, ['usage' => $usage, 'trends_available_from' => $this->trends_available_from]);

		return $usage;
	}

	/**
	 * 요약 값(trends)이 남아 있다고 볼 수 있는 가장 이른 시각(유닉스 초). 마지막 getUsage() 기준.
	 *
	 * Zabbix 하우스키퍼는 보관 기간이 지난 요약 값을 지운다. 조회 시작일이 이 시각보다 이르면 그 앞 구간은
	 * 값이 없어 평균에서 빠진다. 컨트롤러가 이 값으로 화면에 안내한다.
	 *
	 *   관리 > 하우스키핑 의 "트렌드" 에서
	 *     - 하우스키핑을 끄면 지우지 않으므로 null
	 *     - "아이템 트렌드 기간 덮어쓰기" 가 켜져 있으면 그 기간
	 *     - 아니면 대상 아이템의 트렌드 보관 기간 중 가장 짧은 것 (매크로라 계산할 수 없는 값은 뺀다)
	 *
	 * @return int|null  예) 지금이 2026-11-24 이고 보관 기간이 365d 면 2025-11-24 의 유닉스 초. 알 수 없으면 null.
	 */
	public function getTrendsAvailableFrom(): ?int {
		return $this->trends_available_from;
	}

	/**
	 * 결과 저장 이름. 조건이 같으면 같은 이름이 나온다.
	 * sort(): 호스트 ID 순서가 달라도 같은 이름이 나오게 정렬한다. md5: 긴 목록을 짧은 글자로 줄인다.
	 */
	private function cacheKey(array $hostids, int $time_from, int $time_till, string $hours): string {
		$hostids = array_map('strval', $hostids);
		sort($hostids);

		return md5(json_encode([
			$this->metric,
			$this->item_keys,
			$this->config['business_hours'] ?? null,
			$this->config['business_days'] ?? null,
			$this->config['business_peak_whole_day'] ?? null,
			// 사용자 시간대가 다르면 "몇 시인가" 계산이 달라진다.
			(int) date('Z', $time_from),
			$hostids,
			$time_from,
			$time_till,
			$hours
		]));
	}

	/**
	 * 실제 집계. getUsage() 가 저장해 둔 결과가 없을 때 부른다. 반환 형식은 getUsage() 와 같다.
	 */
	private function calculateUsage(array $hostids, int $time_from, int $time_till, string $hours): array {
		// 1) 아이템 찾기. [itemid => hostid]
		$hostid_by_itemid = $this->findCpuItems($hostids);

		if (!$hostid_by_itemid) {
			return [];
		}

		// 요약 값이 남아 있는 시작 시각 (보관 기간 안내용)
		$this->trends_available_from = $this->trendsAvailableFrom();

		/*
		 * 2) 날짜와 시간대를 SQL 에서 계산하기 위한 준비.
		 *
		 *    trends / trends_uint 의 clock 은 UTC 기준 유닉스 시간(초)이다. "그날의 몇 시인가"를 알려면 시간대 차이를 더해야 한다.
		 *    date('Z', 시각) = 로그인한 사용자 시간대가 UTC 보다 몇 초 빠른가. (한국은 32400 = 9시간)
		 *
		 *      (clock + 차이) / 86400 의 몫     = 날짜 번호
		 *      (clock + 차이) 를 86400 으로 나눈 나머지 = 그날 0시부터 지난 초
		 *
		 *    이 계산은 PostgreSQL, MySQL 어느 쪽에서도 같은 문법으로 동작한다.
		 *    (서머타임이 있는 시간대에서는 전환일 전후로 1시간 어긋난다. 한국은 해당 없음)
		 */
		$offset = (int) date('Z', $time_from);
		$local = '(clock+'.$offset.')';
		$day_number = 'FLOOR('.$local.'/'.self::DAY.')';

		// [$a, $b] = 배열 : 배열의 첫째, 둘째 값을 변수 두 개에 한 번에 담는다. 예) [9, 18] -> 9, 18
		[$business_from, $business_till] = $this->getBusinessHours();
		$second_from = (int) $business_from * self::HOUR;
		$second_till = (int) $business_till * self::HOUR;
		$second_of_day = 'MOD('.$local.','.self::DAY.')';

		/*
		 * 요일. 1970-01-01 이 목요일이라서, 시각에 3일(259200초)을 더해 1주(604800초)로 나눈 나머지를
		 * 하루(86400초)로 나누면 월요일 0 ~ 일요일 6 이 된다.
		 * (MOD 에는 정수만 넣어야 PostgreSQL 에서 동작한다. 그래서 날짜 번호가 아니라 초 단위로 계산한다)
		 * 설정의 업무일은 ISO 요일(월 1 ~ 일 7)이라 1 을 빼서 맞춘다.
		 */
		$weekday = 'FLOOR(MOD('.$local.'+'.(3 * self::DAY).','.(7 * self::DAY).')/'.self::DAY.')';
		// array_map(함수, 배열): 각 값에 함수를 적용한 새 배열. 예) [1, 2, 3, 4, 5] -> [0, 1, 2, 3, 4]
		$business_days = array_map(static fn($day): int => (int) $day - 1, $this->config['business_days']);

		/*
		 * $in_hours : 이 시간별 값이 고른 시간대에 속하는가 (SQL 조건식)
		 * $day_condition : 이 날짜를 집계에 넣는가 (SQL 조건식. 업무시간일 때만 요일을 거른다)
		 *
		 * '1=1' 은 "항상 참"인 조건이다. 전체 시간일 때 조건 자리를 비우지 않으려고 넣는다.
		 * 업무일 설정이 빈 배열([])이면 요일 조건을 붙이지 않는다. (= 모든 요일)
		 */
		$in_hours = '1=1';
		$day_condition = '';

		if ($hours === self::HOURS_BUSINESS) {
			$in_hours = $second_of_day.'>='.$second_from.' AND '.$second_of_day.'<'.$second_till;
			$day_condition = $business_days ? ' AND '.dbConditionInt($weekday, $business_days) : '';
		}
		elseif ($hours === self::HOURS_OFF) {
			$in_hours = '('.$second_of_day.'<'.$second_from.' OR '.$second_of_day.'>='.$second_till.')';
		}

		/*
		 * Peak 를 무엇의 최대로 할지.
		 *   업무시간 + business_peak_whole_day = true : 그날 전체 시간의 최대 (5버전과 같다)
		 *   그 밖                                    : 고른 시간대 안의 최대
		 */
		$peak_whole_day = $hours === self::HOURS_BUSINESS && !empty($this->config['business_peak_whole_day']);

		/*
		 * 3) 집계. 안쪽 SELECT 가 아이템별 + 날짜별 하루 평균/최대를, 바깥 SELECT 가 그것의 기간 평균/최대를 구한다.
		 *
		 *    CASE WHEN 조건 THEN 값 END : 조건에 맞을 때만 값, 아니면 NULL. AVG/MAX 는 NULL 을 빼고 계산한다.
		 *    그래서 시간대 밖의 값은 평균에 들어가지 않고, Peak 만 필요하면 전체 시간의 최대를 같이 구할 수 있다.
		 *    고른 시간대의 값이 하나도 없는 날은 하루 평균이 NULL 이 되어 기간 평균에서 빠진다.
		 *
		 *    dbConditionId / dbConditionInt : "컬럼 IN (1,2,3)" 조건을 안전하게 만들어 주는 코어 함수.
		 *    쿼리에 들어가는 나머지 값은 모두 이 메소드 안에서 만든 정수라서 따옴표 처리가 필요 없다.
		 */
		$day_peak = $peak_whole_day ? 'MAX(value_max)' : 'MAX(CASE WHEN '.$in_hours.' THEN value_max END)';

		/*
		 *    숫자 아이템은 값 형식에 따라 요약이 다른 테이블에 쌓인다.
		 *      소수(float)        → trends
		 *      정수(unsigned int) → trends_uint
		 *    아이템이 어느 형식으로 만들어져 있어도 읽히도록 두 테이블을 합쳐(UNION ALL) 읽는다.
		 *    아이템 하나는 둘 중 한 테이블에만 있으므로 값이 두 번 세어지지 않는다.
		 */
		$item_condition = dbConditionId('itemid', array_keys($hostid_by_itemid)).
			' AND clock>='.$time_from.
			' AND clock<='.$time_till;

		$result = DBselect(
			'SELECT d.itemid,AVG(d.day_avg) AS usage_avg,MAX(d.day_max) AS usage_peak'.
			' FROM ('.
				'SELECT itemid,'.$day_number.' AS day_number,'.
					'AVG(CASE WHEN '.$in_hours.' THEN value_avg END) AS day_avg,'.
					$day_peak.' AS day_max'.
				' FROM ('.
					'SELECT itemid,clock,value_avg,value_max FROM trends WHERE '.$item_condition.
					' UNION ALL'.
					' SELECT itemid,clock,value_avg,value_max FROM trends_uint WHERE '.$item_condition.
				') t'.
				' WHERE 1=1'.
					$day_condition.
				' GROUP BY itemid,'.$day_number.
			') d'.
			' WHERE d.day_avg IS NOT NULL'.
			' GROUP BY d.itemid'
		);

		$usage = [];

		// DBselect 는 실패하면 false 를 돌려준다. 그대로 DBfetch 에 넘기면 경고가 나므로 먼저 확인한다.
		if ($result === false) {
			return $usage;
		}

		while ($row = DBfetch($result)) {
			$hostid = $hostid_by_itemid[$row['itemid']];

			// 한 호스트에 사용률 아이템이 둘 이상이면 평균이 큰 쪽을 그 호스트의 값으로 쓴다.
			if (!array_key_exists($hostid, $usage) || $row['usage_avg'] > $usage[$hostid]['avg']) {
				$usage[$hostid] = [
					'avg' => (float) $row['usage_avg'],
					'peak' => (float) $row['usage_peak']
				];
			}
		}

		return $usage;
	}

	/**
	 * 요약 값이 남아 있다고 볼 수 있는 가장 이른 시각. 설명은 getTrendsAvailableFrom().
	 *
	 *   CHousekeepingHelper::get(이름) : 관리 > 하우스키핑 의 설정 값.
	 *     HK_TRENDS_MODE   '1' = 트렌드 하우스키핑 켜짐
	 *     HK_TRENDS_GLOBAL '1' = 아이템 트렌드 기간 덮어쓰기 켜짐,  HK_TRENDS = 그 기간 (예: '365d')
	 *   timeUnitToSeconds('365d') : 초로 바꾼다. 매크로({$TRENDS} 등)처럼 바꿀 수 없으면 null.
	 *   트렌드 기간 0 은 "요약을 저장하지 않음" 이라 그 아이템은 처음부터 값이 없다. 기간 계산에서는 뺀다.
	 */
	private function trendsAvailableFrom(): ?int {
		if (CHousekeepingHelper::get(CHousekeepingHelper::HK_TRENDS_MODE) != 1) {
			return null;
		}

		if (CHousekeepingHelper::get(CHousekeepingHelper::HK_TRENDS_GLOBAL) == 1) {
			$keep = timeUnitToSeconds(CHousekeepingHelper::get(CHousekeepingHelper::HK_TRENDS));
		}
		else {
			$keep = null;

			foreach ($this->item_trends as $trends) {
				$seconds = timeUnitToSeconds((string) $trends);

				if ($seconds !== null && $seconds > 0 && ($keep === null || $seconds < $keep)) {
					$keep = $seconds;
				}
			}
		}

		return ($keep !== null && $keep > 0) ? time() - (int) $keep : null;
	}

	/**
	 * 대상 호스트의 사용률 아이템을 찾아 [itemid => hostid] 로 돌려준다. (다른 클래스에서 쓰는 공개 메소드)
	 *
	 * CPU/Memory 전체 사용률 추출(includes/UsageExportService.php)이 "어느 아이템의 값을 내보낼지" 정할 때 쓴다.
	 * 생성자에서 고른 종류(METRIC_CPU / METRIC_MEMORY)의 아이템을 찾는다.
	 *
	 * @param array $hostids  대상 호스트 ID. 예) ['10084', '10085']
	 *
	 * @return array  [itemid => hostid]. 예) ['44001' => '10084']  못 찾으면 빈 배열.
	 */
	public function findItems(array $hostids): array {
		return $hostids ? $this->findCpuItems($hostids) : [];
	}

	/**
	 * 대상 호스트의 사용률 아이템을 찾는다.
	 *
	 * 이름은 findCpuItems 지만, 생성자에서 METRIC_MEMORY 를 골랐으면 메모리 아이템을 찾는다.
	 * 설정의 exact(키가 정확히 같음)와 prefix(키가 이 글자로 시작) 두 가지 방법으로 찾아서 합친다.
	 * 같은 아이템이 두 방법에 모두 걸려도 itemid 가 배열의 키라서 한 번만 들어간다.
	 *
	 * @param array $hostids  대상 호스트 ID. 예) ['10084', '10085']
	 *
	 * @return array  [itemid => hostid]. 예) ['44001' => '10084', '44050' => '10085']
	 *                아이템을 하나도 못 찾으면 빈 배열.
	 */
	private function findCpuItems(array $hostids): array {
		$keys = $this->item_keys;
		$found = [];

		// 키가 정확히 같은 아이템. filter 는 "값이 같은 것", 배열을 주면 "그중 하나와 같은 것".
		if (!empty($keys['exact'])) {
			$items = API::Item()->get([
				// trends: 요약 보관 기간 (예: '365d'). 보관 기간 안내에 쓴다.
				'output' => ['itemid', 'hostid', 'trends'],
				'hostids' => $hostids,
				'filter' => ['key_' => array_values($keys['exact'])]
			]);

			foreach ($items as $item) {
				$found[$item['itemid']] = $item['hostid'];
				$this->item_trends[$item['itemid']] = $item['trends'];
			}
		}

		/*
		 * 키가 주어진 글자로 시작하는 아이템.
		 *   search      : 부분 일치 검색
		 *   startSearch : true = "포함"이 아니라 "~로 시작"
		 *   searchByAny : true = 여러 검색어 중 하나만 맞아도 된다
		 */
		if (!empty($keys['prefix'])) {
			$items = API::Item()->get([
				'output' => ['itemid', 'hostid', 'trends'],
				'hostids' => $hostids,
				'search' => ['key_' => array_values($keys['prefix'])],
				'startSearch' => true,
				'searchByAny' => true
			]);

			foreach ($items as $item) {
				$found[$item['itemid']] = $item['hostid'];
				$this->item_trends[$item['itemid']] = $item['trends'];
			}
		}

		return $found;
	}
}
