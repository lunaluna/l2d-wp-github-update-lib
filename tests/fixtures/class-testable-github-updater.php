<?php
/**
 * テスト用サブクラスファイル.
 *
 * @package L2dWpGithubUpdateLib
 */

require_once dirname( __DIR__, 2 ) . '/class-l2d-github-updater.php';

/**
 * L2dwpghul_GitHub_Updater のテスト用サブクラス. http_get() を固定レスポンスで
 * オーバーライドし、実際の HTTP 通信を発生させない.
 */
class TestableGitHubUpdater extends L2dwpghul_GitHub_Updater {

	/**
	 * http_get() が返す固定レスポンス.
	 *
	 * @var array|WP_Error|null
	 */
	public $stub_response;

	/**
	 * http_get() に渡された URL の記録.
	 *
	 * @var string[]
	 */
	public $requested_urls = array();

	/**
	 * @param string $url  URL.
	 * @param array  $args リクエスト引数(未使用).
	 * @return array|WP_Error
	 */
	protected function http_get( $url, $args ) {
		$this->requested_urls[] = $url;
		return $this->stub_response;
	}
}
