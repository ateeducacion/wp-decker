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

# Bring up Docker containers in interactive mode
up: check-docker
	docker compose up

# Bring up Docker containers in background mode (daemon)
upd: check-docker
	docker compose up -d	

# Stop and remove Docker containers
down: check-docker
	docker compose down

# Pull the latest images from the registry
pull: check-docker
	docker compose pull

# Run the linter to check PHP code style
lint: phpcs

# Automatically fix PHP code style issues
fix: phpcbf

# Run unit tests with PHPUnit
test: phpunit

# Check code style with PHP-CS-Fixer
phpcs:
	composer --no-cache phpcs

# Automatically fix code style with PHP-CS-Fixer
phpcbf:
	composer --no-cache phpcbf

# Run unit tests with PHPUnit
phpunit:
	composer --no-cache phpunit

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
package:
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: No se ha especificado una versión. Usa 'make package VERSION=1.2.3'"; \
		exit 1; \
	fi
	# Update the version in decker.php
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           $(VERSION)/" decker.php
	$(SED_INPLACE) "s/define( 'DECKER_VERSION', '[^']*'/define( 'DECKER_VERSION', '$(VERSION)'/" decker.php

	# Create the ZIP package
	zip -r "decker-$(VERSION).zip" . -x ".*" "*/.*" "*.git*" "*.DS_Store" "Thumbs.db" ".github/*" "CHANGELOG.md" "README.md" "LICENSE.md" "sftp-config.json" "*.zip" "Makefile" ".gitlab-ci.yml" ".prettierrc" ".eslintrc" "docker-compose.yml" "vendor/*" "tests/*" "phpunit.xml.dist" "README.txt" "composer.json" "LICENSE.txt" "bin/*" "composer.lock" "CONVENTIONS.md" "*.po" "*.pot"

	# Restore the version in decker.php
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           0.0.0/" decker.php
	$(SED_INPLACE) "s/define( 'DECKER_VERSION', '[^']*'/define( 'DECKER_VERSION', '0.0.0'/" decker.php


# Show help with available commands
help:
	@echo "Comandos disponibles:"
	@echo "  up                - Bring up Docker containers in interactive mode"
	@echo "  upd               - Bring up Docker containers in background mode (daemon)"
	@echo "  down              - Stop and remove Docker containers"
	@echo "  pull              - Pull the latest images from the registry"
	@echo "  lint              - Run the linter to check PHP code style"
	@echo "  fix               - Automatically fix PHP code style issues"
	@echo "  test              - Run unit tests"
	@echo "  clean             - Clean and stop Docker containers, removing volumes and orphan containers"
	@echo "  phpcs             - Check code style with PHP-CS-Fixer"
	@echo "  phpcbf            - Automatically fix code style with PHP-CS-Fixer"
	@echo "  phpunit           - Run unit tests with PHPUnit"
	@echo "  shell             - Open a shell inside the wordpress container"
	@echo "  update            - Update Composer dependencies"
	@echo "  package           - Generate a .zip package"
	@echo "  help              - Show help with available commands"
	@echo "  pot               - Generate a .pot file for translations"
	@echo "  po                - Update .po files from .pot file"
	@echo "  mo                - Generate .mo files from .po files"

# Establecer la ayuda como el objetivo predeterminado si no se especifica ningún objetivo
.DEFAULT_GOAL := help
