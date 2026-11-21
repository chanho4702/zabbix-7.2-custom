<?php declare(strict_types = 0);
/*
 * DMove 커스텀 모듈 진입점.
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
	 */
	private const INVENTORY_MENUS = [
		'SW inventory' => ['swinventory.list', ['swinventory.export']]
	];

	/**
	 * 보고서(Reports) 아래에 추가할 메뉴. 적은 순서대로 코어 메뉴 뒤에 붙는다. (5버전 화면의 순서와 같다)
	 */
	private const REPORT_MENUS = [
		'Server time check' =>				['uptime.list', []],
		'Low usage servers' =>				['lowserver.list', ['lowserver.export']],
		'High usage servers' =>				['highserver.list', ['highserver.export']],
		'Performance trend analysis' =>		['perftrend.list', []],
		'Daily check' =>					['dailycheck.list', []],
		'Peak event analysis' =>			['peakevent.list', ['peakevent.export']],
		'Windows patch status' =>			['winpatch.list', ['winpatch.export']],
		'CPU/Memory usage export' =>		['usageexport.list', []],
		// TODO(고객환경): 5버전에서 이 화면이 있던 메뉴 위치를 확인한다. (확인 전이라 보고서 맨 끝에 두었다)
		'Box usage' =>						['hostbox.list', ['hostbox.export']]
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
	 * 반환 타입 ": void" 는 "돌려주는 값이 없다"는 뜻이다. 부모와 똑같이 적어야 한다.
	 */
	public function init(): void {
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

		foreach ($sections as [$section_label, $menus, $insert_after]) {
			$section = $main_menu->find($section_label);

			if ($section === null) {
				continue;
			}

			$submenu = $section->getSubMenu();

			// 여러 개를 같은 위치 뒤에 순서대로 넣으려면 뒤에서부터 넣어야 표의 순서가 유지된다.
			$entries = $insert_after !== null ? array_reverse($menus, true) : $menus;

			foreach ($entries as $label => [$action, $aliases]) {
				// Lang::t(): 사용자 언어가 한국어면 한국어 라벨로 바꾼다.
				$item = (new CMenuItem(Lang::t($label)))
					->setAction($action)
					->setAliases($aliases);

				if ($insert_after !== null && $submenu->find($insert_after) !== null) {
					$submenu->insertAfter($insert_after, $item);
				}
				else {
					$submenu->add($item);
				}
			}
		}

		/*
		 * TODO(고객환경): 5버전 사이드바 맨 아래에 있던 "Agent 설치파일", "사용자메뉴얼" 링크.
		 * 링크 대상(파일 경로/URL)을 확인한 뒤 아래처럼 추가한다. 'menu.user' 가 사이드바 아래쪽 메뉴다.
		 *
		 *   APP::Component()->get('menu.user')
		 *       ->insertBefore(_('User settings'),
		 *           (new CMenuItem('사용자메뉴얼'))
		 *               ->setIcon(ZBX_ICON_HELP_CIRCLED)
		 *               ->setUrl(new \CUrl('확인한 주소'))
		 *               ->setTarget('_blank')
		 *       );
		 *
		 * 새 1단계 메뉴를 만들어 그 아래에 넣고 싶을 때:
		 *
		 *   APP::Component()->get('menu.main')
		 *       ->findOrAdd('DMove')              // 없으면 자동 생성
		 *       ->setIcon(ZBX_ICON_REPORTS)       // 1단계 메뉴는 아이콘 필요
		 *       ->getSubMenu()
		 *       ->add((new CMenuItem('새 화면'))->setAction('xxx.list'));
		 */
	}
}
