<?php declare(strict_types = 0);
/*
 * 로그인 화면의 추가 부품. 5버전 로그인 화면(general.login.php)과 같은 모양.
 *
 * 부르는 곳: ui/include/views/general.login.php 의 DMOVE-PATCH (docs/CORE_PATCHES.md)
 * 기능이 꺼져 있으면(conf/login.conf.php 없음) make() 가 null 을 돌려주고 로그인 화면은 기본 그대로다.
 *
 *   아이디
 *   비밀번호
 *   OTP          [          ]     <- 비밀번호 + OTP 로 로그인
 *   [ ] MPASS                     <- 체크하면 OTP 칸이 막히고, [로그인] 이 휴대폰 푸시 인증으로 바뀐다
 *   [로그인]
 *
 *   MPASS 대기 창: "Push 알림을 전송하였습니다." + 30초 진행 막대 + [취소]
 */

namespace Modules\DMove\Includes;

use CCheckBox;
use CDiv;
use CImg;
use CLabel;
use CLink;
use CTextBox;

require_once __DIR__.'/LoginAuth.php';

class LoginAuthView {

	/**
	 * @return array|null  ['logo', 'otp', 'mpass', 'links', 'popup' => HTML, 'script' => HTML] 또는 null(꺼짐)
	 */
	public static function make(): ?array {
		if (!LoginAuth::isEnabled()) {
			return null;
		}

		$config = LoginAuth::config();

		return [
			// 로고 아래 그림. 5버전: assets/img/esm-logo.png (280 x 35)
			'logo' => $config['logo'] !== ''
				? (new CDiv(
					(new CImg($config['logo']))
						->setAttribute('width', '280px')
						->setAttribute('height', '35px')
				))->addClass('signin-title')
				: null,
			'otp' => [
				new CLabel('OTP', 'otp'),
				(new CTextBox('otp'))->setAttribute('autocomplete', 'off')
			],
			'mpass' => (new CCheckBox('mpass'))->setLabel('MPASS'),
			'links' => $config['license_url'] !== ''
				? (new CDiv(
					(new CLink('License', $config['license_url']))
						->setTarget('_blank')
						->addClass(ZBX_STYLE_GREY)
						->addClass(ZBX_STYLE_LINK_ALT)
				))->addClass(ZBX_STYLE_SIGNIN_LINKS)
				: null,
			'popup' => self::popup((int) $config['mpass_wait']),
			'script' => '<script src="modules/dmove/assets/js/login.js"></script>'
		];
	}

	/**
	 * MPASS 대기 창. 5버전과 같은 글자. 코어 대화 상자 모양(overlay-dialogue)을 빌려 쓴다.
	 */
	private static function popup(int $wait): string {
		return
			'<div class="overlay-dialogue modal modal-popup dm-mpass-popup" id="dm-mpass-popup" aria-modal="true"'.
				' data-wait="'.$wait.'" style="display: none;">'.
				'<div class="overlay-dialogue-header"><h4>Push 알림을 전송하였습니다.</h4></div>'.
				'<div class="overlay-dialogue-body">'.
					'<progress max="'.$wait.'" value="0"></progress>'.
					'<div class="dm-mpass-seconds">'.$wait.'초</div>'.
				'</div>'.
				'<div class="overlay-dialogue-footer">'.
					'<button type="button" class="btn-alt" id="dm-mpass-cancel">취소</button>'.
				'</div>'.
			'</div>'.
			'<style>'.
				// id 로 써야 코어 대화 상자 CSS 의 위치 값보다 앞선다.
				'#dm-mpass-popup { position: fixed; top: 30%; left: 50%; width: 300px; margin-left: -150px; }'.
				'#dm-mpass-popup progress { width: 100%; height: 20px; }'.
				'#dm-mpass-popup .dm-mpass-seconds { text-align: center; margin-top: 6px; }'.
			'</style>';
	}
}
