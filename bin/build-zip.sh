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
# action もこのスクリプトと同じ .distignore を読む。
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

rsync -a --exclude-from="${STAGE}/excludes.txt" ./ "${STAGE}/${SLUG}/"

ZIP_NAME="${SLUG}.${VERSION}.zip"

( cd "$STAGE" && zip -rq "$ZIP_NAME" "$SLUG" )
mv "${STAGE}/${ZIP_NAME}" "./${ZIP_NAME}"

echo "Built: ${ZIP_NAME}"
