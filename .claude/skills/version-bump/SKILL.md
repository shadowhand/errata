---
name: version-bump
description: Determines appropriate semantic version bumps based on changes. Use when deciding version numbers, evaluating breaking changes, or planning releases. Triggers on terms like "version", "semver", "breaking change", "major/minor/patch".
---

# Semantic Versioning Skill

This skill helps determine appropriate version bumps following [Semantic Versioning](https://semver.org/).

## Version Format

```
MAJOR.MINOR.PATCH
```

- **MAJOR**: Breaking changes
- **MINOR**: New features, backwards compatible
- **PATCH**: Bug fixes, backwards compatible

## Version Bump Decision Tree

### MAJOR (X.0.0) - Breaking Changes

Bump MAJOR when you make incompatible API changes:

- Removed public classes or methods
- Changed existing class namespaces
- Changed method signatures (parameters, return types)
- Changed default behavior that breaks existing usage

### MINOR (0.X.0) - New Features

Bump MINOR when you add functionality in a backwards compatible manner:

- New classes or methods

### PATCH (0.0.X) - Bug Fixes

Bump PATCH when you make backwards compatible bug fixes:

- Fix incorrect behavior
- Fix crashes or errors
- Performance improvements (no API changes)
- Internal refactoring (no behavior changes)
- Documentation fixes

## Quick Reference

| Change Type                      | Version Bump |
|----------------------------------|--------------|
| Breaking API change              | MAJOR        |
| Removed feature                  | MAJOR        |
| New command/feature              | MINOR        |
| New CLI flag                     | MINOR        |
| New provider/integration         | MINOR        |
| Bug fix                          | PATCH        |
| Performance fix                  | PATCH        |
| Documentation only               | PATCH        |
| Refactoring (no behavior change) | PATCH        |

## Pre-1.0 Versioning

For versions < 1.0.0 (like this project):
- MINOR can include breaking changes
- PATCH is for bug fixes and small features
- More flexibility before reaching stability

## Instructions

1. Review all changes since last release: `git log --oneline $(git describe --tags --abbrev=0)..HEAD`
2. Check for breaking changes:
   - Removed or renamed public APIs?
   - Changed default behaviors?
   - Incompatible configuration changes?
3. If breaking changes exist -> MAJOR bump
4. If new features exist -> MINOR bump
5. If only fixes/refactoring -> PATCH bump

## Version Update Locations

When bumping version, update `CHANGELOG.md`:

1. Add `## [X.Y.Z] - YYYY-MM-DD` section
2. Update comparison URLs at bottom of CHANGELOG.md
3. Update the `Unreleased` comparison URL at the bottom of CHANGELOG.md

## Release Process

After the changelog is updated and committed:

1. Create a signed tag with no `v` prefix: `git tag -s X.Y.Z -m "X.Y.Z"`
2. Push the tag: `git push origin X.Y.Z`
3. Create the GitHub release from the changelog section for that version:

   ```sh
   awk -v ver="X.Y.Z" '
     $0 ~ "^## \\[" ver "\\]" { inside=1; next }
     inside && (/^## \[/ || /^\[[^]]+\]: /) { inside=0 }
     inside { print }
   ' CHANGELOG.md > /tmp/release-notes.md

   printf '\n**Full Changelog**: %s\n' \
     "$(grep -E '^\[X\.Y\.Z\]: ' CHANGELOG.md | sed 's/^\[X\.Y\.Z\]: //')" \
     >> /tmp/release-notes.md

   gh release create X.Y.Z --title X.Y.Z --notes-file /tmp/release-notes.md --verify-tag
   ```

4. Verify the release exists and is marked latest: `gh release list`

Release notes are the changelog section verbatim, so the changelog stays the single source
of truth. `--verify-tag` fails the command rather than creating a tag that was never pushed.
