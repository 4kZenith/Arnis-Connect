<?php
/**
 * Record Result modal  —  public/modals/add_achievement.php
 * Included by: public/event_detail.php  (needs $participants, $eventId, $event)
 *
 * Fixes in this revision:
 *  - csrf_token hidden input added (hardened action returned HTTP 403).
 *  - NEW "Date Achieved" field. achievements.achieved_at is DATE NOT NULL with
 *    no default, which is why every seeded row reads '0000-00-00'. It now
 *    defaults to the event date instead of guessing.
 *  - member_id emitted as an int, not htmlspecialchars()'d.
 *  - notes capped at 255 (achievements.notes is varchar(255)).
 *  - Error text now falls back through error -> message, so the server's real
 *    reason is shown instead of a generic failure.
 *  - Submit disabled while in flight; select + error reset on open; ESC to close.
 */

$achEventId = isset($eventId) ? (int) $eventId : 0;

/* Best available default for "when was this achieved" = the event's own date. */
$achDefaultDate = '';
if (isset($event['event_date']) && is_string($event['event_date'])) {
    $candidate = trim($event['event_date']);
    if ($candidate !== '' && !str_starts_with($candidate, '0000-00-00')) {
        $achDefaultDate = substr($candidate, 0, 10);
    }
}
if ($achDefaultDate === '') {
    $achDefaultDate = date('Y-m-d');
}

$achParticipants = isset($participants) && is_array($participants) ? $participants : [];
?>
<div id="achievementModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="achievementCard" class="card-bg w-full max-w-2xl rounded-3xl max-h-[80vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">
        <div class="p-6 border-b border-gray-800 flex justify-between items-center bg-white/5">
            <div>
                <h2 class="text-lg font-bold italic uppercase tracking-wider text-yellow-500">Record Result</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest">Assign achievement to participant</p>
            </div>
            <button type="button" onclick="closeAchievementModal()" class="text-gray-500 hover:text-white transition" title="Close"><i class="fas fa-times text-xl"></i></button>
        </div>

        <form id="achievementForm" class="p-8 space-y-5 flex-1 overflow-y-auto" onsubmit="return false;">
            <?php if (function_exists('arnisCsrfToken')): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <div>
                <label class="block text-gray-400 text-[10px] uppercase font-bold mb-2 tracking-widest">Member</label>
                <div class="relative">
                    <select id="achievementMemberId" name="member_id" required
                            class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-yellow-600 transition text-white appearance-none cursor-pointer">
                        <option value="">Select member...</option>
                        <?php foreach ($achParticipants as $achParticipant): ?>
                        <option value="<?= (int) ($achParticipant['member_id'] ?? 0) ?>">
                            <?= htmlspecialchars((string) ($achParticipant['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            (<?= htmlspecialchars(trim((string) ($achParticipant['rank'] ?? '')) ?: 'N/A', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 pointer-events-none"></i>
                </div>
                <?php if (empty($achParticipants)): ?>
                    <p class="text-[9px] text-gray-600 mt-2">Add participants to this event first.</p>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-400 text-[10px] uppercase font-bold mb-2 tracking-widest">Achievement Type</label>
                    <div class="relative">
                        <select name="achievement_type" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-yellow-600 transition text-white appearance-none cursor-pointer">
                            <option value="Gold">Gold</option>
                            <option value="Silver">Silver</option>
                            <option value="Bronze">Bronze</option>
                            <option value="Recognition">Recognition</option>
                        </select>
                        <i class="fas fa-award absolute right-4 top-1/2 -translate-y-1/2 text-yellow-600 pointer-events-none"></i>
                    </div>
                </div>

                <div>
                    <label class="block text-gray-400 text-[10px] uppercase font-bold mb-2 tracking-widest">Level</label>
                    <div class="relative">
                        <select name="level" class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-yellow-600 transition text-white appearance-none cursor-pointer">
                            <option value="School">School</option>
                            <option value="City">City</option>
                            <option value="Regional">Regional</option>
                            <option value="National">National</option>
                        </select>
                        <i class="fas fa-layer-group absolute right-4 top-1/2 -translate-y-1/2 text-gray-600 pointer-events-none"></i>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-gray-400 text-[10px] uppercase font-bold mb-2 tracking-widest">Date Achieved *</label>
                <input type="date" name="achieved_at" value="<?= htmlspecialchars($achDefaultDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required
                       class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-yellow-600 transition text-white">
            </div>

            <div>
                <label class="block text-gray-400 text-[10px] uppercase font-bold mb-2 tracking-widest">Specific Result / Notes</label>
                <textarea name="notes" placeholder="e.g. 1st Place - Anyo Single Baston" maxlength="255"
                          class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-yellow-600 transition h-24 resize-none text-white"></textarea>
            </div>

            <div id="achievementError" class="text-red-500 text-xs hidden" role="alert"></div>

            <div class="pt-4">
                <button type="submit" id="achievementSubmitBtn"
                        class="w-full bg-yellow-600 text-black font-black py-4 rounded-2xl transition uppercase text-xs tracking-[0.2em] hover:bg-yellow-500 shadow-lg shadow-yellow-900/20">
                    Save Achievement
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const EVENT_ID = <?= $achEventId ?>;

    function achFail(message) {
        const box = document.getElementById('achievementError');
        if (!box) return;
        box.textContent = String(message == null ? '' : message);
        box.classList.remove('hidden');
    }

    function achClearError() {
        const box = document.getElementById('achievementError');
        if (!box) return;
        box.textContent = '';
        box.classList.add('hidden');
    }

    window.openAchievementModal = function () {
        const modal = document.getElementById('achievementModal');
        const card = document.getElementById('achievementCard');
        if (!modal || !card) return;

        achClearError();

        const select = document.getElementById('achievementMemberId');
        if (select) select.value = '';

        const submitBtn = document.getElementById('achievementSubmitBtn');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            submitBtn.textContent = 'Save Achievement';
        }

        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        requestAnimationFrame(() => {
            modal.classList.add('opacity-100');
            card.classList.remove('scale-95', 'opacity-0');
            card.classList.add('scale-100', 'opacity-100');
        });
    };

    window.closeAchievementModal = function () {
        const modal = document.getElementById('achievementModal');
        const card = document.getElementById('achievementCard');
        if (!modal || !card) return;

        modal.classList.remove('opacity-100');
        card.classList.remove('scale-100', 'opacity-100');
        card.classList.add('scale-95', 'opacity-0');

        setTimeout(() => {
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }, 300);
    };

    async function achSubmit() {
        achClearError();

        const form = document.getElementById('achievementForm');
        const submitBtn = document.getElementById('achievementSubmitBtn');
        if (!form) return;

        const originalLabel = submitBtn ? submitBtn.textContent : 'Save Achievement';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            submitBtn.textContent = 'SAVING...';
        }

        try {
            const formData = new FormData(form);
            formData.set('event_id', String(EVENT_ID));

            const response = await fetch('../actions/process_add_achievement.php', {
                method: 'POST',
                body: formData,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });

            const raw = await response.text();
            let result;
            try {
                result = JSON.parse(raw);
            } catch (e) {
                throw new Error('Unexpected server response (HTTP ' + response.status + ').');
            }

            if (result && result.success) {
                window.closeAchievementModal();
                setTimeout(() => location.reload(), 400);
                return;
            }

            throw new Error((result && (result.error || result.message)) || 'Failed to save achievement.');

        } catch (err) {
            achFail((err && err.message) ? err.message : 'Could not reach the server.');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                submitBtn.textContent = originalLabel;
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('achievementForm');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                achSubmit();
            });
        }

        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            const modal = document.getElementById('achievementModal');
            if (modal && !modal.classList.contains('hidden')) {
                window.closeAchievementModal();
            }
        });
    });
})();
</script>