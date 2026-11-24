<?php declare(strict_types = 0);
/*
 * zip 파일을 "만드는 대로 바로" 브라우저로 내려보내는 도구.
 *
 * ── 왜 필요한가 ──
 *   CPU/Memory 전체 사용률 추출은 5버전에서 CSV 를 100만 줄마다 나눠 파일 여러 개로 받았다.
 *   (5버전: export_csv.sh 가 psql 결과를 split -l 1000000 으로 나눠 웹 폴더에 저장하고, 화면이 파일마다 내려받기를 걸었다)
 *   7버전은 서버에 파일을 남기지 않고 바로 흘려보낸다. 응답 하나로 파일 여러 개를 보내려면 zip 으로 묶어야 한다.
 *   PHP 의 ZipArchive 는 디스크에 파일을 만든 뒤에야 내보낼 수 있어서, zip 형식을 직접 써서 흘려보낸다.
 *
 * ── 쓰는 법 ──
 *   $zip = new ZipStreamWriter();
 *   $zip->startFile('a_00.csv');  $zip->write("...");  $zip->write("...");
 *   $zip->startFile('a_01.csv');  $zip->write("...");
 *   $zip->finish();               // 마지막에 꼭 부른다. 부르지 않으면 깨진 zip 이 된다.
 *
 * ── 형식 메모 (zip 규격 APPNOTE) ──
 *   파일마다 [로컬 헤더][압축된 내용][데이터 설명자] 를 쓰고, 끝에 [중앙 디렉터리][끝 레코드] 를 쓴다.
 *   내용을 다 쓰기 전에는 크기와 CRC 를 모르므로, 로컬 헤더에는 0 을 쓰고 플래그 3번 비트(데이터 설명자 사용)를 켠다.
 *   플래그 11번 비트는 파일명이 UTF-8 이라는 뜻이다.
 *   크기가 4GB 를 넘는 zip(ZIP64)은 지원하지 않는다. 줄 수 한도(max_rows, 1000만 줄)로는 넘지 않는다.
 */

namespace Modules\DMove\Includes\Export;

class ZipStreamWriter {

	/** 압축 방식. 8 = deflate(보통 압축), 0 = 압축 안 함. zlib 이 없으면 0 으로 쓴다. */
	private int $method;

	/** 지금까지 내보낸 바이트 수. 다음 파일의 로컬 헤더 위치가 된다. */
	private int $offset = 0;

	/** 다 쓴 파일들의 정보. 끝의 중앙 디렉터리에 쓴다. */
	private array $entries = [];

	/** 지금 쓰고 있는 파일. 없으면 null. */
	private ?array $current = null;

	/** @var resource|\DeflateContext|null  deflate 압축 상태 */
	private $deflate = null;

	/** @var \HashContext|null  CRC32 계산 상태 */
	private $crc = null;

	/** 파일 시각(MS-DOS 형식). 모든 파일에 같은 값을 쓴다. */
	private int $dos_time;
	private int $dos_date;

	public function __construct() {
		$this->method = function_exists('deflate_init') ? 8 : 0;

		$now = getdate();
		$this->dos_time = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
		$this->dos_date = (($now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];
	}

	/**
	 * 새 파일을 시작한다. 쓰던 파일이 있으면 먼저 닫는다.
	 *
	 * @param string $name  zip 안의 파일명. 예) 'usage_cpu_all_20261124_00.csv'
	 */
	public function startFile(string $name): void {
		$this->closeFile();

		$this->current = [
			'name' => $name,
			'offset' => $this->offset,
			'size' => 0,
			'compressed' => 0
		];

		// 로컬 헤더. pack('V'): 4바이트 리틀엔디언, pack('v'): 2바이트 리틀엔디언.
		$this->emit(pack('VvvvvvVVVvv',
			0x04034b50,			// 로컬 헤더 표시
			20,					// 풀 때 필요한 버전 (2.0)
			0x0808,				// 플래그: 3번(데이터 설명자) + 11번(UTF-8 파일명)
			$this->method,
			$this->dos_time,
			$this->dos_date,
			0, 0, 0,			// CRC, 압축 크기, 원래 크기: 끝의 데이터 설명자에 쓴다
			strlen($name),
			0					// 추가 필드 길이
		).$name);

		$this->crc = hash_init('crc32b');
		$this->deflate = $this->method === 8 ? deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]) : null;
	}

	/**
	 * 지금 파일에 내용을 이어 쓴다.
	 */
	public function write(string $data): void {
		if ($this->current === null || $data === '') {
			return;
		}

		hash_update($this->crc, $data);
		$this->current['size'] += strlen($data);

		$out = $this->deflate !== null ? deflate_add($this->deflate, $data, ZLIB_NO_FLUSH) : $data;
		$this->current['compressed'] += strlen($out);
		$this->emit($out);
	}

	/**
	 * 지금 파일을 닫고, 끝에 중앙 디렉터리를 써서 zip 을 마친다.
	 */
	public function finish(): void {
		$this->closeFile();

		$directory = '';

		foreach ($this->entries as $entry) {
			$directory .= pack('VvvvvvvVVVvvvvvVV',
				0x02014b50,			// 중앙 디렉터리 항목 표시
				20,					// 만든 버전
				20,					// 풀 때 필요한 버전
				0x0808,
				$this->method,
				$this->dos_time,
				$this->dos_date,
				$entry['crc'],
				$entry['compressed'],
				$entry['size'],
				strlen($entry['name']),
				0, 0,				// 추가 필드, 주석 길이
				0, 0,				// 디스크 번호, 내부 속성
				0,					// 외부 속성
				$entry['offset']	// 로컬 헤더 위치
			).$entry['name'];
		}

		$directory_offset = $this->offset;
		$this->emit($directory);

		// 끝 레코드.
		$this->emit(pack('VvvvvVVv',
			0x06054b50,
			0, 0,					// 디스크 번호
			count($this->entries),
			count($this->entries),
			strlen($directory),
			$directory_offset,
			0						// 주석 길이
		));
	}

	/**
	 * 쓰던 파일을 닫는다. 압축의 남은 부분과 데이터 설명자(CRC, 크기)를 쓴다.
	 */
	private function closeFile(): void {
		if ($this->current === null) {
			return;
		}

		if ($this->deflate !== null) {
			$out = deflate_add($this->deflate, '', ZLIB_FINISH);
			$this->current['compressed'] += strlen($out);
			$this->emit($out);
		}

		// hash_final(.., true): 4바이트 빅엔디언. unpack('N') 으로 정수로 바꾼다.
		$this->current['crc'] = unpack('N', hash_final($this->crc, true))[1];

		$this->emit(pack('VVVV',
			0x08074b50,				// 데이터 설명자 표시
			$this->current['crc'],
			$this->current['compressed'],
			$this->current['size']
		));

		$this->entries[] = $this->current;
		$this->current = null;
		$this->deflate = null;
		$this->crc = null;
	}

	private function emit(string $bytes): void {
		echo $bytes;
		$this->offset += strlen($bytes);
	}
}
