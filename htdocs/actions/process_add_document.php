<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/AuditLog.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    requirePermission('event_documents', 'write');

    arnisRequireCsrf(true);

    if (!isset($_POST['event_id']) || !is_numeric($_POST['event_id'])) {
        throw new RuntimeException('Invalid event ID.');
    }
    $eventId = (int)$_POST['event_id'];
    if ($eventId <= 0) {
        throw new RuntimeException('Invalid event ID.');
    }

    $allowedTypes = ['Image', 'Video', 'Receipt', 'Note'];
    $fileType = (string)($_POST['file_type'] ?? '');
    if (!in_array($fileType, $allowedTypes, true)) {
        throw new RuntimeException('Invalid file type.');
    }

    $cloudinaryUrl = trim((string)($_POST['cloudinary_url'] ?? $_POST['file_path'] ?? ''));
    if ($cloudinaryUrl === '') {
        throw new RuntimeException('File URL is required.');
    }
    if (!filter_var($cloudinaryUrl, FILTER_VALIDATE_URL) || stripos($cloudinaryUrl, 'cloudinary.com') === false) {
        throw new RuntimeException('Invalid Cloudinary URL.');
    }
    if (mb_strlen($cloudinaryUrl) > 255) {
        throw new RuntimeException('File URL is too long (max 255 characters). Use a shorter Cloudinary public ID.');
    }

    $db = (new Database())->connect();

    $stmt = $db->prepare('SELECT event_id, created_by, handler_id FROM events WHERE event_id = :id AND is_voided = 0 LIMIT 1');
    $stmt->execute([':id' => $eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        throw new RuntimeException('Event not found.');
    }

    // Check ownership for scoped permissions (Coach/Trainer 'own_events').
    // The assigned event handler owns the event too, not only its creator.
    $writeScope = permissionValue('event_documents', 'write');
    if ($writeScope !== true) {
        if (!canPerformAction('event_documents', 'write', [
            'event_created_by' => (int)($event['created_by'] ?? 0),
            'handler_id' => (int)($event['handler_id'] ?? 0),
        ])) {
            throw new RuntimeException('You do not have permission to upload documents to this event.');
        }
    }

    $stmt = $db->prepare(
        "INSERT INTO event_documents (event_id, file_type, file_path, uploaded_at, uploaded_by, is_voided)
         VALUES (:event_id, :file_type, :file_path, NOW(), :uploaded_by, 0)"
    );
    $stmt->execute([
        ':event_id' => $eventId,
        ':file_type' => $fileType,
        ':file_path' => $cloudinaryUrl,
        ':uploaded_by' => (int)$_SESSION['user_id'],
    ]);

    AuditLog::record(
        $db,
        'create',
        'event_documents',
        (int)$db->lastInsertId() ?: null,
        'Uploaded ' . $fileType . ' document to event #' . $eventId,
        ['event_id' => $eventId, 'file_type' => $fileType]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Document uploaded successfully.',
        'file_url' => $cloudinaryUrl,
    ]);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('process_add_document DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error.']);
} catch (Throwable $e) {
    error_log('process_add_document error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Failed to save document.']);
}