#!/usr/bin/env bash
#
# Claude Code PostToolUse hook: format a PHP file with Laravel Pint right
# after Claude edits it, so every change follows the project's code style.
#
# Claude Code passes the tool call as JSON on stdin; the edited path is in
# .tool_input.file_path. Anything that isn't a PHP file inside this project
# is ignored. The hook never blocks the edit: it always exits 0.

set -uo pipefail

project_dir="${CLAUDE_PROJECT_DIR:-$(pwd)}"
pint="$project_dir/vendor/bin/pint"

# Nothing to do until dependencies are installed.
[[ -x "$pint" ]] || exit 0

input="$(cat)"

if command -v jq >/dev/null 2>&1; then
    file_path="$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty')"
else
    file_path="$(printf '%s' "$input" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["tool_input"]["file_path"] ?? "";')"
fi

# Only existing .php files inside the project (never vendor/).
[[ -n "$file_path" && "$file_path" == *.php && -f "$file_path" ]] || exit 0
[[ "$file_path" == "$project_dir"/* && "$file_path" != "$project_dir"/vendor/* ]] || exit 0

"$pint" --quiet "$file_path" >/dev/null 2>&1 || true

exit 0
