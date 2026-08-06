# mai_search — v2 Spec: Unified Multi-Backend Search

## Mandate

`mai_search` becomes **the** search extension for Maispace TYPO3 projects. It exposes
one stable API — DTOs, indexer contract, frontend plugin, backend module — behind
which **indexed_search** (TYPO3 core, zero external infra), **Solr**, and
**Meilisearch** are interchangeable backends, selected per project (and optionally
per environment).

`typo3-extension-meilisearch` (`mai_meilisearch`) stops being a parallel product.
It remains installed as a standalone playground/reference for trying out Meilisearch
features in isolation — nothing in this plan requires keeping it in sync with
`mai_search` going forward. Code moves **out of** it and **into** `mai_search`
(adapted to the shared contracts below); it is not deleted, and nothing here changes
its own behavior.

This plan synthesizes lessons pulled from `.lookup/ext-solr`, `.lookup/ext-tika`,
`.lookup/Pixelcoda Search 2.2.11`, `.lookup/Meilisearch 0.9.9`,
`.lookup/Meilisearch 14.0.1`, `.lookup/t3meilisearch`, TYPO3 core's
`vendor/typo3/cms-indexed-search`, and the current state of both local extensions.
Per-source detail lives in the conversation that produced this doc; only the
resulting decisions and rationale are repeated here.

---

## Guiding decisions (read this before touching code)

1. **One indexer contract, three backend adapters.** `SearchIndexerInterface`
   stays the extension point third-party `mai_*` extensions implement. Backends
   translate the same indexer output into their native write call. Indexers never
   know which backend is active.
2. **DI-tag registries win over declarative auto-discovery.** `mai_search`'s
   existing `maispace.search.indexer` / `maispace.search.result_formatter` tags
   (collected via `CompilerPass`) become the canonical registration mechanism —
   confirmed by research as more idiomatic than both ext-solr's TypoScript
   class-map and `mai_meilisearch`'s `Configuration/Indexer/*.php` auto-discovery.
   `mai_meilisearch`'s declarative config is not ported; its *indexer logic* is.
3. **`indexed_search` is a degraded-capability backend, not a peer.** It cannot
   implement real-time `indexRecord()`/`removeRecord()` or a comparable relevance
   score — TYPO3 core indexes rendered HTML by phash, not structured fields. It
   ships as the zero-infrastructure default so `mai_search` works out of the box
   with no Solr/Meilisearch server, with clearly documented reduced capability
   (batch/eventual consistency, coarse relevance, page-level granularity only).
4. **Composer stays simple.** This is a single site-package project, not a
   published TER extension — `solarium` and `meilisearch/meilisearch-php` remain
   normal `require` entries (not `suggest`), but `BackendRegistry` still guards
   backend registration behind `class_exists()` so a misconfigured environment
   fails loudly and specifically, not with a fatal autoload error.
5. **Realtime sync stays hook-based for now, but only ever writes a queue row.**
   PSR-14 record lifecycle events don't exist in TYPO3 core yet (both ext-solr and
   Meilisearch-14.0.1 flag this as their own acknowledged limitation). Build the
   queue/worker split so swapping the hook for a core event later is a one-file
   change.
6. **Every new hook class must be registered as a public DI service** — `DataHandler`
   instantiates hooks via `GeneralUtility::makeInstance()`, which only injects
   dependencies into public container entries (see `mai_base`'s
   `AbstractRecordCacheInvalidationHook` docblock for the precedent).

---

## Target architecture (end state)

```
Classes/
├── Domain/
│   ├── Dto/
│   │   ├── SearchResult.php                       # extended: facets/highlight-ready
│   │   └── SearchResultPage.php                   # existing — reused as-is, not reinvented
│   ├── Model/
│   │   ├── IndexingContext.php
│   │   ├── BackendCapabilities.php                # DONE (Phase 0) — realtime/facets/vectors/score flags
│   │   ├── SearchRequest.php                      # DONE (Phase 0) — query, limit, offset, lang, type, rag
│   │   └── SearchDocumentInput.php                # DONE (Phase 0) — backend-agnostic write payload
│   └── Service/
│       ├── SearchIndexerInterface.php             # canonical indexer contract
│       ├── SearchResultFormatterInterface.php
│       ├── SearchBackendInterface.php             # DONE (Phase 0) — the central abstraction
│       ├── TextExtractorInterface.php             # NEW (or alias TYPO3 core's)
│       ├── ChunkerInterface.php                   # ported from mai_meilisearch
│       ├── EmbeddingProviderInterface.php         # ported
│       ├── ChatProviderInterface.php              # ported
│       └── SearchSynthesisService.php             # ported (RAG)
├── Backend/
│   ├── IndexedSearch/IndexedSearchBackend.php     # DONE (Phase 1) — search only, writes are logged no-ops
│   ├── Solr/SolrBackend.php                       # DONE (Phase 1) — Domain/Solr/{ConnectionFactory,Document,...} stay put
│   └── Meilisearch/MeilisearchBackend.php + Client/* + ConnectionFactory.php  # ported
├── Indexer/
│   ├── AbstractIndexer.php                        # TCA enable-columns trait baked in
│   ├── PageIndexer.php
│   └── FileIndexer.php                            # now calls TextExtractorInterface
├── Extraction/
│   ├── NullTextExtractor.php                      # default, metadata-only
│   └── TikaTextExtractor.php                      # optional, class_exists guarded
├── Service/
│   ├── IndexerRegistry.php / ResultFormatterRegistry.php
│   ├── BackendRegistry.php                        # NEW — DI-tag `maispace.search.backend`
│   ├── ProviderRegistry.php                       # ported, generalized Chat/Embedding
│   ├── IndexQueueService.php                      # NEW — enqueue/drain
│   └── SearchService.php / IndexManagementService.php   # now backend-agnostic facades
├── Report/
│   ├── SolrStatus.php / MeilisearchStatus.php / IndexedSearchStatus.php / TextExtractionStatus.php
├── Widget/SearchHealthWidget.php                  # generalized, wraps Report/* classes
├── Hook/DataHandlerHook.php                       # writes queue rows only
├── Scheduler/FullReindexTask.php + IndexQueueWorkerTask.php
├── Controller/{SearchController, Backend/SearchModuleController}.php
├── Command/IndexCommand.php
└── Event/                                          # NEW — PSR-14 indexer lifecycle
    ├── BeforeDocumentBuiltEvent.php / AfterDocumentBuiltEvent.php
    └── BeforeRecordIndexedEvent.php / AfterRecordIndexedEvent.php
```

---

## Phases

Priorities follow the project's standard scale (P0 critical/blocking → P3 whenever).
Each phase should ship as its own feature branch/PR per the repo's git workflow —
do not batch phases into one branch.

### Phase 0 — Contracts & decisions (P0, no behavior change) — ✅ done

Groundwork only; nothing here alters runtime behavior — no wiring, no DI
registration of the new interface, existing Solr code path untouched.

- [x] Add `SearchBackendInterface` (`Classes/Domain/Service/SearchBackendInterface.php`):
      `getIdentifier()`, `getCapabilities(): BackendCapabilities`,
      `search(SearchRequest): SearchResultPage`, `indexDocument(SearchDocumentInput)`,
      `removeDocument(string $type, int $uid)`.
- [x] Add `BackendCapabilities` value object (`Classes/Domain/Model/BackendCapabilities.php`):
      `supportsRealtimeIndexing`, `supportsFacets`, `supportsVectorSearch`,
      `hasComparableRelevanceScore`. Lives under `Domain/Model` (excluded from DI
      autowiring in `Services.yaml`), not `Domain/Service`, since it's a plain value
      object like `IndexingContext`, not a service.
- [x] Add `SearchRequest` (`Classes/Domain/Model/SearchRequest.php`) — extracted
      from `SearchService::search()`'s existing parameter list (query, limit,
      offset, language, type, ragEnabled). Solr-specific KNN tuning
      (`knnTopK`/`knnWeight`) deliberately stays out: no call site has ever
      overridden those defaults, so they remain `SolrBackend`-private constants in
      Phase 1 rather than leaking a Solr concept into the generic contract.
- [x] Reused the **existing** `SearchResultPage` DTO as the result-set type instead
      of inventing a new `SearchResultSet` — it already carries results/total/
      per-type facet counts/pagination and is what `SearchController` already
      consumes. Facet fields beyond `types` are a Phase 6 addition to this same class.
- [x] Add `SearchDocumentInput` (`Classes/Domain/Model/SearchDocumentInput.php`) —
      the backend-agnostic write payload, modeled directly on
      `AbstractIndexer::createDocument()`'s existing parameter list so migrating
      indexers to build this instead of a `Domain\Solr\Document` is mechanical.
- [x] Add `ext_conf_template.txt` to `mai_search` (didn't exist before — Solr
      connection settings were only ever read from `ExtensionConfiguration` with
      hardcoded fallback defaults in `ConnectionFactory`, never exposed as an
      editable template). Adds `activeBackend` (single global setting, see below)
      + documents the existing `solr.*` keys `ConnectionFactory` already reads.
      Per-language `coreMapping` stays TypoScript-driven (not expressible as a
      flat template scalar) — no behavior change.
- [x] **Decided:** `activeBackend` is a single global `ExtensionConfiguration`
      setting, not overridable per `ApplicationContext`. No Ddev/Development/
      Production branching for backend selection (contrast with `mai_base`'s
      `ConfigProvider` pattern, which was considered and explicitly rejected here
      for simplicity).
- [x] Lint/test gate: `composer check:phpstan` (level 5, clean), `check:phpcs`,
      `check:editorconfig`, and `test:unit` (158 tests / 455 assertions) all green
      via `ddev exec` (host PHP 8.3 doesn't satisfy the project's `>=8.4.1`
      platform requirement — always run through Ddev, not the host interpreter).

**Discoveries made while implementing that revise later phases:**
- The current `mai_search` code is considerably more advanced than `SPEC-v1.md`
  documents: `SearchService` already implements type filtering with facet counts
  and a full Solr hybrid KNN vector re-rank (`Domain/Solr/{SearchQuery,
  SearchResponse,Document,SchemaManager,ConnectionFactory}`), all Solarium-free
  (a hand-rolled `SolrClientInterface`). Phase 1's `SolrBackend` extraction is
  "move this existing logic behind the new interface," not new development.
- `VectorEmbeddingInterface`/`VectorEmbeddingAdapter` already bridges to a
  **third** extension, `mai_translate`, via a `class_exists()` guard — the same
  defensive-optional-dependency pattern planned for `BackendRegistry` in Phase 1.
  This means Phase 2's "reconcile the provider abstraction" now has **three**
  mechanisms to merge, not two: `mai_search`→`mai_translate` (embeddings only),
  `mai_meilisearch`'s `ProviderRegistry` (Chat+Embedding, OpenAI/Ollama/Custom),
  and whatever `mai_search` needs for its own RAG synthesis. Revisit explicitly at
  the start of Phase 2 — don't assume the two-way merge originally scoped.

### Phase 1 — Backend abstraction + `IndexedSearchBackend` (P0) — ✅ done

Delivers the zero-infrastructure default backend and proves the abstraction holds.

- [x] Implemented `BackendRegistry` (`Classes/Service/BackendRegistry.php`, DI tag
      `maispace.search.backend`, collected via `BackendRegistryCompilerPass`).
      Resolves the active backend from the `activeBackend` `ExtensionConfiguration`
      setting added in Phase 0; falls back to `solr` when the configured
      identifier isn't registered (e.g. `activeBackend = meilisearch` before
      Phase 2 exists) or when reading the configuration throws.
- [x] Added `typo3/cms-indexed-search` to `composer.json` requires.
- [x] Implemented `IndexedSearchBackend` (`Classes/Backend/IndexedSearch/`):
  - `search()` wraps core's `IndexSearchRepository` directly (constructor is
    plain DI, no TSFE dependency) — reuses core's own `IndexedSearchUtility::
    getExplodedSearchString()` + `Lexer::split2Words()` for word-splitting
    (both are usable outside core's own `SearchController`, whose equivalent
    methods are `protected`) and `SiteFinder`/`Site::getRouter()->generateUri()`
    for URL building (works without a current PSR-7 request, so it's usable
    from CLI/scheduler contexts too, not just live frontend requests).
    `score` is `1 / result_number`, explicitly documented as **not comparable**
    across backends (`hasComparableRelevanceScore = false`).
  - `indexDocument()`/`removeDocument()` are unsupported: log a warning via
    `LoggerAwareInterface` and return, never throw — matches this repo's own
    established precedent (commit `8a720b0`: "Do not fail DataHandler saves on
    Solr errors"). `SearchBackendInterface`'s docblock was corrected to require
    this behavior instead of the `@throws` originally sketched in Phase 0.
  - **Descoped from this phase, moved to Phase 3:** the "trigger a full
    reindex/crawl" mechanism you chose (minimal internal sub-request crawler,
    no `tomasnorre/crawler` dependency). `SearchBackendInterface` has no
    `reindexAll()`-shaped method — batch orchestration lives entirely in
    `IndexManagementService::reindexAll()`, which iterates `IndexerRegistry`
    indexers and calls `indexAll(IndexingContext)` on each. That model doesn't
    fit "crawl the page tree" at all, and Phase 3 redesigns reindex
    orchestration anyway (queue/worker split) — building throwaway crawler code
    against today's orchestration shape would just be rewritten. For now,
    `IndexedSearchBackend` searches whatever core's own page-render-time
    indexing has already put in the index; the internal crawler is a Phase 3
    deliverable, not deferred indefinitely.
- [x] Refactored `SearchService` into a thin facade: builds a `SearchRequest`
      and delegates to `BackendRegistry::getActive()->search()`. Its Solr query-
      building logic (KNN hybrid re-rank, type-filter normalization, result
      mapping) moved verbatim into `SolrBackend`.
  - **Scope correction from the original Phase 1 text:** `IndexManagementService`
    and `SearchController` turned out **not** to need changes. `SearchController`
    was already fully decoupled from Solr — it only ever talked to `SearchService`,
    so generalizing `SearchService` was sufficient. `IndexManagementService`
    stays Solr-specific internally for now: its write API is typed against
    `Domain\Solr\Document`, and every indexer (`PageIndexer`, `NewsIndexer`, …)
    still builds that type directly via `AbstractIndexer::createDocument()`.
    Retyping that write path to the backend-agnostic `SearchDocumentInput` is
    explicitly Phase 2 work ("unify indexer contract") — doing it here would
    have meant touching every indexer ahead of schedule. `SolrBackend` does
    fully implement `indexDocument()`/`removeDocument()` today (translating
    `SearchDocumentInput` → `Domain\Solr\Document` internally, then delegating
    to `IndexManagementService`), so the interface contract is real and tested
    — nothing yet calls it from the live indexer write path, that wiring is
    Phase 2/3's job once indexers stop building Solr `Document` objects directly.
- [x] Unit tests: `BackendRegistryTest`, `SolrBackendTest` (migrated the Solr
      query-building tests off the old `SearchServiceTest`), `IndexedSearchBackendTest`,
      and a rewritten `SearchServiceTest` asserting the facade delegates correctly.
      179 tests / 496 assertions total, PHPStan level 5 clean, PHP-CS-Fixer clean.

**Tooling note for future phases:** this package's own isolated `vendor/`
(used by `composer check:phpstan`/`test:unit` inside
`packages/typo3-extension-search`) cannot run `composer update` standalone —
its `composer.json` requires `maispace/mai-turbo` with no repository entry
outside the monorepo root's `packages/*` path repository, a **pre-existing**
gap unrelated to this refactor. Adding `typo3/cms-indexed-search` to
`composer.json` was safe (plain manifest edit), but validating code that
references its classes required a temporary, uncommitted bootstrap file
bridging the namespace from the monorepo root's `vendor/` via
`phpstan --autoload-file=` / `phpunit --bootstrap=`. Same trick will be needed
in Phase 2 for `meilisearch/meilisearch-php` if this package's isolated vendor
doesn't already have it.

### Phase 2a — Self-sufficient AI provider layer (P0) — ✅ done

Resolves the three-way provider reconciliation flagged at the end of Phase 1.
**Decided:** `mai_search` becomes fully self-sufficient for AI — no more
`mai_translate` dependency for search/RAG at all.

- [x] Ported `ProviderRegistry`/`ProviderFactory` + `Provider/Chat/*`
      (`AbstractChatProvider`, `OpenAiChatProvider`, `OllamaChatProvider`,
      `CustomChatProvider`) + `Provider/Embedding/*` (`AbstractEmbeddingProvider`,
      `OpenAiEmbeddingProvider`, `OllamaEmbeddingProvider`, `CustomEmbeddingProvider`)
      + `Domain/Model/{ChatMessage,ChatRequest,ChatResponse}` +
      `Domain/Service/{ChatProviderInterface,StreamingChatProviderInterface,
      EmbeddingProviderInterface}` + `Support/ExtractsResponsePath` from
      `mai_meilisearch`, unchanged in behavior.
  - **Deliberate deviation from the source:** dropped
        `EmbeddingProviderInterface::toMeilisearchEmbedderConfig()` — a
        Meilisearch-specific concern that has no place on an interface
        `SolrBackend` also implements against. Added `getMaxInputTokens(): int`
        instead, replacing the same method from the removed
        `VectorEmbeddingInterface` (`IndexManagementService` needs it to
        truncate content before requesting an embedding).
  - **Bugfix found while porting:** `ExtractsResponsePath::extractByPath()` was
        `private` in the source. `CustomChatProvider`/`CustomEmbeddingProvider`
        call it directly while the trait is `use`d by their *parent*
        (`AbstractChatProvider`/`AbstractEmbeddingProvider`) — PHP scopes
        trait-private methods to the class that uses the trait, not its
        subclasses, so this would have been a fatal error the first time a
        custom provider actually ran. Changed to `protected`. Neither source
        extension has a test covering this path, so it went uncaught there too.
  - **Convention fix:** `ProviderRegistry`/`ProviderFactory` were ported as
        `final class` (matching the source verbatim), which made them
        impossible to `createMock()` in consumer tests — breaks with this
        repo's own established pattern (`BackendRegistry`, `IndexerRegistry`,
        `ResultFormatterRegistry`, `SearchService`, `IndexManagementService` are
        all deliberately non-final for exactly this reason). Removed `final`
        from both.
- [x] Removed `Domain/Service/VectorEmbeddingInterface.php`,
      `Service/VectorEmbeddingAdapter.php`, and their Services.yaml binding —
      the `mai_translate` bridge is gone.
- [x] Rewired `SolrBackend` and `IndexManagementService`: `?VectorEmbeddingInterface`
      → required `ProviderRegistry`. Both simplified (no more null-check branch —
      a `ProviderRegistry` with a real, always-registered OpenAI/Ollama/Custom
      embedding provider is always available now, unlike the old optional
      third-party bridge).
- [x] Rewired `SearchSynthesisService`: hardcoded direct OpenAI HTTP call →
      `ProviderRegistry::getActiveChatProvider()->complete()`. **Retrieval
      design intentionally unchanged** — still reuses the top `SearchResult[]`
      from the primary query as context, not `mai_meilisearch`'s separate
      per-type chunk-index retrieval. Upgrading to that more precise design is
      real, separate architecture work — deferred to Phase 7, not bundled in
      here as a silent scope increase.
  - Minor accepted behavior change: the old code pre-flight-checked for an
        empty API key before making any HTTP call. The new design has no
        single "the api key" to check across three possible providers/two
        concerns (chat vs. embedding) — an unconfigured provider now degrades
        via its own graceful handling (empty vector / empty-ish answer from a
        failed HTTP round-trip) rather than skipping the network call
        entirely. Matches `mai_meilisearch`'s own reference behavior.
- [x] **Not ported:** `Chunker`/`AbstractChunker`/`FixedSizeChunker`/`Chunk`/
      `ChunkingOptions`. Reason: nothing in `mai_search` calls its existing
      `ContentChunkerService` either — it's dead, unwired code today. Porting a
      fancier unused chunker in its place would be speculative work with no
      current caller, contradicting the project's own "don't build for
      hypothetical future requirements" rule. Real chunking infrastructure
      belongs with the chunk-index RAG upgrade in Phase 7, where it will
      actually be wired to something.
- [x] Added `embeddingProvider`/`chatProvider` + `openai.*`/`ollama.*`/`custom.*`
      sections to `ext_conf_template.txt`, mirroring `mai_meilisearch`'s template.
- [x] Tests: `ProviderRegistryTest`, `ProviderFactoryTest`, one test class per
      concrete provider (6), `ExtractsResponsePathTest`, plus updated
      `SolrBackendTest`/`IndexManagementServiceTest`/`SearchSynthesisServiceTest`/
      `SearchControllerTest`. 220 tests / 562 assertions total, PHPStan level 5
      clean (including a stale `phpstan-baseline.neon` entry removed — it
      referenced the now-deleted `VectorEmbeddingAdapter.php`), PHP-CS-Fixer clean.

### Phase 2b — MeilisearchBackend + indexer contract merge (P0, paused for check-in)

**Not started.** This is a materially different scope of change than 2a: it
touches `AbstractIndexer`, the shared base class **ten other `mai_*`
extensions' concrete indexers** (`NewsIndexer`, `FaqIndexer`, `GalleryIndexer`,
`JobsIndexer`, `LocationIndexer`, `MemberIndexer`, `TeamMemberIndexer`,
`TestimonialsIndexer`, `TimelineIndexer`, `EventIndexer`) depend on — a
cross-package blast radius Phase 2a deliberately avoided. Confirmed by reading
`NewsIndexer`: it already fully implements `indexAll`/`indexRecord`/
`removeRecord`/`supports`/`getType` itself and only borrows `AbstractIndexer`'s
`createDocument()`/`sendDocument()` helpers — so the `SearchIndexerInterface`
method signatures don't strictly need to change for backward compatibility,
but the "reconcile the indexer contract" checklist item below still touches
shared, widely-depended-on code and deserves its own go/no-go rather than
riding on Phase 2a's momentum.

Outstanding work, unchanged from the original plan:

- [ ] Port `Domain/Client/{MeilisearchClient,ConnectionFactory}.php` from
      `mai_meilisearch` into `mai_search` as `Backend/Meilisearch/*`. Apply the
      Meilisearch-14.0.1 lesson: memoize the client **per site**, and keep the raw
      SDK client reachable alongside any wrapper for features an abstraction
      doesn't expose.
  - Also fold in `t3meilisearch`'s finding: filterable-attribute scoping
        (`rootPageId`, `languageId`) on a **single index** is simpler ops than one
        index per site/language — the current `IndexNameResolver` design should be
        revisited here, but only after confirming with the user (see Phase 7 note;
        this is a real behavior tradeoff, not a pure refactor).
- [ ] Implement `MeilisearchBackend implements SearchBackendInterface` wrapping
      the ported client — `getCapabilities()` reports `supportsVectorSearch = true`.
- [ ] Reconcile the indexer contract: `mai_meilisearch`'s `IndexerInterface`
      (`toDocument()`, `\Generator`-based `indexAll()`) has a cleaner document-build
      shape than `mai_search`'s current `AbstractIndexer`. Merge the better parts
      into the canonical `SearchIndexerInterface` — keep the DI-tag registration
      (decision #2 above), take the generator-based streaming and `toDocument()`
      split from `mai_meilisearch`.
- [ ] Every existing `mai_*` indexer (news, faq, gallery, jobs, locations, member,
      team, testimonials, timeline — see the table in `SPEC-v1.md`) keeps working
      unchanged against the merged interface; add a compatibility test per indexer.
- [ ] Update `mai_search/composer.json`: add `meilisearch/meilisearch-php`.
- [ ] Leave `mai_meilisearch` untouched. Add a short note to its `README.md`
      pointing to `mai_search`'s `MeilisearchBackend` as the production path.

### Phase 3 — Queue-based indexing + lifecycle events (P1)

Replaces synchronous hook→indexer calls with the ext-solr queue/worker split.

- [ ] New table `tx_maisearch_index_queue` (uid, table, record_uid, backend,
      status, attempts, error, tstamp).
- [ ] `DataHandlerHook` (registered as a **public** DI service, per decision #6)
      only enqueues a row on save/delete — no direct backend calls from the hook.
- [ ] `IndexQueueWorkerTask` (scheduler) drains the queue in batches, calling
      `BackendRegistry::getActive()->indexRecord()/removeRecord()`.
- [ ] `FullReindexTask` changes from "push documents directly" to "repopulate the
      queue for every registered indexer" — reindexing and realtime sync now share
      exactly one write path (single source of truth, easier to test).
- [ ] Port ext-solr's TCA `enablecolumns`/workspace/language exclusion query
      helper as a shared trait used by every indexer's `getRecordsForIndexing()`.
- [ ] Add PSR-14 events: `BeforeDocumentBuiltEvent`, `AfterDocumentBuiltEvent`,
      `BeforeRecordIndexedEvent`, `AfterRecordIndexedEvent`,
      `BeforeRecordRemovedEvent` — lets third parties mutate documents without a
      full custom indexer.
- [ ] Backend module addition (can ship partially here, finished in Phase 5):
      queue depth / backlog / failed-item count.

### Phase 4 — Text extraction service (P1)

Closes the gap `FileIndexer.php` already documents ("binary text extraction ...
out of scope here").

- [ ] Define `TextExtractorInterface` — prefer implementing TYPO3 core's own
      `TYPO3\CMS\Core\Resource\TextExtraction\TextExtractorInterface` directly so
      the extractor also benefits RTE previews etc., not just search indexing.
- [ ] `NullTextExtractor` — default, always available, metadata fields only.
- [ ] `TikaTextExtractor` — only registered if `apache-solr-for-typo3/tika` classes
      exist (`class_exists()` guard, no hard composer dependency). Delegates to
      whichever Tika mode (`jar`/`server`/`solr`) that extension is configured
      for — `mai_search` does not reimplement Tika transport.
- [ ] File-size gate before attempting extraction (mirror `SizeValidator`).
- [ ] `TextExtractionStatus` (see Phase 5) surfaces "extractor unavailable" or
      "extractor reports insecure/vulnerable version" as a loud warning, not a
      silent fallback to metadata-only.
- [ ] Wire `FileIndexer::toDocument()` to call the highest-priority registered
      extractor before falling back to metadata fields, replacing the doc-comment
      TODO with an actual implementation.

### Phase 5 — Health & backend-status module (P1)

- [ ] `SolrStatus`, `MeilisearchStatus`, `IndexedSearchStatus`,
      `TextExtractionStatus` — each `TYPO3\CMS\Reports\StatusProviderInterface`,
      auto-discovered by core's Reports module. Replaces the bespoke
      `SolrHealthWidget` as the source of truth.
- [ ] `SearchHealthWidget` (dashboard) becomes a thin presentation layer over the
      same `Report/*` classes — one source of truth, two presentations, and it now
      reflects whichever backend is actually active instead of assuming Solr.
- [ ] Small backend module addition: index counts per type, last full-reindex
      timestamp, queue depth (from Phase 3), one-click "run full reindex."
      Config-driven backend switch (`activeBackend` setting), not the
      file-swapping mechanism seen in Pixelcoda Search — a single config value,
      not `PackageStates.php` rewriting.

### Phase 6 — Faceting & query composition (P2)

Turns SPEC-v1's deferred "v2" into something concretely buildable now.

- [ ] Flesh out `SearchQuery`/`SearchResultSet` with facet fields.
- [ ] Introduce a `QueryModifierInterface` (`apply(BackendQueryContext $context): void`)
      per concern (type filter, sorting, faceting) — mirrors ext-solr's composable
      `ParameterBuilder` pattern, DI-tag registered (not a string class-map).
- [ ] `SolrBackend` and `MeilisearchBackend` each translate the same modifiers
      into their native query builder calls; `IndexedSearchBackend` implements
      only the type-filter subset (`supportsFacets = false` otherwise).
- [ ] `FacetRegistry` (DI-tag based) resolves facet type → renderer.

### Phase 7 — RAG/AI polish & open design questions (P2)

- [ ] Quota/threshold monitoring for AI provider calls (Meilisearch-14.0.1
      lesson) — scheduled check + warning email on approaching OpenAI cost/rate
      limits.
- [ ] Streaming answer synthesis in `SearchSynthesisService`, using the already-
      ported `StreamingChatProviderInterface`.
- [ ] **Open decision, confirm before implementing:** single Meilisearch index
      per site with `language`/`rootPageId` as filterable attributes, vs. the
      current per-language-core/index plan in `SPEC-v1.md`. Simpler ops, weaker
      per-language schema tuning — needs your call, not an assumed refactor.
- [ ] Zero-downtime reindex (draft-index + atomic swap) for `MeilisearchBackend`,
      matching Meilisearch-14.0.1's approach. No direct Solr equivalent exists in
      the current `SolrBackend` design — flag as backend-specific, not a shared
      capability.

### Phase 8 — Frontend/UX quality-of-life (P3)

- [ ] Live search suggestions/autocomplete (already noted as optional in
      `FEATURES.md`) via a shared `SuggestInterface`, implemented per active
      backend.
- [ ] `INDEX_CONTENT_START`/`INDEX_CONTENT_STOP` HTML marker comments
      (t3meilisearch idea, itself borrowed from core indexed_search) as a
      cheap per-template include/exclude escape hatch.
- [ ] Reuse core's existing `no_search`/`no_index` page TCA fields for page-level
      exclusion instead of inventing new flags.
- [ ] If a JS autocomplete widget gets built: esbuild + jest pipeline shape
      (Pixelcoda Search lesson) rather than ad-hoc bundling.

---

## What does NOT change

- `SearchResult` DTO shape (type/title/snippet/url/icon/date/score) stays the
  external contract for the frontend plugin — internal changes must not leak into
  Fluid templates.
- The existing `maispace.search.indexer` DI tag and per-extension indexer classes
  (`NewsIndexer`, `FaqIndexer`, etc.) keep their public API; only the base class
  they extend gains capability.
- `mai_meilisearch` is not modified, deprecated, or deleted by this plan.

## Explicit non-goals for this plan

- No attempt to make `indexed_search` relevance scores comparable to Solr/
  Meilisearch scores — document the limitation instead.
- No multi-tenant / TER-publishable packaging concerns (decision #4) — this is a
  single site-package project.
- No migration tooling to move an existing Solr or Meilisearch index between
  backends — switching `activeBackend` implies a fresh full reindex.
