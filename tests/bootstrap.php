<?php
/**
 * PHPUnit bootstrap.
 *
 * WP のテストスイートは使わず、テスト対象のファイルが読み込み時・実行時に
 * 触れる WP 関数だけを最小限にスタブする.
 *
 * @package L2dWpGithubUpdateLib
 */

define( 'ABSPATH', __DIR__ . '/' );

/**
 * add_action() のスタブ. 実際のフック登録は行わず、呼ばれたことだけを許容する.
 *
 * @param string   $hook_name     フック名(未使用).
 * @param callable $callback      コールバック(未使用).
 * @param int      $priority      優先度(未使用).
 * @param int      $accepted_args 引数の数(未使用).
 * @return true 常に true.
 */
function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	return true;
}

/**
 * add_filter() のスタブ. apply_filters() から実際に呼び出せるよう登録する.
 *
 * @param string   $hook_name     フック名.
 * @param callable $callback      コールバック.
 * @param int      $priority      優先度(未使用).
 * @param int      $accepted_args 引数の数(未使用).
 * @return true 常に true.
 */
function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['l2d_test_filters'][ $hook_name ][] = $callback;
	return true;
}

/**
 * apply_filters() のスタブ. add_filter() で登録されたコールバックを順に適用する.
 *
 * @param string $hook_name フック名.
 * @param mixed  $value     フィルタ対象の値.
 * @param mixed  ...$args   追加引数.
 * @return mixed フィルタ後の値.
 */
function apply_filters( $hook_name, $value, ...$args ) {
	foreach ( $GLOBALS['l2d_test_filters'][ $hook_name ] ?? array() as $callback ) {
		$value = call_user_func( $callback, $value, ...$args );
	}
	return $value;
}

/**
 * get_file_data() のスタブ. WordPress コアの実装を簡略化して再現し、
 * ファイル先頭のコメントヘッダーから値を読み取る.
 *
 * @param string $file            ファイルパス.
 * @param array  $default_headers 取得したいヘッダーのキー => ラベル.
 * @return string[] キーごとの値.
 */
function get_file_data( $file, $default_headers ) {
	$file_data = file_get_contents( $file );
	$file_data = str_replace( "\r", "\n", $file_data );

	$result = array();
	foreach ( $default_headers as $field => $regex ) {
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] ) {
			$result[ $field ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
		} else {
			$result[ $field ] = '';
		}
	}

	return $result;
}

/**
 * trailingslashit() のスタブ.
 *
 * @param string $string 対象の文字列.
 * @return string 末尾に / を1つだけ付けた文字列.
 */
function trailingslashit( $string ) {
	return rtrim( (string) $string, '/\\' ) . '/';
}

/**
 * untrailingslashit() のスタブ.
 *
 * @param string $string 対象の文字列.
 * @return string 末尾の / を取り除いた文字列.
 */
function untrailingslashit( $string ) {
	return rtrim( (string) $string, '/\\' );
}

/**
 * wp_json_encode() のスタブ.
 *
 * @param mixed $data エンコードするデータ.
 * @return string|false JSON 文字列.
 */
function wp_json_encode( $data ) {
	return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- テスト用スタブの実装そのもの.
}

/**
 * wp_tempnam() のスタブ. 実ファイルは作らず、識別可能なダミーパスを返す.
 *
 * @param string $filename 元になるファイル名.
 * @param string $dir      ディレクトリ(未使用).
 * @return string ダミーの一時ファイルパス.
 */
function wp_tempnam( $filename = '', $dir = '' ) {
	return '/tmp/l2d-test-tempfile-' . md5( (string) $filename );
}

/**
 * plugin_basename() のスタブ. 「ディレクトリ名/ファイル名」形式を返す簡易実装.
 *
 * @param string $file プラグインのメインファイルパス.
 * @return string 簡易版 basename.
 */
function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

/**
 * esc_html() のスタブ.
 *
 * @param string $text エスケープ対象.
 * @return string エスケープ後の文字列.
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

/**
 * esc_url() のスタブ. テストでは加工せずそのまま返す.
 *
 * @param string $url URL.
 * @return string URL.
 */
function esc_url( $url ) {
	return (string) $url;
}

/**
 * esc_html__() のスタブ. テストでは翻訳せずそのまま返す.
 *
 * @param string $text   翻訳対象の文字列.
 * @param string $domain テキストドメイン(未使用).
 * @return string $text をそのまま返す.
 */
function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

/**
 * _doing_it_wrong() のスタブ. テスト出力を汚さないよう何もしない.
 *
 * @param string $function_name 関数名(未使用).
 * @param string $message       メッセージ(未使用).
 * @param string $version       バージョン(未使用).
 * @return void
 */
function _doing_it_wrong( $function_name, $message, $version ) {
}

require_once __DIR__ . '/stubs/class-wp-error.php';

/**
 * is_wp_error() のスタブ.
 *
 * @param mixed $thing 判定対象.
 * @return bool WP_Error のインスタンスなら true.
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * wp_remote_retrieve_response_code() のスタブ.
 *
 * @param array $response テスト用の疑似レスポンス配列.
 * @return int ステータスコード.
 */
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

/**
 * wp_remote_retrieve_body() のスタブ.
 *
 * @param array $response テスト用の疑似レスポンス配列.
 * @return string ボディ文字列.
 */
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? (string) $response['body'] : '';
}

/**
 * wp_remote_get() のスタブ. テストでは http_get() をサブクラスでオーバーライド
 * するため通常は呼ばれないが、念のため未スタブであることが分かるエラーを返す.
 *
 * @param string $url  URL(未使用).
 * @param array  $args リクエスト引数(未使用).
 * @return WP_Error 常にエラー.
 */
function wp_remote_get( $url, $args = array() ) {
	return new WP_Error( 'not_stubbed', 'wp_remote_get is not stubbed in tests.' );
}

/**
 * サイトトランジエントのインメモリ・スタブ.
 *
 * @param string $key トランジエントキー.
 * @return mixed 保存値。無ければ false.
 */
function get_site_transient( $key ) {
	return $GLOBALS['l2d_test_site_transients'][ $key ] ?? false;
}

/**
 * サイトトランジエントのインメモリ・スタブ.
 *
 * @param string $key        トランジエントキー.
 * @param mixed  $value      保存する値.
 * @param int    $expiration 有効期限秒数(未使用).
 * @return true 常に true.
 */
function set_site_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['l2d_test_site_transients'][ $key ] = $value;
	return true;
}

/**
 * サイトトランジエントのインメモリ・スタブ.
 *
 * @param string $key トランジエントキー.
 * @return true 常に true.
 */
function delete_site_transient( $key ) {
	unset( $GLOBALS['l2d_test_site_transients'][ $key ] );
	return true;
}
