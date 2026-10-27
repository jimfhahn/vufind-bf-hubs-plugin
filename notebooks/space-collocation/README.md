---
title: BIBFRAME Hubs Collocate Records
emoji: 🗂️
colorFrom: blue
colorTo: red
sdk: static
pinned: false
license: gpl-2.0
short_description: LC records on different shelves, joined by BIBFRAME Hubs
---

# Hubs collocate records across classification

A library shelves each book once, by subject: Oliver Sacks's *The man who
mistook his wife for a hat* in R (medicine), Michael Nyman's opera of it in M
(music). Library of Congress BIBFRAME Hubs connect the two. This
[Observable Notebooks 2.0](https://observablehq.github.io/notebook-kit/) page
shows how often, using 437,836 LC bibliographic records matched to their Hub at
high confidence (`bib_to_hub` in
[jimfhahn/lc-bibframe-hubs](https://huggingface.co/datasets/jimfhahn/lc-bibframe-hubs)):

- pairs of works joined by an adaptation, libretto or musical setting whose
  records sit on different shelves;
- single works whose editions LC shelved in several classes;
- how much of each class reaches a Hub, and how far the match can be trusted.

No server: about 0.5 MB of Parquet (copied into this Space) is queried with
DuckDB-WASM in your browser. To browse any Hub's full family, use the
[Hub family explorer](https://huggingface.co/spaces/jimfhahn/bibframe-hub-explorer).

Source: <https://github.com/jimfhahn/vufind-bf-hubs-plugin> (`notebooks/`,
`tools/bib-hub-mapping/`).
