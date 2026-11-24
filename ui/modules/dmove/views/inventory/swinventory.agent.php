<?php declare(strict_types = 0);
/*
 * SW 인벤토리의 Agent 상태 입력 창 내용.
 *
 * 컨트롤러: actions/inventory/swinventory/AgentEdit.php
 * 5버전   : popup.agent (뷰 소스는 받지 못했다)
 *
 * 팝업 뷰는 화면을 그리지 않고(->show() 없음) 아래 형태의 JSON 을 출력한다.
 *   header : 팝업 제목     body : 입력 폼과 최근 기록 표(HTML)     buttons : [저장] (+ 코어가 붙이는 [취소])
 *
 * ── 창 구성 ──
 *   Agent 상태  ( ) 미설치  ( ) 예외
 *   사유        [여러 줄 입력칸]
 *   최근 기록   시각 | 상태 | 사유 | 입력자     (최근 5개)
 *
 * ── 받는 값 ($data) ──
 *   key, name, available, agent, history, user   (컨트롤러 doAction() 설명 참고)
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\AgentStatusRepository;
use Modules\DMove\Includes\Lang;

$status_labels = [
	AgentStatusRepository::STATUS_NOT_INSTALLED => Lang::t('Not installed'),
	AgentStatusRepository::STATUS_EXCEPTION => Lang::t('Exception')
];

$body = [];

if (!$data['available']) {
	// 상태 표가 없으면 저장할 수 없다. 이유와 해결 방법을 알려 준다.
	$body[] = makeMessageBox(ZBX_STYLE_MSG_WARNING, [],
		Lang::t('The Agent status table does not exist.').' '
			.Lang::t('Run sql/extends_agent.sql in the Zabbix database, or check conf/swinventory.conf.php.'),
		false
	);
}

// 상태 선택. CRadioButtonList(이름, 지금 값) 에 addValue(라벨, 값) 으로 항목을 넣는다.
$status_radio = (new CRadioButtonList('agent', $data['agent']))->setModern(true);

foreach ($status_labels as $value => $label) {
	$status_radio->addValue($label, $value);
}

$form = (new CForm())
	->setId('dmove-agent-form')
	->setName('dmove_agent_form')
	// CSRF 토큰. 모듈 컨트롤러는 "액션 이름 전체"로 만든 토큰을 받는다. (코어 CController::checkCsrfToken)
	->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('swinventory.agent.save')))->removeId())
	->addItem((new CVar('key', $data['key']))->removeId())
	// 입력칸에서 Enter 를 눌러도 [저장] 과 같은 동작을 하도록 보이지 않는 제출 버튼을 둔다. (코어 팝업 폼의 관례)
	->addItem((new CInput('submit', null))->addStyle('display: none;'))
	->addItem(
		(new CFormList())
			->addRow(new CLabel(Lang::t('Agent status')), $status_radio)
			// setAsteriskMark(): 라벨에 빨간 * 를 붙여 꼭 넣어야 하는 칸임을 보인다. (빈 사유는 AgentSave 가 막는다)
			->addRow((new CLabel(Lang::t('Reason'), 'explanation'))->setAsteriskMark(),
				// CTextArea(이름, 값): 여러 줄 입력칸. setMaxlength: 최대 글자 수(표의 열 크기).
				(new CTextArea('explanation', ''))
					->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
					->setRows(3)
					->setMaxlength(2048)
			)
	);

$body[] = $form;

// 최근 기록 표. 최근 것이 위다. (5버전: 최근 5줄)
$history = (new CTableInfo())->setHeader([_('Time'), Lang::t('Agent status'), Lang::t('Reason'), Lang::t('Entered by')]);

foreach ($data['history'] as $record) {
	$history->addRow([
		(new CCol(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $record['clock'])))->addClass(ZBX_STYLE_NOWRAP),
		$status_labels[$record['agent']] ?? Lang::t('Installed'),
		(new CCol($record['explanation']))->addClass(ZBX_STYLE_WORDBREAK),
		$record['username']
	]);
}

$body[] = (new CDiv(Lang::t('Recent records')))->addClass('dm-toolbar');
$body[] = $history;

$output = [
	// 제목 예) Agent 상태: web-server-01
	'header' => Lang::t('Agent status').($data['name'] !== '' ? ': '.$data['name'] : ''),
	'body' => (new CDiv($body))->toString(),
	// 빠뜨리면 창 class 가 "modal-popup undefined" 가 된다. (코어 팝업 뷰도 같이 적는다)
	'dialogue_class' => 'modal-popup-generic',
	'buttons' => $data['available']
		? [[
			'title' => Lang::t('Save'),
			'class' => '',
			// keepOpen: 눌러도 창을 바로 닫지 않는다. 저장이 성공하면 스크립트가 닫는다.
			'keepOpen' => true,
			'isSubmit' => true,
			'action' => 'dmoveSwInventoryList.submitAgent(overlay);'
		]]
		: []
];

// 디버그 모드인 사용자에게는 코어의 디버그 정보를 붙인다. 코어 팝업 뷰와 같은 처리다.
if ($data['user']['debug_mode'] == GROUP_DEBUG_MODE_ENABLED) {
	CProfiler::getInstance()->stop();
	$output['debug'] = CProfiler::getInstance()->make()->toString();
}

echo json_encode($output);
