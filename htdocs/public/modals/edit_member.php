<div id="editMemberModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="editMemberCard" class="card-bg w-full max-w-3xl rounded-3xl max-h-[88vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">
        <div class="p-6 border-b border-gray-800 flex justify-between items-center bg-white/5">
            <h2 class="text-lg font-bold italic uppercase tracking-wider text-purple-400">Edit Member Profile</h2>
            <button onclick="closeEditMemberModal()" class="text-gray-500 hover:text-white transition"><i class="fas fa-times"></i></button>
        </div>

        <form id="editMemberForm" class="p-8 grid grid-cols-1 md:grid-cols-2 gap-5 flex-1 overflow-y-auto">
            <input type="hidden" name="member_id" id="edit_m_id">
            <?php if (function_exists('arnisCsrfToken')): ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="md:col-span-2">
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Full Name</label>
                <input type="text" name="full_name" id="edit_m_name" required class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-blue-500 transition text-white">
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Date of Birth</label>
                <input type="date" name="dob" id="edit_m_dob" required class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-blue-500 transition text-white">
            </div>
            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Email</label>
                <input type="email" name="email" id="edit_m_email" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-blue-500 transition text-white">
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Contact Number</label>
                <input type="text" name="contact_number" id="edit_m_contact" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-blue-500 transition text-white">
            </div>
            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Rank</label>
                <select name="rank" id="edit_m_rank" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none text-white appearance-none cursor-pointer">
                    <option value="None">None (Beginner)</option>
                    <option value="I. White Belt">I. White Belt</option>
                    <option value="II. Yellow Belt">II. Yellow Belt</option>
                    <option value="III. Green Belt">III. Green Belt</option>
                    <option value="IV. Blue Belt">IV. Blue Belt</option>
                    <option value="V. Brown Belt">V. Brown Belt</option>
                    <option value="VI. Black Belt">VI. Black Belt</option>
                    <option value="1st Degree- Junior Instructor">1st Degree- Junior Instructor</option>
                    <option value="2nd Degree- Junior Instructor">2nd Degree- Junior Instructor</option>
                    <option value="3rd Degree- Instructor">3rd Degree- Instructor</option>
                    <option value="4th Degree- Instructor">4th Degree- Instructor</option>
                    <option value="5th Degree- Senior Instructor">5th Degree- Senior Instructor</option>
                    <option value="6th Degree- Senior Instructor">6th Degree- Senior Instructor</option>
                    <option value="7th Degree- Junior Master">7th Degree- Junior Master</option>
                    <option value="8th Degree- Master">8th Degree- Master</option>
                    <option value="9th Degree- Senior Master">9th Degree- Senior Master</option>
                    <option value="VII. Red Belt">VII. Red Belt</option>
                    <option value="10th Degree- Grandmaster">10th Degree- Grandmaster</option>
                    <option value="11th Degree- Senior Grandmaster">11th Degree- Senior Grandmaster</option>
                    <option value="12th Degree- Superior Grandmaster">12th Degree- Superior Grandmaster</option>
                </select>
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Member Role</label>
                <select name="member_type" id="edit_m_member_type" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none text-white appearance-none cursor-pointer">
                    <option value="Player">Player</option>
                    <option value="Athlete">Athlete</option>
                    <option value="Trainer">Trainer</option>
                    <option value="Coach">Coach</option>
                    <option value="Head Coach">Head Coach</option>
                </select>
            </div>
            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Status</label>
                <select name="status" id="edit_m_status" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none text-white appearance-none cursor-pointer">
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                    <option value="On Leave">On Leave</option>
                </select>
            </div>
            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Player Category</label>
                <select name="player_category" id="edit_m_player_category" onchange="syncMemberPaymentUI('edit')" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none text-white appearance-none cursor-pointer">
                    <option value="Public">Public Player</option>
                    <option value="Private">Private Player</option>
                    <option value="Mixed">Mixed / Both</option>
                </select>
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Training Preference</label>
                <select name="training_preference" id="edit_m_training_preference" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none text-white appearance-none cursor-pointer">
                    <option value="Group">Group</option>
                    <option value="Private">Private</option>
                    <option value="Flexible">Flexible</option>
                </select>
            </div>
            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Affiliated School / Club</label>
                <input type="text" name="affiliated_school" id="edit_m_affiliated_school" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-blue-500 transition text-white">
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Height (cm)</label>
                <input type="number" step="0.01" name="height" id="edit_m_height" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-blue-500 transition text-white">
            </div>
            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Weight (kg)</label>
                <input type="number" step="0.01" name="weight" id="edit_m_weight" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-blue-500 transition text-white">
            </div>

            <div class="md:col-span-2">
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Address</label>
                <input type="text" name="address" id="edit_m_address" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-blue-500 transition text-white">
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Guardian Name</label>
                <input type="text" name="guardian_name" id="edit_m_guardian_name" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-blue-500 transition text-white">
            </div>
            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Guardian Contact</label>
                <input type="text" name="guardian_contact" id="edit_m_guardian_contact" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-blue-500 transition text-white">
            </div>

            <div class="md:col-span-2">
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Payment / Program Notes</label>
                <textarea name="payment_notes" id="edit_payment_notes" rows="3" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-blue-500 transition text-white resize-none"></textarea>
            </div>

            <div class="flex gap-3 md:col-span-2 mt-2">
                <button type="button" onclick="closeEditMemberModal()" class="flex-1 px-6 py-4 rounded-full hover:bg-white/5 transition text-xs font-bold tracking-widest text-gray-400 uppercase">Discard Changes</button>
                <button type="submit" class="flex-1 px-6 py-4 bg-[#bf46c7] rounded-full hover:opacity-90 transition text-xs font-bold tracking-widest uppercase shadow-lg shadow-purple-900/20">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
/* This helper normally lives in modals/add_member.php, which member_profile.php
 * does not include — without it openEditMemberModal() throws a ReferenceError
 * before the modal is ever shown. Guarded so it is only defined once when both
 * modals are loaded (members_management.php). */
if (typeof window.syncMemberPaymentUI !== 'function') {
    window.syncMemberPaymentUI = function (prefix) {
        const categorySelect = document.getElementById(prefix + '_player_category') || document.getElementById(prefix + '_m_player_category');
        const notes = document.getElementById(prefix + '_payment_notes') || document.getElementById(prefix + '_m_payment_notes');
        if (!categorySelect || !notes) return;
        const category = categorySelect.value;
        if (category === 'Public' && notes.value.trim() === '') {
            notes.placeholder = 'Public player: free / school-based training.';
        } else if (category === 'Private' && notes.value.trim() === '') {
            notes.placeholder = 'Private player: paid training arrangement, package, or per-session fee.';
        } else if (category === 'Mixed' && notes.value.trim() === '') {
            notes.placeholder = 'Mixed player: note if the member joins both public and private sessions.';
        } else {
            notes.placeholder = 'Optional notes about training arrangement or payment.';
        }
    };
}

function openEditMemberModal(data) {
    const fieldPerms = window.arnisMemberFieldPermissions || {
        rank: true, status: true, member_type: true, payment_notes: true, player_category: true, full_name: true, dob: true, is_member_self: false
    };
    const managedFields = {
        full_name: 'edit_m_name',
        dob: 'edit_m_dob',
        rank: 'edit_m_rank',
        status: 'edit_m_status',
        member_type: 'edit_m_member_type',
        player_category: 'edit_m_player_category',
        payment_notes: 'edit_payment_notes'
    };
    Object.keys(managedFields).forEach(function (key) {
        const el = document.getElementById(managedFields[key]);
        if (!el) return;
        const allowed = fieldPerms[key] !== false;
        el.disabled = !allowed;
        el.classList.toggle('opacity-50', !allowed);
        el.classList.toggle('cursor-not-allowed', !allowed);
        el.title = allowed ? '' : 'This field is read-only.';
    });

    document.getElementById('edit_m_id').value = data.id || data.member_id || '';
    document.getElementById('edit_m_name').value = data.name || data.full_name || '';
    document.getElementById('edit_m_dob').value = data.dob || '';
    document.getElementById('edit_m_email').value = data.email || '';
    document.getElementById('edit_m_rank').value = data.rank || 'None';
    document.getElementById('edit_m_address').value = data.address || '';
    document.getElementById('edit_m_contact').value = data.contact_number || data.contact || '';
    document.getElementById('edit_m_height').value = data.height || '';
    document.getElementById('edit_m_weight').value = data.weight || '';
    document.getElementById('edit_m_member_type').value = data.member_type || 'Player';
    document.getElementById('edit_m_status').value = data.status || 'Active';
    document.getElementById('edit_m_player_category').value = data.player_category || 'Public';
    document.getElementById('edit_m_training_preference').value = data.training_preference || 'Group';
    document.getElementById('edit_m_affiliated_school').value = data.affiliated_school || '';
    document.getElementById('edit_m_guardian_name').value = data.guardian_name || '';
    document.getElementById('edit_m_guardian_contact').value = data.guardian_contact || '';
    document.getElementById('edit_payment_notes').value = data.payment_notes || '';

    syncMemberPaymentUI('edit');

    const modal = document.getElementById('editMemberModal');
    const card = document.getElementById('editMemberCard');
    modal.classList.remove('hidden');
    requestAnimationFrame(() => {
        modal.classList.remove('opacity-0');
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
    document.body.style.overflow = 'hidden';
}

function closeEditMemberModal() {
    const modal = document.getElementById('editMemberModal');
    const card = document.getElementById('editMemberCard');
    modal.classList.add('opacity-0');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }, 250);
}

document.getElementById('editMemberForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);

    fetch('../actions/process_edit_member.php', {
        method: 'POST',
        body: formData
    })
    .then(async response => {
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Failed to update member');
        }
        alert(data.message || 'Member updated successfully.');
        closeEditMemberModal();
        window.location.reload();
    })
    .catch(error => alert(error.message || 'Failed to update member.'));
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('editMemberModal').classList.contains('hidden')) {
        closeEditMemberModal();
    }
});
</script>
