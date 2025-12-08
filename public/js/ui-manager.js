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

        // Flag to keep input enabled at all times after first interaction
        this.keepInputActive = false;

        // Setup input protection observer
        this.setupInputProtection();

        this.initEventListeners();
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

            // When clicking the textarea, aggressively maintain focus
            chatInput.addEventListener('click', (e) => {
                e.stopPropagation();
                e.stopImmediatePropagation();

                // Force focus back after any potential Bootstrap interference
                setTimeout(() => chatInput.focus(), 0);
                setTimeout(() => chatInput.focus(), 10);
                setTimeout(() => chatInput.focus(), 50);
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
    disableBootstrapFocusTrap() {
        // For Bootstrap 5
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            // Get Bootstrap modal instance
            const bsModal = window.bootstrap?.Modal?.getInstance(modal);
            if (bsModal && bsModal._focustrap) {
                // Store original handler
                const originalTrap = bsModal._focustrap._handleFocusin;

                // Override to allow focus in our widget
                bsModal._focustrap._handleFocusin = (event) => {
                    const widget = document.getElementById('smart-assistant-widget');
                    if (widget && widget.contains(event.target)) {
                        // Allow focus in our widget
                        return;
                    }
                    // Otherwise use original behavior
                    if (originalTrap) {
                        originalTrap.call(bsModal._focustrap, event);
                    }
                };
            }
        });

        // For Bootstrap 4 (jQuery-based)
        if (window.jQuery) {
            const $ = window.jQuery;
            $('.modal.show').each(function () {
                const modal = $(this).data('bs.modal');
                if (modal) {
                    // Override enforceFocus for Bootstrap 4
                    modal._enforceFocus = function () {
                        $(document)
                            .off('focusin.bs.modal')
                            .on('focusin.bs.modal', (e) => {
                                const widget = document.getElementById('smart-assistant-widget');
                                if (widget && widget.contains(e.target)) {
                                    return; // Allow focus in our widget
                                }
                                if (this._element !== e.target &&
                                    !$(this._element).has(e.target).length) {
                                    this._element.focus();
                                }
                            });
                    };
                    modal._enforceFocus();
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
        this.panel.classList.add('sa-panel-open');
        this.showWelcomeMessage();

        // Scan for errors after a brief delay
        setTimeout(() => {
            this.scanForErrors();
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

        if (foundErrors.length > 0) {
            this.renderErrorTags(foundErrors);
        } else {
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
}

// Export for use in main script
window.UIManager = UIManager;
