<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Funds.php';
require_once __DIR__ . '/../classes/AuditLog.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$redirect = '../public/funds.php';

/* Preserve the list filters across the edit round-trip. */
$rawMonth = (isset($_POST['redirect_month']) && is_string($_POST['redirect_month'])) ? $_POST['redirect_month'] : '';
$rawType = (isset($_POST['redirect_type']) && is_string($_POST['redirect_type'])) ? $_POST['redirect_type'] : '';
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rawMonth)) {
    $redirect .= '?month=' . rawurlencode($rawMonth);
}
if (in_array($rawType, ['Income', 'Expense'], true)) {
    $redirect .= (str_contains($redirect, '?') ? '&' : '?') . 'type=' . rawurlencode($rawType);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

arnisRequireCsrf(false);

/*
 * CRITICAL: without this, any authenticated Member or Applicant could POST a
 * fund_id and rewrite the club's financial records.
 */
requirePermission('funds', 'write');

// Mandatory: a missing token must fail, not skip verification.
if (!arnisVerifyCsrfToken($_POST['csrf_token'] ?? null)) {
    $_SESSION['error'] = 'Invalid security token. Please reload the page and try again.';
    header('Location: ' . $redirect);
    exit;
}

$fundId = isset($_POST['fund_id']) && is_numeric($_POST['fund_id']) ? (int)$_POST['fund_id'] : 0;

$transactionType = trim((string)($_POST['edit_tr_type'] ?? ''));
$sourceOrPurpose = trim((string)($_POST['purpose'] ?? ''));
$amountRaw = $_POST['amount'] ?? null;
$transactionDate = trim((string)($_POST['transaction_date'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));
$relatedEventId = (isset($_POST['event_id']) && $_POST['event_id'] !== '' && is_numeric($_POST['event_id']))
    ? (int)$_POST['event_id']
    : null;

if ($fundId <= 0) {
    $_SESSION['error'] = 'Invalid transaction.';
    header('Location: ' . $redirect);
    exit;
}

// ENUM whitelist: previously any string was written straight into the column.
if (!in_array($transactionType, ['Income', 'Expense'], true)) {
    $_SESSION['error'] = 'Invalid transaction type.';
    header('Location: ' . $redirect);
    exit;
}

if ($sourceOrPurpose === '') {
    $_SESSION['error'] = 'Purpose / Source is required.';
    header('Location: ' . $redirect);
    exit;
}
if (mb_strlen($sourceOrPurpose) > 255) {
    $_SESSION['error'] = 'Purpose / Source must not exceed 255 characters.';
    header('Location: ' . $redirect);
    exit;
}

// floatval('1e400') is INF, which passes a "> 0" check and then hits MySQL
// out-of-range. Bound it explicitly.
if (!is_numeric($amountRaw)) {
    $_SESSION['error'] = 'Amount must be a valid number.';
    header('Location: ' . $redirect);
    exit;
}
$amount = (float)$amountRaw;
if (!is_finite($amount) || $amount <= 0) {
    $_SESSION['error'] = 'Amount must be a positive number.';
    header('Location: ' . $redirect);
    exit;
}
if ($amount > 100000000) {
    $_SESSION['error'] = 'Amount is unrealistically large.';
    header('Location: ' . $redirect);
    exit;
}

$dateObj = DateTime::createFromFormat('Y-m-d', $transactionDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $transactionDate) {
    $_SESSION['error'] = 'Invalid date format (YYYY-MM-DD).';
    header('Location: ' . $redirect);
    exit;
}
if ($transactionDate > date('Y-m-d')) {
    $_SESSION['error'] = 'Transaction date cannot be in the future.';
    header('Location: ' . $redirect);
    exit;
}

if (mb_strlen($notes) > 255) {
    $_SESSION['error'] = 'Notes must not exceed 255 characters.';
    header('Location: ' . $redirect);
    exit;
}

try {
    $db = (new Database())->connect();
    $fundsModel = new Funds($db);

    $existing = $fundsModel->getById($fundId);
    if ($existing === null) {
        $_SESSION['error'] = 'Transaction not found, or it has already been voided.';
        header('Location: ' . $redirect);
        exit;
    }

    if ($relatedEventId !== null) {
        $check = $db->prepare('SELECT event_id FROM events WHERE event_id = :id AND is_voided = 0 LIMIT 1');
        $check->execute([':id' => $relatedEventId]);
        if (!$check->fetch()) {
            $_SESSION['error'] = 'Linked event not found.';
            header('Location: ' . $redirect);
            exit;
        }
    }

    $data = [
        'transaction_type'  => $transactionType,
        'source_or_purpose' => $sourceOrPurpose,
        'amount'            => $amount,
        'transaction_date'  => $transactionDate,
        'notes'             => $notes !== '' ? $notes : null,
        'related_event_id'  => $relatedEventId,
        'updated_by'        => (int)$_SESSION['user_id'],
    ];

    /*
     * Funds::update() returns rowCount() > 0, and MySQL reports 0 changed rows
     * when the submitted values match the stored ones. Loading the row first
     * above separates "no such record" from "nothing changed", so re-saving an
     * unmodified transaction is not reported as a failure.
     */
    $fundsModel->update($fundId, $data);

    AuditLog::record(
        $db,
        'update',
        'funds',
        $fundId,
        'Updated ' . strtolower($transactionType) . ' transaction "' . $sourceOrPurpose
            . '" to ₱' . number_format($amount, 2),
        [
            'transaction_type' => $transactionType,
            'amount' => $amount,
            'transaction_date' => $transactionDate,
            'previous_amount' => (float)($existing['amount'] ?? 0),
        ]
    );

    $_SESSION['success'] = 'Transaction updated successfully!';
} catch (PDOException $e) {
    error_log('process_edit_transaction DB error: ' . $e->getMessage());
    $_SESSION['error'] = 'Failed to update transaction. Please try again.';
} catch (Throwable $e) {
    error_log('process_edit_transaction error: ' . $e->getMessage());
    $_SESSION['error'] = 'Failed to update transaction. Please try again.';
}

header('Location: ' . $redirect);
exit;