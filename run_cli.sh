#!/bin/sh
# This script allows you to run the dokullm plugin CLI commands from the command line.
# Usage: ./run_cli.sh <command> [options]
sudo -u www-data php /var/www/html/dokuwiki/bin/plugin.php dokullm $@
