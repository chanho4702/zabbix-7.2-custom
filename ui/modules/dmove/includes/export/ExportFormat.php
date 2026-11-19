<?php declare(strict_types = 0);
/*
 * 내보내기 파일 형식 목록. (ExportTrait, ExportButtons, views/export/layout.file.php 가 같이 쓴다)
 *
 * 형식을 하나 더 만들려면 (예: json)
 *   1) 여기에 상수와 CONTENT_TYPES 한 줄을 추가하고
 *   2) views/export/layout.file.php 의 switch 에 만드는 코드를 추가하고
 *   3) ExportButtons::create() 에 버튼을 추가한다.
 */

namespace Modules\DMove\Includes\Export;

class ExportFormat {

	public const CSV = 'csv';
	public const XLSX = 'xlsx';

	/** 허용하는 형식 전체. 값이 곧 파일 확장자다. */
	public const ALL = [self::CSV, self::XLSX];

	/** 형식 => 브라우저에 알려 줄 파일 종류(Content-Type). */
	public const CONTENT_TYPES = [
		self::CSV => 'text/csv; charset=UTF-8',
		self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
	];
}
