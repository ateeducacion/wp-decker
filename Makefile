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


# Stop and remove Docker containers
down: check-docker
	npx wp-env stop

# Run the linter to check PHP code style
lint: phpcs

# Automatically fix PHP code style issues
fix: phpcbf

# Run unit tests with PHPUnit
test:
	npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit

logs:
	npx wp-env logs

# Check code style with PHP-CS-Fixer
phpcs:
	composer --no-cache phpcs

# Automatically fix code style with PHP-CS-Fixer
phpcbf:
	composer --no-cache phpcbf

# Open a shell inside the wordpress container
shell: check-docker
	docker compose exec wordpress sh

# Clean and stop Docker containers, removing volumes and orphan containers
clean: check-docker
	docker compose down -v --remove-orphans

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

wp-env-update:
	wp-env start --update

wp-env-composer:
	npm run wp-env run cli composer install

wp-env-test:
	npm run wp-env run tests-cli phpunit

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
	zip -r "decker-$(VERSION).zip" . -x ".*" "*/.*" "*.git*" "*.DS_Store" "Thumbs.db" ".github/*" "CHANGELOG.md" "README.md" "LICENSE.md" "sftp-config.json" "*.zip" "Makefile" ".gitlab-ci.yml" ".prettierrc" ".eslintrc" "docker-compose.yml" "vendor/*" "tests/*" "phpunit.xml.dist" "README.txt" "composer.json" "LICENSE.txt" "bin/*" "wp-content/*" "wp/*" "composer.lock" "CONVENTIONS.md" "*.po" "*.pot"

	# Restore the version in decker.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           0.0.0/" decker.php
	$(SED_INPLACE) "s/define( 'DECKER_VERSION', '[^']*'/define( 'DECKER_VERSION', '0.0.0'/" decker.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: 0.0.0/" readme.txt

# Show help with available commands
help:
	@echo "Comandos disponibles:"
	@echo "  up                 - Bring up Docker containers in interactive mode"
	@echo "  upd                - Bring up Docker containers in background mode (daemon)"
	@echo "  down               - Stop and remove Docker containers"
	@echo "  pull               - Pull the latest images from the registry"
	@echo "  lint               - Run the linter to check PHP code style"
	@echo "  fix                - Automatically fix PHP code style issues"
	@echo "  test               - Run unit tests"
	@echo "  clean              - Clean and stop Docker containers, removing volumes and orphan containers"
	@echo "  phpcs              - Check code style with PHP-CS-Fixer"
	@echo "  phpcbf             - Automatically fix code style with PHP-CS-Fixer"
	@echo "  shell              - Open a shell inside the wordpress container"
	@echo "  update             - Update Composer dependencies"
	@echo "  package            - Generate a .zip package"
	@echo "  help               - Show help with available commands"
	@echo "  pot                - Generate a .pot file for translations"
	@echo "  po                 - Update .po files from .pot file"
	@echo "  mo                 - Generate .mo files from .po files"
	@echo "  check-untranslated - Check the untranslated strings"

# Set help as the default target if no target is specified
.DEFAULT_GOAL := help
