<?php declare(strict_types = 0);
/*
 * 내보내기 버튼. 뷰에 "꽂아서" 쓴다. (전체 사용법: ExportTrait.php 머리말)
 *
 *   use Modules\DMove\Includes\Export\ExportButtons;
 *
 *   (new CHtmlPage())
 *       ->setTitle(...)
 *       ->setControls(ExportButtons::create('diskusage.export'))     // 제목 오른쪽에 버튼 2개
 *
 * 버튼을 누르면 현재 저장된 필터/정렬 그대로 전체 행을 내려받는다. (필터 값은 프로파일에 저장되어 있다)
 *
 * ── 누가 쓰는가 ──
 *   내보내기가 있는 화면의 뷰. (views/inventory/swinventory.list.php, views/reports/winpatch.list.php,
 *   peakevent.list.php, serverusage.list.php, usageexport.list.php, 견본 template/views/sample.list.php)
 *
 * ── 동작 순서 ──
 *   뷰가 create('x.export') 로 버튼을 만든다
 *     -> 버튼을 누르면 zabbix.php?action=x.export&format=csv (또는 xlsx) 로 이동한다
 *     -> 컨트롤러(ExportTrait)와 views/export/layout.file.php 가 파일을 만들어 내려보낸다
 *
 * ── 관련 파일 ──
 *   includes/export/ExportTrait.php    컨트롤러 쪽 플러그인
 *   includes/export/ExportFormat.php   형식 목록 (csv, xlsx)
 */

namespace Modules\DMove\Includes\Export;

use CList;
use CRedirectButton;
use CTag;
use CUrl;
use Modules\DMove\Includes\Lang;

class ExportButtons {

	/**
	 * 내보내기 버튼 묶음을 만든다. 형식마다 버튼 하나씩이다.
	 *
	 * @param string $export_action  manifest.json 에 등록한 내보내기 액션 이름. 예) 'swinventory.export'
	 * @param array  $formats        표시할 버튼. 기본은 CSV 와 Excel 둘 다.
	 *                               CSV 만: ExportButtons::create('x.export', [ExportFormat::CSV])
	 *
	 * @return CTag  CHtmlPage::setControls() 에 그대로 넘길 수 있는 버튼 묶음.
	 */
	public static function create(string $export_action, array $formats = ExportFormat::ALL): CTag {
		$labels = [
			// _('Export to CSV') 는 코어에 있는 문구라 코어 번역을 쓴다.
			ExportFormat::CSV => _('Export to CSV'),
			ExportFormat::XLSX => Lang::t('Export to Excel')
		];

		$list = new CList();

		foreach ($formats as $format) {
			// 버튼 글자가 준비되지 않은 형식은 건너뛴다.
			if (!array_key_exists($format, $labels)) {
				continue;
			}

			// CRedirectButton: 누르면 주어진 주소로 이동하는 버튼. 응답이 파일이라 화면은 그대로 있고 다운로드만 된다.
			$list->addItem(
				(new CRedirectButton($labels[$format],
					(new CUrl('zabbix.php'))
						->setArgument('action', $export_action)
						->setArgument('format', $format)
				))->setId('export_'.$format)
			);
		}

		// CTag('nav', true, 내용): <nav> ... </nav> 태그. 두 번째 true 는 닫는 태그가 있다는 뜻. 코어 화면의 버튼 영역과 같은 구조다.
		return (new CTag('nav', true, $list))->setAttribute('aria-label', _('Content controls'));
	}
}
