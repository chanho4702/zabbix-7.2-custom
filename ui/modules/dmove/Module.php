<?php declare(strict_types = 0);
/*
 * DMove 커스텀 모듈 진입점.
 *
 * ── 이 파일이 하는 일 ──
 *   Zabbix 가 모듈을 읽어 들일 때 가장 먼저 실행하는 파일이다.
 *   하는 일은 하나다. 왼쪽 사이드바에 이 모듈의 메뉴(SW 인벤토리, 보고서 9개, 장기 미접속 계정 현황)를 붙인다.
 *   메뉴를 누른 뒤 열리는 화면은 이 파일이 아니라 actions/ 의 컨트롤러와 views/ 의 뷰가 만든다.
 *
 * ── 처리 흐름 ──
 *   화면 요청이 들어온다
 *     ├─ 코어가 manifest.json 을 읽는다 (모듈 이름, 액션 목록, CSS)
 *     ├─ 코어가 이 클래스의 init() 을 부른다  ->  메뉴가 붙는다
 *     └─ 주소의 action 값에 맞는 컨트롤러를 manifest.json 에서 찾아 실행한다
 *
 * ── 관련 파일 ──
 *   manifest.json        모듈 등록 정보. 액션 이름 -> 컨트롤러 클래스 / 뷰 / 레이아웃
 *   Module.php           메뉴 등록 (이 파일)
 *   lang/ko.php          메뉴 라벨의 한국어 번역
 *   includes/Lang.php    번역 함수 Lang::t()
 *   actions/             화면별 컨트롤러
 *   views/               화면별 뷰
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ [사이드바 메뉴는 이 파일에서 추가한다]                                  │
 * │                                                                         │
 * │ 5버전에서는 patch.sh 로 include/menu.inc.php, CMenu.php 를 덮어썼지만,  │
 * │ 7버전에서는 본 소스를 건드리지 않고 아래 init() 안에서 메뉴를 붙인다.   │
 * │ init() 은 모듈이 "활성화"되어 있으면 모든 화면 요청마다 1회 호출된다.   │
 * │                                                                         │
 * │ 메뉴를 하나 추가하려면 아래 REPORT_MENUS 같은 표에 한 줄만 넣으면 된다. │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * 네임스페이스 규칙 (틀리면 "Wrong Module.php class name" 오류로 모듈이 안 뜬다)
 *   - 폴더가 ui/modules/dmove 이고 manifest.json 의 "namespace" 가 "DMove" 이면
 *     이 파일의 네임스페이스는 반드시 Modules\DMove 이다.
 *   - 하위 폴더는 소문자, 네임스페이스는 첫 글자 대문자로 대응된다.
 *       ui/modules/dmove/actions/reports/uptime/UptimeList.php -> Modules\DMove\Actions\Reports\Uptime\UptimeList
 *       ui/modules/dmove/includes/export/CsvWriter.php -> Modules\DMove\Includes\Export\CsvWriter
 */

namespace Modules\DMove;

/*
 * 이 파일에서 쓰는 코어 클래스.
 *   APP                  프론트엔드 전체를 대표하는 객체.      ui/include/classes/core/APP.php (부모: ZBase.php)
 *   CMenuItem            메뉴 한 칸.                           ui/include/classes/html/CMenuItem.php
 *   Lang                 모듈 문구 다국어(영어/한국어) 처리.   includes/Lang.php
 *   Zabbix\Core\CModule  모든 모듈의 부모 클래스.              ui/include/classes/core/CModule.php
 */
use APP;
use CMenuItem;
use CRoleHelper;
use CUrl;
use CWebUser;
use Modules\DMove\Includes\Lang;
use Zabbix\Core\CModule;

/*
 * 클래스 이름은 반드시 Module, 부모는 반드시 CModule 이어야 한다.
 *
 * 부모 클래스에서 재정의할 수 있는 메소드 (필요한 것만 만든다):
 *   init()                       모듈이 로드될 때 1회. 메뉴 등록은 여기서 한다.
 *   onBeforeAction(CAction $a)   어떤 화면이든 컨트롤러가 실행되기 직전에 호출된다.
 *   onTerminate(CAction $a)      요청 처리가 끝날 때 호출된다.
 */
class Module extends CModule {

	/**
	 * 인벤토리(Inventory) 아래에 추가할 메뉴.
	 *
	 * 형식: '메뉴 라벨(영어)' => ['대표 액션', [같은 메뉴로 표시할 다른 액션들]]
	 *   - 메뉴 라벨: 영어로 적는다. 한국어 사용자에게는 lang/ko.php 의 번역이 표시된다. (includes/Lang.php)
	 *               새 메뉴를 넣으면 lang/ko.php 에도 '영어 라벨' => '한국어 라벨' 한 줄을 추가한다.
	 *   - 대표 액션: 메뉴를 클릭하면 zabbix.php?action=<대표 액션> 으로 이동한다.
	 *               manifest.json 의 "actions" 키와 글자 하나까지 같아야 한다.
	 *   - 다른 액션: 그 화면에 딸린 액션(내보내기 등). 이 액션이 실행 중일 때도 메뉴가 선택된 것으로 표시된다.
	 *
	 * 예) 아래 한 줄은 "인벤토리 아래에 'SW 인벤토리' 메뉴를 만들고, 누르면 swinventory.list 화면으로 간다"는 뜻이다.
	 */
	private const INVENTORY_MENUS = [
		'SW inventory' => ['swinventory.list', ['swinventory.export']]
	];

	/**
	 * 보고서(Reports) 아래에 추가할 메뉴. 적은 순서대로 코어 메뉴 뒤에 붙는다. (5버전 화면의 순서와 같다)
	 * 형식은 위의 INVENTORY_MENUS 와 같다. 두 번째 칸이 [] 인 줄은 딸린 액션(내보내기)이 없는 화면이다.
	 */
	private const REPORT_MENUS = [
		'Server time check' =>				['uptime.list', []],
		'Low usage servers' =>				['lowserver.list', ['lowserver.export']],
		'High usage servers' =>				['highserver.list', ['highserver.export']],
		'Performance trend analysis' =>		['perftrend.list', ['perftrend.export']],
		'Daily check' =>					['dailycheck.list', []],
		'Peak event analysis' =>			['peakevent.list', ['peakevent.export']],
		'Windows patch status' =>			['winpatch.list', ['winpatch.export']],
		'CPU/Memory usage export' =>		['usageexport.list', ['usageexport.export']]
	];

	/**
	 * 유저(Users) 아래에 추가할 메뉴.
	 *
	 * 5버전에서는 "관리 > 유저" 아래에 있었다. 7버전은 유저 관련 메뉴가 1단계 메뉴 "유저(Users)" 로 분리되었으므로
	 * 그 아래 "유저(Users)" 항목 바로 뒤에 넣는다.
	 */
	private const USER_MENUS = [
		'Long-term inactive accounts' => ['userunconnect.list', []]
	];

	/**
	 * 시간 선택기를 쓰는 화면의 기간 저장 이름(프로파일 키). 화면 뷰의 CFilter::setProfile() 값과 같다.
	 */
	private const TIME_SELECTOR_PROFILES = [
		'web.dmove.peakevent.filter',
		'web.dmove.usageexport.filter',
		'web.dmove.perftrend.filter'
	];

	/**
	 * 이 모듈 화면의 시간 선택기를 코어에 등록한다.
	 *
	 * 시간 선택기에서 "어제", "지난주" 같은 기간을 누르거나 < > 로 옮기면, 브라우저가 코어 액션
	 * timeselector.update 를 불러 새 기간을 저장한다. 그런데 이 코어 액션은 미리 정한 이름(대시보드, 장애 화면 등)만
	 * 받고 나머지는 거부한다. 그래서 이 모듈 화면의 이름을 그 목록에 더해 준다.
	 * 이 등록이 없으면 기간 버튼을 눌러도 화면이 바뀌지 않는다. (주소에 from/to 를 직접 넣으면 동작한다)
	 *
	 *   CControllerTimeSelectorUpdate::$profiles : 코어가 받아 주는 이름 목록. public static 이라 더할 수 있다.
	 *   (ui/app/controllers/CControllerTimeSelectorUpdate.php)
	 *
	 * init() 은 요청마다 불리므로 같은 이름이 두 번 들어가지 않게 확인한다.
	 */
	private static function registerTimeSelectors(): void {
		foreach (self::TIME_SELECTOR_PROFILES as $profile) {
			if (!in_array($profile, \CControllerTimeSelectorUpdate::$profiles, true)) {
				\CControllerTimeSelectorUpdate::$profiles[] = $profile;
			}
		}
	}

	/**
	 * 사이드바에 이 모듈의 메뉴를 붙인다. 모듈이 켜져 있으면 코어가 화면 요청마다 한 번 부른다.
	 *
	 * 하는 일
	 *   1) 사이드바 전체 메뉴 객체를 가져온다.
	 *   2) 인벤토리 / 보고서 / 유저 세 곳을 차례로 돌면서
	 *   3) 위의 표(INVENTORY_MENUS, REPORT_MENUS, USER_MENUS)에 적힌 메뉴를 하나씩 붙인다.
	 *      사용자에게 그 1단계 메뉴가 없으면(역할에서 꺼 둔 경우) 건너뛴다.
	 *
	 * 받는 값은 없다. 메뉴 객체를 직접 바꾸는 것이 결과다.
	 *
	 * 반환 타입 ": void" 는 "돌려주는 값이 없다"는 뜻이다. 부모와 똑같이 적어야 한다.
	 *
	 * @return void
	 */
	public function init(): void {
		self::registerTimeSelectors();

		/*
		 * ── 메뉴가 만들어지는 원리 ──────────────────────────────────────────
		 *
		 * 1) APP::Component()->get('menu.main')  : 왼쪽 사이드바 전체 메뉴 객체(CMenu).
		 * 2) ->findOrAdd(_('Reports'))            : 1단계 메뉴 "보고서"를 찾는다. 없으면 새로 만든다.
		 *                                           _('Reports') 는 사용자 언어로 번역된 라벨이다.
		 *                                           코어에 있는 1단계 메뉴 영문 라벨:
		 *                                           Dashboards, Monitoring, Services, Inventory, Reports,
		 *                                           Data collection, Alerts, Users, Administration
		 * 3) ->getSubMenu()                       : 그 아래 2단계 메뉴 목록.
		 * 4) ->add( new CMenuItem('라벨') )       : 맨 끝에 추가.
		 *    ->insertAfter(_('Users'), ...)        : 특정 메뉴 "뒤"에 끼워 넣을 때.
		 *    ->insertBefore(_('Overview'), ...)    : 특정 메뉴 "앞"에 끼워 넣을 때.
		 *
		 * 코어의 메뉴 원본 정의는 ui/include/classes/helpers/CMenuHelper.php 에서 볼 수 있다. (읽기만 할 것)
		 *
		 * 사용자 역할(유저 > 유저 역할)에서 해당 1단계 메뉴를 전부 꺼 둔 사용자는 그 메뉴 자체가 없다.
		 * 그런 사용자에게 findOrAdd 가 빈 1단계 메뉴를 새로 만들어 버리지 않도록 find() 로 먼저 확인한다.
		 */
		$main_menu = APP::Component()->get('menu.main');

		$sections = [
			// [1단계 메뉴 라벨, 추가할 메뉴 표, 이 코어 메뉴 뒤에 끼워 넣기(null 이면 맨 끝)]
			[_('Inventory'), self::INVENTORY_MENUS, null],
			[_('Reports'), self::REPORT_MENUS, null],
			[_('Users'), self::USER_MENUS, _('Users')]
		];

		// foreach (... as [$a, $b, $c]): 배열의 각 줄을 꺼내면서 세 칸을 변수 세 개에 바로 나눠 담는 문법이다.
		foreach ($sections as [$section_label, $menus, $insert_after]) {
			// find(라벨): 그 라벨의 메뉴를 찾는다. 없으면 null.
			$section = $main_menu->find($section_label);

			// 이 사용자에게는 그 1단계 메뉴가 없다. 다음 구역으로 넘어간다.
			if ($section === null) {
				continue;
			}

			$submenu = $section->getSubMenu();

			// 여러 개를 같은 위치 뒤에 순서대로 넣으려면 뒤에서부터 넣어야 표의 순서가 유지된다.
			// array_reverse(배열, true): 순서를 뒤집는다. true 는 키(메뉴 라벨)를 그대로 두라는 뜻.
			$entries = $insert_after !== null ? array_reverse($menus, true) : $menus;

			// $label = 메뉴 라벨(영어), $action = 대표 액션, $aliases = 같은 메뉴로 표시할 다른 액션들.
			foreach ($entries as $label => [$action, $aliases]) {
				/*
				 * 유저 메뉴(장기 미접속 계정)는 "유저 > 유저" 화면 권한이 있을 때만 넣는다. 컨트롤러도 같은 권한을 본다.
				 * 권한 없이 메뉴만 보이면 눌렀을 때 "접근 거부"가 난다.
				 */
				if ($menus === self::USER_MENUS && !CWebUser::checkAccess(CRoleHelper::UI_ADMINISTRATION_USERS)) {
					continue;
				}

				// Lang::t(): 사용자 언어가 한국어면 한국어 라벨로 바꾼다.
				$item = (new CMenuItem(Lang::t($label)))
					->setAction($action)
					->setAliases($aliases);

				// 끼워 넣을 기준 메뉴가 실제로 있을 때만 그 뒤에 넣는다. 없으면 맨 끝에 붙인다.
				if ($insert_after !== null && $submenu->find($insert_after) !== null) {
					$submenu->insertAfter($insert_after, $item);
				}
				else {
					$submenu->add($item);
				}
			}
		}

		// 사이드바 아래쪽의 "Agent 설치파일", "사용자메뉴얼" 링크. (5버전과 같게. 설정: conf/sidebar.conf.php)
		self::addSidebarLinks();

		/*
		 * 새 1단계 메뉴를 만들어 그 아래에 넣고 싶을 때:
		 *
		 *   APP::Component()->get('menu.main')
		 *       ->findOrAdd('DMove')              // 없으면 자동 생성
		 *       ->setIcon(ZBX_ICON_REPORTS)       // 1단계 메뉴는 아이콘 필요
		 *       ->getSubMenu()
		 *       ->add((new CMenuItem('새 화면'))->setAction('xxx.list'));
		 */
	}

	/**
	 * 사이드바 아래쪽 메뉴에 5버전의 "Agent 설치파일", "사용자메뉴얼" 링크를 넣고, 5버전처럼 코어의 Support,
	 * Integrations, Help 를 뺀다. 값은 conf/sidebar.conf.php 에 있다.
	 *
	 *   APP::Component()->get('menu.user') : 사이드바 아래쪽 메뉴 객체(CMenu). 코어가 모듈 init() 전에 만들어 둔다.
	 *   remove(라벨) / find(라벨)            : 라벨은 사용자 언어로 번역된 글자라서 _('Support') 처럼 번역해서 찾는다.
	 *   insertBefore(_('User settings'), ..) : "사용자 설정" 바로 위에 넣는다. 게스트는 이 메뉴가 없어 맨 끝에 붙인다.
	 */
	private static function addSidebarLinks(): void {
		$file = __DIR__.'/conf/sidebar.conf.php';
		$config = is_readable($file) ? require $file : [];

		if (!is_array($config)) {
			return;
		}

		$user_menu = APP::Component()->get('menu.user');

		foreach ((array) ($config['remove_core'] ?? []) as $label) {
			$user_menu->remove(_((string) $label));
		}

		foreach ((array) ($config['links'] ?? []) as $link) {
			if (!is_array($link) || ($link['label'] ?? '') === '' || ($link['url'] ?? '') === '') {
				continue;
			}

			$item = (new CMenuItem((string) $link['label']))
				->setUrl(new CUrl((string) $link['url']))
				->setTarget('_blank');

			// 'ZBX_ICON_SUPPORT' 같은 상수 이름을 값으로 바꾼다. 없는 이름이면 아이콘 없이 둔다.
			$icon = (string) ($link['icon'] ?? '');

			if ($icon !== '' && defined($icon)) {
				$item->setIcon(constant($icon));
			}

			if ($user_menu->find(_('User settings')) !== null) {
				$user_menu->insertBefore(_('User settings'), $item);
			}
			else {
				$user_menu->add($item);
			}
		}
	}
}
