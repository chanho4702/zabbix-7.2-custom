# 5버전 커스텀 소스 검토: PHP 8 / Zabbix 7.2 호환성

일부 파일만 검토했다. 확인한 파일은 `routing.php`, `swinventory.sql`, `patch.sh`(35~84행),
`CControllerSwinventories.php`(1~288행)이다. 뷰, `C*` 트레잇, `C*Middle`, `function.php`,
CSV 컨트롤러, 289행 이후는 보지 못했으므로 3장의 점검 명령으로 고객환경에서 직접 확인해야 한다.

Zabbix 7.2 는 PHP 8.0 이상이 필수다. Zabbix 는 PHP 경고를 화면 상단에 빨간 메시지로 그대로 보여 주므로,
"동작은 하지만 경고가 나는" 코드도 사용자 눈에 오류로 보인다.

## 1. 확인된 문제

심각도: **치명** = 화면이 안 뜸, **경고** = 화면 상단에 오류 메시지 표시, **버그** = PHP 버전과 무관한 로직 오류

### 1.1 Zabbix 7.2 에서 없어지거나 바뀐 것

| 심각도 | 위치 | 내용 | 조치 |
|---|---|---|---|
| 치명 | 컨트롤러 20행 `disableSIDValidation()` | 7버전에 없는 메소드. `Call to undefined method` | `disableCsrfValidation()` |
| 치명 | `patch.sh` 전체 | `include/menu.inc.php` 는 7버전에 파일 자체가 없다. `CMenu.php`, `jsLoader.php`, `blue-theme.css` 를 5버전 것으로 덮으면 프론트엔드 전체가 깨진다 | 사용 금지. 모듈로 대체 |
| 치명 | `routing.php` | 코어 `ZBase.php`/`CRouter.php` 를 고쳐 읽어 들이던 구조. 7버전 코어에는 그 수정이 없다 | `manifest.json` 의 `actions` |
| 치명 | 트레잇 `C*`, `C*Middle` 로딩 | 코어 오토로더가 `autoever/` 를 모른다 | 모듈 네임스페이스(`includes/`) |
| 경고 | 24~64행 `check_fields`, `T_ZBX_STR`, `O_OPT`, `P_SYS` | 7.2 에 아직 있지만 레거시 페이지 전용이다. MVC 컨트롤러에서는 `validateInput()` 을 써야 한다 | `checkInput()` 규칙 문자열 |
| 경고 | 85~215행 `hasRequest()`, `getRequest()` | 위와 같음 | `$this->hasInput()`, `$this->getInput()` |
| 경고 | 79행 `$page['file'] = 'swinventories.php'` | 레거시 페이지 전역변수. MVC 에서는 의미 없음 | 삭제 |
| 경고 | 68~71행 `checkPermissions()` 가 무조건 `true` | 로그인한 모든 사용자가 접근 | 역할 기반 `checkAccess()` |
| 경고 | `swinventory.sql` | `interface` 조인으로 인터페이스가 여러 개인 호스트는 행이 중복된다. 템플릿도 섞여 나온다. 스키마명 `zabbix.` 가 고정이다. 사용자 권한을 무시한다 | 뷰를 쓰지 않고 `API::Host()->get()` |

`getSubGroups()`, `CProfile::update/updateArray/deleteIdx`, `DBstart()/DBend()`, `CArrayHelper::renameObjectsKeys()`,
`API::Host()->get()`, `API::HostGroup()->get()` 은 7.2.15 에 그대로 있어 호출 자체는 문제없다.

### 1.2 PHP 8 문법/동작 문제

| 심각도 | 위치 | 내용 | 조치 |
|---|---|---|---|
| 경고 | 76, 139, 236, 240행 `$this->rules`, `$this->filter`, `$this->hostall`, `$this->rowdatas` | 클래스나 트레잇에 선언이 없으면 동적 프로퍼티다. PHP 8.2 부터 Deprecated 경고, PHP 9 에서 오류 | `private array $rules = [];` 처럼 선언 |
| 경고+버그 | 217~233행 `$host_name` | 매칭되는 Zabbix 호스트가 없으면 `$host_name` 이 정의되지 않는다. PHP 8 에서 Notice 가 Warning 으로 올라갔다. 게다가 이전 행에서 매칭된 값이 남아 있어 **다른 호스트의 표시명이 표시된다** | 반복문 안에서 `$host_name = '';` 초기화 |
| 경고 | 219행 `strtolower($row['ci_name'])` | ITSM 값이 `null` 이면 PHP 8.1 부터 Deprecated 경고 | `strtolower((string) $row['ci_name'])` |
| 경고 | 281~286행 `$filter['filter_agent_install'] == 1` 등 | 일부는 `isset` 없이 읽는다. 키가 없으면 PHP 8 에서 Warning | `!empty($filter['...'])` |
| 주의 | 필터 비교 전반 (`isFilter`, `filtering`) | PHP 8 은 문자열과 숫자의 느슨한 비교 결과가 바뀌었다. `0 == ''`, `0 == 'abc'` 가 5버전(PHP 7)에서는 참, PHP 8 에서는 거짓이다. 빈 필터를 `== 0` 으로 판단했다면 동작이 달라진다 | `=== ''` 로 명시 비교 |
| 주의 | 75행 `file_get_contents("./autoever/conf/rules/...")` | 상대경로. 실패하면 `false` 가 `json_decode` 로 넘어가 이후 `null` 접근 경고로 이어진다 | `__DIR__` 기준 경로 + 실패 처리 |
| 성능 | 217~224행 | ITSM 행마다 전체 호스트를 도는 이중 반복문 | 호스트명 색인 배열 |

검토한 범위 안에서 PHP 8 에서 **구문 오류(Parse error)** 가 나는 문장은 없었다.
`protected function doAction()` 에 반환 타입이 없는 것도 7.2 의 부모 클래스 선언과 호환된다.

## 2. 새 모듈에서 처리한 방식

위 항목은 `ui/modules/dmove/` 의 새 코드에서 모두 반영했다. 5버전 코드를 붙여 넣을 때는 같은 방식으로 고친다.

| 5버전 | 새 코드 |
|---|---|
| `getRequest('filter_os')` | `$this->getInput('filter_os', '')` |
| `$this->rowdatas = ...` (미선언) | 프로퍼티 선언 또는 지역변수/반환값 |
| `$row['key']` (없을 수 있음) | `$row['key'] ?? ''` |
| `strtolower($x)` | `mb_strtolower((string) $x)` |
| `if ($v == 0)` 로 빈 값 판단 | `if ($v === '')` |
| `"./autoever/conf/..."` | `dirname(__DIR__).'/conf/...'` |

## 3. 검토하지 못한 소스 점검 명령 (고객환경에서 실행)

5버전 `autoever/` 폴더에서 실행한다. 결과가 나오면 옆의 조치를 한다.

```bash
cd /usr/share/zabbix/dmove

# PHP 8 에서 삭제된 함수 → 치명 오류
grep -rnE '\b(each|create_function|money_format|ereg|eregi|split|mysql_[a-z_]+|mcrypt_[a-z_]+|get_magic_quotes_gpc|utf8_encode|utf8_decode)\s*\(' --include='*.php' .

# 문자열/배열 중괄호 접근 $a{0} → Parse error
grep -rnE '\$[A-Za-z_][A-Za-z0-9_]*(\[[^]]*\])*\{[^}]+\}' --include='*.php' .

# 중첩 삼항연산자 (괄호 없음) → Parse error 가능
grep -rnE '\?[^;:?]*:[^;?]*\?[^;]*:' --include='*.php' .

# 필수 인자 앞의 기본값 인자 function f($a = 1, $b) → Deprecated
grep -rnE 'function\s+\w+\s*\([^)]*=[^,)]*,\s*\$[A-Za-z_]+\s*[,)]' --include='*.php' .

# 동적 프로퍼티 후보: $this->xxx 에 대입하는 이름 목록 (클래스에 선언돼 있는지 대조)
grep -rhoE '\$this->[A-Za-z_]+\s*=[^=]' --include='*.php' . | sed -E 's/\s*=.*//' | sort -u

# 7버전에서 없어졌거나 모듈에서는 쓰지 않는 것
grep -rnE 'disableSIDValidation|menu\.inc|CLegacyAction|check_fields|getRequest\(|hasRequest\(' --include='*.php' .

# 요청값 직접 접근, 직접 SQL (보안 점검)
grep -rnE '\$_(GET|POST|REQUEST)\[|DBselect\(|DBexecute\(' --include='*.php' .

# 실제 문법 검사 (PHP 8 이 설치된 서버에서)
find . -name '*.php' -exec php -l {} \; | grep -v 'No syntax errors'
```

`implode($array, ',')` 처럼 인자 순서가 거꾸로인 호출, `count(null)`, `in_array()` 에 문자열/숫자를 섞어 넣는 비교도
PHP 8 에서 오류나 결과 변화가 나므로 눈으로 함께 확인한다.

## 추가 검토: 공통 트레잇과 데이터 클래스 (2차 검토)

2차로 확인한 파일은 `C*Itms`, `C*`(공통), `C*Filter`, `C*Inventoris` 트레잇과 `CDataItems` 클래스다.
이 파일들은 7버전으로 그대로 가져오지 않고 `includes/` 의 클래스로 다시 작성했다. 아래는 5버전 코드를 참고해 다른 화면을 옮길 때 주의할 점이다.

### PHP 8 에서 경고/오류가 나는 곳

| 등급 | 위치 | 문제 | PHP 8 동작 | 조치 |
|---|---|---|---|---|
| 높음 | `C*Inventoris` 의 `host_web($host, $params=null)` 등 전부 | 기본값이 `null` 인데 바로 `$params->displayField` 를 읽는다 | 인자 없이 호출되면 Warning `Attempt to read property on null` (PHP 7 은 Notice) | 인자를 필수로 하거나 `$params?->displayField` |
| 높음 | 공통 `isTable()` | `DBselect` 가 실패하면 `DBfetch` 가 `false` 인데 `$row['exists']` 를 읽는다 | Warning `Trying to access array offset on value of type bool` | 결과가 `false` 인지 먼저 확인 (`includes/ItsmRepository.php` 참고) |
| 높음 | `CDataItems::ids()`, `valueType()`, `get()` | `$this->items[$id]` 를 있는지 확인 없이 읽는다 | 아이템이 없는 호스트에서 Warning `Undefined array key` | `$this->items[$id] ?? []` |
| 높음 | `CDataItems::queryMakeIn()` | 아이템이 하나도 없으면 `"()"` 를 돌려준다 | `IN ()` 은 SQL 문법 오류 → 화면에 DB 오류 | 비었으면 조회를 건너뛴다. `dbConditionId()` 사용 |
| 중간 | 공통 `outputValues()` | 배열이 비어 있으면 `$str` 이 만들어지지 않은 채 `return $str` | Warning `Undefined variable $str` | 기본값을 먼저 준다 |
| 중간 | `C*Inventoris::DBExtend()` | `extract($params)` 로 변수를 만들고, `$items` / `$trends` 는 조건에 따라 만들어지지 않는다. `$trends` 를 채우는 부분은 주석 처리되어 있어 항상 "데이터 없음" 분기로 간다 | `isset()` 으로 감싸져 있어 경고는 없지만 동작이 의도와 다를 수 있다 | 실제로 쓰이는 코드인지 확인 후 옮긴다 |
| 중간 | `C*Filter::filtering()` | `strtolower($this->filter[$key])` 에 `null` 이 들어갈 수 있다 | PHP 8.1 부터 Deprecated | `(string)` 변환 또는 `?? ''` |
| 중간 | 트레잇들이 쓰는 `$this->rowdatas` | 트레잇에 선언이 없다. 컨트롤러에도 선언이 없으면 동적 프로퍼티 | PHP 8.2 부터 Deprecated | 프로퍼티 선언 |
| 낮음 | `C*Filter::stringInFilter()` 의 `strpos($name, $key)` | 찾는 글자가 빈 문자열일 때 | PHP 7 은 Warning + `false`, PHP 8 은 `0`(찾음). 결과가 달라진다 | 빈 필터는 미리 건너뛴다 |
| 낮음 | `strlen()`, `substr()` 로 한글 자르기 | 바이트 단위라 한글이 깨진다 (PHP 버전과 무관) | 글자 깨짐 | `mb_strlen()`, `mb_substr()`, `mb_strimwidth()` |

### 7버전에서 달라지는 곳 (PHP 문법과 무관)

| 위치 | 문제 | 조치 |
|---|---|---|
| `SELECT ... FROM zabbix.items`, `zabbix.host_inventory`, `zabbix.history_str` | 스키마 이름 `zabbix.` 가 고정이다. 7버전 DB 의 스키마가 다르면(기본은 `public`) 전부 실패한다 | 스키마 이름을 빼거나 API(`item.get`, `host.get`, `history.get`)로 바꾼다 |
| DB 직접 조회 전반 | 사용자 권한(호스트 그룹 권한)이 무시된다 | API 를 쓰면 권한이 자동 적용된다 |
| `"... where ci_name ilike '".$host['name']."'"` | 값을 따옴표로 직접 이어 붙인다. 호스트 이름에 `'` 가 있으면 쿼리가 깨지고 SQL 인젝션이 된다. `ilike` 는 PostgreSQL 전용이다 | `zbx_dbstr()` 로 감싼다. 대소문자 무시는 `LOWER(col)=LOWER(값)` |
| `itsm_description()` | 호스트마다 쿼리를 한 번씩 실행한다 (호스트 수만큼) | 한 번에 읽어 PHP 배열로 맞춘다 (`SwInventoryService::getRows()`) |
| `"key_='".$value."' or "` + `rtrim($str, "or ")` | `rtrim` 의 두 번째 인자는 "글자 목록"이다. 끝의 `o`, `r`, 공백을 모두 지우므로 키 값이 그 글자로 끝나면 잘린다 | `dbConditionString('key_', $keys)` |
| `ZBX_STYLE_CURSOR_POINTER`, `ZBX_STYLE_RED`, `CSpan::setHint()` | 7버전에도 있다. 그대로 쓸 수 있다 | - |
| `showTable()` 의 `echo "<td>...$value</td>"` | 값을 이스케이프 없이 출력한다 (XSS). 디버그용으로 보인다 | 옮기지 않는다 |
| `hasRequest('test_set')` | 화면 로직 안에서 요청값을 직접 읽는다 | 컨트롤러의 `checkInput()` 에 등록하고 `getInput()` 으로 읽는다 |

### 확인되어 반영한 내용

| 내용 | 5버전 근거 | 7버전 반영 위치 |
|---|---|---|
| ITSM 목록은 `<스키마>.itsm_info` 의 `id, ci_name, ip_address, description` | `C*Itms::itsm_hostList()` | `includes/ItsmRepository.php`, `conf/itsm.conf.php` |
| OS/WEB/WAS/DB/이중화 = 인벤토리 `os`, `software_app_a`, `_b`, `_c`, `_d` | `C*Inventoris::middle_inventory()` | `includes/SoftwareResolver.php` 의 `INVENTORY_FIELDS` |
| Agent 설치 = ITSM 과 Zabbix 호스트가 매칭됨(1), 미설치 = 매칭 안 됨(2), 예외 = 4 | `C*Itms::itsm_description()` 의 주석 | `includes/SwInventoryService.php` (예외 판정은 미확인) |
| 20자 넘는 값은 줄이고 전체 값은 말풍선으로 표시 | 공통 `outputValues()` | 미반영. 필요하면 뷰에서 `(new CSpan(mb_strimwidth($v, 0, 20, '...')))->setHint($v)` |
