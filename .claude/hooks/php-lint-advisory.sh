#!/usr/bin/env bash
# PostToolUse hook: runs `php -l` on an edited PHP file and prints the result to
# stderr. Advisory only — it never blocks, so it always exits 0. A hook that can
# fail a write is a hook that stops work over a false positive.
#
# Reads the Claude Code hook payload (JSON) from stdin to find the edited path,
# with a plain-text fallback so it still works without jq.

set -u

payload=$(cat 2>/dev/null || true)
edited_path=""

if [ -n "${payload}" ]; then
	if command -v jq >/dev/null 2>&1; then
		edited_path=$(printf '%s' "${payload}" | jq -r '.tool_input.file_path // empty' 2>/dev/null || true)
	else
		# Good enough for the one field we need when jq is unavailable.
		edited_path=$(printf '%s' "${payload}" \
			| sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
			| head -1)
	fi
fi

# Fallbacks for manual invocation and older hook payloads.
[ -n "${edited_path}" ] || edited_path="${CLAUDE_FILE_PATH:-${1:-}}"

case "${edited_path}" in
	*.php) ;;
	*) exit 0 ;;
esac

[ -f "${edited_path}" ] || exit 0

# Prefer the host binary: it is an order of magnitude faster than reaching into a
# container, and syntax checking does not depend on the exact 8.x version. Fall
# back to the container, and stay silent if neither is available — the stack
# being down is not a reason to nag on every edit.
if command -v php >/dev/null 2>&1; then
	output=$(php -l "${edited_path}" 2>&1) || true
elif docker compose ps --status running --services 2>/dev/null | grep -q '^app$'; then
	rel_path="${edited_path#"$(pwd)/"}"
	output=$(docker compose exec -T app php -l "/var/www/html/${rel_path}" 2>&1) || true
else
	exit 0
fi

case "${output}" in
	*"No syntax errors"*) ;;
	*) printf 'php -l: %s\n' "${output}" >&2 ;;
esac

exit 0
