<?php declare(strict_types = 0);
/*
 * 공통 CSV 내보내기.
 *
 * 5버전 대응: 공통 CSV export 모듈 (layout.csv + *.view.csv)
 *
 * 모든 커스텀 화면의 CSV 는 이 클래스를 거친다.
 * 화면에서는 직접 부르지 않는다. 내보내기 플러그인(ExportTrait)이 views/export/layout.file.php 를 통해 부른다.
 * 엑셀 서식이 들어간 파일은 XlsxWriter 가 만든다.
 *
 * TODO(고객환경): 5버전 공통 CSV 모듈에 구분자/인코딩(EUC-KR 변환 등) 규칙이 따로 있었다면 여기 한 곳만 고친다.
 */

namespace Modules\DMove\Includes\Export;

class CsvWriter {

	/**
	 * 엑셀이 UTF-8 한글을 깨뜨리지 않도록 파일 맨 앞에 BOM 을 붙인다.
	 * 엑셀이 아닌 프로그램에서 첫 컬럼명 앞에 이상한 글자가 보이면 false 로 바꾼다.
	 */
	private const WITH_BOM = true;

	/**
	 * @param array $header  ['컬럼1', '컬럼2', ...]
	 * @param array $rows    [['값1', '값2', ...], ...]
	 *
	 * @return string  CSV 파일 본문. 뷰에서 echo 하면 된다.
	 *
	 * 사용 예:
	 *   echo CsvWriter::toCsv(['호스트', 'IP'], [['web01', '10.0.0.1'], ['web02', '10.0.0.2']]);
	 */
	public static function toCsv(array $header, array $rows): string {
		$csv = [self::sanitizeRow($header)];

		foreach ($rows as $row) {
			$csv[] = self::sanitizeRow($row);
		}

		// zbx_toCSV: 코어 함수 (ui/include/func.inc.php). 큰따옴표 이스케이프와 줄 구분을 처리한다.
		return (self::WITH_BOM ? "\xEF\xBB\xBF" : '').zbx_toCSV($csv);
	}

	/**
	 * 한 줄의 모든 값을 안전한 문자열로 바꾼다.
	 * array_map(함수, 배열): 배열의 각 값에 함수를 적용한 새 배열을 만든다.
	 */
	private static function sanitizeRow(array $row): array {
		return array_map(static function ($value): string {
			// null 을 문자열 함수에 넘기면 PHP 8.1+ 에서 Deprecated 경고가 나므로 문자열로 고정한다.
			$value = (string) $value;

			/*
			 * CSV 수식 인젝션 방지: 값이 = + - @ 로 시작하면 엑셀이 수식으로 실행할 수 있다.
			 * 앞에 작은따옴표를 붙여 문자로 취급하게 한다.
			 * 단, 숫자(예: -5, +3.2)와 값 없음 표시('-')는 수식이 아니므로 그대로 둔다.
			 */
			$is_plain = is_numeric($value) || $value === '-';

			if ($value !== '' && !$is_plain && in_array($value[0], ['=', '+', '-', '@'], true)) {
				$value = "'".$value;
			}

			return $value;
		}, array_values($row));
	}
}
