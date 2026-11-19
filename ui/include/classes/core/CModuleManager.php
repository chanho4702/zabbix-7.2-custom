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


use CController as CAction;

use Zabbix\Core\{
	CModule,
	CWidget
};

/**
 * Module manager class for testing and loading user modules.
 */
final class CModuleManager {

	/**
	 * Lowest supported manifest version.
	 */
	private const MIN_MANIFEST_VERSION = 2;

	/**
	 * Highest supported manifest version.
	 */
	private const MAX_MANIFEST_VERSION = 2;

	/**
	 * Root path of modules.
	 */
	private string $root_path;

	/**
	 * Current action name.
	 */
	private string $action_name;

	/**
	 * Manifest data of added modules.
	 */
	private array $manifests = [];

	/**
	 * DB moduleids of added modules.
	 */
	private array $moduleids = [];

	/**
	 * List of instantiated, initialized modules.
	 */
	private array $modules = [];

	/**
	 * List of errors caused by module initialization.
	 */
	private array $errors = [];

	/**
	 * @param string $root_path  Root path of modules.
	 */
	public function __construct(string $root_path) {
		$this->root_path = $root_path;
	}

	/**
	 * Add module and prepare it's manifest data.
	 *
	 * @param string      $relative_path  Relative path to the module.
	 * @param string|null $moduleid       DB module ID.
	 * @param string|null $id             Stored module ID to optionally check the manifest module ID against.
	 * @param array|null  $config         Override configuration to use instead of one stored in the manifest file.
	 *
	 * @return array|null  Either manifest data or null if manifest file had errors or IDs didn't match.
	 */
	// [DM 해설] 모듈 1개를 등록한다. manifest.json 을 읽어 검사하고(loadManifest), 문제가 있으면 null 을 돌려준다.
	// [DM 해설] null 이면 화면에 "Cannot load module at: ..." 오류가 표시된다.
	public function addModule(string $relative_path, ?string $moduleid = null, ?string $id = null,
			?array $config = null): ?array {

		$manifest = $this->loadManifest($relative_path);

		// Ignore module without a valid manifest.
		if ($manifest === null) {
			return null;
		}

		// Ignore module with an unexpected id.
		if ($id !== null && $manifest['id'] !== $id) {
			return null;
		}

		// Use override configuration, if supplied.
		if (is_array($config)) {
			$manifest['config'] = $config;
		}

		$this->manifests[$relative_path] = $manifest;
		$this->moduleids[$relative_path] = $moduleid;

		return $manifest;
	}

	// [DM 해설] 등록된 모듈들의 Module.php 를 실행 가능한 객체로 만든다. 다른 모듈과 충돌하는 모듈은 제외된다.
	public function initModules(): void {
		[
			'conflicts' => $this->errors,
			'conflicting_manifests' => $conflicting_manifests
		] = $this->checkConflicts();

		$non_conflicting_manifests = array_diff_key($this->manifests, array_flip($conflicting_manifests));

		foreach ($non_conflicting_manifests as $relative_path => $manifest) {
			$base_classname = $manifest['type'] === CModule::TYPE_WIDGET ? CWidget::class : CModule::class;
			$classname = $manifest['type'] === CModule::TYPE_WIDGET ? 'Widget' : 'Module';

			$module_class = $base_classname;

			try {
				// [DM 해설] Module.php 가 있으면 <네임스페이스>\Module 클래스를 쓴다. 없으면 기본 CModule 을 쓴다(메뉴 없이 액션만 등록되는 모듈).
				// [DM 해설] 클래스 이름이나 namespace 선언이 틀리면 바로 아래의 "Wrong Module.php class name" 오류가 난다.
				if (is_file($this->root_path.'/'.$relative_path.'/'.$classname.'.php')) {
					$module_class = implode('\\', [$manifest['namespace'], $classname]);

					if (!class_exists($module_class)) {
						$this->errors[] = _s('Wrong %1$s.php class name for module located at %2$s.', $classname,
							$relative_path
						);

						return;
					}
				}

				/** @var CModule $instance */
				$instance = new $module_class($manifest, $this->moduleids[$relative_path], $relative_path);

				if ($instance instanceof $base_classname) {
					// [DM 해설] Module::init() 호출 지점. 모듈이 사이드바 메뉴를 추가하는 코드가 여기서 실행된다.
					// [DM 해설] init() 안에서 예외가 나면 아래 catch 에서 잡혀 "... thrown by module located at ..." 메시지로 표시된다.
					$instance->init();

					$this->modules[$instance->getId()] = $instance;
				}
				else {
					$this->errors[] = _s('%1$s.php class must extend %2$s for module located at %3$s.',
						$classname, $base_classname, $relative_path
					);
				}
			}
			catch (Throwable $e) {
				$this->errors[] = _s('%1$s - thrown by module located at %2$s.', $e->getMessage(), $relative_path);
			}
		}
	}

	/**
	 * Get initialized modules.
	 */
	public function getModules(): array {
		return $this->modules;
	}

	public function setActionName(string $action_name): self {
		$this->action_name = $action_name;

		return $this;
	}

	/**
	 * Get loaded module instance associated with action.
	 *
	 * @return CModule|null
	 */
	public function getActionModule(): ?CModule {
		/** @var CModule $module */
		foreach ($this->modules as $module) {
			if (array_key_exists($this->action_name, $module->getActions())) {
				return $module;
			}
		}

		return null;
	}

	/**
	 * Get initialized widget modules.
	 */
	public function getWidgets(): array {
		$widgets = [];

		/** @var CWidget $widget */
		foreach ($this->modules as $widget) {
			if (!($widget instanceof CWidget)) {
				continue;
			}
			$widgets[$widget->getId()] = $widget;
		}

		return $widgets;
	}

	public function getWidgetsDefaults(): array {
		$widget_defaults = [];

		/** @var CWidget $widget */
		foreach (APP::ModuleManager()->getWidgets() as $widget) {
			$widget_defaults[$widget->getId()] = $widget->getDefaults();
		}

		return $widget_defaults;
	}

	public function getModule($module_id): ?CModule {
		if (!array_key_exists($module_id, $this->modules)) {
			return null;
		}

		return $this->modules[$module_id];
	}

	public function getManifests(): array {
		return $this->manifests;
	}

	/**
	 * Get namespaces of all added modules.
	 */
	public function getNamespaces(): array {
		$namespaces = [];

		foreach ($this->manifests as $relative_path => $manifest) {
			$namespaces[$manifest['namespace']] = [$this->root_path.'/'.$relative_path];
		}

		return $namespaces;
	}

	/**
	 * Get actions of all initialized modules.
	 */
	// [DM 해설] 모든 모듈의 manifest.json "actions" 를 라우터가 쓰는 형식으로 바꾼다.
	public function getActions(): array {
		$actions = [];

		/** @var CModule $module */
		foreach ($this->modules as $module) {
			foreach ($module->getActions() as $name => $data) {
				$actions[$name] = [
					// [DM 해설] 컨트롤러 클래스 이름 = <모듈 네임스페이스>\Actions\<manifest 의 class>.
					// [DM 해설] class 에 "Reports/Uptime/UptimeList" 처럼 / 가 있으면 \ 로 바뀐다 -> 파일 actions/reports/uptime/UptimeList.php, namespace Modules\DMove\Actions\Reports\Uptime;
					'class' => implode('\\', [$module->getNamespace(), 'Actions',
						str_replace('/', '\\', $data['class'])
					]),
					// [DM 해설] manifest 에 layout 을 생략하면 일반 화면(layout.htmlpage)이다. CSV 는 layout.csv, JSON 응답은 layout.json 을 적는다.
					'layout' => array_key_exists('layout', $data) ? $data['layout'] : 'layout.htmlpage',
					'view' => array_key_exists('view', $data) ? $data['view'] : null
				];
			}
		}

		return $actions;
	}

	// [DM 해설] 모듈 manifest.json 의 "assets" (css, js 파일 목록). include/page_header.php 가 이 목록으로 <link>, <script> 를 만든다.
	public function getAssets(): array {
		$assets = [];

		/** @var CModule $module */
		foreach ($this->modules as $module) {
			if ($module->getType() === CModule::TYPE_WIDGET && !CRouter::isDashboardAction($this->action_name)) {
				continue;
			}

			$assets[$module->getId()] = $module->getAssets();
		}

		return $assets;
	}

	/**
	 * Get errors encountered while module initialization.
	 */
	public function getErrors(): array {
		return $this->errors;
	}

	/**
	 * Check added modules for conflicts.
	 *
	 * @return array  Lists of conflicts and conflicting modules.
	 */
	// [DM 해설] 모듈끼리 id, 네임스페이스, 액션명이 겹치는지 검사한다. 겹치면 양쪽 모듈 모두 로드되지 않는다.
	// [DM 해설] 액션명에 'dmove.' 접두어를 붙이는 이유다.
	public function checkConflicts(): array {
		$ids = [];
		$namespaces = [];
		$actions = [];

		foreach ($this->manifests as $relative_path => $manifest) {
			$ids[$manifest['id']][] = $relative_path;
			$namespaces[$manifest['namespace']][] = $relative_path;
			foreach (array_keys($manifest['actions']) as $action_name) {
				$actions[$action_name][] = $relative_path;
			}
		}

		foreach (['ids', 'namespaces', 'actions'] as $var) {
			$$var = array_filter($$var, static function($list) {
				return count($list) > 1;
			});
		}

		$conflicts = [];
		$conflicting_manifests = [];

		foreach ($ids as $id => $relative_paths) {
			$conflicts[] = _s('Identical ID (%1$s) is used by modules located at %2$s.', $id,
				implode(', ', $relative_paths)
			);
			$conflicting_manifests = array_merge($conflicting_manifests, $relative_paths);
		}

		foreach ($namespaces as $namespace => $relative_paths) {
			$conflicts[] = _s('Identical namespace (%1$s) is used by modules located at %2$s.', $namespace,
				implode(', ', $relative_paths)
			);
			$conflicting_manifests = array_merge($conflicting_manifests, $relative_paths);
		}

		$relative_paths = array_unique(array_reduce($actions, static function($carry, $item) {
			return array_merge($carry, $item);
		}, []));

		if ($relative_paths) {
			$conflicts[] = _s('Identical actions are used by modules located at %1$s.', implode(', ', $relative_paths));
			$conflicting_manifests = array_merge($conflicting_manifests, $relative_paths);
		}

		$this->errors = $conflicts;

		return [
			'conflicts' => $conflicts,
			'conflicting_manifests' => array_unique($conflicting_manifests)
		];
	}

	/**
	 * Publish an event to all loaded modules. The module of the responsible action will be served last.
	 *
	 * @param CAction $action  Action responsible for the current request.
	 * @param string  $event   Event to publish.
	 */
	// [DM 해설] 모든 모듈의 onBeforeAction / onTerminate 를 차례로 호출한다.
	public function publishEvent(CAction $action, string $event): void {
		$action_module = $this->getActionModule();

		foreach ($this->modules as $module) {
			if ($module != $action_module) {
				$module->$event($action);
			}
		}

		if ($action_module) {
			$action_module->$event($action);
		}
	}

	/**
	 * Load and parse module manifest file.
	 *
	 * @param string $relative_path  Relative path to the module.
	 *
	 * @return array|null  Either manifest data or null if manifest file had errors.
	 */
	// [DM 해설] manifest.json 읽기와 검사. 하나라도 틀리면 null 을 돌려주고 모듈이 로드되지 않는다.
	// [DM 해설] manifest.json 은 JSON 이라 주석을 쓸 수 없고, 쉼표 하나만 틀려도 여기서 실패한다.
	private function loadManifest(string $relative_path): ?array {
		$relative_path_parts = explode('/', $relative_path, 2);

		if (count($relative_path_parts) != 2) {
			return null;
		}

		$manifest_file_name = $this->root_path.'/'.$relative_path.'/manifest.json';

		if (!is_file($manifest_file_name) || !is_readable($manifest_file_name)) {
			return null;
		}

		$manifest = file_get_contents($manifest_file_name);

		if ($manifest === false) {
			return null;
		}

		$manifest = json_decode($manifest, true);

		if (!is_array($manifest)) {
			return null;
		}

		// Check required keys in manifest.
		// [DM 해설] manifest.json 필수 키 5개. 하나라도 없으면 모듈을 읽지 않는다.
		if (array_diff_key(array_flip(['manifest_version', 'id', 'name', 'namespace', 'version']), $manifest)) {
			return null;
		}

		// Check manifest version.
		if (!is_numeric($manifest['manifest_version']) || $manifest['manifest_version'] < self::MIN_MANIFEST_VERSION
				|| $manifest['manifest_version'] > self::MAX_MANIFEST_VERSION) {
			return null;
		}

		if (trim($manifest['id']) === '' || trim($manifest['name']) === '') {
			return null;
		}

		// Check manifest namespace syntax.
		if (!preg_match('/^[0-9a-z_]+$/i', $manifest['namespace'])) {
			return null;
		}

		// [DM 해설] 최종 네임스페이스 = ucfirst('modules') + '\' + manifest 의 namespace  ->  Modules\DMove
		// [DM 해설] PHP 파일의 namespace 선언은 이 값으로 시작해야 한다.
		$manifest['namespace'] = ucfirst($relative_path_parts[0]).'\\'.$manifest['namespace'];

		// Check module type.
		if (array_key_exists('type', $manifest)
				&& !in_array($manifest['type'], [CModule::TYPE_MODULE, CModule::TYPE_WIDGET], true)) {
			return null;
		}

		// Ensure empty defaults.
		$manifest += [
			'type' => CModule::TYPE_MODULE,
			'author' => '',
			'url' => '',
			'description' => '',
			'actions' => [],
			'assets' => [],
			'config' => []
		];

		$manifest['assets'] += [
			'css' => [],
			'js' => []
		];

		if ($manifest['type'] === CModule::TYPE_WIDGET) {
			if (!array_key_exists('widget', $manifest)) {
				$manifest['widget'] = [];
			}

			$manifest['widget'] += [
				'name' => '',
				'form_class' => CWidget::DEFAULT_FORM_CLASS,
				'js_class' => CWidget::DEFAULT_JS_CLASS,
				'in' => [],
				'out' => [],
				'size' => CWidget::DEFAULT_SIZE,
				'refresh_rate' => CWidget::DEFAULT_REFRESH_RATE
			];
		}

		return $manifest;
	}
}
