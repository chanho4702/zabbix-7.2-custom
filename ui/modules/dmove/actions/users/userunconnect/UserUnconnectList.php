<?php declare(strict_types = 0);
/*
 * 장기 미접속 계정 현황 화면 컨트롤러. (화면 구성 완료, 데이터 조회는 TODO)
 *
 *   화면 : zabbix.php?action=userunconnect.list
 *   메뉴 : 유저 > 장기 미접속 계정 현황   (Module.php 의 USER_MENUS. 5버전에서는 "관리" 아래였다)
 *   5버전: zabbix.php?action=user.unconnect
 *
 * 5버전 화면 구성
 *   필터 : 로그인 이름, 이름, 성, 유저 종류(전부 / Zabbix User / Zabbix Admin / Zabbix Super Admin), 유저 그룹
 *   표   : 로그인 이름, 이름, 성, 유저 종류, 그룹, 최근접속일시
 *
 * 5버전과 달라진 점 (7버전의 구조 변화 때문)
 *   - 로그인 이름 컬럼이 users.alias 에서 users.username 으로 바뀌었다.
 *   - 유저 종류(type)는 users 테이블에 없다. 유저에 연결된 "유저 역할(role)"의 type 이 유저 종류다.
 *     user.get 에 'selectRole' => ['type'] 을 주면 읽을 수 있다.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ TODO(고객환경) 데이터 조회부가 비어 있다. -> 아래 getRows()             │
 * │                                                                         │
 * │ 5버전 컨트롤러에서 확인할 것                                            │
 * │   - "장기 미접속" 기준 일수 (며칠 이상 접속하지 않은 계정인가)          │
 * │   - 최근접속일시를 어디서 읽는가 (sessions.lastaccess 로 추정)          │
 * │   - 비활성화된 계정, 한 번도 접속하지 않은 계정을 포함하는가            │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * 이 화면에 속한 파일
 *   actions/users/userunconnect/UserUnconnectList.php   컨트롤러 (이 파일)
 *   views/users/userunconnect.list.php            화면 뷰
 */

namespace Modules\DMove\Actions\Users\UserUnconnect;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CRoleHelper;
use CUrl;
use Modules\DMove\Includes\Lang;

class UserUnconnectList extends CController {

	private const PROFILE_PREFIX = 'web.dmove.userunconnect.';

	/** 유저 종류 필터의 "전부". 실제 종류 값은 코어 상수 USER_TYPE_ZABBIX_USER(1) / ADMIN(2) / SUPER_ADMIN(3). */
	public const USER_TYPE_ANY = -1;

	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'page' =>				'ge 1',
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1',
			'filter_username' =>	'string',
			'filter_name' =>		'string',
			'filter_surname' =>		'string',
			'filter_user_type' =>	'in '.implode(',', [self::USER_TYPE_ANY, USER_TYPE_ZABBIX_USER,
				USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN
			]),
			// 'id' = 숫자 ID 하나. 0 은 "모두".
			'filter_usrgrpid' =>	'id'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 코어의 "유저 > 유저" 화면을 볼 수 있는 사용자(기본적으로 Super admin)만 허용한다.
	 * 다른 사용자의 계정 정보를 보여 주는 화면이라 5버전보다 좁게 잡았다.
	 */
	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_ADMINISTRATION_USERS);
	}

	protected function doAction(): void {
		// ── 1) 필터 저장/초기화 ──
		if ($this->hasInput('filter_set')) {
			foreach (['username', 'name', 'surname'] as $key) {
				CProfile::update(self::PROFILE_PREFIX.'filter.'.$key, trim($this->getInput('filter_'.$key, '')),
					PROFILE_TYPE_STR
				);
			}

			CProfile::update(self::PROFILE_PREFIX.'filter.user_type',
				(int) $this->getInput('filter_user_type', self::USER_TYPE_ANY), PROFILE_TYPE_INT
			);
			CProfile::update(self::PROFILE_PREFIX.'filter.usrgrpid', $this->getInput('filter_usrgrpid', 0),
				PROFILE_TYPE_ID
			);
		}
		elseif ($this->hasInput('filter_rst')) {
			foreach (['username', 'name', 'surname', 'user_type', 'usrgrpid'] as $key) {
				CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.'.$key);
			}
		}

		// ── 2) 저장된 필터 읽기 ──
		$filter = [
			'username' => CProfile::get(self::PROFILE_PREFIX.'filter.username', ''),
			'name' => CProfile::get(self::PROFILE_PREFIX.'filter.name', ''),
			'surname' => CProfile::get(self::PROFILE_PREFIX.'filter.surname', ''),
			'user_type' => (int) CProfile::get(self::PROFILE_PREFIX.'filter.user_type', self::USER_TYPE_ANY),
			'usrgrpid' => CProfile::get(self::PROFILE_PREFIX.'filter.usrgrpid', 0)
		];

		// ── 3) 데이터 조회 ──
		$rows = $this->getRows($filter);

		// 유저 그룹 선택 목록(드롭다운)에 넣을 그룹 이름. [usrgrpid => 이름]
		$user_groups = API::UserGroup()->get([
			'output' => ['usrgrpid', 'name'],
			'preservekeys' => true
		]);
		// array_column(배열, 값 키, 색인 키): [usrgrpid => name] 형태로 뽑는다.
		$user_groups = array_column($user_groups, 'name', 'usrgrpid');
		// 이름순 정렬. natcasesort 는 키(usrgrpid)를 유지한다.
		natcasesort($user_groups);

		// ── 4) 뷰로 넘길 데이터 ──
		$data = [
			'action' => $this->getAction(),
			'title' => Lang::t('Long-term inactive accounts'),
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'filter.active', 1),
			'filter' => $filter,
			'user_groups' => $user_groups,
			'user_type_any' => self::USER_TYPE_ANY,
			'data_ready' => false	// TODO(고객환경): getRows() 를 구현하면 true 로 바꾼다.
		];

		$data['paging'] = CPagerHelper::paginate($this->getInput('page', 1), $rows, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', $data['action'])
		);
		$data['rows'] = $rows;

		$response = new CControllerResponseData($data);
		$response->setTitle($data['title']);

		$this->setResponse($response);
	}

	/**
	 * 표에 표시할 행 목록.
	 *
	 * TODO(고객환경): 구현한다. 뷰가 기대하는 행 형식은 아래와 같다.
	 *
	 *   [
	 *     'userid'      => '3',
	 *     'username'    => 'W12345',
	 *     'name'        => '길동',
	 *     'surname'     => '홍',
	 *     'user_type'   => 1,                      // USER_TYPE_ZABBIX_USER(1) / ADMIN(2) / SUPER_ADMIN(3)
	 *     'groups'      => '그룹A, 그룹B',
	 *     'last_access' => '2026-01-02 03:04:05'   // 접속 기록이 없으면 ''
	 *   ]
	 *
	 * 구현 순서 예시
	 *
	 *   // 1) 유저 목록. search 는 부분 일치(대소문자 무시), 빈 값('')인 항목은 조건에서 빠진다.
	 *   $users = API::User()->get([
	 *       'output' => ['userid', 'username', 'name', 'surname'],
	 *       'search' => [
	 *           'username' => $filter['username'], 'name' => $filter['name'], 'surname' => $filter['surname']
	 *       ],
	 *       'usrgrpids' => $filter['usrgrpid'] != 0 ? [$filter['usrgrpid']] : null,
	 *       'selectUsrgrps' => ['name'],
	 *       'selectRole' => ['type'],
	 *       'preservekeys' => true
	 *   ]);
	 *
	 *   // 2) 유저별 마지막 접속 시각. 코어의 유저 목록 화면도 sessions 테이블에서 읽는다.
	 *   //    (ui/app/controllers/CControllerUserList.php 의 "sessions" 부분 참고)
	 *   $result = DBselect(
	 *       'SELECT s.userid,MAX(s.lastaccess) AS lastaccess'.
	 *       ' FROM sessions s'.
	 *       ' WHERE '.dbConditionId('s.userid', array_keys($users)).
	 *       ' GROUP BY s.userid'
	 *   );
	 *
	 *   // 3) "N일 이상 미접속" 만 남긴다.  lastaccess < time() - N * 86400  (접속 기록이 없는 계정의 처리도 정한다)
	 */
	private function getRows(array $filter): array {
		return [];
	}
}
