# 장기 미접속 계정 현황 (`userunconnect.list`)

## 1. 한눈에

| 항목 | 내용 |
|---|---|
| 메뉴 위치 | 유저 > 장기 미접속 계정 현황 (코어 "유저 > 유저" 항목 바로 뒤. `Module.php` 의 `USER_MENUS`). 5버전은 "관리 > 유저" 아래였다 |
| 주소 | `zabbix.php?action=userunconnect.list` |
| 상태 | **완성** (2026-11-23 구현). 미접속 기준은 5버전과 같다. 방금 만든 계정을 빼는 판단만 5버전과 다르게 정했다 (7장) |
| 5버전 액션 | `user.unconnect` (컨트롤러 `CControllerUserunconnect.php`, 뷰 `administration.user.unconnect.php`) |
| 내보내기 | 없음 (5버전에도 없었다) |
| 권한 | 코어 "유저 > 유저" 화면을 볼 수 있는 사용자 역할 (`CRoleHelper::UI_ADMINISTRATION_USERS`, 기본 설정에서는 Super admin). 5버전은 Super admin 만 |

## 2. 이 화면은 무엇을 하나

Zabbix 계정 중 오랫동안(기본 60일) 로그인하지 않은 계정을 찾는 화면이다.
보안 담당자나 Zabbix 관리자가 퇴사자, 부서 이동자, 쓰지 않는 공용 계정을 찾아 비활성화하거나 지우는 근거로 쓴다(정기 계정 점검).
로그인 이름, 이름, 유저 종류, 유저 그룹으로 범위를 좁히고, 표의 "최근접속일시"를 보고 판단한다.

### 어떤 계정이 나오는가 (5버전 쿼리와 같은 조건)

아래 세 조건을 **모두** 만족하는 계정이다.

1. 최근 60일 안에 접속한 기록이 없다. (`sessions` 표의 `lastaccess`) 한 번도 접속하지 않은 계정도 여기에 들어간다.
2. 계정을 만든 지 60일이 넘었다. (`auditlog` 표의 "유저 추가" 기록) 방금 만든 계정이 미접속으로 잡히지 않게 하는 조건이다.
3. 기본 관리자 계정(userid 1)이 아니다.

## 3. 화면 구성

### 필터

필터는 좌우 2단이다.

| 이름 (한국어 화면) | 입력 방식 | 기본값 | 저장 여부 |
|---|---|---|---|
| 로그인 이름 (`_('Username')`) | 텍스트. 부분 일치 | 빈 값 | 저장 (`web.dmove.userunconnect.filter.username`) |
| 이름 | 텍스트. 부분 일치 | 빈 값 | 저장 (`...filter.name`) |
| 성 | 텍스트. 부분 일치 | 빈 값 | 저장 (`...filter.surname`) |
| 유저 종류 | 버튼 모양 라디오 4개: 전부 / Zabbix User / Zabbix Admin / Zabbix Super Admin (`user_type2str()` 로 번역) | 전부(-1) | 저장 (`...filter.user_type`) |
| 유저 그룹 | 드롭다운. "모두"(0) + 전체 유저 그룹 이름순 | 모두(0) | 저장 (`...filter.usrgrpid`) |

기준 일수 입력은 화면에 없다(5버전 화면에도 없었다). 설정 파일에서 바꾼다 (6장).

### 표의 열

| 열 | 값의 출처 | 표시 방식 |
|---|---|---|
| 로그인 이름 | `users.username` (5버전은 `users.alias`) | 줄바꿈 안 함. 제목을 누르면 정렬 |
| 이름 | `users.name` | 제목을 누르면 정렬 |
| 성 | `users.surname` | 제목을 누르면 정렬 |
| 유저 종류 | 유저에 연결된 역할(role)의 `type` | `user_type2str()` 로 이름 표시. 제목을 누르면 정렬 |
| 그룹 | 유저 그룹 이름들 | 이름순으로 `, ` 로 이어서. 긴 글은 줄바꿈 |
| 최근접속일시 | `sessions` 표의 `MAX(lastaccess)` | 빨간색. 접속 기록이 없으면 `-`. 줄바꿈 안 함 |

표 위에 빨간 글씨로 기준이 나온다: `※ 60일 이상 접속하지 않은 계정입니다.` 표 아래에 페이지 이동이 있다.

### 텍스트 스케치

```
장기 미접속 계정 현황
┌ 필터 ──────────────────────────────────────────────────────────────────┐
│ 로그인 이름 [        ]     유저 종류 [전부|Zabbix User|Zabbix Admin|Zabbix Super Admin] │
│ 이름        [        ]     유저 그룹 [모두            v]               │
│ 성          [        ]                                                 │
│ [적용] [리셋]                                                          │
└────────────────────────────────────────────────────────────────────────┘
                                      ※ 60일 이상 접속하지 않은 계정입니다.
(노란 경고, 감사 로그 보관 기간이 60일보다 짧을 때만)
로그인 이름▲ | 이름 | 성 | 유저 종류 | 그룹            | 최근접속일시
user-a       | 길동 | 홍 | 유저      | 그룹A, 그룹B    | -
user-c       | 영희 | 김 | 유저      | 그룹A           | 2026/08/25 21:10:48
```

## 4. 어떻게 만들었나 (동작 순서)

1. **CSRF 검사 끄기** - `UserUnconnectList::init()` 에서 `disableCsrfValidation()`.
2. **입력 검증** - `UserUnconnectList::checkInput()`
   - `sort` 는 `username`, `name`, `surname`, `user_type` 중 하나. `sortorder` 는 `ASC` / `DESC`.
   - 유저 종류는 -1(전부), 1, 2, 3 만 받는다. `USER_TYPE_ANY = -1` 은 이 화면에서 만든 상수다.
   - 유저 그룹은 숫자 ID 하나. 0 은 "모두".
3. **권한** - `UserUnconnectList::checkPermissions()`: `checkAccess(CRoleHelper::UI_ADMINISTRATION_USERS)`.
4. **설정 읽기** - `loadConfig()`. `conf/userunconnect.conf.php` 를 읽고, 파일이 없거나 값이 빠져 있으면 5버전 기본값(60일, 만든 날짜 확인 켬, userid 1 제외)을 쓴다.
5. **필터 저장/초기화/읽기** - `doAction()`. 텍스트는 `PROFILE_TYPE_STR`, 유저 종류는 `PROFILE_TYPE_INT`, 그룹 ID 는 `PROFILE_TYPE_ID`. [리셋]은 `CProfile::deleteIdx()`.
6. **조건에 맞는 유저 ID 찾기** - `findInactiveUserIds()`
   `user.get` API 는 "마지막 접속 시각" 조건을 받지 않아서 DB 를 직접 읽는다. 쿼리에 들어가는 값은 모두 코드에서 만든 정수다.
   ```sql
   SELECT u.userid FROM users u
   WHERE NOT EXISTS (SELECT NULL FROM sessions s WHERE s.userid=u.userid AND s.lastaccess > <기준 시각>)
     AND u.userid NOT IN (1)
     AND u.username IN (SELECT a.resourcename FROM auditlog a
                        WHERE a.action=0 AND a.resourcetype=0 AND a.clock < <기준 시각>)
   ```
   - 기준 시각 = `time() - 60 * 86400`.
   - `action=0` 은 감사 로그의 "추가"(`CAudit::ACTION_ADD`), `resourcetype=0` 은 "유저"(`CAudit::RESOURCE_USER`), `resourcename` 은 로그인 이름이다.
   - 마지막 줄은 설정 `created_check` 에 따라 바뀐다. `'exclude_recent'`(기본): `AND u.username NOT IN (... AND a.clock >= 기준)`. 최근에 만든 기록이 있는 계정만 빠지고 기록이 없는 계정은 나온다. `'required'`(5버전 방식): `AND u.username IN (... AND a.clock < 기준)`. `'none'`: 이 줄이 빠진다.
7. **행 만들기** - `getRows()`
   - `API::User()->get()` 에 위에서 찾은 `userids`, 필터의 `search`(빈 값은 `null` 로 줘서 조건에서 뺀다), `usrgrpids`, `selectUsrgrps`, `selectRole` 을 준다.
   - 유저 종류 필터는 결과에서 `$user['role']['type']` 으로 거른다 (API 가 역할의 type 으로는 걸러 주지 않는다).
   - 마지막 접속 시각은 `SELECT s.userid, MAX(s.lastaccess) ... GROUP BY s.userid` 로 한 번에 읽는다. 코어 유저 목록 화면과 같은 방식이다.
   - 날짜는 `zbx_date2str(DATE_TIME_FORMAT_SECONDS, ...)` 로 사용자 시간대에 맞춰 표시한다.
8. **정렬 + 페이징** - `CArrayHelper::sort()` 후 `CPagerHelper::paginate(..., ZBX_SORT_UP, ...)`.
9. **감사 로그 보관 기간 확인** - `isAuditRetentionShorterThan()`. 하우스키핑의 감사 로그 보관 기간이 기준 일수보다 짧으면 뷰가 노란 경고를 띄운다 (7장).
10. **화면** - `views/users/userunconnect.list.php`. `make_sorting_header()` 로 정렬 링크, `CRadioButtonList(...)->setModern(true)` 로 버튼 모양 라디오, `CSelect` 로 드롭다운.

## 5. 5버전과 비교

| 주제 | 5버전 | 7버전 | 왜 |
|---|---|---|---|
| 메뉴 | 관리 > 유저 아래 | 유저 > 장기 미접속 계정 현황 | 7버전은 유저 관련 메뉴가 1단계 메뉴 "유저"로 분리됨 |
| 대상 조건 | 60일 안에 접속한 세션이 없음 + 감사 로그에 60일보다 전의 유저 추가 기록이 있음 + userid 1 제외 | 같다 | 그대로 옮김 |
| 기준 값 | 쿼리에 `60 day` 와 `userid != 1` 이 직접 적혀 있었다 | `conf/userunconnect.conf.php` | 정책이 바뀌어도 소스를 고치지 않게 |
| 유저 그룹 선택 | 제목 오른쪽(필터 밖)의 드롭다운 | 필터 안의 드롭다운 | 필터 값과 함께 [적용]/[리셋]으로 다루고 프로파일에 저장하기 위해 |
| 로그인 이름 | `users.alias` | `users.username` | 7버전 DB 구조 변경 |
| 유저 종류 | `users.type` | 유저 역할의 `type` (`user.get` 의 `selectRole`) | 7버전은 users 표에 type 이 없다 |
| 표 정렬 | 로그인 이름, 이름, 성, 유저 종류 | 같다 | 그대로 옮김 |
| 최근접속일시 | 온라인이면 초록, 아니면 빨강. 기록이 없으면 빨간 빈칸 | 빨강. 기록이 없으면 `-` | 이 목록의 계정은 모두 미접속이라 초록이 나올 수 없다 |
| 유저 수 제한 | "검색/필터 결과 제한" + 1 개까지만 읽어 뒤쪽이 잘릴 수 있었다 | 조건에 맞는 계정을 모두 읽는다 | 누락 방지 |
| 페이징 | 정렬 방향을 페이징 함수에 넘겼다 (내림차순 + 제한 초과 시 앞쪽이 빠지는 원인) | 항상 `ZBX_SORT_UP` | 같은 문제를 옮기지 않음 |
| 권한 | Super admin 만 | "유저 > 유저" 화면 권한 | 기본 설정에서는 같다. 역할로 조정할 수 있다 |
| 쓰이지 않던 계산 | 뷰가 "차단됨" 링크와 GUI 접근 색을 계산했지만 표에 넣지 않았다 | 옮기지 않음 | 실제로 쓰이던 동작에 맞춤 |

## 6. 설정

파일: `ui/modules/dmove/conf/userunconnect.conf.php` (읽는 곳: `UserUnconnectList::loadConfig()`)

| 값 | 뜻 | 기본값 (5버전) |
|---|---|---|
| `inactive_days` | 며칠 동안 접속하지 않으면 장기 미접속으로 보는가 | `60` |
| `created_check` | 방금 만든 계정을 빼는 방법. `'exclude_recent'` = 최근에 만든 기록(감사 로그)이 있는 계정만 제외, 기록이 없으면 표시 (**지금 값**, 2026-11-23 결정). `'required'` = 기준일보다 전에 만든 기록이 있는 계정만 표시 (5버전 방식). `'none'` = 만든 날짜를 따지지 않음 | `'exclude_recent'` |
| `exclude_userids` | 목록에서 항상 빼는 유저 ID | `[1]` |

고객 값이 아니라 정책 숫자라서 저장소에 기본값이 들어 있다. 파일이 없어도 같은 기본값으로 동작한다.

## 7. 비어 있는 부분과 가정

| 자리 | 내용 | 할 일 |
|---|---|---|
| **만든 날짜 판단을 5버전과 다르게 함** (2026-11-23 결정) | 5버전은 감사 로그에 60일보다 전의 유저 추가 기록이 있는 계정만 보여 줬다. 기록이 없으면 빠진다. 감사 로그는 보관 기간이 지나면 지워지고, 5버전 DB 를 7버전으로 올릴 때(바로 올려도) 예전 기록이 넘어오지 않는다. 그대로면 예전부터 있던 계정이 목록에서 모두 빠진다. 그래서 "최근 60일 안에 만든 기록이 있는 계정만 뺀다"로 바꿨다. 기록이 없는 계정은 오래된 계정으로 보고 보여 준다 | 검증: 고객 DB 에서 설정 파일 주석의 확인 쿼리 3개를 돌린다 (`CUSTOMER_ENV_CHECKLIST.md` 1-8b) | 5버전과 똑같은 목록이 필요하면 `created_check` 를 `'required'` 로 |
| **감사 로그 보관 기간** | "만든 지 60일 넘은 계정"을 감사 로그의 유저 추가 기록으로 판단한다. 그런데 7.0 의 감사 로그 기본 보관 기간은 31일이다. 60일보다 짧으면 그 기록이 이미 지워져서 **목록이 항상 빈다**. 이때 화면에 노란 경고가 나온다 | 고객 서버에서 관리 > 하우스키핑 > 감사의 보관 기간을 확인한다. 아래 둘 중 하나를 고른다 |
| ↳ 선택 1 | 감사 로그 보관 기간을 `inactive_days` 보다 길게 늘린다 | 5버전과 같은 결과. 감사 로그 용량이 늘어난다 |
| ↳ 선택 2 | `created_check` 를 `'exclude_recent'`(권장) 또는 `'none'` 으로 바꾼다 | 만든 날짜를 따지지 않는다. 방금 만들고 아직 접속하지 않은 계정도 목록에 나온다 |
| 이관 직후 | 5버전의 감사 로그와 세션 기록이 7버전으로 옮겨지지 않았다면, 이관 후 60일 동안은 목록이 비거나 모든 계정이 "기록 없음"으로 보일 수 있다 | 데이터 이관 범위 확인 |
| 세션 기록 보관 | 세션 기록도 하우스키핑으로 지워진다. 지워진 뒤에는 "기록 없음"(`-`)으로 보이지만 미접속 판정 자체는 같다 | - |
| 가정: 권한 | "유저 > 유저" 화면 권한 | `src/menu.inc.php` |

## 8. 개선점

### 이미 반영한 개선

- 5버전 조건 그대로 조회 구현 (2026-11-23).
- 기준 일수와 제외 계정을 설정 파일로 분리.
- 감사 로그 보관 기간이 짧을 때 이유를 알려 주는 경고.
- 유저 수 제한 제거, 페이징 정렬 인자 수정.
- 7버전 구조(`username`, 역할의 `type`)에 맞춘 필터와 표, 유저 그룹 선택을 필터 안으로.

### 남은 개선 제안

| 제안 | 효과 | 5버전과 결과가 달라지나 (고객 합의) |
|---|---|---|
| 기준 일수를 필터 입력으로도 받기 | 점검 기준을 바꿔 가며 볼 수 있다 | 기본값을 설정값으로 두면 같다 |
| 관리자용 [선택 계정 비활성화] 버튼 | 점검과 조치를 한 화면에서 끝낸다 | 새 기능. 저장하는 액션이므로 CSRF 검사를 켜고 Super admin 만 허용해야 한다. 합의 필요 |
| 내보내기(CSV/Excel) | 정기 계정 점검 증적 자료 | 기능 추가 |
| 최근접속일시 정렬 | 오래된 순서로 바로 본다 | 표시만 바뀜 |
| 만든 날짜를 감사 로그 대신 다른 기준으로 | 감사 로그 보관 기간에 묶이지 않는다. 다만 7.0 users 표에는 만든 시각 열이 없다 | 방법부터 검토 필요 |

## 9. 확인 방법

### 테스트 환경 (http://127.0.0.1:18970, `Admin` / `zabbix`, `dev/compose.yaml`)

2026-11-23 에 아래 순서로 확인했다.

1. 일반 사용자 역할의 시험 계정 4개(a, b, c, d)를 만든다. 계정을 만들면 감사 로그에 유저 추가 기록이 생긴다.
2. DB 에서 시각을 바꿔 상황을 만든다.

   | 계정 | 감사 로그의 추가 시각 | 세션의 마지막 접속 | 기대 |
   |---|---|---|---|
   | a | 100일 전 | 없음 | **표시**, 최근접속일시 `-` |
   | b | 100일 전 | 10일 전 | 안 나옴 (최근에 접속) |
   | c | 100일 전 | 90일 전 | **표시**, 최근접속일시에 날짜 |
   | d | 10일 전 | 없음 | 안 나옴 (만든 지 60일이 안 됨) |

   ```sql
   UPDATE auditlog SET clock = extract(epoch from now())::int - 100*86400
   WHERE resourcetype=0 AND action=0 AND resourcename IN ('<a>','<b>','<c>');
   INSERT INTO sessions (sessionid, userid, lastaccess, status) VALUES ('<임의 글자>', <c 의 userid>, extract(epoch from now())::int - 90*86400, 0);
   ```
3. `Admin` 으로 유저 > 장기 미접속 계정 현황을 연다.
   - 결과: a 와 c 두 줄만 나왔다. 기준 문구 `※ 60일 이상 접속하지 않은 계정입니다.` 가 보였다.
   - 테스트 환경의 감사 로그 보관 기간이 기본값 31일이라 노란 경고도 함께 보였다.
4. 로그인 이름 제목을 눌러 내림차순으로 바꾸면 c → a 순서가 된다. 유저 종류 "Zabbix User" 로 걸러도 두 줄이 그대로 나온다.
5. 권한 확인: 일반 사용자 계정으로 로그인해 주소를 직접 열면 접근 거부 화면이 나와야 한다.

끝나면 시험 계정을 지운다 (세션 행은 계정과 함께 지워진다).

### 고객환경에서 5버전과 비교

1. 먼저 감사 로그 보관 기간을 확인한다 (7장). 경고가 떠 있으면 비교할 수 없다.
2. 같은 조건(유저 종류 전부, 그룹 모두)으로 5버전 `user.unconnect` 와 7버전 화면의 건수를 비교한다.
3. 최근접속일시가 같은 계정 몇 개를 골라 값이 같은지 본다. 이관하면서 세션 기록이 옮겨지지 않았다면 다를 수 있다.

## 10. 파일 목록

### 이 화면의 파일

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/actions/users/userunconnect/UserUnconnectList.php` | 컨트롤러. 입력 검증, 권한, 필터, `loadConfig()`, `findInactiveUserIds()`, `getRows()`, `isAuditRetentionShorterThan()` |
| `ui/modules/dmove/views/users/userunconnect.list.php` | 화면 뷰. 필터, 기준 문구, 경고, 정렬 링크가 있는 표 |
| `ui/modules/dmove/conf/userunconnect.conf.php` | 설정 (기준 일수, 만든 날짜 확인 여부, 제외 계정) |
| `ui/modules/dmove/manifest.json` | `userunconnect.list` 액션 등록 |
| `ui/modules/dmove/Module.php` | `USER_MENUS` 의 `'Long-term inactive accounts'` (코어 "유저" 항목 뒤에 삽입) |

### 같이 쓰는 부품

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/includes/Lang.php`, `ui/modules/dmove/lang/ko.php` | 다국어 문구 ("유저 종류", "최근접속일시", 기준 문구, 경고) |
| (참고, 코어) `ui/app/controllers/CControllerUserList.php` | 마지막 접속 시각을 `sessions` 에서 읽는 방법의 본보기 |
| (참고, 코어) `ui/include/classes/api/CAudit.php` | 감사 로그의 동작/대상 상수 |
