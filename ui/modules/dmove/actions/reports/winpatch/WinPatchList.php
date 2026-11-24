<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 현황 화면 컨트롤러.
 *
 * ── 이 화면이 하는 일 ──
 *   인벤토리 OS 가 windows 인 서버를 목록으로 보여 준다.
 *   기간 안에 수집된 패치 목록이 있으면 "상세보기"가 나오고, 누르면 그 서버에 설치된 패치 목록이 팝업으로 뜬다.
 *   기간은 수집월선택에서 고른 날짜부터 그달 말일까지다.
 *   HotFix ID 를 입력하면, 기간 안의 패치 목록에 그 글자가 들어 있는 서버만 남는다.
 *
 *   화면     : zabbix.php?action=winpatch.list
 *   내보내기 : zabbix.php?action=winpatch.export&format=csv 또는 xlsx
 *   상세보기 : zabbix.php?action=winpatch.detail  (팝업. actions/reports/winpatch/WinPatchDetail.php)
 *   메뉴 : 보고서 > 윈도우즈 패치 현황   (Module.php 의 REPORT_MENUS)
 *   5버전: zabbix.php?action=windows   (화면 제목 "Windows Patch현황")
 *
 * 5버전 화면 구성
 *   필터 : 호스트 그룹(선택창), 호스트(선택창), HotFix ID(입력), 수집월선택(날짜)
 *   버튼 : CSV 내보내기
 *   표   : 호스트(정렬), IP, description, Patch File명
 *          Patch File명 칸은 모든 줄이 "상세보기" 링크이고, 누르면 "Windows 패치 상세정보" 팝업이 뜬다.
 *   팝업 : Caption, CSName, Description, FixComments, HotFixID, InstallDate, InstalledBy, InstalledOn,
 *          Name, ServicePackInEffect   (윈도우의 설치된 업데이트 목록과 같은 항목이다)
 *
 * 데이터가 오는 곳과 처리 방법은 includes/WinPatchService.php 머리말에 있다.
 *
 * ── 데이터 흐름 ──
 *   doAction()
 *     ├─ 필터 저장/읽기 (CProfile, HostFilter)
 *     ├─ WinPatchService::getRows()
 *     │    ├─ host.get : 필터에 맞는 호스트 중 인벤토리 OS 가 windows 인 것 (이름, IP)
 *     │    ├─ item.get : 그 호스트의 패치 목록 아이템
 *     │    ├─ history.get : 기간 안의 값. "상세보기"/"없음" 을 정하고, HotFix ID 를 입력했으면 글자가 들어 있는 호스트만 남긴다
 *     │    └─ ITSM 설명 (ITSM 값만 쓴다)
 *     ├─ 정렬
 *     ├─ 액션이 winpatch.export 면 -> 전체 행을 CSV / Excel 파일로 내려 주고 끝
 *     └─ 아니면 -> 페이징한 뒤 뷰(views/reports/winpatch.list.php)로 넘긴다
 *
 * 5버전과 달라진 점
 *   - 상세보기 표에서 빈 열이 있어도 값이 제 열에 들어간다. (5버전은 뒤의 값이 앞으로 당겨졌다)
 *   - "없음" 의 안내 문구를 둘로 나눴다. 패치 아이템이 없는 경우와, 아이템은 있지만 기간 안에 값이 없는 경우.
 *     (5버전은 둘 다 "item이 없습니다" 였다)
 *   - 기간 끝을 그달 말일 23:59:59 까지 넣는다. (5버전은 "<" 로 비교해서 마지막 1초가 빠졌다)
 *   - 내보내기는 CSV 만 (5버전과 같다).
 *
 * TODO(고객환경)
 *   - conf/winpatch.conf.php 에 아이템 키를 채운다. (5버전 소스의 값을 넣어 두었다. 고객 서버에서 같은지 확인한다)
 *   - 수집월선택의 기본값(지난달 1일)은 5버전 CControllerWindows.php 와 같다.
 *     HotFix ID 필터는 5버전 필터 트레잇(...Filter.php) filtering() 과 같다. (값에 입력한 글자가 들어 있는지, 대소문자 무시)
 *
 * 이 화면에 속한 파일
 *   actions/reports/winpatch/WinPatchList.php     목록 화면 컨트롤러 (이 파일)
 *   actions/reports/winpatch/WinPatchDetail.php   상세보기 팝업 컨트롤러
 *   views/reports/winpatch.list.php               목록 화면 뷰
 *   views/reports/winpatch.detail.php             상세보기 팝업 뷰
 *   views/js/reports/winpatch.list.js.php         상세보기 링크를 누르면 팝업을 여는 스크립트
 *   includes/WinPatchService.php                  데이터 처리
 *   conf/winpatch.conf.php                        아이템 키, 상세 표의 열
 *   includes/filter/HostFilter.php              호스트 그룹/호스트 선택 필터 (여러 화면 공용)
 */

namespace Modules\DMove\Actions\Reports\WinPatch;

use CArrayHelper;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CUrl;
use CWebUser;
use Modules\DMove\Includes\Export\ExportTrait;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\WinPatchService;

class WinPatchList extends CController {

	// 내보내기 플러그인. (includes/export/ExportTrait.php)
	use ExportTrait;

	/** 필터/정렬 값을 사용자별로 저장하는 프로파일 키 접두어. 화면마다 겹치지 않게 'web.dmove.<화면>.' 으로 짓는다. */
	private const PROFILE_PREFIX = 'web.dmove.winpatch.';

	/**
	 * 컨트롤러가 만들어질 때 코어가 한 번 부른다.
	 * 조회만 하는 화면이라 CSRF 토큰 검사를 끈다. (값을 바꾸는 화면에서는 끄면 안 된다)
	 *
	 * @return void  돌려주는 값이 없다.
	 */
	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

	/**
	 * 주소로 들어온 값 검사. 여기에 적지 않은 이름의 값이 오거나 형식이 틀리면 화면을 열지 않는다.
	 *
	 *   'ge 1'     : 1 이상의 숫자
	 *   'in 1'     : 값이 1 이어야 한다
	 *   'in a,b'   : 나열한 값 중 하나
	 *   'string'   : 글자
	 *
	 * 이 화면이 받는 값
	 *   page                      페이지 번호. 예) 2
	 *   filter_set / filter_rst   [적용] / [리셋] 버튼을 눌렀다는 표시
	 *   sort / sortorder          정렬할 열과 방향. 정렬할 수 있는 열은 호스트(name) 하나다.
	 *   filter_hotfixid           HotFix ID 입력값. 예) KB4601060
	 *   filter_month              수집월 입력값. 예) 2026-10-01
	 *   filter_groupids[], filter_hostids[]   호스트 그룹/호스트 선택창의 ID 목록 (HostFilter::INPUT_FIELDS)
	 *   format                    내보내기 형식. csv 또는 xlsx (ExportTrait::exportInputFields())
	 *
	 * @return bool  검사를 통과하면 true. false 면 오류 화면이 나오고 doAction() 은 실행되지 않는다.
	 */
	protected function checkInput(): bool {
		$fields = [
			'page' =>				'ge 1',
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1',
			'sort' =>				'in name',
			'sortorder' =>			'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN,
			'filter_hotfixid' =>	'string',
			// 날짜 입력은 빈 칸으로도 넘어오므로 문자열로 받고, 아래 normalizeDate() 에서 형식을 확인한다.
			'filter_month' =>		'string'
		// 배열 + 배열 = 두 배열을 합친다. 호스트 선택 필터의 규칙과 내보내기 규칙(format)을 더한다.
		] + HostFilter::INPUT_FIELDS + self::exportInputFields();

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 * 표시되는 호스트는 API 가 사용자 권한에 맞게 걸러 준다.
	 *
	 * @return bool  볼 수 있으면 true.
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 화면을 만드는 본체. 입력 검사와 권한 검사를 통과하면 코어가 부른다.
	 * 화면(winpatch.list)과 내보내기(winpatch.export) 두 액션이 이 메소드를 같이 쓴다.
	 *
	 * 하는 일 (아래 코드의 번호와 같다)
	 *   1) [적용]/[리셋]을 눌렀으면 필터를 저장하거나 지운다.
	 *   2) 저장된 필터를 읽는다. 수집월이 비어 있으면 지난달 1일로 한다.
	 *   3) 정렬할 열과 방향을 정한다.
	 *   4) 행을 만들고 정렬한다.
	 *   5) 내보내기 요청이면 파일로 응답하고 끝낸다.
	 *   6) 화면이면 페이지 분량만 잘라서 뷰로 넘긴다.
	 *
	 * 뷰로 넘기는 $data 의 키
	 *   action, export_action, detail_action   화면 / 내보내기 / 상세보기 팝업의 액션 이름
	 *   title           화면 제목
	 *   columns         열 정의. ['name' => '호스트', 'ip' => 'IP', 'description' => '설명']
	 *   active_tab      필터 영역이 펼쳐져 있는지 (1 = 펼침)
	 *   host_filter     선택창에 표시할 값. ['groups' => [...], 'hosts' => [...]]
	 *   filter          ['hotfixid' => 'KB4601060', 'month' => '2026-10-01']
	 *   sort, sortorder 정렬 열, 방향('ASC' / 'DESC')
	 *   config_missing  conf/winpatch.conf.php 의 아이템 키가 비어 있으면 true (뷰가 안내를 띄운다)
	 *   paging          페이지 이동 줄
	 *   rows            현재 페이지의 행. [['hostid' => '10084', 'name' => .., 'ip' => .., 'description' => ..], ...]
	 *
	 * @return void  돌려주는 값은 없다. 결과는 $this->setResponse() 로 코어에 넘긴다.
	 */
	protected function doAction(): void {
		// ── 1) 필터 저장/초기화 ([적용] -> filter_set=1, [리셋] -> filter_rst=1) ──
		if ($this->hasInput('filter_set')) {
			HostFilter::save(self::PROFILE_PREFIX, $this->getInput('filter_groupids', []),
				$this->getInput('filter_hostids', [])
			);
			CProfile::update(self::PROFILE_PREFIX.'filter.hotfixid', trim($this->getInput('filter_hotfixid', '')),
				PROFILE_TYPE_STR
			);
			CProfile::update(self::PROFILE_PREFIX.'filter.month',
				self::normalizeDate($this->getInput('filter_month', '')), PROFILE_TYPE_STR
			);
		}
		elseif ($this->hasInput('filter_rst')) {
			HostFilter::reset(self::PROFILE_PREFIX);
			CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.hotfixid');
			CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.month');
		}

		// ── 2) 저장된 필터 읽기 ──
		$host_filter = HostFilter::load(self::PROFILE_PREFIX);	// ['groupids' => [...], 'hostids' => [...]]
		$filter = [
			'hotfixid' => CProfile::get(self::PROFILE_PREFIX.'filter.hotfixid', ''),
			// 기본값: 지난달 1일.  ?: 는 "왼쪽이 비어 있으면 오른쪽".
			// strtotime('first day of last month'): 영어 표현을 날짜로 바꿔 준다. 오늘이 11월이면 10월 1일.
			'month' => CProfile::get(self::PROFILE_PREFIX.'filter.month', '')
				?: date('Y-m-d', strtotime('first day of last month'))
		];

		// ── 3) 정렬 값. 주소에 없으면 지난번 값을 쓰고, 다시 저장한다. ──
		$sort_field = $this->getInput('sort', CProfile::get(self::PROFILE_PREFIX.'sort', 'name'));
		$sort_order = $this->getInput('sortorder', CProfile::get(self::PROFILE_PREFIX.'sortorder', ZBX_SORT_UP));

		CProfile::update(self::PROFILE_PREFIX.'sort', $sort_field, PROFILE_TYPE_STR);
		CProfile::update(self::PROFILE_PREFIX.'sortorder', $sort_order, PROFILE_TYPE_STR);

		// ── 4) 데이터 ──
		$service = new WinPatchService();
		$rows = $service->getRows($host_filter, $filter['hotfixid'], $filter['month']);

		// 코어의 정렬 도우미. 대소문자를 무시하고 숫자가 섞인 이름(web2 < web10)도 자연스럽게 정렬한다.
		CArrayHelper::sort($rows, [['field' => $sort_field, 'order' => $sort_order]]);
		// array_values: 배열 번호를 0 부터 다시 매긴다.
		$rows = array_values($rows);

		$title = Lang::t('Windows patch status');

		// ── 5) 내보내기 요청이면 파일로 응답하고 끝낸다 ──
		if ($this->isExportRequest()) {
			// 두 번째 인자는 파일명 앞부분. 예) windows_patch_20260102_030405.csv
			$this->setExportResponse($title, 'windows_patch', self::getColumns(), $rows);

			return;
		}

		// ── 6) 화면 응답 ──
		$data = [
			'action' => 'winpatch.list',
			'export_action' => 'winpatch.export',
			'detail_action' => 'winpatch.detail',
			'title' => $title,
			'columns' => self::getColumns(),
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'filter.active', 1),
			'host_filter' => HostFilter::toViewData($host_filter),
			'filter' => $filter,
			'sort' => $sort_field,
			'sortorder' => $sort_order,
			// 아이템 키 설정이 비어 있으면 뷰가 안내를 띄운다.
			'config_missing' => !$service->isConfigured()
		];

		/*
		 * 페이징. 세 번째 인자는 항상 ZBX_SORT_UP 이다. $rows 는 위에서 이미 정렬을 끝낸 전체 목록이다.
		 * ZBX_SORT_DOWN 을 넘기면 코어가 "API 가 결과 제한만큼만 가져온 목록"으로 보고 (전체 건수 - 결과 제한)
		 * 만큼 건너뛴다. 그러면 1000건이 넘을 때 내림차순 1페이지가 501번째 줄부터 나온다. (include/classes/helpers/CPagerHelper.php)
		 */
		$data['paging'] = CPagerHelper::paginate($this->getInput('page', 1), $rows, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', $data['action'])
		);
		$data['rows'] = $rows;

		$response = new CControllerResponseData($data);
		$response->setTitle($title);

		$this->setResponse($response);
	}

	/**
	 * 표의 열 정의. 화면의 제목 줄과 내보내기 파일이 같이 쓴다. ("Patch File명" 열은 링크라서 내보내기에는 없다)
	 *
	 *   키 = 행 배열의 키,  값 = 제목.  _('Host') 는 코어 번역 함수다. 사용자 언어에 맞는 글자로 바뀐다.
	 *
	 * @return array  [행의 키 => 제목]. 예) ['name' => '호스트', 'ip' => 'IP', 'description' => '설명']
	 */
	private static function getColumns(): array {
		return [
			'name' => _('Host'),
			'ip' => _('IP'),
			'description' => _('Description')
		];
	}

	/**
	 * 날짜 문자열이 YYYY-MM-DD 형식이고 실제로 있는 날짜면 그대로, 아니면 빈 문자열을 돌려준다.
	 * 빈 문자열로 저장되면 doAction() 이 기본값(지난달 1일)을 쓴다.
	 *
	 * 예) ' 2026-10-01 ' -> '2026-10-01',  '2026-02-30' -> '',  '2026/10/01' -> '',  '' -> ''
	 *
	 * @param string $value  필터 입력창에 적은 글자.
	 *
	 * @return string  'YYYY-MM-DD' 또는 ''.
	 */
	private static function normalizeDate(string $value): string {
		$value = trim($value);

		// 정규식 풀이: ^ 처음, \d{4} 숫자 4개, - , \d{2} 숫자 2개, - , \d{2} 숫자 2개, $ 끝. 괄호로 묶은 부분이 $m 에 담긴다.
		// preg_match 가 1 을 돌려주면 형식이 맞는 것. $m[1]~[3] 에 연/월/일이 들어온다.
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
			return '';
		}

		// checkdate(월, 일, 연): 실제로 있는 날짜인지 검사한다. (2월 30일 같은 값 거르기)
		return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
	}
}
