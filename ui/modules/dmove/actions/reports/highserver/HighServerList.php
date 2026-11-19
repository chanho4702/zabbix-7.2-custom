<?php declare(strict_types = 0);
/*
 * 고사용 서버 사용현황 화면 컨트롤러. (껍데기)
 *
 *   화면 : zabbix.php?action=highserver.list
 *   메뉴 : 보고서 > 고사용 서버 사용현황   (Module.php 의 REPORT_MENUS)
 *   5버전 routing.php 의 액션 이름: highserver  (추정. 5버전 routing.php 에서 확인 필요)
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ TODO(고객환경) 이 화면은 메뉴와 빈 페이지만 있다.                       │
 * │                                                                         │
 * │ 5버전 화면(필터, 표 컬럼)과 컨트롤러 소스를 확인한 뒤 채운다.           │
 * │ 채우는 순서와 복사해서 쓸 코드는 template/README.md 에 있다.            │
 * │   1) checkInput()  : 필터 입력값 규칙 추가                              │
 * │   2) doAction()    : $rows 에 데이터 넣기                               │
 * │   3) views/reports/highserver.list.php : 필터와 표 컬럼 추가              │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * 이 화면에 속한 파일
 *   actions/reports/highserver/HighServerList.php   컨트롤러 (이 파일)
 *   views/reports/highserver.list.php      화면 뷰
 */

namespace Modules\DMove\Actions\Reports\HighServer;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\DMove\Includes\Lang;

class HighServerList extends CController {

	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다. 데이터를 저장/삭제하는 액션에서는 끄면 안 된다.
		$this->disableCsrfValidation();
	}

	/**
	 * 입력값 검증. 여기에 적지 않은 파라미터는 getInput() 으로 읽을 수 없다.
	 * 필터를 추가하면 'filter_xxx' => 'string' 같은 줄을 넣는다. (규칙 예시는 template 참고)
	 */
	protected function checkInput(): bool {
		$fields = [
			'page' => 'ge 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 지금은 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 *
	 * TODO(고객환경): 권한 정책에 맞게 바꾼다.
	 *   예) 관리자 이상만: return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
	 *   예) 코어 보고서 권한을 따라가기: return $this->checkAccess(\CRoleHelper::UI_REPORTS_SYSTEM_INFO);
	 */
	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		// TODO(고객환경): 여기에서 데이터를 조회해 $rows 에 넣는다. (행 1개 = 표의 한 줄)
		$rows = [];

		// 뷰로 넘길 데이터. 뷰에서는 $data['키'] 로 읽는다.
		$data = [
			'action' => $this->getAction(),
			'title' => Lang::t('High usage servers'),
			'rows' => $rows
		];

		$response = new CControllerResponseData($data);
		$response->setTitle($data['title']);	// 브라우저 탭 제목

		$this->setResponse($response);
	}
}
