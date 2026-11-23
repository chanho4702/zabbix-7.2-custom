<?php declare(strict_types = 0);
/*
 * 내보내기 파일(CSV / Excel)을 만들어 브라우저로 내려보내는 공통 레이아웃.
 *
 * 화면마다 만들 필요가 없다. manifest.json 의 내보내기 액션이 "layout": "export/layout.file" 로 이 파일을 가리키고,
 * 컨트롤러의 ExportTrait::setExportResponse() 가 넘긴 값을 여기서 파일로 바꾼다.
 * (전체 사용법: includes/export/ExportTrait.php 머리말)
 *
 * ── 이 파일이 하는 일 ──
 *   화면의 [CSV 내보내기] / [Excel 내보내기] 버튼을 누르면 xxx.export 액션이 실행된다.
 *   그 액션의 컨트롤러는 표의 열 정의와 전체 행을 넘기기만 하고, 실제 파일 내용은 이 파일이 만든다.
 *   보통 화면은 HTML 을 출력하지만, 이 레이아웃은 HTML 대신 파일 내용(글자 또는 바이너리)을 그대로 출력한다.
 *
 * ── 쓰는 액션 (manifest.json 에서 "layout": "export/layout.file" 인 것) ──
 *   swinventory.export, lowserver.export, highserver.export, peakevent.export, winpatch.export
 *
 * ── 데이터 흐름 ──
 *   컨트롤러 ExportTrait::setExportResponse()   열 정의 + 행 + 형식을 $data['export'] 에 담는다
 *     └─ 이 파일
 *          ├─ 형식이 xlsx 면 XlsxWriter::build()  -> Excel 파일 내용(바이너리 문자열)
 *          ├─ 그 밖에는     CsvWriter::toCsv()    -> CSV 글자
 *          ├─ header() 로 "이것은 내려받는 파일이다" 라고 브라우저에 알린다
 *          └─ echo 로 파일 내용을 출력한다
 *
 * ── 받는 값 ──
 *   $data['export']['format']    'csv' 또는 'xlsx'
 *   $data['export']['title']     시트 이름
 *   $data['export']['columns']   [['key' => .., 'label' => .., 'type' => 'text'|'number'], ...]
 *   $data['export']['rows']      [['키' => 값, ...], ...]
 *   $data['page']['file']        다운로드 파일명 (컨트롤러의 setFileName 값)
 *
 * 코어의 CSV 레이아웃(ui/app/views/layout.csv.php)과 같은 역할이며, 파일 종류만 형식에 따라 바꾼다.
 *
 * ── 관련 파일 ──
 *   includes/export/ExportTrait.php     컨트롤러가 쓰는 내보내기 공통 코드 (이 파일로 값을 넘긴다)
 *   includes/export/ExportFormat.php    형식 이름(csv, xlsx)과 형식별 Content-Type
 *   includes/export/CsvWriter.php       CSV 글자를 만든다
 *   includes/export/XlsxWriter.php      Excel(xlsx) 파일을 만든다
 *   includes/export/ExportButtons.php   화면의 내보내기 버튼
 *
 * @var CView $this
 * @var array $data
 */

use Modules\DMove\Includes\Export\CsvWriter;
use Modules\DMove\Includes\Export\ExportFormat;
use Modules\DMove\Includes\Export\XlsxWriter;

$export = $data['export'];
$format = $export['format'];

// $body 에 파일 내용 전체를 문자열로 만든다. 형식에 따라 만드는 방법이 다르다.
switch ($format) {
	case ExportFormat::XLSX:
		// Excel: 열 정의(제목, 숫자/글자 구분)와 행을 그대로 넘기면 xlsx 파일 내용이 돌아온다.
		$body = XlsxWriter::build($export['title'], $export['columns'], $export['rows']);
		break;

	// default: 위의 case 에 해당하지 않는 모든 값. 모르는 형식이 와도 CSV 로 처리한다.
	default:
		$format = ExportFormat::CSV;

		// CSV 는 열 순서대로 값만 뽑아 넘긴다.
		// array_column(배열, 'label'): 각 열 정의에서 label 값만 모은다. 예) ['호스트', 'IP', ...]
		$header = array_column($export['columns'], 'label');
		$rows = [];

		foreach ($export['rows'] as $row) {
			$line = [];

			foreach ($export['columns'] as $column) {
				// 행에 그 키가 없으면 빈 칸으로 둔다.  ?? : 왼쪽이 없거나 null 이면 오른쪽.
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
// strlen(): 문자열의 바이트 수. 브라우저가 파일 크기를 미리 알 수 있게 한다.
header('Content-Length: '.strlen($body));

echo $body;
