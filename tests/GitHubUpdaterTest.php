<?php
/**
 * L2dwpghul_GitHub_Updater のテスト.
 *
 * @package L2dWpGithubUpdateLib
 */

require_once __DIR__ . '/fixtures/class-testable-github-updater.php';
require_once __DIR__ . '/stubs/class-fake-wp-filesystem.php';

/**
 * GitHubUpdaterTest クラス.
 */
class GitHubUpdaterTest extends PHPUnit\Framework\TestCase {

	/**
	 * テスト用のダミープラグインファイルの絶対パス.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * 各テストの前に、フィルタ登録とサイトトランジエントをリセットする.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['l2d_test_filters']          = array();
		$GLOBALS['l2d_test_site_transients']  = array();
		$GLOBALS['wp_filesystem']              = new FakeWpFilesystem();
		$this->plugin_file = __DIR__ . '/fixtures/fake-plugin/fake-plugin.php';
	}

	/**
	 * 標準構成の TestableGitHubUpdater を作る.
	 *
	 * @param array $config_overrides $config に上書きするキー.
	 * @return TestableGitHubUpdater
	 */
	private function make_updater( array $config_overrides = array() ) {
		return new TestableGitHubUpdater(
			array_merge(
				array(
					'plugin_file' => $this->plugin_file,
					'github_repo' => 'lunaluna/fake-plugin',
				),
				$config_overrides
			)
		);
	}

	/**
	 * 200 OK のレスポンスを組み立てる.
	 *
	 * @param array $body デコード前の連想配列.
	 * @return array
	 */
	private function ok_response( array $body ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $body ),
		);
	}

	/**
	 * デフォルトのリリースボディ.
	 *
	 * @param array $overrides 上書きするキー.
	 * @return array
	 */
	private function release_body( array $overrides = array() ) {
		return array_merge(
			array(
				'tag_name'     => 'v1.2.0',
				'body'         => 'Release notes.',
				'published_at' => '2026-01-01T00:00:00Z',
				'assets'       => array(
					array(
						'name'                 => 'fake-plugin.1.2.0.zip',
						'browser_download_url' => 'https://example.com/fake-plugin.1.2.0.zip',
					),
				),
			),
			$overrides
		);
	}

	// -------------------------------------------------------------------------
	// normalize_version() / extract_zip_url() (純粋関数. FAUC からの移植)
	// -------------------------------------------------------------------------

	/**
	 * normalize_version() が "v" プレフィックスの有無・大文字小文字を問わず
	 * バージョン番号だけを返すことを確認する.
	 */
	public function test_normalize_version_strips_leading_v() {
		$this->assertSame( '1.8.0', L2dwpghul_GitHub_Updater::normalize_version( '1.8.0' ) );
		$this->assertSame( '1.8.0', L2dwpghul_GitHub_Updater::normalize_version( 'v1.8.0' ) );
		$this->assertSame( '1.8.0', L2dwpghul_GitHub_Updater::normalize_version( 'V1.8.0' ) );
	}

	/**
	 * normalize_version() は ltrim と異なり、先頭の "v" を1文字だけ取り除く
	 * ことを確認する（"vv1.0" のような値を壊さない）.
	 */
	public function test_normalize_version_does_not_strip_repeated_v() {
		$this->assertSame( 'v1.0', L2dwpghul_GitHub_Updater::normalize_version( 'vv1.0' ) );
	}

	/**
	 * extract_zip_url() がプラグインスラッグで始まる .zip アセットの
	 * ダウンロード URL を選ぶことを確認する.
	 */
	public function test_extract_zip_url_picks_matching_asset() {
		$body = array(
			'assets' => array(
				array(
					'name'                 => 'source-code.zip',
					'browser_download_url' => 'https://example.com/source-code.zip',
				),
				array(
					'name'                 => 'fake-plugin.1.2.0.zip',
					'browser_download_url' => 'https://example.com/fake-plugin.1.2.0.zip',
				),
			),
		);

		$this->assertSame(
			'https://example.com/fake-plugin.1.2.0.zip',
			L2dwpghul_GitHub_Updater::extract_zip_url( $body, 'fake-plugin' )
		);
	}

	/**
	 * extract_zip_url() は一致するアセットがなければ zipball 等へ
	 * フォールバックせず null を返すことを確認する（回帰テスト）.
	 */
	public function test_extract_zip_url_returns_null_when_no_match() {
		$body = array(
			'assets' => array(
				array(
					'name'                 => 'source-code.zip',
					'browser_download_url' => 'https://example.com/source-code.zip',
				),
			),
		);

		$this->assertNull( L2dwpghul_GitHub_Updater::extract_zip_url( $body, 'fake-plugin' ) );
	}

	/**
	 * extract_zip_url() はアセット配列が空でも null を返すことを確認する.
	 */
	public function test_extract_zip_url_returns_null_when_assets_empty() {
		$this->assertNull( L2dwpghul_GitHub_Updater::extract_zip_url( array( 'assets' => array() ), 'fake-plugin' ) );
		$this->assertNull( L2dwpghul_GitHub_Updater::extract_zip_url( array(), 'fake-plugin' ) );
	}

	/**
	 * extract_zip_url() は $pattern が callable なら、それを判定関数として使う
	 * ことを確認する(WPMAR の vendor-pdf.zip 除外のような固有ニーズに対応する).
	 */
	public function test_extract_zip_url_supports_callable_pattern() {
		$body = array(
			'assets' => array(
				array(
					'name'                 => 'vendor-pdf.zip',
					'browser_download_url' => 'https://example.com/vendor-pdf.zip',
				),
				array(
					'name'                 => 'fake-plugin.1.2.0.zip',
					'browser_download_url' => 'https://example.com/fake-plugin.1.2.0.zip',
				),
			),
		);

		$pattern = function ( $name ) {
			return 'vendor-pdf.zip' !== $name && 0 === strpos( $name, 'fake-plugin' ) && '.zip' === substr( $name, -4 );
		};

		$this->assertSame(
			'https://example.com/fake-plugin.1.2.0.zip',
			L2dwpghul_GitHub_Updater::extract_zip_url( $body, $pattern )
		);
	}

	// -------------------------------------------------------------------------
	// 設定の既定値導出
	// -------------------------------------------------------------------------

	/**
	 * 必須キー(plugin_file, github_repo)が欠けている場合、_doing_it_wrong() を
	 * 呼びフックを登録せずに何もしないことを確認する.
	 */
	public function test_constructor_guards_missing_required_keys() {
		$updater = new TestableGitHubUpdater( array( 'plugin_file' => $this->plugin_file ) );

		// フックが登録されないため、$GLOBALS['l2d_test_filters'] は空のまま.
		$this->assertSame( array(), $GLOBALS['l2d_test_filters'] );
	}

	/**
	 * slug の既定値が plugin_file から導出されることを、cache_key の既定値
	 * (slug を含まず github_repo の md5 由来)ではなく、実際に反映される
	 * plugin_info() の slug 一致判定を通して確認する.
	 */
	public function test_slug_defaults_to_directory_name_of_plugin_file() {
		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$result = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'fake-plugin' ) );

		$this->assertIsObject( $result );
		$this->assertSame( 'fake-plugin', $result->slug );
	}

	/**
	 * name / author の config が未指定のとき、plugin_file のヘッダーから
	 * 導出されること(Author URI があればリンク化されること)を確認する.
	 */
	public function test_name_and_author_default_to_plugin_headers() {
		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$result = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'fake-plugin' ) );

		$this->assertSame( 'Fake Plugin', $result->name );
		$this->assertSame( '<a href="https://example.com/fake-author">Fake Author</a>', $result->author );
	}

	/**
	 * name / author を config で明示指定すると、ヘッダー由来の値より
	 * 優先されることを確認する.
	 */
	public function test_name_and_author_config_overrides_take_precedence() {
		$updater = $this->make_updater(
			array(
				'name'   => 'Custom Display Name',
				'author' => 'Custom Author HTML',
			)
		);
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$result = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'fake-plugin' ) );

		$this->assertSame( 'Custom Display Name', $result->name );
		$this->assertSame( 'Custom Author HTML', $result->author );
	}

	/**
	 * cache_key を明示指定すると、それがサイトトランジエントキーとして
	 * 使われること(FAUC の既存インストールとの後方互換に必要).
	 */
	public function test_cache_key_can_be_overridden() {
		$updater = $this->make_updater( array( 'cache_key' => 'FAUC_github_release_cache' ) );

		$GLOBALS['l2d_test_site_transients']['FAUC_github_release_cache'] = $this->release_body();

		$updater->after_update(
			new stdClass(),
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'fake-plugin/fake-plugin.php' ),
			)
		);

		$this->assertArrayNotHasKey( 'FAUC_github_release_cache', $GLOBALS['l2d_test_site_transients'] );
	}

	// -------------------------------------------------------------------------
	// check_for_update()
	// -------------------------------------------------------------------------

	/**
	 * 新しいバージョンが GitHub 上にあるとき、response に更新情報が入ること.
	 */
	public function test_check_for_update_sets_response_when_newer_version_available() {
		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$result    = $updater->check_for_update( $transient );

		$this->assertObjectHasProperty( 'response', $result );
		$this->assertSame( '1.2.0', $result->response['fake-plugin/fake-plugin.php']->new_version );
	}

	/**
	 * インストール済みバージョンが最新のとき、response の古い通知を消して
	 * no_update に登録すること.
	 */
	public function test_check_for_update_sets_no_update_when_up_to_date() {
		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body( array( 'tag_name' => 'v1.0.0' ) ) );

		$transient = (object) array(
			'checked'  => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ),
			'response' => array( 'fake-plugin/fake-plugin.php' => (object) array( 'stale' => true ) ),
		);
		$result = $updater->check_for_update( $transient );

		$this->assertArrayNotHasKey( 'fake-plugin/fake-plugin.php', $result->response );
		$this->assertSame( '1.0.0', $result->no_update['fake-plugin/fake-plugin.php']->new_version );
	}

	/**
	 * リリース情報が取得できないとき、トランジェントを加工せずそのまま返すこと.
	 */
	public function test_check_for_update_returns_transient_unchanged_when_release_unavailable() {
		$updater = $this->make_updater();
		$updater->stub_response = new WP_Error();

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$result    = $updater->check_for_update( $transient );

		$this->assertSame( $transient, $result );
	}

	/**
	 * checked が空、または transient がオブジェクトでないときは何もしないこと.
	 */
	public function test_check_for_update_ignores_transient_without_checked() {
		$updater = $this->make_updater();

		$this->assertSame( 'not-an-object', $updater->check_for_update( 'not-an-object' ) );

		$empty_transient = (object) array( 'checked' => array() );
		$this->assertSame( $empty_transient, $updater->check_for_update( $empty_transient ) );
	}

	// -------------------------------------------------------------------------
	// キャッシュ層
	// -------------------------------------------------------------------------

	/**
	 * 成功したレスポンスがキャッシュされ、2 回目の呼び出しでは http_get() が
	 * 呼ばれないこと.
	 */
	public function test_fetch_latest_release_caches_successful_response() {
		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$updater->check_for_update( $transient );
		$updater->check_for_update( $transient );

		$this->assertCount( 1, $updater->requested_urls );
	}

	/**
	 * キャッシュが空配列(バックオフ中)のとき、http_get() を呼ばずに
	 * 更新なしとして扱うこと.
	 */
	public function test_fetch_latest_release_respects_backoff_cache() {
		$updater = $this->make_updater();
		$GLOBALS['l2d_test_site_transients']['l2dwpghul_updater_' . md5( 'lunaluna/fake-plugin' )] = array();

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$result    = $updater->check_for_update( $transient );

		$this->assertSame( $transient, $result );
		$this->assertSame( array(), $updater->requested_urls );
	}

	/**
	 * キャッシュに version が欠損した壊れた配列が入っていた場合、有効な
	 * キャッシュとして扱わず、GitHub から再取得すること.
	 */
	public function test_fetch_latest_release_ignores_broken_cache_missing_version() {
		$updater = $this->make_updater();
		$GLOBALS['l2d_test_site_transients']['l2dwpghul_updater_' . md5( 'lunaluna/fake-plugin' )] = array( 'zip_url' => 'https://example.com/x.zip' );
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$updater->check_for_update( $transient );

		$this->assertCount( 1, $updater->requested_urls );
	}

	// -------------------------------------------------------------------------
	// HTTP 失敗系統
	// -------------------------------------------------------------------------

	/**
	 * http_get() が WP_Error を返すとき、更新なしとして扱うこと.
	 */
	public function test_request_latest_release_returns_null_on_wp_error() {
		$updater = $this->make_updater();
		$updater->stub_response = new WP_Error();

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$result    = $updater->check_for_update( $transient );

		$this->assertSame( $transient, $result );
	}

	/**
	 * レスポンスが 200 以外のとき、更新なしとして扱うこと.
	 */
	public function test_request_latest_release_returns_null_on_non_200() {
		$updater = $this->make_updater();
		$updater->stub_response = array(
			'response' => array( 'code' => 403 ),
			'body'     => '',
		);

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$result    = $updater->check_for_update( $transient );

		$this->assertSame( $transient, $result );
	}

	/**
	 * レスポンスボディが JSON として解釈できない、あるいは tag_name を
	 * 欠くとき、更新なしとして扱うこと.
	 */
	public function test_request_latest_release_returns_null_on_parse_failure() {
		$updater = $this->make_updater();
		$updater->stub_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'not json',
		);

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$result    = $updater->check_for_update( $transient );

		$this->assertSame( $transient, $result );
	}

	/**
	 * 一致するアセットが無いとき(extract_zip_url が null を返すとき)、
	 * 更新なしとして扱うこと.
	 */
	public function test_request_latest_release_returns_null_when_no_matching_asset() {
		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body( array( 'assets' => array() ) ) );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$result    = $updater->check_for_update( $transient );

		$this->assertSame( $transient, $result );
	}

	// -------------------------------------------------------------------------
	// plugin_info()
	// -------------------------------------------------------------------------

	/**
	 * plugin_info() が last_updated(published_at) と sections.description
	 * (Description ヘッダー由来)を返すこと(WPMAR 由来のフィールド).
	 */
	public function test_plugin_info_returns_last_updated_and_description() {
		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$result = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'fake-plugin' ) );

		$this->assertSame( '2026-01-01T00:00:00Z', $result->last_updated );
		$this->assertSame( 'A fake plugin used only by GitHubUpdaterTest.', $result->sections['description'] );
	}

	/**
	 * changelog が空のとき、フォールバック文言を返すこと(WPMAR 由来).
	 */
	public function test_plugin_info_formats_empty_changelog_with_fallback() {
		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body( array( 'body' => '' ) ) );

		$result = $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'fake-plugin' ) );

		$this->assertStringContainsString( 'GitHub リリースページをご確認ください。', $result->sections['changelog'] );
	}

	/**
	 * action や slug が一致しないとき、$result をそのまま返すこと.
	 */
	public function test_plugin_info_ignores_unrelated_requests() {
		$updater = $this->make_updater();

		$this->assertFalse( $updater->plugin_info( false, 'query_plugins', (object) array( 'slug' => 'fake-plugin' ) ) );
		$this->assertFalse( $updater->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'other-plugin' ) ) );
	}

	// -------------------------------------------------------------------------
	// after_update()
	// -------------------------------------------------------------------------

	/**
	 * このプラグインが更新されたとき、キャッシュと update_plugins
	 * トランジェントを削除すること.
	 */
	public function test_after_update_deletes_transients_when_this_plugin_updated() {
		$updater   = $this->make_updater();
		$cache_key = 'l2dwpghul_updater_' . md5( 'lunaluna/fake-plugin' );

		$GLOBALS['l2d_test_site_transients'][ $cache_key ]      = $this->release_body();
		$GLOBALS['l2d_test_site_transients']['update_plugins'] = 'anything';

		$updater->after_update(
			new stdClass(),
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'fake-plugin/fake-plugin.php' ),
			)
		);

		$this->assertArrayNotHasKey( $cache_key, $GLOBALS['l2d_test_site_transients'] );
		$this->assertArrayNotHasKey( 'update_plugins', $GLOBALS['l2d_test_site_transients'] );
	}

	/**
	 * 別プラグインの更新、またはプラグイン以外の更新では何もしないこと.
	 */
	public function test_after_update_does_nothing_for_other_plugin_or_action() {
		$updater   = $this->make_updater();
		$cache_key = 'l2dwpghul_updater_' . md5( 'lunaluna/fake-plugin' );

		$GLOBALS['l2d_test_site_transients'][ $cache_key ] = $this->release_body();

		$updater->after_update( new stdClass(), array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'other/other.php' ) ) );
		$this->assertArrayHasKey( $cache_key, $GLOBALS['l2d_test_site_transients'] );

		$updater->after_update( new stdClass(), array( 'action' => 'install', 'type' => 'plugin', 'plugins' => array( 'fake-plugin/fake-plugin.php' ) ) );
		$this->assertArrayHasKey( $cache_key, $GLOBALS['l2d_test_site_transients'] );
	}

	// -------------------------------------------------------------------------
	// フィルタ(enabled キルスイッチ・cache_ttl/backoff_ttl・filter_prefix 後方互換)
	// -------------------------------------------------------------------------

	/**
	 * l2dwpghul_updater_enabled フィルタで false を返すと、GitHub への
	 * リクエストが発生しないこと(キルスイッチ).
	 */
	public function test_is_enabled_kill_switch_via_common_filter() {
		$GLOBALS['l2d_test_filters']['l2dwpghul_updater_enabled'] = array(
			function () {
				return false;
			},
		);

		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$updater->check_for_update( $transient );

		$this->assertSame( array(), $updater->requested_urls );
	}

	/**
	 * filter_prefix を設定すると、旧フィルタ名(<prefix>_github_updater_enabled)
	 * でもキルスイッチが効くこと(後方互換).
	 */
	public function test_filter_prefix_enables_legacy_filter_name() {
		$GLOBALS['l2d_test_filters']['fauc_github_updater_enabled'] = array(
			function () {
				return false;
			},
		);

		$updater = $this->make_updater( array( 'filter_prefix' => 'fauc' ) );
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$updater->check_for_update( $transient );

		$this->assertSame( array(), $updater->requested_urls );
	}

	/**
	 * cache_ttl / backoff_ttl フィルタが適用され、キャッシュへの書き込みに
	 * 反映されること(get_site_transient のインメモリ・スタブは TTL を
	 * 記録しないため、フィルタが実際に呼ばれたことをコールバックの
	 * 呼び出し回数で検証する).
	 */
	public function test_cache_ttl_filter_is_applied() {
		$calls = 0;
		$GLOBALS['l2d_test_filters']['l2dwpghul_updater_cache_ttl'] = array(
			function ( $ttl ) use ( &$calls ) {
				++$calls;
				return $ttl;
			},
		);

		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$updater->check_for_update( $transient );

		$this->assertSame( 1, $calls );
	}

	/**
	 * filter_prefix を設定すると、旧フィルタ名(<prefix>_github_updater_cache_ttl)
	 * でも cache_ttl が上書きされること(後方互換).
	 */
	public function test_filter_prefix_enables_legacy_cache_ttl_filter() {
		$calls = 0;
		$GLOBALS['l2d_test_filters']['fauc_github_updater_cache_ttl'] = array(
			function ( $ttl ) use ( &$calls ) {
				++$calls;
				return $ttl;
			},
		);

		$updater = $this->make_updater( array( 'filter_prefix' => 'fauc' ) );
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$updater->check_for_update( $transient );

		$this->assertSame( 1, $calls );
	}

	/**
	 * filter_prefix を設定すると、旧フィルタ名(<prefix>_github_updater_backoff_ttl)
	 * でも backoff_ttl が上書きされること(後方互換). バックオフが実際に
	 * 発生する HTTP 失敗シナリオで、フィルタが呼ばれたことを検証する.
	 */
	public function test_filter_prefix_enables_legacy_backoff_ttl_filter() {
		$calls = 0;
		$GLOBALS['l2d_test_filters']['fauc_github_updater_backoff_ttl'] = array(
			function ( $ttl ) use ( &$calls ) {
				++$calls;
				return $ttl;
			},
		);

		$updater = $this->make_updater( array( 'filter_prefix' => 'fauc' ) );
		$updater->stub_response = new WP_Error();

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$updater->check_for_update( $transient );

		$this->assertSame( 1, $calls );
		$this->assertSame( array(), $GLOBALS['l2d_test_site_transients']['l2dwpghul_updater_' . md5( 'lunaluna/fake-plugin' )] );
	}

	// -------------------------------------------------------------------------
	// rename_source_directory() (新機能 4a)
	// -------------------------------------------------------------------------

	/**
	 * このプラグインの更新で、ZIP 内ルートディレクトリ名がスラッグと異なる
	 * とき、正しいスラッグ名にリネームすること.
	 */
	public function test_rename_source_directory_renames_when_plugin_matches_and_name_differs() {
		$updater = $this->make_updater();

		$result = $updater->rename_source_directory(
			'/tmp/wp-upgrade/wrong-dir-name/',
			'/tmp/wp-upgrade/wrong-dir-name/',
			new stdClass(),
			array( 'plugin' => 'fake-plugin/fake-plugin.php' )
		);

		$this->assertSame( '/tmp/wp-upgrade/fake-plugin/', $result );
		$this->assertSame(
			array(
				array(
					'source'      => '/tmp/wp-upgrade/wrong-dir-name/',
					'destination' => '/tmp/wp-upgrade/fake-plugin/',
					'overwrite'   => true,
				),
			),
			$GLOBALS['wp_filesystem']->moved_calls
		);
	}

	/**
	 * hook_extra['plugin'] が自分の basename と異なるとき、何もせず
	 * $source をそのまま返すこと(他プラグインの更新を壊さない).
	 */
	public function test_rename_source_directory_ignores_other_plugins() {
		$updater = $this->make_updater();

		$result = $updater->rename_source_directory(
			'/tmp/wp-upgrade/wrong-dir-name/',
			'/tmp/wp-upgrade/wrong-dir-name/',
			new stdClass(),
			array( 'plugin' => 'other-plugin/other-plugin.php' )
		);

		$this->assertSame( '/tmp/wp-upgrade/wrong-dir-name/', $result );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->moved_calls );
	}

	/**
	 * ディレクトリ名が既にスラッグと一致するとき、move() を呼ばずそのまま
	 * 返すこと.
	 */
	public function test_rename_source_directory_skips_when_name_already_matches() {
		$updater = $this->make_updater();

		$result = $updater->rename_source_directory(
			'/tmp/wp-upgrade/fake-plugin/',
			'/tmp/wp-upgrade/fake-plugin/',
			new stdClass(),
			array( 'plugin' => 'fake-plugin/fake-plugin.php' )
		);

		$this->assertSame( '/tmp/wp-upgrade/fake-plugin/', $result );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->moved_calls );
	}

	/**
	 * $source が WP_Error のとき、そのまま返すこと(素通し).
	 */
	public function test_rename_source_directory_passes_through_wp_error() {
		$updater = $this->make_updater();
		$error   = new WP_Error();

		$result = $updater->rename_source_directory(
			$error,
			'/tmp/wp-upgrade/wrong-dir-name/',
			new stdClass(),
			array( 'plugin' => 'fake-plugin/fake-plugin.php' )
		);

		$this->assertSame( $error, $result );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->moved_calls );
	}

	/**
	 * move() が失敗したとき、WP_Error を返すこと.
	 */
	public function test_rename_source_directory_returns_wp_error_on_move_failure() {
		$updater = $this->make_updater();
		$GLOBALS['wp_filesystem']->move_should_fail = true;

		$result = $updater->rename_source_directory(
			'/tmp/wp-upgrade/wrong-dir-name/',
			'/tmp/wp-upgrade/wrong-dir-name/',
			new stdClass(),
			array( 'plugin' => 'fake-plugin/fake-plugin.php' )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	// -------------------------------------------------------------------------
	// prerelease チャンネル(新機能 4b)
	// -------------------------------------------------------------------------

	/**
	 * allow_prerelease が既定(false)のとき、/releases/latest を叩くこと.
	 */
	public function test_allow_prerelease_defaults_to_releases_latest_endpoint() {
		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$updater->check_for_update( $transient );

		$this->assertSame(
			array( 'https://api.github.com/repos/lunaluna/fake-plugin/releases/latest' ),
			$updater->requested_urls
		);
	}

	/**
	 * allow_prerelease が true のとき、/releases(一覧)を叩くこと.
	 */
	public function test_allow_prerelease_true_uses_releases_list_endpoint() {
		$updater = $this->make_updater( array( 'allow_prerelease' => true ) );
		$updater->stub_response = $this->ok_response( array( $this->release_body() ) );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$updater->check_for_update( $transient );

		$this->assertSame(
			array( 'https://api.github.com/repos/lunaluna/fake-plugin/releases' ),
			$updater->requested_urls
		);
	}

	/**
	 * allow_prerelease が true のとき、一覧の先頭が draft でもスキップして
	 * 最初の非 draft リリース(prerelease でもよい)を採用すること.
	 */
	public function test_allow_prerelease_skips_draft_and_accepts_prerelease() {
		$updater = $this->make_updater( array( 'allow_prerelease' => true ) );
		$updater->stub_response = $this->ok_response(
			array(
				array_merge( $this->release_body( array( 'tag_name' => 'v2.0.0' ) ), array( 'draft' => true ) ),
				array_merge( $this->release_body( array( 'tag_name' => 'v1.5.0-beta' ) ), array( 'draft' => false, 'prerelease' => true ) ),
				array_merge( $this->release_body( array( 'tag_name' => 'v1.0.0' ) ), array( 'draft' => false, 'prerelease' => false ) ),
			)
		);

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$result    = $updater->check_for_update( $transient );

		$this->assertSame( '1.5.0-beta', $result->response['fake-plugin/fake-plugin.php']->new_version );
	}

	/**
	 * allow_prerelease が true で、一覧がすべて draft のとき、更新なしとして
	 * 扱うこと.
	 */
	public function test_allow_prerelease_returns_null_when_all_releases_are_draft() {
		$updater = $this->make_updater( array( 'allow_prerelease' => true ) );
		$updater->stub_response = $this->ok_response(
			array(
				array_merge( $this->release_body(), array( 'draft' => true ) ),
			)
		);

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$result    = $updater->check_for_update( $transient );

		$this->assertSame( $transient, $result );
	}

	// -------------------------------------------------------------------------
	// トークン認証(新機能 4c)
	// -------------------------------------------------------------------------

	/**
	 * token を設定すると、GitHub API リクエストに Authorization ヘッダーが
	 * 付くこと.
	 */
	public function test_token_adds_authorization_header_to_api_request() {
		$updater = $this->make_updater( array( 'token' => 'secret-token' ) );
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$updater->check_for_update( $transient );

		$this->assertSame(
			'Bearer secret-token',
			$updater->requested_calls[0]['args']['headers']['Authorization']
		);
	}

	/**
	 * token が空のとき、Authorization ヘッダーを付けないこと.
	 */
	public function test_no_token_omits_authorization_header() {
		$updater = $this->make_updater();
		$updater->stub_response = $this->ok_response( $this->release_body() );

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$updater->check_for_update( $transient );

		$this->assertArrayNotHasKey( 'Authorization', $updater->requested_calls[0]['args']['headers'] );
	}

	/**
	 * token を設定すると、package(zip_url)が asset.browser_download_url
	 * ではなく asset.url(Assets API URL)になること(Step A8 で実機確認済み
	 * の、プライベートリポジトリでのアセット取得方法).
	 */
	public function test_token_uses_asset_api_url_for_package() {
		$updater = $this->make_updater( array( 'token' => 'secret-token' ) );
		$updater->stub_response = $this->ok_response(
			$this->release_body(
				array(
					'assets' => array(
						array(
							'name'                 => 'fake-plugin.1.2.0.zip',
							'browser_download_url' => 'https://github.com/lunaluna/fake-plugin/releases/download/v1.2.0/fake-plugin.1.2.0.zip',
							'url'                  => 'https://api.github.com/repos/lunaluna/fake-plugin/releases/assets/999',
						),
					),
				)
			)
		);

		$transient = (object) array( 'checked' => array( 'fake-plugin/fake-plugin.php' => '1.0.0' ) );
		$result    = $updater->check_for_update( $transient );

		$this->assertSame(
			'https://api.github.com/repos/lunaluna/fake-plugin/releases/assets/999',
			$result->response['fake-plugin/fake-plugin.php']->package
		);
	}

	/**
	 * pre_download_package(): $reply が既に false 以外(他のフィルタが
	 * 短絡済み)のとき、そのまま返すこと.
	 */
	public function test_pre_download_package_passes_through_existing_reply() {
		$updater = $this->make_updater( array( 'token' => 'secret-token' ) );

		$result = $updater->pre_download_package(
			'/already/downloaded.zip',
			'https://api.github.com/repos/lunaluna/fake-plugin/releases/assets/999',
			new stdClass(),
			array( 'plugin' => 'fake-plugin/fake-plugin.php' )
		);

		$this->assertSame( '/already/downloaded.zip', $result );
	}

	/**
	 * pre_download_package(): token が空のとき、false を返し(コアの既定
	 * 処理に任せる)、独自ダウンロードを行わないこと.
	 */
	public function test_pre_download_package_returns_false_when_no_token() {
		$updater = $this->make_updater();

		$result = $updater->pre_download_package(
			false,
			'https://api.github.com/repos/lunaluna/fake-plugin/releases/assets/999',
			new stdClass(),
			array( 'plugin' => 'fake-plugin/fake-plugin.php' )
		);

		$this->assertFalse( $result );
		$this->assertSame( array(), $updater->requested_calls );
	}

	/**
	 * pre_download_package(): hook_extra['plugin'] が自分の basename と
	 * 異なるとき、false を返すこと(他プラグインの更新を壊さない).
	 */
	public function test_pre_download_package_ignores_other_plugins() {
		$updater = $this->make_updater( array( 'token' => 'secret-token' ) );

		$result = $updater->pre_download_package(
			false,
			'https://api.github.com/repos/lunaluna/fake-plugin/releases/assets/999',
			new stdClass(),
			array( 'plugin' => 'other-plugin/other-plugin.php' )
		);

		$this->assertFalse( $result );
		$this->assertSame( array(), $updater->requested_calls );
	}

	/**
	 * pre_download_package(): token 設定時、対象プラグインの更新であれば
	 * Authorization / Accept ヘッダー付きでダウンロードし、一時ファイル
	 * パスを返すこと.
	 */
	public function test_pre_download_package_downloads_with_auth_headers() {
		$updater = $this->make_updater( array( 'token' => 'secret-token' ) );
		$updater->stub_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'zip-bytes',
		);

		$package = 'https://api.github.com/repos/lunaluna/fake-plugin/releases/assets/999';
		$result  = $updater->pre_download_package(
			false,
			$package,
			new stdClass(),
			array( 'plugin' => 'fake-plugin/fake-plugin.php' )
		);

		$this->assertIsString( $result );
		$this->assertCount( 1, $updater->requested_calls );
		$this->assertSame( $package, $updater->requested_calls[0]['url'] );
		$this->assertSame( 'Bearer secret-token', $updater->requested_calls[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'application/octet-stream', $updater->requested_calls[0]['args']['headers']['Accept'] );
	}

	/**
	 * pre_download_package(): ダウンロードが非 200 のとき、WP_Error を
	 * 返すこと.
	 */
	public function test_pre_download_package_returns_wp_error_on_non_200() {
		$updater = $this->make_updater( array( 'token' => 'secret-token' ) );
		$updater->stub_response = array(
			'response' => array( 'code' => 404 ),
			'body'     => '',
		);

		$result = $updater->pre_download_package(
			false,
			'https://api.github.com/repos/lunaluna/fake-plugin/releases/assets/999',
			new stdClass(),
			array( 'plugin' => 'fake-plugin/fake-plugin.php' )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
	}
}
