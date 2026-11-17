# Contributing to particle-academy/last-word

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

## Setup and tests

```bash
composer install
composer test        # pest
```

Tests are Pest, under `tests/Unit`, with fixtures in `tests/fixtures`.

- **The agent guide is pinned.** `agent-guide/professional-documents.md` is the
  canonical copy; the Node and Python packages carry the same bytes, and a test
  in each pins the hash. `tests/Unit/AgentGuideCoversTheApiTest.php` fails if
  the guide stops naming part of the API. Change the guide here, then copy it to
  the mirrors.
- **Cross-language parity.** The sibling repo's canonical fixture is frozen
  into the suite as a cross-read vector, and the conformance rows come from the
  pinned `particle-academy/fancy-conformance` dev dependency.
- **Determinism is tested.** `toBytes()` must stay reproducible: no timestamps
  in any XML part, fixed zip entry order, every entry's mtime pinned.

## Changelog

Update `CHANGELOG.md` (`[Unreleased]`) in the same commit as the change.
