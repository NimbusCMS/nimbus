#!/bin/bash
# Create one database + one least-privilege user per site (granted ONLY on its
# own schema — a compromised site container can never reach another's data).
set -e
for s in A B C D; do
  db=$(eval echo "\$SITE_${s}_DB"); user=$(eval echo "\$SITE_${s}_USER"); pass=$(eval echo "\$SITE_${s}_PASS")
  [ -n "$db" ] && [ -n "$user" ] && [ -n "$pass" ] || continue
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$user'@'%' IDENTIFIED BY '$pass';
GRANT ALL PRIVILEGES ON \`$db\`.* TO '$user'@'%';
FLUSH PRIVILEGES;
SQL
  echo "seeded db+user for site $s: $db / $user"
done
