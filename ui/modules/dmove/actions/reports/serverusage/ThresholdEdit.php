<?php declare(strict_types = 0);
/*
 * 저사용/고사용 화면의 [기준설정] 창을 여는 컨트롤러.
 *
 * ── 하는 일 ──
 *   지금 로그인한 사용자의 판정 기준값 4개를 읽어서 "수집기준 설정" 창의 내용으로 넘긴다.
 *
 *   호출 : zabbix.php?action=serverusage.threshold       (화면의 [기준설정] 버튼이 팝업으로 부른다)
 *   저장 : zabbix.php?action=serverusage.threshold.save  (창의 [설정] 버튼. ThresholdSave.php)
 *   5버전: zabbix.php?action=popup.lowserver  (컨트롤러와 뷰는 받지 못했다. 5버전 화면에서 창의 구성을 확인했다)
 *
 * ── 창 구성 (5버전 화면과 같다) ──
 *   제목 : 수집기준 설정
 *   입력 : 저사용 평균 CPU 사용률(%) / 저사용 Peak기준 / 고사용 평균 CPU 사용률(%) / 고사용 Peak기준
 *          안내 "1~99 사이의 숫자만 입력해 주세요", "모든 항목은 필수입력값입니다"
 *   버튼 : [설정] [취소]
 *
 * ── 데이터 흐름 ──
 *   [기준설정] 클릭 -> views/js/reports/serverusage.list.js.php 가 PopUp('serverusage.threshold') 호출
 *     -> 이 컨트롤러 -> views/reports/serverusage.threshold.php 가 창 내용을 JSON 으로 출력
 *
 * ── 관련 파일 ──
 *   includes/ServerUsageThresholds.php   기준값 읽기/저장 (사용자별 프로파일)
 *   conf/serverusage.conf.php            기준값의 기본값
 */

namespace Modules\DMove\Actions\Reports\ServerUsage;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\DMove\Includes\ServerUsageService;
use Modules\DMove\Includes\ServerUsageThresholds;

class ThresholdEdit extends CController {

	/**
	 * 값을 읽기만 하는 요청이라 CSRF 토큰 검사를 끈다. (저장은 ThresholdSave 가 토큰을 검사한다)
	 */
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	/**
	 * 받는 값이 없다. 항상 통과.
	 */
	protected function checkInput(): bool {
		return true;
	}

	/**
	 * 접근 권한. 저사용/고사용 화면과 같다. (로그인한 사용자, 게스트 제외)
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 지금 사용자의 기준값을 뷰로 넘긴다.
	 *
	 * 뷰가 받는 $data:
	 *   thresholds  ['low_avg' => 30, 'low_peak' => 70, 'high_avg' => 50, 'high_peak' => 70]
	 *   min, max    입력 범위. 1, 99
	 *   user        ['debug_mode' => 0 또는 1]
	 */
	protected function doAction(): void {
		// 기본값은 설정 파일에서, 사용자가 바꾼 값은 프로파일에서 읽는다.
		$defaults = (new ServerUsageService())->getThresholds();

		$this->setResponse(new CControllerResponseData([
			'thresholds' => ServerUsageThresholds::get($defaults),
			'min' => ServerUsageThresholds::MIN,
			'max' => ServerUsageThresholds::MAX,
			// 디버그 모드 사용자에게 디버그 정보를 붙일 때 코어가 쓰는 값. 팝업 뷰의 관례다.
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}
}
