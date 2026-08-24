#!/bin/sh
# Runs inside the wordpress:cli container as the scheduler for the whole stack.
# DISABLE_WP_CRON is true in wp-config.php, so this loop is the only thing that
# makes scheduled events fire. Deliberately NOT `set -e`: a failing tick (WordPress
# not installed yet, database restarting) must not kill the container.

interval="${CRON_INTERVAL:-60}"
echo "[cron] loop started, interval ${interval}s"

while true; do
	if wp core is-installed --quiet 2>/dev/null; then
		wp cron event run --due-now 2>&1 | sed 's/^/[cron] /'
	else
		echo "[cron] WordPress not installed yet, waiting"
	fi
	sleep "${interval}"
done
