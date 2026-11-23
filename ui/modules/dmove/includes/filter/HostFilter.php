<?php declare(strict_types = 0);
/*
 * ★ 호스트 그룹 / 호스트 선택 필터 플러그인 ★  컨트롤러와 뷰에 "꽂아서" 쓴다.
 *
 * 5버전 보고서 화면 대부분의 필터 맨 위에 있던 두 줄이다.
 *     호스트 그룹  [ 검색 문자열을 입력          ] [선택]
 *     호스트       [ 검색 문자열을 입력          ] [선택]
 *
 * 화면마다 같은 코드를 반복하지 않도록 한 곳에 모았다. 붙이는 방법은 아래 5곳이다.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ 컨트롤러                                                                │
 * │                                                                         │
 * │ ① checkInput() : 입력 규칙에 더한다                                     │
 * │     $fields = [ ... ] + HostFilter::INPUT_FIELDS;                       │
 * │                                                                         │
 * │ ② doAction() : [적용] / [리셋] 처리                                     │
 * │     if ($this->hasInput('filter_set')) {                                │
 * │         HostFilter::save(self::PROFILE_PREFIX,                          │
 * │             $this->getInput('filter_groupids', []),                     │
 * │             $this->getInput('filter_hostids', [])                       │
 * │         );                                                              │
 * │     }                                                                   │
 * │     elseif ($this->hasInput('filter_rst')) {                            │
 * │         HostFilter::reset(self::PROFILE_PREFIX);                        │
 * │     }                                                                   │
 * │                                                                         │
 * │ ③ doAction() : 저장된 값 읽기 -> 데이터 조회에 사용                     │
 * │     $host_filter = HostFilter::load(self::PROFILE_PREFIX);              │
 * │     // $host_filter['groupids'], $host_filter['hostids']  (ID 배열)     │
 * │     // 조건은 API 호출 "앞"에서 변수에 담는다. (이유: toApiOptions 설명)│
 * │     $host_options = HostFilter::toApiOptions($host_filter);             │
 * │     $hosts = API::Host()->get($host_options                             │
 * │         + ['output' => ['hostid', 'host', 'name']]);                    │
 * │                                                                         │
 * │ ④ doAction() : 뷰로 넘길 값                                             │
 * │     'host_filter' => HostFilter::toViewData($host_filter)               │
 * │                                                                         │
 * │ 뷰                                                                      │
 * │                                                                         │
 * │ ⑤ 필터 폼에 두 줄을 추가한다                                            │
 * │     $form = HostFilter::addRows(new CFormList(), $data['host_filter']); │
 * │     $form->addRow('다른 필터', ...);     // 이어서 화면 고유 필터       │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * 사용 예: actions/reports/uptime/UptimeList.php, views/reports/uptime.list.php
 *
 * ── 지금 이 필터를 쓰는 화면 ──
 *   서버 시간 확인(uptime), 저사용/고사용 서버(serverusage), 윈도우즈 패치 현황(winpatch),
 *   usageexport 화면의 컨트롤러와 뷰. 그리고 includes/WinPatchService.php.
 *
 * ── 데이터 흐름 ──
 *   [적용] 클릭 -> 주소에 filter_groupids[], filter_hostids[] 가 온다
 *     -> save()         사용자 프로파일에 저장 (다음에 화면을 열어도 남아 있다)
 *     -> load()         저장된 ID 를 읽는다               ['groupids' => [...], 'hostids' => [...]]
 *     -> toApiOptions() API 조건으로 바꾼다 (데이터 조회용)
 *     -> toViewData()   ID 에 이름을 붙인다 (선택창 표시용)
 *     -> addRows()      뷰가 필터 폼에 두 줄을 그린다
 */

namespace Modules\DMove\Includes\Filter;

use API;
use CArrayHelper;
use CFormList;
use CLabel;
use CMultiSelect;
use CProfile;

class HostFilter {

	/**
	 * 입력 규칙. 선택창(멀티셀렉트)은 ID 배열로 넘어온다. 'array_id' = 숫자 ID 의 배열.
	 */
	public const INPUT_FIELDS = [
		'filter_groupids' =>	'array_id',
		'filter_hostids' =>		'array_id'
	];

	/**
	 * 선택 값을 사용자 프로파일에 저장한다.
	 *
	 * 프로파일은 "사용자별 화면 설정 저장소"다. 여기에 넣어 두면 페이지를 넘기거나 다시 로그인해도 필터가 유지된다.
	 * 저장 이름은 접두어 + 'filter.groupids' / 'filter.hostids' 다. 예) 'web.dmove.uptime.filter.groupids'
	 *
	 * @param string $profile_prefix  화면의 프로파일 접두어. 예) 'web.dmove.uptime.'
	 * @param array  $groupids        선택한 호스트 그룹 ID. 예) ['2', '4']  선택하지 않았으면 [].
	 * @param array  $hostids         선택한 호스트 ID. 예) ['10084']  선택하지 않았으면 [].
	 */
	public static function save(string $profile_prefix, array $groupids, array $hostids): void {
		// 배열 값은 updateArray 로 저장한다. PROFILE_TYPE_ID = ID 값.
		CProfile::updateArray($profile_prefix.'filter.groupids', $groupids, PROFILE_TYPE_ID);
		CProfile::updateArray($profile_prefix.'filter.hostids', $hostids, PROFILE_TYPE_ID);
	}

	/**
	 * 저장된 선택 값을 지운다. ([리셋] 버튼)
	 *
	 * 지운 뒤에 load() 를 부르면 두 값 모두 빈 배열이 나온다. (= 조건 없음)
	 *
	 * @param string $profile_prefix  화면의 프로파일 접두어. 예) 'web.dmove.uptime.'
	 */
	public static function reset(string $profile_prefix): void {
		CProfile::deleteIdx($profile_prefix.'filter.groupids');
		CProfile::deleteIdx($profile_prefix.'filter.hostids');
	}

	/**
	 * 저장된 선택 값을 읽는다.
	 *
	 * @param string $profile_prefix  화면의 프로파일 접두어. 예) 'web.dmove.uptime.'
	 *
	 * @return array  ['groupids' => [ID, ...], 'hostids' => [ID, ...]]  선택하지 않았으면 빈 배열.
	 */
	public static function load(string $profile_prefix): array {
		return [
			'groupids' => CProfile::getArray($profile_prefix.'filter.groupids', []),
			'hostids' => CProfile::getArray($profile_prefix.'filter.hostids', [])
		];
	}

	/**
	 * host.get 등 API 호출에 그대로 합칠 수 있는 조건을 만든다.
	 *
	 *   - 아무것도 선택하지 않으면 조건이 없다(= 사용자가 볼 수 있는 전체 호스트).
	 *   - 그룹을 선택하면 그 하위 그룹(예: "서버" 를 고르면 "서버/운영")의 호스트도 포함한다. 코어 화면과 같은 동작이다.
	 *
	 * 사용 예: (배열 + 배열 로 다른 옵션과 합친다)
	 *   $host_options = HostFilter::toApiOptions($host_filter);
	 *   $hosts = API::Host()->get($host_options + ['output' => ['hostid', 'name']]);
	 *
	 * ★ 주의: 반드시 위처럼 API 호출 "앞"에서 변수에 담아 둔다. 아래처럼 괄호 안에 직접 쓰면 안 된다.
	 *     API::Host()->get(HostFilter::toApiOptions($host_filter) + [...]);   // 잘못된 예
	 *   코어의 API::Host(), API::Item() 등은 같은 객체 하나를 돌려쓰면서 "지금 부를 API 이름"만 바꾼다.
	 *   괄호 안의 이 메소드가 호스트 그룹을 조회하면(getSubGroups -> API::HostGroup()) 그 이름이 hostgroup 으로 바뀌어,
	 *   바깥의 get() 이 hostgroup.get 으로 실행된다. 호스트 그룹 필터를 골랐을 때만 오류가 난다.
	 *
	 * @param array $host_filter  load() 의 결과. ['groupids' => [...], 'hostids' => [...]]
	 *
	 * @return array  ['groupids' => [...]|null, 'hostids' => [...]|null]
	 */
	public static function toApiOptions(array $host_filter): array {
		return [
			// getSubGroups(): 코어 함수. 선택한 그룹 + 그 하위 그룹의 ID 를 돌려준다. (ui/include/hostgroups.inc.php)
			// API 에 null 을 주면 그 조건은 없는 것으로 처리된다. 빈 배열을 주면 "결과 없음"이 되므로 주의.
			'groupids' => $host_filter['groupids'] ? getSubGroups($host_filter['groupids']) : null,
			// a ?: b : a 가 비어 있으면(빈 배열 등) b 를 쓴다.
			'hostids' => $host_filter['hostids'] ?: null
		];
	}

	/**
	 * 뷰의 선택창에 표시할 값을 만든다. 선택창은 ID 만으로는 이름을 보여 줄 수 없어서
	 * [['id' => .., 'name' => ..], ...] 형태가 필요하다.
	 *
	 * 사용자가 볼 권한이 없는 그룹/호스트는 API 가 돌려주지 않으므로 자동으로 빠진다.
	 *
	 * @param array $host_filter  load() 의 결과. ['groupids' => [...], 'hostids' => [...]]
	 *
	 * @return array  ['groups' => [...], 'hosts' => [...]]
	 *                예) ['groups' => [['id' => '2', 'name' => 'Linux servers']], 'hosts' => []]
	 */
	public static function toViewData(array $host_filter): array {
		$groups = [];
		$hosts = [];

		if ($host_filter['groupids']) {
			// renameObjectsKeys: 각 행의 키 이름을 바꾼다. groupid -> id
			$groups = CArrayHelper::renameObjectsKeys(API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $host_filter['groupids']
			]), ['groupid' => 'id']);
		}

		if ($host_filter['hostids']) {
			$hosts = CArrayHelper::renameObjectsKeys(API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => $host_filter['hostids']
			]), ['hostid' => 'id']);
		}

		return ['groups' => $groups, 'hosts' => $hosts];
	}

	/**
	 * 필터 폼에 "호스트 그룹", "호스트" 두 줄을 추가한다. (뷰에서 호출)
	 *
	 * @param CFormList $form       줄을 추가할 폼.
	 * @param array     $view_data  toViewData() 의 결과. (컨트롤러가 $data['host_filter'] 로 넘긴 값)
	 *
	 * @return CFormList  같은 폼. 이어서 ->addRow(...) 를 붙일 수 있다.
	 */
	public static function addRows(CFormList $form, array $view_data): CFormList {
		/*
		 * 선택창(멀티셀렉트). [선택] 버튼을 누르면 코어의 팝업이 떠서 고를 수 있고, 글자를 입력하면 자동완성이 된다.
		 *   name         : 폼으로 넘어가는 이름. 여러 개라서 끝에 [] 를 붙인다.
		 *   object_name  : 자동완성 대상 종류. 호스트 그룹은 'hostGroup', 호스트는 'hosts'.
		 *   data         : 이미 선택된 값. [['id' => .., 'name' => ..], ...]
		 *   popup        : [선택] 팝업 설정. srctbl 이 팝업 종류, dstfrm 은 필터 폼 이름(항상 'zbx_filter'),
		 *                  dstfld1 은 name 에서 [] 를 _ 로 바꾼 값이다.
		 * 라벨의 두 번째 인자('filter_groupids__ms')는 name 에서 [] 를 뺀 것 + '__ms' 이다. 라벨을 누르면 입력창에 커서가 간다.
		 *
		 * 호스트 그룹 팝업에만 있는 옵션:
		 *   with_hosts           : 호스트가 들어 있는 그룹만 보여 준다.
		 *   enrich_parent_groups : 하위 그룹에 호스트가 있으면 그 상위 그룹도 목록에 넣는다.
		 * _('Host groups'), _('Hosts') 는 코어에 있는 문구라 코어 번역 함수 _() 를 쓴다.
		 */
		return $form
			->addRow(new CLabel(_('Host groups'), 'filter_groupids__ms'),
				(new CMultiSelect([
					'multiple' => true,
					'name' => 'filter_groupids[]',
					'object_name' => 'hostGroup',
					'data' => $view_data['groups'],
					'popup' => [
						'parameters' => [
							'srctbl' => 'host_groups',
							'srcfld1' => 'groupid',
							'dstfrm' => 'zbx_filter',
							'dstfld1' => 'filter_groupids_',
							'with_hosts' => true,
							'enrich_parent_groups' => true
						]
					]
				]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
			)
			->addRow(new CLabel(_('Hosts'), 'filter_hostids__ms'),
				(new CMultiSelect([
					'multiple' => true,
					'name' => 'filter_hostids[]',
					'object_name' => 'hosts',
					'data' => $view_data['hosts'],
					'popup' => [
						'parameters' => [
							'srctbl' => 'hosts',
							'srcfld1' => 'hostid',
							'dstfrm' => 'zbx_filter',
							'dstfld1' => 'filter_hostids_'
						]
					]
				]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
			);
	}
}
