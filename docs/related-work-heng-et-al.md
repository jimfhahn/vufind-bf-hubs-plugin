# Related Work: Heng et al. (2026) and Multi-Path Hub Resolution

This note documents how the BibframeHub plugin's Hub-resolution architecture
relates to the prior work of Heng, Kudeki, Lampron, and Han (2026):

> Heng, G., Kudeki, D., Lampron, P., & Han, M. J. (2026). *Managing
> BIBFRAME Work and Hub Entities at Scale: Exploring Approaches to
> Large-Scale Reconciliation.* Cataloging & Classification Quarterly.
> <https://doi.org/10.1080/01639374.2026.2655113>
>
> Dataset: <https://doi.org/10.13012/B2IDB-1613787_V1>

The intent here is to give due credit, document where this plugin's
approach differs and why, and capture lessons learned that inform the
design.

## What Heng et al. Contributed

The Heng paper is, to our knowledge, the first published end-to-end
account of BIBFRAME Work and Hub reconciliation at corpus scale. Its
contributions are substantial:

- **A reusable corpus.** The Hamlet (8,678 records), Concerto (86), and
  Local (237) datasets are openly published with both MARC and BIBFRAME
  representations. This dataset is what makes empirical comparison of
  resolution approaches possible at all, and we use it ourselves for
  testing the plugin.
- **A working reconciliation pipeline.** `reconcileWorks.py` implements
  a careful Levenshtein-based scoring system across title,
  contributors, languages, notes, and hub fields, with thoughtful
  weighting and a Redis-cached query layer. The score-design choices
  (e.g., doubling the primary contributor weight when multiple
  matches are found, treating notes more strictly than titles) reflect
  real engineering judgment.
- **Honest reporting of results.** The paper documents a 14.81% Hub
  match rate after 10 iterations and ~22 hours of compute on the
  Hamlet corpus. Many comparable studies would have buried numbers
  this modest; instead the paper presents them straightforwardly,
  which is what makes the work useful as a baseline.
- **Identification of the problem.** Reconciling MARC-derived
  BIBFRAME against canonical LC entities is hard, the Hub layer is
  newer and less discoverable than the Work layer, and existing tools
  don't address it well. Naming the problem is itself a contribution.

This plugin's approach builds on those contributions; we wouldn't
have a reproducible test bed without their dataset.

## What We Learned About Hub Provenance

After working with the same data (and with subsequent BIBFRAME tooling
updates), one observation that informs our architecture is that
`bf:Hub` elements in marc2bibframe2 output don't all have the same
origin. There are five distinct paths by which a Hub appears in a
converted record, and each path has different implications for
resolution. Our working summary of those paths — with example MARC,
converter output, and resolution mechanism — is in
[modern-marc-hub-discovery.md](modern-marc-hub-discovery.md#hub-creation-paths-observed-taxonomy);
the authoritative answer for any given path is in the
[marc2bibframe2 XSL templates](https://github.com/lcnetdev/marc2bibframe2/tree/main/xsl).
The short version is that paths 3 and 5 carry the answer in the
source record itself, paths 1 and 2 produce Hubs whose AAP can be
reconstructed from the generated `bf:agent` and `bf:title` children
(exactly the form LC's `/resources/hubs/label/{encoded_AAP}`
endpoint accepts), and path 4 is structurally a Hub but represents
a different abstraction (a series as a collection rather than a
work as an aggregator).

The Heng pipeline, designed for the broader Work-reconciliation
problem, treats all five paths with one mechanism: derive title and
contributors from the converted record, send them to LC's Search API,
score the ranked results. This works reliably for Works, where the
Search API is the recommended tool. For Hubs the picture is
different — the Search API was never designed as a Hub-resolution
layer, and using it as one introduces the noise floor that the
14.81% number reflects.

## Why This Was Hard to See in May 2025

Several pieces of context that inform the plugin's design weren't
fully available, or weren't widely surfaced, when the Heng dataset
was collected:

1. **The Hub label endpoint is not prominently documented.** It works,
   it's HTTP-accessible, and it's used internally by id.loc.gov
   redirects, but it's not part of the marketed surface for
   reconciliation. Most published reconciliation guides point at
   suggest2 or the Search API. We only found the label endpoint by
   reading id.loc.gov's HTML response headers carefully.
2. **marc2bibframe2 v3.1.0 substantially expanded Hub generation.**
   The series-Hub work and the broader emphasis on emitting
   structurally-complete Hubs landed after the Heng data was
   collected. Earlier converter versions emitted less Hub data, which
   would have made path-aware resolution harder to design even if
   the label endpoint had been known.
3. **LC reprocessed the Hub corpus.** What we now think of as
   `bf:Hub` is a more recently distinct class than it was; previously
   Hubs were modeled as a `bf:Work` subclass. This reprocessing
   retired large numbers of legacy URIs and changed the resolution
   surface. Anyone designing a Hub reconciler before that change
   was working against a moving target.
4. **The Activity Streams feed is not linked from the bulk-download
   page.** We discovered it only by direct exploration of
   id.loc.gov. It's currently the only authoritative way to
   enumerate the live Hub corpus, and its existence reframes what
   "comprehensive Hub coverage" looks like.

In short: the gap between the Heng pipeline and what's possible
today is mostly a function of timing and discoverability, not of
methodology. With the same tools they had in May 2025, we'd
likely have built something similar.

## How This Informs the Plugin

The plugin's `BibframeHub` class makes the path differentiation
explicit:

- **`getFirstHubUriFromField()`** — checks `$0` / `$1` on 240/130
  first (path 5). When present, the URI is already canonical;
  resolution skips entirely to HEAD-check.
- **`extract758Relations()`** — handles 758 (path 3) separately,
  treating those as direct Hub assertions rather than candidates for
  reconciliation.
- **`HubClient::lookupByLabel()`** — uses the label endpoint for
  paths 1 and 2 with the AAP rebuilt from MARC fields. Returns the
  302 redirect URI directly; no scoring, no thresholding.
- **`HubClient::resolveBaseWorkUri()`** — handles the case where the
  initial label resolution lands on a collected-works edition rather
  than the base work, by stripping AAP qualifiers and re-querying.
- **`HubClient::lookup()`** (suggest2-based) — kept as a fallback for
  records that have no AAP-buildable fields and no direct URIs. This
  is the path most analogous to Heng's pipeline.

The five-tier surprise scoring model in `RelationshipInferrer` is a
separate concern from resolution — it operates on relationships
between Hubs after both endpoints have been resolved — but it depends
on the resolution layer being precise rather than recall-maximizing.
False positives at the resolution layer would propagate into
relationship suggestions, which is the user-visible surface.

## A Complementary Proposal Upstream

The plugin's architectural insight — that Hub provenance differs by
MARC source path and should drive resolution mechanism — has a
natural home upstream in marc2bibframe2 itself. The converter knows
which template fired for each Hub element; downstream consumers
don't. By the time a reconciler sees the BIBFRAME output, that
provenance information has been discarded.

This proposal has been filed upstream as
[lcnetdev/marc2bibframe2#270](https://github.com/lcnetdev/marc2bibframe2/issues/270):
have the converter emit a `bflc:hubMatchKey` property on
each generated Hub, formatted as the AAP that the label endpoint
accepts. With that key in place, any downstream system could
resolve canonical URIs at no additional cost — including, in
principle, a re-implementation of the Heng pipeline that would
exceed its match rate without the fuzzy-matching layer. We see
this as a complement to Heng's contribution rather than a
replacement: their work surfaces the problem and provides the
test corpus; the upstream change would make the solution
available everywhere the converter is used.

## Citation

When publishing or extending this plugin, please cite both
contributions:

- The Heng paper for the empirical baseline and the corpus that
  makes comparison possible.
- The marc2bibframe2 v3.1.0 (or current) release for the
  Hub-generation infrastructure that paths 1, 2, and 4 depend on.
