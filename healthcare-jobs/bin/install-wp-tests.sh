#!/usr/bin/env bash
#
# Standard WordPress plugin test-suite installer.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Downloads WordPress core + the PHPUnit test scaffolding into a temp
# directory and creates the test database. Run this once before
# `vendor/bin/phpunit`.

set -eo pipefail

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress/}

download() {
	if [ "$(command -v curl)" ]; then
		curl -s "$1" > "$2"
	elif [ "$(command -v wget)" ]; then
		wget -nv -O "$2" "$1"
	fi
}

install_wp() {
	mkdir -p "$WP_CORE_DIR"
	download "https://wordpress.org/${WP_VERSION}.zip" "/tmp/wordpress.zip"
	unzip -q -o /tmp/wordpress.zip -d /tmp/
	cp -r /tmp/wordpress/* "$WP_CORE_DIR"
}

install_test_suite() {
	mkdir -p "$WP_TESTS_DIR"/includes "$WP_TESTS_DIR"/data
	svn export --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "$WP_TESTS_DIR"/includes
	svn export --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ "$WP_TESTS_DIR"/data

	download "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" "$WP_TESTS_DIR"/wp-tests-config.php
	sed -i.bak "s/youremptytestdbnamehere/${DB_NAME}/" "$WP_TESTS_DIR"/wp-tests-config.php
	sed -i.bak "s/yourusernamehere/${DB_USER}/" "$WP_TESTS_DIR"/wp-tests-config.php
	sed -i.bak "s/yourpasswordhere/${DB_PASS}/" "$WP_TESTS_DIR"/wp-tests-config.php
	sed -i.bak "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
	sed -i.bak "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}'|" "$WP_TESTS_DIR"/wp-tests-config.php
}

install_db() {
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" || true
}

install_wp
install_test_suite
install_db

echo "Done. Run: vendor/bin/phpunit"
