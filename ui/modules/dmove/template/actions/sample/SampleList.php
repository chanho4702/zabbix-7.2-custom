<?php declare(strict_types = 0);
/*
 * ★ 새 페이지용 컨트롤러 템플릿 ★  (복사해서 쓴다. 방법: template/README.md)
 *
 * 이 파일은 template/ 폴더에 있어서 그대로는 실행되지 않는다.
 * 복사한 뒤 README 의 "바꿀 곳" 표대로 Sample / sample 을 새 화면 이름으로 바꾸면
 * 필터 + 정렬 + 페이징 + 내보내기(CSV / Excel)가 되는 "호스트 목록" 화면이 바로 뜬다.
 * 그 다음 [바꿀 곳 ①~⑤] 표시가 있는 부분을 화면에 맞게 고친다.
 *
 *   화면 : zabbix.php?action=sample.list
 *   내보내기 : zabbix.php?action=sample.export&format=csv 또는 xlsx
 *
 * ── 이 템플릿이 만드는 화면 ──
 *   필터 : 호스트(입력), 이름(입력)   글자가 들어 있는 줄만 남긴다. 대소문자는 가리지 않는다.
 *   버튼 : CSV 내보내기, Excel 내보내기
 *   표   : 호스트, 이름   (두 열 모두 눌러서 정렬할 수 있다)
 *
 * ── 데이터 흐름 ──
 *   doAction()
 *     ├─ 필터 저장/읽기 (CProfile)
 *     ├─ getRows()           host.get API 로 호스트를 읽어 행을 만든다   <- 새 화면에서는 여기를 바꾼다
 *     ├─ applyTextFilters()  필터에 맞는 행만 남긴다
 *     ├─ 정렬
 *     ├─ 액션이 sample.export 면 -> 전체 행을 CSV / Excel 파일로 내려 주고 끝
 *     └─ 아니면 -> 페이징한 뒤 뷰(views/reports/sample.list.php)로 넘긴다
 *
 * 컨트롤러는 코어가 아래 순서로 호출한다. (ui/include/classes/mvc/CController.php)
 *   init() -> checkInput() -> checkPermissions() -> doAction()
 *   앞 단계가 false 를 돌려주면 뒤 단계는 실행되지 않는다.
 *
 * ── 같이 복사하는 파일 ──
 *   template/actions/sample/SampleList.php   컨트롤러 템플릿 (이 파일)
 *   template/views/sample.list.php           화면 뷰 템플릿
 *   template/README.md                       복사 방법, 바꿀 곳 표
 */

/*
 * 컨트롤러는 actions/<카테고리>/<화면이름>/ 에 둔다. 폴더 이름(소문자)이 그대로 네임스페이스(첫 글자 대문자)가 된다.
 * 보고서가 아닌 카테고리에 넣을 때는 아래 Reports 를 그 카테고리 이름(Inventory, Users ...)으로 바꾼다.
 *   actions/reports/sample/SampleList.php  ->  namespace Modules\DMove\Actions\Reports\Sample;
 */
namespace Modules\DMove\Actions\Reports\Sample;

/*
 * 네임스페이스가 있는 파일에서는 코어 클래스도 use 로 선언해야 한다.
 * 선언이 빠지면 "Class Modules\DMove\Actions\Reports\Sample\API not found" 같은 오류가 난다.
 * (함수와 상수는 선언 없이 쓸 수 있다. 예: trim(), ZBX_SORT_UP)
 */
use API;
use CArrayHelper;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CUrl;
use CWebUser;
use Modules\DMove\Includes\Export\ExportTrait;
use Modules\DMove\Includes\Lang;

class SampleList extends CController {

	/*
	 * 내보내기 플러그인. 이 한 줄로 isExportRequest(), setExportResponse() 를 쓸 수 있게 된다.
	 * 내보내기가 필요 없는 화면이면 이 줄과 아래의 [내보내기] 표시가 있는 부분을 지운다.
	 * (사용법: includes/export/ExportTrait.php)
	 */
	use ExportTrait;

	/** 필터/정렬 값을 사용자별로 저장하는 프로파일 키 접두어. 'web.dmove.<화면>.' 형식으로 짓는다. */
	private const PROFILE_PREFIX = 'web.dmove.sample.';

	/** 화면 액션과 내보내기 액션의 이름. manifest.json 의 "actions" 에 적은 키와 글자 하나까지 같아야 한다. */
	private const LIST_ACTION = 'sample.list';
	private const EXPORT_ACTION = 'sample.export';

	/** [바꿀 곳 ①] 정렬할 수 있는 컬럼. 행 배열의 키 이름과 같아야 한다. 첫 번째가 기본 정렬 컬럼이다. */
	private const SORT_FIELDS = ['host', 'name'];

	/** [바꿀 곳 ②] 텍스트 필터. 필터 키 => 비교할 행 컬럼. 입력창 이름은 'filter_' + 필터 키가 된다. */
	private const TEXT_FILTERS = [
		'host' => 'host',
		'name' => 'name'
	];

	/**
	 * 컨트롤러가 만들어질 때 코어가 한 번 부른다. 다른 단계(checkInput 등)보다 먼저 실행된다.
	 * 여기서는 CSRF 토큰 검사를 끄는 일만 한다.
	 *
	 * CSRF 토큰: "이 요청이 우리 화면에서 보낸 것이 맞다"를 확인하는 숨은 값이다.
	 * 값을 바꾸지 않고 보기만 하는 화면은 주소만으로 열려야 하므로 검사를 끈다.
	 *
	 * @return void  돌려주는 값이 없다.
	 */
	protected function init(): void {
		/*
		 * 조회 전용 화면(GET)이라 CSRF 토큰 검사를 끈다. (5버전의 disableSIDValidation() 에 해당)
		 * 데이터를 저장/삭제하는 액션에서는 끄면 안 된다.
		 */
		$this->disableCsrfValidation();
	}

	/**
	 * 입력값 검증. 여기에 없는 파라미터는 getInput() 으로 읽을 수 없다.
	 *
	 * 자주 쓰는 규칙 (전체 목록: ui/include/classes/validators/CNewValidator.php)
	 *   'string'      문자열
	 *   'in 0,1'      나열한 값만 허용 (체크박스, 라디오)
	 *   'ge 1'        1 이상 정수
	 *   'id'          숫자 ID 하나
	 *   'array_id'    숫자 ID 배열 (호스트/호스트 그룹 선택창. 사용 예: actions/reports/uptime/UptimeList.php)
	 *
	 * 이 화면이 받는 값
	 *   page                      페이지 번호. 예) 2
	 *   filter_set / filter_rst   [적용] / [리셋] 버튼을 눌렀다는 표시. 값은 1.
	 *   sort / sortorder          정렬할 열과 방향. 예) sort=name&sortorder=DESC
	 *   filter_host, filter_name  필터 입력값 (TEXT_FILTERS 의 키마다 하나씩)
	 *   format                    내보내기 형식. csv 또는 xlsx
	 *
	 * @return bool  모든 값이 규칙에 맞으면 true. 하나라도 틀리면 false 이고 오류 화면이 나온다.
	 */
	protected function checkInput(): bool {
		$fields = [
			'page' =>		'ge 1',
			'filter_set' =>	'in 1',
			'filter_rst' =>	'in 1',
			// implode(',', ['host', 'name']) = 'host,name'. 그래서 규칙은 'in host,name' 이 된다.
			'sort' =>		'in '.implode(',', self::SORT_FIELDS),
			'sortorder' =>	'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN
		// [내보내기] format 파라미터 규칙을 더한다. 배열 + 배열 = 두 배열을 합친다.
		] + self::exportInputFields();

		// 텍스트 필터마다 'filter_<키>' => 'string' 규칙을 넣는다. 예) filter_host, filter_name
		foreach (array_keys(self::TEXT_FILTERS) as $key) {
			$fields['filter_'.$key] = 'string';
		}

		$ret = $this->validateInput($fields);

		if (!$ret) {
			// 오류 화면을 응답으로 지정한다. false 를 돌려주면 doAction() 은 실행되지 않는다.
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * [바꿀 곳 ③] 접근 권한. true 를 돌려준 사용자만 화면을 볼 수 있다.
	 *
	 *   로그인한 모든 사용자(게스트 제외) : return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	 *   관리자 이상                        : return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
	 *   Super admin 만                     : return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	 *   코어 화면 권한을 따라가기          : return $this->checkAccess(\CRoleHelper::UI_INVENTORY_HOSTS);
	 *                                        (상수 목록: ui/include/classes/helpers/CRoleHelper.php)
	 *
	 * 어느 호스트가 보이는지는 여기서 정하지 않는다. getRows() 의 API 가 사용자 권한에 맞게 걸러 준다.
	 *
	 * @return bool  볼 수 있으면 true. false 면 코어가 "접근 권한 없음" 화면을 보여 준다.
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 화면을 만드는 본체. 입력 검사와 권한 검사를 통과하면 코어가 부른다.
	 * 화면(sample.list)과 내보내기(sample.export) 두 액션이 이 메소드를 같이 쓴다.
	 *
	 * 하는 일 (아래 코드의 번호와 같다)
	 *   1) [적용]/[리셋]을 눌렀으면 필터를 저장하거나 지운다. 그 다음 저장된 필터를 읽는다.
	 *   2) 정렬할 열과 방향을 정한다. 주소에 없으면 지난번 값을 쓴다.
	 *   3) 행을 만들고 -> 필터로 거르고 -> 정렬한다.
	 *   4) 내보내기 요청이면 파일로 응답하고 끝낸다.
	 *   5) 화면이면 페이지 분량만 잘라서 뷰로 넘긴다.
	 *
	 * 뷰로 넘기는 $data 의 키
	 *   action, export_action   화면 / 내보내기 액션 이름
	 *   title                   화면 제목
	 *   columns                 열 정의. ['host' => '호스트', 'name' => '이름']   (getColumns())
	 *   filter                  필터 값. ['host' => 'web', 'name' => '']
	 *   active_tab              필터 영역이 펼쳐져 있는지 (1 = 펼침)
	 *   sort, sortorder         정렬 열, 방향('ASC' / 'DESC')
	 *   paging                  페이지 이동 줄
	 *   rows                    현재 페이지의 행들
	 *
	 * @return void  돌려주는 값은 없다. 결과는 $this->setResponse() 로 코어에 넘긴다.
	 */
	protected function doAction(): void {
		// ── 1) 필터 저장/초기화 ([적용] -> filter_set=1, [리셋] -> filter_rst=1) ──
		if ($this->hasInput('filter_set')) {
			foreach (array_keys(self::TEXT_FILTERS) as $key) {
				CProfile::update(self::PROFILE_PREFIX.'filter.'.$key, trim($this->getInput('filter_'.$key, '')),
					PROFILE_TYPE_STR
				);
			}
		}
		elseif ($this->hasInput('filter_rst')) {
			foreach (array_keys(self::TEXT_FILTERS) as $key) {
				CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.'.$key);
			}
		}

		/*
		 * 필터 값은 URL 이 아니라 프로파일(DB)에서 읽는다.
		 * 그래서 페이지 이동, 정렬 클릭, 내보내기, 다음 접속 때에도 같은 필터가 유지된다.
		 */
		$filter = [];

		foreach (array_keys(self::TEXT_FILTERS) as $key) {
			$filter[$key] = CProfile::get(self::PROFILE_PREFIX.'filter.'.$key, '');
		}

		// ── 2) 정렬 값 (헤더를 클릭하면 sort / sortorder 가 넘어온다. 마지막 값을 기억한다) ──
		// getInput('이름', 기본값): 요청에 값이 없으면 기본값. 여기서는 기본값이 "지난번에 저장한 정렬"이다.
		$sort_field = $this->getInput('sort', CProfile::get(self::PROFILE_PREFIX.'sort', self::SORT_FIELDS[0]));
		$sort_order = $this->getInput('sortorder', CProfile::get(self::PROFILE_PREFIX.'sortorder', ZBX_SORT_UP));

		CProfile::update(self::PROFILE_PREFIX.'sort', $sort_field, PROFILE_TYPE_STR);
		CProfile::update(self::PROFILE_PREFIX.'sortorder', $sort_order, PROFILE_TYPE_STR);

		// ── 3) 데이터 조회 -> 필터 -> 정렬 ──
		$rows = $this->getRows();
		$rows = $this->applyTextFilters($rows, $filter);
		// 코어의 정렬 도우미. $rows 를 직접 바꾼다. 대소문자를 무시하고 숫자가 섞인 이름(web2 < web10)도 자연스럽게 정렬한다.
		CArrayHelper::sort($rows, [['field' => $sort_field, 'order' => $sort_order]]);

		// [바꿀 곳 ④] 화면 제목. 영어로 적고 lang/ko.php 에 한국어를 넣는다.
		$title = Lang::t('Sample page');

		// ── 4) [내보내기] 내보내기 요청이면 파일로 응답하고 끝낸다. (페이징으로 자르기 전의 전체 행을 넘긴다) ──
		if ($this->isExportRequest()) {
			// 두 번째 인자는 파일명 앞부분. 예) sample_20260102_030405.xlsx
			$this->setExportResponse($title, 'sample', self::getColumns(), $rows);

			return;
		}

		// ── 5) 뷰로 넘길 데이터. 뷰에서는 $data['키'] 로 읽는다. ──
		$data = [
			'action' => self::LIST_ACTION,
			'export_action' => self::EXPORT_ACTION,
			'title' => $title,
			'columns' => self::getColumns(),
			'filter' => $filter,
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'filter.active', 1),
			'sort' => $sort_field,
			'sortorder' => $sort_order
		];

		/*
		 * 페이징. paginate() 가 $rows 를 현재 페이지 분량으로 잘라 준다.
		 * 화면 목록은 코어 설정 "검색/필터 결과 제한"(관리 > 일반 > GUI, 기본 1000) 까지만 표시된다.
		 */
		$data['paging'] = CPagerHelper::paginate($this->getInput('page', 1), $rows, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', self::LIST_ACTION)
		);
		$data['rows'] = $rows;

		// 응답 지정. 코어가 이 데이터를 manifest.json 에 적힌 뷰로 넘겨 실행한다.
		$response = new CControllerResponseData($data);
		$response->setTitle($title);	// 브라우저 탭 제목

		$this->setResponse($response);
	}

	/**
	 * [바꿀 곳 ⑥] 표의 열 정의. 화면의 제목 줄과 내보내기 파일이 이 한 곳을 같이 쓴다.
	 *
	 *   키 = 행 배열의 키,  값 = 제목
	 *   숫자 열은 'used' => ['label' => '사용량', 'type' => 'number'] 처럼 적으면 엑셀에 숫자로 들어간다.
	 *
	 * @return array  [행의 키 => 제목]. 예) ['host' => '호스트', 'name' => '이름']
	 */
	private static function getColumns(): array {
		return [
			'host' => _('Host'),
			'name' => _('Name')
		];
	}

	/**
	 * [바꿀 곳 ⑤] 표에 표시할 행 목록을 만든다. 행 1개 = 표의 한 줄 = ['컬럼키' => 값, ...]
	 *
	 * 데이터를 읽는 방법
	 *   (권장) API::Host()->get([...]) 같은 Zabbix API.
	 *          로그인한 사용자가 권한을 가진 데이터만 나오고, 버전이 올라가도 잘 깨지지 않는다.
	 *          메소드와 옵션은 Zabbix 공식 문서의 API 레퍼런스(host.get, item.get ...)와 같다.
	 *   (필요할 때만) DBselect('SELECT ...') 로 직접 조회.
	 *          권한이 무시되므로 주의한다. 값을 쿼리에 넣을 때는 반드시 zbx_dbstr($값) 또는
	 *          dbConditionId('컬럼', $id배열) 을 쓴다. (사용 예: includes/ItsmRepository.php)
	 *
	 * 로직이 길어지면 includes/ 에 클래스를 만들어 옮긴다. (예: includes/SwInventoryService.php)
	 *
	 * @return array  행 1개 = 호스트 1대.
	 *   [
	 *     'hostid' => '10084',
	 *     'host'   => 'web-server-01',     // 호스트 이름(기술 이름)
	 *     'name'   => '웹 서버 1호기'      // 표시 이름
	 *   ]
	 */
	private function getRows(): array {
		// host.get 의 output: 받을 필드만 적는다. 적지 않으면 모든 필드가 와서 느려진다.
		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name']
		]);

		$rows = [];

		foreach ($hosts as $host) {
			$rows[] = [
				'hostid' => $host['hostid'],
				'host' => $host['host'],
				'name' => $host['name']
			];
		}

		return $rows;
	}

	/**
	 * 텍스트 필터 적용. 입력한 글자가 해당 컬럼에 "포함"되어 있으면 남긴다. (대소문자 무시)
	 * 필터를 여러 개 입력하면 모두 만족하는 행만 남는다. 빈 필터는 건너뛴다.
	 *
	 * 예) $filter = ['host' => 'web', 'name' => '']  ->  host 에 'web' 이 들어 있는 행만 남는다. ('WEB-01' 도 남는다)
	 *
	 * @param array $rows    getRows() 의 결과.
	 * @param array $filter  [필터 키 => 입력한 글자]. 키는 TEXT_FILTERS 의 키와 같다.
	 *
	 * @return array  남은 행. 번호는 0 부터 다시 매겨진다.
	 */
	private function applyTextFilters(array $rows, array $filter): array {
		foreach (self::TEXT_FILTERS as $key => $column) {
			$needle = $filter[$key];

			if ($needle === '') {
				continue;
			}

			// array_filter: 조건 함수가 true 를 돌려준 행만 남긴다. fn(...) => ... 는 한 줄짜리 함수 표기.
			$rows = array_filter($rows,
				// mb_stripos: 한글도 안전한 "대소문자 무시 포함 검사". 없으면 false 를 돌려준다.
				static fn(array $row): bool => mb_stripos((string) $row[$column], $needle) !== false
			);
		}

		// array_values: array_filter 로 빠진 번호를 0 부터 다시 매긴다.
		return array_values($rows);
	}
}
