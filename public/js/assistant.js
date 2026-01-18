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
        this.greetingPatterns = /^(hi|hello|hey|hii+|helo|hlo|namaste|namaskar|good\s*(morning|afternoon|evening|night|day))[\s\!\.\?]*$/i;
        this.vaguePatterns = /^(help|help me|need help|i need help|issue|problem|error|not working|please help)[\s\!\.\?]*$/i;
        this.noisePatterns = /^(test|testing|123|abc|xyz|qwerty|asdf|[\W\d\s]+|.{1,2})$/i;

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

            let responseHtml = '';

            if (data.answer_en) {
                responseHtml += `<strong>💡 Solution:</strong><br>${data.answer_en}`;
            }

            if (data.answer_hi) {
                responseHtml += `<br><br><strong>🇮🇳 हिंदी में:</strong><br>${data.answer_hi}`;
            }

            if (!responseHtml) {
                responseHtml = 'I found information about this error, but couldn\'t format it properly. Please try rephrasing your question.';
            }

            this.uiManager.addChatMessage(responseHtml, false);
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
            // Check for noise input (test, random chars, etc)
            if (this.noisePatterns.test(message)) {
                this.noiseCount++;
                this.uiManager.addChatMessage(message, true);

                if (this.noiseCount >= this.maxNoiseResponses) {
                    // Exit message - stop responding to noise
                    this.uiManager.addChatMessage(
                        "I've shared all available guidance. Please contact support if you need further assistance.",
                        false
                    );
                } else {
                    this.uiManager.addChatMessage(
                        'I am ready to help. Please state your issue.',
                        false
                    );
                }
                if (this.chatInput) this.chatInput.value = '';
                return;
            }

            // Reset noise count on valid input attempt
            this.noiseCount = 0;

            // Check for greeting-only input
            if (this.greetingPatterns.test(message)) {
                const response = 'Hello. Please state the issue you are facing.';

                // Loop prevention: don't repeat same response
                if (this.lastResponse === response) {
                    this.uiManager.addChatMessage(message, true);
                    this.uiManager.addChatMessage(
                        "I've shared all available guidance. Please contact support if you need further assistance.",
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

            // Check for vague input
            if (this.vaguePatterns.test(message)) {
                const response = 'Please specify the error message or the service (e.g., AEPS, PAN) you are having trouble with.';

                // Loop prevention
                if (this.lastResponse === response) {
                    this.uiManager.addChatMessage(message, true);
                    this.uiManager.addChatMessage(
                        "I've shared all available guidance. Please contact support if you need further assistance.",
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
