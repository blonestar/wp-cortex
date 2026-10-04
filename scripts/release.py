#!/usr/bin/env python3
"""Prepare, validate and export WP Cortex release notes."""

from __future__ import annotations

import argparse
import datetime
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
VERSION_RE = re.compile(r"\d+\.\d+\.\d+")


class ReleaseError(Exception):
    """Raised when a release cannot be prepared safely."""


def read_versions() -> dict[str, str]:
    """Read the version from every release-controlled file."""
    sources = {
        "plugin header": (ROOT / "wp-cortex.php", re.compile(r"^\s*\* Version:\s+(\d+\.\d+\.\d+)\s*$", re.MULTILINE)),
        "plugin constant": (ROOT / "wp-cortex.php", re.compile(r"^define\( 'WP_CORTEX_VERSION', '(\d+\.\d+\.\d+)' \);$", re.MULTILINE)),
        "readme": (ROOT / "README.md", re.compile(r"^- Version:\s+(\d+\.\d+\.\d+)$", re.MULTILINE)),
    }
    versions: dict[str, str] = {}

    for name, (path, pattern) in sources.items():
        match = pattern.search(path.read_text(encoding="utf-8"))
        if match is None:
            raise ReleaseError(f"Could not find the {name} version in {path.name}.")
        versions[name] = match.group(1)

    if len(set(versions.values())) != 1:
        raise ReleaseError(f"Release-controlled versions do not match: {versions}")

    return versions


def validate_version(version: str) -> tuple[int, int, int]:
    """Validate a stable semantic version and return comparable parts."""
    if VERSION_RE.fullmatch(version) is None:
        raise ReleaseError("Version must use stable semantic versioning, for example 0.3.0.")
    return tuple(int(part) for part in version.split("."))  # type: ignore[return-value]


def release_section(changelog: str, version: str) -> str:
    """Return the body of a versioned changelog section."""
    pattern = re.compile(rf"^## \[{re.escape(version)}\][^\n]*\n(?P<body>.*?)(?=^## \[|\Z)", re.MULTILINE | re.DOTALL)
    match = pattern.search(changelog)
    if match is None or not match.group("body").strip():
        raise ReleaseError(f"Changelog section for {version} is missing or empty.")
    return match.group("body").strip()


def verify_release(version: str) -> str:
    """Verify that the repository is internally consistent for a release."""
    target = validate_version(version)
    versions = read_versions()

    if versions["plugin header"] != version:
        raise ReleaseError(f"Repository version is {versions['plugin header']}, not {version}.")

    release_section((ROOT / "CHANGELOG.md").read_text(encoding="utf-8"), version)
    return version


def prepare_release(version: str, release_date: str) -> None:
    """Move Unreleased notes into a dated release and bump all version sources."""
    target = validate_version(version)
    versions = read_versions()
    current = validate_version(versions["plugin header"])

    if target <= current:
        raise ReleaseError(f"New version {version} must be greater than current version {versions['plugin header']}.")

    changelog_path = ROOT / "CHANGELOG.md"
    changelog = changelog_path.read_text(encoding="utf-8")
    unreleased_pattern = re.compile(r"^## \[Unreleased\]\s*\n(?P<body>.*?)(?=^## \[|\Z)", re.MULTILINE | re.DOTALL)
    unreleased = unreleased_pattern.search(changelog)

    if unreleased is None or not unreleased.group("body").strip():
        raise ReleaseError("The Unreleased changelog section is empty.")

    if re.search(rf"^## \[{re.escape(version)}\]", changelog, re.MULTILINE):
        raise ReleaseError(f"Changelog already contains a {version} section.")

    release_block = (
        "## [Unreleased]\n\n"
        f"## [{version}] - {release_date}\n\n"
        f"{unreleased.group('body').strip()}\n\n"
    )
    changelog = changelog[: unreleased.start()] + release_block + changelog[unreleased.end() :].lstrip("\n")
    changelog_path.write_text(changelog.rstrip() + "\n", encoding="utf-8")

    plugin_path = ROOT / "wp-cortex.php"
    plugin = plugin_path.read_text(encoding="utf-8")
    plugin = re.sub(
        r"^(\s*\* Version:\s+)\d+\.\d+\.\d+(\s*)$",
        lambda match: f"{match.group(1)}{version}{match.group(2)}",
        plugin,
        count=1,
        flags=re.MULTILINE,
    )
    plugin = re.sub(
        r"^(define\( 'WP_CORTEX_VERSION', ')\d+\.\d+\.\d+(' \);)$",
        lambda match: f"{match.group(1)}{version}{match.group(2)}",
        plugin,
        count=1,
        flags=re.MULTILINE,
    )
    plugin_path.write_text(plugin, encoding="utf-8")

    readme_path = ROOT / "README.md"
    readme = readme_path.read_text(encoding="utf-8")
    readme = re.sub(r"^- Version:\s+\d+\.\d+\.\d+$", f"- Version: {version}", readme, count=1, flags=re.MULTILINE)
    readme_path.write_text(readme, encoding="utf-8")


def main() -> int:
    """Run the requested release operation."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", required=True, help="Stable release version, for example 0.3.0.")
    parser.add_argument("--date", default=datetime.datetime.now(datetime.timezone.utc).date().isoformat())
    parser.add_argument("--check", action="store_true", help="Validate an already prepared release.")
    parser.add_argument("--notes", action="store_true", help="Print the prepared release notes.")
    args = parser.parse_args()

    if args.check and args.notes:
        parser.error("--check and --notes cannot be used together")

    try:
        if args.check or args.notes:
            changelog = (ROOT / "CHANGELOG.md").read_text(encoding="utf-8")
            notes = release_section(changelog, verify_release(args.version))
            if args.notes:
                print(notes)
            else:
                print(f"Release {args.version} is internally consistent.")
        else:
            prepare_release(args.version, args.date)
            print(f"Prepared release {args.version}.")
    except (OSError, ReleaseError) as error:
        print(f"Release preparation failed: {error}", file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
