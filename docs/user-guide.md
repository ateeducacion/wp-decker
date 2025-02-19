# User Guide

## Overview

The Decker plugin provides a Kanban board interface for managing tasks within WordPress. This guide will walk you through all the features and functionality available.

## The Kanban Board

### Board Layout
The board is divided into columns representing different stages of task progression:
- **To Do**: New and upcoming tasks
- **In Progress**: Tasks currently being worked on
- **Done**: Completed tasks

### Managing Tasks

#### Creating Tasks

There are two ways to create tasks:

1. Through the Interface:
   - Click the "+" button in any column
   - Fill in the task details:
     - Title (required)
     - Description
     - Priority level
     - Due date
     - Assignee

2. Using URL Parameters:
   You can create pre-filled tasks by using URL parameters. This is useful for integrations or bookmarks.
   
   Base URL format:
   ```
   /?decker_page=task&type=new&[parameters]
   ```
   
   Available parameters:
   - title: Task title
   - description: Task description
   - board: Board slug identifier
   - stack: Task status (to-do, in-progress, done)
   - maximum_priority: Set to 1 for maximum priority
   
   Example URL:
   ```
   /?decker_page=task&type=new&title=Bug%20Fix&description=Fix%20the%20login%20issue&board=board-1&stack=in-progress&maximum_priority=1
   ```

#### Priority System
Decker uses a unique priority system:
- **High**: Critical tasks requiring immediate attention
- **Medium**: Important but not urgent tasks
- **Low**: Tasks that can be handled when time permits

#### Moving Tasks
- Drag and drop tasks between columns
- Click on a task to edit its details
- Use the quick-action menu for common operations

## Task Details

### Editing Tasks
Click on any task to open the detail view where you can:
- Modify the title and description
- Change the priority level
- Update the due date
- Reassign the task
- Add comments
- Attach files

### Task States
Tasks can be in various states:
- **Active**: Currently being worked on
- **Blocked**: Cannot proceed due to dependencies
- **Complete**: Finished tasks
- **Archived**: Hidden from main view but preserved

## Tips and Best Practices

1. **Regular Updates**: Keep task status current by updating regularly
2. **Clear Descriptions**: Write clear, actionable task descriptions
3. **Priority Management**: Use priorities consistently across your team
4. **Comments**: Use comments to document progress and decisions
5. **Attachments**: Attach relevant files directly to tasks

## Keyboard Shortcuts

- `N`: New task
- `E`: Edit selected task
- `Space`: Quick view task details
- `←/→`: Navigate between columns
- `↑/↓`: Navigate between tasks
