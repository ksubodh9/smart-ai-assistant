# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Laravel package (`subodh/smart-ai-assistant`, namespace `Subodh\SmartAiAssistant\`, PSR-4 from `src/`) that ships a floating support-chat widget ("Soniya") for the Maddox Pay app. It is developed at `packages/smart-ai-assistant/` next to the host apps. However, the host (`mdxplaygrnd`) installs **tagged releases from GitHub** (`ksubodh9/smart-ai-assistant`) through Composer, not this folder. Package changes reach the host only after you push, tag a release, and run `composer update subodh/smart-ai-assistant` in the host. There is no build step or linter. Commands such as `php artisan` must run from the **host app root**; the tests run from this directory.

The auto-discovered `SmartAiAssistantServiceProvider` is the entry point. It loads routes and migrations, registers the `<x-smart-assistant-widget />` Blade component, and registers the `smart-ai:seed-kb` command.

## Commands (run from host app root)

```bash
# Full redeploy (clears caches, dump-autoload, force-republishes assets; publishes views/config only if missing)
powershell -File packages/smart-ai-assistant/deploy.ps1

# Minimum needed after a package update that changes JS/CSS
php artisan vendor:publish --tag=smart-ai-assistant-assets --force   # public/ -> public/vendor/smart-ai-assistant
php artisan view:clear

# Seed/update the knowledge base from a spreadsheet (xlsx/csv via PhpSpreadsheet)
# Columns: A = error key text, B = English answer, C = Hindi answer; row 1 is a header and is skipped
php artisan smart-ai:seed-kb path/to/file.xlsx [--domain=PAN]   # domain defaults to config default_service

# Delete conversations idle longer than conversations.retention_days (no-op when null); hosts schedule it daily
php artisan smart-ai:prune [--days=90]
```

**Important:** the browser loads the *published copies*, not the files in this package. JS and CSS edits do not show up until the assets are republished with `--force` and the browser is hard-refreshed. `publish --force` copies from the host's `vendor/` (the installed release), not from this folder. The host keeps a customised copy of the config (it holds the auth middleware and all MaddoxPay settings). **Never force-publish the config**: merge package changes into it by hand. Since step 7 the view is configured through `config('smart-ai-assistant.widget')`, so hosts should not publish the view; a leftover copy in `resources/views/vendor/smart-ai-assistant` hides every package view change (`deploy.ps1` warns about it).

### Tests (run from this package directory, not the host app)

```bash
composer install          # package-local vendor/ with orchestra/testbench + PHPUnit
vendor/bin/phpunit        # or: composer test
```

Feature tests use a dedicated MySQL database, `smart_ai_assistant_test` (settings in `phpunit.xml`). It is wiped on every run. MySQL is used because it matches production (`DatabaseKnowledgeSource` also supports SQLite). The tests are **characterization tests**: they pin current behaviour, and cases named `known_bug` / `known_gap` record wrong behaviour on purpose. When fixing one, update its expectation in the same commit. `tests/Stubs/Sentinel` is a test-only stand-in for the host's Sentinel facade; only the widget view tests still need it. Test requests that depend on the session must send a session cookie with `withCredentials()->withCookie(...)`, because JSON test requests carry no cookies by default. Multi-message tests must also send back the `conversation_id` of the previous response, as the widget does (guard state lives in the conversation).

The JavaScript has no automated tests. Frontend changes are still verified manually in the browser, using the checklist in README.md.

## Architecture

### Backend request flow: `POST /smart-assistant/message` (and `/help`)
Route names: `smart-assistant.message` (payload `text`, `source` = `typed` | `page_error` | `suggestion`, default `typed`) and the older `smart-assistant.help` (payload `error_text`, always `page_error`), kept for widgets released before `/message`. Both take `page_url` and `conversation_id` and return the same JSON. The middleware is `config('smart-ai-assistant.middleware')` (package default `['web']`; hosts add their auth middleware, e.g. MaddoxPay uses `['web', 'sentinel.auth']`), followed by `throttle:smart-assistant`. That rate limiter is defined in the service provider, with limits per session and per IP from `config('smart-ai-assistant.rate_limit')`. The text is limited to 1000 characters, and only the path of `page_url` is stored. Neither endpoint ever creates a ticket.

`MessageController` and `ErrorHelpController` only validate and hand over to `Http\MessageResponder`: resolve user → open conversation → interpret → `ResolverPipeline` → store → serialize. No LLM is called; `config('smart-ai-assistant.ai')` is a stub for later. Collaborators are method-injected, because the router reuses controller instances and they depend on per-request config. All bindings are in the service provider. See the pipeline section of [ARCHITECTURE.md](ARCHITECTURE.md).
**Conversation first:** **`Core\Contracts\ConversationStore`** (`Persistence\EloquentConversationStore`) runs before interpretation: `open()` continues the `conversation_id` the widget sent back only if it belongs to the caller (same user id; for guests, same session, stored as a sha256 `meta.guest_key`) and was active within `conversations.idle_minutes`; otherwise it silently creates a new conversation (status `open`). Every response carries a conversation id. The widget keeps it in `sessionStorage` per tab.

1. **`Core\Contracts\Interpreter`** (default `Understanding\RuleBasedInterpreter`) turns the text into a `Core\Data\StructuredProblem` with `intent`, `domains`, and `signals`; `signals['input_type']` keeps the legacy classifier type for the wire format. It wraps **`Support\InputClassifier`**, which runs Unicode-aware (`/u`) regex checks in a fixed priority order: empty → severe abuse → noise → greeting → vague → escalation request → mild abuse → valid. Invalid UTF-8 is noise. Patterns default to generic English (`InputClassifier::DEFAULT_PATTERNS`); `understanding.patterns` replaces them per type. Categories come only from `understanding.categories` (whole words, optional plural "s").
2. **`Core\Resolution\ResolverPipeline`** runs `resolution.strategies` in order (first non-null `Core\Data\Resolution` wins), skipping strategies whose `capabilities()` are disabled in `capabilities`. The defaults are `InputGuardStrategy` (canned reply, not stored), `ExplicitEscalationStrategy` ("Raise Ticket"), `DataToolStrategy`, `KnowledgeLookupStrategy`, and `FallbackStrategy` (must stay last). Reply texts come from `Support\ResponseCatalog` (generic defaults, `responses` overrides per key).
   - **Data tools** (`DataToolStrategy`, capability `data_tools`, off by default): host classes implementing **`Core\Contracts\DataTool`** (`name`, `description`, `argumentSchema`, `authorize`, `execute`) listed in `data_tools`. The interpreter fills `StructuredProblem::$entities` from `understanding.entities` (name => regex, first capture group). The first tool whose arguments were all found runs, only for logged-in users and only after `authorize()` returns true. "Not allowed" and "not found" give the same `tool_not_found` reply; exceptions give `tool_failed` (details go to `report()`). Each call logs one `Smart assistant data tool` line with tool, user id and outcome only. A found `Core\Data\ToolResult` becomes a `key_value` block (via `Resolution::$blocks`, which replaces the text blocks) plus a plain-text `answer_en`, is stored with `data.tool`, and marks the conversation resolved. Tools must return display-safe, masked values. MaddoxPay's tool is `App\SmartAssistant\TransactionStatusTool` (owner and uplines only).
3. **`Core\Contracts\KnowledgeSource`** (default `Knowledge\DatabaseKnowledgeSource`) returns `Core\Data\KnowledgeEntry` objects. It matches when an entry's `key_text` appears inside the user text (case-insensitive); `%`, `_` and `!` are escaped so they match literally. It runs a SQL `LIKE` (`CONCAT`, or `||` on SQLite), then a PHP fallback capped at 5000 rows, filtered by `default_service` (package default `general`).
4. **Guards** (`resolution.guards`) run on the winning resolution: `ClarifyOnceGuard` (same canned reply twice → exit) and `LoopGuard` (same answer hash twice → exit, then forget). Their state goes through `Core\Contracts\ConversationState`, stored in the conversation's `meta.state` (`Persistence\ConversationMetaState`).
5. Only resolutions with `persist` (KB answer or fallback) add messages to `smart_ai_messages`; canned replies, escalation pointers and exits store no text. Conversation status follows the latest stored outcome (`resolved` / `unresolved`; `escalated` once a ticket is created, and it stays). User text and page paths go through **`Core\Contracts\Redactor`** first (`config('smart-ai-assistant.redactor')`, default `Support\DefaultRedactor`: email, PAN, Aadhaar, phone, and 9–18 digit runs). Answers are stored unredacted. `smart-ai:prune` deletes conversations idle longer than `conversations.retention_days`.
6. `Http\ResponseSerializer` builds the JSON.

**Package/host vocabulary boundary:** MaddoxPay's categories, Hinglish patterns, Hindi replies and `AEPS` domain live in the host config. `tests/Fixtures/maddoxpay-config.php` is the reference copy, and feature tests run with it (`TestCase::hostConfig()`; `GenericDefaultsTest` runs without it). `PackageBoundaryTest` fails if that vocabulary appears in `src/` or `config/`.

The response JSON is protocol 1 (PLATFORM_PLAN.md §6.1): `{protocol, conversation_id, blocks[], actions[], meta{source, input_type, category}}`. Blocks are `text` blocks (`format: basic` = `**bold**` and line breaks only, one per answer `locale`). `actions` holds `{type: action, id: escalate, label, confirm: true}` after unresolved replies, requests for a human and exits (when the `escalation` capability is on). With `features.resolve_typed_messages` on, the widget shows it as a "Raise ticket" button; the button first shows what will be sent, and only "Send to support" posts to `/escalate` (or the old host ticket endpoint while `server_escalation` is off). The widget only renders action ids it has a handler for. The legacy fields `source, answer_en, answer_hi, input_type, category?` are still sent for one release; `source` is one of `kb`, `unknown`, `exit`, `escalation`, or the input type. The JS (`SmartAssistant.renderResponse`) prefers `blocks` and falls back to the legacy fields.

### Escalation: `POST /smart-assistant/escalate`
Route name `smart-assistant.escalate`, same middleware as `/help`. `EscalationController` resolves the user (guests get 401), validates `message` / `error_context` / `page_url` / `attachments[]` against `config('smart-ai-assistant.escalation')`, and hands a `Core\Data\EscalationRequest` to **`Core\Contracts\EscalationChannel`** (`escalation.channel`). The default is `Escalation\NullEscalationChannel`, which rejects every request; `Escalation\LogEscalationChannel` is for development. MaddoxPay sets `App\SmartAssistant\MaddoxPayTicketChannel` (host code, built on the host's `App\Services\CustomerSupport\TicketService`). The channel returns an `EscalationResult` (`created` / `rejected` / `throttled` / `failed`), which is sent as `{status, message, reference, view_url}` with 201 / 422 / 429 / 503. The widget uses this endpoint only when `features.server_escalation` is on (env `SMART_AI_SERVER_ESCALATION`); the view passes the flag and the endpoints to the JS in a `<script type="application/json" id="sa-config">` block.

The behaviour rules (never loop, prompt only once, exit cleanly, abuse handling) are specified in [ASSISTANT_BEHAVIOR.md](ASSISTANT_BEHAVIOR.md). Keep the strategies and guards consistent with that document.

### Host-app coupling
The package depends on things that `composer.json` does not declare:
- **Identity** comes from the host through `Core\Contracts\UserContextResolver`, which returns a `Core\Data\UserContext`. The class is set in `config('smart-ai-assistant.user_resolver')`. The default, `Support\LaravelAuthUserContextResolver`, uses Laravel's auth guard. MaddoxPay sets `App\SmartAssistant\SentinelUserContextResolver` (host code). Package PHP in `src/` must not reference Sentinel; `tests/Unit/PackageBoundaryTest` enforces this. Never read identity from the request body.
- **Temporary exception:** while `features.server_escalation` is off, the package Blade view reads Sentinel (guarded by `class_exists('Sentinel')`) to render hidden `maddox_id`/`full_name`/`phone_no` inputs, and the widget posts chat messages and attachments as FormData to the host route **`POST /customer-support/raise/ticket`**. With the flag on, neither happens. Remove both once the flag is on by default.
- A `<meta name="csrf-token">` tag in the host layout.
- The widget loads no third-party scripts. html2canvas 1.4.1 (MIT), used for screenshot capture, is bundled at `public/js/vendor/html2canvas.min.js`. It is byte-identical to the npm release; its license is next to it.

### Tables
These come from one migration:
- `smart_ai_error_definitions`: the knowledge base, with `service`, `key_text`, `answer_en`, `answer_hi`, and `match_type`.
- `smart_ai_conversations`
- `smart_ai_messages`: `sender_type` is one of user, ai, or system.

### Frontend (`public/js/`, plain browser globals, no bundler)
`widget.blade.php` loads the scripts in this order, and **the order matters**: `ui-manager.js` → (`host-compat.js`) → `api-manager.js` → `file-preview.js` → `assistant.js`. The view renders everything host-specific from `config('smart-ai-assistant.widget')` through **`Support\WidgetConfig`**, which fills in defaults key by key within each section (`branding`, `features`, `page_scan`), because Laravel's config merge is only one level deep. Texts are escaped; colours must be hex (otherwise the default is used) and become CSS variables (`--sa-primary`, `--sa-primary-rgb`, ...) in a `<style>` after the stylesheet. The view puts endpoints, flags and `widget.features` / `widget.page_scan` into the `sa-config` JSON block; `ui-manager.js` parses it once into `window.SmartAssistantConfig`.
- `UIManager` handles the DOM, chat bubbles, the notification badge, and page-error scanning. The scan follows `widget.page_scan`: `ids` and `selectors` always count; `soft_selectors` only when no ignore rule applies (ignore classes/ids, inside a form, `data-error-type="placeholder"`, `ignore_patterns` as case-insensitive JS regexes). Elements inside the widget or under `[data-sa-ignore]` are skipped.
- `host-compat.js` (`widget.features.bootstrap_modal_compat`) holds the Bootstrap modal focus fixes and the input re-enable observers. `UIManager` installs it when loaded. For pages rendered by an older published view (no `widget` key in the config block), `UIManager` uses the old built-in MaddoxPay scan rules (`UIManager.LEGACY_PAGE_SCAN`) and injects `host-compat.js` itself, so an outdated customised view keeps working.
- Code between `legacy-host-start` and `legacy-host-end` markers (JS, Blade) is MaddoxPay-specific code kept for the old flows. `PackageBoundaryTest` checks the view, scripts and CSS for host vocabulary outside those blocks. Delete the blocks once `server_escalation` and `resolve_typed_messages` are default-on and no host uses an old published view.
- `APIManager` makes the `fetch` calls: `/smart-assistant/message` (or `/help` while `resolve_typed_messages` is off), and `/smart-assistant/escalate` (or the host ticket endpoint while `server_escalation` is off). It reads endpoints and feature flags from the view's `sa-config` JSON block.
- With `resolve_typed_messages` off, `SmartAssistant.handleSendMessage` keeps the old flow: a JS pre-filter (cold query, greeting, vague) and then a ticket for every other typed message. Delete that flow and the pre-filter once the flag is on by default.
- `FilePreviewManager` handles attachments, screenshots, and the fullscreen preview.
- `SmartAssistant` in `assistant.js` is the controller. It checks that the three classes above are on `window`, then creates `window.smartAssistant`.

`public/assistant.js` (at the root of `public/`) is the old v1 script. The widget does not load it.
