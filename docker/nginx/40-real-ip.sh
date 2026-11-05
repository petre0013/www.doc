#!/bin/sh
# Runs from the nginx image's /docker-entrypoint.d/ on every start.
#
# Decides which upstream hops may tell us the real client IP via
# X-Forwarded-For. PHP always receives the address nginx settled on (see the
# fastcgi_param HTTP_X_FORWARDED_FOR lines in default.conf) — a header the
# visitor typed themselves is never passed through. Without this, the app's
# per-IP login lockout and the audit-log IPs could be forged by any client.
#
#   REAL_IP_FROM unset/empty -> trust nobody: the connecting address is the
#                               client (nginx is the edge, e.g. the prod stack
#                               published on :8080).
#   REAL_IP_FROM="172.16.0.0/12, 10.0.0.5"
#                            -> those hops are proxies; take the client IP from
#                               the X-Forwarded-For they send.
set -eu

out=/etc/nginx/conf.d/00-real-ip.conf
: > "$out"

list=$(echo "${REAL_IP_FROM:-}" | tr ',' ' ')
[ -z "$(echo "$list" | tr -d ' ')" ] && { echo "real-ip: no trusted proxies; using the connecting address"; exit 0; }

{
    echo "real_ip_header X-Forwarded-For;"
    echo "real_ip_recursive on;"
} >> "$out"

for cidr in $list; do
    # IPs/CIDRs only — anything else would be injected into the nginx config.
    case "$cidr" in
        *[!0-9A-Fa-f:./]*) echo "real-ip: ignoring invalid REAL_IP_FROM entry '$cidr'" >&2; continue ;;
    esac
    echo "set_real_ip_from $cidr;" >> "$out"
done

echo "real-ip: trusting X-Forwarded-For from: $list"
