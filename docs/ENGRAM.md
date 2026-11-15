# Engram

Repository-local product spec, GitHub Spec Kit ([SPEC-KIT.md](SPEC-KIT.md)), and `REQ-*` traceability (Makefile, CI) are described in [Spec-driven development](SPEC-DRIVEN-DEVELOPMENT.md).

This repository is prepared for [Engram](https://www.engram.fyi/docs) (MCP). `.cursor/mcp.json` starts the `engram` server (`engram mcp`).

Key references:

- [Spec-driven development](SPEC-DRIVEN-DEVELOPMENT.md) for product behavior and `REQ-*` anchors.
- [SPEC-KIT.md](SPEC-KIT.md) for the Specify CLI and Cursor Agent skills.
- Layout: `src/Contract` (framework-free), `src/InMemory` (reference store), `src/Bridge/Symfony` (optional glue), `tests/`, `docs/`, root tooling (PHP-CS-Fixer, Rector, PHPStan, PHPUnit, Igor). No Twig, translations, or frontend assets.
