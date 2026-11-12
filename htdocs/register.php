<?php
require_once __DIR__ . '/config/session.php';
arnisStartSession();

require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/classes/Settings.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

if (isset($_SESSION["user_id"])) {
    header("Location: public/dashboard.php");
    exit;
}

$registrationOpen = true;
$privacyContact = '';
try {
    $settings = new Settings((new Database())->connect());
    $registrationOpen = $settings->enabled('allow_registration');
    $privacyContact = trim($settings->get('privacy_contact'));
} catch (Throwable $e) {
    error_log('register.php settings unavailable: ' . $e->getMessage());
}

// GET values are fully attacker-controlled, so reject anything that is not a
// plain string (?message[]=x otherwise makes htmlspecialchars() throw a
// TypeError and renders a 500).
$flashType = (isset($_GET['type']) && is_string($_GET['type'])) ? $_GET['type'] : '';
$flashMessage = (isset($_GET['message']) && is_string($_GET['message'])) ? $_GET['message'] : '';
$flashMessage = mb_substr(trim($flashMessage), 0, 500);

$isSuccessFlash = ($flashType === 'success');
?>
<!DOCTYPE html>
<html lang="en" class="text-[13px]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register as Member | Arnis Connect</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/x-icon" href="arnis-favicon.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <style>
        :root { --dark-purple: #1d212a; --card-bg: #161a23; }
        body { font-family: 'Montserrat', sans-serif; background-color: var(--dark-purple); }
        .card-bg { background-color: var(--card-bg); border: 1px solid rgba(255,255,255,0.05); }
        .form-input, .form-select, .form-textarea { background: #1d212a; border: 1px solid transparent; transition: all 0.3s; color: white; }
        .form-input:focus, .form-select:focus, .form-textarea:focus { border-color: #a855f7; outline: none; box-shadow: 0 0 15px rgba(168, 85, 247, 0.1); }
        input[type="date"]::-webkit-calendar-picker-indicator { filter: invert(1); }
    </style>
</head>
<body class="min-h-screen p-4 sm:p-6">
    <div class="fixed top-[-10%] left-[-10%] w-[40%] h-[40%] bg-purple-600/10 blur-[120px] rounded-full"></div>
    <div class="fixed bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-blue-600/10 blur-[120px] rounded-full"></div>

    <main class="relative z-10 max-w-4xl mx-auto py-8">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-purple-500/10 text-purple-500 border border-purple-500/20 mb-4 shadow-lg shadow-purple-900/20">
                <i class="fas fa-user-plus text-2xl"></i>
            </div>
            <h1 class="text-3xl font-black uppercase tracking-tighter italic text-white">Register as <span class="text-purple-500">Member</span></h1>
            <p class="text-gray-400 mt-2 text-sm">Submit your application as a public or private player. Admin approval is required before you become an official member.</p>
        </div>

        <?php if ($flashMessage !== ''): ?>
        <div class="mb-6 p-4 rounded-2xl border text-sm font-semibold <?= $isSuccessFlash ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-300' : 'bg-red-500/10 border-red-500/20 text-red-300' ?>">
            <?= htmlspecialchars($flashMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <div class="card-bg rounded-[2rem] shadow-2xl overflow-hidden">
            <div class="px-6 sm:px-8 py-6 border-b border-white/5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-black uppercase tracking-[0.18em] text-purple-300">Application Form</h2>
                    <p class="text-xs text-gray-500 mt-1 uppercase tracking-[0.16em]">Public players are free. Private players are paid and may join custom/private sessions.</p>
                </div>
                <a href="index.php" class="text-xs uppercase tracking-[0.16em] font-black text-gray-400 hover:text-white transition"><i class="fas fa-arrow-left mr-2"></i>Back to Login</a>
            </div>

            <?php if (!$registrationOpen): ?>
            <div class="p-6 sm:p-8">
                <div class="rounded-2xl border border-yellow-500/20 bg-yellow-500/5 p-6 text-sm text-yellow-200">
                    <i class="fas fa-lock mr-2"></i>
                    Registration is currently closed. Please contact the club administrator if you need an account.
                </div>
            </div>
            <?php else: ?>
            <form action="actions/process_register_request.php" method="POST" enctype="multipart/form-data" class="p-6 sm:p-8 grid grid-cols-1 md:grid-cols-2 gap-5" id="registerForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="md:col-span-2">
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Full Name</label>
                    <input type="text" name="full_name" required maxlength="100" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="Juan Dela Cruz">
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Birthdate</label>
                    <input type="date" name="dob" required max="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" min="1900-01-01" class="form-input w-full rounded-2xl px-4 py-4 text-sm">
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Email</label>
                    <input type="email" name="email" maxlength="100" required class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="you@example.com">
                    <p class="text-[11px] text-gray-500 mt-2">Required. We will email you a verification link before you can sign in.</p>
                </div>
                <div class="md:col-span-2 rounded-2xl border border-purple-500/20 bg-purple-500/5 p-4 sm:p-5">
                    <h3 class="text-[11px] uppercase tracking-[0.18em] font-black text-purple-300 mb-1">Login Credentials</h3>
                    <p class="text-xs text-gray-500 mb-4">You log in with this <span class="text-gray-300 font-bold">immediately</span> to track your application, and keep using it as your member login after approval.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Username</label>
                            <input type="text" name="username" id="username" required maxlength="50" pattern="[A-Za-z0-9._-]{3,50}" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="juan_delacruz" autocomplete="username" onblur="checkUsername()" oninput="clearUsernameStatus()">
                            <div id="username-status" class="mt-2 text-xs"></div>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Password</label>
                            <input type="password" name="password" required minlength="6" maxlength="100" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="Minimum 6 characters" autocomplete="new-password">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Confirm Password</label>
                            <input type="password" name="confirm_password" required minlength="6" maxlength="100" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="Repeat password" autocomplete="new-password">
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Contact Number</label>
                    <input type="text" name="contact_number" required maxlength="30" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="09XXXXXXXXX">
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Requested Rank</label>
                    <select name="requested_rank" id="requested_rank" onchange="toggleRankVerificationFields()" class="form-select w-full rounded-2xl px-4 py-4 text-sm">
                        <option value="None">None (Beginner)</option>
                        <option value="I. White Belt">I. White Belt</option>
                        <option value="II. Yellow Belt">II. Yellow Belt</option>
                        <option value="III. Green Belt">III. Green Belt</option>
                        <option value="IV. Blue Belt">IV. Blue Belt</option>
                        <option value="V. Brown Belt">V. Brown Belt</option>
                        <option value="VI. Black Belt">VI. Black Belt</option>
                        <option value="VII. Red Belt">VII. Red Belt</option>
                        <option value="1st Degree- Junior Instructor">1st Degree- Junior Instructor</option>
                        <option value="2nd Degree- Junior Instructor">2nd Degree- Junior Instructor</option>
                        <option value="3rd Degree- Instructor">3rd Degree- Instructor</option>
                        <option value="4th Degree- Instructor">4th Degree- Instructor</option>
                        <option value="5th Degree- Senior Instructor">5th Degree- Senior Instructor</option>
                        <option value="6th Degree- Senior Instructor">6th Degree- Senior Instructor</option>
                        <option value="7th Degree- Junior Master">7th Degree- Junior Master</option>
                        <option value="8th Degree- Master">8th Degree- Master</option>
                        <option value="9th Degree- Senior Master">9th Degree- Senior Master</option>
                        <option value="10th Degree- Grandmaster">10th Degree- Grandmaster</option>
                        <option value="11th Degree- Senior Grandmaster">11th Degree- Senior Grandmaster</option>
                        <option value="12th Degree- Superior Grandmaster">12th Degree- Superior Grandmaster</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Player Category</label>
                    <select name="player_category" id="player_category" onchange="syncProgramNotes()" class="form-select w-full rounded-2xl px-4 py-4 text-sm">
                        <option value="Public">Public Player</option>
                        <option value="Private">Private Player</option>
                        <option value="Mixed">Mixed / Both</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Training Preference</label>
                    <select name="training_preference" class="form-select w-full rounded-2xl px-4 py-4 text-sm">
                        <option value="Group">Group</option>
                        <option value="Private">Private</option>
                        <option value="Flexible">Flexible</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Affiliated School / Club</label>
                    <input type="text" name="affiliated_school" maxlength="255" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="School, club, or barangay">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Address</label>
                    <input type="text" name="address" maxlength="255" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="House no., street, barangay, city">
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Guardian Name</label>
                    <input type="text" name="guardian_name" maxlength="100" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="Parent / guardian">
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Guardian Contact</label>
                    <input type="text" name="guardian_contact" maxlength="30" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="09XXXXXXXXX">
                </div>

                <!-- Rank Verification Fields (shown only if rank != None) -->
                <div id="rank_verification_section" class="md:col-span-2 rounded-2xl border border-yellow-500/20 bg-yellow-500/5 p-4 sm:p-5 hidden">
                    <h3 class="text-[11px] uppercase tracking-[0.18em] font-black text-yellow-300 mb-3">Rank Verification (Required for non-beginner ranks)</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Previous Organization/School</label>
                            <input type="text" name="prev_organization" id="prev_organization" maxlength="150" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="Where rank was awarded">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Previous Instructor</label>
                            <input type="text" name="prev_instructor" id="prev_instructor" maxlength="100" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="Instructor name">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Year Rank Awarded</label>
                            <input type="text" name="rank_awarded_year" id="rank_awarded_year" maxlength="4" pattern="[0-9]{4}" class="form-input w-full rounded-2xl px-4 py-4 text-sm" placeholder="e.g., 2023">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Rank Certificate (PDF/JPG/PNG/JPEG, max 5MB)</label>
                            <input type="file" name="rank_evidence" id="rank_evidence" accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-purple-500 file:text-white hover:file:bg-purple-600">
                            <p class="text-xs text-gray-500 mt-1">Upload proof of rank (certificate, diploma, etc.)</p>
                        </div>
                    </div>
                </div>

                <div class="md:col-span-2 flex items-start gap-3">
                    <input type="checkbox" name="contact_ok" id="contact_ok" value="1" required class="mt-1 w-4 h-4 rounded border-gray-600 bg-gray-800 text-purple-600 focus:ring-purple-500 focus:ring-2">
                    <label for="contact_ok" class="text-xs text-gray-400 leading-relaxed">I confirm that the contact details provided are accurate and I consent to be contacted regarding my application.</label>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-[10px] uppercase tracking-[0.16em] font-black text-gray-500 mb-2">Program / Payment Notes</label>
                    <textarea name="payment_notes" id="payment_notes" rows="4" maxlength="255" class="form-textarea w-full rounded-2xl px-4 py-4 text-sm" placeholder="Optional notes about your training setup."></textarea>
                </div>

                <div class="md:col-span-2 rounded-2xl border border-blue-500/20 bg-blue-500/5 p-4 sm:p-5">
                    <h3 class="text-[11px] uppercase tracking-[0.18em] font-black text-blue-300 mb-2">
                        <i class="fas fa-shield-halved mr-2"></i>Data Privacy Act Notice
                    </h3>
                    <p class="text-xs text-gray-400 leading-relaxed mb-3">
                        Arnis Connect collects your name, birthdate, contact details, address, guardian information,
                        training preferences, and rank evidence to process your membership application, schedule
                        training, record attendance, and contact you about club activities. Your information is
                        stored in the club database and is visible only to authorized club staff and to you through
                        your own account. We do not sell or share your personal data with third parties. You may
                        request access, correction, or deletion of your data by contacting the club administrator<?= $privacyContact !== '' ? ' at ' . htmlspecialchars($privacyContact, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>.
                    </p>
                    <label class="flex items-start gap-3 text-xs text-gray-300 leading-relaxed">
                        <input type="checkbox" name="privacy_consent" id="privacy_consent" value="1" required class="mt-0.5 w-4 h-4 rounded border-gray-600 bg-gray-800 text-purple-600 focus:ring-purple-500 focus:ring-2">
                        <span>I have read the notice above and consent to the collection and processing of my personal data for the purposes described, in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173).</span>
                    </label>
                </div>

                <div class="md:col-span-2 flex flex-col sm:flex-row gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-500 text-white font-black uppercase text-[11px] tracking-[0.2em] py-5 rounded-2xl transition-all active:scale-95 shadow-lg shadow-purple-900/40">Submit Application</button>
                    <a href="index.php" class="flex-1 text-center bg-white/5 hover:bg-white/10 text-gray-300 font-black uppercase text-[11px] tracking-[0.2em] py-5 rounded-2xl transition-all">Cancel</a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </main>

    <script>
        function syncProgramNotes() {
            const category = document.getElementById('player_category').value;
            const notes = document.getElementById('payment_notes');
            if (!notes.value.trim()) {
                if (category === 'Public') notes.placeholder = 'Public player: free and usually school-based or bulk training.';
                else if (category === 'Private') notes.placeholder = 'Private player: paid private/custom training arrangement.';
                else notes.placeholder = 'Mixed player: joins both public and private sessions.';
            }
        }
        syncProgramNotes();

        function toggleRankVerificationFields() {
            const rankSelect = document.getElementById('requested_rank');
            const section = document.getElementById('rank_verification_section');
            const prevOrg = document.getElementById('prev_organization');
            const prevInst = document.getElementById('prev_instructor');
            const rankYear = document.getElementById('rank_awarded_year');
            const rankEvidence = document.getElementById('rank_evidence');
            if (!rankSelect || !section) return;
            if (rankSelect.value !== 'None') {
                section.classList.remove('hidden');
                if (prevOrg) prevOrg.required = true;
                if (prevInst) prevInst.required = true;
                if (rankYear) rankYear.required = true;
                if (rankEvidence) rankEvidence.required = true;
            } else {
                section.classList.add('hidden');
                if (prevOrg) prevOrg.required = false;
                if (prevInst) prevInst.required = false;
                if (rankYear) rankYear.required = false;
                if (rankEvidence) rankEvidence.required = false;
            }
        }
        toggleRankVerificationFields();

        let usernameCheckTimeout = null;
        function clearUsernameStatus() {
            const statusEl = document.getElementById('username-status');
            statusEl.textContent = '';
            statusEl.className = 'mt-2 text-xs';
        }

        async function checkUsername() {
            const usernameInput = document.getElementById('username');
            const statusEl = document.getElementById('username-status');
            const submitBtn = document.querySelector('#registerForm button[type="submit"]');
            const username = usernameInput.value.trim();

            if (!username) {
                clearUsernameStatus();
                if (submitBtn) submitBtn.disabled = false;
                return;
            }

            // Validate pattern
            const pattern = /^[A-Za-z0-9._-]{3,50}$/;
            if (!pattern.test(username)) {
                statusEl.textContent = 'Username may only contain letters, numbers, dot, underscore and hyphen (3-50 chars)';
                statusEl.className = 'mt-2 text-xs text-red-400';
                if (submitBtn) submitBtn.disabled = true;
                return;
            }

            statusEl.textContent = 'Checking availability...';
            statusEl.className = 'mt-2 text-xs text-gray-400';

            try {
                const resp = await fetch(`actions/check_username.php?username=${encodeURIComponent(username)}`);
                const data = await resp.json();
                if (data.available) {
                    statusEl.textContent = '✓ Username available';
                    statusEl.className = 'mt-2 text-xs text-green-400';
                    if (submitBtn) submitBtn.disabled = false;
                } else {
                    statusEl.textContent = '✗ ' + (data.message || 'Username taken');
                    statusEl.className = 'mt-2 text-xs text-red-400';
                    if (submitBtn) submitBtn.disabled = true;
                }
            } catch (e) {
                statusEl.textContent = 'Could not check username';
                statusEl.className = 'mt-2 text-xs text-yellow-400';
                if (submitBtn) submitBtn.disabled = false; // Don't block submission if check fails
            }
        }

        // Also check on submit
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const privacy = document.getElementById('privacy_consent');
            if (privacy && !privacy.checked) {
                e.preventDefault();
                alert('Please read and accept the Data Privacy Act notice before submitting.');
                return false;
            }
            const usernameInput = document.getElementById('username');
            const pattern = /^[A-Za-z0-9._-]{3,50}$/;
            if (!pattern.test(usernameInput.value.trim())) {
                e.preventDefault();
                alert('Please fix username format');
                return false;
            }
            const rankSelect = document.getElementById('requested_rank');
            if (rankSelect && rankSelect.value !== 'None') {
                if (!document.getElementById('contact_ok').checked) {
                    e.preventDefault();
                    alert('Please confirm the contact consent checkbox.');
                    return false;
                }
                if (!document.getElementById('prev_organization').value.trim() ||
                    !document.getElementById('prev_instructor').value.trim() ||
                    !document.getElementById('rank_awarded_year').value.trim() ||
                    !document.getElementById('rank_evidence').files.length) {
                    e.preventDefault();
                    alert('Please provide all rank verification details and upload evidence.');
                    return false;
                }
            }
        });
    </script>
</body>
</html>
/*sds*/