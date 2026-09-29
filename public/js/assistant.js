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

        // Light input pre-processing patterns (non-authoritative - backend has final say)
        this.greetingPatterns = /^(hi|hello|hey|hii+|helo|hlo|namaste|namaskar|good\s*(morning|afternoon|evening|night|day)|sup|yo|wassup|howdy)[\s\!\.\?]*$/i;
        this.vaguePatterns = /^(help|help me|need help|i need help|issue|problem|error|not working|please help|support|assist)[\s\!\.\?]*$/i;
        // SPECIFIC noise patterns - only obvious junk/test inputs
        this.noisePatterns = /^(test|testing|abc|xyz|qwerty|asdf|jkl|lol|haha|dummy)[\s\!\.\?]*$/i;
        // Repeated characters (hhhhhh, !!!!, but not "hello" or short valid words)
        this.repeatedCharPattern = /^(.)\1{4,}[\s\!\.\?]*$/;

        this.initEventListeners();
    }

    initEventListeners() {
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
        this.uiManager.addChatMessage(`Help me with: "${errorText}"`, true);

        // Show typing indicator
        this.uiManager.showTypingIndicator();
        this.uiManager.setStatus('Analyzing error...');

        const result = await this.apiManager.sendErrorQuery(errorText, window.location.href);

        this.uiManager.hideTypingIndicator();

        if (result.success && result.data) {
            const data = result.data;

            // Server answers are text with optional **bold**; never render them as HTML
            const sections = [];

            if (data.answer_en) {
                sections.push(`**💡 Solution:**\n${data.answer_en}`);
            }

            if (data.answer_hi) {
                sections.push(`**🇮🇳 हिंदी में:**\n${data.answer_hi}`);
            }

            const responseText = sections.length
                ? sections.join('\n\n')
                : 'I found information about this error, but couldn\'t format it properly. Please try rephrasing your question.';

            this.uiManager.addFormattedMessage(responseText);
            this.uiManager.setStatus('Ready to help');

            // If unknown error, suggest manual query
            if (data.source === 'unknown') {
                setTimeout(() => {
                    this.uiManager.addChatMessage(
                        'If you need more help, feel free to type your question below or attach a screenshot.',
                        false
                    );
                }, 500);
            }
        } else if (result.error === 'network_error') {
            this.uiManager.showNetworkError();
            this.uiManager.setStatus('Network error');
        } else {
            this.uiManager.showParseError();
            this.uiManager.setStatus('Error occurred');
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

    async handleSendMessage() {
        const message = this.chatInput?.value.trim();
        const attachments = this.filePreviewManager.getAttachments();

        if (!message && attachments.length === 0) {
            return;
        }

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

        // Show user message
        let userMessageText = message || '';
        const hasAttachments = attachments.length > 0;

        if (hasAttachments) {
            const attachmentTypes = [];
            if (this.filePreviewManager.getFile()) attachmentTypes.push('📎 File');
            if (this.filePreviewManager.getScreenshot()) attachmentTypes.push('📷 Screenshot');

            // Append visual marker to text
            const attachmentLabel = `\n[Attached: ${attachmentTypes.join(', ')}]`;
            userMessageText = (userMessageText + attachmentLabel).trim();
        }

        this.uiManager.addChatMessage(userMessageText, true);

        // Clear input text immediately
        if (this.chatInput) {
            this.chatInput.value = '';
            this.chatInput.style.height = 'auto';
        }

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

        if (result.success) {
            // NOW clear the file preview since it was sent successfully
            this.filePreviewManager.clearPreview();

            this.uiManager.addChatMessage(
                `✅ ${result.message || 'Your message has been sent successfully. Our support team will get back to you soon.'}`,
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
