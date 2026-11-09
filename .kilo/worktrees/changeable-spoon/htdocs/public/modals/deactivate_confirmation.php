<div id="deactivateModal" class="fixed inset-0 bg-black/90 backdrop-blur-md hidden z-[110] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div id="deactivateCard" class="card-bg w-full max-w-sm rounded-3xl border border-red-500/30 p-8 text-center transform scale-95 transition-all duration-300 shadow-2xl">
        
        <div class="w-20 h-20 bg-red-500/10 text-red-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-lg shadow-red-500/5">
            <i class="fas fa-power-off text-3xl"></i>
        </div>

        <h2 class="text-xl font-bold uppercase italic tracking-wider mb-2 text-white">Deactivate User?</h2>
        <p class="text-gray-400 text-sm mb-8 leading-relaxed">
            Are you sure you want to revoke access for <br>
            <span id="deactivateUserName" class="text-white font-bold text-base">User Name</span>? <br>
            <span class="text-red-400/80 text-[11px] uppercase tracking-widest font-bold mt-2 block">They will be logged out immediately.</span>
        </p>
        
        <form action="../actions/process_user_status.php" method="POST">
            <input type="hidden" name="user_id" id="deactivateUserId">
            <input type="hidden" name="status" value="0">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            
            <div class="flex gap-3">
                <button type="button" onclick="closeDeactivateModal()" class="flex-1 py-4 rounded-2xl bg-gray-800 text-[10px] font-bold uppercase tracking-[0.2em] text-gray-400 hover:text-white transition">
                    Cancel
                </button>
                <button type="submit" class="flex-1 py-4 rounded-2xl bg-red-600 text-black font-black text-[10px] uppercase tracking-[0.2em] hover:bg-red-500 transition shadow-lg shadow-red-900/20">
                    Confirm Revoke
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openDeactivateModal(id, name) {
    const modal = document.getElementById('deactivateModal');
    const card = document.getElementById('deactivateCard');
    
    document.getElementById('deactivateUserId').value = id;
    document.getElementById('deactivateUserName').innerText = name;

    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95');
        card.classList.add('scale-100');
    }, 10);
}

function closeDeactivateModal() {
    const modal = document.getElementById('deactivateModal');
    const card = document.getElementById('deactivateCard');
    
    modal.classList.remove('opacity-100');
    card.classList.replace('scale-100', 'scale-95');
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}
</script>