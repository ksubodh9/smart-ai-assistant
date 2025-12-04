# Smart AI Assistant - Quick Reference

## 🚀 Quick Start

### Deploy Changes
```bash
# Open Laragon Terminal, then:
cd c:\Local_Disk_N_2620251648\laragon\www\mdxplaygrnd
php artisan cache:clear && php artisan view:clear
php artisan vendor:publish --tag=smart-ai-assistant-assets --force
php artisan vendor:publish --tag=smart-ai-assistant-views --force
```

### Test in Browser
1. Hard refresh: `Ctrl + Shift + R`
2. Open browser console: `F12`
3. Click Smart Assistant button
4. Check for errors in console

---

## 📁 File Locations

| File | Location |
|------|----------|
| **Source Files** | `packages/smart-ai-assistant/` |
| **Published Assets** | `public/vendor/smart-ai-assistant/` |
| **Published Views** | `resources/views/vendor/smart-ai-assistant/` |

---

## 🎨 Customization Quick Guide

### Change Colors
**File:** `packages/smart-ai-assistant/public/css/assistant.css`

```css
/* Main gradient */
#smart-assistant-toggle {
    background: linear-gradient(135deg, #YOUR_COLOR_1 0%, #YOUR_COLOR_2 100%);
}

/* User message bubble */
.sa-chat-user .sa-chat-bubble {
    background: linear-gradient(135deg, #YOUR_COLOR_1 0%, #YOUR_COLOR_2 100%);
}
```

### Change Assistant Name
**File:** `packages/smart-ai-assistant/resources/views/components/widget.blade.php`

```html
<!-- Line ~23 -->
<div class="sa-welcome-title">Hello! I'm YOUR_NAME</div>

<!-- Line ~17 -->
<span>Chat with YOUR_NAME</span>
```

### Add Error Selectors
**File:** `packages/smart-ai-assistant/public/js/assistant.js`

```javascript
// Line ~33
const errorSelectors = [
    '.alert-danger',
    '.invalid-feedback',
    '.text-danger',
    '.smart-error',
    '.your-custom-selector'  // Add here
];
```

### Change Welcome Message
**File:** `packages/smart-ai-assistant/resources/views/components/widget.blade.php`

```html
<!-- Line ~23-26 -->
<div class="sa-welcome-icon">👋</div>
<div class="sa-welcome-title">Your Custom Title</div>
<div class="sa-welcome-subtitle">Your custom subtitle</div>
```

---

## 🔧 API Endpoints

### Error Query
```javascript
POST /smart-assistant/help

// Request
{
    "error_text": "Error message from page",
    "page_url": "https://example.com/page"
}

// Response
{
    "answer_en": "English solution",
    "answer_hi": "Hindi solution",
    "source": "known" | "unknown"
}
```

### Chat Message
```javascript
POST /customer-support/raise/ticket

// FormData fields
{
    "type": "self",
    "maddox_id": "user_id",
    "name": "User Name",
    "alternate_phone_no": "1234567890",
    "service": 99,
    "category": "Smart Assistant",
    "description": "Message + error context",
    "attachment": File (optional)
}

// Response
{
    "success": true,
    "message": "Ticket created successfully"
}
```

---

## 🎯 CSS Classes Reference

### Panel States
```css
.sa-panel-open          /* Panel is visible */
.sa-modal-open          /* Fullscreen modal is visible */
```

### Error Tags
```css
.sa-error-tag           /* Error tag chip */
.sa-error-tag-selected  /* Selected error tag */
```

### Chat Messages
```css
.sa-chat-message        /* Message container */
.sa-chat-user           /* User message */
.sa-chat-assistant      /* Assistant message */
.sa-chat-bubble         /* Message bubble */
.sa-chat-error          /* Error message */
.sa-typing              /* Typing indicator */
```

### File Preview
```css
.sa-file-preview-item   /* File preview container */
.sa-file-thumbnail      /* Thumbnail image/icon */
.sa-file-info           /* File name and size */
.sa-file-remove         /* Remove button */
```

### Fullscreen Modal
```css
.sa-fullscreen-modal    /* Modal container */
.sa-fullscreen-overlay  /* Dark backdrop */
.sa-fullscreen-content  /* Content wrapper */
.sa-fullscreen-close    /* Close button */
```

---

## 🎨 Color Palette

```css
/* Primary Colors */
--primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
--primary-purple: #667eea;
--primary-dark: #764ba2;

/* Neutral Colors */
--gray-50: #f9fafb;
--gray-100: #f7fafc;
--gray-200: #e2e8f0;
--gray-300: #cbd5e0;
--gray-400: #a0aec0;
--gray-500: #718096;
--gray-600: #4b5563;
--gray-700: #2d3748;
--gray-800: #1a202c;

/* Error Colors */
--error-bg: #fff5f5;
--error-border: #feb2b2;
--error-text: #c53030;

/* Success Colors */
--success-bg: #ecfdf5;
--success-border: #10b981;
--success-text: #065f46;
```

---

## 🔍 Debugging

### Check if JS files are loaded
```javascript
// In browser console
console.log(window.UIManager);        // Should be a function
console.log(window.APIManager);       // Should be a function
console.log(window.FilePreviewManager); // Should be a function
console.log(window.smartAssistant);   // Should be an object
```

### Check panel state
```javascript
// In browser console
const panel = document.getElementById('smart-assistant-panel');
console.log(panel.classList.contains('sa-panel-open')); // true if open
```

### Monitor API calls
```javascript
// In browser console, Network tab
// Filter by: smart-assistant, customer-support
```

### Check for errors
```javascript
// In browser console
// Look for red error messages
// Check Sources tab for file loading issues
```

---

## 📱 Responsive Breakpoints

```css
/* Mobile */
@media (max-width: 480px) {
    /* Panel width: calc(100vw - 40px) */
    /* Toggle button: 56x56px */
    /* Chat bubble max-width: 85% */
}

/* Desktop */
/* Panel width: 420px */
/* Toggle button: 64x64px */
/* Chat bubble max-width: 80% */
```

---

## ⚡ Performance Tips

1. **Minimize reflows**: Batch DOM updates
2. **Use CSS transforms**: Hardware-accelerated animations
3. **Debounce inputs**: Avoid excessive API calls
4. **Lazy load**: Only load when panel opens
5. **Clean up**: Revoke object URLs after use

---

## 🐛 Common Issues & Fixes

| Issue | Solution |
|-------|----------|
| Changes not showing | Clear caches, republish assets, hard refresh |
| JS errors | Check file load order in widget.blade.php |
| Styles not applying | Check CSS conflicts, verify file loaded |
| File upload fails | Check server upload limits |
| API errors | Check network tab, verify endpoints |

---

## 📚 Documentation Files

- **DEPLOYMENT_GUIDE.md** - Step-by-step deployment
- **README_UPGRADE.md** - Feature documentation
- **ARCHITECTURE.md** - System architecture
- **QUICK_REFERENCE.md** - This file

---

## 🎯 Testing Checklist

- [ ] Panel opens/closes smoothly
- [ ] Welcome message displays
- [ ] Error scanning works
- [ ] Error tags are clickable
- [ ] Chat input appears
- [ ] Messages send successfully
- [ ] File attachment works
- [ ] Thumbnail preview shows
- [ ] Fullscreen modal opens
- [ ] Typing indicator animates
- [ ] Auto-scroll works
- [ ] Mobile responsive
- [ ] No console errors

---

## 💡 Pro Tips

1. **Always test on actual error pages** to verify error detection
2. **Use browser DevTools** to inspect element styles
3. **Check Network tab** to debug API issues
4. **Test file uploads** with different file types
5. **Test on mobile** to ensure responsive design works
6. **Clear caches** after every change during development
7. **Use hard refresh** to bypass browser cache

---

**Need more help?** Check the other documentation files or contact the dev team.
