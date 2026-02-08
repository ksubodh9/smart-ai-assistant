/**
 * API Manager - Handles all backend API communications
 */
class APIManager {
    constructor() {
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        this.endpoints = {
            help: '/smart-assistant/help',
            ticket: '/customer-support/raise/ticket'
        };
    }

    async sendErrorQuery(errorText, pageUrl) {
        try {
            const response = await fetch(this.endpoints.help, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken
                },
                body: JSON.stringify({
                    error_text: errorText,
                    page_url: pageUrl || window.location.href
                })
            });

            const text = await response.text();

            try {
                const json = JSON.parse(text);
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

    async sendChatMessage(message, attachments = [], errorContext = null) {
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
}

// Export for use in main script
window.APIManager = APIManager;
