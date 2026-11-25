<?php declare(strict_types = 0);
/*
 * 성능 데이터 추세 분석 화면 전용 스크립트.
 *
 * 불러오는 곳: views/reports/perftrend.list.php 의 $this->includeJsFile('reports/perftrend.list.js.php')
 *
 * 하는 일 (5버전 화면 = 5.0 그래프 위젯 설정 화면과 같은 동작):
 *   1) 데이터 셋 줄을 누르면 그 줄을 펼치고 나머지는 접는다. (한 번에 한 줄)
 *   2) [+ 새로운 데이터 셋을 추가] 로 줄을 더하고, × 로 지운다. 다 지우면 빈 줄 하나를 남긴다. 손잡이로 순서를 바꾼다.
 *   3) 그래프 형식, 집계 함수에 따라 쓸 수 없는 옵션을 흐리게 한다.
 *   4) 값이 바뀌면 perftrend.graph 를 불러 그래프를 다시 그린다. (화면의 값은 그때 사용자별로 저장된다)
 *   5) 그래프 위에 마우스를 올리면 값 말풍선을 띄운다. (코어 class.csvggraph.js)
 *   6) [CSV 내보내기] 는 화면의 값을 그대로 perftrend.export 로 보낸다.
 */
?>

<script>
	const dmovePerfTrend = {

		/** 그래프 높이(픽셀, 범례 포함). 5버전 화면의 그래프 칸 높이(300px)에 범례 자리를 더했다. */
		GRAPH_HEIGHT: 360,

		/**
		 * @param {number}   max      데이터 셋 최대 개수
		 * @param {string[]} palette  새 줄의 색을 고를 색상표 (코어 그래프 위젯과 같다)
		 */
		init({max, palette}) {
			this.max = max;
			this.form = document.getElementById('dm-pt-form');
			this.list = document.getElementById('dm-pt-data-sets');
			this.graph = document.getElementById('dm-pt-graph');
			this.add_button = document.getElementById('dm-pt-ds-add');

			// 새 줄 번호. 지운 줄의 번호를 다시 쓰지 않도록 계속 늘린다. (이름이 겹치면 값이 섞인다)
			this.next = this.list.querySelectorAll('.dm-pt-ds').length;

			// colorPalette: 코어 colorpicker.js 의 색상표. 새 줄에 아직 안 쓴 색을 골라 준다.
			colorPalette.setThemeColors(palette);

			for (const row of this.list.querySelectorAll('.dm-pt-ds')) {
				this.initRow(row);
			}

			// 줄 누르기: × 면 지우고, 아니면 그 줄을 펼친다.
			this.list.addEventListener('click', (e) => {
				const remove = e.target.closest('.js-dm-pt-remove');

				if (remove !== null) {
					this.removeRow(remove.closest('.dm-pt-ds'));

					return;
				}

				const row = e.target.closest('.dm-pt-ds');

				if (row !== null) {
					this.openRow(row);
				}
			});

			// 패턴 칸에 글자를 치려고 들어가도 그 줄을 펼친다.
			this.list.addEventListener('focusin', (e) => {
				const row = e.target.closest('.dm-pt-ds');

				if (row !== null) {
					this.openRow(row);
				}
			});

			this.add_button.addEventListener('click', () => this.addRow());

			// 값이 바뀌면 옵션 흐리기를 고치고 그래프를 다시 그린다.
			jQuery(this.form).on('change', 'input, z-select, .multiselect', () => {
				this.updateRows();
				this.refresh();
			});

			// 손잡이로 줄 순서 바꾸기. (코어 CSortable)
			this.sortable = new CSortable(this.list, {selector_handle: '.js-dm-pt-drag'});
			this.sortable.on(CSortable.EVENT_SORT, () => this.refresh());

			// [기간] 탭: 체크하면 시작/종료 칸을 쓸 수 있다.
			const custom = document.getElementById('dm-pt-period-custom');

			custom.addEventListener('change', () => this.updatePeriod());

			// [CSV 내보내기]: 폼을 perftrend.export 로 보낸다. 응답이 파일이라 화면은 그대로 있다.
			document.getElementById('dm-pt-export').addEventListener('click', () => this.exportCsv());

			// 창 너비가 바뀌면 그래프를 그 너비로 다시 그린다.
			let resize_timer = null;

			window.addEventListener('resize', () => {
				clearTimeout(resize_timer);
				resize_timer = setTimeout(() => this.refresh(), 300);
			});

			this.updateRows();
			this.updatePeriod();
			this.refreshAdd();
			this.refresh();
		},

		/**
		 * 줄 하나의 부품(슬라이더, 색 고르기)을 동작시킨다.
		 */
		initRow(row) {
			jQuery('.<?= CRangeControl::ZBX_STYLE_CLASS ?>', row).rangeControl();

			const swatch = row.querySelector('.js-dm-pt-swatch');

			for (const input of row.querySelectorAll('.<?= ZBX_STYLE_COLOR_PICKER ?> input')) {
				jQuery(input).colorpicker({
					// 색을 바꾸면 줄 머리의 색 칸도 같이 바꾼다.
					onUpdate: (color) => {
						swatch.style.backgroundColor = `#${color}`;
					}
				});
			}
		},

		/** 이 줄을 펼치고 나머지는 접는다. */
		openRow(row) {
			if (row.classList.contains('<?= ZBX_STYLE_LIST_ACCORDION_ITEM_OPENED ?>')) {
				return;
			}

			for (const other of this.list.querySelectorAll('.dm-pt-ds')) {
				other.classList.toggle('<?= ZBX_STYLE_LIST_ACCORDION_ITEM_OPENED ?>', other === row);
				other.classList.toggle('<?= ZBX_STYLE_LIST_ACCORDION_ITEM_CLOSED ?>', other !== row);
			}

			jQuery.colorpicker('hide');
		},

		/** [+ 새로운 데이터 셋을 추가] */
		addRow() {
			if (this.list.querySelectorAll('.dm-pt-ds').length >= this.max) {
				return;
			}

			// 이미 쓰는 색을 빼고 색상표의 다음 색을 고른다.
			const used_colors = [...this.form.querySelectorAll('.<?= ZBX_STYLE_COLOR_PICKER ?> input')]
				.map((input) => input.value)
				.filter((value) => value !== '');

			// Template: 코어 class.template.js. #{rowNum}, #{color} 를 채운다.
			// createContextualFragment 로 넣어야 견본 안의 <script>(패턴 선택창 시작)가 실행된다.
			const template = new Template(document.getElementById('dm-pt-ds-tmpl').innerHTML);
			const fragment = document.createRange().createContextualFragment(template.evaluate({
				rowNum: this.next++,
				color: colorPalette.getNextColor(used_colors)
			}));

			const row = fragment.querySelector('.dm-pt-ds');

			this.list.append(fragment);
			this.initRow(row);
			this.openRow(row);
			this.updateRows();
			this.refreshAdd();
			this.refresh();
		},

		/** × : 줄을 지운다. 다 지우면 빈 줄 하나를 남긴다. */
		removeRow(row) {
			row.remove();

			if (this.list.querySelectorAll('.dm-pt-ds').length === 0) {
				this.addRow();

				return;
			}

			const opened = this.list.querySelector('.<?= ZBX_STYLE_LIST_ACCORDION_ITEM_OPENED ?>');

			if (opened === null) {
				this.openRow(this.list.querySelector('.dm-pt-ds:last-child'));
			}

			this.refreshAdd();
			this.refresh();
		},

		/** 줄이 최대 개수면 [+ 새로운 데이터 셋을 추가] 를 막는다. */
		refreshAdd() {
			this.add_button.disabled = this.list.querySelectorAll('.dm-pt-ds').length >= this.max;
		},

		/**
		 * 그래프 형식, 집계 함수에 따라 쓸 수 없는 옵션을 흐리게 한다. (5.0 그래프 위젯과 같은 규칙)
		 *   포인트      : 너비, 채우기, 결손 데이터 없음. 포인트 사이즈만.
		 *   막대 그래프 : 너비, 채우기, 결손 데이터, 포인트 사이즈 없음.
		 *   선, 계단    : 포인트 사이즈 없음.
		 *   집계 함수가 "없음" 이면 Aggregation interval, Aggregate 없음.
		 */
		updateRows() {
			for (const row of this.list.querySelectorAll('.dm-pt-ds')) {
				const n = row.dataset.set;
				const type = row.querySelector(`[name="ds[${n}][type]"]:checked`)?.value;
				const is_points = type === '<?= SVG_GRAPH_TYPE_POINTS ?>';
				const no_line = is_points || type === '<?= SVG_GRAPH_TYPE_BAR ?>';

				jQuery(`[name="ds[${n}][width]"]`, row).rangeControl(no_line ? 'disable' : 'enable');
				jQuery(`[name="ds[${n}][fill]"]`, row).rangeControl(no_line ? 'disable' : 'enable');
				jQuery(`[name="ds[${n}][pointsize]"]`, row).rangeControl(is_points ? 'enable' : 'disable');

				for (const input of row.querySelectorAll(`[name="ds[${n}][missingdatafunc]"]`)) {
					input.disabled = no_line;
				}

				const aggregate = row.querySelector(`[name="ds[${n}][aggregate_function]"]`);
				const no_aggregate = aggregate === null || aggregate.value == '<?= AGGREGATE_NONE ?>';

				row.querySelector(`[name="ds[${n}][aggregate_interval]"]`).disabled = no_aggregate;

				for (const input of row.querySelectorAll(`[name="ds[${n}][aggregate_grouping]"]`)) {
					input.disabled = no_aggregate;
				}
			}
		},

		/** [기간] 탭: 체크하지 않으면 시작/종료 칸을 막는다. */
		updatePeriod() {
			const enabled = document.getElementById('dm-pt-period-custom').checked;

			for (const input of this.form.querySelectorAll('[name="period[from]"], [name="period[to]"]')) {
				input.disabled = !enabled;
			}

			for (const button of this.form.querySelectorAll('.js-dm-pt-period-date button')) {
				button.disabled = !enabled;
			}
		},

		/**
		 * 폼의 값을 FormData 로 모은다. 흐리게 한(disabled) 칸은 브라우저가 빼므로,
		 * 저장할 때 값이 사라지지 않게 잠깐 풀었다가 모은다. (막는 것은 화면 표시용일 뿐이다)
		 */
		formData() {
			const disabled = [...this.form.querySelectorAll('[name^="ds["]:disabled, [name^="period["]:disabled')];

			disabled.forEach((input) => input.disabled = false);

			const data = new FormData(this.form);

			disabled.forEach((input) => input.disabled = true);

			data.append('save', '1');

			return data;
		},

		/** 그래프를 다시 그린다. 앞의 요청이 아직 오고 있으면 그것은 버린다. */
		refresh() {
			if (this.abort !== undefined) {
				this.abort.abort();
			}

			this.abort = new AbortController();

			const data = this.formData();
			const container = this.graph.parentElement;

			data.append('width', String(Math.max(300, Math.floor(container.clientWidth - 20))));
			data.append('height', String(this.GRAPH_HEIGHT));

			const url = new Curl('zabbix.php');

			url.setArgument('action', 'perftrend.graph');

			container.classList.add('is-loading');

			fetch(url.getUrl(), {method: 'POST', body: data, signal: this.abort.signal})
				.then((response) => response.json())
				.then((response) => {
					container.classList.remove('is-loading');
					this.clearMessages();

					if ('error' in response) {
						this.showMessages(response.error.messages || [], 'bad');

						return;
					}

					this.graph.innerHTML = response.svg + response.legend;

					if (response.messages.length > 0) {
						this.showMessages(response.messages, 'warning');
					}

					this.activateHints(response.data);
				})
				.catch((exception) => {
					if (exception.name === 'AbortError') {
						return;
					}

					container.classList.remove('is-loading');
					this.clearMessages();
					this.showMessages([<?= json_encode(_('Unexpected server error.')) ?>], 'bad');
				});
		},

		/**
		 * 그래프 위 값 말풍선. 코어 class.csvggraph.js 는 대시보드 위젯 객체를 받으므로,
		 * 그 객체가 가진 값과 함수 중 말풍선에 필요한 것만 흉내 낸 객체를 넘긴다.
		 */
		activateHints(svg_data) {
			const svg = this.graph.querySelector('svg');

			if (svg === null || svg_data.dims === undefined) {
				return;
			}

			const widget = {
				_svg: svg,
				_svg_options: svg_data,
				_pauseUpdating() {},
				_resumeUpdating() {}
			};

			jQuery(svg).svggraph(widget);
			jQuery(svg).svggraph('activate');
		},

		showMessages(messages, type) {
			// makeMessageBox: 코어 functions.js. 노란(warning)/빨간(bad) 안내 상자를 만든다.
			const box = makeMessageBox(type, messages, null, true, false)[0];

			box.classList.add('js-dm-pt-message');
			this.form.parentNode.insertBefore(box, this.form);
		},

		clearMessages() {
			for (const box of document.querySelectorAll('.js-dm-pt-message')) {
				box.remove();
			}
		},

		/** [CSV 내보내기]: 화면의 값을 그대로 perftrend.export 로 보낸다. */
		exportCsv() {
			const url = new Curl('zabbix.php');

			url.setArgument('action', 'perftrend.export');

			// 숨은 폼을 만들어 보낸다. (화면 폼의 흐리게 한 칸도 값이 가도록 formData() 의 값을 옮겨 담는다)
			const form = document.createElement('form');

			form.method = 'post';
			form.action = url.getUrl();
			form.style.display = 'none';

			for (const [key, value] of this.formData().entries()) {
				const input = document.createElement('input');

				input.type = 'hidden';
				input.name = key;
				input.value = value;
				form.append(input);
			}

			document.body.append(form);
			form.submit();
			form.remove();
		}
	};
</script>
