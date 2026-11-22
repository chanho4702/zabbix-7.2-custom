# CPU/Memory 전체 사용률 추출 (`usageexport.list`)

## 1. 한눈에

| 항목 | 내용 |
|---|---|
| 메뉴 위치 | 보고서 > CPU/Memory 전체 사용률 추출 (`Module.php` 의 `REPORT_MENUS`, 윈도우즈 패치 현황 다음) |
| 주소 | `zabbix.php?action=usageexport.list` |
| 상태 | **껍데기**. 필터만 있고 추출 기능이 없다. 화면에 "데이터 조회가 아직 구현되지 않았습니다." 안내가 뜬다 |
| 5버전 액션 | `perf.analysis.view` (화면 제목 "CPU/Mem 사용률 추출") |
| 내보내기 | 이 화면의 목적이 내보내기지만 **아직 없음**. `manifest.json` 에 `usageexport.export` 액션이 없고, 뷰의 버튼 코드는 주석 처리되어 있다 |
| 권한 | 로그인한 사용자 중 게스트가 아닌 사람(`getUserType() >= USER_TYPE_ZABBIX_USER` 이고 `!CWebUser::isGuest()`). 5버전과 같게 둠 |

## 2. 이 화면은 무엇을 하나

선택한 서버들의 CPU 사용률과 메모리 사용률 원자료를 파일로 한꺼번에 받는 화면이다. 화면에 표는 없다.
운영/용량 담당자가 받은 파일을 엑셀에서 가공해 월간 보고서, 증설 검토 자료를 만드는 데 쓴다.
"업무시간(09~18시)만" 또는 "전체 시간"을 고를 수 있고, CPU 와 메모리 중 하나만 받을 수도 있다.

## 3. 화면 구성

### 필터

| 이름 (한국어 화면) | 입력 방식 | 기본값 | 저장 여부 |
|---|---|---|---|
| 호스트 그룹 | 선택창(멀티셀렉트) + [선택] 팝업. 하위 그룹 포함 | 비어 있음(전체) | 저장 (`web.dmove.usageexport.filter.groupids`) |
| 호스트 | 선택창(멀티셀렉트) + [선택] 팝업 | 비어 있음(전체) | 저장 (`...filter.hostids`) |
| 항목 | 버튼 모양 라디오 3개: `CPU Util & Mem Util` / `CPU Util only` / `Mem Util only` (값 0/1/2) | `CPU Util & Mem Util`(0) | 저장 (`...filter.items`) |
| 시간 | 버튼 모양 라디오 2개: 업무시간 (09:00 ~ 18:00) / 전체사용현황 (값 0/1) | 업무시간(0) | 저장 (`...filter.time`) |

필터 아래에 빨간 안내문 2줄이 있다(5버전 문구를 그대로 옮김).

- `※실시간 Data 출력은 전일 ~ 3개월 기간 출력이 가능합니다.`
- `※다량의 Data export시 1분 이상 소요될 수 있습니다.`

(영어 화면에서는 `lang/ko.php` 의 영어 원문이 보인다.) 기간(날짜) 입력은 없다. 5버전 화면에도 없었다.

### 표의 열

없다. 결과는 파일로만 받는 화면이다.

### 버튼

- [적용] [리셋]: 필터 저장/초기화만 한다.
- 내보내기 버튼: 아직 없다. 뷰에 `ExportButtons::create('usageexport.export', [ExportFormat::CSV])` 가 주석으로 들어 있다.

### 텍스트 스케치

```
CPU/Memory 전체 사용률 추출                         (내보내기 버튼 자리 - 아직 없음)
┌ 필터 ──────────────────────────────────────────────────────────────┐
│ 호스트 그룹 [검색 문자열을 입력      ] [선택]                      │
│ 호스트      [검색 문자열을 입력      ] [선택]                      │
│ 항목        [CPU Util & Mem Util|CPU Util only|Mem Util only]      │
│ 시간        [업무시간 (09:00 ~ 18:00)|전체사용현황]                │
│             ※실시간 Data 출력은 ... (빨간 글자)                    │
│             ※다량의 Data export시 ... (빨간 글자)                  │
│ [적용] [리셋]                                                      │
└────────────────────────────────────────────────────────────────────┘
(노란 안내) 데이터 조회가 아직 구현되지 않았습니다.
```

## 4. 어떻게 만들었나 (동작 순서)

1. **CSRF 검사 끄기** - `UsageExportList::init()` 에서 `disableCsrfValidation()`.
2. **입력 검증** - `UsageExportList::checkInput()`
   ```php
   'filter_items' => 'in '.implode(',', [self::ITEMS_CPU_MEM, self::ITEMS_CPU, self::ITEMS_MEM]),
   'filter_time' => 'in '.implode(',', [self::TIME_BUSINESS, self::TIME_ALL])
   ```
   항목은 0, 1, 2, 시간은 0, 1 만 받는다. 여기에 `HostFilter::INPUT_FIELDS` 를 더한다. `page` 는 없다(표가 없으므로).
3. **권한** - `UsageExportList::checkPermissions()`: `getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest()` (게스트 계정 제외).
4. **필터 저장/초기화** - `doAction()` 1번 블록. `HostFilter::save()`, 항목/시간은 `CProfile::update(..., PROFILE_TYPE_INT)`. [리셋]은 `HostFilter::reset()` 과 `CProfile::deleteIdx()`.
5. **뷰로 넘기기** - `HostFilter::load()` / `toViewData()`, 라디오 선택지(`items_options`, `time_options`), `'data_ready' => false`. **데이터를 만드는 함수 자체가 아직 없다.**
6. **화면** - `views/reports/usageexport.list.php`. `HostFilter::addRows()` 로 두 줄, `CRadioButtonList(...)->setModern(true)` 로 라디오, 라벨 없는 줄(`addRow('', [...])`)에 `ZBX_STYLE_RED` 안내문 2줄. `data_ready` 가 `false` 면 노란 안내 상자.

## 5. 5버전과 비교

| 주제 | 5버전 | 7버전 | 왜 |
|---|---|---|---|
| 필터 | 호스트 그룹, 호스트, 항목(3개), 시간(2개), 빨간 안내 2줄 | 같다 | 그대로 옮김 |
| 결과 | 화면 표 없이 CSV 만. 한 번 실행에 `export_<날짜시각><순번>.csv` 파일이 여러 개(확인된 것은 3개) 내려왔다 | 없음 | 미구현 |
| 기간 | 화면에 날짜 입력이 없고 안내문에 "전일 ~ 3개월"만 있다. 실제 기간을 정하는 방법은 확인 필요 | 없음 | 5버전 컨트롤러 확인 필요 |
| 파일 여러 개 | 한 번에 여러 파일 | 브라우저는 요청 한 번에 파일 하나만 받으므로 같은 방식은 어렵다. 버튼을 파일 수만큼 두거나(권장) 한 파일에 합친다(컨트롤러 머리말) | 7버전 모듈의 내보내기 구조 |
| 집계 방식 | 확인 필요 (history 인지 trends 인지, 시간/일 단위인지) | 없음. trends 우선 검토로 적어 둠 | 데이터 양 |

## 6. 설정

지금은 없음.
구현하면 아이템 키 설정이 필요하다. CPU 키는 저사용/고사용 화면의 `conf/serverusage.conf.php` 의 `cpu_item_keys` 를 같이 쓸 수 있다. 메모리 사용률 키 설정은 아직 어디에도 없다(5버전 키 클래스 `...Key.php` 의 `serverMemoryUsage()` 에 있다고 정리되어 있다). 업무시간/업무일도 같은 파일의 `business_hours`, `business_days` 를 쓰는 것이 자연스럽다.

## 7. 비어 있는 부분과 가정

| 자리 | 내용 | 확인할 5버전 파일 |
|---|---|---|
| `TODO(고객환경)` 추출 기능 전체 | 데이터 조회, 파일 만들기 | `perf.analysis.view` 액션의 컨트롤러와 뷰 |
| 기간을 정하는 방법 | 화면에 날짜 입력이 없다. "전일 ~ 3개월"의 뜻(어제 하루인지, 어제까지 3개월인지) | 위와 같다 |
| CSV 의 열 구성 | 호스트별/시간별/일별 중 어떤 행인지, 평균/최대 중 무엇인지 | 위와 같다 |
| 파일이 여러 개로 나뉘는 기준 | 항목별인지, 행 수 제한인지 | 위와 같다 |
| 메모리 아이템 키 | OS 별로 다를 수 있다 | 키 클래스의 `serverMemoryUsage()` |
| `TODO(고객환경)` 내보내기 버튼 | 구현 후 뷰의 주석을 푼다 | - |
| `TODO(고객환경)` `data_ready` | 구현하면 `true` | - |

`UNIMPLEMENTED.md` 1장, `CUSTOMER_ENV_CHECKLIST.md` 3-3 과 같은 내용이다.

## 8. 개선점

### 이미 반영한 개선

- 5버전 필터 구성 그대로의 틀, 사용자별 필터 저장, 호스트 그룹 선택 시 하위 그룹 포함.
- 입력 검증(라디오 값 목록).
- 구현 방향(공용 내보내기 부품, trends 우선, 파일 하나씩)을 컨트롤러 머리말에 정리.

### 남은 개선 제안

| 제안 | 효과 | 5버전과 결과가 달라지나 (고객 합의) |
|---|---|---|
| 정기 보고는 7.0 "예약 보고서"(대시보드를 PDF 로 정기 메일 발송) 검토. 웹 서비스(`zabbix_web_service`) 설치가 필요하다 | 사람이 매번 내려받지 않아도 된다 | 결과물이 CSV 가 아니라 대시보드 PDF 로 바뀐다. 합의 필요 |
| 기간 입력 추가(기본: 어제 하루, 최대 3개월) | 무엇을 받는지 화면에서 분명해진다 | 5버전이 고정 기간이었다면 기본값을 같게 두면 된다. 합의 필요 |
| 파일 하나 + "항목" 열로 CPU/메모리 구분, 또는 항목별 버튼 | 브라우저 제약 안에서 5버전의 "여러 파일"을 대신 | 파일 개수와 이름이 달라진다. 합의 필요 |
| Excel 형식도 제공 | 엑셀에서 숫자 열이 바로 숫자로 들어간다(`'type' => 'number'`) | 형식 추가 |
| 업무시간의 업무일(월~금) 적용, Peak 계산 방식은 저사용/고사용과 같은 `business_peak_whole_day` 를 따름 | 화면 간 숫자가 일치 | 5버전 추출 파일이 주말을 포함했다면 달라진다. 확인 후 합의 |
| 데이터 없는(수집 끊긴) 서버를 파일에 따로 표시 | 조용히 빠지는 서버가 없어진다 | 행이 늘어난다. 합의 권장 |

### 7.0 데이터로 새로 만든다면 (설계 초안, **확정 아님**)

5버전 소스를 볼 수 없을 때 쓰는 초안이다. 저사용/고사용 서버 화면과 같은 집계(trends)를 써서 화면 간 숫자가 맞게 한다. 기간, 열 구성, 파일 나눔은 고객과 정한 뒤 확정한다.

**쓸 데이터**

| 데이터 | 7.0 에서 | 비고 |
|---|---|---|
| CPU 사용률 | `conf/serverusage.conf.php` 의 `cpu_item_keys` 로 찾은 아이템 | 저사용/고사용과 공용. `ServerUsageService` 의 아이템 찾기 방식 재사용 |
| 메모리 사용률 | 새 설정(예: 같은 파일에 `mem_item_keys` 추가)으로 찾은 아이템 | 키 값은 고객환경에서 확인. 0~100 실수 아이템이어야 한다 |
| 시간별 값 | `trends` 표 (1시간 단위 최소/평균/최대) | 많은 양을 DB 에서 집계하려면 `ServerUsageService::getUsage()` 처럼 직접 SQL. 행을 그대로 내보내는 경우라면 `trend.get` API 도 가능하나 양이 많으면 PHP 메모리에 주의 |
| 호스트, IP, Description | `host.get` + `HostHelper::mainIp()` + `ItsmRepository::fetchDescriptions()` | 공용 부품 |

**단계**

1. `manifest.json` 에 `usageexport.export` 액션을 추가하고(`"layout": "export/layout.file"`), 컨트롤러에 `use ExportTrait;`, `checkInput()` 에 `+ self::exportInputFields()` 를 더한다. 뷰의 버튼 주석을 푼다.
2. 기간: 필터에 시작/끝 날짜를 추가(기본 어제, 최대 92일)하거나, 합의한 고정 기간을 쓴다. 날짜는 사용자 시간대의 00:00:00 ~ 23:59:59 로 바꾼다.
3. 호스트: `API::Host()->get(HostFilter::toApiOptions($host_filter) + [...])` 로 권한 안의 호스트만 고른다.
4. 아이템: 항목 선택이 0 이면 CPU+메모리, 1 이면 CPU, 2 면 메모리 아이템만 찾는다(키 목록으로 `item.get` 한 번).
5. 집계: 아이템별, 날짜별로 하루 평균(시간별 평균의 평균)과 하루 최대(시간별 최대의 최대). "업무시간"이면 업무일의 09시대~17시대 값만 쓴다.
   예) 어느 날 09~17시의 시간별 평균이 20, 30, 40, 50, 60, 50, 40, 30, 20 이면 하루 평균 = 340 / 9 = 37.8, 시간별 최대 중 가장 큰 값이 Peak.
6. 행 형식 (안): 호스트 | IP | Description | 날짜 | 항목(CPU/Memory) | 평균(%) | 최대(%). 숫자 열은 `['label' => ..., 'type' => 'number']`.
7. `setExportResponse(Lang::t('CPU/Memory usage export'), 'usage_export', $columns, $rows)` 로 파일을 만든다. 파일 이름 예: `usage_export_20261121_142721.csv`.
8. 호스트가 많고 기간이 길면 오래 걸린다(1시간 1행 x 아이템 수 x 날짜 수). 기간 상한을 두고, PHP 실행 시간 제한을 넘는지 고객 규모로 재 본다.

## 9. 확인 방법

### 테스트 환경 (http://127.0.0.1:18970, `Admin` / `zabbix`, `dev/compose.yaml`)

1. 보고서 > CPU/Memory 전체 사용률 추출을 연다.
   - 기대: 필터 4줄과 빨간 안내 2줄, 노란 "구현 안 됨" 안내. 표와 내보내기 버튼은 없다. 빨간 PHP 경고 없음.
2. 항목 `CPU Util only`, 시간 `전체사용현황`, 호스트 하나를 고르고 [적용] -> 다른 메뉴에 갔다 돌아온다.
   - 기대: 선택이 유지된다. [리셋]을 누르면 `CPU Util & Mem Util`, 업무시간, 빈 선택창으로 돌아온다.
3. 주소에 `&filter_items=9&filter_set=1` 처럼 없는 값을 붙여 연다.
   - 기대: 입력 오류 화면(검증이 막는다).
4. 구현 후: Zabbix server 호스트의 CPU 사용률 아이템 키를 설정에 넣고 어제 하루를 내려받아, 저사용 서버 화면(같은 기간, 같은 시간 선택)의 평균/Peak 와 비교한다. 같은 집계를 쓰므로 일치해야 한다.

### 고객환경에서 5버전과 비교 (구현 후)

1. 같은 호스트 그룹, 같은 항목/시간으로 5버전과 7버전에서 파일을 받는다.
2. 5버전 파일의 열 구성, 행 단위(시간/일), 기간을 먼저 확인하고 7버전을 맞춘다.
3. 같은 호스트, 같은 날짜의 평균/최대가 소수점 수준에서 같은지 본다. 업무시간 값만 다르면 업무일(주말 포함 여부)과 Peak 계산 방식 차이다.

## 10. 파일 목록

### 이 화면의 파일

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/actions/reports/usageexport/UsageExportList.php` | 컨트롤러. 입력 검증, 권한, 필터 저장/읽기, 라디오 선택지. 머리말에 5버전 화면 구성과 구현 방향 |
| `ui/modules/dmove/views/reports/usageexport.list.php` | 화면 뷰. 필터, 빨간 안내문, "구현 안 됨" 안내. 내보내기 버튼은 주석 |
| `ui/modules/dmove/manifest.json` | `usageexport.list` 액션만 등록 (`usageexport.export` 없음) |
| `ui/modules/dmove/Module.php` | `REPORT_MENUS` 의 `'CPU/Memory usage export'` |

### 같이 쓰는 부품

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/includes/filter/HostFilter.php` | 호스트 그룹/호스트 선택 필터 |
| `ui/modules/dmove/includes/Lang.php`, `ui/modules/dmove/lang/ko.php` | 다국어 문구 (항목, 시간, 안내문) |
| (구현 시) `ui/modules/dmove/includes/ServerUsageService.php`, `conf/serverusage.conf.php` | CPU 아이템 찾기, trends 집계, 업무시간 설정 |
| (구현 시) `ui/modules/dmove/includes/export/` | CSV/Excel 내보내기 |
| (구현 시) `ui/modules/dmove/includes/HostHelper.php`, `ItsmRepository.php` | 대표 IP, ITSM Description |
