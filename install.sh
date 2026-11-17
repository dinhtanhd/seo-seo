#!/usr/bin/env bash
set -Eeuo pipefail
PROJECT_ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
if [[ ${1:-} == --wizard ]] || { (($# == 0)) && [[ -t 0 ]]; }; then
    if [[ ${1:-} == --wizard ]]; then shift; fi
    command -v python3 >/dev/null || { printf 'Cần Python 3: dnf install -y python3\n' >&2; exit 1; }
    exec python3 "$PROJECT_ROOT/scripts/baota-wizard.py" "$@"
fi
exec bash "$PROJECT_ROOT/scripts/install-baota" "$@"
