<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Settings.php';
require_once __DIR__ . '/../classes/AuditLog.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function settingsRedirect(string $type, string $message): void
{
    $_SESSION[$type === 'success' ? 'success' : 'error'] = $message;
    header('Location: ../public/settings.php');
    exit;
}

if (!hasRole('SuperAdmin')) {
    http_response_code(403);
    exit('Access Denied: Only the SuperAdmin may change system settings.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    settingsRedirect('error', 'Method not allowed.');
}

arnisRequireCsrf();

try {
    $db = (new Database())->connect();
    $settings = new Settings($db);

    $current = $settings->all();
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $organizationName = trim((string)($_POST['organization_name'] ?? ''));
    $contactEmail = trim((string)($_POST['contact_email'] ?? ''));
    $fromEmail = trim((string)($_POST['from_email'] ?? ''));
    $fromName = trim((string)($_POST['from_name'] ?? ''));
    $privacyContact = trim((string)($_POST['privacy_contact'] ?? ''));
    $faceThresholdRaw = trim((string)($_POST['face_match_threshold'] ?? '0.60'));
    $geofenceRaw = trim((string)($_POST['default_geofence_m'] ?? '100'));

    if ($organizationName === '' || mb_strlen($organizationName) > 100) {
        settingsRedirect('error', 'Organization name is required and must be 100 characters or fewer.');
    }
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        settingsRedirect('error', 'Contact email must be a valid email address.');
    }
    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        settingsRedirect('error', 'From email must be a valid email address.');
    }
    if (!is_numeric($faceThresholdRaw)) {
        settingsRedirect('error', 'Face match threshold must be a number between 0.10 and 1.00.');
    }
    $faceThreshold = round((float)$faceThresholdRaw, 2);
    if ($faceThreshold < 0.10 || $faceThreshold > 1.00) {
        settingsRedirect('error', 'Face match threshold must be between 0.10 and 1.00.');
    }
    if (!ctype_digit($geofenceRaw)) {
        settingsRedirect('error', 'Default geofence must be a whole number of metres.');
    }
    $geofence = (int)$geofenceRaw;
    if ($geofence < 5 || $geofence > 5000) {
        settingsRedirect('error', 'Default geofence must be between 5 and 5,000 metres.');
    }

    $values = [
        'organization_name' => mb_substr($organizationName, 0, 100),
        'contact_email' => mb_substr($contactEmail, 0, 100),
        'from_email' => mb_substr($fromEmail, 0, 100),
        'from_name' => mb_substr($fromName !== '' ? $fromName : $organizationName, 0, 100),
        'privacy_contact' => mb_substr($privacyContact, 0, 150),
        'face_match_threshold' => number_format($faceThreshold, 2, '.', ''),
        'default_geofence_m' => (string)$geofence,
        'allow_registration' => isset($_POST['allow_registration']) ? '1' : '0',
        'require_email_verification' => isset($_POST['require_email_verification']) ? '1' : '0',
        'email_notifications' => isset($_POST['email_notifications']) ? '1' : '0',
    ];

    $changed = [];
    $failed = [];
    foreach ($values as $key => $value) {
        if ((string)($current[$key] ?? '') === $value) {
            continue;
        }
        if ($settings->set($key, $value, $userId)) {
            $changed[$key] = $value;
        } else {
            $failed[] = $key;
        }
    }

    if ($changed !== []) {
        AuditLog::record(
            $db,
            'settings_update',
            'settings',
            null,
            'Updated system settings: ' . implode(', ', array_keys($changed)),
            ['changed' => array_keys($changed)]
        );
    }

    if ($failed !== []) {
        settingsRedirect('error', 'Some settings could not be saved: ' . implode(', ', $failed) . '. Run migration 003 if the settings table is missing.');
    }

    settingsRedirect('success', $changed === [] ? 'No settings changed.' : 'System settings saved.');
} catch (Throwable $e) {
    error_log('save_settings.php error: ' . $e->getMessage());
    settingsRedirect('error', 'Settings could not be saved. Please try again.');
}
