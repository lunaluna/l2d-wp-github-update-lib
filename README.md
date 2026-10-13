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
| `asset_pattern` | | `{$slug}.{タグの版}.zip` との完全一致(callable も可) |

`cache_key` / `filter_prefix` は、既存の独自更新機構からこのライブラリへ移行する際に使う。既存実装が使っていたトランジェントキー名・フィルタ名をそのまま指定すれば、移行後も既存のキャッシュや `add_filter` 済みのコードをそのまま引き継げる(省略するとライブラリ共通の名前になり、移行前のキャッシュ・フィルタとは別物として動く)。フィルタの詳細は [フィルタ](#フィルタ) を参照。

`asset_pattern` は、Release のアセットから本体 ZIP を選ぶ規則を決める。

- **文字列(既定は `$slug`)**: アセット名が `{パターン}.{タグの版}.zip` と**完全一致**するものだけを採用する。タグの版は `tag_name` の先頭の `v` を除いたもの(`v1.2.0` → `1.2.0`)。正規表現の semver ではなく文字列の完全一致なので、`1.0.0-RC14` や `1.5.2.1` のような semver に収まらないタグでも、アセット名が同じ版で作られていれば通る。アセット名の版がタグと食い違う Release は、更新なしとして扱われる(別の版の ZIP を掴まないための検証を兼ねる)。`my-plugin-dev.zip` や `my-plugin.1.2.0.zip.sha256` のような、スラッグで始まるだけの別名は採用しない
- **callable**: `extract_zip_url()` がアセットごとに `$name`(アセットのファイル名、例 `my-plugin.1.0.0.zip`)を渡して呼び出し、戻り値が真になったアセットを候補にする。命名規約が違う場合のための逃げ道なので、版の一致は検証しない。**候補がちょうど 1 件のときだけ**採用し、複数に一致したら更新なしとして扱う(1.3.0 で変更。それまでは先頭を採用していた)

```php
'asset_pattern' => function ( $name ) {
	return 'my-plugin.' === substr( $name, 0, 10 ) && '.zip' === substr( $name, -4 ) && 'vendor-pdf.zip' !== $name;
},
```

`allow_prerelease` を `true` にすると、`/releases/latest`(prerelease・draft をどちらも除外)ではなく `/releases` 一覧を取得し、`draft` でない先頭のリリース(prerelease を含む)を最新として扱う。`/releases` は公開日時降順で返るため、先頭から順に draft でないものを探す。

`token` を設定すると、プライベートリポジトリからの更新に対応する。GitHub API リクエストに `Authorization: Bearer <token>` を付け、配布アセットの URL は `asset.browser_download_url` ではなく `asset.url`(Assets API のエンドポイント)を使う。これは `browser_download_url`(github.com の配布ドメイン)が `Authorization` ヘッダーを認識せず 404 になるためで、Assets API URL に `Accept: application/octet-stream` を付ける方式のみがプライベートリポジトリで機能することを実機で確認している。

WordPress コアの `download_url()` にはヘッダーを渡す手段が無いため、`upgrader_pre_download` フィルタでダウンロード処理自体を短絡し、`token` 付きで自前ダウンロードして一時ファイルパスを返す。このとき、トークンの漏えいを防ぐために次の制限をかけている(1.3.0 から)。

- トークンを付けて送るのは `https://api.github.com/repos/{github_repo}/releases/assets/{数値 ID}` だけ。ポート・ユーザー情報・クエリ付きの URL や、別のリポジトリ・別のホストの URL には、トークンも通信も送らずに中止する
- Assets API は、署名付きの配布 URL へリダイレクトで返す。WordPress の HTTP クライアント(Requests)はリダイレクト時に元のリクエストヘッダー(`Authorization` を含む)をそのまま次へ渡すため、1 回目はリダイレクトを追わずに受け取り、`Location` を検証したうえで、`Authorization` を付けずに取得し直す
- ダウンロードに失敗したとき(通信エラー・200 以外・0 バイト)は、一時ファイルを削除する

#### トークンの扱い(必ず守ること)

- **トークンをプラグインのソースに直書きしない。** 配布 ZIP に入り、そのプラグインを入れたすべてのサイトに漏れる。`wp-config.php` に定数として定義し、設定キーにはその定数を渡す(DB には保存しない)

  ```php
  // wp-config.php
  define( 'MY_PLUGIN_GITHUB_TOKEN', 'github_pat_...' );

  // プラグイン側
  'token' => defined( 'MY_PLUGIN_GITHUB_TOKEN' ) ? MY_PLUGIN_GITHUB_TOKEN : '',
  ```

- **最小権限にする。** GitHub の Fine-grained personal access token を使い、対象リポジトリを更新元の 1 つだけにして、リポジトリの権限は Contents の読み取りのみにする
- **有効期限を設定する。** 期限切れ・失効後は更新の確認が失敗してバックオフに入り、**更新通知が静かに止まる**(画面にエラーは出ない)。期限の前にローテーションする: ① 新しいトークンを発行する → ② `wp-config.php` の定数を差し替える → ③ 古いトークンを失効する
- **漏えいが疑われたら、GitHub 側で即座に失効する。** このライブラリはトークンを保存せず `wp-config.php` の値を使うだけなので、失効すれば使えなくなる。その後、新しいトークンを発行して定数を差し替える
- WordPress の仕組み上、他のプラグインが `http_api_debug` アクションや `http_request_args` フィルタなどでリクエストヘッダー(`Authorization` を含む)を読める。これはこのライブラリでは防げない。信頼できないプラグインを同じサイトに入れない

`basename` / `version` / `requires` / `requires_php` / `tested` / `name` / `author` は `plugin_file` から `plugin_basename()` と `get_file_data()` で導出される。

### フィルタ

ライブラリ共通名を正とし、第 2 引数に `$slug` を渡す。`filter_prefix` を設定すると旧名でも同じ値をフィルタできる(後方互換用)。

```php
$enabled = apply_filters( 'l2dwpghul_updater_enabled', true, $slug );
if ( $filter_prefix ) {
	$enabled = apply_filters( "{$filter_prefix}_github_updater_enabled", $enabled );
}
```

対象は次の3つ。いずれも GitHub から最新リリース情報を取得する処理(`fetch_latest_release()`)の内側で適用されるため、`add_filter` するだけで即座に挙動が変わる(再登録や設定変更は不要)。

| フィルタ | 既定値 | できること |
|---|---|---|
| `l2dwpghul_updater_enabled` | `true` | `false` を返すと更新チェック自体を止めるキルスイッチ。GitHub API へのリクエストも発生しなくなる。特定環境(ステージングなど)や条件下で更新チェックを止めたい場合に使う |
| `l2dwpghul_updater_cache_ttl` | 設定キー `cache_ttl`(既定 `21600` 秒 = 6時間) | GitHub から正常に取得できた最新リリース情報をサイトトランジェントにキャッシュする秒数。短くすると更新通知が早く反映される代わりに GitHub API へのリクエスト頻度が上がる |
| `l2dwpghul_updater_backoff_ttl` | 設定キー `backoff_ttl`(既定 `1800` 秒 = 30分) | GitHub API へのリクエストが失敗したときに、その失敗結果を保持しておく秒数(バックオフ)。この間は再リクエストせず更新チェックを `null` で返す。GitHub API の未認証レート制限(実測 60 req/h)に配慮した設計 |

### 更新の入口

`Update URI: false` をヘッダーに指定した上で使うことを前提とする。`pre_set_site_transient_update_plugins` / `plugins_api` / `upgrader_process_complete` を直接フックし、wp.org 公式ルート(`update_plugins_{$hostname}`)は使わない。

## 設計上の注意(信頼境界)

- **より高い版を名乗るコピーが、全プラグインの設定を受け取る。** バージョン交渉では、同じサイトに入っているすべての同梱コピーのうち最も高い版のコピーが、登録されたすべてのプラグインの `$config`(`token` を含む)をインスタンス化する。同梱コピーはサイトのプラグインディレクトリにある PHP コードなので、そこに書き込める権限があれば何でもできるという前提と同じ境界の話だが、「自分のプラグインが同梱していない版のコードが `token` を受け取る」ことは理解しておく
- 登録された設定は、グローバル変数 `$l2dwpghul_updater_registry['configs']` に入る。同じ PHP プロセスで動く他のコードからも、`token` を含めて読める(PHP の仕組み上避けられない)
- 1.3.0 の厳格化(アセット名の完全一致・ダウンロード先 URL の検証・展開後のメインファイル確認)は、**まだ更新していない利用側プラグインの設定にも、1.3.0 のコピーが採用された時点で適用される**。サイト内の 1 つでも 1.3.0 を同梱していれば、全プラグインに効く
- フォーク・改変して使う場合は、関数名・クラス名・グローバル変数名のプレフィックスと、クラス名 `L2dwpghul_GitHub_Updater` を変える。変えないと、元のライブラリのコピーとバージョン交渉で混ざる

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

## タグ慣習

このリポジトリのタグに **v 接頭辞は付けない**(素の semver。利用側プラグインと同じ慣習)。`v1.0.4` 以前は v 付きだった歴史的経緯があるが、既存タグは利用側プラグインが現に参照しているため削除しない。

## 配布物(dist)

`git subtree pull` はリポジトリ全体を取り込むため、配布対象を `dist/` に集約している。リリース時に `release.yml` の `publish-dist` job が `git subtree split --prefix=dist` で配布専用の履歴(`dist` ブランチ・`dist-X.Y.Z` タグ)を自動生成し、利用側プラグインはこの `dist-X.Y.Z` タグを subtree pull の参照先にする。ベンダーコピーは以下の4ファイルのみになり、`tests/` / `.github/` / `composer.*` / `README.md` / `CLAUDE.md` などの開発用ファイルは配布されない。

```text
lib/l2d-updater/
├── loader.php
├── class-l2d-github-updater.php
├── LICENSE
└── bin/build-zip.sh
```

## `plugin-release.yml`(reusable workflow)

単純な構成の利用側プラグイン(FAUC / get-patterns-by-pattern-name / flamingo-csv-sjis-exporter)が `workflow_call` で使うリリース用ワークフロー。WPMAR のような複雑な `release.yml`(フォント生成・`vendor-pdf.zip` 生成等)を持つリポジトリはこれを使わず、composite actions を個別に使う。

### 入力

| 入力 | 必須 | 既定値 | 用途 |
|---|---|---|---|
| `slug` | ✅ | — | プラグインスラッグ |
| `version_files` | ✅ | — | `verify-version` action に渡す `"path:pattern"` の複数行 |
| `notes_source` | ✅ | — | `release-notes` action に渡す抽出元ファイル(`readme.txt` など) |
| `php_version` | | `''` | 非空なら `setup-php` を実行する PHP バージョン(例 `8.2`)。composer で `vendor/` を作る利用側向け |
| `extra_assets` | | `''` | 本体 ZIP に加えて Release へ添付するファイルの複数行リスト。`"path"` または `"path#label"`(`gh release create` の記法) |
| `draft` | | `false` | `true` なら draft として作成する。検証後に `gh release edit <tag> --draft=false` で本公開する |

`version_files` のパターンに一致した行からの値抽出は次の優先順で行う: (1) シングルクォート区切りの値(例 `define('FAUC_VERSION', '1.9.0')`)、(2) ダブルクォート区切りの値(例 `composer.json` の `"version": "1.5.0",`)、(3) どちらも無ければパターン直後のコロン以降(空白除去、例 `Stable tag: 1.9.0`)。

### 2つの前処理フック

利用側がファイルを置くと、いずれも `bash` で明示起動されるためビルド前に自動実行される(実行権限は不要):

- **`bin/build-zip.pre.sh`** — 汎用ビルダー(`build-zip.sh`)がステージング**前**に実行する。**ZIP に同梱する**生成物を作る(委譲していれば `dist/bin/build-zip.sh` から発火)
- **`bin/release.pre.sh`** — `plugin-release.yml` が `build-zip` の**前**に実行する。**Release に添付する追加アセット**(`extra_assets` で指定するもの)や、ZIP に同梱する生成物を作る

両者は「誰が呼ぶか」が異なる: `build-zip.pre.sh` はビルダー自体(ローカル実行でも `plugin-release.yml` 経由でも走る)、`release.pre.sh` は `plugin-release.yml` のジョブ内のみで走る。

## リリース手順

このリポジトリには、タグ push で発火する `release.yml`(`verify-loader-version` / `publish-dist` の2 job)がある。`plugin-release.yml` はこれとは別物で、他リポジトリが `workflow_call` で使う reusable workflow である。リリースは以下の手順を**必ず順番通りに**踏む。

1. `dist/loader.php` の `l2dwpghul_updater_register()` 第一引数(自己申告バージョン、例 `'1.0.3'`)を新しいバージョンに手動で書き換える。他の変更と混ぜず、専用コミットにする(過去の実績: `loader.phpの自己申告バージョンを1.0.3へ更新`)
2. 横断検索して、他に現在バージョンを指す表記が残っていないか確認する(`grep -rn "<旧バージョン>" . --exclude-dir=vendor --exclude-dir=.git`)
3. **`plugin-release.yml` の composite action 参照タグ(`verify-version` / `build-zip` / `release-notes`、いずれも `@X.Y.Z`)を新バージョンへ更新する。** reusable workflow 内の相対パス参照(`uses: ./.github/actions/...`)は呼び出し元リポジトリの `$GITHUB_WORKSPACE` を指すため使えず(実機・公式情報で確認済み)、タグ固定運用が必須。忘れやすい箇所なので毎回チェックする
4. PR を作成し、CI(PHP 構文チェック・PHPCS・PHPStan・PHPUnit・build-zip スモークテスト)を通してから `main` にマージする
5. タグを打って push する(**v 接頭辞なし**): `git tag X.Y.Z && git push origin X.Y.Z`
6. **`release.yml` の両 job(`verify-loader-version` / `publish-dist`)が成功することを確認する** — タグ push で自動起動し、`dist/loader.php` の自己申告バージョンとタグが一致しないと `verify-loader-version` が `::error::` で失敗する。このリポジトリでタグを打つ際の**唯一の自動ゲート**なので、ここで失敗したら Release を作らず先に修正する。`publish-dist` はその後 `dist-X.Y.Z` タグを自動生成する
7. `git ls-tree -r --name-only dist-X.Y.Z` が上記4ファイルのみを返すことを確認する
8. `gh release create X.Y.Z --title X.Y.Z --notes "..."` で GitHub Release を手動作成する(ライブラリであり配布用 ZIP は不要なので、ビルド・アセット添付は行わない)


**外部 Actions の固定と ZIP 検査(1.3.0 から)**: `ci.yml` / `release.yml` / `plugin-release.yml` の外部 Actions(`actions/checkout` など)はコミット SHA で固定している(行末の `# vX.Y.Z` は版のコメント)。更新は Dependabot(`.github/dependabot.yml`、週次)の PR で行い、SHA は `git ls-remote --tags <リポジトリ>` で確認する(注釈付きタグは `^{}` の行がコミット)。`build-zip` の composite action は、生成した ZIP に `{slug}/.git/` / `{slug}/.github/` / `{slug}/tests/` / `{slug}/node_modules/` や、`.env` で終わるファイルが入っていると失敗する(`vendor/` 配下の依存パッケージ由来のファイルは対象外)。失敗したら、利用側の `.distignore` を直してからリリースし直す。

### 利用側プラグインへの反映

**1.1.0 への移行手順とリポジトリ別の作業一覧は [docs/consumer-integration-1.1.0.md](docs/consumer-integration-1.1.0.md) にまとめてある**(利用側リポジトリで作業するときはこれを読む)。

このライブラリをリリースしただけでは、同梱している各プラグイン(FAUC など)には自動反映されない。追従する場合は、利用側プラグインのリポジトリで以下の2箇所を更新する:

- `release.yml` の `uses: lunaluna/l2d-wp-github-update-lib/.github/workflows/plugin-release.yml@X.Y.Z` の参照タグ
- `git subtree pull --prefix=lib/l2d-updater https://github.com/lunaluna/l2d-wp-github-update-lib.git dist-X.Y.Z --squash` でベンダーコピー(`lib/l2d-updater/`)を更新

**初回のみ**: 既存のベンダーコピーは `main` 系の履歴から取り込んだものであり、`dist` 系タグからの `git subtree pull` は無関係な履歴のマージになる。実測では競合せず `exit 0` で完了する(gpbpn での作業で確認済み)が、squash コミットメッセージに旧 `main` 系履歴由来の `REVERT:` 行が約30行残ってしまい可読性を損なう。そのため初回は削除してから `subtree add` し直すことを推奨する(以後は通常の `subtree pull` で追従できる):

```sh
git rm -r lib/l2d-updater && git commit -m "..."
git subtree add --prefix=lib/l2d-updater \
  https://github.com/lunaluna/l2d-wp-github-update-lib.git dist-X.Y.Z --squash
```

## ライセンス

GPL-2.0-or-later
