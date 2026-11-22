# 장기 미접속 계정 현황 (`userunconnect.list`)

## 1. 한눈에

| 항목 | 내용 |
|---|---|
| 메뉴 위치 | 유저 > 장기 미접속 계정 현황 (코어 "유저 > 유저" 항목 바로 뒤. `Module.php` 의 `USER_MENUS`). 5버전은 "관리 > 유저" 아래였다 |
| 주소 | `zabbix.php?action=userunconnect.list` |
| 상태 | **껍데기**. 필터와 표 제목만 있고 데이터 조회(`getRows()`)가 비어 있다. 화면에 "데이터 조회가 아직 구현되지 않았습니다." 안내가 뜬다 |
| 5버전 액션 | `user.unconnect` |
| 내보내기 | 없음 |
| 권한 | 코어 "유저 > 유저" 화면을 볼 수 있는 사용자 역할 (`CRoleHelper::UI_ADMINISTRATION_USERS`, 기본적으로 Super admin). 5버전보다 좁게 잡았다 |

## 2. 이 화면은 무엇을 하나

Zabbix 계정 중 오랫동안 로그인하지 않은 계정을 찾는 화면이다.
보안 담당자나 Zabbix 관리자가 퇴사자, 부서 이동자, 쓰지 않는 공용 계정을 찾아 비활성화하거나 지우는 근거로 쓴다(정기 계정 점검).
로그인 이름, 이름, 유저 종류, 유저 그룹으로 범위를 좁히고, 표의 "최근접속일시"를 보고 판단한다.

## 3. 화면 구성

### 필터

필터는 좌우 2단이다.

| 이름 (한국어 화면) | 입력 방식 | 기본값 | 저장 여부 |
|---|---|---|---|
| 로그인 이름 (`_('Username')`) | 텍스트 | 빈 값 | 저장 (`web.dmove.userunconnect.filter.username`) |
| 이름 | 텍스트 | 빈 값 | 저장 (`...filter.name`) |
| 성 | 텍스트 | 빈 값 | 저장 (`...filter.surname`) |
| 유저 종류 | 버튼 모양 라디오 4개: 전부 / Zabbix User / Zabbix Admin / Zabbix Super Admin (`user_type2str()` 로 번역) | 전부(-1) | 저장 (`...filter.user_type`) |
| 유저 그룹 | 드롭다운. "모두"(0) + 전체 유저 그룹 이름순 | 모두(0) | 저장 (`...filter.usrgrpid`) |

지금은 저장만 되고 결과에는 아무 영향이 없다(데이터가 없으므로). "며칠 이상 미접속" 같은 기준 일수 입력은 없다(5버전 화면에도 없었다).

### 표의 열

| 열 | 값의 출처 (구현 시) | 표시 방식 |
|---|---|---|
| 로그인 이름 | `users.username` (5버전은 `users.alias`) | 줄바꿈 안 함 |
| 이름 | `users.name` | 글자 그대로 |
| 성 | `users.surname` | 글자 그대로 |
| 유저 종류 | 유저에 연결된 역할(role)의 `type` | `user_type2str()` 로 이름 표시 |
| 그룹 | 유저 그룹 이름들 | `, ` 로 이어서. 긴 글은 줄바꿈 |
| 최근접속일시 | 세션 기록의 마지막 접속 시각 (`sessions.lastaccess` 로 추정) | 접속 기록이 없으면 빈칸. 줄바꿈 안 함 |

정렬 링크는 없다(제목이 일반 글자). 표 아래에 페이지 이동이 있다.

### 텍스트 스케치

```
장기 미접속 계정 현황
┌ 필터 ──────────────────────────────────────────────────────────────────┐
│ 로그인 이름 [        ]     유저 종류 [전부|Zabbix User|Zabbix Admin|Zabbix Super Admin] │
│ 이름        [        ]     유저 그룹 [모두            v]               │
│ 성          [        ]                                                 │
│ [적용] [리셋]                                                          │
└────────────────────────────────────────────────────────────────────────┘
(노란 안내) 데이터 조회가 아직 구현되지 않았습니다.
로그인 이름 | 이름 | 성 | 유저 종류 | 그룹 | 최근접속일시
(데이터 없음)
```

## 4. 어떻게 만들었나 (동작 순서)

1. **CSRF 검사 끄기** - `UserUnconnectList::init()` 에서 `disableCsrfValidation()`.
2. **입력 검증** - `UserUnconnectList::checkInput()`
   ```php
   'filter_user_type' => 'in '.implode(',', [self::USER_TYPE_ANY, USER_TYPE_ZABBIX_USER,
       USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN]),
   'filter_usrgrpid' => 'id'
   ```
   - 유저 종류는 -1(전부), 1, 2, 3 만 받는다. `USER_TYPE_ANY = -1` 은 이 화면에서 만든 상수다.
   - 유저 그룹은 숫자 ID 하나. 0 은 "모두".
3. **권한** - `UserUnconnectList::checkPermissions()`: `checkAccess(CRoleHelper::UI_ADMINISTRATION_USERS)`. 다른 사람의 계정 정보를 보여 주는 화면이라 관리자 화면 권한으로 묶었다.
4. **필터 저장/초기화** - `doAction()` 1번 블록. 텍스트는 `PROFILE_TYPE_STR`, 유저 종류는 `PROFILE_TYPE_INT`, 그룹 ID 는 `PROFILE_TYPE_ID` 로 `CProfile::update()`. [리셋]은 `CProfile::deleteIdx()`.
5. **필터 읽기** - `CProfile::get()`.
6. **데이터 조회** - `UserUnconnectList::getRows()`
   **지금은 `return [];` 만 있다.** 주석에 행 형식과 구현 순서 예시(`user.get` + `sessions` 집계)가 있다.
7. **그룹 드롭다운 목록** - `API::UserGroup()->get(['output' => ['usrgrpid','name'], 'preservekeys' => true])` 를 `array_column()` 으로 `[usrgrpid => 이름]` 으로 바꾸고 `natcasesort()` 로 이름순 정렬(키 유지).
8. **뷰로 넘기기** - `'data_ready' => false`, `CPagerHelper::paginate()`.
9. **화면** - `views/users/userunconnect.list.php`. `CRadioButtonList(...)->setModern(true)` 로 버튼 모양 라디오, `CSelect` 로 드롭다운. `data_ready` 가 `false` 면 노란 안내 상자.

## 5. 5버전과 비교

| 주제 | 5버전 | 7버전 | 왜 |
|---|---|---|---|
| 메뉴 | 관리 > 유저 아래 | 유저 > 장기 미접속 계정 현황 | 7버전은 유저 관련 메뉴가 1단계 메뉴 "유저"로 분리됨 |
| 유저 그룹 선택 | 제목 오른쪽(필터 밖)의 드롭다운 | 필터 안의 드롭다운 | 필터 값과 함께 [적용]/[리셋]으로 다루고 프로파일에 저장하기 위해 |
| 로그인 이름 | `users.alias` | `users.username` | 7버전 DB 구조 변경 |
| 유저 종류 | `users.type` | 유저 역할의 `type` (`user.get` 의 `selectRole`) | 7버전은 users 표에 type 이 없다 |
| 표 정렬 | 로그인 이름 오름차순 표시가 있었다 | 정렬 링크 없음 | 구현 시 추가 (8장) |
| 최근접속일시 형식 | `YYYY/MM/DD HH:MM:SS` | 주석의 예시는 `2026-01-02 03:04:05` | 구현할 때 5버전 형식으로 맞출지 정한다 |
| 표시 대상 | 접속 기록이 없는(빈칸) 계정도 표에 나왔다. 기준 일수로 거르는지는 확인 필요 | 미구현 | 5버전 컨트롤러 확인 필요 |
| 권한 | 확인 필요 | "유저 > 유저" 화면 권한 | 계정 정보 노출 범위를 좁힘 |

## 6. 설정

없음. (구현할 때 "장기"의 기준 일수를 설정 파일이나 필터 입력으로 둘지 정한다)

## 7. 비어 있는 부분과 가정

| 자리 | 내용 | 확인할 5버전 파일 |
|---|---|---|
| `TODO(고객환경)` `UserUnconnectList::getRows()` | 데이터 조회 전체 | `user.unconnect` 액션의 컨트롤러와 뷰 |
| "장기"의 기준 일수 | 며칠 이상 접속하지 않은 계정인가. 고정 값인지, 기준 없이 전체를 보여 주고 사람이 판단하는지 | 위와 같다 |
| 최근접속일시의 출처 | `sessions.lastaccess` 로 추정 (테이블인지 함수인지) | 위와 같다 |
| 접속 기록이 없는 계정 | 포함하는가. 5버전 화면에서는 빈칸 행이 보였다 | 위와 같다 |
| 비활성화된 계정 | 포함하는가 | 위와 같다 |
| `TODO(고객환경)` `data_ready` | 구현하면 `true` | - |

`UNIMPLEMENTED.md` 1장, `CUSTOMER_ENV_CHECKLIST.md` 3-2 와 같은 내용이다.

## 8. 개선점

### 이미 반영한 개선

- 7버전 구조(`username`, 역할의 `type`)에 맞춘 필터와 표.
- 관리자 화면 권한으로 좁힘.
- 유저 그룹 선택을 필터 안으로 옮겨 다른 필터와 함께 저장.
- 입력 검증(유저 종류 값 목록, 그룹 ID).

### 남은 개선 제안

| 제안 | 효과 | 5버전과 결과가 달라지나 (고객 합의) |
|---|---|---|
| 기준 일수를 필터 입력 또는 설정으로(예: 기본 90일) | "장기"의 뜻이 화면에 드러나고 점검 기준을 바꿔 가며 볼 수 있다 | 5버전이 고정 기준이었다면 기본값을 그 값으로 두면 같다. 합의 필요 |
| 관리자용 [선택 계정 비활성화] 버튼 (체크박스 + `user.update` 로 비활성 그룹에 넣기 또는 상태 변경) | 점검과 조치를 한 화면에서 끝낸다 | 새 기능. 저장하는 액션이므로 CSRF 검사를 켜고 Super admin 만 허용해야 한다. 합의 필요 |
| 내보내기(CSV/Excel) | 정기 계정 점검 증적 자료 | 기능 추가 |
| 열 정렬(특히 최근접속일시) | 오래된 순서로 바로 본다 | 표시만 바뀜 |
| "접속 기록 없음"을 빈칸 대신 글자로 표시 | 빈칸이 오류인지 기록 없음인지 구분 | 표시가 달라짐. 가볍게 합의 |
| 세션 기록 보존 여부 확인 후 보완 | 세션 행이 지워지면 오래된 계정이 "기록 없음"으로 보일 수 있다 (7버전 세션 정리 동작은 확인 필요) | 결과 정확도 문제. 확인 후 결정 |

### 7.0 데이터로 새로 만든다면 (설계 초안, **확정 아님**)

5버전 소스를 볼 수 없을 때 쓰는 초안이다. 코어 "유저 > 유저" 목록 화면이 마지막 접속 시각을 읽는 방법(`ui/app/controllers/CControllerUserList.php`)을 그대로 따른다. 기준 일수와 "기록 없음" 처리는 고객과 정한 뒤 확정한다.

**쓸 데이터**

| 데이터 | 7.0 에서 |
|---|---|
| 계정, 이름, 성 | `user.get` 의 `username`, `name`, `surname` |
| 유저 종류 | `user.get` 의 `selectRole => ['type']` |
| 그룹 | `user.get` 의 `selectUsrgrps => ['name']` |
| 마지막 접속 | `sessions` 표의 `MAX(lastaccess)` (코어 유저 목록 화면과 같은 방식. API 에는 이 값이 없다) |
| 계정 상태(선택) | 유저 그룹의 `users_status`(비활성 그룹 여부) |

**단계**

1. 유저: `API::User()->get(['output' => ['userid','username','name','surname'], 'search' => [필터 3개], 'usrgrpids' => 그룹이 0 이 아니면 [그룹], 'selectUsrgrps' => ['name'], 'selectRole' => ['type'], 'preservekeys' => true])`. `search` 의 빈 값 항목은 조건에서 빠진다(코어 동작).
2. 유저 종류 필터: 결과에서 `$user['role']['type'] == 필터값` 만 남긴다(-1 이면 건너뜀).
3. 마지막 접속: `DBselect('SELECT s.userid,MAX(s.lastaccess) AS lastaccess FROM sessions s WHERE '.dbConditionId('s.userid', array_keys($users)).' GROUP BY s.userid')`. 조건은 `dbConditionId()` 로 만들어 요청값을 SQL 에 직접 붙이지 않는다.
4. 장기 미접속 판정 (예: 기준 90일, 오늘 = 2026-11-20 00:00)
   - 기준 시각 = `time() - 90 * 86400` = 2026-08-22 00:00 근처.
   - 마지막 접속이 2026-01-09 인 계정 -> 기준보다 이르므로 **표시**.
   - 마지막 접속이 2026-11-01 인 계정 -> **빠짐**.
   - 접속 기록이 없는 계정 -> 기본은 표시하고 최근접속일시는 빈칸(5버전 화면과 같은 모양). 합의 필요.
5. 행 만들기: 그룹 이름은 `implode(', ', array_column($user['usrgrps'], 'name'))`, 날짜는 `date('Y/m/d H:i:s', $lastaccess)`(5버전 형식).
6. 정렬(기본 로그인 이름), `CPagerHelper::paginate()`, `'data_ready' => true`.
7. (선택) 내보내기 `userunconnect.export`, 비활성화 버튼(별도 액션, CSRF 검사 켬).

## 9. 확인 방법

### 테스트 환경 (http://127.0.0.1:18970, `Admin` / `zabbix`, `dev/compose.yaml`)

1. `Admin` 으로 로그인해 유저 > 장기 미접속 계정 현황을 연다.
   - 기대: 사이드바의 유저 메뉴에서 "유저" 바로 아래에 이 메뉴가 있다. 필터, 표 제목 6개, 노란 안내가 보이고 빨간 PHP 경고가 없다.
2. 유저 그룹 드롭다운을 연다.
   - 기대: "모두" 다음에 기본 그룹들이 이름순으로 나온다.
3. 유저 종류 "Zabbix Admin", 로그인 이름 `adm` 을 넣고 [적용] -> 다른 화면에 갔다 돌아온다.
   - 기대: 값이 유지된다. [리셋] 후 "전부"와 빈 칸으로 돌아온다.
4. 권한 확인: 유저 > 유저에서 "User role" 이 일반 사용자인 계정을 만들어 로그인한 뒤 주소를 직접 연다.
   - 기대: 접근 거부 화면.
5. 구현 후: 시험 계정을 하나 만들고 한 번도 로그인하지 않은 상태에서 화면을 본다(기록 없음 처리 확인). 그 계정으로 로그인한 뒤 다시 보면 최근접속일시가 생겨야 하고, 코어 "유저 > 유저" 목록의 로그인 정보와 맞아야 한다.

### 고객환경에서 5버전과 비교 (구현 후)

1. 같은 조건(유저 종류 전부, 그룹 모두)으로 5버전 `user.unconnect` 와 7버전 화면의 건수("N 중 N건")를 비교한다.
2. 최근접속일시가 같은 계정 몇 개를 골라 값이 같은지 본다. 7버전으로 이관하면서 세션 기록이 옮겨지지 않았다면 모든 계정이 "기록 없음"일 수 있다. 이것은 데이터 이관 문제이므로 따로 확인한다.
3. 5버전에서 빠진 계정이 7버전에 나오면 5버전 기준 일수나 비활성 계정 처리 규칙이 있는 것이므로 컨트롤러를 확인해 맞춘다.

## 10. 파일 목록

### 이 화면의 파일

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/actions/users/userunconnect/UserUnconnectList.php` | 컨트롤러. 입력 검증, 권한, 필터 저장/읽기, 유저 그룹 목록. `getRows()` 는 비어 있음(구현 예시 주석 있음) |
| `ui/modules/dmove/views/users/userunconnect.list.php` | 화면 뷰. 필터(텍스트 3개, 라디오, 드롭다운), 표, "구현 안 됨" 안내 |
| `ui/modules/dmove/manifest.json` | `userunconnect.list` 액션 등록 |
| `ui/modules/dmove/Module.php` | `USER_MENUS` 의 `'Long-term inactive accounts'` (코어 "유저" 항목 뒤에 삽입) |

### 같이 쓰는 부품

| 파일 | 역할 |
|---|---|
| `ui/modules/dmove/includes/Lang.php`, `ui/modules/dmove/lang/ko.php` | 다국어 문구 ("유저 종류", "최근접속일시") |
| (참고, 코어) `ui/app/controllers/CControllerUserList.php` | 마지막 접속 시각을 `sessions` 에서 읽는 방법의 본보기 |
| (구현 시) `ui/modules/dmove/includes/export/` | 내보내기 |
