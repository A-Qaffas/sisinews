#!/usr/bin/env bash
# Runs ON THE SERVER. Appends the nightly sisi.news backup to the account's crontab WITHOUT touching the
# lines already there (the account also runs another business's backup job).
#
#   ssh <host> 'bash -s' < deploy/remote-cron.sh
#
# Server time is MST (UTC-7): 17:41 MST = 00:41 UTC = 03:41 Cairo, the quietest hour for an Egyptian audience.
# The existing job runs at 03:17 MST, so the two never overlap.
set -euo pipefail

BK=/home/wxr46gn9f7ld/sisi.news-backups
TS="$(date +%Y%m%d-%H%M%S)"
JOB='41 17 * * * /bin/bash /home/wxr46gn9f7ld/sisi.news-backups/backup.sh >> /home/wxr46gn9f7ld/sisi.news-backups/cron.out 2>&1'

[ -x "$BK/backup.sh" ] || { echo "backup.sh is missing or not executable"; exit 1; }

crontab -l > "$BK/crontab.before.$TS"
if grep -qF 'sisi.news-backups/backup.sh' "$BK/crontab.before.$TS"; then
  echo "backup job already installed; nothing to do"
  rm -f "$BK/crontab.before.$TS"
  exit 0
fi

before="$(wc -l < "$BK/crontab.before.$TS")"
cp "$BK/crontab.before.$TS" "$BK/crontab.new.$TS"
printf '%s\n%s\n' '# sisi.news nightly backup (database + uploaded pictures, 14-day retention)' "$JOB" >> "$BK/crontab.new.$TS"

# Every original line must be untouched and exactly two lines (comment + job) added.
head -n "$before" "$BK/crontab.new.$TS" > "$BK/crontab.head.$TS"
diff "$BK/crontab.before.$TS" "$BK/crontab.head.$TS" >/dev/null || { echo "original lines would change: aborting"; exit 1; }
rm -f "$BK/crontab.head.$TS"
after="$(wc -l < "$BK/crontab.new.$TS")"
[ $((after - before)) -eq 2 ] || { echo "unexpected line count: aborting"; exit 1; }

crontab "$BK/crontab.new.$TS"
crontab -l | cmp - "$BK/crontab.new.$TS" && echo "crontab installed; previous version kept at $BK/crontab.before.$TS"
crontab -l
