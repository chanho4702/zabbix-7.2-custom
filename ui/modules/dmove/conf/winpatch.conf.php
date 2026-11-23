<?php declare(strict_types = 0);
/*
 * 윈도우즈 패치 현황 화면의 설정.
 *
 * 읽는 곳: includes/WinPatchService.php
 *         (화면: zabbix.php?action=winpatch.list   메뉴: 보고서 > 윈도우즈 패치 현황, 그 화면의 상세보기 팝업)
 *
 * ── 이 파일의 형식 ──
 *   실행되는 코드는 없다. "return [ ... ];" 로 배열 하나를 돌려주기만 한다.
 *   WinPatchService 가 만들어질 때마다 require 로 읽으므로, 값을 고치면 다음 화면 조회부터 반영된다.
 *
 * ── 값이 쓰이는 흐름 ──
 *   item_key   목록 화면: 이 키의 아이템을 가진 호스트를 찾아 목록으로 보여 준다.
 *              상세보기: 그 아이템이 수집한 값(긴 글)을 읽는다.
 *   columns    상세보기: 그 글의 제목 줄에서 이 이름들의 위치를 찾아 글을 열로 자른다. 팝업 표의 제목 줄로도 쓴다.
 *
 * ── 값의 출처 (2026-11-23 채움) ──
 *   item_key : 5버전 키 클래스(...Key.php)의 patchFile() 이 돌려주던 키
 *
 *   7버전 서버에서 확인: 데이터 수집 > 호스트 > (윈도우 서버 하나) > 아이템 에서 패치 목록 파일을 읽는 아이템의 "키"
 *   키가 다르면 목록이 비어 나온다. 값을 비우면 화면에 "설정이 필요합니다" 안내가 나온다.
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
	// 5버전 키 클래스 patchFile() 의 windows 값.
	'item_key' => 'vfs.file.contents[C:\Program Files\zabbix\script\Result\Win_Update_All.txt,ANSI]',

	/** 상세 표의 열 이름. 값 파일의 첫 줄(제목 줄)에 이 이름들이 이 순서로 있어야 한다. */
	'columns' => ['Caption', 'CSName', 'Description', 'FixComments', 'HotFixID', 'InstallDate', 'InstalledBy',
		'InstalledOn', 'Name', 'ServicePackInEffect'
	]
];
