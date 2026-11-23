<?php declare(strict_types = 0);
/*
 * 성능 데이터 추세 분석 화면 전용 스크립트.
 *
 * 불러오는 곳: views/reports/perftrend.list.php 의 $this->includeJsFile('reports/perftrend.list.js.php')
 *
 * 하는 일:
 *   [+ 데이터 셋 추가] 를 누르면 데이터 셋 줄을 하나 더 만들고, [삭제] 를 누르면 그 줄을 지운다.
 *   줄을 다 지우면 빈 줄 하나를 남긴다. 저장은 하지 않는다. ([적용] 을 눌러야 저장된다)
 *
 * 새 줄은 뷰가 만들어 둔 <template id="dm-ds-row-tmpl"> 을 복사하고, 이름의 #{n} 자리에 새 번호를 넣는다.
 * 색은 기본 색상표에서 순서대로 고른다. (코어 그래프 위젯과 같은 색상표)
 */
?>

<script>
	const dmovePerfTrendList = {

		/** 코어 그래프 위젯의 기본 색상표. */
		palette: ['FF465C', 'FFD54F', '0EC9AC', '524BBC', 'ED1248', 'D1E754', '2AB5FF', '385CC7', 'EC1594', 'BAE37D'],

		/**
		 * @param {number} max  데이터 셋 최대 개수.
		 */
		init({max}) {
			const table = document.getElementById('dm-ds-table');
			const template = document.getElementById('dm-ds-row-tmpl');
			const add_button = document.getElementById('dm-ds-add');

			// 새 줄 번호. 지운 줄 번호를 다시 쓰지 않도록 계속 늘린다. (이름이 겹치면 값이 섞인다)
			let next = table.querySelectorAll('.js-ds-row').length;

			const rowCount = () => table.querySelectorAll('.js-ds-row').length;
			const refreshAdd = () => { add_button.disabled = rowCount() >= max; };

			const addRow = () => {
				if (rowCount() >= max) {
					return;
				}

				// innerHTML 의 #{n} 을 새 번호로 바꿔서 줄을 만든다.
				const html = template.innerHTML.replaceAll('#{n}', String(next));
				const holder = document.createElement('tbody');
				holder.innerHTML = html;

				const row = holder.querySelector('tr');
				row.querySelector('.dm-ds-color').value = '#' + this.palette[next % this.palette.length];

				(table.tBodies[0] || table).appendChild(row);
				next++;
				refreshAdd();
			};

			add_button.addEventListener('click', addRow);

			// 삭제 버튼. 표 전체에 클릭 감시를 하나 걸고 눌린 것이 삭제 버튼일 때만 처리한다.
			table.addEventListener('click', (e) => {
				const button = e.target.closest('.js-ds-remove');

				if (button === null) {
					return;
				}

				button.closest('tr').remove();

				// 다 지웠으면 빈 줄 하나를 남긴다.
				if (rowCount() === 0) {
					addRow();
				}

				refreshAdd();
			});

			refreshAdd();
		}
	};
</script>
