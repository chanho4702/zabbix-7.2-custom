<?php declare(strict_types = 0);
/*
 * 장기 미접속 계정 현황 화면 컨트롤러.
 *
 * ── 이 화면이 하는 일 ──
 *   오랫동안(기본 60일) 로그인하지 않은 Zabbix 계정을 찾아서 보여 준다.
 *   안 쓰는 계정을 정리(삭제/비활성화)할 대상을 찾는 용도다.
 *
 *   화면 : zabbix.php?action=userunconnect.list
 *   메뉴 : 유저 > 장기 미접속 계정 현황   (Module.php 의 USER_MENUS. 5버전에서는 "관리" 아래였다)
 *   5버전: zabbix.php?action=user.unconnect
 *          컨트롤러 CControllerUserunconnect.php, 뷰 administration.user.unconnect.php
 *
 * ── 화면 구성 (5버전과 같다) ──
 *   필터 : 로그인 이름, 이름, 성, 유저 종류(전부 / User / Admin / Super admin), 유저 그룹
 *   표   : 로그인 이름, 이름, 성, 유저 종류, 그룹, 최근접속일시
 *
 * ── 어떤 계정이 나오는가 (5버전 쿼리와 같은 조건) ──
 *   아래 세 조건을 모두 만족하는 계정이다. (조건 2 만 판단 방식이 5버전과 다르다. 아래 설명) 숫자 60 은 conf/userunconnect.conf.php 에서 바꿀 수 있다.
 *     1) 최근 60일 안에 접속한 기록이 없다.          (sessions 테이블의 lastaccess)
 *        한 번도 접속하지 않은 계정도 여기에 들어간다.
 *     2) 최근 60일 안에 만든 계정이 아니다.          (auditlog 테이블의 "유저 추가" 기록)
 *        방금 만든 계정이 "미접속"으로 잡히지 않게 하는 조건이다.
 *     3) 기본 관리자 계정(userid 1)이 아니다.
 *
 * ── 데이터 흐름 ──
 *   doAction()
 *     ├─ 필터 저장/읽기 (CProfile)
 *     ├─ findInactiveUserIds()  DB 에서 조건에 맞는 userid 를 찾는다
 *     ├─ getRows()              user.get API 로 이름/그룹/종류를 읽고, 필터를 적용해 표의 행을 만든다
 *     ├─ 정렬 + 페이징
 *     └─ 뷰(views/users/userunconnect.list.php)로 넘긴다
 *
 * ── 조건 2) 는 5버전과 다르게 판단한다 (2026-11-23 결정) ──
 *   5버전  : "60일보다 전에 만든 기록이 있는 계정만" 보여 준다. 기록이 없는 계정은 빠진다.
 *   7버전  : "최근 60일 안에 만든 기록이 있는 계정만" 뺀다. 기록이 없는 계정은 오래된 계정으로 보고 보여 준다.
 *   이유   : 감사 로그는 보관 기간이 지나면 지워지고, 5버전 DB 를 7버전으로 올릴 때 예전 기록이 넘어오지 않는다.
 *            5버전 방식 그대로면 예전부터 있던 계정이 목록에서 모두 빠진다.
 *   예시   : 3년 전에 만들고 2년째 접속하지 않은 계정 -> 만든 기록이 없다 -> 5버전 방식은 빠짐, 7버전 방식은 나옴
 *            어제 만들고 아직 접속하지 않은 계정      -> 어제 기록이 있다 -> 두 방식 모두 빠짐
 *   5버전 방식으로 되돌리려면 conf/userunconnect.conf.php 의 created_check 를 'required' 로 바꾼다.
 *
 * ── 5버전과 달라진 점 ──
 *   - 로그인 이름 컬럼이 users.alias 에서 users.username 으로 바뀌었다. (Zabbix 5.4 부터)
 *   - 유저 종류(type)가 users 테이블에서 없어졌다. 유저에 연결된 "유저 역할(role)"의 type 이 유저 종류다.
 *   - 권한: 5버전은 Super admin 만. 7버전은 코어 "유저 > 유저" 화면을 볼 수 있는 역할. (기본 설정에서는 Super admin 만이라 같다)
 *   - 5버전은 유저 수가 "검색/필터 결과 제한"을 넘으면 뒤쪽이 잘렸다. 여기서는 조건에 맞는 계정을 모두 읽는다.
 *   - 쿼리에 숫자 60 과 userid 1 이 직접 적혀 있던 것을 설정 파일로 뺐다.
 *
 * ── 이 화면에 속한 파일 ──
 *   actions/users/userunconnect/UserUnconnectList.php   컨트롤러 (이 파일)
 *   views/users/userunconnect.list.php                  화면 뷰
 *   conf/userunconnect.conf.php                         설정 (미접속 기준 일수 등)
 */

namespace Modules\DMove\Actions\Users\UserUnconnect;

/*
 * use 문: 네임스페이스가 없는 코어 클래스를 이 파일에서 짧은 이름으로 쓰겠다는 선언이다.
 *
 *   API                  Zabbix API 를 PHP 안에서 직접 호출한다.          ui/include/classes/api/API.php
 *   CArrayHelper         배열 정렬 도우미.                                ui/include/classes/helpers/CArrayHelper.php
 *   CAudit               감사 로그의 상수(동작 종류, 대상 종류).          ui/include/classes/api/CAudit.php
 *   CController          모든 화면 컨트롤러의 부모 클래스.                ui/include/classes/mvc/CController.php
 *   CHousekeepingHelper  하우스키핑 설정(보관 기간) 읽기.                 ui/include/classes/helpers/CHousekeepingHelper.php
 *   CPagerHelper         페이지 나누기.                                   ui/include/classes/helpers/CPagerHelper.php
 *   CProfile             사용자별 화면 설정(필터, 정렬) 저장소.           ui/include/classes/user/CProfile.php
 *   CRoleHelper          사용자 역할의 권한 확인.                         ui/include/classes/helpers/CRoleHelper.php
 */
use API;
use CArrayHelper;
use CAudit;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CHousekeepingHelper;
use CPagerHelper;
use CProfile;
use CRoleHelper;
use CSettingsHelper;
use CUrl;
use Modules\DMove\Includes\Lang;

class UserUnconnectList extends CController {

	/** 이 화면의 필터/정렬 값을 사용자별로 저장할 때 쓰는 이름의 앞부분. */
	private const PROFILE_PREFIX = 'web.dmove.userunconnect.';

	/** 설정 파일 위치. 모듈 폴더 기준. */
	private const CONFIG_FILE = '/conf/userunconnect.conf.php';

	/** 유저 종류 필터의 "전부". 실제 종류 값은 코어 상수 USER_TYPE_ZABBIX_USER(1) / ADMIN(2) / SUPER_ADMIN(3). */
	public const USER_TYPE_ANY = -1;

	/**
	 * 방금 만든 계정을 빼는 방법. 설정 파일의 created_check 값이다. (자세한 설명: conf/userunconnect.conf.php)
	 *   CREATED_REQUIRED       : 기준일보다 전에 만든 기록이 있는 계정만 보여 준다. (5버전과 같다)
	 *   CREATED_EXCLUDE_RECENT : 최근에 만든 기록이 있는 계정만 뺀다. 기록이 없는 계정은 보여 준다.
	 *   CREATED_NONE           : 만든 날짜를 따지지 않는다.
	 */
	private const CREATED_REQUIRED = 'required';
	private const CREATED_EXCLUDE_RECENT = 'exclude_recent';
	private const CREATED_NONE = 'none';
	private const CREATED_MODES = [self::CREATED_REQUIRED, self::CREATED_EXCLUDE_RECENT, self::CREATED_NONE];

	/** 정렬할 수 있는 열. (5버전: alias, name, surname, type) */
	private const SORT_FIELDS = ['username', 'name', 'surname', 'user_type'];

	/**
	 * 컨트롤러가 만들어질 때 한 번 불린다.
	 * 조회만 하는 화면이라 CSRF 토큰 검사를 끈다. (값을 바꾸는 화면에서는 끄면 안 된다)
	 */
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	/**
	 * 주소로 들어온 값 검사. 여기에 적지 않은 이름의 값이 오거나 형식이 틀리면 화면을 열지 않는다.
	 *
	 *   'ge 1'      : 1 이상의 숫자
	 *   'in 1'      : 값이 1 이어야 한다
	 *   'in a,b,c'  : 나열한 값 중 하나
	 *   'string'    : 글자
	 *   'id'        : 숫자 ID 하나 (0 은 "모두")
	 *
	 * @return bool  검사를 통과하면 true.
	 */
	protected function checkInput(): bool {
		$fields = [
			'page' =>				'ge 1',
			'sort' =>				'in '.implode(',', self::SORT_FIELDS),
			'sortorder' =>			'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN,
			'filter_set' =>			'in 1',
			'filter_rst' =>			'in 1',
			'filter_username' =>	'string',
			'filter_name' =>		'string',
			'filter_surname' =>		'string',
			'filter_user_type' =>	'in '.implode(',', [self::USER_TYPE_ANY, USER_TYPE_ZABBIX_USER,
				USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN
			]),
			'filter_usrgrpid' =>	'id'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 코어의 "유저 > 유저" 화면을 볼 수 있는 사용자만 허용한다.
	 * 다른 사람의 계정 정보를 보여 주는 화면이기 때문이다. (5버전: Super admin 만)
	 */
	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_ADMINISTRATION_USERS);
	}

	/**
	 * 화면을 만드는 본체. 권한 검사를 통과하면 불린다.
	 */
	protected function doAction(): void {
		$config = self::loadConfig();

		// ── 1) 필터 저장/초기화 ──
		// [적용]을 누르면 filter_set=1, [초기화]를 누르면 filter_rst=1 이 온다.
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

		// ── 3) 정렬 값. 주소에 없으면 지난번 값을 쓰고, 다시 저장한다. ──
		$sort_field = $this->getInput('sort', CProfile::get(self::PROFILE_PREFIX.'sort', 'username'));
		$sort_order = $this->getInput('sortorder', CProfile::get(self::PROFILE_PREFIX.'sortorder', ZBX_SORT_UP));

		CProfile::update(self::PROFILE_PREFIX.'sort', $sort_field, PROFILE_TYPE_STR);
		CProfile::update(self::PROFILE_PREFIX.'sortorder', $sort_order, PROFILE_TYPE_STR);

		// ── 4) 데이터 조회 ──
		$rows = $this->getRows($filter, $config);

		CArrayHelper::sort($rows, [['field' => $sort_field, 'order' => $sort_order]]);
		$rows = array_values($rows);

		// 유저 그룹 선택 목록(드롭다운)에 넣을 그룹 이름. [usrgrpid => 이름]
		$user_groups = API::UserGroup()->get([
			'output' => ['usrgrpid', 'name'],
			'preservekeys' => true
		]);
		// API 가 실패하면 false 를 돌려준다. 빈 목록으로 바꿔 아래 array_column 이 멈추지 않게 한다.
		// array_column(배열, 값 키, 색인 키): [usrgrpid => name] 형태로 뽑는다.
		$user_groups = array_column($user_groups ?: [], 'name', 'usrgrpid');
		// 이름순 정렬. natcasesort 는 키(usrgrpid)를 유지한다.
		natcasesort($user_groups);

		// ── 5) 뷰로 넘길 데이터 ──
		$data = [
			'action' => $this->getAction(),
			'title' => Lang::t('Long-term inactive accounts'),
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'filter.active', 1),
			'filter' => $filter,
			'user_groups' => $user_groups,
			'user_type_any' => self::USER_TYPE_ANY,
			'sort' => $sort_field,
			'sortorder' => $sort_order,
			'inactive_days' => $config['inactive_days'],
			/*
			 * 감사 로그 보관 기간이 기준 일수보다 짧으면 뷰가 경고를 띄운다. 만든 날짜를 따지는 두 방식 모두 영향을 받는다.
			 *   required       : 만든 기록이 지워진 계정이 빠져서 목록이 비거나 줄어든다.
			 *   exclude_recent : 최근에 만든 계정의 기록이 지워지면, 방금 만든 계정이 미접속으로 잘못 나온다.
			 *                    예) 보관 31일, 기준 60일: 40일 전에 만든 뒤 한 번도 접속하지 않은 계정이 목록에 나온다.
			 */
			'created_check' => $config['created_check'],
			// 그룹 칸에 보여 줄 그룹 수의 한도 (관리 > 일반 > GUI 의 "Max number of columns..." 와 같은 값). 넘으면 "…"
			'max_in_table' => (int) CSettingsHelper::get(CSettingsHelper::MAX_IN_TABLE),
			// 그룹 이름을 그룹 편집 링크로 만들지. "유저 > 유저 그룹" 화면을 볼 수 있을 때만.
			'allowed_ui_user_groups' => $this->checkAccess(CRoleHelper::UI_ADMINISTRATION_USER_GROUPS),
			// 감사 로그와 별도로, 세션 보관 기간이 짧아도 최근 접속 기록이 지워져 미접속으로 잘못 나온다.
			'session_retention_short' => self::isSessionRetentionShorterThan($config['inactive_days']),
			'audit_retention_short' => $config['created_check'] !== self::CREATED_NONE
				&& self::isAuditRetentionShorterThan($config['inactive_days'])
		];

		// 세 번째 인자는 항상 ZBX_SORT_UP. $rows 는 이미 정렬을 끝낸 전체 목록이기 때문이다. (CPagerHelper 참고)
		$data['paging'] = CPagerHelper::paginate($this->getInput('page', 1), $rows, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', $data['action'])
		);
		$data['rows'] = $rows;

		$response = new CControllerResponseData($data);
		$response->setTitle($data['title']);

		$this->setResponse($response);
	}

	/**
	 * 설정 파일을 읽는다. 파일이 없거나 값이 빠져 있으면 5버전 기본값을 쓴다.
	 *
	 * @return array  ['inactive_days' => 60, 'created_check' => 'exclude_recent', 'exclude_userids' => [1]]
	 */
	private static function loadConfig(): array {
		// dirname(__DIR__, 3): 이 파일에서 폴더를 세 번 올라간 곳 = 모듈 폴더(ui/modules/dmove).
		$file = dirname(__DIR__, 3).self::CONFIG_FILE;

		// 설정 파일은 "return [ ... ];" 형태다. require 가 그 배열을 그대로 돌려준다.
		$config = is_readable($file) ? require $file : [];

		if (!is_array($config)) {
			$config = [];
		}

		return [
			// max(1, ...): 0 이나 음수가 들어와도 최소 1일로 본다.
			'inactive_days' => max(1, (int) ($config['inactive_days'] ?? 60)),
			// 값이 없거나 모르는 값이면 기본 방식('exclude_recent')으로 본다. in_array(값, 목록, true): 목록에 그 값이 있는가.
			'created_check' => in_array($config['created_check'] ?? null, self::CREATED_MODES, true)
				? $config['created_check']
				: self::CREATED_EXCLUDE_RECENT,
			// array_map('intval', ...): 값을 모두 정수로 바꾼다. 쿼리에 들어가는 값이라 숫자만 허용한다.
			'exclude_userids' => array_map('intval', (array) ($config['exclude_userids'] ?? [1]))
		];
	}

	/**
	 * 감사 로그를 지우는 주기가 기준 일수보다 짧은지 확인한다.
	 *
	 * "만든 지 N일이 넘은 계정"은 감사 로그의 유저 추가 기록으로 판단한다.
	 * 그런데 감사 로그를 N일보다 짧게 보관하면 그 기록이 이미 지워져서 목록이 항상 비게 된다.
	 *
	 *   관리 > 감사 로그 > "내부 하우스키핑 사용" 이 꺼져 있으면 지우지 않으므로 문제없다.
	 *   timeUnitToSeconds('31d') : '31d', '12h' 같은 글자를 초로 바꾸는 코어 함수.
	 */
	private static function isSessionRetentionShorterThan(int $days): bool {
		// 세션 하우스키핑이 꺼져 있으면 세션이 지워지지 않는다.
		if (CHousekeepingHelper::get(CHousekeepingHelper::HK_SESSIONS_MODE) != 1) {
			return false;
		}

		$retention = timeUnitToSeconds(CHousekeepingHelper::get(CHousekeepingHelper::HK_SESSIONS));

		return $retention !== null && $retention < $days * SEC_PER_DAY;
	}

	/**
	 * 감사 로그 보관 기간이 $days 일보다 짧은지. (관리 > 감사 로그)
	 */
	private static function isAuditRetentionShorterThan(int $days): bool {
		if (CHousekeepingHelper::get(CHousekeepingHelper::HK_AUDIT_MODE) != 1) {
			return false;
		}

		$retention = timeUnitToSeconds(CHousekeepingHelper::get(CHousekeepingHelper::HK_AUDIT));

		return $retention !== null && $retention < $days * SEC_PER_DAY;
	}

	/**
	 * 장기 미접속 조건에 맞는 유저 ID 를 DB 에서 찾는다.
	 *
	 * 5버전 쿼리 (같은 뜻):
	 *   select userid from users
	 *   where userid not in (select userid from sessions group by userid
	 *                        having max(lastaccess) > 60일 전)
	 *     and userid != 1
	 *     and alias in (select resourcename from auditlog
	 *                   where action = '0' and resourcetype = 0 and clock < 60일 전)
	 *
	 * 이 조회는 API 로 할 수 없어서 DB 를 직접 읽는다. (user.get 은 "마지막 접속 시각" 조건을 받지 않는다)
	 * 쿼리에 들어가는 값은 모두 이 메소드에서 만든 정수라 따옴표 처리가 필요 없다.
	 *
	 *   NOT EXISTS (...)        : 괄호 안 조회 결과가 한 줄도 없을 때 참.
	 *   sessions.lastaccess     : 그 세션으로 마지막에 화면을 연 시각(유닉스 초).
	 *   CAudit::ACTION_ADD      : 감사 로그의 "추가" 동작(0).
	 *   CAudit::RESOURCE_USER   : 감사 로그의 대상 종류 "유저"(0).
	 *   auditlog.resourcename   : 대상의 이름. 유저면 로그인 이름이다.
	 *   dbConditionInt(..., true) : "컬럼 NOT IN (1,2)" 조건을 만드는 코어 함수.
	 *
	 * @return array  유저 ID 목록. 예) ['3', '7']
	 */
	private function findInactiveUserIds(array $config): array {
		// 이 시각 이후로 접속한 적이 없으면 장기 미접속이다.
		$threshold = time() - $config['inactive_days'] * SEC_PER_DAY;

		$sql = 'SELECT u.userid'.
			' FROM users u'.
			' WHERE NOT EXISTS ('.
				'SELECT NULL FROM sessions s WHERE s.userid=u.userid AND s.lastaccess>'.$threshold.
			')';

		if ($config['exclude_userids']) {
			$sql .= ' AND '.dbConditionInt('u.userid', $config['exclude_userids'], true);
		}

		/*
		 * 방금 만든 계정을 빼는 조건. 설정 created_check 에 따라 셋 중 하나다.
		 * "만든 날짜"는 감사 로그(auditlog)의 유저 추가 기록 시각으로 본다. ($created_records 가 그 기록을 찾는 조회다)
		 *
		 *   required       : 기준 시각보다 "전"에 만든 기록이 있는 계정만 남긴다. (5버전과 같다)
		 *                    기록이 없는 계정은 빠진다.
		 *   exclude_recent : 기준 시각 "이후"에 만든 기록이 있는 계정만 뺀다.
		 *                    기록이 없는 계정은 오래된 계정으로 보고 남긴다.
		 *   none           : 조건을 붙이지 않는다.
		 *
		 * 기본은 exclude_recent 다. (2026-11-23 결정. 이유는 이 파일 머리말과 conf/userunconnect.conf.php 의 created_check 설명)
		 */
		/*
		 * 감사 기록과 계정은 아이디(resourceid = userid)로 잇는다.
		 * 5버전은 이름(resourcename = alias)으로 이었는데, 그러면 두 가지가 틀린다.
		 *   - 이름을 바꾼 계정(대소문자만 바꿔도)은 자기 기록을 못 찾는다. 방금 만든 계정이 미접속으로 나올 수 있다.
		 *   - 지운 계정과 같은 이름으로 새로 만들면 지운 계정의 기록이 새 계정에 붙는다.
		 * 7.0 감사 로그의 유저 추가 기록은 resourceid 에 만든 계정의 userid 를 넣는다. (코어 CAudit::log)
		 */
		$created_records = 'SELECT NULL FROM auditlog a'.
			' WHERE a.resourceid=u.userid'.
				' AND a.action='.CAudit::ACTION_ADD.
				' AND a.resourcetype='.CAudit::RESOURCE_USER;

		if ($config['created_check'] === self::CREATED_REQUIRED) {
			$sql .= ' AND EXISTS ('.$created_records.' AND a.clock<'.$threshold.')';
		}
		elseif ($config['created_check'] === self::CREATED_EXCLUDE_RECENT) {
			$sql .= ' AND NOT EXISTS ('.$created_records.' AND a.clock>='.$threshold.')';
		}

		$result = DBselect($sql);
		$userids = [];

		// DBselect 는 실패하면 false 를 돌려준다. 그대로 DBfetch 에 넘기면 경고가 나므로 먼저 확인한다.
		while ($result !== false && $row = DBfetch($result)) {
			$userids[] = $row['userid'];
		}

		return $userids;
	}

	/**
	 * 표에 표시할 행 목록.
	 *
	 * @param array $filter  ['username' => '', 'name' => '', 'surname' => '', 'user_type' => -1, 'usrgrpid' => 0]
	 * @param array $config  loadConfig() 의 결과.
	 *
	 * @return array  행 1개 = 계정 1개.
	 *   [
	 *     'userid'      => '3',
	 *     'username'    => 'user01',
	 *     'name'        => '길동',
	 *     'surname'     => '홍',
	 *     'user_type'   => 1,                      // USER_TYPE_ZABBIX_USER(1) / ADMIN(2) / SUPER_ADMIN(3)
	 *     'groups'      => [['usrgrpid' => '7', 'name' => '그룹A', 'gui_access' => '0', 'users_status' => '0'], ...],
	 *     'last_access' => '2026-01-02 03:04:05'   // 접속 기록이 없으면 ''
	 *   ]
	 */
	private function getRows(array $filter, array $config): array {
		$userids = $this->findInactiveUserIds($config);

		// 대상이 없으면 여기서 끝낸다. (user.get 에 빈 userids 를 주는 일을 피한다)
		if (!$userids) {
			return [];
		}

		/*
		 * 1) 유저 정보. user.get 옵션:
		 *    userids        이 ID 의 유저만.
		 *    search         부분 일치(대소문자 무시). 빈 값('')인 항목은 조건에서 빠지도록 null 로 준다.
		 *    usrgrpids      이 유저 그룹에 속한 유저만. null 이면 조건 없음.
		 *    selectUsrgrps  각 유저에 'usrgrps' => [[name], ...] 를 붙인다.
		 *    selectRole     각 유저에 'role' => [type] 을 붙인다. type 이 유저 종류다.
		 *    preservekeys   결과 배열의 키를 userid 로 한다.
		 */
		$users = API::User()->get([
			'output' => ['userid', 'username', 'name', 'surname'],
			'userids' => $userids,
			'search' => [
				'username' => $filter['username'] !== '' ? $filter['username'] : null,
				'name' => $filter['name'] !== '' ? $filter['name'] : null,
				'surname' => $filter['surname'] !== '' ? $filter['surname'] : null
			],
			'usrgrpids' => $filter['usrgrpid'] != 0 ? [$filter['usrgrpid']] : null,
			// 그룹 상태(gui_access, users_status)는 5버전처럼 빨강/초록으로 칠하는 데 쓴다.
			'selectUsrgrps' => ['usrgrpid', 'name', 'gui_access', 'users_status'],
			'selectRole' => ['type'],
			'preservekeys' => true
		]);

		if (!$users) {
			return [];
		}

		// 2) 유저별 마지막 접속 시각. 코어의 유저 목록 화면도 sessions 테이블에서 읽는다. (CControllerUserList.php)
		$last_access = [];

		$result = DBselect(
			'SELECT s.userid,MAX(s.lastaccess) AS lastaccess'.
			' FROM sessions s'.
			' WHERE '.dbConditionId('s.userid', array_keys($users)).
			' GROUP BY s.userid'
		);

		while ($result !== false && $row = DBfetch($result)) {
			$last_access[$row['userid']] = (int) $row['lastaccess'];
		}

		// 3) 행 만들기.
		$rows = [];

		foreach ($users as $userid => $user) {
			/*
			 * 역할이 없는 유저(예: 외부 인증으로 만든 뒤 역할이 지워진 계정)는 종류가 없다. null 로 두고 화면에 '-' 로 보인다.
			 * 예전에는 "User" 로 보여서 종류 필터 "User" 에도 걸렸다.
			 */
			$user_type = isset($user['role']['type']) ? (int) $user['role']['type'] : null;

			// 유저 종류 필터. (API 가 역할의 type 으로는 걸러 주지 않아서 여기서 거른다)
			if ($filter['user_type'] != self::USER_TYPE_ANY && $user_type !== (int) $filter['user_type']) {
				continue;
			}

			// 그룹을 이름순으로 정렬한다. (5버전과 코어 유저 목록 화면과 같다)
			$groups = $user['usrgrps'];
			CArrayHelper::sort($groups, ['name']);

			$clock = $last_access[$userid] ?? 0;

			$rows[] = [
				'userid' => $userid,
				'username' => $user['username'],
				'name' => $user['name'],
				'surname' => $user['surname'],
				'user_type' => $user_type,
				// 그룹 목록. 뷰가 링크와 색을 만든다. [['usrgrpid', 'name', 'gui_access', 'users_status'], ...]
				'groups' => array_values($groups),
				// zbx_date2str(형식, 시각): 사용자 시간대와 언어에 맞춰 날짜를 글자로 바꾸는 코어 함수.
				'last_access' => $clock != 0 ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $clock) : ''
			];
		}

		return $rows;
	}
}
