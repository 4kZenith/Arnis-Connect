<?php
/**
 * Record Transaction modal  —  public/modals/add_transaction.php
 * Included by: public/funds.php
 *
 * Fixes in this revision:
 *  - showNotification() / createToastContainer() are now GUARDED assignments.
 *    This file and edit_transaction.php both declared them at top-level script
 *    scope, which made funds.php fatal: "Cannot redeclare showNotification()".
 *  - Added the csrf_token hidden input (process_transaction.php requires it,
 *    so every save was returning HTTP 403).
 *  - Removed window.onclick = ... : it overwrote any other handler and threw
 *    ReferenceError on EVERY click because it referenced closeEditUserModal,
 *    which does not exist on this page.
 *  - Toast text written with textContent (was innerHTML).
 *  - Submit button disabled while in flight (double-click could insert twice).
 *  - Event bootstrap wrapped in try/catch + fetches 500 rows, not the
 *    default 25 (dropdown silently truncated).
 */

$txEvents = [];

try {
    require_once __DIR__ . '/../../config/Database.php';
    require_once __DIR__ . '/../../classes/Events.php';

    $txDb = (new Database())->connect();
    $txEventsModel = new Events($txDb);
    $txEvents = $txEventsModel->getAll([], 500, 1);
} catch (Throwable $e) {
    error_log('[add_transaction] event list failed: ' . $e->getMessage());
    $txEvents = [];
}
?>

<div id="transactionModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="transactionCard" class="card-bg w-full max-w-lg rounded-3xl max-h-[80vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">

        <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
            <h2 class="text-xl font-bold italic uppercase tracking-wider text-purple-400">Record Transaction</h2>
            <button type="button" onclick="closeTransactionModal()" class="text-gray-500 hover:text-white transition" title="Close">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form id="transactionForm" onsubmit="submitTransactionForm(event)" class="p-8 space-y-5 flex-1 overflow-y-auto">

            <?php if (function_exists('arnisCsrfToken')): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <!-- Transaction Type Toggle -->
            <div class="flex gap-4 p-1 bg-[#1d212a] rounded-2xl">
                <label class="flex-1 cursor-pointer">
                    <input type="radio" name="tr_type" value="Income" class="hidden peer" checked>
                    <div class="text-center py-3 rounded-xl font-bold text-xs uppercase peer-checked:bg-green-500 peer-checked:text-black transition text-gray-400">Income</div>
                </label>
                <label class="flex-1 cursor-pointer">
                    <input type="radio" name="tr_type" value="Expense" class="hidden peer">
                    <div class="text-center py-3 rounded-xl font-bold text-xs uppercase peer-checked:bg-red-500 peer-checked:text-black transition text-gray-400">Expense</div>
                </label>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Amount (&#8369;) *</label>
                    <input type="number" name="amount" step="0.01" min="0.01" max="99999999.99" required placeholder="0.00"
                           class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm font-bold focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Date *</label>
                    <input type="date" name="transaction_date" required
                           class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Purpose / Source *</label>
                <input type="text" name="source_or_purpose" placeholder="e.g. Talent Fee, Mat Purchase..." required maxlength="255"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Link to Event (Optional)</label>
                <select name="related_event_id" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none appearance-none cursor-pointer border border-transparent focus:border-purple-500 transition text-white">
                    <option value="">None (General Fund)</option>
                    <?php foreach ($txEvents as $txEvent): ?>
                        <option value="<?= (int) ($txEvent['event_id'] ?? 0) ?>"><?= htmlspecialchars((string) ($txEvent['event_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($txEvents)): ?>
                    <p class="text-[9px] text-gray-600 mt-1">No active events available to link.</p>
                <?php endif; ?>
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Notes</label>
                <textarea name="notes" placeholder="Additional details..." maxlength="255"
                          class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition h-20 resize-none text-white"></textarea>
            </div>

            <div id="transactionError" class="text-xs text-red-400 hidden"></div>

            <div class="flex gap-4 mt-6">
                <button type="button" onclick="closeTransactionModal()"
                        class="flex-1 px-6 py-4 rounded-2xl hover:bg-white/5 transition text-xs font-bold tracking-widest text-gray-400 uppercase">CANCEL</button>
                <button type="submit" id="transactionSubmitBtn"
                        class="flex-1 px-6 py-4 bg-[#bf46c7] rounded-2xl hover:opacity-90 transition text-xs font-bold shadow-lg shadow-purple-900/20 uppercase tracking-widest">SAVE TRANSACTION</button>
            </div>
        </form>
    </div>
</div>

<script>
/* ────────────────────────────────────────────────
   Toasts — GUARDED.
   add_transaction.php and edit_transaction.php are both included by
   funds.php. Top-level `function` declarations in two loaded scripts
   throw "Cannot redeclare showNotification()". Conditional assignment
   makes every copy idempotent regardless of include order.
   ──────────────────────────────────────────────── */
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

/* ────────────────────────────────────────────────
   Submit Transaction via AJAX
   ──────────────────────────────────────────────── */
async function submitTransactionForm(event) {
    if (event) {
        event.preventDefault();
    }

    const form = document.getElementById('transactionForm');
    const submitBtn = document.getElementById('transactionSubmitBtn');
    const errorBox = document.getElementById('transactionError');

    if (!form) return;

    if (errorBox) {
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    }

    const originalLabel = submitBtn ? submitBtn.textContent : 'SAVE TRANSACTION';
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        submitBtn.textContent = 'SAVING...';
    }

    try {
        const response = await fetch('../actions/process_transaction.php', {
            method: 'POST',
            body: new FormData(form),
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        const raw = await response.text();
        let payload;
        try {
            payload = JSON.parse(raw);
        } catch (e) {
            throw new Error('Unexpected server response (HTTP ' + response.status + ').');
        }

        if (payload && payload.success) {
            closeTransactionModal();
            form.reset();
            showNotification(payload.message || 'Transaction recorded successfully.', 'success');
            setTimeout(() => location.reload(), 1500);
            return;
        }

        throw new Error((payload && (payload.message || payload.error)) || 'Failed to record transaction.');
    } catch (err) {
        const text = (err && err.message) ? err.message : 'Could not connect to the server.';
        if (errorBox) {
            errorBox.textContent = text;
            errorBox.classList.remove('hidden');
        } else {
            showNotification(text, 'error');
        }
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            submitBtn.textContent = originalLabel;
        }
    }
}

/* ────────────────────────────────────────────────
   Modal Controls
   ──────────────────────────────────────────────── */
function openTransactionModal() {
    const modal = document.getElementById('transactionModal');
    const card = document.getElementById('transactionCard');
    const errorBox = document.getElementById('transactionError');
    if (!modal || !card) return;

    if (errorBox) {
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    }

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        modal.classList.replace('opacity-0', 'opacity-100');
        card.classList.replace('scale-95', 'scale-100');
        card.classList.replace('opacity-0', 'opacity-100');
    });
}

function closeTransactionModal() {
    const modal = document.getElementById('transactionModal');
    const card = document.getElementById('transactionCard');
    if (!modal || !card) return;

    modal.classList.replace('opacity-100', 'opacity-0');
    card.classList.replace('scale-100', 'scale-95');
    card.classList.replace('opacity-100', 'opacity-0');

    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }, 300);
}

/* Backdrop click.
   Uses addEventListener (not window.onclick =) so it cannot clobber other
   handlers, and every close function is probed with `typeof` because these
   modals are also included by pages where the sibling modals are absent —
   referencing an undeclared identifier throws ReferenceError. */
document.addEventListener('click', function (event) {
    const pairs = [
        ['transactionModal', 'closeTransactionModal'],
        ['editTransactionModal', 'closeEditTransactionModal'],
        ['editUserModal', 'closeEditUserModal'],
        ['voidModal', 'closeVoidModal']
    ];

    pairs.forEach(function (pair) {
        const el = document.getElementById(pair[0]);
        const fn = window[pair[1]];
        if (el && typeof fn === 'function' && event.target === el) {
            fn();
        }
    });
});

/* Close on Escape */
document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;

    if (typeof window.closeTransactionModal === 'function') {
        const modal = document.getElementById('transactionModal');
        if (modal && !modal.classList.contains('hidden')) {
            window.closeTransactionModal();
        }
    }
});
</script>