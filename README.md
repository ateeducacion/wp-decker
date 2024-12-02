# Decker

![WordPress Version](https://img.shields.io/badge/WordPress-6.1-blue)
![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.0-8892bf)
![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)


**Decker** is a WordPress plugin developed for internal use at the Área de Tecnología Educativa (ATE). Its main goal is to efficiently present a task list with a simple Kanban board interface and unique priority system.

### Key Features

- **Import from Nextcloud Deck through API**: Imports boards and cards from Nextcloud.
- **Customization**: Adjustable settings available in the WordPress admin panel.
- **Multisite Support**: Fully compatible with WordPress multisite installations.
- **WordPress Coding Standards Compliance**: Adheres to [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards) for quality and security.
- **Continuous Integration Pipeline**: Set up for automated code verification and release generation on GitLab.

## Installation

1. **Download the latest release** from the [GitHub Releases page](https://github.com/ateeducacion/wp-decker/releases).
2. Upload the downloaded ZIP file to your WordPress site via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Configure the plugin under 'Settings' by providing the necessary Nextcloud API details.

## Development

For development, you can bring up a local WordPress environment with the plugin pre-installed by using the following command:

```bash
make up
```

This command will start a Dockerized WordPress instance accessible at [http://localhost:8888](http://localhost:8080) with the default admin username `admin` and password `password`. 
