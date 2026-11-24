# SW 인벤토리 (`swinventory.list`)

## 1. 한눈에

| 항목 | 내용 |
|---|---|
| 메뉴 위치 | 인벤토리 > SW 인벤토리 (코어 인벤토리 메뉴의 맨 끝에 붙는다. `Module.php` 의 `INVENTORY_MENUS`) |
| 주소 | `zabbix.php?action=swinventory.list` |
| 상태 | 완성. Agent 미설치/예외 입력 창 포함 (2026-11-23). Agent 상태 표(`extends_agent`)가 고객 DB 에 있는지 확인 필요 |
| 5버전 액션 | `swinventories` (화면), `swinventories.view.csv` (CSV). 컨트롤러는 `CControllerSwinventories`, `CControllerSWInventCSV` |
| 내보내기 | 있음. `zabbix.php?action=swinventory.export&format=csv` 또는 `format=xlsx` (CSV, Excel 버튼 2개) |
| 권한 | 코어 "인벤토리 > 호스트" 화면을 볼 수 있는 사용자 역할 (`CRoleHelper::UI_INVENTORY_HOSTS`). 모듈 자체도 사용자 역할의 "모듈 접근"에서 켜고 끌 수 있다 |

## 2. 이 화면은 무엇을 하나

ITSM(자산 관리 시스템)에 등록된 서버 목록을 기준으로, 각 서버가 Zabbix 에 등록되어 있는지(= Agent 설치)와 그 서버의 OS, WEB, WAS, DB, 이중화 솔루션이 무엇인지를 한 표로 보여 준다.
운영 담당자는 이 표로 "ITSM 에는 있는데 Zabbix 감시에서 빠진 서버"(Agent 미설치)를 찾고, 서버별로 어떤 소프트웨어가 올라가 있는지 확인한다.
필터로 특정 OS 나 특정 WAS 를 쓰는 서버만 골라 내고, 결과를 CSV/Excel 로 받아 보고 자료로 쓴다.

## 3. 화면 구성

### 필터

필터는 좌우 2단이다. 왼쪽에 텍스트 3개, 오른쪽에 텍스트 3개와 Agent 체크박스가 있다. 값은 [적용]을 눌러야 저장되고, 사용자별로 DB(프로파일)에 남는다.

| 이름 (한국어 화면) | 입력 방식 | 기본값 | 비교 대상 열 | 저장 여부 |
|---|---|---|---|---|
| ITSM HOST | 텍스트 | 빈 값 | 호스트 (`host`) | 저장 (`web.dmove.swinventory.filter.itsm`) |
| OS/Version | 텍스트 | 빈 값 | OS (`os`) | 저장 (`...filter.os`) |
| WEB | 텍스트 | 빈 값 | WEB (`web`) | 저장 (`...filter.web`) |
| WAS | 텍스트 | 빈 값 | WAS (`was`) | 저장 (`...filter.was`) |
| DB | 텍스트 | 빈 값 | DB (`db`) | 저장 (`...filter.db`) |
| 이중화 | 텍스트 | 빈 값 | 이중화 솔루션 (`duplex`) | 저장 (`...filter.duplex`) |
| Agent: 설치 / 미설치 / 예외 | 체크박스 3개 | 3개 모두 체크 | Agent 설치 (`agent`) | 저장 (`...filter.agent_install` 등) |

- 텍스트 필터는 대소문자를 무시한 부분 일치다. 여러 칸을 채우면 모두 만족하는 행만 남는다(AND).
- Agent 체크박스는 체크한 상태 중 하나라도 맞으면 남는다(OR). 하나도 체크하지 않으면 전체를 보여 준다(5버전과 같다).
- 정렬(열 제목 클릭)도 사용자별로 저장된다 (`web.dmove.swinventory.sort`, `...sortorder`). 기본은 호스트 오름차순.

### 표의 열

| 열 | 값의 출처 | 표시 방식 |
|---|---|---|
| 호스트 | ITSM 행의 `ci_name` | Zabbix 에 등록되어 있고 인벤토리가 켜진 호스트면 코어 인벤토리 상세(`hostinventories.php?hostid=...`)로 가는 링크. 줄바꿈 안 함 |
| 표시명 | 매칭된 Zabbix 호스트의 표시 이름(`name`) | 매칭이 안 되면 빈칸 |
| IP | ITSM 의 `ip_address`. 비어 있으면 Zabbix 호스트의 대표 IP | 줄바꿈 안 함 |
| Description | ITSM 의 `description`. 비어 있으면 Zabbix 호스트 설명 | 긴 글은 줄바꿈 |
| OS | ITSM 값이 있으면 그것, 없으면 Zabbix 호스트 인벤토리 `os` 필드 | 글자 그대로 |
| WEB | 같은 방식. 인벤토리 `software_app_a` | 글자 그대로 |
| WAS | 같은 방식. 인벤토리 `software_app_b` | 글자 그대로 |
| DB | 같은 방식. 인벤토리 `software_app_c` | 글자 그대로 |
| 이중화 솔루션 | 같은 방식. 인벤토리 `software_app_d` | 글자 그대로 |
| Agent 설치 | 매칭 결과로 판정 (4장 4단계) | 설치 = 초록, 미설치 = 빨강, 예외 = 회색 글자 |

모든 열은 제목을 눌러 정렬할 수 있다.

### 버튼, 링크, 안내

- 제목 오른쪽: [CSV 내보내기] [Excel 내보내기]. 지금 저장된 필터와 정렬 그대로, 페이지 구분 없이 전체 행을 받는다. 파일 이름은 `sw_inventory_년월일_시분초.csv` / `.xlsx`.
- ITSM 설정이 비어 있으면 필터 아래에 노란 경고 상자가 뜬다: "ITSM 테이블이 설정되지 않아(conf/itsm.conf.php) Zabbix 호스트 목록 기준으로 표시 중입니다."
- 표 아래에 페이지 이동이 있다.

### 텍스트 스케치

```
SW 인벤토리                                         [CSV 내보내기] [Excel 내보내기]
┌ 필터 ──────────────────────────────────────────────────────────────────────┐
│ ITSM HOST  [          ]        WAS    [          ]                         │
│ OS/Version [          ]        DB     [          ]                         │
│ WEB        [          ]        이중화 [          ]                         │
│                                Agent  [v]설치 [v]미설치 [v]예외            │
│                                [적용] [초기화]                             │
└────────────────────────────────────────────────────────────────────────────┘
(ITSM 미설정 시 노란 경고 상자)
호스트▲ | 표시명 | IP | Description | OS | WEB | WAS | DB | 이중화 솔루션 | Agent 설치
web01  | 웹01   | .. | ...         | .. | ..  | ..  |    |               | 설치(초록)
db02   |        | .. | ...         |    |     |     |    |               | 미설치(빨강)
```

## 4. 어떻게 만들었나 (동작 순서)

화면 요청과 내보내기 요청이 같은 컨트롤러 `SwInventoryList` 를 쓴다. 코어는 `init() -> checkInput() -> checkPermissions() -> doAction()` 순서로 부른다.

1. **CSRF 검사 끄기** - `SwInventoryList::init()`
   조회 전용 화면이라 `disableCsrfValidation()` 을 부른다. (5버전의 `disableSIDValidation()` 은 7버전에 없다)

2. **입력 검증** - `SwInventoryList::checkInput()`
   `page`, `filter_set`, `filter_rst`, `sort`, `sortorder`, `format`, 텍스트 필터 6개(`filter_itsm` ...), 체크박스 3개를 규칙으로 등록한다.
   ```php
   'sort' => 'in '.implode(',', SwInventoryService::SORT_FIELDS),
   ```
   - 정렬 열 이름은 미리 정한 목록 안의 값만 받는다. `sort=아무거나` 를 보내면 오류 화면이 나온다.
   - 텍스트/체크박스 필터 규칙은 `SwInventoryService::TEXT_FILTERS`, `AGENT_FILTERS` 상수를 돌면서 자동으로 만든다. 필터를 하나 더하려면 상수에 한 줄 넣으면 된다.

3. **권한 확인** - `SwInventoryList::checkPermissions()`
   `$this->checkAccess(CRoleHelper::UI_INVENTORY_HOSTS)`. 5버전은 무조건 `true` 였다.

4. **필터 저장/읽기** - `SwInventoryList::doAction()` 1~2번 블록
   - [적용]이면 `updateProfiles()` 가 `CProfile::update()` 로 값을 저장한다. 체크를 푼 체크박스는 요청에 실리지 않으므로 0 으로 저장한다.
   - [초기화]면 `deleteProfiles()` 가 `CProfile::deleteIdx()` 로 지운다.
   - `getFilter()` 가 프로파일에서 값을 읽는다. 체크박스 기본값은 1 이라 처음에는 3개 모두 체크 상태다.
   - 정렬 값도 요청에 없으면 지난번 값을 쓰고, 다시 저장한다.

5. **데이터 만들기** - `SwInventoryService::getRows()`
   1. Zabbix 호스트를 한 번에 읽는다. **권한과 관계없이 전체 호스트**다 (5버전은 권한 적용이었으나 사용자 결정으로 전체. 2026-11-23 결정, 2026-11-24 재확정).
      - `fetchAllHosts()` 가 DB 를 직접 읽는다. `hosts` (상태 0/1 = 모니터링/중지, 템플릿 제외, flags 0/4 = host.get 기본값과 같음)
        + `host_inventory` LEFT JOIN (줄이 없으면 인벤토리 꺼짐) + `interface` 를 따로 읽어 호스트에 붙인다. 그래서 행 중복이 없다.
      - 왜 전체인가: ITSM 목록은 권한과 관계없이 전부 나온다. Zabbix 호스트만 권한으로 줄이면, 권한 밖 호스트가 실제로는
        등록돼 있는데도 "미설치"로 보인다.
      - **정정 (2026-11-24)**: 5버전은 권한 없이 전체를 비교한 것이 아니었다. 5버전은 `API::Host()->get()` 으로 호스트를 읽어 **사용자 권한이 적용됐다** (`v5_restored/src/CControllerSwinventories.php:200-208`). Super admin 은 전체가 보이므로 같은 결과지만, 일반 사용자는 5버전에서 권한 밖 호스트가 "미설치"로 보였다. 지금 7버전 코드는 전체를 읽는다. 어느 쪽으로 할지 사용자 확인 대기.
      - 따로 `API::Host()->get(['output' => ['hostid']])` 로 사용자가 볼 수 있는 호스트를 구한다. 코어 인벤토리 상세 화면 링크는
        이 호스트에만 단다. (권한 밖 호스트를 열면 코어가 권한 오류를 띄우기 때문)
      - 인벤토리는 `os`, `software_app_a~d` 다섯 필드만 가져온다 (`SoftwareResolver::getInventoryFields()`).
      - 인터페이스는 대표 IP 를 고르기 위해 `ip`, `main`, `type` 만 가져온다.
   2. `호스트명(소문자) => hostid` 색인을 만든다. `mb_strtolower()` 를 쓴다.
      5버전은 ITSM 행마다 전체 호스트를 다시 도는 이중 반복문이었다. 예를 들어 ITSM 2,000행 x 호스트 2,000대면 400만 번 비교했지만, 지금은 2,000번 찾기로 끝난다.
   3. ITSM 목록을 읽는다 - `ItsmRepository::fetch()`
      - `conf/itsm.conf.php` 의 `schema`, `table` 을 읽고 영문자/숫자/밑줄만 있는지 검사한다(`getQualifiedTableName()`).
      - `information_schema.tables` 로 테이블이 실제로 있는지 확인한다(`tableExists()`, 값은 `zbx_dbstr()` 로 감싼다).
      - `SELECT id,ci_name,ip_address,description FROM <스키마>.<테이블>` 을 `DBselect()` 로 실행한다.
      - 설정이 비었거나, 이름이 규칙에 안 맞거나, 테이블이 없거나, 조회가 실패하면 `null` 을 돌려준다.
   4. ITSM 이 `null` 이면 **대체 모드**: Zabbix 호스트를 ITSM 행처럼 만든다(`ci_name` = 호스트명, `ip_address` = 빈 값, `description` = 호스트 설명). `isItsmFallback()` 이 `true` 가 되어 화면에 경고가 뜬다. 이 모드에서는 모든 행이 "설치"가 된다.
   5. ITSM 행마다 한 줄을 만든다.
      - `ci_name` 이 비어 있으면 건너뛴다.
      - `ci_name` 을 소문자로 바꿔 색인에서 찾는다. 찾으면 그 Zabbix 호스트와 "매칭"된 것이다.
      - IP: ITSM 값이 비어 있으면 `HostHelper::mainIp()` 로 고른다. 우선순위는 Agent 기본 인터페이스(3점) > 다른 종류의 기본 인터페이스(2점) > 기본이 아닌 인터페이스(1점). 예) SNMP 기본 `10.0.0.5` 와 Agent 기본 `10.0.0.1` 이 있으면 `10.0.0.1`.
      - Description: ITSM 값이 비어 있으면 Zabbix 호스트 설명.
      - 표시명: 매칭이 안 되면 빈 값. (5버전은 여기서 변수를 비우지 않아 바로 앞 행의 표시명이 찍히는 버그가 있었다)
      - OS/WEB/WAS/DB/이중화: `SoftwareResolver::resolve()` (아래 6단계)
      - Agent: `resolveAgentStatus()` (아래 7단계)

6. **소프트웨어 값 정하기** - `SoftwareResolver::resolve()`
   열마다 1순위 ITSM 행의 같은 이름 키(`os`, `web` ...), 2순위 Zabbix 인벤토리 필드를 쓴다.
   ```php
   $value = trim((string) ($itsm_row[$column] ?? ''));
   if ($value === '') {
       $value = trim((string) ($inventory[$inventory_field] ?? ''));
   }
   ```
   - 지금 `ItsmRepository::fetch()` 는 `os`, `web` 같은 열을 읽지 않으므로, 실제로는 **항상 인벤토리 값**이 나온다. 1순위 자리는 나중에 ITSM 이 그 값을 줄 때를 위해 비워 둔 것이다.
   - 인벤토리가 꺼진 호스트는 API 가 빈 배열을 주므로 빈칸이 된다.

7. **Agent 상태 정하기** - `SwInventoryService::applyAgentStatus()` (5버전 Agent 판정 클래스 `...HostAgent` 와 같은 규칙)

   | 조건 | 상태 | 표시 |
   |---|---|---|
   | ITSM 서버 이름과 같은 Zabbix 호스트가 있다 | 설치(1) | 초록 글자 |
   | 없다 + Agent 상태 표의 가장 최근 기록이 2 | 미설치(2) | 빨간 링크 |
   | 없다 + 가장 최근 기록이 4 | 예외(4) | 회색 링크 |
   | 없다 + 가장 최근 기록이 그 밖의 값 | 설치(1) | 5버전 코드 그대로 |
   | 없다 + 기록이 없다 (또는 표가 없다) | 미설치(2) | 빨간 링크 |

   - Agent 상태 표는 `extends_agent` 다 (`conf/swinventory.conf.php`). 줄의 키는 `itsm_` + ITSM id 이고 5버전과 같다. 그래서 5버전 DB 를 올렸으면 기존 미설치/예외 기록이 그대로 이어진다.
   - 목록에 있는 서버들의 최신 기록을 쿼리 한 번으로 읽는다(`AgentStatusRepository::latestByKeys()`). 5버전은 서버마다 한 번씩 읽었다.
   - 미설치/예외 링크에 마우스를 올리면 마지막 사유와 입력자, 시각이 보인다.
   - 값 1, 2, 4 는 2진수 001, 010, 100 이다. 필터에서 "설치 + 예외"를 체크하면 `1 | 4 = 5` 가 되고, 행마다 `(상태 & 5) != 0` 로 한 번에 검사한다. 예) 미설치(2) 는 `2 & 5 = 0` 이라 빠진다.

7-1. **Agent 상태 입력 창** - 미설치/예외 링크를 누르면 열린다 (`swinventory.agent`, 5버전 `popup.agent`)
   - 창: "Agent 상태: 서버 이름". 상태(미설치 / 예외) 선택, 사유(최대 2048자), 아래에 최근 기록 5개(시각, 상태, 사유, 입력자). [저장] [취소].
   - [저장]: `swinventory.agent.save` 가 표에 한 줄을 **추가**한다. 고치거나 지우지 않고 쌓는다(5버전과 같다). 가장 최근 줄이 지금 상태다.
   - 저장 요청은 CSRF 토큰과 권한(인벤토리 > 호스트 화면 권한)을 검사한다. 5버전은 권한 검사가 없었다.
   - 키가 `itsm_` 로 시작하지 않으면(= Zabbix 에 등록된 서버) 거부한다. 등록된 서버는 항상 "설치"이기 때문이다.
   - 5버전 입력 창의 소스는 받지 못했다. 표의 열 구성과 5버전의 "최근 5줄 읽기", "한 줄 추가" 코드를 근거로 만들었다. 창의 모양은 5버전과 다를 수 있다.
   - 표가 없으면 목록 위에 노란 안내가 나오고, 창에는 [저장] 버튼 대신 안내가 나온다. 5버전은 표가 없으면 화면을 여는 도중에 표를 만들었다. 7버전은 자동으로 만들지 않고 `sql/extends_agent.sql` 을 둔다.

8. **필터** - `SwInventoryService::filter()`
   텍스트 필터는 `mb_stripos()` 로 부분 일치, Agent 는 위의 비트 검사. 마지막에 `array_values()` 로 번호를 다시 매긴다.

9. **정렬** - `SwInventoryService::sort()`
   `CArrayHelper::sort()` 로 대소문자를 무시하고 정렬한다. Agent 열은 값(1/2/4) 순서라 오름차순이면 설치 > 미설치 > 예외 순이다.

10. **내보내기면 여기서 끝** - `doAction()` 4번 블록
    `isExportRequest()` (액션 이름이 `.export` 로 끝나는가) 가 참이면 Agent 값을 글자(설치/미설치/예외)로 바꾸고 `setExportResponse()` 로 넘긴다. 레이아웃 `export/layout.file` 이 CSV 또는 XLSX 파일을 만든다. 열 정의는 화면과 같은 `getColumns()` 를 쓴다.

11. **화면 응답** - `doAction()` 5번 블록과 뷰 `views/inventory/swinventory.list.php`
    `CPagerHelper::paginate()` 로 현재 페이지 분량만 자른다. 뷰는 `CFilter`, `CTableInfo`, `make_sorting_header()` 로 화면을 조립하고 `CHtmlPage` 로 출력한다. 화면 클래스가 HTML 이스케이프를 해 준다.
    주의: 화면 목록은 코어 설정 "검색/필터 결과 제한"(관리 > 일반 > GUI, 기본 1000) 까지만 보인다. 그보다 많으면 내보내기로 받는다.

## 5. 5버전과 비교

| 주제 | 5버전 | 7버전 | 왜 |
|---|---|---|---|
| 호스트 목록 | `API::Host()->get()` (호스트 그룹/호스트 필터만). 사용자 권한이 적용됨 (`CControllerSwinventories.php:200-208`). (이전 문서의 "`swinventory` DB 뷰를 직접 조회, 권한과 관계없이 전체"는 틀린 설명이었다. 2026-11-24 정정) | DB 직접 조회(`fetchAllHosts()`). 권한과 관계없이 전체. 인벤토리 상세 링크만 볼 수 있는 호스트에 | 전체를 보는 것은 2026-11-23 결정. 그 결정의 근거("5버전도 전체")가 틀렸음이 2026-11-24 확인됐으나, 같은 날 사용자가 "전체 서버를 보는 게 맞다"로 다시 정함. 5버전 뷰의 행 중복(인터페이스 여러 개), 템플릿 섞임은 고쳤다 |
| ITSM 테이블 이름 | 트레잇 소스 맨 위에 고정 | `conf/itsm.conf.php` 설정. 이름 글자 검사, 테이블 존재 확인 | 공개 저장소에 고객 값을 넣지 않기 위해, 그리고 테이블이 없을 때 DB 오류가 화면에 뜨지 않게 |
| ITSM 테이블이 없을 때 | 존재 확인 함수가 실패 결과를 그대로 읽어 PHP 8 에서 경고 | 조용히 "미연동"으로 처리하고 Zabbix 호스트 기준으로 표시 + 경고 상자 | 개발/검증 환경에서도 화면이 뜨게 |
| 매칭 방식 | ITSM 행마다 전체 호스트를 도는 이중 반복문, `strtolower()` | 소문자 호스트명 색인 한 번, `mb_strtolower()` | 속도, PHP 8.1 의 `null` 경고 방지 |
| 매칭 안 된 행의 표시명 | **버그**: 앞 행의 표시명이 그대로 찍힘 | 빈칸 | 미설치 서버에 다른 서버 이름이 보이던 오류 수정 |
| OS/WEB/WAS/DB/이중화 | 아이템 값을 읽는 단계(`history_str`)가 먼저 돌고 인벤토리 단계가 마지막에 덮어씀. 아이템 값 조회 부분은 주석 처리되어 있어 실제로는 인벤토리 값만 나왔다. 값이 없으면 빨간 `-` / `--` 와 말풍선 | 인벤토리 필드만 사용. 값이 없으면 빈칸 | 실제로 동작하던 경로만 옮김. 표시 방식은 다르다(빈칸) |
| IP | 인터페이스가 여러 개면 첫 IP + 말풍선에 전체 목록. 없으면 빨간 `---` | ITSM IP 우선, 없으면 대표 IP 하나. 없으면 빈칸 | 대표 IP 를 정하는 규칙을 공용 부품(`HostHelper`)으로 통일 |
| Agent "예외" | Agent 판정 클래스(`...HostAgent`). Zabbix 에 등록된 호스트는 "설치". 그렇지 않은 행은 별도 표 `extends_agent` 의 가장 최근 기록으로 미설치/예외. 글자를 누르면 입력 창(`popup.agent`). 표가 없으면 화면을 열 때 만들었다. 서버마다 쿼리 1번, 값을 쿼리에 이어 붙임 | 같은 표, 같은 규칙. 입력 창(`swinventory.agent`) 구현. 최신 기록을 한 번에 읽고 값은 `zbx_dbstr()` 로 감쌈. 표는 자동으로 만들지 않음(`sql/extends_agent.sql`). 저장에 CSRF·권한 검사 추가 | 2026-11-23 구현 |
| 정렬 가능한 열 | 호스트, OS, WEB, WAS, DB, Agent설치 | 10개 열 전부 | 편의 |
| 긴 값 | 20자 넘으면 줄이고 전체는 말풍선 | 줄이지 않음 (Description 은 줄바꿈) | 미반영 (8장 제안) |
| 권한 | 무조건 허용 | "인벤토리 > 호스트" 화면 권한 | 로그인만 하면 누구나 보던 것을 역할 기반으로 |
| 내보내기 | 별도 CSV 컨트롤러 | 같은 컨트롤러 + 공용 내보내기 부품, CSV 와 Excel | 화면과 파일의 열/필터가 항상 같게 |
| 입력값 | `getRequest()` 로 검증 없이 읽음 | `checkInput()` 규칙으로 검증 | 보안, 7버전 MVC 방식 |
| 5버전 테스트용 말풍선 | `test_set` 요청값이 있으면 호스트명에 hostid 말풍선 | 없음 | 디버그 기능이라 옮기지 않음 |

## 6. 설정

**`conf/itsm.conf.php`**

| 값 | 뜻 | 비어 있을 때 |
|---|---|---|
| `schema` | ITSM 테이블이 있는 DB 스키마 이름. 영문자, 숫자, 밑줄만 | 미연동. Zabbix 호스트 목록 기준으로 표시하고 경고 상자를 띄운다. 모든 행이 "설치" |
| `table` | ITSM 테이블 이름. 같은 글자 규칙 | 위와 같다 |

- 읽는 열: `id`, `ci_name`, `ip_address`, `description`. Zabbix 가 쓰는 DB 계정에 SELECT 권한이 있어야 한다.
- 같은 설정을 다른 화면(저사용/고사용 서버 등)의 Description 에서도 쓴다 (`ItsmRepository::fetchDescriptions()`).

**코드 안의 매핑 (설정 파일은 아니지만 바꿀 수 있는 곳)**

- `includes/SoftwareResolver.php` 의 `INVENTORY_FIELDS`: 열 => 인벤토리 필드 (`os`, `software_app_a~d`).

## 7. 비어 있는 부분과 가정

| 자리 | 내용 | 확인할 5버전 파일 | 고칠 곳 |
|---|---|---|---|
| `TODO(고객환경)` ITSM 테이블 이름 | 공개 저장소라 비워 둠 | ITSM 트레잇(`...Itms.php`) 맨 위 두 줄 | `conf/itsm.conf.php` |
| 확인 | Agent 상태 표 `extends_agent` 가 고객 7버전 DB 에 있는지. 5버전 DB 를 올렸으면 있어야 한다. 없으면 `sql/extends_agent.sql` 을 실행한다 | `\dt *.extends_agent` (psql) | `conf/swinventory.conf.php` |
| `TODO(고객환경)` 소프트웨어 판별 | 인벤토리 필드만 사용. 아이템 값으로 판별하는 규칙이 있으면 추가 | 같은 클래스, 또는 SW 인벤토리 컨트롤러가 쓰는 트레잇 | `includes/SoftwareResolver.php` 의 `resolve()` |
| `TODO(고객환경)` 권한 | "인벤토리 > 호스트" 권한으로 가정 | `src/menu.inc.php` 의 메뉴별 조건 | `SwInventoryList::checkPermissions()` |
| 인벤토리 값 | 7버전 서버의 호스트 인벤토리 `os`, `software_app_a~d` 에 값이 들어 있다고 가정 | (서버에서 호스트 인벤토리 탭 확인) | 서버 설정, 또는 `SoftwareResolver` |
| 목록 기준 | ITSM 에 있는 서버만 표시. Zabbix 에만 있는 호스트는 나오지 않는다 (5버전도 ITSM 기준으로 행을 만든 것으로 보이나 확인 필요) | `CControllerSwinventories.php` 289행 이후 | `SwInventoryService::getRows()` |
| 5버전 "호스트" 열 | 5버전 규칙 파일의 열 함수 순서상 "호스트" 열과 "표시명" 열에 어느 필드가 들어갔는지 확실하지 않다. 7버전은 호스트 = ITSM `ci_name`, 표시명 = Zabbix 표시 이름 | 5버전 화면과 비교 | 뷰의 `addRow` |

`../04_남은_작업/미구현_목록.md` 3장, `../04_남은_작업/고객환경_체크리스트.md` 1-1, 1-6, 2-6 과 같은 내용이다.

## 8. 개선점

### 이미 반영한 개선

- 호스트 목록: 5버전처럼 권한과 관계없이 전체를 읽되, 행 중복과 템플릿 섞임을 고쳤다. 권한 밖 호스트에는 인벤토리 상세 링크를 달지 않는다.
- 매칭 안 된 행에 앞 행의 표시명이 찍히던 5버전 버그 수정.
- 이중 반복문을 색인으로 바꿔 속도 개선.
- ITSM 테이블 이름을 설정 파일로, 이름 검사와 존재 확인으로 SQL 인젝션과 DB 오류 방지.
- Excel 내보내기 추가, 화면과 같은 열/필터/정렬로 내보내기.
- 다국어(`Lang::t()` + `lang/ko.php`), 입력 검증, 역할 기반 권한.
- 모든 열 정렬 가능.

### 남은 개선 제안

| 제안 | 효과 | 5버전과 결과가 달라지나 (고객 합의) |
|---|---|---|
| ITSM 정보를 Zabbix 호스트 인벤토리/태그로 동기화(배치 또는 수동 스크립트)하고, 이 화면은 Zabbix 데이터만 읽기 | ITSM 테이블을 Zabbix DB 안에 둘 필요가 없어지고, 코어 인벤토리 화면/필터/대시보드에서도 같은 값을 쓸 수 있다 | 화면 결과는 같게 만들 수 있다. 운영 방식(동기화 주기, 담당)이 바뀌므로 합의 필요 |
| Agent 상태를 7.0 의 인터페이스 가용성으로 판단. `host.get` 에 `selectInterfaces => ['ip','main','type','available']`, 능동형 Agent 는 호스트의 `active_available` | "등록만 되어 있고 실제로는 수집이 끊긴" 서버를 찾을 수 있다 | 달라진다. 지금은 "Zabbix 에 등록 = 설치". 상태를 하나 더 두거나(예: 설치(응답 없음)) 기준을 바꾸려면 합의 필요 |
| 비활성(감시 안 함) 호스트를 따로 표시 | 감시를 꺼 둔 서버가 "설치"로 보이는 착시를 없앤다 | 달라진다. 합의 필요 |
| 값이 없을 때 '-' 표시 통일 | 빈칸이 "값 없음"인지 "아직 안 읽힘"인지 구분이 쉬워진다. 다른 화면(Box 등)과 표시 통일 | 5버전도 빨간 `-` 를 보였으므로 오히려 가까워진다. 합의는 가볍게 |
| 20자 넘는 값은 줄이고 말풍선으로 전체 표시 | 5버전과 같은 표의 폭 | 5버전으로 돌아가는 것. 합의 불필요 |
| "Zabbix 에만 있고 ITSM 에 없는 호스트" 목록 추가(옵션) | 자산 대장 누락을 반대 방향으로도 찾는다 | 기능 추가라 기존 결과는 그대로. 합의 권장 |
| 쓰이지 않는 `$data['total']` 정리 | 코드 정리 | 없음 |

## 9. 확인 방법

### 테스트 환경 (http://127.0.0.1:18970, `Admin` / `zabbix`, `dev/compose.yaml`)

1. `dev` 폴더에서 `docker compose up -d`, 모듈 켜기(`dev/README.md`).
2. 인벤토리 > SW 인벤토리를 연다.
   - 기대: 노란 경고 상자(ITSM 미설정). 기본 호스트(예: Zabbix server)가 한 줄 이상 나오고 Agent 설치는 모두 초록 "설치".
3. 데이터 수집 > 호스트 > 아무 호스트 > 인벤토리 탭에서 "수동"으로 바꾸고 OS, 소프트웨어 애플리케이션 A~D 에 글자를 넣고 저장한다.
   - 기대: SW 인벤토리의 OS/WEB/WAS/DB/이중화 솔루션 칸에 그 글자가 보이고, 호스트 이름이 인벤토리 상세 링크가 된다.
4. 필터 OS/Version 에 3번에서 넣은 글자의 일부를 대문자로 넣고 [적용].
   - 기대: 그 호스트만 남는다(대소문자 무시). 다른 화면에 갔다 와도 필터가 유지된다. [초기화]를 누르면 전체로 돌아오고 체크박스 3개가 다시 체크된다.
5. 열 제목을 눌러 정렬, [CSV 내보내기] [Excel 내보내기].
   - 기대: 파일 이름 `sw_inventory_...`, 화면과 같은 행/순서, Agent 열은 "설치" 같은 글자.
6. (선택) ITSM 연동 시험. 테스트 DB 에 임의 이름의 표를 만든다. 예:
   ```sql
   CREATE SCHEMA dev_itsm;
   CREATE TABLE dev_itsm.servers (id int, ci_name text, ip_address text, description text);
   INSERT INTO dev_itsm.servers VALUES (1, 'ZABBIX SERVER', '', 'ITSM 설명'), (2, 'not-in-zabbix', '10.0.0.9', '');
   ```
   로컬에서만 `conf/itsm.conf.php` 에 `'schema' => 'dev_itsm', 'table' => 'servers'` 를 넣는다(커밋하지 않는다).
   - 기대: 경고 상자가 사라진다. 1번 행은 대소문자가 달라도 매칭되어 "설치", Description 은 "ITSM 설명". 2번 행은 표시명 빈칸, IP `10.0.0.9`, 빨간 "미설치".
   - `table` 에 하이픈 같은 허용되지 않는 글자를 넣으면 다시 미연동 경고가 나와야 한다.
7. 화면 위에 빨간 PHP 경고가 하나도 없어야 한다.

### 고객환경에서 5버전과 비교

1. `conf/itsm.conf.php` 를 채운 뒤, 같은 필터(예: Agent 미설치만)로 5버전 화면과 7버전 화면을 나란히 연다.
2. 행 수가 같은지 본다. 다르면 호스트명 대소문자/공백 차이를 확인한다. (호스트 목록은 5버전처럼 권한과 관계없이 전체라서 사용자 권한에 따른 차이는 없다)
3. 5버전에서 매칭이 안 된 행의 표시명이 7버전에서 빈칸인 것은 버그 수정이므로 차이로 보지 않는다.
4. 미설치/예외 줄의 수가 5버전과 같은지 본다. 같은 표를 읽으므로 같아야 한다. 다르면 표 위치(`conf/swinventory.conf.php`)와 ITSM id 가 같은지 확인한다.
5. OS/WEB/WAS/DB/이중화가 5버전에서 빨간 `-` 인 칸은 7버전에서 빈칸이면 같은 결과다.

## 10. 파일 목록

### 이 화면의 파일

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/actions/inventory/swinventory/SwInventoryList.php` | 컨트롤러. 화면(`swinventory.list`)과 내보내기(`swinventory.export`) 두 액션이 같이 쓴다. 입력 검증, 권한, 필터 저장, 열 정의 |
| `ui/modules/dmove/views/inventory/swinventory.list.php` | 화면 뷰. 필터, 표, 내보내기 버튼, ITSM 미연동 경고 |
| `ui/modules/dmove/includes/SwInventoryService.php` | 데이터 조회, ITSM-Zabbix 매칭, Agent 판정, 필터, 정렬 |
| `ui/modules/dmove/includes/SoftwareResolver.php` | OS/WEB/WAS/DB/이중화 값 결정. 인벤토리 필드 매핑 `INVENTORY_FIELDS` |
| `ui/modules/dmove/conf/itsm.conf.php` | ITSM 스키마/테이블 이름 설정 |
| `ui/modules/dmove/conf/swinventory.conf.php` | Agent 상태 표 위치 설정 |
| `ui/modules/dmove/includes/AgentStatusRepository.php` | Agent 상태 표 읽기(최신 기록, 최근 5개)와 쓰기 |
| `ui/modules/dmove/actions/inventory/swinventory/AgentEdit.php`, `AgentSave.php` | Agent 상태 입력 창 열기, 저장 |
| `ui/modules/dmove/views/inventory/swinventory.agent.php` | 입력 창 내용 |
| `ui/modules/dmove/views/js/inventory/swinventory.list.js.php` | 링크를 누르면 창을 열고 저장하는 스크립트 |
| `ui/modules/dmove/sql/extends_agent.sql` | 표가 없을 때 만드는 SQL |
| `ui/modules/dmove/manifest.json` | `swinventory.list`, `swinventory.export`, `swinventory.agent`, `swinventory.agent.save` 액션 등록 |
| `ui/modules/dmove/Module.php` | `INVENTORY_MENUS` 에 메뉴 등록 |

### 같이 쓰는 부품

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/includes/ItsmRepository.php` | ITSM 목록 조회(`fetch()`), 호스트명 => Description 표(`fetchDescriptions()`, 다른 화면용) |
| `ui/modules/dmove/includes/HostHelper.php` | 대표 IP 고르기(`mainIp()`) |
| `ui/modules/dmove/includes/export/ExportTrait.php`, `ExportButtons.php`, `ExportFormat.php`, `CsvWriter.php`, `XlsxWriter.php`, `ZipWriter.php` | CSV/Excel 내보내기 |
| `ui/modules/dmove/includes/Lang.php`, `ui/modules/dmove/lang/ko.php` | 다국어 문구 |

## 부록: 5버전 Agent 입력 창 대조 (2026-11-24)

- 받은 `CControllerPopupAgent.php`, `popup.agent.js` 와 대조했다. 저장 항목(호스트, 상태, 사유, 입력한 사용자 ID/이름/로그인 이름)과 "고치지 않고 계속 쌓는" 방식이 같다.
- **다른 점 하나를 고쳤다.** 5버전은 사유가 비어 있으면 "Agent 미설치 및 예외 사유를 입력해 주세요" 로 막았다. 7버전은 비어도 저장됐다. 이제 같은 문구로 막고, 사유 칸에 빨간 * 를 붙였다 (`AgentSave.php`, `swinventory.agent.php`).
- 5버전 화면 스크립트는 상태 1(설치)도 처리했지만, 입력 창 뷰(`popup.agent.php`)를 못 받아 창에서 "설치"를 고를 수 있었는지는 모른다. 7버전은 미설치/예외만 고른다.
- 호스트 범위: 사용자가 "전체 서버를 보는 게 맞다"로 다시 정했다 (2026-11-24). 5버전은 권한 안의 서버만 비교했다.
