<?php
/**
 * ExoClick Bulk Creative Uploader — Front Controller
 * Routes: / (HTML shell), /api/auth, /api/campaigns, /api/upload, /api/create-variations
 */

session_start();

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/ExoClickClient.php';
require_once __DIR__ . '/../src/FileValidator.php';
// GeoGroupManager removed — geo data now comes from ExoClick API targeting

// Ensure upload dir exists
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0700, true);
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Optional: require session-based login if AUTH_REQUIRED is enabled.
// By default, the ExoClick API token (Step 1) is the sole authentication gate.
if (defined('AUTH_REQUIRED') && AUTH_REQUIRED) {
    if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (strpos($uri, '/api/') !== false) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        http_response_code(403);
        echo 'Access denied. Set AUTH_REQUIRED to false or implement your own login gate.';
        exit;
    }
}

// Auto-authenticate ExoClick API from environment
if (empty($_SESSION['authenticated'])) {
    $envToken = getenv('EXOCLICK_API_TOKEN');
    if ($envToken) {
        $autoClient = new ExoClickClient();
        $result = $autoClient->login($envToken);
        if ($result['success']) {
            $_SESSION['api_token'] = $envToken;
            $_SESSION['authenticated'] = true;
        }
    }
}

// Route handling — strip /uploader prefix if present
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = preg_replace('#^/uploader#', '', $requestUri);
if ($requestUri === '') $requestUri = '/';
$method = $_SERVER['REQUEST_METHOD'];

// API routes
if (strpos($requestUri, '/api/') === 0) {
    header('Content-Type: application/json');

    // CSRF check on POST/DELETE
    if ($method === 'POST' || $method === 'DELETE') {
        $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $csrfHeader)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }

    $client = new ExoClickClient();
    if (!empty($_SESSION['api_token'])) {
        $client->setApiToken($_SESSION['api_token']);
    }

    // GeoGroupManager removed

    // Batch endpoint uses SSE — handle before switch to avoid Content-Type: application/json
    if ($requestUri === '/api/create-variations-batch') {
        handleCreateVariationsBatch($client);
        exit;
    }

    switch ($requestUri) {
        case '/api/auth':
            handleAuth($client);
            break;
        case '/api/campaigns':
            handleCampaigns($client);
            break;
        case '/api/upload':
            handleUpload($client);
            break;
        case '/api/create-variations':
            handleCreateVariations($client);
            break;
        case '/api/campaign-countries':
            handleCampaignCountries($client, $method);
            break;
        case '/api/offers':
            handleOffers($client);
            break;
        case '/api/translate':
            handleTranslate();
            break;
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
    }
    exit;
}

// ============ API Handlers ============

function handleAuth(ExoClickClient $client): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST only']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $apiToken = trim($input['api_token'] ?? '');

    if ($apiToken === '') {
        http_response_code(400);
        echo json_encode(['error' => 'API token required']);
        return;
    }

    $result = $client->login($apiToken);
    if ($result['success']) {
        $_SESSION['api_token'] = $apiToken;
        $_SESSION['authenticated'] = true;
        echo json_encode(['success' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => $result['error']]);
    }
}

function handleCampaigns(ExoClickClient $client): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'GET only']);
        return;
    }

    if (empty($_SESSION['authenticated'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        return;
    }

    $campaigns = $client->getCampaigns();

    // Merge cached country targeting into each campaign
    $countryCacheFile = __DIR__ . '/../data/campaign_countries.json';
    $countryCache = [];
    if (file_exists($countryCacheFile)) {
        $countryCache = json_decode(file_get_contents($countryCacheFile), true) ?: [];
    }

    $unsyncedIds = [];
    foreach ($campaigns as &$camp) {
        $campId = (string)$camp['id'];
        if (isset($countryCache[$campId])) {
            $camp['countries'] = $countryCache[$campId]['countries'];
        } else {
            $camp['countries'] = null; // not yet synced
            $unsyncedIds[] = (int)$camp['id'];
        }
    }
    unset($camp);

    echo json_encode([
        'campaigns'    => $campaigns,
        'unsynced_ids' => $unsyncedIds,
    ]);
}

function handleUpload(ExoClickClient $client): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST only']);
        return;
    }

    if (empty($_SESSION['authenticated'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        return;
    }

    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        return;
    }

    $file = $_FILES['file'];
    $format = $_POST['format'] ?? 'banner';
    $pushSlot = $_POST['push_slot'] ?? null;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Upload error: ' . $file['error']]);
        return;
    }

    // Move to our upload dir with unique name
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
    $uniqueName = uniqid('exo_', true) . '.' . $safeExt;
    $destPath = UPLOAD_DIR . $uniqueName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save file']);
        return;
    }

    // Server-side validation
    $validator = new FileValidator();
    $result = $validator->validate($destPath, $format, $pushSlot);

    if (!$result['valid']) {
        @unlink($destPath);
        http_response_code(422);
        echo json_encode(['error' => implode('; ', $result['errors']), 'errors' => $result['errors']]);
        return;
    }

    // Upload to ExoClick library
    $uploadResult = $client->uploadFile($destPath, $file['name']);

    // Clean up local file
    @unlink($destPath);

    if ($uploadResult['error']) {
        http_response_code(502);
        echo json_encode(['error' => $uploadResult['error']]);
        return;
    }

    echo json_encode([
        'file_id'    => $uploadResult['file_id'],
        'filename'   => $file['name'],
        'dimensions' => $result['dimensions'],
        'size'       => $result['size'],
    ]);
}

function handleOffers(ExoClickClient $client): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'GET only']);
        return;
    }

    if (empty($_SESSION['authenticated'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        return;
    }

    $offers = $client->getOffers();
    echo json_encode(['offers' => $offers]);
}

function handleCreateVariations(ExoClickClient $client): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST only']);
        return;
    }

    if (empty($_SESSION['authenticated'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $campaignIds = $input['campaign_ids'] ?? [];
    $variations = $input['variations'] ?? [];

    if (empty($campaignIds) || empty($variations)) {
        http_response_code(400);
        echo json_encode(['error' => 'campaign_ids and variations are required']);
        return;
    }

    // Validate text fields server-side
    $format = $input['format'] ?? 'banner';
    $validator = new FileValidator();

    // User-selected offer ID (landing page group) — if provided, use it for all campaigns
    $userOfferId = isset($input['idoffer']) ? (string)$input['idoffer'] : null;

    $results = [];
    $successCount = 0;
    $failCount = 0;

    // Cache offer IDs per campaign to avoid redundant API calls
    $offerIdCache = [];

    foreach ($campaignIds as $campaignId) {
        $campaignId = (int)$campaignId;

        // Use user-selected offer, or fall back to auto-detect from campaign
        if ($userOfferId) {
            $offerId = $userOfferId;
        } else {
            if (!isset($offerIdCache[$campaignId])) {
                $offerId = $client->getCampaignOfferId($campaignId);
                $offerIdCache[$campaignId] = $offerId;
                if ($offerId) {
                    writeLog("Campaign $campaignId → idoffer=$offerId (auto-detected)");
                } else {
                    writeLog("Campaign $campaignId has no existing offer, variations may fail", 'WARN');
                }
            }
            $offerId = $offerIdCache[$campaignId];
        }

        foreach ($variations as $variation) {
            // Validate text fields
            $textFields = $variation['text_fields'] ?? [];
            $textResult = $validator->validateText($textFields, $format);
            if (!$textResult['valid']) {
                $results[] = [
                    'campaign_id'  => $campaignId,
                    'success'      => false,
                    'error'        => implode('; ', $textResult['errors']),
                ];
                $failCount++;
                continue;
            }

            // Build variation payload
            $payload = buildVariationPayload($format, $variation);

            // Include idoffer from campaign's existing variations
            if ($offerId) {
                $payload['idoffer'] = (string)$offerId;
            }

            $createResult = $client->createVariation($campaignId, $payload);
            if ($createResult['error']) {
                $results[] = [
                    'campaign_id'  => $campaignId,
                    'variation_id' => null,
                    'success'      => false,
                    'error'        => $createResult['error'],
                ];
                $failCount++;
            } else {
                $results[] = [
                    'campaign_id'  => $campaignId,
                    'variation_id' => $createResult['variation_id'],
                    'success'      => true,
                    'error'        => null,
                ];
                $successCount++;
            }
        }
    }

    echo json_encode([
        'results'  => $results,
        'success'  => $successCount,
        'failed'   => $failCount,
        'total'    => $successCount + $failCount,
    ]);
}

/**
 * Batch variation creation with SSE streaming progress.
 * POST: {campaign_ids, variations, format, idoffer}
 * Streams Server-Sent Events with per-variation progress.
 */
function handleCreateVariationsBatch(ExoClickClient $client): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['error' => 'POST only']);
        return;
    }

    // CSRF check
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfHeader)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        return;
    }

    if (empty($_SESSION['authenticated'])) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $campaignIds = $input['campaign_ids'] ?? [];
    $variations = $input['variations'] ?? [];
    $format = $input['format'] ?? 'banner';
    $userOfferId = isset($input['idoffer']) ? (string)$input['idoffer'] : null;
    $textOverrides = $input['campaign_text_overrides'] ?? [];

    if (empty($campaignIds) || empty($variations)) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['error' => 'campaign_ids and variations are required']);
        return;
    }

    // SSE headers
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    // Disable output buffering
    while (ob_get_level()) ob_end_flush();

    $validator = new FileValidator();
    $total = count($campaignIds) * count($variations);
    $progressNum = 0;
    $successCount = 0;
    $failCount = 0;

    // Helper to send an SSE event
    $sendEvent = function(array $data) {
        echo "data: " . json_encode($data) . "\n\n";
        flush();
    };

    // Cache offer IDs per campaign
    $offerIdCache = [];

    foreach ($campaignIds as $campaignId) {
        $campaignId = (int)$campaignId;

        // Resolve offer ID
        if ($userOfferId) {
            $offerId = $userOfferId;
        } else {
            if (!isset($offerIdCache[$campaignId])) {
                $offerId = $client->getCampaignOfferId($campaignId);
                $offerIdCache[$campaignId] = $offerId;
                if ($offerId) {
                    writeLog("Batch: campaign $campaignId → idoffer=$offerId (auto-detected)");
                }
            }
            $offerId = $offerIdCache[$campaignId];
        }

        // Build payloads for all variations on this campaign
        $payloads = [];
        $variationMeta = []; // tracks which indices are valid vs pre-failed
        $preFailed = [];     // indices that failed validation

        foreach ($variations as $vIdx => $variation) {
            // Validate text fields, applying per-campaign translated text if present
            $textFields = $variation['text_fields'] ?? [];
            $override = $textOverrides[(string)$campaignId][$vIdx] ?? null;
            if ($override) {
                $textFields = array_merge($textFields, $override);
            }
            $textResult = $validator->validateText($textFields, $format);
            if (!$textResult['valid']) {
                $preFailed[$vIdx] = implode('; ', $textResult['errors']);
                continue;
            }

            $varCopy = $variation;
            $varCopy['text_fields'] = $textFields;
            $payload = buildVariationPayload($format, $varCopy);
            if ($offerId) {
                $payload['idoffer'] = (string)$offerId;
            }
            $payloads[] = ['idx' => $vIdx, 'payload' => $payload];
        }

        // Emit pre-failed validation errors immediately
        foreach ($preFailed as $vIdx => $error) {
            $progressNum++;
            $failCount++;
            $sendEvent([
                'campaign_id'    => $campaignId,
                'variation_idx'  => $vIdx,
                'success'        => false,
                'variation_id'   => null,
                'error'          => $error,
                'progress_num'   => $progressNum,
                'progress_total' => $total,
            ]);
        }

        if (empty($payloads)) continue;

        // Use batch method for parallel creation
        $batchPayloads = array_column($payloads, 'payload');
        $batchResults = $client->createVariationsBatch($campaignId, $batchPayloads);

        // Emit results
        foreach ($batchResults as $bIdx => $result) {
            $vIdx = $payloads[$bIdx]['idx'];
            $progressNum++;
            if ($result['error']) {
                $failCount++;
                $sendEvent([
                    'campaign_id'    => $campaignId,
                    'variation_idx'  => $vIdx,
                    'success'        => false,
                    'variation_id'   => null,
                    'error'          => $result['error'],
                    'progress_num'   => $progressNum,
                    'progress_total' => $total,
                ]);
            } else {
                $successCount++;
                $sendEvent([
                    'campaign_id'    => $campaignId,
                    'variation_idx'  => $vIdx,
                    'success'        => true,
                    'variation_id'   => $result['variation_id'],
                    'error'          => null,
                    'progress_num'   => $progressNum,
                    'progress_total' => $total,
                ]);
            }
        }
    }

    // Final summary event
    $sendEvent([
        'type'          => 'complete',
        'success_count' => $successCount,
        'fail_count'    => $failCount,
        'total'         => $total,
    ]);
}

/**
 * Fetch/return cached campaign country targeting data.
 * GET: return all cached data.
 * POST: { campaign_ids: [int] } — fetch targeting for those campaigns, cache, return.
 */
function handleCampaignCountries(ExoClickClient $client, string $method): void {
    if (empty($_SESSION['authenticated'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        return;
    }

    $cacheFile = __DIR__ . '/../data/campaign_countries.json';
    $cached = [];
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true) ?: [];
    }

    if ($method === 'GET') {
        echo json_encode(['countries' => $cached]);
        return;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'GET or POST only']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $campaignIds = $input['campaign_ids'] ?? [];

    if (empty($campaignIds) || !is_array($campaignIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'campaign_ids array required']);
        return;
    }

    // Cap batch size to avoid timeouts
    $campaignIds = array_slice($campaignIds, 0, 30);

    // Get country ID → ISO2 mapping
    $countryMap = $client->getCountryMap();
    if (empty($countryMap)) {
        http_response_code(502);
        echo json_encode(['error' => 'Failed to fetch country mapping']);
        return;
    }

    $results = [];
    $now = time();

    foreach ($campaignIds as $cid) {
        $cid = (int)$cid;
        $countries = $client->getCampaignCountries($cid, $countryMap);
        $cached[(string)$cid] = [
            'countries' => $countries,
            'synced_at' => $now,
        ];
        $results[(string)$cid] = $countries;
    }

    // Save cache
    @file_put_contents($cacheFile, json_encode($cached));
    writeLog("Synced geo targeting for " . count($campaignIds) . " campaigns");

    echo json_encode([
        'results'      => $results,
        'cached_total' => count($cached),
    ]);
}

/**
 * Translate text fields via LibreTranslate.
 * POST: {texts: string[], source: string, targets: string[]}
 * Returns: {translations: {langCode: string[]}, lang_names: {}}
 */
function handleTranslate(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST only']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $texts = $input['texts'] ?? [];
    $sourceLang = $input['source'] ?? 'en';
    $targetLangs = $input['targets'] ?? [];

    if (empty($texts) || empty($targetLangs)) {
        http_response_code(400);
        echo json_encode(['error' => 'texts and targets required']);
        return;
    }

    $targetLangs = array_values(array_filter($targetLangs, fn($l) => $l !== $sourceLang));
    if (empty($targetLangs)) {
        echo json_encode(['translations' => [], 'lang_names' => LANG_NAMES]);
        return;
    }

    $url = LIBRETRANSLATE_URL . '/translate';
    $translations = [];

    foreach ($targetLangs as $lang) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'q'      => $texts,
                'source' => $sourceLang,
                'target' => $lang,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT    => LIBRETRANSLATE_TIMEOUT,
        ]);

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($resp, true);
            $translations[$lang] = $data['translatedText'] ?? $texts;
        } else {
            writeLog("LibreTranslate error for $lang: HTTP $httpCode", 'WARN');
            $translations[$lang] = $texts;
        }
    }

    echo json_encode([
        'translations' => $translations,
        'lang_names'   => LANG_NAMES,
    ]);
}

/**
 * Build the variation payload based on format.
 */
function buildVariationPayload(string $format, array $variation): array {
    $payload = [];

    $text = $variation['text_fields'] ?? [];

    switch ($format) {
        case 'native':
            if (!empty($text['title']))       $payload['name'] = $text['title'];
            if (!empty($text['description'])) $payload['description'] = $text['description'];
            if (!empty($text['brand']))       $payload['brand'] = $text['brand'];
            $payload['crop_anchor_point'] = (string)(int)($variation['crop_anchor_point'] ?? 0);
            if (!empty($variation['file_id'])) $payload['id_library_file'] = $variation['file_id'];
            break;

        case 'push':
            if (!empty($text['title']))       $payload['name'] = $text['title'];
            if (!empty($text['description'])) $payload['description'] = $text['description'];
            if (!empty($variation['icon_file_id'])) $payload['icon_file_id'] = $variation['icon_file_id'];
            if (!empty($variation['image_file_id'])) $payload['id_library_file'] = $variation['image_file_id'];
            break;

        case 'banner':
            if (!empty($variation['file_id'])) $payload['id_library_file'] = $variation['file_id'];
            if (!empty($text['landing_url']))  $payload['url'] = $text['landing_url'];
            break;

        case 'popunder':
            if (!empty($variation['file_id'])) $payload['id_library_file'] = $variation['file_id'];
            break;
    }

    return $payload;
}

// ============ HTML Shell ============
$csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ExoClick Bulk Creative Uploader</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <h1>ExoClick Bulk Creative Uploader</h1>
            <div id="token-status" class="token-status disconnected">Not connected</div>
        </header>

        <!-- Step 1: Auth -->
        <section id="step-auth" class="step active">
            <div class="step-header">
                <span class="step-number">1</span>
                <h2>Authenticate</h2>
            </div>
            <div class="step-body">
                <div class="auth-form">
                    <input type="password" id="api-token" placeholder="Enter ExoClick API Token" autocomplete="off">
                    <button id="btn-verify" class="btn btn-primary">Verify</button>
                </div>
                <div id="auth-error" class="error-msg hidden"></div>
            </div>
        </section>

        <!-- Step 2: Campaign Selection + File Upload -->
        <section id="step-campaigns" class="step locked">
            <div class="step-header">
                <span class="step-number">2</span>
                <h2>Select Campaigns &amp; Upload Files</h2>
            </div>
            <div class="step-body">
                <div class="two-col">
                    <div class="col">
                        <h3>Campaigns</h3>
                        <div class="campaign-controls">
                            <input type="text" id="campaign-search" placeholder="Filter campaigns...">
                            <button id="btn-sync-geos" class="btn btn-secondary btn-sm" title="Sync country targeting from ExoClick">Sync Geos</button>
                        </div>
                        <div id="filter-bar" class="filter-bar">
                            <div id="format-filter" class="multi-filter" data-label="Format"></div>
                            <div id="group-filter" class="multi-filter" data-label="Group"></div>
                            <div id="geo-filter" class="multi-filter" data-label="Geo"></div>
                        </div>
                        <div id="geo-sync-progress" class="geo-sync-bar hidden">
                            <div class="progress-bar" style="height:6px">
                                <div id="geo-sync-fill" class="progress-fill" style="width:0%"></div>
                            </div>
                            <span id="geo-sync-text" class="geo-sync-text">0 / 0</span>
                        </div>
                        <div id="campaign-list" class="campaign-list">
                            <div class="placeholder">Authenticate to load campaigns</div>
                        </div>
                        <div class="selected-info">
                            Selected: <span id="selected-count">0</span> campaigns
                            <span id="selected-format" class="format-badge hidden"></span>
                        </div>
                    </div>
                    <div class="col">
                        <h3>Upload Files</h3>
                        <div id="drop-zone" class="drop-zone">
                            <div class="drop-zone-content">
                                <div class="drop-icon">+</div>
                                <p>Drag &amp; drop files here or click to browse</p>
                                <p class="small">JPG, PNG, GIF, WebP</p>
                            </div>
                            <input type="file" id="file-input" multiple accept="image/jpeg,image/png,image/gif,image/webp" class="hidden">
                        </div>
                        <div id="file-grid" class="file-grid"></div>
                    </div>
                </div>
                <button id="btn-next" class="btn btn-primary btn-wide" disabled>Continue to Metadata</button>
            </div>
        </section>

        <!-- Step 3: Metadata + Submit -->
        <section id="step-metadata" class="step locked">
            <div class="step-header">
                <span class="step-number">3</span>
                <h2>Creative Details &amp; Submit</h2>
            </div>
            <div class="step-body">
                <div id="variation-rows" class="variation-rows"></div>
                <button id="btn-add-row" class="btn btn-secondary">+ Add Row</button>
                <hr>
                <div class="submit-section">
                    <button id="btn-submit" class="btn btn-primary btn-wide">Create Variations</button>
                    <div id="progress-section" class="hidden">
                        <div class="progress-bar">
                            <div id="progress-fill" class="progress-fill" style="width:0%"></div>
                        </div>
                        <div id="progress-text" class="progress-text">0 / 0</div>
                    </div>
                </div>
                <div id="result-log" class="result-log hidden"></div>
            </div>
        </section>
    </div>

    <script>window.CSRF_TOKEN = "<?= $csrfToken ?>"; window.AUTO_AUTH = <?= !empty($_SESSION['authenticated']) ? 'true' : 'false' ?>;</script>
    <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
