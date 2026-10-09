# Contributing

## Versioning and releases

- The extension version lives in the `<version>` element of `info.xml`. Bump it, and
  `<releaseDate>`, on `master` as part of any release.
- Releases are cut from a `release` branch, not `master` directly, so that `.github`, `composer.lock`, `tests`, `.idea`, `.gitignore`, `CONTRIBUTING.md`, `DEVELOPERS.md`
  never reach client sites (civicrm.org's extension directory installs whatever a
  version tag points at, in full).
- To cut a release: bump `<version>`/`<releaseDate>` on `master` and merge that, then
  run **Cut release** from the Actions tab with a version matching what you just bumped.
  It builds the filtered `release` branch, tags it, and publishes the GitHub Release.
