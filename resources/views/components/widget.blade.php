<?php
/**
 * Blade component for the Smart AI Assistant widget.
 * Modern, WhatsApp/ChatGPT-inspired UI with error detection and file attachments
 */
?>
<div id="smart-assistant-widget">
    <!-- Floating Toggle Button -->
    <button id="smart-assistant-toggle" aria-label="Open Smart Assistant">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
        </svg>
    </button>

    <!-- Assistant Panel -->
    <div id="smart-assistant-panel">
        
        <!-- Header -->
        <div class="sa-header">
            <div class="sa-header-title">
                <span class="sa-header-icon">🤖</span>
                <span>Chat with Soniya</span>
            </div>
            <button id="smart-assistant-close" aria-label="Close Assistant">×</button>
        </div>

        <!-- Content Area -->
        <div class="sa-content">
            
            <!-- Welcome Message -->
            <div id="sa-welcome-message">
                <div class="sa-welcome-icon">👋</div>
                <div class="sa-welcome-title">Hello! I'm Soniya</div>
                <div class="sa-welcome-subtitle">How may I assist you today?</div>
            </div>

            <!-- Status Text -->
            <div id="smart-assistant-status"></div>

            <!-- Error Tags Container -->
            <div id="sa-error-tags"></div>

            <!-- Chat Messages Container -->
            <div id="sa-chat-container"></div>

            <!-- File Preview -->
            <div id="sa-file-preview"></div>

        </div>

        <!-- Chat Input Bar -->
        <div id="sa-chat-input-bar">
            <div class="sa-input-wrapper">
                <!-- Attachment Button -->
                <button id="sa-attach-btn" type="button" aria-label="Attach file">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
                    </svg>
                </button>

                <!-- Hidden File Input -->
                <input type="file" id="sa-file-upload" style="display: none;" accept="image/*,.pdf,.doc,.docx">

                <!-- Message Textarea -->
                <textarea 
                    id="smart-assistant-chat-input" 
                    placeholder="Type a message..." 
                    rows="1"
                    aria-label="Message input"
                ></textarea>
            </div>

            <!-- Send Button -->
            <button id="sa-send-btn" type="button" aria-label="Send message">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </button>
        </div>

        <!-- Footer -->
        <div class="sa-footer">
            Powered by Maddox AI
        </div>

        <!-- Hidden User Data for Chat -->
        @if(Sentinel::check())
            @php $user = Sentinel::getUser(); @endphp
            <input type="hidden" id="sa-user-maddox-id" value="{{ $user->maddox_id }}">
            <input type="hidden" id="sa-user-name" value="{{ $user->full_name }}">
            <input type="hidden" id="sa-user-phone" value="{{ $user->phone_no }}">
        @endif
    </div>
</div>

<!-- Load CSS -->
<link rel="stylesheet" href="{{ asset('vendor/smart-ai-assistant/css/assistant.css') }}">

<!-- Load JavaScript Modules -->
<script src="{{ asset('vendor/smart-ai-assistant/js/ui-manager.js') }}"></script>
<script src="{{ asset('vendor/smart-ai-assistant/js/api-manager.js') }}"></script>
<script src="{{ asset('vendor/smart-ai-assistant/js/file-preview.js') }}"></script>
<script src="{{ asset('vendor/smart-ai-assistant/js/assistant.js') }}"></script>
