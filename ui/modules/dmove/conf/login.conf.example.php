<?php
/*
 * 로그인 추가 인증(OTP, MPASS) 설정 견본.
 *
 * 쓰는 법 (고객 서버에서만):
 *   1) 이 파일을 같은 폴더에 login.conf.php 로 복사한다.
 *   2) 아래 값을 5버전 서버의 OTP_ / MPASS_ 상수 값으로 채우고 'enabled' 를 true 로 바꾼다.
 *      5버전 상수 이름은 각 줄 오른쪽 주석에 적었다. (5버전 소스에서 grep -rn "OTP_MYPASS_URL" 로 찾는다)
 *   3) 웹서버 계정만 읽을 수 있게 권한을 준다.  예) chown root:apache login.conf.php; chmod 640 login.conf.php
 *
 * login.conf.php 는 git 에 넣지 않는다(.gitignore). 이 저장소는 공개 저장소다.
 * login.conf.php 가 없으면 이 기능은 꺼지고 Zabbix 기본 로그인 그대로다.
 */

return [
	'enabled' => false,

	'company_code' => '',		// OTP_COMPANY_CODE
	'asstsq' => '',				// OTP_ASSTSQ
	'host_ip' => '',			// OTP_HOST_IP  (이 Zabbix 웹서버의 IP)
	'otp_url' => '',			// OTP_MYPASS_URL       OTP 확인 주소
	'mpass_auth_url' => '',		// MPASS_AUTH_URL       간편인증 요청/취소 주소
	'mpass_conf_url' => '',		// MPASS_AUTH_CONF_URL  간편인증 결과 주소

	// 인증 서버의 HTTPS 인증서를 확인한다. 사내 인증서라 확인에 실패하면 그 인증서(PEM) 파일 경로를 넣는다.
	'ca_file' => '',

	'connect_timeout' => 5,		// 인증 서버 연결 제한 시간(초)
	'timeout' => 10,			// 인증 서버 응답 제한 시간(초)
	'mpass_wait' => 30,			// 휴대폰 승인 대기 시간(초). 5버전 30초

	'log_path' => '/var/log/zabbix/log',	// 5버전과 같은 곳. OTP_YYYY-MM-DD.log (OTP 번호는 남기지 않는다)

	// 로그인 화면 모양 (5버전과 같게 하려면)
	'logo' => '',				// 예) 'modules/dmove/assets/img/esm-logo.png'  (5버전 assets/img/esm-logo.png 를 그 자리에 복사)
	'license_url' => ''			// 예) '/zabbix/license.htm'  비우면 Zabbix 기본 [Help] [Support] 링크
];
