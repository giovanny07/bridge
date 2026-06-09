# Contributing to Bridge

Thanks for contributing! This guide covers local development, the translation
workflow, and how releases are cut.

## Development setup

```bash
git clone git@github.com:Imagunet-S-A-S/bridge.git bridge
cd bridge
composer install
```

The plugin targets **GLPI 11** and **PHP >= 8.1**. Classes are PSR-4 autoloaded
under the `GlpiPlugin\Bridge\` namespace (`src/`). Match the style of the
surrounding code.

## Tests

```bash
composer test       # unit tests (tests/units/)
composer test:api   # API-group tests (tests/api/)
```

## Translations

Source strings live in `locales/*.po`. After editing a `.po` file, recompile the
binary `.mo` files and commit both:

```bash
php tools/compile-mo.php
git add locales/*.po locales/*.mo
```

The `.mo` files are tracked and shipped in the release zip as-is, so they must be
kept in sync with the `.po` sources.

## Versioning

This project follows [Semantic Versioning](https://semver.org/). The version
string lives in three places, kept in sync by the bump script:

- `setup.php` — `PLUGIN_BRIDGE_VERSION`
- `composer.json` — `version`
- `README.md` — `## Status — vX.Y.Z`

`PLUGIN_BRIDGE_MIN_GLPI` / `PLUGIN_BRIDGE_MAX_GLPI` are GLPI **compatibility**
bounds, not the plugin version — edit those by hand only when compatibility
actually changes.

## Cutting a release

1. Update `CHANGELOG.md`: move the relevant `Unreleased` notes under a new
   `## [X.Y.Z] - <date>` heading and refresh the compare links at the bottom.
2. Bump, commit, and tag in one step:
   ```bash
   tools/bump-version.sh X.Y.Z
   ```
   This rewrites the three version locations, creates a `Release vX.Y.Z` commit,
   and tags `vX.Y.Z`. It does **not** push.
3. Push to trigger the release:
   ```bash
   git push origin main --tags
   ```

Pushing a `v*.*.*` tag runs `.github/workflows/release.yml`, which packages the
plugin and publishes it to S3.

## CI / CD

- **`.github/workflows/ci.yml`** runs on every push and PR to `main`: PHP syntax
  lint and a `git archive` zip-build smoke test.
- **`.github/workflows/release.yml`** runs on `v*.*.*` tags (or manual dispatch).
  It assumes an AWS IAM role via OIDC and runs `scripts/release.sh`, which:
  - packages the plugin with `git archive` (prefix `bridge/`),
  - uploads `bridge-vX.Y.Z.zip` to `s3://imagu-binaries/bridge/<vX.Y.Z>/`,
  - updates `manifest.json` (`versions[]` + `latest`),
  - re-publishes `install.sh`.

### Required repository configuration

The release workflow needs one repository **variable**:

- `AWS_ROLE_ARN` — the ARN of an IAM role whose trust policy accepts GitHub OIDC
  tokens from `Imagunet-S-A-S/bridge`, with write access to
  `s3://imagu-binaries/bridge`.

Set it under **Settings → Secrets and variables → Actions → Variables**.

## Packaging contents

The release zip includes runtime files only:

```
setup.php hook.php composer.json index.html LICENSE
ajax/ front/ src/ css/ js/ public/ locales/ templates/
```

Excluded: `tests/`, `tools/`, `scripts/`, `.github/`, `README.md`,
`CHANGELOG.md`, `CONTRIBUTING.md`, `.gitignore`.
