# 윈도우즈 패치 현황 (`winpatch.list`)

## 1. 한눈에

| 항목 | 내용 |
|---|---|
| 메뉴 위치 | 보고서 > 윈도우즈 패치 현황 (`Module.php` 의 `REPORT_MENUS`, Peak 이벤트 분석 다음) |
| 주소 | 목록 `zabbix.php?action=winpatch.list` / 내보내기 `zabbix.php?action=winpatch.export&format=csv` 또는 `xlsx` / 상세 팝업 `zabbix.php?action=winpatch.detail` (팝업 전용, 직접 열지 않는다) |
| 상태 | 완성. `conf/winpatch.conf.php` 의 아이템 키만 채우면 된다. HotFix ID 필터의 의미와 수집월 기본값은 가정 |
| 5버전 액션 | 목록 `zabbix.php?action=windows` (제목 "Windows Patch현황"), 팝업 `popup.windows` |
| 내보내기 | 있음 (CSV, Excel). 5버전은 CSV 만 있었다. "Patch File명"(상세보기 링크) 열은 내보내지 않는다 |
| 권한 | 로그인한 사용자 모두 (`USER_TYPE_ZABBIX_USER` 이상). 보이는 호스트는 API 가 사용자 권한으로 걸러 준다. 팝업도 같다 |

## 2. 이 화면은 무엇을 하나

윈도우 서버마다 "설치된 업데이트(패치) 목록"을 모아 두고, 어느 서버에 어떤 패치가 깔렸는지 확인하는 화면이다.
보안 담당자나 운영자가 "이번 달 배포한 KB 패치가 모든 서버에 들어갔는가"를 볼 때 쓴다.
HotFix ID 칸에 KB 번호를 넣으면 그 패치가 들어간 서버만 남고, 각 줄의 [상세보기] 를 누르면 그 서버의 패치 목록 전체가 팝업 표로 뜬다.
데이터는 각 윈도우 서버의 Zabbix 에이전트가 "패치 목록 파일"을 통째로 읽어 보내는 아이템 하나에서 온다.

## 3. 화면 구성

### 필터

| 이름 (한국어 화면) | 입력 방식 | 기본값 | 저장 여부 |
|---|---|---|---|
| 호스트 그룹 | 여러 개 고르는 선택창 (공용 부품 `HostFilter`). 하위 그룹도 포함된다 | 비어 있음 = 전체 | 저장 (`web.dmove.winpatch.filter.groupids`) |
| 호스트 | 여러 개 고르는 선택창 (`HostFilter`) | 비어 있음 = 전체 | 저장 (`web.dmove.winpatch.filter.hostids`) |
| HotFix ID | 글자 입력 | 비어 있음 = 거르지 않음 | 저장 (`web.dmove.winpatch.filter.hotfixid`). 앞뒤 빈칸은 지운다 |
| 수집월선택 | 날짜 입력 + 달력 버튼 (`CDateSelector`, `YYYY-MM-DD`). 고른 날짜가 속한 **달 전체**를 본다 | 지난달 1일 (예: 오늘이 2026-11-22 이면 2026-10-01) | 저장 (`web.dmove.winpatch.filter.month`). 형식이 틀리면 빈 값으로 저장되어 기본값으로 돌아간다 |

[적용] 을 누르면 저장, [리셋] 을 누르면 넷 다 지워진다. 필터 탭 펼침 상태는 `web.dmove.winpatch.filter.active`.

### 목록 표의 열

| 열 (한국어 화면) | 값의 출처 | 표시 방식 |
|---|---|---|
| 호스트 | 호스트 표시 이름 (`host.get` 의 `name`) | 눌러서 오름/내림 정렬 (정렬 상태는 `web.dmove.winpatch.sort`, `.sortorder` 에 저장) |
| IP 주소 (`_('IP')`) | 대표 IP (`HostHelper::mainIp()`) | 글자 그대로 |
| 설명 (`_('Description')`) | ITSM 설명 → 없으면 Zabbix 호스트 설명 | 긴 글은 줄바꿈 |
| Patch File명 | 고정 글자 "상세보기" | 점선 밑줄 링크. 누르면 팝업 |

- 한 페이지 줄 수는 사용자 설정(사용자 프로필의 "페이지당 행 수")을 따르는 코어 페이지 나누기(`CPagerHelper`)다.
- 아이템 키 설정이 비어 있으면 표 위에 노란 안내 "설정이 필요합니다: conf/winpatch.conf.php" 가 나오고 표는 빈다.

### 상세 팝업 "Windows 패치 상세정보: <호스트 이름>"

| 열 | 값 |
|---|---|
| Caption, CSName, Description, FixComments, HotFixID, InstallDate, InstalledBy, InstalledOn, Name, ServicePackInEffect | 수집월에 들어온 패치 목록 값 중 **가장 최근 것** 하나를 표로 푼 것. 열 이름과 순서는 `conf/winpatch.conf.php` 의 `columns` |

- 아래쪽 [닫기] 버튼 하나. 오른쪽 위 `×` 도 닫기.
- 그 달에 들어온 값이 없거나 호스트를 볼 권한이 없으면 머리줄만 있는 빈 표("데이터를 찾을 수 없음")가 뜬다.

### 스케치

```
윈도우즈 패치 현황                                       [CSV] [Excel]
┌────────────────────────────────────────────────────────── [필터] ┐
│ 호스트 그룹 [            ][선택]                                   │
│ 호스트      [            ][선택]                                   │
│ HotFix ID   [KB5030000        ]                                    │
│ 수집월선택  [2026-10-01][달력]                                     │
│                         [적용] [리셋]                               │
└────────────────────────────────────────────────────────────────────┘
호스트 ▲   | IP 주소  | 설명            | Patch File명
WIN-A01    | 10.0.0.1 | DHCP 서버       | 상세보기   ← 누르면 팝업
WIN-A02    | 10.0.0.2 | 백업 서버       | 상세보기

  ┌ Windows 패치 상세정보: WIN-A01 ─────────────────────────── × ┐
  │ Caption | CSName | Description | ... | HotFixID | ... | InstalledOn │
  │ http:// | WIN-A01| Update      | ... | KB4601060| ... | 4/9/2021    │
  │                                                     [닫기] │
  └──────────────────────────────────────────────────────────────┘
```

## 4. 어떻게 만들었나 (동작 순서)

### 4-1. 목록 화면

1. **요청 받기.** `zabbix.php?action=winpatch.list` → `manifest.json` 이 `WinPatchList` 클래스, `views/reports/winpatch.list.php` 뷰, `layout.htmlpage` 에 연결한다. 내보내기 `winpatch.export` 도 같은 클래스이고 레이아웃만 `export/layout.file`.

2. **입력 검사.** `WinPatchList::checkInput()`
   - `page` (1 이상), `filter_set`/`filter_rst` (1 만), `sort` (`name` 만), `sortorder` (`ASC`/`DESC`)
   - `filter_hotfixid`, `filter_month` : 문자열. 날짜 칸은 빈 값으로도 오기 때문에 여기서는 문자열로만 받고, 저장할 때 `normalizeDate()` 로 형식을 본다.
   - `HostFilter::INPUT_FIELDS` (`filter_groupids`, `filter_hostids` = ID 배열), `exportInputFields()` (`format`)
   - 틀리면 `CControllerResponseFatal`.

3. **필터 저장/초기화.** `WinPatchList::doAction()` 1)
   - [적용] (`filter_set`) : `HostFilter::save()` 로 그룹/호스트, `CProfile::update()` 로 HotFix ID(앞뒤 빈칸 제거)와 수집월(`normalizeDate()` 통과값)을 저장.
   - [리셋] (`filter_rst`) : `HostFilter::reset()` 과 `CProfile::deleteIdx()` 로 지운다.
   - `normalizeDate()` 는 `preg_match('/^(\d{4})-(\d{2})-(\d{2})$/')` 로 모양을 보고, `checkdate()` 로 실제 있는 날짜인지 본다. 예: `2026-02-30` → `''`.

4. **저장된 필터 읽기.** `doAction()` 2)

   ```php
   'month' => CProfile::get(self::PROFILE_PREFIX.'filter.month', '')
       ?: date('Y-m-d', strtotime('first day of last month'))
   ```

   저장된 값이 비어 있으면 지난달 1일. `?:` 는 "왼쪽이 비어 있으면 오른쪽".

5. **정렬값 읽고 저장.** `doAction()` 3) : 요청의 `sort`/`sortorder`, 없으면 프로파일 값, 그것도 없으면 `name` 오름차순.

6. **데이터 만들기.** `WinPatchService::getRows($host_filter, $hotfix_id, $month)`
   1. `isConfigured()` 가 거짓(아이템 키가 빈 문자열)이면 바로 빈 배열.
   2. **패치 아이템 찾기.**
      ```php
      $items = API::Item()->get(HostFilter::toApiOptions($host_filter) + [
          'output' => ['itemid', 'hostid', 'value_type'],
          'filter' => ['key_' => $this->config['item_key']]
      ]);
      ```
      `toApiOptions()` 는 고른 그룹(하위 그룹 포함, `getSubGroups()`)과 호스트를 `groupids`/`hostids` 로 바꿔 준다. 고르지 않았으면 `null` = 전체.
      `filter key_` 는 키가 **정확히 같은** 아이템만 찾는다.
   3. `array_column($items, null, 'hostid')` 로 `[hostid => 아이템]` 색인을 만들고, `API::Host()->get()` 으로 그 호스트들의 `host`(기술 이름), `name`(표시 이름), `description`, 인터페이스를 한 번에 받는다.
      템플릿에 붙은 같은 키의 아이템도 첫 조회에 섞여 올 수 있지만, `host.get` 은 템플릿을 돌려주지 않으므로 여기서 빠진다.
   4. **HotFix ID 거르기.** 입력이 있을 때만, 남은 호스트마다 `getLatestValue()` 로 그 달의 가장 최근 값을 읽어 `mb_stripos($value, $hotfix_id)` 로 대소문자 무시 "포함" 검사를 하고, 없으면 목록에서 뺀다.
      - 예: 입력 `kb5030000` → 값 안 어디든 `KB5030000` 이 있으면 남는다.
      - 그 달에 값이 아예 없는 호스트도 빠진다.
      - 입력이 없으면 값을 읽지 않는다(값이 길어서). 그래서 **필터 없이 보면 그 달 값이 없는 호스트도 목록에 나온다.**
   5. **설명 정하기.** `(new ItsmRepository())->fetchDescriptions()` 로 `[소문자 ITSM 서버 이름 => 설명]` 을 받고, 호스트 표시 이름 → 기술 이름 → Zabbix 호스트 설명 순으로 찾는다. ITSM 설정(`conf/itsm.conf.php`)이 비어 있으면 빈 배열이라 항상 Zabbix 설명이 나온다.
   6. 한 줄 = `['hostid' => .., 'name' => .., 'ip' => .., 'description' => ..]`.

7. **정렬.** `CArrayHelper::sort($rows, [['field' => $sort_field, 'order' => $sort_order]])` 후 `array_values()`.

8. **내보내기면 파일로.** `isExportRequest()` 이면 `setExportResponse($title, 'windows_patch', getColumns(), $rows)`. 열은 호스트, IP, 설명 3개뿐이다 (`hostid` 는 열 정의에 없어서 나가지 않는다). 페이지 나누기 전이라 **전체 줄**이 나간다.

9. **화면 응답.** `detail_action` (`winpatch.detail`), `host_filter`(선택창에 보여 줄 이름), `filter`, 정렬, `config_missing` 을 담고, `CPagerHelper::paginate()` 가 현재 페이지 줄만 남긴다.

10. **뷰 그리기.** `views/reports/winpatch.list.php`
    - `$this->includeJsFile('reports/winpatch.list.js.php')` : 화면 전용 스크립트를 HTML 안에 넣는다.
    - 필터: `HostFilter::addRows()` + HotFix ID `CTextBox` + 수집월 `CDateSelector(...)->setDateFormat(ZBX_DATE)`.
    - 표: 호스트 열만 `make_sorting_header()` 로 정렬 머리. 마지막 칸은
      ```php
      (new CLinkAction(Lang::t('View details')))
          ->addClass('js-winpatch-detail')
          ->setAttribute('data-hostid', $row['hostid'])
      ```
    - 설정이 비면 `makeMessageBox(ZBX_STYLE_MSG_WARNING, ...)` 안내.
    - 맨 아래에서 스크립트를 시작시킨다 (4-2 의 1단계).

### 4-2. 상세 팝업이 열리는 흐름 (단계별)

팝업은 "목록 뷰 → 화면 스크립트 → 코어 `PopUp()` → 팝업 컨트롤러 → 팝업 뷰(JSON) → 코어가 창에 그리기" 순서로 6개 부분이 엮인다.

**1단계. 목록 화면이 다 그려지면 스크립트를 준비시킨다** (`views/reports/winpatch.list.php` 맨 아래)

```php
(new CScriptTag('dmoveWinPatchList.init('.json_encode([
    'detail_action' => $data['detail_action'],   // 'winpatch.detail'
    'month' => $data['filter']['month']          // 예: '2026-10-01'
]).');'))->setOnDocumentReady()->show();
```

- `json_encode` : PHP 값을 자바스크립트 글자로 안전하게 바꾼다.
- `setOnDocumentReady()` : 페이지가 다 읽힌 뒤에 실행한다.
- 결과로 HTML 에 `dmoveWinPatchList.init({"detail_action":"winpatch.detail","month":"2026-10-01"});` 가 들어간다.
- 수집월은 **저장된 필터 값**이다. 날짜 칸에 새 날짜를 적고 [적용] 을 누르지 않으면 팝업은 예전 달을 본다.

**2단계. 클릭 감시를 하나 건다** (`views/js/reports/winpatch.list.js.php` 의 `init()`)

```js
document.addEventListener('click', (e) => {
    const link = e.target.closest('.js-winpatch-detail');
    if (link === null) { return; }
    PopUp(detail_action, {hostid: link.dataset.hostid, month: month}, {
        dialogue_class: 'modal-popup-generic',
        trigger_element: link
    });
});
```

- 링크마다 감시를 걸지 않고 문서 전체에 하나만 건다. 눌린 요소에서 바깥쪽으로 올라가며(`closest`) `js-winpatch-detail` 표시가 붙은 링크를 찾는다. 다른 곳을 눌렀으면 아무것도 안 한다.
- `link.dataset.hostid` : 뷰가 붙인 `data-hostid="10501"` 값.

**3단계. 사용자가 [상세보기] 를 누르면 코어 `PopUp()` 이 창부터 띄운다** (코어 `ui/js/common.js` 의 `PopUp()`)

- `overlayDialogue()` 로 빈 팝업 창(`class: 'modal-popup modal-popup-generic'`)을 만들고 가운데에 "불러오는 중" 표시를 넣는다.
- `trigger_element: link` : 팝업을 닫으면 키보드 포커스가 그 링크로 돌아간다.

**4단계. 코어가 팝업 액션을 부른다** (코어 `ui/js/class.overlay.js` 의 `Overlay.prototype.load()`)

- `zabbix.php?action=winpatch.detail` 로 **POST** 요청을 보낸다. 본문: `hostid=10501&month=2026-10-01`. 응답은 JSON 으로 기대한다.

**5단계. 팝업 컨트롤러가 데이터를 만든다** (`actions/reports/winpatch/WinPatchDetail.php`)

1. `manifest.json` 의 `winpatch.detail` 은 `"layout": "layout.json"` 이다. 화면 틀(메뉴, 머리말) 없이 뷰가 출력한 글자만 그대로 돌려준다.
2. `init()` : CSRF 검사 끔 (조회 전용).
3. `checkInput()` : `'hostid' => 'required|db hosts.hostid'` (반드시 있고 숫자 ID 형식), `'month' => 'required|abs_date'` (반드시 있고 `YYYY-MM-DD` 실제 날짜).
4. `checkPermissions()` : 목록과 같이 일반 사용자 이상.
5. `doAction()` : `WinPatchService::getDetail($hostid, $month)` 를 부른다.
   - `API::Host()->get(['hostids' => [$hostid]])` 로 호스트를 **다시** 조회한다. 사용자가 볼 수 없는 호스트면 빈 결과라서, 주소창이나 개발자 도구로 hostid 를 바꿔 보내도 권한 밖 값은 안 나온다.
   - 그 호스트의 패치 아이템을 `API::Item()->get(['hostids' => [$hostid], 'filter' => ['key_' => 설정 키]])` 로 찾는다. 호스트가 없거나, 설정이 비었거나, 아이템이 없으면 `null`.
   - `monthRange($month)` 로 그 달의 시작/끝 초를 구한다.
     예: `2026-10-15` → `2026-10-01 00:00:00` ~ `2026-10-31 23:59:59` (`date('Y-m-t')` 의 `t` = 그 달 날 수).
   - `getLatestValue()` : `API::History()->get()` 에 `history = 아이템의 value_type`(1 = 문자, 4 = 텍스트), `time_from/time_till` = 그 달, `sortfield clock` 내림차순, `limit 1` → 그 달 **가장 최근 값** 1건.
   - 값이 있으면 `parse()` 로 표 줄 배열로 바꾼다 (아래 4-3).
   - 응답 데이터: `title` (`Windows 패치 상세정보`), `host` (표시 이름), `columns` (설정의 열 이름), `rows`, `user.debug_mode`.

**6단계. 팝업 뷰가 JSON 을 출력한다** (`views/reports/winpatch.detail.php`)

```php
$output = [
    'header' => $data['host'] !== '' ? $data['title'].': '.$data['host'] : $data['title'],
    'body' => $table->toString(),          // CTableInfo 를 HTML 글자로. 값은 이때 이스케이프된다
    'buttons' => [['title' => _('Close'), 'cancel' => true, 'class' => ZBX_STYLE_BTN_ALT, 'action' => '']]
];
echo json_encode($output);
```

- `->show()` 를 하지 않는다. 화면을 그리는 대신 JSON 글자만 내보낸다.
- 디버그 모드 사용자 그룹이면 `debug` 에 코어 디버그 정보(실행한 SQL 등)를 붙인다.

**7단계. 코어가 받은 JSON 으로 창을 채운다** (다시 `PopUp()` 의 `.then(...)`)

- `header` → 창 제목, `body` → 창 내용, `buttons` → 아래 버튼. `cancel: true` 버튼이 이미 있으므로 코어가 [취소] 버튼을 따로 더하지 않는다.
- 응답에 `error` 가 있으면 빨간 오류 상자를 보여 준다. (이 컨트롤러는 `error` 를 만들지 않는다)
- [닫기] 또는 `×` 를 누르면 창이 닫히고 포커스가 링크로 돌아간다.

### 4-3. 패치 목록 글을 표로 바꾸기 (`WinPatchService::parse()`)

아이템 값은 윈도우의 "설치된 업데이트 목록"을 칸 맞춤 표로 적은 긴 글이다. 열마다 시작 위치가 같고 빈칸으로 채워져 있다.

```
Caption                                     CSName   Description      FixComments  HotFixID   InstallDate  InstalledBy          InstalledOn  ...
http://support.microsoft.com/?kbid=4601060  WIN-A01  Update                        KB4601060                                     4/9/2021     ...
                                            WIN-A01  Security Update               KB5028316               NT AUTHORITY\SYSTEM  10/13/2023   ...
```

1. `preg_split('/\R/u', $text)` 로 줄을 나눈다 (`\r\n`, `\n`, `\r` 모두).
2. 빈 줄은 건너뛴다.
3. 제목 줄을 아직 못 찾았으면 `findOffsets()` 로 이 줄이 제목 줄인지 본다. 설정 `columns` 의 이름을 **순서대로** 찾아 각 이름이 시작하는 글자 위치를 모은다. 예: `[0, 44, 53, 70, ...]`. 하나라도 없으면 제목 줄이 아니다.
4. 제목 줄을 찾은 뒤의 줄은 그 위치에서 자른다. `mb_substr($line, 시작, 다음 열 시작 - 시작)` 후 `trim()`. 마지막 열은 줄 끝까지.
   - 위 예의 1번째 데이터 줄: InstalledBy 위치가 빈칸이면 InstalledBy = `''`, InstalledOn = `4/9/2021`. **값이 제 열에 들어간다.**
5. 제목 줄을 끝내 못 찾으면(형식이 다르면) 5버전처럼 빈칸으로 나눠 앞에서부터 열에 채운다.

5버전은 처음부터 빈칸으로 나눴기 때문에, 위 1번째 줄은 `InstallDate` 자리에 `4/9/2021` 이 들어가는 식으로 뒤의 값이 앞으로 당겨졌다.

## 5. 5버전과 비교

| 항목 | 5버전 | 7버전 | 이유 |
|---|---|---|---|
| 값 읽기 | 윈도우 트레잇(`...Windows.php`)이 `history_str` / `history_text` 테이블을 SQL 로 직접 읽었다. 아이템ID를 글자로 이어 붙인 `IN (...)`, 파일/버전 쿼리는 기간 조건도 없이 그 아이템의 이력 **전부**를 읽었다 | `history.get` API, 호스트 1개당 1건(`limit 1`) | 직접 SQL 제거, 권한 적용, 느려짐 방지. 7버전은 이력 저장소가 바뀔 수 있어(TimescaleDB 등) API 가 안전 |
| 그 달의 어느 값 | 그 달 이력을 `[clock => 값]` 으로 모은 뒤 `$str[0]` (첫 번째). 정렬 조건이 없어 DB 가 준 순서의 첫 값이다 (보통 가장 오래된 값으로 보이나 확인 필요) | 그 달의 **가장 최근** 값 | "그 달 말 기준 설치 상태"가 업무 의미에 맞다고 보았다. 한 달에 여러 번 수집하면 5버전과 다른 값이 나올 수 있다 |
| 기간 끝 | `clock < 그달 말일 23:59:59` (마지막 1초 빠짐) | `time_till` = 그달 말일 23:59:59 포함 | 사소한 경계 오류 수정 |
| 상세 표 만들기 | 빈칸으로 나눠서 빈 열이 있으면 값이 앞으로 당겨졌다 (InstalledBy 가 빈 줄은 날짜가 InstallDate 열에 표시) | 제목 줄의 열 위치로 잘라 제 열에 넣는다 | 표시 오류 수정 |
| [상세보기] 링크 | 그 달 값이 있을 때만 "상세보기", 없으면 빨간 "없음" + 풍선 도움말 "호스트(ID)에 item이 없습니다." | 모든 줄이 "상세보기". 값이 없으면 팝업 표가 빈다 | 목록에서 값을 미리 읽지 않기 위해서 (8장 제안 참고) |
| 팝업 호출 | `PopUp("popup.windows", {hostid, itemid, from}, null, this)` (화면의 onclick 속성) | `PopUp('winpatch.detail', {hostid, month}, {...})` (화면 전용 스크립트) | itemid 를 받지 않고 서버에서 다시 찾는다. 주소를 조작해 남의 아이템을 보는 일을 막는다. 7버전 `PopUp()` 의 세 번째 인자가 옵션 객체로 바뀌었다 |
| IP | `API::HostInterface()->get()` 의 첫 번째 인터페이스 IP (인터페이스가 없으면 PHP 경고) | `HostHelper::mainIp()` : 에이전트 기본 인터페이스 우선 | 대표 IP 를 일정하게, 경고 없이 |
| 설명 | ITSM 설명 (`itsm_description()`) | ITSM 설명, 없으면 Zabbix 호스트 설명 | 빈칸 줄이기 |
| HotFix ID, 수집월 기본값 | 컨트롤러 소스 미확인 | 그 달 값에 글자가 들어 있는 호스트 / 지난달 1일 | 가정 (7장) |
| 내보내기 | CSV | CSV, Excel | 공통 개선 |

## 6. 설정

파일: `ui/modules/dmove/conf/winpatch.conf.php` (읽는 곳: `WinPatchService::__construct()`)

| 값 | 뜻 | 비어 있을 때 |
|---|---|---|
| `item_key` | 패치 목록 파일을 읽어 오는 아이템의 키. 5버전 키 클래스(`...Key.php`)의 `patchFile()` 이 돌려주던 키. 고객 서버의 데이터 수집 > 호스트 > (윈도우 서버) > 아이템 에서 실제 키를 확인해 넣는다 | 목록이 비고 "설정이 필요합니다: conf/winpatch.conf.php" 안내가 나온다. 팝업도 빈 표 |
| `columns` | 상세 표의 열 이름과 순서. 값의 첫 줄(제목 줄)에 이 이름들이 이 순서로 있어야 열 위치로 자를 수 있다 | 기본값이 들어 있다 (Caption ~ ServicePackInEffect 10개). 제목 줄과 다르면 빈칸 나누기 방식으로 처리된다 |

- PHP 작은따옴표 문자열 안의 역슬래시(`\`)는 그대로 적는다. 맨 끝 글자가 `\` 일 때만 `\\`.
- 공개 저장소라서 실제 키는 넣지 않았다. `CUSTOMER_ENV_CHECKLIST.md` 1-4, 1-5 참고.
- 설명 열이 ITSM 값을 쓰려면 `conf/itsm.conf.php` 도 채워야 한다 (SW 인벤토리와 같은 설정).

## 7. 비어 있는 부분과 가정

`WinPatchList.php` 머리말 TODO, `UNIMPLEMENTED.md` 3장, `CUSTOMER_ENV_CHECKLIST.md` 1-4, 1-5, 2-5 와 같은 내용이다.

| 항목 | 지금 구현 (가정) | 확인할 5버전 파일 | 다르면 고칠 곳 |
|---|---|---|---|
| `TODO(고객환경)` 아이템 키 | 빈 값 | 키 클래스 `patchFile()`, 고객 서버의 아이템 | `conf/winpatch.conf.php` |
| HotFix ID 필터의 의미 | 그 달의 가장 최근 값 안에 입력 글자가 (대소문자 무시) 들어 있는 호스트만 | 윈도우 패치 컨트롤러 | `WinPatchService::getRows()` 2) |
| 수집월 기본값 | 지난달 1일 | 같은 컨트롤러 | `WinPatchList::doAction()` 2) |
| 목록에 나오는 호스트 | 패치 아이템이 있는 호스트 전부 (그 달 값이 없어도) | 같은 컨트롤러 | `WinPatchService::getRows()` |
| 값의 형식 | 첫 줄이 `Caption CSName Description ...` 인 칸 맞춤 표 | 고객 서버의 최신 데이터 화면에서 그 아이템 값 열어 보기 | `conf/winpatch.conf.php` 의 `columns`, `WinPatchService::parse()` |
| 글자 폭 | 열 위치를 **글자 수**로 센다 (`mb_substr`). 값 안에 한글이 있고 파일이 바이트 기준으로 줄 맞춤되어 있으면 어긋날 수 있다 (확인 필요) | 위와 같음 | `parse()` |

5버전 트레잇에 있던 `middle_patchVersion`(패치 버전), `patch_version` 은 5버전 화면 열에 없어서 옮기지 않았다. 5버전 "윈도우 전체"(`windowsall`, `popup.windows.all`) 화면은 아예 없다 (`UNIMPLEMENTED.md` 4장).

## 8. 개선점

### 이미 반영한 개선

- 상세 표의 빈 열 밀림 오류 수정 (제목 줄 열 위치로 자르기).
- `history_str` / `history_text` 직접 SQL → `history.get` API. 기간 조건 + 1건만 읽기.
- 팝업은 hostid 만 받고 서버에서 권한 확인 후 아이템을 다시 찾는다.
- 대표 IP 규칙 통일, 인터페이스 없을 때 경고 없음.
- Excel 내보내기, 다국어, 입력 검증(`normalizeDate()`, `abs_date`).

### 남은 개선 제안

| 제안 | 효과 | 5버전과 결과가 달라지나 (고객 합의) |
|---|---|---|
| 그 달 값이 없는 호스트는 [상세보기] 대신 "없음" 표시 (5버전처럼) | 클릭해 보고 빈 표를 만나는 일이 없다. 수집이 끊긴 서버가 바로 보인다. 단, 목록을 그릴 때 호스트마다 `history.get` 을 해야 해서 느려진다. `countOutput` 으로 건수만 세면 가볍다 | 5버전과 같아지는 방향. 합의 불필요 |
| HotFix ID 를 "HotFixID 열 값과 정확히 같음"으로 검사 (지금은 글 전체에서 "포함") | `KB50` 처럼 짧게 넣으면 다른 패치나 다른 열(Caption 주소 등)까지 걸리는 문제를 없앤다 | 5버전 방식이 확인되면 그에 맞춘다. 합의 필요 |
| HotFix ID 필터를 쓸 때 호스트마다 한 번씩 이력 조회 → 아이템ID를 모아 한 번에 조회 | 호스트가 수백 대일 때 빠르다. 다만 `history.get` 은 "아이템별 최근 1건"을 한 번에 주지 못하므로 기간 안 값을 모두 받게 되어 오히려 무거울 수 있다. 수집 주기를 보고 결정 | 결과 같음 |
| 팝업 응답에 `'dialogue_class' => 'modal-popup-generic'` 추가 (**반영함**) | 코어 `PopUp()` 은 응답의 `dialogue_class` 로 창 class 를 다시 정하는데, 연산 순서 때문에 이 값이 없으면 `modal-popup undefined` 가 되어 `modal-popup-generic` 이 빠졌다. 코어 팝업 뷰처럼 응답에 넣었다 | 표시만 |
| 팝업 입력이 틀렸을 때 `CControllerResponseFatal` 대신 JSON `error` 로 응답 | 지금은 화면 이동(redirect) 응답이 가서 팝업이 오류 없이 멈춘 것처럼 보일 수 있다. 정상 사용에서는 생기지 않는다 (수집월은 항상 올바른 날짜) | 없음 |
| 사용 중지된 호스트/아이템 빼기 (`monitored_hosts`, `filter status`) | 더 이상 감시하지 않는 서버가 목록에 남지 않는다 | 5버전 동작 확인 필요 |

## 9. 확인 방법

테스트 환경: `dev/compose.yaml` 로 띄운 http://127.0.0.1:18972 , Admin / zabbix

1. 보고서 > 윈도우즈 패치 현황 을 연다. 키 설정이 비어 있으므로 노란 "설정이 필요합니다: conf/winpatch.conf.php" 안내와 빈 표가 나와야 한다. 수집월 칸에는 지난달 1일이 들어 있다.
2. 시험용 아이템을 만든다. 테스트 호스트에 **트래퍼** 아이템(정보 종류: 텍스트, 키는 아무 이름 예 `test.winpatch`)을 하나 만들고, `conf/winpatch.conf.php` 의 `item_key` 에 같은 키를 넣는다.
3. `zabbix_sender` 로 4-3 의 예시 같은 칸 맞춤 표 글을 보낸다 (InstalledBy 가 빈 줄을 하나 넣는다).
   트래퍼 값은 보낸 시각으로 저장되므로, 수집월을 **이번 달 날짜**로 바꾸고 [적용] 한다.
4. 목록에 그 호스트가 나오고, [상세보기] 를 누르면 "Windows 패치 상세정보: <호스트>" 팝업이 뜬다. InstalledBy 가 빈 줄에서 날짜가 InstalledOn 열에 있어야 한다 (밀림 없음).
5. 브라우저 개발자 도구 Network 탭에서 `zabbix.php?action=winpatch.detail` POST 요청의 본문이 `hostid=...&month=...` 이고 응답이 `{"header":...,"body":...,"buttons":[...]}` JSON 인지 본다.
6. HotFix ID 에 값 안에 있는 KB 번호를 넣고 [적용] → 호스트가 남는다. 없는 번호 → 표가 빈다. 수집월을 지난달로 바꾸면(그 달 값 없음) 호스트가 빠진다.
7. 수집월을 지난달로 두고 HotFix ID 를 비운 채 [상세보기] → 빈 표 팝업.
8. CSV/Excel 버튼 → `windows_patch_날짜_시각` 파일, 열은 호스트/IP 주소/설명 3개.
9. 권한이 없는 일반 사용자로 로그인해 개발자 도구에서 다른 hostid 로 팝업 요청을 보내 보면 빈 표가 와야 한다.

고객환경에서 5버전과 비교: 같은 수집월, 같은 호스트 그룹으로 두 화면을 열어 호스트 수를 맞춘다. 7버전에만 있는 호스트는 대부분 "그 달 값이 없어 5버전에서 '없음'이던 호스트"다.
몇 대를 골라 팝업 표의 줄 수와 KB 번호를 비교한다. 줄 수가 다르면 그 달에 값이 여러 번 들어와 5버전(첫 값)과 7버전(최근 값)이 다른 값을 본 것이다.
HotFix ID 로 같은 KB 를 넣어 남는 호스트 수도 비교한다.

## 10. 파일 목록

이 화면의 파일

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/actions/reports/winpatch/WinPatchList.php` | 목록 컨트롤러. 필터 저장, 정렬, 내보내기, 페이지 나누기 |
| `ui/modules/dmove/actions/reports/winpatch/WinPatchDetail.php` | 상세 팝업 컨트롤러. 입력 검증 후 `getDetail()` |
| `ui/modules/dmove/views/reports/winpatch.list.php` | 목록 뷰. 필터, 표, [상세보기] 링크, 스크립트 시작 |
| `ui/modules/dmove/views/reports/winpatch.detail.php` | 팝업 뷰. 표를 HTML 로 만들어 JSON 출력 |
| `ui/modules/dmove/views/js/reports/winpatch.list.js.php` | 화면 전용 스크립트. 링크 클릭 → `PopUp()` |
| `ui/modules/dmove/includes/WinPatchService.php` | 데이터 처리. 아이템/호스트 조회, HotFix ID 거르기, 이력 1건 읽기, 표로 풀기 |
| `ui/modules/dmove/conf/winpatch.conf.php` | 아이템 키, 상세 표 열 이름 |
| `ui/modules/dmove/manifest.json` | `winpatch.list`, `winpatch.export`, `winpatch.detail`(layout.json) 등록 |
| `ui/modules/dmove/Module.php` | `REPORT_MENUS` 의 메뉴 한 줄 |
| `ui/modules/dmove/lang/ko.php` | `Windows patch status`, `Collection month`, `Patch file name`, `View details`, `Windows patch details` 한국어 |

같이 쓰는 부품

| 파일 | 역할 |
|---|---|
| `includes/filter/HostFilter.php` | 호스트 그룹/호스트 선택창, 프로파일 저장/읽기, API 조건 만들기 |
| `includes/ItsmRepository.php` (+ `conf/itsm.conf.php`) | ITSM 설명 |
| `includes/HostHelper.php` | 대표 IP |
| `includes/export/ExportTrait.php`, `ExportButtons.php` 등 | 내보내기 |
| `includes/Lang.php` | 번역 |
| 코어 `ui/js/common.js` 의 `PopUp()`, `ui/js/class.overlay.js` | 팝업 창 만들기, 액션 호출 |
| 코어 `ui/app/views/layout.json.php` | 팝업 응답(JSON) 레이아웃 |
