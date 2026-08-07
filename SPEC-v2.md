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

### Phase 2b — MeilisearchBackend (P0) — ✅ backend done, indexer wiring split to 2c

**Status: the backend itself is done; the cross-package indexer-contract merge
is split out further as Phase 2c and still not started.**

- [x] Ported `Domain/Client/{MeilisearchClient,ConnectionFactory,
      MeilisearchClientInterface,MeilisearchDocument}` and the search-response
      DTO from `mai_meilisearch` into `mai_search` as `Backend/Meilisearch/*`.
  - **Deviated from the plan's original per-site-memoization idea:**
        checked `mai_meilisearch`'s actual `ConnectionFactory` first — it has no
        per-site memoization at all (one global host/API key, a fresh client
        per call), because this project runs one Meilisearch instance for the
        whole install, not one per TYPO3 site. Ported that simpler reality
        rather than building unneeded per-site caching the source doesn't have.
  - **Index-naming decision (asked and answered):** consolidated to **one
        index per language, all types together, `type` as a filterable
        attribute** (`Backend/Meilisearch/IndexNameResolver::forLanguage()`),
        replacing `mai_meilisearch`'s original one-index-per-type-per-language
        convention. Mirrors `SolrBackend`'s shape exactly (one query, all
        types, type as a facet) — the `t3meilisearch` single-index-with-filters
        lesson from the original research, applied here instead of Phase 7.
  - Interface trimmed vs. the source: dropped `deleteByFilter` (no caller yet);
        merged `ensureIndex`+`updateSettings` into one
        `ensureIndex($indexUid, $filterableAttributes, $primaryKey)` call.
- [x] Implemented `MeilisearchBackend implements SearchBackendInterface`
      (`getIdentifier` = 'meilisearch', realtime indexing + facets + comparable
      score all `true`). `indexDocument()` ensures the per-language index
      (memoized per request via an in-memory flag, not re-sent per document)
      and writes a flat Meilisearch document; `removeDocument()` deletes by
      composed id from the default-language index (same known limitation
      `SolrBackend`/`NewsIndexer::removeRecord()` already have — not a new gap).
  - **`getCapabilities()->supportsVectorSearch = false`, deliberately.** Hybrid
        search needs an `embedders` config derived from whichever
        `EmbeddingProviderInterface` is active — a real, Meilisearch-specific
        mapping that Phase 2a intentionally kept off the generic provider
        interface. Building that mapping under this phase's time budget would
        have meant half-implementing it; it's deferred to Phase 7 alongside the
        chunk-index RAG upgrade, where it has an actual home.
  - **Result-formatter shim, load-bearing and worth reading closely:** every
        existing `SearchResultFormatterInterface::formatResult(array $solrDoc)`
        implementation (`NewsIndexer`, `FaqIndexer`, `EventIndexer`, ... —
        confirmed identical pattern in all three sampled) reads Solr's
        `_s`/`_t`-suffixed field names directly. Meilisearch documents are flat
        (`title`, `content`, `url`, ...). Rather than editing ten indexers'
        formatters, `MeilisearchBackend::toSolrShapedArray()` translates each
        Meilisearch hit into the same Solr-shaped array before calling the
        existing formatter unchanged. This is a deliberate, temporary adapter —
        replace it once Phase 2c (below) generalizes the formatter contract
        itself.
- [x] Wired into `Configuration/Services.yaml` (`maispace.search.backend` tag),
      added `meilisearch/meilisearch-php` to `composer.json`, added
      `meilisearch.host`/`meilisearch.apiKey` to `ext_conf_template.txt`.
  - **Bug fixed in passing, from Phase 0:** `ext_conf_template.txt`'s dotted
        keys produce a *nested* settings array (confirmed — this is exactly how
        `openai.*`/`ollama.*`/etc. already worked from Phase 2a), but
        `Domain\Solr\ConnectionFactory` reads `$typoScriptSettings['host']`
        *flat*, predating this template entirely. Fixed by making the Solr
        section of the template flat (`host`, `port`, `path`, `core`, `scheme`,
        no prefix) to match the existing, tested `ConnectionFactory` — not by
        changing that already-shipped, already-tested class. Net effect:
        `mai_search`'s `ExtensionConfiguration` array has a mixed shape (Solr
        keys flat, everything else nested under its backend name) — an
        accepted wart, not something to silently "fix" by touching working code.
- [x] Tests: `MeilisearchClientTest`, `IndexNameResolverTest`,
      `MeilisearchBackendTest`. 246 tests / 633 assertions total, PHPStan level
      5 clean, PHP-CS-Fixer clean. Validated via a scratch, throwaway Composer
      project (`meilisearch/meilisearch-php` isn't installed anywhere in this
      monorepo, unlike `typo3/cms-indexed-search` in Phase 1 — `mai_meilisearch`
      itself has no `vendor/` at all) bridged the same way as Phase 1's
      indexed_search validation; not committed, cleaned up after use.
- [x] Left `mai_meilisearch` untouched, per your instruction — it remains your
      Meilisearch playground/reference, not modified by this work.

**Investigated (read-only), confirms Phase 2c is real, separate work:** sampled
`NewsIndexer`, `FaqIndexer`, and `EventIndexer` across three different
extensions — all three fully implement `indexAll`/`indexRecord`/`removeRecord`/
`supports`/`getType` themselves (only borrowing `AbstractIndexer::
createDocument()`/`sendDocument()`/`getBoost()`), confirming
`SearchIndexerInterface`'s signatures don't need to change for backward
compatibility. But none of them can write to Meilisearch today: each one's
`indexRecord()` builds a `Domain\Solr\Document` and calls
`$this->connectionFactory->getConnection()` (`Domain\Solr\ConnectionFactory`)
directly — hardcoded to Solr, not routed through `BackendRegistry`. Making
these ten indexers truly backend-agnostic (so a site running
`activeBackend = meilisearch` actually gets indexed) requires either
redesigning `AbstractIndexer` to build `SearchDocumentInput` and call
`BackendRegistry::getActive()->indexDocument()` instead, or giving each
indexer a second Meilisearch-aware code path — real, cross-package work
touching all ten extensions, correctly out of scope for a single phase.

### Phase 2c — Route existing indexers through BackendRegistry (P0) — ✅ write path done

Split out from Phase 2b's original scope after confirming it's real,
cross-package work, not a byproduct of the backend/provider work.

- [x] Redesigned `AbstractIndexer`: `createDocument()` now returns a
      `SearchDocumentInput` (was `Domain\Solr\Document`); `sendDocument()` now
      calls `BackendRegistry::getActive()->indexDocument()` (was
      `IndexManagementService::addDocumentForLanguageCode()` directly); new
      `removeDocument(int $uid)` helper calls
      `BackendRegistry::getActive()->removeDocument($this->getType(), $uid)`.
  - **`ConnectionFactory`/`PersistenceManager` removed from `AbstractIndexer`
        entirely**, not just unused — confirmed via a full grep across all ten
        sibling extensions that `connectionFactory` was referenced *only*
        inside each `removeRecord()`'s direct-deletion call, and
        `persistenceManager` was injected but never read anywhere, in
        `AbstractIndexer` or any subclass. Both were dead weight once
        `removeDocument()` existed.
  - **Turned out to need zero changes to any indexRecord() body across all ten
        extensions.** Every indexer's `indexRecord()` calls `$this->
        createDocument(...)` and passes the result straight to `$this->
        sendDocument($document)` with no local type hint — pure duck typing.
        Changing what `AbstractIndexer` returns/accepts was enough; only
        `removeRecord()` needed an edit per indexer (3 lines → 1 line each).
  - Added `SearchDocumentInput::withAdditionalField()` (immutable, matches the
        `withField()` pattern already used by `MeilisearchDocument`/`Chunk`)
        so `sendDocument()` can merge in `languageCode` without exposing
        `SearchDocumentInput`'s constructor shape to every indexer.
  - Removed a pre-existing dead import (`use ApacheSolrForTypo3\Solr\...
        \Document;`, never referenced) from the 7 indexers that had it, while
        already touching those exact files for the real change.
- [x] Updated all ten indexers (`NewsIndexer`, `FaqIndexer`, `GalleryIndexer`,
      `JobsIndexer`, `LocationIndexer`, `MemberIndexer`, `TeamMemberIndexer`,
      `TestimonialsIndexer`, `TimelineIndexer`, `EventIndexer`) across their
      own extensions, plus `mai_search`'s own `PageIndexer`. Added a
      `removeRecord()` compatibility test to every one of the ten sibling
      extensions' test suites (a gap that existed before this phase — none of
      them tested `removeRecord()` at all, since doing so previously required
      mocking `Domain\Solr\ConnectionFactory` through an untyped property with
      no injection point in the test; routing through `BackendRegistry` made
      this straightforward to test for the first time). Also added
      `AbstractIndexerTest` (didn't exist before) and extended
      `PageIndexerTest` with `removeRecord()` coverage.
  - **Validation note:** `mai_search` plus 6 of the 10 sibling extensions
        (`events`, `jobs`, `news`, `member`, `team`, plus `mai_search` itself)
        were validated with real `phpstan`/`phpunit` runs, all green. The other
        4 (`faq`, `gallery`, `timeline`, `locations`) have never had their own
        isolated `vendor/` installed and hit the exact same pre-existing
        `maispace/mai-turbo` repository gap documented in Phase 1/2b's tooling
        notes when a standalone `composer install` was attempted — unrelated
        to this change, not fixed here. Validated those four with `php -l`
        (syntax-clean) and a direct diff comparison confirming the edit is
        byte-for-byte the same mechanical pattern already proven correct in
        the other six. Flagging this honestly rather than claiming full
        validation across all ten.
- [ ] **Not done, deliberately deferred:** generalizing
      `SearchResultFormatterInterface::formatResult()` off Solr's `_s`/`_t`
      field-naming convention, which would retire `MeilisearchBackend::
      toSolrShapedArray()`'s shim. This is the *read* path — a separate,
      equally cross-package concern (touches the same ten extensions' 
      formatters) from the *write* path fixed above. The write path was the
      functionally critical piece (nothing could reach Meilisearch at all
      before this phase); the read-path shim already works correctly today,
      so this remains a code-quality follow-up, not a blocker.
- [x] Re-confirmed the "one class implements both interfaces" DI-tag
      convention still holds — untouched by this phase, since
      `SearchIndexerInterface`'s method signatures never changed.

### Phase 2d — Environment provisioning tooling (out-of-band, user-requested) — ✅ done

Not part of the original phase numbering — added directly in response to "it's
always a pain to set up cores for each env," alongside adding Meilisearch to
DDEV. Backend-aware: works no matter which `activeBackend` is configured.

- [x] `.ddev/docker-compose.meilisearch.yaml` (root project) — Meilisearch
      `v1.9` service, matching the SDK's floor version, reachable as
      `meilisearch:7700` from the web container. `.ddev/config.yaml` gained
      `MEILISEARCH_HOST`/`MEILISEARCH_API_KEY` env vars, read by
      `Backend\Meilisearch\ConnectionFactory` (already had this env-var
      precedence since Phase 2b — no code change needed, just DDEV wiring).
  - **Debugged during verification** (`ddev restart` actually run, not just
        written and assumed correct): the healthcheck's `wget
        http://localhost:7700/health` failed with "connection refused" even
        though Meilisearch was running fine — this Alpine image resolves
        `localhost` to `::1` first and Meilisearch only binds `0.0.0.0`
        (IPv4). Fixed by using the literal `127.0.0.1` in the healthcheck.
        Confirmed working end-to-end: `ddev describe` shows the service
        healthy, `curl` from inside the web container against
        `http://meilisearch:7700/health` succeeds with the configured master key.
- [x] `Domain\Solr\SchemaManager::ensureCoresExist()` — creates missing Solr
      cores via CoreAdminHandler's `CREATE` action with `configSet=_default`
      (every standard Solr install ships this configset — no custom configset
      upload needed, since `mai_search`'s `_s`/`_t`/`_i`/`_dt` field-naming
      convention was already chosen to match `_default`'s built-in dynamic
      field patterns). Idempotent via a CoreAdminHandler `STATUS` check first.
  - **Bug caught by its own test, fixed before commit:** the first
        `coreExists()` implementation used `!empty($body['status'][$core])`,
        which misreports "doesn't exist" whenever Solr's status object for
        that core happens to be empty/falsy. Fixed to
        `array_key_exists($core, $body['status'])`, the semantically correct
        check ("is this core present in the map" vs. "is its value truthy").
  - `SchemaManager` lost its `final` modifier for the same reason
        `ProviderRegistry`/`ProviderFactory` did in Phase 2a: this repo keeps
        services non-final specifically so dependents can mock them in tests
        (third time fixing this same class-of-mistake this session — worth
        remembering as a standing rule, not a per-file fix).
- [x] `Domain\Service\ProvisioningService` — backend-aware orchestrator:
      Solr → `ensureCoresExist()` + `ensureVectorFieldExistsInAllCores()`;
      Meilisearch → `ensureIndex()` per **site language** (via `SiteFinder`,
      not coupled to Solr's `coreMapping`) plus a no-language default;
      indexed_search → a "nothing to provision" message rather than silently
      doing nothing.
- [x] `mai-search:provision` CLI command and a "Provision Infrastructure"
      button in the Search Index Management backend module, both driven by the
      same `ProvisioningService` so they can't drift.
- [x] Tests for all of the above; 12 more tests added on top of Phase 2b's
      count. Full suite, PHPStan, PHP-CS-Fixer, EditorConfig all clean.

**Not done, flagged rather than assumed:** the root project's own `vendor/`
doesn't have `meilisearch/meilisearch-php` installed yet — `composer.json` for
`mai_search` now requires it, but nobody has run `composer update` at the
*root* project level to actually pull it into the live site's vendor and
`composer.lock`. That's a deliberately separate, more consequential action
(touches the root repo's own lock file, which already has unrelated
uncommitted changes sitting in it from before this session) — flagged for you
to run rather than done silently.

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

#### Phase 7 addendum — lessons from `.lookup/smart-search` (2026-08-07)

`.lookup/smart-search` (`boehmmatthias/smart_search`) is a separate, independently
developed TYPO3 v14 extension solving the same "pluggable embedding/chat provider"
problem `mai_search`'s `ProviderRegistry` layer solves. It owns its own vector
store (packed float32 blobs, `VectorRepository`), which `mai_search` deliberately
does not — vectors here live in Solr's KNN field / Meilisearch, so none of
`smart_search`'s storage/codec/metadata-filter code applies. What *does* transfer
is the provider/RAG layer, audited against `mai_search`'s actual code (not assumed):

- [x] **PSR-3 logging in the provider layer.** Confirmed zero logging existed in
      `Provider/Embedding/*`, `Provider/Chat/*`, or `ProviderRegistry`, and
      `IndexManagementService::addEmbeddingToDocument()`'s failure path was a bare
      `catch (\Throwable) {}` — a persistently down/misconfigured provider produced
      vector-less documents forever with no trace. Fixed: `AbstractEmbeddingProvider`
      and `AbstractChatProvider` now implement `LoggerAwareInterface` (TYPO3's
      `LoggerAwarePass` auto-injects a channel-named logger, same convention as
      `IndexedSearchBackend`); each concrete provider logs before throwing on a
      non-2xx response, and logs (rather than silently returning `[]`) when
      unconfigured. `IndexManagementService`'s catch block now logs a warning
      with the document id and exception message instead of swallowing silently.
  - **Bug fixed in passing:** the three concrete `Chat` providers
        (`OpenAiChatProvider`, `OllamaChatProvider`, `CustomChatProvider`) never
        checked the HTTP status code before streaming — an auth failure or rate
        limit produced an error-JSON body that the SSE/NDJSON parser silently
        yielded zero tokens from, so `complete()` returned an *empty string*
        answer with no exception and nothing in the logs. `AbstractChatProvider`
        gained the same `assertSuccessful()` status check the embedding providers
        already had (status is available on the response object before the body
        is read, even with `'stream' => true`); all three now throw
        `\RuntimeException` on a non-2xx response, matching the embedding side.
  - `CustomEmbeddingProvider` also now logs+returns `[]` (instead of silently
        returning `[]`) when the configured response path doesn't resolve to an
        array — the "vector of the wrong shape masquerading as a valid empty
        result" class of bug `smart_search`'s changelog flags repeatedly.
- [x] **Provider-availability health check.** No equivalent to `smart_search`'s
      `ModelAvailabilityService` existed — only `SolrHealthWidget` (Solr-only).
      Added `Service/ProviderAvailabilityService` (`isEmbeddingProviderAvailable()`
      / `isChatProviderAvailable()`), following whichever provider identifier is
      actually active per concern: OpenAI reports available iff an API key is
      configured (no network call — no free health endpoint, and a billable probe
      has no place in a page render); the "custom" REST-template provider reports
      available iff its endpoint URL is configured (no network call — no known
      health-check shape for an arbitrary user-supplied endpoint, unlike
      `smart_search`'s fixed llama.cpp/Ollama/OpenAI set); Ollama is probed at
      `/api/tags` with a 2s timeout, never throws, and is cached for the instance's
      lifetime. Registered public in `Services.yaml` so a future consumer (the
      frontend RAG toggle, or the Phase 5 health module below) can autowire it
      without further wiring. Not yet wired into anything — this phase item is
      "the service exists and is tested," not "the RAG toggle hides itself."
- [ ] **Not done, deliberately deferred — real, separate scope decisions:**
  - Multi-turn conversation support (`smart_search`'s `ConversationHistory`
        value object) for `SearchSynthesisService`, currently single-shot only.
  - Wiring the already-ported-but-unused `StreamingChatProviderInterface` into
        `SearchSynthesisService` (this phase's existing streaming-synthesis item,
        above — `smart_search`'s `generateStream()` is a usable reference).
  - A reranking step (`smart_search`'s `RerankerInterface`/`LlmReranker`) between
        retrieval and synthesis. Worth inheriting their lesson directly if built:
        their reranker originally overwrote `score` with a rank-derived value,
        which silently broke a downstream threshold filter — reorder without
        touching `score`.
  - `mai-search:cleanup`-equivalent orphan detection (`smart_search`'s
        `smartsearch:cleanup` + `OrphanProviderInterface`, refuses to act on a
        suspiciously empty live-identifier list unless `--allow-empty`). Nothing
        in `mai_search` currently catches documents left behind by hard deletes,
        disabled extensions, or migration errors outside the `DataHandlerHook` path.
  - Reviving `ContentChunkerService` (confirmed still dead/unwired code) using
        `smart_search`'s `ParagraphChunker`/`SlidingWindowChunker` as a better
        starting point than the current unvalidated sliding-window-only version —
        only relevant once/if the chunk-index RAG upgrade above actually happens.
  - Not transferable at all: vector storage/codec (`mai_search` doesn't own
        vector storage), metadata strict-equality filtering, and RRF hybrid-search
        fusion (Solr/Meilisearch already own equivalent ranking natively).

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
