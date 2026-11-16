#!/bin/sh
# Rebuild the demo database from scratch: both tenants back at flags version 1.
# Run it with the app's database settings: `docker compose run --rm cli bin/reset-demo-db.sh`.
# Deletes all data in the default database.
set -eu
bin/cake migrations rollback -t 0
bin/cake migrations migrate
bin/cake seeds run Demo --force
