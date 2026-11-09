<div id="addEventModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="addEventCard" class="card-bg w-full max-w-2xl rounded-3xl max-h-[85vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">
        <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
            <div>
                <h2 class="text-xl tracking-wide font-bold uppercase italic text-purple-400">Create New Event</h2>
                <p class="text-[10px] uppercase tracking-[0.16em] text-gray-500 mt-1">Set the target player group so suggestions work properly.</p>
            </div>
            <button onclick="closeEventModal()" class="text-gray-500 hover:text-white transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form id="addEventForm" class="p-8 flex-1 overflow-y-auto">
            <?php if (function_exists('arnisCsrfToken')): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold text-purple-400">Event Name</label>
                    <input type="text" name="event_name" placeholder="e.g. City Meet 2026" required class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Event Type</label>
                    <select name="event_type" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none appearance-none cursor-pointer text-white border border-transparent focus:border-purple-500 transition">
                        <option value="Performance">Performance</option>
                        <option value="Tournament">Tournament</option>
                        <option value="Outreach">Outreach</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Date</label>
                    <input type="date" name="event_date" required class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Target Player Group</label>
                    <select name="target_player_category" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none cursor-pointer text-white border border-transparent focus:border-purple-500 transition">
                        <option value="Mixed">Mixed / Both</option>
                        <option value="Public">Public Players</option>
                        <option value="Private">Private Players</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Expected Participants</label>
                    <input type="number" min="1" max="50" name="expected_participants" value="8" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Event Handler / Lead Coach</label>
                    <select name="handler_id" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none appearance-none cursor-pointer text-white border border-transparent focus:border-purple-500 transition">
                        <option value="">Unassigned</option>
                        <?php foreach (($eventHandlers ?? []) as $handler): ?>
                        <option value="<?= (int)$handler['member_id'] ?>">
                            <?= htmlspecialchars((string)$handler['full_name'], ENT_QUOTES, 'UTF-8') ?>
                            (<?= htmlspecialchars((string)$handler['member_type'], ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-[9px] text-gray-600 mt-1">The handler can manage this event (participants, attendance, documents) even if they did not create it.</p>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Location (Text)</label>
                    <input type="text" name="location" placeholder="Where will the event happen?" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase tracking-widest mb-2 font-bold">Brief Description</label>
                    <textarea name="description" placeholder="Include notes that can help coaches pick the right players..." class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition h-24 resize-none text-white"></textarea>
                </div>
            </div>

            <div class="mt-8 flex gap-4">
                <button type="button" onclick="closeEventModal()" class="flex-1 px-6 py-4 rounded-2xl hover:bg-white/5 transition text-xs font-bold tracking-widest text-gray-400">CANCEL</button>
                <button type="button" onclick="submitEventForm()" class="flex-1 px-6 py-4 bg-[#bf46c7] rounded-2xl hover:opacity-90 transition text-xs font-bold shadow-lg shadow-purple-900/20 uppercase tracking-widest">Create Event</button>
            </div>
        </form>
    </div>
</div>
<script>
function openEventModal() {
    const modal = document.getElementById('addEventModal');
    const card = document.getElementById('addEventCard');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
}

function closeEventModal() {
    const modal = document.getElementById('addEventModal');
    const card = document.getElementById('addEventCard');
    modal.classList.remove('opacity-100');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }, 300);
}

function showNotification(message, type = 'success') {
    const toastContainer = document.getElementById('toastContainer') || createToastContainer();
    const toast = document.createElement('div');
    toast.className = `fixed top-6 left-1/2 -translate-x-1/2 px-6 py-4 rounded-xl shadow-2xl border flex items-center gap-3 transform transition-all duration-300 ease-out ${type === 'success' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400' : 'bg-amber-500/10 border-amber-500/30 text-amber-400'} animate-in slide-in-from-top-5 z-[9999]`;
    const icon = type === 'success' ? '<i class="fas fa-check-circle text-lg"></i>' : '<i class="fas fa-exclamation-circle text-lg"></i>';
    toast.innerHTML = `${icon} <span class="text-sm font-semibold">${message}</span>`;
    toastContainer.appendChild(toast);
    setTimeout(() => {
        toast.style.animation = 'slideOutTop 0.3s ease-in forwards';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    document.body.appendChild(container);
    const style = document.createElement('style');
    style.textContent = '@keyframes slideOutTop { to { transform: translateY(-400px); opacity: 0; } }';
    document.head.appendChild(style);
    return container;
}

function submitEventForm() {
    const form = document.getElementById('addEventForm');
    const formData = new FormData(form);
    fetch('../actions/process_add_event.php', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }})
        .then(response => response.text())
        .then(text => {
            const jsonData = JSON.parse(text);
            if (jsonData.success) {
                closeEventModal();
                form.reset();
                showNotification('Event created successfully!', 'success');
                setTimeout(() => location.reload(), 1800);
            } else {
                showNotification('Error: ' + (jsonData.message || 'Failed to create event.'), 'error');
            }
        })
        .catch(() => showNotification('Error: An error occurred while creating the event.', 'error'));
}
</script>
