# 저사용 서버 사용현황 (`lowserver.list`)

> 고사용 서버 사용현황(`highserver.list`)과 필터, 표, 계산을 같은 코드로 쓴다. 공통 동작은 이 문서에 자세히 적고, 고사용 화면 문서([highserver.md](highserver.md))에는 다른 점만 적었다.

## 1. 한눈에

| 항목 | 내용 |
|---|---|
| 메뉴 위치 | 보고서 > 저사용 서버 사용현황 (`Module.php` 의 `REPORT_MENUS`) |
| 주소 | `zabbix.php?action=lowserver.list` |
| 상태 | 완성. 단 `conf/serverusage.conf.php` 의 `cpu_item_keys` 를 채워야 데이터가 나온다 |
| 5버전 액션 | `lowserver` (컨트롤러 `CControllerLowserver.php`), CSV 는 `lowserver.view.csv` |
| 내보내기 | 있음. CSV, Excel (`zabbix.php?action=lowserver.export&format=csv` 또는 `xlsx`) |
| 권한 | 사용자 유형 Zabbix User 이상 (`checkPermissions()`). 표에 나오는 호스트는 API 가 그 사용자가 볼 수 있는 것만 돌려준다 |

## 2. 이 화면은 무엇을 하나

정해진 기간 동안 CPU 를 거의 쓰지 않은 서버를 찾는 보고서다.
서버마다 기간 평균 CPU 사용률과 Peak(기간 중 가장 높았던 값)를 계산하고, **평균이 30% 이하이고 Peak 도 70% 이하인** 서버만 보여 준다(기준 값은 설정 파일에 있다).
운영 담당자가 "자원을 줄이거나 통합해도 되는 서버" 후보를 고를 때 쓴다. 업무시간(월~금 09~18시), 업무외시간, 전체 중 하나를 골라 볼 수 있어서 "낮에는 놀고 밤 배치에만 쓰는 서버" 같은 것도 구분할 수 있다.

## 3. 화면 구성

### 필터

| 이름 | 입력 방식 | 기본값 | 저장 여부 |
|---|---|---|---|
| 호스트 그룹 | 선택창(멀티셀렉트) + [선택] | 없음 = 볼 수 있는 전체 호스트. 그룹을 고르면 하위 그룹도 포함 | 저장 (`web.dmove.lowserver.filter.groupids`) |
| 호스트 | 선택창(멀티셀렉트) + [선택] | 없음 | 저장 (`web.dmove.lowserver.filter.hostids`) |
| 시작 | 날짜 입력 + 달력 (`CDateSelector`, YYYY-MM-DD) | 지난달 1일 | 저장 (`...filter.from`) |
| 종료 | 날짜 입력 + 달력 | 지난달 말일 | 저장 (`...filter.to`) |
| (안내문) | 글자 | "※최대 1년까지 기간 지정이 가능합니다." | - |
| 시간 | 라디오 3개, 세로 배치 | 전체사용현황 | 저장 (`...filter.hours`) |

시간 라디오의 값:

| 화면 문구 | 값 | 뜻 |
|---|---|---|
| 업무시간 사용현황(09시~18시) | `work` | 업무일(기본 월~금)의 09:00~17:59 값만 |
| 업무외시간 사용현황(18시~09시) | `night` | 모든 요일의 00:00~08:59, 18:00~23:59 값만 |
| 전체사용현황 | `all` | 모든 요일, 모든 시간 |

문구 속 "09시", "18시" 는 설정 `business_hours` 값을 그대로 넣어 만든다. 설정을 바꾸면 문구도 바뀐다.

필터 값은 [적용]을 누를 때 사용자 프로파일(`profiles` 테이블)에 저장되고, 다음에 화면을 열면 다시 읽힌다. [리셋]은 저장된 값을 지운다. 날짜를 잘못된 형식(예: `2026-13-01`)으로 넣으면 조회하지 않고 "날짜 형식이 올바르지 않습니다" 안내를 띄운다. 저장값은 비워지므로 다음에 열면 기본값(지난달)이다.

### 표

| 열 | 값의 출처 | 표시 방식 |
|---|---|---|
| 호스트 | `host.get` 의 `name`(표시 이름) | 정렬 가능. 줄바꿈 없음 |
| IP | `host.get` 의 인터페이스 중 대표 IP (`HostHelper::mainIp()`). 우선순위: Agent 기본 > 다른 종류 기본 > 아무거나 | 줄바꿈 없음. IP 가 없으면 빈칸 |
| Description | ITSM 의 설명(호스트 표시 이름 → 호스트 이름 순으로 찾음). 없으면 Zabbix 호스트의 설명 | 긴 글은 줄바꿈 |
| 평균CPU사용률(%) | `ServerUsageService::getUsage()` 의 `avg` | 정렬 가능. 소수 둘째 자리 + `%` (예: `3.10%`) |
| Peak(%) | `ServerUsageService::getUsage()` 의 `peak` | 소수 둘째 자리 + `%`. 정렬 없음(5버전과 같다) |

표 위 오른쪽에 빨간 글씨로 판정 기준이 나온다: `※저사용 기준: 평균사용률 30% 이하 / Peak 70% 이하`.
표 아래에는 코어와 같은 페이지 나눔이 붙는다.

### 버튼과 안내 상자

- 제목 오른쪽: [CSV 내보내기] [Excel 내보내기]. 지금 필터와 정렬 그대로 **전체 행**(페이지로 자르기 전)을 파일로 받는다. 숫자 열은 Excel 에서 숫자로 들어간다.
- `cpu_item_keys` 가 비어 있으면 노란 상자: "설정이 필요합니다: conf/serverusage.conf.php". 표는 비어 있다.
- 기간이 잘못되면 빨간 상자: "시작일이 종료일보다 늦을 수 없습니다." 또는 "※최대 1년까지 기간 지정이 가능합니다." 이때는 조회하지 않는다.
- 5버전의 [기준설정] 버튼은 없다(7장).

### 스케치

```
저사용 서버 사용현황                                [CSV 내보내기] [Excel 내보내기]
┌ 필터 ───────────────────────────────────────────────────────────────────────┐
│ 호스트 그룹 [            ][선택]     시작 [2026-10-01][달력]                  │
│ 호스트     [            ][선택]     종료 [2026-10-31][달력]                  │
│                                     ※최대 1년까지 기간 지정이 가능합니다.    │
│                                     시간 ( ) 업무시간 사용현황(09시~18시)    │
│                                          ( ) 업무외시간 사용현황(18시~09시)  │
│                                          (•) 전체사용현황                    │
│                              [적용] [리셋]                                   │
└─────────────────────────────────────────────────────────────────────────────┘
                                ※저사용 기준: 평균사용률 30% 이하 / Peak 70% 이하
 호스트 ▲ | IP       | Description      | 평균CPU사용률(%) | Peak(%)
 srv-01   | 192.0.2.1 | 업무 서버 설명  | 3.10%            | 41.20%
 ...
                                                  3 중 3건을 표시하고 있습니다
```

## 4. 어떻게 만들었나 (동작 순서)

### 4.1 클래스 구조

저사용과 고사용 화면은 "어떤 서버를 남기는가"만 다르다. 그래서 공통 부분을 추상 클래스(`abstract class`) 하나에 두고, 화면별 클래스는 다른 점 네 가지만 채운다.

```
ServerUsageController (abstract, 공통)        ← actions/reports/serverusage/ServerUsageController.php
 ├── LowServerList   : getPageKey()='lowserver', 제목, 기준 문구, matches()
 └── HighServerList  : getPageKey()='highserver', 제목, 기준 문구, matches()
```

`manifest.json` 에는 추상 클래스가 아니라 `LowServerList` 를 등록한다. `lowserver.list` 와 `lowserver.export` 두 액션이 같은 클래스를 쓰고, 뷰는 두 화면 모두 `reports/serverusage.list` 다.

저사용 판정은 이 한 줄이다.

```php
// LowServerList::matches()
return $avg <= $thresholds['low_avg'] && $peak <= $thresholds['low_peak'];
```

- 평균이 `low_avg`(30) **이하** 그리고 Peak 가 `low_peak`(70) **이하** 이면 목록에 남긴다.

### 4.2 요청이 들어와서 화면이 그려질 때까지

1. **액션 연결.** 주소 `zabbix.php?action=lowserver.list` → `manifest.json` → `Modules\DMove\Actions\Reports\LowServer\LowServerList`.
2. **`ServerUsageController::init()`** — 조회 전용이라 `disableCsrfValidation()` 으로 CSRF 토큰 검사를 끈다.
3. **`ServerUsageController::checkInput()`** — 받을 수 있는 파라미터와 규칙을 정한다. 날짜는 빈칸으로도 오므로 `string` 으로 받고 나중에 `normalizeDate()` 로 형식을 본다. 시간은 `in work,night,all` 만 받는다. 호스트 필터 규칙(`HostFilter::INPUT_FIELDS`)과 내보내기 규칙(`format`)을 더한다. 규칙에 맞지 않으면 `CControllerResponseFatal`(오류 화면).
4. **`ServerUsageController::checkPermissions()`** — `getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest()` (게스트 계정 제외).
5. **`ServerUsageController::doAction()`** — 아래 순서로 처리한다.
   1. 프로파일 접두어를 만든다: `'web.dmove.'.getPageKey().'.'` → `web.dmove.lowserver.`
   2. [적용](`filter_set`)이면 `HostFilter::save()` 로 그룹/호스트를, `CProfile::update()` 로 시작/종료/시간을 저장한다. 날짜는 `normalizeDate()` 를 거친다(`YYYY-MM-DD` 이고 `checkdate()` 로 실제 있는 날짜일 때만 그대로, 아니면 `''`).
      [리셋](`filter_rst`)이면 `HostFilter::reset()` 과 `CProfile::deleteIdx()` 로 지운다.
   3. 저장된 값을 읽는다. 날짜가 비어 있으면 `date('Y-m-d', strtotime('first day of last month'))` / `'last day of last month'`.
   4. 시각으로 바꾼다: `$time_from = strtotime(시작.' 00:00:00')`, `$time_till = strtotime(종료.' 23:59:59')`. 시간대는 로그인한 사용자의 시간대다.
   5. 기간 검사: 시작 > 종료면 오류, `종료 - 시작 > max_period_days(366) × 86400` 이면 오류. 오류면 `$rows = []` 로 두고 조회하지 않는다.
      예) 2025-01-01 ~ 2025-12-31 은 365일이라 통과, 2025-01-01 ~ 2026-01-02 는 367일이라 오류.
   6. 정렬 값(`sort` = `name` 또는 `avg`, `sortorder`)을 요청 → 프로파일 순으로 읽고 다시 프로파일에 저장한다.
   7. `getRows()` 로 데이터를 만들고 `CArrayHelper::sort()` 로 정렬한다.
   8. 액션 이름이 `.export` 로 끝나면(`ExportTrait::isExportRequest()`) `setExportResponse()` 로 파일 응답을 만들고 끝낸다.
   9. 아니면 `CPagerHelper::paginate()` 로 현재 페이지 몫만 자르고, 뷰에 넘길 `$data` 를 만든다(`config_missing` = 키 설정이 비었는가, `criteria` = 기준 문구 등).
6. **`ServerUsageController::getRows()`**
   1. `API::Host()->get(HostFilter::toApiOptions(...) + ['output' => [hostid, host, name, description], 'selectInterfaces' => [ip, main, type], 'preservekeys' => true])`
      - 그룹을 고르면 `getSubGroups()` 로 하위 그룹까지 넣는다. 아무것도 안 고르면 조건이 없어서 볼 수 있는 전체 호스트가 나온다.
      - 호스트 상태(사용/중지)는 거르지 않는다(5버전도 거르지 않았다).
   2. `ServerUsageService::getUsage(호스트ID들, 시작, 끝, 시간)` → `[hostid => ['avg' => .., 'peak' => ..]]`
   3. `ItsmRepository::fetchDescriptions()` → `[소문자 호스트명 => 설명]`. ITSM 설정이 비어 있으면 빈 배열.
   4. `$usage` 를 돌며 `matches()` 가 거짓이면 뺀다. **값이 없는 서버는 `$usage` 에 아예 없으므로 처음부터 표에 없다**(5버전과 같다).
   5. Description 은 `ITSM[표시이름] ?? ITSM[호스트이름] ?? Zabbix 설명` 순.
   6. 평균과 Peak 는 `round(값, 2)` 로 여기서 반올림해 둔다. 화면과 파일에 같은 값이 나오게 하려는 것이다.
7. **뷰 `views/reports/serverusage.list.php`** — 필터(좌: `HostFilter::addRows()`, 우: 날짜 두 칸 + 안내 + 시간 라디오), 표(`CTableInfo`, 호스트/평균 열은 `make_sorting_header()`), 안내 상자, 빨간 기준 문구(`dm-criteria` 클래스)를 `CHtmlPage` 에 붙여 출력한다.

### 4.3 평균과 Peak 계산 (`ServerUsageService::getUsage()`)

#### (1) CPU 아이템 찾기 — `findCpuItems()`

- `cpu_item_keys.exact` 가 있으면 `API::Item()->get(['hostids' => .., 'filter' => ['key_' => [키들]]])` — 키가 정확히 같은 아이템.
- `cpu_item_keys.prefix` 가 있으면 `API::Item()->get(['search' => ['key_' => [접두어들]], 'startSearch' => true, 'searchByAny' => true])` — 키가 그 글자로 **시작하는** 아이템.
- 결과는 `[itemid => hostid]`. 아이템이 하나도 없으면 빈 결과.

#### (2) trends 표가 무엇인가

Zabbix 서버는 숫자 아이템의 값을 받으면 원래 값은 `history` 에, **1시간 요약**은 `trends` 에 넣는다. 한 줄이 "아이템 하나의 한 시간"이다.

| itemid | clock | num | value_min | value_avg | value_max |
|---|---|---|---|---|---|
| 12345 | 1767589200 | 60 | 31 | 40 | 60 |

- `clock` = 그 시간의 시작 시각(UTC 기준 유닉스 초). 위 값은 한국 시각 2026-01-05(월) 14:00.
- `value_avg` = 그 한 시간 값들의 평균, `value_max` = 그 한 시간 중 가장 큰 값.
- 실수(float) 아이템은 `trends`, 부호 없는 정수 아이템은 `trends_uint` 에 들어간다. 이 화면은 `trends` 만 읽으므로 **CPU 아이템은 실수형이어야 한다**.

#### (3) "그날의 몇 시, 무슨 요일" 을 SQL 로 구하기

`clock` 은 UTC 라서 그대로 날짜를 자르면 한국 날짜와 9시간 어긋난다. 그래서 사용자 시간대 차이(`date('Z')`, 한국은 32400초)를 먼저 더한다.

```php
$offset = (int) date('Z', $time_from);                 // 32400
$local = '(clock+'.$offset.')';                        // 한국 시각 기준 초
$day_number = 'FLOOR('.$local.'/86400)';               // 날짜 번호
$second_of_day = 'MOD('.$local.',86400)';              // 그날 0시부터 지난 초
$weekday = 'FLOOR(MOD('.$local.'+259200,604800)/86400)'; // 0=월 ... 6=일
```

예시 두 줄로 계산해 보면:

| | 줄 A | 줄 B |
|---|---|---|
| clock (UTC) | 1767589200 (UTC 1월 5일 05:00) | 1767546000 (UTC **1월 4일** 17:00) |
| local = clock + 32400 | 1767621600 | 1767578400 |
| 날짜 번호 = local ÷ 86400 의 몫 | 20458 (= 2026-01-05) | 20458 (= 2026-01-05) |
| 그날 지난 초 = local ÷ 86400 의 나머지 | 50400 → 14시 | 7200 → 2시 |
| 요일 = (local + 259200) ÷ 604800 의 나머지 ÷ 86400 | 0 → 월 | 0 → 월 |

- 줄 B 는 UTC 로는 1월 4일이지만 한국 시각으로는 1월 5일 02시다. 시간대 차이를 더했기 때문에 같은 날(20458)로 묶인다.
- 요일 계산: 1970-01-01(유닉스 시간 0)은 **목요일**이다. 3일(259200초)을 더하면 목요일이 "월요일부터 3일째"(=3)가 되어, 월 0 · 화 1 · … · 일 6 이 된다.
- 설정 `business_days` 는 ISO 요일(월 1 ~ 일 7)이라 코드에서 1 을 빼서 맞춘다: `[1,2,3,4,5]` → `[0,1,2,3,4]`.
- 서머타임이 있는 시간대에서는 전환일 앞뒤로 1시간 어긋난다. 한국은 해당 없다.

#### (4) 시간대 조건

| 시간 선택 | 시간 조건 (`$in_hours`) | 날짜 조건 (`$day_condition`) |
|---|---|---|
| 업무시간 `work` | `그날 지난 초 >= 32400(9시) AND < 64800(18시)` | 요일 IN (0,1,2,3,4) — 토/일 줄은 아예 읽지 않는다 |
| 업무외시간 `night` | `그날 지난 초 < 32400 OR >= 64800` | 없음 (모든 요일) |
| 전체 `all` | `1=1` (항상 참) | 없음 |

주의: 토/일의 09~18시는 "업무시간"에도 "업무외시간"에도 들어가지 않는다. "전체"에만 들어간다(5버전과 같다).

#### (5) 집계 SQL — 두 단계

```sql
SELECT d.itemid, AVG(d.day_avg) AS usage_avg, MAX(d.day_max) AS usage_peak
FROM (
    SELECT itemid, FLOOR((clock+32400)/86400) AS day_number,
           AVG(CASE WHEN <시간 조건> THEN value_avg END) AS day_avg,     -- ① 하루 평균
           MAX(value_max) 또는 MAX(CASE WHEN <시간 조건> THEN value_max END) AS day_max  -- ② 하루 최대
    FROM trends
    WHERE itemid IN (...) AND clock >= <시작> AND clock <= <끝> [AND <요일 조건>]
    GROUP BY itemid, day_number
) d
WHERE d.day_avg IS NOT NULL                                              -- ③
GROUP BY d.itemid                                                        -- ④ 기간 평균, Peak
```

- 안쪽: 아이템별, **날짜별**로 하루 평균(시간별 평균들의 평균)과 하루 최대(시간별 최대들의 최대)를 낸다.
- `CASE WHEN 조건 THEN 값 END` 는 조건에 맞지 않으면 NULL 이다. `AVG`, `MAX` 는 NULL 을 빼고 계산하므로 "고른 시간대의 값만" 평균을 낸 것과 같다.
- ③ 고른 시간대에 값이 하나도 없는 날은 하루 평균이 NULL 이 되어 빠진다.
- 바깥: **기간 평균 = 하루 평균들의 평균**, **Peak = 하루 최대들의 최대**.
- 한 호스트에 CPU 아이템이 둘 이상 걸리면 기간 평균이 큰 쪽을 그 호스트의 값으로 쓴다.
- `dbConditionId()` / `dbConditionInt()` 는 `IN (...)` 조건을 안전하게 만드는 코어 함수다. 나머지 숫자는 모두 코드가 만든 정수라 따옴표가 필요 없다. PostgreSQL, MySQL 모두 같은 문법으로 동작한다.

#### (6) Peak 를 무엇의 최대로 하나 — `business_peak_whole_day`

| 경우 | 하루 최대 |
|---|---|
| 업무시간 + `business_peak_whole_day = true` (기본, 5버전과 같다) | 그 업무일 **하루 전체** 24시간의 최대 (`MAX(value_max)`) |
| 업무시간 + `false` | 09~18시 안의 최대 |
| 업무외시간, 전체 | 고른 시간대 안의 최대 |

5버전 CPU 집계 클래스가 업무시간 Peak 에 `max(value_max)`(하루 전체)를 썼기 때문에 숫자를 맞추려고 기본값을 `true` 로 두었다.

#### (7) 예시 숫자로 따라가기

서버 하나, 3일치 데이터라고 하자. (시간별 `value_avg` / `value_max`)

| 날짜 | 00~09시 (9시간) | 09~18시 (9시간) | 18~24시 (6시간) |
|---|---|---|---|
| 1/5 월 | 평균 10 / 최대 20, 단 **02시는 최대 95**(새벽 백업) | 평균 40 / 최대 60 | 평균 10 / 최대 20 |
| 1/6 화 | 평균 10 / 최대 20 | 평균 50 / 최대 70 | 평균 10 / 최대 20 |
| 1/10 토 | 하루 종일 평균 5 / 최대 15 | | |

**전체사용현황**

| 날짜 | 하루 평균 | 하루 최대 |
|---|---|---|
| 월 | (9×10 + 9×40 + 6×10) ÷ 24 = 510 ÷ 24 = **21.25** | 95 |
| 화 | (90 + 450 + 60) ÷ 24 = 600 ÷ 24 = **25** | 70 |
| 토 | **5** | 15 |

- 기간 평균 = (21.25 + 25 + 5) ÷ 3 = **17.08%**
- Peak = max(95, 70, 15) = **95.00%**
- 저사용 판정: 평균 17.08 ≤ 30 은 맞지만 Peak 95 > 70 이라 **표에 안 나온다**. 새벽 백업 한 번이 판정을 바꾼다.

**업무시간 사용현황** (토요일 줄은 요일 조건으로 빠진다)

| 날짜 | 하루 평균 (09~18시만) | 하루 최대 (`true`) | 하루 최대 (`false`) |
|---|---|---|---|
| 월 | 40 | 95 (02시 포함 하루 전체) | 60 |
| 화 | 50 | 70 | 70 |

- 기간 평균 = (40 + 50) ÷ 2 = **45.00%**
- Peak = `true` 면 **95.00%**, `false` 면 **70.00%**
- 업무시간을 골랐는데 Peak 가 새벽 02시 값(95)인 것이 `true` 의 효과다. 8장의 개선 제안이 이것이다.

**업무외시간 사용현황** (모든 요일, 00~09시 + 18~24시 = 하루 15시간)

| 날짜 | 하루 평균 | 하루 최대 |
|---|---|---|
| 월 | (9×10 + 6×10) ÷ 15 = **10** | 95 |
| 화 | **10** | 20 |
| 토 | **5** | 15 |

- 기간 평균 = (10 + 10 + 5) ÷ 3 = **8.33%**, Peak = **95.00%**
- 같은 날짜의 새벽(00~09시)과 저녁(18~24시)이 한 묶음이다. "1/5 저녁 ~ 1/6 새벽" 처럼 밤을 이어서 묶지 않는다(5버전과 같다).

**왜 "하루 평균의 평균" 인가**

시간별 값을 한꺼번에 평균 내는 것과 결과가 다를 수 있다. 예: 1일은 24시간 모두 평균 10, 2일은 수집이 끊겨 2시간만 있고 평균 70.

- 하루 평균의 평균 = (10 + 70) ÷ 2 = **40**
- 시간 전체의 평균 = (24×10 + 2×70) ÷ 26 = 380 ÷ 26 = **14.6**

5버전도 일별 요약표에 하루 평균을 넣어 두고 그것을 다시 평균 냈으므로, 5버전 숫자와 맞추려면 "하루 평균의 평균" 이어야 한다. 하루 수집이 고르게 되어 있으면 두 방법의 결과는 같다.

#### (8) 성능

1시간 1줄이므로 읽는 줄 수 = 아이템 수 × 24 × 날짜 수. 2000대 × 1년이면 약 1700만 줄이다. DB 가 집계하므로 PHP 메모리는 거의 안 쓰지만 수 초 ~ 수십 초 걸릴 수 있다. `trend.get` API 는 줄을 그대로 돌려줘서 이 양을 PHP 로 받아야 하므로 직접 SQL 을 썼다. 대신 "어떤 호스트를 볼 수 있는가"는 `host.get` 으로 먼저 걸러서 권한을 지킨다.

## 5. 5버전과 비교

| 항목 | 5버전 | 7버전 | 왜 |
|---|---|---|---|
| 집계 방법 | 일별 요약표 `extends_trends_day` 를 따로 만들어 쌓고(화면 요청 때 또는 DB 함수 `batch.sql` 로), 화면은 그 표를 읽었다 | 요약표 없이 `trends` 를 바로 두 단계로 집계 | Zabbix DB 에 표를 추가하면 업그레이드, 백업/복구 때 따로 챙겨야 한다. 요약표 생성 쪽의 오류(아래)도 함께 없어진다 |
| 요약표 생성 (`batch.sql`) | 시작하자마자 표를 `truncate` 로 비우고 전체 기간을 다시 계산. 하루의 시작을 자정이 아니라 `trends` 의 첫 시각에 맞춰 9시간/18시간을 더해서 업무시간 구간이 밀릴 수 있음. 하루 요약 줄의 `clock` 을 다음 날 시작 시각으로 넣어 기간 조회와 하루 어긋날 수 있어 보임. 업무외시간 서브쿼리 이름이 `work` 라 읽기 헷갈림 | 해당 없음. 날짜와 시간대를 사용자 시간대의 자정 기준으로 SQL 에서 계산 | 5버전 오류 제거 |
| 업무시간 요일 | 월~금만 (`weekend in (1,2,3,4,5)`) | 같음 (`business_days`, 설정으로 바꿀 수 있음) | 5버전과 같게 |
| 업무외시간 요일 | 요일 구분 없음 | 같음 | 5버전과 같게 |
| 업무시간 Peak | 업무일 하루 전체의 최대 | 기본은 같음. `business_peak_whole_day=false` 로 업무시간 안의 최대로 바꿀 수 있음 | 숫자를 맞추되 고칠 길을 열어 둠 |
| 판정 | `avg > 기준 || max > 기준` 이면 제외 = "평균 이하 그리고 Peak 이하" 만 남김 | 같음 (`<=` 그리고 `<=`) | |
| 기준 문구 | 화면에는 "30% **미만** / Peak 70% **미만**" 이라고 썼지만 코드는 "이하" 였다 | "이하" 로 코드와 맞춤 | 문구 오류 수정 |
| 기준 값 | 사용자 프로파일(`web.report.server.cpulow`, `peaklow`)에서 읽고 없으면 기본값. [기준설정] 버튼으로 사용자마다 바꿈 | `conf/serverusage.conf.php` 의 값을 모든 사용자가 같이 씀 | [기준설정] 화면 소스를 확인하지 못함 (7장) |
| 데이터 없는 서버 | 표에서 뺌 (`no-trend delete`) | 같음 | 5버전과 같게. 8장에 개선 제안 |
| CPU 아이템 찾기 | 키 목록을 `LIKE ... OR ...` 로 이어 붙인 SQL | `item.get` 의 `filter`(정확히 같음) / `search`+`startSearch`(~로 시작) | 요청값 직접 SQL 제거, 권한 적용 |
| 아이템이 둘 이상인 호스트 | 조회 결과의 첫 줄(`rows[hostid][0]`) | 기간 평균이 큰 쪽 | 첫 줄은 DB 순서에 따라 달라져 결과가 일정하지 않다. 두 아이템이 걸리는 호스트가 있으면 5버전과 숫자가 다를 수 있다 |
| IP | 줄마다 `API::HostInterface()->get()` (호스트 수만큼 API 호출). 첫 인터페이스 IP, 여러 개면 말풍선 | `host.get` 한 번에 인터페이스까지. 대표 IP 하나 | 호출 수 감소. 말풍선은 옮기지 않음 |
| Description | ITSM 값만. 20자 넘으면 자르고 말풍선 | ITSM 값 → 없으면 Zabbix 호스트 설명. 자르지 않고 줄바꿈 | ITSM 미연동이어도 뭔가 보이게 |
| 그룹 필터 | 고른 그룹을 `editable => true` 로 다시 조회해서, 쓰기 권한이 없는 사용자는 그룹 선택이 무시될 수 있어 보임 | `getSubGroups()` 로 바로 하위 그룹 포함. 볼 권한은 API 가 판단 | 5버전 약점 수정 |
| 기간 검사 | 안내문만 확인됨. 실제 검사 여부는 확인 필요 | 시작 > 종료, 366일 초과면 빨간 상자로 이유를 보여 주고 조회 안 함 | 긴 기간 조회로 DB 부하가 커지는 것 방지 |
| 0 값 표시 | `percent(0)` 이 `0` 을 돌려 `0%` | `0.00%` | 표시 통일 |
| 브라우저 제목 | `Low Server` | 화면 제목(번역됨) | |
| 내보내기 | CSV | CSV + Excel | 공통 개선 |
| 입력 처리 | `check_fields()`, `getRequest()` (7버전에 없음) | `checkInput()` 규칙 + `getInput()` | 7버전 방식 |

## 6. 설정

파일: `conf/serverusage.conf.php` (저사용/고사용 공용)

| 값 | 기본 | 뜻 | 비어 있거나 틀리면 |
|---|---|---|---|
| `cpu_item_keys.exact` | `[]` | CPU 사용률(%) 아이템 키. 정확히 같은 키 | `exact`, `prefix` 둘 다 비면 노란 안내 상자 + 빈 표. 키가 틀리면 그 서버가 표에 안 나온다 |
| `cpu_item_keys.prefix` | `[]` | 키가 이 글자로 시작하는 아이템. 키 뒷부분이 서버마다 다를 때 | 위와 같음 |
| `thresholds.low_avg` | 30 | 저사용 평균 기준(%) | |
| `thresholds.low_peak` | 70 | 저사용 Peak 기준(%) | |
| `thresholds.high_avg`, `high_peak` | 50, 70 | 고사용 화면용 | |
| `business_hours` | `[9, 18]` | 업무시간. 시작 시 포함, 끝 시 미포함 → 09시대 ~ 17시대 | 라디오 문구도 이 값으로 바뀐다 |
| `business_days` | `[1,2,3,4,5]` | 업무시간을 골랐을 때 넣을 요일(ISO: 월 1 ~ 일 7). `[]` 이면 모든 요일 | 공휴일은 구분하지 않는다 |
| `business_peak_whole_day` | `true` | 업무시간 Peak 를 하루 전체 최대로 (5버전과 같게) | `false` 면 업무시간 안의 최대 |
| `max_period_days` | 366 | 조회할 수 있는 최대 기간(일) | |

넣을 키는 5버전 키 클래스(`...Key.php`)의 `serverCpuUsage()` 가 돌려주는 3개(리눅스, 유닉스, 윈도우)다. 공개 저장소라 실제 값은 넣지 않았다. 7버전 서버에서는 데이터 수집 > 호스트 > (서버 종류별 하나씩) > 아이템에서 CPU 사용률 아이템의 키를 확인한다. 값은 0~100 실수여야 한다.

ITSM 설명은 `conf/itsm.conf.php` 를 따른다(비어 있으면 Zabbix 호스트 설명만 나온다).

## 7. 비어 있는 부분과 가정

| 구분 | 내용 | 확인할 5버전 파일 / 위치 | 고칠 곳 |
|---|---|---|---|
| TODO(고객환경) | CPU 아이템 키 3종 | 키 클래스(`...Key.php`)의 `serverCpuUsage()` | `conf/serverusage.conf.php` 의 `cpu_item_keys` (CUSTOMER_ENV_CHECKLIST 1-3) |
| 가정 | 저사용 평균 기준 30% (5버전 컨트롤러의 기본값이 30 인지 20 인지 글자를 확정하지 못함) | 저사용 컨트롤러(`CControllerLowserver.php`)의 `$cpulow` 기본값 | `conf/serverusage.conf.php` 의 `low_avg` (UNIMPLEMENTED 3장, CHECKLIST 2-3) |
| 없음 | [기준설정] 버튼. 화면에서 기준을 바꾸게 할지, 바꾼다면 사용자별인지 전체 공통인지 | `popup.lowserver` 컨트롤러 | 지금은 설정 파일 (UNIMPLEMENTED 2장, CHECKLIST 3-5) |
| 합의만 필요 | 업무시간 Peak = 업무일 하루 전체 최대 (5버전과 같게 둠, 확인 끝) | - | `business_peak_whole_day` (UNIMPLEMENTED 3장) |
| 가정 | 5버전 `popup.batch`(배치 팝업)는 요약표가 없어져서 필요 없다고 봄 | `popup.batch` 컨트롤러 | 없음 |
| 가정 | 시간대 = 로그인한 사용자의 시간대(보통 서버 기본 시간대) | - | 관리 > 일반 > GUI 기본 시간대 (CHECKLIST 6-4) |

## 8. 개선점

### 이미 반영한 개선

- 일별 요약표와 배치(표를 비우고 다시 채움, 업무외시간 서브쿼리 이름 혼동, 자정 기준이 아닌 시간 구간)를 없애고 `trends` 를 직접 집계.
- 업무시간 = 월~금(주말 제외)을 설정으로 명시.
- 호스트마다 부르던 인터페이스 API 를 `host.get` 한 번으로.
- 요청값을 이어 붙인 SQL 제거, 입력 검증(`checkInput()`), 날짜 형식 검사.
- 기간 오류를 화면에 표시, 설정 누락 안내.
- 기준 문구를 코드 동작("이하")과 맞춤.
- Excel 내보내기, 다국어(`Lang::t()` + `lang/ko.php`).

### 남은 개선 제안

| 제안 | 효과 | 5버전과 결과가 달라지나 (합의 필요?) |
|---|---|---|
| 데이터가 없는(수집이 끊긴) 서버를 표 아래 별도 목록이나 "데이터 없음" 줄로 표시 | 지금은 조용히 빠져서 "저사용 서버가 없다" 와 "데이터가 없다" 를 구분할 수 없다. 에이전트 장애나 키 불일치를 빨리 찾는다 | 별도 영역이면 기존 표는 같다. 표 안에 섞으면 목록이 달라진다 → 합의 필요 |
| `business_peak_whole_day` 를 `false` 로 | "업무시간" 을 골랐는데 Peak 가 새벽 배치 값이 되는 문제가 없어진다(4.3 예시: 95 → 70) | 업무시간 Peak 숫자가 바뀌고 표에 나오는 서버가 늘 수 있다 → 합의 필요 |
| [기준설정]을 관리자 설정 화면 + DB 저장으로 (예: 모듈 설정 `manifest.json` 의 `config` 를 관리 화면에서 바꾸고 `CModule::getOption()` 으로 읽기) | 설정 파일 수정 없이 기준을 바꾼다 | 5버전은 사용자별 값이었다. 전체 공통으로 바꾸면 동작이 다르다 → 합의 필요 |
| Peak 열도 정렬 가능하게 | 높은 Peak 순으로 보기 | 같다 (표시만) |
| 호스트 이름에 최신 데이터 / 그래프 링크 | 의심 서버의 추이를 바로 확인 | 같다 |
| 중지된 호스트를 빼는 옵션 (`host.get` 에 `filter => ['status' => HOST_STATUS_MONITORED]`) | 이미 정리한 서버가 후보로 나오는 것 방지 | 목록이 줄어든다 → 합의 필요 |
| 공휴일 제외 | 업무시간 평균이 더 정확해진다 | 숫자가 바뀐다 → 합의 필요 |
| ITSM 설명이 빈 문자열이면 Zabbix 설명으로 넘어가게 (**반영함**: `ItsmRepository::fetchDescriptions()` 가 설명이 빈 행을 넣지 않는다) | 빈 Description 줄이 줄어든다 | 같다 (표시만) |
| 긴 기간 조회 속도: TimescaleDB 압축 여부 확인, 필요하면 결과 캐시 | 1년 조회 대기 시간 감소 | 같다 |

## 9. 확인 방법

### 테스트 환경 (http://127.0.0.1:18972, Admin / zabbix, `dev/compose.yaml`)

테스트 환경에는 CPU 를 보내는 에이전트가 없어서 그대로 열면 표가 비어 있다. 아래처럼 계산 예시(4.3 (7))와 같은 데이터를 넣어 숫자를 손계산과 비교한다. **설정 파일 수정은 로컬 확인용이다. 확인 뒤 되돌린다.**

1. 화면만 확인: 보고서 > 저사용 서버 사용현황. 키 설정이 비어 있으므로 노란 "설정이 필요합니다: conf/serverusage.conf.php" 상자와 빈 표, 기준 문구 `※저사용 기준: 평균사용률 30% 이하 / Peak 70% 이하` 가 보이면 정상.
2. 기간 검사: 시작 2026-02-01, 종료 2026-01-01 → [적용] → 빨간 "시작일이 종료일보다 늦을 수 없습니다.". 시작 2025-01-01, 종료 2026-01-05 → "※최대 1년까지 기간 지정이 가능합니다.".
3. 시험 데이터 준비
   - 데이터 수집 > 호스트 > 호스트 생성: 이름 `test-cpu`, 그룹 아무거나.
   - 그 호스트에 아이템 생성: 형식 "Zabbix 트래퍼", 키 `test.cpu`, 정보 형식 "숫자(소수)".
   - DB 에 시간별 요약을 직접 넣는다.
     ```bash
     cd dev
     docker compose exec -T db psql -U zabbix zabbix -c "SELECT itemid FROM items WHERE key_='test.cpu'"
     ```
     ```sql
     -- 12345 를 위에서 나온 itemid 로 바꾼다. 1/5(월), 1/6(화), 1/10(토) 한국 시각 기준 시간별 값.
     INSERT INTO trends (itemid, clock, num, value_min, value_avg, value_max)
     SELECT 12345, EXTRACT(EPOCH FROM t)::int, 60, 0,
            CASE WHEN dd = 10 THEN 5
                 WHEN hh >= 9 AND hh < 18 THEN CASE WHEN dd = 5 THEN 40 ELSE 50 END
                 ELSE 10 END,
            CASE WHEN dd = 10 THEN 15
                 WHEN dd = 5 AND hh = 2 THEN 95
                 WHEN hh >= 9 AND hh < 18 THEN CASE WHEN dd = 5 THEN 60 ELSE 70 END
                 ELSE 20 END
     FROM (
         SELECT t, EXTRACT(HOUR FROM t AT TIME ZONE 'Asia/Seoul') AS hh,
                   EXTRACT(DAY FROM t AT TIME ZONE 'Asia/Seoul') AS dd
         FROM generate_series('2026-01-05 00:00+09'::timestamptz, '2026-01-10 23:00+09'::timestamptz, interval '1 hour') AS t
     ) s
     WHERE dd IN (5, 6, 10);
     ```
   - 로컬 `conf/serverusage.conf.php` 의 `exact` 에 `'test.cpu'` 를 넣는다.
4. 필터: 호스트 `test-cpu`, 시작 2026-01-05, 종료 2026-01-10.
   - 전체사용현황 → 표가 비어 있어야 한다(Peak 95 > 70).
   - 확인을 위해 로컬 설정 `low_peak` 를 100 으로 → 전체 **17.08% / 95.00%**, 업무시간 **45.00% / 95.00%**, 업무외시간 **8.33% / 95.00%**. 업무시간은 평균 45 > 30 이라 빠지므로 `low_avg` 도 50 으로 올려서 본다.
   - `business_peak_whole_day` 를 `false` 로 → 업무시간 Peak 가 **70.00%** 로 바뀐다.
5. 정렬: 호스트, 평균 열 제목을 눌러 오름/내림이 바뀌는지. 다른 화면에 갔다 와도 정렬과 필터가 유지되는지.
6. [CSV 내보내기], [Excel 내보내기] → 파일 이름 `lowserver_YYYYMMDD_HHMMSS.csv/.xlsx`, 화면과 같은 행과 숫자(Excel 에서 숫자 열이 숫자로 정렬되는지).
7. 사용자 언어를 영어로 바꿔 문구가 영어로 나오는지.
8. 끝나면 설정 파일을 되돌리고, 시험 호스트를 지운다(아이템을 지우면 trends 도 하우스키퍼가 정리한다).

### 고객환경에서 5버전과 비교

1. 5버전과 7버전에서 같은 호스트 그룹, 같은 기간(예: 지난달 1일~말일), 같은 시간 선택으로 연다.
2. 표의 서버 목록과 평균/Peak 를 비교한다. 소수 둘째 자리 차이는 반올림이므로 무시한다.
3. 다를 때 볼 곳
   - 서버가 7버전에만 없다 → 그 서버의 CPU 아이템 키가 `cpu_item_keys` 에 있는가, 실수형인가, 기간 안에 trends 가 있는가.
   - 숫자가 조금씩 다르다 → 5버전 요약표가 어느 날짜 구간으로 만들어졌는지(5장의 batch 구간 어긋남), 사용자 시간대가 같은지.
   - 한 서버만 크게 다르다 → 그 서버에 CPU 아이템이 둘 이상 걸리는지(5버전은 첫 줄, 7버전은 평균이 큰 쪽).
   - 업무시간 Peak 만 다르다 → `business_peak_whole_day` 가 `true` 인가.
4. 5버전 [기준설정]에 사용자가 바꾼 값이 있으면(프로파일 `web.report.server.cpulow`, `peaklow`) 그 값을 설정 파일에 넣고 비교한다.

## 10. 파일 목록

### 이 화면의 파일

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/actions/reports/lowserver/LowServerList.php` | 저사용 화면 컨트롤러. 이름, 제목, 기준 문구, `matches()` 만 있다 |
| `ui/modules/dmove/actions/reports/serverusage/ServerUsageController.php` | 저사용/고사용 공통 컨트롤러. 입력, 필터 저장, 기간 검사, 정렬, 행 만들기, 내보내기 |
| `ui/modules/dmove/views/reports/serverusage.list.php` | 저사용/고사용 공통 뷰 |
| `ui/modules/dmove/includes/ServerUsageService.php` | CPU 아이템 찾기, trends 집계(평균/Peak) |
| `ui/modules/dmove/conf/serverusage.conf.php` | 아이템 키, 판정 기준, 업무시간/업무일, Peak 방식, 최대 기간 |
| `ui/modules/dmove/manifest.json` | `lowserver.list`, `lowserver.export` 액션 등록 |
| `ui/modules/dmove/Module.php` | 보고서 메뉴 등록 (`REPORT_MENUS`) |
| `ui/modules/dmove/lang/ko.php` | 한국어 문구 |
| `ui/modules/dmove/assets/css/dmove.css` | 기준 문구 위치(`dm-criteria`) |

### 같이 쓰는 부품

| 파일 | 역할 |
|---|---|
| `includes/filter/HostFilter.php` | 호스트 그룹/호스트 필터 (입력 규칙, 저장, API 조건, 뷰 두 줄) |
| `includes/export/ExportTrait.php`, `ExportButtons.php`, `views/export/layout.file.php` 등 | CSV/Excel 내보내기 |
| `includes/HostHelper.php` | 대표 IP 고르기 (`mainIp()`) |
| `includes/ItsmRepository.php`, `conf/itsm.conf.php` | ITSM Description |
| `includes/Lang.php` | 번역 (`Lang::t()`) |
