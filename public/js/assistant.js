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

    async scanForErrors() {
        const errorSelectors = [
            '.alert-danger',
            '.invalid-feedback',
            '.text-danger',
            '.smart-error'
        ];

        const foundErrors = [];
        const seenErrors = new Set();

        errorSelectors.forEach(selector => {
            const elements = document.querySelectorAll(selector);
            elements.forEach(el => {
                const errorText = el.innerText.trim();
                if (errorText && errorText.length > 0 && errorText.length < 200) {
                    // Avoid duplicates
                    if (!seenErrors.has(errorText)) {
                        seenErrors.add(errorText);
                        foundErrors.push(errorText);
                    }
                }
            });
        });

        if (foundErrors.length > 0) {
            this.uiManager.renderErrorTags(foundErrors);
        } else {
            this.uiManager.setStatus('No errors detected. How can I help you?');
            this.uiManager.showChatInput();
        }
    }

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
            this.uiManager.setStatus('');

            // If unknown error, suggest manual query
            if (data.source === 'unknown') {
                setTimeout(() => {
                    this.uiManager.addChatMessage(
                        'If you need more help, feel free to ask me anything or attach a screenshot.',
                        false
                    );
                }, 500);
            }
        } else if (result.error === 'network_error') {
            this.uiManager.hideTypingIndicator();
            this.uiManager.showNetworkError();
            this.uiManager.setStatus('Network error');
        } else {
            this.uiManager.hideTypingIndicator();
            this.uiManager.showParseError();
            this.uiManager.setStatus('Error occurred');
        }
    }

    async handleSendMessage() {
        const message = this.chatInput?.value.trim();
        const file = this.filePreviewManager.getFile();

        if (!message && !file) {
            return;
        }

        // Disable send button
        if (this.sendBtn) {
            this.sendBtn.disabled = true;
        }

        // Show user message
        let userMessageText = message || '📎 Sent an attachment';
        this.uiManager.addChatMessage(userMessageText, true);

        // Clear input
        if (this.chatInput) {
            this.chatInput.value = '';
            this.chatInput.style.height = 'auto';
        }

        // Clear file preview
        this.filePreviewManager.clearPreview();

        // Show typing indicator
        this.uiManager.showTypingIndicator();
        this.uiManager.setStatus('Sending...');

        const result = await this.apiManager.sendChatMessage(
            message,
            file,
            this.currentErrorContext
        );

        this.uiManager.hideTypingIndicator();

        if (result.success) {
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

        // Re-enable send button
        if (this.sendBtn) {
            this.sendBtn.disabled = false;
        }
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
