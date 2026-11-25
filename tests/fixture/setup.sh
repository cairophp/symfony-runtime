#!/usr/bin/env bash
# setup.sh: installs the fixture app's packages, with this package from the
# working tree (a path repository), for tests/ServerTest.php.
set -euo pipefail
cd "$(dirname "$0")"
rm -f composer.lock
# Symfony's runtime is a Composer plugin: it writes vendor/autoload_runtime.php.
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress --quiet
