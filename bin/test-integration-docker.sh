#!/usr/bin/env bash
# Disposable local integration environment. Optional first argument: release ZIP to install.
set -euo pipefail
repo=$(cd "$(dirname "$0")/.." && pwd)
package=${1:-}
if [[ -n "$package" && ! -f "$package" ]]; then echo "Missing ZIP: $package" >&2; exit 1; fi
prefix="scwc-test-$$"
cleanup() {
  docker rm -f "$prefix-wp" "$prefix-db" "$prefix-redis" >/dev/null 2>&1 || true
  docker volume rm "$prefix-wp" >/dev/null 2>&1 || true
  docker network rm "$prefix" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$prefix" >/dev/null
docker run --rm -d --name "$prefix-db" --network "$prefix" --network-alias db \
  -e MARIADB_ROOT_PASSWORD=test-only -e MARIADB_DATABASE=wordpress mariadb:10.11 >/dev/null
docker run --rm -d --name "$prefix-redis" --network "$prefix" --network-alias redis redis:7-alpine >/dev/null
docker run --rm -d --name "$prefix-wp" --network "$prefix" \
  -v "$prefix-wp:/var/www/html" \
  -e WP_CLI_ALLOW_ROOT=1 -e WP_CLI_PHP_ARGS=-d\ memory_limit=256M --user 0 \
  --entrypoint sh wordpress:cli-php8.3 -c 'sleep infinity' >/dev/null
db_ready=false
for i in $(seq 1 60); do
  if docker exec "$prefix-db" mariadb --host=127.0.0.1 -uroot -ptest-only wordpress -e 'SELECT 1' >/dev/null 2>&1; then
    db_ready=true
    break
  fi
  sleep 1
done
if [[ "$db_ready" != true ]]; then echo 'Test database did not become ready.' >&2; exit 1; fi
# Snapshot the source so concurrent local edits cannot affect a running test.
docker exec "$prefix-wp" mkdir /plugin
for path in src templates assets languages tests sidrena-cijena-za-woocommerce.php uninstall.php; do
  docker cp "$repo/$path" "$prefix-wp:/plugin/$path"
done
wp() { docker exec "$prefix-wp" php -d memory_limit=256M /usr/local/bin/wp "$@"; }
wp core download --version=6.8.3
wp config create --dbname=wordpress --dbuser=root --dbpass=test-only --dbhost=db
wp config set DISABLE_WP_CRON true --raw
wp core install --url=http://scwc-test.invalid --title=Test --admin_user=test --admin_password=test-only --admin_email=test@example.invalid --skip-email
wp option update timezone_string Europe/Zagreb
wp plugin install woocommerce --version=9.9.0 --activate
if [[ -n "$package" ]]; then
  docker cp "$package" "$prefix-wp:/release.zip"
  wp plugin install /release.zip --activate
else
  docker exec "$prefix-wp" ln -s /plugin /var/www/html/wp-content/plugins/sidrena-cijena-za-woocommerce
  wp plugin activate sidrena-cijena-za-woocommerce
fi
wp eval-file /plugin/tests/integration/first-offer.php --user=test
wp eval-file /plugin/tests/integration/updates.php --user=test
wp plugin install redis-cache --version=2.7.0 --activate
wp config set WP_REDIS_HOST redis
wp config set WP_REDIS_CLIENT predis
wp redis enable
wp redis status
# Save settings in a separate request before product creation, as in wp-admin.
wp eval-file /plugin/tests/integration/price-list.php configure --user=test
wp eval-file /plugin/tests/integration/price-list.php setup --user=test
# Fresh PHP process for every action, like separate web queue requests.
for i in $(seq 1 30); do
  wp action-scheduler run --hooks=scwc_generate_price_list,scwc_generate_price_list_batch --batch-size=1 --batches=1 --force
  result=$(wp eval-file /plugin/tests/integration/price-list.php step --user=test)
  echo "$result"
  if [[ "$result" == *'EXPORT_COMPLETE'* ]]; then break; fi
done
wp eval-file /plugin/tests/integration/price-list.php verify --user=test

wp eval-file /plugin/tests/integration/streaming.php --user=test
