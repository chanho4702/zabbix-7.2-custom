# 고사용 서버 사용현황 (`highserver.list`)

> 이 화면은 저사용 서버 사용현황과 필터, 표, 계산 코드가 같다. 공통 동작(필터 저장, 기간 검사, trends 집계 SQL, 예시 숫자 계산)은 [lowserver.md](lowserver.md)에 자세히 있다. 여기서는 **다른 점**을 중심으로 적는다.

## 1. 한눈에

| 항목 | 내용 |
|---|---|
| 메뉴 위치 | 보고서 > 고사용 서버 사용현황 (`Module.php` 의 `REPORT_MENUS`) |
| 주소 | `zabbix.php?action=highserver.list` |
| 상태 | 완성. 단 판정 조건은 **가정**이고, `conf/serverusage.conf.php` 의 `cpu_item_keys` 를 채워야 데이터가 나온다 |
| 5버전 액션 | `highserver` (컨트롤러 `CControllerHighserver.php`, 규칙 `highserver.json`), CSV 는 `highserver.view.csv`. 같은 묶음에 `popup.agent` 가 있었다(용도 확인 필요) |
| 내보내기 | 있음. CSV, Excel (`zabbix.php?action=highserver.export&format=csv` 또는 `xlsx`) |
| 권한 | 사용자 유형 Zabbix User 이상. 호스트는 API 가 사용자 권한대로 거른다 |

## 2. 이 화면은 무엇을 하나

정해진 기간 동안 CPU 를 많이 쓴 서버를 찾는 보고서다.
서버마다 기간 평균 CPU 사용률과 Peak 를 계산하고 **평균이 50% 이상이고 Peak 도 70% 이상인** 서버만 보여 준다.
운영 담당자가 "증설이나 부하 분산이 필요한 서버" 후보를 고를 때 쓴다. 업무시간/업무외시간/전체를 골라 "낮 업무 때문에 바쁜지, 밤 배치 때문에 바쁜지" 를 나눠 볼 수 있다.

## 3. 화면 구성

필터, 표, 버튼, 안내 상자는 저사용 화면과 같다 → [lowserver.md 3장](lowserver.md#3-화면-구성).

다른 점만:

| 항목 | 저사용 | 고사용 |
|---|---|---|
| 제목 | 저사용 서버 사용현황 | 고사용 서버 사용현황 |
| 기준 문구 (표 위 오른쪽, 빨간 글씨) | `※저사용 기준: 평균사용률 30% 이하 / Peak 70% 이하` | `※고사용 기준: 평균사용률 50% 이상 / Peak 70% 이상` |
| 프로파일 키 접두어 | `web.dmove.lowserver.` | `web.dmove.highserver.` (필터, 정렬을 저사용과 따로 저장) |
| 내보내기 파일 이름 | `lowserver_날짜_시각.csv` | `highserver_날짜_시각.csv` |

```
고사용 서버 사용현황                                [CSV 내보내기] [Excel 내보내기]
┌ 필터 (저사용과 같음) ─────────────────────────────────────────────────────────┐
│ 호스트 그룹 / 호스트          시작 / 종료 / ※최대 1년 / 시간(업무·업무외·전체) │
└─────────────────────────────────────────────────────────────────────────────┘
                               ※고사용 기준: 평균사용률 50% 이상 / Peak 70% 이상
 호스트 ▲ | IP       | Description      | 평균CPU사용률(%) | Peak(%)
 srv-21   | 192.0.2.21| 업무 서버 설명   | 78.40%           | 97.10%
```

## 4. 어떻게 만들었나 (동작 순서)

### 4.1 이 화면만의 코드

`HighServerList` 는 공통 컨트롤러 `ServerUsageController` 를 상속하고 네 가지만 채운다.

| 메소드 | 값 |
|---|---|
| `getPageKey()` | `'highserver'` → 액션 `highserver.list` / `highserver.export`, 프로파일 `web.dmove.highserver.` |
| `getPageTitle()` | `Lang::t('High usage servers')` → "고사용 서버 사용현황" |
| `getCriteriaText()` | `Lang::t('* High usage criteria: average usage %1$s%% or more / Peak %2$s%% or more', high_avg, high_peak)` |
| `matches()` | 아래 |

```php
// HighServerList::matches()
return $avg >= $thresholds['high_avg'] && $peak >= $thresholds['high_peak'];
```

- 평균이 `high_avg`(50) **이상** 그리고 Peak 가 `high_peak`(70) **이상** 이면 남긴다.

### 4.2 동작 순서

요청 → `init()` → `checkInput()` → `checkPermissions()` → `doAction()`(필터 저장/읽기, 기간 검사, 정렬, `getRows()`, 내보내기 또는 화면) 순서는 저사용과 완전히 같다 → [lowserver.md 4.2](lowserver.md#42-요청이-들어와서-화면이-그려질-때까지).

평균/Peak 계산(`ServerUsageService::getUsage()`)도 같다 → [lowserver.md 4.3](lowserver.md#43-평균과-peak-계산-serverusageservicegetusage). 요점만 다시 적으면:

1. `item.get` 으로 CPU 아이템을 찾는다(`cpu_item_keys` 의 `exact` / `prefix`).
2. `trends`(1시간 요약)에서 `clock + 시간대차이` 로 한국 날짜, 시각, 요일을 계산한다.
3. 아이템별·날짜별 **하루 평균 = 시간별 평균의 평균**, **하루 최대 = 시간별 최대의 최대**.
4. **기간 평균 = 하루 평균들의 평균**, **Peak = 하루 최대들의 최대**.
5. 업무시간이면 월~금 + 09~18시, 업무외시간이면 모든 요일의 나머지 시간, 전체면 다.

### 4.3 예시 숫자로 판정해 보기

[lowserver.md 4.3 (7)](lowserver.md#7-예시-숫자로-따라가기)의 서버(전체 17.08% / 95.00%, 업무시간 45.00% / 95.00%)는 평균이 50 미만이라 고사용 화면 어느 시간 선택에도 나오지 않는다.

다른 서버 네 대로 판정을 보자 (기준 50 / 70).

| 서버 | 기간 평균 | Peak | 평균 ≥ 50? | Peak ≥ 70? | 지금 코드("그리고") | 만약 "또는" 이라면 |
|---|---|---|---|---|---|---|
| A | 62.40 | 100.00 | 예 | 예 | **나옴** | 나옴 |
| B | 80.00 | 65.00 | 예 | 아니오 | 안 나옴 | 나옴 |
| C | 30.00 | 98.00 | 아니오 | 예 | 안 나옴 | 나옴 |
| D | 50.00 | 70.00 | 예 (같음 포함) | 예 (같음 포함) | **나옴** | 나옴 |

- B 는 "꾸준히 바쁘지만 튀지는 않는" 서버, C 는 "평소엔 한가하고 가끔 튀는" 서버다. 5버전이 "또는" 이었다면 B, C 도 나와야 한다. 그래서 7장에서 5버전 소스 확인이 필요하다.
- D 처럼 기준과 정확히 같은 값도 "이상" 이라 포함된다. 단 비교는 반올림 전 값으로 한다. 예를 들어 실제 평균이 49.996 이면 화면에는 `50.00%` 로 보이지만 판정에서는 빠진다.

### 4.4 `business_peak_whole_day` 가 고사용에 주는 영향

업무시간을 골랐을 때 Peak 를 "업무일 하루 전체의 최대" 로 잡으면(기본값 `true`, 5버전과 같다) Peak 가 커진다.

- 저사용 화면에서는 Peak 가 커지면 **덜** 나온다(Peak ≤ 70 을 통과하기 어려워짐).
- 고사용 화면에서는 Peak 가 커지면 **더** 나온다(Peak ≥ 70 을 통과하기 쉬워짐).

예: 업무시간 평균 55, 업무시간 안의 최대 65, 새벽 백업 때 최대 95 인 서버

| 설정 | 업무시간 Peak | 고사용 판정 |
|---|---|---|
| `true` (기본) | 95 | 나옴 (55 ≥ 50, 95 ≥ 70) |
| `false` | 65 | 안 나옴 (65 < 70) |

"업무시간 때문에 바쁜 서버" 를 찾으려는 목적이라면 `false` 가 더 맞다. 바꾸려면 합의가 필요하다(8장).

## 5. 5버전과 비교

공통 부분(요약표 없앰, 시간 구간, 요일, IP, Description, 그룹 필터, 아이템 찾기, 내보내기 등)은 [lowserver.md 5장](lowserver.md#5-5버전과-비교)과 같다. 고사용 화면에서 다른 점:

| 항목 | 5버전 | 7버전 | 왜 |
|---|---|---|---|
| 판정 조건 | 컨트롤러 소스를 확인하지 못함. 5버전 화면의 [기준설정] 창에서 "고사용 평균 CPU 사용률 50 % 이상", "고사용 Peak기준 70 % 이상" 은 확인했다 (2026-11-23) | "평균 ≥ 50 **그리고** Peak ≥ 70" | 기준값과 "이상"은 확인됨. 두 조건이 그리고인지 또는인지는 여전히 가정 |
| 표 구성 | 규칙 파일 `highserver.json`: 호스트(정렬), IP, description, 평균CPU사용율(%)(정렬), Peak(%) | 같은 5열. 호스트/평균 정렬 | 5버전과 같게 |
| 기준 값 | 저사용과 같은 [기준설정] 창 하나에서 저사용 2개, 고사용 2개 값(1~99)을 함께 바꿨다. 저장 위치는 미확인 | `conf/serverusage.conf.php` 의 `high_avg`, `high_peak` | `popup.lowserver` 컨트롤러를 받지 못함 |
| `popup.agent` | 5버전 액션 목록에서 이 화면 근처에 있었지만 이 화면 것이 아니다. SW 인벤토리의 Agent 미설치/예외 입력 창이다 (2026-11-23 확인) | 옮기지 않음 | → [swinventory.md](swinventory.md) 7장 |
| 5버전에서 확인된 화면 예 | 표의 행이 모두 평균 50% 이상, Peak 85% 이상이었다 | - | "그리고" 가정과 어긋나지 않는다. 다만 "또는" 을 배제하는 근거는 아니다 |

## 6. 설정

파일: `conf/serverusage.conf.php` (저사용과 공용). 전체 표는 [lowserver.md 6장](lowserver.md#6-설정).

이 화면에서 쓰는 값:

| 값 | 기본 | 뜻 |
|---|---|---|
| `cpu_item_keys.exact`, `.prefix` | `[]` | CPU 사용률 아이템 키. 비면 노란 "설정이 필요합니다" 상자 + 빈 표 |
| `thresholds.high_avg` | 50 | 고사용 평균 기준(%). 이 값 **이상** |
| `thresholds.high_peak` | 70 | 고사용 Peak 기준(%). 이 값 **이상** |
| `business_hours`, `business_days`, `business_peak_whole_day`, `max_period_days` | `[9,18]`, 월~금, `true`, 366 | 저사용과 같은 뜻 |
| `memory_item_keys` | `[]` | 이 화면은 쓰지 않는다. 일일점검의 [Memory 사용률 Top 10] 팝업용 |

## 7. 비어 있는 부분과 가정

| 구분 | 내용 | 확인할 5버전 파일 | 고칠 곳 |
|---|---|---|---|
| 가정 | 평균 50% 이상 **그리고** Peak 70% 이상. 기준값과 방향(이상)은 5버전 화면에서 확인. "그리고/또는"은 미확인 | `controllers/CControllerHighserver.php` | `actions/reports/highserver/HighServerList.php` 의 `matches()` 한 줄, 값은 `conf/serverusage.conf.php` (UNIMPLEMENTED 3장, CHECKLIST 2-1) |
| TODO(고객환경) | CPU 아이템 키 3종 | 키 클래스(`...Key.php`)의 `serverCpuUsage()` | `conf/serverusage.conf.php` (CHECKLIST 1-3) |
| 없음 | [기준설정] 버튼. 저사용과 같은 창 하나를 쓴다 (고사용 전용 팝업은 없다) | `popup.lowserver` 컨트롤러와 뷰 | 지금은 설정 파일 (UNIMPLEMENTED 2장, CHECKLIST 3-5) |
| 합의만 필요 | 업무시간 Peak = 업무일 하루 전체 최대 | - | `business_peak_whole_day` |

`CControllerHighserver.php` 는 보고서 숫자에 직접 영향을 주므로 UNIMPLEMENTED 7장 우선순위 2번이다.

## 8. 개선점

### 이미 반영한 개선

저사용 화면과 같다 → [lowserver.md 8장](lowserver.md#8-개선점). (trends 직접 집계, 주말 제외 명시, API 호출 수 감소, 요청값 SQL 제거, 입력 검증, 기간 오류 표시, Excel, 다국어)

고사용 화면만의 것: 판정 조건을 `matches()` 한 줄로 모아 두어, 5버전 소스를 확인한 뒤 고치기 쉽다.

### 남은 개선 제안

| 제안 | 효과 | 5버전과 결과가 달라지나 (합의 필요?) |
|---|---|---|
| `business_peak_whole_day` 를 `false` 로 | 업무시간을 골랐을 때 새벽 배치 때문에 고사용으로 잡히는 서버가 빠진다(4.4 예시) | 목록이 줄어든다 → 합의 필요 |
| 데이터가 없는 서버를 따로 표시 | 수집이 끊긴 서버를 놓치지 않는다 | 별도 영역이면 기존 표는 같다 |
| [기준설정]을 관리자 설정 화면 + DB 저장으로 | 설정 파일 수정 없이 기준 변경 | 사용자별 → 공통이면 다르다 → 합의 필요 |
| Peak 열 정렬 | 가장 많이 튄 서버부터 보기 | 같다 |
| 호스트 이름에 그래프 링크 | 원인 시간대 바로 확인 | 같다 |
| "평균 기준만" / "Peak 기준만" 같은 보기 선택 | 4.3 의 B, C 유형 서버도 찾을 수 있다 | 기본 보기를 지금처럼 두면 같다 |

## 9. 확인 방법

### 테스트 환경 (http://127.0.0.1:18970, Admin / zabbix, `dev/compose.yaml`)

1. 보고서 > 고사용 서버 사용현황. 키 설정이 비어 있으면 노란 안내 상자와 빈 표, 기준 문구 `※고사용 기준: 평균사용률 50% 이상 / Peak 70% 이상` 이 보이면 정상.
2. [lowserver.md 9장](lowserver.md#9-확인-방법)의 3번(시험 호스트 `test-cpu` + trends 데이터 + 로컬 설정에 `test.cpu`)을 그대로 한다.
3. 필터: 호스트 `test-cpu`, 2026-01-05 ~ 2026-01-10.
   - 전체사용현황 → 평균 17.08 < 50 이라 빈 표.
   - 로컬 설정 `high_avg` 를 40 으로 → 업무시간 사용현황에서 **45.00% / 95.00%** 로 나온다.
   - 이어서 `high_peak` 를 80 으로 → 그래도 나온다(Peak 95, 하루 전체 최대). `business_peak_whole_day` 를 `false` 로 → Peak 70 < 80 이라 **사라진다**(4.4 의 효과).
4. 저사용 화면에서 고른 필터가 고사용 화면에 따라오지 않는지(프로파일 키가 다름).
5. 내보내기 파일 이름이 `highserver_...` 인지, 내용이 화면과 같은지.
6. 끝나면 로컬 설정을 되돌린다.

### 고객환경에서 5버전과 비교

1. 먼저 `CControllerHighserver.php` 의 판정 부분을 읽고 `matches()` 와 같은지 본다. 다르면 `matches()` 를 고친다.
2. 5버전과 7버전에서 같은 그룹, 같은 기간, 같은 시간 선택으로 열어 목록과 숫자를 비교한다. 5버전 화면 아래 "N 중 N건" 건수부터 맞춰 본다.
3. 목록이 다르면: 7버전에 없는 서버가 "평균만 넘음" 또는 "Peak 만 넘음" 유형이면 판정이 "또는" 인 것이다. 숫자 자체가 다르면 [lowserver.md 9장](lowserver.md#고객환경에서-5버전과-비교)의 점검 순서를 따른다.

## 10. 파일 목록

### 이 화면의 파일

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/actions/reports/highserver/HighServerList.php` | 고사용 화면 컨트롤러. 이름, 제목, 기준 문구, `matches()` |
| `ui/modules/dmove/actions/reports/serverusage/ServerUsageController.php` | 저사용/고사용 공통 컨트롤러 |
| `ui/modules/dmove/views/reports/serverusage.list.php` | 저사용/고사용 공통 뷰 |
| `ui/modules/dmove/includes/ServerUsageService.php` | CPU 아이템 찾기, trends 집계 |
| `ui/modules/dmove/conf/serverusage.conf.php` | 아이템 키, 판정 기준, 업무시간 등 |
| `ui/modules/dmove/manifest.json` | `highserver.list`, `highserver.export` 등록 |
| `ui/modules/dmove/Module.php` | 보고서 메뉴 등록 |
| `ui/modules/dmove/lang/ko.php` | 한국어 문구 |

### 같이 쓰는 부품

| 파일 | 역할 |
|---|---|
| `includes/filter/HostFilter.php` | 호스트 그룹/호스트 필터 |
| `includes/export/ExportTrait.php`, `ExportButtons.php`, `views/export/layout.file.php` | CSV/Excel 내보내기 |
| `includes/HostHelper.php` | 대표 IP |
| `includes/ItsmRepository.php`, `conf/itsm.conf.php` | ITSM Description |
| `includes/Lang.php` | 번역 |
