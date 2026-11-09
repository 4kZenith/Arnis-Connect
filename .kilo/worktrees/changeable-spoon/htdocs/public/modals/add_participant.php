<?php
/**
 * Add Participant modal  —  public/modals/add_participant.php
 * Included by: public/event_detail.php  (needs $db, $event, $eventId)
 *
 * Fixes in this revision:
 *  - csrf_token hidden input added (process_add_participant.php requires it,
 *    so every add returned HTTP 403).
 *  - STORED XSS: the search highlight used innerHTML on textContent, so any
 *    member name containing markup executed on the first keystroke.
 *    Highlighting is now built from text nodes + <mark> elements.
 *  - Search no longer builds a RegExp from raw input (crashed on "(" / "[").
 *  - "No members found" node is created/removed correctly and can no longer
 *    throw a TypeError when #memberList does not exist.
 *  - document.body.style.overflow is restored on close (page stayed frozen).
 *  - Raw member name/rank go into data-* attributes (escaped once) instead of
 *    pre-escaped-then-lowercased, so "a & b" is searchable.
 *  - Submit button disabled while in flight (no duplicate rows).
 */

$apEventId = isset($eventId) ? (int) $eventId : 0;
$apGroup   = (string) ($event['target_player_category'] ?? 'Mixed');

$apTargetGroupSql = '';
if ($apGroup === 'Public') {
    $apTargetGroupSql = " AND m.player_category IN ('Public', 'Mixed') ";
} elseif ($apGroup === 'Private') {
    $apTargetGroupSql = " AND m.player_category IN ('Private', 'Mixed') ";
}

$availableMembers = [];

try {
    if (!isset($db)) {
        require_once __DIR__ . '/../../config/Database.php';
        $db = (new Database())->connect();
    }

    $stmt = $db->prepare("
        SELECT m.member_id, m.full_name, m.rank, m.height,
               m.player_category, m.training_preference
        FROM members m
        WHERE m.is_voided = 0
          AND m.status = 'Active'
          AND m.member_id NOT IN (
              SELECT member_id
              FROM event_participants
              WHERE event_id = :event_id AND is_voided = 0
          )
          {$apTargetGroupSql}
        ORDER BY m.full_name ASC
    ");
    $stmt->execute([':event_id' => $apEventId]);
    $availableMembers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[add_participant] member list failed: ' . $e->getMessage());
    $availableMembers = [];
}
?>
<div id="participantModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="participantCard" class="card-bg w-full max-w-lg rounded-3xl max-h-[80vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">

        <div class="p-6 border-b border-gray-800 flex justify-between items-center bg-white/5">
            <div>
                <h2 class="text-lg font-bold italic uppercase tracking-wider text-purple-400">Add Participant</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest">Assign member to this event</p>
            </div>
            <button type="button" onclick="closeParticipantModal()" class="text-gray-500 hover:text-white transition" title="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="participantForm" class="p-8 space-y-6 flex-1 overflow-y-auto" onsubmit="return false;">
            <?php if (function_exists('arnisCsrfToken')): ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(arnisCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php endif; ?>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Select Member</label>
                <div class="relative">
                    <div class="relative mb-2">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-sm"></i>
                        <input type="text"
                               id="memberSearchInput"
                               placeholder="Search members by name, rank..."
                               autocomplete="off"
                               class="w-full bg-[#1d212a] rounded-xl pl-10 pr-4 py-3 text-sm focus:outline-none focus:border-purple-500 border border-transparent transition text-white placeholder-gray-600">
                    </div>

                    <input type="hidden" id="participantMemberId" name="member_id" required>

                    <div id="memberListContainer" class="bg-[#1d212a] rounded-xl border border-transparent max-h-[300px] overflow-y-auto custom-scrollbar">
                        <?php if (empty($availableMembers)): ?>
                            <div class="p-6 text-center text-gray-500">
                                <i class="fas fa-users text-2xl mb-2 opacity-30"></i>
                                <p class="text-sm"><?= $apGroup === 'Mixed' ? 'All active members are already participants' : 'No active ' . htmlspecialchars(strtolower($apGroup), ENT_QUOTES) . '/mixed members left to add' ?></p>
                            </div>
                        <?php else: ?>
                            <div id="memberList" class="divide-y divide-gray-800">
                                <?php foreach ($availableMembers as $apMember):
                                    $apId     = (int) ($apMember['member_id'] ?? 0);
                                    $apName   = (string) ($apMember['full_name'] ?? '');
                                    $apRank   = trim((string) ($apMember['rank'] ?? '')) ?: 'N/A';
                                    $apHeight = ($apMember['height'] === null || $apMember['height'] === '')
                                        ? 'N/A'
                                        : (string) $apMember['height'];
                                    $apCat    = (string) ($apMember['player_category'] ?? 'Public');
                                    $apPref   = trim((string) ($apMember['training_preference'] ?? '')) ?: 'Group';

                                    if ($apCat === 'Private') {
                                        $apCatCls = 'bg-yellow-500/10 text-yellow-300';
                                    } elseif ($apCat === 'Mixed') {
                                        $apCatCls = 'bg-pink-500/10 text-pink-300';
                                    } else {
                                        $apCatCls = 'bg-green-500/10 text-green-300';
                                    }
                                ?>
                                <div class="member-item p-4 hover:bg-white/[0.05] cursor-pointer transition border border-transparent"
                                     data-member-id="<?= $apId ?>"
                                     data-member-name="<?= htmlspecialchars($apName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                     data-member-rank="<?= htmlspecialchars($apRank, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                     data-member-height="<?= htmlspecialchars($apHeight, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <div class="font-bold text-sm text-white member-name"><?= htmlspecialchars($apName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                            <div class="text-xs text-gray-400 mt-1">
                                                <span class="member-rank"><?= htmlspecialchars($apRank, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                <?php if ($apHeight !== 'N/A'): ?>
                                                    <span class="text-gray-600"> • <?= htmlspecialchars($apHeight, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>cm</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                <span class="px-2 py-1 rounded-full text-[9px] font-bold <?= $apCatCls ?>">
                                                    <?= htmlspecialchars($apCat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                </span>
                                                <span class="px-2 py-1 rounded-full text-[9px] font-bold bg-white/5 text-gray-300">
                                                    <?= htmlspecialchars($apPref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                                </span>
                                            </div>
                                        </div>
                                        <i class="fas fa-check-circle text-purple-500 opacity-0 transition member-check"></i>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div id="selectedMemberDisplay" class="hidden mt-2 p-3 bg-purple-500/10 border border-purple-500/30 rounded-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm font-bold text-purple-400" id="selectedMemberName"></div>
                                <div class="text-xs text-gray-400" id="selectedMemberDetails"></div>
                            </div>
                            <button type="button" onclick="clearMemberSelection()" class="text-gray-500 hover:text-white transition" title="Clear selection">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <label class="block text-gray-500 text-[10px] uppercase font-bold mb-2 tracking-widest">Assigned Role</label>
                <div class="relative">
                    <input type="text"
                           name="role"
                           list="role-suggestions"
                           placeholder="e.g. Facilitator, Snack Boy..."
                           required
                           maxlength="255"
                           class="w-full bg-[#1d212a] rounded-xl px-4 py-4 text-sm focus:outline-none border border-transparent focus:border-purple-500 transition text-white">
                    <i class="fas fa-pen-nib absolute right-4 top-1/2 -translate-y-1/2 text-gray-700 pointer-events-none"></i>

                    <datalist id="role-suggestions">
                        <option value="Competitor">
                        <option value="Performer">
                        <option value="Facilitator">
                        <option value="Organizer">
                        <option value="Snack Boy">
                        <option value="Official">
                        <option value="Photographer">
                    </datalist>
                </div>
                <p class="text-[9px] text-gray-600 mt-2 uppercase italic tracking-wider">Tip: You can type a custom role or select from common ones.</p>
            </div>

            <div id="participantError" class="text-red-500 text-xs hidden" role="alert"></div>

            <div class="pt-4">
                <button type="submit" id="participantSubmitBtn"
                        class="w-full bg-white text-black font-black py-4 rounded-2xl transition uppercase text-xs tracking-[0.2em] hover:bg-purple-500 hover:text-white shadow-lg shadow-purple-900/10">
                    Confirm Participation
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const EVENT_ID = <?= $apEventId ?>;

    let selectedMemberId = null;

    /* ---------- helpers ---------- */

    function apFail(message) {
        const box = document.getElementById('participantError');
        if (!box) return;
        box.textContent = String(message == null ? '' : message);
        box.classList.remove('hidden');
    }

    function apClearError() {
        const box = document.getElementById('participantError');
        if (!box) return;
        box.textContent = '';
        box.classList.add('hidden');
    }

    /**
     * Highlight every case-insensitive occurrence of `term` inside `el`.
     * Built from text nodes + <mark> elements — innerHTML is NEVER used, so a
     * member name containing markup is displayed as text and never parsed.
     */
    function apHighlight(el, text, term) {
        el.textContent = '';

        if (!term) {
            el.textContent = text;
            return;
        }

        const haystack = text.toLowerCase();
        const needle = term.toLowerCase();

        let cursor = 0;
        let idx = haystack.indexOf(needle);

        while (idx !== -1) {
            if (idx > cursor) {
                el.appendChild(document.createTextNode(text.slice(cursor, idx)));
            }

            const mark = document.createElement('mark');
            mark.className = 'bg-purple-500/30 text-purple-300';
            mark.textContent = text.slice(idx, idx + needle.length);
            el.appendChild(mark);

            cursor = idx + needle.length;
            idx = haystack.indexOf(needle, cursor);
        }

        if (cursor < text.length) {
            el.appendChild(document.createTextNode(text.slice(cursor)));
        }
    }

    function apSetNoResults(show) {
        const list = document.getElementById('memberList');
        if (!list) return;

        const existing = list.querySelector('.no-results');

        if (show && !existing) {
            const node = document.createElement('div');
            node.className = 'no-results p-6 text-center text-gray-500';

            const icon = document.createElement('i');
            icon.className = 'fas fa-search text-2xl mb-2 opacity-30';

            const label = document.createElement('p');
            label.className = 'text-sm';
            label.textContent = 'No members match your search.';

            node.appendChild(icon);
            node.appendChild(label);
            list.appendChild(node);
        } else if (!show && existing) {
            existing.remove();
        }
    }

    /* ---------- filtering ---------- */

    window.filterMembers = function (searchTerm) {
        const items = document.querySelectorAll('.member-item');
        const term = String(searchTerm == null ? '' : searchTerm).toLowerCase().trim();

        let visible = 0;

        items.forEach((item) => {
            const name = item.dataset.memberName || '';
            const rank = item.dataset.memberRank || '';
            const haystack = (name + ' ' + rank).toLowerCase();

            if (term === '' || haystack.indexOf(term) !== -1) {
                item.style.display = '';
                visible += 1;
            } else {
                item.style.display = 'none';
            }

            const nameEl = item.querySelector('.member-name');
            if (nameEl) {
                apHighlight(nameEl, name, (term !== '' && name.toLowerCase().indexOf(term) !== -1) ? term : '');
            }
        });

        apSetNoResults(term !== '' && visible === 0);
    };

    /* ---------- selection ---------- */

    function apResetVisualSelection() {
        document.querySelectorAll('.member-item').forEach((item) => {
            item.classList.remove('bg-purple-500/10', 'border-purple-500/30');
            item.classList.add('border-transparent', 'hover:bg-white/[0.05]');
            const check = item.querySelector('.member-check');
            if (check) check.classList.add('opacity-0');

            const nameEl = item.querySelector('.member-name');
            if (nameEl) nameEl.textContent = item.dataset.memberName || '';
        });
    }

    window.selectMember = function (memberId, memberName, memberRank, memberHeight) {
        selectedMemberId = memberId;

        const hidden = document.getElementById('participantMemberId');
        if (hidden) hidden.value = String(memberId);

        const nameEl = document.getElementById('selectedMemberName');
        const detEl = document.getElementById('selectedMemberDetails');
        if (nameEl) nameEl.textContent = String(memberName == null ? '' : memberName);
        if (detEl) {
            detEl.textContent = String(memberRank == null ? '' : memberRank)
                + (memberHeight && memberHeight !== 'N/A' ? ' • ' + memberHeight + 'cm' : '');
        }

        const display = document.getElementById('selectedMemberDisplay');
        if (display) display.classList.remove('hidden');

        document.querySelectorAll('.member-item').forEach((item) => {
            const check = item.querySelector('.member-check');
            if (String(item.dataset.memberId) === String(memberId)) {
                item.classList.add('bg-purple-500/10', 'border-purple-500/30');
                item.classList.remove('border-transparent', 'hover:bg-white/[0.05]');
                if (check) check.classList.remove('opacity-0');
            } else {
                item.classList.remove('bg-purple-500/10', 'border-purple-500/30');
                item.classList.add('border-transparent', 'hover:bg-white/[0.05]');
                if (check) check.classList.add('opacity-0');
            }
        });

        const container = document.getElementById('memberListContainer');
        if (container) container.classList.add('hidden');
    };

    window.clearMemberSelection = function () {
        selectedMemberId = null;

        const hidden = document.getElementById('participantMemberId');
        if (hidden) hidden.value = '';

        const display = document.getElementById('selectedMemberDisplay');
        if (display) display.classList.add('hidden');

        const search = document.getElementById('memberSearchInput');
        if (search) search.value = '';

        const container = document.getElementById('memberListContainer');
        if (container) container.classList.remove('hidden');

        apResetVisualSelection();
        window.filterMembers('');
    };

    /* ---------- modal ---------- */

    window.openParticipantModal = function () {
        const modal = document.getElementById('participantModal');
        const card = document.getElementById('participantCard');
        if (!modal || !card) return;

        window.clearMemberSelection();
        apClearError();

        const submitBtn = document.getElementById('participantSubmitBtn');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            submitBtn.textContent = 'Confirm Participation';
        }

        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        setTimeout(() => {
            modal.classList.add('opacity-100');
            card.classList.remove('scale-95', 'opacity-0');
            card.classList.add('scale-100', 'opacity-100');
        }, 10);
    };

    window.closeParticipantModal = function () {
        const modal = document.getElementById('participantModal');
        const card = document.getElementById('participantCard');
        if (!modal || !card) return;

        modal.classList.remove('opacity-100');
        card.classList.add('scale-95', 'opacity-0');

        setTimeout(() => {
            modal.classList.add('hidden');
            /* the page was left unscrollable after a previous close */
            document.body.style.overflow = 'auto';
        }, 300);
    };

    /* ---------- submit ---------- */

    async function apSubmit() {
        apClearError();

        const form = document.getElementById('participantForm');
        const submitBtn = document.getElementById('participantSubmitBtn');
        if (!form) return;

        if (!selectedMemberId) {
            apFail('Please select a member.');
            return;
        }

        const originalLabel = submitBtn ? submitBtn.textContent : 'Confirm Participation';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            submitBtn.textContent = 'SAVING...';
        }

        try {
            const formData = new FormData(form);
            formData.set('event_id', String(EVENT_ID));

            const response = await fetch('../actions/process_add_participant.php', {
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
                window.closeParticipantModal();
                setTimeout(() => location.reload(), 400);
                return;
            }

            throw new Error((result && (result.error || result.message)) || 'Failed to add participant.');

        } catch (err) {
            apFail((err && err.message) ? err.message : 'Could not reach the server.');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                submitBtn.textContent = originalLabel;
            }
        }
    }

    /* ---------- wiring ---------- */

    document.addEventListener('DOMContentLoaded', () => {
        const search = document.getElementById('memberSearchInput');
        const container = document.getElementById('memberListContainer');

        if (search) {
            search.addEventListener('input', (e) => window.filterMembers(e.target.value));
            search.addEventListener('focus', () => {
                if (container) container.classList.remove('hidden');
            });
        }

        document.querySelectorAll('.member-item').forEach((item) => {
            item.addEventListener('click', () => {
                window.selectMember(
                    item.dataset.memberId,
                    item.dataset.memberName || '',
                    item.dataset.memberRank || '',
                    item.dataset.memberHeight || 'N/A'
                );
            });
        });

        const form = document.getElementById('participantForm');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                apSubmit();
            });
        }

        /* close the picker when clicking outside it */
        document.addEventListener('click', (e) => {
            if (!container || !search) return;

            const display = document.getElementById('selectedMemberDisplay');
            const insideList = container.contains(e.target);
            const insideSearch = search.contains(e.target);
            const insideDisplay = display && display.contains(e.target);

            if (!insideList && !insideSearch && !insideDisplay && selectedMemberId) {
                container.classList.add('hidden');
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            const modal = document.getElementById('participantModal');
            if (modal && !modal.classList.contains('hidden')) {
                window.closeParticipantModal();
            }
        });
    });
})();
</script>