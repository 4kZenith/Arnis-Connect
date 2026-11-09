<?php
/**
 * Edit Transaction modal  —  public/modals/edit_transaction.php
 * Included by: public/funds.php
 *
 * Field names (fund_id / edit_tr_type / amount / transaction_date /
 * purpose / event_id / notes) intentionally match
 * actions/process_edit_transaction.php — verified, not renamed.
 *
 * Fixes in this revision:
 *  - showNotification()/createToastContainer() guarded (was a 2-way
 *    "Cannot redeclare" fatal against add_transaction.php).
 *  - csrf_token hidden input added.
 *  - addslashes() flash injection replaced with hex json_encode.
 *  - null values (event_id, notes, purpose, date) no longer render as the
 *    literal string "null" / leave the <select> blank.
 *  - Guards against a missing/invalid data.id.
 *  - ESC-to-close.
 */

$txEditEvents = [];

try {
    require_once __DIR__ . '/../../config/Database.php';
    require_once __DIR__ . '/../../classes/Events.php';

    $txEditDb = (new Database())->connect();
    $txEditModel = new Events($txEditDb);
    $txEditEvents = $txEditModel->getAll([], 500, 1);
} catch (Throwable $e) {
    error_log('[edit_transaction] event list failed: ' . $e->getMessage());
    $txEditEvents = [];
}
?>

<div id="editTransactionModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="editTransactionCard" class="card-bg w-full max-w-lg rounded-3xl max-h-[80vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">
        <div class="p-6 border-b border-gray-800 flex justify-between items-center bg-white/5">
            <div>
                <h2 class="text-lg font-bold italic uppercase tracking-wider text-purple-400">Edit Transaction</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest">Update record details</p>
            </div>
            <button type="button" onclick="closeEditTransactionModal()" class="text-gray-500 hover:text-white transition" title="Close"><i class="fas fa-times"></i></button>
        </div>

        <form action="../actions/process_edit_transaction.php" method="POST" class="p-8 space-y-5 flex-1 overflow-y-auto">
            <input type="hidden" name="fund_id" id="edit_fund_id">

            <?php if (function_exists('arnisCsrfToken')): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="flex gap-4 p-1 bg-[#1d212a] rounded-2xl">
                <label class="flex-1 cursor-pointer">
                    <input type="radio" name="edit_tr_type" value="Income" id="edit_type_income" class="hidden peer">
                    <div class="text-center py-3 rounded-xl font-bold text-xs uppercase peer-checked:bg-green-500 peer-checked:text-black transition text-gray-500">Income</div>
                </label>
                <label class="flex-1 cursor-pointer">
                    <input type="radio" name="edit_tr_type" value="Expense" id="edit_type_expense" class="hidden peer">
                    <div class="text-center py-3 rounded-xl font-bold text-xs uppercase peer-checked:bg-red-500 peer-checked:text-black transition text-gray-500">Expense</div>
                </label>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Amount (&#8369;)</label>
                    <input type="number" step="0.01" min="0.01" max="99999999.99" name="amount" id="edit_amount" required
                           class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm font-bold focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Date</label>
                    <input type="date" name="transaction_date" id="edit_date" required
                           class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Purpose / Source</label>
                <input type="text" name="purpose" id="edit_purpose" required maxlength="255"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Link to Event</label>
                <select name="event_id" id="edit_event" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none appearance-none cursor-pointer text-white">
                    <option value="">None (General Fund)</option>
                    <?php foreach ($txEditEvents as $txEditEvent): ?>
                        <option value="<?= (int) ($txEditEvent['event_id'] ?? 0) ?>"><?= htmlspecialchars((string) ($txEditEvent['event_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Notes (Optional)</label>
                <textarea name="notes" id="edit_notes" maxlength="255" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none h-20 resize-none text-white"></textarea>
            </div>

            <div id="editTransactionError" class="text-xs text-red-400 hidden"></div>

            <button type="submit" class="w-full bg-white text-black font-black py-4 rounded-2xl transition uppercase text-xs tracking-widest hover:bg-purple-500 hover:text-white shadow-lg">
                Save Changes
            </button>
        </form>
    </div>
</div>

<script>
/**
 * Populate + open the Edit Transaction modal.
 * funds.php calls this with:
 *   { id, type, amount, date, purpose, event_id, notes }
 * where event_id / notes / purpose / date may be null.
 */
function openEditTransactionModal(data) {
    const modal = document.getElementById('editTransactionModal');
    const card = document.getElementById('editTransactionCard');
    const errorBox = document.getElementById('editTransactionError');

    if (!modal || !card || !data) return;

    const id = parseInt(data.id, 10) || 0;
    if (id <= 0) {
        if (errorBox) {
            errorBox.textContent = 'This transaction could not be loaded. Close the modal and try again.';
            errorBox.classList.remove('hidden');
        }
        return;
    }

    if (errorBox) {
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    }

    /* ---- populate ---- */
    document.getElementById('edit_fund_id').value = String(id);
    document.getElementById('edit_amount').value =
        (data.amount === null || data.amount === undefined || data.amount === '') ? '' : String(data.amount);
    document.getElementById('edit_date').value =
        (data.date === null || data.date === undefined) ? '' : String(data.date).slice(0, 10);
    document.getElementById('edit_purpose').value =
        (data.purpose === null || data.purpose === undefined) ? '' : String(data.purpose);
    document.getElementById('edit_notes').value =
        (data.notes === null || data.notes === undefined) ? '' : String(data.notes);

    /* null / undefined / '' must map to the "None (General Fund)" option —
       assigning null sets selectedIndex to -1 and renders a blank dropdown. */
    const eventSelect = document.getElementById('edit_event');
    const eventValue = (data.event_id === null || data.event_id === undefined || data.event_id === '')
        ? ''
        : String(data.event_id);
    eventSelect.value = eventValue;
    if (eventSelect.selectedIndex === -1) {
        eventSelect.selectedIndex = 0;
    }

    if (data.type === 'Expense') {
        document.getElementById('edit_type_expense').checked = true;
    } else {
        document.getElementById('edit_type_income').checked = true;
    }

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
}

function closeEditTransactionModal() {
    const modal = document.getElementById('editTransactionModal');
    const card = document.getElementById('editTransactionCard');
    if (!modal || !card) return;

    modal.classList.remove('opacity-100');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');

    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }, 300);
}

/* ---- toasts: guarded (co-included with add_transaction.php) ---- */
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

/* ---- session flash.
 Consumed once (the first co-included modal to run gets it); every later
     copy sees null and renders nothing, so there are never duplicate toasts. */
document.addEventListener('DOMContentLoaded', () => {
    <?php
    $__txSuccess = $_SESSION['success'] ?? null;
    unset($_SESSION['success']);
    $__txError = $_SESSION['error'] ?? null;
    unset($_SESSION['error']);
    ?>
    <?php if (is_string($__txSuccess) && $__txSuccess !== ''): ?>
        window.showNotification(<?= htmlspecialchars((string) json_encode($__txSuccess, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>, 'success');
    <?php endif; ?>

    <?php if (is_string($__txError) && $__txError !== ''): ?>
        window.showNotification(<?= htmlspecialchars((string) json_encode($__txError, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>, 'error');
    <?php endif; ?>
});

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    const modal = document.getElementById('editTransactionModal');
    if (modal && !modal.classList.contains('hidden')) {
        window.closeEditTransactionModal();
    }
});
</script>