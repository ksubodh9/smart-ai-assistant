# Smart AI Assistant v2.0

> A modern, intelligent chat assistant for Laravel applications with automatic error detection, file attachments, and bilingual support.

[![Version](https://img.shields.io/badge/version-2.0.0-blue.svg)](CHANGELOG.md)
[![Laravel](https://img.shields.io/badge/Laravel-8.x%2B-red.svg)](https://laravel.com)
[![License](https://img.shields.io/badge/license-Proprietary-green.svg)](LICENSE)

---

## 🌟 Features

- ✅ **Auto Error Detection** - Automatically scans pages for errors
- ✅ **Clickable Error Tags** - Interactive chips for found errors
- ✅ **Modern Chat UI** - WhatsApp/ChatGPT-inspired design
- ✅ **File Attachments** - Upload images, PDFs, and documents
- ✅ **Fullscreen Preview** - View attachments in fullscreen modal
- ✅ **Bilingual Support** - English and Hindi responses
- ✅ **Typing Indicators** - Animated dots while waiting
- ✅ **Smart Context** - Includes error context in queries
- ✅ **Responsive Design** - Works on mobile and desktop
- ✅ **Smooth Animations** - Professional transitions and effects

---

## 📸 Screenshots

See the visual mockups showing the modern, premium design!

---

## 🚀 Quick Start

### Installation

This package is already installed in your Laravel application.

### Deployment

```bash

# Clear caches
php artisan cache:clear
php artisan view:clear

# Publish assets
php artisan vendor:publish --tag=smart-ai-assistant-assets --force
php artisan vendor:publish --tag=smart-ai-assistant-views --force

# Hard refresh browser
# Ctrl + Shift + R
```

### Usage

Add the widget to any Blade template:

```blade
<x-smart-assistant-widget />
```

That's it! The assistant will appear as a floating button in the bottom-right corner.

---

## 📚 Documentation

| Document | Description |
|----------|-------------|
| **[UPGRADE_SUMMARY.md](UPGRADE_SUMMARY.md)** | Complete upgrade overview |
| **[DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md)** | Step-by-step deployment |
| **[README_UPGRADE.md](README_UPGRADE.md)** | Feature documentation |
| **[ARCHITECTURE.md](ARCHITECTURE.md)** | System architecture |
| **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** | Developer quick reference |
| **[CHANGELOG.md](CHANGELOG.md)** | Version history |

---

## 🎯 How It Works

### 1. Panel Opens
- Shows welcome message: "Hello! I'm Soniya"
- Automatically scans page for errors
- Displays status: "Scanning your page..."

### 2. Error Detection
- Scans for: `.alert-danger`, `.invalid-feedback`, `.text-danger`, `.smart-error`
- Displays errors as clickable tags
- Shows warning icon (⚠️)

### 3. User Interaction
- Click error tag → Sends to API → Shows AI response
- Or type manual message → Send to support
- Attach files for context

### 4. AI Response
- Returns English and Hindi solutions
- Displays in chat bubble format
- Auto-scrolls to bottom

---

## 🎨 Customization

### Change Colors

Edit `public/css/assistant.css`:

```css
#smart-assistant-toggle {
    background: linear-gradient(135deg, #YOUR_COLOR_1 0%, #YOUR_COLOR_2 100%);
}
```

### Change Assistant Name

Edit `resources/views/components/widget.blade.php`:

```html
<div class="sa-welcome-title">Hello! I'm YOUR_NAME</div>
```

### Host configuration checklist

The package defaults are generic (English replies, no categories, knowledge
domain `general`). Publish the config once
(`php artisan vendor:publish --tag=smart-ai-assistant-config`) and set:

1. `middleware`: keep `web` and add your auth middleware.
2. `user_resolver`: only if you don't use Laravel's auth guard (e.g. Sentinel).
3. `default_service`: the knowledge domain your KB rows use
   (`smart-ai:seed-kb file.xlsx --domain=...`).
4. `understanding.patterns` / `understanding.categories`: your language and
   product vocabulary. A pattern type you list replaces its defaults.
5. `responses`: reply texts to change, per key, optionally with `hi`.
6. `resolution.strategies`: add your own `ResolutionStrategy` classes; keep
   `FallbackStrategy` last.
7. `escalation.channel`: your `EscalationChannel` class that turns a support
   request into a ticket in your system (the default rejects every request).
   Set `escalation.attachments` and `escalation.max_message_length` within
   your system's limits.
8. `features.server_escalation` (or `SMART_AI_SERVER_ESCALATION=true`): send
   typed messages through the package endpoint and your channel. While it is
   off, the widget posts to `/customer-support/raise/ticket` with identity
   fields rendered into the page.
9. `conversations.retention_days` (or `SMART_AI_RETENTION_DAYS`): how long
   to keep stored conversations, then schedule `php artisan smart-ai:prune`
   daily. `null` keeps everything.

The config is merged one level deep: a top-level key in your file replaces the
package's value for that key entirely.

### Add Error Selectors

Edit `public/js/assistant.js`:

```javascript
const errorSelectors = [
    '.alert-danger',
    '.your-custom-selector'
];
```

---

## 🔧 API Endpoints

### Error Query
```
POST /smart-assistant/help
```

**Request:**
```json
{
    "error_text": "Error message",
    "page_url": "https://example.com",
    "conversation_id": 12
}
```

`conversation_id` is the one from the previous response; it is only honoured
for the same user (or guest session) and while the conversation is active.

**Response** (protocol 1, with the legacy fields kept for one release):
```json
{
    "protocol": 1,
    "conversation_id": 12,
    "blocks": [
        { "type": "text", "format": "basic", "locale": "en", "text": "English solution" },
        { "type": "text", "format": "basic", "locale": "hi", "text": "Hindi solution" }
    ],
    "actions": [],
    "meta": { "source": "kb", "input_type": "valid", "category": "AEPS" },
    "source": "kb",
    "answer_en": "English solution",
    "answer_hi": "Hindi solution",
    "input_type": "valid",
    "category": "AEPS"
}
```

After an unresolved reply or a request for a human, `actions` holds
`{"type": "action", "id": "escalate", "label": "Raise ticket", "confirm": true}`.

### Support Request (`features.server_escalation` on)
```
POST /smart-assistant/escalate
```

**Multipart form:** `message` (required unless files are attached),
`error_context` (page error the user picked, optional), `page_url` (optional),
`attachments[]` (files, limited by `escalation.attachments`). The user comes
from the session; identity fields in the form are ignored.

**Response** (`201` created, `422` rejected, `429` throttled by the host,
`503` failed; `401` for guests):
```json
{
    "conversation_id": 12,
    "status": "created",
    "message": "Your query has been registered successfully.",
    "reference": "MDXCID123456789",
    "view_url": "/customer-support/ticket/MDXCID123456789"
}
```

### Chat Message (`features.server_escalation` off)
```
POST /customer-support/raise/ticket
```

**FormData:**
- `maddox_id`: User ID
- `description`: Message text
- `attachment`: File (optional)

---

## 📁 File Structure

```
packages/smart-ai-assistant/
├── public/
│   ├── css/
│   │   └── assistant.css
│   └── js/
│       ├── ui-manager.js
│       ├── api-manager.js
│       ├── file-preview.js
│       └── assistant.js
├── resources/
│   └── views/
│       └── components/
│           └── widget.blade.php
├── src/
│   ├── Http/
│   │   └── Controllers/
│   ├── Models/
│   └── SmartAiAssistantServiceProvider.php
├── config/
│   └── smart-ai-assistant.php
├── database/
│   └── migrations/
└── routes/
    └── web.php
```

---

## 🧪 Testing

### Manual Testing Checklist

- [ ] Panel opens with welcome message
- [ ] Error scanning detects errors
- [ ] Error tags are clickable
- [ ] Chat messages display correctly
- [ ] File attachment works
- [ ] Fullscreen preview opens
- [ ] Send button works
- [ ] API responses display
- [ ] With `server_escalation` on: a typed message creates a ticket and shows
      its reference; a second one within the host's limit shows the host's
      "please wait" message; a `.docx` or a file over the size limit is refused
      with a message; the page source has no `sa-user-*` inputs
- [ ] Replies look as before (💡 Solution / 🇮🇳 हिंदी में sections); the same
      greeting twice, or the same error twice, gets the exit message, also
      after navigating to another page in the same tab
- [ ] Mobile responsive works
- [ ] No console errors

### Browser Testing

- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+
- Mobile browsers

---

## 🐛 Troubleshooting

### Changes not appearing?
1. Clear all Laravel caches
2. Republish assets with `--force`
3. Hard refresh browser

### JavaScript errors?
1. Check all 4 JS files are loaded
2. Verify load order in widget.blade.php
3. Check browser console

### Styles not applying?
1. Verify CSS file is loaded
2. Check for CSS conflicts
3. Inspect elements in DevTools

See [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md) for more troubleshooting tips.

---

## 📊 Version History

### v2.0.0 (2025-12-04)
- Complete UI/UX overhaul
- Modular JavaScript architecture
- File attachment system
- Fullscreen preview modal
- Modern chat interface
- Comprehensive documentation

### v1.0.0 (2025-11-29)
- Initial release
- Basic error detection
- Simple chat interface

See [CHANGELOG.md](CHANGELOG.md) for detailed history.

---

## 🏗️ Architecture

### Modular Design

- **ui-manager.js** - UI interactions and state
- **api-manager.js** - Backend communications
- **file-preview.js** - File handling and preview
- **assistant.js** - Main controller

### Data Flow

```
User Action → UI Manager → API Manager → Backend
                ↓              ↓
         Update UI ← Parse Response
```

See [ARCHITECTURE.md](ARCHITECTURE.md) for detailed diagrams.

---

## 🎓 Learning Resources

### For Developers
1. Start with [QUICK_REFERENCE.md](QUICK_REFERENCE.md)
2. Study [ARCHITECTURE.md](ARCHITECTURE.md)
3. Review code comments in JS files

### For Designers
1. Review [assistant.css](public/css/assistant.css)
2. Check color palette in docs
3. Inspect animations

### For Testers
1. Follow testing checklist above
2. Test on multiple browsers
3. Test error scenarios

---

## 🤝 Contributing

This is a proprietary package for Maddox Pay. Internal contributions welcome.

### Development Workflow

1. Make changes in `packages/smart-ai-assistant/`
2. Test locally
3. Publish assets
4. Test in browser
5. Document changes

---

## 📄 License

Proprietary - Maddox Pay

---

## 👥 Credits

**Developed for:** Maddox Pay  
**Version:** 2.0.0  
**Last Updated:** December 4, 2025  

---

## 📞 Support

For issues or questions:

1. Check documentation files
2. Review troubleshooting section
3. Check browser console
4. Contact development team

---

## 🎯 Roadmap

### Future Enhancements
- [ ] Voice input support
- [ ] Multi-language support (beyond EN/HI)
- [ ] Advanced analytics
- [ ] Custom themes
- [ ] Keyboard shortcuts
- [ ] Search history
- [ ] Export chat history

---

## 🌟 Why Choose Smart AI Assistant?

### Before
- Basic error detection
- Simple UI
- Limited functionality
- No file support
- Minimal documentation

### After (v2.0)
- ✅ Advanced error detection with tags
- ✅ Modern, premium UI
- ✅ Full file attachment system
- ✅ Fullscreen previews
- ✅ Comprehensive documentation
- ✅ Mobile responsive
- ✅ Smooth animations
- ✅ Bilingual support

---

**Ready to get started?** Check out [DEPLOYMENT_GUIDE.md](DEPLOYMENT_GUIDE.md)!

---

*Built with ❤️ for Maddox Pay*  
*Powered by Maddox AI*
