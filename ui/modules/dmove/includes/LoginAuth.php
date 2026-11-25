<?php declare(strict_types = 0);
/*
 * 로그인 추가 인증: OTP 와 MPASS(휴대폰 간편인증). 5버전 로그인 커스텀을 옮긴 것이다.
 *
 * ── 누가 부르나 ──
 *   코어 파일 2개에 넣은 짧은 패치(DMOVE-PATCH)가 이 파일을 require 해서 부른다. (docs/CORE_PATCHES.md)
 *     ui/index.php                        : OTP 확인, MPASS 요청/결과/취소 처리
 *     ui/include/views/general.login.php  : 로그인 화면의 OTP 칸, MPASS 체크, 대기 창
 *   로그인 전에는 모듈이 로드되지 않아 자동 로딩이 안 된다. 그래서 require_once 로 직접 읽는다.
 *
 * ── 설정 ──
 *   conf/login.conf.php (git 에 넣지 않는다. 견본: conf/login.conf.example.php)
 *   파일이 없거나 'enabled' => false 면 이 기능은 꺼지고 Zabbix 기본 로그인 그대로다.
 *   (설정 전에 패치를 먼저 넣어도 아무도 못 들어가는 일이 없게 하려는 것이다)
 *
 * ── 5버전과 같은 것 (인증 서버와 주고받는 값) ── 5버전 CWebUser.php 의 OTPCheck / fidoCertReq / fidoCertResult
 *   OTP 확인     : otp_url 에 POST  CompanyCode, ID, OTP, IP(접속자), asstsq, sysIP    응답 "0" 이면 성공
 *   MPASS 요청   : mpass_auth_url 에 POST  uid, aip, type(A=요청, C=취소), cid, asstsq   응답 "1:<cid>" 면 성공
 *   MPASS 결과   : mpass_conf_url 에 POST  uid, fid(=cid)                                응답 "S" 면 승인
 *   MPASS 대기   : 30초. 5초마다 결과를 묻는다. 시간이 지나거나 [취소] 면 type=C 로 취소를 보낸다.
 *   실패 문구    : "OTP 인증을 실패하였습니다." 등 5버전 문구
 *
 * ── 5버전에서 고친 것 (2026-11-25 사용자 결정) ──
 *   1) 인증서 확인: 5버전은 인증 서버의 HTTPS 인증서를 확인하지 않았다(CURLOPT_SSL_VERIFYPEER false).
 *      여기서는 확인한다. 사내 인증서면 설정의 'ca_file' 에 인증서 파일 경로를 넣는다.
 *   2) 로그: 5버전은 OTP 번호까지 로그 파일에 남겼다. 여기서는 아이디, 접속 IP, 응답 결과만 남긴다.
 *   3) 비밀번호 다시 보내기: 5버전은 MPASS 대기 중 브라우저가 비밀번호를 5초마다, 그리고 승인 뒤 한 번 더 보냈다.
 *      여기서는 처음 한 번만 받는다. 비밀번호가 맞으면 세션을 "확인 필요" 상태로 만들어 두고(코어 2단계 인증과 같은 방식.
 *      이 상태의 세션으로는 화면도 API 도 쓸 수 없다), 승인되면 그 세션을 "활성"으로 바꾼다.
 *   그 밖에:
 *   - 5버전은 비밀번호보다 OTP 를 먼저 확인했다. 여기서는 비밀번호가 맞을 때만 OTP 를 인증 서버로 보낸다.
 *   - 5버전의 OTP 응답 비교($result == 0)는 PHP 7 에서 빈 응답(서버 장애)도 성공으로 보았다. 여기서는 정확히 "0" 일 때만 성공.
 *   - 인증 서버가 응답하지 않으면 실패로 본다(로그인 막음). 연결/응답 제한 시간을 둔다.
 */

namespace Modules\DMove\Includes;

use API;
use CJsonRpc;
use CMenuHelper;
use CSessionHelper;
use CWebUser;
use DB;

class LoginAuth {

	/** 세션에 MPASS 대기 정보를 넣는 이름. */
	private const PENDING_KEY = 'dmove_mpass';

	/** 설정 파일을 한 번만 읽도록 담아 둔다. */
	private static ?array $config = null;

	/**
	 * 설정을 읽는다. conf/login.conf.php 가 없으면 꺼진 상태.
	 *
	 * @return array  ['enabled' => bool, 'otp_url' => .., ...]
	 */
	public static function config(): array {
		if (self::$config !== null) {
			return self::$config;
		}

		$file = dirname(__DIR__).'/conf/login.conf.php';
		$config = is_readable($file) ? require $file : [];
		$config = is_array($config) ? $config : [];

		self::$config = $config + [
			'enabled' => false,
			'company_code' => '',		// 5버전 OTP_COMPANY_CODE
			'asstsq' => '',				// 5버전 OTP_ASSTSQ
			'host_ip' => '',			// 5버전 OTP_HOST_IP (이 Zabbix 서버의 IP)
			'otp_url' => '',			// 5버전 OTP_MYPASS_URL
			'mpass_auth_url' => '',		// 5버전 MPASS_AUTH_URL
			'mpass_conf_url' => '',		// 5버전 MPASS_AUTH_CONF_URL
			'ca_file' => '',			// 인증 서버 인증서(사내 인증서일 때). 비우면 서버 기본 인증서 목록
			'connect_timeout' => 5,		// 초
			'timeout' => 10,			// 초
			'mpass_wait' => 30,			// 휴대폰 승인을 기다리는 시간(초). 5버전 30초
			'log_path' => '/var/log/zabbix/log',	// 5버전과 같은 곳. 파일명 OTP_YYYY-MM-DD.log
			'logo' => '',				// 로그인 화면 로고 아래 그림. 예) 'modules/dmove/assets/img/esm-logo.png'
			'license_url' => ''			// 로그인 화면 아래 [License] 링크. 예) '/zabbix/license.htm'
		];

		self::$config['enabled'] = (bool) self::$config['enabled'];

		return self::$config;
	}

	/** 이 기능이 켜져 있는지. */
	public static function isEnabled(): bool {
		return self::config()['enabled'];
	}

	// ── OTP ──────────────────────────────────────────────────────────────────

	/**
	 * 비밀번호 로그인이 성공한 바로 뒤에 부른다. (index.php 의 로그인 조건 안)
	 * OTP 가 맞으면 true. 틀리면 방금 만든 세션을 끊고 로그인 화면에 5버전 문구를 띄운 뒤 false.
	 * 기능이 꺼져 있으면 늘 true.
	 */
	public static function verifyOtpOrLogout(string $otp): bool {
		if (!self::isEnabled()) {
			return true;
		}

		$config = self::config();
		$username = (string) CWebUser::$data['username'];
		$otp = trim($otp);

		$result = $otp === '' ? null : self::post($config['otp_url'], [
			'CompanyCode' => $config['company_code'],
			'ID' => $username,
			'OTP' => $otp,
			'IP' => CWebUser::getIp(),
			'asstsq' => $config['asstsq'],
			'sysIP' => $config['host_ip']
		]);

		// OTP 번호는 남기지 않는다.
		self::log('OTP|ID='.$username.'|IP='.CWebUser::getIp().'|RESULT='.($result ?? 'NO_RESPONSE'));

		if ($result === '0') {
			return true;
		}

		self::endSession((string) CWebUser::$data['sessionid']);
		CWebUser::setDefault();
		error('OTP 인증을 실패하였습니다.');

		return false;
	}

	// ── MPASS (휴대폰 간편인증) ──────────────────────────────────────────────

	/**
	 * 로그인 화면 스크립트의 MPASS 요청을 처리하고 JSON 으로 응답한 뒤 끝낸다. (index.php 에서 부른다)
	 *
	 * @param string $step       request(푸시 보내기) / result(승인됐는지 묻기) / cancel(취소)
	 * @param string $request    로그인 뒤 갈 주소 (index.php 가 이미 검사한 값)
	 * @param int    $autologin  "30일 동안 기억" 값
	 */
	public static function handleMpass(string $step, string $request, int $autologin): void {
		if (!self::isEnabled()) {
			self::respond(['error' => 'MPASS 인증을 사용하지 않습니다.']);
		}

		switch ($step) {
			case 'request':
				self::respond(self::mpassRequest($autologin, $request));

			case 'result':
				self::respond(self::mpassResult());

			case 'cancel':
				self::respond(self::mpassCancel());
		}

		self::respond(['error' => 'MPASS 인증을 실패하였습니다.']);
	}

	/**
	 * 1) 비밀번호를 확인하고 휴대폰으로 푸시를 보낸다.
	 *    비밀번호가 맞으면 세션을 만들되 "확인 필요" 상태로 바꿔 둔다. 승인 전에는 이 세션으로 아무것도 할 수 없다.
	 */
	private static function mpassRequest(int $autologin, string $request): array {
		self::clearPending();

		$name = (string) getRequest('name', '');
		$password = (string) getRequest('password', '');

		if ($name === '' || !CWebUser::login($name, $password)) {
			return ['error' => self::lastError('MPASS 인증요청을 실패하였습니다.')];
		}

		$sessionid = (string) CWebUser::$data['sessionid'];
		$username = (string) CWebUser::$data['username'];

		// 코어 2단계 인증(MFA)이 걸린 사용자는 함께 쓰지 않는다. (둘 중 하나만)
		if (CWebUser::$data['mfaid'] != 0) {
			self::endSession($sessionid);

			return ['error' => 'Zabbix 2단계 인증이 설정된 사용자는 MPASS 를 쓸 수 없습니다.'];
		}

		DB::update('sessions', [
			'values' => ['status' => ZBX_SESSION_CONFIRMATION_REQUIRED],
			'where' => ['sessionid' => $sessionid]
		]);

		// 창을 닫아 버려 남은 이 사용자의 옛 대기 세션(5분 지난 것)을 지운다. (코어 2단계 인증과 같은 정리)
		DBexecute(
			'DELETE FROM sessions'.
			' WHERE '.dbConditionId('userid', [CWebUser::$data['userid']]).
				' AND '.dbConditionInt('status', [ZBX_SESSION_CONFIRMATION_REQUIRED]).
				' AND lastaccess<'.zbx_dbstr(strtotime('-5 minutes'))
		);

		$config = self::config();
		$result = self::post($config['mpass_auth_url'], [
			'uid' => $username,
			'aip' => $config['host_ip'],
			'type' => 'A',
			'cid' => '',
			'asstsq' => $config['asstsq']
		]);

		self::log('MPASS_REQ|ID='.$username.'|IP='.CWebUser::getIp().'|RESULT='.($result ?? 'NO_RESPONSE'));

		// 성공 응답: "1:<cid>"
		$parts = explode(':', (string) $result, 2);

		if ($parts[0] !== '1' || !isset($parts[1]) || trim($parts[1]) === '') {
			self::endSession($sessionid);

			return ['error' => 'MPASS 인증요청을 실패하였습니다.'];
		}

		CSessionHelper::set(self::PENDING_KEY, [
			'sessionid' => $sessionid,
			'username' => $username,
			'cid' => trim($parts[1]),
			// 브라우저가 늦게 물어도 되도록 대기 시간에 10초를 더 준다.
			'expires' => time() + (int) $config['mpass_wait'] + 10,
			'autologin' => $autologin,
			'request' => $request
		]);

		return ['status' => 'sent', 'wait' => (int) $config['mpass_wait']];
	}

	/**
	 * 2) 승인됐는지 묻는다. 승인이면 세션을 "활성"으로 바꿔 로그인을 마치고 갈 주소를 알려 준다.
	 */
	private static function mpassResult(): array {
		$pending = CSessionHelper::get(self::PENDING_KEY);

		if (!is_array($pending)) {
			return ['error' => 'MPASS 인증을 실패하였습니다.'];
		}

		if (time() > (int) $pending['expires']) {
			self::cancelAtServer($pending);
			self::clearPending();

			return ['error' => 'MPASS 인증 시간이 지났습니다.'];
		}

		$config = self::config();
		$result = self::post($config['mpass_conf_url'], [
			'uid' => $pending['username'],
			'fid' => $pending['cid']
		]);

		self::log('MPASS_RESULT|ID='.$pending['username'].'|IP='.CWebUser::getIp().'|RESULT='.($result ?? 'NO_RESPONSE'));

		if ($result !== 'S') {
			return ['status' => 'wait'];
		}

		// 승인: "확인 필요" 세션을 활성으로 바꾸고 이 브라우저의 로그인 세션으로 삼는다.
		DB::update('sessions', [
			'values' => ['status' => ZBX_SESSION_ACTIVE],
			'where' => ['sessionid' => $pending['sessionid'], 'status' => ZBX_SESSION_CONFIRMATION_REQUIRED]
		]);

		CSessionHelper::unset([self::PENDING_KEY]);

		if (!CWebUser::checkAuthentication($pending['sessionid'])) {
			self::endSession($pending['sessionid']);

			return ['error' => 'MPASS 인증을 실패하였습니다.'];
		}

		CSessionHelper::set('sessionid', $pending['sessionid']);

		API::getWrapper()->auth = [
			'type' => CJsonRpc::AUTH_TYPE_FRONTEND,
			'auth' => $pending['sessionid']
		];

		// index.php 의 일반 로그인과 같다: "30일 동안 기억" 값 반영, 갈 주소 결정.
		if (CWebUser::$data['autologin'] != $pending['autologin']) {
			API::User()->update([
				'userid' => CWebUser::$data['userid'],
				'autologin' => $pending['autologin']
			]);
		}

		$redirect = array_filter([$pending['request'], CWebUser::$data['url'], CMenuHelper::getFirstUrl()]);

		return ['status' => 'ok', 'redirect' => (string) reset($redirect)];
	}

	/**
	 * 3) [취소] 또는 시간 초과: 인증 서버에 취소(type=C)를 보내고, 만들어 둔 세션을 지운다.
	 */
	private static function mpassCancel(): array {
		$pending = CSessionHelper::get(self::PENDING_KEY);

		if (is_array($pending)) {
			self::cancelAtServer($pending);
		}

		self::clearPending();

		return ['status' => 'cancelled'];
	}

	/** 인증 서버에 취소를 보낸다. (5버전: 30초가 지나거나 [취소] 면 type=C 로 다시 요청) */
	private static function cancelAtServer(array $pending): void {
		$config = self::config();
		$result = self::post($config['mpass_auth_url'], [
			'uid' => $pending['username'],
			'aip' => $config['host_ip'],
			'type' => 'C',
			'cid' => $pending['cid'],
			'asstsq' => $config['asstsq']
		]);

		self::log('MPASS_CANCEL|ID='.$pending['username'].'|IP='.CWebUser::getIp().'|RESULT='.($result ?? 'NO_RESPONSE'));
	}

	/** 세션에 남은 대기 정보와 그 "확인 필요" 세션을 지운다. */
	private static function clearPending(): void {
		$pending = CSessionHelper::get(self::PENDING_KEY);

		if (is_array($pending) && isset($pending['sessionid'])) {
			DB::delete('sessions', [
				'sessionid' => $pending['sessionid'],
				'status' => ZBX_SESSION_CONFIRMATION_REQUIRED
			]);
		}

		CSessionHelper::unset([self::PENDING_KEY]);
	}

	// ── 공통 ─────────────────────────────────────────────────────────────────

	/**
	 * 방금 만든 로그인 세션을 DB 에서 끝낸다. (OTP 실패, MPASS 실패)
	 * 상태를 "종료(PASSIVE)" 로 바꾸면 그 세션으로는 다시 들어올 수 없다.
	 */
	private static function endSession(string $sessionid): void {
		if ($sessionid === '') {
			return;
		}

		DB::update('sessions', [
			'values' => ['status' => ZBX_SESSION_PASSIVE],
			'where' => ['sessionid' => $sessionid]
		]);
	}

	/**
	 * 인증 서버에 POST 하고 응답 글자(앞뒤 공백 제거)를 돌려준다. 실패하면 null.
	 *   - 주소가 비었거나 연결/응답이 안 되면 null → 부르는 쪽에서 실패(로그인 막음)로 처리한다.
	 *   - HTTPS 인증서를 확인한다. (5버전은 확인하지 않았다)
	 */
	private static function post(string $url, array $fields): ?string {
		if ($url === '' || !function_exists('curl_init')) {
			return null;
		}

		$config = self::config();
		$ch = curl_init();

		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
			CURLOPT_POSTFIELDS => http_build_query($fields),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => (int) $config['connect_timeout'],
			CURLOPT_TIMEOUT => (int) $config['timeout']
		]);

		if ($config['ca_file'] !== '') {
			curl_setopt($ch, CURLOPT_CAINFO, $config['ca_file']);
		}

		$result = curl_exec($ch);
		$error = curl_errno($ch) !== 0;

		curl_close($ch);

		return ($error || !is_string($result)) ? null : trim($result);
	}

	/**
	 * 5버전과 같은 로그 파일에 한 줄 남긴다. 예) 2026-11-25 10:00:00::OTP|ID=admin|IP=10.0.0.1|RESULT=0
	 * 쓸 수 없으면 조용히 넘어간다. (로그 때문에 로그인이 막히면 안 된다)
	 */
	private static function log(string $message): void {
		$path = rtrim((string) self::config()['log_path'], '/');

		if ($path === '') {
			return;
		}

		if (!is_dir($path)) {
			@mkdir($path, 0750, true);
		}

		// 응답에 줄바꿈이 섞여도 한 줄로 남긴다.
		$message = str_replace(["\r", "\n"], ' ', $message);

		@file_put_contents($path.'/OTP_'.date('Y-m-d').'.log', date('Y-m-d H:i:s').'::'.$message.PHP_EOL, FILE_APPEND);
	}

	/** 로그인 실패 때 코어가 남긴 오류 문구(예: 아이디 또는 비밀번호가 틀림)를 꺼낸다. 없으면 $default. */
	private static function lastError(string $default): string {
		$messages = get_and_clear_messages();
		$last = $messages ? end($messages) : null;

		return ($last !== null && isset($last['message']))
			? trim(preg_replace('/\[.*\]/', '', $last['message']))
			: $default;
	}

	/** JSON 으로 응답하고 끝낸다. 세션 값을 먼저 저장해야 쿠키가 응답 머리에 실린다. */
	private static function respond(array $data): void {
		session_write_close();

		header('Content-Type: application/json; charset=UTF-8');
		echo json_encode($data);

		exit;
	}
}
