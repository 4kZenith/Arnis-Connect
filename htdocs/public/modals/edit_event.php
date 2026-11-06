<div id="editEventModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="editEventCard" class="card-bg w-full max-w-2xl rounded-3xl max-h-[85vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">
        <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
            <div>
                <h2 class="text-xl tracking-wide font-bold uppercase italic">Edit Event Details</h2>
                <p class="text-[10px] uppercase tracking-[0.16em] text-gray-500 mt-1">Update the target group to improve player suggestions.</p>
            </div>
            <button type="button" onclick="closeEditEventModal()" class="text-gray-500 hover:text-white transition" title="Close"><i class="fas fa-times text-xl"></i></button>
        </div>

        <form id="editEventForm" class="p-8 flex-1 overflow-y-auto" onsubmit="return false;">
            <?php if (function_exists('arnisCsrfToken')): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Event Name</label>
                    <input type="text" id="editEventName" name="event_name" required maxlength="255" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Event Type</label>
                    <select id="editEventType" name="event_type" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none appearance-none cursor-pointer text-white border border-transparent focus:border-purple-500 transition">
                        <option value="Performance">Performance</option>
                        <option value="Tournament">Tournament</option>
                        <option value="Outreach">Outreach</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Date</label>
                    <input type="date" id="editEventDate" name="event_date" required class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none focus:border-purple-500 transition text-white border border-transparent">
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Target Player Group</label>
                    <select id="editTargetPlayerCategory" name="target_player_category" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none cursor-pointer text-white border border-transparent focus:border-purple-500 transition">
                        <option value="Mixed">Mixed / Both</option>
                        <option value="Public">Public Players</option>
                        <option value="Private">Private Players</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Expected Participants</label>
                    <input type="number" min="0" max="500" id="editExpectedParticipants" name="expected_participants" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
<p class="text-[9px] text-gray-600 mt-1">0 = not set</p>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Event Handler / Lead Coach</label>
                    <select id="editEventHandler" name="handler_id" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none appearance-none cursor-pointer text-white border border-transparent focus:border-purple-500 transition">
                        <option value="">Unassigned</option>
                        <?php foreach (($eventHandlers ?? []) as $handler): ?>
                        <option value="<?= (int)$handler['member_id'] ?>">
                            <?= htmlspecialchars((string)$handler['full_name'], ENT_QUOTES, 'UTF-8') ?>
                            (<?= htmlspecialchars((string)$handler['member_type'], ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[9px] text-gray-600 mt-1">The handler can manage this event even if they did not create it.</p>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Location (Text)</label>
                    <input type="text" id="editEventLocation" name="location" maxlength="255" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none focus:border-purple-500 transition text-white border border-transparent">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Description</label>
                    <textarea id="editEventDescription" name="description" maxlength="1000" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none focus:border-purple-500 transition h-24 resize-none text-white border border-transparent"></textarea>
                </div>
            </div>
            <div id="editEventError" class="text-xs text-red-400 mt-4 hidden"></div>
            <div class="mt-8 flex gap-4">
                <button type="button" onclick="closeEditEventModal()" class="flex-1 px-6 py-4 rounded-2xl hover:bg-white/5 transition text-xs font-bold tracking-widest text-gray-400 uppercase">Discard Changes</button>
                <button type="submit" class="flex-1 px-6 py-4 bg-purple-600 rounded-2xl hover:bg-purple-500 transition text-xs font-bold text-white uppercase tracking-widest shadow-lg shadow-purple-900/20">Update Event</button>
            </div>
        </form>
    </div>
</div>
<script>
function openEditEventModal(eventId) {
    const modal = document.getElementById('editEventModal');
    const card = document.getElementById('editEventCard');
    const form = document.getElementById('editEventForm');
    const errorBox = document.getElementById('editEventError');
    if (!modal || !card || !form) return;

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });

    if (errorBox) { errorBox.textContent = ''; errorBox.classList.add('hidden'); }

    fetch('../actions/get_event.php?id=' + encodeURIComponent(String(eventId)), {
        headers: { 'Accept': 'application/json' }
    })
        .then(async (r) => {
            const raw = await r.text();
            let payload;
            try {
                payload = JSON.parse(raw);
            } catch (e) {
                throw new Error('Unexpected server response (HTTP ' + r.status + ').');
            }
            if (!payload || payload.success !== true || !payload.data) {
                throw new Error((payload && (payload.message || payload.error)) || 'Could not load this event.');
            }
            return payload.data;
        })
        .then((event) => {
            document.getElementById('editEventName').value = event.event_name || '';
            document.getElementById('editEventType').value = event.event_type || '';
            document.getElementById('editEventDate').value = event.event_date || '';
            document.getElementById('editEventLocation').value = event.location || '';
            document.getElementById('editEventDescription').value = event.description || '';
            document.getElementById('editTargetPlayerCategory').value = event.target_player_category || 'Mixed';
            document.getElementById('editExpectedParticipants').value =
                (event.expected_participants === null || event.expected_participants === undefined) ? 0 : event.expected_participants;
            const handlerSelect = document.getElementById('editEventHandler');
            if (handlerSelect) {
                handlerSelect.value = (event.handler_id === null || event.handler_id === undefined) ? '' : String(event.handler_id);
            }
            form.dataset.eventId = String(eventId);
        })
        .catch((err) => {
            if (errorBox) {
                errorBox.textContent = (err && err.message) ? err.message : 'Could not load this event.';
                errorBox.classList.remove('hidden');
            }
        });
}

function closeEditEventModal() {
    const modal = document.getElementById('editEventModal');
    const card = document.getElementById('editEventCard');
    if (!modal || !card) return;

    modal.classList.remove('opacity-100');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');

    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }, 300);
}

/* ---- toasts: guarded assignment, this modal is co-included with
 add_document.php and void_confirmation.php ---- */
if (typeof window.showNotification !== 'function') {
    window.showNotification = function (message, type) {
        type = type || 'success';

        let toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toastContainer';
            document.body.appendChild(toastContainer);
        }

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

if (typeof window.createToastContainer !== 'function') {
    window.createToastContainer = function () {
        const existing = document.getElementById('toastContainer');
        if (existing) return existing;
        const c = document.createElement('div');
        c.id = 'toastContainer';
        document.body.appendChild(c);
        return c;
    };
}

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('editEventForm');
    const errorBox = document.getElementById('editEventError');
    if (!form) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const eventId = form.dataset.eventId;
        if (!eventId) {
            if (errorBox) {
                errorBox.textContent = 'This event could not be loaded. Close and try again.';
                errorBox.classList.remove('hidden');
            }
            return;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalLabel = submitBtn.textContent;
        if (errorBox) { errorBox.textContent = ''; errorBox.classList.add('hidden'); }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Updating...';

        try {
            const formData = new FormData(form);
            formData.set('event_id', String(eventId));

            const response = await fetch('../actions/process_edit_event.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });

            const raw = await response.text();
            let payload;
            try {
                payload = JSON.parse(raw);
            } catch (err) {
                throw new Error('Unexpected server response (HTTP ' + response.status + ').');
            }

            if (payload && payload.success) {
                closeEditEventModal();
                showNotification(payload.message || 'Event updated successfully.', 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                throw new Error((payload && (payload.error || payload.message)) || 'Failed to update event.');
            }
        } catch (err) {
            if (errorBox) {
                errorBox.textContent = (err && err.message) ? err.message : 'Failed to update event.';
                errorBox.classList.remove('hidden');
            }
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
        }
    });
});
</script>