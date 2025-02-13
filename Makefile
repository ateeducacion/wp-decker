# Makefile

# Define SED_INPLACE based on the operating system
ifeq ($(shell uname), Darwin)
  SED_INPLACE = sed -i ''
else
  SED_INPLACE = sed -i
endif


# Check if Docker is running
check-docker:
	@docker version  > /dev/null || (echo "" && echo "Error: Docker is not running. Please ensure Docker is installed and running." && echo "" && exit 1)

# Bring up Docker containers
up: check-docker
	npx wp-env start --update
	$(MAKE) create-user USER=test1 EMAIL=test1@example.org ROLE=editor PASSWORD=password
	$(MAKE) create-user USER=test2 EMAIL=test2@example.org ROLE=editor PASSWORD=password
	npx wp-env run cli wp plugin activate decker

# Function to create a user only if it does not exist
create-user:
	@if [ -z "$(USER)" ] || [ -z "$(EMAIL)" ] || [ -z "$(ROLE)" ]; then \
		echo "Error: Please, specify USER, EMAIL, ROLE and PASSWORD. Usage: make create-user USER=test1 EMAIL=test1@example.org ROLE=editor PASSWORD=password"; \
		exit 1; \
	fi
	npx wp-env run cli sh -c 'wp user list --field=user_login | grep -q "^$(USER)$$" || wp user create $(USER) $(EMAIL) --role=$(ROLE) --user_pass=$(PASSWORD)'

# Stop and remove Docker containers
down: check-docker
	npx wp-env stop

# Clean the environments, the same that running "npx wp-env clean all"
clean:
	npx wp-env clean development
	npx wp-env clean tests

destroy:
	npx wp-env destroy

# Pass the wp plugin-check
check-plugin: up
	npx wp-env run cli wp plugin install plugin-check --activate --color
	npx wp-env run cli wp plugin check decker --exclude-directories=tests --exclude-checks=file_type,image_functions --ignore-warnings --color

# Combined check for lint, tests, untranslated, and more
check: fix lint check-plugin test check-untranslated mo

check-all: check

# Run unit tests with PHPUnit
tests: test
test:
# 	npx wp-env start
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit --testdox --colors=always

test-verbose:
# 	npx wp-env start
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit --debug --verbose --colors=always

logs:
	npx wp-env logs

# Check code style with PHP-CS-Fixer
lint:
	composer --no-cache phpcs --colors=always

# Automatically fix code style with PHP-CS-Fixer
fix:
	composer --no-cache phpcbf --colors=always

# Update Composer dependencies
update: check-docker
	npx wp-env update
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

# Check the untranslated strings
check-untranslated:
	composer check-untranslated

# Generate the decker-X.X.X.zip package
package:
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: No se ha especificado una versión. Usa 'make package VERSION=1.2.3'"; \
		exit 1; \
	fi
	# Update the version in decker.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           $(VERSION)/" decker.php
	$(SED_INPLACE) "s/define( 'DECKER_VERSION', '[^']*'/define( 'DECKER_VERSION', '$(VERSION)'/" decker.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: $(VERSION)/" readme.txt

	# Create the ZIP package
	composer archive --format=zip --file="decker-$(VERSION)"

	# Restore the version in decker.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           0.0.0/" decker.php
	$(SED_INPLACE) "s/define( 'DECKER_VERSION', '[^']*'/define( 'DECKER_VERSION', '0.0.0'/" decker.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: 0.0.0/" readme.txt

# Show help with available commands
help:
	@echo "Available commands:"
	@echo "  up                 - Bring up Docker containers in interactive mode"
	@echo "  up                 - Bring up Docker containers in interactive mode"
	@echo "  down               - Stop and remove Docker containers"
	@echo "  logs               - Show the docker container logs"
	@echo "  fix                - Automatically fix code style with PHP-CS-Fixer"
	@echo "  lint               - Check code style with PHP-CS-Fixer"
	@echo "  check-plugin       - Run WordPress plugin-check tests"
	@echo "  test               - Run unit tests"
	@echo "  check-untranslated - Check the untranslated strings"
	@echo "  check/check-all    - Run fix, lint, check-pugin, test, check-untraslated, mo"
	@echo "  update             - Update Composer dependencies"
	@echo "  package            - Generate a .zip package"
	@echo "  destroy            - Destroy the WordPress environment"
	@echo "  create-user        - Create a WordPress user if it doesn't exist. Usage: make create-user USER=<username> EMAIL=<email> ROLE=<role> PASSWORD=<password>"
	@echo "  clean              - Clean up WordPress environment"
	@echo "  help               - Show help with available commands"
	@echo "  pot                - Generate a .pot file for translations"
	@echo "  po                 - Update .po files from .pot file"
	@echo "  mo                 - Generate .mo files from .po files"


# Set help as the default target if no target is specified
.DEFAULT_GOAL := help
