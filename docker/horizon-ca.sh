#!/bin/sh
# Trust Lando's local certificate authority inside the Horizon container.
#
# Lando issues its own certificate for *.lndo.site and installs its CA into the
# appserver's trust store, but a `via: cli` service like this one gets no such
# treatment: the CA is mounted at /lando/certs but never registered. Horizon
# therefore could not call the local WordPress over HTTPS, failing with
# "cURL error 60: SSL certificate problem: unable to get local issuer
# certificate" while the same request from the appserver succeeded.
#
# This installs the CA properly rather than disabling verification. Turning
# verification off would have hidden a real class of transport bug and, worse,
# taught the codebase a habit that must never reach production.
#
# Runs as root, then hands off to the worker entrypoint as www-data. The
# hand-off lives here rather than in .lando.yml because Lando passes the command
# to docker-php-entrypoint, which does not interpret shell operators: an
# `A && B` command would silently run only A and the container would exit.
set -eu

CA_SOURCE="${LANDO_CA:-/lando/certs/LandoCA.crt}"
CA_TARGET="/usr/local/share/ca-certificates/LandoCA.crt"

install_ca() {
    if [ ! -f "$CA_SOURCE" ]; then
        echo "horizon: no Lando CA at $CA_SOURCE; HTTPS to *.lndo.site will not verify" >&2
        return 0
    fi

    if [ -f "$CA_TARGET" ] && cmp -s "$CA_SOURCE" "$CA_TARGET"; then
        echo "horizon: Lando CA already trusted"
        return 0
    fi

    mkdir -p "$(dirname "$CA_TARGET")"
    cp "$CA_SOURCE" "$CA_TARGET"

    # update-ca-certificates rebuilds /etc/ssl/certs/ca-certificates.crt, which
    # is what curl and PHP's OpenSSL both read.
    if command -v update-ca-certificates >/dev/null 2>&1; then
        update-ca-certificates >/dev/null
        echo "horizon: Lando CA installed into the system trust store"
    else
        echo "horizon: update-ca-certificates is unavailable; CA not registered" >&2
    fi
}

install_ca

# Drop to www-data so `lando horizon-restart`, which also runs as www-data, is
# permitted to signal the master process.
exec su www-data -s /bin/sh -c 'sh /app/docker/horizon-entrypoint.sh'
