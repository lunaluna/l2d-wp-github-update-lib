#!/usr/bin/env bash
#
# 汎用の配布用 ZIP ビルダー(l2d-wp-github-update-lib).
#
# プラグインルート(カレントディレクトリ)で実行する想定。利用側プラグインは
# 自身の bin/build-zip.sh から
#   bash lib/l2d-updater/bin/build-zip.sh
# のように呼ぶ薄いラッパーを置く(このスクリプト自体はディレクトリを移動
# しない)。
#
# SLUG はカレントディレクトリ名から解決する(ハードコードしない)。
# 除外定義は .distignore を単一の正とし、release.yml 経由の composite
# action もこのスクリプトと同じ .distignore を読む。ただしこのスクリプト自身の
# 生成物({slug}.{version}.zip)だけは、利用側の .distignore の内容に依存せず
# スクリプト側で必ず除外する(理由は rsync 実行箇所のコメントを参照)。
#
# composer install --no-dev や追加ライブラリの同梱など、プラグイン固有の
# 前処理は、利用側に bin/build-zip.pre.sh があればステージング前に実行する
# (WPMAR の Action Scheduler 同梱のようなニーズに対応する).

set -euo pipefail

SLUG="$(basename "$(pwd)")"
PLUGIN_FILE="${SLUG}.php"

if [ ! -f "$PLUGIN_FILE" ]; then
  echo "Error: $PLUGIN_FILE not found. Run this script from the plugin root." >&2
  exit 1
fi

VERSION=$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$PLUGIN_FILE" \
  | head -n1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')

if [ -z "$VERSION" ]; then
  echo "Error: Could not read Version from $PLUGIN_FILE." >&2
  exit 1
fi

echo "Building: ${SLUG} v${VERSION}"

if [ ! -f ".distignore" ]; then
  echo "Error: .distignore not found. Run this script from the plugin root." >&2
  exit 1
fi

if [ -x "bin/build-zip.pre.sh" ]; then
  echo "Running bin/build-zip.pre.sh ..."
  bash bin/build-zip.pre.sh
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "${STAGE}/${SLUG}"

# rsync の --exclude-from に渡す前に、コメント行・空行を除去する
# (--exclude-from がコメント行をどう扱うかは未検証のため、依存しない).
grep -vE '^[[:space:]]*(#|$)' .distignore > "${STAGE}/excludes.txt"

# このスクリプトは生成物をプラグインルートに置くため、同じ作業ツリーで 2 回
# ビルドすると 1 回目の ZIP が 2 回目の ZIP に入れ子で同梱されてしまう
# (バージョンを上げて再ビルドしたときは、古い版の ZIP が丸ごと入り込む)。
# 利用側の .distignore に *.zip があるかどうかに関わらず起きるため、ここで
# 必ず除外する。先頭の / は転送ルート(プラグインルート)へのアンカーなので、
# プラグインが意図的に同梱する assets/**/*.zip などには影響しない。
rsync -a --exclude-from="${STAGE}/excludes.txt" --exclude="/${SLUG}.*.zip" ./ "${STAGE}/${SLUG}/"

ZIP_NAME="${SLUG}.${VERSION}.zip"

( cd "$STAGE" && zip -rq "$ZIP_NAME" "$SLUG" )
mv "${STAGE}/${ZIP_NAME}" "./${ZIP_NAME}"

echo "Built: ${ZIP_NAME}"
