<?php
/**
 * api/delete.php
 * Deletes a document's uploaded PDF and its chunk file.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('CHUNKS_DIR', __DIR__ . '/../chunks/');

$body  = json_decode(file_get_contents('php://input'), true);
$docId = preg_replace('/[^a-zA-Z0-9_.]/', '', $body['doc_id'] ?? '');

if (!$docId) {
    echo json_encode(['success' => false, 'error' => 'Geen doc_id opgegeven.']);
    exit;
}

$deleted = 0;

// Delete chunk file
$chunkFile = CHUNKS_DIR . $docId . '.json';
if (file_exists($chunkFile)) { unlink($chunkFile); $deleted++; }

// Delete PDF (glob since filename includes original name)
foreach (glob(UPLOAD_DIR . $docId . '_*') as $f) { unlink($f); $deleted++; }

echo json_encode(['success' => true, 'deleted' => $deleted]);
