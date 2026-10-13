<?php
/**
 * テスト用サブクラスファイル.
 *
 * @package L2dWpGithubUpdateLib
 */

require_once dirname( __DIR__, 2 ) . '/dist/class-l2d-github-updater.php';

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
	 * http_get() が呼び出し順に返すレスポンスの待ち行列. 空でなければ stub_response より優先して
	 * 先頭から1件ずつ取り出す. 複数回の HTTP 呼び出し(リダイレクトを手動で追う処理など)を
	 * 1回ずつ別のレスポンスで検証するために使う. 既存テストは stub_response のまま動く.
	 *
	 * @var array<int, array|WP_Error>
	 */
	public $stub_responses = array();

	/**
	 * http_get() に渡された URL の記録.
	 *
	 * @var string[]
	 */
	public $requested_urls = array();

	/**
	 * http_get() に渡された url/args の組の記録.
	 *
	 * @var array[]
	 */
	public $requested_calls = array();

	/**
	 * @param string $url  URL.
	 * @param array  $args リクエスト引数.
	 * @return array|WP_Error
	 */
	protected function http_get( $url, $args ) {
		$this->requested_urls[]  = $url;
		$this->requested_calls[] = array(
			'url'  => $url,
			'args' => $args,
		);

		// 待ち行列があれば先頭を、無ければ固定レスポンスを使う.
		$response = $this->stub_responses ? array_shift( $this->stub_responses ) : $this->stub_response;

		// stream + filename が指定されていれば、実際の WordPress と同じく本文をファイルへ書き出す.
		// ステータスに関係なく書く(3xx や 4xx でも小さな本文が書かれるため). WP_Error のときは書かない.
		if ( is_array( $response ) && ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], isset( $response['body'] ) ? (string) $response['body'] : '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- テスト用スタブ.
		}

		return $response;
	}
}
