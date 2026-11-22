# 로컬에서 띄워 보기

Docker Desktop 이 실행 중이어야 한다. 저장소의 `dev` 폴더에서 실행한다.

## 처음 한 번

```bash
docker compose up -d
```

DB 가 처음 만들어지는 데 1~2분 걸린다. 아래 주소에서 로그인 화면이 나오면 준비된 것이다.

- 주소: http://127.0.0.1:18970
- 계정: `Admin` / `zabbix` (Zabbix 기본 계정. 로컬 테스트 전용)

모듈을 켠다. (한 번만 하면 된다)

```bash
docker compose exec -T db psql -U zabbix zabbix < enable-module.sql
```

## 화면 주소

| 화면 | 주소 |
|---|---|
| SW 인벤토리 | http://127.0.0.1:18970/zabbix.php?action=swinventory.list |
| 서버 시간 확인 | http://127.0.0.1:18970/zabbix.php?action=uptime.list |
| 장기 미접속 계정 현황 | http://127.0.0.1:18970/zabbix.php?action=userunconnect.list |

나머지 보고서 화면은 사이드바의 보고서 메뉴 아래에 있다.

## 수정하고 확인하기

`ui/modules/dmove` 아래 파일을 고치고 저장한 뒤 브라우저를 새로고침하면 된다. 컨테이너를 다시 띄울 필요는 없다.

PHP 오류로 화면이 하얗게 나오면 로그를 본다.

```bash
docker compose logs web --tail 30
```

## 영어/한국어 전환

오른쪽 아래 유저 설정 > 프로필 > 언어에서 바꾼다. 또는:

```bash
docker compose exec -T db psql -U zabbix zabbix -c "UPDATE users SET lang='en_US' WHERE username='Admin'"
```

## 끄기

```bash
docker compose stop        # 멈춤 (데이터 유지)
docker compose down        # 삭제 (데이터도 지워진다. 다시 up 하면 처음부터)
```
