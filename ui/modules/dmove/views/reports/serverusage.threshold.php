<?php declare(strict_types = 0);
/*
 * 저사용/고사용 화면의 [기준설정] 창("수집기준 설정")의 내용.
 *
 * 컨트롤러: actions/reports/serverusage/ThresholdEdit.php
 * 5버전   : popup.lowserver (뷰 소스는 받지 못했다. 5버전 화면에서 창의 구성을 확인했다)
 *
 * 팝업 뷰는 화면을 그리지 않고(->show() 없음) 아래 형태의 JSON 을 출력한다.
 *   header  : 팝업 제목
 *   body    : 팝업 안에 들어갈 HTML 문자열 (여기서는 입력 폼)
 *   buttons : 팝업 아래쪽 버튼 목록
 *
 * ── 창 구성 ──
 *   * 저사용 평균 CPU 사용률(%)  [30] % 이하
 *   * 저사용 Peak기준            [70] % 이하
 *   * 고사용 평균 CPU 사용률(%)  [50] % 이상
 *   * 고사용 Peak기준            [70] % 이상
 *   1~99 사이의 숫자만 입력해 주세요. / 모든 항목은 필수입력값입니다.
 *   [설정] [취소]
 *
 *   5버전 창에는 저사용 쪽이 "%미만"으로 적혀 있었다. 실제 판정은 "이하"였으므로 여기서는 "이하"로 적는다.
 *   (목록 화면의 기준 안내문과 같은 표현이다. actions/reports/lowserver/LowServerList.php 의 matches() 설명 참고)
 *
 * ── 받는 값 ($data) ──
 *   thresholds  ['low_avg' => 30, 'low_peak' => 70, 'high_avg' => 50, 'high_peak' => 70]   지금 사용자의 기준값
 *   min, max    입력 범위. 1, 99
 *   user        ['debug_mode' => 0 또는 1]
 *
 * ── [설정] 을 누르면 ──
 *   버튼의 action 이 화면 쪽 스크립트 dmoveServerUsageList.submitThreshold(overlay) 를 부른다.
 *   그 함수가 이 폼의 값을 serverusage.threshold.save 로 보낸다. (views/js/reports/serverusage.list.js.php)
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Lang;

/*
 * 입력 한 줄을 만든다: [숫자 입력칸] + 뒤에 붙는 글자("% 이하" 등).
 *   CNumericBox(이름, 값, 최대 글자 수) : 숫자만 입력할 수 있는 칸.
 *   setAriaRequired()                  : 필수 입력이라는 표시(화면 낭독기용).
 */
$make_input = static function (string $name, int $value, string $suffix): array {
	return [
		(new CNumericBox($name, $value, 2))
			->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
			->setAriaRequired(),
		' ',
		$suffix
	];
};

$or_less = Lang::t('% or less');
$or_more = Lang::t('% or more');

/*
 * CFormList : "라벨 + 입력" 을 한 줄씩 쌓는 폼 부품.
 * CLabel(...)->setAsteriskMark() : 라벨 앞에 빨간 별표(*)를 붙인다. 필수 입력 표시다.
 */
$form_list = (new CFormList())
	->addRow((new CLabel(Lang::t('Low usage average CPU usage (%)'), 'low_avg'))->setAsteriskMark(),
		$make_input('low_avg', $data['thresholds']['low_avg'], $or_less)
	)
	->addRow((new CLabel(Lang::t('Low usage Peak criterion'), 'low_peak'))->setAsteriskMark(),
		$make_input('low_peak', $data['thresholds']['low_peak'], $or_less)
	)
	->addRow((new CLabel(Lang::t('High usage average CPU usage (%)'), 'high_avg'))->setAsteriskMark(),
		$make_input('high_avg', $data['thresholds']['high_avg'], $or_more)
	)
	->addRow((new CLabel(Lang::t('High usage Peak criterion'), 'high_peak'))->setAsteriskMark(),
		$make_input('high_peak', $data['thresholds']['high_peak'], $or_more)
	)
	// 안내 두 줄. 라벨 자리를 비우고('') 빨간 글씨로 넣는다.
	->addRow('', [
		(new CDiv(Lang::t('Enter numbers between %1$d and %2$d only.', $data['min'], $data['max'])))
			->addClass(ZBX_STYLE_RED),
		(new CDiv(Lang::t('All fields are required.')))->addClass(ZBX_STYLE_RED)
	]);

$form = (new CForm())
	->setId('dmove-threshold-form')
	->setName('dmove_threshold_form')
	/*
	 * CSRF 토큰. 저장 컨트롤러(serverusage.threshold.save)가 이 값이 맞는지 검사한다.
	 * 모듈 컨트롤러는 "액션 이름 전체"로 만든 토큰을 받는다. (코어 CController::checkCsrfToken)
	 */
	->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('serverusage.threshold.save')))->removeId())
	// 입력칸에서 Enter 를 눌러도 [설정] 버튼과 같은 동작을 하도록 보이지 않는 제출 버튼을 둔다. (코어 팝업 폼의 관례)
	->addItem((new CInput('submit', null))->addStyle('display: none;'))
	->addItem($form_list);

$output = [
	'header' => Lang::t('Collection criteria settings'),
	// toString(): 화면 클래스를 HTML 문자열로 바꾼다. 값의 HTML 이스케이프도 이때 된다.
	'body' => $form->toString(),
	// 팝업 창의 모양(class). 빠뜨리면 창 class 가 "modal-popup undefined" 가 된다. (코어 팝업 뷰도 같이 적는다)
	'dialogue_class' => 'modal-popup-generic',
	'buttons' => [
		[
			'title' => Lang::t('Set'),
			'class' => '',
			// keepOpen : 눌러도 창을 바로 닫지 않는다. 저장이 성공하면 스크립트가 닫는다.
			'keepOpen' => true,
			// isSubmit : 폼의 기본 버튼. 입력칸에서 Enter 를 누르면 이 버튼이 눌린다.
			'isSubmit' => true,
			// overlay : 이 팝업 창 객체. 코어가 action 을 실행할 때 넘겨 준다.
			'action' => 'dmoveServerUsageList.submitThreshold(overlay);'
		]
		// [취소] 버튼은 적지 않아도 코어 PopUp() 이 자동으로 붙인다.
	]
];

// 디버그 모드인 사용자에게는 코어의 디버그 정보(실행한 SQL 등)를 붙인다. 코어 팝업 뷰와 같은 처리다.
if ($data['user']['debug_mode'] == GROUP_DEBUG_MODE_ENABLED) {
	CProfiler::getInstance()->stop();
	$output['debug'] = CProfiler::getInstance()->make()->toString();
}

echo json_encode($output);
