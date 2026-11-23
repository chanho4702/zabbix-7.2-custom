<?php declare(strict_types = 0);
/*
 * 내보내기 파일 형식 목록. (ExportTrait, ExportButtons, views/export/layout.file.php 가 같이 쓴다)
 *
 * 형식을 하나 더 만들려면 (예: json)
 *   1) 여기에 상수와 CONTENT_TYPES 한 줄을 추가하고
 *   2) views/export/layout.file.php 의 switch 에 만드는 코드를 추가하고
 *   3) ExportButtons::create() 에 버튼을 추가한다.
 *
 * 이 클래스에는 메소드가 없다. 상수만 모아 둔 곳이다. ExportFormat::CSV 처럼 읽어 쓴다.
 *
 * 어디에 쓰이는가
 *   ExportTrait::exportInputFields()   주소의 format 값으로 ALL 에 있는 것만 받는다
 *   ExportButtons::create()            형식마다 버튼을 하나씩 만든다
 *   views/export/layout.file.php       형식에 따라 파일을 만들고, CONTENT_TYPES 로 응답 헤더를 정한다
 */

namespace Modules\DMove\Includes\Export;

class ExportFormat {

	/** 형식 이름. 주소의 format 값이자 파일 확장자다. 예) ...&format=xlsx -> 파일명.xlsx */
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
