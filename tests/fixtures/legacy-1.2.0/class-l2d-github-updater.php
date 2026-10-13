<?php
/**
 * 1.2.0 のローダー(同じディレクトリの loader.php)が require_once する、ダミーの本体ファイル.
 *
 * 本物の 1.2.0 のクラスではなく、読み込まれたことと、インスタンス化された回数だけを記録する.
 * バージョン混在のテスト(LoaderTest)で、新しい版のほうが採用されることを確認するために使う.
 *
 * @package L2dWpGithubUpdateLib
 */

$GLOBALS['l2d_test_loaded_files'][] = __FILE__;

if ( ! class_exists( 'L2dwpghul_GitHub_Updater' ) ) {
	/**
	 * ダミーの更新機構クラス.
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
