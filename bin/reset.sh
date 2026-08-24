#!/usr/bin/env bash
# Destroys the stack including its volumes, then bootstraps it again.
# This is the only reliable way to re-apply WORDPRESS_CONFIG_EXTRA, because the
# official image writes wp-config.php exactly once — when it does not exist yet.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

printf '\033[33m!\033[0m This deletes the database and the ./wordpress directory,\n'
printf '  including anything you put in wp-content.\n'
if [ -t 0 ] && [ "${FORCE:-}" != "1" ]; then
	read -r -p '  Continue? [y/N] ' answer
	case "${answer}" in
		[yY]|[yY][eE][sS]) ;;
		*) echo '  aborted'; exit 1 ;;
	esac
fi

docker compose down -v --remove-orphans

# Core files are written by the container as uid 33, which the host user may not
# be able to remove — so delete them from inside a container running as root.
# Only ./wordpress is passed in, so this cannot reach the rest of the repository.
if [ -d wordpress ]; then
	docker run --rm --entrypoint sh \
		-v "$(pwd)/wordpress:/target" \
		wordpress:cli-php8.3 \
		-c 'rm -rf /target/..?* /target/.[!.]* /target/*' 2>/dev/null || true
	printf '\033[32m✓\033[0m ./wordpress emptied\n'
fi

exec ./bin/bootstrap.sh
