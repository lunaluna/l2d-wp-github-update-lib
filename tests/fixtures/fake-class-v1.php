<?php
/**
 * loader.php のテスト用フィクスチャ. class-l2d-github-updater.php の代わりに
 * l2dwpghul_updater_boot() から require_once されるダミーの本体ファイル.
 *
 * @package L2dWpGithubUpdateLib
 */

$GLOBALS['l2d_test_loaded_files'][] = __FILE__;

if ( ! class_exists( 'L2dwpghul_GitHub_Updater' ) ) {
	/**
	 * ダミーの更新機構クラス. 実際の実装は A3 で追加する.
	 */
	class L2dwpghul_GitHub_Updater {

		/**
		 * @param array $config このコピーに渡された設定.
		 */
		public function __construct( $config ) {
			$GLOBALS['l2d_test_constructed_configs'][] = $config;
		}
	}
}
