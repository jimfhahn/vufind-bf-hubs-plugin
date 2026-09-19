---
title: BIBFRAME Hub Family Explorer
emoji: 📚
colorFrom: gray
colorTo: red
sdk: static
pinned: false
license: gpl-2.0
short_description: LC BIBFRAME Hubs as bibliographic families, in-browser
---

# BIBFRAME Hub family explorer

An [Observable Notebooks 2.0](https://observablehq.github.io/notebook-kit/)
page that loads the
[jimfhahn/lc-bibframe-hubs](https://huggingface.co/datasets/jimfhahn/lc-bibframe-hubs)
Parquet files into DuckDB-WASM and renders any Library of Congress BIBFRAME
Hub's *bibliographic family*: climb from a translation up to its Work, then see
adaptations, sequels, parodies, translations and series laid out as an indented
tree (or tidy tree), coloured by how surprising the relationship is.

**Deep link:** `?hub=<uuid>` — e.g.
[`?hub=013dc21c-e732-e589-24fe-bcc876264d3a`](./?hub=013dc21c-e732-e589-24fe-bcc876264d3a)
for *Pride and prejudice*.

No server: the first load pulls ~97 MB of Parquet from the dataset repo; after
that everything runs locally in your browser.

Source and the companion VuFind plugin:
<https://github.com/jimfhahn/vufind-bf-hubs-plugin> (`notebooks/`).
