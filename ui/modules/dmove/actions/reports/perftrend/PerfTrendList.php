<?php declare(strict_types = 0);
/*
 * 성능 데이터 추세 분석 화면 컨트롤러. (껍데기)
 *
 * ── 이 화면이 하는 일 ──
 *   아직 기능이 없다. 메뉴를 누르면 "준비 중" 안내와 빈 표만 나온다.
 *   아래 TODO 에서 방향을 정한 뒤에 채운다.
 *
 *   화면 : zabbix.php?action=perftrend.list
 *   메뉴 : 보고서 > 성능 데이터 추세 분석   (Module.php 의 REPORT_MENUS)
 *   5버전: zabbix.php?action=data.analysis   (화면 제목 "데이터 추세 분석")
 *
 * ── 5버전 화면 구성 ──
 *   위쪽   : 그래프 (가로축 시간)
 *   아래쪽 : 탭 2개 [데이터 셋] [기간]
 *            데이터 셋 = 호스트 패턴 + 아이템 패턴을 여러 줄 추가하고, 줄마다 색/그래프 형식(선, 포인트, 막대)/
 *                        너비/투명도/채우기/결손 데이터/Y축/타임 시프트/집계 함수와 간격을 정한다.
 *            기간      = 그래프에 표시할 기간
 *   버튼   : CSV 내보내기
 *
 *   이 구성은 Zabbix 대시보드의 "그래프" 위젯 설정 화면과 같다. 5버전은 그 위젯의 설정 폼을
 *   독립 화면으로 꺼내 쓰고 CSV 내보내기를 붙인 것으로 보인다.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ TODO(고객환경) 이 화면은 메뉴와 빈 페이지만 있다. 먼저 방향을 정한다.   │
 * │                                                                         │
 * │ (가) 코어 기능으로 대체 (권장)                                          │
 * │      7버전 대시보드에 "그래프" 위젯을 올리면 같은 설정(호스트/아이템    │
 * │      패턴, 색, 형식, 집계, 기간)으로 같은 그래프가 나온다. 코드가 없다. │
 * │      값이 필요하면 최신 데이터 화면이나 위젯의 값 내려받기를 쓴다.      │
 * │      이 메뉴는 그 대시보드로 가는 링크로 바꾸면 된다.                   │
 * │                                                                         │
 * │ (나) 5버전처럼 독립 화면으로 구현                                       │
 * │      그래프 위젯의 설정 폼과 그리기 코드를 모듈로 가져와야 해서 양이    │
 * │      많고, Zabbix 버전이 바뀔 때마다 맞춰야 한다. CSV 의 열 구성과      │
 * │      집계 방식은 5버전 컨트롤러에서 확인해야 한다.                      │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * ── 데이터 흐름 (지금) ──
 *   doAction()
 *     ├─ 빈 목록($rows = [])을 만든다
 *     └─ 뷰(views/reports/perftrend.list.php)로 넘긴다. 뷰는 안내 상자와 빈 표를 그린다
 *
 * ── 이 화면에 속한 파일 ──
 *   actions/reports/perftrend/PerfTrendList.php   컨트롤러 (이 파일)
 *   views/reports/perftrend.list.php              화면 뷰
 *   template/                                     필터/정렬/페이징/내보내기가 들어 있는 예시 (구현할 때 참고)
 */

namespace Modules\DMove\Actions\Reports\PerfTrend;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CWebUser;
use Modules\DMove\Includes\Lang;

class PerfTrendList extends CController {

	/**
	 * 컨트롤러가 만들어질 때 한 번 불린다.
	 * 조회만 하는 화면이라 CSRF 토큰 검사를 끈다.
	 */
	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다. 데이터를 저장/삭제하는 액션에서는 끄면 안 된다.
		$this->disableCsrfValidation();
	}

	/**
	 * 입력값 검증. 여기에 적지 않은 파라미터는 getInput() 으로 읽을 수 없다.
	 * 필터를 추가하면 'filter_xxx' => 'string' 같은 줄을 넣는다. (규칙 예시는 template 참고)
	 *
	 *   'ge 1' : 1 이상의 숫자. page 는 페이지 번호다. (지금은 받기만 하고 쓰지 않는다)
	 *
	 * @return bool  검사를 통과하면 true. 실패하면 오류 화면(CControllerResponseFatal)이 나온다.
	 */
	protected function checkInput(): bool {
		$fields = [
			'page' => 'ge 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 지금은 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 *
	 * TODO(고객환경): 권한 정책에 맞게 바꾼다.
	 *   예) 관리자 이상만: return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;
	 *   예) 코어 보고서 권한을 따라가기: return $this->checkAccess(\CRoleHelper::UI_REPORTS_SYSTEM_INFO);
	 *
	 * @return bool  화면을 열어도 되면 true.
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 화면을 만드는 본체. 입력 검사와 권한 검사를 통과하면 불린다.
	 * 지금은 빈 목록과 제목만 뷰로 넘긴다.
	 *
	 * 뷰가 받는 $data:
	 *   [
	 *     'action' => 'perftrend.list',   // 이 화면의 액션 이름
	 *     'title'  => 화면 제목,
	 *     'rows'   => []                  // 표의 행. 아직 비어 있다
	 *   ]
	 */
	protected function doAction(): void {
		// TODO(고객환경): 여기에서 데이터를 조회해 $rows 에 넣는다. (행 1개 = 표의 한 줄)
		$rows = [];

		// 뷰로 넘길 데이터. 뷰에서는 $data['키'] 로 읽는다.
		$data = [
			'action' => $this->getAction(),
			'title' => Lang::t('Performance trend analysis'),
			'rows' => $rows
		];

		// CControllerResponseData: "이 데이터를 뷰에 넘겨서 화면을 그려라"는 응답 객체다.
		$response = new CControllerResponseData($data);
		$response->setTitle($data['title']);	// 브라우저 탭 제목

		$this->setResponse($response);
	}
}
