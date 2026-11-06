<?php
/**
 * Legacy report endpoint.
 *
 * Reports are now rendered by reports.php (server-rendered, RBAC-gated, with
 * CSV and print support). Keep this file as a redirect so old bookmarks and
 * cached forms do not hit a dead end.
 */
require_once __DIR__ . '/../includes/authorize.php';

requirePermission('reports', 'read');

arnisRequireCsrf(false);

$allowed = ['members', 'attendance', 'trainings', 'events', 'funds'];
$report = 'members';
$candidate = $_POST['report_type'] ?? $_GET['report'] ?? $_GET['report_type'] ?? '';
if (is_string($candidate) && in_array($candidate, $allowed, true)) {
    $report = $candidate;
}

$query = ['report' => $report];
foreach (['start_date', 'end_date'] as $key) {
    $value = $_POST[$key] ?? $_GET[$key] ?? '';
    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
        $query[$key] = trim($value);
    }
}

header('Location: reports.php?' . http_build_query($query));
exit;
