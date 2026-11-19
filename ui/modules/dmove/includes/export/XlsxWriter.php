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
 */

namespace Modules\DMove\Includes\Export;

class XlsxWriter {

	/** 제목 줄 배경색 / 글자색. 'FF' + RGB 16진수. (FF1F4E78 = 진한 파랑) */
	private const HEADER_FILL = 'FF1F4E78';
	private const HEADER_FONT_COLOR = 'FFFFFFFF';
	private const BORDER_COLOR = 'FFBFBFBF';

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

	private static function sheetXml(array $columns, array $rows): string {
		$column_count = count($columns);
		$widths = [];
		$xml_rows = [];

		// 1행: 제목 줄.
		$cells = '';

		foreach (array_values($columns) as $index => $column) {
			$label = self::clean((string) $column['label']);
			// 제목 줄에는 필터 버튼(▼)이 들어갈 자리 3칸을 더 준다.
			$widths[$index] = self::displayWidth($label) + 3;
			$cells .= self::textCell($index, 1, $label, self::STYLE_HEADER);
		}

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
			// + 2 는 칸 안쪽 여백.
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
			// 가로 방향, 너비 1페이지에 맞춤, 높이는 제한 없음(0).
			'<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'.
			'</worksheet>';
	}

	/**
	 * 텍스트 칸. t="inlineStr" 은 "이 칸 안에 글자를 직접 적는다"는 뜻이다.
	 * 이렇게 넣은 값은 엑셀이 수식이나 숫자로 해석하지 않는다.
	 */
	private static function textCell(int $column_index, int $row_number, string $value, int $style): string {
		return '<c r="'.self::cellName($column_index, $row_number).'" s="'.$style.'" t="inlineStr">'.
			'<is><t xml:space="preserve">'.self::esc($value).'</t></is></c>';
	}

	/**
	 * 칸 이름. (0, 1) -> A1, (27, 3) -> AB3
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
	 */
	private static function clean(string $value): string {
		// 깨진 UTF-8 바이트를 정리한다. (깨진 글자가 있으면 파일 전체가 안 열린다)
		$value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
		// XML 에 쓸 수 없는 제어 문자를 지운다. (탭, 줄바꿈은 남긴다)
		$value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
		// 줄바꿈을 \n 하나로 통일한다.
		$value = str_replace(["\r\n", "\r"], "\n", $value);

		return mb_substr($value, 0, self::MAX_CELL_LENGTH, 'UTF-8');
	}

	/** XML 특수문자(& < > ")를 안전하게 바꾼다. */
	private static function esc(string $value): string {
		return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	// ── 서식 ─────────────────────────────────────────────────────────────────

	/**
	 * 글꼴, 배경, 테두리, 그리고 그 조합(cellXfs)을 정의한다.
	 * 칸은 s="번호" 로 cellXfs 의 몇 번째 조합을 쓸지 가리킨다. (위의 STYLE_* 상수)
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

	private static function workbookXml(string $sheet_name): string {
		// 시트 이름 규칙: 31자 이하, \ / ? * [ ] : 사용 불가, 비어 있으면 안 됨.
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

	private static function rootRelsXml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
			'<Relationship Id="rId1"'.
			' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'.
			' Target="xl/workbook.xml"/>'.
			'</Relationships>';
	}

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
