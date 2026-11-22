# 서버 시간 확인 (`uptime.list`)

## 1. 한눈에

| 항목 | 내용 |
|---|---|
| 메뉴 위치 | 보고서 > 서버 시간 확인 (코어 보고서 메뉴 뒤에 붙는 모듈 메뉴 중 첫 번째. `Module.php` 의 `REPORT_MENUS`) |
| 주소 | `zabbix.php?action=uptime.list` |
| 상태 | **껍데기**. 필터와 표 제목만 있고 데이터 조회(`getRows()`)가 비어 있다. 화면에 "데이터 조회가 아직 구현되지 않았습니다." 안내가 뜬다 |
| 5버전 액션 | `uptime.view` (컨트롤러 `CControllerUptimeView.php`) |
| 내보내기 | 없음 (5버전에도 내보내기 버튼이 있었는지는 확인 필요) |
| 권한 | 로그인한 사용자 중 게스트가 아닌 사람(`getUserType() >= USER_TYPE_ZABBIX_USER`). 표에 나올 호스트는 API 가 사용자 권한으로 거른다(구현 후) |

## 2. 이 화면은 무엇을 하나

서버별로 "지금 몇 일째 켜져 있는지(가동 시간)"와 "언제 부팅했는지"를 한 표로 보여 주는 화면이다.
운영 담당자는 오래 재부팅하지 않은 서버(예: 180일 이상)를 골라 패치/재부팅 계획을 세우거나, 예상하지 못한 재부팅이 있었는지 확인하는 데 쓴다.
필터의 "서버 Uptime N day 이상"으로 오래 켜져 있는 서버만 골라 볼 수 있다.
(화면 이름이 "서버 시간"이라 서버 시계가 맞는지 보는 화면으로 오해하기 쉽지만, 7버전 코드에 적힌 5버전 표시 예시로 보면 "서버 Time" 열은 시계가 아니라 가동 시간이다. 4장 참고)

## 3. 화면 구성

### 필터

| 이름 (한국어 화면) | 입력 방식 | 기본값 | 저장 여부 |
|---|---|---|---|
| 호스트 그룹 | 선택창(멀티셀렉트) + [선택] 팝업. 하위 그룹 포함 | 비어 있음(전체) | 저장 (`web.dmove.uptime.filter.groupids`) |
| 호스트 | 선택창(멀티셀렉트) + [선택] 팝업 | 비어 있음(전체) | 저장 (`...filter.hostids`) |
| OS/Version | 텍스트 | 빈 값 | 저장 (`...filter.os`) |
| 서버 Uptime | 텍스트(숫자), 흐린 안내 글자 "day 이상" | 빈 값 | 저장 (`...filter.uptime_days`). 숫자가 아니면 빈 값으로 저장 |

지금은 저장만 되고 결과에는 아무 영향이 없다(데이터가 없으므로).

### 표의 열

| 열 | 값의 출처 (구현 시) | 표시 방식 |
|---|---|---|
| 호스트 | 호스트 이름 | 줄바꿈 안 함 |
| IP | 대표 IP | 줄바꿈 안 함 |
| Description | 확인 필요 (Zabbix 호스트 설명 또는 ITSM description) | 긴 글은 줄바꿈 |
| OS | 확인 필요 (인벤토리 `os` 또는 아이템 값) | 글자 그대로 |
| 서버 Time | 가동 시간. 5버전 형식 예: `158 일, 13:18:45` | 줄바꿈 안 함 |
| 서버 start | 부팅 시각. 5버전 형식 예: `2026/06/14 05:13:26` | 줄바꿈 안 함 |

정렬 링크는 없다(제목이 일반 글자). 표 아래에 페이지 이동이 있다.

### 텍스트 스케치

```
서버 시간 확인
┌ 필터 ─────────────────────────────────────────────┐
│ 호스트 그룹  [검색 문자열을 입력      ] [선택]    │
│ 호스트       [검색 문자열을 입력      ] [선택]    │
│ OS/Version   [            ]                       │
│ 서버 Uptime  [ day 이상   ]                       │
│ [적용] [리셋]                                     │
└───────────────────────────────────────────────────┘
(노란 안내) 데이터 조회가 아직 구현되지 않았습니다.
호스트 | IP | Description | OS | 서버 Time | 서버 start
(데이터 없음)
```

## 4. 어떻게 만들었나 (동작 순서)

1. **CSRF 검사 끄기** - `UptimeList::init()` 에서 `disableCsrfValidation()`.
2. **입력 검증** - `UptimeList::checkInput()`
   `page`, `filter_set`, `filter_rst`, `filter_os`(문자열), `filter_uptime_days`(문자열) + `HostFilter::INPUT_FIELDS`(`filter_groupids`, `filter_hostids` = 숫자 ID 배열).
   일수를 문자열로 받는 이유: 빈 칸으로도 넘어오기 때문이다. 숫자 검사는 다음 단계에서 한다.
3. **권한** - `UptimeList::checkPermissions()`: `getUserType() >= USER_TYPE_ZABBIX_USER`.
4. **필터 저장/초기화** - `UptimeList::doAction()` 1번 블록
   - [적용]: `HostFilter::save()` 로 그룹/호스트 ID 배열을 `CProfile::updateArray()` 로 저장, OS 와 일수는 `CProfile::update()`.
   - 일수는 `normalizeDays()` 를 거친다. `ctype_digit()` 로 0~9 로만 된 글자인지 본다. 예) `"30"` -> `"30"`, `"30일"` -> `""`, `"-5"` -> `""`.
   - [리셋]: `HostFilter::reset()` 과 `CProfile::deleteIdx()`.
5. **필터 읽기** - `HostFilter::load()` 와 `CProfile::get()`.
6. **데이터 조회** - `UptimeList::getRows()`
   **지금은 `return [];` 만 있다.** 주석에 행 형식과 구현 순서 예시가 적혀 있다(8장의 설계 초안과 같은 방향).
7. **뷰로 넘기기** - `HostFilter::toViewData()` 로 선택창에 보일 이름을 만들고, `'data_ready' => false` 를 넘긴다. `CPagerHelper::paginate()` 로 페이징.
8. **화면** - `views/reports/uptime.list.php`
   `HostFilter::addRows()` 로 그룹/호스트 두 줄을 만들고 OS, Uptime 입력을 붙인다. `data_ready` 가 `false` 면 `makeMessageBox(ZBX_STYLE_MSG_WARNING, ...)` 로 안내 상자를 띄운다.

## 5. 5버전과 비교

| 주제 | 5버전 | 7버전 | 왜 |
|---|---|---|---|
| 필터 구성 | 호스트 그룹, 호스트, OS/Version, 서버 Uptime("day 이상") | 같다 | 그대로 옮김 |
| 표 구성 | 호스트, IP, Description, OS, 서버 Time, 서버 start | 같다 | 그대로 옮김 |
| 데이터 | `DBselect` 로 items / history 표를 직접 읽은 것으로 보인다(정확한 키와 방법은 확인 필요) | 없음. 구현할 때 API(`host.get`, `item.get`)를 쓰도록 주석에 적어 둠 | 직접 SQL 은 사용자 권한을 무시하고, 스키마 이름이 다르면 깨진다 |
| 일수 입력 검사 | 확인 필요 | 숫자만 저장, 아니면 필터 없음 | 잘못된 값으로 오류가 나지 않게 |
| 메뉴 | 보고서 아래 | 보고서 아래 (모듈 메뉴 중 첫 번째) | 5버전 순서 유지 |

## 6. 설정

없음. (구현할 때 아이템 키를 설정 파일로 뺄지 정한다. 표준 키 `system.uptime` 을 그대로 쓴다면 설정이 필요 없을 수도 있다)

## 7. 비어 있는 부분과 가정

| 자리 | 내용 | 확인할 5버전 파일 |
|---|---|---|
| `TODO(고객환경)` `UptimeList::getRows()` | 데이터 조회 전체 | `controllers/CControllerUptimeView.php`, 그 뷰, 컨트롤러가 쓰는 트레잇 |
| 서버 Time / 서버 start 의 출처 | 어떤 아이템 키에서 읽는가 | 위 컨트롤러와 트레잇, 키 클래스(`...Key.php`) |
| OS, Description 의 출처 | 인벤토리 `os` 인지 아이템 값인지, Zabbix 설명인지 ITSM 인지 | 위와 같다 |
| "N day 이상" 비교 | 가동 초 >= N x 86400 인지, 날짜 단위로 자르는지 | 위와 같다 |
| `TODO(고객환경)` `data_ready` | 구현하면 `true` 로 바꾼다 | - |
| `TODO(고객환경)` 권한 | 게스트 제외 모두로 가정 | `src/menu.inc.php` |

`UNIMPLEMENTED.md` 1장, `CUSTOMER_ENV_CHECKLIST.md` 3-1 과 같은 내용이다.

## 8. 개선점

### 이미 반영한 개선

- 필터와 표 틀, 사용자별 필터 저장, 호스트 그룹 선택 시 하위 그룹 포함(코어 화면과 같은 동작).
- 일수 입력값 검사.
- 데이터는 직접 SQL 이 아니라 API 로 읽도록 방향을 정해 둠(주석).

### 남은 개선 제안

| 제안 | 효과 | 5버전과 결과가 달라지나 (고객 합의) |
|---|---|---|
| 표준 템플릿의 `system.localtime` 시각 차이 트리거(예: 코어 리눅스/윈도우 Agent 템플릿의 "시스템 시간 불일치" 류 트리거, `fuzzytime()`)를 켜서 실시간 알람 병행 | 시계가 틀어진 서버를 화면을 열지 않아도 바로 알 수 있다 | 화면 결과와는 별개. 알람이 새로 생기므로 운영 합의 필요. 고객 서버에 그 템플릿이 연결되어 있는지 확인 필요 |
| "시각 차이(초)" 열 추가 (`system.localtime` 값 - 수집 시각) | 이름 그대로 "서버 시간 확인"도 할 수 있다 | 열이 하나 늘어난다. 합의 권장 |
| 데이터가 오래된(수집이 끊긴) 서버 표시 | 가동 시간이 멈춘 값으로 보이는 착시를 막는다 | 5버전에 없던 표시. 합의 권장 |
| 가동 시간 숫자 열로 정렬 | 오래 켜진 순서로 바로 본다 | 표시만 바뀜 |
| 내보내기(CSV/Excel) 추가 | 재부팅 계획 자료로 바로 쓴다 | 기능 추가 |

### 7.0 데이터로 새로 만든다면 (설계 초안, **확정 아님**)

5버전 소스를 끝내 볼 수 없을 때 쓰는 초안이다. 열 이름과 형식은 5버전 화면에 맞추되, 값의 출처는 7.0 표준 데이터로 정한 것이다. 5버전과 숫자가 같다는 보장이 없으므로 고객과 결과를 비교한 뒤 확정한다.

**쓸 데이터**

| 데이터 | 7.0 에서 | 비고 |
|---|---|---|
| 가동 시간 | 에이전트 기본 아이템 `system.uptime` (초) | 코어 리눅스/윈도우 Agent 템플릿에 들어 있다. 고객 서버에 연결되어 있는지 확인 필요 |
| 서버 시각 | 에이전트 기본 아이템 `system.localtime` (유닉스 초) | 시각 차이 열/트리거용 (선택) |
| OS | 호스트 인벤토리 `os` 필드 (SW 인벤토리와 같은 출처) | 비어 있으면 아이템 값 사용을 검토 |
| Description | `ItsmRepository::fetchDescriptions()` 에서 찾고, 없으면 Zabbix 호스트 설명 | 저사용/고사용 화면과 같은 방식 |
| IP | `HostHelper::mainIp()` | 공용 부품 |

**단계**

1. 호스트: `API::Host()->get(HostFilter::toApiOptions($host_filter) + ['output' => ['hostid','host','name','description'], 'selectInterfaces' => HostHelper::INTERFACE_FIELDS, 'selectInventory' => ['os'], 'preservekeys' => true])`. 비활성 호스트를 뺄지(`'monitored_hosts' => true`)는 합의 사항.
2. 아이템을 **한 번에**: `API::Item()->get(['output' => ['itemid','hostid','lastvalue','lastclock'], 'hostids' => array_keys($hosts), 'filter' => ['key_' => 'system.uptime'], 'monitored' => true])`. 결과를 hostid 로 묶는다. (시각 차이 열을 넣으면 `key_` 에 `system.localtime` 도 넣는다)
3. 행 만들기 (예: `lastvalue` = 13,700,325초, `lastclock` = 2026-11-20 14:00:00 수집)
   - 서버 Time = 가동 시간 표시: 13,700,325 / 86,400 = 158일, 나머지 49,125초 = 13:38:45 -> `158 일, 13:38:45`
   - 서버 start = `lastclock - lastvalue` 를 날짜로: `date('Y/m/d H:i:s', ...)`. 사용자 시간대로 표시된다.
   - 아이템이 없거나 `lastclock` 이 0 이면 '-' 로 표시(데이터 없음).
4. 필터
   - OS/Version: OS 값에 대소문자 무시 부분 일치 (`mb_stripos`).
   - 서버 Uptime N day 이상: `lastvalue >= N * 86400`. 예) N=180 이면 15,552,000초 이상. 위 예(13,700,325초)는 빠진다.
5. 정렬(기본 호스트 이름), `CPagerHelper::paginate()`, `'data_ready' => true`.
6. (선택) 내보내기: `manifest.json` 에 `uptime.export` 추가, `ExportTrait` 를 꽂는다(`includes/export/ExportTrait.php` 머리말 순서).

## 9. 확인 방법

### 테스트 환경 (http://127.0.0.1:18972, `Admin` / `zabbix`, `dev/compose.yaml`)

1. 보고서 > 서버 시간 확인을 연다 (`zabbix.php?action=uptime.list`).
   - 기대: 필터 4줄, 표 제목 6개, 노란 안내 "데이터 조회가 아직 구현되지 않았습니다.", 빨간 PHP 경고 없음.
2. 호스트 그룹에서 아무 그룹을 고르고, 서버 Uptime 에 `abc` 를 넣고 [적용].
   - 기대: 오류 없이 다시 열린다. 그룹은 선택된 채로 남고, Uptime 칸은 빈 값이 된다(숫자가 아니므로).
3. Uptime 에 `30` 을 넣고 [적용] -> 다른 메뉴에 갔다가 돌아온다.
   - 기대: `30` 이 그대로 남아 있다. [리셋]을 누르면 모두 비워진다.
4. 구현 후: 데이터 수집 > 호스트에서 Zabbix server 호스트에 `system.uptime` 아이템이 있는지(기본 리눅스 템플릿) 보고, 최신 데이터의 값과 화면의 서버 Time 이 맞는지 확인한다.

### 고객환경에서 5버전과 비교 (구현 후)

1. 같은 호스트 그룹으로 5버전 `uptime.view` 와 7버전 화면을 같은 시각에 연다.
2. 서버 start 가 몇 초 이내로 같으면 출처가 같다고 본다. 크게 다르면 5버전이 다른 키나 다른 계산을 쓴 것이므로 `CControllerUptimeView.php` 를 확인한다.
3. Uptime 필터(예: 100)를 같게 넣고 행 수를 비교한다. 경계값(정확히 100일 근처)에서만 다르면 비교 방식(초 단위인지 날짜 단위인지) 차이다.

## 10. 파일 목록

### 이 화면의 파일

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/actions/reports/uptime/UptimeList.php` | 컨트롤러. 입력 검증, 권한, 필터 저장/읽기. `getRows()` 는 비어 있음(구현 예시 주석 있음) |
| `ui/modules/dmove/views/reports/uptime.list.php` | 화면 뷰. 필터, 표, "구현 안 됨" 안내 |
| `ui/modules/dmove/manifest.json` | `uptime.list` 액션 등록 |
| `ui/modules/dmove/Module.php` | `REPORT_MENUS` 의 `'Server time check'` |

### 같이 쓰는 부품

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/includes/filter/HostFilter.php` | 호스트 그룹/호스트 선택 필터(저장, 읽기, API 조건, 뷰 두 줄) |
| `ui/modules/dmove/includes/Lang.php`, `ui/modules/dmove/lang/ko.php` | 다국어 문구 ("서버 Uptime", "day 이상", "서버 Time", "서버 start") |
| (구현 시) `ui/modules/dmove/includes/HostHelper.php`, `ItsmRepository.php`, `includes/export/` | 대표 IP, ITSM Description, 내보내기 |
