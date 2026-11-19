# 고객환경에서 확인할 것

아직 확인하지 못해서 코드를 비워 둔 곳을 정리했다. 위에서부터 급한 순서다.
각 항목의 "막힌 곳"이 이 내용을 몰라서 비워 둔 코드다.

## 확인하는 방법

- 5버전 소스와 화면은 **고객환경 안에서 열어 보고**, 필요한 내용(아이템 키, 기준 값, 컬럼 구성, 처리 순서)을 파악한다.
- 고객 자료(소스 파일, 화면, 실제 서버 이름, IP, 계정, 접속 주소)는 **밖으로 가져오지 않는다.** 이 저장소에도 넣지 않는다.
- 파악한 내용은 고객환경 안에서 이 모듈 코드의 `TODO(고객환경)` 자리에 직접 채운다.
- 표의 "볼 것" 열은 어떤 파일/화면을 열어 봐야 하는지, "확인하려는 것" 열은 거기서 무엇을 알아내야 하는지다.

## 1순위: SW 인벤토리를 끝내는 데 필요한 것

| # | 볼 것 | 종류 | 확인하려는 것 | 막힌 곳 |
|---|---|---|---|---|
| 1-1 | ITSM 트레잇(`controllers/...Itms.php`) 9~11행 | 값 확인 | `$itsmSchema`, `$itsmTable` 의 정확한 값 | `conf/itsm.conf.php` |
| 1-2 | 7버전 DB 에 ITSM 테이블이 있는지 | 값 확인. `psql` 에서 `\dt *.itsm_info` | 7버전 DB 로 테이블이 옮겨졌는지, 스키마 이름, Zabbix DB 계정의 조회 권한 | `conf/itsm.conf.php` |
| 1-3 | Agent 판정 클래스 (`new ...HostAgent(...)` 로 쓰이는 클래스 파일 전체) | 소스 | `_hostAgent_checkAll()`, `get()` 의 로직. **"예외"(값 4)를 어떤 기준으로 정하는지** | `includes/SwInventoryService.php` 의 `resolveAgentStatus()` |
| 1-4 | `CControllerSwinventories.php` 288행 이후 끝까지 | 소스 | 288행까지만 검토했다. 정렬, CSV, 응답 부분 | 동작 차이 확인 |
| 1-5 | SW 인벤토리 뷰 (`sw.list.php`) 전체 | 소스 | 컬럼별 표시 방식(말줄임, 말풍선, 색) | `views/inventory/swinventory.list.php` |
| 1-6 | 5버전 `conf/rules/swinventory.json` 전체 | 소스 | `table->func`, `displayField`, 미들웨어 실행 순서. OS/WEB/WAS/DB 를 **인벤토리에서 읽는지 아이템 값(history_str)에서 읽는지** 여기서 정해진다 | `includes/SoftwareResolver.php` |
| 1-7 | 아이템 키 클래스 (`new ...Key()` 로 쓰이는 클래스 파일 전체) | 소스 | `osName()`, `osVersion()`, `inventoryWeb()`, `inventoryWas()`, `inventoryDB()`, `inventoryDuplex()` 가 돌려주는 아이템 키 목록 | 1-6 에서 아이템 값을 쓴다고 확인되면 필요 |
| 1-8 | 미들웨어 클래스 (`...Middle` 파일 전체), 데이터 트레잇(`...Data` 파일 전체) | 소스 | `before()` 가 규칙 파일을 읽어 `middle_*` 함수를 부르는 순서 | 1-6 과 같이 봐야 한다 |
| 1-9 | SW 인벤토리 화면 (5버전, 필터 펼친 상태) | 화면 | 실제 표시 모양 | 뷰 모양 맞추기 |

## 2순위: 서버 시간 확인, 장기 미접속 계정 현황

화면 구성은 5버전과 같게 만들어 두었고 데이터 조회만 비어 있다.

| # | 볼 것 | 종류 | 확인하려는 것 | 막힌 곳 |
|---|---|---|---|---|
| 2-1 | `CControllerUptimeView.php` 전체 | 소스 | 서버 Time / 서버 start 를 읽는 아이템 키, OS 와 Description 의 출처, "day 이상" 비교 방식, 표시 형식 | `actions/reports/uptime/UptimeList.php` 의 `getRows()` |
| 2-2 | 서버 시간 확인의 뷰 파일 전체 | 소스 | 컬럼 표시 방식, 정렬 가능한 컬럼 | `views/reports/uptime.list.php` |
| 2-3 | `routing.php` 에서 `uptime.view` 줄 | 값 확인 | 컨트롤러/뷰 파일 이름 | 2-1, 2-2 파일 찾기 |
| 2-4 | 장기 미접속 계정 현황 컨트롤러 전체 (`routing.php` 의 `user.unconnect` 줄에 적힌 클래스) | 소스 | **"장기"의 기준 일수**, 최근접속일시의 출처(테이블/컬럼), 비활성 계정과 미접속 계정 처리 | `actions/users/userunconnect/UserUnconnectList.php` 의 `getRows()` |
| 2-5 | 장기 미접속 계정 현황 뷰 전체 | 소스 | 오른쪽 위 "유저 그룹" 선택 동작, 컬럼 표시 | `views/users/userunconnect.list.php` |
| 2-6 | 장기 미접속 계정 화면에 데이터가 나온 상태 | 화면 | 최근접속일시 표시 형식, 그룹 표시 방식 (계정명은 가려도 된다) | 표시 형식 |

## 3순위: 껍데기만 있는 보고서 7개

화면마다 아래 3가지를 확인한다. **화면 구성이 가장 중요하다.** (필터와 컬럼을 알아야 뷰를 만들 수 있다)

1. 화면 구성 (필터 펼친 상태 + 표 제목 줄 + CSV 등 버튼이 있으면 그 부분)
2. 컨트롤러 파일 전체
3. 뷰 파일 전체

| # | 화면 | 7버전 액션 | 5버전 액션(추정) | 화면 | 컨트롤러 | 뷰 |
|---|---|---|---|---|---|---|
| 3-1 | 저사용 서버 사용현황 | `lowserver.list` | `lowserver` | ☐ | ☐ | ☐ |
| 3-2 | 고사용 서버 사용현황 | `highserver.list` | `highserver` | ☐ | ☐ | ☐ |
| 3-3 | 성능 데이터 추세 분석 | `perftrend.list` | 모름 | ☐ | ☐ | ☐ |
| 3-4 | 일일점검 | `dailycheck.list` | `daily` | ☐ | ☐ | ☐ |
| 3-5 | Peak 이벤트 분석 | `peakevent.list` | 모름 | ☐ | ☐ | ☐ |
| 3-6 | 윈도우즈 패치 현황 | `winpatch.list` | `windows`, `windowsall` | ☐ | ☐ | ☐ |
| 3-7 | CPU/Memory 전체 사용률 추출 | `usageexport.list` | 모름 | ☐ | ☐ | ☐ |

같이 필요한 것:

| # | 볼 것 | 종류 | 확인하려는 것 |
|---|---|---|---|
| 3-8 | `routing.php` 전체 | 소스 | 위 표의 "5버전 액션"을 확정한다. 각 화면의 컨트롤러/뷰 파일 이름, CSV·팝업 액션(`*.csv`, `popup.*`, `hostbox`, `hostesxi`)이 어느 화면에 딸린 것인지 |
| 3-9 | 각 화면이 쓰는 `conf/rules/*.json` | 소스 | 화면별 컬럼/함수 규칙 |
| 3-10 | 화면에서 팝업이 뜨는 경우 그 팝업 | 화면 | 팝업 화면은 별도 액션으로 만들어야 한다 |
| 3-11 | 날짜 선택 부품 (`src/CDateChoice.php`) | 소스 | 추세 분석, Peak 분석처럼 기간을 고르는 화면에서 쓰였을 가능성이 높다 |

## 4순위: 메뉴, 화면 꾸밈

| # | 볼 것 | 종류 | 확인하려는 것 | 막힌 곳 |
|---|---|---|---|---|
| 4-1 | `src/menu.inc.php` 에서 커스텀 메뉴를 추가한 부분 | 소스. 가장 오래된 백업(`menu.inc.php.날짜`)과 `diff` 한 결과면 더 좋다 | 메뉴별 접근 권한(유저 종류 조건), 사이드바 아래 "Agent 설치파일", "사용자메뉴얼" 의 링크 주소 | `Module.php` 의 TODO |
| 4-2 | `src/CMenu.php` 의 변경 부분 | `diff` 결과 | 메뉴 표시를 바꾼 내용 | `Module.php` |
| 4-3 | `src/blue-theme.css` 에서 추가한 부분 | `diff` 결과 | 커스텀 CSS (로고, 색) | `assets/css/dmove.css` |
| 4-4 | `src/jsLoader.php` 의 변경 부분 | `diff` 결과 | 추가로 불러오는 JS 파일 | 화면별 JS |
| 4-5 | 사이드바 왼쪽 위 로고 설정 방법 | 값 확인 | 로고 이미지를 파일로 바꿨는지, 브랜딩 설정(`local/conf/brand.conf.php`)을 썼는지 | 로고 |

`diff` 는 5버전 서버에서 이렇게 만든다. (원본이 `파일명.날짜` 로 남아 있다)

```bash
ls -la include/menu.inc.php*                                 # 가장 오래된 백업을 찾는다
diff include/menu.inc.php.<가장오래된날짜> autoever/src/menu.inc.php
```

## 5순위: 로그인 (OTP, mypass)

자세한 배경은 `docs/LOGIN_CUSTOMIZATION.md` 1장에 있다.

| # | 볼 것 | 종류 | 확인하려는 것 |
|---|---|---|---|
| 5-1 | 5버전 로그인 화면 | 화면 | 입력창 구성 (OTP 입력창이 같은 화면에 있는지) |
| 5-2 | `index.php` 의 변경 부분 | `diff` 결과 | OTP/mypass 검증을 언제, 어떻게 부르는지 |
| 5-3 | `include/views/general.login.php` 의 변경 부분 | `diff` 결과 | 추가된 입력창, 스크립트 |
| 5-4 | `grep -rn -i "otp\|mypass" --include=*.php .` 결과 | 소스 | 관련 코드가 있는 파일 전체 목록 |
| 5-5 | 5-4 에서 나온 파일 중 검증 함수가 있는 파일 | 소스 | 어느 서버로 무엇을 보내는지 (주소와 비밀값은 옮겨 적지 않는다) |
| 5-6 | `docs/LOGIN_CUSTOMIZATION.md` 1.4 의 질문 7개 | 값 확인 | 담당자에게 물어볼 내용 |

## 환경 정보

| # | 항목 | 확인 명령 | 왜 필요한가 |
|---|---|---|---|
| E-1 | 7버전 서버의 PHP 버전 | `php -v` | 8.2 이상이면 동적 프로퍼티 경고까지 확인해야 한다 |
| E-2 | 7버전 DB 종류와 버전 | `psql --version` 또는 `mysql --version` | 5버전 쿼리에 PostgreSQL 전용 문법(`ilike`)이 있다 |
| E-3 | 7버전 DB 의 Zabbix 스키마 이름 | `psql` 에서 `\dn` | 5버전 쿼리는 `zabbix.items` 처럼 스키마를 고정해 썼다 |
| E-4 | Zabbix 정확한 버전 | 화면 맨 아래 또는 `zabbix_server -V` | 이 저장소는 7.2.15 기준이다 |
| E-5 | 프론트엔드 설치 경로 | `ls /usr/share/zabbix/modules` | 모듈을 올릴 위치 |
| E-6 | 유저들이 쓰는 화면 언어 | 유저 설정 > 언어 | 한국어 번역 표(`lang/ko.php`)가 적용되는지 |
| E-7 | 5버전 주소를 즐겨찾기나 다른 시스템 링크로 쓰는지 | 담당자 확인 | 쓰고 있다면 5버전 액션 이름(`uptime.view` 등)으로도 열리게 맞춰야 한다 |
