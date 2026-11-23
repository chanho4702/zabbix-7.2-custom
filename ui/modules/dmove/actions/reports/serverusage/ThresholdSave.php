<?php declare(strict_types = 0);
/*
 * 저사용/고사용 화면의 [기준설정] 창에서 [설정] 을 눌렀을 때 값을 저장하는 컨트롤러.
 *
 * ── 하는 일 ──
 *   창에서 보낸 기준값 4개를 검사하고, 지금 로그인한 사용자의 설정(프로파일)에 저장한다.
 *   결과를 JSON 으로 돌려준다. 화면 쪽 스크립트가 성공이면 창을 닫고 목록을 다시 불러온다.
 *
 *   호출 : zabbix.php?action=serverusage.threshold.save   (POST, JSON 본문)
 *
 * ── 응답 ──
 *   성공: {"success": {"title": "기준을 저장했습니다."}}
 *   실패: {"error": {"title": "...", "messages": ["..."]}}
 *
 * ── 왜 CSRF 토큰 검사를 켜 두는가 ──
 *   값을 바꾸는 요청이기 때문이다. 다른 사이트가 사용자 몰래 이 주소를 호출하는 것을 막는다.
 *   창의 폼에 숨은 값으로 넣어 둔 토큰(views/reports/serverusage.threshold.php)이 같이 와야 통과한다.
 *
 * ── 관련 파일 ──
 *   includes/ServerUsageThresholds.php            기준값 저장
 *   views/js/reports/serverusage.list.js.php      이 주소를 부르는 스크립트
 */

namespace Modules\DMove\Actions\Reports\ServerUsage;

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\ServerUsageThresholds;

class ThresholdSave extends CController {

	/**
	 * 요청 본문을 JSON 으로 읽는다. (스크립트가 fetch 로 JSON 을 보낸다)
	 * CSRF 토큰 검사는 끄지 않는다.
	 */
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	/**
	 * 들어온 값 검사. 네 값 모두 필수이고 1~99 사이의 정수여야 한다.
	 *
	 *   'required' : 반드시 있어야 한다
	 *   'int32'    : 정수
	 *   'ge 1'     : 1 이상      'le 99' : 99 이하
	 *
	 * 검사에 걸리면 이유를 JSON 으로 돌려준다. (창이 열린 채로 이유가 표시된다)
	 */
	protected function checkInput(): bool {
		$rule = 'required|int32|ge '.ServerUsageThresholds::MIN.'|le '.ServerUsageThresholds::MAX;
		$fields = [];

		foreach (ServerUsageThresholds::names() as $name) {
			$fields[$name] = $rule;
		}

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => [
					'title' => Lang::t('Enter numbers between %1$d and %2$d in all fields.',
						ServerUsageThresholds::MIN, ServerUsageThresholds::MAX
					),
					// 코어가 모아 둔 검사 오류 문구. array_column 으로 문구만 뽑는다.
					'messages' => array_column(get_and_clear_messages(), 'message')
				]
			])]));
		}

		return $ret;
	}

	/**
	 * 접근 권한. 저사용/고사용 화면과 같다. (로그인한 사용자, 게스트 제외)
	 */
	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 기준값을 저장하고 성공 응답을 돌려준다.
	 */
	protected function doAction(): void {
		$values = [];

		foreach (ServerUsageThresholds::names() as $name) {
			$values[$name] = (int) $this->getInput($name);
		}

		ServerUsageThresholds::save($values);

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'success' => ['title' => Lang::t('The criteria have been saved.')]
		])]));
	}
}
