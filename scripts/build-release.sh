#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
	echo "Usage: bash scripts/build-release.sh VERSION [OUTPUT_DIR]" >&2
	exit 2
fi

version="$1"
root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output_dir="${2:-$root_dir/dist}"

if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Version must use stable semantic versioning." >&2
	exit 1
fi

if ! grep -Eq "^[[:space:]]*\* Version:[[:space:]]+$version[[:space:]]*$" "$root_dir/wp-cortex.php"; then
	echo "wp-cortex.php does not contain version $version." >&2
	exit 1
fi

mkdir -p "$output_dir"
zip_path="$output_dir/wp-cortex-v${version}.zip"
checksum_path="${zip_path}.sha256"

if [[ -e "$zip_path" || -e "$checksum_path" ]]; then
	echo "Refusing to overwrite an existing release artifact in $output_dir." >&2
	exit 1
fi

git -C "$root_dir" archive --format=zip --prefix=wp-cortex/ HEAD -o "$zip_path"
unzip -t "$zip_path" >/dev/null
unzip -Z1 "$zip_path" | grep -Fxq "wp-cortex/wp-cortex.php"

printf '%s  %s\n' "$(sha256sum "$zip_path" | awk '{print $1}')" "$(basename "$zip_path")" > "$checksum_path"

echo "Built $zip_path"
echo "Wrote $checksum_path"
