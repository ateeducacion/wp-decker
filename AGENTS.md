# AGENTS.md — Decker

Decker manages tasks, boards and a knowledge base in WordPress. Minimum versions:
WordPress 6.1 and PHP 8.3. Keep `decker.php` as bootstrap, with domain classes in
`includes/`, admin screens in `admin/`, and frontend code in `public/`.

## Project boundaries

- Use the existing Bootstrap 5/jQuery UI and WordPress enqueue mechanisms.
- `Decker_Abilities::is_available()` gates optional Abilities API registration;
  the service is lazy. Keep the plugin working when those APIs are absent.
  Do not raise the WordPress minimum merely to follow an upstream skill.
- Task saves use the generation-token and takeover rules in `Decker_Task_Locks`
  and `Decker_Task_Lock_State`. Preserve stale-form rejection, including another
  tab of the same user; locking stands down for explicitly enabled collaboration.
- Mail parser runtime code is copied by Composer into `admin/vendor/`; update the
  dependency/source, not the vendored copy.
- Preserve existing classes, hooks and stored identifiers unless the task changes
  their contract. `docs/adr/` documents durable decisions.

## Verification

- PHP: `make lint`, `make test`; do not replace `.phpcs.xml.dist` with a generic
  standard. Current source uses tabs; PHPCS is authoritative over stale editor hints.
- JS: `npm run test:js`; browser behavior: `make test-e2e`.
- Before pushing: run `make lint`, `make test` and `make check-untranslated`.
- Changed strings require the POT, Spanish PO and compiled catalogs in the same
  commit. PHPUnit does not catch untranslated strings.
- Packaging: `make check-plugin`; complexity checks use `make phpmd` / `phpmd.xml`.
- wp-env uses the existing project configuration; do not create a second stack.

Read [translation notes](.agents/references/translations.md) when changing strings
or localized JS, including placeholder comments and plural forms.

## Working conventions

- Branches use English names with `feature/` or `hotfix/`; PRs target `main`.
- Follow the repository PHPCS ruleset and current source. English PHPDoc precedes
  functions/methods. Unslash request data before sanitizing; escape at output.
- Check capabilities and resource ownership as well as nonces at write boundaries;
  follow the full caller chain before declaring a deliberately delegated guard missing.
- Read only the domain docs needed by the task. Keep changes focused and report
  what changed, what was verified, and any unresolved check failure concisely.
- Agent guidance/workflow changes need frontmatter, link, provenance and `actionlint`
  checks. Runtime changes need the relevant tests above. Do not weaken CI gates.
- No production deployment, release publication or data mutation is implied by
  a local implementation task. Respect authorization already given in the session.

English source strings use the plugin text domain; Spanish translations and
assertions preserve the user-facing language. Update catalogs with string changes,
use plural-aware translation functions, and add `translators:` comments for
placeholders. JS strings/nonces/URLs use the existing localization pipeline.

## Skills

Load only the skill relevant to the task. Local contracts override generic examples.
- [blueprint](.agents/skills/blueprint/SKILL.md): WordPress Playground blueprint JSON.
- [github-actions-hardening](.agents/skills/github-actions-hardening/SKILL.md): Author/review GitHub Actions workflows.
- [playwright-cli](.agents/skills/playwright-cli/SKILL.md): Terminal browser exploration; keep the existing test runner.
- [security-audit](.agents/skills/security-audit/SKILL.md): Requested vulnerability audits.
- [wp-abilities-api](.agents/skills/wp-abilities-api/SKILL.md): Optional Abilities API integration.
- [wp-performance](.agents/skills/wp-performance/SKILL.md): Measured backend performance work.
- [wp-plugin-development](.agents/skills/wp-plugin-development/SKILL.md): WordPress hooks, lifecycle and settings.
- [wp-plugin-directory-guidelines](.agents/skills/wp-plugin-directory-guidelines/SKILL.md): Distribution/readme and directory checks.
- [wp-plugin-security](.agents/skills/wp-plugin-security/SKILL.md): WordPress input/output and authorization review.
- [wp-project-triage](.agents/skills/wp-project-triage/SKILL.md): Identify existing WordPress tooling and layout.
- [wp-rest-api](.agents/skills/wp-rest-api/SKILL.md): REST schemas, routes and permissions.

### Skill maintenance

Install upstream skills with `gh skills install OWNER/REPO skills/NAME --dir .agents/skills`.
Keep upstream text and `metadata.github-*` unchanged; fix upstream and reinstall.
Local skills have no GitHub provenance and the updater skips them. Put project
exceptions in local guidance, not inside installed upstream folders.

WordPress skills may target 7.0+: verify APIs against this project's supported
versions. Do not upgrade requirements, scaffold new packages or change architecture
merely because a generic skill recommends it. Resolve example `skills/...` paths
under the actual `.agents/skills/` installation; use existing commands first.

New Claude entries are symlinks to `../../.agents/skills/NAME`.
Preserve existing Claude copies; the workflow updates both host directories.

`.github/workflows/update-agent-skills.yml` checks weekly/on dispatch, scoped to
installed skills, and opens a review PR on `main`. It never merges updates.
Review prompt diffs as behavior changes. PRs made with the default GitHub token
may not trigger CI; do not assume green checks will appear automatically.

Maintainer preference: use `actions/checkout@v7` and
`devantler-tech/actions/update-agent-skills@v13.3.3`; prefer the floating major
`v13` when upstream provides it. Use `peter-evans/create-pull-request@v8` too. Keep all actions in the skill-update workflow on version tags, not SHAs.
