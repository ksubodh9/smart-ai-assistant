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
```

**Important:** the browser loads the *published copies*, not the files in this package. JS and CSS edits do not show up until the assets are republished with `--force` and the browser is hard-refreshed. `publish --force` copies from the host's `vendor/` (the installed release), not from this folder. The host keeps customised copies of the view (`resources/views/vendor/smart-ai-assistant`, for branding) and the config (it holds the auth middleware). **Never force-publish views or config**: merge package changes into those files by hand.

### Tests (run from this package directory, not the host app)

```bash
composer install          # package-local vendor/ with orchestra/testbench + PHPUnit
vendor/bin/phpunit        # or: composer test
```

Feature tests use a dedicated MySQL database, `smart_ai_assistant_test` (settings in `phpunit.xml`). It is wiped on every run. MySQL is used because it matches production (`DatabaseKnowledgeSource` also supports SQLite). The tests are **characterization tests**: they pin current behaviour, and cases named `known_bug` / `known_gap` record wrong behaviour on purpose. When fixing one, update its expectation in the same commit. `tests/Stubs/Sentinel` is a test-only stand-in for the host's Sentinel facade; only the widget view tests still need it. Test requests that depend on the session must send a session cookie with `withCredentials()->withCookie(...)`, because JSON test requests carry no cookies by default.

The JavaScript has no automated tests. Frontend changes are still verified manually in the browser, using the checklist in README.md.

## Architecture

### Backend request flow: `POST /smart-assistant/help`
Route name: `smart-assistant.help`. The middleware is `config('smart-ai-assistant.middleware')` (package default `['web']`; hosts add their auth middleware, e.g. MaddoxPay uses `['web', 'sentinel.auth']`), followed by `throttle:smart-assistant`. That rate limiter is defined in the service provider, with limits per session and per IP from `config('smart-ai-assistant.rate_limit')`. `error_text` is limited to 1000 characters, and only the path of `page_url` is stored.

`ErrorHelpController::store` runs a deterministic pipeline. No LLM is called; `config('smart-ai-assistant.ai')` is a stub for later. Its collaborators are method-injected, because the router reuses controller instances and they depend on per-request config.
1. **`Core\Contracts\Interpreter`** (default `Understanding\RuleBasedInterpreter`) turns the text into a `Core\Data\StructuredProblem` with `intent`, `domains`, and `signals`; `signals['input_type']` keeps the legacy classifier type for the wire format. It wraps **`Support\InputClassifier::classify()`**, which uses Unicode-aware (`/u`) regex checks in a fixed priority order: empty → severe abuse → noise → greeting → vague → escalation request → mild abuse → valid. Invalid UTF-8 is noise. It also tags a keyword category (PAN, RECHARGE, AEPS, PAYOUT, KYC, IRCTC), matching whole words with an optional plural "s".
2. Non-processable intents get a canned reply (`InputClassifier::cannedResponse()`) and are **not** saved to the database.
3. An escalation request gets the "Raise Ticket" message.
4. **`Core\Contracts\KnowledgeSource`** (default `Knowledge\DatabaseKnowledgeSource`) returns `Core\Data\KnowledgeEntry` objects. The database source matches when an entry's `key_text` appears inside the user text (case-insensitive); `%`, `_` and `!` in `key_text` are escaped so they match literally. It runs a SQL `LIKE` (`CONCAT`, or `||` on SQLite), then a PHP fallback capped at 5000 rows. It filters by `config('smart-ai-assistant.default_service')` (default `AEPS`).
5. **Loop prevention**: the last response (or its md5 hash) is kept in the session. If the same answer would be sent twice in a row, the controller returns an `exit` message instead.
6. Only meaningful input is saved to `smart_ai_conversations` and `smart_ai_messages`. User text and the page path go through **`Core\Contracts\Redactor`** first (`config('smart-ai-assistant.redactor')`, default `Support\DefaultRedactor`: email, PAN, Aadhaar, phone, and 9–18 digit runs). KB answers are stored unredacted.

The response JSON has the shape `{conversation_id, source, answer_en, answer_hi, input_type, category?}`, where `source` is one of `kb`, `unknown`, `exit`, `escalation`, or the input type.

The behaviour rules (never loop, prompt only once, exit cleanly, abuse handling) are specified in [ASSISTANT_BEHAVIOR.md](ASSISTANT_BEHAVIOR.md). Keep the controller and classifier consistent with that document.

### Host-app coupling
The package depends on things that `composer.json` does not declare:
- **Identity** comes from the host through `Core\Contracts\UserContextResolver`, which returns a `Core\Data\UserContext`. The class is set in `config('smart-ai-assistant.user_resolver')`. The default, `Support\LaravelAuthUserContextResolver`, uses Laravel's auth guard. MaddoxPay sets `App\SmartAssistant\SentinelUserContextResolver` (host code). Package PHP in `src/` must not reference Sentinel; `tests/Unit/PackageBoundaryTest` enforces this. Never read identity from the request body.
- **Temporary exception:** the package Blade view still reads Sentinel (guarded by `class_exists('Sentinel')`) to render hidden `maddox_id`/`full_name`/`phone_no` inputs for the host ticket endpoint. This goes away when escalation moves server-side (plan step 4).
- A host-app route, **`POST /customer-support/raise/ticket`**, which receives chat messages and attachments as FormData.
- A `<meta name="csrf-token">` tag in the host layout.
- The widget loads no third-party scripts. html2canvas 1.4.1 (MIT), used for screenshot capture, is bundled at `public/js/vendor/html2canvas.min.js`. It is byte-identical to the npm release; its license is next to it.

### Tables
These come from one migration:
- `smart_ai_error_definitions`: the knowledge base, with `service`, `key_text`, `answer_en`, `answer_hi`, and `match_type`.
- `smart_ai_conversations`
- `smart_ai_messages`: `sender_type` is one of user, ai, or system.

### Frontend (`public/js/`, plain browser globals, no bundler)
`widget.blade.php` loads the scripts in this order, and **the order matters**: `ui-manager.js` → `api-manager.js` → `file-preview.js` → `assistant.js`.
- `UIManager` handles the DOM, chat bubbles, the notification badge, and page-error scanning. The error scan uses **selectors hard-coded in `ui-manager.js`**; the `error_selectors` config key is not used.
- `APIManager` makes the `fetch` calls to `/smart-assistant/help` and the ticket endpoint.
- `FilePreviewManager` handles attachments, screenshots, and the fullscreen preview.
- `SmartAssistant` in `assistant.js` is the controller. It checks that the three classes above are on `window`, then creates `window.smartAssistant`.

`public/assistant.js` (at the root of `public/`) is the old v1 script. The widget does not load it.
