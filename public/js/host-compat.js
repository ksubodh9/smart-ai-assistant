/**
 * Host compatibility workarounds (optional; widget.features.bootstrap_modal_compat).
 *
 * For host pages that fight the chat input:
 * - Bootstrap 4/5 modals trap focus, so the chat input cannot be typed in
 *   while a modal is open;
 * - page scripts that disable every input (e.g. while a form submits) also
 *   disable the chat input.
 *
 * Loaded after ui-manager.js. UIManager calls SmartAssistantHostCompat.install()
 * when this file is present; if this file loads after the widget started, it
 * installs itself.
 */
class HostCompat {
    constructor(ui) {
        this.ui = ui;
    }

    install() {
        this.setupInputProtection();
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
                if (this.ui.keepInputActive) {
                    mutations.forEach(mutation => {
                        if (mutation.type === 'attributes') {
                            if (mutation.attributeName === 'disabled' ||
                                mutation.attributeName === 'readonly') {
                                // Re-enable immediately if disabled
                                if (chatInput.disabled || chatInput.readOnly) {
                                    this.ui.forceEnableInput();
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
                if (this.ui.keepInputActive) {
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
            if (this.ui.keepInputActive && this.ui.panel?.classList.contains('sa-panel-open')) {
                this.ui.forceEnableInput();
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
                        this.ui.forceEnableInput();
                    }
                }, 0);
            }
        }, true);

        // Prevent Bootstrap's focusout handler from taking focus away
        widget.addEventListener('focusout', (e) => {
            if (this.ui.panel?.classList.contains('sa-panel-open')) {
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
                    this.ui.forceEnableInput();
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
}

window.SmartAssistantHostCompat = {
    install(ui) {
        if (!ui || ui.hostCompatInstalled) return;
        ui.hostCompatInstalled = true;
        new HostCompat(ui).install();
    }
};

// Loaded after the widget started (e.g. injected for an older published view)
if (window.smartAssistant?.uiManager) {
    window.SmartAssistantHostCompat.install(window.smartAssistant.uiManager);
}
