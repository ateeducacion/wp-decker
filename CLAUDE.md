@AGENTS.md

# Claude Code

Project instructions live in `AGENTS.md`, which this file imports so that Claude
Code and every other agent read the same rules. Do not duplicate content here —
add it there.

`CONVENTIONS.md` holds the longer-form style guide, and
`.github/copilot-instructions.md` mirrors the same rules for GitHub Copilot.

Skills live in `.agents/skills/` and `.claude/skills/` (Grok Build reads the
Claude Code skills automatically). They are third-party and installed with
`gh skill add`: read them, do not reformat them. Consult the relevant one before touching hooks or admin UI
(`wp-plugin-development`), the REST API (`wp-rest-api`), the WordPress.org
`readme.txt` (`wp-plugin-directory-guidelines`), `blueprint.json` (`blueprint`),
performance work (`wp-performance`), a repo inventory (`wp-project-triage`) or
plugin security (`wp-plugin-security`). See the Skills section in `AGENTS.md`.

Nothing in this file, `AGENTS.md` or the skill directories ships in the release ZIP.
