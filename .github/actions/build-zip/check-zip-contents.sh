#!/usr/bin/env bash
#
# 生成した配布用 ZIP に、開発用・機密のファイルが紛れ込んでいないかを検査する.
#
# 使い方: check-zip-contents.sh <zip ファイル> <スラッグ>
#
# 禁止するもの(見つかったら ::error:: を出して exit 1):
#   - {slug}/.git/  {slug}/.github/  {slug}/tests/  {slug}/node_modules/ (スラッグ直下のみ)
#   - .env で終わるファイル名(階層を問わない)
#
# 禁止を「スラッグ直下」に限る理由: 利用側の vendor/ には、依存パッケージ由来の .github/ や tests/ が
# 正当に含まれうる(WPMAR の vendor-pdf.zip の中身で確認)ため. vendor/ そのものは禁止しない
# (WPMAR は composer install --no-dev の vendor/ を正当に同梱する).
# .env だけは階層を問わず禁止する. 機密を含みやすく、正当に同梱する理由がほぼ無いため.
#
# 利用側の bin/build-zip.sh(.distignore)の除外漏れを、リリースを止めることで検知する最後の砦.

set -euo pipefail

ZIP="${1:?zip ファイルを指定してください}"
SLUG="${2:?スラッグを指定してください}"

if [ ! -f "$ZIP" ]; then
	echo "::error::${ZIP} が見つかりません"
	exit 1
fi

ENTRIES="$(unzip -Z1 "$ZIP")"

# スラッグ直下の禁止ディレクトリ. ディレクトリ自身のエントリ(末尾 / なし・あり)と、その配下のすべてに一致させる.
FOUND="$(printf '%s\n' "$ENTRIES" | grep -E "^${SLUG}/(\.git|\.github|tests|node_modules)(/|$)" || true)"

# .env で終わるファイル名(.env / production.env など. .env.example のような接尾辞付きは対象外).
ENV_FILES="$(printf '%s\n' "$ENTRIES" | grep -E '(^|/)[^/]*\.env$' || true)"

if [ -n "$ENV_FILES" ]; then
	FOUND="$(printf '%s\n%s' "$FOUND" "$ENV_FILES" | sed '/^$/d')"
fi

if [ -n "$FOUND" ]; then
	echo "::error::${ZIP} に配布してはいけないファイルが含まれています(.distignore を確認してください)"
	printf '%s\n' "$FOUND" | head -n 20 | sed 's/^/  /'
	exit 1
fi

echo "ZIP の内容検査: 問題なし(${ZIP})"
