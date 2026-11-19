<?php declare(strict_types = 0);
/*
 * ★ 내보내기(CSV / Excel) 플러그인 ★  컨트롤러에 "꽂아서" 쓴다.
 *
 * 어떤 목록 화면이든 아래 4곳만 추가하면 [CSV 내보내기] [Excel 내보내기] 버튼이 생긴다.
 * 화면마다 CSV 뷰 파일을 따로 만들 필요가 없다.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ ① manifest.json : 내보내기 액션 한 항목 (view 는 적지 않는다)           │
 * │                                                                         │
 * │     "diskusage.export": {                                               │
 * │         "class": "Reports/DiskUsage/DiskUsageList",     <- 화면과 같은 컨트롤러 │
 * │         "layout": "export/layout.file"          <- 항상 이 값           │
 * │     }                                                                   │
 * │                                                                         │
 * │ ② 컨트롤러 : trait 을 꽂는다                                            │
 * │                                                                         │
 * │     use Modules\DMove\Includes\Export\ExportTrait;                      │
 * │                                                                         │
 * │     class DiskUsageList extends CController {                           │
 * │         use ExportTrait;                                                │
 * │                                                                         │
 * │ ③ 컨트롤러 checkInput() : 입력 규칙에 내보내기용 규칙을 더한다          │
 * │                                                                         │
 * │     $fields = [ ... 기존 규칙 ... ] + self::exportInputFields();        │
 * │                                                                         │
 * │ ④ 컨트롤러 doAction() : 데이터를 만든 뒤, 내보내기 요청이면 넘긴다      │
 * │                                                                         │
 * │     if ($this->isExportRequest()) {                                     │
 * │         $this->setExportResponse('디스크 현황', 'disk_usage',           │
 * │             ['host' => '호스트', 'used' => '사용량'],   // 열 정의      │
 * │             $rows                                       // 전체 행      │
 * │         );                                                              │
 * │         return;                                                         │
 * │     }                                                                   │
 * │                                                                         │
 * │ ⑤ 뷰 : 제목 오른쪽에 버튼을 단다                                        │
 * │                                                                         │
 * │     ->setControls(ExportButtons::create('diskusage.export'))            │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * 열 정의 쓰는 법  (키 = $rows 각 행의 키)
 *   'host' => '호스트'                                   가장 간단한 형태. 텍스트 열.
 *   'used' => ['label' => '사용량', 'type' => 'number']   숫자 열. 엑셀에서 숫자로 들어가 합계/정렬이 된다.
 *
 * 행에는 "화면에 보이는 글자"를 넣는다. (예: 상태 값 1 이 아니라 '설치')
 *
 * 동작 순서
 *   버튼 클릭 -> zabbix.php?action=diskusage.export&format=xlsx
 *   -> 컨트롤러 doAction() -> setExportResponse()
 *   -> views/export/layout.file.php 가 CsvWriter 또는 XlsxWriter 로 파일을 만들어 내려보낸다.
 *
 * trait 이란: 클래스에 메소드 묶음을 끼워 넣는 PHP 문법이다. "use ExportTrait;" 한 줄을 적으면
 * 아래 메소드들이 그 컨트롤러의 메소드가 된다. ($this->isExportRequest() 처럼 부를 수 있다)
 */

namespace Modules\DMove\Includes\Export;

use CControllerResponseData;

trait ExportTrait {

	/**
	 * checkInput() 의 규칙 배열에 더할 내보내기용 규칙.
	 * format 파라미터로 csv 또는 xlsx 만 받는다.
	 */
	protected static function exportInputFields(): array {
		return [
			'format' => 'in '.implode(',', ExportFormat::ALL)
		];
	}

	/**
	 * 지금 요청이 내보내기 액션인지. 액션 이름이 ".export" 로 끝나면 내보내기다.
	 */
	protected function isExportRequest(): bool {
		// str_ends_with: PHP 8.0 부터 있는 함수. 문자열이 주어진 글자로 끝나는지 검사한다.
		return str_ends_with($this->getAction(), '.export');
	}

	/**
	 * 내보내기 응답을 지정한다. 이 뒤에 doAction() 에서 바로 return 하면 된다.
	 *
	 * @param string $title        시트 이름과 브라우저 제목으로 쓴다.
	 * @param string $file_prefix  파일명 앞부분(영문/숫자/밑줄). 예) 'sw_inventory' -> sw_inventory_20260102_030405.xlsx
	 * @param array  $columns      열 정의. 위의 "열 정의 쓰는 법" 참고.
	 * @param array  $rows         전체 행. (페이징으로 자르기 전의 것을 넘긴다)
	 */
	protected function setExportResponse(string $title, string $file_prefix, array $columns, array $rows): void {
		// format 이 없으면 CSV.
		$format = $this->getInput('format', ExportFormat::CSV);

		// 파일명에 위험한 글자가 섞이지 않게 영문/숫자/밑줄/하이픈만 남긴다.
		$file_prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $file_prefix) ?: 'export';

		$response = new CControllerResponseData([
			// views/export/layout.file.php 가 이 값을 읽는다.
			'export' => [
				'format' => $format,
				'title' => $title,
				'columns' => self::normalizeExportColumns($columns),
				'rows' => array_values($rows)
			]
		]);
		$response->setTitle($title);
		$response->setFileName($file_prefix.'_'.date('Ymd_His').'.'.$format);

		$this->setResponse($response);
	}

	/**
	 * 두 가지 열 정의 형태를 [['key' => .., 'label' => .., 'type' => ..], ...] 하나로 맞춘다.
	 */
	private static function normalizeExportColumns(array $columns): array {
		$normalized = [];

		foreach ($columns as $key => $column) {
			$normalized[] = is_array($column)
				? [
					'key' => $key,
					'label' => (string) ($column['label'] ?? $key),
					'type' => ($column['type'] ?? 'text') === 'number' ? 'number' : 'text'
				]
				: ['key' => $key, 'label' => (string) $column, 'type' => 'text'];
		}

		return $normalized;
	}
}
