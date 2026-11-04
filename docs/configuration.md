# Configuration

All main settings are under **Settings → Decker**.

## General

| Setting | Description |
|---------|-------------|
| Alert Message / Color | Optional banner shown in the interface |
| Minimum User Profile | Lowest role that can use Decker (default: Editor) |
| Shared Key | Bearer token for the email-to-post REST endpoint |
| Allow Email Notifications | Global switch for email notifications |
| Subtasks | Show the Subtasks tab and the card progress counter (on by default). Turning it off keeps the stored subtasks |
| Ignored Users | Comma-separated list of user IDs excluded from Decker |
| Clear All Data | Destructive action that deletes all boards, labels and tasks |

Task edit locks and stale-form protection are always active. The retired real-time
collaboration, signaling-server and sidebar board-status options are ignored on
existing installations and removed from the saved configuration the next time an
administrator saves settings. The retired AI provider, API key, model and prompt
settings are discarded on that same save. Task descriptions and other task and
board data are kept.

## Nextcloud import

Nextcloud Deck import is a separate page (not inside the main Settings screen).

It is a **one-way** import of boards and cards. The plugin works fully without Nextcloud.

See the FAQ in `readme.txt` for the basic usage.
