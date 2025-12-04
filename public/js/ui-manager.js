/**
 * UI Manager - Handles all UI interactions and rendering
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
        this.selectedErrorTag = null;

        this.initEventListeners();
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
        // Trigger error scan after a brief delay
        setTimeout(() => {
            if (window.smartAssistant) {
                window.smartAssistant.scanForErrors();
            }
        }, 500);
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
        this.setStatus('Scanning your page for possible errors...');
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
        this.errorTagsContainer.style.display = 'block';

        errors.forEach((errorText, index) => {
            const tag = document.createElement('div');
            tag.className = 'sa-error-tag';
            tag.textContent = errorText;
            tag.dataset.errorIndex = index;
            tag.dataset.errorText = errorText;

            tag.addEventListener('click', () => this.handleErrorTagClick(tag, errorText));

            this.errorTagsContainer.appendChild(tag);
        });

        this.setStatus(`Found ${errors.length} error${errors.length > 1 ? 's' : ''} on this page`);
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

        // Show chat input
        this.showChatInput();

        // Focus chat input
        if (this.chatInput) {
            this.chatInput.focus();
        }

        // Send error to API
        if (window.smartAssistant) {
            window.smartAssistant.handleErrorQuery(errorText);
        }
    }

    showChatInput() {
        const chatInputBar = document.getElementById('sa-chat-input-bar');
        if (chatInputBar) {
            chatInputBar.style.display = 'flex';
        }
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
    }

    resetUI() {
        this.clearChatMessages();
        this.clearErrorTags();
        this.selectedErrorTag = null;
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
