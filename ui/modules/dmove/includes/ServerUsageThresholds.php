<?php declare(strict_types = 0);
/*
 * 저사용 / 고사용 판정 기준값을 사용자별로 읽고 저장한다.
 *
 * ── 이 파일이 하는 일 ──
 *   저사용/고사용 화면의 [기준설정] 창에서 바꾼 기준값 4개를 "사용자별 설정(프로파일)"에 저장하고,
 *   화면을 조회할 때 그 값을 읽어 준다. 저장한 값이 없으면 conf/serverusage.conf.php 의 기본값을 쓴다.
 *
 *     low_avg   저사용 평균 CPU 사용률(%)   기본 30
 *     low_peak  저사용 Peak 기준(%)         기본 70
 *     high_avg  고사용 평균 CPU 사용률(%)   기본 50
 *     high_peak 고사용 Peak 기준(%)         기본 70
 *
 * ── 누가 쓰는가 ──
 *   actions/reports/serverusage/ServerUsageController.php   목록을 조회할 때 get()
 *   actions/reports/serverusage/ThresholdEdit.php           [기준설정] 창을 열 때 get()
 *   actions/reports/serverusage/ThresholdSave.php           [설정] 을 눌렀을 때 save()
 *
 * ── 5버전 대응 ──
 *   5버전 저사용 컨트롤러는 기준값을 이렇게 읽었다.
 *     $cpulow  = CProfile::get('web.report.server.cpulow');    없으면 기본값
 *     $cpupeak = CProfile::get('web.report.server.peaklow');   없으면 70
 *   프로파일은 사용자마다 따로 저장되는 값이다. 그래서 한 사람이 기준을 바꿔도 다른 사람 화면에는 영향이 없다.
 *
 *   저사용 두 값은 5버전과 같은 프로파일 키를 쓴다. 5버전 DB 를 올린 환경에서는 사용자가 바꿔 둔 값이 그대로 이어진다.
 *   고사용 두 값의 키(cpuhigh, peakhigh)는 5버전 고사용 컨트롤러를 받지 못해서 같은 규칙으로 이름을 지었다.
 *   TODO(고객환경): 5버전 고사용 컨트롤러의 프로파일 키가 다르면 아래 PROFILE_KEYS 의 두 줄을 그 이름으로 바꾼다.
 *                   (다르더라도 화면은 정상 동작한다. 5버전에서 바꿔 둔 고사용 기준이 이어지지 않을 뿐이다)
 */

namespace Modules\DMove\Includes;

use CProfile;

class ServerUsageThresholds {

	/** 기준값 이름 => 프로파일 키. */
	private const PROFILE_KEYS = [
		'low_avg' => 'web.report.server.cpulow',
		'low_peak' => 'web.report.server.peaklow',
		'high_avg' => 'web.report.server.cpuhigh',
		'high_peak' => 'web.report.server.peakhigh'
	];

	/** 입력할 수 있는 범위. (5버전 창의 안내: "1~99 사이의 숫자만 입력해 주세요") */
	public const MIN = 1;
	public const MAX = 99;

	/**
	 * 기준값 이름 목록. 예) ['low_avg', 'low_peak', 'high_avg', 'high_peak']
	 */
	public static function names(): array {
		return array_keys(self::PROFILE_KEYS);
	}

	/**
	 * 지금 로그인한 사용자의 기준값을 돌려준다.
	 *
	 * 사용자가 저장한 값이 있으면 그 값, 없으면(또는 범위를 벗어난 값이면) 기본값이다.
	 *
	 * @param array $defaults  conf/serverusage.conf.php 의 thresholds.
	 *                         예) ['low_avg' => 30, 'low_peak' => 70, 'high_avg' => 50, 'high_peak' => 70]
	 *
	 * @return array  같은 모양의 배열. 값은 정수.
	 */
	public static function get(array $defaults): array {
		$thresholds = [];

		foreach (self::PROFILE_KEYS as $name => $profile_key) {
			$default = (int) ($defaults[$name] ?? 0);
			// CProfile::get(키, 기본값): 저장된 값이 없으면 두 번째 인자를 돌려준다.
			$value = (int) CProfile::get($profile_key, $default);

			$thresholds[$name] = self::isValid($value) ? $value : $default;
		}

		return $thresholds;
	}

	/**
	 * 지금 로그인한 사용자의 기준값을 저장한다.
	 *
	 * @param array $values  ['low_avg' => 30, 'low_peak' => 70, 'high_avg' => 50, 'high_peak' => 70]
	 *                       호출하는 쪽에서 1~99 인지 먼저 검사해야 한다. (ThresholdSave 의 checkInput)
	 */
	public static function save(array $values): void {
		foreach (self::PROFILE_KEYS as $name => $profile_key) {
			// PROFILE_TYPE_INT: 정수로 저장한다. 실제 DB 반영은 요청이 끝날 때 코어가 한꺼번에 한다.
			CProfile::update($profile_key, (int) $values[$name], PROFILE_TYPE_INT);
		}
	}

	/**
	 * 값이 입력 범위(1~99) 안에 있는가.
	 */
	public static function isValid(int $value): bool {
		return $value >= self::MIN && $value <= self::MAX;
	}
}
