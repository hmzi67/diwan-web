#!/usr/bin/env bash
# READ-ONLY FTP probe. Answers one question: what is this account's login root?
#
# It logs in, prints the working directory, and lists folders. It never uploads,
# creates, or deletes anything. Safe to run against a live server.
#
#   FTP_HOST=… FTP_USERNAME=… FTP_PASSWORD=… bash scripts/ftp-probe.sh [os-path] [domain]
set -uo pipefail

HOST="${FTP_HOST:?set FTP_HOST}"
USER="${FTP_USERNAME:?set FTP_USERNAME}"
PASS="${FTP_PASSWORD:?set FTP_PASSWORD}"
OS_PATH="${1:-}"
DOMAIN="${2:-}"

ftp_do() {
  # ssl:verify-certificate no — shared hosts routinely present a certificate for
  # the hostname rather than the FTP vhost. Acceptable for a read-only probe;
  # never relax this for the real deploy.
  lftp -u "$USER","$PASS" "$HOST" <<LFTP 2>&1
set ssl:verify-certificate no
set net:timeout 20
set net:max-retries 1
set cmd:fail-exit no
$1
bye
LFTP
}

hdr() { printf '\n=== %s ===\n' "$1"; }

hdr "1. Login root (what the FTP daemon calls '/')"
ftp_do "pwd"

hdr "2. Contents of the login root"
ftp_do "cls -l ."

hdr "3. Does /public_html exist relative to the login root?"
ftp_do "cd /public_html; pwd; cls -l ."

hdr "4. Is there a 'domains' folder at the login root?"
ftp_do "cls -d /domains"

if [[ -n "$DOMAIN" ]]; then
  hdr "5. Does /domains/${DOMAIN}/public_html exist?"
  ftp_do "cd /domains/${DOMAIN}/public_html; pwd"
fi

if [[ -n "$OS_PATH" ]]; then
  hdr "6. Does the absolute OS path resolve over FTP?"
  echo "    ${OS_PATH}"
  echo "    (Expected to FAIL on a chrooted account.)"
  ftp_do "cd ${OS_PATH}; pwd"
fi

cat <<'NOTE'

=== HOW TO READ THIS ===

Step 1 prints the login root as the FTP daemon sees it. Every server-dir value
is resolved against that, NOT against the OS filesystem.

  If step 3 succeeds AND step 4 finds no 'domains' folder:
      the account is chrooted to the domain folder.
      FTP_SERVER_DIR = /public_html/
      APP_ROOT       = /app

  If step 4 DOES list a 'domains' folder (and step 5 succeeds):
      the account is scoped one level higher, at the account home.
      FTP_SERVER_DIR = /domains/<your-domain>/public_html/
      APP_ROOT       = /domains/<your-domain>/app

  Step 6 should FAIL on a chrooted account. If it SUCCEEDS, the account is not
  chrooted — both forms then work, but still prefer the relative one so a host
  migration that changes the home directory does not break the deploy.
NOTE
