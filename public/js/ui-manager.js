/**
 * UI Manager - Handles all UI interactions and rendering
 * 
 * Error Scanning:
 * - Ignores .invalid-feedback (form validation messages)
 * - Shows all other error messages (alert-danger, text-danger, modal errors)
 */
class UIManager {
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

        // Setup input protection observer
        this.setupInputProtection();

        // Setup proactive error monitoring
        this.setupErrorMonitoring();

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

        // Create Toast
        this.toast = document.createElement('div');
        this.toast.className = 'sa-notification-toast';
        this.toast.innerHTML = '<span>⚠️ Issues found</span>';

        // Append toast to the widget container so it's positioned relative to it
        const widget = document.getElementById('smart-assistant-widget');
        if (widget) {
            widget.appendChild(this.toast);
        }
    }

    /**
     * Setup MutationObserver to protect chat input from being disabled
     * This ensures the input always stays enabled after user interaction
     */
    setupInputProtection() {
        const chatInput = document.getElementById('smart-assistant-chat-input');
        const sendBtn = document.getElementById('sa-send-btn');

        if (chatInput) {
            // Observer for chat input
            const inputObserver = new MutationObserver((mutations) => {
                if (this.keepInputActive) {
                    mutations.forEach(mutation => {
                        if (mutation.type === 'attributes') {
                            if (mutation.attributeName === 'disabled' ||
                                mutation.attributeName === 'readonly') {
                                // Re-enable immediately if disabled
                                if (chatInput.disabled || chatInput.readOnly) {
                                    this.forceEnableInput();
                                }
                            }
                        }
                    });
                }
            });

            inputObserver.observe(chatInput, {
                attributes: true,
                attributeFilter: ['disabled', 'readonly']
            });
        }

        if (sendBtn) {
            // Observer for send button
            const btnObserver = new MutationObserver((mutations) => {
                if (this.keepInputActive) {
                    mutations.forEach(mutation => {
                        if (mutation.type === 'attributes' &&
                            mutation.attributeName === 'disabled') {
                            if (sendBtn.disabled) {
                                sendBtn.disabled = false;
                                sendBtn.removeAttribute('disabled');
                            }
                        }
                    });
                }
            });

            btnObserver.observe(sendBtn, {
                attributes: true,
                attributeFilter: ['disabled']
            });
        }

        // Also set up an interval to periodically check and re-enable
        setInterval(() => {
            if (this.keepInputActive && this.panel?.classList.contains('sa-panel-open')) {
                this.forceEnableInput();
            }
        }, 1000);

        // Handle Bootstrap modal focus trap
        // Bootstrap modals trap focus inside them, preventing interaction with elements outside
        // We need to bypass this for our assistant widget
        this.setupModalFocusBypass();
    }

    /**
     * Bypass Bootstrap modal focus trapping for the assistant widget
     * This allows the chat input to receive focus even when a modal is open
     */
    setupModalFocusBypass() {
        const widget = document.getElementById('smart-assistant-widget');
        const chatInput = document.getElementById('smart-assistant-chat-input');
        if (!widget) return;

        // Stop Bootstrap from intercepting focus events on our widget
        widget.addEventListener('focusin', (e) => {
            e.stopPropagation();
            e.stopImmediatePropagation();
        }, true);

        widget.addEventListener('focus', (e) => {
            e.stopPropagation();
            e.stopImmediatePropagation();
        }, true);

        // Handle click events - ensure they work inside our widget when modal is open
        widget.addEventListener('mousedown', (e) => {
            e.stopPropagation();
            e.stopImmediatePropagation();

            // If clicking on the input, directly focus it
            if (e.target.id === 'smart-assistant-chat-input' ||
                e.target.closest('#smart-assistant-chat-input')) {
                setTimeout(() => {
                    const input = document.getElementById('smart-assistant-chat-input');
                    if (input) {
                        input.focus();
                        this.forceEnableInput();
                    }
                }, 0);
            }
        }, true);

        // Prevent Bootstrap's focusout handler from taking focus away
        widget.addEventListener('focusout', (e) => {
            if (this.panel?.classList.contains('sa-panel-open')) {
                e.stopPropagation();
                e.stopImmediatePropagation();
            }
        }, true);

        // SPECIFIC TEXTAREA HANDLING
        // Textareas need special handling because they require establishing a text cursor
        if (chatInput) {
            // Prevent all focus-related events from bubbling on the textarea
            ['focus', 'focusin', 'click', 'mousedown', 'mouseup', 'touchstart', 'touchend'].forEach(eventType => {
                chatInput.addEventListener(eventType, (e) => {
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                }, true);
            });

            // Aggressive mousedown handler to prevent focus stealing
            chatInput.addEventListener('mousedown', (e) => {
                e.stopPropagation();
                // We don't preventDefault here because we want the text cursor to be placed normally
                // But we aggressively ensure focus is ours

                setTimeout(() => {
                    this.forceEnableInput();
                    chatInput.focus();
                }, 0);
                setTimeout(() => chatInput.focus(), 50);
            }, true);

            // When clicking the textarea, aggressively maintain focus
            chatInput.addEventListener('click', (e) => {
                e.stopPropagation();
                e.stopImmediatePropagation();

                // Force focus back after any potential Bootstrap interference
                setTimeout(() => chatInput.focus(), 0);
            });

            // When textarea receives focus, prevent Bootstrap from stealing it
            chatInput.addEventListener('focus', (e) => {
                e.stopPropagation();
                e.stopImmediatePropagation();

                // Add a temporary listener to block any blur attempts
                const preventBlur = (blurEvent) => {
                    // Check if blur is going to somewhere outside our widget
                    const relatedTarget = blurEvent.relatedTarget;
                    const widget = document.getElementById('smart-assistant-widget');

                    // If focus is leaving to a modal element, prevent it
                    if (!relatedTarget ||
                        (relatedTarget && relatedTarget.closest('.modal') &&
                            widget && !widget.contains(relatedTarget))) {
                        blurEvent.preventDefault();
                        blurEvent.stopImmediatePropagation();
                        setTimeout(() => chatInput.focus(), 0);
                    }
                };

                chatInput.addEventListener('blur', preventBlur, { once: true, capture: true });

                // Remove this listener after a short delay to prevent memory issues
                setTimeout(() => {
                    chatInput.removeEventListener('blur', preventBlur, { capture: true });
                }, 100);
            });

            // Add keyboard event handling so typing works
            chatInput.addEventListener('keydown', (e) => {
                e.stopPropagation();
            }, true);

            chatInput.addEventListener('keyup', (e) => {
                e.stopPropagation();
            }, true);

            chatInput.addEventListener('keypress', (e) => {
                e.stopPropagation();
            }, true);

            chatInput.addEventListener('input', (e) => {
                e.stopPropagation();
            }, true);
        }

        // Override Bootstrap's enforceFocus if it exists
        // This runs after page load to catch dynamically created modals
        setTimeout(() => {
            this.disableBootstrapFocusTrap();
        }, 1000);

        // Also watch for new modals being shown
        document.addEventListener('shown.bs.modal', () => {
            this.disableBootstrapFocusTrap();
        });
    }

    /**
     * Disable Bootstrap's focus trap when our widget is active
     */
    /**
     * Disable Bootstrap's focus trap when our widget is active
     */
    disableBootstrapFocusTrap() {
        const widget = document.getElementById('smart-assistant-widget');

        // STRATEGY 1: Bootstrap 4 Global Prototype Patch
        // This fixes it for ALL modals, present and future
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal && window.jQuery.fn.modal.Constructor) {
            const Constructor = window.jQuery.fn.modal.Constructor;

            // Check if we already patted it
            if (!Constructor.prototype.saPatched) {
                const originalEnforceFocus = Constructor.prototype._enforceFocus;

                Constructor.prototype._enforceFocus = function () {
                    // This is the Bootstrap 4 logic, modified to allow our widget
                    const $ = window.jQuery;
                    const that = this;
                    $(document)
                        .off('focusin.bs.modal') // Turn off existing
                        .on('focusin.bs.modal', function (e) {
                            if (
                                document === e.target ||
                                that._element === e.target ||
                                $(that._element).has(e.target).length ||
                                // OUR FIX: Allow focus if it's inside our widget
                                (widget && widget.contains(e.target))
                            ) {
                                return;
                            }
                            that._element.focus();
                        });
                };
                Constructor.prototype.saPatched = true;
                console.log('Smart Assistant: Applied global Bootstrap 4 focus fix');
            }
        }

        // STRATEGY 2: Bootstrap 5 Instance Patching
        // We have to do this per-instance as they are created
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            const bsModal = window.bootstrap?.Modal?.getInstance(modal);
            if (bsModal && bsModal._focustrap && !bsModal._focustrap.saPatched) {
                const originalTrap = bsModal._focustrap._handleFocusin;
                bsModal._focustrap._handleFocusin = (event) => {
                    if (widget && widget.contains(event.target)) {
                        return;
                    }
                    if (originalTrap) originalTrap.call(bsModal._focustrap, event);
                };
                bsModal._focustrap.saPatched = true;
                console.log('Smart Assistant: Patched Bootstrap 5 modal instance');
            }
        });

        // STRATEGY 3: jQuery Instance Patching (Fallback for BS4 instances already created)
        if (window.jQuery) {
            const $ = window.jQuery;
            $('.modal.show').each(function () {
                const modalData = $(this).data('bs.modal');
                if (modalData && !modalData.saPatched) {
                    const originalEnforceFocus = modalData._enforceFocus;
                    modalData._enforceFocus = function () {
                        $(document)
                            .off('focusin.bs.modal')
                            .on('focusin.bs.modal', (e) => {
                                if (widget && widget.contains(e.target)) return;
                                if (this._element !== e.target && !$(this._element).has(e.target).length) {
                                    this._element.focus();
                                }
                            });
                    };
                    // Re-run it to apply the new handler
                    modalData._enforceFocus();
                    modalData.saPatched = true;
                }
            });
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

    // Scan for errors - IGNORES .invalid-feedback
    scanForErrors() {
        // Selectors to scan (EXCLUDING .invalid-feedback)
        const errorSelectors = [
            '.alert-danger',
            '.text-danger:not(.invalid-feedback)',  // Exclude invalid-feedback
            '.smart-error',
            '#modal_status_message',
            '.error-message',
            '.alert-warning'
        ];

        const foundErrors = [];
        const seenErrors = new Set();

        errorSelectors.forEach(selector => {
            try {
                const elements = document.querySelectorAll(selector);
                elements.forEach(el => {
                    // Skip if element is inside a form (likely validation message)
                    if (el.closest('form') && el.classList.contains('text-danger')) {
                        return;
                    }

                    // Skip .invalid-feedback elements
                    if (el.classList.contains('invalid-feedback')) {
                        return;
                    }

                    const errorText = el.innerText.trim();

                    // Valid error: non-empty, reasonable length, not duplicate
                    if (errorText &&
                        errorText.length > 3 &&
                        errorText.length < 300 &&
                        !seenErrors.has(errorText)) {
                        seenErrors.add(errorText);
                        foundErrors.push(errorText);
                    }
                });
            } catch (e) {
                console.warn('Error scanning selector:', selector, e);
            }
        });

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
            console.log(`Smart Assistant: ${newDetectedErrors.length} new error(s) detected.`);

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

    addChatMessage(message, isUser = false, isError = false) {
        if (!this.chatContainer) return;

        const messageDiv = document.createElement('div');
        messageDiv.className = `sa-chat-message ${isUser ? 'sa-chat-user' : 'sa-chat-assistant'}`;

        if (isError) {
            messageDiv.classList.add('sa-chat-error');
        }

        const bubble = document.createElement('div');
        bubble.className = 'sa-chat-bubble';
        bubble.innerHTML = message;

        messageDiv.appendChild(bubble);
        this.chatContainer.appendChild(messageDiv);

        // Auto-scroll to bottom
        this.scrollChatToBottom();

        // Ensure chat input is enabled after showing message
        // Call multiple times to override any external disabling
        this.enableChatInput();
        setTimeout(() => this.enableChatInput(), 100);
        setTimeout(() => this.enableChatInput(), 300);
    }

    scrollChatToBottom() {
        if (this.chatContainer) {
            this.chatContainer.scrollTop = this.chatContainer.scrollHeight;
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

        // Show Toast
        if (this.toast) {
            this.toast.innerHTML = `<span>⚠️ ${newCount} new issue${newCount > 1 ? 's' : ''} found</span>`;
            this.toast.classList.add('sa-toast-visible');

            // Hide toast after 5 seconds
            if (this.notificationToastTimeout) clearTimeout(this.notificationToastTimeout);
            this.notificationToastTimeout = setTimeout(() => {
                this.toast.classList.remove('sa-toast-visible');
            }, 5000);
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

        // Hide Toast
        if (this.toast) {
            this.toast.classList.remove('sa-toast-visible');
        }
    }
}

// Export for use in main script
window.UIManager = UIManager;
