<?php declare(strict_types = 0);
/*
 * Box 사용현황 화면 컨트롤러.
 *
 *   화면     : zabbix.php?action=hostbox.list
 *   내보내기 : zabbix.php?action=hostbox.export&format=csv 또는 xlsx
 *   메뉴     : 보고서 > Box 사용현황   (Module.php 의 REPORT_MENUS)
 *   5버전    : zabbix.php?action=hostbox  (규칙 파일 hostbox.json, 트레잇 ...HostBox.php)
 *
 * 무엇을 보여 주나
 *   ESXi 같은 가상화 장비(Box)별로 CPU 코어, 메모리, 디스크의 전체량과 할당량을 보여 준다.
 *   값은 장비 정보를 수집하는 아이템의 "마지막 값"이다. (아이템 키: conf/hostbox.conf.php)
 *
 * 5버전 화면 구성 (규칙 파일 기준)
 *   표 : 호스트(정렬), CPU 전체 Core, CPU 할당 Core, 전체 메모리, 할당 메모리, 할당 Disk, 최근 데이터
 *        최근 데이터 = "상세보기" 링크. 누르면 그 호스트의 최신 데이터 화면이 열린다.
 *
 * 5버전과 달라진 점
 *   - 5버전은 칸마다 아이템과 이력을 따로 조회했다. (호스트 수 x 6번) 여기서는 아이템을 한 번에 조회해
 *     마지막 값(lastvalue)을 쓴다.
 *   - 값이 없을 때의 표시를 '-' 로 통일했다. (5버전은 칸마다 "item없음", "no-value", "0.0Tb" 등으로 달랐다)
 *   - 메모리/디스크는 코어의 단위 변환으로 표시한다. (예: 1.5 TB)
 *   - 5버전 트레잇에 있던 "전체 Disk" 값은 화면에 없었다. 설정에 키를 넣으면 열이 추가된다.
 *
 * TODO(고객환경)
 *   - conf/hostbox.conf.php 에 아이템 키를 채운다.
 *   - 5버전 화면이 메뉴의 어디에 있었는지, 필터가 있었는지 확인한다. (화면과 컨트롤러는 확인하지 못했다.
 *     지금은 보고서 아래에 두었고 필터는 호스트 그룹/호스트만 있다)
 *
 * 이 화면에 속한 파일
 *   actions/reports/hostbox/HostBoxList.php   컨트롤러 (이 파일)
 *   views/reports/hostbox.list.php            화면 뷰
 *   conf/hostbox.conf.php                     아이템 키
 */

namespace Modules\DMove\Actions\Reports\HostBox;

use API;
use CArrayHelper;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CPagerHelper;
use CProfile;
use CUrl;
use CWebUser;
use Modules\DMove\Includes\Export\ExportTrait;
use Modules\DMove\Includes\Filter\HostFilter;
use Modules\DMove\Includes\Lang;

class HostBoxList extends CController {

	// 내보내기 플러그인. (includes/export/ExportTrait.php)
	use ExportTrait;

	private const PROFILE_PREFIX = 'web.dmove.hostbox.';

	/** 설정 파일 위치. 모듈 폴더 기준. */
	private const CONFIG_FILE = '/conf/hostbox.conf.php';

	/** 바이트 단위로 표시할 열. */
	private const BYTE_COLUMNS = ['total_memory', 'use_memory', 'total_disk', 'use_disk'];

	protected function init(): void {
		// 조회 전용 화면이라 CSRF 토큰 검사를 끈다.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'page' =>		'ge 1',
			'filter_set' =>	'in 1',
			'filter_rst' =>	'in 1',
			'sort' =>		'in name',
			'sortorder' =>	'in '.ZBX_SORT_UP.','.ZBX_SORT_DOWN
		] + HostFilter::INPUT_FIELDS + self::exportInputFields();

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	/**
	 * 접근 권한. 5버전처럼 로그인한 사용자(게스트 제외) 모두 허용한다.
	 */
	protected function checkPermissions(): bool {
		// isGuest(): 로그인하지 않고 들어온 게스트 계정. 게스트도 사용자 유형은 User 라서 따로 막는다.
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	protected function doAction(): void {
		// ── 1) 필터 저장/초기화 ──
		if ($this->hasInput('filter_set')) {
			HostFilter::save(self::PROFILE_PREFIX, $this->getInput('filter_groupids', []),
				$this->getInput('filter_hostids', [])
			);
		}
		elseif ($this->hasInput('filter_rst')) {
			HostFilter::reset(self::PROFILE_PREFIX);
		}

		$host_filter = HostFilter::load(self::PROFILE_PREFIX);

		// ── 2) 설정 읽기 ──
		// dirname(__DIR__, 3): 이 파일 폴더(hostbox)에서 세 단계 위 = 모듈 폴더.
		$config = require dirname(__DIR__, 3).self::CONFIG_FILE;
		$item_keys = array_map('trim', $config['item_keys']);

		// ── 3) 정렬 ──
		$sort_order = $this->getInput('sortorder', CProfile::get(self::PROFILE_PREFIX.'sortorder', ZBX_SORT_UP));
		CProfile::update(self::PROFILE_PREFIX.'sortorder', $sort_order, PROFILE_TYPE_STR);

		// ── 4) 데이터 ──
		$rows = $item_keys['total_core'] !== '' ? $this->getRows($host_filter, $item_keys) : [];

		CArrayHelper::sort($rows, [['field' => 'name', 'order' => $sort_order]]);
		$rows = array_values($rows);

		$columns = self::getColumns($item_keys['total_disk'] !== '');
		$title = Lang::t('Box usage');

		// ── 5) 내보내기 요청이면 파일로 응답하고 끝낸다 ──
		if ($this->isExportRequest()) {
			$this->setExportResponse($title, 'box_usage', $columns, $rows);

			return;
		}

		// ── 6) 화면 응답 ──
		$data = [
			'action' => 'hostbox.list',
			'export_action' => 'hostbox.export',
			'title' => $title,
			'columns' => $columns,
			'active_tab' => CProfile::get(self::PROFILE_PREFIX.'filter.active', 1),
			'host_filter' => HostFilter::toViewData($host_filter),
			'sort' => 'name',
			'sortorder' => $sort_order,
			// 목록에 호스트를 고르는 기준(total_core 키)이 비어 있으면 뷰가 안내를 띄운다.
			'config_missing' => $item_keys['total_core'] === ''
		];

		$data['paging'] = CPagerHelper::paginate($this->getInput('page', 1), $rows, $sort_order,
			(new CUrl('zabbix.php'))->setArgument('action', $data['action'])
		);
		$data['rows'] = $rows;

		$response = new CControllerResponseData($data);
		$response->setTitle($title);

		$this->setResponse($response);
	}

	/**
	 * 표의 열 정의. 화면의 제목 줄과 내보내기 파일이 같이 쓴다. ("최근 데이터" 링크 열은 내보내기에 없다)
	 *
	 * @param bool $with_total_disk  전체 Disk 열을 넣을지. (설정에 키가 있을 때만)
	 */
	private static function getColumns(bool $with_total_disk): array {
		$columns = [
			'name' => _('Host'),
			'total_core' => Lang::t('CPU total cores'),
			'use_core' => Lang::t('CPU allocated cores'),
			'total_memory' => Lang::t('Total memory'),
			'use_memory' => Lang::t('Allocated memory')
		];

		if ($with_total_disk) {
			$columns['total_disk'] = Lang::t('Total disk');
		}

		$columns['use_disk'] = Lang::t('Allocated disk');

		return $columns;
	}

	/**
	 * Box 목록.
	 *
	 * @param array $item_keys  conf/hostbox.conf.php 의 item_keys (공백 정리됨)
	 *
	 * @return array  [['hostid' => .., 'name' => .., 'total_core' => '48', 'total_memory' => '1.5 TB', ...], ...]
	 */
	private function getRows(array $host_filter, array $item_keys): array {
		// 비어 있지 않은 키만. [열 키 => 아이템 키]
		$keys = array_filter($item_keys, static fn(string $key): bool => $key !== '');

		/*
		 * 1) 필터에 맞는 호스트들의 해당 아이템을 한 번에 조회한다.
		 *    lastvalue : 아이템의 마지막 수집 값. 이력을 따로 조회하지 않아도 된다.
		 *    filter    : 키가 목록 중 하나와 정확히 같은 아이템.
		 */
		$items = API::Item()->get(HostFilter::toApiOptions($host_filter) + [
			'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'lastclock'],
			'filter' => ['key_' => array_values(array_unique($keys))]
		]);

		// [hostid => [아이템 키 => 마지막 값]]  아직 한 번도 수집되지 않은 아이템(lastclock 0)은 값이 없는 것으로 본다.
		$values = [];

		foreach ($items as $item) {
			if ($item['lastclock'] != 0) {
				$values[$item['hostid']][$item['key_']] = $item['lastvalue'];
			}
		}

		// 2) total_core 아이템을 가진 호스트 = Box.
		$hostids = [];

		foreach ($items as $item) {
			if ($item['key_'] === $keys['total_core']) {
				$hostids[$item['hostid']] = true;
			}
		}

		if (!$hostids) {
			return [];
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name'],
			'hostids' => array_keys($hostids)
		]);

		// 3) 행 만들기.
		$rows = [];

		foreach ($hosts as $host) {
			$row = ['hostid' => $host['hostid'], 'name' => $host['name']];

			foreach ($keys as $column => $item_key) {
				$value = $values[$host['hostid']][$item_key] ?? null;

				if ($value === null || $value === '') {
					$row[$column] = '-';
				}
				elseif (in_array($column, self::BYTE_COLUMNS, true) && is_numeric($value)) {
					// convertUnits(): 코어 함수. 1610612736000 B -> "1.46 TB" 처럼 읽기 쉬운 단위로 바꾼다.
					$row[$column] = convertUnits(['value' => $value, 'units' => 'B']);
				}
				else {
					$row[$column] = $value;
				}
			}

			// 설정에 키가 없는 열은 '-' 로 채운다. (뷰와 내보내기가 모든 열을 기대한다)
			foreach (['total_core', 'use_core', 'total_memory', 'use_memory', 'total_disk', 'use_disk'] as $column) {
				$row += [$column => '-'];
			}

			$rows[] = $row;
		}

		return $rows;
	}
}
