<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 현황 화면의 설정.
 *
 * 읽는 곳: includes/WinPatchService.php
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ TODO(고객환경) ★ 고객 서버에 올린 뒤 item_key 를 채운다 ★               │
 * │                                                                         │
 * │ 이 저장소는 공개 저장소라서 실제 아이템 키는 비워 두었다.               │
 * │ 비어 있으면 화면에 "설정이 필요합니다" 안내가 나오고 목록은 비어 있다.  │
 * │                                                                         │
 * │ 넣을 값: 5버전 키 클래스(...Key.php)의 patchFile() 이 돌려주는 키       │
 * │ 7버전 서버에서 확인: 데이터 수집 > 호스트 > (윈도우 서버 하나)          │
 * │          > 아이템 에서 패치 목록 파일을 읽는 아이템의 "키"              │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

return [
	/*
	 * 설치된 패치 목록 파일을 읽어 오는 아이템의 키.  예) 'vfs.file.contents[파일경로,인코딩]'
	 *
	 * 이 아이템의 값은 윈도우의 설치된 업데이트 목록(Caption, CSName, Description, FixComments, HotFixID,
	 * InstallDate, InstalledBy, InstalledOn, Name, ServicePackInEffect 열을 가진 표 형태의 글)이다.
	 * 이 키를 가진 호스트가 화면에 나온다.
	 *
	 * PHP 의 작은따옴표 문자열 안에서 역슬래시(\)는 그대로 쓰면 된다. (단, 맨 끝 글자가 \ 이면 \\ 로 적는다)
	 */
	'item_key' => '',

	/** 상세 표의 열 이름. 값 파일의 첫 줄(제목 줄)에 이 이름들이 이 순서로 있어야 한다. */
	'columns' => ['Caption', 'CSName', 'Description', 'FixComments', 'HotFixID', 'InstallDate', 'InstalledBy',
		'InstalledOn', 'Name', 'ServicePackInEffect'
	]
];
