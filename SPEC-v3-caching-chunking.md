# mai_search — v3 Spec: RAG-Caching & Chunking

> **Status: Phasen A–G umgesetzt** (Query-Embedding-Cache, Dokument-/Chunk-Embedding-Skip,
> Answer-Cache-Erweiterungen, Chunk-Indexierung + Rank-Fusion für Solr und Meilisearch,
> Orphan-Cleanup + `mai-search:cleanup-chunks`, Konfiguration). Alle Unit-Tests grün,
> PHPStan/PHPCS clean. Dieses Dokument bleibt als Entscheidungs-Historie stehen —
> siehe `FEATURES.md` für die nutzerorientierte Zusammenfassung des Endergebnisses.

## Mandat

Zwei zusammenhängende Themen, die der Nutzer explizit zusammen geplant haben möchte:

1. **Caching der AI-Antworten** (und der dafür nötigen Embeddings), inklusive
   sauberer Invalidierung bei Content-Änderungen (Save-Hooks) und Reindexierung.
2. **Chunking überdenken** — aktuell toter Code (`ContentChunkerService`,
   `tx_maisearch_chunks`, `OrphanChunkCleanupService`) — mit Blick auf
   `.lookup/smart-search` (`boehmmatthias/smart_search`), wie sich Chunks bei
   Solr **und** Meilisearch sauber für Semantic Search abbilden lassen, inkl.
   Invalidierung/Reindexierung.

Dies ist ein **Planungsdokument** (Spec-driven Development, siehe
`General Rules`). Es wird **nichts implementiert**, bevor der Plan
abgenommen ist. Die Entscheidungen in Abschnitt 6 sind vom Nutzer bestätigt;
der Plan (inkl. der daraus resultierenden Konsequenzen in Abschnitt 4.6) ist
damit umsetzungsreif, sobald das finale Go für die Implementierung erfolgt.

> **Kontext beim Schreiben dieses Plans:** Im Arbeitsverzeichnis liegen bereits
> unversionierte/uncommittete Änderungen (`Classes/Middleware/SearchAnswerMiddleware.php`,
> `Classes/Http/RagApiSupport.php`, Anpassungen an `SearchController`/
> `SearchStreamMiddleware`), die einen proxy-sicheren synchronen RAG-Antwort-Endpunkt
> (`POST /api/search/answer`) als Fallback zum SSE-Streaming einführen. Dieser Plan
> baut darauf auf und geht davon aus, dass diese Arbeit bestehen bleibt bzw. fertig
> committet wird — er verändert sie nicht.

---

## 1. Ist-Zustand (Audit, mit Beleg im Code)

### 1.1 Was schon funktioniert

- **`AnswerCacheService`** (`Classes/Service/AnswerCacheService.php`) cached
  bereits die fertig synthetisierte Antwort. Cache-Key =
  `sha256(normalizedQuery|contextURLs|history)`. TYPO3-Caching-Framework,
  Frontend `maisearch_answer`, DB-Backend, TTL 3600s. Tag pro Context-URL
  (`context_<md5(url)>`) + globaler Tag `maisearch_answer`.
- **`ContentHashService`** (`Classes/Service/ContentHashService.php`) hält pro
  `(type, uid)` einen SHA-256-Hash des extrahierten Contents in
  `tx_maisearch_content_hash`.
- **Verdrahtung:** `AbstractIndexer::trackContentChange()`
  (`Classes/Indexer/AbstractIndexer.php:100-113`) berechnet bei jedem
  `sendDocument()`-Aufruf den neuen Hash, vergleicht ihn, und ruft **nur bei
  echter Content-Änderung** `AnswerCacheService::invalidateForUrl()` auf.
  Metadaten-only-Saves (Timestamp, Workspace-State, …) lösen also **keine**
  Cache-Invalidierung aus — das ist bereits genau das Verhalten, das der
  Nutzer für "nach Save-Hook invalidieren" haben möchte, und es ist gut gebaut.
- `SearchSynthesisService::synthesise()` liest/schreibt den Answer-Cache; das
  gilt inzwischen für **beide** synchronen Pfade: den klassischen
  `SearchController::resultsAction()` und den neuen
  `SearchAnswerMiddleware` (`POST /api/search/answer`).

### 1.2 Lücken im Caching (das eigentliche Thema dieses Plans)

| # | Lücke | Beleg | Auswirkung |
|---|-------|-------|------------|
| G1 | **Streaming-Pfad umgeht den Answer-Cache komplett.** `SearchStreamMiddleware::streamResponse()` ruft `$chatProvider->stream()` immer live auf. | `Classes/Middleware/SearchStreamMiddleware.php` | Bei aktivem Streaming (`ragStreamingEnabled=1`, das ist der empfohlene UX-Modus) wird **nie** aus dem Cache bedient — jede Anfrage kostet einen vollen LLM-Call, selbst bei identischer Frage. |
| G2 | **Query wird pro Request bis zu zweimal embedded.** `SearchController::resultsAction()` ruft `SearchService::search()` einmal auf (embedded die Query für den KNN-Rerank in `SolrBackend`), und wenn `ragRerankEnabled=1` ist, ruft `RagContextResolver::resolve()` **erneut** `SearchService::search()` mit demselben Query-Text auf → zweiter Embedding-Call für denselben String, in derselben Anfrage. | `Classes/Controller/SearchController.php:90-114`, `Classes/Domain/Service/RagContextResolver.php:42-49`, `Classes/Backend/Solr/SolrBackend.php:207-227` | Doppelte Kosten/Latenz pro Suchanfrage, sobald Rerank aktiv ist — unabhängig von jedem Cache. |
| G3 | **Kein Embedding-Cache für Queries.** Jede RAG-Suche embedded den Query-Text neu, auch wenn exakt dieselbe Frage (von einem anderen Besucher, oder demselben durch Pagination/erneutes Absenden) kurz zuvor schon embedded wurde. | — | Genau der vom Nutzer benannte Kostentreiber ("Fragen... jedes mal auch embedded"). |
| G4 | **Kein Skip beim Dokument-Embedding, wenn sich der Inhalt nicht geändert hat.** `IndexManagementService::addEmbeddingToDocument()` ruft bei **jedem** `addDocument()`/`addDocumentForLanguageCode()` bedingungslos `$embeddingProvider->embed($content)` auf. | `Classes/Domain/Service/IndexManagementService.php:337-372` | Ein kompletter Reindex (Scheduler-Task, Backend-Button) embedded **jedes** Dokument neu, auch wenn sich außer einem Tippfehler im Titel nichts geändert hat. `ContentHashService` existiert bereits und würde das erkennen — wird hier aber nicht gefragt. |
| G5 | **`invalidateAll()` wird nirgends aufgerufen.** Die Methode existiert (`AnswerCacheService::invalidateAll()`), aber `IndexManagementService::clearIndex()`/`clearAllCores()`/`reindexAll()`/`repopulateQueue()` und `FullReindexTask` rufen sie nicht. | Grep über `Classes/` bestätigt: einziger Aufrufer von irgendetwas auf `AnswerCacheService` ist `AbstractIndexer::trackContentChange()` (`invalidateForUrl`) und `SearchSynthesisService` (`get`/`set`). | Ein manuelles "Index leeren" im Backend-Modul lässt alte gecachte Antworten unangetastet, auch wenn sie sich auf Dokumente beziehen, die gerade aus dem Index geflogen sind. |
| G6 | **Kein manueller "Cache leeren"-Knopf im Backend-Modul.** | `Classes/Controller/Backend/SearchBackendController.php` (keine entsprechende Action) | Admin kann eine fehlerhafte/veraltete AI-Antwort nicht gezielt aus dem Cache werfen, ohne den ganzen Suchindex neu aufzubauen. |

### 1.3 Chunking: aktueller Zustand ist totes Gerüst

- `Classes/Service/ContentChunkerService.php` — Sliding-Window-Chunking,
  **wird von nichts aufgerufen** (bestätigt per Grep).
- `ext_tables.sql`: Tabelle `tx_maisearch_chunks`
  (`document_type`, `document_uid`, `chunk_index`, `chunk_content`,
  `chunk_hash`, `tstamp`) — **existiert, wird von nichts beschrieben.**
- `Classes/Service/OrphanChunkCleanupService.php` — kann verwaiste Zeilen in
  `tx_maisearch_chunks` löschen, wird aber **nirgends aufgerufen** und würde,
  selbst wenn sie aufgerufen würde, nur die lokale Buchhaltungstabelle
  bereinigen — **nicht** die zugehörigen Dokumente im Suchbackend (Solr/
  Meilisearch), die die eigentlichen Chunk-Vektoren tragen sollen.
- Konsequenz: RAG-Retrieval passiert heute ausschließlich auf
  **Dokumentenebene** — ein einzelner Vektor pro Seite/Datensatz
  (`content_vector` in Solr, `SchemaManager::VECTOR_DIMENSIONS = 1024`,
  Ollama `bge-m3`). Lange Inhalte werden vor dem Embedding auf
  `maxInputTokens` des Providers gekürzt
  (`IndexManagementService::addEmbeddingToDocument()`, `TOKENS_PER_CHAR`-Schätzung)
  — der Rest eines langen Artikels ist für Vektorsuche **unsichtbar**.
- `MeilisearchBackend::getCapabilities()->supportsVectorSearch` ist `false` —
  dort gibt es aktuell überhaupt keine Vektorsuche, chunked oder nicht.

### 1.4 Relevante Lessons aus `.lookup/smart-search`

`smart_search` löst ein verwandtes, aber nicht identisches Problem: es besitzt
seinen eigenen Vektorspeicher (`VectorRepository`, gepackte Float32-Blobs).
`mai_search` will das laut SPEC-v2 explizit **nicht** — Vektoren leben in
Solrs KNN-Feld bzw. in Meilisearch, nicht in einer eigenen MySQL-Tabelle. Was
trotzdem direkt übertragbar ist:

- **`VectorService::embedAndStore()`** (`.lookup/smart-search/Classes/Service/VectorService.php:37-75`):
  hasht den Text, vergleicht mit gespeichertem Hash, **überspringt den
  Embedding-API-Call komplett**, wenn der Hash gleich ist. Genau der
  Mechanismus, der in `mai_search` für G4 fehlt — und `mai_search` hat mit
  `ContentHashService` bereits die halbe Infrastruktur dafür.
- **`VectorService::embedAndStoreChunked()`** (Zeilen 107-169): Chunk-IDs als
  `"{identifier}_chunk_{n}"`, räumt nach jedem Schreibvorgang verwaiste
  Chunk-IDs auf, die es vorher gab, jetzt aber nicht mehr (Dokument ist
  geschrumpft). Das Muster (Suffix-basierte Chunk-Identität +
  Präfix-Scan + strikter Regex-Check gegen Kollisionen) ist 1:1 auf Solr-/
  Meilisearch-Dokument-IDs übertragbar (`{type}-{uid}-chunk-{n}` statt
  `{type}-{uid}`).
- **`VectorService::findSimilar()`** cached ganze Suchergebnis-Sets pro
  Collection mit TTL + Tag-Invalidierung bei jedem Schreib-/Löschvorgang
  (`flushQueryCache()`). Granularität ist gröber als `mai_search`s
  URL-genaues Tagging im `AnswerCacheService` — für den **Query-Embedding-
  Cache** (Abschnitt 3) ist das grobe Schema aber unnötig, weil Embeddings
  eines festen Query-Strings zeitlich stabil sind (siehe dort).
- **`collapseChunks`** (`VectorService::findSimilar()`, `HybridSearchService`):
  bei mehreren Chunk-Treffern desselben Elterndokuments wird nur der
  bestbewertete behalten, damit ein einzelnes langes Dokument nicht alle
  Trefferplätze belegt. Relevant für die Solr-/Meilisearch-Chunk-Suche
  (Abschnitt 4).
- **`CleanupCommand`/`OrphanProviderInterface`** (`.lookup/smart-search/Classes/Command/`):
  CLI-Command, der pro registriertem Provider eine Liste "aktuell lebender
  IDs" abfragt und alles andere löscht, mit `--allow-empty`-Sicherheitsnetz
  gegen einen kaputten Provider, der versehentlich "nichts lebt mehr" meldet
  und die ganze Collection leerräumt. Guter Präzedenzfall für einen
  `mai_search:cleanup-chunks`-Befehl (Abschnitt 4.4), auch wenn `mai_search`
  aktuell kein Analogon zu "Collection" braucht (Chunks sind immer an ein
  konkretes `(type, uid)` gebunden, das über `IndexerRegistry` bereits
  bekannt ist).
- **Nicht übertragbar:** eigener Vektorspeicher/Codec, strikte
  Metadaten-Gleichheitsfilter, RRF-Fusion (Solr/Meilisearch bringen eigenes
  Ranking mit) — deckt sich mit dem, was SPEC-v2 Phase 7 addendum schon
  festgehalten hat.

---

## 2. Ziele dieses Plans

1. Keine Query wird innerhalb derselben Anfrage zweimal embedded (G2).
2. Wiederholte Queries (gleicher Text) nutzen ein gecachtes Embedding statt
   erneut die Provider-API zu bemühen (G3).
3. Ein Reindex, der den extrahierten Inhalt eines Dokuments nicht ändert,
   ruft den Embedding-Provider für dieses Dokument **nicht** erneut auf (G4).
4. RAG-Antworten werden unabhängig vom gewählten Ausgabemodus (synchron,
   Streaming) aus dem Cache bedient, wenn Query+Kontext+History
   übereinstimmen (G1).
5. Der Answer-Cache wird bei Volltext-Reindex/Index-Leeren korrekt
   invalidiert; Admins können ihn zusätzlich manuell leeren (G5, G6).
6. Lange Inhalte sind für RAG-Retrieval **vollständig** durchsuchbar, nicht
   nur die ersten `maxInputTokens` Zeichen — via Chunk-Index für Solr und
   Meilisearch.
7. Chunk-Invalidierung ist an dieselbe Content-Hash-Logik gekoppelt wie schon
   für den Answer-Cache: Reindex eines unveränderten Chunks embedded nicht
   neu; ein geschrumpftes Dokument hinterlässt keine verwaisten Chunks im
   Suchbackend.

---

## 3. Entwurf: Query-Embedding-Cache

Neuer Service `Classes/Service/EmbeddingCacheService.php` (Name vorläufig),
der als **Decorator** vor den aktiven `EmbeddingProviderInterface` gehängt
wird — ausschließlich für **Query-Zeit**-Embeddings (KNN-Rerank in
`SolrBackend::applyHybridKnnReRank()`, künftige Chunk-Suche in
`RagContextResolver`), nicht für Dokument-Indexierung (dafür siehe
Abschnitt 4, andere Optimierung passt dort besser).

**Warum ein Decorator statt Cache-Logik direkt in `SolrBackend`:** Der exakt
gleiche Bedarf entsteht in Abschnitt 4 nochmal (Chunk-Suche embedded auch
eine Query), und potenziell in `MeilisearchBackend`, sobald dort Vektorsuche
existiert. Ein Decorator hinter `ProviderRegistry::getActiveEmbeddingProvider()`
deckt alle Aufrufer auf einmal ab, ohne dass jeder Call-Site eigene
Cache-Logik braucht — passt zum bestehenden Muster
(`StreamingChatProviderInterface` liegt genauso transparent über
`ChatProviderInterface`).

- **Cache-Key:** `sha256(providerIdentifier . '|' . normalizedQueryText)`.
  Providerkennung **muss** Teil des Keys sein — wechselt `activeEmbeddingProvider`
  (z. B. OpenAI → Ollama, andere Dimension/Modell), soll der alte
  Cache-Eintrag automatisch verfehlt werden, nicht explizit invalidiert
  werden müssen.
- **Kein Content-bezogenes Invalidieren nötig.** Die Embedding-Funktion ist
  eine reine, deterministische Funktion von (Modell, Text) — sie hat keine
  Abhängigkeit zu irgendeinem indexierten Dokument. TTL reicht als einzige
  Verfallsstrategie.
- **TTL konfigurierbar (Entscheidung 4, Abschnitt 6):** neuer Extension-
  Configuration-Schlüssel `queryEmbeddingCacheTtl`, Default `86400` (24h —
  lang genug um Rerank-Doppelaufrufe und wiederkehrende FAQ-artige Fragen
  abzudecken, kurz genug um nicht unbegrenzt zu wachsen), gelesen über
  `RagConfiguration`.
- **Scope:** nur Query-Strings, keine Dokumentinhalte — Query-Texte sind kurz,
  die Cache-Einträge damit klein. Kein Tagging/Invalidierungsmechanismus
  nötig, nur TTL + Cache-Größe im Auge behalten (TYPO3 Caching Framework
  DB-Backend räumt abgelaufene Einträge selbst auf).
- **Löst G2 nebenbei:** Ruft `RagContextResolver::resolve()` intern denselben
  Query-Text erneut embedden, kommt der zweite Call innerhalb derselben
  Anfrage aus demselben Cache-Eintrag (Cache wurde Millisekunden zuvor
  geschrieben) — kein Sonderfall nötig, reines Nebenprodukt des Caches.

---

## 4. Entwurf: Chunking für RAG (Solr + Meilisearch)

### 4.1 Grundprinzip

Zwei getrennte Retrieval-Pfade, die **nicht** vermischt werden:

1. **Ergebnis-Grid** (Trefferliste, Facetten, Pagination) bleibt wie heute
   auf Dokumentenebene (`type_s`/`type`, ein Treffer pro Seite/News/FAQ/…).
   Das **Ranking** dieser Liste wechselt aber laut Entscheidung 3 (Abschnitt 6)
   von einem separaten Dokument-Vektor auf eine Chunk-basierte Rank-Fusion —
   siehe 4.6. Facetten/Zähler/Pagination selbst sind davon nicht betroffen,
   nur die Reihenfolge der Treffer.
2. **RAG-Kontext** (was der LLM als Grundlage für die Antwort bekommt) wird
   neu auf **Chunk-Ebene** aufgelöst: jeder Chunk ist ein eigenes Dokument im
   Suchbackend mit eigenem Vektor, das nur für die RAG-Kontextsuche bzw. die
   Rank-Fusion (4.6) angefragt wird — nie direkt in der normalen Trefferliste.

Diese Trennung vermeidet, dass Chunks in die Facetten/Zähler der normalen
Suche einsickern. Die Chunk-Vektoren sind ab jetzt die **einzige** Vektorquelle
im System — es gibt keinen zweiten, dokumentweiten Vektor mehr, den es separat
zu pflegen gilt (siehe 4.6).

### 4.2 Schema/Dokumentform

**Solr** — neues, paralleles Dokument pro Chunk, gleiche Cores wie die
Hauptdokumente, aber `type_s = 'chunk'`:

| Feld | Bedeutung |
|---|---|
| `id` | `{type}-{uid}-chunk-{n}` |
| `type_s` | immer `chunk` — Hauptsuche filtert das per `-type_s:chunk` explizit aus |
| `parent_type_s` | Typ des Elterndokuments (`page`, `news`, …) |
| `parent_uid_i` | UID des Elterndokuments |
| `parent_url_s` | URL des Elterndokuments (für Zitat/Quelle in der Antwort) |
| `chunk_index_i` | Position im Elterndokument |
| `content_t` | Chunk-Text |
| `content_vector` | Embedding des Chunk-Texts (gleiches Feld/Schema wie heute, wiederverwendet) |

**Meilisearch** — analoges Dokument in demselben Index, `type: 'chunk'`
filterbar; Vektor über einen `userProvided`-Embedder (kein Meilisearch-eigenes
Embedding-Modell — Provider-Wahl bleibt bei `ProviderRegistry`, konsistent
mit Solr):

- Index-Settings brauchen einmalig `embedders: { default: { source: 'userProvided', dimensions: 1024 } }`
  (via Meilisearch Settings-API — `meilisearch/meilisearch-php: ^1.9`
  unterstützt das; `MeilisearchClientInterface` braucht dafür eine neue
  Methode, z. B. `configureEmbedder(string $indexUid, int $dimensions): void`).
- Chunk-Dokumente bekommen `_vectors.default = [...]` beim Indexieren.
- Retrieval **nicht** über Meilisearch' eingebautes Hybrid-Ranking
  (`hybrid`/`semanticRatio`) — das würde Chunk- und Volltextergebnisse
  vermischen, was wir gerade bewusst trennen. Stattdessen ein reiner
  Vektor-Query mit `filter: type = "chunk"` und `vector`-Parameter, isoliert
  für die RAG-Kontextsuche.

### 4.3 Schnittstellen-Entscheidung

Neues, schlankes Interface statt Erweiterung von `SearchBackendInterface`
(non-breaking, folgt dem bereits im Code etablierten Muster
`StreamingChatProviderInterface extends ChatProviderInterface` /
`instanceof`-Check in `SearchStreamMiddleware`):

```php
interface ChunkSearchBackendInterface
{
    public function indexChunk(ChunkDocumentInput $chunk): void;
    public function removeChunks(string $type, int $uid): void; // alle Chunks eines Elterndokuments
    /** @return list<ChunkResult> */
    public function searchChunks(string $queryText, ?SiteLanguage $language, int $topK): array;
}
```

- `SolrBackend` und (nach 4.2) `MeilisearchBackend` implementieren es.
- `IndexedSearchBackend` implementiert es **nicht** — `RagContextResolver`
  prüft `instanceof ChunkSearchBackendInterface` und fällt sonst auf das
  heutige Dokument-Ebenen-Retrieval zurück (kein Hard-Error, kein RAG-Ausfall
  auf dem Fallback-Backend).
- `BackendCapabilities` bekommt kein neues Feld — die `instanceof`-Prüfung
  **ist** die Capability-Prüfung, genau wie beim Streaming-Chat-Provider.

### 4.4 Indexierung, Hash-Diffing, Orphan-Cleanup

Erweiterung von `AbstractIndexer::sendDocument()`/`buildDocumentWithEvents()`
um einen optionalen Chunking-Schritt (nur wenn Backend `ChunkSearchBackendInterface`
implementiert):

1. `ContentChunkerService::chunk($content)` → Liste von Chunk-Texten.
2. Pro Chunk: Hash bilden (`ContentHashService::computeContentHash()`,
   wiederverwendet), gegen `tx_maisearch_chunks` vergleichen
   (`document_type`, `document_uid`, `chunk_index`).
3. **Unverändert** → nichts tun (weder Embedding-Call noch Backend-Schreibzugriff).
   Das bestehende Chunk-Dokument im Suchbackend bleibt exakt so stehen wie es ist.
4. **Neu/geändert** → embedden, `indexChunk()` aufrufen, Hash in
   `tx_maisearch_chunks` aktualisieren.
5. **Nach der Schleife:** `OrphanChunkCleanupService::cleanupOrphans()`
   liefert die jetzt überzähligen `chunk_index`-Werte (Dokument ist
   geschrumpft) — **muss erweitert werden**, damit sie zusätzlich zum
   Löschen der lokalen Buchhaltungszeilen auch `removeChunks()`/gezielte
   Chunk-Löschung im aktiven Backend auslöst (heute räumt sie nur die MySQL-
   Tabelle auf, s. G-Punkt in 1.3 — das ist eine Lücke im bestehenden Code,
   nicht nur fehlende Verdrahtung).
6. Bei Record-Löschung (`AbstractIndexer::removeDocument()`) zusätzlich
   `removeChunks()` + alle `tx_maisearch_chunks`-Zeilen für `(type, uid)`
   löschen (Analogon zu `contentHashService?->removeContentHash()`, das dort
   schon passiert).

Das läuft transparent sowohl bei einzelnen Saves (`DataHandlerHook` →
Queue → `IndexQueueProcessor::indexRecord()`) als auch bei vollständigem
Reindex (`repopulateQueue()` durchläuft denselben `indexRecord()`-Pfad) —
**kein Sonderfall zwischen Incremental und Full-Reindex nötig**, weil beide
schon heute über dieselbe Queue laufen.

### 4.5 RAG-Kontextsuche wechselt auf Chunks

`RagContextResolver::resolve()` fragt, wenn das aktive Backend
`ChunkSearchBackendInterface` implementiert, `searchChunks()` statt
`SearchService::search()` mit `ragEnabled: true`. Ergebnis sind
`ChunkResult`-Objekte (Chunk-Text + Parent-Titel/URL/Type/Score), die 1:1 wie
heute `SearchResult[]` in `SearchSynthesisService::buildUserPrompt()`
einfließen — der Prompt-Aufbau selbst ändert sich nicht, nur die Herkunft
der Kontext-Schnipsel wird präziser (echte Chunk-Passagen statt eines evtl.
gekürzten `snippet`).

**Entschieden (Abschnitt 6, Punkt 2):** `collapseChunks` bleibt für den
RAG-Kontext aus — mehrere Chunks desselben Elterndokuments dürfen gleichzeitig
im Prompt landen.

### 4.6 Trefferlisten-Rerank via Chunk-basierte Rank-Fusion (Entscheidung 3)

Ersetzt `SolrBackend::applyHybridKnnReRank()`/`addKnnReRankParam()` (den
heutigen, Solr-spezifischen `{!rerank}`/`{!knn}`-Mechanismus über einen
*dokumentweiten* Vektor) durch eine backend-agnostische Lösung, die auf dem
neuen Chunk-Index aufsetzt. Grund für den Umbau statt "Vektor einfach
behalten": ein dokumentweiter Vektor ist bei langen Inhalten
unpräzise (Abschnitt 1.3 — der Kürzungs-Effekt betrifft nicht nur RAG-Kontext,
sondern genauso das Rerank-Signal der Trefferliste), und Meilisearch hätte
sonst dauerhaft kein Äquivalent, weil es dort schlicht keinen
Dokument-Vektor gibt und auch keinen geben soll (kein zweites Vektorsystem
parallel zum Chunk-Index, siehe 4.1).

**Mechanismus — Reciprocal Rank Fusion (RRF), Muster aus
`.lookup/smart-search/Classes/Service/HybridSearchService.php` (siehe 1.4),
in eine neue, backend-unabhängige Klasse `Classes/Search/HybridRankFusionService.php`
extrahiert:**

1. Textsuche wie heute (BM25 in Solr / Meilisearch-Ranking) liefert die
   Kandidatenliste inkl. Rang.
2. Query-Text wird (aus dem Query-Embedding-Cache, Abschnitt 3) embedded und
   gegen den Chunk-Index gesucht (`ChunkSearchBackendInterface::searchChunks()`,
   Abschnitt 4.3) → Chunk-Treffer, pro Elterndokument auf den bestbewerteten
   Chunk reduziert (**nur hier** wird kollabiert — ausschließlich für dieses
   Ranking-Signal, nicht für den RAG-Prompt-Kontext aus 4.5, wo Entscheidung 2
   bewusst mehrere Chunks pro Dokument erlaubt; zwei verschiedene
   Verwendungszwecke desselben Chunk-Index).
3. `HybridRankFusionService` fusioniert beide Ranglisten per RRF
   (`score = Σ 1/(k + rank)`, `k = 60`, wie im smart-search-Vorbild) zu einer
   gemeinsamen Reihenfolge der Elterndokument-IDs.
4. `SolrBackend`/`MeilisearchBackend::search()` sortieren ihre
   `SearchResultPage::results` gemäß dieser fusionierten Reihenfolge um.
   Facetten/`total`/Pagination bleiben unverändert von der Textsuche bestimmt
   — nur die Reihenfolge *innerhalb* der aktuellen Seite ändert sich.
5. Nur relevant, wenn `ragEnabled` (das Feature ist weiterhin an RAG gekoppelt,
   wie das heutige `applyHybridKnnReRank()` auch nur bei `ragEnabled` greift) —
   ohne RAG bleibt es bei reiner Textrelevanz, kein Embedding-Call für die
   normale Suche.

**Konsequenzen, die im Umsetzungsschritt explizit gegengeprüft werden müssen:**

- `SolrBackend`: `content_vector`-Feld auf den Hauptdokumenten wird **nicht
  mehr beschrieben** (kein `addEmbeddingToDocument()`-Aufruf mehr für
  Hauptdokumente — nur noch für Chunks, siehe 4.4). Das Feld bleibt im Schema
  bestehen (harmlos, ungenutzt) statt aktiv migriert/entfernt zu werden —
  kein Migrations-Aufwand nötig, nur toter Feld-Rest im Schema.
- `MeilisearchBackend::getCapabilities()->supportsVectorSearch` wechselt von
  `false` auf `true`, sobald Phase E abgeschlossen ist — Meilisearch bekommt
  dieselbe Rank-Fusion wie Solr, **nicht** über Meilisearchs eigenes
  `hybrid`/`semanticRatio`-Feature (das würde Chunk- und Volltextergebnisse
  im Backend selbst vermischen, siehe 4.2) — die Fusion passiert bewusst
  in PHP, backend-unabhängig, in derselben `HybridRankFusionService`-Klasse.
- Kosten-/Latenz-Trade-off: die Trefferliste braucht jetzt bei RAG-Anfragen
  immer einen zusätzlichen Chunk-KNN-Query (zusätzlich zur Textsuche) — vorher
  war es ein einzelner Solr-Request mit eingebettetem Rerank-Parameter.
  Der Query-Embedding-Cache (Abschnitt 3) hält die Embedding-Kosten dabei
  konstant; der zusätzliche Suchbackend-Roundtrip bleibt als echter Mehraufwand
  bestehen — vertretbar laut Entscheidung 3, aber im Verifikationsschritt
  (Abschnitt 7) auf Latenz zu prüfen.

---

## 5. Cache-Invalidierung — Gesamtübersicht

| Trigger | Betroffener Cache | Mechanismus | Status |
|---|---|---|---|
| Record-Save, Content unverändert | Answer-Cache | keine Invalidierung (korrekt so) | ✅ vorhanden |
| Record-Save, Content geändert | Answer-Cache | `AnswerCacheService::invalidateForUrl()` via `AbstractIndexer::trackContentChange()` | ✅ vorhanden |
| Record-Save, Content geändert | Chunk-Embeddings | Hash-Diff pro Chunk, nur geänderte Chunks neu embedden (4.4) | ⬜ neu |
| Record-Save, Content geändert (unverändert vs. geändert je Chunk) | Query-Embedding-Cache | nicht betroffen — Query-Embeddings hängen nicht an Dokumentinhalt | — n/a |
| Record-Delete | Answer-Cache, Content-Hash, Chunks | `removeDocument()`/`removeContentHash()` (vorhanden) + `removeChunks()` + Chunk-Hash-Zeilen löschen (neu) | teils ⬜ |
| Dokument schrumpft (weniger Chunks) | Chunk-Backend-Dokumente | `OrphanChunkCleanupService`, erweitert um Backend-Löschung (4.4) | ⬜ Lücke schließen |
| Voller Reindex / Queue-Repopulate | Embedding-API-Last | Hash-Skip pro Dokument (G4) und pro Chunk (4.4) | ⬜ neu |
| Voller Reindex / "Index leeren" im Backend-Modul | Answer-Cache | `invalidateAll()` aufrufen (G5) | ⬜ neu |
| Manueller Admin-Wunsch | Answer-Cache | neuer Button in `SearchBackendController`/Backend-Modul (G6) | ⬜ neu |
| `activeBackend` wird gewechselt | Answer-Cache | **keine Sonderbehandlung nötig** — Cache-Key hängt an Context-URLs, nicht am Backend; ein anderes Backend liefert i.d.R. andere/gerankte Kontext-URLs, wodurch der Cache-Key ohnehin abweicht. Volltext-Reindex ist beim Backend-Wechsel ohnehin Pflicht (bestehender Non-Goal aus SPEC-v2). | ✅ selbstheilend, keine Aktion nötig |
| Provider/Modell-Wechsel (Embedding) | Query-Embedding-Cache | Provider-Identifier ist Teil des Cache-Keys → alter Eintrag wird automatisch verfehlt | ✅ by design (Abschnitt 3) |

---

## 6. Entschiedene Fragen (vom Nutzer bestätigt)

1. **Streaming + Cache (G1) → Entschieden: Cache-Hit auch im Stream-Pfad
   nutzen.** `SearchStreamMiddleware` prüft `AnswerCacheService::get()`
   *bevor* der Provider gestreamt wird; bei Treffer wird die Antwort als
   einzelner schneller SSE-Burst ausgegeben (gleiche Event-Struktur wie beim
   echten Stream, nur ohne Token-für-Token-Verzögerung) statt eines echten
   Live-Streams. Betrifft Phase C.
2. **`collapseChunks` für RAG-Kontext (4.5) → Entschieden: nicht kollabieren.**
   Mehrere Chunks desselben Dokuments dürfen gleichzeitig als getrennte
   Kontext-Einträge im Prompt landen; `ragTopN` bleibt die einzige Deckelung.
   Betrifft Phase D/E.
3. **Dokument-Level-Vektor vs. Chunk-Only (Trefferlisten-Rerank) → Entschieden:
   auf bestes Ergebnis optimieren, d. h. der separate Dokument-Vektor
   (`content_vector` auf dem Hauptdokument) wird retiriert.** Der bislang
   Solr-spezifische `{!rerank}`/`{!knn}`-Mechanismus
   (`SolrBackend::applyHybridKnnReRank()`) wird durch eine **backend-agnostische
   Chunk-basierte Rank-Fusion** ersetzt — Details in Abschnitt 4.6. Das ist
   eine größere strukturelle Änderung als die ursprünglich vorgeschlagene
   "beide behalten"-Variante, dafür gewinnt Meilisearch dieselbe
   Hybrid-Ranking-Fähigkeit wie Solr (statt `supportsVectorSearch: false` zu
   bleiben) — beide Backends nutzen ab dann dieselbe Fusion-Logik statt
   jeweils eigener Query-DSL. Betrifft Phase D/E, erhöht deren Umfang.
4. **TTLs → Entschieden: konfigurierbar über Extension Configuration, mit
   sinnvollem Default.** Query-Embedding-Cache: neuer Schlüssel
   `queryEmbeddingCacheTtl`, Default `86400` (24h). Answer-Cache:
   `AnswerCacheService::DEFAULT_TTL` wandert von einer Klassenkonstante zu
   einem Extension-Configuration-Wert `ragAnswerCacheTtl`, Default `3600`
   (1h, unverändert) — beide über `RagConfiguration` gelesen, analog zu
   `ragRerankWidth`/`ragTopN`. Betrifft Phase A (Query-Cache) und Phase C
   (Answer-Cache).
5. **Meilisearch Vector-Store-Feature-Flag → Entschieden: aktivieren, falls
   nötig.** `ProvisionCommand`/`MeilisearchBackend`-Setup prüft beim
   Provisionieren, ob `vectorStore` als Experimental Feature aktiviert werden
   muss (abhängig von der tatsächlich laufenden Meilisearch-Version), und
   aktiviert es automatisch statt manuell vorauszusetzen. Betrifft Phase E.
6. **`mai_search:cleanup-chunks`-CLI-Command → Entschieden: bauen, als
   eigener Command** (Analogon zu `smartsearch:cleanup`, siehe 1.4). Bleibt
   Teil von Phase F, wie ursprünglich vorgeschlagen — kein Vorziehen nötig,
   nur Bestätigung, dass er kommt statt optional zu bleiben.

---

## 7. Testing-Szenarien (für die spätere Umsetzung)

**Unit:**
- `EmbeddingCacheService`: Hit/Miss, Isolation nach Provider-Identifier,
  TTL-Ablauf.
- `IndexManagementService::addEmbeddingToDocument()`: Embedding-Provider wird
  **nicht** aufgerufen, wenn `ContentHashService` "unverändert" meldet;
  Vektor-Feld bleibt im upgedateten Dokument erhalten (Solr Atomic Update /
  Meilisearch Partial-Update-Semantik).
- Chunk-Hash-Diffing: wachsendes Dokument (mehr Chunks) → nur neue Chunks
  embedded; schrumpfendes Dokument → überzählige Chunks werden sowohl lokal
  als auch im Backend entfernt.
- `OrphanChunkCleanupService`, erweitert: ruft `removeChunks()`/gezielte
  Löschung am injizierten Backend-Mock auf, nicht nur DB-Delete.
- `AnswerCacheService`: `invalidateAll()` wird von `IndexManagementService::
  clearIndex()`/`clearAllCores()` und `FullReindexTask` aufgerufen (Spy/Mock).

**Szenario/Verifikation (manuell oder Functional-Test):**
- Gleiche Frage zweimal stellen (RAG an) → zweiter Aufruf misst spürbar
  weniger Latenz, kein zweiter Chat-Completion-Call in den Logs.
- Seite editieren, aber Inhalt inhaltlich unverändert lassen (z. B. nur
  Backend-Layout-Feld ändern) → nach Reindex kein Embedding-API-Call in den
  Logs, Answer-Cache bleibt warm.
- Seite mit langem Fließtext editieren, nur den letzten Absatz ändern → nur
  der/die betroffene(n) Chunk(s) werden neu embedded, nicht das ganze
  Dokument.
- Langen Artikel kürzen, sodass er von 5 auf 3 Chunks schrumpft → Chunks 4/5
  sind danach weder in `tx_maisearch_chunks` noch im Suchbackend auffindbar.
- "Index leeren" im Backend-Modul auslösen → anschließende identische RAG-Anfrage
  erzeugt zwingend eine frische LLM-Antwort (kein stiller Cache-Hit auf
  Datenbasis, die gerade gelöscht wurde).
- `activeBackend` von Solr auf Meilisearch umstellen + Reindex → RAG-Kontext
  liefert Meilisearch-Chunk-Treffer, Antworttext bleibt inhaltlich plausibel
  (kein Backend-spezifisches Datenformat leckt in den Prompt).
- Trefferlisten-Rerank (4.6) auf beiden Backends mit derselben Testfrage
  vergleichen → Reihenfolge der Top-Treffer ist zwischen Solr und Meilisearch
  plausibel ähnlich (beide nutzen dieselbe `HybridRankFusionService`-Logik,
  unterschiedliche Textsuchen als Basis).
- Latenzmessung Trefferliste mit `ragEnabled=1` vor/nach Phase D/E → zusätzlicher
  Chunk-KNN-Roundtrip ist sichtbar, aber durch Query-Embedding-Cache (Phase A)
  bei wiederholten Queries nicht mit einem zweiten Embedding-Call verbunden.

---

## 8. Task-Breakdown / Phasenplan

Reihenfolge nach Abhängigkeit, nicht nach Priorität — A, D und teilweise F
sind unabhängig voneinander und könnten parallel/in beliebiger Reihenfolge
begonnen werden.

- **Phase A — Query-Embedding-Cache + Doppel-Embed-Fix (G2, G3).** Kein
  Schema-Change, kleiner Blast-Radius, sofortiger Kosten-Nutzen. Inkl.
  `queryEmbeddingCacheTtl`-Konfigurationseintrag (Entscheidung 4).
- **Phase B — Dokument-Embedding-Skip bei unverändertem Content (G4).**
  Erfordert Klärung der Solr-Atomic-Update- bzw. Meilisearch-Partial-Update-
  Mechanik, damit das Vektorfeld beim Re-Save nicht verloren geht. Wird durch
  Phase D teilweise überholt (Hauptdokumente bekommen dort gar kein Vektorfeld
  mehr, siehe 4.6) — bleibt aber für die Chunk-Embeddings selbst relevant
  (4.4) und ist unabhängig von D umsetzbar, daher weiter eigene Phase.
- **Phase C — Answer-Cache-Lücken schließen** (Streaming-Cache-Hit als
  SSE-Burst, Entscheidung 1; `invalidateAll()`-Verdrahtung, G5; manueller
  Flush-Button im Backend-Modul, G6; `ragAnswerCacheTtl`-Konfigurationseintrag,
  Entscheidung 4). Unabhängig von Chunking, kann vorgezogen werden.
- **Phase D — Chunk-Indexierung + Rank-Fusion für Solr**
  (`ChunkSearchBackendInterface`, Chunk-Dokumentschema, `AbstractIndexer`-
  Erweiterung, Hash-Diffing, `RagContextResolver`-Umstellung auf Chunks,
  **plus** `HybridRankFusionService` (4.6) und Ablösung von
  `SolrBackend::applyHybridKnnReRank()`). Durch Entscheidung 3 größer im
  Umfang als ursprünglich vorgeschlagen — enthält jetzt sowohl den
  RAG-Kontext-Umbau als auch den Trefferlisten-Rerank-Umbau.
- **Phase E — Chunk-Indexierung + Rank-Fusion für Meilisearch**
  (Embedder-Konfiguration inkl. automatischem Aktivieren des
  `vectorStore`-Feature-Flags falls nötig, Entscheidung 5;
  `MeilisearchClientInterface`-Erweiterung; `MeilisearchBackend` nutzt
  dieselbe `HybridRankFusionService` aus Phase D; `getCapabilities()->
  supportsVectorSearch` wechselt auf `true`). Baut auf D auf — Chunk-
  Domain-Modelle (`ChunkDocumentInput`, `ChunkResult`) und
  `HybridRankFusionService` werden aus D wiederverwendet, nicht dupliziert.
- **Phase F — Orphan-Cleanup vervollständigen** (Backend-Löschung statt nur
  lokaler Tabellenbereinigung) **plus** `mai_search:cleanup-chunks`-CLI
  (Entscheidung 6, bestätigt — kein optionaler Anhang mehr).
- **Phase G — Konfiguration & Doku:** verbleibende `ext_conf_template.txt`-
  Einträge (Chunk-Größe/Overlap), `SPEC-v2.md` Phase-7-Addendum als erledigt
  markieren, `FEATURES.md` aktualisieren (insbesondere: Meilisearch-Zeile
  "keine Vektorsuche" entfernen).

---

## 9. Was sich NICHT ändert

- `SearchResult`-DTO-Form bleibt external contract (wie in SPEC-v2 festgehalten).
- Die Trefferliste (Facetten, Pagination, Relevanz-Ranking) bleibt
  dokumentweise — Chunks tauchen dort nie auf.
- Bestehende Indexer-Klassen (`NewsIndexer`, `FaqIndexer`, …) behalten ihre
  öffentliche API; Chunking passiert in der gemeinsamen Basisklasse.
- `mai_meilisearch` bleibt unangetastet (bestehender Non-Goal aus SPEC-v2).

## 10. Explizite Nicht-Ziele

- Kein eigener Vektorspeicher (bleibt Solr/Meilisearch-Aufgabe, wie in
  SPEC-v2 entschieden).
- Keine Migration bestehender Indizes zwischen Backends im Rahmen dieses Plans.
- Kein Umbau der Prompt-Konstruktion selbst (`SearchSynthesisService::
  buildUserPrompt()`) — nur die Herkunft der Kontext-Snippets ändert sich.
