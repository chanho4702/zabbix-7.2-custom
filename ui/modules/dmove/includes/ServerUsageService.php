<?php declare(strict_types = 0);
/*
 * 서버별 CPU 사용률의 기간 평균과 Peak 를 계산한다. (저사용 / 고사용 서버 사용현황 화면 공용)
 *
 * 5버전 대응: 서버 CPU 집계 클래스(...CPU.php)와 키 클래스(...Key.php)의 serverCpuUsage()
 *
 * 계산 방법
 *   Zabbix 는 숫자 아이템의 값을 1시간 단위로 요약해 trends 테이블에 저장한다. (시간별 최소 / 평균 / 최대)
 *   이 클래스는 그 표를 DB 에서 바로 집계한다.
 *
 *     1) 대상 호스트에서 CPU 사용률 아이템을 찾는다. (키 목록: conf/serverusage.conf.php)
 *     2) 아이템별, 날짜별로  하루 평균 = 그날 시간별 평균의 평균,  하루 최대 = 그날 시간별 최대의 최대
 *     3) 아이템별로          기간 평균 = 하루 평균들의 평균,       Peak = 하루 최대들의 최대
 *
 *   "업무시간" 을 고르면 2) 에서 업무시간대의 시간별 값만 쓰고, "업무외시간" 이면 그 나머지만 쓴다.
 *
 * 5버전과 달라진 점
 *   - 5버전은 하루치 요약을 따로 만든 테이블(extends_trends_day)에 쌓아 두고 읽었다.
 *     여기서는 추가 테이블 없이 trends 를 직접 집계한다. 결과는 같다.
 *     (Zabbix DB 에 테이블을 추가하면 업그레이드와 백업/복구 때 따로 챙겨야 해서 만들지 않았다)
 *   - 5버전의 메모리용 집계 코드에는 업무시간 Peak 에 "하루 전체의 최대"를 쓰는 부분이 있었다.
 *     여기서는 업무시간을 고르면 Peak 도 업무시간 안의 최대다. 5버전과 Peak 숫자가 다르면 이 차이일 수 있다.
 *
 * 주의
 *   - 기간이 길고 호스트가 많으면 읽는 양이 많다. (1시간 1줄 x 아이템 수 x 날짜 수)
 *     1년 x 2000대면 약 1700만 줄이다. DB 가 집계하므로 PHP 메모리는 쓰지 않지만 수 초 ~ 수십 초 걸릴 수 있다.
 *   - trends 를 직접 읽는 것은 API 로 같은 집계를 할 수 없기 때문이다. (trend.get 은 줄을 그대로 돌려줘서
 *     위 분량을 PHP 로 받아야 한다) 대신 "어느 호스트를 볼 수 있는가"는 API 로 걸러서 권한을 지킨다.
 */

namespace Modules\DMove\Includes;

use API;

class ServerUsageService {

	/** 시간대 구분. 화면의 라디오 버튼 값이기도 하다. */
	public const HOURS_ALL = 'all';
	public const HOURS_BUSINESS = 'work';
	public const HOURS_OFF = 'night';

	public const HOURS_MODES = [self::HOURS_BUSINESS, self::HOURS_OFF, self::HOURS_ALL];

	private const DAY = 86400;
	private const HOUR = 3600;

	/** @var array  conf/serverusage.conf.php 의 내용 */
	private array $config;

	public function __construct() {
		// 설정 파일은 "return [ ... ];" 형태다. require 가 그 배열을 돌려준다.
		$this->config = require dirname(__DIR__).'/conf/serverusage.conf.php';
	}

	/**
	 * 아이템 키 설정이 채워져 있는가. 비어 있으면 화면에 "설정이 필요합니다" 안내를 띄운다.
	 */
	public function isConfigured(): bool {
		$keys = $this->config['cpu_item_keys'];

		return !empty($keys['exact']) || !empty($keys['prefix']);
	}

	/** 판정 기준. ['low_avg' => 30, 'low_peak' => 70, 'high_avg' => 50, 'high_peak' => 70] */
	public function getThresholds(): array {
		return $this->config['thresholds'];
	}

	/** 업무시간 [시작 시, 끝 시]. 예) [9, 18] */
	public function getBusinessHours(): array {
		return $this->config['business_hours'];
	}

	public function getMaxPeriodDays(): int {
		return (int) $this->config['max_period_days'];
	}

	/**
	 * 호스트별 CPU 사용률 평균과 Peak.
	 *
	 * @param array  $hostids    대상 호스트 ID. (API 로 조회한, 사용자가 볼 수 있는 호스트여야 한다)
	 * @param int    $time_from  시작 시각(유닉스 초). 그날 00:00:00
	 * @param int    $time_till  끝 시각(유닉스 초). 그날 23:59:59
	 * @param string $hours      HOURS_ALL / HOURS_BUSINESS / HOURS_OFF
	 *
	 * @return array  [hostid => ['avg' => 12.34, 'peak' => 56.78], ...]
	 *                기간 안에 값이 없는 호스트와 CPU 아이템이 없는 호스트는 결과에 없다.
	 */
	public function getUsage(array $hostids, int $time_from, int $time_till, string $hours): array {
		if (!$hostids) {
			return [];
		}

		// 1) 아이템 찾기. [itemid => hostid]
		$hostid_by_itemid = $this->findCpuItems($hostids);

		if (!$hostid_by_itemid) {
			return [];
		}

		/*
		 * 2) 날짜와 시간대를 SQL 에서 계산하기 위한 준비.
		 *
		 *    trends.clock 은 UTC 기준 유닉스 시간(초)이다. "그날의 몇 시인가"를 알려면 시간대 차이를 더해야 한다.
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

		[$business_from, $business_till] = $this->getBusinessHours();
		$second_from = (int) $business_from * self::HOUR;
		$second_till = (int) $business_till * self::HOUR;
		$second_of_day = 'MOD('.$local.','.self::DAY.')';

		$hours_condition = '';

		if ($hours === self::HOURS_BUSINESS) {
			$hours_condition = ' AND '.$second_of_day.'>='.$second_from.' AND '.$second_of_day.'<'.$second_till;
		}
		elseif ($hours === self::HOURS_OFF) {
			$hours_condition = ' AND ('.$second_of_day.'<'.$second_from.' OR '.$second_of_day.'>='.$second_till.')';
		}

		/*
		 * 3) 집계. 안쪽 SELECT 가 아이템별 + 날짜별 하루 평균/최대를, 바깥 SELECT 가 그것의 기간 평균/최대를 구한다.
		 *
		 *    dbConditionId(컬럼, ID배열) : "itemid IN (1,2,3)" 조건을 안전하게 만들어 주는 코어 함수.
		 *    쿼리에 들어가는 나머지 값은 모두 이 메소드 안에서 만든 정수라서 따옴표 처리가 필요 없다.
		 */
		$result = DBselect(
			'SELECT d.itemid,AVG(d.day_avg) AS usage_avg,MAX(d.day_max) AS usage_peak'.
			' FROM ('.
				'SELECT itemid,'.$day_number.' AS day_number,AVG(value_avg) AS day_avg,MAX(value_max) AS day_max'.
				' FROM trends'.
				' WHERE '.dbConditionId('itemid', array_keys($hostid_by_itemid)).
					' AND clock>='.$time_from.
					' AND clock<='.$time_till.
					$hours_condition.
				' GROUP BY itemid,'.$day_number.
			') d'.
			' GROUP BY d.itemid'
		);

		$usage = [];

		if ($result === false) {
			return $usage;
		}

		while ($row = DBfetch($result)) {
			$hostid = $hostid_by_itemid[$row['itemid']];

			// 한 호스트에 CPU 아이템이 둘 이상이면 평균이 큰 쪽을 그 호스트의 값으로 쓴다.
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
	 * 대상 호스트의 CPU 사용률 아이템을 찾는다.
	 *
	 * @return array  [itemid => hostid]
	 */
	private function findCpuItems(array $hostids): array {
		$keys = $this->config['cpu_item_keys'];
		$found = [];

		// 키가 정확히 같은 아이템. filter 는 "값이 같은 것", 배열을 주면 "그중 하나와 같은 것".
		if (!empty($keys['exact'])) {
			$items = API::Item()->get([
				'output' => ['itemid', 'hostid'],
				'hostids' => $hostids,
				'filter' => ['key_' => array_values($keys['exact'])]
			]);

			foreach ($items as $item) {
				$found[$item['itemid']] = $item['hostid'];
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
				'output' => ['itemid', 'hostid'],
				'hostids' => $hostids,
				'search' => ['key_' => array_values($keys['prefix'])],
				'startSearch' => true,
				'searchByAny' => true
			]);

			foreach ($items as $item) {
				$found[$item['itemid']] = $item['hostid'];
			}
		}

		return $found;
	}
}
