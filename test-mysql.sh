#!/usr/bin/env bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=8889 DB_DATABASE=canteen_test \
DB_USERNAME=root DB_PASSWORD=root ./vendor/bin/pest tests/Concurrency "$@"