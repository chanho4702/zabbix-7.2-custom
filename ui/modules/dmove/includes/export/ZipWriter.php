<?php declare(strict_types = 0);
/*
 * 아주 작은 ZIP 파일 작성기. (엑셀 .xlsx 파일을 만들 때만 쓴다)
 *
 * 왜 직접 만들었나
 *   .xlsx 는 "여러 XML 파일을 ZIP 으로 묶은 것"이다.
 *   PHP 의 ZipArchive 는 zip 확장이 설치되어 있어야 하는데 Zabbix 는 그 확장을 요구하지 않는다.
 *   (공식 Docker 이미지에도 없다) 그래서 추가 설치 없이 동작하도록 ZIP 형식을 직접 쓴다.
 *   zlib 확장(Zabbix 필수 확장)이 있으면 압축하고, 없으면 압축 없이 묶는다. 어느 쪽이든 엑셀에서 열린다.
 *
 * 이 파일은 고칠 일이 거의 없다. 사용 예:
 *   $zip = new ZipWriter();
 *   $zip->addFile('a.txt', '내용');
 *   $binary = $zip->finish();
 *
 * ── 누가 쓰는가 ──
 *   includes/export/XlsxWriter.php 의 build() 한 곳. 엑셀 파일을 이루는 XML 6개를 묶는다.
 *
 * ── ZIP 파일의 구조 (이 클래스가 만드는 모양) ──
 *   [파일1 헤더][파일1 내용][파일2 헤더][파일2 내용] ...    <- $body      (addFile() 이 하나씩 붙인다)
 *   [파일1 목록 항목][파일2 목록 항목] ...                  <- $directory (addFile() 이 하나씩 붙인다)
 *   [끝 표시]                                               <- finish() 가 붙인다
 *
 *   프로그램은 ZIP 을 열 때 맨 끝의 "끝 표시"부터 읽고, 목록을 보고, 각 파일이 시작하는 위치로 찾아간다.
 *   그래서 목록 항목마다 "이 파일은 몇 번째 바이트에서 시작한다"는 값을 적어 둔다.
 *
 * ── 관련 파일 ──
 *   includes/export/XlsxWriter.php   이 클래스를 쓰는 곳
 */

namespace Modules\DMove\Includes\Export;

class ZipWriter {

	/** 지금까지 쓴 파일 본문들(로컬 헤더 포함). */
	private string $body = '';

	/** 파일 목록 영역(중앙 디렉터리). ZIP 맨 끝에 붙는다. */
	private string $directory = '';

	/** 지금까지 넣은 파일 수. 끝 표시에 적는다. */
	private int $count = 0;

	/**
	 * 묶음에 파일 하나를 넣는다.
	 *
	 * 하는 일
	 *   1) 내용의 검사값(CRC32)과 크기를 구한다. 푸는 쪽이 파일이 깨지지 않았는지 확인할 때 쓴다.
	 *   2) zlib 확장이 있으면 압축해 본다. 압축한 쪽이 더 작을 때만 압축본을 쓴다.
	 *   3) "헤더 + 이름 + 내용"을 $body 뒤에 붙인다.
	 *   4) 같은 정보에 "이 파일이 $body 의 몇 번째 바이트에서 시작하는가"를 더해 $directory 뒤에 붙인다.
	 *
	 * 사용 예:
	 *   $zip->addFile('xl/workbook.xml', '<?xml ...>');
	 *
	 * @param string $name     묶음 안에서의 파일 경로. 폴더는 / 로 구분한다. 예) 'xl/worksheets/sheet1.xml'
	 * @param string $content  파일 내용.
	 */
	public function addFile(string $name, string $content): void {
		// crc32(): 내용으로 계산한 4바이트 검사값. strlen(): 바이트 수. (글자 수가 아니다)
		$crc = crc32($content);
		$size = strlen($content);

		// 8 = deflate 압축, 0 = 압축 없음(저장).
		$method = 0;
		$data = $content;

		if (function_exists('gzdeflate')) {
			$deflated = gzdeflate($content);

			if ($deflated !== false && strlen($deflated) < $size) {
				$method = 8;
				$data = $deflated;
			}
		}

		[$dos_time, $dos_date] = self::dosDateTime();
		// 이 파일의 헤더가 시작하는 위치 = 지금까지 쓴 본문의 길이. 목록 항목에 적는다.
		$offset = strlen($this->body);

		/*
		 * pack(): 숫자를 정해진 바이트 수의 이진 데이터로 바꾼다.  V = 4바이트, v = 2바이트. (작은 자리 바이트부터 쓴다)
		 * 아래 숫자들의 순서와 의미는 ZIP 형식 규격(로컬 파일 헤더 / 중앙 디렉터리)에 정해져 있다.
		 *
		 * 로컬 파일 헤더 (형식 글자 'VvvvvvVVVvv' 의 순서대로)
		 *   0x04034b50   "여기서 파일이 시작한다"는 표시. 바이트로 쓰면 'PK' 03 04 다.
		 *   20           푸는 데 필요한 ZIP 버전 (2.0)
		 *   0            옵션 플래그 (없음)
		 *   $method      압축 방식 (0 = 저장, 8 = deflate)
		 *   시간, 날짜   DOS 형식
		 *   $crc         원래 내용의 검사값
		 *   strlen($data)  압축한 크기,  $size  원래 크기
		 *   strlen($name)  파일 이름의 바이트 수
		 *   0            추가 정보 길이 (없음)
		 *
		 * 중앙 디렉터리 항목 (0x02014b50 으로 시작)
		 *   위와 같은 값에 "만든 프로그램의 ZIP 버전(20)"이 앞에 하나 더 있고,
		 *   뒤에 주석 길이, 디스크 번호, 파일 속성(모두 0)과 $offset(로컬 헤더의 위치)이 붙는다.
		 */
		$this->body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, $dos_time, $dos_date, $crc, strlen($data),
			$size, strlen($name), 0
		).$name.$data;

		$this->directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, $dos_time, $dos_date, $crc,
			strlen($data), $size, strlen($name), 0, 0, 0, 0, 0, $offset
		).$name;

		$this->count++;
	}

	/**
	 * ZIP 파일 전체(이진 문자열)를 돌려준다.
	 *
	 * 본문 + 파일 목록 + 끝 표시를 이어 붙인다. addFile() 을 모두 부른 뒤 마지막에 한 번 부른다.
	 *
	 * 끝 표시('VvvvvVVv' 의 순서대로)
	 *   0x06054b50          "여기가 끝"이라는 표시
	 *   0, 0                디스크 번호 (나누지 않은 한 개짜리 파일이라 0)
	 *   $count, $count      파일 수 (이 디스크의 수, 전체 수)
	 *   strlen($directory)  파일 목록의 크기
	 *   strlen($body)       파일 목록이 시작하는 위치 (= 본문의 길이)
	 *   0                   주석 길이 (없음)
	 *
	 * @return string  ZIP 파일 내용. 그대로 파일로 저장하거나 브라우저로 내려보내면 된다.
	 */
	public function finish(): string {
		$end = pack('VvvvvVVv', 0x06054b50, 0, 0, $this->count, $this->count, strlen($this->directory),
			strlen($this->body), 0
		);

		return $this->body.$this->directory.$end;
	}

	/**
	 * 현재 시각을 ZIP 이 쓰는 옛 DOS 형식(2바이트 시간, 2바이트 날짜)으로 바꾼다.
	 *
	 * 숫자 하나의 비트를 나눠서 여러 값을 담는 방식이다.
	 *   시간 16비트 = 시(5비트) | 분(6비트) | 초/2(5비트)        초는 2초 단위로만 저장된다.
	 *   날짜 16비트 = 연도-1980(7비트) | 월(4비트) | 일(5비트)
	 *
	 *   a << n : a 를 왼쪽으로 n 비트 민다. (자리를 만들어 준다)   a >> 1 : 2 로 나눈 몫.
	 *   a | b  : 비트 OR. 자리가 겹치지 않는 값들을 하나로 합친다.
	 *
	 * 예) 13시 38분 44초 -> (13 << 11) | (38 << 5) | 22
	 *
	 * @return array  [시간 값, 날짜 값]
	 */
	private static function dosDateTime(): array {
		$t = getdate();

		return [
			($t['hours'] << 11) | ($t['minutes'] << 5) | ($t['seconds'] >> 1),
			(($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday']
		];
	}
}
