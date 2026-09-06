#!/usr/bin/env bash
# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright (C) 2026 Bijstaan
# Put glpisop into the state sop-check.js expects: the shipped example SOP
# published and enforcing, a trigger that matches on the title, and a fresh
# ticket carrying a run of it.
#
# Setup runs over HTTP and SQL rather than through the browser because none of
# it is what sop-check.js is testing, and driving GLPI's ticket form with
# Playwright to get one row is slow and brittle.
#
# Prints `TICKET_ID=<n>` so the caller can hand it to the check script.
set -u

BASE=${BASE:-http://localhost:8081}
JAR=$(mktemp)
DB="docker exec glpi-db-1 mariadb -uglpi -pglpi glpi"

csrf() { grep -oE 'name="_glpi_csrf_token" value="[^"]+"' | head -1 | cut -d'"' -f4; }

# No -X POST: with -L curl would re-POST to the redirect target, which GLPI
# rejects and which tears the session back down.
T=$(curl -sL -b "$JAR" -c "$JAR" "$BASE/index.php" | csrf)
curl -sL -o /dev/null -b "$JAR" -c "$JAR" "$BASE/front/login.php" \
  --data-urlencode login_name=glpi --data-urlencode login_password=glpi \
  --data-urlencode "_glpi_csrf_token=$T" --data-urlencode submit=Post

curl -sL -b "$JAR" -c "$JAR" "$BASE/front/central.php" | grep -q "Standard interface" \
  || { echo "login failed" >&2; exit 1; }

SOP=$($DB -N -e "SELECT id FROM glpi_plugin_glpisop_sops ORDER BY id LIMIT 1" 2>/dev/null)
[ -n "$SOP" ] || { echo "no SOP found — is the plugin installed?" >&2; exit 1; }

$DB -e "
UPDATE glpi_plugin_glpisop_sops
   SET is_active=1, is_autoattach=1, enforce_on_solve=1 WHERE id=$SOP;
DELETE FROM glpi_plugin_glpisop_triggers WHERE plugin_glpisop_sops_id=$SOP;
INSERT INTO glpi_plugin_glpisop_triggers
  (plugin_glpisop_sops_id, criterion, match_condition, value, date_creation)
VALUES ($SOP, 'name', 'contains', 'browsercheck', NOW());" 2>/dev/null

T=$(curl -sL -b "$JAR" -c "$JAR" "$BASE/front/ticket.form.php" | csrf)
curl -sL -o /dev/null -b "$JAR" -c "$JAR" "$BASE/front/ticket.form.php" \
  -d "_glpi_csrf_token=$T" -d add=1 -d entities_id=0 \
  --data-urlencode "name=browsercheck — account lockout for jsmith" \
  --data-urlencode "content=Cannot sign in after the password change." \
  -d urgency=3 -d impact=3 -d priority=3 -d type=1 -d _users_id_requester=2 -d status=1

TID=$($DB -N -e "SELECT MAX(id) FROM glpi_tickets" 2>/dev/null)
RUNS=$($DB -N -e "SELECT COUNT(*) FROM glpi_plugin_glpisop_runs WHERE items_id=$TID" 2>/dev/null)
[ "$RUNS" = "1" ] || { echo "the SOP did not attach to ticket $TID" >&2; exit 1; }

echo "TICKET_ID=$TID"
