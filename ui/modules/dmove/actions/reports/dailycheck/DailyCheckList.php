<?php declare(strict_types = 0);
/*
 * 일일점검 화면 컨트롤러.
 *
 *   화면 : zabbix.php?action=dailycheck.list            (기본: 어제 07:00 ~ 오늘 06:59)
 *          zabbix.php?action=dailycheck.list&period=1   (D-1: 하루 전 구간)
 *          zabbix.php?action=dailycheck.list&period=2   (D-2: 이틀 전 구간)
 *   메뉴 : 보고서 > 일일점검   (Module.php 의 REPORT_MENUS)
 *   5버전: zabbix.php?action=daily, &period1=1, &period2=1   (컨트롤러 CControllerDaily.php, 규칙 daily.json)
 *
 * 5버전 화면 구성
 *   제목 아래 : 오늘 날짜, [D-1] [D-2] 버튼, (점검 구간의 시작일 ~ 종료일)
 *   표        : 점검항목 | 정상호스트 | 장애발생 호스트 | 점검대상(*전체호스트)
 *   행 7개    : OS기동상태 / 업무솔루션 프로세스 기동여부 / Disk Usage 90% 초과 /
 *               Disk Usage 전일 대비 30% 급증 / CPU Usage(업무시간) 전일 대비 20% 급증 /
 *               Paging space 기준 초과(1%) / 이중화 솔루션 상태
 *   장애발생 호스트 숫자를 누르면 해당 호스트 이름 목록이 뜬다.
 *   필터는 없다.
 *
 * 5버전과 달라진 점
 *   - 기간 파라미터를 period1=1 / period2=1 두 개에서 period=1 / period=2 하나로 합쳤다.
 *   - 5버전은 점검 항목 목록을 daily.json 에서, 태그를 트레잇 안에서 읽었다.
 *     여기서는 항목 목록을 아래 CHECKS 상수에, 태그를 conf/dailycheck.conf.php 에 둔다.
 *
 * 집계 방식 (5버전 일일점검 트레잇과 같다)
 *   점검 항목 7개 모두 같은 방법으로 센다. 항목마다 "트리거 태그"가 하나씩 정해져 있다. (아래 CHECKS)
 *
 *     점검대상       = 그 태그(이름=값)가 달린 트리거를 가진 호스트 수
 *     장애발생 호스트 = 그 트리거 중 하나라도 점검 구간 안에 장애(PROBLEM) 이벤트가 발생한 호스트 수
 *     정상호스트     = 점검대상 - 장애발생 호스트
 *
 *   즉 이 화면은 값을 직접 계산하지 않고 "태그가 달린 트리거가 구간 안에 울렸는가"만 본다.
 *   그래서 숫자가 맞게 나오려면 7버전 서버의 트리거(또는 템플릿의 트리거)에 5버전과 같은 태그가 달려 있어야 한다.
 *   태그의 이름과 항목별 값은 conf/dailycheck.conf.php 에 적는다. (공개 저장소라 비워 두었다)
 *   해당 태그가 달린 트리거가 하나도 없으면 그 항목은 점검대상 0 으로 나온다.
 *
 * 5버전과 달라진 점
 *   - trigger_tag / functions / items / events 테이블을 직접 조회하던 것을 API(trigger.get, event.get)로 바꿨다.
 *     결과는 같고, 로그인한 사용자가 볼 수 있는 호스트만 세어진다. (5버전은 점검대상을 구할 때만 권한이 적용됐다)
 *   - 5버전 소스에 있던 아이템 값 직접 비교(전일 대비 30% / 20% 를 trends 로 계산) 함수는 호출되지 않는
 *     코드였다. 옮기지 않았다. 30% / 20% 판정은 해당 태그가 달린 트리거가 한다.
 *
 * TODO(고객환경)
 *   - conf/dailycheck.conf.php 에 태그 이름과 값을 채운다.
 *   - 7버전 서버의 트리거에 그 태그가 5버전과 같게 달려 있는지 확인한다.
 *   - 5버전 화면 오른쪽 위의 [CPU 사용률 Top 10] [Memory 사용률 Top 10] 버튼이 여는 화면을 확인한다.
 *
 * 이 화면에 속한 파일
 *   actions/reports/dailycheck/DailyCheckList.php   컨트롤러 (이 파일)
 *   views/reports/dailycheck.list.php               화면 뷰
 *   conf/dailycheck.conf.php                        태그 이름과 항목별 값
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

	/** 하루 = 86400 초. */
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

	/** 설정 파일 위치. __DIR__ 에서 네 단계 위가 모듈 폴더다. (dailycheck -> reports -> actions -> dmove) */
	private const CONFIG_FILE = '/conf/dailycheck.conf.php';

	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

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
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	protected function doAction(): void {
		$period = (int) $this->getInput('period', 0);

		/*
		 * ── 점검 구간 (5버전과 같은 계산) ──
		 *   기본 : 어제 07:00:00 ~ 오늘 06:59:59
		 *   D-1  : 그보다 하루 전
		 *   D-2  : 그보다 이틀 전
		 *
		 * date('Y-m-d') 는 오늘 날짜 문자열, strtotime() 은 날짜 문자열을 유닉스 시간(초)으로 바꾼다.
		 * 시간대는 로그인한 사용자의 시간대 설정을 따른다.
		 */
		$time_till = strtotime(date('Y-m-d').' 06:59:59') - $period * self::DAY;
		$time_from = $time_till - (self::DAY - 1);

		// ── 설정 읽기 ──
		// dirname(__DIR__, 3): __DIR__ 에서 세 단계 위 폴더. 설정 파일은 "return [ ... ];" 형태다.
		$config = require dirname(__DIR__, 3).self::CONFIG_FILE;
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

		$total = count($all_hosts);
		$fail = count($fail_hosts);

		return [
			'ok' => $total - $fail,
			'fail' => $fail,
			'total' => $total,
			'fail_hosts' => array_values($fail_hosts)
		];
	}
}
