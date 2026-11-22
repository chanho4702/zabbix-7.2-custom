# 해설집: Zabbix 5 커스텀 화면을 7.0 로 옮기기

고객환경에서 이 문서 하나만 보고 작업할 수 있게 만든 안내서다.
여기 실린 예제 코드는 Zabbix 7.2.15 + PHP 8.4 에서 확인했다. 모듈의 모든 화면은 고객과 같은 Zabbix 7.0.26 (+ PHP 8.5) 에서 다시 확인했다.

| 알고 싶은 것 | 볼 곳 |
|---|---|
| 5버전과 뭐가 달라졌나 | 1장 |
| 페이지를 새로 추가하는 방법 | 2장 (원리), 2.1 (본보기 화면), 2.2~2.3 (공통 작업, 기능별 방법) |
| 5버전 소스 중 그대로 쓰면 안 되는 코드 | 3장 |
| 5버전 코드를 한 줄씩 바꾸는 규칙 | 4장 |
| 남은 화면 목록과 진행 순서 | 5장 |
| 미구현 영역과 채우는 데 필요한 5버전 파일 | `UNIMPLEMENTED.md` |
| 오류가 났을 때 | 6장 |
| 용어 | 7장 |
| 로그인 화면 수정, OTP 등 추가 인증 | `LOGIN_CUSTOMIZATION.md` |

더 깊은 내용은 `DEVELOPMENT_GUIDE.md`(규칙), `ZABBIX_CORE_WALKTHROUGH.md`(코어 동작), `LEGACY_V5_PHP8_REVIEW.md`(검토 근거)에 있다.

---

## 1. 무엇이 달라졌나

5버전은 코어 파일을 덮어써서 화면을 끼워 넣었다. 7버전은 **모듈**이라는 공식 확장 방식이 있어서 코어를 건드리지 않는다.

```
[5버전]                                      [7버전]
/usr/share/zabbix/                           /usr/share/zabbix/
├── dmove/                                └── modules/
│   ├── routing.php      액션 표                 └── dmove/          ← 이 폴더만 올린다
│   ├── controllers/                                 ├── manifest.json   액션 표
│   ├── views/                                       ├── Module.php      메뉴
│   ├── src/             덮어쓸 코어 파일            ├── actions/        컨트롤러
│   └── patch.sh         코어를 덮어쓰는 스크립트    ├── includes/       로직
├── include/menu.inc.php   ← 덮어씀                  ├── views/          화면
├── include/.../CMenu.php  ← 덮어씀                  └── assets/css/     CSS
└── assets/.../blue-theme.css ← 덮어씀
```

| 하고 싶은 일 | 5버전 | 7버전 |
|---|---|---|
| URL(액션) 등록 | `autoever/routing.php` 에 한 줄 | `manifest.json` 의 `actions` 에 한 항목 |
| 사이드바 메뉴 | `menu.inc.php` 를 고쳐서 `patch.sh` 로 덮어쓰기 | `Module.php` 의 `init()` 에 한 줄 |
| 컨트롤러 | `autoever/controllers/CControllerXxx.php` | `actions/reports/xxx/XxxList.php` |
| 공통 로직 | `C*` 트레잇, `function.php` | `includes/` 의 클래스 |
| CSS | `blue-theme.css` 를 덮어쓰기 | `assets/css/dmove.css` |
| 서버 반영 | `patch.sh` 실행 | 폴더 복사 후 관리 화면에서 모듈 활성화 |
| Zabbix 업그레이드 시 | 덮어쓴 파일을 전부 다시 작업 | 모듈 폴더를 그대로 복사 |

화면 주소도 바뀐다. 5버전의 `swinventories.php` 같은 파일 주소는 없어지고 전부 `zabbix.php?action=액션명` 이다.

---

## 2. 따라 하기: 새 페이지 추가

> 실제로 페이지를 추가할 때는 **`ui/modules/dmove/template/README.md` 의 템플릿 복사 절차**를 쓴다. (파일 3개 복사 + 이름 바꾸기)
> 비슷한 화면이 이미 있으면 그 화면을 복사하는 것이 더 빠르다. (2.1)
> 저사용 서버 화면은 실제로는 완성되어 있고 구조도 아래 예제와 다르다(공통 컨트롤러 사용). 아래는 원리를 보이기 위한 단순한 형태다.
> 이 장은 각 파일이 무슨 일을 하는지 처음부터 손으로 만들어 보며 이해하기 위한 설명이다.

5버전의 "저사양 서버" 화면을 예로, **필터 1개 + 목록 테이블**이 있는 페이지를 보고서(Reports) 메뉴 아래에 추가한다.
만드는 파일은 2개, 고치는 파일은 2개다.

```
ui/modules/dmove/
├── manifest.json                      (고침) ① 액션 등록
├── Module.php                         (고침) ④ 메뉴 추가
├── actions/reports/lowserver/LowServerList.php          (새로) ② 컨트롤러
└── views/reports/lowserver.list.php  (새로) ③ 화면
```

### ① 액션 등록: `manifest.json`

`actions` 안에 항목을 추가한다. 5버전 `routing.php` 의 아래 줄과 같은 일을 한다.

```php
// 5버전 routing.php
'lowserver' => ['CControllerLowserver', 'layout.htmlpage', 'report/lowserver'],
```

```json
"actions": {
	"swinventory.list": { ... 기존 ... },
	"lowserver.list": {
		"class": "Reports/LowServer/LowServerList",
		"view": "reports/lowserver.list",
		"layout": "layout.htmlpage"
	}
}
```

| 키 | 뜻 | 규칙 |
|---|---|---|
| `"lowserver.list"` | 액션명. 주소가 `zabbix.php?action=lowserver.list` 가 된다 | 모듈 이름(dmove)은 넣지 않는다. 코어 액션과 겹치면 안 된다 |
| `class` | `화면 폴더/컨트롤러 클래스` | `Reports/LowServer/LowServerList` → `actions/reports/lowserver/LowServerList.php` |
| `view` | `카테고리 폴더/뷰 파일 이름`(확장자 제외) | `reports/lowserver.list` → `views/reports/lowserver.list.php` |
| `layout` | 겉틀 | 일반 화면 `layout.htmlpage`, JSON `layout.json`, 내보내기(CSV/Excel) `export/layout.file` |

주의: JSON 은 주석을 쓸 수 없고, 마지막 항목 뒤에 쉼표를 붙이면 안 된다. 문법이 틀리면 **모듈 전체**가 안 뜬다.

### ② 컨트롤러: `actions/reports/lowserver/LowServerList.php`

```php
<?php declare(strict_types = 0);

namespace Modules\DMove\Actions\Reports\LowServer;      // actions/lowserver 폴더 -> Actions\Reports\LowServer

use API;                                  // 코어 클래스는 use 로 선언해야 쓸 수 있다
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CRoleHelper;
use CUrl;

class LowServerList extends CController {  // 클래스 이름 = 파일 이름 = manifest 의 class

	// 필터 값을 사용자별로 저장할 때 쓰는 키의 앞부분. 화면마다 다르게 짓는다.
	private const PROFILE_PREFIX = 'web.lowserver.';

	protected function init(): void {
		$this->disableCsrfValidation();   // 조회 전용 화면이므로 CSRF 검사를 끈다
	}

	// 1) 입력 검증: 화면에서 넘어올 수 있는 값을 전부 여기에 적는다
	protected function checkInput(): bool {
		$fields = [
			'page' =>			'ge 1',      // 페이지 번호 (1 이상)
			'filter_set' =>		'in 1',      // [적용] 버튼
			'filter_rst' =>		'in 1',      // [초기화] 버튼
			'filter_name' =>	'string'     // 필터 입력창
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	// 2) 권한: true 를 돌려줘야 화면이 열린다
	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_REPORTS_SYSTEM_INFO);
	}

	// 3) 실제 처리
	protected function doAction(): void {
		// [적용] 이면 필터 저장, [초기화] 면 삭제
		if ($this->hasInput('filter_set')) {
			CProfile::update(self::PROFILE_PREFIX.'filter.name', trim($this->getInput('filter_name', '')),
				PROFILE_TYPE_STR
			);
		}
		elseif ($this->hasInput('filter_rst')) {
			CProfile::deleteIdx(self::PROFILE_PREFIX.'filter.name');
		}

		$filter_name = CProfile::get(self::PROFILE_PREFIX.'filter.name', '');

		// 데이터 조회. 화면이 복잡해지면 이 부분을 includes/ 의 클래스로 옮긴다.
		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name'],
			'search' => $filter_name !== '' ? ['name' => $filter_name] : null,
			'sortfield' => 'name'
		]);

		// 뷰로 넘길 값
		$data = [
			'action' => $this->getAction(),
			'filter_name' => $filter_name,
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'filter.active', 1)
		];

		// 페이징: $hosts 가 현재 페이지 분량으로 잘린다
		$data['paging'] = CPagerHelper::paginate($this->getInput('page', 1), $hosts, ZBX_SORT_UP,
			(new CUrl('zabbix.php'))->setArgument('action', $data['action'])
		);
		$data['hosts'] = $hosts;

		$response = new CControllerResponseData($data);
		$response->setTitle('저사양 서버');
		$this->setResponse($response);
	}
}
```

`checkPermissions()` 에 쓸 수 있는 권한 이름은 `ui/include/classes/helpers/CRoleHelper.php` 의 `UI_...` 상수다.
사용자 유형으로 막으려면 `return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN;` 처럼 쓴다.

### ③ 화면: `views/reports/lowserver.list.php`

```php
<?php declare(strict_types = 0);

/**
 * @var CView $this
 * @var array $data     컨트롤러가 넘긴 $data
 */

// 필터 영역
$filter = (new CFilter())
	->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', $data['action']))
	->setProfile('web.lowserver.filter')     // 컨트롤러 PROFILE_PREFIX + 'filter'
	->setActiveTab($data['active_tab'])
	->addVar('action', $data['action'])
	->addFilterTab(_('Filter'), [
		(new CFormList())->addRow('호스트명',
			(new CTextBox('filter_name', $data['filter_name']))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
		)
	]);

// 목록 테이블
$table = (new CTableInfo())
	->setHeader(['호스트', '표시명'])
	->setPageNavigation($data['paging']);

foreach ($data['hosts'] as $host) {
	$table->addRow([$host['host'], $host['name']]);
}

// 페이지 출력
(new CHtmlPage())
	->setTitle('저사양 서버')
	->addItem($filter)
	->addItem($table)
	->show();
```

입력창 이름 `filter_name` 은 컨트롤러 `checkInput()` 의 키와 같아야 한다. 다르면 값이 버려진다.

### ④ 메뉴: `Module.php` 의 `init()`

```php
APP::Component()->get('menu.main')
	->findOrAdd(_('Reports'))          // 1단계 메뉴 "보고서"
	->getSubMenu()
	->add((new CMenuItem('저사양 서버'))->setAction('lowserver.list'));
```

### ⑤ 확인

1. 브라우저를 새로고침한다. `manifest.json` 은 요청마다 다시 읽히므로 모듈을 껐다 켤 필요는 없다.
2. 사이드바 보고서 아래에 "저사양 서버"가 보이는지 확인한다.
3. 들어가서 목록이 나오는지, 필터 [적용]/[초기화]가 동작하는지 확인한다.
4. 화면 상단에 빨간 오류 메시지가 없는지 확인한다. 있으면 6장을 본다.

### 여기에 기능을 더 붙이려면

완성된 본보기는 SW 인벤토리다. 필요한 기능이 들어 있는 파일을 열어 해당 부분을 복사한다.

| 기능 | 본보기 위치 |
|---|---|
| 입력창 여러 개, 체크박스 필터 | `views/inventory/swinventory.list.php` 1) 필터 영역 |
| 컬럼 헤더 클릭 정렬 | 같은 파일 `make_sorting_header`, 컨트롤러의 `sort` / `sortorder` 처리 |
| CSV / Excel 내보내기 | `manifest.json` 의 `swinventory.export`, `includes/export/ExportTrait.php`(사용법이 머리말에 있다) |
| 데이터 로직 분리 | `includes/SwInventoryService.php` |
| 설정 파일 읽기 | `includes/ItsmRepository.php` 의 `getQualifiedTableName()` |
| 외부 데이터(ITSM) 연동 자리 | `includes/ItsmRepository.php` |
| 글자색, 줄바꿈 금지 등 스타일 | 뷰의 `ZBX_STYLE_GREEN`, `ZBX_STYLE_NOWRAP` |

내보내기(CSV / Excel)는 플러그인처럼 꽂는다. 화면마다 CSV 뷰를 만들지 않는다.

1. `manifest.json` 에 `"<화면>.export": {"class": "<화면과 같은 값>", "layout": "export/layout.file"}` 를 등록한다. (`view` 없음)
2. 컨트롤러 클래스 안에 `use ExportTrait;` 를 적는다.
3. `checkInput()` 의 규칙 배열 뒤에 `+ self::exportInputFields()` 를 붙인다.
4. `doAction()` 에서 행을 만든 뒤 `if ($this->isExportRequest()) { $this->setExportResponse(제목, 파일명앞부분, 열정의, $rows); return; }` 를 넣는다.
5. 화면 뷰의 `CHtmlPage` 에 `->setControls(ExportButtons::create('<화면>.export'))` 를 단다.

Excel 파일은 제목 줄 서식/고정/필터, 테두리, 열 너비 맞춤이 적용되어 나온다. 자세한 내용은 `ui/modules/dmove/template/README.md` 의 "내보내기" 절에 있다.

5버전의 팝업 액션(`popup.windows` 등)은 아래 2.3 의 "상세 팝업" 방식으로 옮긴다.

> 위 ②③④ 의 예제는 원리를 보이려고 한국어를 코드에 바로 적었다. 실제 코드는 화면 글자를 영어로 적고 `Lang::t('...')` 로 감싼 뒤 `lang/ko.php` 에 번역을 넣는다. (2.2 ①)

---

## 2.1 지금 있는 화면과 본보기

새 화면은 아래 중 **가장 비슷한 화면을 하나 골라 복사해서 고치는 것**이 제일 빠르다. 처음부터 만들 때만 템플릿(`template/README.md`)을 쓴다.

| 화면 | 컨트롤러 / 뷰 | 이 화면에서 배울 것 |
|---|---|---|
| SW 인벤토리 | `actions/inventory/swinventory/`, `views/inventory/swinventory.list.php` | 체크박스 필터, 로직을 `includes/` 로 분리, 외부 DB(ITSM) 합치기 |
| 저사용 / 고사용 서버 | `actions/reports/serverusage/ServerUsageController.php` + `lowserver/`, `highserver/` | 비슷한 화면 두 개를 공통 컨트롤러로 묶기, 날짜 기간 필터, trends 집계 |
| 일일점검 | `actions/reports/dailycheck/`, `views/reports/dailycheck.list.php` | 버튼으로 기간 바꾸기(D-1, D-2), 숫자에 말풍선 목록, 코어 화면으로 가는 링크 |
| Peak 이벤트 분석 | `actions/reports/peakevent/`, `views/reports/peakevent.list.php` | 코어의 시간 선택기(최근 1시간, 어제 ...) |
| 윈도우즈 패치 현황 | `actions/reports/winpatch/`, `views/reports/winpatch.*.php`, `views/js/reports/winpatch.list.js.php` | 상세 팝업, 화면 전용 스크립트, 이력(history) 값 읽기 |
| Box 사용현황 | `actions/reports/hostbox/`, `views/reports/hostbox.list.php` | 아이템 마지막 값으로 표 만들기, 설정 파일에서 아이템 키 읽기, 단위 변환 |
| 서버 시간 확인, 장기 미접속 계정, CPU/Memory 추출 | `uptime/`, `users/userunconnect/`, `usageexport/` | 데이터 없이 필터와 표만 있는 껍데기. 드롭다운/라디오 필터 |

---

## 2.2 어떤 화면이든 공통으로 하는 것

템플릿을 복사했든 기존 화면을 복사했든 아래 6곳은 항상 손댄다.

| # | 파일 | 할 일 |
|---|---|---|
| ① | `manifest.json` | 액션 등록. 화면 `<이름>.list`, 내보내기 `<이름>.export`, 팝업 `<이름>.detail` |
| ② | `actions/<카테고리>/<이름>/` | 컨트롤러. `namespace Modules\DMove\Actions\<카테고리>\<이름>;` (폴더는 소문자, 네임스페이스는 대문자 시작) |
| ③ | `views/<카테고리>/<이름>.list.php` | 뷰 |
| ④ | `Module.php` | `REPORT_MENUS` 같은 표에 `'영어 라벨' => ['<이름>.list', ['<이름>.export']]` 한 줄 |
| ⑤ | `lang/ko.php` | 메뉴 라벨, 제목, 열 이름, 안내 문구의 한국어 |
| ⑥ | `docs/DEVELOPMENT_GUIDE.md` 5.1 | 화면 상태 표에 한 줄 |

코드에 적는 규칙:

- **화면 글자**: 영어로 적고 `Lang::t('Box usage')`. 코어에 이미 있는 말(`Host`, `Filter`, `Close`)은 `_('Host')` 를 쓰면 번역이 따로 필요 없다.
- **프로파일 키**: `web.dmove.<이름>.` 로 시작. (필터 저장값이 다른 화면과 섞이지 않게)
- **액션 이름**: `dmove` 를 넣지 않는다. 주소에 보인다. 코어 액션과 겹치는지 `template/README.md` 의 "액션 이름 정하기" 로 확인.
- **고객 값**(아이템 키, 태그, 테이블 이름): 코드에 적지 않고 `conf/` 파일로 뺀다. (2.3 의 "설정 파일")
- **권한**: 조회 화면은 `$this->getUserType() >= USER_TYPE_ZABBIX_USER`, 관리 화면은 `$this->checkAccess(CRoleHelper::UI_ADMINISTRATION_USERS)` 처럼.

---

## 2.3 기능별로 붙이는 법

필요한 기능의 절만 골라 읽는다. 코드 조각은 실제 파일에서 가져왔다. 더 자세한 설명은 그 파일의 주석에 있다.

### 설정 파일 (고객 값을 코드 밖으로)

고객 서버마다 다른 값(아이템 키 등)은 `conf/<이름>.conf.php` 에 두고, 비어 있으면 화면에 안내를 띄운다.
본보기: `conf/hostbox.conf.php`, `actions/reports/hostbox/HostBoxList.php`

```php
// conf/diskusage.conf.php : 배열을 return 하는 PHP 파일. 공개 저장소에는 값을 비워서 올린다.
return [
	'item_key' => ''      // TODO(고객환경) 실제 아이템 키
];
```

```php
// 컨트롤러 doAction(). dirname(__DIR__, 3) = actions/<카테고리>/<이름>/ 에서 세 단계 위 = 모듈 폴더
$config = require dirname(__DIR__, 3).'/conf/diskusage.conf.php';
$item_key = trim($config['item_key']);

$rows = $item_key !== '' ? $this->getRows($item_key) : [];
$data['config_missing'] = $item_key === '';
```

```php
// 뷰. 비어 있으면 노란 안내 상자
if ($data['config_missing']) {
	$html_page->addItem(makeMessageBox(ZBX_STYLE_MSG_WARNING, [],
		Lang::t('Settings are required: %1$s', 'conf/diskusage.conf.php'), false
	));
}
```

`includes/` 의 서비스 클래스에서 읽을 때는 `dirname(__DIR__).'/conf/...'` 다. (`includes/WinPatchService.php` 생성자)
새 설정 파일을 만들면 `docs/CUSTOMER_ENV_CHECKLIST.md` 1장에 "무엇을 어디서 확인해 채우는지" 한 줄을 추가한다.

### 호스트 그룹 / 호스트 필터

공용 부품 `includes/filter/HostFilter.php` 를 쓴다. 본보기: `HostBoxList.php`, `views/reports/hostbox.list.php`

```php
// 컨트롤러 checkInput()
$fields = [ ... ] + HostFilter::INPUT_FIELDS + self::exportInputFields();

// 컨트롤러 doAction()
if ($this->hasInput('filter_set')) {
	HostFilter::save(self::PROFILE_PREFIX, $this->getInput('filter_groupids', []), $this->getInput('filter_hostids', []));
}
elseif ($this->hasInput('filter_rst')) {
	HostFilter::reset(self::PROFILE_PREFIX);
}
$host_filter = HostFilter::load(self::PROFILE_PREFIX);

// API 조회에 조건 붙이기
$items = API::Item()->get(HostFilter::toApiOptions($host_filter) + ['output' => [...], ...]);

// 뷰로 넘기기
$data['host_filter'] = HostFilter::toViewData($host_filter);
```

```php
// 뷰
->addFilterTab(_('Filter'), [HostFilter::addRows(new CFormList(), $data['host_filter'])]);
```

### 날짜 기간 필터 (시작일 ~ 종료일)

본보기: `ServerUsageController.php` 의 `filter_from` / `filter_to`, `views/reports/serverusage.list.php` 의 `CDateSelector`.
시작 ≤ 종료, 최대 기간(366일) 검사가 컨트롤러에 있다. 기간 단위 집계(일, 월)를 하는 화면에 맞다.

### 코어 시간 선택기 (최근 1시간, 어제, 지난주 ...)

코어 보고서 화면 위쪽의 그 시간 선택기다. 본보기: `PeakEventList.php`, `views/reports/peakevent.list.php`

```php
// 컨트롤러 checkInput()
$fields = ['from' => 'range_time', 'to' => 'range_time', ...];
$ret = $this->validateInput($fields) && $this->validateTimeSelectorPeriod();

// 컨트롤러 doAction()
$options = ['profileIdx' => 'web.dmove.diskusage.filter', 'profileIdx2' => 0, 'from' => null, 'to' => null];
$this->getInputs($options, ['from', 'to']);
updateTimeSelectorPeriod($options);
$timeline = getTimeSelectorPeriod($options);   // $timeline['from_ts'], ['to_ts'] = 유닉스 초
```

뷰에는 두 가지가 필요하다. 하나라도 빠지면 기간을 눌러도 화면이 안 바뀐다.

1. `$filter->addTimeSelector($data['timeline']['from'], $data['timeline']['to'], true, $data['timeline_profile']);`
2. 뷰 맨 아래 `timeControl.addObject("diskusage", ...)` 스크립트. `peakevent.list.php` 맨 아래를 복사하고 `"peakevent"` 를 화면 이름으로 바꾼다.

### 상세 팝업 (목록에서 [상세보기] 를 누르면 뜨는 창)

5버전의 `popup.*` 액션이 여기에 해당한다. 본보기: 윈도우즈 패치 현황. 파일 4개가 엮인다.

```
목록 뷰 (winpatch.list.php)
  ├─ 링크에 표시를 단다:  ->addClass('js-winpatch-detail')->setAttribute('data-hostid', $row['hostid'])
  ├─ 스크립트를 부른다:  $this->includeJsFile('reports/winpatch.list.js.php')
  └─ 시작시킨다:         dmoveWinPatchList.init({detail_action: 'winpatch.detail', month: ...})
        │
        ▼ 링크를 누르면
스크립트 (views/js/reports/winpatch.list.js.php)
  └─ PopUp('winpatch.detail', {hostid, month}, {dialogue_class: 'modal-popup-generic', trigger_element: link})
        │
        ▼ 코어가 액션을 호출
팝업 컨트롤러 (WinPatchDetail.php)  manifest 의 layout 은 "layout.json"
  └─ 입력 검증 'hostid' => 'required|db hosts.hostid'
        │
        ▼
팝업 뷰 (winpatch.detail.php)
  └─ echo json_encode(['header' => 제목, 'body' => $table->toString(), 'buttons' => [...]])
```

주의할 것:

- 스크립트 파일 위치는 `views/js/<카테고리>/<뷰 이름>.js.php` 이고, `includeJsFile()` 에는 `views/js/` **뒤의 경로**만 적는다. (`'reports/winpatch.list.js.php'`) 뷰 파일과 같은 폴더에 두면 못 찾는다.
- 스크립트 안의 객체 이름은 `dmove` 로 시작한다. (다른 화면 스크립트와 겹치지 않게)
- 팝업 뷰는 `->show()` 를 하지 않고 JSON 만 출력한다. 값은 `CTableInfo`, `CCol` 로 조립해야 HTML 이스케이프가 된다.
- 팝업 컨트롤러에서도 호스트는 API 로 다시 조회한다. 주소의 hostid 를 바꿔 넣어도 권한 밖 호스트는 API 가 돌려주지 않는다.

### 데이터 가져오기: 무엇을 쓸까

| 필요한 값 | 방법 | 본보기 |
|---|---|---|
| 아이템의 지금 값 | `API::Item()->get(['output' => ['lastvalue', 'lastclock'], 'filter' => ['key_' => ...]])`. `lastclock` 이 0 이면 아직 수집 안 됨 | `HostBoxList::getRows()` |
| 기간 안의 원래 값(글자, 숫자) | `API::History()->get(['history' => 아이템의 value_type, ...])` | `WinPatchService::getLatestValue()` |
| 기간의 시간별 평균/최대 (며칠~몇 달) | `trends` 표 집계 SQL. 고객 DB 는 PostgreSQL 이다. (쿼리는 MySQL 문법과도 맞게 써 두었다) | `ServerUsageService::getUsage()` |
| 트리거 / 장애 건수 | `API::Trigger()->get(['tags' => ...])`, `API::Event()->get(['countOutput' => true, 'groupBy' => ['objectid']])` | `DailyCheckList::getCheckResult()`, `PeakEventList::getRows()` |
| Zabbix 밖의 표(ITSM 등) | `DBselect()` + `zbx_dbstr()`. 표 이름은 설정에서 읽고 검증한다 | `includes/ItsmRepository.php` |

API 로 되는 것은 API 를 쓴다. 사용자 권한이 자동으로 적용된다. 직접 SQL 은 집계처럼 API 로 안 되는 경우만 쓴다.

표시 가공:

- 바이트 → `convertUnits(['value' => $v, 'units' => 'B'])` (1.5 TB)
- 심각도 → `CSeverityHelper::getName($severity)`, 색은 `CSeverityHelper::getStyle($severity)`
- 값이 없을 때는 `'-'` 로 통일한다. (내보내기 파일에서도 그대로 `-` 로 나온다)

### 비슷한 화면 두 개를 한 컨트롤러로

저사용 / 고사용처럼 필터와 표가 같고 판정 조건만 다르면, 공통 부분을 `abstract` 클래스에 두고 화면별로 다른 부분만 채운다.
본보기: `ServerUsageController.php` (공통) + `LowServerList.php` (저사용, 40줄 남짓)

```php
class LowServerList extends ServerUsageController {
	protected function getPageKey(): string { return 'lowserver'; }          // 액션/프로파일/파일명에 쓰는 이름
	protected function getPageTitle(): string { return Lang::t('Low usage servers'); }
	protected function getCriteriaText(array $t): string { ... }            // 화면에 보일 판정 기준 문장
	protected function matches(float $avg, float $peak, array $t): bool {    // 목록에 넣을지
		return $avg <= $t['low_avg'] && $peak <= $t['low_peak'];
	}
}
```

두 화면이 같은 뷰를 쓰므로 manifest 의 `view` 는 둘 다 `reports/serverusage.list` 다.

### 다 만든 뒤 확인

1. `php -l` 로 문법 검사 (테스트 환경: `docker compose -f dev/compose.yaml exec web php -l /usr/share/zabbix/modules/dmove/...`)
2. 메뉴 → 화면 → 필터 적용/초기화 → 정렬 → 다음 페이지 → CSV / Excel 내보내기 순서로 눌러 본다.
3. 설정 파일을 비운 상태에서도 열어 본다. 안내 상자만 나오고 오류가 없어야 한다.
4. 화면 언어를 English / 한국어로 바꿔 둘 다 본다.
5. 일반 사용자(Zabbix User) 계정으로도 열어 본다.

---

## 3. 5버전 소스에서 그대로 쓰면 안 되는 코드

검토한 파일은 `routing.php`, `patch.sh`, `swinventory.sql`, `CControllerSwinventories.php`(1~288행) 기준이다.
보지 못한 파일(뷰, `C*` 트레잇, `C*Middle`, `function.php`, 다른 컨트롤러)은 3.4 의 점검 명령으로 확인한다.

결과가 세 가지로 나뉜다.

- **화면이 안 뜸**: 흰 화면 또는 오류 페이지
- **빨간 경고**: 화면은 뜨지만 상단에 오류 메시지가 보임. Zabbix 는 PHP 경고를 화면에 그대로 보여 준다
- **값이 틀림**: 오류 없이 잘못된 결과가 나옴. 가장 찾기 어렵다

### 3.1 Zabbix 7.0 에서 없어졌거나 바뀐 것

| 5버전 코드 | 7.0 에서 | 결과 | 바꿀 코드 |
|---|---|---|---|
| `$this->disableSIDValidation();` | 메소드가 없어짐 | 화면이 안 뜸 | `$this->disableCsrfValidation();` |
| `patch.sh` (코어 파일 덮어쓰기) | `include/menu.inc.php` 는 파일 자체가 없음. 나머지도 내용이 완전히 다름 | 프론트엔드 전체가 깨짐 | 실행 금지. 모듈로 대체 |
| `autoever/routing.php` | 코어가 이 파일을 읽지 않음 | 페이지를 찾을 수 없음 | `manifest.json` 의 `actions` |
| `'xxx.php' => ['CLegacyAction', null, null]` 라우트 | 필요 없음 | - | 삭제. `zabbix.php?action=` 만 사용 |
| `use C*;` 등 트레잇, `new C*Middle()` | 코어 오토로더가 `autoever/` 폴더를 모름 | 화면이 안 뜸 (클래스 없음) | `includes/` 에 네임스페이스 클래스로 |
| `check_fields($fields)` + `T_ZBX_STR, O_OPT, P_SYS ...` | 옛 방식 페이지 전용 | MVC 에서는 값이 `getInput` 으로 안 읽힘 | `$this->validateInput([...])` |
| `hasRequest('x')`, `getRequest('x')` | 옛 방식 페이지 전용 | 검증을 거치지 않은 값 사용 | `$this->hasInput('x')`, `$this->getInput('x')` |
| `$page['file'] = 'swinventories.php';` | 의미 없음 | - | 삭제 |
| `checkPermissions() { return true; }` | 동작은 함 | 로그인한 누구나 접근 | `$this->checkAccess(...)` |
| `swinventory` DB 뷰 (`hosts` + `interface` 조인) | 동작은 함 | 인터페이스가 여러 개면 행 중복, 템플릿 섞임, 사용자 권한 무시 | 뷰 없이 DB 직접 조회(`SwInventoryService::fetchAllHosts()`). 중복/템플릿은 고치고, 권한과 관계없이 전체를 보는 동작은 5버전대로 유지 (2026-11-23 결정) |
| 뷰에서 `CWidget`, `CTableInfo` 등 화면 클래스 | 일부 클래스 이름과 메소드가 바뀜 | 화면이 안 뜰 수 있음 | 미확인. 7.0 의 `CHtmlPage` 기준으로 새로 작성 |

7.0 에 **그대로 있는** 것: `CProfile::get/update/updateArray/deleteIdx`, `DBstart()/DBend()`, `DBselect()/DBfetch()`,
`API::Host()->get()`, `API::HostGroup()->get()`, `getSubGroups()`, `CArrayHelper::renameObjectsKeys()`, `zbx_toCSV()`.

### 3.2 PHP 8 에서 문제가 되는 문법과 동작

5버전 서버는 PHP 7.x 였을 것이고, Zabbix 7.0 는 PHP 8.0 이상이 필수다.

**(1) 선언하지 않은 프로퍼티** → 빨간 경고 (PHP 8.2+)

```php
// 5버전 (76, 139, 236, 240행)
$this->rules = $data['rules'];
$this->filter = $filter;
$this->hostall = $rowdatas;
$this->rowdatas = $rowdatas;

// 바꾼 코드: 클래스 맨 위에 선언한다
private array $rules = [];
private array $filter = [];
```

**(2) 초기화하지 않은 변수** → 빨간 경고 + 값이 틀림

```php
// 5버전 (216~233행)
foreach ($ITSM as $row) {
	$hostid = "itsm_".$row['id'];
	foreach ($hosts as $host) {
		if (strtolower($host['host']) == strtolower($row['ci_name'])) {
			$hostid = $host['hostid'];
			$host_name = $host['name'];        // 매칭됐을 때만 값이 들어간다
		}
	}
	$rowdatas[$hostid] = ['host' => $host_name, ...];   // 매칭 안 되면 앞 행의 값이 그대로 들어간다
}

// 바꾼 코드: 반복문 안에서 매번 초기화
foreach ($ITSM as $row) {
	$host_name = '';
	...
```

이것은 PHP 버전과 상관없는 5버전의 버그이기도 하다. 미설치 서버에 다른 서버의 표시명이 표시된다.

**(3) `null` 을 문자열 함수에 전달** → 빨간 경고 (PHP 8.1+)

```php
strtolower($row['ci_name'])              // ci_name 이 null 이면 경고
mb_strtolower((string) $row['ci_name'])  // 바꾼 코드
```

`strlen`, `trim`, `str_replace`, `strpos`, `explode`, `htmlspecialchars` 등 문자열 함수 전부 해당된다.

**(4) 없는 배열 키 읽기** → 빨간 경고 (PHP 8 에서 Notice 가 Warning 으로 올라감)

```php
if ($filter['filter_agent_install'] == 1)          // 키가 없으면 경고
if (!empty($filter['filter_agent_install']))       // 바꾼 코드
$value = $row['os'] ?? '';                         // 기본값을 줄 때
```

**(5) 문자열과 숫자의 느슨한 비교** → 값이 틀림 (경고 없음)

| 비교 | PHP 7 | PHP 8 |
|---|---|---|
| `0 == ''` | true | **false** |
| `0 == 'abc'` | true | **false** |
| `'1' == '01'` | true | true |
| `null == false` | true | true |

필터가 비었는지를 `== 0` 이나 `!= 0` 으로 판단한 코드는 결과가 달라진다. `isFilter()`, `filtering()` 안을 꼭 확인한다.
빈 문자열은 `=== ''`, 숫자는 `(int) $v === 0` 처럼 타입까지 비교하는 `===` 로 바꾼다.

**(6) 상대경로 파일 읽기** → 빨간 경고

```php
json_decode(file_get_contents("./autoever/conf/rules/swinventory.json"));    // 실행 위치에 따라 실패
json_decode((string) file_get_contents(dirname(__DIR__).'/conf/rules/x.json'), true);
```

5버전은 `json_decode` 의 두 번째 인자가 없어서 결과가 객체(`$rules->columns`)였다. `true` 를 주면 배열(`$rules['columns']`)이다.
옮기는 코드가 어느 쪽 문법으로 읽는지 맞춰야 한다.

검토한 범위 안에서 PHP 8 의 **구문 오류(Parse error)** 가 나는 줄은 없었다.

### 3.3 검토하지 못한 파일에서 흔히 걸리는 것

| 코드 | PHP 8 결과 | 바꿀 코드 |
|---|---|---|
| `each($arr)` | 화면이 안 뜸 (함수 삭제) | `foreach ($arr as $k => $v)` |
| `create_function(...)` | 화면이 안 뜸 | `function (...) { ... }` |
| `$str{0}`, `$arr{'k'}` | 화면이 안 뜸 (구문 오류) | `$str[0]`, `$arr['k']` |
| `mysql_query()` 등 `mysql_*` | 화면이 안 뜸 | `DBselect()` |
| `implode($arr, ',')` | 화면이 안 뜸 | `implode(',', $arr)` |
| `count(null)`, `count('문자열')` | 화면이 안 뜸 (TypeError) | `count($x ?? [])` 또는 `is_array` 확인 |
| `$a ? $b : $c ? $d : $e` | 화면이 안 뜸 (구문 오류) | 괄호로 묶기 |
| `function f($a = 1, $b)` | 빨간 경고 | 필수 인자를 앞으로 |
| `utf8_encode()`, `utf8_decode()` | 빨간 경고 (8.2+) | `mb_convert_encoding()` |
| `"${var}"` | 빨간 경고 (8.2+) | `"{$var}"` |
| `in_array('abc', [0])` | 값이 틀림 (true → false) | 세 번째 인자 `true` 를 주고 타입 맞추기 |
| `switch` 에서 문자열과 숫자 `case` 혼용 | 값이 틀림 | 타입 통일 |

### 3.4 고객환경에서 돌릴 점검 명령

5버전 `autoever/` 폴더에서 실행한다. 아무것도 안 나오면 해당 항목은 없는 것이다.

```bash
cd /usr/share/zabbix/dmove

# 삭제된 함수 (화면이 안 뜸)
grep -rnE '\b(each|create_function|money_format|ereg|eregi|split|mysql_[a-z_]+|mcrypt_[a-z_]+|get_magic_quotes_gpc)\s*\(' --include='*.php' .

# 중괄호 접근 $a{0} (구문 오류)
grep -rnE '\$[A-Za-z_][A-Za-z0-9_]*(\[[^]]*\])*\{[^}]+\}' --include='*.php' .

# 7.0 에서 없어졌거나 모듈에서 쓰지 않는 것
grep -rnE 'disableSIDValidation|menu\.inc|CLegacyAction|check_fields|getRequest\(|hasRequest\(' --include='*.php' .

# 선언이 필요한 프로퍼티 후보
grep -rhoE '\$this->[A-Za-z_]+\s*=[^=]' --include='*.php' . | sed -E 's/\s*=.*//' | sort -u

# 느슨한 비교 (값이 틀려질 수 있는 곳)
grep -rnE "(==|!=)\s*(0|''|\"\")\s*[);&|]" --include='*.php' .

# 상대경로 파일 접근
grep -rnE "(file_get_contents|fopen|include|require)[^;]*['\"]\./" --include='*.php' .

# 요청값 직접 접근, 직접 SQL (보안 점검)
grep -rnE '\$_(GET|POST|REQUEST)\[|DBselect\(|DBexecute\(' --include='*.php' .

# PHP 8 서버에서 실제 문법 검사
find . -name '*.php' -exec php -l {} \; | grep -v 'No syntax errors'
```

---

## 4. 5버전 코드를 옮길 때 변환표

5버전 컨트롤러를 열어 놓고 위에서부터 이 표대로 바꾼다.

### 파일 머리

| 5버전 | 7.0 모듈 |
|---|---|
| `class CControllerSwinventories extends CController {` | 파일 맨 위에 `namespace Modules\DMove\Actions\<화면>;` 과 `use CController;` 등을 추가. 클래스 이름은 `SwInventoryList` 처럼 짧게 |
| `use C*; use C*Data; ...` (트레잇) | `includes/` 의 클래스로 옮기고 `new SwInventoryService()` 처럼 객체로 사용 |
| `private $host_agent_check = [];` | `private array $host_agent_check = [];` (타입 선언) |

### 입력

| 5버전 | 7.0 모듈 |
|---|---|
| `'groupids' => [T_ZBX_INT, O_OPT, P_SYS, DB_ID, null]` | `'groupids' => 'array_db hosts_groups.groupid'` |
| `'hostid' => [T_ZBX_INT, O_OPT, P_SYS, DB_ID, null]` | `'hostid' => 'db hosts.hostid'` |
| `'filter_set' => [T_ZBX_STR, O_OPT, P_SYS, null, null]` | `'filter_set' => 'in 1'` |
| `'filter_os' => [T_ZBX_STR, O_OPT, null, null, null]` | `'filter_os' => 'string'` |
| `'filter_exact' => [T_ZBX_INT, O_OPT, null, 'IN(0,1)', null]` | `'filter_exact' => 'in 0,1'` |
| `'sort' => [..., IN('"name","osname"'), null]` | `'sort' => 'in name,osname'` |
| `'sortorder' => [..., IN('"'.ZBX_SORT_DOWN.'","'.ZBX_SORT_UP.'"'), null]` | `'sortorder' => 'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN` |
| `check_fields($fields); return true;` | `$ret = $this->validateInput($fields);` 후 실패 시 `setResponse(new CControllerResponseFatal())` |
| `hasRequest('filter_set')` | `$this->hasInput('filter_set')` |
| `getRequest('filter_os')` | `$this->getInput('filter_os', '')` |
| `getRequest('groupids', [])` | `$this->getInput('groupids', [])` |

### 처리

| 5버전 | 7.0 모듈 |
|---|---|
| `$this->disableSIDValidation();` | `$this->disableCsrfValidation();` |
| `protected function doAction() {` | `protected function doAction(): void {` |
| `CProfile::update(...)`, `CProfile::get(...)`, `CProfile::deleteIdx(...)` | 그대로. 키만 `web.dmove.<화면>.` 로 시작하게 |
| `DBstart(); ... DBend();` (프로파일 삭제를 감싼 것) | 빼도 된다. 코어가 요청 끝에 프로파일을 저장한다 |
| `API::Host()->get([...])` | 그대로 |
| `getSubGroups($groupids, $ms_data)` | 그대로 |
| `$page['file'] = '...';` | 삭제 |
| `"./autoever/conf/rules/x.json"` | `dirname(__DIR__).'/conf/rules/x.json'` |
| `$this->rowdatas = ...; $Middle = new C*Middle($this); $Middle->before();` | 컨트롤러를 통째로 넘기지 말고 `$rows = $service->getRows();` 처럼 값을 주고받는다 |
| `$data` 를 만들어 뷰로 넘기는 마지막 부분 | `$this->setResponse(new CControllerResponseData($data));` |

### 뷰

| 5버전 | 7.0 모듈 |
|---|---|
| `(new CWidget())->setTitle(...)->addItem(...)->show();` | `(new CHtmlPage())->setTitle(...)->addItem(...)->show();` |
| 필터 폼을 직접 조립 | `CFilter` + `CFormList` (2장 ③ 참고) |
| `$data['rules']->columns` (객체) | `$data['rules']['columns']` (배열. `json_decode(..., true)` 를 쓴 경우) |
| HTML 문자열을 `echo` | `CSpan`, `CLink`, `CCol` 등 클래스로 조립 (자동 이스케이프) |

5버전 뷰는 검토하지 못해서 이 부분은 일반적인 차이만 적었다. 옮길 때 7.0 의 `ui/app/views/reports.actionlog.list.php` 를 옆에 두고 맞춘다.

---

## 5. 남은 화면과 진행 순서

5버전 `routing.php` 에 있던 액션 기준이다.

| 5버전 액션 | 화면 | 7.0 상태 |
|---|---|---|
| `swinventories`, `swinventories.view.csv` | SW 인벤토리 | 완성. Agent "예외" 판정만 비어 있음 |
| `lowserver`, `lowserver.view.csv` | 저사용 서버 | 완성. [기준설정] 버튼은 설정 파일로 대체 |
| `highserver`, `highserver.view.csv`, `popup.agent` | 고사용 서버 | 완성. 판정 조건은 가정 |
| `windows`, `windows.view.csv`, `popup.windows` | 윈도우즈 패치 현황 | 완성 (상세 팝업 포함) |
| `popup.windows.all`, `windowsall`, `windowsall.view.csv` | 윈도우 전체 | 미착수. 필요 여부 확인 |
| `hostbox` | Box 사용현황 | 완성. 메뉴 위치는 가정 |
| `hostesxi` | ESXi | 미착수. 소스 없음 |
| `daily` | 일일점검 | 완성 |
| `popup.topcpu`, `popup.topmemory` | 일일점검의 Top 10 버튼 | 미착수. 소스 없음 |
| `popup.batch` | 배치 팝업 | 미착수. 7버전에서는 일별 집계표(`extends_trends_day`) 없이 `trends` 를 바로 집계하므로 필요 없을 가능성이 크다 |
| `uptime.view` | 서버 시간 확인 | 껍데기 (필터와 표만) |
| `user.unconnect` | 장기 미접속 계정 | 껍데기 |
| `perf.analysis.view` | CPU/Memory 사용률 추출 | 껍데기 |
| `data.analysis` | 성능 데이터 추세 분석 | 빈 페이지. 대시보드 위젯으로 대체할지 결정 필요 |
| (peak_event.php) | Peak 이벤트 분석 | 완성. 집계 기준은 가정 |

화면별 최신 상태는 `DEVELOPMENT_GUIDE.md` 5.1, 고객환경에서 확인할 것은 `CUSTOMER_ENV_CHECKLIST.md` 에 있다.

고객환경 첫날 순서:

1. 모듈 폴더를 서버에 올리고 활성화한다 (`DEVELOPMENT_GUIDE.md` 3장).
2. `find /usr/share/zabbix/modules/dmove -name '*.php' -exec php -l {} \;` 로 문법을 확인한다.
3. 메뉴의 화면을 하나씩 연다. 이 시점에는 "설정이 필요합니다: conf/..." 안내가 보이는 것이 정상이다.
4. `CUSTOMER_ENV_CHECKLIST.md` 1장을 따라 `conf/` 의 설정 파일을 채운다.
5. 5버전 화면과 7버전 화면을 같은 조건으로 열어(또는 CSV 를 뽑아) 행 수와 값을 비교한다. (체크리스트 2장)
6. 껍데기 화면은 5버전 소스를 보고 2.3 의 방법으로 채운다.

---

## 6. 오류가 났을 때

### 메뉴가 안 보인다

| 확인 | 조치 |
|---|---|
| 관리 > 일반 > 모듈 목록에 `DMove Custom` 이 없다 | [디렉토리 스캔] 을 누른다. 그래도 없으면 폴더 위치(`modules/dmove/manifest.json`)와 JSON 문법을 확인 |
| 목록에 있지만 비활성이다 | 활성으로 바꾼다 |
| 특정 사용자만 안 보인다 | 사용자 > 사용자 역할에서 그 역할의 모듈 접근에 체크 |
| 관리자에게도 안 보인다 | `Module.php` 의 `init()` 에 메뉴 추가 코드가 있는지, `findOrAdd` 의 라벨이 맞는지 확인 |

### 화면 상단 메시지별 원인

| 메시지 | 원인 | 조치 |
|---|---|---|
| `Cannot load module at: modules/dmove.` | `manifest.json` 을 읽지 못함 | JSON 문법(쉼표, 따옴표), 필수 키 5개, 파일 권한 확인 |
| `Wrong Module.php class name for module located at ...` | `Module.php` 의 네임스페이스나 클래스명이 틀림 | `namespace Modules\DMove;` 와 `class Module extends CModule` 확인 |
| `Module.php class must extend ...` | 부모 클래스가 틀림 | `use Zabbix\Core\CModule;` 확인 |
| `... - thrown by module located at ...` | `init()` 안에서 예외 발생 | 메시지 앞부분이 실제 오류다. 보통 `use` 선언 누락 |
| `Identical actions are used by modules located at ...` | 다른 모듈과 액션명이 겹침 | 겹치지 않는 이름으로 바꾼다. 주소에 보이므로 `dmove` 는 넣지 않는다 |
| `Page not found` | 등록되지 않은 액션 | 주소의 `action` 값과 `manifest.json` 의 키가 같은지 확인 |
| `Class ... not found for action ...` | 컨트롤러 파일을 못 찾음 | 파일이 `actions/<카테고리>/<화면>/` 에 있는지, manifest 의 `class` 가 `카테고리/화면/클래스` 형식인지, 파일명과 클래스명과 manifest 의 `class` 가 대소문자까지 같은지, `namespace Modules\DMove\Actions;` 인지 확인 |
| `Action class ... must extend CController class.` | 컨트롤러가 `CController` 를 상속하지 않음 | `use CController;` 와 `extends CController` 확인 |
| `Access denied` 화면 | `checkPermissions()` 가 `true` 가 아님 | 로그인한 역할의 권한 확인 |
| `Class "Modules\DMove\Actions\CProfile" not found` 같은 오류 | 코어 클래스의 `use` 선언 누락 | 파일 위쪽에 `use CProfile;` 추가 |
| `Undefined variable $xxx` | 값을 넣기 전에 읽음 | 3.2 (2) |
| `Undefined array key "xxx"` | 없는 키를 읽음 | 3.2 (4). 뷰라면 컨트롤러 `$data` 에 그 키를 넣었는지 확인 |
| `Creation of dynamic property ... is deprecated` | 선언 안 한 프로퍼티 | 3.2 (1) |
| `Passing null to parameter ... is deprecated` | `null` 을 문자열 함수에 전달 | 3.2 (3) |

### 메시지 없이 이상할 때

| 증상 | 원인 | 조치 |
|---|---|---|
| 흰 화면 | PHP 치명 오류 | 웹서버/PHP-FPM 오류 로그 확인. `php -l 파일` 로 문법 검사 |
| 필터에 넣은 값이 적용되지 않는다 | 입력창 이름이 `checkInput()` 에 없음 | 뷰의 `filter_xxx` 와 컨트롤러 키를 맞춘다 |
| [적용] 을 누르면 다른 화면으로 간다 | `CFilter` 에 `addVar('action', ...)` 누락 | 추가 |
| 정렬 헤더를 눌러도 순서가 그대로다 | 컨트롤러가 `sort`, `sortorder` 로 정렬하지 않음 | `make_sorting_header` 는 링크만 만든다. 정렬은 직접 한다 |
| 목록이 1000건에서 끊긴다 | 코어의 검색 결과 제한 | 정상. 전체는 CSV 로 받거나 관리 > 일반 > GUI 에서 제한을 조정 |
| CSV 한글이 엑셀에서 깨진다 | BOM 없음 | `includes/export/CsvWriter.php` 의 `WITH_BOM` 이 `true` 인지 확인. 또는 Excel 내보내기를 쓴다 |
| 메뉴는 있는데 선택 표시가 안 된다 | 부속 액션이 `setAliases` 에 없음 | `Module.php` 에 추가 |
| CSS 가 적용되지 않는다 | 브라우저 캐시 또는 `manifest.json` 의 `assets` 누락 | 강력 새로고침(Ctrl+F5), `assets.css` 확인 |
| 서버에서만 클래스를 못 찾는다 | 리눅스는 대소문자를 구분 | 폴더는 소문자, 파일명은 클래스명과 똑같이 |

---

## 7. 용어

| 용어 | 뜻 |
|---|---|
| 모듈 | `ui/modules/` 아래 폴더 하나로 된 확장 단위. 관리 화면에서 켜고 끈다 |
| 매니페스트 | `manifest.json`. 모듈의 이름, 네임스페이스, 액션, 정적 파일을 적는 파일 |
| 액션 | 주소의 `action=` 값. 화면 하나 또는 요청 하나에 해당한다 |
| 컨트롤러 | 액션을 처리하는 PHP 클래스. 입력 검증, 권한 확인, 데이터 준비를 한다 |
| 뷰 | 컨트롤러가 준 데이터를 화면(HTML)이나 CSV 로 출력하는 PHP 파일 |
| 레이아웃 | 뷰의 출력을 감싸는 겉틀. 사이드바가 있는 일반 화면, CSV, JSON 이 있다 |
| 네임스페이스 | 클래스 이름 앞에 붙는 묶음 이름. 폴더 위치와 대응된다 (`Modules\DMove\Actions` ↔ `modules/dmove/actions`) |
| `use` | 다른 네임스페이스의 클래스를 이 파일에서 짧은 이름으로 쓰겠다는 선언 |
| 오토로더 | 클래스 이름으로 파일을 찾아 자동으로 읽어 주는 장치. `require` 를 직접 쓰지 않아도 된다 |
| 프로파일 | 사용자별 저장값(DB `profiles` 테이블). 필터와 정렬 상태를 기억하는 데 쓴다 |
| 사용자 역할 | 사용자에게 화면, 모듈, API 권한을 묶어 주는 단위. 5버전의 사용자 유형보다 세분화됐다 |
| 옛 방식 페이지 | `hostinventories.php` 처럼 파일 하나가 화면 하나인 예전 구조. 7.0 에도 일부 남아 있다 |
| CSRF 검사 | 다른 사이트가 사용자 몰래 요청을 보내는 것을 막는 토큰 검사. 조회 화면에서만 끈다 |
| `[DM 해설]` | 이 저장소의 코어 파일에 달아 둔 한국어 해설 주석의 표시 |
| `TODO(고객환경)` | 모듈 코드에서 고객환경의 실제 정보를 넣어야 하는 자리의 표시 |
