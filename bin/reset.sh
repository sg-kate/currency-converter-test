#!/usr/bin/env bash
# Destroys the database and everything Composer installed, then bootstraps again.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

printf '\033[33m!\033[0m This deletes the database, web/wp and vendor/.\n'
printf '  web/app is left alone, so your themes, plugins and uploads survive.\n'
if [ -t 0 ] && [ "${FORCE:-}" != "1" ]; then
	read -r -p '  Continue? [y/N] ' answer
	case "${answer}" in
		[yY]|[yY][eE][sS]) ;;
		*) echo '  aborted'; exit 1 ;;
	esac
fi

docker compose down -v --remove-orphans

# Composer runs as the host user, so these are removable without a helper
# container — unlike the files a root container would have written.
rm -rf web/wp vendor
printf '\033[32m✓\033[0m web/wp and vendor removed\n'

exec ./bin/bootstrap.sh
