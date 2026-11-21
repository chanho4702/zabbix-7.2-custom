<?php declare(strict_types = 0);
/*
 * 고사용 서버 사용현황 화면 컨트롤러.
 *
 *   화면     : zabbix.php?action=highserver.list
 *   내보내기 : zabbix.php?action=highserver.export&format=csv 또는 xlsx
 *   메뉴     : 보고서 > 고사용 서버 사용현황   (Module.php 의 REPORT_MENUS)
 *   5버전    : zabbix.php?action=highserver
 *
 * 필터, 표, 계산은 저사용 화면과 같아서 공통 컨트롤러가 처리한다.
 *   -> actions/reports/serverusage/ServerUsageController.php  (화면 구성과 파일 목록도 거기에 있다)
 * 이 파일에는 고사용 화면만의 내용(이름, 제목, 판정 기준)만 있다.
 *
 * 판정
 *   평균 CPU 사용률이 high_avg 이상  그리고  Peak 가 high_peak 이상인 서버만 표시한다.
 *   기준 값은 conf/serverusage.conf.php 의 thresholds 에 있다. (기본 50 / 70)
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ TODO(고객환경) 판정 조건 확인                                           │
 * │ 5버전 고사용 컨트롤러의 소스는 확인하지 못했다. 화면의 안내문           │
 * │ "고사용 기준: 평균사용률 50% 이상 / Peak 70..." 과 저사용 화면의 방식을 │
 * │ 근거로 "평균 이상 그리고 Peak 이상" 으로 만들었다.                      │
 * │ 5버전 소스에서 아래를 확인해 다르면 matches() 한 줄을 고친다.           │
 * │   - 두 조건이 "그리고" 인지 "또는" 인지                                 │
 * │   - Peak 기준의 방향과 값                                               │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

namespace Modules\DMove\Actions\Reports\HighServer;

use Modules\DMove\Actions\Reports\ServerUsage\ServerUsageController;
use Modules\DMove\Includes\Lang;

class HighServerList extends ServerUsageController {

	protected function getPageKey(): string {
		return 'highserver';
	}

	protected function getPageTitle(): string {
		return Lang::t('High usage servers');
	}

	protected function getCriteriaText(array $thresholds): string {
		return Lang::t('* High usage criteria: average usage %1$s%% or more / Peak %2$s%% or more',
			$thresholds['high_avg'], $thresholds['high_peak']
		);
	}

	protected function matches(float $avg, float $peak, array $thresholds): bool {
		return $avg >= $thresholds['high_avg'] && $peak >= $thresholds['high_peak'];
	}
}
