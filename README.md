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
- **Board Journals**: Create daily journal entries for each board, with fields for attendees, topics, agreements, and derived tasks.

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

## Advanced Usage

### REST API

The Board Journal feature provides a REST API for programmatic access.

**Get Journal Entry by Board and Date**

*   **Endpoint**: `GET /decker/v1/journals`
*   **Permission**: `read`
*   **Query Parameters**:
    *   `board` (string|integer, required): The slug or ID of the board.
    *   `date` (string, required): The date in `YYYY-MM-DD` format.
*   **Example Request**:
    ```bash
    curl -X GET "http://localhost:8888/wp-json/decker/v1/journals?board=my-board&date=2025-08-30" -u "admin:password"
    ```
*   **Example Response**:
    ```json
    {
        "id": 123,
        "date": "2025-08-30T11:45:00",
        "title": { "raw": "Daily Standup" },
        "meta": {
            "journal_date": "2025-08-30",
            "attendees": ["Fran", "Humberto"],
            "topic": "Project Sync",
            "agreements": ["Review PRs by EOD."],
            "derived_tasks": [],
            "notes": [],
            "related_task_ids": []
        }
        // ... other post fields
    }
    ```

### WP-CLI

You can create journal entries from the command line using WP-CLI.

**Create a Journal Entry**

*   **Command**: `wp decker journal create`
*   **Usage**:
    ```bash
    wp decker journal create --board=<slug|id> --title=<title> [--date=<YYYY-MM-DD>] [--topic=...] [--attendees=...] [--agreements=...]
    ```
*   **Parameters**:
    *   `--board`: (Required) The slug or ID of the board.
    *   `--title`: (Required) The title of the journal entry.
    *   `--date`: The date of the entry (defaults to today).
    *   `--topic`: The topic of the entry.
    *   `--attendees`: Comma-separated list of attendees.
    *   `--agreements`: Pipe-separated list of agreements.
    *   `--force`: Force creation even if an entry for the same board and date already exists.
*   **Example**:
    ```bash
    wp decker journal create --board=my-board --title="Afternoon Sync" --attendees="Fran,Humberto,Ernesto" --topic="Review new designs"
    ```
