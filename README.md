# Decker

![WordPress Version](https://img.shields.io/badge/WordPress-6.1-blue)
![Language](https://img.shields.io/badge/Language-PHP-orange)
![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)
![Downloads](https://img.shields.io/github/downloads/ateeducacion/wp-decker/total)
![Last Commit](https://img.shields.io/github/last-commit/ateeducacion/wp-decker)
![Open Issues](https://img.shields.io/github/issues/ateeducacion/wp-decker)

**Decker** is a WordPress plugin developed for internal use at the Área de Tecnología Educativa (ATE). Its main goal is to efficiently present a task list with a simple Kanban board interface and unique priority system.

## Demo

Try Decker instantly in your browser using WordPress Playground. The demo includes sample data.

[<kbd> <br> Preview in WordPress Playground <br> </kbd>](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/ateeducacion/wp-decker/refs/heads/main/blueprint.json)

## Key Features

- **Kanban board** with drag-and-drop and a distinctive priority system (including Max Priority).
- **Import from Nextcloud Deck** (one-way) through the API.
- **AI Description Improvements**: Gemini Nano (browser) or Gemini API (server-side).
- **Individual Calendar Feeds** for event types (Meeting, Absence, Warning, Alert).
- **Collaborative editing** of tasks (optional, WebRTC).
- **Email-to-post** endpoint.
- Multisite support.
- Follows WordPress Coding Standards.
- Continuous Integration pipeline.

## Installation

1. Download the latest release from the [GitHub Releases page](https://github.com/ateeducacion/wp-decker/releases).
2. Upload the ZIP via **Plugins → Add New → Upload Plugin**.
3. Activate the plugin.
4. Configure under **Settings → Decker** (AI provider, notifications, collaborative editing, etc.).

## AI description improvements

- **Gemini Nano (browser-based)** keeps the prompt inside a compatible browser.
- **Gemini API (server-side)** sends the prompt from WordPress using a key stored in settings.

The saved Gemini API key is never exposed in public JavaScript, REST responses, or the settings form after saving.

## Documentation

Plain Markdown documentation lives in the [`docs/`](docs/) folder:

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [User Guide](docs/user-guide.md)
- [Development](docs/development.md)

## Development

```bash
make up
```

This starts a Dockerized WordPress instance at http://localhost:8888  
(admin / password).

See [docs/development.md](docs/development.md) for more details, available hooks and Make targets.
