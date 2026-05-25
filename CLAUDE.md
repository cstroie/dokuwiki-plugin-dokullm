# DokuLLM Plugin — Codebase Notes

## Overview

DokuLLM is a DokuWiki plugin that integrates Large Language Model (LLM) capabilities with semantic search via ChromaDB. It adds an editor toolbar with AI-powered text-processing actions (create, rewrite, summarize, etc.) and supports vector-based document search using Ollama embeddings.

- **Plugin ID**: `dokullm`
- **Author**: Costin Stroie <costinstroie@eridu.eu.org>
- **License**: GPL 2.0
- **Minimum PHP**: 7.4

---

## File Map

| File | Role |
|---|---|
| `action.php` | Main DokuWiki action plugin — event hooks, AJAX routing, template handling, ChromaDB page indexing |
| `LlmClient.php` | OpenAI-compatible API client — prompt loading, tool calls, ChromaDB queries |
| `ChromaDBClient.php` | ChromaDB v2 REST client — collection management, Ollama embedding generation, document chunking/indexing |
| `cli.php` | DokuWiki CLI plugin — batch `send`/`query`/`get`/`list`/`heartbeat`/`identity` commands |
| `MenuItem.php` | Page-tools menu item for the "Copy page" button |
| `script.js` | Frontend toolbar — fetches actions, sends AJAX requests, handles result modes |
| `style.css` | Toolbar and modal CSS |
| `conf/default.php` | Default config values |
| `conf/metadata.php` | Config field types for the DokuWiki admin UI |
| `lang/en/lang.php` | English strings (PHP + JS via `$lang['js'][...]`) |
| `lang/ro/lang.php` | Romanian strings |
| `doc/prompts.txt` | Prompt system and namespace documentation |
| `doc/llm.txt` | Additional LLM documentation |

---

## Architecture

### Request Flow (Editor → LLM)

1. Page loads → `action.php::handleDokuwikiStarted` injects config into `JSINFO.plugins.dokullm`
2. Edit page → `action.php::handleMetaHeaders` injects `script.js`
3. User clicks a toolbar button → `script.js` POSTs to `lib/exe/ajax.php?call=plugin_dokullm`
4. `action.php::handleAjax` routes to `processRequest()`
5. `processRequest()` instantiates `LlmClient` (and optionally `ChromaDBClient`) then calls `LlmClient::process()`
6. `LlmClient::process()` loads prompt from DokuWiki page, calls the LLM API, optionally handles tool calls
7. JSON result returned to JS; JS updates the editor textarea

### Page Indexing Flow

`INDEXER_TASKS_RUN` hook → `handlePageSave()` → `sendPageToChromaDB()` → `ChromaDBClient::processSingleFile()`

---

## Configuration Keys (`conf/default.php`)

| Key | Type | Default | Notes |
|---|---|---|---|
| `provider` | multichoice | `openai` | `openai` = any OpenAI-compatible API; `anthropic` = Anthropic native API; `ollama` = Ollama native `/api/chat` |
| `openai_api_url` | string | OpenAI chat completions URL | Any OpenAI-compatible endpoint; ignored when provider=anthropic |
| `openai_api_key` | password | `''` | Bearer token; empty = no auth |
| `openai_model` | string | `gpt-4o-mini` | Model ID for the OpenAI path |
| `anthropic_api_key` | password | `''` | Sent as `x-api-key` header; only used when provider=anthropic |
| `anthropic_model` | string | `claude-sonnet-4-6` | Model ID for the Anthropic path |
| `timeout` | int | `30` | cURL timeout in seconds (min 5) |
| `profile` | string | `default` | Controls which `dokullm:profiles:PROFILE:` namespace is used |
| `temperature` | float | `0.3` | 0.0–1.0 |
| `top_p` | float | `0.8` | 0.0–1.0 |
| `top_k` | int | `20` | ≥ 1 |
| `min_p` | float | `0.0` | 0.0–1.0 |
| `think` | bool | `false` | Sends `think: true` in API payload; strips `<think>` tags from response |
| `tools` | bool | `false` | Enables `get_document`, `get_template`, `get_examples` tool calls |
| `show_copy_button` | bool | `true` | Show "Copy page" in page tools |
| `replace_id` | bool | `true` | Replace template page ID in copied content |
| `enable_chromadb` | bool | `false` | Master switch for all ChromaDB features |
| `chroma_host` | string | `127.0.0.1` | |
| `chroma_port` | int | `8000` | |
| `chroma_tenant` | string | `dokullm` | |
| `chroma_database` | string | `dokullm` | |
| `chroma_default_collection` | string | `documents` | Fallback collection when page ID has no first-segment (e.g. pages outside a namespace) |
| `ollama_host` | string | `127.0.0.1` | Shared by LLM and embeddings |
| `ollama_port` | int | `11434` | Shared by LLM and embeddings |
| `ollama_model` | string | `llama3.2` | LLM model for the Ollama provider path |
| `ollama_embeddings_model` | string | `nomic-embed-text` | Used for embedding generation only |

---

## Prompt System

Prompts are stored as **DokuWiki pages** in the `dokullm:` namespace, not as files in the plugin.

### Namespace layout

```
dokullm:profiles:
└── {PROFILE}/
    ├── (index page)       ← action definitions table
    ├── system             ← base system prompt
    ├── {ACTION}           ← user-message prompt for each action
    └── system:{ACTION}    ← optional system prompt appendage per action
```

### Action definitions table

The profile index page (`dokullm:profiles:PROFILE`) contains a DokuWiki table with columns:

`| ID | Label | Description | Icon | Result |`

- **ID**: action name (or a `[[link]]` to a sub-page — last colon-segment is used)
- **Result**: `show` | `append` | `replace` | `insert`
- Parsing stops after the first table ends

### Prompt placeholders

`{text}`, `{template}`, `{examples}`, `{snippets}`, `{previous}`, `{prompt}`, `{current_date}`, `{current_time}`, `{previous_date}`, `{action}`, `{think}`

### Prompt loading fallback

1. Try `dokullm:profiles:{PROFILE}:{ACTION}`
2. Fall back to `dokullm:profiles:default:{ACTION}`
3. Throw exception if neither exists

---

## Page Metadata Directives

These are embedded in DokuWiki page content and parsed by `script.js`:

| Directive | Purpose |
|---|---|
| `~~LLM_TEMPLATE:page_id~~` | Associate a template page |
| `~~LLM_EXAMPLES:id1,id2~~` | Reference example pages |
| `~~LLM_PREVIOUS:page_id~~` | Reference a previous report |

When a page is copied from a template whose ID contains the word `template`, `~~LLM_TEMPLATE:template_id~~` is automatically inserted after the first title line.

---

## ChromaDB Document ID Format

Documents are indexed with IDs derived from the DokuWiki page ID:

```
reports:mri:institution:250620-name-surname  →  Format 1 (institution-based)
reports:mri:2024:g287-name-surname           →  Format 2 (year-based)
reports:mri:templates:name                   →  Template (type=template metadata)
```

Chunks are indexed as `{dokuwiki_id}@{paragraph_index}`. The collection name is taken from the **first** colon-segment of the page ID (e.g. `reports:mri:...` → collection `reports`). If the page ID has no colon, `chroma_default_collection` is used as fallback.

Metadata stored per chunk: `document_id`, `processed_at`, `type` (`report`|`template`), `modality`, `institution`/`year`, `name`, `registration`, `date`, `chunk_id`, `chunk_number`, `total_chunks`, `tags`.

---

## LLM Tool Calls

When `tools = true`, the LLM can call three tools:

| Tool | Description |
|---|---|
| `get_document` | Fetch a DokuWiki page by ID |
| `get_template` | Retrieve the best-matching template from ChromaDB |
| `get_examples` | Retrieve N example snippets from ChromaDB |

Loop protection:
- Each individual tool is capped at **3 calls**
- Total tool calls capped at **10**
- When limits hit, tools are stripped from the next API call to force a final answer

---

## CLI Usage

```
./bin/plugin.php dokullm send <path>                    # index file or directory into ChromaDB
./bin/plugin.php dokullm query [-c collection] [-l N] [-t type] <search terms>
./bin/plugin.php dokullm get <document_id>
./bin/plugin.php dokullm list
./bin/plugin.php dokullm heartbeat
# Add -v for verbose output
```

Must be run as the web server user (e.g. `sudo -u www-data php ./bin/plugin.php dokullm ...`) because ChromaDB client reads DokuWiki config which requires proper file permissions.

---

## Known Issues / Technical Debt

### Bugs

All previously identified bugs have been fixed:

1. ~~**`LlmClient.php` — Dead code in `getChromaDBClient()`**~~ — Fixed.
2. ~~**Config key mismatch**~~ — Fixed: `$meta['use_tools']` renamed to `$meta['tools']`; lang files updated.
3. ~~**`cli.php` — Wrong config key**~~ — Fixed: `getConf('ollama_model')` → `getConf('ollama_embeddings_model')`.
4. ~~**Fallback profile path typo**~~ — Fixed: `dokullm:profile:default` → `dokullm:profiles:default` in `loadPrompt()`.
5. ~~**Missing `global $ID` in `processRequest()`**~~ — Fixed: added `global $INPUT, $ID;`.
6. ~~**`chroma_collection` config was dead**~~ — Fixed: renamed to `chroma_default_collection`; now used as actual fallback everywhere hardcoded `'documents'` appeared.
7. ~~**`find_template` always queried wrong collection**~~ — Fixed: DokuWiki AJAX sets `$ID` from GET only; `id` is now read from `$INPUT` (which covers POST) in `processRequest()` and passed explicitly.
8. ~~**ChromaDB multi-condition where clause rejected**~~ — Fixed: multiple filter conditions are now wrapped in `{"$and": [...]}` as ChromaDB v2 requires; single conditions use the plain `{"field": {"$eq": "val"}}` form.
9. ~~**`DOKU_DATA` undefined warning**~~ — Fixed: `DOKU_DATA` is not defined on this DokuWiki setup; replaced with `$conf['savedir']` in `action.php` and `conf/metadata.php`.
10. ~~**Model cache `tmp/` directory missing**~~ — Fixed: `saveModelCache()` now calls `mkdir(..., true)` before writing.

### DokuWiki Environment Quirks

- **`DOKU_DATA` is not defined** on this installation. Always use `$conf['savedir']` for the data directory path (no trailing slash — append `/` explicitly).
- **`$ID` is not set from POST in AJAX context.** DokuWiki's init only populates `$ID` from GET parameters. Any AJAX handler that needs the current page ID must read it via `cleanID($INPUT->str('id'))`.
- **`DokuWiki_CLI_Plugin`** may not be defined in all DokuWiki versions. `cli.php` provides a shim that defines the class (extending `\splitbrain\phpcli\CLI` with a `getConf()` helper) if it is missing.
- **Do not `require_once` DokuWiki's init from `cli.php`.** `bin/plugin.php` already bootstraps DokuWiki before loading the plugin's CLI class; double-bootstrapping produces HTML error output.

### ChromaDB v2 API Notes

- All endpoints are under `/api/v2` (prepended by `makeRequest()`).
- **Single-condition where**: `{"field": {"$eq": "value"}}`
- **Multi-condition where**: `{"$and": [{"field1": {"$eq": "v1"}}, {"field2": {"$eq": "v2"}}]}` — a flat object with multiple keys is rejected with HTTP 400.
- There is no `/identity` endpoint in ChromaDB v2.

### Disabled Code

- `handleToolbar()` in `action.php` is implemented but its `register_hook` call is commented out. The plugin uses its own custom toolbar div via `script.js` instead.

### Minor Issues

- `ChromaDBClient::processSingleFile()` — `total_chunks` metadata stores paragraph count *before* empty filtering, not the actual number of chunks stored.
- The `style.css` / `images/` directory is minimal; icons rely on emoji or DokuWiki's own image library.

---

## Event Hooks Registered

| Hook | When | Handler |
|---|---|---|
| `DOKUWIKI_STARTED` | AFTER | Inject config into `JSINFO` |
| `TPL_METAHEADER_OUTPUT` | BEFORE | Inject `script.js` on edit/preview pages |
| `AJAX_CALL_UNKNOWN` | BEFORE | Handle `plugin_dokullm` AJAX calls |
| `COMMON_PAGETPL_LOAD` | BEFORE | Handle `copyfrom` template loading |
| `MENU_ITEMS_ASSEMBLY` | AFTER | Add "Copy page" menu item |
| `INDEXER_TASKS_RUN` | AFTER | Index saved pages into ChromaDB |

---

## External Dependencies (Runtime)

- **ChromaDB** v2 API — vector database
- **Ollama** — local embedding generation (`/api/embeddings`)
- **Any OpenAI-compatible LLM API** — text generation

All communication uses `cURL` directly (no Composer packages).
