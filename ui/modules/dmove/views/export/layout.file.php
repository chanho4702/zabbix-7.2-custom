<?php declare(strict_types = 0);
/*
 * 내보내기 파일(CSV / Excel)을 만들어 브라우저로 내려보내는 공통 레이아웃.
 *
 * 화면마다 만들 필요가 없다. manifest.json 의 내보내기 액션이 "layout": "export/layout.file" 로 이 파일을 가리키고,
 * 컨트롤러의 ExportTrait::setExportResponse() 가 넘긴 값을 여기서 파일로 바꾼다.
 * (전체 사용법: includes/export/ExportTrait.php 머리말)
 *
 * 받는 값
 *   $data['export']['format']    'csv' 또는 'xlsx'
 *   $data['export']['title']     시트 이름
 *   $data['export']['columns']   [['key' => .., 'label' => .., 'type' => 'text'|'number'], ...]
 *   $data['export']['rows']      [['키' => 값, ...], ...]
 *   $data['page']['file']        다운로드 파일명 (컨트롤러의 setFileName 값)
 *
 * 코어의 CSV 레이아웃(ui/app/views/layout.csv.php)과 같은 역할이며, 파일 종류만 형식에 따라 바꾼다.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Export\CsvWriter;
use Modules\DMove\Includes\Export\ExportFormat;
use Modules\DMove\Includes\Export\XlsxWriter;

$export = $data['export'];
$format = $export['format'];

switch ($format) {
	case ExportFormat::XLSX:
		$body = XlsxWriter::build($export['title'], $export['columns'], $export['rows']);
		break;

	default:
		$format = ExportFormat::CSV;

		// CSV 는 열 순서대로 값만 뽑아 넘긴다.
		$header = array_column($export['columns'], 'label');
		$rows = [];

		foreach ($export['rows'] as $row) {
			$line = [];

			foreach ($export['columns'] as $column) {
				$line[] = $row[$column['key']] ?? '';
			}

			$rows[] = $line;
		}

		$body = CsvWriter::toCsv($header, $rows);
}

// header(): HTTP 응답 헤더를 보낸다. 본문을 출력하기 전에 불러야 한다.
header('Content-Type: '.ExportFormat::CONTENT_TYPES[$format]);
// attachment: 브라우저가 화면에 표시하지 않고 파일로 저장하게 한다.
header('Content-Disposition: attachment; filename="'.$data['page']['file'].'"');
header('Content-Length: '.strlen($body));

echo $body;
