#!/bin/sh
# Sends the same requests to the worker and to the classic container.
set -u

call() {
    printf '  %-26s ' "$2"
    curl -s -H 'Accept: application/json' "http://localhost:$1$2"
    echo
}

status() {
    printf '  %-26s ' "$2"
    curl -s -o /dev/null -w 'HTTP %{http_code}' "http://localhost:$1$2"
    echo
}

for mode in worker:8380 classic:8381; do
    name=${mode%%:*}
    port=${mode##*:}
    echo "=== $name mode (port $port)"
    call "$port" /setup
    echo " 1) SQL filters"
    call "$port" /notes
    call "$port" '/notes?admin=1'
    call "$port" /notes
    echo " 2) Transaction left open by a failed request"
    status "$port" /transaction/fail
    call "$port" /transaction/write
    call "$port" /transaction/committed
    echo
done
