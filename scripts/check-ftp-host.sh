#!/usr/bin/env bash
# Fail fast if FTP_HOST does not actually speak FTP.
#
# Why this exists: a hostname that serves the website is NOT necessarily the
# hostname that serves FTP. When a CDN or proxy sits in front of the web IPs,
# port 21 there simply black-holes, and the FTP action reports the generic
# "Timeout (control socket)" only after burning its full timeout — twice, if a
# retry is configured. This turns four confusing minutes into five clear seconds.
set -uo pipefail

HOST="${1-}"
PORT="${2:-21}"

if [ -z "$HOST" ]; then
  echo "::error::FTP host is empty."
  exit 1
fi

echo "Checking that ${HOST}:${PORT} answers as an FTP server…"

banner=$(python3 - "$HOST" "$PORT" <<'PY'
import socket, sys
host, port = sys.argv[1], int(sys.argv[2])
try:
    s = socket.create_connection((host, port), timeout=10)
    s.settimeout(10)
    try:
        print(s.recv(200).decode(errors="replace").split("\r\n")[0])
    except socket.timeout:
        print("__NO_BANNER__")
    s.close()
except Exception as e:
    print(f"__FAIL__ {type(e).__name__}: {e}")
PY
)

case "$banner" in
  2[0-9][0-9]*)
    echo "  OK — ${banner}"
    exit 0
    ;;
  __NO_BANNER__*)
    echo "::error::Connected to ${HOST}:${PORT} but got no FTP greeting within 10s."
    echo "::error::Something is listening, but it is not an FTP server — a CDN or proxy edge behaves exactly like this."
    ;;
  __FAIL__*)
    echo "::error::Cannot reach ${HOST}:${PORT} — ${banner#__FAIL__ }"
    ;;
  *)
    echo "::error::Unexpected greeting from ${HOST}:${PORT}: ${banner}"
    ;;
esac

cat >&2 <<'HINT'

  The FTP hostname is often NOT your website's domain. If your site sits behind
  a CDN, the domain's A records point at edge IPs that do not carry FTP.

  Find the correct value in your hosting panel under FTP Accounts, or try the
  ftp.<your-domain> hostname. Verify locally before re-running:

      python3 -c "import socket;s=socket.create_connection(('HOST',21),timeout=8);print(s.recv(100))"

  A working host answers with: 220 FTP Server ready.
HINT
exit 1
