<div id="deleteUserModal" class="fixed inset-0 bg-black/90 backdrop-blur-md hidden z-[110] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div id="deleteUserCard" class="card-bg w-full max-w-sm rounded-3xl border border-red-500/30 p-8 text-center transform scale-95 transition-all duration-300 shadow-2xl">

        <div class="w-20 h-20 bg-red-500/10 text-red-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-lg shadow-red-500/5">
            <i class="fas fa-trash text-3xl"></i>
        </div>

        <h2 class="text-xl font-bold uppercase italic tracking-wider mb-2 text-white">Delete User?</h2>
        <p class="text-gray-400 text-sm mb-8 leading-relaxed">
            Permanently remove <br>
            <span id="deleteUserName" class="text-white font-bold text-base">User Name</span>? <br>
            <span class="text-red-400/80 text-[11px] uppercase tracking-widest font-bold mt-2 block">This cannot be undone. Member accounts are deleted together with their training attendance and event participation records.</span>
        </p>

        <div id="deleteUserError" class="text-red-500 text-xs hidden mb-4"></div>

        <input type="hidden" id="deleteUserCsrf" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

        <div class="flex gap-3">
            <button type="button" onclick="closeDeleteUserModal()" class="flex-1 py-4 rounded-2xl bg-gray-800 text-[10px] font-bold uppercase tracking-[0.2em] text-gray-400 hover:text-white transition">
                Cancel
            </button>
            <button type="button" onclick="confirmDeleteUser()" id="deleteUserConfirmBtn" class="flex-1 py-4 rounded-2xl bg-red-600 text-black font-black text-[10px] uppercase tracking-[0.2em] hover:bg-red-500 transition shadow-lg shadow-red-900/20">
                Delete Forever
            </button>
        </div>
    </div>
</div>

<script>
let pendingDeleteUserId = 0;

function openDeleteUserModal(id, name) {
    const modal = document.getElementById('deleteUserModal');
    const card = document.getElementById('deleteUserCard');
    const errorBox = document.getElementById('deleteUserError');

    pendingDeleteUserId = parseInt(id, 10) || 0;
    document.getElementById('deleteUserName').textContent = String(name || '');
    if (errorBox) { errorBox.textContent = ''; errorBox.classList.add('hidden'); }

    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95');
        card.classList.add('scale-100');
    }, 10);
}

function closeDeleteUserModal() {
    const modal = document.getElementById('deleteUserModal');
    const card = document.getElementById('deleteUserCard');

    modal.classList.remove('opacity-100');
    card.classList.replace('scale-100', 'scale-95');
    setTimeout(() => {
        modal.classList.add('hidden');
        pendingDeleteUserId = 0;
    }, 300);
}

async function confirmDeleteUser() {
    const errorBox = document.getElementById('deleteUserError');
    const btn = document.getElementById('deleteUserConfirmBtn');
    if (!pendingDeleteUserId) return;

    const csrfEl = document.getElementById('deleteUserCsrf');
    const csrf = csrfEl ? csrfEl.value : '';

    btn.disabled = true;
    btn.textContent = 'Deleting...';

    try {
        const body = new URLSearchParams({
            user_id: String(pendingDeleteUserId),
            csrf_token: csrf
        });

        const response = await fetch('../actions/process_delete_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
        });

        const result = await response.json();
        if (result && result.success) {
            closeDeleteUserModal();
            window.location.reload();
        } else {
            throw new Error((result && (result.error || result.message)) || 'Failed to delete user.');
        }
    } catch (err) {
        errorBox.textContent = (err && err.message) ? err.message : 'Failed to delete user.';
        errorBox.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Delete Forever';
    }
}
</script>