# DMove Zabbix 7.2 커스터마이징 개발 가이드

Zabbix 5 에서 `autoever/` + `patch.sh` 로 넣었던 커스텀 화면을 Zabbix 7.2 로 옮기기 위한 저장소다.
원칙은 하나다. **본 소스(코어)의 동작은 고치지 않고, 모든 커스텀 코드는 `ui/modules/dmove/` 안에만 둔다.**

코어 파일 27개에는 학습용 한국어 주석(`// [DM 해설]`)만 추가되어 있다. 코드 변경은 없다.
**고객 서버에는 `ui/modules/dmove/` 만 반영한다. 주석이 달린 코어 파일은 서버에 올리지 않는다.**

관련 문서:

- `docs/HANDBOOK.md` : **해설집.** 처음 읽을 문서. 페이지 추가 따라 하기, 5버전 코드 변환표, 오류 해결
- `docs/LOGIN_CUSTOMIZATION.md` : 로그인 화면 수정과 추가 인증(OTP 등) 이관. 5버전/7.2 에서 볼 파일, 구현 방법 비교
- `docs/ZABBIX_CORE_WALKTHROUGH.md` : 코어 소스가 요청/모듈/메뉴를 처리하는 과정을 파일:줄번호로 해설
- `docs/LEGACY_V5_PHP8_REVIEW.md` : 5버전 커스텀 소스의 PHP 8 / 7.2 호환성 검토

표기 안내
- 5버전 커스텀 클래스 이름의 고객사 접두어는 `C*` 로 가려 적었다. 예) `C*Itms` = 5버전 `controllers/` 의 `...Itms.php` 트레잇.
- 문서의 `autoever/` 는 5버전 서버에 있는 커스텀 폴더의 실제 이름이다. (7버전 모듈 폴더는 `dmove/`)
- 관련 문서: `docs/CUSTOMER_ENV_CHECKLIST.md` (고객환경에서 확인할 것), `ui/modules/dmove/template/README.md` (새 페이지 추가), `dev/README.md` (로컬에서 띄워 보기)

## 1. 저장소 구조

```
C:\zabbix
├── ui/                          Zabbix 7.2.15 프론트엔드 원본 (= 서버의 /usr/share/zabbix)
│   └── modules/
│       └── dmove/            ★ 커스텀 코드는 전부 여기
│           ├── manifest.json        모듈 정의 + 액션(URL) 등록   (5버전 routing.php 역할)
│           ├── Module.php           사이드바 메뉴 등록           (5버전 menu.inc.php 패치 역할)
│           ├── actions/<카테고리>/<화면>/  컨트롤러 (요청 검증, 권한, 응답). 카테고리 = 사이드바 1단계 메뉴
│           ├── views/<카테고리>/    화면 뷰. 사이드바 1단계 메뉴마다 폴더 하나 (inventory, reports, users)
│           │   └── js/<카테고리>/   화면 전용 스크립트 (필요한 화면만)
│           ├── includes/            여러 화면이 같이 쓰는 클래스, 데이터/업무 로직
│           │                        (5버전 C* 트레잇 역할. Lang, ItsmRepository ...)
│           │   ├── export/          내보내기 플러그인 (CSV / Excel). 사용법: ExportTrait.php 머리말
│           │   └── filter/          호스트 그룹/호스트 선택 필터 플러그인. 사용법: HostFilter.php 머리말
│           ├── lang/ko.php          한국어 번역 표 (영어/한국어 전환)
│           ├── conf/                설정 (ITSM 테이블, 아이템 키, 판정 기준). 고객환경에서 값을 확인해 맞춘다
│           ├── assets/css, img      정적 파일
│           └── template/            ★ 새 페이지용 복사 템플릿 + 사용법(README.md)
├── dev/                         로컬에서 띄워 보는 Docker 구성 (dev/README.md)
└── docs/                        이 문서, 5버전 소스 검토 결과, 고객환경 확인 목록
```

### 5버전 → 7버전 대응표

| 5버전 | 7버전 |
|---|---|
| `autoever/routing.php` | `modules/dmove/manifest.json` 의 `actions` |
| `patch.sh` 로 `menu.inc.php`, `CMenu.php` 덮어쓰기 | `Module.php` 의 `init()` |
| `patch.sh` 로 `blue-theme.css` 덮어쓰기 | `assets/css/dmove.css` |
| `autoever/controllers/CControllerXxx.php` | `actions/reports/xxx/XxxList.php` |
| `C*` 트레잇, `C*Middle`, `function.php` | `includes/` 의 클래스 |
| `autoever/views/...` | `views/<카테고리>/*.php` |
| `swinventories.php` → `CLegacyAction` 라우트 | 필요 없음. `zabbix.php?action=...` 만 쓴다 |
| `swinventory.sql` (DB 뷰) | 쓰지 않음. `API::Host()->get()` 사용 |

## 2. 깃 운영 규칙

- 첫 커밋(태그 `vendor/zabbix-7.2.15`)은 공식 소스 그대로다. 절대 수정하거나 덮어쓰지 않는다.
- 코어 파일에 해설을 더 달 때는 반드시 `// [DM 해설] ...` 형식의 한 줄 주석을 **새 줄로 추가만** 한다.
  기존 줄을 고치거나 지우지 않는다. 커밋 머리말은 `docs(core):` 를 쓴다.
- 그 이후 커밋이 곧 커스터마이징 내역이다.

```bash
# 원본 대비 내가 바꾼 파일 목록
git diff --stat vendor/zabbix-7.2.15

# 코어 파일에서 주석이 달린 파일 목록
git diff --stat vendor/zabbix-7.2.15 -- ui ':!ui/modules/dmove'

# 코어 파일의 "코드"를 실수로 고치지 않았는지 확인 (아무것도 안 나와야 정상)
# [DM 해설] 주석 줄 추가가 아닌 변경만 골라서 보여 준다.
git diff vendor/zabbix-7.2.15 -- ui ':!ui/modules/dmove' | grep -E '^[+-][^+-]' | grep -vE '^\+\s*// \[DM 해설\]'

# 고객 서버에 반영할 변경분을 패치 파일로 만들기
git diff vendor/zabbix-7.2.15 -- ui/modules/dmove > dmove-module.patch
```

- 커밋 메시지 머리말: `feat(swinventory): ...`, `fix(swinventory): ...`, `docs: ...`, `vendor: ...`
- 화면 하나를 옮길 때마다 커밋을 나눈다.
- `.gitattributes` 의 `* -text` 는 줄바꿈 자동 변환을 막는 설정이다. 지우지 않는다.
- 고객 서버의 실제 패치 버전이 7.2.15 가 아니면, 해당 버전 소스로 `vendor:` 커밋을 다시 만들고 그 위에 모듈 커밋을 올린다.
  모듈이 코어를 고치지 않으므로 충돌 없이 그대로 올라간다.

### 부득이 코어 파일을 고쳐야 할 때

먼저 모듈로 가능한지 확인한다(메뉴, 화면, CSS, JS, 요청 가로채기 `onBeforeAction` 은 모두 모듈로 된다).
그래도 필요하면 `docs/CORE_PATCHES.md` 를 만들어 **파일, 이유, 수정 내용**을 적고, 코드에는 아래 표시를 남긴다.

```php
// DMOVE-PATCH BEGIN: 이유
...
// DMOVE-PATCH END
```

## 3. 서버 반영과 모듈 켜기

1. `ui/modules/dmove/` 폴더를 서버의 `/usr/share/zabbix/modules/dmove/` 로 복사한다.
   웹서버 계정이 읽을 수 있어야 한다.
2. Zabbix 화면에서 **관리 > 일반 > 모듈** (Administration > General > Modules) → **디렉토리 스캔** (Scan directory) 버튼을 누른다.
3. 목록에 나타난 `DMove Custom` 을 **활성**으로 바꾼다.
4. 왼쪽 사이드바 **인벤토리** 아래에 **SW 인벤토리**가 생긴다.

메뉴가 안 보일 때 확인할 것:

- **사용자 > 사용자 역할** 에서 해당 역할의 모듈 접근에 `DMove Custom` 이 체크되어 있는가
- 해당 역할에 **인벤토리 > 호스트** 화면 접근 권한이 있는가 (`checkPermissions()` 조건)
- 모듈 화면 상단에 `Cannot load module` / `Wrong Module.php class name` 오류가 없는가 (→ 4.1 네임스페이스 규칙)

## 4. 코드 가이드라인

### 4.1 이름 규칙

| 대상 | 규칙 | 예 |
|---|---|---|
| 액션명 | `<화면>.<동작>`. 주소에 그대로 보이므로 모듈 이름(dmove)은 넣지 않는다. 코어 액션과 겹치면 안 된다(확인 방법: `template/README.md`) | `swinventory.list` |
| 컨트롤러 | `actions/<카테고리>/<화면>/<화면><동작>.php` | `actions/inventory/swinventory/SwInventoryList.php` |
| 뷰 | `views/<카테고리>/<액션명>.php` | `views/inventory/swinventory.list.php` |
| manifest 의 `class` / `view` | `카테고리/화면/클래스` / `카테고리/액션명` | `Inventory/SwInventory/SwInventoryList` / `inventory/swinventory.list` |
| 화면 문구 | 코어에 있는 문구는 `_('Host')`, 새 문구는 `Lang::t('English')` + `lang/ko.php` | `Lang::t('Server time check')` |
| 프로파일 키 | `web.dmove.<화면>.` 로 시작 | `web.dmove.swinventory.filter.os` |
| CSS 클래스 | `dm-` 로 시작 | `.dm-badge` |
| 네임스페이스 | 폴더는 소문자, 네임스페이스는 첫 글자 대문자 | `includes/Foo.php` → `Modules\DMove\Includes\Foo` |

코어와 이름이 겹치면 모듈이 충돌로 꺼지므로 `dmove.` 접두어는 꼭 지킨다.

### 4.2 계층

- **actions/** : 입력 검증, 권한, 프로파일 저장, 응답 생성만. 업무 로직을 넣지 않는다.
- **includes/** : 조회/매칭/필터/정렬 같은 로직. 화면과 CSV 가 같이 쓴다.
- **views/** : 출력만. DB 나 API 를 호출하지 않는다.

### 4.3 반드시 지킬 것

- 요청 값은 `checkInput()` 에 규칙을 등록하고 `$this->getInput()` 으로만 읽는다.
  `$_GET`, `$_POST`, `$_REQUEST`, `getRequest()` 를 직접 쓰지 않는다.
- 호스트/아이템 등 Zabbix 데이터는 `API::Host()->get()` 같은 API 로 조회한다. 사용자 권한이 자동 적용된다.
- 직접 SQL 이 필요하면 `DBselect()` 를 쓰고, 값은 `zbx_dbstr()`, ID 목록은 `dbConditionInt()` 로 넣는다.
  문자열을 그대로 이어 붙이지 않는다.
- 화면 출력은 `CTableInfo`, `CTextBox`, `CSpan` 같은 코어 클래스를 쓴다(자동 이스케이프).
  HTML 문자열을 직접 `echo` 하지 않는다.
- 접속 정보, 비밀번호를 소스나 `conf/` JSON 에 적지 않는다. 모듈 폴더는 웹에서 접근 가능한 위치다.
- 파일 경로는 `__DIR__` 기준 절대경로로 쓴다. (`"./autoever/..."` 같은 상대경로 금지)
- PHP 8 규칙: 클래스 프로퍼티는 반드시 선언하고, 배열 키는 `??` 로 기본값을 주고,
  `null` 일 수 있는 값은 `(string)` 으로 바꾼 뒤 문자열 함수에 넘긴다.
  (상세: `docs/LEGACY_V5_PHP8_REVIEW.md`)
- Zabbix 는 PHP 경고(Warning/Deprecated)를 화면 상단 빨간 메시지로 그대로 보여 준다.
  경고가 하나도 안 뜨는 상태로 커밋한다.

### 4.4 검증 이력

이 모듈은 공식 컨테이너 이미지(Zabbix 7.2.15, PHP 8.4, PostgreSQL)에서 다음을 확인했다.
메뉴 표시와 선택 상태, 목록 표시, 텍스트/Agent 필터, 정렬, 필터 초기화, CSV 다운로드,
ITSM 미연동 경고, ITSM 행과 Zabbix 호스트 매칭(대소문자 무시), HTML 이스케이프, PHP 경고 없음.
고객 서버는 DB(MySQL 여부)와 PHP 버전이 다를 수 있으므로 반영 후 같은 항목을 다시 확인한다.

### 4.5 서버에서 문법 검사

```bash
find /usr/share/zabbix/modules/dmove -name '*.php' -exec php -l {} \;
```

## 5. 새 페이지 추가 순서

`ui/modules/dmove/template/` 의 템플릿을 복사해서 만든다. **명령어까지 적힌 절차는 `ui/modules/dmove/template/README.md` 에 있다.**

1. 템플릿 2개(컨트롤러, 화면 뷰)를 `actions/<카테고리>/<화면>/`, `views/<카테고리>/` 로 복사하고 `Sample` / `sample` 을 새 이름으로 바꾼다
2. `manifest.json` 의 `actions` 에 액션 추가 (화면용, 필요하면 CSV 용)
3. `Module.php` 의 메뉴 표(`REPORT_MENUS` 등)에 한 줄 추가
4. `lang/ko.php` 에 한국어 문구 추가
5. 브라우저를 새로고침한다. `manifest.json` 은 요청마다 다시 읽히므로 모듈을 껐다 켤 필요는 없다
6. 컨트롤러와 뷰의 `[바꿀 곳]` 표시를 채운다. 로직이 길어지면 `includes/XxxService.php` 로 옮긴다

### 5.1 현재 화면 목록

| 메뉴 | 액션 | 상태 |
|---|---|---|
| 인벤토리 > SW 인벤토리 | `swinventory.list`, `.export` | 구현. ITSM 테이블 이름 설정과 Agent "예외" 기준만 남음 |
| 보고서 > 저사용 서버 사용현황 | `lowserver.list`, `.export` | 구현 (5버전 소스 기준) |
| 보고서 > 고사용 서버 사용현황 | `highserver.list`, `.export` | 구현. 판정 조건은 가정 (확인 필요) |
| 보고서 > 일일점검 | `dailycheck.list` | 구현 (5버전 소스 기준). 태그 설정(`conf/dailycheck.conf.php`)을 채워야 한다 |
| 보고서 > Peak 이벤트 분석 | `peakevent.list`, `.export` | 구현. 집계 기준은 가정 (확인 필요) |
| 보고서 > 윈도우즈 패치 현황 | `winpatch.list`, `.export`, `.detail` | 구현 (5버전 소스 기준). 필터의 세부 동작은 가정 |
| 보고서 > 서버 시간 확인 | `uptime.list` | 필터/표 구성 완료, 데이터 조회 TODO |
| 유저 > 장기 미접속 계정 현황 | `userunconnect.list` | 필터/표 구성 완료, 데이터 조회 TODO |
| 보고서 > CPU/Memory 전체 사용률 추출 | `usageexport.list` | 필터 구성 완료, 추출 기능 TODO |
| 보고서 > 성능 데이터 추세 분석 | `perftrend.list` | 빈 페이지. 대시보드 그래프 위젯으로 대체할지 먼저 결정 |

"구현"인 화면도 `conf/` 의 설정(ITSM 테이블, 아이템 키, 태그)을 채워야 값이 나온다. 공개 저장소라서 그 값은 비워 두었다.
서버에서 확인할 것, 가정으로 만든 것, 아직 못 만든 것은 `docs/CUSTOMER_ENV_CHECKLIST.md` 에 정리했다.

### 5.2 꽂아 쓰는 공용 부품

| 부품 | 하는 일 | 사용법이 적힌 곳 |
|---|---|---|
| 내보내기 | 화면에 [CSV 내보내기] [Excel 내보내기] 버튼 | `includes/export/ExportTrait.php` 머리말 |
| 호스트 필터 | 필터의 "호스트 그룹", "호스트" 선택창 두 줄 | `includes/filter/HostFilter.php` 머리말 |
| 다국어 | 영어/한국어 문구 전환 | `includes/Lang.php` 머리말 |
| 호스트 도우미 | 호스트의 대표 IP | `includes/HostHelper.php` |
| ITSM | ITSM 목록, 호스트명으로 Description 찾기 | `includes/ItsmRepository.php` |

## 6. SW 인벤토리: 고객환경에서 채워야 할 곳

소스에서 `TODO(고객환경)` 를 검색하면 전부 나온다. 우선순위 순서:

| 순서 | 파일 | 할 일 |
|---|---|---|
| 1 | `conf/itsm.conf.php` | ITSM 테이블의 스키마/테이블 이름을 채운다. 조회 로직(`includes/ItsmRepository.php`)은 5버전 쿼리대로 구현되어 있다. 비워 두면 Zabbix 호스트 기준으로 표시되고 경고가 뜬다 |
| 2 | `includes/SoftwareResolver.php` 의 `INVENTORY_FIELDS` | OS/WEB/WAS/DB/이중화 = 인벤토리 `os`, `software_app_a~d`. 5버전 소스에서 확인한 값이다. 바뀌었으면 이 상수만 고친다 |
| 3 | `includes/SoftwareResolver.php` | 값이 아이템에서 온다면 `resolve()` 에 로직 추가 |
| 4 | `includes/SwInventoryService.php` | `resolveAgentStatus()` 의 "예외" 판정 기준 확인 |
| 5 | `assets/css/dmove.css` | 5버전에서 추가했던 커스텀 CSS 만 옮긴다 |
| 6 | `actions/inventory/swinventory/SwInventoryList.php` | `checkPermissions()` 권한 정책 확인 |
| 7 | `includes/export/CsvWriter.php` | 5버전 공통 CSV 의 인코딩/구분자 규칙이 달랐다면 맞춘다 |
| 8 | `includes/export/XlsxWriter.php` | Excel 파일의 색/글꼴/열 너비를 고객 표준에 맞추려면 맨 위 상수를 고친다 |
