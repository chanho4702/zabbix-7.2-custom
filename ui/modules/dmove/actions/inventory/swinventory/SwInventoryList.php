<?php declare(strict_types = 0);
/*
 * SW 인벤토리 화면 + 내보내기(CSV / Excel) 컨트롤러.
 *
 * ── 이 화면이 하는 일 ──
 *   ITSM 에 등록된 서버 목록을 한 줄씩 보여 주고, 각 서버가 Zabbix 에 등록되어 있는지(Agent 설치 여부)와
 *   그 서버의 OS / WEB / WAS / DB / 이중화 솔루션을 함께 보여 준다.
 *   ITSM 을 연결하지 않은 환경에서는 Zabbix 호스트 목록을 대신 쓴다. (그때는 화면에 안내가 뜬다)
 *
 *   화면 : zabbix.php?action=swinventory.list
 *   내보내기 : zabbix.php?action=swinventory.export&format=csv 또는 xlsx
 *   메뉴 : 인벤토리 > SW 인벤토리   (Module.php 의 INVENTORY_MENUS)
 *
 * 5버전 대응: 커스텀 폴더의 controllers/CControllerSwinventories.php, CControllerSWInventCSV
 *
 * ── 화면 구성 ──
 *   필터 : ITSM HOST, OS/Version, WEB, WAS, DB, 이중화 (글자 입력. 부분 일치)
 *          Agent 설치 / 미설치 / 예외 (체크박스)
 *   버튼 : CSV 내보내기, Excel 내보내기
 *   표   : 호스트, 표시명, IP, Description, OS, WEB, WAS, DB, 이중화 솔루션, Agent 설치
 *          (열 정의는 아래 getColumns(). 모든 열을 눌러서 정렬할 수 있다)
 *
 * ── 데이터 흐름 ──
 *   doAction()
 *     ├─ 필터 저장/읽기 (CProfile)  updateProfiles() / deleteProfiles() / getFilter()
 *     ├─ SwInventoryService::getRows()   ITSM 목록과 Zabbix 호스트를 맞춰서 전체 행을 만든다
 *     ├─ SwInventoryService::filter()    필터에 맞는 행만 남긴다
 *     ├─ SwInventoryService::sort()      정렬한다
 *     ├─ 액션이 swinventory.export 면 -> 전체 행을 CSV / Excel 파일로 내려 주고 끝
 *     └─ 아니면 -> 페이징한 뒤 뷰(views/inventory/swinventory.list.php)로 넘긴다
 *
 * 새 페이지를 추가하는 방법은 ui/modules/dmove/template/README.md 에 있다. (복사해서 쓰는 템플릿 포함)
 *
 * ── 이 화면에 속한 파일 ──
 *   actions/inventory/swinventory/SwInventoryList.php   컨트롤러 (이 파일. 화면과 내보내기 두 액션이 같이 쓴다)
 *   views/inventory/swinventory.list.php          화면 뷰
 *   includes/export/                          내보내기 플러그인 (사용법: includes/export/ExportTrait.php)
 *   includes/SwInventoryService.php           데이터 조회/매칭/필터/정렬
 *   includes/ItsmRepository.php               ITSM 목록 조회 (설정: conf/itsm.conf.php)
 *   includes/SoftwareResolver.php             OS/WEB/WAS/DB/이중화 값 결정 (필드 매핑: INVENTORY_FIELDS 상수)
 *
 * 컨트롤러는 코어가 아래 순서로 호출한다. (ui/include/classes/mvc/CController.php)
 *   init() -> checkInput() -> checkPermissions() -> doAction()
 *   앞 단계가 false 를 돌려주면 뒤 단계는 실행되지 않는다.
 */

/*
 * 컨트롤러는 actions/<카테고리>/<화면이름>/ 폴더에 넣는다. 카테고리 = 사이드바 1단계 메뉴(inventory, reports, users).
 * 폴더 이름(소문자)이 그대로 네임스페이스(첫 글자 대문자)가 된다.
 *   actions/inventory/swinventory/SwInventoryList.php  ->  namespace Modules\DMove\Actions\Inventory\SwInventory;
 * manifest.json 에는 "class": "Inventory/SwInventory/SwInventoryList" 처럼 폴더를 / 로 붙여 적는다.
 */
namespace Modules\DMove\Actions\Inventory\SwInventory;

/*
 * 이 파일에서 쓰는 클래스. (네임스페이스가 있는 파일에서는 코어 클래스도 use 로 선언해야 한다)
 *   CController               모든 컨트롤러의 부모.          ui/include/classes/mvc/CController.php
 *   CControllerResponseData   뷰로 넘길 데이터 묶음.         ui/include/classes/mvc/CControllerResponseData.php
 *   CControllerResponseFatal  입력 오류 시 오류 화면 응답.   ui/include/classes/mvc/CControllerResponseFatal.php
 *   CPagerHelper              페이징.                        ui/include/classes/helpers/CPagerHelper.php
 *   CProfile                  사용자별 설정 저장.            ui/include/classes/user/CProfile.php
 *   CRoleHelper               사용자 역할 권한 이름 상수.    ui/include/classes/helpers/CRoleHelper.php
 *   CUrl                      주소 조립.                     ui/include/classes/routing/CUrl.php
 *   SwInventoryService        이 모듈의 데이터 로직.         includes/SwInventoryService.php
 */
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CRoleHelper;
use CUrl;
use CWebUser;
use Modules\DMove\Includes\Export\ExportTrait;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\SwInventoryService;

class SwInventoryList extends CController {

	/**
	 * 필터 값을 사용자별로 저장하는 프로파일 키 접두어. (DB profiles 테이블)
	 * 화면마다 겹치지 않게 'web.dmove.<화면>.' 형식으로 짓는다.
	 */
	private const PROFILE_PREFIX = 'web.dmove.swinventory.';

	/*
	 * 내보내기 플러그인을 꽂는다. isExportRequest(), setExportResponse() 등이 이 클래스의 메소드가 된다.
	 * (includes/export/ExportTrait.php)
	 */
	use ExportTrait;

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
		 * 조회 전용 화면(GET)이라 CSRF 토큰 검사를 끈다.
		 * 5버전의 disableSIDValidation() 은 7버전에 없다. 이름이 disableCsrfValidation() 으로 바뀌었다.
		 * 데이터를 저장/삭제하는 액션에서는 끄면 안 된다.
		 */
		$this->disableCsrfValidation();
	}

	/**
	 * 입력값 검증. 여기에 없는 파라미터는 getInput() 으로 읽을 수 없다.
	 *
	 * 5버전의 check_fields($fields) / T_ZBX_STR, O_OPT ... 방식 대신 규칙 문자열을 쓴다.
	 *   'string'        문자열
	 *   'in 0,1'        나열한 값만 허용
	 *   'ge 1'          1 이상 정수
	 *   'array_db hosts.hostid'   DB 컬럼 형식의 ID 배열 (멀티셀렉트용)
	 * 규칙 전체 목록: ui/include/classes/validators/CNewValidator.php
	 *
	 * 이 화면이 받는 값
	 *   page                    페이지 번호. 예) 2
	 *   filter_set / filter_rst [적용] / [초기화] 버튼을 눌렀다는 표시. 값은 1.
	 *   sort / sortorder        정렬할 열과 방향. 예) sort=os&sortorder=DESC
	 *   filter_<이름>           필터 입력값. 예) filter_os=linux, filter_agent_install=1
	 *   format                  내보내기 형식. csv 또는 xlsx. (ExportTrait 가 더해 준다)
	 *
	 * @return bool  모든 값이 규칙에 맞으면 true. 하나라도 틀리면 false 이고 오류 화면이 나온다.
	 */
	protected function checkInput(): bool {
		$fields = [
			'page' =>		'ge 1',
			'filter_set' =>	'in 1',
			'filter_rst' =>	'in 1',
			'sort' =>		'in '.implode(',', SwInventoryService::SORT_FIELDS),
			'sortorder' =>	'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN
		// 내보내기용 규칙(format)을 더한다. 배열 + 배열 = 두 배열을 합친다.
		] + self::exportInputFields();

		// 텍스트 필터: filter_itsm, filter_os, filter_web, filter_was, filter_db, filter_duplex
		foreach (array_keys(SwInventoryService::TEXT_FILTERS) as $key) {
			$fields['filter_'.$key] = 'string';
		}

		// 체크박스 필터: filter_agent_install, filter_agent_uninstall, filter_agent_exception
		foreach (array_keys(SwInventoryService::AGENT_FILTERS) as $key) {
			$fields['filter_'.$key] = 'in 0,1';
		}

		// 규칙에 맞지 않는 값이 하나라도 있으면 false. (예: sort=없는컬럼)
		$ret = $this->validateInput($fields);

		if (!$ret) {
			// 오류 화면을 응답으로 지정한다. false 를 돌려주면 doAction() 은 실행되지 않는다.
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한.
	 *
	 * 5버전은 무조건 true 를 돌려줘서 로그인만 하면 누구나 볼 수 있었다.
	 * 여기서는 코어의 "인벤토리 > 호스트" 화면 권한(사용자 역할)을 가진 사용자만 허용한다.
	 * 추가로, 모듈 자체도 사용자 역할 설정(사용자 > 사용자 역할 > 모듈 접근)에서 켜고 끌 수 있다.
	 *
	 * TODO(고객환경): 고객 권한 정책이 다르면 조건을 바꾼다.
	 *   예) 관리자 이상만: return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
	 *
	 * @return bool  볼 수 있으면 true. false 면 코어가 "접근 권한 없음" 화면을 보여 준다.
	 */
	protected function checkPermissions(): bool {
		// checkAccess(권한 이름): 로그인한 사용자의 역할에 그 권한이 켜져 있으면 true.
		// isGuest(): 로그인하지 않고 들어온 게스트 계정은 막는다.
		return $this->checkAccess(CRoleHelper::UI_INVENTORY_HOSTS) && !CWebUser::isGuest();
	}

	/**
	 * 화면을 만드는 본체. 입력 검사와 권한 검사를 통과하면 코어가 부른다.
	 *
	 * 하는 일 (아래 코드의 번호와 같다)
	 *   1) [적용]/[초기화]를 눌렀으면 필터를 저장하거나 지운다.
	 *   2) 정렬할 열과 방향을 정한다. 주소에 없으면 지난번 값을 쓴다.
	 *   3) 전체 행을 만들고 -> 필터로 거르고 -> 정렬한다.
	 *   4) 내보내기 액션(swinventory.export)이면 파일로 응답하고 끝낸다.
	 *   5) 화면 액션(swinventory.list)이면 페이지 분량만 잘라서 뷰로 넘긴다.
	 *
	 * 뷰로 넘기는 $data 의 키
	 *   action, export_action   화면 / 내보내기 액션 이름
	 *   columns                 열 정의. ['host' => '호스트', ...]   (getColumns())
	 *   filter                  필터 값. ['os' => 'linux', ..., 'agent_install' => 1, ...]
	 *   active_tab              필터 영역이 펼쳐져 있는지 (1 = 펼침)
	 *   sort, sortorder         정렬 열, 방향('ASC' / 'DESC')
	 *   agent_labels            Agent 상태 값 => 글자. [1 => '설치', 2 => '미설치', 4 => '예외']
	 *   itsm_fallback           ITSM 대신 Zabbix 호스트 목록을 썼으면 true (뷰가 안내를 띄운다)
	 *   total                   필터를 적용한 뒤의 전체 줄 수
	 *   paging                  페이지 이동 줄
	 *   rows                    현재 페이지의 행들
	 *
	 * @return void  돌려주는 값은 없다. 결과는 $this->setResponse() 로 코어에 넘긴다.
	 */
	protected function doAction(): void {
		// ── 1) 필터 저장/초기화 ─────────────────────────────────────────────
		// [적용] 버튼 -> filter_set=1, [초기화] 버튼 -> filter_rst=1 이 넘어온다.
		if ($this->hasInput('filter_set')) {
			$this->updateProfiles();
		}
		elseif ($this->hasInput('filter_rst')) {
			$this->deleteProfiles();
		}

		/*
		 * 필터 값은 URL 이 아니라 프로파일(DB)에서 읽는다.
		 * 그래서 페이지 이동, 정렬 클릭, 내보내기, 다음에 다시 접속했을 때도 같은 필터가 유지된다.
		 */
		$filter = $this->getFilter();

		// ── 2) 정렬 값 (헤더를 클릭하면 sort / sortorder 가 넘어온다. 마지막 값을 기억한다) ──
		// getInput('이름', 기본값): 요청에 값이 없으면 기본값. 여기서는 기본값이 "지난번에 저장한 정렬"이다.
		$sort_field = $this->getInput('sort', CProfile::get(self::PROFILE_PREFIX.'sort', 'host'));
		$sort_order = $this->getInput('sortorder', CProfile::get(self::PROFILE_PREFIX.'sortorder', ZBX_SORT_UP));

		CProfile::update(self::PROFILE_PREFIX.'sort', $sort_field, PROFILE_TYPE_STR);
		CProfile::update(self::PROFILE_PREFIX.'sortorder', $sort_order, PROFILE_TYPE_STR);

		// ── 3) 데이터 조회 -> 필터 -> 정렬 (로직은 includes/SwInventoryService.php) ──
		$service = new SwInventoryService();

		$rows = $service->getRows();
		$rows = $service->filter($rows, $filter);
		$rows = $service->sort($rows, $sort_field, $sort_order);

		// Agent 상태 값 => 화면에 보일 글자. [1 => '설치', 2 => '미설치', 4 => '예외']
		$agent_labels = SwInventoryService::getAgentStatusLabels();

		// ── 4) 내보내기 요청이면 여기서 끝낸다 (이 컨트롤러는 액션 2개 list, export 에 등록되어 있다) ──
		if ($this->isExportRequest()) {
			// 내보내기 행에는 화면에 보이는 글자를 넣는다. Agent 상태 값(1/2/4)을 라벨(설치/미설치/예외)로 바꾼다.
			foreach ($rows as &$row) {
				$row['agent'] = $agent_labels[$row['agent']];
			}
			// foreach 에서 &(참조)를 쓴 뒤에는 unset 으로 끊어 준다. 안 하면 뒤에서 $row 를 쓸 때 마지막 행이 바뀐다.
			unset($row);

			// 페이지 구분 없이 필터된 전체 행을 내보낸다. 파일명 예) sw_inventory_20260102_030405.xlsx
			$this->setExportResponse(Lang::t('SW inventory'), 'sw_inventory', self::getColumns(), $rows);

			return;
		}

		// ── 5) 화면 응답. 뷰에서는 $data['키'] 로 읽는다. ──
		$data = [
			'action' => 'swinventory.list',
			'export_action' => 'swinventory.export',
			'columns' => self::getColumns(),
			'filter' => $filter,
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'filter.active', 1),
			'sort' => $sort_field,
			'sortorder' => $sort_order,
			'agent_labels' => $agent_labels,
			'itsm_fallback' => $service->isItsmFallback(),
			'total' => count($rows)
		];

		/*
		 * 페이징. paginate() 가 $rows 를 현재 페이지 분량으로 잘라 준다.
		 * 주의: 화면 목록은 코어 설정 "검색/필터 결과 제한"(관리 > 일반 > GUI, 기본 1000) 까지만 표시된다.
		 *       그보다 많은 전체 목록은 내보내기로 받는다.
		 */
		$data['paging'] = CPagerHelper::paginate($this->getInput('page', 1), $rows, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', $data['action'])
		);
		$data['rows'] = $rows;

		// 응답 지정. 코어가 이 데이터를 manifest.json 에 적힌 뷰로 넘겨 실행한다.
		$response = new CControllerResponseData($data);
		$response->setTitle(Lang::t('SW inventory'));	// 브라우저 탭 제목

		$this->setResponse($response);
	}

	/**
	 * 표의 열 정의. 화면의 제목 줄과 내보내기 파일이 이 한 곳을 같이 쓴다.
	 * 열을 추가/삭제하려면 (1) 여기 (2) 뷰의 addRow (3) SwInventoryService::SORT_FIELDS 를 함께 고친다.
	 *
	 *   키   = 행 배열의 키 (정렬 키로도 쓴다)
	 *   값   = 제목. _('Host') 는 코어 번역, Lang::t() 는 모듈 번역(lang/ko.php).
	 *
	 * @return array  [행의 키 => 제목]. 예) ['host' => '호스트', 'name' => '표시명', ...]
	 */
	private static function getColumns(): array {
		return [
			'host' => _('Host'),
			'name' => Lang::t('Display name'),
			'ip' => _('IP'),
			'description' => _('Description'),
			'os' => _('OS'),
			'web' => 'WEB',
			'was' => 'WAS',
			'db' => 'DB',
			'duplex' => Lang::t('Redundancy solution'),
			'agent' => Lang::t('Agent installed')
		];
	}

	/**
	 * 프로파일에 저장된 필터 값을 읽는다.
	 * Agent 체크박스는 처음 들어왔을 때(저장된 값 없음) 3개 모두 체크된 상태다. (5버전과 동일)
	 *
	 *   CProfile::get(키, 기본값): 저장된 값이 없으면 기본값을 돌려준다.
	 *   글자 필터의 기본값은 ''(조건 없음), 체크박스의 기본값은 1(체크)이다.
	 *
	 * @return array  예) ['itsm' => '', 'os' => 'linux', 'web' => '', 'was' => '', 'db' => '', 'duplex' => '',
	 *                     'agent_install' => 1, 'agent_uninstall' => 1, 'agent_exception' => 0]
	 */
	private function getFilter(): array {
		$filter = [];

		foreach (array_keys(SwInventoryService::TEXT_FILTERS) as $key) {
			$filter[$key] = CProfile::get(self::PROFILE_PREFIX.'filter.'.$key, '');
		}

		foreach (array_keys(SwInventoryService::AGENT_FILTERS) as $key) {
			$filter[$key] = (int) CProfile::get(self::PROFILE_PREFIX.'filter.'.$key, 1);
		}

		return $filter;
	}

	/**
	 * [적용] 을 눌렀을 때 입력값을 프로파일에 저장한다.
	 * CProfile::update(키, 값, 타입): 타입은 문자열이면 PROFILE_TYPE_STR, 정수면 PROFILE_TYPE_INT.
	 *
	 * 저장되는 키 예) web.dmove.swinventory.filter.os = 'linux'
	 * 값은 로그인한 사용자별로 따로 저장된다. trim() 으로 앞뒤 빈칸을 뗀 뒤 저장한다.
	 *
	 * @return void
	 */
	private function updateProfiles(): void {
		foreach (array_keys(SwInventoryService::TEXT_FILTERS) as $key) {
			CProfile::update(self::PROFILE_PREFIX.'filter.'.$key, trim($this->getInput('filter_'.$key, '')),
				PROFILE_TYPE_STR
			);
		}

		// 체크 해제된 체크박스는 요청에 아예 안 실려 오므로 기본값 0 으로 저장한다.
		foreach (array_keys(SwInventoryService::AGENT_FILTERS) as $key) {
			CProfile::update(self::PROFILE_PREFIX.'filter.'.$key, (int) $this->getInput('filter_'.$key, 0),
				PROFILE_TYPE_INT
			);
		}
	}

	/**
	 * [초기화] 를 눌렀을 때 저장된 필터를 지운다. 지우면 getFilter() 의 기본값으로 돌아간다.
	 *
	 *   CProfile::deleteIdx(키): 그 키로 저장된 값을 지운다.
	 *
	 * @return void
	 */
	private function deleteProfiles(): void {
		// 배열 + 배열: 글자 필터 키와 체크박스 필터 키를 한 목록으로 합쳐서 한 번에 돈다.
		foreach (array_keys(SwInventoryService::TEXT_FILTERS + SwInventoryService::AGENT_FILTERS) as $key) {
			CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.'.$key);
		}
	}
}
