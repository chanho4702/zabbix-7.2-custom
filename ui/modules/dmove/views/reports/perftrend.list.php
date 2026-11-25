<?php declare(strict_types = 0);
/*
 * 성능 데이터 추세 분석 화면.
 *
 * 컨트롤러: actions/reports/perftrend/PerfTrendList.php
 * 5버전   : monitoring.data.analysis.php (+ 5.0 그래프 위젯의 설정 화면)
 *
 * ── 이 파일이 하는 일 ──
 *   5버전 화면과 같은 모양으로 그린다.
 *
 *   성능 데이터 추세 분석                                                   [CSV 내보내기]
 *   ┌──────────────────────────────────────────────────────────────────────────────┐
 *   │ (그래프. 값을 바꾸면 스크립트가 perftrend.graph 를 불러 다시 그린다)            │
 *   └──────────────────────────────────────────────────────────────────────────────┘
 *    데이터 셋 | 기간
 *    데이터 셋  ⋮ ■ [호스트 패턴      ][선택]   [아이템 패턴      ][선택]   ×
 *               ⋮ ■ [호스트 패턴      ][선택]   [아이템 패턴      ][선택]   ×   <- 누르면 펼쳐진다
 *                   기본 색상 ■ 0EC9AC              결손 데이터 [없음|접속됨|0 으로 처리]
 *                   그래프 형식 [선|포인트|계단|막대]   Y축 [왼쪽|오른쪽]
 *                   너비 ──●──── 1                    타임 시프트 [없음]
 *                   포인트 사이즈 ...                 Aggregation function [없음 ▼]
 *                   투명도 ...                        Aggregation interval [1h]
 *                   채우기 ...                        Aggregate [Each item|데이터 셋]
 *               [+ 새로운 데이터 셋을 추가]
 *
 * ── 받는 값 ($data) ──
 *   title             화면 제목
 *   data_sets         PerfTrendService::normalizeDataSets() 의 결과. 줄마다 hosts, items, color, type, width ...
 *   max_data_sets     데이터 셋 최대 개수 (10)
 *   period            ['custom' => 0|1, 'from' => 'now-1h', 'to' => 'now']
 *   default_data_set  새 줄의 기본값
 *   color_palette     새 줄의 색을 고를 색상표
 *
 * ── 관련 파일 ──
 *   views/js/reports/perftrend.list.js.php   펼치기, 줄 추가/삭제, 그래프 다시 그리기, CSV 버튼
 *   includes/PerfTrendService.php            값 정리, 그래프, CSV
 *   assets/css/dmove.css                     .dm-pt-* 모양
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Lang;
use Modules\DMove\Includes\PerfTrendService;

/*
 * 이 화면에 필요한 코어 스크립트. (multiselect.js, class.cverticalaccordion.js, class.sortable.js 는 늘 불러온다)
 *   colorpicker.js          : 기본 색상 고르기 창
 *   class.crangecontrol.js  : 너비, 투명도 같은 슬라이더
 *   class.calendar.js       : [기간] 탭의 날짜 입력칸 옆 달력
 *   class.csvggraph.js      : 그래프 위에 마우스를 올렸을 때 값 말풍선
 */
$this->addJsFile('colorpicker.js');
$this->addJsFile('class.crangecontrol.js');
$this->addJsFile('class.calendar.js');
$this->addJsFile('class.csvggraph.js');

$this->includeJsFile('reports/perftrend.list.js.php');

/** 폼 이름. 호스트/아이템 [선택] 창이 고른 값을 이 폼으로 돌려준다. */
$form_name = 'dm-pt-form';

// ── 1) 데이터 셋 한 줄 ──────────────────────────────────────────────────────

/*
 * 데이터 셋 한 줄(<li>)을 만든다. 같은 함수로 "새 줄 견본"(<template>)도 만든다.
 * 견본은 번호 자리에 #{rowNum}, 색 자리에 #{color} 를 넣고, 스크립트가 새 번호와 색으로 바꾼다.
 *
 *   $n       : 줄 번호(이름의 ds[n]). 화면에서 줄을 지워도 번호를 다시 쓰지 않는다.
 *   $ds      : 이 줄의 값
 *   $opened  : 펼친 상태로 그릴지. 5버전처럼 한 번에 한 줄만 펼친다.
 *
 * 입력 이름: ds[n][hosts][], ds[n][items][], ds[n][color], ds[n][type], ds[n][width], ds[n][pointsize],
 *            ds[n][transparency], ds[n][fill], ds[n][missingdatafunc], ds[n][axisy], ds[n][timeshift],
 *            ds[n][aggregate_function], ds[n][aggregate_interval], ds[n][aggregate_grouping]
 */
$make_data_set = static function (string $n, array $ds, bool $opened) use ($form_name): CListItem {
	$name = static fn(string $field): string => 'ds['.$n.']['.$field.']';

	/*
	 * 호스트 패턴, 아이템 패턴 선택창. 코어 그래프 위젯과 같은 부품(CPatternSelect)이다.
	 *   글자를 쳐서 패턴(* 사용 가능)을 넣거나, [선택] 으로 목록에서 고른다.
	 *   아이템 [선택] 창은 위에서 고른 호스트로 미리 걸러진다(filter_preselect).
	 */
	$hosts = (new CPatternSelect([
		'name' => $name('hosts').'[]',
		'object_name' => 'hosts',
		'data' => $ds['hosts'],
		'placeholder' => Lang::t('host pattern'),
		'wildcard_allowed' => 1,
		'popup' => [
			'parameters' => [
				'srctbl' => 'hosts',
				'srcfld1' => 'host',
				'dstfrm' => $form_name,
				'dstfld1' => zbx_formatDomId($name('hosts').'[]')
			]
		],
		'add_post_js' => false
	]))->addClass('js-dm-pt-hosts');

	$preselect = [
		'id' => $hosts->getId(),
		'submit_as' => 'host_pattern',
		'submit_parameters' => [
			'host_pattern_wildcard_allowed' => 1,
			'host_pattern_multiple' => 1
		],
		'multiple' => true
	];

	$items = (new CPatternSelect([
		'name' => $name('items').'[]',
		'object_name' => 'items',
		'data' => $ds['items'],
		'placeholder' => Lang::t('item pattern'),
		'wildcard_allowed' => 1,
		'popup' => [
			'parameters' => [
				'srctbl' => 'items',
				'srcfld1' => 'name',
				'real_hosts' => 1,
				'numeric' => 1,
				'resolve_macros' => 1,
				'dstfrm' => $form_name,
				'dstfld1' => zbx_formatDomId($name('items').'[]')
			],
			'filter_preselect' => $preselect
		],
		'autosuggest' => ['filter_preselect' => $preselect],
		'add_post_js' => false
	]))->addClass('js-dm-pt-items');

	$type = (int) $ds['type'];
	$no_line = in_array($type, [SVG_GRAPH_TYPE_POINTS, SVG_GRAPH_TYPE_BAR]);	// 포인트, 막대는 선 옵션이 없다
	$no_aggregate = (int) $ds['aggregate_function'] === AGGREGATE_NONE;

	// 슬라이더 하나. 코어 부품(CRangeControl): 막대를 끌거나 옆 칸에 숫자를 넣는다.
	$slider = static fn(string $field, int $min, bool $enabled): CRangeControl =>
		(new CRangeControl($name($field), (int) $ds[$field]))
			->setEnabled($enabled)
			->setWidth(ZBX_TEXTAREA_MEDIUM_WIDTH)
			->setStep(1)
			->setMin($min)
			->setMax(10);

	// 집계 함수 목록. 5버전 화면과 같다.
	$aggregate_function = (new CSelect($name('aggregate_function')))
		->setId('ds_'.$n.'_aggregate_function')
		->setValue((int) $ds['aggregate_function'])
		->addOptions(CSelect::createOptionsFromArray([
			AGGREGATE_NONE => Lang::t('None'),
			AGGREGATE_MIN => 'min',
			AGGREGATE_MAX => 'max',
			AGGREGATE_AVG => 'avg',
			AGGREGATE_COUNT => 'count',
			AGGREGATE_SUM => 'sum',
			AGGREGATE_FIRST => 'first',
			AGGREGATE_LAST => 'last'
		]))
		->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
		->addClass('js-dm-pt-aggregate-function');

	// 왼쪽 열: 기본 색상, 그래프 형식, 너비, 포인트 사이즈, 투명도, 채우기
	$left = (new CFormGrid())
		->addItem([
			new CLabel(Lang::t('Base color')),
			new CFormField(
				// 색 고르기 칸. 창 동작은 스크립트가 붙인다(appendColorPickerJs(false)).
				(new CColor($name('color'), $ds['color'], 'ds_'.$n.'_color'))->appendColorPickerJs(false)
			)
		])
		->addItem([
			new CLabel(Lang::t('Graph type')),
			new CFormField(
				(new CRadioButtonList($name('type'), $type))
					->addValue(Lang::t('Line'), SVG_GRAPH_TYPE_LINE)
					->addValue(Lang::t('Points'), SVG_GRAPH_TYPE_POINTS)
					->addValue(Lang::t('Staircase'), SVG_GRAPH_TYPE_STAIRCASE)
					->addValue(Lang::t('Bar'), SVG_GRAPH_TYPE_BAR)
					->setModern()
					->addClass('js-dm-pt-type')
			)
		])
		->addItem([new CLabel(Lang::t('Width')), new CFormField($slider('width', 0, !$no_line))])
		->addItem([new CLabel(Lang::t('Point size')),
			new CFormField($slider('pointsize', 1, $type === SVG_GRAPH_TYPE_POINTS))
		])
		->addItem([new CLabel(Lang::t('Transparency')), new CFormField($slider('transparency', 0, true))])
		->addItem([new CLabel(Lang::t('Fill')), new CFormField($slider('fill', 0, !$no_line))]);

	// 오른쪽 열: 결손 데이터, Y축, 타임 시프트, Aggregation function / interval, Aggregate
	$right = (new CFormGrid())
		->addItem([
			new CLabel(Lang::t('Missing data')),
			new CFormField(
				(new CRadioButtonList($name('missingdatafunc'), (int) $ds['missingdatafunc']))
					->addValue(Lang::t('None'), SVG_GRAPH_MISSING_DATA_NONE)
					->addValue(Lang::t('Connected'), SVG_GRAPH_MISSING_DATA_CONNECTED)
					->addValue(Lang::t('Treat as 0'), SVG_GRAPH_MISSING_DATA_TREAT_AS_ZERO)
					->setEnabled(!$no_line)
					->setModern()
					->addClass('js-dm-pt-missingdata')
			)
		])
		->addItem([
			new CLabel(Lang::t('Y-axis')),
			new CFormField(
				(new CRadioButtonList($name('axisy'), (int) $ds['axisy']))
					->addValue(Lang::t('Left'), GRAPH_YAXIS_SIDE_LEFT)
					->addValue(Lang::t('Right'), GRAPH_YAXIS_SIDE_RIGHT)
					->setModern()
			)
		])
		->addItem([
			new CLabel(Lang::t('Time shift')),
			new CFormField(
				(new CTextBox($name('timeshift'), $ds['timeshift']))
					->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
					->setAttribute('placeholder', Lang::t('none'))
			)
		])
		->addItem([
			new CLabel(Lang::t('Aggregation function'), 'label-ds_'.$n.'_aggregate_function'),
			new CFormField($aggregate_function->setFocusableElementId('label-ds_'.$n.'_aggregate_function'))
		])
		->addItem([
			new CLabel(Lang::t('Aggregation interval')),
			new CFormField(
				(new CTextBox($name('aggregate_interval'), $ds['aggregate_interval']))
					->setEnabled(!$no_aggregate)
					->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
					->setAttribute('placeholder', GRAPH_AGGREGATE_DEFAULT_INTERVAL)
					->addClass('js-dm-pt-aggregate-interval')
			)
		])
		->addItem([
			new CLabel(Lang::t('Aggregate')),
			new CFormField(
				(new CRadioButtonList($name('aggregate_grouping'), (int) $ds['aggregate_grouping']))
					->addValue(Lang::t('Each item'), GRAPH_AGGREGATE_BY_ITEM)
					->addValue(Lang::t('Data set'), GRAPH_AGGREGATE_BY_DATASET)
					->setEnabled(!$no_aggregate)
					->setModern()
					->addClass('js-dm-pt-aggregate-grouping')
			)
		]);

	return (new CListItem([
		(new CDiv([
			// 끌어서 줄 순서를 바꾸는 손잡이(점 세로줄)
			(new CDiv())->addClass(ZBX_STYLE_DRAG_ICON)->addClass('js-dm-pt-drag'),
			// 이 줄의 색. 기본 색상과 같이 바뀐다. 누르면 줄이 펼쳐진다.
			(new CSpan())
				->addClass('dm-pt-swatch')
				->addClass('js-dm-pt-swatch')
				->addStyle('background-color: #'.$ds['color'].';'),
			$hosts,
			new CScriptTag($hosts->getPostJS()),
			$items,
			new CScriptTag($items->getPostJS()),
			// 줄 삭제
			(new CButtonIcon(ZBX_ICON_REMOVE_SMALLER, _('Delete')))->addClass('js-dm-pt-remove')
		]))->addClass('dm-pt-ds-head'),
		(new CDiv([$left, $right]))->addClass('dm-pt-ds-body')
	]))
		->addClass('dm-pt-ds')
		->addClass($opened ? ZBX_STYLE_LIST_ACCORDION_ITEM_OPENED : ZBX_STYLE_LIST_ACCORDION_ITEM_CLOSED)
		->setAttribute('data-set', $n);
};

// ── 2) [데이터 셋] 탭 ───────────────────────────────────────────────────────

$data_set_list = (new CList())->setId('dm-pt-data-sets')->addClass('dm-pt-data-sets');
$last = count($data['data_sets']) - 1;

foreach (array_values($data['data_sets']) as $n => $ds) {
	// 처음에는 마지막 줄을 펼쳐 둔다. (5버전 화면도 마지막 줄이 펼쳐져 있었다)
	$data_set_list->addItem($make_data_set((string) $n, $ds, $n === $last));
}

// 새 줄 견본. [+ 새로운 데이터 셋을 추가] 를 누르면 스크립트가 복사해 번호와 색을 채운다.
$template = new CTemplateTag('dm-pt-ds-tmpl',
	$make_data_set('#{rowNum}', ['color' => '#{color}'] + $data['default_data_set'], true)
);

$data_set_tab = (new CDiv([
	(new CDiv(Lang::t('Data set')))->addClass('dm-pt-ds-label'),
	(new CDiv([
		$data_set_list,
		(new CSimpleButton(Lang::t('Add new data set')))
			->setId('dm-pt-ds-add')
			->addClass('dm-pt-ds-add')
	]))->addClass('dm-pt-ds-content')
]))->addClass('dm-pt-ds-tab');

// ── 3) [기간] 탭 ────────────────────────────────────────────────────────────

/*
 * 5버전(5.0 그래프 위젯)의 [기간] 탭과 같다.
 *   [사용자 정의 기간 설정] 을 체크하면 시작/종료를 쓴다. 체크하지 않으면 최신 1시간.
 *   시작/종료에는 "2026-11-25 00:00:00" 같은 날짜나 "now-1d", "now-1d/d" 같은 표현을 넣는다.
 */
$custom = (bool) $data['period']['custom'];

$period_tab = (new CFormList())
	->addRow(Lang::t('Set custom time period'),
		(new CCheckBox('period[custom]'))
			->setId('dm-pt-period-custom')
			->setChecked($custom)
	)
	->addRow(Lang::t('From'),
		(new CDateSelector('period[from]', $data['period']['from']))
			->setDateFormat(ZBX_FULL_DATE_TIME)
			->setPlaceholder(_('YYYY-MM-DD hh:mm:ss'))
			->setEnabled($custom)
			->addClass('js-dm-pt-period-date')
	)
	->addRow(Lang::t('To'),
		(new CDateSelector('period[to]', $data['period']['to']))
			->setDateFormat(ZBX_FULL_DATE_TIME)
			->setPlaceholder(_('YYYY-MM-DD hh:mm:ss'))
			->setEnabled($custom)
			->addClass('js-dm-pt-period-date')
	);

// ── 4) 그래프 + 탭을 한 폼으로 ───────────────────────────────────────────────

$tabs = (new CTabView(['id' => 'dm-pt-tabs']))
	->addTab('dm-pt-tab-ds', Lang::t('Data set'), $data_set_tab)
	->addTab('dm-pt-tab-period', Lang::t('Period'), $period_tab)
	->setSelected(0);

$form = (new CForm('post'))
	->setName($form_name)
	->setId($form_name)
	->addClass('dm-pt-form')
	/*
	 * 그래프 자리. 스크립트가 perftrend.graph 의 응답으로 채운다.
	 * dashboard-grid-widget-container: 코어 값 말풍선(class.csvggraph.js)이 이 이름의 칸을 찾아 그 안에 말풍선을 넣는다.
	 * (대시보드용 위치 지정은 dmove.css 에서 끈다)
	 */
	->addItem(
		(new CDiv(
			(new CDiv())->setId('dm-pt-graph')->addClass('dashboard-grid-widget-container')
		))->addClass('dm-pt-graph')
	)
	->addItem($tabs)
	->addItem($template);

(new CHtmlPage())
	->setTitle($data['title'])
	->setControls(
		(new CTag('nav', true,
			(new CList())->addItem(
				// 5버전처럼 화면의 값을 그대로 보내 CSV 를 받는다. (스크립트가 폼을 perftrend.export 로 보낸다)
				(new CSimpleButton(_('Export to CSV')))->setId('dm-pt-export')
			)
		))->setAttribute('aria-label', _('Content controls'))
	)
	->addItem($form)
	->show();

(new CScriptTag('dmovePerfTrend.init('.json_encode([
	'max' => $data['max_data_sets'],
	'palette' => $data['color_palette']
]).');'))
	->setOnDocumentReady()
	->show();
