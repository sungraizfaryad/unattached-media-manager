#!/bin/zsh
PHP="/Users/sungraizfaryad/Library/Application Support/Local/lightning-services/php-8.4.18+1/bin/darwin-arm64/bin/php"
SOCK="/Users/sungraizfaryad/Library/Application Support/Local/run/8-CWukao6/mysql/mysqld.sock"
SITE="/Users/sungraizfaryad/Local Sites/flp/app/public"
"$PHP" -d memory_limit=1024M -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  /opt/homebrew/Cellar/wp-cli/2.12.0/bin/wp --path="$SITE" "$@"
