#!/bin/bash
set -e
cron
exec apache2-foreground
