# WP Cortex release process

WP Cortex releases are published from `main` through the GitHub Actions workflows. The WordPress updater consumes only the stable GitHub release asset produced by `Publish release`.

## Before preparing a release

1. Record every user-visible change in `CHANGELOG.md` under `## [Unreleased]`.
2. Keep the working tree clean and merge all intended feature pull requests into `main`.
3. Choose the next stable semantic version. The release helper accepts only `A.B.C` versions greater than the current version.

## Prepare the release

1. Open **Actions > Prepare release** in GitHub.
2. Run it from the `main` branch and enter the next version, for example `0.3.0`.
3. The workflow validates the release metadata, bumps the plugin header, `WP_CORTEX_VERSION` and `README.md`, moves the `Unreleased` entries into a dated version section, pushes `release/vX.Y.Z` and opens a pull request.
4. Review the pull request and merge it into `main`.

The same preparation can be run locally with `python3 scripts/release.py --version 0.3.0`. It only edits release metadata; it does not commit, tag or push.

## Publish the release

1. After the preparation pull request is merged, open **Actions > Publish release**.
2. Run it from `main` with the same version.
3. The workflow validates the merged metadata, runs PHP and JavaScript syntax checks, builds `wp-cortex-vX.Y.Z.zip` with the required `wp-cortex/` plugin root, writes a SHA-256 checksum, creates the `vX.Y.Z` tag and publishes the GitHub release with both artifacts.

Do not create a release manually without the ZIP asset. WordPress's update response rejects releases that do not contain the exact `wp-cortex-vX.Y.Z.zip` package built by the workflow.

## How WordPress updates the plugin

The plugin declares the GitHub `Update URI` in its main file and uses WordPress's native `update_plugins_github.com` hook. It checks the public `releases/latest` endpoint, accepts only non-draft, non-prerelease `vX.Y.Z` releases with the expected ZIP asset, and caches the metadata for 12 hours. **Dashboard > Updates > Check Again** clears this cache.

When a newer release is available, WordPress puts it in the normal plugin update transient. The Plugins screen therefore shows the standard update row and button, and WordPress's normal `Plugin_Upgrader` installs the release ZIP. The updater does not enable automatic updates and does not require a GitHub token.
