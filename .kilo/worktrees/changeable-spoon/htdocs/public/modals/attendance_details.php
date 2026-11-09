<div id="attendanceDetailsModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4 transition-opacity duration-300 opacity-0">
    <div id="attendanceDetailsCard" class="card-bg w-full max-w-lg rounded-3xl max-h-[80vh] flex flex-col overflow-hidden shadow-2xl transform scale-95 opacity-0 transition-all duration-300 ease-out border border-gray-800">
        
        <div class="p-6 border-b border-gray-800 flex justify-between items-center">
            <div>
                <h2 id="attDetailTitle" class="text-xl font-black italic uppercase tracking-wider text-blue-400">Present Records</h2>
                <p class="text-[10px] text-gray-500 uppercase tracking-widest mt-1">Attendance history for Lucas Hood</p>
            </div>
            <button onclick="closeAttendanceDetails()" class="text-gray-500 hover:text-white transition"><i class="fas fa-times"></i></button>
        </div>

        <div class="p-0 max-h-[60vh] overflow-y-auto">
            <table class="w-full text-left border-collapse">
                <thead class="sticky top-0 bg-[#161b22] text-gray-500 text-[10px] uppercase tracking-widest border-b border-gray-800">
                    <tr>
                        <th class="p-6">Date</th>
                        <th class="p-6">Training Session</th>
                        <th class="p-6">Coach</th>
                        <th class="p-6 text-right">Status</th>
                    </tr>
                </thead>
                <tbody id="attendanceDetailTable" class="text-sm">
                    </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatTime(timeStr) {
    if (!timeStr) return 'N/A';
    const [hours, minutes] = timeStr.split(':');
    const h = parseInt(hours, 10);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const displayH = h % 12 || 12;
    return `${displayH}:${minutes} ${ampm}`;
}

function openAttendanceDetails(memberId, statusType) {
    const modal = document.getElementById('attendanceDetailsModal');
    const card = document.getElementById('attendanceDetailsCard');
    const title = document.getElementById('attDetailTitle');
    const tableBody = document.getElementById('attendanceDetailTable');

    if (!memberId || memberId <= 0) {
        console.error('Invalid member ID:', memberId);
        return;
    }

    title.innerText = `${statusType} Records`;
    title.className = `text-xl font-black italic uppercase tracking-wider ${
        statusType === 'Present' ? 'text-green-400' : 
        statusType === 'Late' ? 'text-yellow-400' : 'text-red-400'
    }`;

    // Show loading state
    tableBody.innerHTML = '<tr><td colspan="4" class="p-6 text-center text-gray-500">Loading...</td></tr>';

    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.add('opacity-100');
        card.classList.replace('scale-95', 'scale-100');
        card.classList.replace('opacity-0', 'opacity-100');
    }, 10);

    // Fetch actual data
    fetch(`../actions/get_attendance_details.php?member_id=${memberId}&status=${statusType}`)
        .then(r => r.json())
        .then(result => {
            if (result.success && result.data.length > 0) {
                tableBody.innerHTML = result.data.map(row => `
                    <tr class="border-b border-gray-800 hover:bg-white/5 transition">
                        <td class="p-6 text-gray-400 font-mono text-xs">${formatDate(row.training_date)}</td>
                        <td class="p-6 font-bold">${row.location || 'N/A'}</td>
                        <td class="p-6 text-gray-500">${row.coach_name || 'N/A'}</td>
                        <td class="p-6 text-right">
                            <span class="px-2 py-1 rounded-md text-[9px] font-black uppercase tracking-tighter ${
                                statusType === 'Present' ? 'bg-green-500/10 text-green-500' : 
                                statusType === 'Late' ? 'bg-yellow-500/10 text-yellow-500' : 'bg-red-500/10 text-red-500'
                            }">${statusType}</span>
                        </td>
                    </tr>
                `).join('');
            } else {
                tableBody.innerHTML = '<tr><td colspan="4" class="p-6 text-center text-gray-500">No records found</td></tr>';
            }
        })
        .catch(err => {
            console.error('Error fetching attendance details:', err);
            tableBody.innerHTML = '<tr><td colspan="4" class="p-6 text-center text-red-500">Error loading records</td></tr>';
        });
}

function closeAttendanceDetails() {
    const modal = document.getElementById('attendanceDetailsModal');
    const card = document.getElementById('attendanceDetailsCard');
    modal.classList.remove('opacity-100');
    card.classList.replace('scale-100', 'scale-95');
    card.classList.replace('opacity-100', 'opacity-0');
    setTimeout(() => modal.classList.add('hidden'), 300);
}
</script>