<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 상세정보 팝업 컨트롤러.
 *
 * ── 이 팝업이 하는 일 ──
 *   윈도우 서버 한 대에 설치된 패치 목록을 표로 보여 준다.
 *   윈도우즈 패치 현황 화면에서 어느 줄의 "상세보기" 링크를 누르면 열린다.
 *   필터에서 고른 "수집월" 안에 수집된 값 중 가장 최근 것을 보여 준다.
 *
 *   호출 : 목록 화면의 "상세보기" 링크 -> 스크립트의 PopUp('winpatch.detail', {hostid, month})
 *          주소로는 zabbix.php?action=winpatch.detail&hostid=10084&month=2026-10-01
 *   5버전: popup.windows
 *
 * ── 팝업 구성 ──
 *   제목 : Windows 패치 상세정보
 *   표   : conf/winpatch.conf.php 의 columns 에 적은 열 (Caption, CSName, Description, ... 10개)
 *          줄 1개 = 설치된 패치 1개
 *
 * ── 데이터 흐름 ──
 *   doAction()
 *     ├─ WinPatchService::getDetail(hostid, month)
 *     │    ├─ host.get    : 그 호스트를 사용자가 볼 수 있는지 확인하고 이름을 읽는다
 *     │    ├─ item.get    : 그 호스트의 패치 목록 아이템을 찾는다
 *     │    ├─ history.get : 그 달에 수집된 값 중 가장 최근 것 1건
 *     │    └─ parse()     : 긴 글을 표(행 배열)로 바꾼다
 *     └─ 뷰(views/reports/winpatch.detail.php)가 팝업용 JSON 으로 출력
 *
 * 팝업은 일반 화면과 세 가지가 다르다.
 *   1) manifest.json 에 "layout": "layout.json" 으로 등록한다. 화면 전체가 아니라 JSON 을 돌려준다.
 *   2) 뷰가 화면을 그리지 않고 {header: 제목, body: 내용 HTML, buttons: 버튼} 을 JSON 으로 출력한다.
 *   3) 스크립트의 PopUp() 이 그 JSON 을 받아 팝업 창을 만든다.
 *
 * 이 팝업에 속한 파일
 *   actions/reports/winpatch/WinPatchDetail.php   컨트롤러 (이 파일)
 *   views/reports/winpatch.detail.php             뷰 (JSON 출력)
 *   views/js/reports/winpatch.list.js.php         PopUp() 을 부르는 스크립트
 *   includes/WinPatchService.php                  데이터 처리
 */

namespace Modules\DMove\Actions\Reports\WinPatch;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CWebUser;
use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\WinPatchService;

class WinPatchDetail extends CController {

	/**
	 * 컨트롤러가 만들어질 때 코어가 한 번 부른다.
	 * 조회만 하는 팝업이라 CSRF 토큰 검사를 끈다. (값을 바꾸는 액션에서는 끄면 안 된다)
	 *
	 * @return void  돌려주는 값이 없다.
	 */
	protected function init(): void {
		// 조회 전용이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

	/**
	 * 주소로 들어온 값 검사. 두 값 모두 반드시 있어야 한다.
	 *
	 *   hostid  어느 호스트의 패치를 볼지. 예) 10084
	 *   month   어느 달을 볼지. 날짜 하나를 받고, 그 날짜가 속한 달 전체를 본다. 예) 2026-10-01
	 *
	 * 규칙을 | 로 이으면 "둘 다 만족해야 한다"는 뜻이다.
	 *
	 * @return bool  검사를 통과하면 true. false 면 오류 응답이 나가고 doAction() 은 실행되지 않는다.
	 */
	protected function checkInput(): bool {
		$fields = [
			// 'required' = 반드시 있어야 하는 값. 'db hosts.hostid' = hosts 테이블의 hostid 형식(숫자 ID).
			'hostid' =>	'required|db hosts.hostid',
			// 'abs_date' = YYYY-MM-DD 형식의 실제 날짜.
			'month' =>	'required|abs_date'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 목록 화면과 같다.
	 * 어느 호스트를 볼 수 있는지는 WinPatchService 안의 API 호출이 걸러 준다.
	 *
	 * @return bool  로그인한 사용자(게스트 제외)면 true.
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * 팝업 내용을 만드는 본체. 입력 검사와 권한 검사를 통과하면 코어가 부른다.
	 *
	 * 뷰로 넘기는 $data 의 키
	 *   title    팝업 제목
	 *   host     호스트 표시 이름. 예) 'win-server-01'   (찾지 못하면 '')
	 *   columns  표의 열 이름. 예) ['Caption', 'CSName', ...]
	 *   rows     표의 행. [['Caption' => '...', 'CSName' => '...', ...], ...]   (값이 없으면 빈 배열)
	 *   user     ['debug_mode' => 0 또는 1]
	 *
	 * @return void  돌려주는 값은 없다. 결과는 $this->setResponse() 로 코어에 넘긴다.
	 */
	protected function doAction(): void {
		$service = new WinPatchService();

		// 볼 수 없는 호스트이거나 패치 아이템이 없으면 null.
		// 있으면 ['host' => 호스트 표시 이름, 'rows' => [[열 이름 => 값, ...], ...]]
		$detail = $service->getDetail($this->getInput('hostid'), $this->getInput('month'));

		$this->setResponse(new CControllerResponseData([
			'title' => Lang::t('Windows patch details'),
			// 조건 ? A : B : 조건이 참이면 A, 거짓이면 B. null 일 때 $detail['host'] 를 읽으면 경고가 나므로 먼저 확인한다.
			'host' => $detail !== null ? $detail['host'] : '',
			'columns' => $service->getColumns(),
			'rows' => $detail !== null ? $detail['rows'] : [],
			// 디버그 모드 사용자에게 디버그 정보를 붙일 때 코어가 쓰는 값. 팝업 뷰의 관례다.
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}
}
