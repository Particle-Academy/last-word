# Changelog

## [Unreleased]

## 0.6.3 — 2026-09-15

### Fixed

Found by porting the ops to Node (last-word-js 0.6.0), each with a test that
fails against 0.6.2:

- **A document with a numeric top-level key lost its small diff (a 0.6.2
  regression).** PHP stores the key `"5"` as the int 5, `diff()` emitted
  `doc.set` with `key: 5`, and 0.6.2's reducer skips non-string keys, so the
  replay check failed and the whole diff became `doc.replace`. The key is now
  emitted as a string. 0.6.2's changelog said diff keys were always strings;
  they were not until now.
- **An insert whose path named a list by a key turned the list into a map.**
  `/blocks/blocks` set a key `blocks` on the top-level block list. A list is
  reached by position only; such a path is skipped.
- **`opSchema()` accepted an empty `doc.set` key**, which the reducer refuses.

  **What you must do:** nothing.

## 0.6.2 — 2026-09-15

### Fixed

- **An op with a position that is not a number edited the first item.**
  `blocks.remove` with `index: "abc"` removed block 0, because `(int) "abc"` is 0;
  so did `replace`, `move` and `insert` with a non-numeric `index`, `from` or
  `to`. Such ops are now skipped. Ints and digit strings still work.
- **`doc.set` with a non-string `key` set a key `"1"`** (`(string) true`). It is
  skipped. So is an op whose `op` or `path` is not a string, which previously
  cast an array to `"Array"` with a warning.

  **What you must do:** nothing. Ops from `diff()` always carry int positions
  and string keys.

## 0.6.1 — 2026-09-15

### Fixed

- **`diff()` could call two different values the same.** Its equality check
  encoded values to JSON and returned `""` for anything JSON cannot hold (invalid
  UTF-8, NAN, INF), so two different such values compared as equal and a change
  could go unrecorded. It now throws `JsonException`. Found in holy-sheet's
  identical helper by its Python port.

  **What you must do:** nothing.

## 0.6.0 — 2026-09-15

### Added

- **`Agent::diff()`, `Agent::reduce()`, `Agent::opSchema()` and `Agent::equivalent()`:
  a document's versions stored as ops** (last-word#2). A version history cannot
  keep a .docx per edit, hashing the bytes cannot keep a one-word edit small
  (it is a zip), and diffing `toMarkdown()` output would lose run formatting,
  tables and page breaks on restore. The diff is over Last Word's own model.
  - `reduce($a, diff($a, $b))` equals `$b`, key order aside. The ops are verified
    by replaying them; ops that do not reproduce `$b` become one `doc.replace`.
  - Every list is aligned by content: the top-level blocks, a quote's blocks, a
    list's items and their children, a table's rows, a row's cells and a cell's
    blocks. Rewording one paragraph is one `blocks.replace` at its own path, even
    inside a table cell; moving one is one `blocks.move`. A container whose own
    properties changed is replaced whole.
  - Documents that write the same file diff to `[]`, so a save without a change
    records nothing, even where the reader normalises (merged runs, a header
    row's bold, a dropped empty paragraph).
  - Blocks have no ids, so ops address a list by JSON Pointer and an item by
    index: `blocks.*`, `items.*`, `rows.*` and `cells.*`, each with
    `insert`/`remove`/`move`/`replace`, plus `doc.set` and `doc.replace`.

  **What you must do:** nothing. This only adds methods.

## 0.5.0 — 2026-09-13

### Added

- **`Agent::read()` reads legacy Word `.doc` (Word 97-2003)** (last-word#1).
  0.4 refused it by name, which was honest and left a host with nothing to give
  a model. The compound-file container (MS-CFB) and the Word binary format
  (MS-DOC) are read by code in this package; there is no new dependency.

  What comes through: paragraph text from every piece of the piece table
  (fast-saved files included, 8-bit and UTF-16 pieces), headings by the style's
  built-in identifier (so "Überschrift 1" is still a heading), directly applied
  bold / italic / underline / strike, hyperlinks from `HYPERLINK` fields (other
  fields keep their displayed result), bulleted and numbered lists with nesting,
  tables with header rows, and page breaks.

  What does not: formatting inherited from a style, fonts, sizes and colours,
  images and embedded objects, text boxes, headers, footers, footnotes and
  comments, merged cells, and the document title.

  Word 6/95 files and encrypted files are refused by name
  (`UnsupportedFormatException`, `format()` `doc`). A compound file that is not
  a Word document is named too: `xls`, `ppt`, `msg`, or `cfb`.

- **The same document now reads the same in all four formats.** A document
  written by this package and converted by LibreOffice to `.doc`, `.odt` and
  `.rtf` reads back **identically** to the `.docx` from the `.doc` and the
  `.odt`, and identically from the `.rtf` except for a header-row flag that
  file does not carry. `LegacyFormatsTest` asserts exactly that on committed
  fixtures (`tests/fixtures/formats/`, with a README saying how they were made).

- **Office files that are not word-processing documents are named.** An
  `.xlsx`, `.pptx`, `.ods` or `.odp` raises `UnsupportedFormatException` with
  that format, rather than "not a document".

### Changed

- **The ODT reader keeps far more of the document.** Bold, italic, underline
  and strike from automatic styles (where ODF keeps direct formatting),
  hyperlinks, lists with nesting and numbered/bulleted from their list style,
  tables with header rows and merged cells, `text:s` / `text:tab` /
  `text:line-break`, page breaks, and the title from `meta.xml`. 0.4 returned
  headings and paragraphs as plain text.

  The shape changes with it: a list item that 0.4 returned as a top-level
  paragraph is now an item of a `list` block, and a table cell's paragraph is
  inside a `table` block. A caller that walks blocks by `type`, as it already
  must for a `.docx`, needs no change; one that only read top-level paragraphs
  of an `.odt` will now find that text inside lists and tables.

- **The RTF reader is a real tokenizer with group-scoped state.** It reads
  headings by style name or outline level, direct bold / italic / underline /
  strike, `HYPERLINK` fields, lists from the list table, tables (header rows
  where `\trhdr` marks them), `\uN` with `\ucN` fallback skipping and surrogate
  pairs, `\'hh` in the document's code page (`\ansicpg`: 874 and 1250-1258
  decoded; 932/936/949/950 double-byte characters become one U+FFFD each), line
  breaks, tabs, page breaks and the title.

  **BREAKING, for RTF only:** 0.4 turned any bold-led paragraph into a level-1
  heading. That was a guess, and it made emphasis into structure. A bold
  paragraph is now a paragraph with a bold run; a heading is a paragraph whose
  style or outline level says so. If you relied on the guess, look for a
  paragraph whose runs are all bold.

- **Bytes that are no document raise `UnsupportedFormatException`** (format
  `unknown`) instead of a bare `InvalidArgumentException`. It extends
  `InvalidArgumentException`, so a `catch` for that still catches it; do
  nothing unless you compared the class exactly.

- **A damaged file raises `RuntimeException`, and an unsupported format raises
  `UnsupportedFormatException`.** "This file is broken" and "save it as .docx"
  send a person to do different things. A `.doc` signature with nothing valid
  behind it was an unsupported format in 0.4 and is a damaged file now.

### Security

- **Legacy readers treat an upload as hostile.** Every compound-file offset is
  bounds-checked; sector chains, the DIFAT chain and the directory tree are
  followed at most once per node, so a loop fails instead of spinning; a stream
  over 256 MB, or an allocation table naming more sectors than the file holds,
  is refused; the Word piece table must run forwards, so no byte range is read
  twice; an ODT part carrying a DOCTYPE is refused before parsing, parts over
  64 MB are refused, element nesting past 257 is refused (libxml's own limit,
  now pinned by a test so the Node and Python parsers refuse at the same depth),
  and repeated rows and columns are capped (1,000 per repeat, 100,000 cells in
  total); RTF group nesting is capped at 10,000 and `\bin` data
  is skipped by its length. Each guard has a test on a hand-built file
  (`tests/Support/LegacyFiles.php`).

## 0.4.1 — 2026-09-10

### Fixed

- **`version()` reports the version this package actually ships as.** It
  returned `0.2.0 (the SCHEMA version)` from a 0.4.x release. The constant had drifted because
  nothing compared it to the packaging metadata — the same shape as every other
  two-copies-of-one-number failure in this estate.

  `VersionIsSingleSourcedTest` / `version.test.ts` now pins it, so the class is
  closed rather than the instance fixed. `dark-slide-py` already had that
  assertion and was the only engine in the family to catch itself.


### Added

- **A premium-document composition suite.** `PremiumDocumentTest` /
  `premium-document.test.ts` writes one document using every formatting feature
  at once — all eight run properties in a single paragraph, styled headings, a
  repeating table header, resolved list numbering, A4 landscape geometry, a
  document default font — and asserts the unzipped OOXML rather than that a file
  appeared.

  The suites beside it prove each feature works ON ITS OWN. That is a different
  question from whether they compose, and a dropped feature is invisible: Word
  shows no error and the document is merely plain. Nobody files a bug against a
  report that looks boring; they conclude the library is boring.

  It found no defects here, which is the result worth recording. It includes a
  control that fails if the assertions could pass on an unformatted document,
  and it pins two decisions that read like gaps: `highlight` renders as `<w:shd>`
  rather than `<w:highlight>` (which takes sixteen named colours and could not
  carry a `#RRGGBB` schema), and `page.margins` are in POINTS.

## 0.4.0 — 2026-09-10

### Added

- **`Agent::read()` now reads ODT and RTF, and NAMES a legacy `.doc` instead of
  failing obscurely** (last-word#1).

  A consumer removed `phpoffice/phpword` and made this package the only docx
  path in their app. PhpWord's `IOFactory` sniffed `.doc`, `.odt` and `.rtf`
  alongside `.docx`; dropping it dropped those three.

  **The failure was silent, which is the part that mattered.** Those arrive as
  user uploads that an agent then analyses: the file stored fine and contributed
  no text. Nothing raised, nothing logged, and an agent answered questions about
  a document nobody had read.

- **`UnsupportedFormatException`**, distinct from `InvalidArgumentException`.
  "This file is damaged" and "this is a format we do not read, save it as
  .docx" lead a person to different actions, so they cannot share a class. It
  carries `format()` so a host can branch without grepping a message — messages
  are for people, and a host that parses one breaks when the wording improves.

  A legacy `.doc` is detected and refused by name. Binary Word 97-2003 parsing
  is a separate and much larger job; refusing it clearly is the honest answer
  today, and it is what lets a host say "re-save it as .docx".

### Fixed

- **A path is now sniffed, not assumed to be docx.** `read()` used to treat any
  existing path as docx bytes — reading the file and handing it to `DocxReader`
  without checking the signature — so a `.doc` on disk failed *inside the zip
  reader* and the error named a broken archive rather than the real problem.

### Reading, honestly scoped

  ODT keeps structure: `text:h` becomes a heading with its level, not a
  paragraph. The consumer left another library precisely because it flattened
  nested lists and stripped emphasis, and text that survives with its shape lost
  is a worse input for a model than text that fails loudly.

  RTF extraction takes paragraphs and bold-led headings. RTF carries much more —
  tables, embedded objects, styles — and this does not claim them. Stated here
  rather than discovered.

  Both return **the same document shape as `DocxReader`**, so a caller that
  already handles our documents needs no second code path per input format. The
  moment it does, the two paths drift and only one gets the next fix.

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
