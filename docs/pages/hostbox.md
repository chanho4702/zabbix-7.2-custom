# Box 사용현황 (`hostbox.list`)

## 1. 한눈에

| 항목 | 내용 |
|---|---|
| 메뉴 위치 | 보고서 > Box 사용현황 (`Module.php` 의 `REPORT_MENUS` 맨 끝). **5버전 메뉴 위치는 미확인이라 가정** |
| 주소 | `zabbix.php?action=hostbox.list` / 내보내기 `zabbix.php?action=hostbox.export&format=csv` 또는 `xlsx` |
| 상태 | 완성. `conf/hostbox.conf.php` 의 아이템 키만 채우면 된다 |
| 5버전 액션 | `zabbix.php?action=hostbox` (규칙 파일 `hostbox.json`, Box 사용현황 트레잇 `...HostBox.php`) |
| 내보내기 | 있음 (CSV, Excel). "최근 데이터" 링크 열은 내보내지 않는다 |
| 권한 | 로그인한 사용자 모두 (`USER_TYPE_ZABBIX_USER` 이상). 보이는 호스트는 API 가 사용자 권한으로 걸러 준다 |

## 2. 이 화면은 무엇을 하나

ESXi 같은 가상화 장비(이 화면에서는 "Box" 라고 부른다)마다 CPU 코어, 메모리, 디스크의 **전체량과 가상 머신에 할당한 양**을 한 표로 보여 준다.
인프라 담당자가 "어느 장비에 자원이 남아 있어 새 가상 머신을 올릴 수 있는가", "과할당된 장비는 어디인가"를 판단할 때 쓴다.
값은 장비 정보를 수집하는 아이템 6개의 **마지막 값**이다. 기간을 고르는 기능은 없다.

## 3. 화면 구성

### 필터

| 이름 | 입력 방식 | 기본값 | 저장 여부 |
|---|---|---|---|
| 호스트 그룹 | 여러 개 고르는 선택창 (공용 부품 `HostFilter`). 하위 그룹 포함 | 비어 있음 = 전체 | 저장 (`web.dmove.hostbox.filter.groupids`) |
| 호스트 | 여러 개 고르는 선택창 (`HostFilter`) | 비어 있음 = 전체 | 저장 (`web.dmove.hostbox.filter.hostids`) |

5버전 규칙 파일에는 필터 정의가 없어서, 5버전에 필터가 있었는지는 모른다 (가정).

### 표의 열

| 열 (한국어 화면) | 값의 출처 | 표시 방식 |
|---|---|---|
| 호스트 | 호스트 표시 이름 | 눌러서 오름/내림 정렬 (`web.dmove.hostbox.sortorder` 저장) |
| CPU 전체 Core | `item_keys.total_core` 아이템의 마지막 값 | 값 그대로 (예: `48`) |
| CPU 할당 Core | `item_keys.use_core` 아이템의 마지막 값 | 값 그대로 |
| 전체 메모리 | `item_keys.total_memory` (바이트) | 단위 변환 (예: `1.46 TB`) |
| 할당 메모리 | `item_keys.use_memory` (바이트) | 단위 변환 |
| 전체 Disk | `item_keys.total_disk` (바이트) | 단위 변환. **설정에 키를 넣었을 때만 열이 생긴다** (5버전 화면에는 없던 열) |
| 할당 Disk | `item_keys.use_disk` (바이트) | 단위 변환 |
| 최근 데이터 | 고정 글자 "상세보기" | 링크. 코어 "모니터링 > 최신 데이터" 화면을 이 호스트로 걸러서 연다 |

- 값이 없으면(아이템이 없음, 아직 수집 안 됨, 키 설정이 비었음, 빈 값) 모두 `-`.
- 페이지 나누기 있음 (`CPagerHelper`, 사용자 설정의 페이지당 행 수).
- `total_core` 키가 비어 있으면 표 위에 노란 안내 "설정이 필요합니다: conf/hostbox.conf.php" 가 나오고 표는 빈다.

### 버튼/링크

- 제목 오른쪽: CSV, Excel 내보내기 (`ExportButtons::create('hostbox.export')`).
- 각 줄 끝 "상세보기": `zabbix.php?action=latest.view&hostids[]=<hostid>&filter_set=1` 로 이동 (같은 창). 팝업은 없다.

### 스케치

```
Box 사용현황                                                      [CSV] [Excel]
┌──────────────────────────────────────────────────────────── [필터] ┐
│ 호스트 그룹 [            ][선택]                                     │
│ 호스트      [            ][선택]                                     │
│                            [적용] [리셋]                              │
└──────────────────────────────────────────────────────────────────────┘
호스트 ▲ | CPU 전체 Core | CPU 할당 Core | 전체 메모리 | 할당 메모리 | 할당 Disk | 최근 데이터
esxi-01  | 48            | 96            | 1.46 TB     | 1.2 TB      | 8.5 TB    | 상세보기
esxi-02  | 32            | -             | 768 GB      | -           | -         | 상세보기
```

## 4. 어떻게 만들었나 (동작 순서)

1. **요청 받기.** `zabbix.php?action=hostbox.list` → `manifest.json` 이 `HostBoxList` 클래스, `views/reports/hostbox.list.php`, `layout.htmlpage` 에 연결한다. `hostbox.export` 도 같은 클래스이고 레이아웃만 `export/layout.file`.

2. **입력 검사.** `HostBoxList::checkInput()` : `page`(1 이상), `filter_set`/`filter_rst`(1 만), `sort`(`name` 만), `sortorder`(`ASC`/`DESC`), `HostFilter::INPUT_FIELDS`(ID 배열), `exportInputFields()`(`format`). 틀리면 `CControllerResponseFatal`.

3. **권한.** `checkPermissions()` : 일반 사용자 이상.

4. **필터 저장/초기화.** `doAction()` 1) : [적용] 이면 `HostFilter::save()`, [리셋] 이면 `HostFilter::reset()`. 그 다음 `HostFilter::load()` 로 저장된 그룹/호스트를 읽는다.

5. **설정 읽기.** `doAction()` 2)

   ```php
   $config = require dirname(__DIR__, 3).self::CONFIG_FILE;   // 모듈 폴더/conf/hostbox.conf.php
   $item_keys = array_map('trim', $config['item_keys']);
   ```

   - `dirname(__DIR__, 3)` : `actions/reports/hostbox` 에서 세 단계 위 = 모듈 폴더.
   - `array_map('trim', ...)` : 키 앞뒤의 빈칸을 지운다.

6. **정렬값.** `doAction()` 3) : `sortorder` 를 요청 → 프로파일 → 오름차순 순으로 정하고 저장. 정렬 기준 열은 호스트 이름 하나뿐이다.

7. **데이터.** `doAction()` 4) : `total_core` 키가 비어 있으면 조회하지 않고 빈 배열, 아니면 `HostBoxList::getRows()`.
   1. 빈 키를 뺀다. `array_filter(..., fn($key) => $key !== '')` → `[열 이름 => 아이템 키]`.
   2. **아이템을 한 번에 조회.**
      ```php
      $items = API::Item()->get(HostFilter::toApiOptions($host_filter) + [
          'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'lastclock'],
          'filter' => ['key_' => array_values(array_unique($keys))]
      ]);
      ```
      - `filter key_` 에 배열을 주면 "키가 이 중 하나와 정확히 같은" 아이템을 모두 받는다. 호스트 100대 × 키 6개라도 API 호출은 1번이다.
      - `lastvalue` / `lastclock` : 마지막 수집 값과 시각. 이력을 따로 조회하지 않아도 된다.
   3. **값 표 만들기.** `lastclock != 0` 인 아이템만 `$values[hostid][아이템 키] = lastvalue` 로 담는다. `lastclock` 이 0 이면 값이 없는 것으로 본다.
   4. **Box 고르기.** 키가 `total_core` 와 같은 아이템을 가진 호스트 = Box. 값이 아직 없어도(수집 전) 아이템만 있으면 목록에 들어간다.
      템플릿에 붙은 같은 키의 아이템도 2번 조회에 섞여 올 수 있지만, 다음의 `host.get` 이 템플릿을 돌려주지 않으므로 빠진다.
   5. `API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => ...])` 로 이름을 받는다.
   6. **칸 채우기.** 호스트마다 키 목록을 돌며
      - 값이 없거나 빈 글자 → `'-'`
      - 메모리/디스크 열(`BYTE_COLUMNS`)이고 숫자 → `convertUnits(['value' => $value, 'units' => 'B'])`. 코어 함수로, 1024 단위로 나눠 읽기 쉬운 단위를 붙인다.
        예: `1610612736000` → `1610612736000 ÷ 1024⁴ ≈ 1.46` → `1.46 TB`. `824633720832` → `768 GB`.
      - 그 밖(CPU 코어, 숫자가 아닌 메모리 값) → 값 그대로.
      - 설정에 키가 없는 열은 마지막에 `$row += [$column => '-']` 로 `-` 를 채운다. (`+=` 는 "없는 키만 추가")
   7. 한 줄 예: `['hostid' => '10601', 'name' => 'esxi-01', 'total_core' => '48', 'use_core' => '96', 'total_memory' => '1.46 TB', 'use_memory' => '1.2 TB', 'total_disk' => '-', 'use_disk' => '8.5 TB']`

8. **정렬.** `CArrayHelper::sort($rows, [['field' => 'name', 'order' => $sort_order]])`.

9. **열 정의.** `HostBoxList::getColumns($item_keys['total_disk'] !== '')` : 전체 Disk 키가 있을 때만 `total_disk` 열을 넣는다. 화면 머리줄과 내보내기가 같은 정의를 쓴다.

10. **내보내기면 파일로.** `setExportResponse($title, 'box_usage', $columns, $rows)`. 파일 이름 `box_usage_YYYYMMDD_HHMMSS.csv|xlsx`. 값은 화면과 같은 글자(예: `1.46 TB`)로 들어간다. 페이지 나누기 전이라 전체 줄.

11. **화면 응답.** `columns`, `host_filter`(`HostFilter::toViewData()`), 정렬, `config_missing`(`total_core` 키가 비었는지)을 담고, `CPagerHelper::paginate()` 가 현재 페이지 줄만 남긴다.

12. **뷰 그리기.** `views/reports/hostbox.list.php`
    - `CFilter` + `HostFilter::addRows()` 로 필터 두 줄.
    - 머리줄: 첫 열(호스트)만 `make_sorting_header()`, 나머지는 `columns` 의 2번째부터(`array_slice(..., 1)`), 끝에 "최근 데이터".
    - 각 줄 끝 링크:
      ```php
      new CLink(Lang::t('View details'), (new CUrl('zabbix.php'))
          ->setArgument('action', 'latest.view')
          ->setArgument('hostids', [$row['hostid']])
          ->setArgument('filter_set', 1));
      ```
      `hostids` 는 배열이라 주소에 `hostids[]=10601` 로 들어간다. `filter_set=1` 은 "이 조건을 최신 데이터 필터에 적용하라"는 뜻.
    - `config_missing` 이면 `makeMessageBox(ZBX_STYLE_MSG_WARNING, ...)` 안내.

## 5. 5버전과 비교

| 항목 | 5버전 | 7버전 | 이유 |
|---|---|---|---|
| 화면 정의 | 규칙 파일(`hostbox.json`)의 열 목록 + 트레잇의 칸 함수(`total_core()` 등) | 컨트롤러 하나 + 뷰 | 7버전 모듈 구조 |
| 조회 횟수 | 칸마다 아이템 확인 + 이력 조회. 호스트 수 × 6번 이상 | 아이템 조회 1번 + 호스트 조회 1번 | 호스트가 많으면 5버전은 매우 느렸다 |
| 값 | 이력 테이블에서 `limit(1)` 로 1건 (어느 1건인지는 정렬 조건에 달림, 확인 필요) | 아이템의 `lastvalue` (마지막 값) | 이력 직접 조회 제거 |
| 값이 없을 때 | 칸마다 달랐다: 아이템 없음 "item없음", 값 없음 "no-value", 전체 Disk 빈 값 "0.0Tb", 그 밖 "-" | 모두 `-` | 표시 통일. 특히 "0.0Tb" 는 0 과 구분이 안 돼 오해를 부른다 |
| 바이트 표시 | 트레잇의 `bytes()` 함수 | 코어 `convertUnits()` | 코어와 같은 단위 표시 |
| 전체 Disk | 트레잇에 `total_disk()` 가 있었지만 규칙 파일 열 목록에 없어서 화면에 안 나왔다 | 설정에 키를 넣으면 열이 생긴다. 비우면 5버전과 같다 | 기능은 살리고 기본 모양은 5버전과 같게 |
| 최근 데이터 링크 | `zabbix.php?action=latest.view&filter_hostids[]=<hostid>&filter_set=1` | `zabbix.php?action=latest.view&hostids[]=<hostid>&filter_set=1` | 7버전 최신 데이터 화면의 인자 이름이 `hostids` 로 바뀌었다 |
| 아이템 키 | 키 클래스(`...Key.php`)의 `cpuCoreTotal()` ~ `diskUsage()` 가 돌려주는 문자열 | `conf/hostbox.conf.php` | 공개 저장소에 고객 값을 넣지 않기 위해 설정으로 분리 |
| 필터, 메뉴 위치 | 미확인 | 호스트 그룹/호스트, 보고서 맨 끝 | 가정 (7장) |
| 내보내기 | 미확인 | CSV, Excel | 공통 개선 |

## 6. 설정

파일: `ui/modules/dmove/conf/hostbox.conf.php` (읽는 곳: `HostBoxList::doAction()`)

`item_keys` 배열 하나에 키 6개가 있다. 각 값은 아이템 키 1개다.

| 키 | 뜻 | 5버전 키 클래스 함수 | 비어 있을 때 |
|---|---|---|---|
| `total_core` | CPU 전체 Core. **이 키를 가진 호스트가 목록에 나온다** | `cpuCoreTotal()` | 목록이 비고 "설정이 필요합니다: conf/hostbox.conf.php" 안내 |
| `use_core` | CPU 할당 Core | `cpuCoreUsage()` | 그 열이 모두 `-` |
| `total_memory` | 전체 메모리 (바이트) | `memoryTotal()` | 그 열이 모두 `-` |
| `use_memory` | 할당 메모리 (바이트) | `memoryUsage()` | 그 열이 모두 `-` |
| `total_disk` | 전체 Disk (바이트) | `diskTotal()` | **열 자체가 없다** (5버전 화면과 같음) |
| `use_disk` | 할당 Disk (바이트) | `diskUsage()` | 그 열이 모두 `-` |

주의:

- **5버전 소스의 키 문자열을 그대로 옮기지 않는다.** 5버전 소스에서는 셸 기호(`|`, `"`, `$`, `'` 등) 자리에 밑줄(`_`)이 들어간 것으로 보였다. 고객 서버의 데이터 수집 > 호스트 > (ESXi 정보를 수집하는 호스트) > 아이템 에서 실제 키를 복사해 넣는다. (`CUSTOMER_ENV_CHECKLIST.md` 1-4b)
- 키는 정확히 같아야 찾는다 (대소문자, 빈칸, 따옴표 포함). 앞뒤 빈칸만 코드가 지운다.
- 6개 키 이름(`total_core` 등) 줄을 지우면 안 된다. 쓰지 않는 값은 `''` 로 둔다. (줄을 지우면 `$item_keys['total_disk']` 같은 곳에서 PHP 경고가 난다)
- 공개 저장소라서 실제 키는 넣지 않았다.

## 7. 비어 있는 부분과 가정

`HostBoxList.php` 머리말 TODO, `UNIMPLEMENTED.md` 3장, `CUSTOMER_ENV_CHECKLIST.md` 1-4b, 2-7 과 같은 내용이다.

| 항목 | 지금 구현 (가정) | 확인할 5버전 파일 | 다르면 고칠 곳 |
|---|---|---|---|
| `TODO(고객환경)` 아이템 키 6개 | 빈 값 | 키 클래스 `cpuCoreTotal()` ~ `diskUsage()` + 고객 서버의 실제 아이템 | `conf/hostbox.conf.php` |
| `TODO(고객환경)` 메뉴 위치 | 보고서 맨 끝 | `src/menu.inc.php` | `Module.php` 의 `REPORT_MENUS` (다른 메뉴 아래면 그 메뉴 배열로 옮긴다) |
| 필터 | 호스트 그룹/호스트 | `hostbox` 컨트롤러 (5버전 화면과 컨트롤러는 확인하지 못했다) | `HostBoxList.php`, `hostbox.list.php` |
| Box 를 고르는 기준 | `total_core` 아이템이 있는 호스트 | 같은 컨트롤러 (호스트를 어떻게 골랐는지) | `getRows()` 2) |
| 값의 단위 | 메모리/디스크는 바이트 | 고객 서버의 아이템 단위 | 다른 단위면 `BYTE_COLUMNS` 와 `convertUnits()` 부분 |

5버전 "ESXi"(`hostesxi`) 화면은 이 화면과 별개이고 옮기지 않았다 (`UNIMPLEMENTED.md` 4장).

## 8. 개선점

### 이미 반영한 개선

- 칸마다 조회(호스트 수 × 6) → 아이템 한 번 조회 + `lastvalue`.
- 값 없음 표시를 `-` 로 통일 ("item없음", "no-value", "0.0Tb" 제거).
- 메모리/디스크를 코어 단위 변환으로 표시.
- 전체 Disk 열을 설정으로 켤 수 있게 함.
- CSV/Excel 내보내기, 다국어, 입력 검증, 이력 직접 SQL 없음.

### 남은 개선 제안

| 제안 | 효과 | 5버전과 결과가 달라지나 (고객 합의) |
|---|---|---|
| 수집 주기가 긴 아이템 확인 | `item.get` 의 `lastvalue` 는 코어 설정 "최대 이력 표시 기간"(관리 > 일반 > 표시 설정(GUI), 기본 24시간) 안의 값만 찾는다. 장비 정보 아이템이 하루에 한 번보다 드물게 수집되면 값이 있어도 `-` 로 나온다. 수집 주기를 확인하고, 길면 그 설정을 늘리거나 `history.get` 으로 바꾼다 | 5버전은 이력에서 기간 제한 없이 읽었으므로, 이 경우 5버전에는 값이 나오고 7버전에는 `-` 가 나온다. 확인 필요 |
| 마지막 수집 시각 표시 (값에 풍선 도움말로 `lastclock`) | 오래된 값인지 알 수 있다. 수집이 끊긴 Box 를 찾기 쉽다 | 표시만 추가 |
| 할당률 열 추가 (할당 ÷ 전체, 예: 96 ÷ 48 = 200%) | 과할당 장비를 바로 찾는다. 값 정렬도 가능하게 | 열 추가. 합의 필요 |
| 숫자 열 정렬, 내보내기에서 숫자(바이트)로 내보내기 | 지금은 `1.46 TB` 같은 글자로 나가서 Excel 에서 합계/정렬이 안 된다 | 내보내기 파일 모양이 바뀐다. 합의 필요 |
| 사용 중지된 호스트/아이템 빼기 | 폐기한 장비가 목록에 남지 않는다 | 5버전 동작 확인 필요 |
| 대시보드 위젯(항목 값, 표 형태 위젯)으로 대체 검토 | 화면 유지보수 감소 | 화면 모양이 달라진다. 합의 필요 |

## 9. 확인 방법

테스트 환경: `dev/compose.yaml` 로 띄운 http://127.0.0.1:18972 , Admin / zabbix

1. 보고서 > Box 사용현황 을 연다. 키 설정이 비어 있으므로 노란 "설정이 필요합니다: conf/hostbox.conf.php" 안내와 빈 표가 나와야 한다. 머리줄에 "전체 Disk" 열이 없어야 한다.
2. 시험용 아이템을 만든다. 테스트 호스트에 트래퍼 아이템 5개(숫자 부호 없음, 키 예 `test.box.core.total`, `test.box.core.use`, `test.box.mem.total`, `test.box.mem.use`, `test.box.disk.use`)를 만들고 `conf/hostbox.conf.php` 에 같은 키를 넣는다. `total_disk` 는 비워 둔다.
3. 화면을 새로 고치면 그 호스트가 나오고 값 칸은 모두 `-` 다 (아직 수집 전, `lastclock` 0).
4. `zabbix_sender` 로 값을 보낸다. 예: core.total = 48, mem.total = 1610612736000. 새로 고치면 `48`, `1.46 TB` 로 보여야 한다. 보내지 않은 열은 `-`.
5. `total_disk` 에도 키를 넣으면 "전체 Disk" 열이 "할당 메모리"와 "할당 Disk" 사이에 생긴다.
6. "상세보기" 를 누르면 모니터링 > 최신 데이터 화면이 그 호스트로 걸러져 열린다.
7. 호스트 열 머리를 눌러 정렬이 바뀌는지, 다른 메뉴에 갔다 와도 유지되는지 본다.
8. CSV/Excel → `box_usage_날짜_시각` 파일. 열 순서가 화면과 같고 "최근 데이터" 열은 없다.
9. 필터에서 다른 호스트 그룹을 고르면 그 호스트가 빠진다. [리셋] 으로 돌아온다.

고객환경에서 5버전과 비교: 같은 시점에 두 화면을 열어 Box 목록(호스트 수)과 각 칸의 값을 비교한다.

- 5버전 "item없음" / "no-value" / "0.0Tb" 칸은 7버전에서 `-` 이면 정상이다.
- 5버전에는 값이 있는데 7버전이 `-` 이면 8장 첫 번째 제안(수집 주기와 "최대 이력 표시 기간")을 먼저 의심한다.
- 바이트 값은 단위 표시 방식(소수 자릿수, `Tb` 와 `TB`)이 다를 수 있다. 숫자 크기가 같은지만 본다.

## 10. 파일 목록

이 화면의 파일

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/actions/reports/hostbox/HostBoxList.php` | 컨트롤러. 필터, 설정 읽기, 아이템 조회, 칸 채우기, 내보내기 |
| `ui/modules/dmove/views/reports/hostbox.list.php` | 뷰. 필터, 표, 최신 데이터 링크 |
| `ui/modules/dmove/conf/hostbox.conf.php` | 열별 아이템 키 6개 |
| `ui/modules/dmove/manifest.json` | `hostbox.list`, `hostbox.export` 등록 |
| `ui/modules/dmove/Module.php` | `REPORT_MENUS` 의 메뉴 한 줄 (위치 가정 TODO) |
| `ui/modules/dmove/lang/ko.php` | `Box usage`, `CPU total cores` ~ `Allocated disk`, `Latest data`, `View details` 한국어 |

같이 쓰는 부품

| 파일 | 역할 |
|---|---|
| `includes/filter/HostFilter.php` | 호스트 그룹/호스트 선택창, 프로파일 저장/읽기, API 조건 만들기 |
| `includes/export/ExportTrait.php`, `ExportButtons.php` 등 | 내보내기 |
| `includes/Lang.php` | 번역 |
| 코어 `convertUnits()` (`ui/include/func.inc.php`) | 바이트 단위 변환 |
