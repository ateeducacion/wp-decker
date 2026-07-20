# User Guide

## Kanban board

Tasks are organized in columns (stacks). The most common are:

- To Do
- In Progress
- Done

You can create, edit, move (drag & drop) and archive tasks from the board.

## Priority system

Decker has a distinctive priority system. Tasks can be marked with **Max Priority** so they stand out.

## Creating tasks

- From the board interface (recommended).
- Via URL parameters (useful for integrations):

```
/?decker_page=task&type=new&title=My+task&stack=to-do&maximum_priority=1
```

Available parameters include `title`, `description`, `board`, `stack`, `maximum_priority`.

## AI description improvements

When enabled in settings, you can improve a task description with AI:

- **Gemini Nano**: runs entirely in a compatible browser.
- **Gemini API**: runs on the server using the saved API key.

## Calendar feeds

The plugin exposes individual iCal feeds for different event types (Meeting, Absence, Warning, Alert). You can subscribe to them from any calendar client.

## Collaborative editing

If enabled, multiple users can edit the same task at the same time using WebRTC.

## Email to post

There is a REST endpoint that accepts emails (authenticated with the Shared Key) and turns them into tasks.
