<div id="voidModal" class="fixed inset-0 bg-black/90 backdrop-blur-sm hidden z-[100] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div id="voidCard" class="card-bg w-full max-w-md rounded-3xl border border-red-500/30 p-8 transform scale-95 transition-all duration-300 shadow-2xl">

        <div class="text-center mb-6">
            <div class="w-16 h-16 bg-red-500/10 text-red-500 rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg">
                <i class="fas fa-ban text-2xl"></i>
            </div>
            <h2 id="voidTitle" class="text-xl font-bold uppercase italic tracking-wider text-white">Void Record?</h2>
            <p class="text-gray-400 text-xs mt-1">You are about to void: <span id="voidTargetName" class="text-red-400 font-bold">Item Name</span></p>
        </div>

        <form id="voidForm" action="../actions/process_void.php" method="POST" class="space-y-5">
            <input type="hidden" name="target_id" id="voidTargetId">
            <input type="hidden" name="target_type" id="voidTargetType">
            <?php if (function_exists('arnisCsrfToken')): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Reason for Voiding</label>
                <textarea
                    name="void_reason"
                    id="voidReasonInput"
                    required
                    maxlength="255"
                    placeholder="e.g., Incorrect entry, Event cancelled, Duplicate record..."
                    class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-red-500 transition h-24 resize-none text-white"
                ></textarea>
                <p class="text-[9px] text-gray-600 mt-2 italic">Note: This action will be logged under your account.</p>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeVoidModal()" class="flex-1 py-4 rounded-2xl bg-gray-800 text-[10px] font-bold uppercase tracking-[0.2em] text-gray-400 hover:text-white transition">
                    Cancel
                </button>
                <button type="submit" class="flex-1 py-4 rounded-2xl bg-red-600 text-black font-black text-[10px] uppercase tracking-[0.2em] hover:bg-red-500 transition shadow-lg shadow-red-900/20">
                    Confirm Void
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    /* Guarded assignment: this modal is included together with add_document.php
       and edit_event.php, which used to declare the same two functions at
       top-level scope. That produced "Cannot redeclare showNotification()"
       and a 500 on event_detail.php. Only the first definition now wins. */
    if (typeof window.normalizeType !== 'function') {
        window.normalizeType = function (t) {
            if (!t) return '';
            const map = {
                'training': 'Training',
                'member': 'Member',
                'event': 'Event',
                'fund': 'Fund',
                'attendance': 'Attendance',
                'achievement': 'Achievement',
                'document': 'Document',
                'event_document': 'Document',
                'participant': 'Participant'
            };
            const key = String(t).toLowerCase();
            return map[key] || (key.charAt(0).toUpperCase() + key.slice(1));
        };
    }

    if (typeof window.openVoidModal !== 'function') {
        window.openVoidModal = function (type, id, name) {
            const modal = document.getElementById('voidModal');
            const card = document.getElementById('voidCard');
            if (!modal || !card) return;

            const normType = window.normalizeType(type);
            const intId = parseInt(id, 10) || 0;

            const title = document.getElementById('voidTitle');
            const target = document.getElementById('voidTargetName');
            const idInput = document.getElementById('voidTargetId');
            const typeInput = document.getElementById('voidTargetType');
            const reason = document.getElementById('voidReasonInput');

            title.textContent = 'Void ' + normType + '?';
            target.textContent = (name === null || name === undefined || name === '') ? 'Item' : String(name);

            idInput.value = intId;
            typeInput.value = normType;
            reason.value = '';

            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';

            setTimeout(() => {
                modal.classList.add('opacity-100');
                card.classList.remove('scale-95');
                card.classList.add('scale-100');
                reason.focus();
            }, 10);
        };
    }

    if (typeof window.closeVoidModal !== 'function') {
        window.closeVoidModal = function () {
            const modal = document.getElementById('voidModal');
            const card = document.getElementById('voidCard');
            if (!modal || !card) return;

            modal.classList.remove('opacity-100');
            card.classList.replace('scale-100', 'scale-95');

            setTimeout(() => {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }, 300);
        };
    }

    /* ---------- toasts (guarded — see note above) ---------- */

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
                    '@keyframes slideOutTop { to { transform: translateY(-400px); opacity: 0; } }',
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

            /* textContent, never innerHTML — the message can echo DB content. */
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

    /* ---------- session flash on load ---------- */

    document.addEventListener('DOMContentLoaded', () => {
        <?php
        $__success = $_SESSION['success'] ?? null;
        unset($_SESSION['success']);
        $__error = $_SESSION['error'] ?? null;
        unset($_SESSION['error']);
        ?>
        <?php if (is_string($__success) && $__success !== ''): ?>
            window.showNotification(<?= htmlspecialchars((string) json_encode($__success, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>, 'success');
        <?php endif; ?>

        <?php if (is_string($__error) && $__error !== ''): ?>
            window.showNotification(<?= htmlspecialchars((string) json_encode($__error, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>, 'error');
        <?php endif; ?>
    });

    /* Close on backdrop click */
    window.addEventListener('click', function (event) {
        const modal = document.getElementById('voidModal');
        if (modal && event.target === modal) {
            window.closeVoidModal();
        }
    });

    /* Close on Escape */
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            const modal = document.getElementById('voidModal');
            if (modal && !modal.classList.contains('hidden')) {
                window.closeVoidModal();
            }
        }
    });
</script>