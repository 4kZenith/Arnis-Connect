<style>
    input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }
</style>

<div id="addMemberModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div id="addMemberCard" class="card-bg w-full max-w-3xl rounded-3xl max-h-[88vh] flex flex-col overflow-hidden shadow-2xl transform transition-all duration-300 ease-out scale-95 opacity-0">
        <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
            <h2 class="text-xl tracking-wide font-bold italic uppercase text-purple-400">ADD NEW MEMBER</h2>
            <button onclick="closeMemberModal()" class="text-gray-500 hover:text-white transition">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form id="addMemberForm" class="p-8 flex-1 overflow-y-auto">
            <?php if (function_exists('arnisCsrfToken')): ?>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <input type="hidden" name="cloudinary_url" id="add_cloudinary_url">
            <input type="file" id="add_cloudinaryFileInput" accept="image/jpeg,image/png,image/gif,image/webp" class="hidden">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-xs uppercase mb-2">Full Name</label>
                    <input type="text" name="full_name" required class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition">
                </div>

                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2 tracking-widest">Date of Birth</label>
                    <input type="date" name="dob" id="dobInput" required class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2 tracking-widest">Age</label>
                    <input type="number" id="ageInput" readonly placeholder="Auto" class="w-full bg-[#1d212a]/50 rounded-xl px-4 py-3 text-sm text-gray-400 cursor-not-allowed">
                </div>

                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Email</label>
                    <input type="email" name="email" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition">
                </div>
                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Contact Number</label>
                    <input type="text" name="contact_number" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition">
                </div>

                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Rank</label>
                    <select name="rank" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
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
                    <label class="block text-gray-500 text-xs uppercase mb-2">Member Role</label>
                    <select name="member_type" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                        <option value="Player" selected>Player</option>
                        <option value="Athlete">Athlete</option>
                        <option value="Trainer">Trainer</option>
                        <option value="Coach">Coach</option>
                        <?php if (function_exists('hasRole') && hasRole('SuperAdmin')): ?>
                        <option value="Head Coach">Head Coach</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Player Category</label>
                    <select name="player_category" id="add_player_category" onchange="syncMemberPaymentUI('add')" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                        <option value="Public" selected>Public Player</option>
                        <option value="Private">Private Player</option>
                        <option value="Mixed">Mixed / Both</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Training Preference</label>
                    <select name="training_preference" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                        <option value="Group" selected>Group</option>
                        <option value="Private">Private</option>
                        <option value="Flexible">Flexible</option>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Height (cm)</label>
                    <input type="number" name="height" step="0.01" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition">
                </div>
                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Weight (kg)</label>
                    <input type="number" name="weight" step="0.01" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition">
                </div>

                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Status</label>
                    <select name="status" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                        <option value="On Leave">On Leave</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Affiliated School / Club</label>
                    <input type="text" name="affiliated_school" placeholder="Public school, private club, barangay, etc." class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-xs uppercase mb-2">Address</label>
                    <input type="text" name="address" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition">
                </div>

                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Guardian Name</label>
                    <input type="text" name="guardian_name" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition">
                </div>
                <div>
                    <label class="block text-gray-500 text-xs uppercase mb-2">Guardian Contact</label>
                    <input type="text" name="guardian_contact" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-xs uppercase mb-2">Payment / Program Notes</label>
                    <textarea name="payment_notes" id="add_payment_notes" rows="3" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition resize-none" placeholder="For public players: usually free. For private players: note training package or payment arrangement."></textarea>
                </div>

                <div class="md:col-span-2">
                    <div class="rounded-xl border border-purple-500/20 bg-purple-500/5 p-4 space-y-4">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-user-lock text-purple-300"></i>
                            <span class="text-[10px] font-black uppercase tracking-[0.18em] text-purple-200">Login Account</span>
                        </div>
                        <p class="text-[10px] text-gray-400 leading-relaxed">Every member is linked to a login account. The username is optional (defaults to <span class="text-gray-200 font-bold">member.&lt;id&gt;</span>), but the password is <span class="text-gray-200 font-bold">required</span> — new accounts never receive the shared default password.</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-500 text-xs uppercase mb-2">Username</label>
                                <input type="text" name="username" maxlength="50" pattern="[A-Za-z0-9._-]{3,50}" placeholder="e.g. member.juan" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition">
                            </div>
                            <div>
                                <label class="block text-gray-500 text-xs uppercase mb-2">Password</label>
                                <input type="text" name="password" maxlength="100" required placeholder="Min 6 characters" class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-gray-500 text-xs uppercase mb-2">Profile Picture (Optional)</label>
                    <div class="text-center p-4 bg-gradient-to-r from-purple-900/20 to-pink-900/20 rounded-xl border border-purple-700/30 mb-4">
                        <img id="add_profile_preview" src="https://upload.wikimedia.org/wikipedia/commons/0/03/Twitter_default_profile_400x400.png" alt="Preview" class="w-24 h-24 rounded-full object-cover mx-auto mb-4 border-2 border-purple-500/30">
                        <button type="button" onclick="document.getElementById('add_cloudinaryFileInput').click()"
                                class="w-full px-5 py-2 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 rounded-xl transition text-sm font-semibold shadow-lg shadow-purple-900/30">
                            <i class="fas fa-cloud-upload-alt mr-2"></i>Choose file
                        </button>
                        <p class="text-xs text-gray-400 mt-2">Select an image from your computer</p>
                    </div>

                    <!-- Upload Progress -->
                    <div id="add_uploadProgress" class="hidden mb-3">
                        <div class="flex items-center gap-3">
                            <div class="flex-1 bg-gray-800 rounded-full h-2">
                                <div id="add_progressBar" class="bg-purple-500 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                            </div>
                            <span id="add_progressText" class="text-xs text-gray-400">0%</span>
                        </div>
                        <p id="add_uploadStatus" class="text-xs text-gray-500 mt-2 text-center"></p>
                    </div>

                    <!-- Preview -->
                    <div id="add_cloudinaryPreview" class="hidden text-center mb-3">
                        <p class="text-gray-500 text-sm mb-2">New Image Preview:</p>
                        <img id="add_newPreviewImage" src="" alt="Preview" class="w-24 h-24 rounded-full object-cover border-4 border-gray-700 mx-auto">
                        <p class="text-xs text-green-400 mt-2">Ready to save!</p>
                    </div>

                    <!-- Validation message -->
                    <div id="add_uploadMessage" class="text-xs text-red-400 hidden"></div>
                </div>
            </div>

            <div class="flex gap-4 mt-8">
                <button type="button" onclick="closeMemberModal()" class="flex-1 px-6 py-3 rounded-3xl hover:bg-gray-800 transition text-sm font-semibold">CANCEL</button>
                <button type="button" onclick="submitMemberForm()" id="saveMemberBtn" class="flex-1 px-6 py-3 bg-[#bf46c7] rounded-3xl hover:opacity-90 transition text-sm font-semibold shadow-lg shadow-purple-900/20">SAVE MEMBER</button>
            </div>
        </form>
    </div>
</div>

<script>
function calculateAgeFromDOB(dateString) {
    if (!dateString) return '';
    const dob = new Date(dateString);
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
    return age >= 0 ? age : '';
}

function openMemberModal() {
    const modal = document.getElementById('addMemberModal');
    const card = document.getElementById('addMemberCard');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
}

function closeMemberModal() {
    const modal = document.getElementById('addMemberModal');
    const card = document.getElementById('addMemberCard');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
        document.getElementById('addMemberForm').reset();
        document.getElementById('add_profile_preview').src = 'https://upload.wikimedia.org/wikipedia/commons/0/03/Twitter_default_profile_400x400.png';
        document.getElementById('add_cloudinary_url').value = '';
        document.getElementById('add_cloudinaryFileInput').value = '';
        syncMemberPaymentUI('add');
    }, 250);
}

document.getElementById('dobInput')?.addEventListener('change', function() {
    document.getElementById('ageInput').value = calculateAgeFromDOB(this.value);
});

function syncMemberPaymentUI(prefix) {
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
}

function submitMemberForm() {
    const form = document.getElementById('addMemberForm');
    const formData = new FormData(form);

    fetch('../actions/process_add_member.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message || (data.success ? 'Member saved successfully.' : 'Failed to save member.'));
        if (data.success) {
            closeMemberModal();
            window.location.reload();
        }
    })
    .catch(() => alert('Failed to save member.'));
}

/* ==================== CLOUDINARY UPLOAD ==================== */
const ADD_CLOUD_NAME = 'c1xfzhkb';
const ADD_UPLOAD_PRESET = 'members_upload';
const ADD_ALLOWED_UPLOAD_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const ADD_MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

function setAddUploadProgress(percent, statusText) {
    const progressEl = document.getElementById('add_uploadProgress');
    const barEl = document.getElementById('add_progressBar');
    const pctEl = document.getElementById('add_progressText');
    const statusEl = document.getElementById('add_uploadStatus');
    if (progressEl) progressEl.classList.remove('hidden');
    if (barEl) barEl.style.width = percent + '%';
    if (pctEl) pctEl.textContent = percent + '%';
    if (statusEl && statusText !== undefined) statusEl.textContent = statusText;
}

function showAddUploadMessage(text) {
    const box = document.getElementById('add_uploadMessage');
    if (box) {
        box.textContent = text;
        box.classList.remove('hidden');
    }
}

function hideAddUploadMessage() {
    const box = document.getElementById('add_uploadMessage');
    if (box) {
        box.classList.add('hidden');
    }
}

document.getElementById('add_cloudinaryFileInput')?.addEventListener('change', async function (event) {
    const file = event.target.files && event.target.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
        showNotification('Image size must be less than 5MB', 'error');
        event.target.value = '';
        return;
    }

    if (['image/jpeg', 'image/png', 'image/gif', 'image/webp'].indexOf(file.type) === -1) {
        showNotification('Please select a valid image file (JPEG, PNG, GIF, WebP)', 'error');
        event.target.value = '';
        return;
    }

    document.getElementById('add_uploadMessage')?.classList.add('hidden');
    setAddUploadProgress(10, 'Uploading to Cloudinary...');

    try {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('upload_preset', ADD_UPLOAD_PRESET);

        const response = await fetch('https://api.cloudinary.com/v1_1/' + ADD_CLOUD_NAME + '/image/upload', {
            method: 'POST',
            body: formData
        });

        setAddUploadProgress(70, 'Processing image...');

        const data = await response.json().catch(() => ({}));

        if (data.error) {
            throw new Error(data.error.message || 'Upload failed');
        }

        if (!data.secure_url) {
            throw new Error('No secure URL returned from Cloudinary');
        }

        setAddUploadProgress(100, 'Upload complete!');

        document.getElementById('add_cloudinary_url').value = data.secure_url;
        document.getElementById('add_profile_preview').src = data.secure_url;

        showNotification('Image uploaded successfully!', 'success');

        setTimeout(() => {
            const progressEl = document.getElementById('add_uploadProgress');
            if (progressEl) progressEl.classList.add('hidden');
        }, 2000);
    } catch (error) {
        console.error('Cloudinary upload error:', error);
        const progressEl = document.getElementById('add_uploadProgress');
        if (progressEl) progressEl.classList.add('hidden');

        if (error.message && error.message.indexOf('upload preset') !== -1) {
            showAddUploadMessage('Cloudinary upload preset not found. Check the UPLOAD_PRESET constant.');
            showNotification('Cloudinary upload preset not found.', 'error');
        } else {
            showAddUploadMessage(error.message || 'Upload failed');
            showNotification('Upload failed: ' + (error.message || 'unknown error'), 'error');
        }
    }
});

syncMemberPaymentUI('add');

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !document.getElementById('addMemberModal').classList.contains('hidden')) {
        closeMemberModal();
    }
});
</script>
