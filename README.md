# Decker

![WordPress Version](https://img.shields.io/badge/WordPress-6.1-blue)
![Language](https://img.shields.io/badge/Language-PHP-orange)
![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)
![Downloads](https://img.shields.io/github/downloads/ateeducacion/wp-decker/total)
![Last Commit](https://img.shields.io/github/last-commit/ateeducacion/wp-decker)
![Open Issues](https://img.shields.io/github/issues/ateeducacion/wp-decker)

**Decker** is a WordPress plugin developed for internal use at the Área de Tecnología Educativa (ATE). Its main goal is to efficiently present a task list with a simple Kanban board interface and unique priority system.

## Demo

Try Decker instantly in your browser using WordPress Playground! The demo includes sample data to help you explore the features. Note that all changes will be lost when you close the browser window, as everything runs locally in your browser.

[<kbd> <br> Preview in WordPress Playground <br> </kbd>](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/ateeducacion/wp-decker/refs/heads/main/blueprint.json)


### Key Features

- **Customization**: Adjustable settings available in the WordPress admin panel.
- **Multisite Support**: Fully compatible with WordPress multisite installations.
- **WordPress Coding Standards Compliance**: Adheres to [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards) for quality and security.
- **Continuous Integration Pipeline**: Set up for automated code verification and release generation on GitLab.
- **Individual Calendar Feeds**: Subscribe to event-type specific calendar URLs (Meeting, Absence, Warning and Alert).
- **AI Description Improvements**: Improve task descriptions with Gemini Nano in supported browsers or with the Gemini API through the WordPress server.

## Installation

1. **Download the latest release** from the [GitHub Releases page](https://github.com/ateeducacion/wp-decker/releases).
2. Upload the downloaded ZIP file to your WordPress site via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Configure the plugin under **Settings > Decker**, including any optional AI provider details you want to use.

## AI description improvements

Decker can improve task descriptions with two different providers:

- **Gemini Nano (browser-based)** keeps the prompt in a compatible browser that supports the Prompt API.
- **Gemini API (server-side)** sends the prompt from WordPress to Google using `wp_remote_post()` and a Gemini API key saved in plugin settings.

To configure the Gemini API provider:

1. Open **Settings > Decker**.
2. Enable **AI Improvements**.
3. Choose **Gemini API (server-side)** as the provider.
4. Paste a Gemini API key and optionally change the Gemini model name.

Privacy and security notes:

- The saved Gemini API key is never exposed in public JavaScript, REST responses, or the settings form after saving.
- Leave the API key field empty to keep the stored key unchanged.
- Use the browser provider if you prefer to keep the prompt entirely inside a supported browser profile.

## Development

For development, you can bring up a local WordPress environment with the plugin pre-installed by using the following command:

```bash
make up
```

This command will start a Dockerized WordPress instance accessible at [http://localhost:8888](http://localhost:8080) with the default admin username `admin` and password `password`.
