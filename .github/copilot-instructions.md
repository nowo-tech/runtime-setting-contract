# Copilot / coding agent instructions — Runtime Setting Contract

## Product

- `nowo-tech/runtime-setting-contract`: a bundle setting that lives in `.env` can come from a database source instead,
  change live through `nowo:runtime-setting:*` commands or an admin UI, and rotate without downtime.
- `src/Contract` is framework-free (PHP + PSR interfaces only): `RuntimeValueSourceInterface`,
  `RuntimeSettingWriterInterface`, `RuntimeSettingDefinition`, `RuntimeSettingResolver`, `RuntimeSettingUpdater`,
  `SettingChangedEvent`. `src/Bridge/Symfony` is the optional Symfony glue. `src/InMemory` is the reference store.

## Conventions

- Keep PHPDoc, comments, and Markdown in **English**.
- Never log, print or put in an exception message a setting **value** (it may be a secret); ids and fingerprints only.
- Worker mode with kernel reset false: no request data in properties unless cleared at the request boundary.
- Document config/API changes in `docs/` and `docs/CHANGELOG.md`.
- Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers to commit messages.
- Never add `Co-Authored-By: Claude …` / `noreply@anthropic.com` trailers either (REQ-GIT-001).

## Quality

- Run `make test`, `make phpstan`, `make cs-check` and `make igor` before release.
- Keep PHP line coverage at 100%.
