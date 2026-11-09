<div id="userMenuModal" class="fixed inset-0 bg-black/60 backdrop-blur-md hidden z-[70] flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="userMenuCard" class="card-bg w-full max-w-sm rounded-3xl flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">
        
        <div class="p-8 border-b border-gray-800/50 flex justify-between items-center bg-white/[0.02]">
            <h2 class="text-xl font-bold italic uppercase tracking-wider text-white">User Menu</h2>
            <button onclick="closeUserMenuModal()" class="text-gray-500 hover:text-white transition"><i class="fas fa-times"></i></button>
        </div>

        <div class="p-6 flex flex-col gap-3">
            <?php
            $menuSelfMemberId = (isset($_SESSION['member_id']) && $_SESSION['member_id'] !== null)
                ? (int)$_SESSION['member_id']
                : 0;
            $menuUserRole = (string)($_SESSION['role'] ?? '');
            // Only show "My Profile & Face Enroll" for roles that actually need face enrollment
            // or own member profile. Applicants should not see face enrollment UI.
            $menuShowProfile = false;
            if ($menuUserRole === 'Member') {
                // Members can view their own profile and enroll face
                $menuShowProfile = true;
            } elseif ($menuUserRole === 'Coach/Trainer' || $menuUserRole === 'Officer' || $menuUserRole === 'SuperAdmin') {
                // Staff may have linked member profiles; show if linked or can resolve
                if ($menuSelfMemberId > 0) {
                    $menuShowProfile = true;
                }
            }
            // Applicant: no face enrollment / member profile UI per requirements
            $menuProfileHref = $menuSelfMemberId > 0
                ? 'member_profile.php?id=' . $menuSelfMemberId
                : 'member_profile.php';
            ?>
            <?php if ($menuShowProfile): ?>
            <!-- Member profile + face enrollment (linked accounts open their own row) -->
            <button type="button" onclick="window.location.href='<?= htmlspecialchars($menuProfileHref, ENT_QUOTES, 'UTF-8') ?>'"
                    class="w-full px-6 py-4 bg-purple-600 hover:bg-purple-500 rounded-full transition text-sm font-bold text-white uppercase tracking-widest shadow-lg shadow-purple-900/20 flex items-center justify-center gap-3">
                <i class="fas fa-id-badge"></i>
                <span>My Profile &amp; Face Enroll</span>
            </button>
            <?php endif; ?>

            <!-- View/Edit Profile Button -->
            <button onclick="openEditUserProfileModal()" 
                    class="w-full px-6 py-4 bg-blue-600 hover:bg-blue-500 rounded-full transition text-sm font-bold text-white uppercase tracking-widest shadow-lg shadow-blue-900/20 flex items-center justify-center gap-3">
                <i class="fas fa-user-edit"></i>
                <span>View/Edit Profile</span>
            </button>

            <!-- Logout Button -->
            <button type="button" onclick="logoutUser()" 
                    class="w-full px-6 py-4 bg-red-600/20 hover:bg-red-600/30 border border-red-600/50 rounded-full transition text-sm font-bold text-red-400 uppercase tracking-widest flex items-center justify-center gap-3">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </button>

            <!-- Cancel Button -->
            <button onclick="closeUserMenuModal()" 
                    class="w-full px-6 py-4 rounded-full hover:bg-white/5 transition text-sm font-bold tracking-widest text-gray-400 uppercase">
                Cancel
            </button>
        </div>
    </div>
</div>

<script>
function openUserMenuModal() {
    const modal = document.getElementById('userMenuModal');
    const card = document.getElementById('userMenuCard');
    
    modal.classList.remove('hidden');
    // Trigger animation
    setTimeout(() => {
        modal.classList.add('opacity-100');
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    }, 10);
}

function closeUserMenuModal() {
    const modal = document.getElementById('userMenuModal');
    const card = document.getElementById('userMenuCard');
    
    modal.classList.remove('opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    card.classList.remove('scale-100', 'opacity-100');
    
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

function openEditUserProfileModal() {
    closeUserMenuModal();
    // Check if we're on users.php page - use the profile-specific modal
    if (typeof openEditProfileUserModal === 'function') {
        openEditProfileUserModal();
    } else if (typeof openEditUserModal === 'function') {
        // Fallback for other pages
        openEditUserModal();
    }
}

function logoutUser() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '../classes/logout.php';

    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = 'csrf_token';
    token.value = <?= json_encode(arnisCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    form.appendChild(token);
    document.body.appendChild(form);
    form.submit();
}

window.addEventListener('click', function(e) {
    const modal = document.getElementById('userMenuModal');

    if (e.target === modal) {
        closeUserMenuModal();
    }
});
</script>
