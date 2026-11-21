<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 현황 화면의 데이터 처리.
 *
 * 5버전 대응: 윈도우 트레잇(...Windows.php)의 middle_patchFile / middle_patchVersion / middle_patchDate,
 *            키 클래스의 patchFile()
 *
 * 데이터가 오는 곳
 *   각 윈도우 서버의 Zabbix 에이전트가 "설치된 패치 목록 파일"의 내용을 통째로 읽어 보내는 아이템이 있다.
 *   (아이템 키: conf/winpatch.conf.php)  그 값(긴 글)이 이력(history)에 쌓인다.
 *
 *     목록 화면   = 그 아이템을 가진 호스트
 *     상세보기    = 선택한 달에 수집된 값 중 가장 최근 것을 표로 풀어서 보여 준다
 *     HotFix ID   = 그 값 안에 입력한 글자가 들어 있는 호스트만 남긴다
 *
 * 5버전과 달라진 점
 *   - history_str / history_text 테이블을 직접 읽던 것을 API(history.get)로 바꿨다.
 *   - 5버전은 값을 "빈칸으로 나눠" 표로 만들어서, 비어 있는 열이 있으면 뒤의 값이 앞으로 당겨졌다.
 *     (예: 설치한 사람이 비어 있으면 날짜가 다른 열에 표시됨)
 *     여기서는 제목 줄의 열 위치를 기준으로 잘라서 값이 제 열에 들어간다. (parse() 참고)
 */

namespace Modules\DMove\Includes;

use API;
use Modules\DMove\Includes\Filter\HostFilter;

class WinPatchService {

	/** @var array  conf/winpatch.conf.php 의 내용 */
	private array $config;

	public function __construct() {
		$this->config = require dirname(__DIR__).'/conf/winpatch.conf.php';
	}

	/**
	 * 아이템 키 설정이 채워져 있는가. 비어 있으면 화면에 "설정이 필요합니다" 안내를 띄운다.
	 */
	public function isConfigured(): bool {
		return trim((string) $this->config['item_key']) !== '';
	}

	/** 상세 표의 열 이름. */
	public function getColumns(): array {
		return $this->config['columns'];
	}

	/**
	 * 'YYYY-MM-DD' 가 속한 달의 시작과 끝을 유닉스 시간(초)으로 돌려준다.
	 * 5버전의 "수집월선택" 과 같다. 날짜를 하나 고르면 그 날이 속한 달 전체를 본다.
	 *
	 * @return array  [그달 1일 00:00:00, 그달 말일 23:59:59]
	 */
	public static function monthRange(string $date): array {
		$time = strtotime($date.' 00:00:00');

		// date('Y-m-01') = 그달 1일,  date('Y-m-t') = 그달 말일 (t 는 "그달의 날 수")
		return [
			strtotime(date('Y-m-01', $time).' 00:00:00'),
			strtotime(date('Y-m-t', $time).' 23:59:59')
		];
	}

	/**
	 * 패치 목록 아이템을 가진 호스트 목록.
	 *
	 * @param array  $host_filter  HostFilter::load() 의 결과.
	 * @param string $hotfix_id    비어 있지 않으면, 선택한 달의 값에 이 글자가 들어 있는 호스트만 남긴다.
	 * @param string $month_date   'YYYY-MM-DD'. 이 날짜가 속한 달을 본다.
	 *
	 * @return array  [['hostid' => .., 'name' => .., 'ip' => .., 'description' => ..], ...]
	 */
	public function getRows(array $host_filter, string $hotfix_id, string $month_date): array {
		// 설정이 비어 있으면 찾을 아이템이 없다.
		if (!$this->isConfigured()) {
			return [];
		}

		// 1) 필터에 맞는 호스트 중 패치 목록 아이템을 가진 것.
		$items = API::Item()->get(HostFilter::toApiOptions($host_filter) + [
			'output' => ['itemid', 'hostid', 'value_type'],
			'filter' => ['key_' => $this->config['item_key']]
		]);

		if (!$items) {
			return [];
		}

		// array_column(배열, null, 'hostid') : 각 행을 hostid 로 찾을 수 있게 색인한다. [hostid => 아이템]
		$item_by_hostid = array_column($items, null, 'hostid');

		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'description'],
			'selectInterfaces' => HostHelper::INTERFACE_FIELDS,
			'hostids' => array_keys($item_by_hostid),
			'preservekeys' => true
		]);

		// 2) HotFix ID 필터. 값이 길어서, 필터를 쓴 경우에만 호스트별로 값을 읽는다.
		$hotfix_id = trim($hotfix_id);

		if ($hotfix_id !== '') {
			[$time_from, $time_till] = self::monthRange($month_date);

			foreach ($hosts as $hostid => $host) {
				$value = $this->getLatestValue($item_by_hostid[$hostid], $time_from, $time_till);

				// mb_stripos: 대소문자 무시 "포함" 검사. 없으면 false.
				if ($value === null || mb_stripos($value, $hotfix_id) === false) {
					unset($hosts[$hostid]);
				}
			}
		}

		// 3) Description 은 ITSM 값을 먼저 쓰고, 없으면 Zabbix 호스트 설명을 쓴다.
		$itsm_descriptions = (new ItsmRepository())->fetchDescriptions();

		$rows = [];

		foreach ($hosts as $hostid => $host) {
			$rows[] = [
				'hostid' => $hostid,
				'name' => $host['name'],
				'ip' => HostHelper::mainIp($host['interfaces']),
				'description' => $itsm_descriptions[mb_strtolower($host['name'])]
					?? $itsm_descriptions[mb_strtolower($host['host'])]
					?? $host['description']
			];
		}

		return $rows;
	}

	/**
	 * 한 호스트의 패치 상세 표.
	 *
	 * @return array|null  ['host' => 호스트 표시명, 'rows' => [[열 이름 => 값, ...], ...]]
	 *                     호스트가 없거나 볼 권한이 없거나 패치 아이템이 없으면 null.
	 */
	public function getDetail(string $hostid, string $month_date): ?array {
		// API 는 사용자가 볼 수 없는 호스트를 돌려주지 않는다. 주소의 hostid 를 바꿔 넣어도 권한 밖의 값은 못 본다.
		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'hostids' => [$hostid]
		]);

		$items = ($hosts && $this->isConfigured())
			? API::Item()->get([
				'output' => ['itemid', 'hostid', 'value_type'],
				'hostids' => [$hostid],
				'filter' => ['key_' => $this->config['item_key']]
			])
			: [];

		if (!$items) {
			return null;
		}

		[$time_from, $time_till] = self::monthRange($month_date);
		$value = $this->getLatestValue($items[0], $time_from, $time_till);

		return [
			'host' => $hosts[0]['name'],
			'rows' => $value !== null ? $this->parse($value) : []
		];
	}

	/**
	 * 기간 안에 수집된 값 중 가장 최근 것. 없으면 null.
	 */
	private function getLatestValue(array $item, int $time_from, int $time_till): ?string {
		/*
		 * history.get
		 *   history   : 값이 저장된 표의 종류. 아이템의 value_type 과 같은 값을 줘야 한다. (1 = 문자, 4 = 텍스트)
		 *   sortfield / sortorder / limit : 시각 내림차순으로 1건 = 가장 최근 값.
		 */
		$history = API::History()->get([
			'output' => ['value'],
			'history' => (int) $item['value_type'],
			'itemids' => [$item['itemid']],
			'time_from' => $time_from,
			'time_till' => $time_till,
			'sortfield' => 'clock',
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => 1
		]);

		return $history ? (string) $history[0]['value'] : null;
	}

	/**
	 * 패치 목록 글을 표로 바꾼다.
	 *
	 * 값은 이런 모양의 "칸 맞춤 표"다. (열마다 시작 위치가 같고 빈칸으로 채워져 있다)
	 *
	 *   Caption                                     CSName   Description      FixComments  HotFixID   ...
	 *   http://support.microsoft.com/?kbid=4601060  HOST-A   Update                        KB4601060  ...
	 *
	 * 제목 줄에서 각 열 이름이 시작하는 위치를 찾고, 모든 줄을 그 위치에서 자른다.
	 * 그래서 값이 비어 있는 열이 있어도 뒤의 값이 앞으로 당겨지지 않는다.
	 *
	 * 제목 줄을 찾지 못하면(형식이 다르면) 빈칸으로 나누는 5버전 방식으로 처리한다.
	 *
	 * @return array  [[열 이름 => 값, ...], ...]
	 */
	public function parse(string $text): array {
		$columns = $this->getColumns();
		// preg_split('/\R/u', ...) : 줄바꿈(\r\n, \n, \r 모두)으로 나눈다.
		$lines = preg_split('/\R/u', $text) ?: [];
		$rows = [];
		$offsets = null;

		foreach ($lines as $line) {
			// rtrim: 줄 끝의 빈칸을 지운다. 빈 줄은 건너뛴다.
			if (rtrim($line) === '') {
				continue;
			}

			// 제목 줄을 아직 못 찾았으면 이 줄이 제목 줄인지 본다.
			if ($offsets === null) {
				$offsets = self::findOffsets($line, $columns);

				if ($offsets !== null) {
					continue;
				}
			}

			$row = [];

			if ($offsets !== null) {
				// 열 위치로 자르기. 마지막 열은 줄 끝까지.
				foreach ($columns as $index => $column) {
					$start = $offsets[$index];
					$length = array_key_exists($index + 1, $offsets) ? $offsets[$index + 1] - $start : null;

					$row[$column] = trim(mb_substr($line, $start, $length, 'UTF-8'));
				}
			}
			else {
				// 5버전 방식: 빈칸으로 나눈다. PREG_SPLIT_NO_EMPTY = 빈 조각은 버린다.
				$parts = preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];

				foreach ($columns as $index => $column) {
					$row[$column] = $parts[$index] ?? '';
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * 제목 줄에서 각 열 이름의 시작 위치(글자 수 기준)를 찾는다.
	 *
	 * @return array|null  [0, 44, 53, ...]  열 이름이 순서대로 다 있지 않으면 null.
	 */
	private static function findOffsets(string $line, array $columns): ?array {
		$offsets = [];
		$search_from = 0;

		foreach ($columns as $column) {
			// mb_strpos(줄, 찾을 글자, 찾기 시작 위치): 처음 나오는 위치. 없으면 false.
			$position = mb_strpos($line, $column, $search_from, 'UTF-8');

			if ($position === false) {
				return null;
			}

			$offsets[] = $position;
			$search_from = $position + mb_strlen($column, 'UTF-8');
		}

		return $offsets;
	}
}
