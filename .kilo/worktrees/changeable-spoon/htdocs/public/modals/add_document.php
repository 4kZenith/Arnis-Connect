<?php
/**
 * Upload Event Document modal.
 * Requires: $eventId in scope (event_detail.php).
 */
$__eventId = isset($eventId) ? (int) $eventId : 0;
?>

<style>
    .image-preview {
        max-width: 200px;
        max-height: 200px;
        border-radius: 10px;
        border: 2px dashed #bf46c7;
        object-fit: cover;
    }

    .progress-bar {
        transition: width 0.3s ease;
    }
</style>

<div id="documentModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div id="documentCard"
         class="card-bg w-full max-w-md rounded-3xl max-h-[80vh] flex flex-col overflow-hidden shadow-2xl
                transform transition-all duration-300 ease-out
                scale-95 opacity-0">
        <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
            <h2 class="text-xl tracking-wide font-bold italic uppercase text-blue-400">Upload Event Document</h2>
            <button type="button" onclick="closeDocumentModal()" class="text-gray-500 hover:text-white transition" title="Close">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form id="documentForm" class="p-8 flex-1 overflow-y-auto" onsubmit="return false;">
            <input type="hidden" name="event_id" value="<?= $__eventId ?>">
            <input type="hidden" name="cloudinary_url" id="document_cloudinary_url">
            <?php if (function_exists('arnisCsrfToken')): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="space-y-6">

                <button type="button" onclick="uploadEventDocumentToCloudinary()"
                        class="w-full px-4 py-3 bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 rounded-xl transition text-sm font-semibold shadow-lg shadow-blue-900/30 mb-3">
                    <i class="fas fa-cloud-upload-alt mr-2"></i>Click to Upload Image
                </button>

                <div id="documentUploadProgress" class="hidden mb-3">
                    <div class="flex items-center gap-3">
                        <div class="flex-1 bg-gray-800 rounded-full h-2">
                            <div id="documentProgressBar" class="bg-blue-500 h-2 rounded-full progress-bar" style="width: 0%"></div>
                        </div>
                        <span id="documentProgressText" class="text-xs text-gray-400">0%</span>
                    </div>
                    <p id="documentUploadStatus" class="text-xs text-gray-500 mt-2 text-center"></p>
                </div>

                <div id="documentCloudinaryPreview" class="hidden text-center">
                    <p class="text-gray-500 text-sm mb-2">Image Preview:</p>
                    <img id="documentPreviewImage" src="" alt="Preview" class="image-preview mx-auto">
                    <p class="text-xs text-green-400 mt-2">&#9989; Image uploaded to Cloudinary!</p>
                </div>

                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Document Type</label>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" onclick="selectDocumentType('Image')"
                                class="document-type-btn py-3 rounded-lg border transition flex flex-col items-center justify-center"
                                id="typeImageBtn">
                            <i class="fas fa-image text-blue-500 text-lg mb-1"></i>
                            <span class="text-xs font-bold">Image</span>
                        </button>
                        <button type="button" onclick="selectDocumentType('Receipt')"
                                class="document-type-btn py-3 rounded-lg border transition flex flex-col items-center justify-center"
                                id="typeReceiptBtn">
                            <i class="fas fa-receipt text-green-500 text-lg mb-1"></i>
                            <span class="text-xs font-bold">Receipt</span>
                        </button>
                    </div>
                    <input type="hidden" name="file_type" id="documentFileType" value="Image">
                </div>

                <div id="uploadMessage" class="text-xs text-red-400 hidden"></div>

                <div class="text-xs text-gray-500 text-center mt-3">
                    <i class="fas fa-info-circle mr-1"></i>
                    Supports JPG, PNG, GIF, WEBP (Max 5MB)
                </div>
            </div>

            <div class="mt-10 flex gap-4">
                <button type="button" onclick="closeDocumentModal()" class="flex-1 px-6 py-3 rounded-3xl hover:bg-gray-800 transition text-sm font-semibold">CANCEL</button>
                <button type="button" onclick="saveEventDocument()" id="saveDocumentBtn" disabled
                        class="flex-1 px-6 py-3 bg-blue-600 rounded-3xl opacity-50 cursor-not-allowed transition text-sm font-semibold shadow-lg shadow-blue-900/20">
                    SAVE DOCUMENT
                </button>
            </div>
        </form>
    </div>
</div>

<script>
/* ==================== MODAL FUNCTIONS ==================== */
function openDocumentModal() {
    const modal = document.getElementById('documentModal');
    const card = document.getElementById('documentCard');
    if (!modal || !card) return;

    resetDocumentForm();

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
}

function closeDocumentModal() {
    const modal = document.getElementById('documentModal');
    const card = document.getElementById('documentCard');
    if (!modal || !card) return;

    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');

    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
        resetDocumentForm();
    }, 300);
}

function resetDocumentForm() {
    const urlInput = document.getElementById('document_cloudinary_url');
    const preview = document.getElementById('documentCloudinaryPreview');
    const previewImg = document.getElementById('documentPreviewImage');
    const progress = document.getElementById('documentUploadProgress');
    const bar = document.getElementById('documentProgressBar');
    const pct = document.getElementById('documentProgressText');
    const msg = document.getElementById('uploadMessage');
    const saveBtn = document.getElementById('saveDocumentBtn');

    if (urlInput) urlInput.value = '';
    if (previewImg) previewImg.removeAttribute('src');
    if (preview) preview.classList.add('hidden');
    if (progress) progress.classList.add('hidden');
    if (bar) bar.style.width = '0%';
    if (pct) pct.textContent = '0%';
    if (msg) { msg.classList.add('hidden'); msg.textContent = ''; }

    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.classList.add('opacity-50', 'cursor-not-allowed');
        saveBtn.textContent = 'SAVE DOCUMENT';
    }

    selectDocumentType('Image');
}

/* ==================== CLOUDINARY UPLOAD ==================== */
async function uploadEventDocumentToCloudinary() {
    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';

    fileInput.addEventListener('change', async function (e) {
        const file = e.target.files && e.target.files[0];
        if (!file) return;

        if (file.size > 5 * 1024 * 1024) {
            showNotification('Image size must be less than 5MB', 'error');
            return;
        }

        const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (validTypes.indexOf(file.type) === -1) {
            showNotification('Please select a valid image file (JPEG, PNG, GIF, WebP)', 'error');
            return;
        }

        const progress = document.getElementById('documentUploadProgress');
        const bar = document.getElementById('documentProgressBar');
        const pct = document.getElementById('documentProgressText');
        const status = document.getElementById('documentUploadStatus');

        progress.classList.remove('hidden');
        bar.style.width = '10%';
        pct.textContent = '10%';
        status.textContent = 'Uploading to Cloudinary...';

        try {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('upload_preset', 'members_upload');
            formData.append('cloud_name', 'df8bufsta');
            formData.append('folder', 'event_documents/<?= $__eventId ?>');

            const response = await fetch('https://api.cloudinary.com/v1_1/df8bufsta/image/upload', {
                method: 'POST',
                body: formData
            });

            bar.style.width = '70%';
            pct.textContent = '70%';
            status.textContent = 'Processing image...';

            const data = await response.json();

            if (data && data.error) {
                throw new Error(data.error.message || 'Upload failed');
            }

            if (!data || !data.secure_url) {
                throw new Error('No secure URL returned from Cloudinary');
            }

            bar.style.width = '100%';
            pct.textContent = '100%';
            status.textContent = 'Upload complete!';

            document.getElementById('document_cloudinary_url').value = data.secure_url;
            document.getElementById('documentPreviewImage').src = data.secure_url;
            document.getElementById('documentCloudinaryPreview').classList.remove('hidden');

            const saveBtn = document.getElementById('saveDocumentBtn');
            saveBtn.disabled = false;
            saveBtn.classList.remove('opacity-50', 'cursor-not-allowed');

            showNotification('Image uploaded. Click SAVE DOCUMENT to attach it.', 'success');

            setTimeout(() => progress.classList.add('hidden'), 2000);
        } catch (error) {
            progress.classList.add('hidden');
            const msg = (error && error.message) ? error.message : 'Upload failed';
            showNotification(msg.indexOf('preset') !== -1
                ? 'Cloudinary upload preset not found. Please check your upload preset name.'
                : 'Upload failed: ' + msg, 'error');
        }
    });

    fileInput.click();
}

/* Document type selection */
function selectDocumentType(type) {
    document.getElementById('documentFileType').value = type;

    const buttons = document.querySelectorAll('.document-type-btn');
    buttons.forEach((btn) => {
        btn.classList.remove('border-blue-500', 'bg-blue-500/10');
        btn.classList.add('border-gray-700', 'bg-transparent');
    });

    const selected = type === 'Image'
        ? document.getElementById('typeImageBtn')
        : document.getElementById('typeReceiptBtn');

    if (selected) {
        selected.classList.add('border-blue-500', 'bg-blue-500/10');
    }
}

/* Save to database */
async function saveEventDocument() {
    const urlInput = document.getElementById('document_cloudinary_url');
    const fileTypeInput = document.getElementById('documentFileType');
    const csrfInput = document.querySelector('#documentForm input[name="csrf_token"]');
    const saveBtn = document.getElementById('saveDocumentBtn');
    const uploadMessage = document.getElementById('uploadMessage');
    const eventId = <?= $__eventId ?>;

    const cloudinaryUrl = urlInput ? urlInput.value.trim() : '';
    const fileType = fileTypeInput ? fileTypeInput.value : 'Image';

    const fail = (text) => {
        uploadMessage.textContent = text;
        uploadMessage.classList.remove('hidden');
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            saveBtn.textContent = 'SAVE DOCUMENT';
        }
    };

    if (!cloudinaryUrl) {
        fail('Please upload an image first.');
        return;
    }

    if (cloudinaryUrl.indexOf('https://res.cloudinary.com/') !== 0) {
        fail('Invalid image URL. Please use the Cloudinary uploader.');
        return;
    }

    saveBtn.disabled = true;
    saveBtn.textContent = 'SAVING...';

    const params = new URLSearchParams();
    params.set('event_id', String(eventId));
    params.set('file_type', fileType);
    params.set('cloudinary_url', cloudinaryUrl);
    if (csrfInput) {
        params.set('csrf_token', csrfInput.value);
    }

    try {
        const response = await fetch('../actions/process_add_document.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: params.toString()
        });

        const raw = await response.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (err) {
            throw new Error('Unexpected server response (HTTP ' + response.status + ').');
        }

        if (data && data.success) {
            closeDocumentModal();
            showNotification(data.message || 'Document saved.', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            throw new Error((data && (data.error || data.message)) || 'Failed to save document');
        }
    } catch (error) {
        fail((error && error.message) ? error.message : 'Failed to save document');
    }
}

/* ==================== TOASTS (guarded) ==================== */
if (typeof window.createToastContainer !== 'function') {
    window.createToastContainer = function () {
        const existing = document.getElementById('toastContainer');
        if (existing) return existing;

        const container = document.createElement('div');
        container.id = 'toastContainer';
        document.body.appendChild(container);

        if (!document.getElementById('arnisToastStyles')) {
            const style = document.createElement('style');
            style.id = 'arnisToastStyles';
            style.textContent = [
                '@keyframes slideOutTop { to { transform: translateY(-100px); opacity: 0; } }',
                '@keyframes slideInFromTop {',
                '  from { transform: translateY(-100px); opacity: 0; }',
                '  to   { transform: translateY(0); opacity: 1; }',
                '}',
                '.animate-in { animation: slideInFromTop 0.3s ease-out; }'
            ].join('\n');
            document.head.appendChild(style);
        }

        return container;
    };
}

if (typeof window.showNotification !== 'function') {
    window.showNotification = function (message, type) {
        type = type || 'success';

        const toastContainer = window.createToastContainer();
        const toast = document.createElement('div');
        toast.className = 'fixed top-6 left-1/2 -translate-x-1/2 px-6 py-4 rounded-xl shadow-2xl border flex items-center gap-3 '
            + 'transform transition-all duration-300 ease-out animate-in z-[9999] '
            + (type === 'success'
                ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
                : 'bg-amber-500/10 border-amber-500/30 text-amber-400');

        const icon = document.createElement('i');
        icon.className = type === 'success' ? 'fas fa-check-circle text-lg' : 'fas fa-exclamation-circle text-lg';

        const text = document.createElement('span');
        text.className = 'text-sm font-semibold';
        text.textContent = String(message == null ? '' : message);

        toast.appendChild(icon);
        toast.appendChild(text);
        toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideOutTop 0.3s ease-in forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    };
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('documentFileType')) {
        selectDocumentType('Image');
    }
});
</script>