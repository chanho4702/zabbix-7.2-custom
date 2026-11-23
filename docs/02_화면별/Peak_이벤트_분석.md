# Peak 이벤트 분석 (`peakevent.list`)

## 1. 한눈에

| 항목 | 내용 |
|---|---|
| 메뉴 위치 | 보고서 > Peak 이벤트 분석 (`Module.php` 의 `REPORT_MENUS`, 일일점검 다음) |
| 주소 | `zabbix.php?action=peakevent.list` |
| 내보내기 주소 | `zabbix.php?action=peakevent.export&format=csv` 또는 `format=xlsx` |
| 상태 | 완성. 단, 집계 기준(무엇을 세는지)은 가정이다 |
| 5버전 액션 | `peak_event.php` (`zabbix.php?action=` 형식이 아닌 독립 PHP 파일) |
| 내보내기 | 있음 (CSV). 5버전에는 내보내기 버튼이 확인되지 않았다 |
| 권한 | 로그인한 사용자 모두 (`getUserType() >= USER_TYPE_ZABBIX_USER` 이고 `!CWebUser::isGuest()`). 보이는 트리거/호스트는 API 가 사용자 권한으로 걸러 준다 |

## 2. 이 화면은 무엇을 하나

고른 기간 동안 **장애(PROBLEM) 이벤트가 몇 번 났는지를 트리거별로 세어**, 많은 순서로 최대 100개를 보여 준다.
같은 트리거가 장애와 정상을 자주 오갈수록(이른바 "깜빡이는" 트리거) 숫자가 커진다.
운영자는 이 표를 보고 "어느 서버의 어떤 감시 항목이 가장 시끄러운가"를 찾고, 임계값을 손볼 트리거나 실제로 불안정한 서버를 고른다.
코어의 "보고서 > Top 100 triggers" 와 같은 집계에 IP 주소와 Description(호스트 설명) 열을 붙인 화면이라고 보면 된다.

## 3. 화면 구성

### 필터

| 이름 | 입력 방식 | 기본값 | 저장 여부 |
|---|---|---|---|
| 기간 (시작/종료) | 코어 시간 선택기. `now-1h` 같은 표현을 직접 적거나, "어제", "최신 7 일간" 같은 빠른 선택 링크를 누른다. `<` `>` `줌 아웃` 버튼도 있다 | 코어 기본 기간 (최신 1 시간) | 저장됨. 사용자 프로파일 `web.dmove.peakevent.filter` 에 기간이 저장되어 다음에 들어와도 유지된다 |

5버전 화면과 같은 필터가 있다 (2026-11-23 추가): 호스트 그룹 [선택], 호스트 [선택], Peak이벤트 유형(입력, 트리거 이름 부분 일치), 심각도(체크박스). 심각도는 5버전처럼 3개가 아니라 7.0 의 6단계 전체를 체크박스로 보여 준다 (5버전의 Critical / Minor / Warning 이 어느 단계인지 미확인). 필터를 비우면 전체다. 내보내기도 같은 필터로 만들어진다.

### 표의 열

| 열 (한국어 화면) | 값의 출처 | 표시 방식 |
|---|---|---|
| 호스트 | 트리거가 속한 호스트의 표시 이름 (`trigger.get` 의 `selectHosts` → `name`) | 글자 그대로, 줄바꿈 안 함. 링크 아님 |
| IP 주소 | 그 호스트의 인터페이스 중 대표 IP (`HostHelper::mainIp()`) | 글자 그대로 |
| 설명 (`_('Description')`. 5버전 열 이름은 "Description") | Zabbix 호스트의 "설명" 필드 (`host.get` 의 `description`) | 긴 글은 줄바꿈 |
| Peak이벤트 유형 | 트리거 이름. 이름 안의 매크로(`{HOST.NAME}` 등)는 실제 값으로 바뀐다 (`expandDescription`) | 긴 글은 줄바꿈. 링크 아님 |
| 심각도 | 트리거 심각도 (0~5) | 코어 심각도 칸. 이름과 배경색이 관리 > 일반 > 트리거 표시 옵션 설정을 따른다 |
| 상태 변경 횟수 | 기간 안의 장애 이벤트 수 | 숫자. 이 열의 내림차순으로 정렬되어 나온다 (정렬 바꾸기 없음) |

### 버튼/링크/팝업

- 제목 오른쪽: [CSV 내보내기] 버튼. 공용 부품 `ExportButtons::create('peakevent.export')`.
- 표 안의 링크와 팝업은 없다.
- 페이지 나누기 없음. 최대 100줄이 한 화면에 다 나온다.

### 스케치

```
Peak 이벤트 분석                                    [CSV 내보내기]
                                    < 줌 아웃 >  [최신 1 시간] [필터]
┌──────────────────────────────────────────────────────────────────┐
│ 시작 [now-1h      ][달력]  종료 [now        ][달력]   [적용]     │
│ 최신 2 일간 | 어제 | 오늘 | 최신 5 분간 ...                       │
└──────────────────────────────────────────────────────────────────┘
호스트   | IP 주소  | 설명        | Peak이벤트 유형      | 심각도 | 상태 변경 횟수
host-A   | 10.0.0.1 | 개발 DB     | 메모리 사용률 90% .. | 경고   | 12
host-B   | 10.0.0.2 | 웹 서버     | CPU 사용량 95% ..    | 높음   | 6
...
```

## 4. 어떻게 만들었나 (동작 순서)

1. **요청 받기.** 메뉴를 누르면 `zabbix.php?action=peakevent.list` 로 들어온다. `manifest.json` 이 이 액션을 `PeakEventList` 클래스와 `views/reports/peakevent.list.php` 뷰, `layout.htmlpage` 레이아웃에 연결해 둔다. 내보내기(`peakevent.export`)도 **같은 클래스**를 쓰고 레이아웃만 `export/layout.file` 이다.

2. **CSRF 검사 끄기.** `PeakEventList::init()` 에서 `disableCsrfValidation()`. 조회만 하는 화면이라 그렇다.

3. **입력 검사.** `PeakEventList::checkInput()`

   ```php
   $fields = ['from' => 'range_time', 'to' => 'range_time'] + self::exportInputFields();
   $ret = $this->validateInput($fields) && $this->validateTimeSelectorPeriod();
   ```

   - `range_time` : `now-1h`, `now`, `2026-01-02 03:04:05` 같은 시간 표현만 받는다.
   - `validateTimeSelectorPeriod()` : 코어 함수. 시작이 종료보다 앞인지, 기간이 최소/최대 범위 안인지 본다.
   - `exportInputFields()` : `format` 이 `csv` 또는 `xlsx` 인지 본다 (`ExportTrait`).
   - 틀리면 `CControllerResponseFatal` (오류 화면).

4. **권한 검사.** `PeakEventList::checkPermissions()` : `getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest()` (게스트 계정 제외).

5. **기간 정하기.** `PeakEventList::doAction()` 의 1) 부분

   ```php
   $timeselector_options = ['profileIdx' => 'web.dmove.peakevent.filter', 'profileIdx2' => 0, 'from' => null, 'to' => null];
   $this->getInputs($timeselector_options, ['from', 'to']);   // 요청에 from/to 가 있으면 채운다
   updateTimeSelectorPeriod($timeselector_options);           // 있으면 프로파일에 저장한다
   $timeline = getTimeSelectorPeriod($timeselector_options);  // 저장된 값(없으면 기본 1시간)으로 계산
   ```

   - 세 함수는 모두 코어 `ui/include/func.inc.php` 에 있다.
   - 결과의 `from_ts`, `to_ts` 가 유닉스 시간(초)이다. 예: "어제"를 고르면 `from_ts` = 어제 00:00:00, `to_ts` = 어제 23:59:59.

6. **이벤트 세기.** `PeakEventList::getRows()` 의 1)

   ```php
   $counts = API::Event()->get([
       'countOutput' => true, 'groupBy' => ['objectid'],
       'source' => EVENT_SOURCE_TRIGGERS, 'object' => EVENT_OBJECT_TRIGGER,
       'value' => TRIGGER_VALUE_TRUE,
       'time_from' => $time_from, 'time_till' => $time_till,
       'sortfield' => ['rowscount'], 'sortorder' => ZBX_SORT_DOWN,
       'limit' => self::MAX_ROWS   // 100
   ]);
   ```

   - `countOutput` + `groupBy objectid` : 이벤트 목록 대신 "트리거ID별 개수"만 받는다. DB 가 `GROUP BY` 로 세므로 이벤트가 수십만 건이어도 빠르다.
   - `source/object` : 트리거가 만든 이벤트만 (내부 이벤트, 디스커버리 이벤트 제외).
   - `value = TRIGGER_VALUE_TRUE` : 장애 이벤트만. 복구(OK) 이벤트는 세지 않는다.
   - `sortfield rowscount` 내림차순 + `limit 100` : 많은 순서로 100개.
   - 결과 예: `[['objectid' => '23001', 'rowscount' => '12'], ['objectid' => '23050', 'rowscount' => '6'], ...]`
   - 코어 "Top 100 triggers" 화면(`ui/app/controllers/CControllerTopTriggersList.php`)이 같은 호출을 쓴다. 차이는 코어 쪽에 호스트 그룹/호스트/심각도/태그/이름 조건이 더 있다는 것뿐이다.
   - 이어서 `array_column($counts, 'rowscount', 'objectid')` 로 `[트리거ID => 개수]` 모양으로 바꾼다. 배열 순서가 곧 "많은 순서"다.

7. **트리거 정보.** `getRows()` 의 2) : `API::Trigger()->get()` 에 `triggerids` 로 100개를 한 번에 넘겨 이름(`description`), 심각도(`priority`), 소속 호스트(`selectHosts`)를 받는다. `expandDescription => true` 로 이름 안 매크로를 풀고, `preservekeys => true` 로 결과를 트리거ID로 찾을 수 있게 한다.

8. **호스트 정보.** `getRows()` 의 3) : 트리거들의 호스트ID를 모아(배열 키로 넣어 중복 제거) `API::Host()->get()` 을 **한 번만** 부른다. `description` 과 인터페이스(`HostHelper::INTERFACE_FIELDS` = ip, main, type)를 받는다.

9. **행 만들기.** `getRows()` 의 4) : `[트리거ID => 개수]` 순서대로 돌면서
   - 트리거 조회 결과에 없는 ID(그 사이 삭제됐거나 볼 권한이 없음)는 건너뛴다.
   - 트리거가 여러 호스트에 걸쳐 있으면 **첫 번째 호스트**로 표시한다 (`reset($trigger['hosts'])`).
   - IP 는 `HostHelper::mainIp()` 가 고른다. 규칙: 빈 IP 는 버리고, "기본(main) + 에이전트" 인터페이스 3점 > "기본 + 그 밖의 종류" 2점 > 기본 아님 1점 중 가장 높은 것.
     예: 에이전트 기본 10.0.0.1 과 SNMP 기본 10.0.0.9 가 있으면 10.0.0.1.
   - 결과 한 줄: `['host' => 'host-A', 'ip' => '10.0.0.1', 'description' => '개발 DB', 'trigger' => '...', 'severity' => 2, 'count' => 12]`

10. **내보내기 요청이면 파일로.** `doAction()` 3) : `isExportRequest()` 가 참이면 심각도 숫자를 `CSeverityHelper::getName()` 으로 이름으로 바꾼 뒤 `setExportResponse($title, 'peak_event', 열 정의, $rows)` 로 끝낸다. 파일 이름은 `peak_event_YYYYMMDD_HHMMSS.csv`.

11. **화면 응답.** `doAction()` 4) : `CControllerResponseData` 에 `columns`, `timeline`, `timeline_profile`, `active_tab`(필터 탭 펼침 상태, 프로파일 `web.dmove.peakevent.filter.active`), `rows` 를 담는다.

12. **뷰 그리기.** `views/reports/peakevent.list.php`
    - `CFilter` 에 `addTimeSelector(from, to, true, 프로파일 키)` 로 시간 선택기 탭만 붙인다.
    - `CTableInfo` 로 표를 만든다. 심각도 칸은 `CSeverityHelper::makeSeverityCell()` 이 이름+색을 넣어 준다. 다른 칸은 `CCol` 이라 HTML 이스케이프가 자동이다.
    - 맨 아래 `timeControl.addObject("peakevent", ...)` + `timeControl.processObjects()` 스크립트. 이것이 없으면 기간을 바꿔도 화면이 다시 불리지 않는다. (코어 `reports.auditlog.list.php` 와 같은 방식)

## 5. 5버전과 비교

| 항목 | 5버전 | 7버전 | 이유 |
|---|---|---|---|
| 화면 형식 | 독립 PHP 파일 `peak_event.php` | 모듈 액션 `peakevent.list` | 7버전은 코어 파일을 덮어쓰지 않고 모듈로만 화면을 넣는다 |
| 기간 선택 | 코어 시간 선택기 (기본 최신 1 시간) | 같음. 프로파일 키만 `web.dmove.peakevent.filter` 로 따로 둔다 | 다른 화면과 기간이 섞이지 않게 |
| 집계 기준 | 소스 미확인 (`peak_event.php` 를 아직 받지 못함) | 기간 안 장애 이벤트 수, 트리거별, 상위 100개 | 열 구성이 코어 Top 100 triggers 와 같아 같은 방식으로 가정 |
| 필터 | 호스트 그룹, 호스트, Peak이벤트 유형(입력), 심각도 체크박스(Critical / Minor / Warning) | 호스트 그룹, 호스트, Peak이벤트 유형(부분 일치), 심각도 체크박스(6단계) + 기간 선택 | 2026-11-23 추가. `event.get` 의 `groupids`, `hostids`, `search name`, `severities` 로 거른다 |
| 표의 열 | 호스트, IP 주소, Description, Peak이벤트 유형, 심각도, 상태 변경 횟수. 횟수 내림차순 | 같은 6열, 같은 정렬 | 5버전 화면에서 확인 (2026-11-23). 열 제목 "상태 변경 횟수"도 같다 |
| 호스트 열 | 밑줄 링크였다 | 글자만 | 링크 대상이 확인되지 않아 넣지 않음 |
| 트리거 이름 열 | 점선 밑줄 링크(코어 트리거 메뉴로 보임)였다 | 글자만 | 위와 같음. 필요하면 추가 가능 (8장) |
| 심각도 | 영어 이름 + 색 배경 | 코어 도우미로 이름 + 색. 사용자 언어와 관리 설정의 이름을 따른다 | 코어와 같은 표시 |
| 내보내기 | 확인 안 됨 | CSV | 공통 개선 |
| 화면 글자 | 한국어 고정 | 영어로 적고 `Lang::t()` / `_()` 로 번역. 그래서 열 이름이 5버전과 조금 다르다 ("Description" → "설명") | 다국어 공통 규칙 |

5버전의 오류를 고친 부분은 이 화면에서는 확인된 것이 없다. (5버전 소스를 못 봤기 때문)

## 6. 설정

없음. 이 화면은 `conf/` 파일을 읽지 않는다. 표시 건수 100은 코드 상수 `PeakEventList::MAX_ROWS` 다.

## 7. 비어 있는 부분과 가정

`PeakEventList.php` 머리말의 `TODO(고객환경)` 상자와 `../04_남은_작업/미구현_목록.md` 3장, `../04_남은_작업/고객환경_체크리스트.md` 2-4 와 같은 내용이다.

| 가정 | 지금 구현 | 확인할 5버전 파일 | 다르면 고칠 곳 |
|---|---|---|---|
| 세는 대상 | 장애(PROBLEM) 이벤트만. 열 이름이 "상태 변경 횟수"라서 복구 이벤트도 세었을 가능성이 있다 (확인 필요) | `peak_event.php` 의 집계 쿼리 | `getRows()` 의 `'value'`. 둘 다 세려면 `[TRIGGER_VALUE_TRUE, TRIGGER_VALUE_FALSE]` 로 주는데, 이때 API 가 두 결과를 합치면서 정렬이 풀리므로 PHP 에서 다시 정렬하고 100개로 잘라야 한다 (`CEvent::get()` 의 groupBy 처리 참고) |
| 대상 트리거 | 모든 트리거 | 같은 파일 (이름이 특정 접두어로 시작하는 것만 세는 조건이 있었는지) | `getRows()` 의 `API::Event()->get()` 에 `search` 또는 `tags` 조건 추가 |
| 표시 건수 | 100개 | 같은 파일 (LIMIT) | `MAX_ROWS` |
| Description 출처 | Zabbix 호스트 설명 | 같은 파일 (ITSM 설명을 썼는지) | 다른 화면처럼 `ItsmRepository::fetchDescriptions()` 를 먼저 보게 바꾼다 |
| 호스트/트리거 링크 | 없음 | 같은 파일 | 뷰 |
| 가정 | Peak이벤트 유형 필터는 부분 일치(대소문자 무시)로 만들었다. 심각도는 5버전처럼 3개가 아니라 7.0 의 6단계 전체를 체크박스로 보여 준다 (5버전의 Critical / Minor / Warning 이 어느 단계인지 미확인) | `peak_event.php` | `PeakEventList::getRows()` 의 `search`, `severities` |

## 8. 개선점

### 이미 반영한 개선

- 이벤트를 하나씩 가져와 세지 않고 `countOutput + groupBy` 로 DB 가 세게 했다. 호스트 정보도 한 번에 조회한다.
- 볼 권한이 없는 트리거/호스트는 API 가 빼 준다.
- CSV 내보내기.
- 다국어 (`Lang::t('Peak event analysis')`, `Lang::t('Peak event type')`, 나머지는 코어 `_()`).

### 남은 개선 제안

| 제안 | 효과 | 5버전과 결과가 달라지나 (고객 합의) |
|---|---|---|
| 코어 "보고서 > Top 100 triggers" 를 그대로 쓰기 | 화면 하나를 유지보수하지 않아도 된다. 코어 화면에는 호스트 그룹/호스트/심각도/태그/이름 필터가 이미 있다. 빠지는 것은 IP 와 Description 열 | 화면 모양이 달라진다. 집계 기준이 같다면 숫자는 같다. 합의 필요 |
| 트리거 이름에 코어 트리거 메뉴 링크 (`CMenuPopupHelper::getTrigger()`) | 5버전처럼 눌러서 이벤트/그래프로 이동 | 표시만 바뀜. 합의 불필요 |
| "상태 변경 횟수" 열 이름 한국어 번역 (**반영함**) | 코어 번역 파일에 없는 문장이라 한국어 화면에서도 영어로 나왔다. `Lang::t()` 로 바꾸고 `lang/ko.php` 에 추가했다 | 글자만 바뀜 |
| 100개 제한 때문에 권한 밖 트리거가 상위에 있으면 줄 수가 100보다 적어질 수 있음 | 필요하면 `limit` 을 넉넉히 받고 PHP 에서 100개로 자르기 | 일반 사용자에게만 해당. 합의 불필요 |

## 9. 확인 방법

테스트 환경: `dev/compose.yaml` 로 띄운 http://127.0.0.1:18970 , Admin / zabbix

1. 보고서 > Peak 이벤트 분석 을 연다. 제목 "Peak 이벤트 분석", 오른쪽 위에 [CSV 내보내기] 버튼, 시간 선택기 탭(최신 1 시간)이 보이면 된다.
2. 테스트 서버에 장애 이벤트가 없으면 표가 비어 "데이터를 찾을 수 없음"이 나온다. 이벤트를 만들려면 테스트 호스트에 낮은 임계값 트리거를 하나 두거나, 에이전트를 멈췄다 켜서 가용성 트리거가 몇 번 장애/복구되게 한다.
3. 시간 선택기에서 "최신 7 일간"을 누른다. 화면이 다시 그려지고 기간이 바뀐다. 다른 메뉴에 갔다 와도 "최신 7 일간"이 유지되는지 본다 (프로파일 저장).
4. 같은 기간으로 코어 보고서 > Top 100 triggers 를 연다. 필터를 비운 상태에서 트리거별 "장애의 수"(Number of problems) 숫자가 이 화면의 숫자와 같아야 한다.
5. 심각도 칸의 색이 코어 문제 화면과 같은지 본다.
6. [CSV 내보내기] 를 눌러 파일 이름이 `peak_event_날짜_시각.csv` 이고, 심각도가 이름(숫자 아님)으로 들어 있는지 본다.
7. 일반 사용자(그룹 권한이 일부만 있는 계정)로 로그인해 권한 밖 호스트의 트리거가 안 나오는지 본다.

고객환경에서 5버전과 비교: 5버전 `peak_event.php` 와 7버전 화면에서 **같은 기간**(예: "어제")을 골라 위에서 10줄의 트리거와 숫자를 맞춰 본다.
숫자가 7버전이 대략 절반이면 5버전은 복구 이벤트까지 센 것이다 (7장 첫 줄). 트리거 종류가 다르면 대상 트리거 조건이 있었던 것이다.

## 10. 파일 목록

이 화면의 파일

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/actions/reports/peakevent/PeakEventList.php` | 컨트롤러. 기간 처리, 이벤트 집계, 화면/내보내기 응답 |
| `ui/modules/dmove/views/reports/peakevent.list.php` | 뷰. 시간 선택기, 표, 시간 선택기 스크립트 |
| `ui/modules/dmove/manifest.json` | `peakevent.list`, `peakevent.export` 액션 등록 |
| `ui/modules/dmove/Module.php` | `REPORT_MENUS` 의 메뉴 한 줄 |
| `ui/modules/dmove/lang/ko.php` | `Peak event analysis`, `Peak event type` 한국어 |

같이 쓰는 부품

| 파일 | 역할 |
|---|---|
| `includes/export/ExportTrait.php`, `ExportButtons.php`, `CsvWriter.php`, `XlsxWriter.php` | 내보내기 버튼과 파일 만들기 |
| `includes/HostHelper.php` | `mainIp()` 대표 IP 고르기, `INTERFACE_FIELDS` |
| `includes/Lang.php` | `Lang::t()` 번역 |
| 코어 `ui/include/func.inc.php` | `getTimeSelectorPeriod()`, `updateTimeSelectorPeriod()` |
| 코어 `CSeverityHelper` | 심각도 이름과 색 칸 |


## 부록: 2026-11-24 보완

- 시간 선택기의 "어제" 같은 버튼과 < > 가 동작하지 않던 문제를 고쳤다. 화면이 코어 스크립트 `gtlc.js` 를 불러오지 않았고, 코어 `timeselector.update` 가 모듈의 기간 저장 이름을 받지 않았다 (`Module.php` 의 `registerTimeSelectors()` 로 등록). 주소에 from/to 를 직접 넣었을 때는 동작해서 이전 확인에서 놓쳤다. 실제 브라우저에서 "그저께" 를 눌러 바뀌는 것을 확인했다.

- 심각도 체크박스: 지금은 Zabbix 심각도 6단계 전체. 5버전 화면은 Critical / Minor / Warning 3개였다. 이 이름들이 어느 단계인지 사용자가 확인해 주기로 했다 (2026-11-24, 고객환경 체크리스트 3-7b). 확인되면 그 3개만 보이게 한다. "Peak이벤트 유형"은 심각도가 아니라 이벤트 이름(트리거 이름)의 부분 일치 글자 필터다.
