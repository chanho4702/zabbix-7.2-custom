<?php declare(strict_types = 0);
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


/**
 * The Zabbix autoloader class.
 */
class CAutoloader {

	/**
	 * Registered namespace array. Key is namespace name with trailing '\' and value is array of directories
	 * relative path with trailing '/'.
	 *
	 * @var array
	 */
	protected $namespaces = [];

	/**
	 * Register supported namespace.
	 *
	 * @param string $namespace  Namespace value without trailing '\'.
	 * @param array  $paths      Array of namespace files directory absolute path without trailing '/'.
	 */
	public function addNamespace(string $namespace, array $paths): void {
		foreach ($paths as $path) {
			$path = realpath($path);

			if ($path) {
				$this->namespaces[$namespace][] = $path.'/';
			}
		}
	}

	/**
	 * Add "loadClass" method as an autoload handler.
	 *
	 * @return bool
	 */
	public function register(): bool {
		return spl_autoload_register([$this, 'loadClass']);
	}

	/**
	 * Attempts to find and load the given class.
	 *
	 * @param string $class_name
	 *
	 * @return bool
	 */
	// [DM 해설] 클래스 이름을 파일 경로로 바꿔 읽어 들인다.
	// [DM 해설] 예) Modules\DMove\Includes\SwInventoryService -> modules/dmove/includes/SwInventoryService.php
	protected function loadClass(string $class_name): bool {
		$chunks = explode('\\', $class_name);
		$file_name = array_pop($chunks).'.php';

		do {
			$namespace = implode('\\', $chunks);

			if (array_key_exists($namespace, $this->namespaces)) {
				foreach ($this->namespaces[$namespace] as $dir) {
					if (is_file($dir.$file_name)) {
						require $dir.$file_name;

						return true;
					}
				}
			}

			if ($chunks) {
				// [DM 해설] 네임스페이스의 중간 부분은 소문자 폴더명으로 바뀐다(Includes -> includes). 파일명(클래스명)은 대소문자를 그대로 쓴다.
				// [DM 해설] 리눅스는 대소문자를 구분하므로 폴더는 소문자, 파일명은 클래스명과 똑같이 지어야 한다.
				$file_name = strtolower(array_pop($chunks)).'/'.$file_name;
			}
		} while ($chunks);

		return false;
	}
}
