# Changelog

## [Unreleased]

### Added

- **A rich-layout surface, so a business one-pager is expressible.** The model
  was far narrower than the XML this engine already emitted: font size, font
  family, small caps, letter spacing, per-cell shading, borders, padding,
  vertical alignment and both merge directions were produced from hardcoded
  blocks or from `styles.xml` and were **unreachable from the model**. An agent
  could emit `size`, `colSpan` or `shading`, the validator returned no errors,
  and every one of them was silently dropped.

  | where | new keys |
  |---|---|
  | run | `size` (points, half-points exact) · `font` · `smallCaps` · `letterSpacing` (points, may be negative) |
  | paragraph, **heading** and list item | `spaceBefore` · `spaceAfter` · `lineHeight` · `indentLeft` · `indentRight` · `keepNext` · `shading` · `borders` · `align` (on headings too) |
  | table | `widths` (relative column weights) · `width` (% of the text column) · `align` · `borders` (incl. `insideH` / `insideV`) · `cellPadding` |
  | cell | `shading` · `borders` · `padding` · `valign` · `colSpan` · `rowSpan` |
  | document | `page` (`size`, `orientation`, `margins`) · `defaultFont` · `defaultSize` |

  Every key is validated, every key round-trips through the reader, and every
  key appears in `jsonSchema()` so an agent registering the tool is told it
  exists.

- **A heading is now a paragraph.** It takes the same properties, so a section
  label that needs spacing or alignment no longer has to be a bold paragraph
  impersonating a heading — and therefore appearing in no navigation pane and
  no table of contents.

- **Both merge directions**, written HTML-style: a `rowSpan` cell appears ONCE
  and the rows it covers list only their own remaining cells. The writer
  synthesises the `w:vMerge` continuations OOXML requires and the reader folds
  them back, so the model that comes out is the model that went in.

- **The table grid is computed from the section.** All three engines carried
  `9360` twips as a literal, so a document that narrowed its margins got a
  table that no longer matched its own page — too narrow, and silently so.

- **`last-word/docx-constructs` in `fancy-conformance`** — 44 shared rows
  pinning which construct emits which XML, in which order, and what the reader
  gives back. The rows are not transcribed into this repo: all three engines
  assert the same file, so a mapping that drifts in one fails there rather than
  quietly becoming that engine's behaviour.

### Fixed

- **Adjacent tables no longer merge into one in Word.** OOXML merges two
  `<w:tbl>` elements that touch, imposing the first table's column grid on the
  second. A stat band followed by a callout became a single two-row table.
- **A run's properties can no longer be lost to run-merging.** Adjacent runs
  are merged when their formatting matches, and the comparison listed only the
  properties that existed when it was written — so two differently-sized runs
  merged into one and took the first one's size.

- **`fancy-conformance ^0.7.0` is a new dev dependency**, and it is not on
  Packagist yet — the fixture package has to be released before this one can
  install. `composer.lock` is deliberately NOT updated here for that reason;
  regenerate it once the fixture release lands.

### Changed

- **Table properties are now written inline, not taken from a named style.**
  A named table style cannot vary per table instance, so per-table borders
  forced this. Also reconciled with it: header cells are one grey in all three
  engines, and header bold is one mechanism.

- **Document defaults name an East Asian font.** Without it Word picks its own
  face for CJK runs, which is exactly the text a mixed-script document
  contains.

  **What you must do: nothing.** No existing key changed meaning, nothing was
  removed and nothing was renamed. A document written before this release
  produces the same page. The visible differences are confined to tables, are
  small, and are listed above so a pixel comparison against an old build is not
  a surprise.

### Notes

- **A `header: true` row is not a round-trip fixpoint, and now says so.** The
  writer bolds the row's runs and the reader honestly reports the bold it
  finds, so the model that comes out is not the model that went in. The
  alternatives were to stop bolding header rows (changing every existing
  consumer's output) or to have the reader strip bold from header rows
  (discarding bold an author really asked for). Pinned as case `0042` rather
  than left as a surprise.

- **Release order: `fancy-conformance` first.** The shared table is version
  `0.7.0`, which is not on a registry yet, so this package's dev dependency
  cannot resolve and its lockfile cannot be regenerated until that release
  lands. Nothing about the runtime surface depends on it — only the test that
  asserts the shared rows.

- **What DOCX cannot do, so nobody chases it:** table corners are always
  square (there is no border radius in WordprocessingML), a background cannot
  bleed past the page margin without an anchored drawing, and naming a font is
  not shipping one — a reader without it substitutes. None of the three is
  worked around here; a layout that needs them needs a different format.

## 0.3.0 — 2026-08-07

### Changed

- **BREAKING — PHP 8.2 is no longer supported.** `require.php` moves from `^8.2` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.2, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- CI now tests PHP 8.4 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.

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
