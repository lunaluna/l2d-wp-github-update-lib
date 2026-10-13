<?php
/**
 * WP_Error のスタブ.
 *
 * @package L2dWpGithubUpdateLib
 */

/**
 * WP_Error のスタブ. is_wp_error() の判定対象に加え、エラーコードとメッセージを
 * 取り出せる(トークンがメッセージに混入しないことの検証に使う).
 */
class WP_Error {

	/**
	 * @var string
	 */
	private $code;

	/**
	 * @var string
	 */
	private $message;

	/**
	 * @var mixed
	 */
	private $data;

	/**
	 * @param string $code    エラーコード.
	 * @param string $message エラーメッセージ.
	 * @param mixed  $data    付加データ.
	 */
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	/**
	 * @return string エラーコード.
	 */
	public function get_error_code() {
		return $this->code;
	}

	/**
	 * @return string エラーメッセージ.
	 */
	public function get_error_message() {
		return $this->message;
	}

	/**
	 * @return mixed 付加データ.
	 */
	public function get_error_data() {
		return $this->data;
	}
}
