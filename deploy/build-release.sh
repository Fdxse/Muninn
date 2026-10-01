#!/usr/bin/env bash
#
# Builds the Muninn deployment zip. Used by .github/workflows/release.yml and runnable locally.
#
#   deploy/build-release.sh <version>      e.g. deploy/build-release.sh v0.1.0
#
# Output: build/muninn-<version>.zip containing
#   api/        → copy to the NAS (its public/ folder is the API web root)
#   frontend/   → FTP the CONTENTS to the www.dx.se web root
#   DEPLOY.md   → step-by-step instructions
#   Deploy-Api.ps1
#
# Real config files (api/config/config.php, frontend includes/config.php), tests, logs and
# development tools are never included, so a deploy cannot overwrite server configuration.

set -euo pipefail

release_version="${1:?Usage: deploy/build-release.sh <version>}"
repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
staging_directory="$repository_root/build/muninn-$release_version"
zip_path="$repository_root/build/muninn-$release_version.zip"

rm -rf "$staging_directory" "$zip_path"
mkdir -p "$staging_directory/api/config" "$staging_directory/api/storage" "$staging_directory/frontend"

# --- API: production dependencies only (currently just Composer's autoloader). ---
api_build_directory="$(mktemp -d)"
cp -R "$repository_root/api/." "$api_build_directory/"
rm -rf "$api_build_directory/vendor" "$api_build_directory/config/config.php"
(cd "$api_build_directory" && composer install --no-dev --no-interaction --no-progress --optimize-autoloader --classmap-authoritative)

for api_item in public src bin migrations vendor composer.json composer.lock; do
    cp -R "$api_build_directory/$api_item" "$staging_directory/api/"
done
cp "$api_build_directory/config/config.example.php" "$staging_directory/api/config/"
touch "$staging_directory/api/storage/.gitkeep"
rm -rf "$api_build_directory"

# --- Frontend: the public folder, without the real config. ---
cp -R "$repository_root/frontend/public/." "$staging_directory/frontend/"
rm -f "$staging_directory/frontend/includes/config.php"
rm -f "$staging_directory/api/public/app-location.php"

# --- Instructions and the API deploy script. ---
cp "$repository_root/deploy/DEPLOY.md" "$repository_root/deploy/Deploy-Api.ps1" "$staging_directory/"
echo "$release_version" > "$staging_directory/VERSION"

# Safety net: refuse to ship anything that looks like a real config file.
if find "$staging_directory" -name 'config.php' | grep -q .; then
    echo "Refusing to build: a config.php ended up in the release." >&2
    exit 1
fi

(cd "$repository_root/build" && zip -qr "muninn-$release_version.zip" "muninn-$release_version")
echo "Built $zip_path"
