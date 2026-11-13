---
name: release
disable-model-invocation: true
description: Prepare a new version of this package - choose the semantic version, finalize CHANGELOG.md, write UPGRADE.md notes for breaking changes, run checks, and prepare (but not push) the git tag. Use when asked to "cut a release", "bump the version" or "prepare vX.Y.Z".
---

# Release

The package is published to Packagist from git tags (`vX.Y.Z`). There is no version field in `composer.json`; the tag is the version.

**Never push, tag, or create a GitHub release without the user's explicit confirmation in this conversation.** Prepare everything locally, show the summary, then ask.

## 1. Pick the version (SemVer)

- **Major**: a public class, method, option or config key was removed or renamed, or the default behaviour changed (look for UPGRADE.md entries and `@deprecated` removals).
- **Minor**: new endpoints, options or DTO fields, and new deprecations.
- **Patch**: bug fixes and doc fixes only.

Check `git log <last-tag>..HEAD --oneline` and the `[Unreleased]` section of CHANGELOG.md.

## 2. Update files

1. **CHANGELOG.md** ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/)): rename `[Unreleased]` to `[X.Y.Z] - YYYY-MM-DD` (today's date), add a fresh empty `[Unreleased]`, and group entries under Added / Changed / Deprecated / Removed / Fixed / Security.
2. **UPGRADE.md**: for a major release, add a section `## From A.x to X.0` with every breaking change, each with a before/after snippet.
3. **README.md**: the requirements table (PHP / Laravel versions) and install command.
4. Deprecated aliases scheduled for removal in this major version: delete them, and remove their container aliases in `ConstructorServiceProvider::registerDeprecatedAliases()`.

## 3. Verify

```bash
composer validate --strict
composer check
```

Both must pass. If CI exists for the branch, confirm it is green.

## 4. Prepare and confirm

Show the user:
- the version and why (major/minor/patch)
- the CHANGELOG section
- the commands you will run:

```bash
git status            # review: only the release files should be changed
git add -A
git diff --cached     # show the user what will be committed
git commit -m "Release vX.Y.Z"
git tag -a vX.Y.Z -m "vX.Y.Z"
git push origin <branch> --follow-tags
```

Run them only after the user confirms. After pushing, remind them that Packagist updates from the tag (if the webhook is configured) and that a GitHub release can be created with `gh release create vX.Y.Z --notes-from-tag`.
