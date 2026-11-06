<div id="activateModal" class="fixed inset-0 bg-black/90 backdrop-blur-md hidden z-[110] flex items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div id="activateCard" class="card-bg w-full max-w-sm rounded-3xl border border-green-500/30 p-8 text-center transform scale-95 transition-all duration-300 shadow-2xl">
        
        <div class="w-20 h-20 bg-green-500/10 text-green-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-lg shadow-green-500/5">
            <i class="fas fa-power-off text-3xl"></i>
        </div>

        <h2 class="text-xl font-bold uppercase italic tracking-wider mb-2 text-white">Activate User?</h2>
        <p class="text-gray-400 text-sm mb-8 leading-relaxed">
            Are you sure you want to restore access for <br>
            <span id="activateUserName" class="text-white font-bold text-base">User Name</span>? <br>
            <span class="text-green-400/80 text-[11px] uppercase tracking-widest font-bold mt-2 block">They will be able to log in again.</span>
        </p>
        
        <form id="activateForm">
            <input type="hidden" name="user_id" id="activateUserId">
            <input type="hidden" name="status" value="1">
            
            <div class="flex gap-3">
                <button type="button" onclick="closeActivateModal()" class="flex-1 py-4 rounded-2xl bg-gray-800 text-[10px] font-bold uppercase tracking-[0.2em] text-gray-400 hover:text-white transition">
                    Cancel
                </button>
                <button type="button" onclick="submitActivateForm()" class="flex-1 py-4 rounded-2xl bg-green-600 text-black font-black text-[10px] uppercase tracking-[0.2em] hover:bg-green-500 transition shadow-lg shadow-green-900/20">
                    Confirm Activate
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openActivateModal(id, name) {
    const modal = document.getElementById('activateModal');
    const card = document.getElementById('activateCard');
    
    document.getElementById('activateUserId').value = id;
    document.getElementById('activateUserName').innerText = name;

    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95');
        card.classList.add('scale-100');
    }, 10);
}

function closeActivateModal() {
    const modal = document.getElementById('activateModal');
    const card = document.getElementById('activateCard');
    
    modal.classList.remove('opacity-100');
    card.classList.replace('scale-100', 'scale-95');
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

async function submitActivateForm() {
    const userId = document.getElementById('activateUserId').value;
    const userName = document.getElementById('activateUserName').textContent;
    const btn = document.querySelector('#activateForm button[type="button"]:last-child');
    
    // Show loading
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Activating...';
    btn.disabled = true;
    
    try {
        const response = await fetch('../actions/process_user_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest' // IMPORTANT!
            },
            body: `user_id=${encodeURIComponent(userId)}&status=1&csrf_token=${encodeURIComponent(<?= json_encode(arnisCsrfToken()) ?>)}`
        });
        
        const data = await response.json();
        console.log('Response:', data);
        
        if (data.success) {
            showNotification(`✅ ${userName} activated!`, 'success');
            closeActivateModal();
            
            // Refresh page after delay
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            throw new Error(data.error || 'Failed to activate user');
        }
    } catch (error) {
        console.error('Error:', error);
        showNotification('❌ ' + error.message, 'error');
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}
</script>