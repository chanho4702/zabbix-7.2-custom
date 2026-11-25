# 코어 파일 수정 목록 (DMOVE-PATCH)

Zabbix 코어 파일을 고친 곳 전체다. 모듈로 할 수 없는 것만 여기에 있다.
**Zabbix 를 올리면(새 버전 설치) 이 수정이 사라지므로, 이 문서를 보고 다시 넣는다.**

코드에는 `// DMOVE-PATCH BEGIN: 이유` ~ `// DMOVE-PATCH END` 로 표시해 두었다.
저장소의 코어 파일에는 학습용 `[DM 해설]` 주석도 섞여 있으므로, **고객 서버에는 파일을 통째로 복사하지 말고 아래 블록만 고객 서버의 원본 파일에 옮겨 넣는다.**

```bash
# 저장소에서 원본 대비 바뀐 곳 보기
git diff vendor/zabbix-7.0.26 -- ui/index.php ui/include/views/general.login.php | grep -v '\[DM 해설\]'
```

## 1. 로그인 추가 인증 (OTP, MPASS) — 2026-11-25

이유: 5버전 로그인 커스텀(사내 인증 서버로 OTP / 휴대폰 간편인증 확인)을 옮긴다. 로그인 전에는 모듈이 로드되지 않아 모듈만으로는 할 수 없다. (`03_5버전_분석/로그인_커스텀.md` 2.2)

동작 코드는 모두 모듈 폴더에 있다. 코어에는 부르는 줄만 넣는다.

| 모듈 파일 | 역할 |
|---|---|
| `ui/modules/dmove/includes/LoginAuth.php` | OTP 확인, MPASS 요청/결과/취소, 인증 서버 호출, 로그 |
| `ui/modules/dmove/includes/LoginAuthView.php` | 로그인 화면의 OTP 칸, MPASS 체크, 대기 창 |
| `ui/modules/dmove/assets/js/login.js` | 로그인 화면 스크립트 (MPASS 대기, 5초마다 결과 확인) |
| `ui/modules/dmove/conf/login.conf.example.php` | 설정 견본. 고객 서버에서 `login.conf.php` 로 복사해 값을 넣는다 (git 제외) |

**`conf/login.conf.php` 가 없으면 아래 패치가 들어가 있어도 Zabbix 기본 로그인 그대로다.** 패치를 먼저 넣고 설정은 나중에 해도 된다.

### 1.1 `ui/index.php` (3곳)

(가) `$fields` 맨 끝. `'form' => ...` 줄 끝에 쉼표를 붙이고 그 아래에:

```php
	// DMOVE-PATCH BEGIN: 로그인 추가 인증(OTP, MPASS)의 입력값 (docs/CORE_PATCHES.md)
	'otp' =>		[T_ZBX_STR, O_OPT,	null,			null,		null],
	'dmove_mpass' =>	[T_ZBX_STR, O_OPT,	null,	IN('"request","result","cancel"'),	null]
	// DMOVE-PATCH END
```

(나) `if ($request !== '' && !CHtmlUrlValidator::validateSameSite($request)) { ... }` 블록 바로 아래:

```php
// DMOVE-PATCH BEGIN: MPASS(휴대폰 간편인증) 요청/결과/취소. 로그인 화면 스크립트가 부르고, JSON 으로 응답한 뒤 끝난다.
require_once __DIR__.'/modules/dmove/includes/LoginAuth.php';

if (hasRequest('dmove_mpass')) {
	\Modules\DMove\Includes\LoginAuth::handleMpass(getRequest('dmove_mpass'), $request, (int) $autologin);
}
// DMOVE-PATCH END
```

(다) `// login via form` 아래의 `if (hasRequest('enter') && CWebUser::login(...)) {` **한 줄을** 아래로 바꾼다:

```php
// DMOVE-PATCH BEGIN: 비밀번호가 맞으면 OTP 를 확인한다. 틀리면 방금 만든 세션을 끊는다. (기능이 꺼져 있으면 늘 통과)
if (hasRequest('enter') && CWebUser::login(getRequest('name', ZBX_GUEST_USER), getRequest('password', ''))
		&& \Modules\DMove\Includes\LoginAuth::verifyOtpOrLogout(getRequest('otp', ''))) {
// DMOVE-PATCH END
```

### 1.2 `ui/include/views/general.login.php` (5곳)

(가) `$error = null;` 바로 아래:

```php
// DMOVE-PATCH BEGIN: 로그인 추가 인증(OTP, MPASS) 화면 부품. 기능이 꺼져 있으면 $dmove_login 은 null 이고 화면은 기본 그대로다.
require_once __DIR__.'/../../modules/dmove/includes/LoginAuthView.php';
$dmove_login = \Modules\DMove\Includes\LoginAuthView::make();
// DMOVE-PATCH END
```

(나) `(new CDiv(makeLogo(LOGO_TYPE_NORMAL)))->addClass(ZBX_STYLE_SIGNIN_LOGO),` 바로 아래:

```php
			// DMOVE-PATCH BEGIN: 로고 아래 그림 (설정했을 때만)
			$dmove_login !== null ? $dmove_login['logo'] : null,
			// DMOVE-PATCH END
```

(다) 비밀번호 묶음 다음의 "Remember me" 체크박스 `->addItem( (new CCheckBox('autologin')) ... )` 를 아래로 바꾼다:

```php
						// DMOVE-PATCH BEGIN: OTP 입력칸과 MPASS 체크. 5버전 화면처럼 "30일 동안 기억" 은 없다.
						->addItem($dmove_login !== null ? $dmove_login['otp'] : null)
						->addItem($dmove_login !== null ? $dmove_login['mpass'] : null)
						->addItem($dmove_login !== null ? null :
							(new CCheckBox('autologin'))
								->setLabel(_('Remember me for 30 days'))
								->setChecked($data['autologin'])
						)
						// DMOVE-PATCH END
```

(라) `]))->addClass(ZBX_STYLE_SIGNIN_CONTAINER),` 바로 아래, 도움말 링크 묶음 `(new CDiv([ (new CLink(_('Help') ...` 바로 앞:

```php
		// DMOVE-PATCH BEGIN: 5버전처럼 아래 링크를 [License] 하나로 (설정했을 때만)
		$dmove_login !== null && $dmove_login['links'] !== null ? $dmove_login['links'] :
		// DMOVE-PATCH END
```

(마) 맨 아래 `->show();` 와 `?>` 사이:

```php
// DMOVE-PATCH BEGIN: MPASS 대기 창과 로그인 화면 스크립트
if ($dmove_login !== null) {
	echo $dmove_login['popup'];
	echo $dmove_login['script'];
}
// DMOVE-PATCH END
```

### 1.3 넣은 뒤 확인 (고객 서버)

먼저 `login.conf.php` **없이** 넣고 기본 로그인이 그대로 되는지 본다. 그다음 설정을 넣고:

1. 비밀번호 + 맞는 OTP → 로그인
2. 비밀번호 + 틀린 OTP → "OTP 인증을 실패하였습니다.", 주소를 직접 쳐도 화면에 못 들어간다
3. 틀린 비밀번호 → Zabbix 기본 오류 (인증 서버로 OTP 를 보내지 않는다)
4. MPASS 체크 → 대기 창 → 휴대폰 승인 → 로그인
5. MPASS 대기 중 [취소] / 30초 지남 → 창이 닫히고 인증 서버에 취소(type=C)가 간다
6. 인증 서버가 안 될 때 → 로그인 막힘 (OTP 실패 문구)
7. `/var/log/zabbix/log/OTP_날짜.log` 에 아이디, IP, 결과만 있고 OTP 번호는 없다

**잠김 대비**: 설정을 넣은 뒤 아무도 못 들어오면 `login.conf.php` 의 `'enabled'` 를 `false` 로 바꾸거나 파일 이름을 바꾸면 즉시 기본 로그인으로 돌아간다.
