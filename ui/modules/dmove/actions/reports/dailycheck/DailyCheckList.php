<?php declare(strict_types = 0);
/*
 * 일일점검 화면 컨트롤러.
 *
 * ── 이 화면이 하는 일 ──
 *   하루에 한 번 보는 점검표다. 점검 항목 7개마다 "정상인 서버 몇 대, 장애가 난 서버 몇 대"를 보여 준다.
 *   점검 구간은 아침 7시부터 다음 날 아침 6시 59분 59초까지의 24시간이다.
 *   [D-1] [D-2] 버튼으로 하루 전, 이틀 전 구간을 볼 수 있다.
 *
 *   화면 : zabbix.php?action=dailycheck.list            (기본: 어제 07:00 ~ 오늘 06:59)
 *          zabbix.php?action=dailycheck.list&period=1   (D-1: 하루 전 구간)
 *          zabbix.php?action=dailycheck.list&period=2   (D-2: 이틀 전 구간)
 *   메뉴 : 보고서 > 일일점검   (Module.php 의 REPORT_MENUS)
 *   5버전: zabbix.php?action=daily, &period1=1, &period2=1   (컨트롤러 CControllerDaily.php, 규칙 daily.json)
 *
 * ── 화면 구성 (5버전 기준) ──
 *   제목 아래 : 오늘 날짜, [D-1] [D-2] 버튼, (점검 구간의 시작일 ~ 종료일)
 *   표        : 점검항목 | 정상호스트 | 장애발생 호스트 | 점검대상(*전체호스트)
 *   행 7개    : OS기동상태 / 업무솔루션 프로세스 기동여부 / Disk Usage 90% 초과 /
 *               Disk Usage 전일 대비 30% 급증 / CPU Usage(업무시간) 전일 대비 20% 급증 /
 *               Paging space 기준 초과(1%) / 이중화 솔루션 상태
 *   장애발생 호스트 숫자를 누르면 해당 호스트 이름 목록이 뜬다.
 *   오른쪽 위 : [CPU 사용률 Top 10] [Memory 사용률 Top 10] 버튼. 누르면 팝업이 열린다. (DailyCheckTop.php)
 *   필터는 없다.
 *
 * ── 집계 방식 (5버전 일일점검 트레잇과 같다) ──
 *   점검 항목 7개 모두 같은 방법으로 센다. 항목마다 "트리거 태그"가 하나씩 정해져 있다. (아래 CHECKS)
 *
 *     점검대상       = 그 태그(이름=값)가 달린 트리거를 가진 호스트 수
 *     장애발생 호스트 = 그 트리거 중 하나라도 점검 구간 안에 장애(PROBLEM) 이벤트가 발생한 호스트 수
 *     정상호스트     = 점검대상 - 장애발생 호스트
 *
 *   즉 이 화면은 값을 직접 계산하지 않고 "태그가 달린 트리거가 구간 안에 울렸는가"만 본다.
 *   그래서 숫자가 맞게 나오려면 7버전 서버의 트리거(또는 템플릿의 트리거)에 5버전과 같은 태그가 달려 있어야 한다.
 *   태그의 이름과 항목별 값은 conf/dailycheck.conf.php 에 적는다. (5버전 소스의 값을 넣어 두었다. 고객 서버에서 같은지 확인한다)
 *   해당 태그가 달린 트리거가 하나도 없으면 그 항목은 점검대상 0 으로 나온다.
 *
 * ── 데이터 흐름 ──
 *   doAction()
 *     ├─ period 값으로 점검 구간(시작/끝 시각)을 계산한다
 *     ├─ conf/dailycheck.conf.php 에서 태그 이름과 항목별 태그 값을 읽는다
 *     ├─ 항목 7개마다 getCheckResult()
 *     │    ├─ trigger.get : 그 태그가 달린 트리거와 소속 호스트  -> 점검대상
 *     │    ├─ event.get   : 그 트리거 중 구간 안에 장애 이벤트가 있었던 것  -> 장애발생 호스트
 *     │    └─ 정상호스트 = 점검대상 - 장애발생 호스트
 *     └─ 뷰(views/reports/dailycheck.list.php)로 넘긴다
 *
 * ── 5버전과 달라진 점 ──
 *   - 기간 파라미터를 period1=1 / period2=1 두 개에서 period=1 / period=2 하나로 합쳤다.
 *   - 5버전은 점검 항목 목록을 daily.json 에서, 태그를 트레잇 안에서 읽었다.
 *     여기서는 항목 목록을 아래 CHECKS 상수에, 태그를 conf/dailycheck.conf.php 에 둔다.
 *   - trigger_tag / functions / items / events 테이블을 직접 조회하던 것을 API(trigger.get, event.get)로 바꿨다.
 *     결과는 같고, 로그인한 사용자가 볼 수 있는 호스트만 세어진다. (5버전은 점검대상을 구할 때만 권한이 적용됐다)
 *   - 5버전 소스에 있던 아이템 값 직접 비교(전일 대비 30% / 20% 를 trends 로 계산) 함수는 호출되지 않는
 *     코드였다. 옮기지 않았다. 30% / 20% 판정은 해당 태그가 달린 트리거가 한다.
 *
 * ── TODO(고객환경) ──
 *   - conf/dailycheck.conf.php 에 태그 이름과 값을 채운다.
 *   - 7버전 서버의 트리거에 그 태그가 5버전과 같게 달려 있는지 확인한다.
 *
 * ── 이 화면에 속한 파일 ──
 *   actions/reports/dailycheck/DailyCheckList.php   컨트롤러 (이 파일)
 *   views/reports/dailycheck.list.php               화면 뷰
 *   views/js/reports/dailycheck.list.js.php         Top 10 버튼을 누르면 팝업을 여는 스크립트
 *   conf/dailycheck.conf.php                        태그 이름과 항목별 값
 *   actions/reports/dailycheck/DailyCheckTop.php    Top 10 팝업 컨트롤러 (뷰: views/reports/dailycheck.top.php)
 */

namespace Modules\DMove\Actions\Reports\DailyCheck;

use API;
use CArrayHelper;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CWebUser;
use Modules\DMove\Includes\Lang;

class DailyCheckList extends CController {

	/** 하루 = 86400 초. (24시간 x 60분 x 60초) 점검 구간을 하루씩 옮길 때 쓴다. */
	private const DAY = 86400;

	/**
	 * 점검 항목. 키 => 화면에 표시할 이름(영어. 한국어는 lang/ko.php). 표의 줄 순서는 여기 적은 순서와 같다.
	 * 키는 conf/dailycheck.conf.php 의 tag_values 에서 이 항목의 태그 값을 찾는 데 쓴다.
	 *
	 * 항목을 추가하려면 여기에 한 줄, conf/dailycheck.conf.php 의 tag_values 에 한 줄, lang/ko.php 에 번역을 넣는다.
	 */
	private const CHECKS = [
		'os' =>		'OS running state',
		'work' =>	'Business solution processes running',
		'disk90' =>	'Disk usage over 90%',
		'disk30' =>	'Disk usage up 30% from previous day',
		'cpu20' =>	'CPU usage (business hours) up 20% from previous day',
		'paging' =>	'Paging space over threshold (1%)',
		'duplex' =>	'Redundancy solution state'
	];

	/**
	 * 설정 파일 위치. 모듈 폴더 기준.
	 * __DIR__(이 파일이 있는 폴더)에서 세 단계 위가 모듈 폴더다. (dailycheck -> reports -> actions -> dmove)
	 */
	private const CONFIG_FILE = '/conf/dailycheck.conf.php';

	/**
	 * 컨트롤러가 만들어질 때 한 번 불린다. (부모 CController 의 생성자가 부른다)
	 * 조회만 하는 화면이라 CSRF 토큰 검사를 끈다. (값을 바꾸는 화면에서는 끄면 안 된다)
	 *
	 *   CSRF 토큰 : 폼을 보낼 때 같이 보내는 확인용 값. 다른 사이트가 사용자 몰래 요청을 보내는 것을 막는다.
	 */
	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

	/**
	 * 주소로 들어온 값 검사. 여기에 적지 않은 이름의 값이 오거나 형식이 틀리면 화면을 열지 않는다.
	 *
	 *   'in 0,1,2' : 나열한 값 중 하나만 허용한다.
	 *
	 * 예) zabbix.php?action=dailycheck.list&period=1  -> 통과
	 *     zabbix.php?action=dailycheck.list&period=9  -> 실패. 오류 화면(CControllerResponseFatal)이 나온다.
	 *
	 * @return bool  검사를 통과하면 true. true 일 때만 checkPermissions() 와 doAction() 이 이어서 불린다.
	 */
	protected function checkInput(): bool {
		$fields = [
			// 0 = 기본, 1 = D-1, 2 = D-2
			'period' => 'in 0,1,2'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 * 숫자에 세어지는 호스트는 API 가 사용자 권한에 맞게 걸러 준다.
	 *
	 * @return bool  화면을 열어도 되면 true. false 면 "권한 없음" 화면이 나온다.
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 화면을 만드는 본체. 입력 검사와 권한 검사를 통과하면 불린다.
	 *
	 * 하는 일:
	 *   1) period 값(0 / 1 / 2)으로 점검 구간을 계산한다.
	 *   2) 설정 파일에서 태그 이름과 항목별 태그 값을 읽는다.
	 *   3) 점검 항목(CHECKS)마다 getCheckResult() 로 숫자를 구한다. 태그가 비어 있는 항목은 조회하지 않는다.
	 *   4) 결과를 뷰로 넘긴다.
	 *
	 * 뷰가 받는 $data:
	 *   [
	 *     'action'         => 'dailycheck.list',
	 *     'title'          => '일일점검',
	 *     'period'         => 0,                        // 0 = 기본, 1 = D-1, 2 = D-2
	 *     'today'          => '2026-11-23',             // 오늘 날짜
	 *     'date_from'      => '2026-11-22',             // 점검 구간 시작일
	 *     'date_till'      => '2026-11-23',             // 점검 구간 종료일
	 *     'tag_name'       => '...',                    // 설정의 태그 이름
	 *     'config_missing' => false,                    // 태그가 비어 있는 항목이 있으면 true
	 *     'time_from'      => '2026-11-22 07:00:00',    // 점검 구간 시작 시각
	 *     'time_till'      => '2026-11-23 06:59:59',    // 점검 구간 끝 시각
	 *     'rows'           => [                         // 행 1개 = 점검 항목 1개
	 *       ['label' => 'OS기동상태', 'tag' => '...', 'ok' => 95, 'fail' => 5, 'total' => 100,
	 *        'fail_hosts' => [['hostid' => '10084', 'name' => 'web-server-01'], ...]],
	 *       ...
	 *     ]
	 *   ]
	 */
	protected function doAction(): void {
		// (int): 주소로 온 값은 글자('1')라서 숫자로 바꾼다. period 가 없으면 0(기본 구간).
		$period = (int) $this->getInput('period', 0);

		/*
		 * ── 점검 구간 (5버전과 같은 계산) ──
		 *   기본 : 어제 07:00:00 ~ 오늘 06:59:59
		 *   D-1  : 그보다 하루 전
		 *   D-2  : 그보다 이틀 전
		 *
		 * date('Y-m-d') 는 오늘 날짜 문자열, strtotime() 은 날짜 문자열을 유닉스 시간(초)으로 바꾼다.
		 * 시간대는 로그인한 사용자의 시간대 설정을 따른다.
		 *
		 * 예) 오늘이 2026-11-23 이고 period = 0 이면
		 *   $time_till = 2026-11-23 06:59:59
		 *   $time_from = $time_till - 86399초 = 2026-11-22 07:00:00   (끝 시각을 포함해 정확히 24시간)
		 */
		$time_till = strtotime(date('Y-m-d').' 06:59:59') - $period * self::DAY;
		$time_from = $time_till - (self::DAY - 1);

		// ── 설정 읽기 ──
		// dirname(__DIR__, 3): __DIR__ 에서 세 단계 위 폴더. 설정 파일은 "return [ ... ];" 형태다.
		$config = require dirname(__DIR__, 3).self::CONFIG_FILE;
		// ?? '' : 설정에 그 키가 없으면 빈 문자열로 본다. trim() 은 앞뒤 공백을 지운다.
		$tag_name = trim((string) ($config['tag_name'] ?? ''));

		// ── 항목별 집계 ──
		$rows = [];
		// 태그가 비어 있어 집계하지 못한 항목이 하나라도 있으면 화면에 안내를 띄운다.
		$config_missing = false;

		foreach (self::CHECKS as $key => $label) {
			$tag_value = trim((string) ($config['tag_values'][$key] ?? ''));

			if ($tag_name === '' || $tag_value === '') {
				$config_missing = true;
				// 숫자 자리에 null 을 넣으면 뷰가 '-' 로 표시한다.
				$result = ['ok' => null, 'fail' => null, 'total' => null, 'fail_hosts' => []];
			}
			else {
				$result = $this->getCheckResult($tag_name, $tag_value, $time_from, $time_till);
			}

			// 배열 + 배열: 두 배열을 합친다. (label, tag, ok, fail, total, fail_hosts 를 가진 한 줄이 된다)
			$rows[] = ['label' => Lang::t($label), 'tag' => $tag_value] + $result;
		}

		$title = Lang::t('Daily check');

		$response = new CControllerResponseData([
			'action' => $this->getAction(),
			'title' => $title,
			'period' => $period,
			'today' => date('Y-m-d'),
			'date_from' => date('Y-m-d', $time_from),
			'date_till' => date('Y-m-d', $time_till),
			// 호스트 이름을 눌렀을 때 여는 "장애" 화면의 조건으로 쓴다. (뷰 참고)
			'tag_name' => $tag_name,
			'config_missing' => $config_missing,
			// ZBX_FULL_DATE_TIME: 코어 상수. 'Y-m-d H:i:s' 형식이다.
			'time_from' => date(ZBX_FULL_DATE_TIME, $time_from),
			'time_till' => date(ZBX_FULL_DATE_TIME, $time_till),
			'rows' => $rows
		]);
		$response->setTitle($title);

		$this->setResponse($response);
	}

	/**
	 * 점검 항목 하나의 결과.
	 *
	 * 태그(이름=값)가 달린 트리거를 찾고, 그 트리거가 구간 안에 한 번이라도 울렸는지 확인해서 호스트 수를 센다.
	 * 호스트 하나에 해당 트리거가 여러 개 있어도 호스트는 한 번만 센다.
	 *
	 * @param string $tag_name   트리거 태그 이름.
	 * @param string $tag_value  트리거 태그 값.
	 * @param int    $time_from  점검 구간 시작(유닉스 초)
	 * @param int    $time_till  점검 구간 끝(유닉스 초)
	 *
	 * @return array  [
	 *     'ok'         => 정상호스트 수,
	 *     'fail'       => 장애발생 호스트 수,
	 *     'total'      => 점검대상 수,
	 *     'fail_hosts' => [['hostid' => .., 'name' => ..], ...]   // 장애발생 호스트. 이름순
	 * ]
	 */
	private function getCheckResult(string $tag_name, string $tag_value, int $time_from, int $time_till): array {
		/*
		 * 1) 이 태그가 달린 트리거와 그 트리거가 속한 호스트.
		 *
		 *    tags        : 태그 조건. TAG_OPERATOR_EQUAL = 값이 정확히 같은 것만. (기본값은 "포함"이라 꼭 지정한다)
		 *    templated   : false = 템플릿에 있는 원본 트리거는 빼고, 실제 호스트의 트리거만.
		 *    selectHosts : 각 트리거에 'hosts' => [[hostid, name], ...] 를 붙인다.
		 *
		 *    중지된 트리거/호스트도 포함한다. (5버전도 상태를 가리지 않고 셌다)
		 */
		$triggers = API::Trigger()->get([
			'output' => ['triggerid'],
			'tags' => [
				['tag' => $tag_name, 'value' => $tag_value, 'operator' => TAG_OPERATOR_EQUAL]
			],
			'templated' => false,
			'selectHosts' => ['hostid', 'name'],
			'preservekeys' => true
		]);

		if (!$triggers) {
			return ['ok' => 0, 'fail' => 0, 'total' => 0, 'fail_hosts' => []];
		}

		// 점검대상 호스트. [hostid => 이름]  (키로 넣으므로 같은 호스트가 여러 트리거에 있어도 한 번만 세어진다)
		$all_hosts = [];

		foreach ($triggers as $trigger) {
			foreach ($trigger['hosts'] as $host) {
				$all_hosts[$host['hostid']] = $host['name'];
			}
		}

		/*
		 * 2) 그 트리거들 중 구간 안에 장애 이벤트가 있었던 것.
		 *    트리거별로 묶어 개수만 센다. 결과에 나온 트리거 = 한 번 이상 울린 트리거다.
		 *
		 *    countOutput + groupBy : 이벤트를 그대로 받지 않고, objectid(트리거 ID)별 개수만 받는다.
		 *                            결과: [['objectid' => 트리거ID, 'rowscount' => 개수], ...]
		 *    source / object       : "트리거가 만든 이벤트"만.
		 *    objectids             : 이 트리거들의 이벤트만.
		 *    value                 : TRIGGER_VALUE_TRUE = 장애(PROBLEM) 이벤트만. 복구 이벤트는 세지 않는다.
		 *
		 *    time_till 은 "이하"다. $time_till 이 이미 06:59:59 라서 그대로 넘기면 06:59:59 까지 들어간다.
		 *    (5버전은 끝을 06:59:59 로 잡고 "clock < 끝" 으로 비교해서 06:59:59 의 1초가 빠졌다. 그 경계 오류는 옮기지 않았다)
		 */
		$fired = API::Event()->get([
			'countOutput' => true,
			'groupBy' => ['objectid'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'objectids' => array_keys($triggers),
			'value' => TRIGGER_VALUE_TRUE,
			'time_from' => $time_from,
			'time_till' => $time_till
		]);

		// 3) 울린 트리거가 속한 호스트 = 장애발생 호스트.
		$fail_hosts = [];

		foreach ($fired as $row) {
			foreach ($triggers[$row['objectid']]['hosts'] as $host) {
				$fail_hosts[$host['hostid']] = ['hostid' => $host['hostid'], 'name' => $host['name']];
			}
		}

		// 이름순 정렬. (대소문자 무시, 숫자가 섞인 이름도 자연스럽게)
		CArrayHelper::sort($fail_hosts, ['name']);

		// count(): 배열의 원소 수.
		$total = count($all_hosts);
		$fail = count($fail_hosts);

		return [
			'ok' => $total - $fail,
			'fail' => $fail,
			'total' => $total,
			// array_values(): 키(hostid)를 버리고 0, 1, 2 ... 순서의 배열로 바꾼다.
			'fail_hosts' => array_values($fail_hosts)
		];
	}
}
