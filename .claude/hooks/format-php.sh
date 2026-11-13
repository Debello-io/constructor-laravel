#!/usr/bin/env bash
#
# Claude Code PostToolUse hook: format a PHP file with Laravel Pint right
# after Claude edits it, so every change follows the project's code style.
#
# Claude Code passes the tool call as JSON on stdin; the edited path is in
# .tool_input.file_path. Anything that isn't a PHP file inside this project
# (outside vendor/) is ignored. The hook never blocks the edit: it always
# exits 0.

set -uo pipefail

# Print the physical, absolute path of an existing file (symlinks and ".."
# resolved), so "/project/../elsewhere.php" can't pass the prefix check.
resolve_path() {
    local path="$1"

    if command -v realpath >/dev/null 2>&1; then
        realpath "$path" 2>/dev/null
        return
    fi

    local dir
    dir="$(cd "$(dirname "$path")" 2>/dev/null && pwd -P)" || return 1
    printf '%s/%s\n' "$dir" "$(basename "$path")"
}

project_dir="$(cd "${CLAUDE_PROJECT_DIR:-$(pwd)}" 2>/dev/null && pwd -P)" || exit 0
pint="$project_dir/vendor/bin/pint"

# Nothing to do until dependencies are installed.
[[ -x "$pint" ]] || exit 0

input="$(cat)"

if command -v jq >/dev/null 2>&1; then
    file_path="$(printf '%s' "$input" | jq -r '.tool_input.file_path // empty' 2>/dev/null)"
else
    file_path="$(printf '%s' "$input" | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? ($d["tool_input"]["file_path"] ?? "") : "";' 2>/dev/null)"
fi

# Only existing .php files.
[[ -n "$file_path" && "$file_path" == *.php && -f "$file_path" ]] || exit 0

file_path="$(resolve_path "$file_path")" || exit 0

# Only inside this project, never vendor/.
[[ "$file_path" == "$project_dir"/* && "$file_path" != "$project_dir"/vendor/* ]] || exit 0

pint_args=(--quiet)
[[ -f "$project_dir/pint.json" ]] && pint_args+=(--config "$project_dir/pint.json")

"$pint" "${pint_args[@]}" "$file_path" >/dev/null 2>&1 || true

exit 0
