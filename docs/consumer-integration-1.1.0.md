# l2d-updater 1.1.0 — 利用側プラグインへの反映ガイド

このドキュメントは、**利用側プラグインのリポジトリで作業するセッション向け**の作業指示書。
ライブラリ側(`l2d-wp-github-update-lib`)の 1.1.0 リリースは完了済み。各プラグインへの反映は
リポジトリごとに個別に行う。

読み込み方(利用側リポジトリのセッションで):

```text
/Users/mkgq3lla/private/l2d-wp-github-update-lib/docs/consumer-integration-1.1.0.md
```

- ライブラリのローカルパス: `/Users/mkgq3lla/private/l2d-wp-github-update-lib`
- ライブラリのリモート: `https://github.com/lunaluna/l2d-wp-github-update-lib`
- 1.1.0 の Release: <https://github.com/lunaluna/l2d-wp-github-update-lib/releases/tag/1.1.0>

> **注意**: 1.1.0 以降、ベンダーコピー(`lib/l2d-updater/`)に `CLAUDE.md` / `README.md` は
> **含まれない**。従来 `lib/l2d-updater/CLAUDE.md` を参照していたコメントや手順は、
> このドキュメントかライブラリリポジトリ本体を指すように書き換えること。

---

## 1. 1.1.0 で何が変わったか

### 1-1. 配布形態: `dist/` サブツリー方式に移行(最重要)

`git subtree pull` はリポジトリ全体を取り込むため、従来はベンダーコピーに開発用ファイルが
丸ごと入っていた。1.1.0 では配布対象を `dist/` に集約し、リリース時に
`git subtree split --prefix=dist` で**配布専用のタグ `dist-X.Y.Z`** を自動生成する方式にした。

| | 1.0.x まで | 1.1.0 以降 |
|---|---|---|
| subtree の参照先 | `v1.0.4`(通常のリリースタグ) | **`dist-1.1.0`**(配布専用タグ) |
| ベンダーコピーの中身 | 15 エントリ / 29 ファイル(`tests/` `.github/` `composer.lock` `README.md` `CLAUDE.md` `phpcs/phpstan/phpunit` 設定など全部。gpbpn で実測) | **4 ファイルのみ** |
| ZIP 混入の防止責務 | 利用側の `.distignore`(書き漏れが再発する構造) | ライブラリ側(ホワイトリスト) |

1.1.0 のベンダーコピーはこれだけになる:

```text
lib/l2d-updater/
├── loader.php
├── class-l2d-github-updater.php
├── LICENSE
└── bin/build-zip.sh
```

**ファイルの配置パスは変わらない。** `lib/l2d-updater/loader.php` も
`lib/l2d-updater/bin/build-zip.sh` も従来と同じ位置にあるため、
プラグイン本体の `require` 行や `bin/build-zip.sh` のラッパーは**変更不要**。

### 1-2. タグ慣習: `v` 接頭辞なしに統一

v 接頭辞を使っていたのはライブラリだけで、利用側プラグイン(FAUC・gpbpn・WPMAR)は
すべて素の semver だった。1.1.0 以降はライブラリ側も **v なし**に統一する。

- ライブラリの参照は `@1.1.0`(**`@v1.1.0` ではない**)
- 既存の `v1.0.x` タグは利用側が現に参照しているため**削除しない**

### 1-3. `build-zip.sh` がライブラリ自身の `bin/` を常時除外するようになった

`lib/l2d-updater/bin/` は配布物に不要。従来は利用側 `.distignore` の `bin` 行に
依存して消えていたが、書き漏れた利用側では ZIP に混入していた。1.1.0 からは
`${BASH_SOURCE[0]}` から自分の配置を求めてビルダー側で必ず除外する。

→ **利用側の `.distignore` の `bin` 行の有無に関わらず除外される。**

### 1-4. `plugin-release.yml` の composite action 参照タグを `@1.1.0` に固定

`plugin-release.yml` の中で使う composite action(`verify-version` / `build-zip` /
`release-notes`)は、`@v1.0.0` 参照のまま止まっていた。1.1.0 で `@1.1.0` に更新済み。

**なぜ相対参照にできないのか**: reusable workflow の中で `uses: ./.github/actions/...` と
書くと、それは**呼び出し元(利用側プラグイン)リポジトリの `$GITHUB_WORKSPACE`** を指す。
reusable workflow は呼び出し元の文脈で動き、定義元リポジトリはディスクに clone されない
([GitHub Community Discussion #66094](https://github.com/orgs/community/discussions/66094))。
そのためタグ固定運用が必須で、ライブラリのリリース手順に恒久チェック項目として入れてある。

→ 利用側は `plugin-release.yml@1.1.0` を参照するだけでよい(composite action の
タグはライブラリ側で解決済み)。

---

## 2. 反映手順

### 2-A. 既存のベンダーコピーがある場合 — 初回のみ「付け替え」が必要

既存の `lib/l2d-updater/` は `main` 系の履歴から取り込んだもの。`dist-1.1.0` は
`git subtree split` が生成した**別系統の履歴**(独立したルートコミット)なので、
そのまま `git subtree pull` すると無関係な履歴のマージになる。
**初回だけ削除してから `subtree add` し直す**(以後は通常の `subtree pull` で追従できる)。

```sh
# 作業ブランチで実行すること(main では実行しない)
git rm -r lib/l2d-updater
git commit -m "lib/l2d-updater のベンダーコピーを削除(dist サブツリーへ付け替えるため)"

git subtree add --prefix=lib/l2d-updater \
  https://github.com/lunaluna/l2d-wp-github-update-lib.git dist-1.1.0 --squash
```

> **未実測**: 「`dist-1.1.0` からの `subtree pull` は無関係な履歴のマージになり競合する」は
> `subtree split` が独立した履歴を作ることからの推論で、実機では未確認(検証しようとしたが
> 権限で実行できなかった)。上記は削除 + `add` なのでこの推論が外れていても正しく動くが、
> 気になる場合は使い捨てブランチで `git subtree pull --prefix=lib/l2d-updater <repo> dist-1.1.0 --squash`
> を1回試し、競合したらブランチを捨てて上記手順に戻ればよい。**いずれにせよ作業ブランチで行うこと。**

削除 → `add` の2コミットに分けるのは、`git rm -r` が破壊的操作でありレビュー時に
差分を追いやすくするため。

### 2-B. 新規に組み込む場合 — 削除は不要

```sh
git subtree add --prefix=lib/l2d-updater \
  https://github.com/lunaluna/l2d-wp-github-update-lib.git dist-1.1.0 --squash
```

### 2-C. つなぎこみ(4 点)

どこまで採用するかはリポジトリごとに違う。**(1) は更新機構を使うなら必須**、
(2)〜(4) はライブラリのリリース基盤に乗る場合のみ。

#### (1) メインプラグインファイルの `require` — 更新機構を使うなら必須

```php
$l2dwpghul_updater_register = require plugin_dir_path( __FILE__ ) . 'lib/l2d-updater/loader.php';
$l2dwpghul_updater_register( array(
	'plugin_file' => __FILE__,
	'github_repo' => 'lunaluna/<このプラグインのリポジトリ名>',
) );
```

- `require_once` ではなく **`require`**(戻り値のクロージャを受け取る必要がある)
- プラグインヘッダーに **`Update URI: false`** を入れる(wp.org 公式ルートを使わないため)
- 設定キーの一覧はライブラリの [README.md](../README.md) を参照
- **既に組み込み済みなら変更不要**(1.1.0 でパスも API も変わっていない)

#### (2) `release.yml` の参照タグ — `plugin-release.yml` を使う場合のみ

```yaml
jobs:
  release:
    uses: lunaluna/l2d-wp-github-update-lib/.github/workflows/plugin-release.yml@1.1.0
```

- `@v1.0.4` → **`@1.1.0`**(v なし)
- 併せて、ファイル冒頭のコメントに `@vX.Y.Z` 表記や
  「`lib/l2d-updater/CLAUDE.md` を参照」の記述があれば書き換える
  (1.1.0 以降そのファイルは配布されない)

#### (3) `bin/build-zip.sh` — ライブラリのビルダーに委譲する場合のみ

```sh
#!/usr/bin/env bash
#
# 配布用 ZIP をビルドする.
#
# 実処理は同梱ライブラリの汎用ビルダーに委譲する。除外定義はプラグインルートの
# .distignore が単一の正であり、リリースワークフローの build-zip composite
# action もこのスクリプトを経由して同じ .distignore を読む.
#
# 呼び出し先はディレクトリを移動しないため、プラグインルートで実行すること.

set -euo pipefail

bash lib/l2d-updater/bin/build-zip.sh
```

委譲すると得られるもの: slug のハードコード解消 / 生成物の入れ子同梱の除外 /
ライブラリ自身の `bin/` 除外 / `bin/build-zip.pre.sh` フック。

**ライブラリのビルダーの前提条件**(満たさないとエラー終了する):

| 前提 | 満たさないと |
|---|---|
| プラグインルートで実行される | `${SLUG}.php not found` で終了 |
| ディレクトリ名 == slug | slug を `basename $(pwd)` から解決するため名前が食い違う |
| `${SLUG}.php` に `* Version:` ヘッダーがある | `Could not read Version` で終了 |
| ルートに **`.distignore` がある** | `.distignore not found` で終了 |

`build-zip` composite action は checkout 直下で `bash bin/build-zip.sh` を実行するので、
ラッパーは `cd` せずそのまま委譲すればよい。

#### (4) `.distignore`

1.1.0 で**不要になる**行(書いてあっても害はない):

- ライブラリの開発用ファイル向けの行 — `dist` サブツリーではそもそも配布されない
- `*.zip` — ビルダーが `/${SLUG}.*.zip` をルートにアンカーして常時除外する
  (`.distignore` に `*.zip` と書くと `assets/**/*.zip` のように**意図的に同梱する zip も
  消える**ため、意図的に同梱する zip があるなら書かない方がよい)

**引き続き必要**な行:

- `bin` — プラグイン自身の `bin/` を除外するため(ライブラリの `bin/` は
  ビルダーが自力で除外するが、自分の `bin/` は `.distignore` の責務)
- `tests` / `vendor` / `composer.json` / `.github` など、プラグイン自身の開発用ファイル
- 自身のルートに `CLAUDE.md` があるなら `CLAUDE.md`
  (ライブラリの `CLAUDE.md` はもう配布されないが、自分のものは自分で除外する)

---

## 3. リポジトリ別の作業一覧

現状は 2026-08-20 時点の実測。**各リポジトリで別作業が進行中**のため、着手前に
必ず `git branch --show-current` と `git status` で最新の状態を確認すること。

### 3-1. [get-patterns-by-pattern-name](/Users/mkgq3lla/private/get-patterns-by-pattern-name/)

現状: ブランチ `feature/v1.4.0-github-updater`(clean)、ベンダーコピーは **1.0.4**、
`release.yml` は `plugin-release.yml@v1.0.4`、タグは素の semver(1.1.0 / 1.2.0)。

| 作業 | 内容 |
|---|---|
| subtree 付け替え | **必要**(手順 2-A)。現コピーは全 16 エントリ入り |
| `release.yml` | `@v1.0.4` → `@1.1.0`。冒頭コメントの `@vX.Y.Z` 表記と「`lib/l2d-updater/CLAUDE.md` を参照」の一文も更新 |
| `bin/build-zip.sh` | **変更不要**(既に薄いラッパーで委譲済み) |
| `.distignore` | **変更不要**。`CLAUDE.md` 行は gpbpn 自身のルート用に残す。`*.zip` は冗長になるが、意図的に同梱する zip が無いなら残して害はない |
| メインファイルの `require` | **変更不要** |

### 3-2. [forced-auto-update-controller](/Users/mkgq3lla/private/forced-auto-update-controller/)

現状: ブランチ `chore/bump-l2d-updater-lib-1.0.4`(clean)、タグは素の semver(〜1.9.0)。

> ⚠️ **このブランチは作業途中**: `release.yml` は `@v1.0.4` に上げてあるが、
> ベンダーコピーは **1.0.3 のまま**(subtree pull 未実施)。1.1.0 へ移行するなら、
> 未完の 1.0.4 への pull は**飛ばして直接 `dist-1.1.0` へ**行くのが素直。
> その場合ブランチ名が実態と合わなくなるので、新しいブランチを切るか改名を検討する。

| 作業 | 内容 |
|---|---|
| subtree 付け替え | **必要**(手順 2-A) |
| `release.yml` | `@v1.0.4` → `@1.1.0`。`verify-pot-version` job(`.pot` の Project-Id-Version チェック)は FAUC 独自なのでそのまま残す |
| `bin/build-zip.sh` | **要置換**。現在はライブラリに委譲していない独自版(slug ハードコード・生成物の入れ子除外なし)。手順 2-C(3) の薄いラッパーに置き換える |
| `.distignore` | `bin` / `tests` / `vendor` は既にある。`*.zip` は**追加不要**(ビルダーが常時除外)。ルートに `CLAUDE.md` は無いので追加不要 |
| メインファイルの `require` | **変更不要** |

`bin/build-zip.sh` の置換で、先日ライブラリ側で直した「生成物が次のビルドの ZIP に
入れ子で同梱される」修正がようやく FAUC にも届く(独自版のままでは届かない)。

### 3-3. [wp-maintenance-audit-reporter](/Users/mkgq3lla/private/test-armfu/test-armfu.local/app/public/wp-content/plugins/wp-maintenance-audit-reporter/)

現状: ブランチ `main`(clean)、**`lib/l2d-updater` は未組込**、タグは素の semver
(1.3.0〜1.4.1、`v0.10.0` は歴史的な例外)。

> ⚠️ **他の3リポジトリと性質が違う。WPMAR は既に独自の更新機構を持っている。**
> `includes/class-wpmar-github-updater.php`(369 行、`WPMAR_GitHub_Updater`、静的メソッド構成)が
> ライブラリと**同じ3フック**(`pre_set_site_transient_update_plugins` / `plugins_api` /
> `upgrader_process_complete`)を登録している。つまり WPMAR の作業は「追加」ではなく
> **既存実装からの置き換え(移行)**。そのまま両方入れると二重にフックが登録される。
> ライブラリの `class-l2d-github-updater.php` は FAUC と WPMAR の updater を統合して
> 作ったものなので、機能的にはライブラリ側が上位互換。

#### 更新機構の移行(WPMAR 固有の作業)

| 現状 | 移行後 |
|---|---|
| `includes/class-wpmar-github-updater.php`(369 行) | **削除**。メインファイル L100 の require も外す |
| `tests/GitHubUpdaterTest.php`(172 行、private static を Reflection で叩くテスト) | **削除**(ライブラリ側の 54 件のテストが引き継ぐ) |
| `Update URI: https://github.com/lunaluna/wp-maintenance-audit-reporter` | **`Update URI: false` に変更**(ライブラリは wp.org 公式ルート `update_plugins_{$hostname}` を使わない前提) |
| フィルタ `wpmar_github_updater_cache_ttl` / `..._backoff_ttl` | `'filter_prefix' => 'wpmar'` を渡せば**両方そのまま効く**(後方互換用の設定キー) |
| トランジェント `wpmar_github_release_cache` | `'cache_key' => 'wpmar_github_release_cache'` を渡して**既存キーを維持**(省略すると `l2dwpghul_updater_<md5>` に変わり、既存サイトのキャッシュが載り替わる) |

したがって登録はこうなる:

```php
$l2dwpghul_updater_register = require plugin_dir_path( __FILE__ ) . 'lib/l2d-updater/loader.php';
$l2dwpghul_updater_register( array(
	'plugin_file'   => __FILE__,
	'github_repo'   => 'lunaluna/wp-maintenance-audit-reporter',
	'filter_prefix' => 'wpmar',                        // 既存フィルタ名を維持
	'cache_key'     => 'wpmar_github_release_cache',   // 既存トランジェントを維持
) );
```

#### リリース基盤には乗せない

`release.yml` は**完全に独自**(`plugin-release.yml` も composite action も使わず、
5 ファイル横断のバージョン整合チェックをインラインで持ち、フォント生成 /
`vendor-pdf.zip` / Action Scheduler 同梱がある)。`bin/` にも独自スクリプトが 2 本ある。
汎用の `plugin-release.yml` ではこれらを表現できない。

| 作業 | 内容 |
|---|---|
| subtree | **新規 add**(手順 2-B)。削除は不要 |
| 更新機構 | **要移行**(上表)。単純な `require` 追加ではない |
| `release.yml` | **変更しない**(独自構成を維持) |
| `bin/build-zip.sh` | **変更しない**(Action Scheduler 同梱等の独自処理があるため委譲しない) |
| `.distignore` | **変更不要**。`bin` 行が任意階層に一致するので `lib/l2d-updater/bin/` は既に除外される |

将来 WPMAR をリリース基盤に乗せる案(独自処理を `bin/build-zip.pre.sh` フックに移し、
層1の composite action だけを個別に使う形に書き換える)はライブラリ側に受け口があるが、
**この 1.1.0 反映作業とは独立した別判断**。混ぜないこと。

#### WPMAR の検証(他リポジトリより重い)

更新機構を差し替えるため、チェックリスト(第 4 節)に加えて実機確認が必要:

- 管理画面の更新チェックが従来どおり動くこと(更新通知 → ワンクリック更新)
- `wpmar_github_updater_cache_ttl` / `..._backoff_ttl` のフィルタが引き続き効くこと
- 既存トランジェント `wpmar_github_release_cache` が使われていること
- フックが二重登録されていないこと(旧クラスの削除漏れ)

### 3-4. [flamingo-csv-sjis-exporter](/Users/mkgq3lla/private/flamingo-csv-sjis-exporter/)

現状: ブランチ `feature/l2d-updater-lib-integration`(clean)。
**`lib/` も `.github/workflows/` も `bin/` も `.distignore` も無く、タグも 0 件**。
ブランチ名の通り組み込み予定だが、まだ何も入っていない完全な新規。

| 作業 | 内容 |
|---|---|
| subtree | **新規 add**(手順 2-B)。削除は不要 |
| メインファイルの `require` | **要追加**(手順 2-C(1))。`Update URI: false` ヘッダーも要追加 |
| `release.yml` | **要新規作成**。`plugin-release.yml@1.1.0` を使うのが最も簡単(gpbpn の `release.yml` が最小構成の見本) |
| `bin/build-zip.sh` | **要新規作成**(手順 2-C(3) をそのまま使える) |
| `.distignore` | **要新規作成**。ライブラリのビルダーは `.distignore` が無いとエラー終了する |
| 初回タグ | タグが 0 件。素の semver で打つ(例 `1.0.0`。**`v` は付けない**) |

`readme.txt` の `Stable tag:` と `= x.y.z =` 節が `plugin-release.yml` の
`version_files` / `notes_source` の前提になる。無いならリリース前に用意する。

---

## 4. 検証チェックリスト(各リポジトリで)

```sh
# 1. ベンダーコピーが 4 ファイルのみ・CLAUDE.md と README.md が無いこと
find lib/l2d-updater -type f | sort
#   → lib/l2d-updater/LICENSE
#     lib/l2d-updater/bin/build-zip.sh
#     lib/l2d-updater/class-l2d-github-updater.php
#     lib/l2d-updater/loader.php

# 2. 自己申告バージョンが 1.1.0 であること
grep "l2dwpghul_updater_register( '" lib/l2d-updater/loader.php

# 3. ZIP に開発用ファイルが入っていないこと(build-zip.sh を採用した場合)
bash bin/build-zip.sh
unzip -Z1 <slug>.<version>.zip | grep l2d-updater
#   → loader.php / class-l2d-github-updater.php / LICENSE のみ
#     bin/build-zip.sh も除外されていること
```

さらに横断で確認すること:

- **AI 誤読の解消**: 利用側リポジトリで `lib/l2d-updater/` 配下のファイルを読むセッションを開き、
  `/context` の Memory files にライブラリの `CLAUDE.md` が現れないこと
  (これが dist 方式に移行した動機の半分)
- **バージョン交渉**: 片方のプラグインだけ 1.1.0 にした状態で WordPress 管理画面を開き、
  両プラグインの更新チェックが従来どおり動くこと(`loader.php` の自己申告バージョンで
  新しい方が勝つ設計。1.1.0 と 1.0.x の混在は正常な状態)
- **利用側の Release 実行**: `plugin-release.yml@1.1.0` を採用したリポジトリのうち
  **どれか 1 つ**で実際にタグを打ち、Release が成功すること
  (数字始まりタグの `uses: ...@1.1.0` 参照と composite action 参照を実測する。**未実測**)

---

## 5. 落とし穴

- **`@v1.1.0` と書かない**。1.1.0 のタグは `v` なし。`v1.1.0` タグは存在しない
- **`dist-1.1.0` と `1.1.0` を混同しない**。subtree の参照先は `dist-1.1.0`、
  `release.yml` の workflow 参照は `1.1.0`。前者は配布専用の split 履歴、後者は通常のリリースタグ
- **`git rm -r lib/l2d-updater` は必ず作業ブランチで**。`main` では実行しない
- **1.0.x と 1.1.0 の混在は正常**。全プラグインを同時に上げる必要はない
  (バージョン交渉で最新版が勝つ設計)
- **ライブラリ側を直しても利用側には自動で届かない**。ベンダーコピーと
  `release.yml` の参照タグ、**両方**を更新しないと版が食い違う
