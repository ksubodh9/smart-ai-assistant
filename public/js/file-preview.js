/**
 * File Preview Manager - Handles file attachments, thumbnails, and fullscreen preview
 */
class FilePreviewManager {
    constructor() {
        this.fileInput = document.getElementById('sa-file-upload');
        this.attachBtn = document.getElementById('sa-attach-btn');
        this.previewContainer = document.getElementById('sa-file-preview');
        this.currentFile = null;
        this.previewUrl = null;

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
    }

    handleFileSelect(event) {
        const file = event.target.files[0];

        if (!file) {
            this.clearPreview();
            return;
        }

        this.currentFile = file;
        this.showPreview(file);
    }

    showPreview(file) {
        if (!this.previewContainer) return;

        // Clear previous preview
        this.clearPreview();

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

        // Add event listeners
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

        // Trigger animation
        setTimeout(() => modal.classList.add('sa-modal-open'), 10);

        // Close handlers
        const closeBtn = modal.querySelector('.sa-fullscreen-close');
        const overlay = modal.querySelector('.sa-fullscreen-overlay');

        const closeModal = () => {
            modal.classList.remove('sa-modal-open');
            setTimeout(() => modal.remove(), 300);
        };

        closeBtn?.addEventListener('click', closeModal);
        overlay?.addEventListener('click', closeModal);

        // ESC key to close
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
        this.clearPreview();
    }

    clearPreview() {
        if (this.previewContainer) {
            this.previewContainer.innerHTML = '';
            this.previewContainer.style.display = 'none';
        }

        // Revoke object URL to free memory
        if (this.previewUrl) {
            URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
        }
    }

    getFile() {
        return this.currentFile;
    }
}

// Export for use in main script
window.FilePreviewManager = FilePreviewManager;
