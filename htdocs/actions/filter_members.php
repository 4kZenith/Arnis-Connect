<?php
require_once "../includes/auth.php";
require_once "../includes/authorize.php";
require_once "../config/Database.php";
require_once "../classes/Members.php";

header('Content-Type: application/json');

if (!canAccess('members', 'read')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

try {
    $filters = [
        'search' => $_POST['search'] ?? '',
        'status' => $_POST['status'] ?? '',
        'rank' => $_POST['rank'] ?? '',
        'member_type' => $_POST['member_type'] ?? '',
        'player_category' => $_POST['player_category'] ?? ''
    ];

    $db = (new Database())->connect();
    $membersModel = new Members($db);
    $readScope = permissionValue('members', 'read');
    $userPermission = ($readScope === 'own_trainees') ? 'limited' : 'all';
    $scopeCoachId = ($userPermission === 'limited') ? getCurrentCoachMemberId($db) : null;

    $result = $membersModel->getAllPaginated($filters, 1, 10, $userPermission, $scopeCoachId);
    $stats = $membersModel->getStats($filters, $userPermission, $scopeCoachId);

    $members = $result['members'];
    $totalMembers = $result['total'];

    $programClass = function($value) {
        return $value === 'Private' ? 'bg-yellow-500/10 text-yellow-300' : ($value === 'Mixed' ? 'bg-pink-500/10 text-pink-300' : 'bg-green-500/10 text-green-300');
    };

    $mobileHtml = '';
    if (empty($members)) {
        $mobileHtml = '<div class="p-6 text-center text-gray-500">No members found.</div>';
    } else {
        foreach ($members as $member) {
            $mobileHtml .= '
                <div class="bg-white/[0.02] rounded-xl p-4 border border-gray-800/50">
                    <div class="flex justify-between items-start mb-3 gap-3">
                        <div>
                            <div class="text-xs text-gray-500 mb-1">ID: ' . htmlspecialchars((string)$member['member_id']) . '</div>
                            <div class="font-bold text-sm">' . htmlspecialchars($member['full_name']) . '</div>
                            <div class="text-[10px] text-gray-400 mt-1">Age: ' . htmlspecialchars((string)($member['age'] ?? '—')) . ' • ' . htmlspecialchars((string)$member['rank']) . '</div>
                        </div>
                        <span class="text-[9px] font-bold px-2 py-1 rounded-full ' . ($member['status'] === 'Active' ? 'text-green-400 bg-green-400/10' : ($member['status'] === 'On Leave' ? 'text-orange-400 bg-orange-400/10' : 'text-red-400 bg-red-400/10')) . '">' . htmlspecialchars((string)$member['status']) . '</span>
                    </div>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <span class="px-2 py-1 rounded-full text-[9px] font-bold bg-blue-500/10 text-blue-400">' . htmlspecialchars((string)($member['member_type'] ?? 'Player')) . '</span>
                        <span class="px-2 py-1 rounded-full text-[9px] font-bold ' . $programClass($member['player_category'] ?? 'Public') . '">' . htmlspecialchars((string)($member['player_category'] ?? 'Public')) . '</span>
                    </div>
                    <div class="text-[10px] text-gray-400 space-y-1 mb-3">
                        <div><i class="fas fa-phone w-4"></i> ' . htmlspecialchars((string)($member['contact_number'] ?? '—')) . '</div>
                        <div><i class="fas fa-school w-4"></i> ' . htmlspecialchars((string)($member['affiliated_school'] ?? '—')) . '</div>
                        <div><i class="fas fa-map-marker-alt w-4"></i> ' . htmlspecialchars((string)($member['address'] ?? '—')) . '</div>
                    </div>
                    <div class="flex gap-2">
                        <a href="member_profile.php?id=' . urlencode((string)$member['member_id']) . '" class="flex-1 bg-white/5 hover:bg-white/10 text-center py-2 rounded-lg text-xs font-bold transition"><i class="fa-solid fa-eye mr-1"></i> View</a>
                        <button onclick="openEditMemberModal({id: ' . (int)$member['member_id'] . ', name: \'' . htmlspecialchars($member['full_name'], ENT_QUOTES) . '\', dob: \'' . htmlspecialchars($member['dob'] ?? '', ENT_QUOTES) . '\', email: \'' . htmlspecialchars($member['email'] ?? '', ENT_QUOTES) . '\', rank: \'' . htmlspecialchars($member['rank'] ?? 'None', ENT_QUOTES) . '\', address: \'' . htmlspecialchars($member['address'] ?? '', ENT_QUOTES) . '\', contact: \'' . htmlspecialchars($member['contact_number'] ?? '', ENT_QUOTES) . '\', height: \'' . htmlspecialchars($member['height'] ?? '', ENT_QUOTES) . '\', weight: \'' . htmlspecialchars($member['weight'] ?? '', ENT_QUOTES) . '\', member_type: \'' . htmlspecialchars($member['member_type'] ?? 'Player', ENT_QUOTES) . '\', player_category: \'' . htmlspecialchars($member['player_category'] ?? 'Public', ENT_QUOTES) . '\', training_preference: \'' . htmlspecialchars($member['training_preference'] ?? 'Group', ENT_QUOTES) . '\', affiliated_school: \'' . htmlspecialchars($member['affiliated_school'] ?? '', ENT_QUOTES) . '\', guardian_name: \'' . htmlspecialchars($member['guardian_name'] ?? '', ENT_QUOTES) . '\', guardian_contact: \'' . htmlspecialchars($member['guardian_contact'] ?? '', ENT_QUOTES) . '\', payment_notes: \'' . htmlspecialchars($member['payment_notes'] ?? '', ENT_QUOTES) . '\', status: \'' . htmlspecialchars($member['status'] ?? 'Active', ENT_QUOTES) . '\', current_profile_img: \'' . htmlspecialchars($member['profile_img'] ?? '', ENT_QUOTES) . '\'})"
                            class="flex-1 bg-white/5 hover:bg-white/10 text-center py-2 rounded-lg text-xs font-bold transition ' . (canAccess('members', 'write') ? 'text-blue-400 hover:text-white cursor-pointer' : 'text-gray-700 opacity-50 cursor-not-allowed') . '" ' . (!canAccess('members', 'write') ? 'disabled' : '') . '><i class="fa-solid fa-edit mr-1"></i> Edit</button>
                        <button onclick="openVoidModal(\'Member\', ' . (int)$member['member_id'] . ', \'' . htmlspecialchars($member['full_name'], ENT_QUOTES) . '\')" class="flex-1 text-center py-2 rounded-lg text-xs font-bold transition bg-white/5 hover:bg-orange-500/20 cursor-pointer"><i class="fas fa-ban mr-1"></i> Void</button>
                    </div>
                </div>';
        }
    }

    $desktopHtml = '';
    if (empty($members)) {
        $desktopHtml = '<tr><td colspan="10" class="p-6 text-center text-gray-500">No members found.</td></tr>';
    } else {
        foreach ($members as $member) {
            $desktopHtml .= '
                <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                    <td class="p-4 lg:p-6">' . htmlspecialchars((string)$member['member_id']) . '</td>
                    <td class="p-4 lg:p-6">' . htmlspecialchars($member['full_name']) . '</td>
                    <td class="p-4 lg:p-6">' . htmlspecialchars((string)($member['age'] ?? '—')) . '</td>
                    <td class="p-4 lg:p-6">' . htmlspecialchars((string)$member['rank']) . '</td>
                    <td class="p-4 lg:p-6"><span class="px-2 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-400">' . htmlspecialchars((string)($member['member_type'] ?? 'Player')) . '</span></td>
                    <td class="p-4 lg:p-6"><span class="px-2 py-1 rounded-full text-xs font-semibold ' . $programClass($member['player_category'] ?? 'Public') . '">' . htmlspecialchars((string)($member['player_category'] ?? 'Public')) . '</span></td>
                    <td class="p-4 lg:p-6">' . htmlspecialchars((string)($member['affiliated_school'] ?? '—')) . '</td>
                    <td class="p-4 lg:p-6">' . htmlspecialchars((string)($member['contact_number'] ?? '—')) . '</td>
                    <td class="p-4 lg:p-6"><span class="px-3 py-1 rounded-full text-xs ' . ($member['status'] === 'Active' ? 'text-green-400 bg-green-400/10' : ($member['status'] === 'On Leave' ? 'text-orange-400 bg-orange-400/10' : 'text-red-400 bg-red-400/10')) . '">' . htmlspecialchars((string)$member['status']) . '</span></td>
                    <td class="p-4 lg:p-6 text-center space-x-3">
                        <a href="member_profile.php?id=' . urlencode((string)$member['member_id']) . '" class="text-gray-500 hover:text-green-500 transition-colors"><i class="fa-solid fa-eye"></i></a>
                        <button onclick="openEditMemberModal({id: ' . (int)$member['member_id'] . ', name: \'' . htmlspecialchars($member['full_name'], ENT_QUOTES) . '\', dob: \'' . htmlspecialchars($member['dob'] ?? '', ENT_QUOTES) . '\', email: \'' . htmlspecialchars($member['email'] ?? '', ENT_QUOTES) . '\', rank: \'' . htmlspecialchars($member['rank'] ?? 'None', ENT_QUOTES) . '\', address: \'' . htmlspecialchars($member['address'] ?? '', ENT_QUOTES) . '\', contact: \'' . htmlspecialchars($member['contact_number'] ?? '', ENT_QUOTES) . '\', height: \'' . htmlspecialchars($member['height'] ?? '', ENT_QUOTES) . '\', weight: \'' . htmlspecialchars($member['weight'] ?? '', ENT_QUOTES) . '\', member_type: \'' . htmlspecialchars($member['member_type'] ?? 'Player', ENT_QUOTES) . '\', player_category: \'' . htmlspecialchars($member['player_category'] ?? 'Public', ENT_QUOTES) . '\', training_preference: \'' . htmlspecialchars($member['training_preference'] ?? 'Group', ENT_QUOTES) . '\', affiliated_school: \'' . htmlspecialchars($member['affiliated_school'] ?? '', ENT_QUOTES) . '\', guardian_name: \'' . htmlspecialchars($member['guardian_name'] ?? '', ENT_QUOTES) . '\', guardian_contact: \'' . htmlspecialchars($member['guardian_contact'] ?? '', ENT_QUOTES) . '\', payment_notes: \'' . htmlspecialchars($member['payment_notes'] ?? '', ENT_QUOTES) . '\', status: \'' . htmlspecialchars($member['status'] ?? 'Active', ENT_QUOTES) . '\', current_profile_img: \'' . htmlspecialchars($member['profile_img'] ?? '', ENT_QUOTES) . '\'})"
                            class="transition ' . (canAccess('members', 'write') ? 'text-blue-400 hover:text-white cursor-pointer' : 'text-gray-700 opacity-50 cursor-not-allowed') . '" ' . (!canAccess('members', 'write') ? 'disabled' : '') . '><i class="fas fa-edit"></i></button>
                        <button onclick="openVoidModal(\'Member\', ' . (int)$member['member_id'] . ', \'' . htmlspecialchars($member['full_name'], ENT_QUOTES) . '\')" class="text-gray-600 hover:text-orange-500 transition cursor-pointer"><i class="fas fa-ban"></i></button>
                    </td>
                </tr>';
        }
    }

    echo json_encode([
        'success' => true,
        'members' => $members,
        'totalMembers' => $totalMembers,
        'mobileHtml' => $mobileHtml,
        'desktopHtml' => $desktopHtml,
        'stats' => [
            'total' => (int)($stats['total'] ?? 0),
            'active' => (int)($stats['active'] ?? 0),
            'public_count' => (int)($stats['public_count'] ?? 0),
            'private_count' => (int)($stats['private_count'] ?? 0)
        ]
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}