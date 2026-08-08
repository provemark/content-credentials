#!/usr/bin/env bash
# `composer check`, with the output of a FAILING run kept on disk.
#
# Why this exists: an intermittent test failure has been seen five times and
# reproduced zero times. Four of those five, the evidence was lost — the run was
# piped through a grep, or re-run to confirm before it was read (NOTES Steps 20,
# 30, 31, 38, 39). Every one of those was a lapse of memory under a routine
# "just check it is green", and a habit that has failed four times will fail a
# fifth. So this is mechanical rather than behavioural.
#
# The check sequence itself is unchanged — this only decides what survives it.
# A green run leaves nothing behind; a red one leaves out/check-<stamp>.log,
# which is gitignored.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOG_DIR="$ROOT/out"
mkdir -p "$LOG_DIR"

# $$ rather than a sub-second timestamp: `date +%N` is a GNU extension, and on a
# stock macOS it emits a literal "N" instead of failing, so the `||` fallback
# never fires and two failures in the same second overwrite each other — losing
# exactly the evidence this script exists to keep. A pid is unique per run
# everywhere.
LOG="$LOG_DIR/check-$(date +%Y%m%d-%H%M%S)-$$.log"

# `check:run` is the real sequence. Calling `composer check` here would recurse.
composer check:run 2>&1 | tee "$LOG"
status=${PIPESTATUS[0]}

if [ "$status" -eq 0 ]; then
    rm -f "$LOG"
else
    echo
    echo "✗ check failed — full output kept at:"
    echo "  $LOG"
    echo
    echo "  Read it BEFORE re-running. The failure this exists for has never"
    echo "  reproduced on demand, so a second run is not a second chance."
fi

exit "$status"
