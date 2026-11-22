<?php
/*
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


/**
 * @var CView $this
 */

// [DM 해설] CSV 다운로드용 레이아웃. 라우팅(또는 모듈 manifest.json)에서 layout 을 'layout.csv' 로 지정하면 쓰인다.
// [DM 해설] 파일명은 컨트롤러의 $response->setFileName() 값이다.
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$data['page']['file'].'"');

// [DM 해설] main_block = 뷰 파일이 echo 한 내용 전체. CSV 뷰는 HTML 없이 CSV 문자열만 출력해야 한다.
echo $data['main_block'];
