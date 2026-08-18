## Search Index

* Unified search index — indexes content across multiple record types and content elements
* Indexer interface — pluggable indexer interface so any extension can contribute searchable records
* Backend index management — re-index, clear, and inspect the search index in the backend
* RAG prompts — system prompt and user template are Site Settings (`search.rag.*`); the Search backend module shows the resolved values read-only
* Live index queue — backend queue view auto-refreshes counts and recent jobs while the scheduler drains the queue
* Colorful queue progress bar — stacked pending / processing / done / failed bar on index and queue backend views

## Search Results

* Results page — paginated search results with relevance ranking; the Search plugin exposes only the `results` action (GET). Theme and extension templates wrap the plugin in a `mai_turbo` frame (`mai-search-results`) so the initial `/suche` view, form submit, filters, and pagination update in place without a full page reload
* Type filtering — filter results by content type (`type_s`: news, page, events, …) including facet counts that stay visible while a type filter is active
* Own Solr client — Solarium-backed `SolrClient` in `mai_search` (no `ext-solr` dependency)
* Live search suggestions — optional AJAX-based autocomplete for the search field
* Hybrid rank fusion — when RAG is enabled, the results grid is reordered by fusing text-search rank with a chunk-based semantic rank (`HybridRankFusionService`, Reciprocal Rank Fusion), backend-agnostically on both Solr and Meilisearch

## RAG (Retrieval-Augmented Generation)

Optional answer card generation on top of search results, gated behind
`plugin.tx_maisearch.settings.ragEnabled` (default: off). When enabled, context
chunks from the top hits are retrieved and synthesised into a natural-language
answer via the configured LLM provider (OpenAI, Ollama, or a custom
OpenAI-compatible endpoint, selected in Extension Configuration). The pipeline
comprises four stages:

### 1. Content Chunking
Indexed content is split into overlapping text chunks by `ContentChunkerService`.
Chunk size (default: 500 characters) and overlap (default: 50 characters) are
configurable via Extension Configuration (`chunkSize`/`chunkOverlap`). Chunk
boundaries align to word breaks. Each chunk is indexed as its own document
(`ChunkSearchBackendInterface`), independent of the whole-document result
shown in the results grid, so long content is fully searchable rather than
cut off at the embedding provider's token limit.

### 2. Vector Embeddings
Each chunk is embedded via the active `EmbeddingProviderInterface`
(`ProviderRegistry` — OpenAI, Ollama, or custom). Vectors are stored in Solr's
`DenseVectorField` schema or, on Meilisearch, a `userProvided` embedder —
either way for k-nearest-neighbour retrieval. Re-indexing a chunk whose
content hasn't changed skips re-embedding entirely.

### 3. Hybrid Search
The search query is also embedded — using a short-lived cache
(`QueryEmbeddingCacheService`) so an identical or repeated question doesn't
re-embed — and compared against stored chunk vectors via KNN scoring. The
vector proximity score is combined with the BM25/text-relevance score
(`HybridRankFusionService`) for a hybrid ranking that improves semantic match
quality, on both Solr and Meilisearch.

### 4. Answer Synthesis
Top-ranked context chunks are assembled into a prompt and sent to the active
chat provider's Chat Completions API. System prompt and user template
(`{context}`, `{query}`) are Site Settings (`search.rag.systemPrompt` /
`search.rag.userTemplate`, Site Set `maispace/mai-search`); empty or invalid
values fall back to the code defaults on `RagPrompt`. `SearchSynthesisService`
returns a concise answer summary with source references. The Fluid template
renders the answer card above the hit list; the card is hidden when RAG is
disabled. Answers can be streamed token-by-token (SSE) or delivered
synchronously. The Search backend module shows the currently resolved prompts
read-only; edit them in Sites → Site Configuration → Settings.

## Caching

* RAG answer cache — synthesised answers are cached (`AnswerCacheService`), keyed by query + context + conversation history + prompt fingerprint, with a configurable TTL (`ragAnswerCacheTtl`). Served identically for the synchronous and the streaming answer path — a cache hit on the streaming endpoint is delivered as a fast SSE burst instead of a live token-by-token generation
* Query embedding cache — query-time embedding vectors are cached (`QueryEmbeddingCacheService`, configurable TTL via `queryEmbeddingCacheTtl`) so the same question is never sent to the embedding provider twice, whether asked by different visitors or embedded twice within one request (hybrid rerank + RAG context resolution)
* Content-hash-gated invalidation — a record save that doesn't actually change the extracted content (metadata-only edits) neither invalidates the answer cache nor re-embeds anything; content-hash tracking exists at both document (`ContentHashService`) and chunk (`OrphanChunkCleanupService`) granularity
* Reindex/clear invalidation — a full reindex or "clear index" in the backend module invalidates the whole answer cache; admins can also clear just the answer cache without rebuilding the search index
* Orphan chunk cleanup — chunks left behind when a document shrinks are removed automatically on save, from both the search backend and local bookkeeping; the `mai-search:cleanup-chunks` CLI command catches records deleted outside the normal save/delete path (hard SQL deletes, disabled extensions, migration errors)
