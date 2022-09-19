#!/bin/sh

set -e

CHOWN=$(/usr/bin/which chown)
SQUID=$(/usr/bin/which squid)

# Ensure permissions are set correctly on the Squid cache + log dir.
sudo "$CHOWN" -R squid:squid /var/cache/squid
sudo "$CHOWN" -R squid:squid /var/log/squid

# Clean SSL Squid DB
echo "Initializing SSL DB..."
sudo rm -rf /var/lib/ssl_db
sudo /usr/lib/squid/security_file_certgen -c -s /var/lib/ssl_db -M 1024

# Prepare the cache using Squid.
echo "Initializing cache..."
sudo "$SQUID" -z

# Give the Squid cache some time to rebuild.
sleep 5

# Launch squid
echo "Starting Squid..."
exec sudo "$SQUID" -NYCd 1
