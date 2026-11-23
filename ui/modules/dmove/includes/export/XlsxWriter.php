<?php declare(strict_types = 0);
/*
 * 엑셀(.xlsx) 파일 작성기. 표 한 장짜리 통합 문서를 만든다.
 *
 * 만들어지는 엑셀의 모양
 *   - 제목 줄: 굵은 흰 글씨 + 진한 파란 배경, 가운데 정렬, 조금 높은 줄
 *   - 제목 줄 고정(스크롤해도 보임) + 자동 필터(▼ 버튼)
 *   - 모든 칸에 얇은 테두리
 *   - 열 너비를 내용 길이에 맞춤 (한글은 2칸으로 계산, 너무 긴 열은 MAX_WIDTH 에서 멈추고 줄바꿈)
 *   - 값은 기본적으로 "텍스트"로 넣는다. 그래서 IP, 앞자리가 0 인 번호, 긴 숫자가 그대로 보이고
 *     =, +, - 로 시작하는 값이 수식으로 실행되지 않는다.
 *   - 숫자 열로 지정한 열(type => number)만 숫자로 넣어 오른쪽 정렬한다. (엑셀에서 합계/정렬 가능)
 *   - 인쇄: 가로 방향, 한 페이지 너비에 맞춤
 *
 * 모양을 바꾸고 싶을 때 고칠 곳
 *   색/글꼴     : 아래 상수 HEADER_FILL, HEADER_FONT_COLOR, FONT_NAME, FONT_SIZE
 *   열 너비     : MIN_WIDTH, MAX_WIDTH
 *   그 밖의 서식: stylesXml()
 *
 * 화면에서는 이 클래스를 직접 부르지 않는다. ExportTrait 가 대신 불러 준다. (사용법: ExportTrait.php)
 *
 * .xlsx 의 정체: 여러 XML 파일을 ZIP 으로 묶은 것이다. 아래 build() 가 그 XML 6개를 만들어 ZipWriter 로 묶는다.
 *
 * ── 누가 쓰는가 ──
 *   views/export/layout.file.php 한 곳에서만 XlsxWriter::build() 를 부른다.
 *   [Excel 내보내기] 버튼이 있는 모든 화면의 엑셀 파일이 여기서 만들어진다.
 *
 * ── 데이터 흐름 ──
 *   컨트롤러 setExportResponse() -> views/export/layout.file.php -> XlsxWriter::build(시트 이름, 열 정의, 행)
 *     ├─ contentTypesXml()   [Content_Types].xml          묶음 안 파일들의 종류 목록
 *     ├─ rootRelsXml()       _rels/.rels                  "통합 문서는 xl/workbook.xml 이다"
 *     ├─ workbookXml()       xl/workbook.xml              시트 목록 (시트 1장)
 *     ├─ workbookRelsXml()   xl/_rels/workbook.xml.rels   "시트와 서식 파일은 여기 있다"
 *     ├─ stylesXml()         xl/styles.xml                글꼴, 배경, 테두리
 *     ├─ sheetXml()          xl/worksheets/sheet1.xml     실제 표 내용
 *     └─ ZipWriter::finish() 로 하나로 묶은 이진 문자열을 돌려준다
 *
 * ── 관련 파일 ──
 *   includes/export/ZipWriter.php      ZIP 묶기
 *   includes/export/ExportTrait.php    컨트롤러용 내보내기 플러그인 (사용법)
 *   includes/export/CsvWriter.php      CSV 쪽 작성기
 *   views/export/layout.file.php       이 클래스를 부르는 곳
 */

namespace Modules\DMove\Includes\Export;

class XlsxWriter {

	/** 제목 줄 배경색 / 글자색. 'FF' + RGB 16진수. (FF1F4E78 = 진한 파랑) */
	private const HEADER_FILL = 'FF1F4E78';
	private const HEADER_FONT_COLOR = 'FFFFFFFF';
	/** 칸 테두리 색. (FFBFBFBF = 옅은 회색) */
	private const BORDER_COLOR = 'FFBFBFBF';

	/** 글꼴 이름과 크기(pt). 제목 줄과 본문이 같이 쓴다. */
	private const FONT_NAME = '맑은 고딕';
	private const FONT_SIZE = 10;

	/** 열 너비 하한/상한. 단위는 엑셀의 "문자 수". */
	private const MIN_WIDTH = 8;
	private const MAX_WIDTH = 60;

	/** 엑셀 한 칸에 넣을 수 있는 최대 글자 수. */
	private const MAX_CELL_LENGTH = 32767;

	/** styles.xml 의 cellXfs 순번. (stylesXml() 의 순서와 같아야 한다) */
	private const STYLE_HEADER = 1;
	private const STYLE_TEXT = 2;
	private const STYLE_NUMBER = 3;

	/**
	 * 엑셀 파일 하나를 통째로 만든다. 이 클래스에서 밖에서 부르는 메소드는 이것 하나다.
	 *
	 * XML 파일 6개를 만들어 ZIP 으로 묶는다. 파일 이름과 폴더 위치는 엑셀 규격에 정해져 있어서 바꾸면 안 된다.
	 *
	 * 사용 예:
	 *   $binary = XlsxWriter::build('디스크 현황',
	 *       [['key' => 'host', 'label' => '호스트', 'type' => 'text'], ['key' => 'used', 'label' => '사용량', 'type' => 'number']],
	 *       [['host' => 'web01', 'used' => '12.5']]
	 *   );
	 *
	 * @param string $sheet_name  시트 이름.
	 * @param array  $columns     [['key' => 'host', 'label' => '호스트', 'type' => 'text'|'number'], ...]
	 * @param array  $rows        [['host' => 'web01', ...], ...]  (키는 $columns 의 key)
	 *
	 * @return string  .xlsx 파일 내용(이진 문자열).
	 */
	public static function build(string $sheet_name, array $columns, array $rows): string {
		$zip = new ZipWriter();

		$zip->addFile('[Content_Types].xml', self::contentTypesXml());
		$zip->addFile('_rels/.rels', self::rootRelsXml());
		$zip->addFile('xl/workbook.xml', self::workbookXml($sheet_name));
		$zip->addFile('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
		$zip->addFile('xl/styles.xml', self::stylesXml());
		$zip->addFile('xl/worksheets/sheet1.xml', self::sheetXml($columns, $rows));

		return $zip->finish();
	}

	// ── 시트 본문 ────────────────────────────────────────────────────────────

	/**
	 * 시트 한 장의 XML(xl/worksheets/sheet1.xml)을 만든다. 실제 표 내용이 여기에 들어간다.
	 *
	 * 하는 일
	 *   1) 1행에 제목 줄을 만든다. (열 정의의 label)
	 *   2) 2행부터 데이터 줄을 만든다. 숫자 열이고 값이 숫자면 숫자 칸, 아니면 텍스트 칸.
	 *   3) 줄을 만들면서 열마다 가장 긴 값의 폭을 기억해 두고, 그 값으로 열 너비를 정한다.
	 *   4) 제목 줄 고정, 자동 필터, 인쇄 설정을 붙인다.
	 *
	 * XML 의 모양 (줄 하나 = <row>, 칸 하나 = <c>)
	 *   <row r="2"><c r="A2" s="2" t="inlineStr"><is><t>web01</t></is></c><c r="B2" s="3"><v>12.5</v></c></row>
	 *     r = 칸 이름(A2),  s = 서식 번호(STYLE_* 상수),  <v> = 숫자 값,  <is><t> = 글자 값
	 *
	 * @param array $columns  [['key' => 'host', 'label' => '호스트', 'type' => 'text'|'number'], ...]
	 * @param array $rows     [['host' => 'web01', ...], ...]  행에 없는 키는 빈 칸이 된다.
	 *
	 * @return string  시트 XML 글.
	 */
	private static function sheetXml(array $columns, array $rows): string {
		$column_count = count($columns);
		$widths = [];
		$xml_rows = [];

		// 1행: 제목 줄.
		$cells = '';

		// array_values(): 키를 0, 1, 2 ... 로 다시 매긴다. $index 가 곧 열 번호(0 = A열)가 된다.
		foreach (array_values($columns) as $index => $column) {
			$label = self::clean((string) $column['label']);
			// 제목 줄에는 필터 버튼(▼)이 들어갈 자리 3칸을 더 준다.
			$widths[$index] = self::displayWidth($label) + 3;
			$cells .= self::textCell($index, 1, $label, self::STYLE_HEADER);
		}

		// ht="22" customHeight="1": 줄 높이를 22 로 직접 지정한다. (기본보다 조금 높게)
		$xml_rows[] = '<row r="1" ht="22" customHeight="1">'.$cells.'</row>';

		// 2행부터: 데이터.
		$row_number = 1;

		foreach ($rows as $row) {
			$row_number++;
			$cells = '';

			foreach (array_values($columns) as $index => $column) {
				$value = self::clean((string) ($row[$column['key']] ?? ''));
				$widths[$index] = max($widths[$index], self::displayWidth($value));

				// 숫자 열이고 값이 정말 숫자일 때만 숫자 칸으로 넣는다. 아니면(빈 값, '-' 등) 텍스트로 넣는다.
				// $value + 0 : 숫자 모양의 글자를 숫자로 바꾼다. 예) '012.50' -> 12.5
				$cells .= ($column['type'] === 'number' && is_numeric($value))
					? '<c r="'.self::cellName($index, $row_number).'" s="'.self::STYLE_NUMBER.'"><v>'.
						self::esc((string) ($value + 0)).'</v></c>'
					: self::textCell($index, $row_number, $value, self::STYLE_TEXT);
			}

			$xml_rows[] = '<row r="'.$row_number.'">'.$cells.'</row>';
		}

		// 열 너비.
		$cols = '';

		foreach ($widths as $index => $width) {
			// + 2 는 칸 안쪽 여백. min/max 로 MIN_WIDTH 이상, MAX_WIDTH 이하로 맞춘다.
			// <col min max>: 몇 번째 열부터 몇 번째 열까지인지(1부터 센다). customWidth="1" = 너비를 직접 지정했다는 표시.
			$width = min(self::MAX_WIDTH, max(self::MIN_WIDTH, $width + 2));
			$cols .= '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"/>';
		}

		// 표 전체 범위. 예) A1:J25
		$range = 'A1:'.self::cellName(max(0, $column_count - 1), $row_number);

		/*
		 * 아래 태그들은 엑셀 규격에 정해진 순서대로 적어야 한다. 순서가 바뀌면 엑셀이 "복구" 창을 띄운다.
		 *   sheetPr -> sheetViews -> sheetFormatPr -> cols -> sheetData -> autoFilter -> pageMargins -> pageSetup
		 */
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
			'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
			// fitToPage: 인쇄할 때 페이지에 맞춤 사용.
			'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'.
			// pane: 1행을 고정한다. (ySplit="1" = 위에서 1줄)
			'<sheetViews><sheetView workbookViewId="0">'.
				'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'.
			'</sheetView></sheetViews>'.
			'<sheetFormatPr defaultRowHeight="16.5"/>'.
			($cols !== '' ? '<cols>'.$cols.'</cols>' : '').
			'<sheetData>'.implode('', $xml_rows).'</sheetData>'.
			// autoFilter: 제목 줄에 필터 버튼을 단다.
			($column_count > 0 ? '<autoFilter ref="'.$range.'"/>' : '').
			'<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'.
			// 가로 방향, 너비 1페이지에 맞춤, 높이는 제한 없음(0). paperSize="9" 는 A4 용지.
			'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'.
			'</worksheet>';
	}

	/**
	 * 텍스트 칸. t="inlineStr" 은 "이 칸 안에 글자를 직접 적는다"는 뜻이다.
	 * 이렇게 넣은 값은 엑셀이 수식이나 숫자로 해석하지 않는다.
	 * xml:space="preserve" 는 값 앞뒤의 빈칸을 지우지 말라는 표시다.
	 *
	 * @param int    $column_index  열 번호. 0 = A열.
	 * @param int    $row_number    줄 번호. 1 = 첫 줄.
	 * @param string $value         칸에 넣을 글자. (clean() 을 거친 값)
	 * @param int    $style         서식 번호. STYLE_HEADER 또는 STYLE_TEXT.
	 *
	 * @return string  예) <c r="A2" s="2" t="inlineStr"><is><t xml:space="preserve">web01</t></is></c>
	 */
	private static function textCell(int $column_index, int $row_number, string $value, int $style): string {
		return '<c r="'.self::cellName($column_index, $row_number).'" s="'.$style.'" t="inlineStr">'.
			'<is><t xml:space="preserve">'.self::esc($value).'</t></is></c>';
	}

	/**
	 * 칸 이름. (0, 1) -> A1, (27, 3) -> AB3
	 *
	 * 엑셀의 열 이름은 A ~ Z 다음에 AA, AB ... 로 이어진다. 26진법과 비슷하지만 0 에 해당하는 글자가 없어서
	 * 아래처럼 1 을 빼 가며 계산한다.
	 *
	 * @param int $column_index  열 번호. 0 = A열, 25 = Z열, 26 = AA열.
	 * @param int $row_number    줄 번호. 1 = 첫 줄.
	 *
	 * @return string  예) 'A1', 'AB3'
	 */
	private static function cellName(int $column_index, int $row_number): string {
		$letters = '';
		$n = $column_index + 1;

		while ($n > 0) {
			$remainder = ($n - 1) % 26;
			// chr(65) = 'A'
			$letters = chr(65 + $remainder).$letters;
			$n = intdiv($n - $remainder - 1, 26);
		}

		return $letters.$row_number;
	}

	/**
	 * 화면에 보이는 폭을 센다. 한글/한자 같은 넓은 글자는 2칸, 나머지는 1칸.
	 * 여러 줄이면 가장 긴 줄 기준.
	 *
	 * 예) 'web01' -> 5,  '호스트' -> 6,  "ab\n가나다" -> 6
	 *   mb_strwidth : 글자의 표시 폭을 센다. (넓은 글자 2, 좁은 글자 1)
	 *
	 * @return int  칸 수.
	 */
	private static function displayWidth(string $value): int {
		$max = 0;

		foreach (preg_split('/\R/u', $value) ?: [$value] as $line) {
			$max = max($max, mb_strwidth($line, 'UTF-8'));
		}

		return $max;
	}

	/**
	 * 엑셀이 받아들이지 못하는 글자를 정리한다.
	 *
	 * 칸에 넣는 모든 값(제목, 데이터, 시트 이름)이 이 메소드를 거친다.
	 * 깨진 글자 정리 -> 제어 문자 삭제 -> 줄바꿈 통일 -> 너무 긴 값 자르기 순서로 처리한다.
	 *
	 * @return string  정리된 값. 최대 MAX_CELL_LENGTH 글자.
	 */
	private static function clean(string $value): string {
		// 깨진 UTF-8 바이트를 정리한다. (깨진 글자가 있으면 파일 전체가 안 열린다)
		$value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
		// XML 에 쓸 수 없는 제어 문자를 지운다. (탭, 줄바꿈은 남긴다)
		// [\x00-\x08\x0B\x0C\x0E-\x1F] : 문자 코드 0~31 중 탭(09), 줄바꿈(0A), CR(0D)을 뺀 나머지.
		$value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
		// 줄바꿈을 \n 하나로 통일한다.
		$value = str_replace(["\r\n", "\r"], "\n", $value);

		return mb_substr($value, 0, self::MAX_CELL_LENGTH, 'UTF-8');
	}

	/**
	 * XML 특수문자(& < > " ')를 안전하게 바꾼다.
	 *
	 * 값에 < 나 & 가 그대로 들어가면 XML 이 깨져서 엑셀이 파일을 열지 못한다.
	 * 예) 'a<b & c' -> 'a&lt;b &amp; c'
	 *   ENT_XML1   : XML 규칙으로 바꾼다.
	 *   ENT_QUOTES : 큰따옴표와 작은따옴표도 바꾼다.
	 *   A | B      : 두 옵션을 함께 켠다. (비트 OR)
	 */
	private static function esc(string $value): string {
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	// ── 서식 ─────────────────────────────────────────────────────────────────

	/**
	 * 글꼴, 배경, 테두리, 그리고 그 조합(cellXfs)을 정의한다.
	 * 칸은 s="번호" 로 cellXfs 의 몇 번째 조합을 쓸지 가리킨다. (위의 STYLE_* 상수)
	 *
	 * 읽는 법: <fonts>, <fills>, <borders> 에 재료를 번호 순서로 적어 두고,
	 * <cellXfs> 의 <xf fontId="1" fillId="2" borderId="1"> 처럼 "몇 번 글꼴 + 몇 번 배경 + 몇 번 테두리"로 조합한다.
	 * 번호는 모두 0 부터 센다.
	 *
	 * @return string  xl/styles.xml 의 내용.
	 */
	private static function stylesXml(): string {
		$font = '<sz val="'.self::FONT_SIZE.'"/><name val="'.self::esc(self::FONT_NAME).'"/>';
		$border_side = ' style="thin"><color rgb="'.self::BORDER_COLOR.'"/>';

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
			'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
			// 글꼴 0: 기본, 1: 제목 줄(굵게, 흰색)
			'<fonts count="2">'.
				'<font>'.$font.'</font>'.
				'<font><b/><color rgb="'.self::HEADER_FONT_COLOR.'"/>'.$font.'</font>'.
			'</fonts>'.
			// 배경 0, 1 은 엑셀이 예약한 값이라 그대로 둔다. 2: 제목 줄 배경
			'<fills count="3">'.
				'<fill><patternFill patternType="none"/></fill>'.
				'<fill><patternFill patternType="gray125"/></fill>'.
				'<fill><patternFill patternType="solid"><fgColor rgb="'.self::HEADER_FILL.'"/></patternFill></fill>'.
			'</fills>'.
			// 테두리 0: 없음, 1: 사방 얇은 선
			'<borders count="2">'.
				'<border><left/><right/><top/><bottom/><diagonal/></border>'.
				'<border>'.
					'<left'.$border_side.'</left><right'.$border_side.'</right>'.
					'<top'.$border_side.'</top><bottom'.$border_side.'</bottom><diagonal/>'.
				'</border>'.
			'</borders>'.
			'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'.
			// numFmtId 49 = 텍스트 서식(@), 0 = 일반
			'<cellXfs count="4">'.
				// 0: 기본
				'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'.
				// 1: 제목 줄
				'<xf numFmtId="49" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1"'.
					' applyFill="1" applyBorder="1" applyAlignment="1">'.
					'<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'.
				// 2: 본문 텍스트 (위쪽 맞춤, 길면 줄바꿈)
				'<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"'.
					' applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'.
				// 3: 본문 숫자 (오른쪽 맞춤)
				'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'.
					'<alignment horizontal="right" vertical="top"/></xf>'.
			'</cellXfs>'.
			'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'.
			'</styleSheet>';
	}

	// ── 아래는 .xlsx 가 요구하는 고정 파일들. 고칠 일이 없다. ────────────────

	/**
	 * 통합 문서 XML(xl/workbook.xml)을 만든다. "이 파일에 어떤 시트가 있는가"를 적는 곳이다.
	 *
	 * 시트는 1장뿐이다. 시트 이름만 바뀌고 나머지는 고정이다.
	 * r:id="rId1" 은 workbookRelsXml() 의 rId1(= worksheets/sheet1.xml)을 가리킨다.
	 *
	 * @param string $sheet_name  시트 이름. 엑셀이 받지 않는 글자는 빈칸으로 바꾸고 31자로 자른다.
	 *                            예) '저사용/고사용' -> '저사용 고사용',  '' -> 'Sheet1'
	 *
	 * @return string  xl/workbook.xml 의 내용.
	 */
	private static function workbookXml(string $sheet_name): string {
		// 시트 이름 규칙: 31자 이하, \ / ? * [ ] : 사용 불가, 비어 있으면 안 됨.
		// 정규식 [\\\\\/?*\[\]:] : 대괄호 안의 글자 중 하나. \\\\ 는 역슬래시 한 글자를 뜻한다. (PHP 문자열과 정규식에서 두 번 풀린다)
		$sheet_name = preg_replace('/[\\\\\/?*\[\]:]/u', ' ', self::clean($sheet_name)) ?? '';
		$sheet_name = trim(mb_substr($sheet_name, 0, 31, 'UTF-8'));

		if ($sheet_name === '') {
			$sheet_name = 'Sheet1';
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
			'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'.
			' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
			'<sheets><sheet name="'.self::esc($sheet_name).'" sheetId="1" r:id="rId1"/></sheets>'.
			'</workbook>';
	}

	/**
	 * 종류 목록 XML([Content_Types].xml)을 만든다. 묶음 안의 각 파일이 무엇인지 엑셀에 알려 준다.
	 *
	 *   Default  : 확장자로 종류를 정한다. (.rels, .xml)
	 *   Override : 파일 하나를 콕 집어 종류를 정한다. (통합 문서, 시트, 서식)
	 *
	 * 내용은 항상 같다. build() 가 묶는 파일을 늘리면(예: 시트 2장) 여기에도 한 줄을 더해야 한다.
	 *
	 * @return string  [Content_Types].xml 의 내용.
	 */
	private static function contentTypesXml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
			'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
			'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
			'<Default Extension="xml" ContentType="application/xml"/>'.
			'<Override PartName="/xl/workbook.xml"'.
			' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
			'<Override PartName="/xl/worksheets/sheet1.xml"'.
			' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
			'<Override PartName="/xl/styles.xml"'.
			' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.
			'</Types>';
	}

	/**
	 * 최상위 관계 XML(_rels/.rels)을 만든다. 엑셀이 파일을 열 때 가장 먼저 읽는 길잡이다.
	 *
	 * "이 묶음의 본문(통합 문서)은 xl/workbook.xml 에 있다"는 한 줄만 들어 있다. 내용은 항상 같다.
	 *
	 * @return string  _rels/.rels 의 내용.
	 */
	private static function rootRelsXml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
			'<Relationship Id="rId1"'.
			' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'.
			' Target="xl/workbook.xml"/>'.
			'</Relationships>';
	}

	/**
	 * 통합 문서의 관계 XML(xl/_rels/workbook.xml.rels)을 만든다. 통합 문서에 딸린 파일의 위치를 적는다.
	 *
	 *   rId1 : 시트       -> worksheets/sheet1.xml   (workbookXml() 의 r:id="rId1" 이 이것을 가리킨다)
	 *   rId2 : 서식 파일  -> styles.xml
	 *
	 * Target 은 xl/ 폴더 기준의 경로다. 내용은 항상 같다.
	 *
	 * @return string  xl/_rels/workbook.xml.rels 의 내용.
	 */
	private static function workbookRelsXml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
			'<Relationship Id="rId1"'.
			' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'.
			' Target="worksheets/sheet1.xml"/>'.
			'<Relationship Id="rId2"'.
			' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'.
			' Target="styles.xml"/>'.
			'</Relationships>';
	}
}
