<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 현황 화면의 데이터 처리.
 *
 * ── 이 파일이 하는 일 ──
 *   윈도우 서버의 "설치된 패치 목록" 아이템을 찾아서, 목록 화면의 행과 상세보기 표를 만든다.
 *   화면을 그리지 않는다. 배열만 만들어 컨트롤러에 돌려준다.
 *
 * ── 누가 쓰는가 ──
 *   actions/reports/winpatch/WinPatchList.php     목록 화면 (zabbix.php?action=winpatch.list) -> getRows()
 *   actions/reports/winpatch/WinPatchDetail.php   상세보기 창 -> getDetail(), getColumns()
 *
 * ── 데이터 흐름 ──
 *   목록 : getRows()
 *            ├─ item.get : 필터에 맞는 호스트 중 패치 목록 아이템을 가진 것
 *            ├─ host.get : 그 호스트의 이름, 설명, 인터페이스
 *            ├─ (HotFix ID 를 입력했을 때만) getLatestValue() 로 값을 읽어 글자가 들어 있는지 본다
 *            └─ ItsmRepository::fetchDescriptions() 로 Description 을 채운다
 *   상세 : getDetail()
 *            ├─ host.get / item.get : 호스트와 패치 목록 아이템 (권한 밖이면 결과가 없다)
 *            ├─ getLatestValue()    : 선택한 달의 가장 최근 값 (history.get)
 *            └─ parse()             : 긴 글을 표의 행으로 자른다
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
 *
 * ── 관련 파일 ──
 *   conf/winpatch.conf.php                        설정 (아이템 키, 상세 표의 열 이름)
 *   actions/reports/winpatch/WinPatchList.php     목록 화면 컨트롤러
 *   actions/reports/winpatch/WinPatchDetail.php   상세보기 컨트롤러
 *   includes/filter/HostFilter.php                호스트 그룹/호스트 선택 필터
 *   includes/ItsmRepository.php                   ITSM 설명 조회
 */

namespace Modules\DMove\Includes;

use API;
use Modules\DMove\Includes\Filter\HostFilter;

class WinPatchService {

	/** @var array  conf/winpatch.conf.php 의 내용 */
	private array $config;

	/**
	 * 객체를 만들 때 설정 파일을 한 번 읽어 둔다.
	 *
	 * 설정 파일은 "return [ ... ];" 형태다. require 가 그 배열을 돌려준다.
	 * dirname(__DIR__) 은 이 파일이 있는 폴더(includes)의 위 폴더, 곧 모듈 폴더다.
	 *
	 * 읽은 뒤의 모양: ['item_key' => '아이템 키', 'columns' => ['Caption', 'CSName', ...]]
	 *
	 * 사용 예:
	 *   $service = new WinPatchService();
	 */
	public function __construct() {
		$this->config = require dirname(__DIR__).'/conf/winpatch.conf.php';
	}

	/**
	 * 아이템 키 설정이 채워져 있는가. 비어 있으면 화면에 "설정이 필요합니다" 안내를 띄운다.
	 *
	 * @return bool  conf/winpatch.conf.php 의 item_key 에 글자가 있으면 true. 빈칸만 있어도 false.
	 */
	public function isConfigured(): bool {
		return trim((string) $this->config['item_key']) !== '';
	}

	/**
	 * 상세 표의 열 이름. conf/winpatch.conf.php 의 columns 를 그대로 돌려준다.
	 * 상세보기 창의 제목 줄과 parse() 가 값을 자를 때 같이 쓴다.
	 *
	 * @return array  예) ['Caption', 'CSName', 'Description', ...]
	 */
	public function getColumns(): array {
		return $this->config['columns'];
	}

	/**
	 * 'YYYY-MM-DD' 가 속한 달의 시작과 끝을 유닉스 시간(초)으로 돌려준다.
	 * 5버전의 "수집월선택" 과 같다. 날짜를 하나 고르면 그 날이 속한 달 전체를 본다.
	 *
	 * 예) '2026-11-23' -> [2026-11-01 00:00:00 의 유닉스 초, 2026-11-30 23:59:59 의 유닉스 초]
	 *
	 * @param string $date  'YYYY-MM-DD' 형식의 날짜.
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
	 * 패치 목록 아이템을 가진 호스트 목록. (목록 화면의 행)
	 *
	 * 설정의 아이템 키를 가진 호스트만 나온다. 호스트는 API 가 사용자 권한에 맞게 걸러 준다.
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

		/*
		 * 1) 필터에 맞는 호스트 중 패치 목록 아이템을 가진 것. item.get 옵션:
		 *    HostFilter::toApiOptions()  선택한 그룹/호스트를 groupids, hostids 조건으로 바꿔 준다.
		 *    filter                      정확히 일치하는 값으로 거른다. 여기서는 아이템 키.
		 *    value_type                  값의 형식. 뒤에서 history.get 을 부를 때 필요하다.
		 */
		/*
		 * 주의: HostFilter::toApiOptions() 는 반드시 API 호출 "앞"에서 변수에 담아 둔다.
		 * API::Host()->get(HostFilter::toApiOptions(...) + [...]) 처럼 괄호 안에 직접 쓰면 안 된다.
		 * 코어의 API::Host(), API::Item() 등은 같은 객체 하나를 돌려쓰면서 "지금 부를 API 이름"만 바꾼다.
		 * 괄호 안의 toApiOptions() 가 호스트 그룹을 조회하면(API::HostGroup()) 그 이름이 hostgroup 으로 바뀌어,
		 * 바깥의 get() 이 엉뚱하게 hostgroup.get 으로 실행된다. (호스트 그룹 필터를 골랐을 때만 생기는 오류다)
		 */
		$host_options = HostFilter::toApiOptions($host_filter);

		$items = API::Item()->get($host_options + [
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

		// ITSM 설명은 호스트 표시 이름(name)으로 먼저 찾고, 없으면 기술 이름(host)으로 찾는다. 둘 다 없으면 Zabbix 설명.
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
	 * 한 호스트의 패치 상세 표. (상세보기 창의 내용)
	 *
	 * 선택한 달에 수집된 값 중 가장 최근 것을 표로 바꾼다. 그 달에 값이 없으면 rows 가 빈 배열이다.
	 *
	 * @param string $hostid      호스트 ID. 예) '10084'
	 * @param string $month_date  'YYYY-MM-DD'. 이 날짜가 속한 달을 본다.
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

		// 조건 ? A : B : 조건이 참이면 A, 아니면 B. 호스트가 안 보이거나 설정이 비었으면 아이템을 찾지 않는다.
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
			// 5버전 팝업처럼 설치일(InstalledOn) 최근 순으로 정렬한다.
			'rows' => $value !== null ? self::sortByInstalledOn($this->parse($value)) : []
		];
	}

	/**
	 * 기간 안에 수집된 값 중 가장 최근 것. 없으면 null.
	 *
	 * @param array $item       item.get 의 행 1개. ['itemid' => .., 'hostid' => .., 'value_type' => ..]
	 * @param int   $time_from  시작 시각(유닉스 초).
	 * @param int   $time_till  끝 시각(유닉스 초).
	 *
	 * @return string|null  아이템 값(패치 목록 글 전체). 기간 안에 값이 없으면 null.
	 *                      ?string 의 ? 는 "null 도 돌려줄 수 있다"는 뜻이다.
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
	 * 상세 표의 줄을 설치일(InstalledOn) 최근 순으로 정렬한다. (5버전 popup.windows.php: order_result($info, "InstalledOn", "desc"))
	 *
	 * 5버전은 "10/13/2026" 같은 글자를 글자 순서로 정렬해서 월/일이 섞여 틀렸다. (예: 9/30 이 10/13 보다 뒤로 감)
	 * 여기서는 날짜로 바꿔 비교한다. 날짜로 읽을 수 없는 값(빈칸 등)은 맨 뒤로 보낸다.
	 *
	 *   strtotime('10/13/2026') : 미국식 월/일/년 날짜를 유닉스 초로 바꾼다. 읽을 수 없으면 false.
	 *   usort + <=>              : 큰 값(최근)이 앞으로 오게 비교한다.
	 *
	 * @param array $rows  parse() 의 결과. [['InstalledOn' => '10/13/2026', ...], ...]
	 */
	private static function sortByInstalledOn(array $rows): array {
		$time = static function (array $row): int {
			$ts = strtotime(trim((string) ($row['InstalledOn'] ?? '')));

			return $ts === false ? PHP_INT_MIN : $ts;
		};

		usort($rows, static fn(array $a, array $b): int => $time($b) <=> $time($a));

		return $rows;
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
	 * @param string $text  아이템 값. 제목 줄 1개 + 패치 1건당 1줄.
	 *
	 * @return array  [[열 이름 => 값, ...], ...]
	 *                예) [['Caption' => 'http://...', 'CSName' => 'HOST-A', 'Description' => 'Update',
	 *                      'FixComments' => '', 'HotFixID' => 'KB4601060', ...], ...]
	 */
	public function parse(string $text): array {
		$columns = $this->getColumns();
		// preg_split('/\R/u', ...) : 줄바꿈(\r\n, \n, \r 모두)으로 나눈다. 끝의 u 는 글을 UTF-8 로 다루라는 표시.
		// ?: [] : preg_split 이 실패해서 false 를 주면 빈 배열로 바꾼다. (a ?: b = a 가 비었으면 b)
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
				// 열 위치로 자르기. 마지막 열은 줄 끝까지. (mb_substr 의 길이에 null 을 주면 끝까지 자른다)
				foreach ($columns as $index => $column) {
					$start = $offsets[$index];
					$length = array_key_exists($index + 1, $offsets) ? $offsets[$index + 1] - $start : null;

					$row[$column] = trim(mb_substr($line, $start, $length, 'UTF-8'));
				}
			}
			else {
				// 5버전 방식: 빈칸으로 나눈다. \s+ = 빈칸/탭이 한 개 이상 이어진 곳. PREG_SPLIT_NO_EMPTY = 빈 조각은 버린다.
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
	 * 열 이름을 앞에서부터 차례로 찾는다. 앞 열 이름이 끝난 자리부터 다음 열 이름을 찾으므로,
	 * 한 이름이 다른 이름 안에 들어 있어도(예: 뒤쪽 열 Name 은 앞쪽 열 CSName 안에도 있다) 엉뚱한 자리를 잡지 않는다.
	 * 하나라도 못 찾으면 그 줄은 제목 줄이 아니라고 보고 null 을 돌려준다.
	 *
	 * @param string $line     검사할 줄.
	 * @param array  $columns  열 이름 목록. getColumns() 의 결과.
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
