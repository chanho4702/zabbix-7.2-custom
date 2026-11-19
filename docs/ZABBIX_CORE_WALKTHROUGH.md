# Zabbix 7.2 코어 소스 해설 (모듈 개발에 필요한 부분)

모듈 개발에 관련된 코어 파일 27개에는 `// [DM 해설]` 로 시작하는 한국어 주석을 직접 달아 두었다.
이 문서는 그 주석들을 흐름 순서로 묶은 지도다.

- 코어 파일의 변경은 **주석 줄 추가뿐**이다. 동작하는 코드는 한 글자도 바꾸지 않았다.
- 아래 표의 **줄번호는 주석을 넣기 전 원본(태그 `vendor/zabbix-7.2.15`, = 고객 서버의 파일) 기준**이다.
  이 저장소의 파일은 주석만큼 줄이 밀려 있으므로, 저장소 안에서는 `[DM 해설]` 을 검색해서 찾는다.
- 주석이 달린 파일 목록: `git diff --stat vendor/zabbix-7.2.15 -- ui ':!ui/modules/dmove'`

경로는 모두 `ui/` 아래다.

로그인 관련 파일(`index.php`, `general.login.php`, `index_mfa.php`, `CWebUser.php`, `CUser.php`)의 해설은 `LOGIN_CUSTOMIZATION.md` 2장에 있다.

읽는 순서: 1장(요청 흐름) → 2장(모듈) → 3장(메뉴) → 필요할 때 4장 이후.

## 1. 요청 하나가 처리되는 흐름

브라우저가 `zabbix.php?action=swinventory.list` 를 열었을 때 일어나는 일이다.

```
zabbix.php
  └ include/config.inc.php          APP::getInstance()->run()
      └ ZBase::run()                설정/DB/로그인 → 메뉴 생성 → 모듈 로드 → 라우터에 액션 등록
          └ ZBase::processRequest() 컨트롤러 객체 생성 → 실행
              └ CController::run()  checkInput → checkPermissions → doAction
          └ processResponseFinal()  뷰 실행 → 레이아웃으로 감싸 출력
```

| 위치 | 하는 일 |
|---|---|
| `zabbix.php:17` | `include/config.inc.php` 를 불러오는 것이 전부다 |
| `include/config.inc.php:20` | `APP::getInstance()->run(APP::EXEC_MODE_DEFAULT)` 로 프론트엔드를 시작한다. `APP` 는 `ZBase` 를 상속한 클래스다 |
| `include/classes/core/ZBase.php:167` `run()` | 전체 진행을 맡는다 |
| `ZBase.php:180~181` | URL 에서 액션명을 꺼낸다. `zabbix.php` 면 `action` 파라미터, 그 외(`hostinventories.php` 등 옛 방식 페이지)는 파일명이 액션명이다 |
| `ZBase.php:187~191` | 설정 파일 읽기, DB 연결, 로그인 확인 |
| `ZBase.php:194` `initComponents()` → `:888` | 라우터와 **사이드바 메뉴**(`menu.main`)를 만들어 등록한다 |
| `ZBase.php:195` `initModuleManager()` → `:900` | DB 의 `module` 테이블에서 **활성화된 모듈**을 읽어 `Module.php` 의 `init()` 을 호출한다. 메뉴가 먼저 만들어졌기 때문에 `init()` 에서 메뉴를 고칠 수 있다 |
| `ZBase.php:198~199` | 모듈의 `manifest.json` 액션들을 라우터에 추가한다 |
| `ZBase.php:212` | 라우터가 액션명으로 컨트롤러 클래스, 레이아웃, 뷰 이름을 결정한다 |
| `ZBase.php:215~218` | 현재 액션에 해당하는 사이드바 메뉴를 "선택됨"으로 표시한다. `CMenuItem::setAction()` / `setAliases()` 에 적은 액션명과 비교한다 |
| `ZBase.php:585` `processRequest()` | 컨트롤러 객체를 만들고(`:614`), 모듈들의 `views/` 폴더를 뷰 검색 경로에 추가하고(`:633~638`), 실행한다 |
| `include/classes/mvc/CController.php:461` `run()` | CSRF 검사 → `checkInput()` → `checkPermissions()` → `doAction()` 순서로 부른다. `checkPermissions()` 가 `true` 가 아니면 접근 거부 화면이 나온다 |
| `ZBase.php:660` `processResponseFinal()` | 컨트롤러가 `setResponse()` 로 넘긴 데이터를 뷰에 전달해 실행하고(`:727`), 결과를 레이아웃(`app/views/layout.htmlpage.php` 등)에 끼워 출력한다 |

### 오류가 화면에 보이는 이유

`ZBase.php:175` 에서 `set_error_handler('zbx_err_handler')` 를 건다. 그래서 PHP 의 Warning, Deprecated 가
`include/func.inc.php:2257` 의 `zbx_err_handler()` 로 들어가 화면 상단의 빨간 메시지로 표시된다.
개발 중 빨간 메시지가 보이면 대부분 "정의 안 된 변수/배열 키" 또는 "null 을 문자열 함수에 전달"이다.

## 2. 모듈이 읽히는 방식

| 위치 | 하는 일 |
|---|---|
| `ZBase.php:903~907` | `module` 테이블에서 상태가 "활성"인 모듈만 가져온다. **관리 > 일반 > 모듈**에서 켜야 하는 이유다 |
| `ZBase.php:912` | 로그인한 사용자의 역할에 그 모듈 접근 권한이 없으면 건너뛴다 |
| `include/classes/core/CModuleManager.php:370` `loadManifest()` | `manifest.json` 을 읽고 검사한다. 필수 키는 `manifest_version`, `id`, `name`, `namespace`, `version` 이다(`:398`). `manifest_version` 은 2 만 허용된다(`:32`, `:37`) |
| `CModuleManager.php:414` | 네임스페이스를 `Modules\<namespace>` 로 만든다. 폴더가 `modules/` 아래라서 앞에 `Modules` 가 붙는다 |
| `CModuleManager.php:112` `initModules()` | `Module.php` 가 있으면 `Modules\<namespace>\Module` 클래스를 만들고 `init()` 을 부른다. 클래스 이름이 틀리면 `Wrong Module.php class name` 오류(`:130`) |
| `CModuleManager.php:244` `getActions()` | `manifest.json` 의 액션을 라우터용으로 바꾼다. 컨트롤러 클래스는 `Modules\<namespace>\Actions\<class>` 가 된다. `layout` 을 생략하면 `layout.htmlpage` 다 |
| `CModuleManager.php:290` `checkConflicts()` | 다른 모듈과 `id`, 네임스페이스, 액션명이 겹치면 양쪽 다 로드하지 않는다 |
| `include/classes/core/CAutoloader.php:62` `loadClass()` | 클래스 이름을 파일 경로로 바꾼다. 네임스페이스 중간 부분은 **소문자 폴더**로 바뀐다. `Modules\DMove\Includes\Foo` → `modules/dmove/includes/Foo.php` |
| `include/classes/core/CModule.php` | 모듈 기본 클래스. `init()`(`:40`), 요청마다 컨트롤러 실행 직전에 불리는 `onBeforeAction()`(`:132`), 종료 시 불리는 `onTerminate()`(`:140`) 를 재정의할 수 있다 |
| `include/page_header.php:143~150` | `manifest.json` 의 `assets.css` 파일을 `<모듈>/assets/css/` 에서 찾아 모든 화면에 넣는다. JS 는 `:185` 부근에서 `<모듈>/assets/js/` 로 처리한다 |

코어 자체의 액션 표는 `include/classes/mvc/CRouter.php:30` 부터의 배열이다.
형식은 `'액션명' => [컨트롤러 클래스, 레이아웃, 뷰]` 로, 5버전 `autoever/routing.php` 와 같은 모양이다.

## 3. 사이드바 메뉴

| 위치 | 하는 일 |
|---|---|
| `include/classes/helpers/CMenuHelper.php:25` `getMainMenu()` | 사이드바 전체를 만든다. 1단계 메뉴 위치: Dashboards `:30`, Monitoring `:70`, Services `:97`, **Inventory `:117`**, Reports `:155`, Data collection `:208`, Alerts `:285`, Users `:322`, Administration `:400` |
| `CMenuHelper.php:105~121` | 인벤토리 메뉴. 하위 항목 Overview, Hosts 는 사용자 역할 권한이 있을 때만 들어간다. 둘 다 없으면 Inventory 자체가 생기지 않는다(그래서 모듈에서는 `find` 가 아니라 `findOrAdd` 를 쓴다) |
| `include/classes/html/CMenu.php` | 메뉴 목록. `add()` `:53`, `find()` `:66`, `findOrAdd()` `:83`, `insertAfter()` `:117`, `insertBefore()` `:129`, `remove()` `:140`. 찾는 기준은 **화면에 보이는 라벨 문자열**이다 |
| `include/classes/html/CMenuItem.php` | 메뉴 한 칸. `setAction()` `:91`, `setAliases()` `:121`, `setIcon()` `:138`, `getSubMenu()` `:218`, `setUrl()` `:292`(옛 방식 `xxx.php` 페이지용) |
| `app/partials/layout.htmlpage.aside.php:82` | 완성된 `menu.main` 을 HTML 로 출력한다 |

코어 메뉴를 숨기고 싶을 때도 `Module.php` 에서 `->remove(_('Overview'))` 처럼 처리할 수 있다.

## 4. 컨트롤러에서 쓰는 것

| 기능 | 위치 | 설명 |
|---|---|---|
| 입력 검증 | `CController.php:247` `validateInput()` | 규칙 배열을 받아 검사하고, 통과한 값만 `getInput()` 으로 읽을 수 있게 저장한다 |
| 검증 규칙 종류 | `include/classes/validators/CNewValidator.php:95~344` | `not_empty`, `in`, `int32`, `id`, `array_id`, `array`, `array_db`, `ge`, `le`, `db`, `range_time`, `string`, `bool` 등. 여러 개는 `'required|string'` 처럼 `|` 로 잇는다 |
| 값 읽기 | `CController.php:339` `hasInput()`, `:351` `getInput()` | 5버전 `hasRequest()` / `getRequest()` 의 대체 |
| 권한 | `CController.php:165` `checkAccess()` | 사용자 역할 규칙 확인. 규칙 이름은 `include/classes/helpers/CRoleHelper.php:20~` 의 상수(`UI_INVENTORY_HOSTS` `:29` 등) |
| 사용자 유형 | `CController.php:154` `getUserType()` | `USER_TYPE_ZABBIX_USER`(1), `USER_TYPE_ZABBIX_ADMIN`(2), `USER_TYPE_SUPER_ADMIN`(3) |
| CSRF | `CController.php:172` `disableCsrfValidation()` | 조회 전용 액션에서만 끈다. 5버전 `disableSIDValidation()` 의 새 이름 |
| 응답 | `include/classes/mvc/CControllerResponseData.php` | 뷰로 넘길 배열을 담는다. `setTitle()` 은 브라우저 탭 제목, `setFileName()` 은 CSV 파일명 |
| 사용자별 설정 저장 | `include/classes/user/CProfile.php` | `get()` `:157`, `getArray()` `:200`, `deleteIdx()` `:234`, `update()` `:262`, `updateArray()` `:314`. DB `profiles` 테이블에 사용자별로 저장된다. 타입은 `PROFILE_TYPE_STR`, `PROFILE_TYPE_INT`, `PROFILE_TYPE_ID` |
| 페이징 | `include/classes/helpers/CPagerHelper.php:37` `paginate()` | 배열을 현재 페이지 분량으로 자르고 페이지 이동 HTML 을 돌려준다. 전체 행이 "검색/필터 결과 제한"을 넘으면 그 수까지만 다룬다(`:99~108`) |
| Zabbix 데이터 조회 | `include/classes/api/services/CHost.php` 등 | `API::Host()->get([...])`. 옵션은 공식 문서의 host.get 과 같다. 사용자 권한이 자동 적용된다 |
| 하위 호스트 그룹 | `include/hostgroups.inc.php:41` `getSubGroups()` | 5버전 컨트롤러가 쓰던 함수. 7.2 에도 있다 |

## 5. 뷰에서 쓰는 것

화면 클래스는 모두 `include/classes/html/` 에 클래스 이름과 같은 파일로 있다.

| 클래스/함수 | 용도 |
|---|---|
| `CHtmlPage` | 페이지 전체 틀. `setTitle()`, `setControls()`(제목 오른쪽 버튼), `addItem()`, `show()` |
| `CFilter` | 접히는 필터 영역. `setResetUrl()` `:106`, `addVar()` `:122`, `setProfile()` `:145`, `setActiveTab()` `:175`, `addFilterTab()` `:190`. [적용]/[초기화] 버튼을 자동으로 만든다 |
| `CFormList` | "라벨 + 입력" 줄 목록. `addRow(라벨, 입력)` |
| `CTextBox`, `CCheckBox`, `CSelect`, `CMultiSelect` | 입력 요소 |
| `CTableInfo` | 목록 테이블. `setHeader()`, `addRow()`, `setPageNavigation()` `:41`. 행이 없으면 "데이터 없음"을 자동 표시한다 |
| `CCol`, `CSpan`, `CLink`, `CDiv`, `CTag` | 칸, 글자 묶음, 링크 등 |
| `CRedirectButton` | 누르면 주소로 이동하는 버튼(CSV 내보내기 등) |
| `CUrl` | 주소 조립. `(new CUrl('zabbix.php'))->setArgument('action', '...')` |
| `make_sorting_header()` `include/func.inc.php:1319` | 클릭하면 `sort`, `sortorder` 를 붙여 다시 여는 테이블 헤더 |
| `makeMessageBox()` `include/func.inc.php:1639` | 안내/경고 상자 |
| `zbx_toCSV()` `include/func.inc.php:1246` | 2차원 배열을 CSV 문자열로 |
| `ZBX_STYLE_*` 상수 `include/defines.inc.php:2000~` | 코어 CSS 클래스 이름. `ZBX_STYLE_GREEN` `:2037`, `ZBX_STYLE_RED` `:2123`, `ZBX_STYLE_NOWRAP` `:2113` 등 |

레이아웃 파일(`app/views/`):

- `layout.htmlpage.php` : 일반 화면. 머리말, 사이드바, 본문을 조립한다.
- `layout.csv.php` : CSV 다운로드. 헤더 두 줄을 보내고 뷰 출력만 내보낸다(`:21~24`).
- `layout.json.php` : JSON 응답(팝업, AJAX).

## 6. 따라 읽기 좋은 코어 화면

"필터 + 목록 + CSV 내보내기" 구조가 SW 인벤토리와 같아서 본보기로 삼았다.

| 파일 | 볼 것 |
|---|---|
| `include/classes/mvc/CRouter.php:53~54` | 같은 컨트롤러를 화면용/CSV 용 액션 두 개로 등록하는 방법 |
| `app/controllers/CControllerActionLogList.php` | 필터 프로파일 저장/삭제, 페이징, CSV 일 때 `setFileName()` |
| `app/views/reports.actionlog.list.php` | `CFilter`, `CTableInfo`, CSV 버튼 |
| `app/views/reports.actionlog.list.csv.php` | `zbx_toCSV()` 로 출력 |

5버전에서 옮겨야 할 "인벤토리 > 호스트" 원본 화면은 아직 옛 방식 페이지다: `hostinventories.php`,
`include/views/inventory.host.list.php`. 구조 참고용으로만 보고, 새 화면은 위의 MVC 방식으로 만든다.
