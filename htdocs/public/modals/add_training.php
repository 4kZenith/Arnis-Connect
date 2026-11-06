<?php
if (!isset($coaches) || !is_array($coaches)) {
    require_once __DIR__ . '/../../config/Database.php';
    require_once __DIR__ . '/../../classes/Trainings.php';
    $db = (new Database())->connect();
    $trainingsModel = new Trainings($db);
    $coaches = $trainingsModel->getCoaches();
}
?>

<div id="addTrainingModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="addTrainingCard" class="max-h-[88vh] flex flex-col overflow-hidden card-bg w-full max-w-3xl rounded-3xl shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">
        <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
            <h2 class="text-xl tracking-wide font-bold italic uppercase text-purple-400">Schedule Training</h2>
            <button onclick="closeTrainingModal()" class="text-gray-500 hover:text-white transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form id="addTrainingForm" onsubmit="submitTrainingForm(event)" class="p-8 flex-1 overflow-y-auto">
            <?php if (function_exists('arnisCsrfToken')): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Training Date</label>
                    <input type="date" name="training_date" required class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Training Type</label>
                    <select name="training_type" id="training_type" required onchange="toggleTrainingFields('add')" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white cursor-pointer">
                        <option value="Regular" selected>Regular Group</option>
                        <option value="Private">Private Session</option>
                        <option value="Custom">Custom Session</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Target Player Group</label>
                    <select name="target_group" id="add_target_group" onchange="toggleTrainingFields('add')" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white cursor-pointer">
                        <option value="Public">Public Players</option>
                        <option value="Private">Private Players</option>
                        <option value="Mixed" selected>Mixed / Both</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Venue Type</label>
                    <select name="venue_type" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white cursor-pointer">
                        <option value="School">School / Public Venue</option>
                        <option value="Private Venue">Private Venue</option>
                        <option value="Custom" selected>Custom Location</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Start Time</label>
                    <input type="time" name="start_time" required class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">End Time</label>
                    <input type="time" name="end_time" required class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Location / Session Title</label>
                    <input type="text" name="location" required placeholder="Ex. CTU Gym, Barangay Hall, School Court, Home Service" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div id="add_fee_wrapper">
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Fee Amount</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-blue-400 text-sm">₱</span>
                        <input type="number" name="fee_amount" id="add_fee_amount" placeholder="0.00" step="0.01" class="w-full bg-blue-500/5 border border-blue-500/30 rounded-xl pl-8 pr-4 py-3 text-sm focus:outline-none focus:border-blue-400 text-blue-400 font-bold">
                    </div>
                    <p class="text-[10px] text-gray-500 mt-2">Public sessions usually stay at ₱0.00. Private sessions may have a fee.</p>
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Lead Coach</label>
                    <select name="coach_id" required class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white cursor-pointer">
                        <?php foreach ($coaches as $coach): ?>
                            <option value="<?= $coach['coach_id'] ?>">
                                <?= htmlspecialchars($coach['full_name']) ?> (<?= htmlspecialchars($coach['member_type']) ?><?= !empty($coach['player_category']) ? ' • ' . htmlspecialchars($coach['player_category']) : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Venue Location (Map Picker)</label>
                    <div id="addMap" class="w-full h-64 rounded-xl border border-gray-700 mb-2 overflow-hidden"></div>
                    <p class="text-[9px] text-gray-500 mt-1">Search for a place (barangay, school, gym) in the box on the map, pick a result, then click the exact spot to pin the venue. This auto-fills latitude and longitude for geofence check-in.</p>
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Venue Latitude</label>
                    <input type="number" name="venue_lat" id="add_venue_lat" step="0.0000001" placeholder="e.g., 10.3157" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Venue Longitude</label>
                    <input type="number" name="venue_lng" id="add_venue_lng" step="0.0000001" placeholder="e.g., 123.8854" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Geofence Radius (meters)</label>
                    <input type="number" name="geofence_m" min="10" max="1000" value="100" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                    <p class="text-[9px] text-gray-500 mt-1">Default 100m. Members must be within this radius to check in.</p>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Session Notes / Focus</label>
                    <textarea name="description" rows="4" placeholder="Describe the session focus, target members, or if the location is custom." class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition resize-none text-white"></textarea>
                </div>
            </div>

            <div class="flex gap-4 mt-8">
                <button type="button" onclick="closeTrainingModal()" class="flex-1 px-6 py-4 rounded-2xl hover:bg-white/5 transition text-[10px] font-bold tracking-widest text-gray-400 uppercase">Cancel</button>
                <button type="submit" class="flex-1 px-6 py-4 bg-[#bf46c7] rounded-2xl hover:opacity-90 transition text-[10px] font-bold tracking-widest shadow-lg shadow-purple-900/20 uppercase">Schedule Session</button>
            </div>
        </form>
    </div>
</div>

<script>
let addPicker = null;

function toggleTrainingFields(prefix) {
    const type = document.getElementById(prefix === 'add' ? 'training_type' : 'edit_training_type')?.value;
    const target = document.getElementById(prefix === 'add' ? 'add_target_group' : 'edit_target_group')?.value;
    const feeInput = document.getElementById(prefix === 'add' ? 'add_fee_amount' : 'edit_fee_amount');
    if (!feeInput) return;

    if (target === 'Public') {
        feeInput.value = '0';
        feeInput.setAttribute('readonly', 'readonly');
        feeInput.classList.add('opacity-60', 'cursor-not-allowed');
    } else {
        feeInput.removeAttribute('readonly');
        feeInput.classList.remove('opacity-60', 'cursor-not-allowed');
        if (type === 'Private' && (!feeInput.value || feeInput.value === '0')) {
            feeInput.value = '0';
        }
    }
}

function submitTrainingForm(event) {
    event.preventDefault();
    const formData = new FormData(document.getElementById('addTrainingForm'));
    fetch('../actions/process_add_training.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        alert(data.message || (data.success ? 'Training scheduled successfully.' : 'Failed to schedule training.'));
        if (data.success) {
            closeTrainingModal();
            window.location.reload();
        }
    }).catch(() => alert('Failed to schedule training.'));
}

function openTrainingModal() {
    const modal = document.getElementById('addTrainingModal');
    const card = document.getElementById('addTrainingCard');
    modal.classList.remove('hidden');
    requestAnimationFrame(() => {
        modal.classList.remove('opacity-0');
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
    document.body.style.overflow = 'hidden';
    toggleTrainingFields('add');
    /*
     * Location picker: create it lazily (the div has no size until the modal
     * is on screen), then re-measure once the open transition has settled so
     * Leaflet lays out tiles correctly instead of rendering a black box.
     */
    setTimeout(() => {
        if (typeof createLocationPicker === 'function' && !addPicker) {
            addPicker = createLocationPicker({
                containerId: 'addMap',
                latInputId: 'add_venue_lat',
                lngInputId: 'add_venue_lng'
            });
        }
        if (!addPicker) return;
        addPicker.invalidate();
        setTimeout(() => {
            addPicker.invalidate();
            const lat = parseFloat(document.getElementById('add_venue_lat').value);
            const lng = parseFloat(document.getElementById('add_venue_lng').value);
            if (!isNaN(lat) && !isNaN(lng)) {
                addPicker.setMarker(lat, lng, { zoom: 16 });
            } else {
                addPicker.flyHome();
            }
        }, 350);
    }, 350);
}

function closeTrainingModal() {
    const modal = document.getElementById('addTrainingModal');
    const card = document.getElementById('addTrainingCard');
    modal.classList.add('opacity-0');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
        document.getElementById('addTrainingForm').reset();
        document.getElementById('add_target_group').value = 'Mixed';
        if (addPicker) {
            addPicker.clearMarker();
        }
        toggleTrainingFields('add');
    }, 250);
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('addTrainingModal').classList.contains('hidden')) {
        closeTrainingModal();
    }
});
toggleTrainingFields('add');
</script>
