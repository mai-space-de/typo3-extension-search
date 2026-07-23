## Search Index

* Unified search index — indexes content across multiple record types and content elements
* Indexer interface — pluggable indexer interface so any extension can contribute searchable records
* Backend index management — re-index, clear, and inspect the search index in the backend

## Search Results

* Results page — paginated search results with relevance ranking
* Type filtering — filter results by content type (`type_s`: news, page, events, …) including facet counts that stay visible while a type filter is active
* Own Solr client — Solarium-backed `SolrClient` in `mai_search` (no `ext-solr` dependency)
* Live search suggestions — optional AJAX-based autocomplete for the search field

## RAG (Retrieval-Augmented Generation)

Optional answer card generation on top of search results, gated behind
`plugin.tx_maisearch.settings.ragEnabled` (default: off). When enabled, context
chunks from the top hits are retrieved and synthesised into a natural-language
answer via the configured LLM provider (OpenAI). The pipeline comprises four
stages:

### 1. Content Chunking
Indexed HTML is split into overlapping text chunks by `ContentChunkerService`.
Configurable chunk size (default: 500 characters) and overlap (default: 50
characters) control granularity. Chunk boundaries align to word breaks.

### 2. Vector Embeddings
Each chunk is embedded via `mai_translate::EmbeddingServiceInterface` (OpenAI
`text-embedding-3-small`, 1536 dimensions). Vectors are stored in a Solr
`DenseVectorField` schema for k-nearest-neighbour retrieval.

### 3. Hybrid Search
The search query is also embedded and compared against stored vectors using KNN
scoring. The vector proximity score is combined with the BM25 text-relevance
score for a hybrid ranking that improves semantic match quality.

### 4. Answer Synthesis
Top-ranked context chunks are assembled into a prompt and sent to the OpenAI
Chat Completions API (`gpt-4o-mini`). `SearchSynthesisService` returns a concise
answer summary with source references. The Fluid template renders the answer
card above the hit list; the card is hidden when RAG is disabled.
