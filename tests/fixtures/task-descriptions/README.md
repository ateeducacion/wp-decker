# Historical task descriptions

These fixtures derive from the private WordPress WXR export dated 2026-11-04.
Text and identifiers in attributes are masked; links use example.invalid. The
original export, titles, authors, comments, credentials and metadata are not copied.
HTML structure, checklist states, inline tags and whitespace are retained.

- 5531: empty pending and completed checklist items.
- 6976: image inside a checklist item.
- 5561: pre-existing image with src="" and no alt; retain the available structure.
- 6936: large description, adjusted to 189,084 characters after anonymization.
- 4480: four Quill code containers; line boundaries are represented by nodes.
- 5082 / 3606: actual flat list indentation and mixed list types.
- 8201: paragraph indentation, not list nesting.
- 3372 / 3905: ordinary lists mixed with checklists and real nested lists.
- 3867: pending, completed, bullet and ordered items in one legacy container.
- 4653 / 4673: existing ordinary lists starting at 2 and 5.
- 6745 / 6639: monospace and alignment classes.
- 8598: description of the task with independently stored subtasks.

manifest.json records source status, field absence and fixture size. It contains
no original content hashes, names or URLs. Synthetic unit cases also cover
characters, formats, malformed input and whitespace absent from this sample.

`empty-checklist.html`, `empty-list.html` and `empty-paragraphs.html` are synthetic
examples written from scratch. They contain no content copied from the export
and exercise descriptions whose entire content is visually empty structure.
