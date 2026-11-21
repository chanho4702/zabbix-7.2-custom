<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 현황 화면 컨트롤러.
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
 * 5버전과 달라진 점
 *   - 상세보기 표에서 빈 열이 있어도 값이 제 열에 들어간다. (5버전은 뒤의 값이 앞으로 당겨졌다)
 *   - Description 은 ITSM 값을 먼저 쓰고, 없으면 Zabbix 호스트 설명을 쓴다.
 *   - Excel 내보내기가 추가됐다.
 *
 * TODO(고객환경)
 *   - conf/winpatch.conf.php 에 아이템 키를 채운다. (공개 저장소라 비워 두었다)
 *   - "수집월선택" 의 기본값(여기서는 지난달 1일)과 HotFix ID 필터의 의미(여기서는 "그 달의 패치 목록에
 *     입력한 글자가 들어 있는 호스트")가 5버전과 같은지 확인한다. 5버전 컨트롤러 소스는 확인하지 못했다.
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
use Modules\DMove\Includes\Export\ExportTrait;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\WinPatchService;

class WinPatchList extends CController {

	// 내보내기 플러그인. (includes/export/ExportTrait.php)
	use ExportTrait;

	private const PROFILE_PREFIX = 'web.dmove.winpatch.';

	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

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
		] + HostFilter::INPUT_FIELDS + self::exportInputFields();

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 */
	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		// ── 1) 필터 저장/초기화 ──
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
		$host_filter = HostFilter::load(self::PROFILE_PREFIX);
		$filter = [
			'hotfixid' => CProfile::get(self::PROFILE_PREFIX.'filter.hotfixid', ''),
			// 기본값: 지난달 1일.  ?: 는 "왼쪽이 비어 있으면 오른쪽".
			'month' => CProfile::get(self::PROFILE_PREFIX.'filter.month', '')
				?: date('Y-m-d', strtotime('first day of last month'))
		];

		// ── 3) 정렬 ──
		$sort_field = $this->getInput('sort', CProfile::get(self::PROFILE_PREFIX.'sort', 'name'));
		$sort_order = $this->getInput('sortorder', CProfile::get(self::PROFILE_PREFIX.'sortorder', ZBX_SORT_UP));

		CProfile::update(self::PROFILE_PREFIX.'sort', $sort_field, PROFILE_TYPE_STR);
		CProfile::update(self::PROFILE_PREFIX.'sortorder', $sort_order, PROFILE_TYPE_STR);

		// ── 4) 데이터 ──
		$service = new WinPatchService();
		$rows = $service->getRows($host_filter, $filter['hotfixid'], $filter['month']);

		CArrayHelper::sort($rows, [['field' => $sort_field, 'order' => $sort_order]]);
		$rows = array_values($rows);

		$title = Lang::t('Windows patch status');

		// ── 5) 내보내기 요청이면 파일로 응답하고 끝낸다 ──
		if ($this->isExportRequest()) {
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

		$data['paging'] = CPagerHelper::paginate($this->getInput('page', 1), $rows, $sort_order,
			(new CUrl('zabbix.php'))->setArgument('action', $data['action'])
		);
		$data['rows'] = $rows;

		$response = new CControllerResponseData($data);
		$response->setTitle($title);

		$this->setResponse($response);
	}

	/**
	 * 표의 열 정의. 화면의 제목 줄과 내보내기 파일이 같이 쓴다. ("Patch File명" 열은 링크라서 내보내기에는 없다)
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
	 */
	private static function normalizeDate(string $value): string {
		$value = trim($value);

		// preg_match 가 1 을 돌려주면 형식이 맞는 것. $m[1]~[3] 에 연/월/일이 들어온다.
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
			return '';
		}

		// checkdate(월, 일, 연): 실제로 있는 날짜인지 검사한다. (2월 30일 같은 값 거르기)
		return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
	}
}
