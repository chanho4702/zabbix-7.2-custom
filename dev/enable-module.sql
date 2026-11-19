-- 로컬 테스트용: dmove 모듈을 등록하고 켠다. 여러 번 실행해도 된다.
-- (화면에서 하려면: 관리 > 일반 > 모듈 > [디렉토리 스캔] 후 DMove Custom 을 활성화)
INSERT INTO module (moduleid, id, relative_path, status, config)
SELECT COALESCE(MAX(moduleid), 0) + 1, 'dmove', 'modules/dmove', 1, '[]'
FROM module
HAVING NOT EXISTS (SELECT 1 FROM module WHERE id = 'dmove');

UPDATE module SET status = 1 WHERE id = 'dmove';

-- Admin 계정의 화면 언어. 영어로 보려면 'en_US' 로 바꿔 실행한다.
UPDATE users SET lang = 'ko_KR' WHERE username = 'Admin';
