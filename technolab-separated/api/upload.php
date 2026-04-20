<?php
/**
 * api/upload.php
 * Handles PDF uploads, extracts text using Python, and chunks the content for RAG.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── CONFIG ──
define('UPLOAD_DIR',  __DIR__ . '/../uploads/');
define('CHUNKS_DIR',  __DIR__ . '/../chunks/');
define('MAX_SIZE',    20 * 1024 * 1024); // 20 MB
define('CHUNK_SIZE',  400);              // words per chunk
define('CHUNK_OVERLAP', 60);             // words overlap

// Create dirs if needed
foreach ([UPLOAD_DIR, CHUNKS_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// ── VALIDATE REQUEST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['pdf'])) {
    echo json_encode(['success' => false, 'error' => 'Geen bestand ontvangen.']);
    exit;
}

$file = $_FILES['pdf'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Upload mislukt (code ' . $file['error'] . ').']);
    exit;
}

if ($file['size'] > MAX_SIZE) {
    echo json_encode(['success' => false, 'error' => 'Bestand te groot (max 20 MB).']);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'pdf') {
    echo json_encode(['success' => false, 'error' => 'Alleen PDF-bestanden zijn toegestaan.']);
    exit;
}

// ── SAVE PDF ──
$docId  = uniqid('doc_', true);
$safeFilename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', basename($file['name']));
$pdfPath = UPLOAD_DIR . $docId . '_' . $safeFilename;

if (!move_uploaded_file($file['tmp_name'], $pdfPath)) {
    echo json_encode(['success' => false, 'error' => 'Bestand opslaan mislukt.']);
    exit;
}

// ── EXTRACT TEXT via Python ──
$pythonScript = __DIR__ . '/extract_pdf.py';
$escapedPath  = escapeshellarg($pdfPath);
$output = shell_exec("python3 {$pythonScript} {$escapedPath} 2>&1");

if ($output === null || trim($output) === '') {
    unlink($pdfPath);
    echo json_encode(['success' => false, 'error' => 'PDF-tekst extractie mislukt.']);
    exit;
}

// Check if python script returned an error marker
if (strpos($output, 'EXTRACT_ERROR:') === 0) {
    unlink($pdfPath);
    $errMsg = substr($output, strlen('EXTRACT_ERROR:'));
    echo json_encode(['success' => false, 'error' => 'PDF lezen mislukt: ' . trim($errMsg)]);
    exit;
}

$text = $output;

// ── CHUNK TEXT ──
$chunks = chunkText($text, $docId, $safeFilename, CHUNK_SIZE, CHUNK_OVERLAP);

if (empty($chunks)) {
    unlink($pdfPath);
    echo json_encode(['success' => false, 'error' => 'Geen tekst gevonden in dit PDF.']);
    exit;
}

// ── SAVE CHUNKS as JSON ──
$chunkFile = CHUNKS_DIR . $docId . '.json';
$meta = [
    'doc_id'   => $docId,
    'filename' => $safeFilename,
    'original_name' => $file['name'],
    'created'  => time(),
    'chunk_count' => count($chunks),
    'chunks'   => $chunks,
];

file_put_contents($chunkFile, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode([
    'success' => true,
    'doc_id'  => $docId,
    'filename' => $file['name'],
    'chunks'  => count($chunks),
]);

// ── HELPERS ──

/**
 * Split text into overlapping word-based chunks.
 */
function chunkText(string $text, string $docId, string $filename, int $size, int $overlap): array {
    // Normalize whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    $words = explode(' ', trim($text));
    $total = count($words);
    $chunks = [];
    $i = 0;
    $idx = 0;

    while ($i < $total) {
        $slice = array_slice($words, $i, $size);
        $chunkText = implode(' ', $slice);

        if (strlen(trim($chunkText)) < 30) {
            $i += $size;
            continue;
        }

        $chunks[] = [
            'id'       => $docId . '_chunk_' . $idx,
            'doc_id'   => $docId,
            'filename' => $filename,
            'index'    => $idx,
            'text'     => $chunkText,
            'word_start' => $i,
            'word_end'   => $i + count($slice),
        ];

        $idx++;
        $i += ($size - $overlap);
        if ($i >= $total) break;
    }

    return $chunks;
}
