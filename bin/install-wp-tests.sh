#!/usr/bin/env bash
#
# Installs WordPress or ClassicPress plus its PHPUnit test library for plugin testing.
#
# Usage: install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [version] [skip-database-creation]
#
# version:
#   latest           Latest WordPress release (default).
#   6.2 | 6.2.13     A WordPress branch (resolved to its newest release) or exact release.
#   trunk            WordPress trunk.
#   classicpress:2.7.3  An exact ClassicPress release.
#
# Requires curl (or wget), tar, unzip, git and the mysql client.

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}
WP_CORE_DIR=$(echo "$WP_CORE_DIR" | sed -e "s:/\+$::")

set -e

download() {
	if command -v curl > /dev/null; then
		curl -sSfL "$1" > "$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

# Shallow, sparse checkout of the test library from a development repository.
# Usage: checkout_tests <repo-url> <ref>
checkout_tests() {
	local SRC="$TMPDIR/wp-tests-src"
	rm -rf "$SRC"
	git clone --quiet --depth=1 --branch "$2" --filter=blob:none --sparse "$1" "$SRC"
	git -C "$SRC" sparse-checkout set tests/phpunit/includes tests/phpunit/data
	mkdir -p "$WP_TESTS_DIR"
	cp -R "$SRC/tests/phpunit/includes" "$SRC/tests/phpunit/data" "$WP_TESTS_DIR/"
	cp "$SRC/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
	rm -rf "$SRC"
}

if [[ $VERSION == classicpress:* ]]; then
	PLATFORM=classicpress
	CP_VERSION=${VERSION#classicpress:}
	CORE_URL="https://github.com/ClassicPress/ClassicPress-release/archive/refs/tags/${CP_VERSION}.tar.gz"
	TESTS_REPO="https://github.com/ClassicPress/ClassicPress.git"
	# The development repository tags each release as <version>+dev.
	TESTS_REF="${CP_VERSION}+dev"
elif [[ $VERSION == 'trunk' || $VERSION == 'nightly' ]]; then
	PLATFORM=wordpress
	CORE_URL="https://wordpress.org/nightly-builds/wordpress-latest.zip"
	TESTS_REPO="https://github.com/WordPress/wordpress-develop.git"
	TESTS_REF="trunk"
else
	PLATFORM=wordpress
	if [[ $VERSION == 'latest' ]]; then
		download https://api.wordpress.org/core/version-check/1.7/ "$TMPDIR/wp-latest.json"
		WP_VERSION=$(grep -o '"version":"[^"]*' "$TMPDIR/wp-latest.json" | head -1 | sed 's/"version":"//')
	elif [[ $VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
		# A branch such as 6.2: use its newest release.
		download https://api.wordpress.org/core/stable-check/1.0/ "$TMPDIR/wp-versions.json"
		WP_VERSION=$(grep -oE "\"${VERSION//./\\.}(\.[0-9]+)?\"" "$TMPDIR/wp-versions.json" | tr -d '"' | sort -V | tail -1)
	else
		WP_VERSION=$VERSION
	fi
	if [[ -z $WP_VERSION ]]; then
		echo "Could not resolve WordPress version '$VERSION'"
		exit 1
	fi
	CORE_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
	TESTS_REPO="https://github.com/WordPress/wordpress-develop.git"
	TESTS_REF="$WP_VERSION"
fi

echo "Installing $PLATFORM from $CORE_URL with tests from $TESTS_REPO@$TESTS_REF"

set -x

install_core() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi
	mkdir -p "$WP_CORE_DIR"
	if [[ $CORE_URL == *.zip ]]; then
		download "$CORE_URL" "$TMPDIR/core.zip"
		unzip -q "$TMPDIR/core.zip" -d "$TMPDIR/core-unzip"
		mv "$TMPDIR"/core-unzip/*/* "$WP_CORE_DIR"
		rm -rf "$TMPDIR/core-unzip" "$TMPDIR/core.zip"
	else
		download "$CORE_URL" "$TMPDIR/core.tar.gz"
		tar --strip-components=1 -zxmf "$TMPDIR/core.tar.gz" -C "$WP_CORE_DIR"
		rm -f "$TMPDIR/core.tar.gz"
	fi
}

install_test_suite() {
	if [ -d "$WP_TESTS_DIR/includes" ]; then
		return
	fi
	checkout_tests "$TESTS_REPO" "$TESTS_REF"

	local CONFIG="$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$CONFIG"
	sed -i "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$CONFIG"
	sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$CONFIG"
	sed -i "s/yourusernamehere/$DB_USER/" "$CONFIG"
	sed -i "s/yourpasswordhere/$DB_PASS/" "$CONFIG"
	sed -i "s|localhost|${DB_HOST}|" "$CONFIG"
}

install_db() {
	if [ "${SKIP_DB_CREATE}" = "true" ]; then
		return 0
	fi

	# Parse DB_HOST for port or socket references.
	local PARTS=(${DB_HOST//\:/ })
	local DB_HOSTNAME=${PARTS[0]}
	local DB_SOCK_OR_PORT=${PARTS[1]}
	local EXTRA=""

	if [ -n "$DB_HOSTNAME" ]; then
		if [[ $DB_SOCK_OR_PORT =~ ^[0-9]+$ ]]; then
			EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
		elif [ -n "$DB_SOCK_OR_PORT" ]; then
			EXTRA=" --socket=$DB_SOCK_OR_PORT"
		else
			EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
		fi
	fi

	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS"$EXTRA
}

install_core
install_test_suite
install_db
