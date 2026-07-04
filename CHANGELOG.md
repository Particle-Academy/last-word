# Changelog

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
