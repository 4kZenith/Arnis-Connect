<?php
/*
 * Self-contained helper. This modal used to call getInitials(), which is not
 * defined anywhere in the project, so PHP died with a fatal "Call to undefined
 * function" as soon as at least one eligible member existed, truncating the
 * whole attendance_session.php response.
 */
if (!function_exists('atmInitials')) {
    function atmInitials(?string $name): string
    {
        $name = trim((string)$name);
        if ($name === '') {
            return '??';
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
        }
        return strtoupper(substr($name, 0, 2));
    }
}
?>
<div id="addPeopleModal" class="fixed inset-0 bg-black/60 backdrop-blur-md hidden z-[60] flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div class="relative card-bg w-full max-w-lg rounded-[2rem] border border-gray-800 shadow-2xl overflow-hidden transform scale-95 opacity-0 transition-all duration-300 ease-out">
        
        <div class="p-8 border-b border-gray-800/50 flex justify-between items-center bg-gradient-to-r from-white/[0.02] to-transparent">
            <div>
                <h2 class="text-xl font-bold italic uppercase tracking-wider text-white">Assign Members</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-[0.2em] mt-1 font-bold">Training Roster Management</p>
            </div>
            <button onclick="closeAddPeopleModal()" class="w-10 h-10 rounded-full flex items-center justify-center text-gray-500 hover:bg-white/5 hover:text-white transition">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <form action="../actions/add_to_training.php" method="POST" class="flex-1 overflow-y-auto">

        <input type="hidden" name="status" value="Absent">

        <input type="hidden" name="training_id" value="<?= $trainingId ?>">

        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

        <div class="px-8 pt-6">
            <div class="flex items-center justify-between mb-4">
                <span id="selectedCount" class="text-[10px] text-purple-400 font-bold uppercase tracking-widest">0 Members Selected</span>
                <button type="button" onclick="toggleSelectAll()" id="selectAllBtn" class="text-[10px] text-gray-400 hover:text-white font-bold uppercase tracking-widest transition">
                    Select All
                </button>
            </div>
            <div class="relative">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-sm"></i>
                <input type="text" placeholder="Quick find member..." class="w-full bg-black/20 border border-gray-800 rounded-2xl pl-11 pr-4 py-4 text-sm outline-none focus:border-purple-500/50 transition italic">
            </div>
        </div>

        <div class="p-8">
            <div class="max-h-[400px] overflow-y-auto space-y-3 pr-2 custom-scrollbar">
                
                <?php if (empty($availableMembers)): ?>
                        <div class="text-center text-gray-500 py-8">
                            <i class="fas fa-users text-3xl mb-3 opacity-30"></i>
                            <p class="text-sm">All active members have been added</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($availableMembers as $member): ?>
                            <label class="flex items-center gap-4 p-4 bg-white/[0.02] hover:bg-white/[0.05] rounded-xl cursor-pointer transition border border-transparent hover:border-purple-500/30">
                                <input type="checkbox" name="member_ids[]" value="<?= (int)$member['member_id'] ?>" 
                                       class="w-5 h-5 rounded bg-gray-800 border-gray-700 text-purple-500 focus:ring-purple-500 focus:ring-offset-0">
                                <div class="flex items-center gap-3 flex-1">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500/20 to-blue-500/20 flex items-center justify-center text-xs font-bold border border-white/5">
                                        <?= htmlspecialchars(atmInitials($member['full_name'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </div>
                                    <div>
                                        <div class="text-sm font-bold"><?= htmlspecialchars((string)($member['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                        <div class="text-xs text-gray-400"><?= htmlspecialchars((string)($member['rank'] ?? 'None'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
            </div>
        </div>

        <div class="p-8 bg-white/[0.02] border-t border-gray-800 flex items-center gap-4">
            <button onclick="closeAddPeopleModal()" type="button" class="flex-1 px-6 py-4 rounded-2xl text-xs font-bold uppercase tracking-widest text-gray-500 hover:text-white hover:bg-white/5 transition">
                Cancel
            </button>
            <button type="submit" class="flex-[2] bg-accent-purple px-6 py-4 rounded-2xl text-xs font-bold uppercase tracking-widest text-white hover:opacity-90 transition shadow-xl shadow-purple-900/20">
                Confirm Selection
            </button>
        </div>
        </form>
    </div>
</div>


<script>
function openAddPeopleModal() {
    const modal = document.getElementById('addPeopleModal');
    const card = modal.querySelector('.relative.card-bg'); // The inner card

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        modal.classList.remove('opacity-0');
        modal.classList.add('opacity-100');

        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });
}

function closeAddPeopleModal() {
    const modal = document.getElementById('addPeopleModal');
    const card = modal.querySelector('.relative.card-bg');

    modal.classList.replace('opacity-100', 'opacity-0');
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');

    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }, 300); 
}

// Click outside to close (on the backdrop)
window.onclick = function(event) {
    const modal = document.getElementById('addPeopleModal');
    if (event.target === modal) {
        closeAddPeopleModal();
    }
};

function toggleSelectAll() {
    const modal = document.getElementById('addPeopleModal');
    const checkboxes = modal.querySelectorAll('input[name="member_ids[]"]');
    const selectAllBtn = document.getElementById('selectAllBtn');
    
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);

    checkboxes.forEach(cb => {
        cb.checked = !allChecked;
    });

    selectAllBtn.innerText = allChecked ? "Select All" : "Deselect All";
    updateSelectedCount();
}

function updateSelectedCount() {
    const modal = document.getElementById('addPeopleModal');
    const selectedCount = modal.querySelectorAll('input[name="member_ids[]"]:checked').length;
    document.getElementById('selectedCount').innerText = `${selectedCount} Members Selected`;
}

document.addEventListener('change', function(e) {
    if (e.target.name === 'member_ids[]') {
        updateSelectedCount();
        
        const checkboxes = document.querySelectorAll('input[name="member_ids[]"]');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        document.getElementById('selectAllBtn').innerText = allChecked ? "Deselect All" : "Select All";
    }
});




</script>