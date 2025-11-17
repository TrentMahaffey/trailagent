#!/bin/bash
set -e

# Run our init script in the background
/usr/local/bin/docker-entrypoint-init.sh &

# Execute the original WordPress entrypoint
exec docker-entrypoint.sh "$@"
