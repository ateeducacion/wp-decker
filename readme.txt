=== Decker ===
Contributors: ateeducacion, erseco
Tags: ate, tasks, board, kanban
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.0.0
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Decker provides a task list with a simple Kanban board interface and unique priority system.

== Description ==

Decker is a WordPress plugin developed for internal use at the Área de Tecnología Educativa (ATE). Its main goal is to efficiently present a task list with a simple Kanban board interface and unique priority system.

### Key Features

– **Import from Nextcloud Deck through API**: Imports boards and cards from Nextcloud.
– **Subtasks**: Break a task into steps with their own state and assignee, and see the progress on the card.
– **Customization**: Adjustable settings available in the WordPress admin panel.  
– **Multisite Support**: Fully compatible with WordPress multisite installations.  
– **WordPress Coding Standards Compliance**: Adheres to [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards).  
– **Continuous Integration Pipeline**: Set up for automated code verification and release generation on GitHub.  

== Installation ==

1. Download the plugin from the WordPress Plugin Directory.
2. Upload the plugin to your WordPress site via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Configure the plugin under 'Settings'.

== Frequently Asked Questions ==

= How do I connect the plugin to Nextcloud Deck? =  
Go to the Import from NextCloud page, and enter your Nextcloud API credentials (URL and API token).

= Can I use this plugin without Nextcloud Deck? =  
Yes, the plugin independant, Nextcloud is only to oneway import.

= Can I synchronize events with iPhone or Android? =
Yes. Generate a calendar token under Users > Profile > Decker Settings. Add the displayed CalDAV URL and WordPress username to iPhone Calendar or DAVx⁵ on Android, using the token as password and SSL enabled. Create events inside the Decker calendar. ICS subscriptions are read only and use a separate secret. CAS logout does not revoke a calendar token; revoke or regenerate it in the profile.

Deployment and device verification: https://github.com/ateeducacion/wp-decker/blob/main/docs/calendar/caldav.md

= How do existing task descriptions move to the classic editor? =
Pause all HTTP, cron, CLI and import writers before updating. Existing sites stay read-only for task description writes until an administrator runs `wp decker migrate-descriptions --writers-paused` and `wp decker verify-descriptions`. The command backs up every task, including archived, hidden and empty tasks. Never skip the backups or use a WXR export as the complete database backup.

Read-only simulation, maintenance, recovery and future upgrade instructions: https://github.com/ateeducacion/wp-decker/blob/main/docs/task-description-migration.md

== Screenshots ==

1. **Kanban Board View**  
   A visual representation of tasks organized in columns.  

2. **Settings Panel**  
   Configure plugin preferences.
