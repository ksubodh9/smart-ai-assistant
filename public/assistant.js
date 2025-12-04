document.addEventListener('DOMContentLoaded', function () {
    const toggleBtn = document.getElementById('smart-assistant-toggle');
    const panel = document.getElementById('smart-assistant-panel');
    const status = document.getElementById('smart-assistant-status');
    const responseDiv = document.getElementById('smart-assistant-response');

    const closeBtn = document.getElementById('smart-assistant-close');

    const fileInput = document.getElementById('sa-file-upload');
    const fileInfo = document.getElementById('sa-selected-file');
    const fileNameSpan = document.getElementById('sa-selected-file-name');

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            if (fileInput.files.length > 0) {
                fileNameSpan.textContent = fileInput.files[0].name;
                fileInfo.style.display = "block";
            } else {
                fileInfo.style.display = "none";
            }
        });
    }

    if (!toggleBtn || !panel) return;

    toggleBtn.addEventListener('click', function () {
        if (panel.style.display === 'none') {
            panel.style.display = 'block';
            fetchHelp();
        } else {
            panel.style.display = 'none';
        }
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            panel.style.display = 'none';
        });
    }

    const chatUi = document.getElementById('smart-assistant-chat-ui');
    const chatInput = document.getElementById('smart-assistant-chat-input');
    const chatSendBtn = document.getElementById('smart-assistant-chat-send');

    function fetchHelp() {
        status.textContent = 'Scanning for errors...';
        responseDiv.innerHTML = '';

        // Reset UI
        if (chatUi) chatUi.style.display = 'none';

        // 1. Try to find error text on the page
        const errorSelectors = ['.alert-danger', '.smart-error', '.invalid-feedback', '.text-danger'];
        let foundError = '';

        for (let selector of errorSelectors) {
            const el = document.querySelector(selector);
            if (el && el.innerText.trim().length > 0) {
                foundError = el.innerText.trim();
                break;
            }
        }

        if (!foundError) {
            status.textContent = 'How can I help you?';
            if (chatUi) {
                chatUi.style.display = 'block';
                // Focus input if visible
                setTimeout(() => chatInput?.focus(), 100);
            }
            return;
        }

        console.log('Found error:', foundError);
        status.textContent = 'Error found. Analyzing...';

        // 2. Send to backend
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        fetch('/smart-assistant/help', {
            method: 'POST',
            redirect: 'manual',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({
                error_text: foundError,
                page_url: window.location.href
            })
        })
            .then(async (response) => {
                let text = "";
                try {
                    text = await response.text();
                } catch (e) {
                    text = "Error reading response";
                }

                try {
                    const json = JSON.parse(text);
                    // Display the answer
                    let html = `<div style="background: #eef2ff; padding: 10px; border-radius: 8px; margin-bottom: 10px; border-left: 3px solid #4f46e5;">
                        <strong>Suggestion:</strong><br>
                        ${json.answer_en}
                    </div>`;
                    if (json.answer_hi) {
                        html += `<div style="background: #fff; padding: 10px; border-radius: 8px; border: 1px solid #e5e7eb; font-size: 0.95em;">
                            <strong>हिंदी:</strong><br>
                            ${json.answer_hi}
                        </div>`;
                    }
                    responseDiv.innerHTML = html;
                    status.textContent = 'Analysis complete.';

                    // Show chat UI if the error is unknown so user can contact support
                    if (json.source === 'unknown' && chatUi) {
                        chatUi.style.display = 'block';
                    }
                } catch (e) {
                    console.error("JSON Parse Error:", e);
                    responseDiv.innerHTML = `<div style="color:red">Error parsing response: ${text.substring(0, 100)}...</div>`;
                }
            })
            .catch(err => {
                console.log("Fetch error:", err);
                status.textContent = 'Connection error.';
            });
    }

    if (chatSendBtn) {
        chatSendBtn.addEventListener('click', function () {

            const message = chatInput.value.trim();
            const file = fileInput?.files[0] || null;

            if (!message && !file) return;

            const maddoxId = document.getElementById('sa-user-maddox-id')?.value;
            const userName = document.getElementById('sa-user-name')?.value;
            let userPhone = document.getElementById('sa-user-phone')?.value || '';

            userPhone = userPhone.replace(/\D/g, '');
            if (userPhone.length > 12) userPhone = userPhone.slice(-10);

            if (!maddoxId) {
                alert('Please log in to send a query.');
                return;
            }

            status.textContent = 'Sending...';
            chatSendBtn.disabled = true;

            // FormData for file upload
            const formData = new FormData();
            formData.append("type", "self");
            formData.append("maddox_id", maddoxId);
            formData.append("name", userName);
            formData.append("alternate_phone_no", userPhone);
            formData.append("service", 99);
            formData.append("other", "Smart Assistant Query");
            formData.append("category", "Smart Assistant");
            formData.append("description", message);

            if (file) formData.append("attachment", file);

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            fetch('/customer-support/raise/ticket', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                body: formData
            })
                .then(async (response) => {
                    const text = await response.text();
                    let json = JSON.parse(text);

                    if (response.ok) {
                        chatInput.value = '';
                        fileInput.value = '';
                        fileInfo.style.display = 'none';

                        responseDiv.innerHTML = `
                        <div style="background:#ecfdf5; color:#065f46; padding:10px; border-radius:8px; border-left: 3px solid #10b981;">
                            <strong>Success:</strong><br>${json.message}
                        </div>`;
                        status.textContent = 'Message sent.';

                    } else {
                        responseDiv.innerHTML = `
                        <div style="background:#fef2f2; color:#991b1b; padding:10px; border-radius:8px; border-left:3px solid #ef4444;">
                            <strong>Error:</strong><br>${json.message}
                        </div>`;
                        status.textContent = 'Error sending.';
                    }
                })
                .catch(() => {
                    responseDiv.innerHTML = `<div style="color:red">Connection error. Try again.</div>`;
                    status.textContent = 'Error.';
                })
                .finally(() => {
                    chatSendBtn.disabled = false;
                });

        });
    }

});
