<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 상세정보 팝업 컨트롤러.
 *
 *   호출 : 목록 화면의 "상세보기" 링크 -> 스크립트의 PopUp('winpatch.detail', {hostid, month})
 *   5버전: popup.windows
 *
 * 팝업은 일반 화면과 세 가지가 다르다.
 *   1) manifest.json 에 "layout": "layout.json" 으로 등록한다. 화면 전체가 아니라 JSON 을 돌려준다.
 *   2) 뷰가 화면을 그리지 않고 {header: 제목, body: 내용 HTML, buttons: 버튼} 을 JSON 으로 출력한다.
 *   3) 스크립트의 PopUp() 이 그 JSON 을 받아 팝업 창을 만든다.
 *
 * 이 팝업에 속한 파일
 *   actions/reports/winpatch/WinPatchDetail.php   컨트롤러 (이 파일)
 *   views/reports/winpatch.detail.php             뷰 (JSON 출력)
 *   views/js/reports/winpatch.list.js.php         PopUp() 을 부르는 스크립트
 *   includes/WinPatchService.php                  데이터 처리
 */

namespace Modules\DMove\Actions\Reports\WinPatch;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CWebUser;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\WinPatchService;

class WinPatchDetail extends CController {

	protected function init(): void {
		// 조회 전용이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			// 'required' = 반드시 있어야 하는 값. 'db hosts.hostid' = hosts 테이블의 hostid 형식(숫자 ID).
			'hostid' =>	'required|db hosts.hostid',
			// 'abs_date' = YYYY-MM-DD 형식의 실제 날짜.
			'month' =>	'required|abs_date'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 목록 화면과 같다.
	 * 어느 호스트를 볼 수 있는지는 WinPatchService 안의 API 호출이 걸러 준다.
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	protected function doAction(): void {
		$service = new WinPatchService();

		// 볼 수 없는 호스트이거나 패치 아이템이 없으면 null.
		$detail = $service->getDetail($this->getInput('hostid'), $this->getInput('month'));

		$this->setResponse(new CControllerResponseData([
			'title' => Lang::t('Windows patch details'),
			'host' => $detail !== null ? $detail['host'] : '',
			'columns' => $service->getColumns(),
			'rows' => $detail !== null ? $detail['rows'] : [],
			// 디버그 모드 사용자에게 디버그 정보를 붙일 때 코어가 쓰는 값. 팝업 뷰의 관례다.
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}
}
