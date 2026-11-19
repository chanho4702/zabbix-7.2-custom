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
 */

namespace Modules\DMove\Includes\Export;

class ZipWriter {

	/** 지금까지 쓴 파일 본문들(로컬 헤더 포함). */
	private string $body = '';

	/** 파일 목록 영역(중앙 디렉터리). ZIP 맨 끝에 붙는다. */
	private string $directory = '';

	private int $count = 0;

	public function addFile(string $name, string $content): void {
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
		$offset = strlen($this->body);

		/*
		 * pack(): 숫자를 정해진 바이트 수의 이진 데이터로 바꾼다.  V = 4바이트, v = 2바이트 (리틀 엔디언)
		 * 아래 숫자들의 순서와 의미는 ZIP 형식 규격(로컬 파일 헤더 / 중앙 디렉터리)에 정해져 있다.
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
	 */
	public function finish(): string {
		$end = pack('VvvvvVVv', 0x06054b50, 0, 0, $this->count, $this->count, strlen($this->directory),
			strlen($this->body), 0
		);

		return $this->body.$this->directory.$end;
	}

	/**
	 * 현재 시각을 ZIP 이 쓰는 옛 DOS 형식(2바이트 시간, 2바이트 날짜)으로 바꾼다.
	 */
	private static function dosDateTime(): array {
		$t = getdate();

		return [
			($t['hours'] << 11) | ($t['minutes'] << 5) | ($t['seconds'] >> 1),
			(($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday']
		];
	}
}
