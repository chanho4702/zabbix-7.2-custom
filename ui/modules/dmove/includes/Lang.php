<?php declare(strict_types = 0);
/*
 * 모듈 화면 문구의 다국어 처리 (영어 / 한국어).
 *
 * 왜 따로 만들었나
 *   코어의 번역 함수 _('Hosts') 는 Zabbix 가 배포한 번역 파일(ui/locale/.../frontend.mo)에 있는 문구만 번역한다.
 *   모듈에서 새로 만든 문구('서버 시간 확인' 등)는 그 파일에 없어서 _() 로는 번역되지 않는다.
 *   코어 번역 파일을 고치면 코어 수정이 되므로, 모듈 안에 작은 번역 표를 둔다.
 *
 * 사용법
 *   코드에는 "영어 문구"를 적고 Lang::t() 로 감싼다.
 *       Lang::t('Server time check')
 *   로그인한 사용자의 언어(유저 설정 > 언어. '시스템 기본값'이면 관리 > 일반 > GUI 의 기본 언어)가
 *     - 한국어면  lang/ko.php 에서 같은 영어 문구를 찾아 한국어로 바꿔 준다.
 *     - 영어면    적은 영어 문구 그대로 나온다.
 *     - 번역 표에 없는 문구는 영어 그대로 나온다. (오류 없음)
 *
 *   문구 중간에 값을 넣을 때는 %1$s, %2$s 자리표시를 쓴다. (코어의 _s() 와 같은 방식)
 *       Lang::t('Displaying %1$s of %2$s found', 10, 25)
 *
 * 문구를 추가할 때
 *   1) 코드에 Lang::t('New English text') 를 쓴다.
 *   2) lang/ko.php 에 'New English text' => '새 한국어 문구', 한 줄을 추가한다.
 *
 * 다른 언어를 추가할 때
 *   lang/ja.php 처럼 파일만 만들면 된다. 파일 이름은 언어 코드 앞 두 글자다. (ja_JP -> ja)
 *
 * 코어에 이미 있는 문구(Filter, Hosts, Host groups, Name ...)는 지금처럼 코어의 _() 를 그대로 쓴다.
 *
 * 뷰 파일(네임스페이스 없음)에서는 맨 위에 use Modules\DMove\Includes\Lang; 을 적고 쓴다.
 */

namespace Modules\DMove\Includes;

use CWebUser;

class Lang {

	/** @var array|null  현재 언어의 번역 표. 한 요청 안에서 한 번만 읽는다. */
	private static ?array $messages = null;

	/**
	 * 문구를 현재 사용자 언어로 바꾼다.
	 *
	 * @param string $text     영어 원문. lang/<언어>.php 의 키와 글자 하나까지 같아야 한다.
	 * @param mixed  ...$args  %1$s, %2$s ... 자리에 넣을 값. ("..." 는 인자를 몇 개든 받는다는 뜻)
	 */
	public static function t(string $text, ...$args): string {
		if (self::$messages === null) {
			self::$messages = self::load();
		}

		$translated = self::$messages[$text] ?? $text;

		// vsprintf: 자리표시(%1$s ...)를 배열의 값으로 채운다.
		return $args ? vsprintf($translated, $args) : $translated;
	}

	/**
	 * 현재 사용자 언어의 번역 표를 읽는다. 파일이 없으면(영어 등) 빈 배열 -> 원문 그대로 출력된다.
	 */
	private static function load(): array {
		// CWebUser::getLang(): 사용자 언어 코드의 앞부분. ko_KR -> 'ko', en_US -> 'en'
		$lang = (string) CWebUser::getLang();

		// 언어 코드는 영문 소문자만 허용한다. (파일 경로에 들어가는 값이므로 검사한다)
		if (preg_match('/^[a-z]{2,3}$/', $lang) !== 1) {
			return [];
		}

		$file = dirname(__DIR__).'/lang/'.$lang.'.php';

		if (!is_readable($file)) {
			return [];
		}

		$messages = require $file;

		return is_array($messages) ? $messages : [];
	}
}
