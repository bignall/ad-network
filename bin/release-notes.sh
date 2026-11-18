#!/usr/bin/env bash
# Split release-notes/<tag>.md into a release title and body.
#
# Usage: bin/release-notes.sh <tag> <body-output-file>
# Prints the title on stdout and writes the body to <body-output-file>.
#
# If the notes file's first line is a "# Heading", the heading is the title and the rest
# (leading blank lines removed) is the body. Otherwise the title is the tag and the whole
# file is the body. Exits 3 when there is no notes file (the caller generates notes instead).
set -euo pipefail

TAG="${1:?tag required}"
OUT="${2:?body output file required}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FILE="$ROOT/release-notes/${TAG}.md"

[ -f "$FILE" ] || exit 3

FIRST="$(head -n 1 "$FILE" | tr -d '\r')"
if [[ "$FIRST" =~ ^#[[:space:]]+(.*[^[:space:]])[[:space:]]*$ ]]; then
	printf '%s\n' "${BASH_REMATCH[1]}"
	tail -n +2 "$FILE" | sed '/[^[:space:]]/,$!d' > "$OUT"
else
	printf '%s\n' "$TAG"
	cp "$FILE" "$OUT"
fi
