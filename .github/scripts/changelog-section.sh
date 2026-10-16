#!/usr/bin/env bash
# Prints the CHANGELOG.md section of a version, without its heading.
# Fails when the section is missing, has no date, or is empty.
#
#   .github/scripts/changelog-section.sh 2.0.0-alpha.1 [CHANGELOG.md]
set -euo pipefail

version="${1:?usage: changelog-section.sh <version> [changelog]}"
changelog="${2:-CHANGELOG.md}"

heading=$(grep -E "^## \[${version//./\\.}\]" "$changelog" || true)
if [ -z "$heading" ]; then
    echo "No section for ${version} in ${changelog}: add '## [${version}] - YYYY-MM-DD'." >&2
    exit 1
fi
if ! grep -qE "^## \[${version//./\\.}\] - [0-9]{4}-[0-9]{2}-[0-9]{2}$" <<<"$heading"; then
    echo "The section of ${version} has no date: '${heading}'." >&2
    exit 1
fi

section=$(awk -v heading="$heading" '
    $0 == heading { found = 1; next }
    found && /^## / { exit }
    found && /^\[[^]]+\]: / { exit }
    found { print }
' "$changelog" | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}')

if [ -z "$section" ]; then
    echo "The section of ${version} is empty." >&2
    exit 1
fi

printf '%s\n' "$section"
