# Configuration Guide

This guide explains how to configure Decker through the WordPress admin panel.

## Admin Settings

### Nextcloud Integration

Access the Nextcloud integration settings at **Settings > Decker**:

- **Nextcloud URL**: Your Nextcloud instance URL (e.g., `https://nextcloud.example.com`)
- **API Username**: Your Nextcloud username
- **API Password**: Your Nextcloud app password
- **Default Board**: Select which Nextcloud board to sync by default

### Import Settings

Configure how Decker imports tasks from Nextcloud:

- **Auto Import**: Enable/disable automatic imports
- **Import Frequency**: How often to check for new tasks (hourly/daily)
- **Import Labels**: Choose whether to import Nextcloud labels
- **Import Assignments**: Import user assignments from Nextcloud

### Task Settings

Customize task behavior:

- **Maximum Priority**: Enable/disable the priority system
- **Default Stack**: Choose the default stack for new tasks
- **Due Date Required**: Make due dates mandatory
- **Allow Comments**: Enable/disable task comments
- **File Attachments**: Enable/disable file attachments

### User Permissions

Control what different user roles can do:

- **Create Tasks**: Which roles can create new tasks
- **Edit Tasks**: Which roles can edit existing tasks
- **Delete Tasks**: Which roles can delete tasks
- **Manage Labels**: Which roles can create/edit labels
- **View All Tasks**: Which roles can see all tasks vs only assigned

### Email Notifications

Configure email notifications:

- **Task Assignment**: Notify users when assigned to tasks
- **Due Date Reminders**: Send reminders before due dates
- **Status Changes**: Notify when task status changes
- **Comments**: Notify on new comments

### Display Settings

Customize how Decker appears:

- **Board Layout**: Choose between horizontal/vertical layout
- **Color Scheme**: Select predefined color schemes
- **Stack Names**: Customize names for To-Do/In Progress/Done
- **Items Per Page**: Number of tasks to show per page
- **Sort Order**: Default sort order for tasks

## Advanced Configuration

For advanced settings, you can use WordPress filters. See the [Development Guide](development.md) for available hooks and filters.

## Troubleshooting

If you encounter issues:

1. Check the WordPress debug log
2. Verify Nextcloud API credentials
3. Ensure proper user permissions
4. Check server requirements are met

For more help, visit our [GitHub Issues](https://github.com/ateeducacion/wp-decker/issues) page.
