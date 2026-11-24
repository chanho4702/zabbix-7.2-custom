<?php declare(strict_types = 0);
/*
 * 계산 결과를 잠시 저장해 두는 도구. (저사용/고사용 서버의 집계 결과용)
 *
 * ── 왜 필요한가 ──
 *   저사용/고사용 화면은 페이지를 넘기거나 정렬을 바꿀 때마다 같은 집계를 처음부터 다시 돌렸다.
 *   1년 x 서버 2000대면 수천만 줄을 읽는 집계라 매번 수 초 ~ 수십 초가 걸린다.
 *   같은 조건이면 처음 결과를 몇 분 동안 저장해 두고 다시 쓴다.
 *
 * ── 어디에 저장하나 ──
 *   웹 서버의 임시 폴더(sys_get_temp_dir(), 보통 /tmp) 아래 dmove_cache 폴더에 파일로 저장한다.
 *   파일 하나 = 조건 하나. 내용은 JSON. 파일 이름은 조건을 md5 로 줄인 값이다.
 *   (Zabbix 7.0 의 세션은 쿠키에 담기므로 큰 결과를 넣을 수 없다. DB 에 표를 만들지 않으려고 파일을 쓴다)
 *   폴더는 웹 서버 사용자만 읽고 쓸 수 있게(0700) 만든다.
 *
 * ── 언제 지워지나 ──
 *   저장한 지 $seconds 초가 지나면 읽지 않는다(없는 것으로 본다). 오래된 파일은 저장할 때 같이 지운다.
 *   웹 서버를 다시 시작하거나 임시 폴더가 비워져도 문제없다. 다음 조회 때 다시 계산한다.
 *
 * ── 쓰는 법 ──
 *   $cache = new UsageCache(300);           // 300초 동안 유지. 0 이면 저장하지 않는다(매번 계산).
 *   $value = $cache->get($key);             // 없거나 오래됐으면 null
 *   if ($value === null) { $value = 계산(); $cache->set($key, $value); }
 */

namespace Modules\DMove\Includes;

class UsageCache {

	/** 임시 폴더 안의 저장 폴더 이름. */
	private const DIR_NAME = 'dmove_cache';

	/** 유지 시간(초). 0 이면 사용하지 않는다. */
	private int $seconds;

	public function __construct(int $seconds) {
		$this->seconds = max(0, $seconds);
	}

	/**
	 * 저장해 둔 값. 없거나, 유지 시간이 지났거나, 읽을 수 없으면 null.
	 *
	 * @param string $key  저장 이름. 영문/숫자만 (md5 값을 넘긴다)
	 */
	public function get(string $key): ?array {
		$file = $this->fileOf($key);

		if ($file === null || !is_file($file)) {
			return null;
		}

		// filemtime: 파일을 마지막으로 쓴 시각. 유지 시간이 지났으면 지우고 없는 것으로 본다.
		if (filemtime($file) < time() - $this->seconds) {
			@unlink($file);

			return null;
		}

		// @: 읽는 사이 다른 요청이 지웠을 때의 경고를 숨긴다. 실패하면 false 라서 아래에서 null 이 된다.
		$json = @file_get_contents($file);
		$value = is_string($json) ? json_decode($json, true) : null;

		return is_array($value) ? $value : null;
	}

	/**
	 * 값을 저장한다. 저장하지 못해도(폴더 권한 등) 오류를 내지 않는다. 그때는 다음에 다시 계산할 뿐이다.
	 */
	public function set(string $key, array $value): void {
		$file = $this->fileOf($key);

		if ($file === null) {
			return;
		}

		$this->removeExpired();

		/*
		 * 임시 파일에 다 쓴 뒤 이름을 바꾼다. 다른 요청이 쓰다 만 파일을 읽지 않게 하기 위해서다.
		 * LOCK_EX: 쓰는 동안 다른 요청이 같은 파일에 쓰지 못하게 한다.
		 */
		$tmp = $file.'.'.getmypid().'.tmp';

		if (@file_put_contents($tmp, json_encode($value), LOCK_EX) !== false) {
			@chmod($tmp, 0600);
			@rename($tmp, $file);
		}
		else {
			@unlink($tmp);
		}
	}

	/**
	 * 저장 파일 경로. 유지 시간이 0 이거나 폴더를 만들 수 없으면 null (저장하지 않음).
	 */
	private function fileOf(string $key): ?string {
		// 파일 이름에 쓸 수 없는 글자가 들어오면 저장하지 않는다. (경로를 벗어나는 값 차단)
		if ($this->seconds === 0 || preg_match('/^[a-f0-9]{32}$/', $key) !== 1) {
			return null;
		}

		$dir = $this->dir();

		return $dir !== null ? $dir.'/'.$key.'.json' : null;
	}

	/**
	 * 저장 폴더. 없으면 만든다(0700). 쓸 수 없거나 안전하지 않으면 null (저장하지 않음).
	 *
	 * 임시 폴더는 서버의 모든 사용자가 쓸 수 있다. 다른 사용자가 같은 이름의 폴더를 먼저 만들어 두면
	 * 저장 결과를 바꿔치기할 수 있으므로, 아래 두 가지가 맞을 때만 쓴다.
	 *   - 진짜 폴더일 것 (is_link: 다른 곳을 가리키는 바로가기(심볼릭 링크)가 아님)
	 *   - 웹 서버 사용자가 만든 폴더일 것 (fileowner: 폴더 주인, posix_geteuid: 지금 PHP 를 실행하는 사용자)
	 *     posix 함수가 없는 서버(윈도우 등)에서는 주인 확인을 건너뛴다.
	 */
	private function dir(): ?string {
		$dir = rtrim(sys_get_temp_dir(), '/\\').'/'.self::DIR_NAME;

		if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
			return null;
		}

		if (is_link($dir) || !is_writable($dir)) {
			return null;
		}

		if (function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid()) {
			return null;
		}

		return $dir;
	}

	/**
	 * 유지 시간이 지난 파일을 지운다. 저장할 때마다 부르지만 파일 수가 적어(조건 하나에 하나) 부담이 없다.
	 * glob(패턴): 패턴에 맞는 파일 목록.
	 */
	private function removeExpired(): void {
		$dir = $this->dir();

		if ($dir === null) {
			return;
		}

		$limit = time() - $this->seconds;

		foreach (glob($dir.'/*.json') ?: [] as $file) {
			if (@filemtime($file) < $limit) {
				@unlink($file);
			}
		}
	}
}
