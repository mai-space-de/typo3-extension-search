# Search reindex (after News/Event doktype cutover)

After migrating News/Event records to custom page doktypes (120/121), rebuild the
search index so documents use public SiteRouter page URLs.

## DDEV

```bash
ddev exec vendor/bin/typo3 mai-search:index --no-interaction
```

Useful variants:

```bash
# Provision indexes only (no content)
ddev exec vendor/bin/typo3 mai-search:index --schema-only --no-interaction

# Index statistics
ddev exec vendor/bin/typo3 mai-search:index --stats --no-interaction
```

## Backend

TYPO3 backend module **Search** → reindex action (same `IndexManagementService`
path as the CLI command).

## What gets indexed

| Indexer | Source | Public URL |
|---------|--------|------------|
| `PageIndexer` | `pages` doktypes 1, 120, 121 | SiteRouter page UID |
| `NewsIndexer` | `pages` doktype 120 | SiteRouter page UID |
| `EventIndexer` | `pages` doktype 121 | SiteRouter page UID |

Hydration / CI already call `mai-search:index` when Solr/Meilisearch is reachable
(`scripts/hydration/import-all.sh`, `scripts/e2e-test/ci-bootstrap.sh`).
