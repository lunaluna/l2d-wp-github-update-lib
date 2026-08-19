# l2d-wp-github-update-lib 向けの必須手順

## リリース手順(絶対のルール)

**このリポジトリでバージョンをリリースするときは、以下の手順を必ず順番通りに踏むこと。**

このリポジトリ自身にはタグ push で配布物を組み立てる `release.yml` が無い(`plugin-release.yml` は他リポジトリが `workflow_call` で使う側)。そのため、以下が唯一のリリースフローになる。

1. `loader.php` の `l2dwpghul_updater_register()` 第一引数(自己申告バージョン、例 `'1.0.3'`)を新しいバージョンに手動で書き換える。他の変更と混ぜず、専用コミットにする
2. 横断検索して、他に現在バージョンを指す表記が残っていないか確認する(`grep -rn "<旧バージョン>" . --exclude-dir=vendor --exclude-dir=.git`)
3. PR を作成し、CI(PHP 構文チェック・PHPCS・PHPStan・PHPUnit)を通してから `main` にマージする
4. タグを打って push する: `git tag vX.Y.Z && git push origin vX.Y.Z`
5. **`release-version-check.yml` が成功することを確認する** — タグ push で自動起動し、`loader.php` の自己申告バージョンとタグが一致しないと `::error::` で失敗する。このリポジトリでタグを打つ際の**唯一の自動ゲート**。ここで失敗したら Release を作らず先に修正する
6. `gh release create vX.Y.Z --title vX.Y.Z --notes "..."` で GitHub Release を手動作成する(ライブラリであり配布用 ZIP は不要なので、ビルド・アセット添付は行わない)

**なぜこの手順が必須か**: `loader.php` の自己申告バージョン文字列は、複数プラグインが異なる版のこのライブラリを同梱していても最新版だけが起動する、というバージョン交渉ロジック(`loader.php` の `l2dwpghul_updater_boot()`)の判定基準そのもの。この文字列の更新を忘れてタグだけ打つと、古いライブラリコードが新しいと誤認識され続け、静かに古い版が勝ち続ける実害に直結する(2026-08-19、WPMAR の更新機構修正の横展開調査で発見・修正済み)。

## 利用側プラグインへの反映(絶対のルール)

**このライブラリをリリースしただけでは、同梱している各プラグイン(FAUC など)には自動反映されない。** 利用側プラグインへ反映する場合は、利用側プラグインのリポジトリで以下の2箇所を必ず更新すること。

- `release.yml` の `uses: lunaluna/l2d-wp-github-update-lib/.github/workflows/plugin-release.yml@vX.Y.Z` の参照タグ
- `git subtree pull --prefix=lib/l2d-updater https://github.com/lunaluna/l2d-wp-github-update-lib.git vX.Y.Z --squash` でベンダーコピー(`lib/l2d-updater/`)を更新

この2つを両方更新しないと、ライブラリ本体は新しくなったのに利用側は古い版のまま、という不整合が生じる。

## この文書と README.md の関係

上記の手順は [README.md](README.md) の「リリース手順」節と同一内容。人間向けの詳細説明・背景は README.md 側を正とし、この CLAUDE.md は AI セッションが自動で読み込むための複製。**手順を変更するときは両方の文書を同時に更新すること**(どちらか一方だけ更新すると、今回のライブラリ自体の不具合と同じ「二重管理のドリフト」が起きる)。
