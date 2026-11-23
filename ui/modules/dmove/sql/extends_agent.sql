-- SW 인벤토리의 Agent "미설치 / 예외" 상태를 저장하는 표.
--
-- 5버전 DB 를 7버전으로 올렸다면 이 표는 이미 있다. 그때는 실행하지 않는다.
-- 표가 없을 때만 Zabbix DB 에서 한 번 실행한다. (PostgreSQL)
--   psql -U <DB계정> <DB이름> -f extends_agent.sql
--
-- 열 구성은 5버전 코드의 표 정의와 같다. 마지막 줄의 색인은 7버전에서 더한 것이다(조회 속도용).
-- 표 이름이나 스키마를 바꾸면 conf/swinventory.conf.php 도 같이 바꾼다.

CREATE TABLE IF NOT EXISTS extends_agent (
	hostid varchar(20) NOT NULL,
	agent int4 NOT NULL,
	explanation varchar(2048) NOT NULL DEFAULT '',
	clock int4 NOT NULL,
	userid int8 NOT NULL,
	username varchar(255) NOT NULL DEFAULT '',
	useralias varchar(255)
);

CREATE INDEX IF NOT EXISTS extends_agent_1 ON extends_agent (hostid, clock);
