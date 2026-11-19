# 새 페이지 추가 방법 (템플릿)

이 폴더의 파일을 복사해서 이름만 바꾸면 **필터 + 정렬 + 페이징 + 내보내기(CSV / Excel)** 가 되는 화면이 바로 뜬다.
그 다음 화면에 맞게 내용을 고친다. 코어 파일은 건드리지 않는다.

`template/` 폴더의 파일은 그대로는 실행되지 않는다. (`manifest.json` 에 등록되지 않았고 위치도 다르다)

## 폴더 구조

```
ui/modules/dmove/
├── manifest.json                 액션(URL) 등록표
├── Module.php                    사이드바 메뉴 등록
├── actions/                      컨트롤러. 카테고리(사이드바 1단계 메뉴) > 화면 순서로 폴더를 둔다
│   ├── inventory/swinventory/SwInventoryList.php
│   ├── reports/uptime/UptimeList.php
│   ├── users/userunconnect/UserUnconnectList.php
│   └── <카테고리>/<화면이름>/<화면이름>List.php
├── views/                        뷰. 사이드바 1단계 메뉴(카테고리)마다 폴더 하나
│   ├── inventory/                인벤토리 아래 화면
│   ├── reports/                  보고서 아래 화면
│   │   └── <화면이름>.list.php
│   └── users/                    유저 아래 화면
│   └── export/layout.file.php    내보내기 공통 레이아웃 (고칠 일 없음)
├── includes/                     여러 화면이 같이 쓰는 클래스 (Lang ...)
│   └── export/                   내보내기 플러그인 (ExportTrait, ExportButtons, XlsxWriter, CsvWriter)
├── lang/ko.php                   한국어 번역 표
├── conf/                         설정 파일
├── assets/css/dmove.css          모듈 공통 CSS
└── template/                     ★ 이 폴더 (복사용)
```

## 예시: "디스크 현황" 화면을 추가한다

화면 이름을 정한다. 영문 소문자 한 단어로 짓는다. 여기서는 `diskusage`, 클래스 접두어는 `DiskUsage`.
보고서 아래에 넣을 화면이므로 컨트롤러는 `actions/reports/`, 뷰는 `views/reports/` 에 둔다.
인벤토리면 `inventory`, 유저면 `users`, 새 카테고리면 영문 소문자로 폴더를 만든다. 보고서가 아닌 곳에 넣을 때는 아래 명령과 코드의 `reports` / `Reports` 를 그 이름으로 바꾼다.

### 1. 파일 2개를 복사한다

`ui/modules/dmove` 폴더에서 실행한다.

```bash
mkdir -p actions/reports/diskusage
cp template/actions/sample/SampleList.php       actions/reports/diskusage/DiskUsageList.php
cp template/views/sample.list.php         views/reports/diskusage.list.php
```

### 2. 복사한 파일 안의 이름을 바꾼다

| 찾을 글자 | 바꿀 글자 | 나오는 곳 |
|---|---|---|
| `Sample` | `DiskUsage` | 네임스페이스, 클래스 이름 |
| `sample` | `diskusage` | 액션 이름, 프로파일 키, 내보내기 파일명 |

한 번에 바꾸는 명령:

```bash
sed -i 's/Sample/DiskUsage/g; s/sample/diskusage/g' \
    actions/reports/diskusage/DiskUsageList.php views/reports/diskusage.list.php
```

바꾼 뒤 컨트롤러 맨 위가 아래와 같으면 맞다. **폴더는 소문자, 네임스페이스와 클래스는 대문자로 시작한다.**

```php
namespace Modules\DMove\Actions\Reports\DiskUsage;      // 폴더 actions/reports/diskusage
class DiskUsageList extends CController {        // 파일 DiskUsageList.php
```

### 3. `manifest.json` 의 `"actions"` 에 등록한다

마지막 항목 뒤에 쉼표를 붙이고 추가한다. JSON 은 쉼표 하나만 틀려도 모듈 전체가 안 뜬다.

```json
"diskusage.list": {
	"class": "Reports/DiskUsage/DiskUsageList",
	"view": "reports/diskusage.list",
	"layout": "layout.htmlpage"
},
"diskusage.export": {
	"class": "Reports/DiskUsage/DiskUsageList",
	"layout": "export/layout.file"
}
```

| 키 | 뜻 |
|---|---|
| `"diskusage.list"` | 액션 이름. 주소는 `zabbix.php?action=diskusage.list`. 모듈 이름은 넣지 않는다(주소에 노출됨). 코어 액션과 겹치면 안 된다 → 아래 "액션 이름 정하기" |
| `class` | `카테고리/화면/클래스`. 폴더 부분도 대문자로 시작해 적는다 → `actions/reports/diskusage/DiskUsageList.php` |
| `view` | `카테고리 폴더/뷰 파일 이름`(`.php` 제외) → `views/reports/diskusage.list.php` |
| `layout` | 일반 화면은 `layout.htmlpage`, JSON 응답은 `layout.json` |
| `"diskusage.export"` | 내보내기 액션. 이름은 반드시 `.export` 로 끝나야 한다. `view` 는 적지 않고 `layout` 은 항상 `export/layout.file` |

내보내기가 필요 없는 화면이면 `.export` 항목을 넣지 않고, 컨트롤러와 뷰에서 `[내보내기]` 표시가 있는 부분을 지운다.

#### 액션 이름 정하기

액션 이름은 주소(`zabbix.php?action=...`)에 그대로 보이므로 모듈 이름(dmove)을 넣지 않고 `<화면이름>.list` 형식으로 짓는다.
대신 **코어에 같은 이름의 액션이 있으면 코어 화면을 덮어쓰게 되므로**, 새 이름을 정할 때 아래 명령으로 확인한다.
아무것도 안 나오면 써도 된다. (`ui` 폴더에서 실행)

```bash
grep -n "'diskusage\." include/classes/mvc/CRouter.php
```

`host.`, `user.`, `item.`, `report.` 처럼 코어가 이미 쓰는 앞 단어는 피한다. Zabbix 를 업그레이드할 때도 이 확인을 다시 한다.

### 4. `Module.php` 에 메뉴를 한 줄 추가한다

보고서 아래에 넣으려면 `REPORT_MENUS` 에 추가한다. 라벨은 영어로 적는다.

```php
'Disk usage' => ['diskusage.list', ['diskusage.export']],
```

### 5. `lang/ko.php` 에 한국어 문구를 추가한다

```php
'Disk usage' => '디스크 현황',
```

컨트롤러의 `'title' => Lang::t('Sample page')` 도 `Lang::t('Disk usage')` 로 바꾼다.

### 6. 브라우저에서 확인한다

저장하고 새로고침하면 메뉴가 보인다. 모듈을 껐다 켤 필요는 없다.
이 상태에서 호스트 목록이 나오면 뼈대는 끝난 것이다.

### 7. 내용을 채운다

컨트롤러와 뷰에 `[바꿀 곳]` 표시가 있다.

| 표시 | 파일 | 할 일 |
|---|---|---|
| ① `SORT_FIELDS` | 컨트롤러 | 정렬할 컬럼 키 |
| ② `TEXT_FILTERS` | 컨트롤러 | 텍스트 필터 (필터 키 => 비교할 컬럼) |
| ③ `checkPermissions()` | 컨트롤러 | 누가 볼 수 있는지 |
| ④ `title` | 컨트롤러 | 화면 제목 |
| ⑤ `getRows()` | 컨트롤러 | 데이터 조회. 행 배열을 돌려준다 |
| ⑥ `getColumns()` | 컨트롤러 | 열 정의(키 => 제목). 화면 제목 줄과 내보내기 파일이 같이 쓴다 |
| 필터 입력창 | 뷰 | ② 와 같은 이름으로 입력창 추가 |
| 표 제목/값 | 뷰 | 열 추가 |

## 내보내기(CSV / Excel)를 기존 화면에 꽂기

템플릿으로 만든 화면에는 이미 들어 있다. 껍데기 화면처럼 내보내기가 없는 화면에 나중에 붙일 때는 아래 5곳을 추가한다.
화면마다 CSV 뷰 파일을 만들 필요는 없다. (자세한 설명: `includes/export/ExportTrait.php` 머리말)

| # | 파일 | 추가할 것 |
|---|---|---|
| ① | `manifest.json` | `"<화면>.export": {"class": "<화면과 같은 값>", "layout": "export/layout.file"}` |
| ② | 컨트롤러 | 위쪽에 `use Modules\DMove\Includes\Export\ExportTrait;`, 클래스 안 첫 줄에 `use ExportTrait;` |
| ③ | 컨트롤러 `checkInput()` | `$fields = [ ... ] + self::exportInputFields();` |
| ④ | 컨트롤러 `doAction()` | 행을 만든 뒤 `if ($this->isExportRequest()) { $this->setExportResponse('제목', '파일명앞부분', $열정의, $rows); return; }` |
| ⑤ | 뷰 | `->setControls(ExportButtons::create('<화면>.export'))` 와 위쪽에 `use Modules\DMove\Includes\Export\ExportButtons;` |

Excel 파일은 이렇게 나온다.

- 제목 줄: 굵은 흰 글씨 + 진한 파란 배경, 스크롤해도 고정, 필터 버튼(▼)
- 모든 칸에 얇은 테두리, 열 너비는 내용 길이에 맞춤(긴 열은 줄바꿈)
- 값은 텍스트로 들어간다. IP, 앞자리 0, 긴 숫자가 그대로 보이고 `=` 로 시작하는 값이 수식으로 실행되지 않는다
- 합계를 낼 숫자 열은 열 정의를 `'used' => ['label' => '사용량', 'type' => 'number']` 로 적는다
- 색, 글꼴, 열 너비 한계는 `includes/export/XlsxWriter.php` 맨 위 상수에서 바꾼다

메뉴 표(`Module.php`)의 두 번째 값에 `.export` 액션을 적어 두면 내보내기 중에도 메뉴 선택 표시가 유지된다.

## 자주 나는 오류

| 증상 | 원인 |
|---|---|
| 모듈 메뉴가 전부 사라짐 | `manifest.json` 문법 오류(쉼표, 따옴표). 관리 > 일반 > 모듈 화면에 오류가 표시된다 |
| `Page not found` | `manifest.json` 의 액션 이름과 메뉴의 액션 이름이 다르다 |
| `Class "Modules\DMove\Actions\..." not found` | 폴더/파일 이름과 `namespace`/`class` 가 다르다. 리눅스는 대소문자를 구분한다 |
| `Class "Modules\DMove\Actions\Xxx\CProfile" not found` | 컨트롤러 위쪽에 `use CProfile;` 이 빠졌다 |
| 화면 위에 빨간 `Undefined array key` | 뷰가 읽는 `$data['키']` 또는 `$row['키']` 를 컨트롤러가 안 넘겼다 |
| 필터가 적용되지 않음 | 입력창 이름(`filter_xxx`)이 `checkInput()` 에 없다 |
| 내보내기 버튼을 누르면 `Page not found` | `manifest.json` 에 `<화면>.export` 액션이 없거나 이름이 `.export` 로 끝나지 않는다 |
| 내보내기 파일에 열은 있는데 값이 비어 있음 | 열 정의의 키가 행 배열의 키와 다르다 |
| 한국어로 안 바뀜 | `lang/ko.php` 의 영어 원문이 코드의 `Lang::t('...')` 와 글자가 다르다 |

## 참고할 구현

| 필요한 것 | 볼 파일 |
|---|---|
| 호스트 그룹/호스트 선택창 필터 | `actions/reports/uptime/UptimeList.php`, `views/reports/uptime.list.php` |
| 라디오 버튼, 드롭다운 필터 | `views/users/userunconnect.list.php` |
| 체크박스 필터, 데이터 로직을 `includes/` 로 분리 | `actions/inventory/swinventory/`, `includes/SwInventoryService.php` |
| DB 직접 조회(안전한 방법) | `includes/ItsmRepository.php` |
