# Smart AI Assistant — Platform Architecture Plan (Phase: Foundation)

> Status: **proposal, not implemented**. No production code has been changed.
> Based on inspection of this package (`main` @ 55c6be1) and its integration in the `mdxplaygrnd` host app
> (`QueryController::raiseTicket`, `routes/web_maddox.php`, published views/config).

---

## 1. Current architecture assessment

### 1.1 What actually happens at runtime

There are **two disconnected paths**, and only one of them touches the resolution pipeline:

| Path | Trigger | Goes to | Pipeline? |
|---|---|---|---|
| **A. Error tag** | User clicks an error chip scraped from the page DOM | `POST /smart-assistant/help` (package) | Yes: `InputClassifier` → `ErrorMatcher` → canned answer |
| **B. Typed chat** | User types into the chat box and presses send | `POST /customer-support/raise/ticket` (**host**) | **No.** Every typed message that survives the JS pre-filter becomes a support ticket |

Consequences:
- The backend classifier (greeting / vague / escalation / abuse / category) effectively only ever sees **page-scraped error text**, never what the user types.
- The "assistant" for typed input is a JS regex pre-filter (`assistant.js` `isColdQuery`, greeting, vague) that **duplicates and diverges from** `InputClassifier` (different rules: JS has a 50-char cutoff and a consonant-run gibberish rule; PHP has a 1–2 char rule and abuse/escalation handling).
- The host ticket endpoint enforces constraints the widget does not know about:
  - one ticket per user per **10 minutes**, so a second typed message within 10 min fails with a 422
  - `description` max **500** chars, and the widget prepends `Error Context: …`, which pushes it closer to the limit
  - attachments limited to `jpg,jpeg,png,pdf`, max **2 MB**, while the widget's file input accepts `.doc/.docx`

### 1.2 Backend (`/help`)

- `ErrorHelpController::store` is a linear 6-step script: classify → canned reply → escalation → KB match → loop guard → persist. It works, but every new capability would become another step in this method. This is the "giant if/else" trajectory.
- `InputClassifier` mixes a **generic mechanism** (ordered pattern rules → type) with **MaddoxPay domain data**: categories PAN/AEPS/IRCTC/…, Hinglish phrases, and response wording.
- `ErrorMatcher` mixes **generic retrieval** (substring match) with **MaddoxPay scoping**: it filters on a single global `default_service` (`AEPS`). Because the detected category is never passed to the matcher, KB rows for any other service are unreachable.
- Knowledge answers are stored as fixed `answer_en` / `answer_hi` columns. Language is baked into the schema.
- Conversation handling:
  - a new `Conversation` row is created for **every** message, always with `status = resolved`, even when the answer is `unknown`
  - `conversation_id` is returned to the client but never sent back, so there is no multi-turn context
  - the loop guard is stored in the **session, globally** (per user session, not per conversation)
- Correctness defects found:
  - `noise` rule `/^[\W\d\s]+$/i` has no `u` flag. PCRE then treats UTF-8 Devanagari bytes as non-word characters, so **Hindi-only input is very likely classified as noise and ignored**. This needs a test to confirm, and it matters for this user base.
  - Category keywords use raw substring matching: `vi` matches "ser**vi**ce"/"de**vi**ce"; `pan` matches "com**pan**y"; `ticket` → IRCTC (for example "raise ticket"); `bank`/`account` → PAYOUT. Categories are checked in order, so the first false hit wins.
  - The KB answer prefix uses `**bold**` markdown, but the client renders it with `innerHTML`, so users see literal asterisks.
  - `SeedKbFromCsv` writes `match_type`, but that column is not in the migration or `$fillable`, so it is silently dropped. The seeder also always writes `default_service`.
  - `whereRaw('LOWER(?) LIKE CONCAT("%", LOWER(key_text), "%")')`:
    - uses double-quoted string literals, which is MySQL-only and breaks under `ANSI_QUOTES`
    - treats `%` and `_` inside `key_text` as wildcards
    - loads the whole KB table in PHP on every miss (the fallback path)

### 1.3 Frontend

- Four globals (`UIManager`, `APIManager`, `FilePreviewManager`, `SmartAssistant`) loaded in a fixed order. The structure is reasonable, with clean responsibility splits.
- `addChatMessage()` writes **every** message with `innerHTML`: user text, page-scraped text, KB answers, and server error messages. See §7 (security).
- Page-error scanning hard-codes host-specific knowledge:
  - the element IDs `modal_error`, `modal_status_message`, `error-display`
  - Bootstrap classes
  - AEPS placeholder words (`fingerprint`, `withdrawal`, …)
  - The `error_selectors` config key is **unused**.
- It works around host behaviour aggressively:
  - a 1-second interval that re-enables the input
  - `stopImmediatePropagation` on focus events to defeat Bootstrap modal focus traps
  - These are host-coupling symptoms, not assistant features.
- Branding is changed by **forking the published view**. `mdxplaygrnd`'s published `widget.blade.php` already differs from the package ("Smart Assistant Soniya", "Your MaddoxPay AI assistant?", "Powered by - MaddoxPay AI"). Future package view changes will be silently masked in that host.

### 1.4 Config

- `middleware` (`['web','auth']`) is **not applied**. The route hard-codes `['web']`.
- `error_selectors` is unused.
- `ai.*` is a stub. That is fine, but it pre-commits to a "driver" shape; see "Do Not Decide Yet".

### 1.5 Verdict

The package is small, working, and deterministic, which is a good base. Its main structural problems are:
1. Resolution is only wired to one of the two input paths.
2. MaddoxPay identity, ticketing, domain vocabulary, and DOM knowledge are hard-coded inside package files.
3. Responses are HTML strings, with no structured response model.

All three can be fixed incrementally without a rewrite.

---

## 2. Current package vs MaddoxPay boundary map

Legend: **Core** = stays in the package core · **Contract** = the package defines an interface or extension point, with a default implementation · **Host** = moves to the MaddoxPay integration layer · **Config** = the package mechanism stays, the values become host config

| Component | Today | Category (A–G) | Target |
|---|---|---|---|
| `ErrorHelpController` pipeline shape | Linear script | A | **Core**: thin HTTP controller → `ResolverPipeline` |
| Loop prevention / "prompt once" / exit | Session-global, inside controller | A | **Core** guard (per conversation) |
| `InputClassifier` rule engine (ordered patterns → type) | Class with hard-coded arrays | A | **Core**: `RuleBasedInterpreter` |
| Greeting / noise / vague / abuse / escalation patterns | Hard-coded (EN + Hinglish) | A/B | **Config**: package ships generic defaults; host extends |
| Category map (PAN, AEPS, IRCTC, …) | Hard-coded | B | **Host config** (domain vocabulary) |
| Canned response wording (incl. Hindi strings in controller) | Hard-coded | A/B | **Config/lang files** (package defaults, host overrides) |
| `ErrorMatcher` substring retrieval | Class | E | **Contract** `KnowledgeSource`, default `DatabaseKnowledgeSource` |
| `smart_ai_error_definitions` table + model | Package migration | E | **Core** (default knowledge store), keep table |
| `service` scoping (`default_service = AEPS`) | Global config | B/E | Generic `domain` tag on knowledge entries; values are **host data** |
| `smart-ai:seed-kb` importer | Package command | E | **Core** tool (fix column mapping) |
| `Conversation` / `Message` models + tables | Package | A | **Core** behind `ConversationStore` |
| `Sentinel::check()` / `getUser()` in controller | Package | F/B | **Host** via `UserContextResolver` contract |
| Sentinel + `maddox_id`/`full_name`/`phone_no` in Blade (hidden inputs) | Package view | F/B | **Remove.** Identity is resolved server-side |
| Ticket payload (`type=self`, `service=99`, `category='Smart Assistant'`, `maddox_id`, phone) | `api-manager.js` | G/B | **Host** `EscalationChannel` implementation |
| `/customer-support/raise/ticket` URL | `api-manager.js` | G/B | Replaced by package `/smart-assistant/escalate` → host channel |
| Ticket truth, 10-min throttle, hierarchy check (`isDescendantOfLoginUser`) | Host `QueryController` | G/F | **Host** (already there; keep it) |
| Widget chrome, chat bubbles, typing indicator, badge | `ui-manager.js` | C | **Core UI** |
| Page-error scanning mechanism (MutationObserver, dedupe, badge) | `ui-manager.js` | C | **Core UI** (feature-flagged) |
| Scan selectors, IDs, placeholder-word list | Hard-coded | C/B | **Host config** (`page_scan.selectors`, `ignore_patterns`) |
| Bootstrap focus-trap bypass, input re-enable interval | `ui-manager.js` | C/B | **Isolate** into an optional "host compat" shim; long term, fix in the host |
| Attachments + screenshot (html2canvas) | `file-preview.js` | C | **Core UI** (feature-flagged); limits come from config and mirror the escalation channel |
| Branding ("Soniya", "Maddox AI", welcome text) | Blade (forked in host) | C/B | **Config** |
| Legacy `public/assistant.js` (v1) | Not loaded | – | Delete when convenient |

Validation against the proposed ownership split (§8): today the **host already owns** ticket truth, hierarchy authorization, the auth middleware on its own routes, and the DB schema for tickets. The **package wrongly owns**:
- Sentinel coupling
- MaddoxPay user-field names
- the ticket payload shape
- the ticket URL
- the domain category list
- AEPS as the default service
- DOM IDs of MaddoxPay pages
- branding

---

## 3. Proposed package architecture

### 3.1 Principles

1. **The host is the authority** for identity, authorization, business data, and write actions. The package orchestrates.
2. **Lightest reliable mechanism first.** Strategies are ordered from cheapest and most deterministic to most expensive. AI is one optional strategy/interpreter among several.
3. **Everything returns data, not HTML.** Backend → structured `AssistantResponse` → browser renders from a fixed vocabulary.
4. **Behaviour policies are separate from resolution.** "Never loop", "prompt once" and "exit cleanly" are guards that run around the resolvers, not logic inside them.
5. **No new abstraction without a current consumer.** Every contract below maps to existing code, or to the one next capability that is planned.

### 3.2 Proposed layout (target, reached incrementally)

```
src/
  Core/
    Data/          UserContext, IncomingMessage, ConversationContext, StructuredProblem,
                   Resolution, AssistantResponse, Block, KnowledgeEntry,
                   EscalationRequest, EscalationResult, ToolResult
    Contracts/     UserContextResolver, Interpreter, ResolutionStrategy, KnowledgeSource,
                   EscalationChannel, ConversationStore, Redactor, (later) DataTool
    Resolution/    ResolverPipeline, StrategyRegistry, Guards/ (LoopGuard, ClarifyOnceGuard)
  Understanding/   RuleBasedInterpreter            (evolved InputClassifier)
  Knowledge/       DatabaseKnowledgeSource         (evolved ErrorMatcher) + ErrorDefinition model
  Strategies/      InputGuardStrategy, ExplicitEscalationStrategy,
                   KnowledgeLookupStrategy, FallbackStrategy
  Persistence/     EloquentConversationStore
  Http/            MessageController (thin), EscalationController, FormRequests, ResponseSerializer
  Support/         DefaultRedactor, LaravelAuthUserContextResolver (default)
```

`Core/` depends on nothing Laravel-specific except, at most, `Illuminate\Support` collections. It is plain PHP data objects plus interfaces, so it is testable without a database.

### 3.3 Core concepts (minimal shapes, deliberately small)

| Concept | Minimal fields | Notes |
|---|---|---|
| **UserContext** | `id` (opaque string), `isAuthenticated`, `displayName?`, `locale`, `attributes` (host-defined, e.g. `role`), `tenantId?` | Built **server-side** by the host resolver. Never built from request fields. It contains no phone or PAN. |
| **IncomingMessage** | `text`, `source` (`typed` \| `page_error` \| `suggestion`), `attachments` (refs), `pageUrl?` (path only, query stripped), `clientConversationId?` | The raw request becomes this after validation and length limits. |
| **ConversationContext** | `conversationId`, `user` (UserContext), `history` (last N turns, summarized), `state` (e.g. `clarificationsAsked`, `lastResponseHash`, `escalationOffered`) | Replaces the session-global loop state. |
| **StructuredProblem** | `intent` (`report_error`, `ask_status`, `how_to`, `request_human`, `greeting`, `vague`, `noise`, `abuse`, `unknown`), `domains` (host tags such as `AEPS`), `entities` (e.g. `reference_id`, `amount`, `date`), `signals` (`abuse_level`, `language`), `confidence`, `interpretedBy` | `InputClassifier`'s `type` + `category` map onto `intent` + `domains` with no behaviour change. |
| **Resolution** | `outcome` (`answered`, `clarify`, `escalate`, `refuse`, `exit`), `blocks`, `provenance` (strategy name, knowledge ids, tool calls), `persist` (bool), `conversationStatus` | This is what a strategy returns. |
| **KnowledgeEntry** | `id`, `sourceId`, `title/key`, `content` (per-locale map), `domains`, `score`, `matchType` | Retrieval output. It says nothing about how the entry was found. |
| **AssistantResponse** | `conversationId`, `blocks[]`, `actions[]`, `meta` (`source`, `input_type`, `category`) + **legacy** `answer_en`/`answer_hi` during migration | The wire format (§6). |
| **EscalationRequest / Result** | Request: `user`, `conversationId`, `summary`, `transcriptExcerpt`, `attachments`, `domains`. Result: `status` (`created`, `rejected`, `throttled`), `reference`, `message`, `viewUrl?` | Generic. The host maps it to its ticket system. |

**Capability** is a *configuration* concept, not a class: a named feature the host enables. Examples: `knowledge`, `escalation`, `attachments`, `screenshot`, `page_scan`, and later `data_tools` and `ai_understanding`.

- Strategies declare the capabilities they require.
- The registry skips strategies whose capabilities are disabled.
- The same flags are sent to the widget so it hides disabled UI.

---

## 4. Proposed contracts / interfaces

### 4.1 Justified now

The existing code has a concrete implementation or consumer for each of these.

| Contract | Responsibility | Input → Output | Security boundary | Lives in | Why now |
|---|---|---|---|---|---|
| **`UserContextResolver`** | Turn the authenticated request into a `UserContext` | `Request` → `UserContext` | **The** identity boundary. The host decides who the user is; the package never reads identity from payloads. | Interface: core. Default `LaravelAuthUserContextResolver` (`auth()->user()`): core. `SentinelUserContextResolver`: **host** | Removes Sentinel from the controller and view. Fixes browser-supplied identity. |
| **`Interpreter`** | Turn a message into a `StructuredProblem` | `IncomingMessage`, `ConversationContext` → `StructuredProblem` | Pure function over text; no data access | Interface + `RuleBasedInterpreter`: core. Patterns/categories: config | Wraps `InputClassifier` so an AI or hybrid interpreter can be added later without touching resolvers |
| **`ResolutionStrategy`** | Try to resolve a problem with one mechanism | `StructuredProblem`, `ConversationContext` → `?Resolution` (null = "not mine") | Receives only `UserContext`; any data access goes through other contracts | Interface + 4 initial strategies: core. Host may register more | Replaces the linear controller; this is the anti-if/else seam |
| **`KnowledgeSource`** | Retrieve knowledge entries relevant to a problem | `StructuredProblem` (+ `limit`) → `KnowledgeEntry[]` | Read-only. Must not return entries the user may not see (entries can carry an audience tag later) | Interface + `DatabaseKnowledgeSource`: core. Extra sources: host or core | Wraps `ErrorMatcher`; decouples "knowledge" from "how it's matched" |
| **`EscalationChannel`** | Hand the conversation to humans | `EscalationRequest` → `EscalationResult` | Host re-authorizes and enforces its throttle and limits. Package sends only server-derived identity | Interface: core. `MaddoxPayTicketChannel`: **host**. Core ships `NullEscalationChannel` / `LogEscalationChannel` | Removes the ticket URL, payload shape, and PII from the browser. Makes escalation a resolver outcome |
| **`ConversationStore`** | Load/save conversation, turns, and guard state | ids / `ConversationContext` ↔ storage | Must scope every lookup to `UserContext::id` (no IDOR on `conversation_id`) | Interface + `EloquentConversationStore`: core | Needed for multi-turn state and to move loop state out of the session |
| **`Redactor`** | Mask PII before persisting or logging (and, later, before any LLM call) | string/array → string/array | The single choke point for outbound data | Interface + `DefaultRedactor` (Aadhaar-like 12-digit numbers, account-like digit runs, phone, email, PAN pattern): core. Host may extend | The package **already** stores raw user/page text unredacted |

### 4.2 Evaluated and deferred or rejected

| Candidate | Decision | Reason |
|---|---|---|
| `TransactionProvider`, `PayoutProvider`, `KYCProvider`, `AccountProvider`, "ServiceProvider" | **Rejected as package contracts** | Each one would make the core know MaddoxPay domain concepts, which violates the brief. No current code consumes them. (Also, the name "ServiceProvider" collides with Laravel's.) |
| → replacement: **`DataTool`** (one generic contract, introduced with the first real data capability) | **Deferred to the first data capability** | Shape: `name()`, `description()`, `argumentSchema()`, `authorize(UserContext, args): bool`, `execute(UserContext, args): ToolResult`. `ToolResult` = display-safe, host-redacted structured fields + optional block hint. **Transaction status, payout status, PAN status and KYC state become host-implemented `DataTool`s.** The same contract later serves "LLM + tools". |
| `SearchProvider` | **Merged into `KnowledgeSource`** | Retrieval technique (exact, keyword, semantic, hybrid) is an internal detail of a source. Split out a shared `Retriever` only when two sources need the same retrieval engine. |
| `KnowledgeProvider` | **Same as `KnowledgeSource`** | One name only. Multiple sources are aggregated by a `CompositeKnowledgeSource` (core). |
| `TicketProvider` | **Renamed `EscalationChannel`** | Escalation can be a ticket, a callback request, or a handoff to live chat. The ticket is one host implementation. |
| `AIProvider` | **Deferred; do not define the interface yet** | No consumer exists. When the first AI feature arrives it will plug in as an `Interpreter` or a `ResolutionStrategy` (or a response composer). The core pipeline does not change. Define the LLM client interface then, shaped by the real use. |
| `ToolProvider` | **Becomes a registry of `DataTool`s** (config/tagged services), not a separate contract | — |

---

## 5. Proposed resolution pipeline

```
HTTP  (auth middleware from config, throttle, FormRequest: length/type limits)
  │
  ▼
UserContextResolver ──► UserContext            (host authority)
  │
  ▼
ConversationStore.load(clientConversationId, user)  ──► ConversationContext
  │                                                     (ownership-checked; else new)
  ▼
Interpreter chain  ──► StructuredProblem
  │   1. RuleBasedInterpreter (always; cheap)
  │   2. [optional, later] AI interpreter only if confidence < threshold
  │      and capability `ai_understanding` is enabled
  ▼
Pre-guards        (e.g. severe-abuse boundary, clarify-once, repeated-noise → exit)
  │
  ▼
ResolverPipeline: ordered strategies from config, first non-null Resolution wins
  │   input_guard        → greeting / noise / vague / empty      (today's STEP 2)
  │   explicit_escalation→ user asked for human                  (today's STEP 3)
  │   [later] data_tool  → e.g. transaction status via host DataTool
  │   knowledge_lookup   → KnowledgeSource                       (today's STEP 4, match)
  │   [later] clarify    → ask ONE targeted question if a required entity is missing
  │   [later] ai_answer  → RAG / LLM over retrieved knowledge
  │   fallback           → "not documented" + offer escalation   (today's STEP 4, miss)
  ▼
Post-guards       (LoopGuard: same answer twice → exit;  today's STEP 5)
  │
  ▼
Redactor → ConversationStore.save (only if Resolution.persist)      (today's STEP 6)
  │
  ▼
ResponseSerializer → AssistantResponse JSON (blocks + legacy fields)
```

Why this does not become a giant if/else:
- Each strategy is a small class with one question: "can I resolve this, cheaply and safely?"
- **Order and enablement are config** (`resolution.strategies => [...]`), so a host that only wants KB + escalation lists two strategies.
- Conversation policies (ASSISTANT_BEHAVIOR.md rules) live in guards, so every strategy obeys them without repeating them.
- Escalation is an **outcome** (`Resolution::escalate`) with an explicit user-confirmation action, not a side-effect. Tickets are created only when the user confirms, through `EscalationChannel`.
- Start with **ordered first-match**. Scoring or arbitration between competing strategies is a later decision (see §11).

Supported configurations (examples, none requiring code changes in core):

| Mode | Interpreter | Strategies |
|---|---|---|
| Deterministic only (today) | rules | input_guard, explicit_escalation, knowledge_lookup, fallback |
| + App data | rules | … + data_tool (host tools) before knowledge_lookup |
| Knowledge retrieval without generation | rules | knowledge_lookup with a keyword/semantic `KnowledgeSource` |
| LLM understanding only | rules → AI | unchanged strategies (the AI only fills `StructuredProblem`) |
| RAG | rules (→ AI) | … + ai_answer (consumes `KnowledgeSource` results + LLM) |
| LLM + tools | rules → AI | ai_answer is allowed to call registered `DataTool`s via the same authorize path |
| Escalation-only | rules | input_guard, explicit_escalation, fallback |

---

## 6. Proposed UI boundary

### 6.1 Wire protocol (versioned)

```json
{
  "protocol": 1,
  "conversation_id": "…",
  "blocks": [
    { "type": "text", "text": "…", "format": "plain|basic" },
    { "type": "notice", "level": "info|warning|error", "text": "…" },
    { "type": "key_value", "title": "Transaction", "items": [{"label":"Status","value":"Pending"}] },
    { "type": "reference", "label": "Ticket", "value": "CMP123", "status": "open" }
  ],
  "actions": [
    { "type": "suggestion", "label": "Money deducted", "send": "Money deducted but failed" },
    { "type": "action", "id": "escalate", "label": "Raise ticket", "confirm": true },
    { "type": "action", "id": "attach", "label": "Attach screenshot" }
  ],
  "meta": { "source": "kb", "input_type": "valid", "category": "AEPS" },
  "answer_en": "…", "answer_hi": "…"
}
```

Rules:
- The browser renders **only** known `type`s through a renderer registry (`BlockRenderers[type]`). An unknown type falls back to its `text` field, or is dropped.
- **All text goes in through `textContent`.** `format: "basic"` is parsed by a tiny client formatter: bold, line breaks, bullet lists, and links restricted to `https:` plus host-allowlisted paths. No server HTML, ever.
- `action.id`s map to **client-side handlers that already exist** (escalate, attach, screenshot, and `navigate` to a host-allowlisted route key). The server can never send a URL or script to execute.
- "Transaction card" and "ticket status" are **not** core components. They are `key_value` / `reference` blocks. The core vocabulary stays generic; a host can register an extra renderer only through a documented JS hook.
- `form` and `confirmation` are reserved names, specified when the first use case needs them (for example, "which transaction?" with a reference-ID field).

### 6.2 Host configuration → widget

The Blade component emits one `<script type="application/json" id="sa-config">` built from config. The package JS reads it:
- **branding**: assistant name, title, subtitle, footer, avatar, CSS variables. This ends view forking.
- **features / capabilities**: page_scan, attachments, screenshot, escalation
- **endpoints**: from `route()` names; no hard-coded URLs
- **page_scan**: selectors, id list, ignore patterns (replaces hard-coded AEPS words)
- **attachments**: accepted MIME types and max size, taken from the `EscalationChannel` limits (for MaddoxPay: jpg/png/pdf, 2 MB)
- **welcome**: welcome blocks, starter suggestions, entry points (for example, a host page can call `smartAssistant.open({ topic: 'AEPS' })`)
- **No PII.** The hidden `maddox_id` / name / phone inputs are removed.

### 6.3 Migration path for the UI

1. Make `addChatMessage` text-safe by default (`addChatMessage(text)` → `textContent`, plus `addFormattedMessage(basic)`).
2. Add a `renderResponse(json)` that prefers `blocks` and falls back to `answer_en`/`answer_hi`.
3. Move branding and scan selectors to the JSON config.
4. Route typed messages to the backend (flagged).
5. Delete the JS classifier duplicate.
6. Isolate the Bootstrap focus/re-enable hacks into `host-compat.js`, loaded only when `features.bootstrap_modal_compat` is on.

---

## 7. Security boundary

### 7.1 Findings in the current implementation (ordered by severity)

| # | Issue | Where | Severity | Fix phase |
|---|---|---|---|---|
| S1 | **DOM XSS.** Page error text is read with `innerText` (unescaped), then written with `innerHTML` in `Help me with: "${errorText}"`. Any page that shows an error echoing user-controlled data (a beneficiary name, a remark) becomes script execution in the app origin, which holds the CSRF token and an authenticated session. User-typed text, KB answers, and server `message` strings are also written as HTML. File names go into `innerHTML` in `file-preview.js`. | `ui-manager.js addChatMessage`, `assistant.js handleErrorQuery`, `file-preview.js` | **High** | 0 |
| S2 | **Third-party script without integrity.** `html2canvas` is loaded from `unpkg.com` without SRI, into a page with payment functions. | `widget.blade.php` | **High** | 0 |
| S3 | **`/smart-assistant/help` has no auth and no throttle.** It uses `web` only, and the config `middleware` key is ignored. There is no max length on `error_text`. Valid-looking input creates 1 conversation + 2 message rows per request, and misses trigger a full-table PHP scan. This allows anonymous DB growth and cheap load amplification. | `routes/web.php`, controller | **Medium** | 0 |
| S4 | **Identity supplied by the browser.** `maddox_id`, name, and phone are read from hidden DOM inputs and posted to the ticket endpoint. The **host mitigates this**: `validateCredibiltiyToRaiseQuery` checks the hierarchy and `sentinel.auth` guards the route. However, `name` and `alternate_phone_no` are still taken from the browser, and the package design assumes client identity. | `widget.blade.php`, `api-manager.js` | Medium | 4 |
| S5 | **PII in the DOM.** Name and phone are exposed to every script on the page (see S2). | `widget.blade.php` | Medium | 4 |
| S6 | **Screenshots capture `document.body`** without redaction. Pages can show Aadhaar, account, and mobile numbers of *customers* (not the retailer), and these are uploaded to tickets. | `file-preview.js` | Medium (privacy) | later; needs a product decision (mask selectors `[data-sa-redact]`) |
| S7 | **Unredacted persistence and logging.** Raw user and page text is stored in `smart_ai_messages`/`meta.raw_error_text`. The full `page_url` (including the query string) is stored. There is no retention policy. The host logs `request->all()` on ticket failure. | controller, host `QueryController` | Medium | 1–3 (package), host note |
| S8 | KB `LIKE` treats `%`/`_` in `key_text` as wildcards: an authoring error can make one entry match everything. It is portable only to MySQL. | `ErrorMatcher` | Low | 1 |
| S9 | Server error `debug` messages are returned when `app.debug` is on (host), and the widget displays the `message` as HTML. | host + S1 | Low (after S1) | 0 |

CSRF is handled correctly: the `web` middleware plus the `X-CSRF-TOKEN` header.

### 7.2 Target boundary rules

1. **Identity:** the package only ever uses the `UserContext` from `UserContextResolver`. Request payloads never contain identity. `conversation_id` from the client is a *hint* and is loaded only if it belongs to `UserContext::id`.
2. **Authorization of data:** the package has **no direct access to host tables**. Application data is reachable only through host `DataTool`s. Each tool's `authorize(UserContext, args)` enforces ownership or hierarchy (for example, `isDescendantOfLoginUser`). The tool returns **display-safe, pre-masked** fields.
3. **Tenant / user isolation:** every `ConversationStore` query is scoped by user (and by `tenantId` if the host sets one). Knowledge entries are global by default; an optional audience tag can be added later.
4. **Write actions** (ticket creation, and later anything that affects money or state) require a **user-confirmed action**. They are never executed as a side-effect of interpretation, and never on an LLM's initiative.
5. **Uploads:** the package validates type and size before forwarding, and the host re-validates. The package does not store files itself; they pass through to the `EscalationChannel`.
6. **Future LLM:**
   - It gets **no DB, filesystem, or HTTP access**. It may only select from registered `DataTool`s, with schema-validated arguments, through the same `authorize` path.
   - All inputs pass through the `Redactor`.
   - Page-scraped text, KB content, ticket history, and tool outputs are **untrusted data** (prompt-injection surface). They are delimited and never granted instruction authority.
   - Model output is rendered only through the block protocol (text blocks). That protocol is the XSS backstop.
   - All tool calls are audit-logged with the user, arguments (redacted), and result status.
7. **Logging:** the package logs ids, outcome, strategy, and timings. Content is logged only after redaction. A configurable retention period prunes conversations (`smart-ai:prune`).

---

## 8. MaddoxPay adapter boundary

**Proposed host layout** (`mdxplaygrnd/app/SmartAssistant/`, bound in a host service provider):

| Host piece | Implements | Built from existing host code |
|---|---|---|
| `SentinelUserContextResolver` | `UserContextResolver` | `Sentinel::getUser()` → id, `full_name`, role attributes |
| `MaddoxPayTicketChannel` | `EscalationChannel` | Extract a `TicketService::raise()` from `QueryController::raiseTicket`, so the controller and the channel share the throttle, validation, and hierarchy logic. The channel supplies `service=99`, `category`, and `type=self` server-side. |
| `config/smart-ai-assistant.php` (published) | Config | Categories (PAN/AEPS/IRCTC/…), Hinglish patterns, response wording, scan selectors/IDs, branding, attachment limits, strategy order, middleware `['web','sentinel.auth']` |
| KB spreadsheets per domain | Data | Seeded via `smart-ai:seed-kb --domain=PAN file.xlsx` |
| Later: `TransactionStatusTool`, `PayoutStatusTool`, `PanStatusTool`, `OnboardingStateTool` | `DataTool` | The host's own repositories and authorization. They return masked fields. |
| Host-side fixes (not package) | – | Log redaction in `raiseTicket`; remove `$request->id` typo in the error message; long term, remove the need for the modal focus hacks |

**Package owns:**
- conversation and resolution machinery
- the strategy registry and guards
- `RuleBasedInterpreter`
- knowledge abstractions and the default DB knowledge store
- the escalation contract and endpoint
- the redaction hook
- the response protocol and widget
- the default `LaravelAuthUserContextResolver` and `LogEscalationChannel`, so the package works in a non-MaddoxPay app

**MaddoxPay owns:**
- auth and hierarchy
- transaction, payout, PAN, and KYC truth
- the ticket system and its throttle
- business rules
- domain vocabulary and KB content
- branding

The table in §2 shows what is out of place today.

---

## 9. Incremental migration plan

Every step ships independently and leaves the widget working. Steps 0–3 produce **no user-visible behaviour change**, other than the security fixes and the listed bug fixes.

| Step | What | Behaviour change | Safety net |
|---|---|---|---|
| **0. Safety + test harness** | (a) Add `orchestra/testbench` + PHPUnit/Pest dev deps. (b) **Characterization tests** that lock current `/help` JSON for about 40 representative inputs (greeting, noise, vague, abuse, escalation, KB hit, KB miss, loop exit), plus a sample drawn from the ~2,000 real queries. (c) Fix S1: text-safe rendering and a basic formatter, which also fixes the literal `**`. (d) Fix S2: self-host html2canvas in `public/vendor` or add SRI. (e) Fix S3: apply `config('middleware')`, add `throttle`, `max:1000` on text. (f) Align attachment accept/size with the host (jpg/png/pdf, 2 MB). | Security only | Tests (b) written **before** (c)–(f) |
| **1. Value objects + wrappers** | Add `Core/Data` + `Core/Contracts`. `RuleBasedInterpreter` wraps `InputClassifier` (same arrays). `DatabaseKnowledgeSource` wraps `ErrorMatcher`; escape `%`/`_`; drop the double-quote SQL. Add `Redactor` and apply it on persist. Fix the `/u` flag (Hindi) and word-boundary category matching (**intentional bug fixes**, with tests updated explicitly). | Bug fixes only | Characterization tests |
| **2. Identity boundary** | `UserContextResolver` with the default `LaravelAuthUserContextResolver`. Controller uses it instead of `Sentinel`. MaddoxPay binds `SentinelUserContextResolver`. Hidden inputs stay for now (still needed by the ticket call). | None | Tests with a fake resolver |
| **3. Resolver boundary** | `ResolverPipeline` + 4 strategies + `LoopGuard`, with the strategy order in config. The controller becomes about 20 lines. Move the MaddoxPay categories and Hinglish patterns into **published config**; package defaults become generic (no categories). The host config keeps today's values, so the host behaviour is identical. | None | Same tests must pass unchanged |
| **4. Escalation boundary** | `EscalationChannel` + `POST /smart-assistant/escalate` (multipart, validated). The host extracts `TicketService` and implements `MaddoxPayTicketChannel`. JS switches the ticket call to the package endpoint, behind `features.server_escalation` (default off, then on). Then remove the hidden PII inputs. | Same UX; host errors (throttle, limit) come back as structured notices | Flag; manual checklist; host feature test on `TicketService` |
| **5. Structured response** | `ResponseSerializer` emits `blocks`/`actions` **plus** the legacy `answer_en`/`answer_hi`. JS `renderResponse()` prefers blocks. `ConversationStore` + client-sent `conversation_id` (ownership-checked); loop state moves from the session to the conversation. Conversation `status` reflects the outcome (`resolved` / `unresolved` / `escalated`). | Visually the same | Legacy fields retained one release |
| **6. Unify typed chat with the resolver** | Behind `features.resolve_typed_messages`: typed text goes to `/smart-assistant/message` (the `/help` successor). The resolver answers, clarifies once, or returns an **"Raise ticket" action** that the user confirms, and that goes through step 4. Remove the JS classifier duplicate once the flag is default-on. | **Yes**: typed input gets an answer first, and tickets need confirmation | Flag, per-host rollout; measure ticket volume and resolution rate before and after |
| **7. Config-driven widget** | JSON config block; branding, welcome, suggestions, scan selectors, and features come from config. MaddoxPay deletes its forked published view. The Bootstrap hacks move to optional `host-compat.js`. | None (the host sets the same branding) | Visual check |
| **8. First data capability** | Introduce the `DataTool` contract + `data_tool` strategy + the `key_value` block. MaddoxPay implements **one** tool (transaction status by reference id, scoped to the user and descendants). An entity extractor for reference ids goes in host config. | New capability for one intent | Flag; authorization tests in the host |
| **9+. Evaluate, then extend** | Replay the ~2,000 labelled queries through the pipeline (`smart-ai:eval`) and measure coverage per resolution category. Add the cheapest mechanism that closes the biggest gap: more rules, more KB, another tool, keyword retrieval, and only then AI understanding or RAG. | Incremental | Eval harness gives a before/after number |

Step order rationale:
- 0 comes first because there are no tests and there is an XSS.
- 2 comes before 4 because escalation needs a server-side identity.
- 3 comes before 6 because typed messages should not enter a pipeline that is still a monolithic method.
- 8 comes before any AI work so that the tool/authorization boundary exists before a model can reach it.

---

## 10. Risks / trade-offs

- **Abstraction ahead of need.** Mitigation: only the seven contracts in §4.1, each wrapping existing code. `DataTool` waits for step 8, and the AI interfaces wait for a real AI feature.
- **Silent behaviour drift during refactor.** The package has zero tests today, and step 0 characterization tests are the whole safety net. Intentional changes (the Hindi `/u` fix, category word boundaries) must be separate commits that update the expectations.
- **Step 6 changes support economics.** Fewer tickets is the goal, but users who "just want a ticket" may feel blocked. Mitigation:
  - the escalation action is always one tap away
  - the flag allows a staged rollout
  - track ticket rate and repeat-contact rate
- **Cross-repo coordination.** Steps 2, 4, and 7 need MaddoxPay host changes (bindings, `TicketService` extraction, config). Every package step keeps a default that works without the host change.
- **Published-asset model.** The browser loads published copies, and the host has already forked the view. Until step 7, every UI change needs `vendor:publish --force` and a re-application of branding. Document this in each release note.
- **Rule-based ceiling.** Regex understanding of about 2,000 inconsistent, multilingual (EN/HI/Hinglish) queries will plateau. The eval harness (step 9) turns "do we need an LLM?" into a measured question instead of a default.
- **Ordered first-match** is simple but can mask a better later strategy. That is acceptable until evaluation shows real conflicts.
- **Framework support.** `composer.json` allows `illuminate/support ^10|^11` only. Confirm the host version before adding testbench or other dependencies.
- **Privacy of the evaluation corpus.** The ~2,000 real queries likely contain PII. Redact them before committing them as fixtures.

---

## 11. Do Not Decide Yet

Each of these stays open because nothing in the current code or the next phase depends on it.

| Open item | Why it can wait / what should trigger the decision |
|---|---|
| **LLM provider / model** | No AI consumer exists. Decide when step 9 eval shows a gap that rules, KB, and tools can't close. The `ai.driver` config stub should not be treated as a commitment. |
| **LLM client interface shape** | Define it together with its first consumer (an interpreter vs an answer generator need different shapes). |
| **Vector DB, embedding model, RAG framework** | Knowledge is a `KnowledgeSource` abstraction. Semantic retrieval is one possible implementation behind it, chosen after keyword retrieval is measured. |
| **Knowledge format beyond the current table** (documents, procedures, response-code tables, versioning, authoring workflow) | The current KB is about one sheet of key→answer rows. Decide when a second kind of source is actually being added. Only the `KnowledgeEntry` shape is fixed now. |
| **Language strategy beyond EN/HI** (per-locale map vs translation) | Keep the columns and map them to a locale dictionary in `KnowledgeEntry`. Decide the storage change when a third language or generated answers appear. |
| **Strategy arbitration** (scoring or multiple candidates vs first-match) | Wait until evaluation shows strategies competing. |
| **`form` / `confirmation` block specs, and the front-end tech** (vanilla vs web components vs a framework) | Specify them with the first flow that needs them (probably "which transaction?" in step 8). Vanilla JS is adequate until then. |
| **Redis / queues / async processing / streaming responses** | Every current operation is a sub-second DB lookup. Revisit only with LLM latency. |
| **Separate AI service, microservices, splitting into multiple composer packages** | One package with an internal `Core/` boundary gives the same separation at no operational cost. Revisit if a second host needs the core without the widget. |
| **Autonomous / multi-step agents** | This conflicts with the security rules until the tool, authorization, and confirmation boundary has been proven in production (step 8). |
| **Multi-tenancy model** | `UserContext::tenantId` is nullable and reserved. Decide when a second tenant or host shares one database. |
| **Retention period for conversations** | A **business/compliance** decision, not a technical one. The pruning mechanism is built in step 5; the value is set by MaddoxPay. |
| **Screenshot redaction approach** | Needs a product decision (block screenshots on certain pages vs mask `[data-sa-redact]` elements vs server-side review). |

---

## 12. Concrete implementation tasks for the next phase (steps 0–3)

**Step 0: safety and harness**
1. Add dev dependencies `orchestra/testbench` and `phpunit` (check the host Laravel version first). Add `phpunit.xml` and a `tests/` skeleton with a SQLite in-memory DB.
2. Write `InputClassifierTest`: a table-driven test covering every type, including Devanagari input (it documents the current noise misclassification).
3. Write `HelpEndpointTest` characterization cases: canned replies, escalation, KB hit/miss, loop exit on the second identical response, and no persistence for non-processable input.
4. Build a redacted sample fixture from the ~2,000 real queries (50–100 rows, labelled with resolution category) for later evaluation.
5. `ui-manager.js`: `addChatMessage` renders via `textContent`. Add `addFormattedMessage` using a minimal safe formatter (bold, line breaks, `https` links only). Update all callers in `assistant.js`.
6. `file-preview.js`: build filename and size nodes with `textContent`; never interpolate into `innerHTML`.
7. Self-host `html2canvas.min.js` under `public/vendor/`, or add an `integrity` + `crossorigin` attribute.
8. `routes/web.php`: use `config('smart-ai-assistant.middleware')` (default `['web']`) plus `throttle:30,1` (configurable). MaddoxPay config sets `['web','sentinel.auth']`.
9. Controller validation: `error_text` gets `max:1000`, and `page_url` is stored as its path only.
10. `widget.blade.php`: set `accept` to match the host (`image/jpeg,image/png,application/pdf`). Add a client-side 2 MB check with a clear message.

**Step 1: data objects, wrappers, bug fixes**

11. Create `Core/Data` classes: `UserContext`, `IncomingMessage`, `StructuredProblem`, `Resolution`, `KnowledgeEntry`, `AssistantResponse`. Use readonly classes with no behaviour.
12. Create `Core/Contracts`: `Interpreter`, `KnowledgeSource`, `Redactor` (plus `UserContextResolver`, `ResolutionStrategy`, `ConversationStore`, `EscalationChannel` as interfaces only).
13. `RuleBasedInterpreter` wraps `InputClassifier` and maps `type`/`category` → `StructuredProblem`.
14. `DatabaseKnowledgeSource` replaces the `ErrorMatcher` body: escape LIKE wildcards, use portable SQL, and cap the PHP fallback (or remove it once the SQL path is proven).
15. Fixes, each in a separate commit that updates test expectations:
    - the `/u` flag on all classifier regexes
    - word-boundary category matching
    - the `match_type` mismatch (either add the column or stop writing it)
    - a `--domain` option on `smart-ai:seed-kb`
16. `DefaultRedactor`; apply it to persisted `message` and `meta`.

**Step 2: identity boundary**

17. `LaravelAuthUserContextResolver` (default binding). Controller uses the `UserContextResolver`. Remove the `Sentinel` import from the package controller.
18. Host PR (MaddoxPay): `SentinelUserContextResolver` + binding in `AppServiceProvider`.

**Step 3: resolver boundary**

19. `ResolverPipeline` + `StrategyRegistry` (strategy classes listed in config, resolved from the container, filtered by enabled capabilities).
20. Add the strategies `InputGuardStrategy`, `ExplicitEscalationStrategy`, `KnowledgeLookupStrategy`, `FallbackStrategy`, and the post-guard `LoopGuard`. Each must reproduce today's JSON exactly.
21. Move the categories, Hinglish patterns, and response strings to config/lang. Package defaults become generic. Publish the MaddoxPay values into the host config.
22. Controller is reduced to validate → resolve context → pipeline → serialize. All step-0 characterization tests pass unchanged.
23. Update `ARCHITECTURE.md`, `CLAUDE.md`, and README: new structure, contracts, config keys, and the host binding checklist.

The exit criteria for this phase are:
- same user-visible behaviour, except the listed security and bug fixes
- the package controller no longer references Sentinel
- MaddoxPay vocabulary lives only in host config
- the characterization and unit test suites pass

---

## 13. Next phase: natural replies and document answers (steps 10–15)

Decided after step 9. The deterministic pipeline, fallback and escalation stay
as they are; this phase adds one reply language per message and, later, answers
grounded in documents the host provides. The package never holds product
knowledge itself.

| Step | What | Behaviour change | Depends on |
|---|---|---|---|
| **10. Conversational replies and reply language** *(done)* | `Support\Locales` + config `locales`: the reply language is the widget's menu choice, else the message's language (script, host word patterns, catch-all), else the conversation's, else the host's, else the default. Per-language maps in `Resolution`, `ResponseCatalog`, serializer and store; one text block per reply + `meta.locale`. Conversational texts, no category prefixes (`unknown_category` instead). Widget: no "Solution"/"हिंदी में" headings, language menu, "Raise ticket" inside the reply bubble. | **Yes** | — |
| **11. Baseline evaluation** *(rules-only part done)* | Run `smart-ai:eval` on the real queries; keep the JSON as the "before" number. Done on 3,029 labelled tickets with an empty KB: 2.6% of expected outcomes matched. MaddoxPay routing config then tuned (escalation for activation/cancel/install/account-change requests, vague for bare service names, 8 more categories, category kept on escalation and vague): 16.5% matched, "not documented" 97.2% → 81.8%. Still to do: the same run in the host against the real KB (needs a release), and the team's `answer_exists` column. | Host routing | Labelled sheet |
| **12. Document ingestion** | Tables `smart_ai_documents` (title, file, mime, checksum, scope, `audience` public/users, version, active/archived) and `smart_ai_document_chunks` (FULLTEXT). `TextExtractor` contract (PDF, DOCX, XLSX/CSV, TXT/MD/HTML); `smart-ai:ingest`. Re-upload by title = new version; same checksum = no-op. | None | — |
| **13. Document retrieval** | `DocumentKnowledgeSource` (FULLTEXT; LIKE on SQLite), filtered by audience and active version **in SQL**. Registered separately (passages are not answers), not in `knowledge.sources`. Eval reports retrieval hits. | None | 12 |
| **14. Grounded answers** | `AnswerGenerator` contract (one HTTP driver + null driver) and `DocumentAnswerStrategy` between `knowledge_lookup` and `fallback`, capability `document_answers` (off by default). No passage above `min_score` → no LLM call; `answerable: false`, error or timeout → `null` → unchanged fallback + "Raise ticket". Redacted question only; answers cite the source document and are stored with document ids and versions. | Only with the flag | 10, 13 |
| **15. Measure and roll out** | Eval against the step 11 baseline; host ingests its first documents and enables the flag. | Host | 11, 14 |

Out of scope for this phase: embeddings / vector DB, LLM tool calling or
agents, conversation memory, automatic knowledge generation, upload UI, OCR,
queues, streaming.

Effect on §11: the reply-language policy is decided (storage for a third KB
language is still deferred); the LLM client shape and a second knowledge kind
get decided in steps 12–14; vector storage stays deferred.

Open decisions before step 14: LLM provider/model; whether sending redacted
questions and document passages to it is allowed; document answers for
logged-in users only or guests too; whether to show the source document title;
LLM timeout and cost limit.
