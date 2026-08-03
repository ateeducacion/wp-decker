@AGENTS.md

# Claude Code

Project instructions live in `AGENTS.md`, which this file imports so that Claude
Code and every other agent read the same rules. Do not duplicate content here —
add it there.

`CONVENTIONS.md` holds the longer-form style guide, and
`.github/copilot-instructions.md` mirrors the same rules for GitHub Copilot.

Skills live in `.agents/skills/`, linked from `.claude/skills/`. They are
third-party and vendored verbatim: read them, do not reformat them. Consult the
relevant one before touching hooks or admin UI (`wp-plugin-development`), the
REST API (`wp-rest-api`), the WordPress.org `readme.txt` (`wp-plugin-directory-guidelines`)
or `blueprint.json` (`blueprint`). See the Skills section in `AGENTS.md`.

Nothing in this file, `AGENTS.md` or `.agents/` ships in the release ZIP.
