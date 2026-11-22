# 미구현 영역과 필요한 5버전 파일

7버전 모듈에서 아직 비어 있거나 가정으로 만든 곳, 그리고 그것을 채우려면 5버전 서버에서 어떤 파일을 봐야 하는지 정리했다.
코드에서 `TODO(고객환경)` 를 검색하면 아래 항목의 자리가 모두 나온다.

- 설정값만 넣으면 되는 것(`conf/` 파일 5개)은 `CUSTOMER_ENV_CHECKLIST.md` 1장에 있다.
- 화면별 진행 상태 표는 `DEVELOPMENT_GUIDE.md` 5.1 에 있다.
- 문서의 `autoever/` 는 5버전 서버의 커스텀 폴더 이름이다. (`DEVELOPMENT_GUIDE.md` 맨 위 참고)

## 한눈에 보기

| 구분 | 화면 / 기능 |
|---|---|
| 완성 (설정값만 넣으면 됨) | SW 인벤토리, 저사용 서버, 고사용 서버, 일일점검, Peak 이벤트 분석, 윈도우즈 패치 현황, Box 사용현황 |
| 껍데기 (필터와 표만, 데이터 없음) | 서버 시간 확인, 장기 미접속 계정 현황, CPU/Memory 전체 사용률 추출 |
| 빈 페이지 | 성능 데이터 추세 분석 |
| 없음 | 일일점검 Top 10 버튼, [기준설정] 버튼, 윈도우 전체, ESXi, 사이드바 하단 링크, 커스텀 CSS, 로그인 OTP / mypass |

---

## 1. 껍데기 화면 (1순위)

메뉴와 화면은 뜨지만 데이터 조회부가 비어 있다. 화면에 "준비 중" 안내가 나온다.
컨트롤러의 `getRows()` 를 채우고 `'data_ready' => false` 를 `true` 로 바꾸면 안내가 사라진다.

| 화면 | 7버전 파일 | 필요한 5버전 파일 | 그 안에서 볼 것 |
|---|---|---|---|
| 서버 시간 확인 | `actions/reports/uptime/UptimeList.php` | `controllers/CControllerUptimeView.php`, 그 뷰, 컨트롤러가 쓰는 트레잇 | 서버 Time / 서버 start 를 읽는 아이템 키, "N day 이상" 비교 방식, OS 와 Description 의 출처 |
| 장기 미접속 계정 현황 | `actions/users/userunconnect/UserUnconnectList.php` | `user.unconnect` 액션의 컨트롤러와 뷰 | "장기"의 기준 일수, 최근 접속일을 읽는 곳(테이블 또는 함수), 접속 기록이 없는 계정의 처리 |
| CPU/Memory 전체 사용률 추출 | `actions/reports/usageexport/UsageExportList.php` | `perf.analysis.view` 액션의 컨트롤러와 뷰 | 기간을 정하는 방법, CSV 열 구성, 파일이 여러 개로 나뉘는 기준 |

## 2. 방향을 먼저 정할 것

| 화면 | 7버전 파일 | 정할 것 |
|---|---|---|
| 성능 데이터 추세 분석 | `actions/reports/perftrend/PerfTrendList.php` | 대시보드 "그래프" 위젯으로 대체할지(권장), 독립 화면으로 만들지. 독립 화면이면 `data.analysis` 컨트롤러와 CSV 구성이 필요하다 |
| 일일점검 [CPU / Memory 사용률 Top 10] | `views/reports/dailycheck.list.php` | 대시보드 "Top hosts" 위젯으로 대체할지, 팝업으로 만들지. 팝업이면 `popup.topcpu`, `popup.topmemory` 컨트롤러가 필요하다 |
| 저사용/고사용 [기준설정] | 지금은 `conf/serverusage.conf.php` 로 대체 | 화면에서 바꾸게 할지. 바꾼다면 사용자별 저장인지 전체 공통인지. 5버전 `popup.lowserver` 컨트롤러를 본다 |
| 로그인 OTP / mypass | `docs/LOGIN_CUSTOMIZATION.md` | 7.0 기본 다단계 인증(TOTP, Duo)으로 대체할지(권장) |

## 3. 가정으로 만든 것 (2순위)

동작은 하지만 5버전과 결과가 같은지 확인해야 한다.

| 화면 | 가정한 내용 | 필요한 5버전 파일 | 고칠 곳 |
|---|---|---|---|
| 고사용 서버 | 평균 50% 이상 **그리고** Peak 70% 이상 | `controllers/CControllerHighserver.php` | `actions/reports/highserver/HighServerList.php` 의 `matches()` |
| 저사용 서버 | 평균 기준 30% | 저사용 컨트롤러(`CControllerLowserver.php`)의 기본값 | `conf/serverusage.conf.php` |
| 저사용/고사용 | 업무시간 Peak = 업무일 하루 전체의 최대 (5버전과 같게 둠) | 확인 끝. 고칠지 합의만 필요 | `conf/serverusage.conf.php` 의 `business_peak_whole_day` |
| Peak 이벤트 분석 | 기간 안 장애 이벤트 수를 트리거별로 센 상위 100개 | `peak_event.php` | `actions/reports/peakevent/PeakEventList.php` 의 `getRows()` |
| SW 인벤토리 | Agent "예외" = ITSM 행의 `agent_exception` 값 | Agent 판정 트레잇(`...HostAgent`) | `includes/SwInventoryService.php` |
| SW 인벤토리 | 소프트웨어 판별 = 인벤토리 필드만 | 같은 트레잇, 또는 SW 인벤토리 컨트롤러가 쓰는 트레잇 | `includes/SoftwareResolver.php` |
| 윈도우즈 패치 현황 | HotFix ID = 그 달 패치 목록에 글자가 들어 있는 호스트, 수집월 기본값 = 지난달 | 윈도우 패치 컨트롤러 | `includes/WinPatchService.php`, `actions/reports/winpatch/WinPatchList.php` |
| Box 사용현황 | 메뉴는 보고서 맨 끝, 필터는 호스트 그룹/호스트 | `src/menu.inc.php`, `hostbox` 컨트롤러 | `Module.php`, `actions/reports/hostbox/HostBoxList.php` |
| 내보내기 | CSV 는 UTF-8 + BOM, 쉼표 구분 | 5버전 공통 CSV 처리 부분 (EUC-KR 변환 등이 있었는지) | `includes/export/CsvWriter.php` |

## 4. 아예 없는 것 (3순위)

| 기능 | 필요한 5버전 파일 | 고칠 곳 |
|---|---|---|
| 사이드바 아래 "Agent 설치파일", "사용자메뉴얼" 링크 | `src/menu.inc.php` 의 원본과의 차이 | `Module.php` |
| 메뉴별 접근 권한 | 같은 파일 | `Module.php`, 각 컨트롤러의 `checkPermissions()` |
| 커스텀 CSS (로고, 색) | `src/blue-theme.css` 의 원본과의 차이 | `assets/css/dmove.css`, 또는 7.0 브랜딩 설정 |
| 윈도우 전체 (`windowsall`, `popup.windows.all`) | 해당 컨트롤러. 먼저 필요한지 확인 | 새 화면 |
| ESXi (`hostesxi`) | 해당 컨트롤러와 뷰 | 새 화면 |
| 그 밖에 남은 액션 | `routing.php` 전체 | `manifest.json` |

---

## 5. 5버전 서버에서 파일 찾기

액션 이름에서 시작해 컨트롤러 → 트레잇 순서로 따라간다. 실제 계산은 트레잇 안에 있는 경우가 많으므로 **컨트롤러가 `use` 하는 트레잇 파일도 같이** 본다.

```bash
cd /usr/share/zabbix/autoever

# 1) routing.php 에서 액션 -> 컨트롤러 클래스, 뷰 이름
grep -n "uptime\|unconnect\|perf.analysis\|highserver\|lowserver\|topcpu\|topmemory\|windowsall\|hostesxi" routing.php

# 2) 컨트롤러 파일
ls controllers/ | grep -i "uptime\|unconnect\|perf\|highserver\|lowserver"

# 3) 컨트롤러가 쓰는 트레잇 (use 로 시작하는 줄)
grep -n "^\s*use " controllers/CControllerUptimeView.php

# 4) 코어 파일을 고친 부분만 보기 (원본이 "파일명.날짜" 로 남아 있다)
ls -la /usr/share/zabbix/include/menu.inc.php*
diff /usr/share/zabbix/include/menu.inc.php.<가장오래된날짜> src/menu.inc.php

# 5) 로그인 관련 코드 위치
grep -rn -i "otp\|mypass" --include=*.php /usr/share/zabbix
```

## 6. 파일을 가져올 수 없을 때

1장의 화면 3개는 아래만 알아도 7.0 기본 데이터로 새로 설계할 수 있다.

- 5버전 화면의 **열 이름**과 **값 예시 한두 줄** (서버 이름, IP 는 가린다)
- **필터 항목**
- 화면을 쓰는 사람이 무엇을 판단하는 데 쓰는지

7.0 에서 쓸 수 있는 데이터:

| 화면 | 7.0 데이터 |
|---|---|
| 서버 시간 확인 | 에이전트 기본 아이템 `system.uptime`(가동 시간), `system.localtime`(서버 시각) |
| 장기 미접속 계정 | 사용자 세션 기록(마지막 접속 시각), `user.get` API |
| CPU/Memory 추출 | `trends` 표 (저사용/고사용 서버와 같은 집계 방식) |

## 7. 우선순위

1. 1장의 컨트롤러 3개와 그 컨트롤러가 쓰는 트레잇. 이것만 있으면 껍데기 화면이 모두 채워진다.
2. `CControllerHighserver.php`, `peak_event.php`. 보고서 숫자에 직접 영향을 준다.
3. `src/menu.inc.php` 의 차이. 메뉴 위치, 권한, 링크가 한 번에 확정된다.
4. 2장의 방향 결정. 파일보다 결정이 먼저 필요하다.
