/**
 * Smart Assistant - Main Controller
 * Coordinates UI, API, and File Preview managers
 */
class SmartAssistant {
    constructor() {
        this.uiManager = new UIManager();
        this.apiManager = new APIManager();
        this.filePreviewManager = new FilePreviewManager();

        this.chatInput = document.getElementById('smart-assistant-chat-input');
        this.sendBtn = document.getElementById('sa-send-btn');

        this.currentErrorContext = null;

        // Response loop prevention
        this.lastResponse = null;
        this.noiseCount = 0;
        this.maxNoiseResponses = 2; // After 2 noise responses, show exit message

        // legacy-host-start: pre-filter of the old flow (resolve_typed_messages off); the
        // backend classifier replaces it. Remove with the other legacy-host blocks.
        // Light input pre-processing patterns (non-authoritative - backend has final say)
        this.greetingPatterns = /^(hi|hello|hey|hii+|helo|hlo|namaste|namaskar|good\s*(morning|afternoon|evening|night|day)|sup|yo|wassup|howdy)[\s\!\.\?]*$/i;
        this.vaguePatterns = /^(help|help me|need help|i need help|issue|problem|error|not working|please help|support|assist)[\s\!\.\?]*$/i;
        // SPECIFIC noise patterns - only obvious junk/test inputs
        this.noisePatterns = /^(test|testing|abc|xyz|qwerty|asdf|jkl|lol|haha|dummy)[\s\!\.\?]*$/i;
        // Repeated characters (hhhhhh, !!!!, but not "hello" or short valid words)
        this.repeatedCharPattern = /^(.)\1{4,}[\s\!\.\?]*$/;
        // legacy-host-end

        this.initEventListeners();
    }

    initEventListeners() {
        // Starter questions under the welcome text (rendered only with resolve_typed_messages)
        document.querySelectorAll('#sa-suggestions .sa-suggestion').forEach(button => {
            button.addEventListener('click', () => this.handleSuggestion(button.dataset.send || button.textContent));
        });

        if (this.sendBtn) {
            this.sendBtn.addEventListener('click', () => this.handleSendMessage());
        }

        if (this.chatInput) {
            this.chatInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.handleSendMessage();
                }
            });

            // Auto-expand textarea
            this.chatInput.addEventListener('input', () => {
                this.chatInput.style.height = 'auto';
                this.chatInput.style.height = Math.min(this.chatInput.scrollHeight, 120) + 'px';
            });
        }
    }

    /**
     * Check if message is a cold/dummy query that shouldn't go to support
     * ONLY blocks obvious junk - legitimate short messages are allowed
     * @param {string} message - The message text to check
     * @returns {boolean} - True if it's obviously junk
     */
    isColdQuery(message) {
        const trimmed = message.trim();
        
        // Allow anything with attachments or longer messages
        if (trimmed.length > 50) return false;
        
        // Only block OBVIOUS junk patterns
        // Repeated characters: "hhhhhh", "!!!!", "?????"
        if (this.repeatedCharPattern.test(trimmed)) return true;
        
        // Known test/dummy keywords
        if (this.noisePatterns.test(trimmed)) return true;
        
        // Only numeric sequences: "123", "456", "789"
        if (/^[\d\s\-\.]+$/.test(trimmed)) return true;
        
        // Only punctuation: "!!!", "???", "..."
        if (/^[\s\!\?\.\,]+$/.test(trimmed)) return true;
        
        // Gibberish keyboard smashing (5+ consecutive non-vowel consonants)
        if (/[bcdfghjklmnpqrstvwxyz]{5,}/i.test(trimmed)) return true;
        
        return false;
    }

    // Note: Error scanning is now handled by MutationObserver in UIManager
    // This detects only NEW errors that appear after user activity

    async handleErrorQuery(errorText) {
        this.currentErrorContext = errorText;

        // Show user message
        this.uiManager.disableActionMessages();
        this.uiManager.addChatMessage(`Help me with: "${errorText}"`, true);

        // Show typing indicator
        this.uiManager.showTypingIndicator();
        this.uiManager.setStatus('Analyzing error...');

        const result = await this.apiManager.sendErrorQuery(errorText, window.location.href);

        this.uiManager.hideTypingIndicator();

        if (result.success && result.data) {
            const data = result.data;

            this.renderResponse(data);
            this.uiManager.setStatus('Ready to help');

            if (this.apiManager.resolveTypedMessages) {
                // The assistant decides when to offer a ticket
                this.renderActions(data.actions, { message: errorText, errorContext: null });
            } else if ((data.meta?.source ?? data.source) === 'unknown') {
                // If unknown error, suggest manual query
                setTimeout(() => {
                    this.uiManager.addChatMessage(
                        'If you need more help, feel free to type your question below or attach a screenshot.',
                        false
                    );
                }, 500);
            }
        } else {
            this.showAssistantError(result);
        }

        // IMPORTANT: Ensure chat input is enabled after response
        // Call multiple times to override any external disabling
        this.uiManager.enableChatInput();
        setTimeout(() => {
            this.uiManager.enableChatInput();
            if (this.chatInput) this.chatInput.focus();
        }, 100);
        setTimeout(() => {
            this.uiManager.enableChatInput();
            if (this.chatInput) this.chatInput.focus();
        }, 500);
    }

    /**
     * Show an assistant reply. Uses the response blocks when the server sends
     * them (protocol 1), else the legacy answer_en/answer_hi fields.
     * Server text may hold **bold**; it is never rendered as HTML.
     */
    renderResponse(data) {
        const headings = {
            en: '**💡 Solution:**',
            hi: '**🇮🇳 हिंदी में:**'
        };
        const sections = [];

        if (Array.isArray(data.blocks)) {
            data.blocks.forEach(block => {
                // Only known block types are shown; others are skipped
                if (block && block.type === 'text' && block.text) {
                    const heading = headings[block.locale];
                    sections.push(heading ? `${heading}\n${block.text}` : String(block.text));
                } else if (block && block.type === 'key_value' && Array.isArray(block.items)) {
                    // e.g. a transaction's status: one "Label: Value" line per item
                    const lines = block.items
                        .filter(item => item && item.label)
                        .map(item => `${item.label}: ${item.value ?? ''}`);
                    if (block.title) lines.unshift(`**${block.title}**`);
                    if (lines.length) sections.push(lines.join('\n'));
                }
            });
        } else {
            if (data.answer_en) sections.push(`${headings.en}\n${data.answer_en}`);
            if (data.answer_hi) sections.push(`${headings.hi}\n${data.answer_hi}`);
        }

        this.uiManager.addFormattedMessage(sections.length
            ? sections.join('\n\n')
            : 'I found information about this error, but couldn\'t format it properly. Please try rephrasing your question.');
    }

    showAssistantError(result) {
        if (result.error === 'network_error') {
            this.uiManager.showNetworkError();
            this.uiManager.setStatus('Network error');
        } else if (result.error === 'rate_limited') {
            this.uiManager.addChatMessage('⏳ Too many requests. Please wait a minute and try again.', false, true);
            this.uiManager.setStatus('Please wait');
        } else {
            this.uiManager.showParseError();
            this.uiManager.setStatus('Error occurred');
        }
    }

    /**
     * Show the actions of a response. Only actions with a handler here are
     * shown; the server can never make the widget run anything else.
     * @param {{message: string, errorContext: ?string}} pending - What a ticket would contain
     */
    renderActions(actions, pending) {
        const escalate = (Array.isArray(actions) ? actions : [])
            .find(action => action && action.type === 'action' && action.id === 'escalate');

        if (escalate) {
            this.offerEscalation(pending, escalate.label);
        }
    }

    /**
     * Offer a ticket. Nothing is sent until the user confirms what will be sent.
     */
    offerEscalation(pending, label = 'Raise ticket') {
        const bubble = this.uiManager.addActionMessage(
            'Still need help? Our support team can look into this.',
            [{ label: label || 'Raise ticket', onClick: () => this.confirmEscalation(bubble, pending) }]
        );
    }

    confirmEscalation(bubble, pending) {
        const files = this.filePreviewManager.getAttachments();
        const lines = ['This will be sent to our support team:'];

        if (pending.errorContext) lines.push(`• Error: ${pending.errorContext}`);
        if (pending.message) lines.push(`• Message: ${pending.message}`);
        if (files.length) lines.push(`• Attachments: ${files.length}`);

        this.uiManager.fillActionBubble(bubble, lines.join('\n'), [
            { label: 'Send to support', onClick: () => this.raiseTicket(pending) },
            {
                label: 'Cancel',
                secondary: true,
                onClick: () => this.uiManager.addChatMessage('Okay, nothing was sent.', false)
            }
        ]);
    }

    async raiseTicket(pending) {
        this.uiManager.showTypingIndicator();
        this.uiManager.setStatus('Sending...');

        const result = await this.apiManager.sendChatMessage(
            pending.message,
            this.filePreviewManager.getAttachments(),
            pending.errorContext
        );

        this.uiManager.hideTypingIndicator();
        this.showTicketResult(result);
        this.uiManager.enableChatInput();
    }

    /**
     * Typed message with resolve_typed_messages on: the assistant answers
     * first; a ticket is only offered, never created here.
     */
    /**
     * A starter question was picked: send it like a typed message.
     */
    handleSuggestion(text) {
        if (!text || !this.apiManager.resolveTypedMessages) return;

        if (this.uiManager.welcomeMessage) {
            this.uiManager.welcomeMessage.style.display = 'none';
        }

        return this.handleTypedMessage(String(text).trim(), [], 'suggestion');
    }

    /**
     * @param {string} source - typed | suggestion
     */
    async handleTypedMessage(message, attachments, source = 'typed') {
        this.uiManager.disableActionMessages();
        this.uiManager.addChatMessage(this.withAttachmentLabel(message), true);
        this.clearChatInput();

        const pending = { message: message || '', errorContext: this.currentErrorContext };

        // Files alone: nothing to answer, offer to send them to support
        if (!message) {
            this.offerEscalation(pending);
            return;
        }

        if (this.sendBtn) this.sendBtn.disabled = true;
        this.uiManager.showTypingIndicator();
        this.uiManager.setStatus('Thinking...');

        const result = await this.apiManager.sendMessage(message, source);

        this.uiManager.hideTypingIndicator();
        if (this.sendBtn) this.sendBtn.disabled = false;

        if (result.success && result.data) {
            const data = result.data;
            const offersTicket = (data.actions || []).some(action => action && action.id === 'escalate');

            this.renderResponse(data);
            this.renderActions(data.actions, pending);

            // Attached files are only sent with a ticket; say so if none was offered
            if (attachments.length && !offersTicket) {
                this.offerEscalation(pending, 'Send attachment to support');
            }

            this.uiManager.setStatus('Ready to help');
        } else {
            this.showAssistantError(result);
        }

        this.uiManager.enableChatInput();
        setTimeout(() => this.uiManager.enableChatInput(), 100);
    }

    withAttachmentLabel(message) {
        const types = [];
        if (this.filePreviewManager.getFile()) types.push('📎 File');
        if (this.filePreviewManager.getScreenshot()) types.push('📷 Screenshot');

        return types.length
            ? `${message || ''}\n[Attached: ${types.join(', ')}]`.trim()
            : (message || '');
    }

    clearChatInput() {
        if (this.chatInput) {
            this.chatInput.value = '';
            this.chatInput.style.height = 'auto';
        }
    }

    /**
     * Show what happened to a ticket request (either ticket endpoint).
     */
    showTicketResult(result) {
        if (result.success) {
            // Files went with the ticket; keep them on failure so the user can retry
            this.filePreviewManager.clearPreview();

            // 'reference' from the package endpoint, 'complaint_id' from the host ticket endpoint
            const reference = result.data?.reference || result.data?.complaint_id;
            this.uiManager.addChatMessage(
                `✅ ${result.message || 'Your message has been sent successfully. Our support team will get back to you soon.'}`
                    + (reference ? `\nReference: ${reference}` : ''),
                false
            );
            this.uiManager.setStatus('Message sent');

            // Clear error context after successful send
            this.currentErrorContext = null;
        } else {
            if (result.error === 'auth_required') {
                this.uiManager.addChatMessage(
                    '⚠️ Please log in to send a message.',
                    false,
                    true
                );
            } else if (result.error === 'network_error') {
                this.uiManager.showNetworkError();
            } else {
                this.uiManager.addChatMessage(
                    `⚠️ ${result.message || 'Failed to send message. Please try again.'}`,
                    false,
                    true
                );
            }
            this.uiManager.setStatus('Send failed');
        }
    }

    async handleSendMessage() {
        const message = this.chatInput?.value.trim();
        const attachments = this.filePreviewManager.getAttachments();

        if (!message && attachments.length === 0) {
            return;
        }

        // The assistant answers first; the checks below are only for the old flow
        if (this.apiManager.resolveTypedMessages) {
            return this.handleTypedMessage(message, attachments);
        }

        // =====================================================================
        // OLD FLOW (resolve_typed_messages off): every typed message that
        // passes these checks becomes a ticket. Remove once the flag is default-on.
        // =====================================================================
        // LIGHT FRONTEND PRE-PROCESSING (Non-authoritative - backend decides)
        // =====================================================================
        // These provide instant UX feedback but do NOT block - backend has final say

        if (message && !attachments.length) {
            // Priority 1: Check for obvious junk/dummy queries (won't go to support)
            if (this.isColdQuery(message)) {
                this.uiManager.addChatMessage(message, true);
                this.uiManager.addChatMessage(
                    "⚠️ This message won't be sent to support. Please describe your actual issue or error message.",
                    false,
                    true
                );
                if (this.chatInput) this.chatInput.value = '';
                return;
            }

            // Priority 2: Check for greeting-only input (local response, won't go to support)
            if (this.greetingPatterns.test(message)) {
                const response = '👋 Hello! Please describe the issue you\'re facing. Real details will be sent to support when ready.';

                // Loop prevention: don't repeat same response
                if (this.lastResponse === response) {
                    this.uiManager.addChatMessage(message, true);
                    this.uiManager.addChatMessage(
                        "ℹ️ I'm here to help. Describe your specific issue and I'll guide you or escalate to support.",
                        false
                    );
                    if (this.chatInput) this.chatInput.value = '';
                    return;
                }

                this.lastResponse = response;
                this.uiManager.addChatMessage(message, true);
                this.uiManager.addChatMessage(response, false);
                if (this.chatInput) this.chatInput.value = '';
                return;
            }

            // Priority 3: Check for vague input (local response, ask for details)
            if (this.vaguePatterns.test(message)) {
                const response = "📝 Please be more specific. Tell me: What service? What's the exact error message? Details will go to support.";

                // Loop prevention
                if (this.lastResponse === response) {
                    this.uiManager.addChatMessage(message, true);
                    this.uiManager.addChatMessage(
                        "ℹ️ Share specific details (error, service name, screenshots) and I'll help or escalate.",
                        false
                    );
                    if (this.chatInput) this.chatInput.value = '';
                    return;
                }

                this.lastResponse = response;
                this.uiManager.addChatMessage(message, true);
                this.uiManager.addChatMessage(response, false);
                if (this.chatInput) this.chatInput.value = '';
                return;
            }

            // Valid input - clear last response tracking
            this.lastResponse = null;
        }

        // Disable send button
        if (this.sendBtn) {
            this.sendBtn.disabled = true;
        }

        // Show user message, with a marker for attached files
        this.uiManager.addChatMessage(this.withAttachmentLabel(message), true);

        // Clear input text immediately
        this.clearChatInput();

        // NOTE: We do NOT clear the file preview yet. 
        // We wait until success to ensure the user can retry on failure without re-attaching.

        // Show typing indicator
        this.uiManager.showTypingIndicator();
        this.uiManager.setStatus('Sending...');

        const result = await this.apiManager.sendChatMessage(
            message,
            attachments,
            this.currentErrorContext
        );

        this.uiManager.hideTypingIndicator();
        this.showTicketResult(result);

        // Re-enable send button and chat input
        if (this.sendBtn) {
            this.sendBtn.disabled = false;
        }

        // Call enableChatInput multiple times to ensure it stays enabled
        this.uiManager.enableChatInput();
        setTimeout(() => this.uiManager.enableChatInput(), 100);
        setTimeout(() => this.uiManager.enableChatInput(), 300);
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function () {
    // Wait for all manager classes to be loaded
    if (window.UIManager && window.APIManager && window.FilePreviewManager) {
        window.smartAssistant = new SmartAssistant();
    } else {
        console.error('Smart Assistant: Required manager classes not loaded');
    }
});
