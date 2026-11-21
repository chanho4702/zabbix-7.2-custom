<?php declare(strict_types = 0);
/*
 * 저사용 서버 사용현황 화면 컨트롤러.
 *
 *   화면     : zabbix.php?action=lowserver.list
 *   내보내기 : zabbix.php?action=lowserver.export&format=csv 또는 xlsx
 *   메뉴     : 보고서 > 저사용 서버 사용현황   (Module.php 의 REPORT_MENUS)
 *   5버전    : zabbix.php?action=lowserver    (컨트롤러 CControllerLowserver.php)
 *
 * 필터, 표, 계산은 고사용 화면과 같아서 공통 컨트롤러가 처리한다.
 *   -> actions/reports/serverusage/ServerUsageController.php  (화면 구성과 파일 목록도 거기에 있다)
 * 이 파일에는 저사용 화면만의 내용(이름, 제목, 판정 기준)만 있다.
 *
 * 판정 (5버전과 같다)
 *   평균 CPU 사용률이 low_avg 이하  그리고  Peak 가 low_peak 이하인 서버만 표시한다.
 *   기준 값은 conf/serverusage.conf.php 의 thresholds 에 있다. (기본 30 / 70)
 */

namespace Modules\DMove\Actions\Reports\LowServer;

use Modules\DMove\Actions\Reports\ServerUsage\ServerUsageController;
use Modules\DMove\Includes\Lang;

class LowServerList extends ServerUsageController {

	protected function getPageKey(): string {
		return 'lowserver';
	}

	protected function getPageTitle(): string {
		return Lang::t('Low usage servers');
	}

	protected function getCriteriaText(array $thresholds): string {
		// %1$s, %2$s 자리에 기준 값이 들어간다.
		return Lang::t('* Low usage criteria: average usage %1$s%% or less / Peak %2$s%% or less',
			$thresholds['low_avg'], $thresholds['low_peak']
		);
	}

	protected function matches(float $avg, float $peak, array $thresholds): bool {
		return $avg <= $thresholds['low_avg'] && $peak <= $thresholds['low_peak'];
	}
}
