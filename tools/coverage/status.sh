#!/usr/bin/env bash
# Print crawler status: process state, recent progress, ETA, error count.
set -euo pipefail
cd "$(dirname "$0")"

PID=$(grep -Eo 'PID:[[:space:]]*[0-9]+' crawler-pids.txt 2>/dev/null | tail -1 | grep -Eo '[0-9]+' || true)
LOG=$(ls -t crawler-*.log 2>/dev/null | head -1 || true)

if [[ -z "$PID" || -z "$LOG" ]]; then
  echo "No crawler tracked. Check crawler-pids.txt and crawler-*.log."
  exit 1
fi

if ps -p "$PID" >/dev/null 2>&1; then
  STATE=$(ps -p "$PID" -o etime= | tr -d ' ')
  echo "STATUS  : RUNNING (pid=$PID, elapsed=$STATE)"
else
  echo "STATUS  : NOT RUNNING (pid=$PID)"
fi
echo "LOG     : $LOG"
echo

CHK="output/checkpoint.json"
if [[ -f "$CHK" ]]; then
  python3 - <<PY
import json, time
d = json.load(open("$CHK"))
pages = d["completed_pages"]
total_target = 29347
done = len(pages)
items = d["total_items"]
print(f"PAGES   : {done:>6} / {total_target}  ({100*done/total_target:5.1f}%)")
print(f"ITEMS   : {items:>6} hub events captured")
print(f"REMAIN  : {total_target - done} pages")
PY
fi

echo
echo "RECENT LOG:"
tail -5 "$LOG" | sed 's/^/  /'

echo
WARN=$(grep -cE "WARNING" "$LOG" 2>/dev/null; true)
ERR=$(grep -cE "ERROR" "$LOG" 2>/dev/null; true)
echo "WARNINGS: ${WARN:-0}  ERRORS: ${ERR:-0}"

# Compute pg/s from last two progress lines if present
LAST_TWO=$(grep "progress:" "$LOG" 2>/dev/null | tail -2 || true)
if [[ -n "$LAST_TWO" ]]; then
  echo
  echo "LAST TWO PROGRESS LINES:"
  echo "$LAST_TWO" | sed 's/^/  /'
fi
