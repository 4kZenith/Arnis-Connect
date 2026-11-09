<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
arnisStartSession();
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../classes/Mailer.php';
require_once __DIR__ . '/../classes/AuditLog.php';

function redirectWithMessage(string $type, string $message): void
{
    header('Location: ../register.php?type=' . urlencode($type) . '&message=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithMessage('error', 'Invalid request method.');
}

if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectWithMessage('error', 'Your registration session expired. Please reload the form and try again.');
}

$fullName = trim((string)($_POST['full_name'] ?? ''));
$dob = trim((string)($_POST['dob'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');
$contact = trim((string)($_POST['contact_number'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$playerCategory = (string)($_POST['player_category'] ?? 'Public');
$trainingPreference = (string)($_POST['training_preference'] ?? 'Group');
$affiliatedSchool = trim((string)($_POST['affiliated_school'] ?? ''));
$guardianName = trim((string)($_POST['guardian_name'] ?? ''));
$guardianContact = trim((string)($_POST['guardian_contact'] ?? ''));
$paymentNotes = trim((string)($_POST['payment_notes'] ?? ''));
$requestedRank = (string)($_POST['requested_rank'] ?? 'None');
$prevOrganization = trim((string)($_POST['prev_organization'] ?? ''));
$prevInstructor = trim((string)($_POST['prev_instructor'] ?? ''));
$rankAwardedYear = trim((string)($_POST['rank_awarded_year'] ?? ''));
$contactOk = isset($_POST['contact_ok']) && (int)$_POST['contact_ok'] === 1;
$privacyConsent = isset($_POST['privacy_consent']) && (int)$_POST['privacy_consent'] === 1;

$allowedPlayerCategories = ['Public', 'Private', 'Mixed'];
$allowedTrainingPreferences = ['Group', 'Private', 'Flexible'];
$allowedRanks = ['None', 'I. White Belt', 'II. Yellow Belt', 'III. Green Belt', 'IV. Blue Belt', 'V. Brown Belt', 'VI. Black Belt', 'VII. Red Belt', '1st Degree- Junior Instructor', '2nd Degree- Junior Instructor', '3rd Degree- Instructor', '4th Degree- Instructor', '5th Degree- Senior Instructor', '6th Degree- Senior Instructor', '7th Degree- Junior Master', '8th Degree- Master', '9th Degree- Senior Master', '10th Degree- Grandmaster', '11th Degree- Senior Grandmaster', '12th Degree- Superior Grandmaster'];

try {
    $db = (new Database())->connect();
} catch (Throwable $e) {
    error_log('process_register_request DB connect error: ' . $e->getMessage());
    redirectWithMessage('error', 'Registration is temporarily unavailable. Please try again later.');
}

$settings = new Settings($db);
if (!$settings->enabled('allow_registration')) {
    redirectWithMessage('error', 'Registration is currently closed. Please contact the club administrator.');
}
$requireVerification = $settings->enabled('require_email_verification');

if ($fullName === '' || $dob === '' || $contact === '') {
    redirectWithMessage('error', 'Full name, birthdate, and contact number are required.');
}

if (!$privacyConsent) {
    redirectWithMessage('error', 'You must accept the Data Privacy Act notice before submitting your application.');
}

/* Login credentials: the applicant signs in as Applicant to track this
 * request, and the same login becomes their Member account after approval. */
if ($username === '' || $password === '' || $confirmPassword === '') {
    redirectWithMessage('error', 'Username and password are required.');
}
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    redirectWithMessage('error', 'Username may only contain letters, numbers, dot, underscore and hyphen (3-50 characters).');
}
if (strlen($password) < 6) {
    redirectWithMessage('error', 'Password must be at least 6 characters.');
}
if ($password !== $confirmPassword) {
    redirectWithMessage('error', 'Passwords do not match.');
}

$dobObj = DateTime::createFromFormat('Y-m-d', $dob);
if (!$dobObj || $dobObj->format('Y-m-d') !== $dob || $dob > date('Y-m-d')) {
    redirectWithMessage('error', 'Please enter a valid birthdate.');
}

if (mb_strlen($fullName) > 100) {
    redirectWithMessage('error', 'Full name must not exceed 100 characters.');
}

if (!in_array($playerCategory, $allowedPlayerCategories, true)
    || !in_array($trainingPreference, $allowedTrainingPreferences, true)
    || !in_array($requestedRank, $allowedRanks, true)) {
    redirectWithMessage('error', 'One or more selected application options are invalid.');
}

if ($email === '') {
    if ($requireVerification) {
        redirectWithMessage('error', 'An email address is required so we can verify your account.');
    }
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
    redirectWithMessage('error', 'Please enter a valid email address.');
}

if ($playerCategory === 'Public' && $paymentNotes === '') {
    $paymentNotes = 'Public player applicant - free / school-based training.';
} elseif ($playerCategory === 'Private' && $paymentNotes === '') {
    $paymentNotes = 'Private player applicant - paid private training arrangement.';
} elseif ($playerCategory === 'Mixed' && $paymentNotes === '') {
    $paymentNotes = 'Mixed player applicant - may join public and private sessions.';
}

try {
    /* Username must be free both as a login and across pending applications. */
    $taken = $db->prepare('SELECT user_id FROM users WHERE username = :username LIMIT 1');
    $taken->execute([':username' => $username]);
    if ($taken->fetch()) {
        redirectWithMessage('error', 'That username is already taken. Please choose another.');
    }
    $takenReq = $db->prepare("SELECT request_id FROM registration_requests WHERE status = 'Pending' AND username = :username LIMIT 1");
    $takenReq->execute([':username' => $username]);
    if ($takenReq->fetch()) {
        redirectWithMessage('error', 'That username already has a pending application.');
    }

    if ($email !== '') {
        $dup = $db->prepare("SELECT request_id FROM registration_requests WHERE status = 'Pending' AND email = :email LIMIT 1");
        $dup->execute([':email' => $email]);
        if ($dup->fetch()) {
            redirectWithMessage('error', 'A pending application with the same email already exists.');
        }
        $dupUser = $db->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
        $dupUser->execute([':email' => $email]);
        if ($dupUser->fetch()) {
            redirectWithMessage('error', 'That email address is already registered.');
        }
    }

    $dup = $db->prepare("SELECT request_id FROM registration_requests WHERE status = 'Pending' AND full_name = :full_name AND dob = :dob LIMIT 1");
    $dup->execute([':full_name' => $fullName, ':dob' => $dob]);
    if ($dup->fetch()) {
        redirectWithMessage('error', 'A pending application with the same name and birthdate already exists.');
    }

    // Handle rank verification requirements and upload.
    $rankEvidencePath = null;
    if ($requestedRank !== 'None') {
        if (!$contactOk) {
            redirectWithMessage('error', 'You must confirm the contact consent checkbox for non-beginner rank requests.');
        }
        if ($prevOrganization === '' || $prevInstructor === '' || $rankAwardedYear === '') {
            redirectWithMessage('error', 'Please provide previous organization, instructor, and year rank was awarded.');
        }
        if (!preg_match('/^\d{4}$/', $rankAwardedYear) || (int)$rankAwardedYear < 1900 || (int)$rankAwardedYear > (int)date('Y')) {
            redirectWithMessage('error', 'Please enter a valid year rank was awarded.');
        }
        if (!isset($_FILES['rank_evidence']) || $_FILES['rank_evidence']['error'] !== UPLOAD_ERR_OK) {
            redirectWithMessage('error', 'Please upload your rank certificate (PDF or image).');
        }
        $file = $_FILES['rank_evidence'];
        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/jpg' => 'jpg',
        ];
        $max = 5 * 1024 * 1024;
        if ($file['size'] > $max) {
            redirectWithMessage('error', 'File too large (max 5MB).');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!isset($allowed[$mime])) {
            redirectWithMessage('error', 'Invalid file type. Only PDF/JPG/PNG allowed.');
        }
        $ext = $allowed[$mime];
        $uploadDir = __DIR__ . '/../uploads/rank_evidence/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $filename = 'rank_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $uploadDir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            redirectWithMessage('error', 'Failed to save rank evidence file.');
        }
        $rankEvidencePath = 'uploads/rank_evidence/' . $filename;
    }

    /*
     * New accounts are only blocked when they carry a verification token and
     * have no email_verified_at. Existing/grandfathered accounts have no token,
     * so enabling verification does not lock them out.
     */
    $verificationToken = null;
    $verificationExpiresAt = null;
    $emailVerifiedAt = date('Y-m-d H:i:s');
    if ($requireVerification) {
        $verificationToken = bin2hex(random_bytes(32));
        $verificationExpiresAt = date('Y-m-d H:i:s', time() + 86400);
        $emailVerifiedAt = null;
    }

    $createUser = $db->prepare(
        'INSERT INTO users
            (full_name, username, email, password_hash, role, is_active,
             email_verified_at, email_verification_token, email_verification_expires_at,
             privacy_consent_at)
         VALUES
            (:full_name, :username, :email, :password_hash, \'Applicant\', 1,
             :email_verified_at, :verification_token, :verification_expires_at,
             NOW())'
    );
    $createUser->execute([
        ':full_name' => $fullName,
        ':username' => $username,
        ':email' => $email !== '' ? $email : null,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':email_verified_at' => $emailVerifiedAt,
        ':verification_token' => $verificationToken,
        ':verification_expires_at' => $verificationExpiresAt,
    ]);
    $applicantUserId = (int)$db->lastInsertId();

    $stmt = $db->prepare(
        'INSERT INTO registration_requests
         (full_name, dob, email, contact_number, address, player_category, training_preference,
          affiliated_school, guardian_name, guardian_contact, payment_notes, requested_rank, status,
          username, user_id, prev_organization, prev_instructor, rank_awarded_year, rank_evidence_path, contact_ok, privacy_consent_at)
         VALUES
         (:full_name, :dob, :email, :contact_number, :address, :player_category, :training_preference,
          :affiliated_school, :guardian_name, :guardian_contact, :payment_notes, :requested_rank, \'Pending\',
          :username, :user_id, :prev_organization, :prev_instructor, :rank_awarded_year, :rank_evidence_path, :contact_ok, NOW())'
    );
    $stmt->execute([
        ':full_name' => $fullName,
        ':dob' => $dob,
        ':email' => $email !== '' ? $email : null,
        ':contact_number' => $contact,
        ':address' => $address !== '' ? $address : null,
        ':player_category' => $playerCategory,
        ':training_preference' => $trainingPreference,
        ':affiliated_school' => $affiliatedSchool !== '' ? $affiliatedSchool : null,
        ':guardian_name' => $guardianName !== '' ? $guardianName : null,
        ':guardian_contact' => $guardianContact !== '' ? $guardianContact : null,
        ':payment_notes' => $paymentNotes !== '' ? $paymentNotes : null,
        ':requested_rank' => $requestedRank,
        ':username' => $username,
        ':user_id' => $applicantUserId,
        ':prev_organization' => $prevOrganization !== '' ? $prevOrganization : null,
        ':prev_instructor' => $prevInstructor !== '' ? $prevInstructor : null,
        ':rank_awarded_year' => $rankAwardedYear !== '' ? $rankAwardedYear : null,
        ':rank_evidence_path' => $rankEvidencePath,
        ':contact_ok' => $contactOk ? 1 : 0,
    ]);
    $requestId = (int)$db->lastInsertId();

    AuditLog::recordForUser(
        $db,
        $applicantUserId,
        $fullName,
        'Applicant',
        'create',
        'registration',
        $requestId,
        'Submitted registration request (' . $username . ')'
    );

    $mailSent = true;
    if ($requireVerification && $verificationToken !== null) {
        require_once __DIR__ . '/../classes/Mailer.php';
        $mailer = new Mailer($db);
        $mailSent = $mailer->sendVerification($email, $fullName, $verificationToken);
    }
} catch (PDOException $e) {
    error_log('process_register_request DB error: ' . $e->getMessage());
    redirectWithMessage('error', 'Could not submit your application. Please try again.');
}

if ($requireVerification) {
    if ($mailSent) {
        redirectWithMessage('success', 'Application submitted. We emailed a verification link to ' . $email . '. Verify your email before signing in.');
    }
    redirectWithMessage('success', 'Application submitted, but the verification email could not be sent. Use "Resend verification" on the login page to try again.');
}

redirectWithMessage('success', 'Application submitted! You can now log in with your username and password to track it.');
