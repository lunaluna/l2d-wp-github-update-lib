<?php
/**
 * WP_Error のスタブ.
 *
 * @package L2dWpGithubUpdateLib
 */

/**
 * WP_Error のスタブ. is_wp_error() の判定対象として使うだけの空実装.
 */
class WP_Error {

	/**
	 * @param string $code    エラーコード(未使用).
	 * @param string $message エラーメッセージ(未使用).
	 * @param mixed  $data    付加データ(未使用).
	 */
	public function __construct( $code = '', $message = '', $data = '' ) {
	}
}
