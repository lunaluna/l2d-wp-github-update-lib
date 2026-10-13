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
		$register = require dirname( __DIR__ ) . '/dist/loader.php';
		$register( array( 'slug' => 'plugin-a' ) );

		global $l2dwpghul_updater_registry;

		$this->assertSame(
			array( dirname( __DIR__ ) . '/dist/class-l2d-github-updater.php' ),
			array_values( $l2dwpghul_updater_registry['files'] )
		);
		$this->assertSame( array( array( 'slug' => 'plugin-a' ) ), $l2dwpghul_updater_registry['configs'] );
	}

	/**
	 * 複数バージョンが登録された場合、最も新しいバージョンの本体だけが
	 * require_once され、登録された設定の数だけインスタンス化されること.
	 */
	public function test_boot_uses_highest_version() {
		require dirname( __DIR__ ) . '/dist/loader.php';

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
		require dirname( __DIR__ ) . '/dist/loader.php';

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
		require dirname( __DIR__ ) . '/dist/loader.php';

		l2dwpghul_updater_boot();

		$this->assertSame( array(), $GLOBALS['l2d_test_loaded_files'] );
	}

	/**
	 * l2dwpghul_updater_register() は $config を検証も加工もせず素通しすること.
	 */
	public function test_register_passes_config_through_without_modification() {
		require dirname( __DIR__ ) . '/dist/loader.php';

		$config = array(
			'slug'         => 'plugin-a',
			'unknown_key'  => 'kept-as-is',
			'nested'       => array( 'a' => 1 ),
		);
		l2dwpghul_updater_register( '1.0.0', __DIR__ . '/fixtures/fake-class-v1.php', $config );

		global $l2dwpghul_updater_registry;

		$this->assertSame( array( $config ), $l2dwpghul_updater_registry['configs'] );
	}

	/**
	 * 同じ版を別々のパス(別プラグインの同梱コピー)から 2 回登録しても、本体は 1 回だけ
	 * 読み込まれ、設定は 2 件ともインスタンス化されること.
	 */
	public function test_same_version_registered_twice_loads_once() {
		require dirname( __DIR__ ) . '/dist/loader.php';

		l2dwpghul_updater_register( '1.0.0', __DIR__ . '/fixtures/fake-class-v1.php', array( 'slug' => 'plugin-a' ) );
		l2dwpghul_updater_register( '1.0.0', __DIR__ . '/fixtures/fake-class-v1.php', array( 'slug' => 'plugin-b' ) );

		l2dwpghul_updater_boot();

		$this->assertSame( array( __DIR__ . '/fixtures/fake-class-v1.php' ), $GLOBALS['l2d_test_loaded_files'] );
		$this->assertCount( 2, $GLOBALS['l2d_test_constructed_configs'] );
	}

	/**
	 * 古い版を先に登録しても、新しい版の本体が読み込まれること.
	 */
	public function test_older_registered_first_still_picks_newer() {
		require dirname( __DIR__ ) . '/dist/loader.php';

		l2dwpghul_updater_register( '1.1.0', __DIR__ . '/fixtures/fake-class-v1.php', array( 'slug' => 'plugin-a' ) );
		l2dwpghul_updater_register( '1.3.0', __DIR__ . '/fixtures/fake-class-v2.php', array( 'slug' => 'plugin-b' ) );

		l2dwpghul_updater_boot();

		$this->assertSame( array( __DIR__ . '/fixtures/fake-class-v2.php' ), $GLOBALS['l2d_test_loaded_files'] );
		$this->assertCount( 2, $GLOBALS['l2d_test_constructed_configs'] );
	}

	/**
	 * 新しい版を先に登録しても、新しい版の本体が読み込まれること(順序に依存しない).
	 */
	public function test_newer_registered_first_still_picks_newer() {
		require dirname( __DIR__ ) . '/dist/loader.php';

		l2dwpghul_updater_register( '1.3.0', __DIR__ . '/fixtures/fake-class-v2.php', array( 'slug' => 'plugin-b' ) );
		l2dwpghul_updater_register( '1.1.0', __DIR__ . '/fixtures/fake-class-v1.php', array( 'slug' => 'plugin-a' ) );

		l2dwpghul_updater_boot();

		$this->assertSame( array( __DIR__ . '/fixtures/fake-class-v2.php' ), $GLOBALS['l2d_test_loaded_files'] );
		$this->assertCount( 2, $GLOBALS['l2d_test_constructed_configs'] );
	}

	/**
	 * 1.2.0 のローダー(git の 1.2.0 タグの dist/loader.php をそのままコピーしたフィクスチャ)が
	 * 先に関数を定義していても、現在の dist/loader.php を同じプロセスに読み込んだとき、
	 * 現在の版の本体が採用され、両方の設定が現在の版のクラスでインスタンス化されること.
	 *
	 * 実際のサイトでは、1.2.0 を同梱するプラグインと現在の版を同梱するプラグインが混在し、
	 * どちらのローダーが先に読み込まれるかは決まらない. function_exists ガードにより、
	 * 先に読み込まれたほうの関数が使われるが、レジストリの最大版が勝つ動作は同じ.
	 * (自己申告バージョンが 1.2.0 のままの間は、同じ版の登録が後勝ちで上書きされるだけだが、
	 * 1.3.0 に上げた時点で「新しい版が勝つ」ことの検証として意味を持つ.)
	 */
	public function test_legacy_1_2_0_loader_then_current_loader_picks_current() {
		$legacy_register  = require __DIR__ . '/fixtures/legacy-1.2.0/loader.php';
		$current_register = require dirname( __DIR__ ) . '/dist/loader.php';

		$legacy_register(
			array(
				'plugin_file' => __DIR__ . '/fixtures/fake-plugin/fake-plugin.php',
				'github_repo' => 'lunaluna/legacy-plugin',
			)
		);
		$current_register(
			array(
				'plugin_file' => __DIR__ . '/fixtures/fake-plugin/fake-plugin.php',
				'github_repo' => 'lunaluna/current-plugin',
			)
		);

		l2dwpghul_updater_boot();

		$this->assert_current_version_wins_over_legacy();
	}

	/**
	 * 現在の dist/loader.php を先に、1.2.0 のローダーを後に読み込んだ場合も、結果が同じであること.
	 */
	public function test_current_loader_then_legacy_1_2_0_loader_picks_current() {
		$current_register = require dirname( __DIR__ ) . '/dist/loader.php';
		$legacy_register  = require __DIR__ . '/fixtures/legacy-1.2.0/loader.php';

		$current_register(
			array(
				'plugin_file' => __DIR__ . '/fixtures/fake-plugin/fake-plugin.php',
				'github_repo' => 'lunaluna/current-plugin',
			)
		);
		$legacy_register(
			array(
				'plugin_file' => __DIR__ . '/fixtures/fake-plugin/fake-plugin.php',
				'github_repo' => 'lunaluna/legacy-plugin',
			)
		);

		l2dwpghul_updater_boot();

		$this->assert_current_version_wins_over_legacy();
	}

	/**
	 * 1.2.0 のダミーと現在の本体のどちらが採用されたかを、版の大小に応じて検証する.
	 *
	 * 現在の自己申告バージョンが 1.2.0 より大きければ、現在の本体が採用され、2 件の設定の両方が
	 * 現在のクラスでインスタンス化される(フィルタが 2 回登録される). 同じ 1.2.0 の間は、
	 * レジストリが版をキーにしていて後勝ちで上書きされるだけ(どちらも同じコード)なので、
	 * どちらか 1 つだけが読み込まれることだけを確認する. 1.3.0 に上げた時点で厳密な検証になる.
	 */
	private function assert_current_version_wins_over_legacy() {
		preg_match( "/l2dwpghul_updater_register\\( '([^']+)'/", file_get_contents( dirname( __DIR__ ) . '/dist/loader.php' ), $m );
		$this->assertNotEmpty( $m, 'dist/loader.php の自己申告バージョンを読み取れること.' );
		$this->assertTrue( version_compare( $m[1], '1.2.0', '>=' ) );

		$current = dirname( __DIR__ ) . '/dist/class-l2d-github-updater.php';
		$legacy  = __DIR__ . '/fixtures/legacy-1.2.0/class-l2d-github-updater.php';
		$loaded  = array_intersect( array( $current, $legacy ), get_included_files() );

		// どの場合も、本体は 1 つだけ読み込まれる.
		$this->assertCount( 1, $loaded );

		if ( version_compare( $m[1], '1.2.0', '>' ) ) {
			$this->assertSame( array( $current ), array_values( $loaded ) );
			$this->assertCount( 2, $GLOBALS['l2d_test_filters']['pre_set_site_transient_update_plugins'] );
		}
	}
}
