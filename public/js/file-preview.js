/**
 * File Preview Manager - Handles file attachments and Snipping Tool-style screenshots
 * 
 * Screenshot Capture Flow (Like Windows Snipping Tool):
 * 1. User clicks camera icon
 * 2. Capture ENTIRE viewport immediately (before any overlay)
 * 3. Show the captured image as overlay background
 * 4. User draws selection rectangle on the frozen image
 * 5. Crop the captured image based on selection
 * 6. This ensures modals and all visible content is captured correctly
 */
class FilePreviewManager {
    constructor() {
        this.fileInput = document.getElementById('sa-file-upload');
        this.attachBtn = document.getElementById('sa-attach-btn');
        this.screenshotBtn = document.getElementById('sa-screenshot-btn');
        this.previewContainer = document.getElementById('sa-file-preview');
        this.screenshotPreviewContainer = document.getElementById('sa-screenshot-preview');

        this.currentFile = null;
        this.currentScreenshot = null;
        this.previewUrl = null;
        this.screenshotUrl = null;

        // Selection state
        this.isSelecting = false;
        this.selectionOverlay = null;
        this.selectionBox = null;
        this.startX = 0;
        this.startY = 0;

        // Captured image for snipping
        this.capturedCanvas = null;
        this.capturedImageUrl = null;

        this.initEventListeners();
    }

    initEventListeners() {
        if (this.fileInput) {
            this.fileInput.addEventListener('change', (e) => this.handleFileSelect(e));
        }

        if (this.attachBtn) {
            this.attachBtn.addEventListener('click', () => {
                this.fileInput?.click();
            });
        }

        if (this.screenshotBtn) {
            this.screenshotBtn.addEventListener('click', () => this.startSnippingCapture());
        }
    }

    handleFileSelect(event) {
        const file = event.target.files[0];

        if (!file) {
            this.clearFilePreview();
            return;
        }

        this.currentFile = file;
        this.showFilePreview(file);
    }

    showFilePreview(file) {
        if (!this.previewContainer) return;

        this.clearFilePreview();

        const fileType = this.getFileType(file);
        const fileName = file.name;
        const fileSize = this.formatFileSize(file.size);

        const previewDiv = document.createElement('div');
        previewDiv.className = 'sa-file-preview-item';
        previewDiv.innerHTML = `
            <div class="sa-file-thumbnail" data-file-type="${fileType}">
                ${this.getThumbnailContent(file, fileType)}
            </div>
            <div class="sa-file-info">
                <div class="sa-file-name">${fileName}</div>
                <div class="sa-file-size">${fileSize}</div>
            </div>
            <button class="sa-file-remove" title="Remove file">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        `;

        this.previewContainer.appendChild(previewDiv);
        this.previewContainer.style.display = 'block';

        const thumbnail = previewDiv.querySelector('.sa-file-thumbnail');
        const removeBtn = previewDiv.querySelector('.sa-file-remove');

        if (thumbnail && (fileType === 'image' || fileType === 'pdf')) {
            thumbnail.style.cursor = 'pointer';
            thumbnail.addEventListener('click', () => this.openFullscreen(file, fileType));
        }

        if (removeBtn) {
            removeBtn.addEventListener('click', () => this.removeFile());
        }
    }

    // ==========================================
    // SNIPPING TOOL STYLE SCREENSHOT CAPTURE
    // ==========================================

    async startSnippingCapture() {
        // Prevent multiple captures
        if (this.selectionOverlay) {
            return;
        }

        // Check if html2canvas is available
        if (typeof html2canvas === 'undefined') {
            this.showScreenshotError('Screenshot library not loaded. Please refresh the page.');
            return;
        }

        // Add capturing indicator to button
        if (this.screenshotBtn) {
            this.screenshotBtn.classList.add('sa-capturing');
        }

        try {
            // STEP 1: Hide the widget FIRST
            const widget = document.getElementById('smart-assistant-widget');
            if (widget) {
                widget.style.visibility = 'hidden';
            }

            // Small delay to ensure widget is hidden
            await new Promise(resolve => setTimeout(resolve, 50));

            // STEP 2: Capture the ENTIRE viewport immediately (before any overlay)
            // This captures exactly what the user sees, including modals
            this.capturedCanvas = await html2canvas(document.body, {
                useCORS: true,
                allowTaint: true,
                logging: false,
                scale: 1,
                width: window.innerWidth,
                height: window.innerHeight,
                x: window.scrollX,
                y: window.scrollY,
                windowWidth: window.innerWidth,
                windowHeight: window.innerHeight,
                ignoreElements: (element) => {
                    // Only ignore the assistant widget
                    return element.id === 'smart-assistant-widget';
                }
            });

            // Convert canvas to image URL for display
            this.capturedImageUrl = this.capturedCanvas.toDataURL('image/png');

            // STEP 3: Show selection overlay with captured image as background
            this.showSelectionOverlay();

        } catch (error) {
            console.error('Screenshot capture error:', error);

            // Show widget again
            const widget = document.getElementById('smart-assistant-widget');
            if (widget) {
                widget.style.visibility = 'visible';
            }

            this.showScreenshotError('Failed to capture screen. Please try again.');
        } finally {
            // Remove capturing indicator
            if (this.screenshotBtn) {
                this.screenshotBtn.classList.remove('sa-capturing');
            }
        }
    }

    showSelectionOverlay() {
        // Create overlay with the captured image as background
        this.selectionOverlay = document.createElement('div');
        this.selectionOverlay.className = 'sa-selection-overlay';
        this.selectionOverlay.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 99999;
            cursor: crosshair;
            background-image: url('${this.capturedImageUrl}');
            background-size: cover;
            background-position: top left;
            background-repeat: no-repeat;
        `;

        // Add dark tint overlay
        const tintOverlay = document.createElement('div');
        tintOverlay.className = 'sa-selection-tint';
        tintOverlay.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            pointer-events: none;
        `;
        this.selectionOverlay.appendChild(tintOverlay);

        // Create instructions
        const instructions = document.createElement('div');
        instructions.className = 'sa-selection-instructions';
        instructions.innerHTML = `
            📷 Drag to select area
            <span class="sa-esc-hint">(ESC to cancel)</span>
        `;
        instructions.style.cssText = `
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
            z-index: 100002;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.4);
            pointer-events: none;
            user-select: none;
        `;

        document.body.appendChild(this.selectionOverlay);
        document.body.appendChild(instructions);
        this.instructionsElement = instructions;

        // Prevent scrolling
        document.body.style.overflow = 'hidden';

        // Bind event handlers
        this.boundMouseDown = this.handleMouseDown.bind(this);
        this.boundMouseMove = this.handleMouseMove.bind(this);
        this.boundMouseUp = this.handleMouseUp.bind(this);
        this.boundKeyDown = this.handleKeyDown.bind(this);

        this.selectionOverlay.addEventListener('mousedown', this.boundMouseDown);
        document.addEventListener('mousemove', this.boundMouseMove);
        document.addEventListener('mouseup', this.boundMouseUp);
        document.addEventListener('keydown', this.boundKeyDown);
    }

    handleMouseDown(e) {
        e.preventDefault();

        this.isSelecting = true;
        this.startX = e.clientX;
        this.startY = e.clientY;

        // Create selection box that shows the clear area
        this.selectionBox = document.createElement('div');
        this.selectionBox.className = 'sa-selection-box';
        this.selectionBox.style.cssText = `
            position: fixed;
            border: 2px solid #667eea;
            background-image: url('${this.capturedImageUrl}');
            background-size: ${window.innerWidth}px ${window.innerHeight}px;
            background-position: -${this.startX}px -${this.startY}px;
            pointer-events: none;
            z-index: 100001;
            box-sizing: border-box;
            left: ${this.startX}px;
            top: ${this.startY}px;
            width: 0px;
            height: 0px;
        `;

        // Add white border inside
        const innerBorder = document.createElement('div');
        innerBorder.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border: 2px dashed white;
            pointer-events: none;
        `;
        this.selectionBox.appendChild(innerBorder);

        // Add dimensions display
        const dimensions = document.createElement('div');
        dimensions.className = 'sa-selection-dimensions';
        dimensions.style.cssText = `
            position: absolute;
            bottom: -28px;
            left: 50%;
            transform: translateX(-50%);
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
            font-family: monospace;
        `;
        this.selectionBox.appendChild(dimensions);
        this.dimensionsElement = dimensions;

        document.body.appendChild(this.selectionBox);
    }

    handleMouseMove(e) {
        if (!this.isSelecting || !this.selectionBox) return;

        const currentX = e.clientX;
        const currentY = e.clientY;

        // Calculate dimensions (handle reverse selection)
        const left = Math.min(this.startX, currentX);
        const top = Math.min(this.startY, currentY);
        const width = Math.abs(currentX - this.startX);
        const height = Math.abs(currentY - this.startY);

        // Update selection box position and size
        this.selectionBox.style.left = `${left}px`;
        this.selectionBox.style.top = `${top}px`;
        this.selectionBox.style.width = `${width}px`;
        this.selectionBox.style.height = `${height}px`;

        // Update background position to show the clear image in selection
        this.selectionBox.style.backgroundPosition = `-${left}px -${top}px`;

        // Update dimensions display
        if (this.dimensionsElement) {
            this.dimensionsElement.textContent = `${Math.round(width)} × ${Math.round(height)}`;
        }
    }

    handleMouseUp(e) {
        if (!this.isSelecting) return;

        this.isSelecting = false;

        // Get selection bounds
        const boxRect = this.selectionBox.getBoundingClientRect();
        const width = boxRect.width;
        const height = boxRect.height;

        // Minimum size check (50x50 pixels)
        if (width < 50 || height < 50) {
            this.cancelSelection();
            this.showScreenshotError('Selection too small. Please select a larger area (min 50×50 pixels).');
            return;
        }

        // Crop the captured canvas to selection
        this.cropAndSaveSelection(boxRect);
    }

    handleKeyDown(e) {
        if (e.key === 'Escape') {
            this.cancelSelection();
        }
    }

    cropAndSaveSelection(rect) {
        try {
            // Create a new canvas for the cropped area
            const croppedCanvas = document.createElement('canvas');
            croppedCanvas.width = rect.width;
            croppedCanvas.height = rect.height;

            const ctx = croppedCanvas.getContext('2d');

            // Draw the cropped portion from the captured canvas
            ctx.drawImage(
                this.capturedCanvas,
                rect.left,           // source x
                rect.top,            // source y
                rect.width,          // source width
                rect.height,         // source height
                0,                   // destination x
                0,                   // destination y
                rect.width,          // destination width
                rect.height          // destination height
            );

            // Clean up selection UI
            this.cleanupSelection();

            // Show the widget again
            const widget = document.getElementById('smart-assistant-widget');
            if (widget) {
                widget.style.visibility = 'visible';
            }

            // Convert cropped canvas to blob
            croppedCanvas.toBlob((blob) => {
                if (blob) {
                    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
                    const fileName = `screenshot-${timestamp}.png`;
                    const file = new File([blob], fileName, { type: 'image/png' });

                    this.currentScreenshot = file;
                    this.showScreenshotPreview(file);
                } else {
                    this.showScreenshotError('Failed to save screenshot. Please try again.');
                }
            }, 'image/png', 0.95);

        } catch (error) {
            console.error('Crop error:', error);
            this.cleanupSelection();

            const widget = document.getElementById('smart-assistant-widget');
            if (widget) {
                widget.style.visibility = 'visible';
            }

            this.showScreenshotError('Failed to crop screenshot. Please try again.');
        }
    }

    cancelSelection() {
        this.cleanupSelection();

        // Show the widget again
        const widget = document.getElementById('smart-assistant-widget');
        if (widget) {
            widget.style.visibility = 'visible';
        }
    }

    cleanupSelection() {
        this.isSelecting = false;

        // Remove overlay
        if (this.selectionOverlay) {
            this.selectionOverlay.remove();
            this.selectionOverlay = null;
        }

        // Remove selection box
        if (this.selectionBox) {
            this.selectionBox.remove();
            this.selectionBox = null;
        }

        // Remove instructions
        if (this.instructionsElement) {
            this.instructionsElement.remove();
            this.instructionsElement = null;
        }

        // Remove event listeners
        if (this.boundMouseMove) {
            document.removeEventListener('mousemove', this.boundMouseMove);
        }
        if (this.boundMouseUp) {
            document.removeEventListener('mouseup', this.boundMouseUp);
        }
        if (this.boundKeyDown) {
            document.removeEventListener('keydown', this.boundKeyDown);
        }

        // Restore scrolling
        document.body.style.overflow = '';

        // Clear captured image
        this.capturedCanvas = null;
        this.capturedImageUrl = null;
        this.dimensionsElement = null;
    }

    showScreenshotPreview(file) {
        if (!this.screenshotPreviewContainer) return;

        this.clearScreenshotPreview();

        const fileSize = this.formatFileSize(file.size);
        const url = URL.createObjectURL(file);
        this.screenshotUrl = url;

        const previewDiv = document.createElement('div');
        previewDiv.className = 'sa-screenshot-preview-item';
        previewDiv.innerHTML = `
            <div class="sa-screenshot-thumbnail">
                <img src="${url}" alt="Screenshot Preview" />
            </div>
            <div class="sa-screenshot-info">
                <div class="sa-screenshot-name">📷 Screenshot captured</div>
                <div class="sa-screenshot-size">${fileSize}</div>
            </div>
            <button class="sa-screenshot-remove" title="Remove screenshot">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        `;

        this.screenshotPreviewContainer.appendChild(previewDiv);
        this.screenshotPreviewContainer.style.display = 'block';

        const thumbnail = previewDiv.querySelector('.sa-screenshot-thumbnail');
        const removeBtn = previewDiv.querySelector('.sa-screenshot-remove');

        if (thumbnail) {
            thumbnail.style.cursor = 'pointer';
            thumbnail.addEventListener('click', () => this.openFullscreen(file, 'image'));
        }

        if (removeBtn) {
            removeBtn.addEventListener('click', () => this.removeScreenshot());
        }
    }

    showScreenshotError(message) {
        // Show error in UI
        if (window.smartAssistant && window.smartAssistant.uiManager) {
            window.smartAssistant.uiManager.addChatMessage(`⚠️ ${message}`, false, true);
        } else {
            alert(message);
        }
    }

    getThumbnailContent(file, fileType) {
        if (fileType === 'image') {
            const url = URL.createObjectURL(file);
            this.previewUrl = url;
            return `<img src="${url}" alt="Preview" />`;
        } else if (fileType === 'pdf') {
            return `
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <text x="7" y="17" font-size="6" fill="#ef4444" font-weight="bold">PDF</text>
                </svg>
            `;
        } else {
            return `
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2">
                    <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path>
                    <polyline points="13 2 13 9 20 9"></polyline>
                </svg>
            `;
        }
    }

    openFullscreen(file, fileType) {
        const modal = document.createElement('div');
        modal.className = 'sa-fullscreen-modal';
        modal.innerHTML = `
            <div class="sa-fullscreen-overlay"></div>
            <div class="sa-fullscreen-content">
                <button class="sa-fullscreen-close" title="Close">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                <div class="sa-fullscreen-inner">
                    ${this.getFullscreenContent(file, fileType)}
                </div>
                <div class="sa-fullscreen-filename">${file.name}</div>
            </div>
        `;

        document.body.appendChild(modal);
        setTimeout(() => modal.classList.add('sa-modal-open'), 10);

        const closeBtn = modal.querySelector('.sa-fullscreen-close');
        const overlay = modal.querySelector('.sa-fullscreen-overlay');

        const closeModal = () => {
            modal.classList.remove('sa-modal-open');
            setTimeout(() => modal.remove(), 300);
        };

        closeBtn?.addEventListener('click', closeModal);
        overlay?.addEventListener('click', closeModal);

        const escHandler = (e) => {
            if (e.key === 'Escape') {
                closeModal();
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);
    }

    getFullscreenContent(file, fileType) {
        if (fileType === 'image') {
            const url = URL.createObjectURL(file);
            return `<img src="${url}" alt="${file.name}" />`;
        } else if (fileType === 'pdf') {
            const url = URL.createObjectURL(file);
            return `<iframe src="${url}" frameborder="0"></iframe>`;
        } else {
            return `
                <div class="sa-fullscreen-file-info">
                    <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2">
                        <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path>
                        <polyline points="13 2 13 9 20 9"></polyline>
                    </svg>
                    <p>Preview not available for this file type</p>
                    <p class="sa-file-details">${file.name} (${this.formatFileSize(file.size)})</p>
                </div>
            `;
        }
    }

    getFileType(file) {
        const type = file.type.toLowerCase();

        if (type.startsWith('image/')) {
            return 'image';
        } else if (type === 'application/pdf') {
            return 'pdf';
        } else {
            return 'other';
        }
    }

    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';

        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));

        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    removeFile() {
        this.currentFile = null;
        if (this.fileInput) {
            this.fileInput.value = '';
        }
        this.clearFilePreview();
    }

    removeScreenshot() {
        this.currentScreenshot = null;
        this.clearScreenshotPreview();
    }

    clearFilePreview() {
        if (this.previewContainer) {
            this.previewContainer.innerHTML = '';
            this.previewContainer.style.display = 'none';
        }

        if (this.previewUrl) {
            URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
        }
    }

    clearScreenshotPreview() {
        if (this.screenshotPreviewContainer) {
            this.screenshotPreviewContainer.innerHTML = '';
            this.screenshotPreviewContainer.style.display = 'none';
        }

        if (this.screenshotUrl) {
            URL.revokeObjectURL(this.screenshotUrl);
            this.screenshotUrl = null;
        }
    }

    clearPreview() {
        this.clearFilePreview();
        this.clearScreenshotPreview();
        this.currentFile = null;
        this.currentScreenshot = null;
    }

    getFile() {
        return this.currentFile;
    }

    getScreenshot() {
        return this.currentScreenshot;
    }

    getAttachments() {
        const attachments = [];
        if (this.currentFile) {
            attachments.push(this.currentFile);
        }
        if (this.currentScreenshot) {
            attachments.push(this.currentScreenshot);
        }
        return attachments;
    }

    hasAttachments() {
        return !!this.currentFile || !!this.currentScreenshot;
    }
}

// Export for use in main script
window.FilePreviewManager = FilePreviewManager;
