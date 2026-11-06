<?php
declare(strict_types=1);

/*
 * EVENT DOCUMENT DOWNLOAD — public proxy for Cloudinary files.
 *
 * Fix revision:
 *  - The old version appended `?fl_attachment` to the Cloudinary URL and
 *    fetched with `follow_location => 0`. Cloudinary answers that with a
 *    redirect / non-2xx status, so EVERY receipt click died with
 *    {"success":false,"error":"Failed to retrieve the file from storage."}.
 *  - This version fetches the RAW stored URL, FOLLOWS redirects (max 5),
 *    and on any fetch failure falls back to a plain browser redirect to the
 *    Cloudinary URL instead of a JSON error — so a click always opens the
 *    file as long as the stored URL itself is valid.
 *  - RBAC is unchanged: event_documents.read scope ('own_events' / 'own')
 *    is enforced before any byte is sent.
 *  - JSON errors are now only returned for: bad id, no permission,
 *    missing/voided document, or a stored URL that is not https Cloudinary.
 */

require_once __DIR__ . '/../includes/authorize.php';
require_once __DIR__ . '/../config/Database.php';

/** Hard ceiling on a single proxied download, in bytes. */
const ARNIS_DOC_MAX_BYTES = 26214400; // 25 MB

/** Only these Cloudinary hosts may be fetched server-side (SSRF guard). */
const ARNIS_DOC_ALLOWED_HOST_SUFFIX = 'cloudinary.com';

function arnisDocJsonError(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/*
 * The stored value must be an https Cloudinary URL, max 255 chars
 * (event_documents.file_path is varchar(255)). Returns the URL untouched —
 * no query flags appended. Cloudinary honours `fl_attachment` only as a
 * delivery transformation in the path, never as a query string.
 */
function arnisDocValidateUrl(string $filePath): ?string
{
    $filePath = trim($filePath);
    if ($filePath === '' || mb_strlen($filePath) > 255) {
        return null;
    }

    $parts = parse_url($filePath);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    if (strtolower($parts['scheme']) !== 'https') {
        return null;
    }

    $host = strtolower($parts['host']);
    $suffix = ARNIS_DOC_ALLOWED_HOST_SUFFIX;
    if ($host !== $suffix && !str_ends_with($host, '.' . $suffix)) {
        return null;
    }

    return $filePath;
}

/** Safe attachment filename derived from the document type, not the URL. */
function arnisDocBuildFilename(int $documentId, string $fileType): string
{
    $extensionByType = [
        'Image' => 'jpg',
        'Video' => 'mp4',
        'Receipt' => 'pdf',
        'Note' => 'txt',
    ];
    $extension = $extensionByType[$fileType] ?? 'bin';
    return 'arnis_document_' . $documentId . '.' . $extension;
}

/*
 * Fetch the remote file. Returns ['body', 'content_type'] on success,
 * null on any failure (network blocked, non-2xx, oversize). cURL is
 * preferred (InfinityFree often disables allow_url_fopen); both paths
 * FOLLOW redirects because Cloudinary delivery URLs commonly 302.
 */
function arnisDocFetch(string $url): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'ArnisConnect/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $size = function_exists('curl_getinfo') ? (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD) : 0;
        curl_close($ch);

        if (!is_string($body) || $body === '' || $httpCode < 200 || $httpCode >= 300) {
            error_log('download_document: curl fetch failed HTTP ' . $httpCode . ' for ' . $url);
            return null;
        }
        if (($size > 0 && $size > ARNIS_DOC_MAX_BYTES) || strlen($body) > ARNIS_DOC_MAX_BYTES) {
            error_log('download_document: upstream file exceeds 25 MB for ' . $url);
            return null;
        }
        return ['body' => $body, 'content_type' => $contentType];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true,
            'header' => "Accept: */*\r\nUser-Agent: ArnisConnect/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        error_log('download_document: fopen fetch failed for ' . $url);
        return null;
    }

    $status = 200;
    $contentType = '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (stripos($headerLine, 'HTTP/') === 0) {
                $status = (int)trim(substr($headerLine, 5, 3));
            } elseif (stripos($headerLine, 'Content-Type:') === 0) {
                $contentType = trim(substr($headerLine, 13));
            }
        }
    }
    if ($status < 200 || $status >= 300) {
        error_log('download_document: upstream status ' . $status . ' for ' . $url);
        return null;
    }
    if (strlen($body) > ARNIS_DOC_MAX_BYTES) {
        error_log('download_document: upstream file exceeds 25 MB for ' . $url);
        return null;
    }
    return ['body' => $body, 'content_type' => $contentType];
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    arnisDocJsonError('Invalid document ID');
}

$documentId = (int)$_GET['id'];
if ($documentId <= 0) {
    arnisDocJsonError('Invalid document ID');
}

try {
    $readPerm = permissionValue('event_documents', 'read');
    if ($readPerm === false) {
        arnisDocJsonError('You do not have permission to download event documents.', 403);
    }

    $db = (new Database())->connect();

    $stmt = $db->prepare(
        "SELECT d.document_id, d.event_id, d.file_path, d.file_type,
                e.event_name, e.created_by AS event_created_by
         FROM event_documents d
         JOIN events e ON e.event_id = d.event_id
         WHERE d.document_id = :id
           AND d.is_voided = 0
           AND e.is_voided = 0
         LIMIT 1"
    );
    $stmt->execute([':id' => $documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$document) {
        arnisDocJsonError('Document not found', 404);
    }

    /*
     * Scoped read permissions ('own_events' for Coach, 'own' for Member)
     * are enforced here rather than in the UI, otherwise the id is
     * guessable and any session could iterate document_id values.
     */
    if ($readPerm === 'own_events' || $readPerm === 'own') {
        $isParticipant = false;

        if ($readPerm === 'own' && (int)($_SESSION['member_id'] ?? 0) > 0) {
            $check = $db->prepare(
                "SELECT participant_id
                 FROM event_participants
                 WHERE event_id = :event_id
                   AND member_id = :member_id
                   AND is_voided = 0
                 LIMIT 1"
            );
            $check->execute([
                ':event_id' => (int)$document['event_id'],
                ':member_id' => (int)$_SESSION['member_id'],
            ]);
            $isParticipant = (bool)$check->fetch();
        }

        $isEventOwner = (int)($document['event_created_by'] ?? 0) > 0
            && (int)$document['event_created_by'] === (int)($_SESSION['user_id'] ?? 0);

        if (!$isParticipant && !$isEventOwner) {
            arnisDocJsonError('You do not have permission to download this document.', 403);
        }
    }

    $fileUrl = arnisDocValidateUrl((string)$document['file_path']);
    if ($fileUrl === null) {
        arnisDocJsonError('Stored file location is not a valid download source.', 422);
    }

    $fetched = arnisDocFetch($fileUrl);

    if ($fetched === null) {
        /*
         * Server-side fetch failed (common on InfinityFree: outbound HTTP
         * blocked or Cloudinary edge refused the proxy). The stored URL
         * itself is valid and permission-checked, so send the browser
         * straight to it instead of showing a JSON error.
         */
        header('Location: ' . $fileUrl);
        exit;
    }

    $fileType = (string)($document['file_type'] ?? '');
    $upstreamType = strtolower(trim(explode(';', $fetched['content_type'])[0]));

    if ($fileType === 'Image' && str_starts_with($upstreamType, 'image/')) {
        header('Content-Type: ' . $fetched['content_type']);
        header('Content-Disposition: inline; filename="'
            . arnisDocBuildFilename($documentId, $fileType) . '"');
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'
            . arnisDocBuildFilename($documentId, $fileType) . '"');
    }
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($fetched['body']));
    header('Expires: 0');
    header('Cache-Control: private, no-store, must-revalidate, max-age=0');
    header('Pragma: private');

    echo $fetched['body'];
    exit;
} catch (PDOException $e) {
    error_log('download_document DB error: ' . $e->getMessage());
    arnisDocJsonError('Database error.', 500);
} catch (Throwable $e) {
    error_log('download_document error: ' . $e->getMessage());
    arnisDocJsonError('Failed to download document.', 500);
}