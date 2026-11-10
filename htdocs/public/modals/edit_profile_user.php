<div id="editUserModal" class="fixed inset-0 bg-black/60 backdrop-blur-md hidden z-[70] flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="editUserCard" class="card-bg w-full max-w-lg rounded-3xl max-h-[80vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">
        
        <div class="p-8 border-b border-gray-800/50 flex justify-between items-center bg-white/[0.02]">
            <h2 class="text-xl font-bold italic uppercase tracking-wider text-white">Edit User Account</h2>
            <button onclick="closeEditUserModal()" class="text-gray-500 hover:text-white transition"><i class="fas fa-times"></i></button>
        </div>

        <form id="editUserForm" class="p-8 grid grid-cols-2 gap-5 flex-1 overflow-y-auto">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="user_id" id="edit_u_id">

            <div class="col-span-2">
                <label class="text-[10px] text-gray-500 uppercase font-black tracking-widest ml-1">Full Name</label>
                <input type="text" name="full_name" id="edit_u_fullname" required 
                       class="w-full bg-black/20 border border-gray-800 rounded-2xl px-5 py-4 text-sm outline-none focus:border-blue-500 transition">
            </div>

            <div class="col-span-1">
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Username</label>
                <input type="text" name="username" id="edit_u_username" required 
                       class="w-full bg-black/20 border border-gray-800 rounded-2xl px-5 py-4 text-sm outline-none focus:border-blue-500 transition">
            </div>

            <div class="col-span-1">
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">System Role</label>
                <div class="w-full bg-[#1d212a]/60 rounded-xl px-4 py-4 text-sm text-gray-300 border border-gray-800">
                    <?= htmlspecialchars((string)($_SESSION['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <input type="hidden" name="role" id="edit_u_role" value="<?= htmlspecialchars((string)($_SESSION['role'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <p class="text-[8px] text-gray-500 mt-2 italic">* System role cannot be changed here.</p>
            </div>
                <p class="text-[8px] text-gray-500 mt-2 italic">* Contact administrator to change user role</p>
            </div>

            <div class="col-span-2 mt-2">
                <div class="p-4 rounded-2xl bg-blue-500/5 border border-blue-500/10">
                    <label class="block text-blue-400 text-[10px] uppercase font-bold mb-3 tracking-widest">Change Password (Optional)</label>
                    
                    <div class="mb-3">
                        <label class="text-gray-500 text-[10px] uppercase font-bold mb-2 block tracking-widest">Current Password</label>
                        <input type="password" name="current_password" id="edit_u_current_password" 
                               class="w-full bg-black/20 border border-gray-800 rounded-2xl px-5 py-3 text-sm outline-none focus:border-blue-500 transition" 
                               placeholder="Required if changing password">
                    </div>
                    
                    <div>
                        <label class="text-gray-500 text-[10px] uppercase font-bold mb-2 block tracking-widest">New Password</label>
                        <input type="password" name="password" id="edit_u_password" 
                               class="w-full bg-black/20 border border-gray-800 rounded-2xl px-5 py-3 text-sm outline-none focus:border-blue-500 transition" 
                               placeholder="Leave blank to keep current password">
                    </div>
                    <p class="text-[9px] text-gray-500 mt-2 italic">* Only fill these out if you wish to change the password.</p>
                </div>
            </div>

            <div id="editUserMessage" class="col-span-2 p-3 rounded-xl text-sm hidden"></div>

            <div class="col-span-2 flex gap-3 mt-4">
                <button type="button" onclick="closeEditUserModal()" 
                        class="flex-1 px-6 py-4 rounded-full hover:bg-white/5 transition text-xs font-bold tracking-widest text-gray-400 uppercase">
                    Cancel
                </button>
                <button type="button" onclick="submitEditUserForm()" id="editUserSubmitBtn"
                        class="flex-1 px-6 py-4 bg-blue-600 rounded-full hover:bg-blue-500 transition text-xs font-bold text-white uppercase tracking-widest shadow-lg shadow-blue-900/20">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditUserModal() {
    const modal = document.getElementById('editUserModal');
    const card = document.getElementById('editUserCard');

    // Prefill the fields with current user data from session
    document.getElementById('edit_u_id').value = '<?php echo isset($_SESSION['user_id']) ? htmlspecialchars($_SESSION['user_id']) : ''; ?>';
    document.getElementById('edit_u_fullname').value = '<?php echo isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : ''; ?>';
    document.getElementById('edit_u_username').value = '<?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : ''; ?>';
    
    // Set the role value but keep it disabled
    const roleSelect = document.getElementById('edit_u_role');
    const currentRole = '<?php echo isset($_SESSION['role']) ? htmlspecialchars($_SESSION['role']) : ''; ?>';
    roleSelect.value = currentRole;
    
    // Clear password fields
    document.getElementById('edit_u_current_password').value = '';
    document.getElementById('edit_u_password').value = '';
    document.getElementById('editUserMessage').textContent = '';
    document.getElementById('editUserMessage').classList.add('hidden');

    // Show with animation
    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.add('opacity-100');
        card.classList.replace('scale-95', 'scale-100');
        card.classList.replace('opacity-0', 'opacity-100');
    }, 10);
}

function closeEditUserModal() {
    const modal = document.getElementById('editUserModal');
    const card = document.getElementById('editUserCard');
    
    modal.classList.remove('opacity-100');
    card.classList.replace('scale-100', 'scale-95');
    card.classList.replace('opacity-100', 'opacity-0');
    setTimeout(() => modal.classList.add('hidden'), 300);
}

function submitEditUserForm() {
    const userId = document.getElementById('edit_u_id').value;
    const fullName = document.getElementById('edit_u_fullname').value.trim();
    const username = document.getElementById('edit_u_username').value.trim();
    const role = document.getElementById('edit_u_role').value; // This is now read-only
    const currentPassword = document.getElementById('edit_u_current_password').value.trim();
    const newPassword = document.getElementById('edit_u_password').value.trim();
    const messageDiv = document.getElementById('editUserMessage');
    const submitBtn = document.getElementById('editUserSubmitBtn');

    // Validation
    if (!fullName) {
        showMessage(messageDiv, 'Full name is required', 'error');
        return;
    }

    if (!username) {
        showMessage(messageDiv, 'Username is required', 'error');
        return;
    }

    // If user is trying to change password
    if (newPassword) {
        if (!currentPassword) {
            showMessage(messageDiv, 'Current password is required to change your password', 'error');
            return;
        }

        if (newPassword.length < 6) {
            showMessage(messageDiv, 'New password must be at least 6 characters', 'error');
            return;
        }
    }

    // Disable button while submitting
    submitBtn.disabled = true;
    submitBtn.classList.add('opacity-50');
    submitBtn.textContent = 'Saving...';

    // Prepare form data
    const formData = new FormData();
    formData.append('csrf_token', document.querySelector('#editUserForm input[name="csrf_token"]')?.value || '');
    formData.append('user_id', userId);
    formData.append('full_name', fullName);
    formData.append('username', username);
    formData.append('role', role); // Still send the role, but it won't be changed
    formData.append('current_password', currentPassword);
    formData.append('password', newPassword);

    // Submit via AJAX
    fetch('../actions/process_edit_user.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(messageDiv, 'Profile updated successfully!', 'success');
            setTimeout(() => {
                // Update session data in UI if needed
                closeEditUserModal();
                // Optional: Show success notification
                if (window.showNotification) {
                    window.showNotification('Profile updated successfully!', 'success');
                }
            }, 1500);
        } else {
            showMessage(messageDiv, data.error || 'An error occurred while updating profile', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showMessage(messageDiv, 'An error occurred: ' + error.message, 'error');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50');
        submitBtn.textContent = 'Save Changes';
    });
}

function showMessage(element, message, type) {
    element.textContent = message;
    element.classList.remove('hidden');
    element.classList.remove('bg-red-500/10', 'text-red-400', 'border', 'border-red-500/30', 'bg-green-500/10', 'text-green-400', 'border-green-500/30');
    
    if (type === 'error') {
        element.classList.add('bg-red-500/10', 'text-red-400', 'border', 'border-red-500/30');
    } else if (type === 'success') {
        element.classList.add('bg-green-500/10', 'text-green-400', 'border', 'border-green-500/30');
    }
}
</script>