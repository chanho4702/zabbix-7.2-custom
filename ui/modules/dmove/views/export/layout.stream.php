<?php declare(strict_types = 0);
/*
 * 큰 CSV 를 "읽는 대로 바로" 브라우저로 내려보내는 레이아웃.
 *
 * ── 이 파일이 하는 일 ──
 *   CPU/Memory 전체 사용률 추출처럼 줄 수가 수백만 개가 될 수 있는 내보내기에 쓴다.
 *   보통 내보내기(views/export/layout.file.php)는 파일 전체를 문자열로 만든 뒤 한 번에 내보낸다.
 *   그 방식은 줄이 많으면 PHP 메모리가 모자란다. 여기서는 DB 에서 읽는 대로 조금씩 내보낸다.
 *
 * ── 누가 쓰는가 ──
 *   manifest.json 의 "usageexport.export" 액션이 "layout": "export/layout.stream" 으로 이 파일을 가리킨다.
 *   컨트롤러(actions/reports/usageexport/UsageExportList.php)가 넘긴 계획을 UsageExportService::stream() 에 넘긴다.
 *
 * ── 받는 값 ($data) ──
 *   stream_plan     UsageExportService::buildPlan() 의 결과 (어느 아이템을, 어느 기간으로 내보낼지)
 *   page['file']    다운로드 파일명 (컨트롤러의 setFileName 값)
 *
 * ── 왜 출력 버퍼를 닫고, 끝에서 exit 하는가 ──
 *   코어는 뷰/레이아웃의 출력을 일단 메모리에 모았다가(출력 버퍼) 끝난 뒤 한꺼번에 내보낸다. (CView::getOutput())
 *   그대로 두면 "읽는 대로 내보내기"가 되지 않고 전체가 메모리에 쌓인다.
 *   그래서 열려 있는 출력 버퍼를 모두 닫고 직접 내보낸 뒤, 코어가 남은 처리를 하지 않도록 exit 로 끝낸다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\UsageExportService;

// 열려 있는 출력 버퍼를 모두 닫는다. ob_get_level(): 겹쳐 열린 버퍼의 수. ob_end_clean(): 버퍼 하나를 버리고 닫는다.
while (ob_get_level() > 0) {
	ob_end_clean();
}

/*
 * 세션 잠금을 푼다. PHP 는 요청 하나가 세션을 쓰는 동안 같은 사용자의 다른 요청을 기다리게 한다.
 * 내려받기가 몇 분 걸리면 그동안 같은 브라우저의 다른 화면이 멈춘다. 이 뒤로는 세션 값을 바꾸지 않으므로 닫아도 된다.
 *   session_status() === PHP_SESSION_ACTIVE : 세션이 열려 있을 때만 닫는다.
 */
if (session_status() === PHP_SESSION_ACTIVE) {
	session_write_close();
}

// 줄 수가 많으면 PHP 기본 실행 시간(보통 300초)을 넘길 수 있다. 0 = 시간 제한 없음. (5버전도 같은 설정을 썼다)
set_time_limit(0);

// header(): HTTP 응답 헤더를 보낸다. 본문을 출력하기 전에 불러야 한다.
header('Content-Type: text/csv; charset=UTF-8');
// attachment: 브라우저가 화면에 표시하지 않고 파일로 저장하게 한다.
header('Content-Disposition: attachment; filename="'.$data['page']['file'].'"');
// 전체 크기를 미리 알 수 없으므로 Content-Length 는 보내지 않는다.
// 웹 서버(nginx)가 응답을 모아 두지 않고 바로 흘려보내게 한다.
header('X-Accel-Buffering: no');

(new UsageExportService())->stream($data['stream_plan']);

// 코어가 이 뒤에 다른 출력(디버그 정보 등)을 붙이지 않도록 여기서 끝낸다.
exit;
