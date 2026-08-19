<?php
/**
 * PHPUnit bootstrap.
 *
 * WP のテストスイートは使わず、テスト対象のファイルが読み込み時に触れる
 * WP 関数だけを最小限にスタブする.
 *
 * @package L2dWpGithubUpdateLib
 */

/**
 * add_action() のスタブ. loader.php が読み込み時に呼ぶ.
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
