#!/usr/bin/env bash
#
# bin/build-zip.sh のスモークテスト.
#
# ダミーの利用側プラグインを一時ディレクトリに組み立ててビルドを 2 回実行し、
# 以下を検証する.
#
#   1. 生成物の名前が {slug}.{version}.zip になる
#   2. ZIP 内のルートディレクトリ名がスラッグに一致する
#   3. .distignore の除外が(任意階層で)効いている
#   4. 同梱すべきファイルが残っている
#   5. 前回のビルドで残った生成物が、次の ZIP に入れ子で同梱されない
#      (同一バージョンの生成物と、バージョンを上げる前の古い生成物の両方)
#   6. 一方で、プラグインが意図的に同梱する ZIP(assets/ 配下など)は残る
#
# 5 は利用側の .distignore に *.zip が無い状態で検証する。これはスクリプト側の
# 責務として保証すべき挙動であり、利用側の除外設定に依存させないため。
# 6 は生成物の除外パターンを転送ルートにアンカーした狭いものにしている根拠.
#
# 実行方法: リポジトリルートで `bash tests/build-zip-test.sh`

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILDER="${REPO_ROOT}/bin/build-zip.sh"

if [ ! -f "$BUILDER" ]; then
	echo "Error: ${BUILDER} が見つかりません." >&2
	exit 1
fi

for cmd in rsync zip unzip; do
	if ! command -v "$cmd" > /dev/null 2>&1; then
		echo "Error: ${cmd} が必要です." >&2
		exit 1
	fi
done

FAILURES=0

pass() {
	echo "  ok  : $1"
}

fail() {
	echo "  NG  : $1" >&2
	FAILURES=$((FAILURES + 1))
}

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

SLUG='fake-consumer-plugin'
VERSION='1.2.3'
ZIP_NAME="${SLUG}.${VERSION}.zip"
PLUGIN_DIR="${WORK}/${SLUG}"

# ---- ダミーの利用側プラグインを組み立てる ------------------------------------

mkdir -p \
	"${PLUGIN_DIR}/bin" \
	"${PLUGIN_DIR}/tests" \
	"${PLUGIN_DIR}/vendor" \
	"${PLUGIN_DIR}/assets" \
	"${PLUGIN_DIR}/.github/workflows" \
	"${PLUGIN_DIR}/lib/l2d-updater/tests"

cat > "${PLUGIN_DIR}/${SLUG}.php" << 'PHP'
<?php
/**
 * Plugin Name:       Fake Consumer Plugin
 * Version:           1.2.3
 * Update URI:        false
 */
PHP

echo 'readme' > "${PLUGIN_DIR}/readme.txt"
echo 'license' > "${PLUGIN_DIR}/LICENSE"
echo '<?php // shipped' > "${PLUGIN_DIR}/lib/l2d-updater/loader.php"
echo '<?php // dev only' > "${PLUGIN_DIR}/tests/ExampleTest.php"
echo '<?php // dev only' > "${PLUGIN_DIR}/lib/l2d-updater/tests/ExampleTest.php"
echo '<?php // dev only' > "${PLUGIN_DIR}/vendor/autoload.php"
echo '{}' > "${PLUGIN_DIR}/composer.json"
echo 'name: CI' > "${PLUGIN_DIR}/.github/workflows/ci.yml"

# プラグインが意図的に同梱する ZIP。生成物の除外に巻き込まれてはいけない.
echo 'bundled payload' > "${PLUGIN_DIR}/assets/bundled.zip"

# バージョンを上げる前のビルドで残った古い生成物。これも除外されなければならない.
echo 'stale artifact' > "${PLUGIN_DIR}/${SLUG}.1.0.0.zip"

# 検証対象のビルダーをプラグインルートの bin/ に置く(composite action と同じ配置).
cp "$BUILDER" "${PLUGIN_DIR}/bin/build-zip.sh"

# *.zip を意図的に含めない。生成物の除外はスクリプト側の責務であることを検証する.
cat > "${PLUGIN_DIR}/.distignore" << 'DISTIGNORE'
# コメント行と空行が除去されることも同時に検証する.

.git
.github
.distignore
bin
tests
vendor
composer.json
DISTIGNORE

# ---- 2 回ビルドする ----------------------------------------------------------

cd "$PLUGIN_DIR"

echo "1 回目のビルド"
bash bin/build-zip.sh

if [ ! -f "$ZIP_NAME" ]; then
	echo "  NG  : 1 回目のビルドで ${ZIP_NAME} が生成されなかった" >&2
	exit 1
fi

FIRST_SIZE=$(wc -c < "$ZIP_NAME" | tr -d ' ')
echo "  (生成: ${ZIP_NAME}, ${FIRST_SIZE} バイト)"

echo "2 回目のビルド(1 回目の生成物がルートに残った状態)"
bash bin/build-zip.sh

# ---- 検証 --------------------------------------------------------------------

echo "検証"

if [ ! -f "$ZIP_NAME" ]; then
	echo "  NG  : 2 回目のビルドで ${ZIP_NAME} が生成されなかった" >&2
	exit 1
fi
pass "生成物の名前が ${ZIP_NAME}"

ENTRIES="$(unzip -Z1 "$ZIP_NAME")"

# 1. ZIP 内ルートがスラッグ.
if [ -n "$(printf '%s\n' "$ENTRIES" | grep -v "^${SLUG}/" || true)" ]; then
	fail "ZIP 内ルートが ${SLUG}/ 以外のエントリを含む"
	printf '%s\n' "$ENTRIES" | grep -v "^${SLUG}/" | sed 's/^/        /' >&2
else
	pass "ZIP 内ルートが ${SLUG}/ に統一されている"
fi

# 2. 生成物が入れ子で同梱されていない(利用側の .distignore に *.zip が無い状態で).
#    同一バージョンの生成物と、バージョンを上げる前の古い生成物の両方を見る.
NESTED="$(printf '%s\n' "$ENTRIES" | grep -E "^${SLUG}/${SLUG}\..*\.zip$" || true)"
if [ -n "$NESTED" ]; then
	fail "生成物が入れ子で同梱されている"
	printf '%s\n' "$NESTED" | sed 's/^/        /' >&2
else
	pass "生成物が入れ子で同梱されていない(同一バージョン / 旧バージョンとも)"
fi

# 3. .distignore の除外が効いている(スラッシュ無しパターンは任意階層に一致する).
for excluded in \
	"${SLUG}/bin/" \
	"${SLUG}/tests/" \
	"${SLUG}/vendor/" \
	"${SLUG}/composer.json" \
	"${SLUG}/.distignore" \
	"${SLUG}/.github/" \
	"${SLUG}/lib/l2d-updater/tests/"; do
	if printf '%s\n' "$ENTRIES" | grep -q -- "^${excluded}"; then
		fail "除外されるべき ${excluded} が同梱されている"
	else
		pass "除外: ${excluded}"
	fi
done

# 4. 同梱すべきファイルが残っている.
for included in \
	"${SLUG}/${SLUG}.php" \
	"${SLUG}/readme.txt" \
	"${SLUG}/LICENSE" \
	"${SLUG}/lib/l2d-updater/loader.php" \
	"${SLUG}/assets/bundled.zip"; do
	if printf '%s\n' "$ENTRIES" | grep -q -- "^${included}$"; then
		pass "同梱: ${included}"
	else
		fail "同梱されるべき ${included} が無い"
	fi
done

echo
if [ "$FAILURES" -gt 0 ]; then
	echo "FAILED: ${FAILURES} 件の検証が失敗しました." >&2
	exit 1
fi

echo "PASSED: すべての検証が成功しました."
