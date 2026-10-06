/**
 * UI Manager - Handles all UI interactions and rendering
 *
 * Error scanning follows widget.page_scan from the view's config block:
 * ids and selectors always count as errors; soft selectors only after the
 * ignore rules (form validation, placeholders, labels).
 */

// Widget settings rendered by the view (<script type="application/json" id="sa-config">)
window.SmartAssistantConfig = window.SmartAssistantConfig || (() => {
    try {
        return JSON.parse(document.getElementById('sa-config')?.textContent || '{}');
    } catch (e) {
        console.error('Smart Assistant: invalid sa-config block', e);
        return {};
    }
})();

// Where this script was loaded from, to find host-compat.js next to it
const SA_SCRIPT_BASE = (
    document.currentScript?.src
    || document.querySelector('script[src*="smart-ai-assistant/js/ui-manager.js"]')?.src
    || ''
).replace(/[^/]*$/, '');

class UIManager {
    static loadHostCompat() {
        if (!SA_SCRIPT_BASE || document.querySelector('script[data-sa-host-compat]')) return;
        const script = document.createElement('script');
        script.src = SA_SCRIPT_BASE + 'host-compat.js';
        script.dataset.saHostCompat = '';
        document.head.appendChild(script);
    }

    constructor() {
        this.panel = document.getElementById('smart-assistant-panel');
        this.toggleBtn = document.getElementById('smart-assistant-toggle');
        this.closeBtn = document.getElementById('smart-assistant-close');
        this.chatContainer = document.getElementById('sa-chat-container');
        this.errorTagsContainer = document.getElementById('sa-error-tags');
        this.statusText = document.getElementById('smart-assistant-status');
        this.welcomeMessage = document.getElementById('sa-welcome-message');
        this.chatInput = document.getElementById('smart-assistant-chat-input');
        this.chatInputBar = document.getElementById('sa-chat-input-bar');
        this.selectedErrorTag = null;

        // Error Monitoring
        this.previousErrors = new Set();
        this.errorCheckInterval = null;

        // Flag to keep input enabled at all times after first interaction
        this.keepInputActive = false;

        // Widget settings from the view; null when an older published view rendered the page
        const widgetConfig = window.SmartAssistantConfig?.widget || null;
        this.features = Object.assign(
            { page_scan: true, bootstrap_modal_compat: true },
            widgetConfig?.features || {}
        );
        this.pageScan = widgetConfig?.page_scan || UIManager.LEGACY_PAGE_SCAN;

        // Optional workarounds for Bootstrap modals and input-disabling pages
        if (window.SmartAssistantHostCompat) {
            window.SmartAssistantHostCompat.install(this);
        } else if (!widgetConfig) {
            // Older published views always had the workarounds; keep them
            UIManager.loadHostCompat();
        }

        // Setup proactive error monitoring
        if (this.features.page_scan) {
            this.setupErrorMonitoring();
        }

        this.initEventListeners();

        // Initialize Notification Elements
        this.createNotificationElements();
    }

    /**
     * Create the localized notification elements (Badge and Toast)
     */
    createNotificationElements() {
        if (!this.toggleBtn) return;

        // Create Badge
        this.badge = document.createElement('div');
        this.badge.className = 'sa-notification-badge';
        this.toggleBtn.appendChild(this.badge);

        // Create Notification Popup Card
        this.notificationPopup = document.createElement('div');
        this.notificationPopup.className = 'sa-notification-popup';
        this.notificationPopup.innerHTML = '<span>⚠️ Issues detected - Click to view</span>';
        this.notificationPopup.style.cursor = 'pointer';
        this.notificationPopup.addEventListener('click', () => this.togglePanel());

        // Create Toast
        this.toast = document.createElement('div');
        this.toast.className = 'sa-notification-toast';
        this.toast.innerHTML = '<span>⚠️ Issues found</span>';

        // Append to the widget container
        const widget = document.getElementById('smart-assistant-widget');
        if (widget) {
            widget.appendChild(this.notificationPopup);
            widget.appendChild(this.toast);
        }
    }


    /**
     * Force enable the input without any checks
     */
    forceEnableInput() {
        const chatInput = document.getElementById('smart-assistant-chat-input');
        const sendBtn = document.getElementById('sa-send-btn');
        const chatInputBar = document.getElementById('sa-chat-input-bar');

        if (chatInput) {
            chatInput.disabled = false;
            chatInput.readOnly = false;
            chatInput.removeAttribute('disabled');
            chatInput.removeAttribute('readonly');
            chatInput.style.pointerEvents = 'auto';
            chatInput.style.opacity = '1';
            chatInput.style.cursor = 'text';
        }

        if (sendBtn) {
            sendBtn.disabled = false;
            sendBtn.removeAttribute('disabled');
            sendBtn.style.pointerEvents = 'auto';
            sendBtn.style.opacity = '1';
        }

        if (chatInputBar) {
            chatInputBar.style.display = 'flex';
            chatInputBar.style.pointerEvents = 'auto';
        }
    }

    initEventListeners() {
        if (this.toggleBtn) {
            this.toggleBtn.addEventListener('click', () => this.togglePanel());
        }

        if (this.closeBtn) {
            this.closeBtn.addEventListener('click', () => this.closePanel());
        }
    }

    togglePanel() {
        if (this.panel.classList.contains('sa-panel-open')) {
            this.closePanel();
        } else {
            this.openPanel();
        }
    }

    openPanel() {
        console.log('Smart Assistant: Opening panel...');

        // Safety: Remove any stuck overlays from previous screenshots
        document.querySelectorAll('.sa-selection-overlay').forEach(el => el.remove());

        // Ask for notification permission on first interaction
        if ("Notification" in window && Notification.permission !== "granted" && Notification.permission !== "denied") {
            Notification.requestPermission();
        }

        this.panel.classList.add('sa-panel-open');

        // Clear visual notifications when panel is opened
        this.clearVisualNotifications();

        this.showWelcomeMessage();

        // Ensure input is enabled immediately
        this.forceEnableInput();

        // Scan for errors after a brief delay and ensure focus
        setTimeout(() => {
            this.scanForErrors();

            // Explicitly focus the chat input
            if (this.chatInput) {
                console.log('Smart Assistant: Forcing focus on input...');
                this.chatInput.focus();

                // Double check active element
                if (document.activeElement !== this.chatInput) {
                    console.warn('Smart Assistant: Input failed to focus. Active element:', document.activeElement);
                    this.chatInput.focus();
                }
            }
        }, 300);
    }

    closePanel() {
        this.panel.classList.remove('sa-panel-open');
        this.resetUI();
    }

    showWelcomeMessage() {
        if (this.welcomeMessage) {
            this.welcomeMessage.style.display = 'block';
        }
        this.clearChatMessages();
        this.clearErrorTags();
        this.setStatus('Scanning for issues...');
    }

    /**
     * Find error messages on the page and offer them as tags.
     */
    scanForErrors() {
        if (!this.features.page_scan) {
            this.setStatus('How can I help you today?');
            this.showChatInput();
            return;
        }

        const foundErrors = this.collectPageErrors();

        // ---------------------------------------------------------
        // ALERT SYSTEM LOGIC
        // ---------------------------------------------------------

        // Identify NEW errors by checking against previous set
        const newDetectedErrors = [];
        const currentErrorSet = new Set(foundErrors);

        foundErrors.forEach(errorText => {
            if (!this.previousErrors.has(errorText)) {
                newDetectedErrors.push(errorText);
            }
        });

        // Update previous errors state
        this.previousErrors = currentErrorSet;

        // Trigger Alert if new errors found
        if (newDetectedErrors.length > 0) {
            console.log(`Smart Assistant: ${newDetectedErrors.length} new error(s) detected.`, newDetectedErrors);

            // 1. Play Sound
            this.playAlertSound();

            // 2. Show Notification (Classic Method)
            this.showSystemNotification(newDetectedErrors.length);

            // 3. Show Visual Cues (Badge + Toast) - ONLY if panel is closed
            if (!this.panel.classList.contains('sa-panel-open')) {
                this.updateVisualNotifications(newDetectedErrors.length, foundErrors.length);
            }
        }

        // ---------------------------------------------------------

        if (foundErrors.length > 0) {
            this.renderErrorTags(foundErrors);

            // Ensure badge is correct if we missed an update
            if (!this.panel.classList.contains('sa-panel-open')) {
                this.updateBadge(foundErrors.length);
            }
        } else {
            this.clearVisualNotifications();
            this.setStatus('How can I help you today?');
        }

        // Always show chat input
        this.showChatInput();
    }

    /**
     * Error texts on the page, by the widget.page_scan rules: ids and
     * selectors always count; soft selectors only when no ignore rule applies.
     * @returns {string[]} Unique texts in page order
     */
    collectPageErrors() {
        const rules = this.pageScan || {};
        const found = [];
        const widget = document.getElementById('smart-assistant-widget');

        const isOurs = (el) => (widget && widget.contains(el)) || el.closest('[data-sa-ignore]');
        const addText = (text) => {
            if (!found.includes(text)) found.push(text);
        };

        // Level 1 and 2: explicit error ids, then containers that always hold errors
        const certain = [
            ...(rules.ids || []).map(id => document.getElementById(id)).filter(Boolean),
            ...this.queryAll(rules.selectors)
        ];
        certain.forEach(el => {
            if (isOurs(el)) return;
            const text = el.innerText?.trim();
            if (text && text.length > 3 && text.length < 300) addText(text);
        });

        // Level 3: soft containers (e.g. .text-danger) with filtering
        const ignoreClasses = rules.ignore_classes || [];
        const ignoreIds = rules.ignore_ids || [];
        const ignorePatterns = (rules.ignore_patterns || []).map(pattern => {
            try {
                return new RegExp(pattern, 'i');
            } catch (e) {
                console.warn('Smart Assistant: invalid page_scan ignore pattern', pattern);
                return null;
            }
        }).filter(Boolean);

        this.queryAll(rules.soft_selectors).forEach(el => {
            if (isOurs(el)) return;
            // Marked as placeholder by the page
            if (el.getAttribute('data-error-type') === 'placeholder') return;
            // Form validation messages and other known non-error classes
            if (ignoreClasses.some(name => el.classList.contains(name))) return;
            // Inside a form: most likely a validation message
            if (el.closest('form')) return;
            if (el.id && ignoreIds.includes(el.id)) return;

            const text = el.innerText?.trim();
            if (!text || text.length < 3 || text.length > 300) return;
            // Labels and placeholders ("Loading...", "Please wait")
            if (ignorePatterns.some(re => re.test(text))) return;

            addText(text);
        });

        return found;
    }

    queryAll(selectors) {
        const elements = [];
        (selectors || []).forEach(selector => {
            try {
                elements.push(...document.querySelectorAll(selector));
            } catch (e) {
                console.warn('Smart Assistant: invalid page_scan selector', selector);
            }
        });
        return elements;
    }

    setStatus(text) {
        if (this.statusText) {
            this.statusText.textContent = text;
        }
    }

    clearErrorTags() {
        if (this.errorTagsContainer) {
            this.errorTagsContainer.innerHTML = '';
            this.errorTagsContainer.style.display = 'none';
        }
        this.selectedErrorTag = null;
    }

    renderErrorTags(errors) {
        if (!this.errorTagsContainer || errors.length === 0) return;

        this.errorTagsContainer.innerHTML = '';
        this.errorTagsContainer.style.display = 'flex';

        errors.forEach((errorText, index) => {
            const tag = document.createElement('div');
            tag.className = 'sa-error-tag';
            tag.textContent = errorText.length > 100 ? errorText.substring(0, 100) + '...' : errorText;
            tag.dataset.errorIndex = index;
            tag.dataset.errorText = errorText;
            tag.title = errorText; // Show full text on hover

            tag.addEventListener('click', () => this.handleErrorTagClick(tag, errorText));

            this.errorTagsContainer.appendChild(tag);
        });

        this.setStatus(`Found ${errors.length} issue${errors.length > 1 ? 's' : ''} - Click for help`);
    }

    handleErrorTagClick(tagElement, errorText) {
        // Deselect previous tag
        if (this.selectedErrorTag) {
            this.selectedErrorTag.classList.remove('sa-error-tag-selected');
        }

        // Select new tag
        tagElement.classList.add('sa-error-tag-selected');
        this.selectedErrorTag = tagElement;

        // Hide welcome message
        if (this.welcomeMessage) {
            this.welcomeMessage.style.display = 'none';
        }

        // Show chat input and ensure it's enabled
        this.showChatInput();
        this.enableChatInput();

        // Send error to API
        if (window.smartAssistant) {
            window.smartAssistant.handleErrorQuery(errorText);
        }
    }

    showChatInput() {
        if (this.chatInputBar) {
            this.chatInputBar.style.display = 'flex';
        }
    }

    enableChatInput() {
        // Set flag to keep input always active after first enable
        this.keepInputActive = true;

        // Use the force enable method
        this.forceEnableInput();
    }

    clearChatMessages() {
        if (this.chatContainer) {
            this.chatContainer.innerHTML = '';
        }
    }

    /**
     * Add a plain-text chat message. The text is never parsed as HTML;
     * line breaks are preserved.
     */
    addChatMessage(message, isUser = false, isError = false) {
        this.appendChatBubble(isUser, isError, (bubble) => this.appendText(bubble, message));
    }

    /**
     * Add an assistant message with basic formatting: **bold** and line breaks.
     * Everything else is rendered as text, so server content cannot inject HTML.
     */
    addFormattedMessage(message, isError = false) {
        this.appendChatBubble(false, isError, (bubble) => this.appendFormattedText(bubble, message));
    }

    appendText(parent, text) {
        String(text ?? '').split('\n').forEach((line, index) => {
            if (index > 0) parent.appendChild(document.createElement('br'));
            if (line) parent.appendChild(document.createTextNode(line));
        });
    }

    appendFormattedText(parent, text) {
        String(text ?? '').split(/(\*\*[^*\n]+\*\*)/).forEach(part => {
            const bold = part.match(/^\*\*([^*\n]+)\*\*$/);
            if (bold) {
                const strong = document.createElement('strong');
                strong.textContent = bold[1];
                parent.appendChild(strong);
            } else {
                this.appendText(parent, part);
            }
        });
    }

    appendChatBubble(isUser, isError, fillBubble) {
        if (!this.chatContainer) return;

        const messageDiv = document.createElement('div');
        messageDiv.className = `sa-chat-message ${isUser ? 'sa-chat-user' : 'sa-chat-assistant'}`;

        if (isError) {
            messageDiv.classList.add('sa-chat-error');
        }

        const bubble = document.createElement('div');
        bubble.className = 'sa-chat-bubble';
        fillBubble(bubble);

        messageDiv.appendChild(bubble);
        this.chatContainer.appendChild(messageDiv);

        // Auto-scroll to bottom
        this.scrollChatToBottom();

        // Ensure chat input is enabled after showing message
        // Call multiple times to override any external disabling
        this.enableChatInput();
        setTimeout(() => this.enableChatInput(), 100);
        setTimeout(() => this.enableChatInput(), 300);

        return bubble;
    }

    /**
     * Add an assistant message with buttons under the text.
     * @param {string} message - Plain text
     * @param {Array<{label: string, secondary?: boolean, onClick: Function}>} buttons
     * @returns {HTMLElement|undefined} The bubble, so the caller can replace its content
     */
    addActionMessage(message, buttons) {
        return this.appendChatBubble(false, false, (bubble) => this.fillActionBubble(bubble, message, buttons));
    }

    fillActionBubble(bubble, message, buttons) {
        bubble.replaceChildren();
        this.appendText(bubble, message);

        const row = document.createElement('div');
        row.className = 'sa-chat-actions';

        buttons.forEach(({ label, secondary, onClick }) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = secondary ? 'sa-action-btn sa-action-btn-secondary' : 'sa-action-btn';
            button.textContent = label;
            button.addEventListener('click', () => {
                // One click per button set: stops double submissions
                row.querySelectorAll('button').forEach(b => { b.disabled = true; });
                onClick();
            });
            row.appendChild(button);
        });

        bubble.appendChild(row);
        this.scrollChatToBottom();
    }

    /**
     * Disable the buttons of earlier action messages (they refer to an older
     * point in the conversation).
     */
    disableActionMessages() {
        this.chatContainer?.querySelectorAll('.sa-chat-actions button').forEach(b => { b.disabled = true; });
    }

    scrollChatToBottom() {
        if (this.chatContainer) {
            // Get the parent scrollable container (.sa-content)
            const contentArea = this.chatContainer.closest('.sa-content') || this.chatContainer.parentElement;
            if (contentArea) {
                setTimeout(() => {
                    contentArea.scrollTo({
                        top: contentArea.scrollHeight,
                        behavior: 'smooth'
                    });
                }, 50);
            }
        }
    }

    showTypingIndicator() {
        if (!this.chatContainer) return;

        const typingDiv = document.createElement('div');
        typingDiv.className = 'sa-chat-message sa-chat-assistant';
        typingDiv.id = 'sa-typing-indicator';

        const bubble = document.createElement('div');
        bubble.className = 'sa-chat-bubble sa-typing';
        bubble.innerHTML = '<span></span><span></span><span></span>';

        typingDiv.appendChild(bubble);
        this.chatContainer.appendChild(typingDiv);

        this.scrollChatToBottom();
    }

    hideTypingIndicator() {
        const typingIndicator = document.getElementById('sa-typing-indicator');
        if (typingIndicator) {
            typingIndicator.remove();
        }

        // Ensure chat input is enabled - call multiple times with delays
        // to override any external code that might disable it
        this.enableChatInput();
        setTimeout(() => this.enableChatInput(), 100);
        setTimeout(() => this.enableChatInput(), 300);
        setTimeout(() => this.enableChatInput(), 500);
    }

    resetUI() {
        this.clearChatMessages();
        this.clearErrorTags();
        this.selectedErrorTag = null;
        this.keepInputActive = false; // Reset flag on UI reset
        if (this.welcomeMessage) {
            this.welcomeMessage.style.display = 'block';
        }
        this.setStatus('');
    }

    showNetworkError() {
        this.addChatMessage(
            '⚠️ Network issue detected. Please check your connection and try again.',
            false,
            true
        );
    }

    showParseError() {
        this.addChatMessage(
            '⚠️ I couldn\'t understand the response. Please try again.',
            false,
            true
        );
    }

    /**
     * Setup continuous monitoring for errors on the page
     */
    setupErrorMonitoring() {
        // Only start monitoring after page is fully loaded to avoid catching temporary placeholder elements
        const startMonitoring = () => {
            // Use MutationObserver to detect DOM changes that might be errors
            const observer = new MutationObserver((mutations) => {
                let shouldScan = false;

                mutations.forEach(mutation => {
                    if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                        shouldScan = true;
                    } else if (mutation.type === 'attributes' && (mutation.attributeName === 'class' || mutation.attributeName === 'style')) {
                        shouldScan = true;
                    }
                });

                if (shouldScan) {
                    // Debounce scan
                    if (this.errorCheckInterval) clearTimeout(this.errorCheckInterval);
                    this.errorCheckInterval = setTimeout(() => this.scanForErrors(), 1000);
                }
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['class', 'style']
            });
        };

        // Wait for page load, then start monitoring
        if (document.readyState === 'complete') {
            startMonitoring();
        } else {
            window.addEventListener('load', startMonitoring);
        }
    }

    /**
     * Play a classic alert sound (Beep)
     */
    playAlertSound() {
        try {
            // Simple beep sound (Base64 encoded WAV)
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.value = 880; // A5
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);

            oscillator.start();

            // Fade out
            gainNode.gain.setValueAtTime(0.1, audioCtx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.00001, audioCtx.currentTime + 0.5);

            oscillator.stop(audioCtx.currentTime + 0.5);

        } catch (e) {
            console.warn('AudioContext not supported or failed', e);
        }
    }

    /**
     * Show a system notification
     * @param {number} count Number of errors
     */
    showSystemNotification(count) {
        const title = 'Action Required';
        const message = `${count} error${count > 1 ? 's were' : ' was'} detected`;

        // Check if browser supports notifications
        if (!("Notification" in window)) {
            return;
        }

        // Check permission
        if (Notification.permission === "granted") {
            try {
                new Notification(title, {
                    body: message,
                    icon: '/favicon.ico', // Optional: try to use favicon
                    silent: true // We play our own sound
                });
            } catch (e) {
                console.error("Notification failed", e);
            }
        }
        // Note: we don't ask for permission here to avoid spamming. 
        // Permission is requested in openPanel.
    }
    /**
     * Update visual notifications (Badge, Pulse, Toast)
     */
    updateVisualNotifications(newCount, totalCount) {
        // Update Badge
        this.updateBadge(totalCount);

        // Add pulse animation
        if (this.toggleBtn) {
            this.toggleBtn.classList.add('sa-pulse-animation');
        }

        // Show Notification Popup only
        if (this.notificationPopup) {
            this.notificationPopup.innerHTML = `<span>⚠️ ${totalCount} issue${totalCount > 1 ? 's' : ''} detected - Click to view</span>`;
            this.notificationPopup.classList.add('sa-notification-popup-visible');

            // Auto-hide after 6 seconds
            if (this.notificationPopupTimeout) clearTimeout(this.notificationPopupTimeout);
            this.notificationPopupTimeout = setTimeout(() => {
                this.notificationPopup.classList.remove('sa-notification-popup-visible');
            }, 6000);
        }
    }

    updateBadge(count) {
        if (this.badge) {
            if (count > 0) {
                this.badge.textContent = count > 9 ? '9+' : count;
                this.badge.classList.add('sa-badge-visible');
            } else {
                this.badge.classList.remove('sa-badge-visible');
            }
        }
    }

    clearVisualNotifications() {
        // Clear Badge
        if (this.badge) {
            this.badge.classList.remove('sa-badge-visible');
        }

        // Remove Pulse
        if (this.toggleBtn) {
            this.toggleBtn.classList.remove('sa-pulse-animation');
        }

        // Hide Notification Popup
        if (this.notificationPopup) {
            this.notificationPopup.classList.remove('sa-notification-popup-visible');
        }
    }
}

// legacy-host-start
/**
 * The scan rules built into releases before the page scan was configurable,
 * used only for pages rendered by an older published view (no widget config).
 * Remove together with the other legacy-host blocks.
 */
UIManager.LEGACY_PAGE_SCAN = {
    ids: ['modal_error', 'modal_status_message', 'error-display'],
    selectors: ['.alert-danger'],
    soft_selectors: ['.text-danger'],
    ignore_classes: ['invalid-feedback', 'help-block', 'loader-text', 'loading-message', 'placeholder', 'responseMessage'],
    ignore_ids: ['status', 'loading-message', 'loader-text', 'message'],
    ignore_patterns: [
        '^(transaction\\s+status|loading\\.*|please\\s+wait|processing|capturing|fingerprint|balance|withdrawal|deposit|statement|abbreviation|your\\s+device)$'
    ]
};
// legacy-host-end

// Export for use in main script
window.UIManager = UIManager;
