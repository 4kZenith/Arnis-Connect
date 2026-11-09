<?php
/*
 * Face + geolocation self check-in (training or event).
 *
 * Accepts (all optional, with back-compat fallbacks):
 *   $checkinType         'training' (default) or 'event'
 *   $checkinTraining / $training / $checkinEvent / $event   array  row for summary
 *   $checkinTrainingId / $trainingId / $checkinEventId / $eventId  int
 *   $checkinMemberId / $memberId     int
 *   $checkinCsrf / $csrfToken        string
 *
 * Requires face-api.js on the host page for the camera flow.
 */
$checkinType = (string)($checkinType ?? 'training');
if (!in_array($checkinType, ['training', 'event'], true)) $checkinType = 'training';
$isEvent = ($checkinType === 'event');

if ($isEvent) {
    $checkinRow = (isset($checkinEvent) && is_array($checkinEvent)) ? $checkinEvent : ((isset($event) && is_array($event)) ? $event : []);
    $checkinId = (int)($checkinEventId ?? $eventId ?? 0);
} else {
    $checkinRow = (isset($checkinTraining) && is_array($checkinTraining)) ? $checkinTraining : ((isset($training) && is_array($training)) ? $training : []);
    $checkinId = (int)($checkinTrainingId ?? $trainingId ?? 0);
}
$checkinMemberId = (int)($checkinMemberId ?? $memberId ?? 0);
$checkinCsrf = (string)($checkinCsrf ?? $csrfToken ?? '');

if ($isEvent) {
    $checkinDateTs = !empty($checkinRow['event_date']) ? strtotime((string)$checkinRow['event_date']) : false;
    $checkinStartTs = false; $checkinEndTs = false;
    $checkinDateLabel = $checkinDateTs !== false ? date('M d, Y', $checkinDateTs) : 'TBA';
    $checkinStartLabel = 'Event'; $checkinEndLabel = '';
    $checkinLocationLabel = trim((string)($checkinRow['location'] ?? $checkinRow['event_name'] ?? '')) !== ''
        ? (string)($checkinRow['location'] ?? $checkinRow['event_name'])
        : 'Event';
    $idField = 'event_id';
    $endpoint = '../actions/process_event_checkin.php';
    $idPrefix = 'EVT';
} else {
    $checkinDateTs = !empty($checkinRow['training_date']) ? strtotime((string)$checkinRow['training_date']) : false;
    $checkinStartTs = !empty($checkinRow['start_time']) ? strtotime((string)$checkinRow['start_time']) : false;
    $checkinEndTs = !empty($checkinRow['end_time']) ? strtotime((string)$checkinRow['end_time']) : false;
    $checkinDateLabel = $checkinDateTs !== false ? date('M d, Y', $checkinDateTs) : 'TBA';
    $checkinStartLabel = $checkinStartTs !== false ? date('h:i A', $checkinStartTs) : 'TBA';
    $checkinEndLabel = $checkinEndTs !== false ? date('h:i A', $checkinEndTs) : 'TBA';
    $checkinLocationLabel = trim((string)($checkinRow['location'] ?? '')) !== '' ? (string)$checkinRow['location'] : 'Training Session';
    $idField = 'training_id';
    $endpoint = '../actions/process_attendance_checkin.php';
    $idPrefix = 'TRN';
}
?>
<div id="attendanceCheckinModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden z-50 flex items-center justify-center p-4">
    <div id="attendanceCheckinCard" class="card-bg w-full max-w-md rounded-3xl max-h-[90vh] flex flex-col overflow-hidden shadow-2xl transform transition-all duration-300 ease-out scale-95 opacity-0">
        <div class="px-8 py-6 border-b border-gray-800 flex justify-between items-center">
            <h2 class="text-xl tracking-wide font-bold italic uppercase text-purple-400">Check In</h2>
            <button type="button" onclick="closeCheckinModal()" class="text-gray-500 hover:text-white transition"><i class="fas fa-times text-xl"></i></button>
        </div>

        <form id="attendanceCheckinForm" class="p-8 flex-1 overflow-y-auto" onsubmit="return false;">
            <input type="hidden" name="<?= htmlspecialchars($idField, ENT_QUOTES, 'UTF-8') ?>" id="checkin_target_id" value="<?= $checkinId ?>">
            <input type="hidden" name="member_id" id="checkin_member_id" value="<?= $checkinMemberId ?>">
            <input type="hidden" name="face_descriptor" id="checkin_face_descriptor">
            <input type="hidden" name="latitude" id="checkin_latitude">
            <input type="hidden" name="longitude" id="checkin_longitude">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($checkinCsrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="space-y-6">
                <div class="text-center p-4 bg-white/5 rounded-2xl border border-gray-700/50">
                    <p class="text-gray-400 text-xs uppercase tracking-widest mb-1"><?= htmlspecialchars($checkinLocationLabel, ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-sm font-semibold text-white"><?= htmlspecialchars($checkinDateLabel, ENT_QUOTES, 'UTF-8') ?><?php if ($checkinStartLabel !== 'Event'): ?> • <?= htmlspecialchars($checkinStartLabel, ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($checkinEndLabel, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?= $isEvent ? 'Event' : 'Session' ?> ID: #<?= $idPrefix ?>-<?= str_pad((string)$checkinId, 4, '0', STR_PAD_LEFT) ?></p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-purple-500/10 flex items-center justify-center">
                        <i class="fas fa-user-check text-2xl text-purple-400"></i>
                    </div>
                    <p class="text-gray-300 text-sm mb-2" id="checkinTitle">Verify your identity and location</p>
                    <p class="text-gray-500 text-xs" id="checkinSubtitle">Position your face in the frame and allow location access</p>
                </div>

                <div class="relative" id="checkinCameraContainer">
                    <video id="checkinVideo" class="w-full aspect-video rounded-xl bg-gray-900" autoplay muted playsinline></video>
                    <canvas id="checkinCanvas" class="absolute top-0 left-0 w-full h-full rounded-xl pointer-events-none"></canvas>
                    <div id="checkinOverlay" class="absolute top-0 left-0 w-full h-full rounded-xl flex items-center justify-center pointer-events-none">
                        <div class="w-48 h-48 border-2 border-purple-400/50 rounded-full animate-pulse"></div>
                    </div>
                </div>

                <div id="checkinStatus" class="text-center text-sm text-gray-400 hidden">
                    <div class="flex items-center justify-center gap-2">
                        <div class="w-4 h-4 border-2 border-purple-400 border-t-transparent rounded-full animate-spin"></div>
                        <span id="checkinStatusText">Initializing camera...</span>
                    </div>
                </div>

                <div id="checkinError" class="text-center text-sm text-red-400 hidden" role="alert"></div>
                <div id="checkinSuccess" class="text-center text-sm text-green-400 hidden" role="status"></div>

                <div id="checkinLocationStatus" class="text-center text-xs text-gray-500 hidden">
                    <i class="fas fa-map-marker-alt mr-1"></i> <span id="checkinLocationText">Getting location...</span>
                </div>

                <div class="flex gap-3" id="checkinActions">
                    <button type="button" onclick="startCheckin()"
                            id="startCheckinBtn"
                            class="flex-1 px-6 py-3 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 rounded-xl transition text-sm font-semibold shadow-lg shadow-purple-900/30">
                        <i class="fas fa-video mr-2"></i>Start Verification
                    </button>
                    <button type="button" onclick="captureCheckin()"
                            id="captureCheckinBtn"
                            class="flex-1 px-6 py-3 bg-green-600 hover:bg-green-700 rounded-xl transition text-sm font-semibold shadow-lg shadow-green-900/30 hidden disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled>
                        <i class="fas fa-check mr-2"></i>Check In
                    </button>
                </div>

                <div class="flex gap-3" id="checkinRetryActions" hidden>
                    <button type="button" onclick="retakeCheckin()"
                            class="flex-1 px-6 py-3 bg-gray-700 hover:bg-gray-600 rounded-xl transition text-sm font-semibold">
                        <i class="fas fa-redo mr-2"></i>Retake
                    </button>
                    <button type="button" onclick="closeCheckinModal()"
                            class="flex-1 px-6 py-3 rounded-3xl hover:bg-gray-800 transition text-sm font-semibold">CANCEL</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let checkinVideo = null;
let checkinCanvas = null;
let checkinDetection = null;
let checkinModelsLoaded = false;
let checkinStream = null;
const CHECKIN_ENDPOINT = <?= json_encode($endpoint, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const CHECKIN_ID_FIELD = <?= json_encode($idField, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const CHECKIN_MODEL_BASE_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/model/';

async function loadCheckinFaceApiModels() {
    if (checkinModelsLoaded) return true;
    try {
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(CHECKIN_MODEL_BASE_URL),
            faceapi.nets.faceLandmark68Net.loadFromUri(CHECKIN_MODEL_BASE_URL),
            faceapi.nets.faceRecognitionNet.loadFromUri(CHECKIN_MODEL_BASE_URL),
        ]);
        checkinModelsLoaded = true;
        return true;
    } catch (error) {
        console.error('Failed to load face-api.js models for check-in:', error);
        return false;
    }
}

function openCheckinModal(targetId, memberId) {
    document.getElementById('checkin_target_id').value = targetId;
    document.getElementById('checkin_member_id').value = memberId;
    document.getElementById('checkin_face_descriptor').value = '';
    document.getElementById('checkin_latitude').value = '';
    document.getElementById('checkin_longitude').value = '';

    document.getElementById('checkinStatus').classList.add('hidden');
    document.getElementById('checkinError').classList.add('hidden');
    document.getElementById('checkinSuccess').classList.add('hidden');
    document.getElementById('checkinLocationStatus').classList.add('hidden');
    document.getElementById('startCheckinBtn').classList.remove('hidden');
    document.getElementById('captureCheckinBtn').classList.add('hidden');
    document.getElementById('captureCheckinBtn').disabled = true;
    document.getElementById('checkinRetryActions').hidden = true;
    document.getElementById('checkinActions').hidden = false;
    document.getElementById('checkinCameraContainer').classList.remove('hidden');
    document.getElementById('checkinOverlay').classList.remove('hidden');

    const modal = document.getElementById('attendanceCheckinModal');
    const card = document.getElementById('attendanceCheckinCard');
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        card.classList.remove('scale-95', 'opacity-0');
        card.classList.add('scale-100', 'opacity-100');
    });

    checkinVideo = document.getElementById('checkinVideo');
    checkinCanvas = document.getElementById('checkinCanvas');
}

function closeCheckinModal() {
    const modal = document.getElementById('attendanceCheckinModal');
    const card = document.getElementById('attendanceCheckinCard');
    stopCheckinCamera();
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }, 300);
}

function stopCheckinCamera() {
    if (checkinStream) {
        checkinStream.getTracks().forEach(track => track.stop());
        checkinStream = null;
    }
    if (checkinVideo) checkinVideo.srcObject = null;
    if (checkinDetection) { cancelAnimationFrame(checkinDetection); checkinDetection = null; }
}

async function startCheckin() {
    const statusEl = document.getElementById('checkinStatus');
    const statusText = document.getElementById('checkinStatusText');
    const errorEl = document.getElementById('checkinError');
    const locationStatusEl = document.getElementById('checkinLocationStatus');
    const startBtn = document.getElementById('startCheckinBtn');
    const captureBtn = document.getElementById('captureCheckinBtn');
    const overlay = document.getElementById('checkinOverlay');

    errorEl.classList.add('hidden'); errorEl.textContent = '';
    locationStatusEl.classList.add('hidden');
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading models...';
    statusEl.classList.remove('hidden');
    statusText.textContent = 'Loading face detection models...';

    const loaded = await loadCheckinFaceApiModels();
    if (!loaded) {
        statusEl.classList.add('hidden');
        errorEl.textContent = 'Failed to load face detection models. Check your internet connection.';
        errorEl.classList.remove('hidden');
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-video mr-2"></i>Start Verification';
        return;
    }

    statusText.textContent = 'Requesting camera access...';
    try {
        checkinStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false,
        });
        checkinVideo.srcObject = checkinStream;
        await new Promise((resolve) => {
            checkinVideo.onloadedmetadata = () => { checkinVideo.play().then(resolve).catch(resolve); };
        });
        checkinCanvas.width = checkinVideo.videoWidth;
        checkinCanvas.height = checkinVideo.videoHeight;

        statusText.textContent = 'Getting your location...';
        locationStatusEl.classList.remove('hidden');
        document.getElementById('checkinLocationText').textContent = 'Getting location...';
        navigator.geolocation.getCurrentPosition(
            (position) => {
                document.getElementById('checkin_latitude').value = position.coords.latitude;
                document.getElementById('checkin_longitude').value = position.coords.longitude;
                document.getElementById('checkinLocationText').textContent = `Location acquired (±${Math.round(position.coords.accuracy)}m accuracy)`;
            },
            (error) => {
                console.warn('Geolocation error:', error);
                document.getElementById('checkinLocationText').textContent = 'Location access denied';
                locationStatusEl.classList.add('hidden');
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );

        statusEl.classList.add('hidden');
        startBtn.classList.add('hidden');
        captureBtn.classList.remove('hidden');
        captureBtn.disabled = false;
        overlay.classList.add('hidden');
        detectCheckinFaceLoop();
    } catch (error) {
        console.error('Camera access error:', error);
        statusEl.classList.add('hidden');
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-video mr-2"></i>Start Verification';
        let msg = 'Could not access camera. ';
        if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') msg += 'Please allow camera access in your browser settings.';
        else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') msg += 'No camera found on this device.';
        else if (error.name === 'NotReadableError' || error.name === 'TrackStartError') msg += 'Camera is in use by another application.';
        else msg += error.message || 'Unknown error.';
        errorEl.textContent = msg;
        errorEl.classList.remove('hidden');
    }
}

function detectCheckinFaceLoop() {
    if (!checkinVideo || !checkinCanvas || checkinVideo.paused || checkinVideo.ended) return;
    const ctx = checkinCanvas.getContext('2d');
    const displaySize = { width: checkinVideo.videoWidth, height: checkinVideo.videoHeight };
    faceapi.matchDimensions(checkinCanvas, displaySize);
    faceapi.detectAllFaces(checkinVideo, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }))
    .withFaceLandmarks().withFaceDescriptors()
    .then(detections => {
        ctx.clearRect(0, 0, checkinCanvas.width, checkinCanvas.height);
        if (detections.length > 0) {
            detections.sort((a, b) => (b.detection.box.width * b.detection.box.height) - (a.detection.box.width * a.detection.box.height));
            const bestDetection = detections[0];
            const resized = faceapi.resizeResults(bestDetection, displaySize);
            const box = resized.detection.box;
            ctx.strokeStyle = '#bf46c7'; ctx.lineWidth = 3;
            ctx.strokeRect(box.x, box.y, box.width, box.height);
            ctx.fillStyle = '#bf46c7';
            resized.landmarks.positions.forEach(point => { ctx.beginPath(); ctx.arc(point.x, point.y, 2, 0, 2 * Math.PI); ctx.fill(); });
            checkinDetection = bestDetection;
        } else {
            checkinDetection = null;
        }
        requestAnimationFrame(detectCheckinFaceLoop);
    })
    .catch(err => { console.error('Detection error:', err); requestAnimationFrame(detectCheckinFaceLoop); });
}

function captureCheckin() {
    const captureBtn = document.getElementById('captureCheckinBtn');
    const retryActions = document.getElementById('checkinRetryActions');
    const actions = document.getElementById('checkinActions');
    const errorEl = document.getElementById('checkinError');
    const successEl = document.getElementById('checkinSuccess');
    const overlay = document.getElementById('checkinOverlay');
    errorEl.classList.add('hidden'); successEl.classList.add('hidden');
    if (!checkinDetection) { errorEl.textContent = 'No face detected. Position your face in the frame and try again.'; errorEl.classList.remove('hidden'); return; }
    const descriptor = checkinDetection.descriptor;
    if (!descriptor || descriptor.length !== 128) { errorEl.textContent = 'Failed to compute face descriptor. Please try again.'; errorEl.classList.remove('hidden'); return; }
    document.getElementById('checkin_face_descriptor').value = JSON.stringify(Array.from(descriptor));
    captureBtn.classList.add('hidden'); actions.hidden = true; retryActions.hidden = false; overlay.classList.remove('hidden');
    successEl.textContent = 'Face captured! Verifying location and checking in...'; successEl.classList.remove('hidden');
    submitCheckin();
}

function retakeCheckin() {
    document.getElementById('checkin_face_descriptor').value = '';
    document.getElementById('checkin_latitude').value = '';
    document.getElementById('checkin_longitude').value = '';
    checkinDetection = null;
    document.getElementById('checkinError').classList.add('hidden');
    document.getElementById('checkinSuccess').classList.add('hidden');
    document.getElementById('checkinLocationStatus').classList.add('hidden');
    document.getElementById('captureCheckinBtn').classList.remove('hidden');
    document.getElementById('captureCheckinBtn').disabled = false;
    document.getElementById('checkinRetryActions').hidden = true;
    document.getElementById('checkinActions').hidden = false;
    document.getElementById('checkinOverlay').classList.remove('hidden');
}

async function submitCheckin() {
    const form = document.getElementById('attendanceCheckinForm');
    const formData = new FormData(form);
    const errorEl = document.getElementById('checkinError');
    const successEl = document.getElementById('checkinSuccess');
    const submitBtn = document.querySelector('#checkinRetryActions button:first-child');
    errorEl.classList.add('hidden'); successEl.classList.add('hidden');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Checking in...'; }
    try {
        const response = await fetch(CHECKIN_ENDPOINT, { method: 'POST', credentials: 'same-origin', body: formData });
        const data = await response.json().catch(() => ({ success: false, error: 'Unexpected server response.' }));
        if (data.success) {
            successEl.textContent = data.message; successEl.classList.remove('hidden');
            if (typeof showNotification === 'function') showNotification(data.message, 'success');
            setTimeout(() => { closeCheckinModal(); window.location.reload(); }, 1500);
        } else {
            errorEl.textContent = data.error || 'Check-in failed.'; errorEl.classList.remove('hidden');
            if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-redo mr-2"></i>Retry'; }
        }
    } catch (error) {
        console.error('Check-in error:', error);
        errorEl.textContent = 'Network error. Please try again.'; errorEl.classList.remove('hidden');
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="fas fa-redo mr-2"></i>Retry'; }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('attendanceCheckinModal');
    if (modal) {
        modal.addEventListener('mousedown', function (event) {
            if (event.target === modal) closeCheckinModal();
        });
    }
});
</script>
