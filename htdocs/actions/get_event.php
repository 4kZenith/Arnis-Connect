<?php
require_once "../includes/auth.php";
require_once "../config/Database.php";
require_once "../classes/Events.php";

header('Content-Type: application/json');

try {
    // Validate event ID
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Invalid event ID");
    }

    $eventId = (int) $_GET['id'];

    // Initialize database and fetch event
    $database = new Database();
    $db = $database->connect();
    $eventsModel = new Events($db);
    
    $event = $eventsModel->getById($eventId);
    
    if (!$event) {
        throw new Exception("Event not found");
    }

    echo json_encode([
        'success' => true,
        'data' => $event
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
