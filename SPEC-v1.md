# mai_search — v1 Spec

## Scope

**v1 delivers full-text site search via Apache Solr** with a plugin-based indexer/result-formatter architecture. It replaces the default TYPO3 indexed-search for the BGM site.

### In scope
- Full-text content search across pages, news, faq, gallery, events, timeline, members, team, testimonials, jobs, locations
- Plugin-based indexer registration (DI tag `maispace.search.indexer`)
- Plugin-based result formatting (DI tag `maispace.search.result_formatter`)
- Search result DTO with type, title, snippet, URL, icon, date
- TypoScript-based Solr connection config (core, host, port, path)
- Scheduler task for full reindex
- Frontend plugin: search form + results
- Solr query: full field search, ranked by relevance + per-type boost

### Out of scope for v1
- Faceted search (planned v2)
- Autocomplete / suggest (v2)
- Did-you-mean spell correction (v2)
- Geographic search (v2, if needed)
- Highlighted snippets in result body (v2)
- Solr schema management (handled externally)
- Multi-language field configuration (single-field search only)

---

## Architecture

```
[Frontend plugin]
    │
    ▼
SearchService ─────────► IndexManagementService
    │                          │
    │                    FullReindexTask (scheduler)
    │
    ▼
Solr (external) ◄── Indexer implementations
    │
    ▼
ResultFormatter implementations ◄── FE rendering
```

### Key contracts

**`SearchIndexerInterface`**
```php
public function getType(): string;                          // unique key, e.g. 'page', 'news'
public function indexAll(IndexingContext $context): void;   // batch-index all records
public function indexRecord(                           // index single record on save
    object $record, 
    IndexingContext $context
): void;
public function removeRecord(int $uid, string $table): void; // delete from Solr
public function getBoost(string $type): float;          // relevance multiplier
public function supports(string $table): bool;          // does this indexer handle a table?
```

**`SearchResultFormatterInterface`**
```php
public function getType(): string;                          // matches indexer type
public function formatResult(array $solrDoc): SearchResult; // Solr doc → DTO
public function getIcon(string $type): string;              // CSS class / icon identifier
```

**`SearchResult`** (DTO)
```php
readonly class SearchResult {
    public string $type;      // indexer type
    public string $title;     
    public string $snippet;   // excerpt / abstract
    public string $url;       
    public string $icon;      // CSS class
    public ?DateTime $date;   
    public float $score;      
}
```

---

## Extension-level dependencies (runtime)

- `typo3/cms-core` ^14.1
- `typo3/cms-extbase` ^14.1
- `typo3/cms-fluid` ^14.1
- `typo3/cms-scheduler` ^14.1
- `apache-solr-for-typo3/solr` ^14.0 (ext-solr)

→ Add to `composer.json` requires section.

---

## Files to create

```
Classes/
├── Domain/
│   ├── Dto/
│   │   └── SearchResult.php              # readonly DTO
│   ├── Model/
│   │   └── IndexingContext.php           # carries connection config + batch info
│   ├── Service/
│   │   ├── SearchService.php             # search entry point: query → SearchResult[]
│   │   └── IndexManagementService.php    # CRUD on Solr index (add/delete/clear)
│   └── Solr/
│       └── ConnectionFactory.php         # builds Solr client from TypoScript
├── Indexer/
│   └── AbstractIndexer.php               # base class with common Solr doc builder
├── ResultFormatter/
│   └── AbstractResultFormatter.php       # base class with common field mapping
├── Scheduler/
│   └── FullReindexTask.php               # scheduler task: walk all indexers
├── Controller/
│   └── SearchController.php              # Extbase plugin controller
└── Service/
    ├── IndexerRegistry.php               # collects tagged indexer services
    └── ResultFormatterRegistry.php       # collects tagged formatter services
```

Implementing extensions (each gets its own `Indexer` + optional `ResultFormatter`):

| Extension | Indexer class | Status |
|---|---|---|
| `mai_search` | `PageIndexer` (pages + tt_content — built into `mai_search`) | ✅ Implemented |
| `mai_news` | `NewsIndexer` | ✅ Implemented |
| `mai_faq` | `FaqIndexer` | ✅ Implemented |
| `mai_gallery` | `GalleryIndexer` | ✅ Implemented |
| `mai_jobs` | `JobsIndexer` | ✅ Implemented |
| `mai_locations` | `LocationIndexer` | ✅ Implemented |
| `mai_member` | `MemberIndexer` | ✅ Implemented |
| `mai_team` | `TeamMemberIndexer` | ✅ Implemented |
| `mai_testimonials` | `TestimonialsIndexer` | ✅ Implemented |
| `mai_timeline` | `TimelineIndexer` | ✅ Implemented |
| `mai_events` | `EventsIndexer` | 📝 Planned (tracked: `events-5`) |

---

## Implementation order

| Step | What | Depends on |
|------|------|------------|
| 1 | Create DTOs + contracts: `SearchResult`, `IndexingContext`, `SearchIndexerInterface`, `SearchResultFormatterInterface` | Nothing |
| 2 | Create `Solr\ConnectionFactory` — builds ExtSolr `SolrService` from TypoScript | Step 1 |
| 3 | Create `Service\SearchService` — queries Solr, returns typed `SearchResult[]` | Steps 1–2 |
| 4 | Create `Service\IndexManagementService` — add/delete/clear | Steps 1–2 |
| 5 | Create abstract base classes: `AbstractIndexer`, `AbstractResultFormatter` | Step 1 |
| 6 | Create registries: `IndexerRegistry`, `ResultFormatterRegistry` (DI tag collecting) | Step 1 |
| 7 | Create `Scheduler\FullReindexTask` — iterates all indexers | Steps 4–6 |
| 8 | Create `SearchController` — search form + results action | Steps 3, 6 |
| 9 | Register TypoScript constants + setup (Connection, plugin) | Step 8 |
| 10 | Implement indexers in each extension — see implementation map above (10 done, 1 planned) | Steps 5–6 |
| 11 | Implement result formatters per extension | Steps 5–6 |
| 12 | Integration test: reindex, search, verify results | All |

---

## Indexer conventions per extension

Each implementing extension registers:

```yaml
# Configuration/Services.yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Maispace\MaiNews\Indexer\NewsIndexer:
    tags:
      - name: 'maispace.search.indexer'
      - name: 'maispace.search.result_formatter'
```

One class implements both interfaces unless formatting needs diverge.

Indexer `indexAll()` paginates in batches of 100 to avoid memory issues.

---

## Solr document schema (minimal)

| Field | Type | Source |
|-------|------|--------|
| `id` | string | `{type}-{uid}` |
| `type_s` | string | indexer type key |
| `title_s` | string | record title |
| `content_t` | text | concatenated body fields |
| `url_s` | string | frontend URL |
| `uid_i` | int | record UID |
| `crdate_dt` | date | creation date |
| `boost_i` | int | per-type boost value |

---

## TypoScript

```typo3_typoscript
plugin.tx_maisearch {
    settings {
        solr {
            host = localhost
            port = 8983
            path = /solr/core_en
            core = core_en
        }
        resultsPerPage = 20
    }
}
```

Multi-language: each language gets its own Solr core. `core_en`, `core_de`, etc. Selected by `$GLOBALS['TSFE']->sys_language_uid` → configurable mapping.
