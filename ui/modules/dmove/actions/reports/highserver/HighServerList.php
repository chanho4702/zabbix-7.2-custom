<?php declare(strict_types = 0);
/*
 * 고사용 서버 사용현황 화면 컨트롤러.
 *
 * ── 이 화면이 하는 일 ──
 *   고른 기간 동안 CPU 사용률이 높았던 서버를 찾아서 보여 준다.
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
 * ── 데이터 흐름 ──
 *   주소로 요청이 오면 부모 클래스(ServerUsageController)의 doAction() 이 실행된다.
 *   doAction() 은 필요한 곳에서 이 파일의 메소드 4개를 부른다.
 *     getPageKey()      -> 액션 이름과 필터 저장 이름을 만들 때
 *     getPageTitle()    -> 화면 제목을 정할 때
 *     getCriteriaText() -> 표 위의 기준 안내문을 만들 때
 *     matches()         -> 서버 한 대씩, 목록에 남길지 정할 때
 *
 *   class A extends B : A 가 B 의 메소드를 모두 물려받는다는 뜻이다. 여기에 없는 메소드(init, checkInput,
 *                       doAction 등)는 부모의 것이 그대로 쓰인다.
 *
 * 판정
 *   평균 CPU 사용률이 high_avg 이상  그리고  Peak 가 high_peak 이상인 서버만 표시한다.
 *   기준 값은 conf/serverusage.conf.php 의 thresholds 에 있다. (기본 50 / 70)
 *
 * ── 5버전과 대조 (2026-11-24, 5버전 CControllerHighserver.php) ──
 *   5버전은 "평균 < cpuhigh(기본 50) 또는 최대 < peakhigh(기본 70)" 인 서버를 뺐다.
 *   남는 서버 = 평균 50 이상 그리고 Peak 70 이상. 아래 matches() 와 같다.
 *   (5버전 고사용 컨트롤러는 저사용 코드를 복사해 필터 저장 이름이 web.lowserver.filter.* 로 저사용과 같았다.
 *    그래서 두 화면의 필터 값이 섞였다. 여기서는 화면마다 따로 저장한다)
 */

namespace Modules\DMove\Actions\Reports\HighServer;

use Modules\DMove\Actions\Reports\ServerUsage\ServerUsageController;
use Modules\DMove\Includes\Lang;

class HighServerList extends ServerUsageController {

	/**
	 * 화면 이름. 부모 클래스가 이 값으로 액션 이름과 필터 저장 이름을 만든다.
	 *
	 * @return string  'highserver'
	 *                 -> 액션 highserver.list / highserver.export, 프로파일 키 'web.dmove.highserver.'
	 */
	protected function getPageKey(): string {
		return 'highserver';
	}

	/**
	 * 화면 제목. 화면 위쪽과 브라우저 탭, 내보내기 파일의 시트 이름에 쓰인다.
	 *
	 * @return string  번역된 제목. Lang::t() 가 lang/ko.php 에서 한국어 문구를 찾아 준다. 없으면 영어 그대로.
	 */
	protected function getPageTitle(): string {
		return Lang::t('High usage servers');
	}

	/**
	 * 표 위 오른쪽에 빨간 글씨로 보여 줄 판정 기준 안내문.
	 *
	 * @param array $thresholds  conf/serverusage.conf.php 의 thresholds.
	 *                           예) ['low_avg' => 30, 'low_peak' => 70, 'high_avg' => 50, 'high_peak' => 70]
	 *
	 * @return string  기준 값이 들어간 안내문.
	 */
	protected function getCriteriaText(array $thresholds): string {
		// %1$s, %2$s 자리에 기준 값(high_avg, high_peak)이 들어간다. %% 는 글자 % 하나다.
		return Lang::t('* High usage criteria: average usage %1$s%% or more / Peak %2$s%% or more',
			$thresholds['high_avg'], $thresholds['high_peak']
		);
	}

	/**
	 * 이 서버가 고사용 서버인가. 부모 클래스의 getRows() 가 서버 한 대마다 부른다.
	 *
	 * @param float $avg         기간 평균 CPU 사용률(%). 예) 62.5
	 * @param float $peak        기간 중 최대 CPU 사용률(%). 예) 91.0
	 * @param array $thresholds  conf/serverusage.conf.php 의 thresholds.
	 *
	 * @return bool  true 면 목록에 남긴다.
	 *               예) 기준이 50 / 70 일 때  평균 62.5, Peak 91.0 -> true
	 *                                         평균 62.5, Peak 65.0 -> false (Peak 가 기준보다 낮다)
	 *                                         평균 50.0, Peak 70.0 -> true  (기준값과 같아도 "이상"이라 포함)
	 */
	protected function matches(float $avg, float $peak, array $thresholds): bool {
		// >= : 크거나 같다(이상).  && : 두 조건이 모두 참일 때만 참(그리고).
		return $avg >= $thresholds['high_avg'] && $peak >= $thresholds['high_peak'];
	}
}
