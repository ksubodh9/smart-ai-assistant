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
