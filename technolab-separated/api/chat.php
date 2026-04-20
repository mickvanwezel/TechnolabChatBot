<?php
/**
 * api/chat.php (Gemini version)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── CONFIG ──
define('CHUNKS_DIR', __DIR__ . '/../chunks/');
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: ''); // set in env
define('GEMINI_MODEL', 'gemini-1.5-flash');
define('TOP_K', 6);
define('MAX_CONTEXT_CHARS', 8000);

// ── READ REQUEST ──
$body = json_decode(file_get_contents('php://input'), true);
$question = trim($body['question'] ?? '');
$docIds   = $body['doc_ids'] ?? [];

if (!$question || empty($docIds)) {
    echo json_encode(['success' => false, 'error' => 'Vraag of doc_ids ontbreekt.']);
    exit;
}

if (strlen($question) > 1000) {
    echo json_encode(['success' => false, 'error' => 'Vraag te lang.']);
    exit;
}

// ── LOAD CHUNKS ──
$allChunks = [];
foreach ($docIds as $docId) {
    $docId = preg_replace('/[^a-zA-Z0-9_.]/', '', $docId);
    $path  = CHUNKS_DIR . $docId . '.json';
    if (!file_exists($path)) continue;

    $meta = json_decode(file_get_contents($path), true);
    if (!empty($meta['chunks'])) {
        $allChunks = array_merge($allChunks, $meta['chunks']);
    }
}

if (empty($allChunks)) {
    echo json_encode(['success' => false, 'error' => 'Geen documenten gevonden. Upload eerst een PDF.']);
    exit;
}

// ── RETRIEVE CHUNKS ──
$topChunks = retrieveChunks($question, $allChunks, TOP_K);

if (empty($topChunks)) {
    echo json_encode([
        'answer' => 'Ik kon geen relevante informatie vinden in de documenten.',
        'sources' => []
    ]);
    exit;
}

// ── BUILD CONTEXT ──
$context = '';
$sourceFiles = [];
$charCount = 0;

foreach ($topChunks as $chunk) {
    $text = $chunk['text'];

    if ($charCount + strlen($text) > MAX_CONTEXT_CHARS) break;

    $context .= "\n---\n" . $text;
    $charCount += strlen($text);

    $fn = $chunk['filename'] ?? '';
    if ($fn && !in_array($fn, $sourceFiles)) {
        $sourceFiles[] = $fn;
    }
}

// ── PROMPT ──
$prompt = <<<PROMPT
Je bent de Technolab Vraagbaak.

Beantwoord ALLEEN op basis van deze context:
{$context}

Vraag:
{$question}

Antwoord:
PROMPT;

// ── CALL GEMINI ──
$response = callGeminiAPI($prompt);

if ($response['success']) {
    $displaySources = array_map(function($fn) {
        return preg_replace('/^doc_[a-z0-9._]+_/', '', $fn);
    }, $sourceFiles);

    echo json_encode([
        'answer'  => $response['text'],
        'sources' => $displaySources,
    ]);
} else {
    echo json_encode(['success' => false, 'error' => $response['error']]);
}

// ── BM25 RETRIEVAL ──
function retrieveChunks(string $query, array $chunks, int $topK): array {
    $queryTokens = tokenize($query);
    if (empty($queryTokens)) return array_slice($chunks, 0, $topK);

    $docFreq = [];
    $N = count($chunks);

    foreach ($chunks as $chunk) {
        $words = array_unique(tokenize($chunk['text']));
        foreach ($words as $w) {
            $docFreq[$w] = ($docFreq[$w] ?? 0) + 1;
        }
    }

    $k1 = 1.5; $b = 0.75;
    $avgLen = array_sum(array_map(fn($c) => str_word_count($c['text']), $chunks)) / $N;

    $scored = [];

    foreach ($chunks as $i => $chunk) {
        $chunkTokens = tokenize($chunk['text']);
        $tf = array_count_values($chunkTokens);
        $docLen = count($chunkTokens);
        $score = 0.0;

        foreach ($queryTokens as $term) {
            $f = $tf[$term] ?? 0;
            $df = $docFreq[$term] ?? 0;
            if ($f === 0 || $df === 0) continue;

            $idf = log(($N - $df + 0.5) / ($df + 0.5) + 1);
            $tfn = ($f * ($k1 + 1)) / ($f + $k1 * (1 - $b + $b * $docLen / $avgLen));
            $score += $idf * $tfn;
        }

        if ($score > 0 && stripos($chunk['text'], $query) !== false) {
            $score *= 1.5;
        }

        $scored[$i] = $score;
    }

    arsort($scored);

    $result = [];
    foreach (array_keys($scored) as $idx) {
        if (count($result) >= $topK) break;
        if ($scored[$idx] > 0) $result[] = $chunks[$idx];
    }

    return $result ?: array_slice($chunks, 0, min($topK, count($chunks)));
}

function tokenize(string $text): array {
    $text  = mb_strtolower($text);
    $text  = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
    $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

    $stop  = ['de','het','een','en','is','in','van','voor','op','met','te','dat',
              'zijn','er','aan','ook','als','maar','om','uit','bij','dan','nog',
              'al','dit','je','we','ze','ik','niet',
              'the','a','an','and','is','in','of','for','on','with','to','that'];

    return array_values(array_diff($words, $stop));
}

// ── GEMINI API ──
function callGeminiAPI(string $prompt): array {
    $apiKey = GEMINI_API_KEY ?: "AQ.Ab8RN6JEchGOT7OhbyPpqbRqRmo0rrJfLwxGgBd84F9WTEHIkw";

    if (!$apiKey) {
        return ['success' => false, 'error' => 'API key ontbreekt (GEMINI_API_KEY).'];
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent?key=" . $apiKey;

    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'error' => $curlErr];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        return ['success' => false, 'error' => $data['error']['message'] ?? 'API fout'];
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    if (!$text) {
        return ['success' => false, 'error' => 'Leeg antwoord'];
    }

    return ['success' => true, 'text' => $text];
}