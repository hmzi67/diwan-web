#!/usr/bin/env bash
# Refuses to let a deploy start with an unusable server path.
#
# The failure this exists to prevent: if the path variable is unset, the
# expression in deploy.yml collapses to an empty string, server-dir becomes "/",
# and the entire site is uploaded to the FTP login root instead of the web root.
# On a live server that is a mess to clean up by hand, so it is a hard stop.
set -Eeuo pipefail

WEB="${1-}"
APP="${2-}"
fail=0

check() {
  local name="$1" value="$2"

  if [[ -z "$value" ]]; then
    echo "::error::${name} is empty. Set it as a repository or environment Variable before deploying."
    fail=1
    return
  fi

  if [[ "$value" != */ ]]; then
    echo "::error::${name}='${value}' must end with a trailing slash — SamKirkland/FTP-Deploy-Action requires it."
    fail=1
  fi

  # An OS-absolute path only works if the FTP account is NOT chrooted. If it is
  # chrooted, basic-ftp's ensureDir will MKD each segment and silently build a
  # bogus ~/home/u.../ tree, then report success. Block it until proven.
  if [[ "$value" == /home/* || "$value" == /var/* || "$value" == /usr/* ]]; then
    echo "::error::${name}='${value}' looks like an absolute OS filesystem path."
    echo "::error::server-dir is resolved by the FTP daemon, not the OS. If the account is chrooted this creates a fake directory tree and 'succeeds' in the wrong place."
    echo "::error::Run the 'FTP path probe' workflow to confirm the login root, then use the path relative to it."
    fail=1
  fi

  echo "  ${name} = ${value}"
}

echo "Preflight: server paths"
check "FTP_SERVER_DIR" "$WEB"
[[ -n "${APP+x}" ]] && check "APP_ROOT" "$APP"

echo
if [[ $fail -ne 0 ]]; then
  echo "::error::Preflight failed. Nothing was uploaded."
  exit 1
fi
echo "Preflight passed."
