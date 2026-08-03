# Makefile

# Define SED_INPLACE based on the operating system
ifeq ($(shell uname), Darwin)
  SED_INPLACE = sed -i ''
else
  SED_INPLACE = sed -i
endif

# ─── Port arbitration (local dev) ────────────────────────────────────────────
# wp-decker and the documentate plugin both default to ports 8888/8889, so only
# one wp-env stack can own them at a time. Before starting ours, stop whatever
# publishes the ports we need. `docker stop` (not `rm`) keeps the other stack's
# data — its own `make up` brings it back. Skipped under CI ($$CI set) and a
# no-op when Docker is down, so it only ever acts on a developer's machine —
# never stopping an environment CI just started.
# Usage: $(call free_ports,8888 8889)
define free_ports
	@if [ -z "$$CI" ] && docker version >/dev/null 2>&1; then \
		ids="$$(docker ps -q $(patsubst %,--filter publish=%,$(1)))"; \
		if [ -n "$$ids" ]; then \
			echo "Freeing port(s) '$(1)': stopping conflicting containers..."; \
			docker stop $$ids >/dev/null; \
		fi; \
	fi
endef

# Check if Docker is running
check-docker:
	@docker version  > /dev/null || (echo "" && echo "Error: Docker is not running. Please ensure Docker is installed and running." && echo "" && exit 1)

install-requirements:
	npm -g i @wordpress/env

# Ensure the environment is running. Used as a prerequisite by the test/check
# targets: the probe keeps repeated `make test` runs fast, and `wp-env start`
# is idempotent so re-running it is safe. Probes the development site (8888).
start-if-not-running: check-docker
	@if [ "$$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8888)" = "000" ]; then \
		echo "wp-env is not running. Starting..."; \
		npx wp-env start; \
		npx wp-env run cli wp plugin activate decker; \
		echo "Visit http://localhost:8888/wp-admin/ to access the Decker dashboard."; \
	else \
		echo "wp-env is already running."; \
	fi

# Bring up the environment. Always calls `wp-env start` (idempotent), so it
# (re)syncs the containers instead of skipping when something only appears up.
# WP_ENV_FLAGS passes options through to `wp-env start`; CI uses it to bring
# the environment up with Xdebug in coverage mode:
#   make up WP_ENV_FLAGS=--xdebug=coverage
WP_ENV_FLAGS ?=
up: check-docker
	$(call free_ports,8888 8889)
	npx wp-env start $(WP_ENV_FLAGS)
	-npx wp-env run cli wp plugin activate decker
	@echo "Visit http://localhost:8888/wp-admin/ to access the Decker dashboard."

# Alias for `up` (some folks type `make start`).
start: up

# Update WordPress core/themes and (re)start the environment.
update-env: check-docker
	npx wp-env start --update
	-npx wp-env run cli wp plugin activate decker

flush-permalinks:
	#npx wp-env run cli wp rewrite flush --hard
	npx wp-env run cli wp rewrite structure '/%postname%/'

# Function to create a user only if it does not exist
create-user:
	@if [ -z "$(USER)" ] || [ -z "$(EMAIL)" ] || [ -z "$(ROLE)" ]; then \
		echo "Error: Please, specify USER, EMAIL, ROLE and PASSWORD. Usage: make create-user USER=test1 EMAIL=test1@example.org ROLE=editor PASSWORD=password"; \
		exit 1; \
	fi
	npx wp-env run cli sh -c 'wp user list --field=user_login | grep -q "^$(USER)$$" || wp user create $(USER) $(EMAIL) --role=$(ROLE) --user_pass=$(PASSWORD)'

# Stop the environment (containers are stopped; data is preserved — use
# `destroy` to remove containers and volumes entirely).
down: check-docker
	npx wp-env stop

# Alias for `down` (some folks type `make stop`).
stop: down

# Clean the environments, the same that running "npx wp-env clean all"
clean:
	npx wp-env clean development
	npx wp-env clean tests

destroy:
	npx wp-env destroy

# Reset the WordPress databases to a fresh install, then reactivate the plugin.
reset: check-docker
	npx wp-env reset
	-npx wp-env run cli wp plugin activate decker

# Pass the wp plugin-check
check-plugin: check-docker start-if-not-running
	npx wp-env run cli wp plugin install plugin-check --activate --color
	npx wp-env run cli wp plugin check decker --exclude-directories=tests --exclude-checks=file_type,image_functions --ignore-warnings --color

# Combined check for lint, tests, untranslated, and more
check: fix lint check-plugin test check-untranslated mo

check-all: check

tests: test

# Run unit tests with PHPUnit. Use FILE or FILTER (or both).
test: start-if-not-running
	@CMD="./vendor/bin/phpunit"; \
	if [ -n "$(FILE)" ]; then CMD="$$CMD $(FILE)"; fi; \
	if [ -n "$(FILTER)" ]; then CMD="$$CMD --filter $(FILTER)"; fi; \
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker $$CMD --testdox --colors=always

# Run the PHPUnit suite with coverage, writing the reports CI uploads to
# Codecov (clover.xml) plus a text summary and a browsable HTML report.
# IMPORTANT: the containers need Xdebug in coverage mode, which is not the
# default. If the report comes out at 0%, restart the environment with:
#   npx wp-env start --xdebug=coverage
test-coverage: start-if-not-running
	@mkdir -p artifacts/coverage
	@CMD="env XDEBUG_MODE=coverage ./vendor/bin/phpunit --testdox --colors=always --coverage-text=artifacts/coverage/coverage.txt --coverage-html artifacts/coverage/html --coverage-clover artifacts/coverage/clover.xml"; \
	if [ -n "$(FILE)" ]; then CMD="$$CMD $(FILE)"; fi; \
	if [ -n "$(FILTER)" ]; then CMD="$$CMD --filter $(FILTER)"; fi; \
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker $$CMD; \
	EXIT_CODE=$$?; \
	echo ""; \
	grep -E "^\s*(Classes|Methods|Lines):" artifacts/coverage/coverage.txt 2>/dev/null || echo "Coverage data not available"; \
	echo "Full report: artifacts/coverage/html/index.html"; \
	exit $$EXIT_CODE

# Run unit tests in verbose mode. Honor TEST filter if provided.
test-verbose: start-if-not-running
	@CMD="./vendor/bin/phpunit"; \
	if [ -n "$(TEST)" ]; then CMD="$$CMD --filter $(TEST)"; fi; \
	CMD="$$CMD --debug --verbose"; \
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker $$CMD --colors=always

# Ensure tests environment has admin user and plugin active
setup-tests-env:
	@echo "Setting up tests environment..."
	@npx wp-env run tests-cli wp core install \
		--url=http://localhost:8889 \
		--title="Decker Tests" \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.com \
		--skip-email 2>/dev/null || true
	@npx wp-env run tests-cli wp plugin activate decker 2>/dev/null || true
	@npx wp-env run tests-cli wp rewrite structure '/%postname%/' --hard 2>/dev/null || true
	@# Keep the deterministic suite free of the external-service collaboration feature.
	@npx wp-env run tests-cli wp eval '$$o=get_option("decker_settings",array()); $$o["collaborative_editing"]="0"; update_option("decker_settings",$$o);' 2>/dev/null || true

# Run E2E tests with Playwright against wp-env tests environment (port 8889).
# The collaboration spec is excluded here (see test-e2e-collab).
test-e2e: start-if-not-running setup-tests-env
	WP_BASE_URL=http://localhost:8889 npm run test:e2e

test-e2e-visual: start-if-not-running setup-tests-env
	WP_BASE_URL=http://localhost:8889 npm run test:e2e -- --ui

# Run ONLY the collaboration spec. It needs the collaborative editing feature
# enabled and reaches external services (esm.sh CDN + signalling server), so it
# is kept out of the default CI gate. This enables the feature, runs the spec,
# and restores the setting afterwards regardless of the outcome.
test-e2e-collab: start-if-not-running setup-tests-env
	@npx wp-env run tests-cli wp eval '$$o=get_option("decker_settings",array()); $$o["collaborative_editing"]="1"; update_option("decker_settings",$$o);' 2>/dev/null || true
	-WP_BASE_URL=http://localhost:8889 DECKER_E2E_COLLAB=1 npm run test:e2e -- tests/e2e/specs/task-collaboration.spec.js
	@npx wp-env run tests-cli wp eval '$$o=get_option("decker_settings",array()); $$o["collaborative_editing"]="0"; update_option("decker_settings",$$o);' 2>/dev/null || true

test-js:
	npm run test:js

logs:
	npx wp-env logs

logs-test:
	npx wp-env logs --environment=tests


# Install PHP_CodeSniffer and WordPress Coding Standards in the container
install-phpcs: check-docker start-if-not-running
	@echo "Checking if PHP_CodeSniffer is installed..."
	@if ! npx wp-env run cli bash -c '[ -x "$$HOME/.composer/vendor/bin/phpcs" ]' > /dev/null 2>&1; then \
		echo "Installing PHP_CodeSniffer and WordPress Coding Standards..."; \
		npx wp-env run cli composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true; \
		npx wp-env run cli composer global require squizlabs/php_codesniffer wp-coding-standards/wpcs --no-interaction; \
	else \
		echo "PHP_CodeSniffer is already installed."; \
	fi


# Check code style with PHP Code Sniffer inside the container
lint: install-phpcs
	npx wp-env run cli phpcs --standard=wp-content/plugins/decker/.phpcs.xml.dist wp-content/plugins/decker

# Automatically fix code style with PHP Code Beautifier inside the container
fix: install-phpcs
	npx wp-env run cli phpcbf --standard=wp-content/plugins/decker/.phpcs.xml.dist wp-content/plugins/decker

# Run PHP Mess Detector ignoring vendor and node_modules
phpmd:
	phpmd . text cleancode,codesize,controversial,design,naming,unusedcode --exclude vendor,node_modules,tests

# Finds the CLI container used by wp-env
cli-container:
	@docker ps --format "{{.Names}}" \
	| grep "\-cli\-" \
	| grep -v "tests-cli" \
	|| ( \
		echo "No main CLI container found. Please run 'make up' first." ; \
		exit 1 \
	)

# Fix wihout tty for use on git hooks
fix-no-tty: cli-container start-if-not-running
	@CONTAINER_CLI=$$( \
		docker ps --format "{{.Names}}" \
		| grep "\-cli\-" \
		| grep -v "tests-cli" \
	) && \
	echo "Running PHPCBF (no TTY) inside $$CONTAINER_CLI..." && \
	docker exec -i $$CONTAINER_CLI \
		phpcbf --standard=wp-content/plugins/decker/.phpcs.xml.dist wp-content/plugins/decker

# Lint wihout tty for use on git hooks
lint-no-tty: cli-container start-if-not-running
	@CONTAINER_CLI=$$( \
		docker ps --format "{{.Names}}" \
		| grep "\-cli\-" \
		| grep -v "tests-cli" \
	) && \
	echo "Running PHPCS (no TTY) inside $$CONTAINER_CLI..." && \
	docker exec -i $$CONTAINER_CLI \
		phpcs --standard=wp-content/plugins/decker/.phpcs.xml.dist wp-content/plugins/decker


# Update Composer dependencies
update: check-docker
	composer update --no-cache --with-all-dependencies

# Generate a .pot file for translations
pot:
	composer make-pot

# Update .po files from .pot file
po:
	composer update-po

# Generate .mo files from .po files
mo:
	composer make-mo

# Generate .l10n.php files from .po files.
# WordPress 6.5+ loads these instead of the .mo when both are present; the .mo
# stays as the fallback for the 6.1-6.4 range declared in readme.txt.
l10n-php:
	composer make-php

# Check the untranslated strings
check-untranslated:
	composer check-untranslated

# Build and validate the runtime translation files that ship in the package.
# They are generated from the committed .po sources and deliberately kept out
# of the repository, so packaging must never assume they are already present.
package-translations: mo l10n-php
	@set -e; \
	found=0; \
	for po in languages/decker-*.po; do \
		if [ ! -e "$$po" ]; then continue; fi; \
		found=1; \
		mo="$${po%.po}.mo"; \
		l10n="$${po%.po}.l10n.php"; \
		for f in "$$mo" "$$l10n"; do \
			if [ ! -s "$$f" ]; then \
				echo "Error: Missing or empty generated translation file: $$f" >&2; \
				exit 1; \
			fi; \
		done; \
	done; \
	if [ "$$found" -eq 0 ]; then \
		echo "Error: No translation source files found under languages/." >&2; \
		exit 1; \
	fi

# Generate the decker-X.X.X.zip package
package: package-translations
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: No se ha especificado una versión. Usa 'make package VERSION=1.2.3'"; \
		exit 1; \
	fi
	# Update the version in decker.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           $(VERSION)/" decker.php
	$(SED_INPLACE) "s/define( 'DECKER_VERSION', '[^']*'/define( 'DECKER_VERSION', '$(VERSION)'/" decker.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: $(VERSION)/" readme.txt

	@# Create the ZIP package with proper folder structure.
	@# `wp dist-archive` reads .distignore straight from the working tree, so the
	@# runtime translations that .gitignore keeps out of the repository are
	@# packaged like any other file.
	@# --plugin-dirname is what makes the archive extract as decker/. Without it
	@# WordPress names the plugin folder after the ZIP file and every release
	@# lands in a new directory.
	./vendor/bin/wp dist-archive . "$(CURDIR)/decker-$(VERSION).zip" \
		--plugin-dirname=decker --force

	# Restore the version in decker.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           0.0.0/" decker.php
	$(SED_INPLACE) "s/define( 'DECKER_VERSION', '[^']*'/define( 'DECKER_VERSION', '0.0.0'/" decker.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: 0.0.0/" readme.txt

# Show help with available commands
help:
	@echo "Available commands:"
	@echo ""
	@echo "General:"
	@echo "  up / start         - Start the WordPress environment (idempotent)"
	@echo "  down / stop        - Stop the environment (data preserved)"
	@echo "  update-env         - Update WordPress core/themes and restart"
	@echo "  logs               - Show the docker container logs"
	@echo "  logs-test          - Show logs from test environment"
	@echo "  clean              - Reset both environments' databases"
	@echo "  reset              - Reset the development database to a fresh install"
	@echo "  destroy            - Remove the environment (containers and volumes)"
	@echo "  flush-permalinks   - Flush the created permalinks"
	@echo "  create-user        - Create a WordPress user if it doesn't exist."
	@echo "                       Usage: make create-user USER=<username> EMAIL=<email> ROLE=<role> PASSWORD=<password>"
	@echo ""
	@echo "Linting & Code Quality:"
	@echo "  fix                - Automatically fix code style with PHP_CodeSniffer"
	@echo "  lint               - Check code style with PHP_CodeSniffer"
	@echo "  fix-no-tty         - Same as 'fix' but without TTY (for git hooks)"
	@echo "  lint-no-tty        - Same as 'lint' but without TTY (for git hooks)"
	@echo "  check-plugin       - Run WordPress plugin-check tests"
	@echo "  check-untranslated - Check for untranslated strings"
	@echo "  check              - Run fix, lint, plugin-check, tests, untranslated, and mo"
	@echo "  check-all          - Alias for 'check'"
	@echo ""
	@echo "Testing:"
	@echo "  test               - Run PHPUnit tests. Accepts optional variables:"
	@echo "                       FILTER=<pattern> (run tests matching the pattern)"
	@echo "                       FILE=<path>      (run tests in specific file)"
	@echo "                       Examples:"
	@echo "                         make test FILTER=MyTest"
	@echo "                         make test FILE=tests/MyTest.php"
	@echo "                         make test FILE=tests/MyTest.php FILTER=test_my_feature"
	@echo ""
	@echo "  test-coverage      - Run PHPUnit with coverage into artifacts/coverage/"
	@echo "                       (needs: npx wp-env start --xdebug=coverage)"
	@echo "  test-js            - Run JavaScript unit tests with Jest"
	@echo "  test-e2e           - Run E2E tests (non-interactive)"
	@echo "  test-e2e-visual    - Run E2E tests with visual test UI"
	@echo ""
	@echo "Translations:"
	@echo "  pot                - Generate a .pot file for translations"
	@echo "  po                 - Update .po files from .pot file"
	@echo "  mo                 - Generate .mo files from .po files"
	@echo ""
	@echo "Packaging & Updates:"
	@echo "  update             - Update Composer dependencies"
	@echo "  package            - Create ZIP package. Usage: make package VERSION=x.y.z"
	@echo ""
	@echo "  help               - Show this help message"

# Set help as the default target if no target is specified
.DEFAULT_GOAL := help
