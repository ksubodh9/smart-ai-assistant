# Smart AI Assistant - Architecture Overview

## 🏗️ System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        USER INTERFACE                            │
│                     (widget.blade.php)                           │
│                                                                   │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │   Welcome    │  │ Error Tags   │  │ Chat Input   │          │
│  │   Message    │  │  Container   │  │     Bar      │          │
│  └──────────────┘  └──────────────┘  └──────────────┘          │
│                                                                   │
│  ┌──────────────────────────────────────────────────┐           │
│  │          Chat Messages Container                  │           │
│  │  ┌────────────┐  ┌────────────┐                  │           │
│  │  │   User     │  │ Assistant  │                  │           │
│  │  │  Message   │  │  Message   │                  │           │
│  │  └────────────┘  └────────────┘                  │           │
│  └──────────────────────────────────────────────────┘           │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    JAVASCRIPT LAYER                              │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │              assistant.js (Main Controller)               │   │
│  │                                                            │   │
│  │  • Coordinates all managers                               │   │
│  │  • Handles error scanning                                 │   │
│  │  • Manages message sending                                │   │
│  └──────────────────────────────────────────────────────────┘   │
│                              │                                    │
│         ┌────────────────────┼────────────────────┐             │
│         ▼                    ▼                    ▼             │
│  ┌─────────────┐    ┌─────────────┐    ┌─────────────┐        │
│  │ ui-manager  │    │ api-manager │    │file-preview │        │
│  │    .js      │    │    .js      │    │    .js      │        │
│  │             │    │             │    │             │        │
│  │ • Error     │    │ • Error     │    │ • Thumbnail │        │
│  │   tags      │    │   queries   │    │   preview   │        │
│  │ • Chat      │    │ • Chat      │    │ • Fullscreen│        │
│  │   messages  │    │   messages  │    │   modal     │        │
│  │ • Panel     │    │ • User data │    │ • File      │        │
│  │   state     │    │             │    │   handling  │        │
│  └─────────────┘    └─────────────┘    └─────────────┘        │
│         │                    │                    │             │
└─────────┼────────────────────┼────────────────────┼─────────────┘
          │                    │                    │
          ▼                    ▼                    ▼
┌─────────────────────────────────────────────────────────────────┐
│                      BACKEND API                                 │
│                                                                   │
│  ┌──────────────────────────┐  ┌──────────────────────────┐    │
│  │  /smart-assistant/help   │  │ /customer-support/raise  │    │
│  │                          │  │        /ticket           │    │
│  │  • Receives error text   │  │                          │    │
│  │  • Queries knowledge base│  │  • Creates support ticket│    │
│  │  • Returns EN/HI answers │  │  • Handles file uploads  │    │
│  └──────────────────────────┘  └──────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
```

## 🔄 User Flow Diagrams

### Flow 1: Panel Opens → Error Detection

```
User clicks toggle button
         │
         ▼
Panel opens with animation
         │
         ▼
Show welcome message
"Hello! I'm Soniya..."
         │
         ▼
Status: "Scanning your page..."
         │
         ▼
Scan DOM for error selectors
(.alert-danger, .invalid-feedback, etc.)
         │
         ├─── No errors found ───────────┐
         │                                │
         ▼                                ▼
    Errors found                  Status: "No errors detected"
         │                                │
         ▼                                ▼
Display error tags              Show chat input bar
as clickable chips                      │
         │                               │
         └───────────────┬───────────────┘
                         │
                         ▼
                   User can chat
```

### Flow 2: Error Tag Clicked

```
User clicks error tag
         │
         ▼
Highlight tag as selected
(⚠️ changes to ✓)
         │
         ▼
Hide welcome message
         │
         ▼
Show chat input bar
         │
         ▼
Add user message to chat
"Help me with: [error text]"
         │
         ▼
Show typing indicator
(animated dots)
         │
         ▼
Send error to API
POST /smart-assistant/help
         │
         ├─── Success ──────┬─── Error ───┐
         │                  │              │
         ▼                  ▼              ▼
Hide typing          Network error   Parse error
indicator                 │              │
         │                ▼              ▼
         ▼          Show network    Show parse
Display AI          error msg      error msg
response in
chat bubble
(EN + HI)
```

### Flow 3: File Attachment

```
User clicks attachment icon
         │
         ▼
File picker opens
         │
         ▼
User selects file
         │
         ▼
Determine file type
(image/pdf/other)
         │
         ├─── Image ────┬─── PDF ────┬─── Other ───┐
         │              │             │             │
         ▼              ▼             ▼             ▼
Show image      Show PDF icon  Show file icon  Show file icon
thumbnail                                              │
         │              │             │               │
         └──────────────┴─────────────┴───────────────┘
                        │
                        ▼
         Display preview with:
         • Thumbnail
         • Filename
         • File size
         • Remove button
                        │
         ┌──────────────┴──────────────┐
         │                             │
         ▼                             ▼
Click thumbnail              Click remove button
         │                             │
         ▼                             ▼
Open fullscreen modal        Clear preview
with dark overlay            Reset file input
         │
         ▼
Show full size image/PDF
         │
         ▼
Close with:
• × button
• Overlay click
• ESC key
```

### Flow 4: Send Message

```
User types message
(with optional file)
         │
         ▼
User clicks send button
or presses Enter
         │
         ▼
Validate input
(message or file required)
         │
         ├─── Invalid ───┐
         │                │
         ▼                ▼
    Valid input      Do nothing
         │
         ▼
Disable send button
         │
         ▼
Add user message to chat
         │
         ▼
Clear input & file preview
         │
         ▼
Show typing indicator
         │
         ▼
Create FormData with:
• User info (maddox_id, name, phone)
• Message text
• Error context (if any)
• File (if attached)
         │
         ▼
POST /customer-support/raise/ticket
         │
         ├─── Success ──────┬─── Auth Error ──┬─── Network Error ──┐
         │                  │                  │                    │
         ▼                  ▼                  ▼                    ▼
Hide typing          Show auth msg      Show network msg    Show error msg
indicator                 │                   │                   │
         │                │                   │                   │
         ▼                │                   │                   │
Show success msg          │                   │                   │
in chat bubble            │                   │                   │
         │                │                   │                   │
         └────────────────┴───────────────────┴───────────────────┘
                                      │
                                      ▼
                              Re-enable send button
```

## 📊 Data Flow

### Error Query Data Flow

```
┌──────────┐     error_text      ┌──────────┐     Query KB     ┌──────────┐
│          │  ─────────────────▶ │          │ ───────────────▶ │          │
│  Client  │     page_url        │  Backend │                  │ Database │
│          │                     │   API    │                  │   (KB)   │
│          │  ◀─────────────────  │          │ ◀───────────────  │          │
└──────────┘   answer_en/hi      └──────────┘   Match result   └──────────┘
               source (known/
                unknown)
```

### Chat Message Data Flow

```
┌──────────┐    FormData:         ┌──────────┐   Create ticket  ┌──────────┐
│          │  • maddox_id         │          │  ───────────────▶│          │
│  Client  │  • name              │  Backend │                  │ Database │
│          │  • phone             │   API    │                  │ (Tickets)│
│          │  • description       │          │                  │          │
│          │  • attachment        │          │                  │          │
│          │  ─────────────────▶  │          │                  │          │
│          │                      │          │                  │          │
│          │  ◀─────────────────  │          │ ◀───────────────  │          │
└──────────┘   success/error      └──────────┘   Ticket created └──────────┘
               message
```

## 🎨 Component Hierarchy

```
SmartAssistant (Main Controller)
│
├── UIManager
│   ├── togglePanel()
│   ├── showWelcomeMessage()
│   ├── renderErrorTags()
│   ├── handleErrorTagClick()
│   ├── addChatMessage()
│   ├── showTypingIndicator()
│   └── scrollChatToBottom()
│
├── APIManager
│   ├── sendErrorQuery()
│   ├── sendChatMessage()
│   └── getUserData()
│
└── FilePreviewManager
    ├── handleFileSelect()
    ├── showPreview()
    ├── getThumbnailContent()
    ├── openFullscreen()
    └── removeFile()
```

## ⚙️ Backend Resolution Pipeline

`POST /smart-assistant/help` is handled by a thin controller that hands the
work to small, replaceable classes. The flow is the same for every host; what
differs between hosts is configuration.

```
validate (max 1000 chars, page path only)
  → UserContextResolver        who is the user (host decides)
  → ConversationStore::open    continue the sent conversation_id if it is the caller's
                               and not idle; otherwise start a new one
  → Interpreter                text → StructuredProblem (intent, domains, signals)
  → ResolverPipeline
       strategies, in config order, first non-null wins:
         InputGuardStrategy          greeting / vague / noise / abuse → canned reply
         ExplicitEscalationStrategy  "talk to a human"     (capability: escalation)
         KnowledgeLookupStrategy     KnowledgeSource match (capability: knowledge)
         FallbackStrategy            "not documented" + raise-ticket hint
       guards, applied to the winner:
         ClarifyOnceGuard            same canned reply twice → exit
         LoopGuard                   same answer twice → exit
  → ConversationStore::record  only if Resolution::persist; user text redacted;
                               status resolved / unresolved
  → ResponseSerializer         protocol 1: {protocol, conversation_id, blocks, actions, meta}
                               + legacy {source, answer_en, answer_hi, input_type, category?}
```

| Piece | Where | Replace via |
|---|---|---|
| Contracts and data objects | `src/Core/Contracts`, `src/Core/Data` | — |
| `ResolverPipeline` | `src/Core/Resolution` | — |
| Strategies | `src/Strategies` | `resolution.strategies` (add host classes) |
| Guards | `src/Resolution/Guards` | `resolution.guards` |
| `RuleBasedInterpreter` + `InputClassifier` | `src/Understanding`, `src/Support` | `understanding.patterns`, `understanding.categories` |
| `DatabaseKnowledgeSource` | `src/Knowledge` | `default_service` (the domain searched) |
| Reply texts | `Support\ResponseCatalog::DEFAULTS` | `responses` (per key) |
| Identity | `Support\LaravelAuthUserContextResolver` | `user_resolver` |
| Redaction | `Support\DefaultRedactor` | `redactor` |
| Conversations | `Persistence\EloquentConversationStore` | `conversations.idle_minutes`, `conversations.retention_days` |

**Conversations.** One chat is one row in `smart_ai_conversations`. The
widget keeps the id it gets back in `sessionStorage` and sends it with the next
message (and with a support request). The id is only a hint: it is honoured for
the same user id, or for guests the same session (a sha256 of the session id in
`meta.guest_key`), and only within `idle_minutes`. Otherwise a new conversation
starts, so a guessed id never reaches someone else's conversation.

Guard state (last reply sent) lives in the conversation's `meta.state` through
the `ConversationState` contract. Canned replies store no text; KB answers and
fallbacks add a user and an AI message. Status: `open` → `resolved` /
`unresolved` (latest stored outcome) → `escalated` once a ticket is created
(final). `php artisan smart-ai:prune` deletes conversations idle longer than
`retention_days`; hosts schedule it.

**Wire format (protocol 1).** `blocks` are `text` blocks, one per answer
locale, with `format: "basic"` (`**bold**` and line breaks; rendered as text).
`actions` offers `escalate` after unresolved replies and requests for a human;
the widget does not render actions yet (plan step 6). The legacy fields stay
for one release; `SmartAssistant.renderResponse()` prefers blocks.

### Escalation: `POST /smart-assistant/escalate`

Used by the widget for typed messages when `features.server_escalation` is on.
`EscalationController` follows the same shape as `/help`:

```
UserContextResolver            guests get 401; identity fields in the form are ignored
  → validate                   message or attachments; limits from config('escalation')
  → Interpreter                only for domain tags (e.g. AEPS), passed to the channel
  → ConversationStore::open    same rule as /help
  → EscalationChannel          host system decides: created / rejected / throttled / failed
  → recordEscalation           user message (redacted) + system message with the outcome;
                               "created" marks the conversation escalated
  → JSON                       {conversation_id, status, message, reference, view_url}; 201 / 422 / 429 / 503
```

| Piece | Where | Replace via |
|---|---|---|
| `EscalationChannel` contract, `EscalationRequest` / `EscalationResult` | `src/Core` | — |
| `NullEscalationChannel` (default, rejects), `LogEscalationChannel` (dev) | `src/Escalation` | `escalation.channel` |

The widget learns the endpoint and the flag from a `<script type="application/json"
id="sa-config">` block in the view. With the flag on, the view renders no
`sa-user-*` inputs. MaddoxPay's channel (`App\SmartAssistant\MaddoxPayTicketChannel`)
uses the host's `TicketService`, which `QueryController::raiseTicket` also uses,
so both share the 10-minute limit and ticket creation.

## 🔐 Security Considerations

1. **CSRF Protection**: All API calls include CSRF token
2. **File Validation**: Client-side file type checking
3. **User Authentication**: Checks for logged-in user before sending
4. **Input Sanitization**: Backend should sanitize all inputs
5. **File Upload Limits**: Server-side file size validation

## 🚀 Performance Optimizations

1. **Lazy Loading**: Panel content loads only when opened
2. **Debouncing**: Textarea auto-expand is optimized
3. **Memory Management**: Object URLs are revoked after use
4. **Efficient DOM Updates**: Minimal reflows and repaints
5. **CSS Animations**: Hardware-accelerated transforms

---

**This architecture ensures:**
- ✅ Separation of concerns
- ✅ Easy maintenance
- ✅ Scalability
- ✅ Testability
- ✅ Performance
