<?php
/**
 * Blade component for the Smart AI Assistant widget.
 * Modern, WhatsApp/ChatGPT-inspired UI with error detection and file attachments.
 *
 * Everything host-specific (texts, colours, page scan rules, features) comes
 * from config('smart-ai-assistant.widget') through Support\WidgetConfig, so
 * hosts do not need to publish and edit this view.
 */
?>
@php
    $saWidget = \Subodh\SmartAiAssistant\Support\WidgetConfig::fromConfig();
    $saServerEscalation = (bool) config('smart-ai-assistant.features.server_escalation');
    $saResolveTyped = (bool) config('smart-ai-assistant.features.resolve_typed_messages');
    $saAttachmentTypes = config('smart-ai-assistant.escalation.attachments.mimes', ['jpg', 'jpeg', 'png', 'pdf']);
    // Reply languages; the menu is only shown when there is a choice
    $saLocales = \Subodh\SmartAiAssistant\Support\Locales::fromConfig()->forScript();
    // Read by the widget scripts; holds no personal data
    $saConfig = [
        'endpoints' => [
            'help'     => route('smart-assistant.help', [], false),
            'message'  => route('smart-assistant.message', [], false),
            'escalate' => route('smart-assistant.escalate', [], false),
        ],
        'features' => [
            'server_escalation'      => $saServerEscalation,
            'resolve_typed_messages' => $saResolveTyped,
        ],
        'widget'  => $saWidget->forScript(),
        'locales' => $saLocales,
    ];
@endphp
<script type="application/json" id="sa-config">@json($saConfig)</script>
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
                <span class="sa-header-icon">{{ $saWidget->branding('icon') }}</span>
                <span>{{ $saWidget->branding('title') }}</span>
            </div>
            <div class="sa-header-actions">
                @if(count($saLocales) > 1)
                {{-- Reply language: Auto follows the language each message is written in --}}
                <select id="sa-locale" class="sa-locale-select" aria-label="Reply language" title="Reply language">
                    <option value="auto">Auto</option>
                    @foreach($saLocales as $saLocale)
                        <option value="{{ $saLocale['code'] }}">{{ $saLocale['label'] }}</option>
                    @endforeach
                </select>
                @endif
                <button id="smart-assistant-close" aria-label="Close Assistant"><span aria-hidden="true">&times;</span></button>
            </div>
        </div>

        <!-- Content Area -->
        <div class="sa-content">
            
            <!-- Welcome Message -->
            <div id="sa-welcome-message">
                <div class="sa-welcome-icon">👋</div>
                <div class="sa-welcome-title">{{ $saWidget->branding('welcome_title') }}</div>
                <div class="sa-welcome-subtitle">{{ $saWidget->branding('welcome_subtitle') }}</div>

                {{-- Starter questions are sent like typed messages, so they need the assistant to answer typed text --}}
                @if($saResolveTyped && $saWidget->suggestions())
                    <div id="sa-suggestions">
                        @foreach($saWidget->suggestions() as $saSuggestion)
                            <button type="button" class="sa-suggestion" data-send="{{ $saSuggestion }}">{{ $saSuggestion }}</button>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Status Text -->
            <div id="smart-assistant-status"></div>

            <!-- Error Tags Container -->
            <div id="sa-error-tags"></div>

            <!-- Chat Messages Container -->
            <div id="sa-chat-container"></div>

            <!-- File Preview -->
            <div id="sa-file-preview"></div>

            <!-- Screenshot Preview -->
            <div id="sa-screenshot-preview"></div>

        </div>

        <!-- Chat Input Bar -->
        <div id="sa-chat-input-bar">
            <div class="sa-input-wrapper">
                @if($saWidget->feature('attachments'))
                <!-- Attachment Button -->
                <button id="sa-attach-btn" type="button" aria-label="Attach file" title="Attach file">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
                    </svg>
                </button>

                <!-- Hidden File Input -->
                <input type="file" id="sa-file-upload" style="display: none;" accept="{{ '.' . implode(',.', $saAttachmentTypes) }}">
                @endif

                @if($saWidget->feature('screenshot'))
                <!-- Screenshot Button -->
                <button id="sa-screenshot-btn" type="button" aria-label="Take screenshot" title="Capture screenshot">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                        <circle cx="12" cy="13" r="4"></circle>
                    </svg>
                </button>
                @endif

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

        @if($saWidget->branding('footer'))
        <!-- Footer -->
        <div class="sa-footer">
            {{ $saWidget->branding('footer') }}
        </div>
        @endif

        {{-- legacy-host-start: identity fields for the MaddoxPay ticket endpoint; not rendered with
             server escalation. Remove with the other legacy-host blocks once that is the default. --}}
        @if(! $saServerEscalation && class_exists('Sentinel') && Sentinel::check())
            @php $user = Sentinel::getUser(); @endphp
            <input type="hidden" id="sa-user-maddox-id" value="{{ $user->maddox_id }}">
            <input type="hidden" id="sa-user-name" value="{{ $user->full_name }}">
            <input type="hidden" id="sa-user-phone" value="{{ $user->phone_no }}">
        @endif
        {{-- legacy-host-end --}}
    </div>
</div>

<!-- Load CSS -->
<link rel="stylesheet" href="{{ asset('vendor/smart-ai-assistant/css/assistant.css') }}">
<!-- Brand colours from config (after the stylesheet, so they override its defaults) -->
<style>:root { @foreach($saWidget->cssVariables() as $saName => $saValue){{ $saName }}: {{ $saValue }}; @endforeach}</style>

@if($saWidget->feature('screenshot'))
<!-- Load html2canvas 1.4.1 (MIT) for screenshot capture; bundled, not loaded from a CDN -->
<script src="{{ asset('vendor/smart-ai-assistant/js/vendor/html2canvas.min.js') }}"></script>
@endif

<!-- Load JavaScript Modules -->
<script src="{{ asset('vendor/smart-ai-assistant/js/ui-manager.js') }}"></script>
@if($saWidget->feature('bootstrap_modal_compat'))
<script src="{{ asset('vendor/smart-ai-assistant/js/host-compat.js') }}"></script>
@endif
<script src="{{ asset('vendor/smart-ai-assistant/js/api-manager.js') }}"></script>
<script src="{{ asset('vendor/smart-ai-assistant/js/file-preview.js') }}"></script>
<script src="{{ asset('vendor/smart-ai-assistant/js/assistant.js') }}"></script>

