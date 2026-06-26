# Release Process

Atlas Server uses a lightweight, automated release pipeline.

## Versioning

- Semantic versioning (`MAJOR.MINOR.PATCH`) is recorded in `APP_VERSION` / `ATLAS_VERSION` in `.env`.
- Git tags are prefixed with `v` (`v2.0.0`).

## How to release

1. Make sure `main` is green in GitHub Actions.
2. Run the release workflow manually from GitHub Actions (`workflow_dispatch`).
   It will bump the version tag and create a GitHub Release.
3. Or push a tag manually: `git tag -a v2.1.0 -m "Release 2.1.0" && git push origin v2.1.0`.
4. Release notes are auto-generated from merged PR labels.

## Release notes

The release notes are built from `.github/release-changelog-config.json`.
Every PR must have one of these labels:

- `feature`, `bug`, `security`, `refactor`, `test`, `docs`, `chore`

## Minimum release age

- Dependabot groups minor/patch updates and waits for CI to be green before auto-merging.
- No release is cut if the `main` branch CI is failing.

## Rollback

1. Revert the offending PR or commit.
2. Push a revert tag: `git tag -a v2.0.1-rollback -m "Rollback v2.1.0"`.
3. Re-run the release workflow to create a new patch release.
