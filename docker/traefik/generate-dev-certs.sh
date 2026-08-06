#!/usr/bin/env bash
set -euo pipefail

DOMAIN="${APP_DOMAIN:-mrbills.localhost}"
CERT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/certs"

if ! command -v mkcert >/dev/null 2>&1; then
    echo "mkcert não encontrado. Instale antes de continuar (ex.: sudo apt-get install -y mkcert)." >&2
    exit 1
fi

mkcert -install

mkdir -p "$CERT_DIR"
mkcert -cert-file "$CERT_DIR/$DOMAIN.pem" -key-file "$CERT_DIR/$DOMAIN-key.pem" "$DOMAIN"

echo "Certificado gerado em $CERT_DIR para $DOMAIN"
