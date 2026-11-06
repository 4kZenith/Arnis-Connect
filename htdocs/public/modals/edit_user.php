<div id="editUserModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="editUserCard" class="card-bg w-full max-w-lg rounded-3xl max-h-[80vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">

        <div class="p-6 border-b border-gray-800 flex justify-between items-center bg-white/5">
            <h2 class="text-lg font-bold italic uppercase tracking-wider text-orange-400">Edit User Account</h2>
            <button type="button" onclick="closeEditUserModal()" class="text-gray-500 hover:text-white transition"><i class="fas fa-times"></i></button>
        </div>

        <form id="editUserForm" class="p-8 space-y-5 flex-1 overflow-y-auto">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" id="edit_user_id" name="user_id" value="">

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Full Name</label>
                <input type="text" id="edit_full_name" name="full_name" required maxlength="100"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-orange-500 transition text-white">
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Username</label>
                <input type="text" id="edit_username" name="username" required maxlength="50" pattern="[A-Za-z0-9._-]{3,50}"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-orange-500 transition text-white">
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Email (optional)</label>
                <input type="email" id="edit_email" name="email" maxlength="100"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-orange-500 transition text-white">
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Linked Member ID (optional)</label>
                <input type="number" id="edit_member_id" name="member_id" min="0" step="1"
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-orange-500 transition text-white">
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">System Role</label>
                <select id="edit_role" name="role" required class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none appearance-none cursor-pointer text-white border border-transparent focus:border-orange-500 transition">
                    <?php foreach ((array)($roles ?? require __DIR__ . '/../../config/roles.php') as $roleKey => $roleMeta): ?>
                        <option value="<?= htmlspecialchars((string)$roleKey, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars((string)($roleMeta['label'] ?? $roleKey), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="bg-orange-500/10 border border-orange-500/20 rounded-xl p-4">
                <div class="flex items-center mb-3">
                    <i class="fas fa-key text-orange-400 mr-2"></i>
                    <label class="block text-orange-400 text-[10px] uppercase font-bold tracking-widest">Change Password (Optional)</label>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-gray-400 text-[9px] uppercase font-bold mb-1 tracking-widest">New Password</label>
                        <input type="password" id="edit_new_password" name="new_password" maxlength="100" placeholder="Enter new password" autocomplete="new-password"
                               class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-orange-500 transition text-white">
                    </div>

                    <div>
                        <label class="block text-gray-400 text-[9px] uppercase font-bold mb-1 tracking-widest">Confirm New Password</label>
                        <input type="password" id="edit_confirm_password" name="confirm_password" maxlength="100" placeholder="Confirm new password" autocomplete="new-password"
                               class="w-full bg-[#1d212a] rounded-xl px-4 py-3 text-sm focus:outline-none border border-transparent focus:border-orange-500 transition text-white">
                    </div>
                </div>

                <div class="mt-4 bg-[#1d212a] rounded-lg p-3">
                    <p class="text-[8px] text-gray-400 uppercase font-bold tracking-wider mb-1">Password Guidelines:</p>
                    <ul class="text-[7px] text-gray-500 space-y-0.5">
                        <li class="flex items-center"><i class="fas fa-info-circle mr-1 text-[6px]"></i> Leave both fields empty to keep current password</li>
                        <li class="flex items-center"><i class="fas fa-info-circle mr-1 text-[6px]"></i> Minimum 6 characters if changing password</li>
                        <li class="flex items-center"><i class="fas fa-info-circle mr-1 text-[6px]"></i> Both fields must match to change password</li>
                    </ul>
                </div>
            </div>

            <div id="editUserError" class="text-red-500 text-xs hidden"></div>
            <div id="editUserSuccess" class="text-green-500 text-xs hidden">User updated successfully</div>

            <div class="flex gap-4 pt-4">
                <button type="button" onclick="closeEditUserModal()" class="flex-1 px-6 py-4 rounded-2xl hover:bg-white/5 transition text-xs font-bold tracking-widest text-gray-400 uppercase">Cancel</button>
                <button type="submit" class="flex-1 bg-orange-500 text-black font-black py-4 rounded-2xl transition uppercase text-xs tracking-widest hover:bg-orange-400 shadow-lg">
                    Update User
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditUserModal(userId) {
    const id = parseInt(userId, 10);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    const modal = document.getElementById('editUserModal');
    const card = document.getElementById('editUserCard');
    const hiddenInput = document.getElementById('edit_user_id');
    const errorBox = document.getElementById('editUserError');

    if (hiddenInput) {
        hiddenInput.value = String(id);
    }

    errorBox.classList.add('hidden');
    document.getElementById('editUserSuccess').classList.add('hidden');

    document.body.style.overflow = 'hidden';
    modal.classList.remove('hidden');

    fetch('../actions/get_user.php?id=' + encodeURIComponent(String(id)), {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success || !data.data) {
                errorBox.textContent = 'Failed to load user: ' + ((data && (data.error || data.message)) || 'Unknown error');
                errorBox.classList.remove('hidden');
                return;
            }

            const user = data.data;

            document.getElementById('edit_full_name').value = user.full_name || '';
            document.getElementById('edit_username').value = user.username || '';
            document.getElementById('edit_email').value = user.email || '';
            document.getElementById('edit_member_id').value = user.member_id ? String(user.member_id) : '';
            document.getElementById('edit_role').value = user.role || '';

            document.getElementById('edit_new_password').value = '';
            document.getElementById('edit_confirm_password').value = '';

            if (hiddenInput) {
                hiddenInput.value = String(id);
            }

            requestAnimationFrame(() => {
                modal.classList.add('opacity-100');
                card.classList.remove('scale-95', 'opacity-0');
                card.classList.add('scale-100', 'opacity-100');
            });
        })
        .catch(() => {
            errorBox.textContent = 'Error fetching user data.';
            errorBox.classList.remove('hidden');
        });
}

function closeEditUserModal() {
    const modal = document.getElementById('editUserModal');
    const card = document.getElementById('editUserCard');

    modal.classList.remove('opacity-100');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    document.body.style.overflow = 'auto';

    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('editUserForm');
    if (!form) {
        return;
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const errorBox = document.getElementById('editUserError');
        const successBox = document.getElementById('editUserSuccess');
        const submitBtn = form.querySelector('button[type="submit"]');

        const showError = function (message) {
            errorBox.textContent = message;
            errorBox.classList.remove('hidden');
            successBox.classList.add('hidden');
        };

        const newPassword = document.getElementById('edit_new_password').value;
        const confirmPassword = document.getElementById('edit_confirm_password').value;

        if (newPassword || confirmPassword) {
            if (!newPassword || !confirmPassword) {
                showError('Both password fields must be filled if changing password');
                return;
            }
            if (newPassword !== confirmPassword) {
                showError('New passwords do not match');
                return;
            }
            if (newPassword.length < 6) {
                showError('New password must be at least 6 characters');
                return;
            }
        }

        const hiddenInput = document.getElementById('edit_user_id');
        if (!hiddenInput || !hiddenInput.value) {
            showError('User ID not found in form data');
            return;
        }

        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';

        try {
            const response = await fetch('../actions/process_edit_user.php', {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });

            const result = await response.json();

            if (result && result.success) {
                errorBox.classList.add('hidden');
                successBox.classList.remove('hidden');
                setTimeout(() => {
                    closeEditUserModal();
                    window.location.reload();
                }, 1200);
            } else {
                showError((result && (result.error || result.message)) || 'Failed to update user');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        } catch (error) {
            showError('Error: could not reach the server.');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    });

    // Close on backdrop click. addEventListener (not window.onclick) so this
    // page cannot clobber another handler.
    window.addEventListener('click', function (event) {
        const modals = [
            { el: document.getElementById('editUserModal'), close: closeEditUserModal },
            { el: document.getElementById('activateModal'), close: closeActivateModal },
            { el: document.getElementById('deactivateModal'), close: closeDeactivateModal },
            { el: document.getElementById('addUserModal'), close: closeAddUserModal }
        ];

        modals.forEach(function (m) {
            if (m.el && event.target === m.el) {
                m.close();
            }
        });
    });
});
</script>