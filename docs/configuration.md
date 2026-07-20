# Configuration

All main settings are under **Settings → Decker**.

## General

| Setting | Description |
|---------|-------------|
| Alert Message / Color | Optional banner shown in the interface |
| Minimum User Profile | Lowest role that can use Decker (default: Editor) |
| Shared Key | Bearer token for the email-to-post REST endpoint |
| Allow Email Notifications | Global switch for email notifications |
| Collaborative Editing | Enable real-time collaborative editing of tasks (WebRTC) |
| Signaling Server | WebRTC signaling server URL (default: `wss://signaling.yjs.dev`) |
| Ignored Users | Comma-separated list of user IDs excluded from Decker |
| Clear All Data | Destructive action that deletes all boards, labels and tasks |

## AI Improvements

| Setting | Description |
|---------|-------------|
| AI Improvements | Enable/disable the feature |
| AI Provider | `Gemini Nano (browser-based)` or `Gemini API (server-side)` |
| Gemini API Key | Required only for the server-side provider. Never shown again after saving |
| Gemini Model | Optional model name (has a sensible default) |
| AI Prompt | Customizable base prompt. Supports placeholders: `{{mode_instruction}}`, `{{task_context}}`, `{{content_html}}`, `{{language_instruction}}`, `{{response_format}}` |

**Privacy notes**

- The Gemini API key is stored only on the server.
- It is never exposed in JavaScript, REST responses or the settings form after saving.
- Leave the key field empty to keep the previously saved key.

## Nextcloud import

Nextcloud Deck import is a separate page (not inside the main Settings screen).

It is a **one-way** import of boards and cards. The plugin works fully without Nextcloud.

See the FAQ in `readme.txt` for the basic usage.
