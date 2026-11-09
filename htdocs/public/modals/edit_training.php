<?php
/* This modal expects $coaches array and $training array to be available */
/* If not, it will load them automatically */
if (!isset($coaches) || !is_array($coaches)) {
    require_once __DIR__ . '/../../config/Database.php';
    require_once __DIR__ . '/../../classes/Trainings.php';
    $db = (new Database())->connect();
    $trainingsModel = new Trainings($db);
    $coaches = $trainingsModel->getCoaches();
}

if (!isset($training) || !is_array($training)) {
    $training = [];
}

/*
 * attendance_session.php includes this modal with the current row in scope and
 * its "Edit Session" button calls openEditTrainingModal() with NO argument, so
 * the form used to open blank (and then failed validation on save). Render the
 * row here as the JS fallback. Pages that pass an explicit payload, or that
 * have no $training (trainings.php), simply get null.
 */
$editTrainingDefaults = null;
if (!empty($training['training_id']) && empty($trainingNotFound)) {
    $editTrainingDefaults = $training;
}
?>

<div id="editTrainingModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="editTrainingCard" class="max-h-[88vh] flex flex-col overflow-hidden card-bg w-full max-w-3xl rounded-3xl shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">
        <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
            <h2 class="text-xl tracking-wide font-bold italic uppercase text-purple-400">Edit Training Session</h2>
            <button onclick="closeEditTrainingModal()" class="text-gray-500 hover:text-white transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form id="editTrainingForm" onsubmit="submitEditTrainingForm(event)" class="p-8 flex-1 overflow-y-auto">
            <?php if (function_exists('arnisCsrfToken')): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>
            <input type="hidden" name="training_id" id="edit_training_id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Training Date</label>
                    <input type="date" name="training_date" id="edit_training_date" required class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Training Type</label>
                    <select name="training_type" id="edit_training_type" required onchange="toggleTrainingFields('edit')" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white cursor-pointer">
                        <option value="Regular">Regular Group</option>
                        <option value="Private">Private Session</option>
                        <option value="Custom">Custom Session</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Target Player Group</label>
                    <select name="target_group" id="edit_target_group" onchange="toggleTrainingFields('edit')" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white cursor-pointer">
                        <option value="Public">Public Players</option>
                        <option value="Private">Private Players</option>
                        <option value="Mixed">Mixed / Both</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Venue Type</label>
                    <select name="venue_type" id="edit_venue_type" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white cursor-pointer">
                        <option value="School">School / Public Venue</option>
                        <option value="Private Venue">Private Venue</option>
                        <option value="Custom">Custom Location</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Start Time</label>
                    <input type="time" name="start_time" id="edit_start_time" required class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">End Time</label>
                    <input type="time" name="end_time" id="edit_end_time" required class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Location / Session Title</label>
                    <input type="text" name="location" id="edit_location" required placeholder="Ex. CTU Gym, Barangay Hall, School Court, Home Service" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>

                <div id="edit_fee_wrapper">
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Fee Amount</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-blue-400 text-sm">₱</span>
                        <input type="number" name="fee_amount" id="edit_fee_amount" placeholder="0.00" step="0.01" class="w-full bg-blue-500/5 border border-blue-500/30 rounded-xl pl-8 pr-4 py-3 text-sm focus:outline-none focus:border-blue-400 text-blue-400 font-bold">
                    </div>
                    <p class="text-[10px] text-gray-500 mt-2">Public sessions usually stay at ₱0.00. Private sessions may have a fee.</p>
                </div>

                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Lead Coach</label>
                    <select name="coach_id" id="edit_coach_id" required class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white cursor-pointer">
                        <?php foreach ($coaches as $coach): ?>
                            <option value="<?= $coach['coach_id'] ?>">
                                <?= htmlspecialchars($coach['full_name']) ?> (<?= htmlspecialchars($coach['member_type']) ?><?= !empty($coach['player_category']) ? ' • ' . htmlspecialchars($coach['player_category']) : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Venue Location (Map Picker)</label>
                    <div id="editMap" class="w-full h-64 rounded-xl border border-gray-700 mb-2 overflow-hidden"></div>
                    <p class="text-[9px] text-gray-500 mt-1">Search for a place (barangay, school, gym) in the box on the map, pick a result, then click the exact spot to pin the venue. This auto-fills latitude and longitude for geofence check-in.</p>
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Venue Latitude</label>
                    <input type="number" name="venue_lat" id="edit_venue_lat" step="0.0000001" placeholder="e.g., 10.3157" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Venue Longitude</label>
                    <input type="number" name="venue_lng" id="edit_venue_lng" step="0.0000001" placeholder="e.g., 123.8854" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Geofence Radius (meters)</label>
                    <input type="number" name="geofence_m" id="edit_geofence_m" min="10" max="1000" value="100" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                    <p class="text-[9px] text-gray-500 mt-1">Default 100m. Members must be within this radius to check in.</p>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Session Notes / Focus</label>
                    <textarea name="description" id="edit_description" rows="4" placeholder="Describe the session focus, target members, or if the location is custom." class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition resize-none text-white"></textarea>
                </div>
            </div>

            <div class="flex gap-4 mt-8">
                <button type="button" onclick="closeEditTrainingModal()" class="flex-1 px-6 py-4 rounded-2xl hover:bg-white/5 transition text-[10px] font-bold tracking-widest text-gray-400 uppercase">Cancel</button>
                <button type="submit" class="flex-1 px-6 py-4 bg-[#bf46c7] rounded-2xl hover:opacity-90 transition text-[10px] font-bold tracking-widest shadow-lg shadow-purple-900/20 uppercase">Update Session</button>
            </div>
        </form>
    </div>
</div>

<script>
const EDIT_TRAINING_DEFAULTS = <?= json_encode($editTrainingDefaults, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null' ?>;
let editPicker = null;

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

function submitEditTrainingForm(event) {
    event.preventDefault();
    const formData = new FormData(document.getElementById('editTrainingForm'));
    fetch('../actions/process_edit_training.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(data => {
        alert(data.message || (data.success ? 'Training updated successfully.' : 'Failed to update training.'));
        if (data.success) {
            closeEditTrainingModal();
            window.location.reload();
        }
    }).catch(() => alert('Failed to update training.'));
}

function openEditTrainingModal(trainingData = null) {
    // Explicit payload, or the row the including page rendered for us.
    const data = trainingData || EDIT_TRAINING_DEFAULTS;
    // Populate the form
    if (data) {
        document.getElementById('edit_training_id').value = data.training_id || '';
        document.getElementById('edit_training_date').value = data.training_date || '';
        document.getElementById('edit_training_type').value = data.training_type || 'Regular';
        document.getElementById('edit_target_group').value = data.target_group || 'Mixed';
        document.getElementById('edit_venue_type').value = data.venue_type || 'Custom';
        document.getElementById('edit_start_time').value = data.start_time || '';
        document.getElementById('edit_end_time').value = data.end_time || '';
        document.getElementById('edit_location').value = data.location || '';
        document.getElementById('edit_fee_amount').value = data.fee_amount !== null ? data.fee_amount : '';
        document.getElementById('edit_coach_id').value = data.coach_id || '';
        document.getElementById('edit_venue_lat').value = data.venue_lat !== null ? data.venue_lat : '';
        document.getElementById('edit_venue_lng').value = data.venue_lng !== null ? data.venue_lng : '';
        document.getElementById('edit_geofence_m').value = data.geofence_m !== null ? data.geofence_m : '100';
        document.getElementById('edit_description').value = data.description || '';
    }

    const modal = document.getElementById('editTrainingModal');
    const card = document.getElementById('editTrainingCard');
    modal.classList.remove('hidden');
    requestAnimationFrame(() => {
        modal.classList.remove('opacity-0');
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
    document.body.style.overflow = 'hidden';
    toggleTrainingFields('edit');
    /*
     * Location picker: this modal is shared by trainings.php AND
     * attendance_session.php, so the map must be fully self-contained here
     * (no helpers from the including page). Created lazily because the div
     * has no size until the modal is on screen, then re-measured after the
     * open transition so Leaflet lays out tiles instead of a black box.
     */
    setTimeout(() => {
        if (typeof createLocationPicker === 'function' && !editPicker) {
            editPicker = createLocationPicker({
                containerId: 'editMap',
                latInputId: 'edit_venue_lat',
                lngInputId: 'edit_venue_lng'
            });
        }
        if (!editPicker) return;
        editPicker.invalidate();

        const latVal = parseFloat(document.getElementById('edit_venue_lat').value);
        const lngVal = parseFloat(document.getElementById('edit_venue_lng').value);

        setTimeout(() => {
            editPicker.invalidate();
            if (!isNaN(latVal) && !isNaN(lngVal)) {
                editPicker.setMarker(latVal, lngVal, { zoom: 16 });
            } else {
                editPicker.flyHome();
            }
        }, 350);
    }, 350);
}

function closeEditTrainingModal() {
    const modal = document.getElementById('editTrainingModal');
    const card = document.getElementById('editTrainingCard');
    modal.classList.add('opacity-0');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
        document.getElementById('editTrainingForm').reset();
        document.getElementById('edit_target_group').value = 'Mixed';
        toggleTrainingFields('edit');
        if (editPicker) {
            editPicker.clearMarker();
        }
    }, 250);
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('editTrainingModal').classList.contains('hidden')) {
        closeEditTrainingModal();
    }
});

toggleTrainingFields('edit');
</script>