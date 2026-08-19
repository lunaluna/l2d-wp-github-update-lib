# l2d-wp-github-update-lib

WordPress プラグイン向け GitHub Releases セルフホスト更新機構の共有ライブラリ。

wp.org に掲載していないプラグインでも、GitHub Releases を版元として管理画面から通常の更新フロー(通知 → ワンクリック更新)を提供する。Packagist には出さず、`git subtree` で各プラグインの `lib/l2d-updater/` に同梱して配布する。

## 使い方

利用側プラグインのメインファイルで、ローダーを読み込んでから設定を登録する。

```php
$l2dwpghul_updater_register = require plugin_dir_path( __FILE__ ) . 'lib/l2d-updater/loader.php';
$l2dwpghul_updater_register( array(
	'plugin_file' => __FILE__,
	'github_repo' => 'lunaluna/your-plugin-repo',
) );
```

`loader.php` は `require`(`require_once` ではない)する。戻り値はこのコピー固有のバージョンとファイルパスをクロージャでキャプチャした登録関数で、それを呼ぶと初めて設定が登録される。複数のプラグインが異なるバージョンの同梱コピーを持っていても、実行時に最も新しいバージョンのコピーだけが起動する(バージョン交渉)。

グローバル定数(例 `L2DWPGHUL_UPDATER_LIB_VERSION`)でバージョンを渡さない理由: 複数コピーが同じ定数名を `define` しようとすると、最初に読み込まれたコピーの値で固定され、後続のコピーが自分の実際のバージョンを報告できなくなり、バージョン交渉が壊れるため。

### 設定キー

| キー | 必須 | 既定値 |
|---|---|---|
| `plugin_file` | ✅ | — |
| `github_repo` | ✅ | — |
| `slug` | | `dirname( plugin_basename( $plugin_file ) )` |
| `name` | | ヘッダー `Plugin Name` |
| `author` | | ヘッダー `Author` を `Author URI` でリンク化 |
| `cache_key` | | `'l2dwpghul_updater_' . md5( $github_repo )` |
| `filter_prefix` | | なし(ライブラリ共通フィルタのみ) |
| `cache_ttl` | | `21600` |
| `backoff_ttl` | | `1800` |
| `allow_prerelease` | | `false` |
| `token` | | `''` |
| `asset_pattern` | | `$slug` 前方一致 + `.zip` 後方一致(callable も可) |

`allow_prerelease` を `true` にすると、`/releases/latest`(prerelease・draft をどちらも除外)ではなく `/releases` 一覧を取得し、`draft` でない先頭のリリース(prerelease を含む)を最新として扱う。`/releases` は公開日時降順で返るため、先頭から順に draft でないものを探す。

`token` を設定すると、プライベートリポジトリからの更新に対応する。GitHub API リクエストに `Authorization: Bearer <token>` を付け、配布アセットの URL は `asset.browser_download_url` ではなく `asset.url`(Assets API のエンドポイント)を使う。これは `browser_download_url`(github.com の配布ドメイン)が `Authorization` ヘッダーを認識せず 404 になるためで、Assets API URL に `Accept: application/octet-stream` を付ける方式のみがプライベートリポジトリで機能することを実機で確認している。

WordPress コアの `download_url()` にはヘッダーを渡す手段が無いため、`upgrader_pre_download` フィルタでダウンロード処理自体を短絡し、`token` 付きで自前ダウンロードして一時ファイルパスを返す。`token` は `wp-config.php` の定数から渡すこと(DB に既定で保存する設計にはしていない)。

`basename` / `version` / `requires` / `requires_php` / `tested` / `name` / `author` は `plugin_file` から `plugin_basename()` と `get_file_data()` で導出される。

### フィルタ

ライブラリ共通名を正とし、第 2 引数に `$slug` を渡す。`filter_prefix` を設定すると旧名でも同じ値をフィルタできる(後方互換用)。

```php
$enabled = apply_filters( 'l2dwpghul_updater_enabled', true, $slug );
if ( $filter_prefix ) {
	$enabled = apply_filters( "{$filter_prefix}_github_updater_enabled", $enabled );
}
```

対象: `enabled` / `cache_ttl` / `backoff_ttl`。

### 更新の入口

`Update URI: false` をヘッダーに指定した上で使うことを前提とする。`pre_set_site_transient_update_plugins` / `plugins_api` / `upgrader_process_complete` を直接フックし、wp.org 公式ルート(`update_plugins_{$hostname}`)は使わない。

## 契約の凍結事項

以下は初版で固定し、後方互換のため変更しない。

1. `l2dwpghul_updater_register( $version, $class_file, array $config )` の引数を増やさない。拡張は `$config` のキー追加で行う
2. ローダーは `$config` を検証も加工もせず素通しする
3. クラス名は `L2dwpghul_GitHub_Updater` 固定。コンストラクタは `array $config` 1 引数
4. `plugins_loaded` 優先度 `-100` で起動する
5. `loader.php` は `require` の戻り値として `array $config` を受け取る callable を返す。この形を変えない(グローバル定数は使わない)

## 開発

```sh
composer install
composer lint      # phpcs
composer lint:fix  # phpcbf
composer analyse   # phpstan
composer test      # phpunit
```

## ライセンス

GPL-2.0-or-later
