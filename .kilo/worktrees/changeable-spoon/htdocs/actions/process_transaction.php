<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Funds.php';
require_once __DIR__ . '/../classes/AuditLog.php';

try {
    if (!isset($_SESSION['user_id'])) {
        throw new RuntimeException('Not authenticated.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    requirePermission('funds', 'write');

    arnisRequireCsrf(true);

    $transactionType = (string)($_POST['tr_type'] ?? 'Income');
    $sourceOrPurpose = trim((string)($_POST['source_or_purpose'] ?? ''));
    $amountRaw = $_POST['amount'] ?? 0;
    $transactionDate = trim((string)($_POST['transaction_date'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $relatedEventId = (!empty($_POST['related_event_id']) && is_numeric($_POST['related_event_id']))
        ? (int)$_POST['related_event_id']
        : null;

    if (!in_array($transactionType, ['Income', 'Expense'], true)) {
        throw new RuntimeException('Invalid transaction type.');
    }

    if ($sourceOrPurpose === '') {
        throw new RuntimeException('Source/Purpose is required.');
    }
    if (mb_strlen($sourceOrPurpose) > 255) {
        throw new RuntimeException('Source/Purpose must not exceed 255 characters.');
    }

    if (!is_numeric($amountRaw) || (float)$amountRaw <= 0) {
        throw new RuntimeException('Amount must be a positive number.');
    }
    if ((float)$amountRaw > 100000000) {
        throw new RuntimeException('Amount is unrealistically large.');
    }

    $dateObj = DateTime::createFromFormat('Y-m-d', $transactionDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $transactionDate) {
        throw new RuntimeException('Invalid date format (YYYY-MM-DD).');
    }
    if ($transactionDate > date('Y-m-d')) {
        throw new RuntimeException('Transaction date cannot be in the future.');
    }

    if ($notes !== '' && mb_strlen($notes) > 255) {
        throw new RuntimeException('Notes must not exceed 255 characters.');
    }

    $db = (new Database())->connect();

    if ($relatedEventId !== null) {
        $check = $db->prepare('SELECT event_id FROM events WHERE event_id = :id AND is_voided = 0 LIMIT 1');
        $check->execute([':id' => $relatedEventId]);
        if (!$check->fetch()) {
            throw new RuntimeException('Linked event not found.');
        }
    }

    $fundsModel = new Funds($db);

    $data = [
        'transaction_type' => $transactionType,
        'source_or_purpose' => $sourceOrPurpose,
        'amount' => (float)$amountRaw,
        'transaction_date' => $transactionDate,
        'notes' => $notes !== '' ? $notes : null,
        'related_event_id' => $relatedEventId,
        'recorded_by' => (int)$_SESSION['user_id'],
    ];

    if (!$fundsModel->create($data)) {
        throw new RuntimeException('Failed to save transaction to database.');
    }

    AuditLog::record(
        $db,
        'create',
        'funds',
        (int)$db->lastInsertId() ?: null,
        'Recorded ' . strtolower($transactionType) . ' of ₱' . number_format((float)$amountRaw, 2)
            . ' for ' . $sourceOrPurpose,
        [
            'transaction_type' => $transactionType,
            'amount' => (float)$amountRaw,
            'transaction_date' => $transactionDate,
            'related_event_id' => $relatedEventId,
        ]
    );

    echo json_encode(['success' => true, 'message' => 'Transaction recorded successfully!']);
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('process_transaction DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    error_log('process_transaction error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}