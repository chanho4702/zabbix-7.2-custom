<?php declare(strict_types = 0);
/*
 * SW 인벤토리 화면 + 내보내기(CSV / Excel) 컨트롤러.
 *
 *   화면 : zabbix.php?action=swinventory.list
 *   내보내기 : zabbix.php?action=swinventory.export&format=csv 또는 xlsx
 *
 * 5버전 대응: 커스텀 폴더의 controllers/CControllerSwinventories.php, CControllerSWInventCSV
 *
 * 새 페이지를 추가하는 방법은 ui/modules/dmove/template/README.md 에 있다. (복사해서 쓰는 템플릿 포함)
 *
 * 이 화면에 속한 파일
 *   actions/inventory/swinventory/SwInventoryList.php   컨트롤러 (이 파일. 화면과 내보내기 두 액션이 같이 쓴다)
 *   views/inventory/swinventory.list.php          화면 뷰
 *   includes/export/                          내보내기 플러그인 (사용법: includes/export/ExportTrait.php)
 *   includes/SwInventoryService.php           데이터 조회/매칭/필터/정렬
 *   includes/ItsmRepository.php               ITSM 목록 조회 (설정: conf/itsm.conf.php)
 *   includes/SoftwareResolver.php             OS/WEB/WAS/DB/이중화 값 결정 (필드 매핑: INVENTORY_FIELDS 상수)
 *
 * 컨트롤러는 코어가 아래 순서로 호출한다. (ui/include/classes/mvc/CController.php)
 *   init() -> checkInput() -> checkPermissions() -> doAction()
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
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정은 막는다.
		return $this->checkAccess(CRoleHelper::UI_INVENTORY_HOSTS) && !CWebUser::isGuest();
	}

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
	 */
	private function deleteProfiles(): void {
		foreach (array_keys(SwInventoryService::TEXT_FILTERS + SwInventoryService::AGENT_FILTERS) as $key) {
			CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.'.$key);
		}
	}
}
