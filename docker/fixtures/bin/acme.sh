#!/bin/sh
# Local/Docker stub for acme.sh — enough for Sync / cleanup UI demos.
set -eu
HOME_DIR=""
MODE=""
DOMAIN=""
ECC=0

while [ "$#" -gt 0 ]; do
  case "$1" in
    --home) HOME_DIR="$2"; shift 2 ;;
    --list|--listraw) MODE="list"; shift ;;
    --remove) MODE="remove"; shift ;;
    --deploy) MODE="deploy"; shift ;;
    --issue) MODE="issue"; shift ;;
    --renew) MODE="renew"; shift ;;
    -d|--domain) DOMAIN="$2"; shift 2 ;;
    --ecc) ECC=1; shift ;;
    *) shift ;;
  esac
done

FIXTURE_HOME="${HOME_DIR:-/var/www/html/docker/fixtures/acme.sh}"
LIST="$FIXTURE_HOME/list.raw"

case "$MODE" in
  remove)
    if [ -z "$DOMAIN" ]; then
      echo "Missing domain for --remove" >&2
      exit 1
    fi
    if [ -f "$LIST" ] && grep -q "^${DOMAIN}|" "$LIST"; then
      tmp="$(mktemp)"
      grep -v "^${DOMAIN}|" "$LIST" > "$tmp" || true
      mv "$tmp" "$LIST"
      echo "${DOMAIN} is removed, the key and cert files are in ${FIXTURE_HOME}/${DOMAIN}"
      echo "You can remove them by yourself."
      exit 0
    fi
    echo "${DOMAIN} is not in the list for --remove (stub)."
    exit 1
    ;;
  deploy|issue|renew)
    echo "Stub acme.sh ${MODE} for ${DOMAIN:-unknown} (ecc=${ECC}) — no-op success."
    exit 0
    ;;
  list|"")
    if [ -f "$LIST" ]; then
      cat "$LIST"
      exit 0
    fi
    echo "Main_Domain|KeyLength|SAN|CA|Created|Renew"
    echo "fixture.example.com|ec-256|www.fixture.example.com|ZeroSSL|2026-01-01|2026-09-01"
    exit 0
    ;;
  *)
    echo "Stub acme.sh: unsupported mode '${MODE}'" >&2
    exit 1
    ;;
esac
