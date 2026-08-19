<?php
/**
 * loader.php(バージョン交渉ローダー)のテスト.
 *
 * @package L2dWpGithubUpdateLib
 */

/**
 * バージョン交渉ローダーのテスト.
 *
 * フィクスチャ(fake-class-v1.php / fake-class-v2.php)は本物のクラスと同名の
 * L2dwpghul_GitHub_Updater を class_exists ガードで定義する。GitHubUpdaterTest
 * が同一プロセスで先に本物のクラスを読み込むとフィクスチャ側の定義がスキップ
 * され、テストが汚染されるため、このクラスは別プロセスで実行する.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LoaderTest extends PHPUnit\Framework\TestCase {

	/**
	 * 各テストの前にグローバルなレジストリと計測用配列をリセットする.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['l2dwpghul_updater_registry']         = null;
		$GLOBALS['l2d_test_loaded_files']        = array();
		$GLOBALS['l2d_test_constructed_configs'] = array();
	}

	/**
	 * loader.php の戻り値のクロージャを呼ぶと、このコピー固有のバージョンと
	 * ファイルパスで l2dwpghul_updater_register() が呼ばれること.
	 */
	public function test_returned_closure_registers_this_copy() {
		$register = require dirname( __DIR__ ) . '/loader.php';
		$register( array( 'slug' => 'plugin-a' ) );

		global $l2dwpghul_updater_registry;

		$this->assertSame(
			array( dirname( __DIR__ ) . '/class-l2d-github-updater.php' ),
			array_values( $l2dwpghul_updater_registry['files'] )
		);
		$this->assertSame( array( array( 'slug' => 'plugin-a' ) ), $l2dwpghul_updater_registry['configs'] );
	}

	/**
	 * 複数バージョンが登録された場合、最も新しいバージョンの本体だけが
	 * require_once され、登録された設定の数だけインスタンス化されること.
	 */
	public function test_boot_uses_highest_version() {
		require dirname( __DIR__ ) . '/loader.php';

		l2dwpghul_updater_register( '1.0.0', __DIR__ . '/fixtures/fake-class-v1.php', array( 'slug' => 'plugin-a' ) );
		l2dwpghul_updater_register( '2.0.0', __DIR__ . '/fixtures/fake-class-v2.php', array( 'slug' => 'plugin-b' ) );
		l2dwpghul_updater_register( '1.5.0', __DIR__ . '/fixtures/fake-class-v1.php', array( 'slug' => 'plugin-c' ) );

		l2dwpghul_updater_boot();

		$this->assertSame( array( __DIR__ . '/fixtures/fake-class-v2.php' ), $GLOBALS['l2d_test_loaded_files'] );
		$this->assertCount( 3, $GLOBALS['l2d_test_constructed_configs'] );
	}

	/**
	 * 候補が 1 つだけのとき、それがそのまま使われること.
	 */
	public function test_boot_with_single_candidate() {
		require dirname( __DIR__ ) . '/loader.php';

		l2dwpghul_updater_register( '1.0.0', __DIR__ . '/fixtures/fake-class-v1.php', array( 'slug' => 'plugin-a' ) );

		l2dwpghul_updater_boot();

		$this->assertSame( array( __DIR__ . '/fixtures/fake-class-v1.php' ), $GLOBALS['l2d_test_loaded_files'] );
		$this->assertCount( 1, $GLOBALS['l2d_test_constructed_configs'] );
	}

	/**
	 * 何も登録されていない状態で l2dwpghul_updater_boot() を呼んでもエラーにならず、
	 * 何も require されないこと.
	 */
	public function test_boot_with_no_candidates_does_nothing() {
		require dirname( __DIR__ ) . '/loader.php';

		l2dwpghul_updater_boot();

		$this->assertSame( array(), $GLOBALS['l2d_test_loaded_files'] );
	}

	/**
	 * l2dwpghul_updater_register() は $config を検証も加工もせず素通しすること.
	 */
	public function test_register_passes_config_through_without_modification() {
		require dirname( __DIR__ ) . '/loader.php';

		$config = array(
			'slug'         => 'plugin-a',
			'unknown_key'  => 'kept-as-is',
			'nested'       => array( 'a' => 1 ),
		);
		l2dwpghul_updater_register( '1.0.0', __DIR__ . '/fixtures/fake-class-v1.php', $config );

		global $l2dwpghul_updater_registry;

		$this->assertSame( array( $config ), $l2dwpghul_updater_registry['configs'] );
	}
}
