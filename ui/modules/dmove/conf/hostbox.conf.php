<?php declare(strict_types = 0);
/*
 * Box 사용현황 화면의 설정.
 *
 * 읽는 곳: actions/reports/hostbox/HostBoxList.php
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ TODO(고객환경) ★ 고객 서버에 올린 뒤 item_keys 를 채운다 ★              │
 * │                                                                         │
 * │ 이 저장소는 공개 저장소라서 실제 아이템 키는 비워 두었다.               │
 * │ 비어 있으면 화면에 "설정이 필요합니다" 안내가 나오고 목록은 비어 있다.  │
 * │                                                                         │
 * │ 넣을 값: 5버전 키 클래스(...Key.php)의 cpuCoreTotal(), cpuCoreUsage(),  │
 * │          memoryTotal(), memoryUsage(), diskTotal(), diskUsage() 가      │
 * │          돌려주는 키 (각 1개)                                           │
 * │                                                                         │
 * │ 주의: 5버전 소스의 키 문자열에는 셸 기호(| " $ ' 등) 자리에 밑줄(_)이   │
 * │       들어가 있는 것으로 보였다. 소스의 문자열을 그대로 옮기지 말고     │
 * │       7버전 서버에서 실제 키를 확인해서 넣는다.                         │
 * │       확인 방법: 데이터 수집 > 호스트 > (ESXi 정보를 수집하는 호스트)   │
 * │                  > 아이템 에서 각 아이템의 "키"                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

return [
	/*
	 * 열별 아이템 키. 각 호스트에서 이 키를 가진 아이템의 "마지막 값"을 표시한다.
	 * total_core 키를 가진 호스트가 목록에 나온다. (= Box 로 취급할 호스트)
	 *
	 *   total_core   : CPU 전체 Core
	 *   use_core     : CPU 할당 Core
	 *   total_memory : 전체 메모리 (값은 바이트)
	 *   use_memory   : 할당 메모리 (값은 바이트)
	 *   total_disk   : 전체 Disk  (값은 바이트. 5버전 화면에는 없던 열이라 비워 두면 표시하지 않는다)
	 *   use_disk     : 할당 Disk  (값은 바이트)
	 */
	'item_keys' => [
		'total_core' => '',
		'use_core' => '',
		'total_memory' => '',
		'use_memory' => '',
		'total_disk' => '',
		'use_disk' => ''
	]
];
