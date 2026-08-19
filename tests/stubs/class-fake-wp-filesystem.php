<?php
/**
 * WP_Filesystem のテスト用フェイク実装.
 *
 * @package L2dWpGithubUpdateLib
 */

/**
 * rename_source_directory() のテストで使う、move() の呼び出しを記録するだけの
 * 最小フェイク実装.
 */
class FakeWpFilesystem {

	/**
	 * move() に渡された引数の記録.
	 *
	 * @var array
	 */
	public $moved_calls = array();

	/**
	 * move() の戻り値を強制的に false にするかどうか.
	 *
	 * @var bool
	 */
	public $move_should_fail = false;

	/**
	 * @param string $source      移動元.
	 * @param string $destination 移動先.
	 * @param bool   $overwrite   上書きするか.
	 * @return bool
	 */
	public function move( $source, $destination, $overwrite = false ) {
		$this->moved_calls[] = array(
			'source'      => $source,
			'destination' => $destination,
			'overwrite'   => $overwrite,
		);

		return ! $this->move_should_fail;
	}
}
