<div id="addUserModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="addUserCard" class="card-bg w-full max-w-lg rounded-3xl max-h-[80vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">

        <div class="p-6 border-b border-gray-800 flex justify-between items-center bg-white/5">
            <div>
                <h2 class="text-xl tracking-wide font-bold italic uppercase text-purple-400">Create Account</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest">Register a new system user</p>
            </div>
            <button type="button" onclick="closeAddUserModal()" class="text-gray-500 hover:text-white transition"><i class="fas fa-times"></i></button>
        </div>

        <form id="addUserForm" class="p-8 space-y-5 flex-1 overflow-y-auto">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Full Name</label>
                <input type="text" name="full_name" required maxlength="100" placeholder="e.g. Maria Clara" autocomplete="off"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Username</label>
                    <input type="text" name="username" required maxlength="50" pattern="[A-Za-z0-9._-]{3,50}" placeholder="maria_clara" autocomplete="off"
                           class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Access Level</label>
                    <div class="relative">
                        <select name="role" required class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none appearance-none cursor-pointer text-white border border-transparent focus:border-purple-500 transition">
                            <option value="">Select role...</option>
                            <?php foreach ((array)($roles ?? require __DIR__ . '/../../config/roles.php') as $roleKey => $roleMeta):
                                /* Applicants self-register; process_add_user.php
                                 * rejects that role, so do not offer it here. */
                                if ((string)$roleKey === 'Applicant') {
                                    continue;
                                }
                            ?>
                                <option value="<?= htmlspecialchars((string)$roleKey, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string)($roleMeta['label'] ?? $roleKey), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 pointer-events-none"></i>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Email (optional)</label>
                <input type="email" name="email" maxlength="100" placeholder="maria@arnisconnect.local" autocomplete="off"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
            </div>

            <div id="addUserMemberFields" class="hidden space-y-5 rounded-2xl border border-purple-500/20 bg-purple-500/5 p-4">
                <p class="text-[10px] text-purple-300 uppercase font-bold tracking-widest">Member Profile</p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Date of Birth</label>
                        <input type="date" name="dob" max="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"
                               class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                    </div>
                    <div>
                        <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Contact Number</label>
                        <input type="text" name="contact_number" maxlength="20" placeholder="09XXXXXXXXX"
                               class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                    </div>
                </div>
                <div id="addUserCategoryField">
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Player Category</label>
                    <select name="player_category" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none appearance-none cursor-pointer text-white border border-transparent focus:border-purple-500 transition">
                        <option value="Public">Public (free group training)</option>
                        <option value="Private">Private (paid private training)</option>
                        <option value="Mixed">Mixed (both)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Link Existing Member ID (optional)</label>
                    <input type="number" name="member_id" min="1" step="1" placeholder="Leave blank to create a new profile"
                           class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                    <p class="text-[9px] text-gray-600 mt-2 tracking-wider">
                        Only use this for a member record that already exists without a login.
                    </p>
                </div>
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Initial Password</label>
                <input type="password" name="password" required minlength="6" maxlength="100" placeholder="Minimum 6 characters" autocomplete="new-password"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
            </div>

            <button type="submit" class="bg-[#bf46c7] px-8 py-4 rounded-full font-bold text-sm hover:opacity-90 transition shadow-lg shadow-purple-900/20 w-full">
                Register User
            </button>
        </form>
    </div>
</div>

<script>
function openAddUserModal() {
    const modal = document.getElementById('addUserModal');
    const card = document.getElementById('addUserCard');
    const form = document.getElementById('addUserForm');

    form.reset();
    document.body.style.overflow = 'hidden';

    modal.classList.remove('hidden');
    requestAnimationFrame(() => {
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
}

function closeAddUserModal() {
    const modal = document.getElementById('addUserModal');
    const card = document.getElementById('addUserCard');

    modal.classList.remove('opacity-100');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    document.body.style.overflow = 'auto';

    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

// Toast notification functions (textContent only - never innerHTML)
function showNotification(message, type = 'success') {
    const toastContainer = document.getElementById('toastContainer') || createToastContainer();

    const toast = document.createElement('div');
    toast.className =
        'fixed top-6 left-1/2 -translate-x-1/2 px-6 py-4 rounded-xl shadow-2xl border flex items-center gap-3 ' +
        'transform transition-all duration-300 ease-out z-[9999] ' +
        (type === 'success'
            ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
            : 'bg-amber-500/10 border-amber-500/30 text-amber-400');

    const icon = document.createElement('i');
    icon.className = type === 'success'
        ? 'fas fa-check-circle text-lg'
        : 'fas fa-exclamation-circle text-lg';

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
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    document.body.appendChild(container);

    const style = document.createElement('style');
    style.textContent =
        '@keyframes slideOutTop {' +
        '  to { transform: translateY(-400px); opacity: 0; }' +
        '}';
    document.head.appendChild(style);

    return container;
}

function syncAddUserMemberFields() {
    const form = document.getElementById('addUserForm');
    const role = form.elements['role'].value;
    const needsProfile = ['Head Coach', 'Coach/Trainer', 'Member'].includes(role);
    const linking = form.elements['member_id'].value.trim() !== '';
    document.getElementById('addUserMemberFields').classList.toggle('hidden', !needsProfile);
    document.getElementById('addUserCategoryField').classList.toggle('hidden', role !== 'Member');
    form.elements['dob'].required = needsProfile && !linking;
    form.elements['contact_number'].required = needsProfile && !linking;
}

// Handle form submission
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('addUserForm');
    if (!form) {
        return;
    }
    form.elements['role'].addEventListener('change', syncAddUserMemberFields);
    form.elements['member_id'].addEventListener('input', syncAddUserMemberFields);
    form.addEventListener('reset', () => setTimeout(syncAddUserMemberFields, 0));

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        const formData = new FormData(form);

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Creating...';

        fetch('../actions/process_add_user.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(response => response.text().then(text => ({ status: response.status, text })))
        .then(data => {
            let json = null;
            try {
                json = JSON.parse(data.text);
            } catch (err) {
                json = null;
            }

            if (json && json.success) {
                closeAddUserModal();
                form.reset();
                showNotification(json.message || 'User created successfully!', 'success');
                setTimeout(() => window.location.reload(), 1800);
            } else if (json) {
                showNotification('Error: ' + (json.message || 'Failed to create user.'), 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            } else {
                showNotification('Error: Invalid response from server (HTTP ' + data.status + ')', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        })
        .catch(() => {
            showNotification('Error: Could not connect to server', 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        });
    });
});
</script>