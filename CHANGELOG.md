# Changelog

## 0.2.0

Cross-language metadata parity with the Node mirror
(@particle-academy/last-word) — last-word-js#1.

- **Title** now lives in `docProps/core.xml` (`dc:title`), byte-identical
  to the Node writer's part (+ its content-type override and package rel),
  instead of a Title-styled body paragraph. The reader prefers core.xml
  and still consumes the legacy Title paragraph from 0.1.x files.
- **Code blocks** are wrapped in a `w:sdt` content control tagged
  `lastword:code[:{lang}]` (the canonical cross-language language slot;
  survives Word edits) instead of an invisible `LastWordCode_{lang}`
  bookmark; **quotes** get the matching `lastword:quote` sdt wrapper. The
  reader parses the sdt tags, keeps the legacy bookmark read, and still
  handles bare pStyle-only files.
- Tables no longer emit a trailing pad paragraph (only between adjacent
  tables), matching the Node writer's structure.
- New frozen cross-read vector: `tests/fixtures/node-canonical.docx`
  (written by the Node engine) + its JSON, asserted semantically
  deep-equal on read.

## 0.1.0

Initial release — the docx sibling of holy-sheet (xlsx) and dark-slide
(pptx), mirrored 1:1 with `@particle-academy/last-word` (Node).

- **JSON document model** (camelCase, associative arrays): title +
  heading / paragraph / list / table / code / quote / image / pageBreak /
  hr blocks with styled runs (bold, italic, underline, strike, inline
  code, link, color, highlight).
- **Agent façade**: `validate`, `validateAndRepair` (heuristic repair with
  retained errors), `toBytes`, `write`, `read` / `fromBytes`, `describe`,
  `toMarkdown` / `fromMarkdown`, `jsonSchema`, `version`.
- **DOCX writer**: minimal valid WordprocessingML package — styles part
  (Title, Heading1-6, Quote, CodeBlock, InlineCode, Hyperlink), bullet +
  decimal numbering with 6 indent levels and per-list ordered restarts,
  real tables with header shading, inline images from data URLs with
  PNG/JPEG dimension sniffing and a 6.5in width cap, hyperlink rels.
  Deterministic output: fixed entry order, no timestamps, pinned zip
  mtimes.
- **DOCX reader**: round-trips the writer's output and tolerates
  Word-authored files (pStyle/outlineLvl headings, named highlights,
  numPr nesting, unknown constructs degrade — never throws).
- **Markdown bridge**: hand-rolled GFM subset both directions, no
  external markdown dependency; `<!-- pagebreak -->` comment convention.
