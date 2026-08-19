<?php
/**
 * loader.php のテスト用フィクスチャ(fake-class-v1.php と同じ役割の別ファイル).
 * 「どちらのファイルが実際に require_once されたか」を区別するために
 * ファイルを分けている. クラス定義自体は class_exists ガードで共有する.
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
