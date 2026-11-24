<?php declare(strict_types = 0);
/*
 * SW 인벤토리의 Agent 상태 입력 창에서 [저장] 을 눌렀을 때 기록을 추가하는 컨트롤러.
 *
 * ── 하는 일 ──
 *   상태(미설치 2 / 예외 4)와 사유를 상태 표(extends_agent)에 한 줄 추가한다.
 *   5버전처럼 고치지 않고 쌓는다. 가장 최근 줄이 그 서버의 지금 상태다.
 *
 *   호출 : zabbix.php?action=swinventory.agent.save   (POST, JSON 본문)
 *
 * ── 응답 ──
 *   성공: {"success": {"title": "저장했습니다."}}
 *   실패: {"error": {"title": "...", "messages": ["..."]}}
 *
 * ── 왜 CSRF 토큰 검사를 켜 두는가 ──
 *   DB 에 기록을 남기는 요청이기 때문이다. 창의 폼에 숨은 값으로 넣어 둔 토큰이 같이 와야 통과한다.
 */

namespace Modules\DMove\Actions\Inventory\SwInventory;

use CController;
use CControllerResponseData;
use CRoleHelper;
use CWebUser;
use Modules\DMove\Includes\AgentStatusRepository;
use Modules\DMove\Includes\ItsmRepository;
use Modules\DMove\Includes\Lang;

class AgentSave extends CController {

	/** 사유의 최대 길이. 표의 explanation 열 크기(varchar 2048)와 같다. */
	private const EXPLANATION_MAX = 2048;

	/**
	 * 요청 본문을 JSON 으로 읽는다. CSRF 토큰 검사는 끄지 않는다.
	 */
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	/**
	 * 줄의 키가 올바른가. 'itsm_' + 비어 있지 않은 ITSM id 이고, 20자 이하이고(표의 hostid 열이 varchar(20)),
	 * 그 id 의 줄이 ITSM 표에 실제로 있어야 한다.
	 * Zabbix 에 등록된 서버(키가 숫자 hostid)는 상태를 입력하지 않는다. (5버전과 같다. 등록된 서버는 항상 "설치")
	 */
	public static function isValidKey(string $key): bool {
		if (!str_starts_with($key, 'itsm_') || $key === 'itsm_' || mb_strlen($key) > 20) {
			return false;
		}

		// substr($key, 5): 앞의 'itsm_' 5글자를 뗀 나머지가 ITSM id 다.
		return (new ItsmRepository())->idExists(substr($key, 5));
	}

	/**
	 * 들어온 값 검사.
	 *   key         : 줄의 키
	 *   agent       : 2(미설치) 또는 4(예외)
	 *   explanation : 사유. 2048자 이하
	 */
	protected function checkInput(): bool {
		$fields = [
			'key' =>			'required|string|not_empty',
			'agent' =>			'required|in '.AgentStatusRepository::STATUS_NOT_INSTALLED.','
				.AgentStatusRepository::STATUS_EXCEPTION,
			'explanation' =>	'string'
		];

		$ret = $this->validateInput($fields);

		if ($ret && !self::isValidKey($this->getInput('key'))) {
			error(Lang::t('Invalid server key.'));
			$ret = false;
		}

		// 사유는 꼭 넣어야 한다. 5버전 popup.agent.js 도 비어 있으면 이 문구로 막았다.
		if ($ret && trim($this->getInput('explanation', '')) === '') {
			error(Lang::t('Enter the reason for Agent not installed or exception.'));
			$ret = false;
		}

		if ($ret && mb_strlen($this->getInput('explanation', '')) > self::EXPLANATION_MAX) {
			error(Lang::t('The reason can be up to %1$d characters.', self::EXPLANATION_MAX));
			$ret = false;
		}

		if (!$ret) {
			$this->respondError(Lang::t('Cannot save.'));
		}

		return $ret;
	}

	/**
	 * 접근 권한. SW 인벤토리 화면과 같다. (코어 "인벤토리 > 호스트" 화면 권한, 게스트 제외)
	 * 5버전은 따로 검사하지 않았다.
	 */
	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_INVENTORY_HOSTS) && !CWebUser::isGuest();
	}

	/**
	 * 기록을 추가하고 결과를 돌려준다.
	 */
	protected function doAction(): void {
		$repository = new AgentStatusRepository();

		if (!$repository->isAvailable()) {
			error(Lang::t('Run sql/extends_agent.sql in the Zabbix database, or check conf/swinventory.conf.php.'));
			$this->respondError(Lang::t('The Agent status table does not exist.'));

			return;
		}

		// CWebUser::$data: 지금 로그인한 사용자의 정보. userid, username(로그인 이름), name(이름) 이 들어 있다.
		// 사유는 입력한 그대로 저장한다. (5버전과 같다. 빈칸만 있는 사유는 checkInput() 에서 막는다)
		$saved = $repository->insert($this->getInput('key'), (int) $this->getInput('agent'),
			$this->getInput('explanation', ''), CWebUser::$data
		);

		if (!$saved) {
			$this->respondError(Lang::t('Cannot save.'));

			return;
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'success' => ['title' => Lang::t('Saved.')]
		])]));
	}

	/**
	 * 오류 응답. 코어가 모아 둔 오류 문구(error() 로 넣은 것, 입력 검사 오류)를 함께 보낸다.
	 */
	private function respondError(string $title): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'error' => [
				'title' => $title,
				'messages' => array_column(get_and_clear_messages(), 'message')
			]
		])]));
	}
}
