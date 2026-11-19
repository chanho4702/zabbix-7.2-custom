<?php
/*
** Copyright (C) 2001-2025 Zabbix SIA
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


// [DM 해설] 모든 MVC 화면의 진입점. 주소는 zabbix.php?action=<액션명> 형식이다.
// [DM 해설] 이 파일은 아래 한 줄이 전부이고, 실제 처리는 include/config.inc.php -> ZBase::run() 에서 한다.
// [DM 해설] 모듈 화면도 같은 진입점을 쓴다. 예) zabbix.php?action=swinventory.list
require_once dirname(__FILE__).'/include/config.inc.php';
