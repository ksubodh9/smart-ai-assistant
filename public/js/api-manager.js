/**
 * API Manager - Handles all backend API communications
 */
class APIManager {
    constructor() {
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        // Rendered by the widget view; older published views have no config block
        const config = this.readConfig();
        this.serverEscalation = config.features?.server_escalation === true;
        this.resolveTypedMessages = config.features?.resolve_typed_messages === true;
        this.endpoints = {
            help: config.endpoints?.help || '/smart-assistant/help',
            message: config.endpoints?.message || '/smart-assistant/message',
            escalate: config.endpoints?.escalate || '/smart-assistant/escalate',
            ticket: '/customer-support/raise/ticket'
        };
    }

    /**
     * The server's conversation id, kept per browser tab so a chat continues
     * across page loads. The server only honours it for the same user/session.
     */
    getConversationId() {
        try {
            return sessionStorage.getItem('smart-assistant:conversation-id') || this.conversationId || null;
        } catch (e) {
            return this.conversationId || null;
        }
    }

    rememberConversationId(json) {
        const id = json && json.conversation_id;
        if (!id) return;
        this.conversationId = String(id);
        try {
            sessionStorage.setItem('smart-assistant:conversation-id', this.conversationId);
        } catch (e) {
            // Storage blocked: the in-memory copy still covers this page
        }
    }

    readConfig() {
        // Parsed once by ui-manager.js, which loads first
        return window.SmartAssistantConfig || {};
    }

    /**
     * Ask the assistant about a page error the user picked.
     */
    async sendErrorQuery(errorText, pageUrl) {
        if (this.resolveTypedMessages) {
            return this.sendMessage(errorText, 'page_error', pageUrl);
        }

        return this.postToAssistant(this.endpoints.help, {
            error_text: errorText,
            page_url: pageUrl || window.location.href,
            conversation_id: this.getConversationId()
        });
    }

    /**
     * Ask the assistant about anything the user typed or picked. Never
     * creates a ticket: unresolved replies carry an "escalate" action instead.
     * @param {string} source - typed | page_error | suggestion
     */
    async sendMessage(text, source = 'typed', pageUrl = null) {
        return this.postToAssistant(this.endpoints.message, {
            text: text,
            source: source,
            page_url: pageUrl || window.location.href,
            conversation_id: this.getConversationId()
        });
    }

    async postToAssistant(endpoint, payload) {
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken
                },
                body: JSON.stringify(payload)
            });

            if (response.status === 429) {
                return { success: false, error: 'rate_limited' };
            }

            if (!response.ok) {
                return { success: false, error: 'http_error', status: response.status };
            }

            const text = await response.text();

            try {
                const json = JSON.parse(text);
                this.rememberConversationId(json);
                return {
                    success: true,
                    data: json
                };
            } catch (e) {
                console.error('JSON Parse Error:', e);
                return {
                    success: false,
                    error: 'parse_error',
                    rawResponse: text
                };
            }
        } catch (error) {
            console.error('Fetch Error:', error);
            return {
                success: false,
                error: 'network_error',
                message: error.message
            };
        }
    }

    // legacy-host-start: MaddoxPay ticket endpoint and page identity fields, used while
    // features.server_escalation is off. Remove with the other legacy-host blocks.
    async sendChatMessage(message, attachments = [], errorContext = null) {
        if (this.serverEscalation) {
            return this.escalate(message, attachments, errorContext);
        }

        try {
            const userData = this.getUserData();

            if (!userData.maddoxId) {
                return {
                    success: false,
                    error: 'auth_required',
                    message: 'Please log in to send a query.'
                };
            }

            const formData = new FormData();
            formData.append('type', 'self');
            formData.append('maddox_id', userData.maddoxId);
            formData.append('name', userData.name);
            formData.append('alternate_phone_no', userData.phone);
            formData.append('service', '99');
            formData.append('other', 'Smart Assistant Query');
            formData.append('category', 'Smart Assistant');

            // Include error context if available
            let description = message || '';
            if (errorContext) {
                description = `Error Context: ${errorContext}\n\nUser Query: ${message || 'See attached file'}`;
            }
            if (!description && attachments.length > 0) {
                description = 'See attached file(s)';
            }
            formData.append('description', description);

            // Append all attachments (file + screenshot)
            // Use 'file[]' as the field name to match Laravel validation
            if (Array.isArray(attachments)) {
                attachments.forEach(file => {
                    if (file) {
                        formData.append('file[]', file, file.name);
                    }
                });
            } else if (attachments) {
                // For backward compatibility - single file
                formData.append('file[]', attachments, attachments.name);
            }

            const response = await fetch(this.endpoints.ticket, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': this.csrfToken,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const text = await response.text();

            try {
                const json = JSON.parse(text);
                return {
                    success: response.ok,
                    data: json,
                    message: json.message
                };
            } catch (parseError) {
                console.error('JSON Parse Error:', parseError, 'Raw:', text);
                return {
                    success: false,
                    error: 'parse_error',
                    message: 'Server returned an invalid response.'
                };
            }
        } catch (error) {
            console.error('Send Message Error:', error);
            return {
                success: false,
                error: 'network_error',
                message: 'Connection error. Please try again.'
            };
        }
    }
    // legacy-host-end

    /**
     * Send a support request through the package endpoint. The server knows
     * who the user is from the session, so no identity fields are sent.
     * Responses: {status: created|rejected|throttled|failed, message, reference, view_url}
     */
    async escalate(message, attachments = [], errorContext = null) {
        try {
            const formData = new FormData();
            formData.append('message', message || '');
            if (errorContext) {
                formData.append('error_context', errorContext);
            }
            formData.append('page_url', window.location.href);
            const conversationId = this.getConversationId();
            if (conversationId) {
                formData.append('conversation_id', conversationId);
            }

            (Array.isArray(attachments) ? attachments : [attachments]).forEach(file => {
                if (file) {
                    formData.append('attachments[]', file, file.name);
                }
            });

            const response = await fetch(this.endpoints.escalate, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': this.csrfToken,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const text = await response.text();
            let json;

            try {
                json = JSON.parse(text);
            } catch (parseError) {
                console.error('JSON Parse Error:', parseError, 'Raw:', text);
                return {
                    success: false,
                    error: 'parse_error',
                    message: 'Server returned an invalid response.'
                };
            }

            this.rememberConversationId(json);

            // 429 without a status field comes from the package rate limiter, not the host
            if (response.status === 429 && !json.status) {
                return {
                    success: false,
                    error: 'rate_limited',
                    message: 'Too many requests. Please wait a minute and try again.'
                };
            }

            return {
                success: response.ok && json.status === 'created',
                data: json,
                message: json.message
            };
        } catch (error) {
            console.error('Escalation Error:', error);
            return {
                success: false,
                error: 'network_error',
                message: 'Connection error. Please try again.'
            };
        }
    }

    // legacy-host-start
    getUserData() {
        const maddoxId = document.getElementById('sa-user-maddox-id')?.value || '';
        const name = document.getElementById('sa-user-name')?.value || '';
        let phone = document.getElementById('sa-user-phone')?.value || '';

        // Clean phone number
        phone = phone.replace(/\D/g, '');
        if (phone.length > 12) {
            phone = phone.slice(-10);
        }

        return {
            maddoxId,
            name,
            phone
        };
    }
    // legacy-host-end
}

// Export for use in main script
window.APIManager = APIManager;
