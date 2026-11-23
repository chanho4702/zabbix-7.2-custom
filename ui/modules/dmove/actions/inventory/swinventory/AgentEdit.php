<?php declare(strict_types = 0);
/*
 * SW 인벤토리의 Agent 상태 입력 창을 여는 컨트롤러.
 *
 * ── 하는 일 ──
 *   Zabbix 에 등록되지 않은 ITSM 서버의 Agent 상태(미설치 / 예외)와 사유를 입력하는 창을 연다.
 *   창 아래에는 그 서버의 최근 입력 기록 5개를 보여 준다.
 *
 *   호출 : zabbix.php?action=swinventory.agent&key=itsm_1001&name=web-server-01
 *          (목록에서 "미설치" / "예외" 글자를 누르면 화면 스크립트가 팝업으로 부른다)
 *   저장 : zabbix.php?action=swinventory.agent.save  (AgentSave.php)
 *   5버전: zabbix.php?action=popup.agent  (컨트롤러와 뷰는 받지 못했다. Agent 판정 클래스의 표 정의와
 *          _hostAgent_select() (최근 5줄), _hostAgent_insert() (한 줄 추가)를 근거로 만들었다)
 *
 * ── 관련 파일 ──
 *   views/inventory/swinventory.agent.php        창 내용(JSON)
 *   views/js/inventory/swinventory.list.js.php   창을 열고 저장하는 스크립트
 *   includes/AgentStatusRepository.php           상태 표 읽기/쓰기
 */

namespace Modules\DMove\Actions\Inventory\SwInventory;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use CWebUser;
use Modules\DMove\Includes\AgentStatusRepository;

class AgentEdit extends CController {

	/**
	 * 값을 읽기만 하는 요청이라 CSRF 토큰 검사를 끈다. (저장은 AgentSave 가 토큰을 검사한다)
	 */
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	/**
	 * 받는 값 검사.
	 *   key  : 줄의 키. 'itsm_' 로 시작하는 20자 이하. (표의 hostid 열 크기)
	 *   name : 창 제목에 보일 서버 이름.
	 */
	protected function checkInput(): bool {
		$fields = [
			'key' =>	'required|string|not_empty',
			'name' =>	'string'
		];

		$ret = $this->validateInput($fields) && AgentSave::isValidKey($this->getInput('key'));

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. SW 인벤토리 화면과 같다. (코어 "인벤토리 > 호스트" 화면 권한, 게스트 제외)
	 */
	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_INVENTORY_HOSTS) && !CWebUser::isGuest();
	}

	/**
	 * 지금 상태와 최근 기록을 뷰로 넘긴다.
	 *
	 * 뷰가 받는 $data:
	 *   key, name     줄의 키와 서버 이름
	 *   available     상태 표를 쓸 수 있는가. false 면 창에 안내만 나온다
	 *   agent         지금 상태. 2(미설치) 또는 4(예외). 기록이 없으면 2
	 *   history       최근 기록 5개 [['agent' => .., 'explanation' => .., 'clock' => .., 'username' => ..], ...]
	 *   user          ['debug_mode' => 0 또는 1]
	 */
	protected function doAction(): void {
		$repository = new AgentStatusRepository();
		$key = $this->getInput('key');
		$history = $repository->history($key, 5);

		// 지금 상태는 가장 최근 기록. 미설치/예외 가 아닌 값이면 미설치로 시작한다.
		$agent = $history ? $history[0]['agent'] : AgentStatusRepository::STATUS_NOT_INSTALLED;

		if ($agent !== AgentStatusRepository::STATUS_EXCEPTION) {
			$agent = AgentStatusRepository::STATUS_NOT_INSTALLED;
		}

		$this->setResponse(new CControllerResponseData([
			'key' => $key,
			'name' => $this->getInput('name', ''),
			'available' => $repository->isAvailable(),
			'agent' => $agent,
			'history' => $history,
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}
}
