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
 */

namespace Modules\DMove\Includes\Export;

use CList;
use CRedirectButton;
use CTag;
use CUrl;
use Modules\DMove\Includes\Lang;

class ExportButtons {

	/**
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

		return (new CTag('nav', true, $list))->setAttribute('aria-label', _('Content controls'));
	}
}
