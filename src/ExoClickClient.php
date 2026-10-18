<?php
/**
 * ExoClick Bulk Creative Uploader — API Client
 * Handles authentication, file uploads, variation creation, rate limiting.
 *
 * Key API quirks (from existing codebase knowledge):
 * - POST /login with {"api_token":"..."} → {"token":"...","expires_in":3600}
 * - Campaigns list returns dict keyed by campaign ID, not array
 * - Variation endpoint is singular: /campaigns/{id}/variation (NOT /variations)
 * - Costs in dollars, bids in cents
 * - Rate limit: 150 req/60s
 */

require_once __DIR__ . '/config.php';

class ExoClickClient {
    private ?string $bearerToken = null;
    private ?string $apiToken = null;
    private array $requestTimestamps = [];
    private ?\CurlHandle $persistentHandle = null;

    /**
     * Get or create a persistent curl handle for connection reuse.
     */
    private function getPersistentHandle(): \CurlHandle {
        if ($this->persistentHandle === null) {
            $this->persistentHandle = curl_init();
            curl_setopt_array($this->persistentHandle, [
                CURLOPT_FORBID_REUSE   => false,
                CURLOPT_TCP_KEEPALIVE  => 1,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_RETURNTRANSFER => true,
            ]);
        }
        return $this->persistentHandle;
    }

    public function __destruct() {
        if ($this->persistentHandle !== null) {
            curl_close($this->persistentHandle);
            $this->persistentHandle = null;
        }
    }

    /**
     * Authenticate with ExoClick API.
     * @param string $apiToken  The user's API token
     * @return array {success: bool, error: string|null}
     */
    public function login(string $apiToken): array {
        $this->apiToken = $apiToken;

        $ch = curl_init(EXOCLICK_API_BASE . '/login');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['api_token' => $apiToken]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            writeLog("Login curl error: $curlError", 'ERROR');
            return ['success' => false, 'error' => "Connection error: $curlError"];
        }

        if ($httpCode !== 200) {
            writeLog("Login failed: HTTP $httpCode", 'ERROR');
            return ['success' => false, 'error' => "Authentication failed (HTTP $httpCode)"];
        }

        $data = json_decode($resp, true);
        if (!isset($data['token'])) {
            writeLog("Login failed: no token in response", 'ERROR');
            return ['success' => false, 'error' => 'Invalid response from ExoClick'];
        }

        $this->bearerToken = $data['token'];
        $expiresIn = (int)($data['expires_in'] ?? 3600);

        // Cache bearer token (NOT the api_token)
        $cacheData = [
            'token'      => $this->bearerToken,
            'expires_ts' => time() + $expiresIn - 60,
        ];
        @file_put_contents(TOKEN_CACHE_FILE, json_encode($cacheData));
        @chmod(TOKEN_CACHE_FILE, 0600);

        writeLog("Authenticated successfully");
        return ['success' => true, 'error' => null];
    }

    /**
     * Get a valid bearer token, refreshing if expired.
     */
    private function getToken(): ?string {
        if ($this->bearerToken) {
            return $this->bearerToken;
        }

        // Try cache
        if (file_exists(TOKEN_CACHE_FILE)) {
            $cached = json_decode(file_get_contents(TOKEN_CACHE_FILE), true) ?: [];
            if (isset($cached['expires_ts']) && $cached['expires_ts'] > time() && isset($cached['token'])) {
                $this->bearerToken = $cached['token'];
                return $this->bearerToken;
            }
        }

        // Re-login if we have the api_token in session
        if ($this->apiToken) {
            $result = $this->login($this->apiToken);
            if ($result['success']) {
                return $this->bearerToken;
            }
        }

        return null;
    }

    /**
     * Set the API token (from session restore).
     */
    public function setApiToken(string $apiToken): void {
        $this->apiToken = $apiToken;
    }

    /**
     * Rate-limited API call.
     * @param string $endpoint  API path (e.g., /campaigns)
     * @param array $data       Request body (for POST/PUT)
     * @param string $method    HTTP method
     * @param bool $isMultipart Whether to send as multipart form data
     * @return array Decoded JSON response
     */
    public function apiCall(string $endpoint, array $data = [], string $method = 'POST', bool $isMultipart = false): array {
        $this->enforceRateLimit();

        $token = $this->getToken();
        if (!$token) {
            return ['error' => 'Not authenticated'];
        }

        $url = EXOCLICK_API_BASE . $endpoint;
        $ch = $this->getPersistentHandle();
        $headers = ["Authorization: Bearer $token"];

        // Reset handle state for this request
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, null);

        if ($method === 'GET') {
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT    => 30,
            ]);
        } elseif ($isMultipart) {
            // For file uploads — don't json_encode, don't set Content-Type (curl sets it with boundary)
            curl_setopt_array($ch, [
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT    => 60,
            ]);
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt_array($ch, [
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT    => 30,
            ]);
        }

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        $this->requestTimestamps[] = time();

        if ($curlError) {
            writeLog("API curl error on $endpoint: $curlError", 'ERROR');
            return ['error' => "Connection error: $curlError"];
        }

        $decoded = json_decode($resp, true) ?: [];

        // Handle 401 — token expired, try refresh
        if ($httpCode === 401 && $this->apiToken) {
            writeLog("Got 401 on $endpoint, refreshing token", 'WARN');
            $this->bearerToken = null;
            @unlink(TOKEN_CACHE_FILE);
            $loginResult = $this->login($this->apiToken);
            if ($loginResult['success']) {
                return $this->apiCall($endpoint, $data, $method, $isMultipart);
            }
            return ['error' => 'Token expired and refresh failed'];
        }

        // Handle 429 — rate limited, retry with backoff
        if ($httpCode === 429) {
            return $this->retryWithBackoff($endpoint, $data, $method, $isMultipart);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return $decoded;
        }

        $errorMsg = $decoded['error'] ?? $decoded['message'] ?? "HTTP $httpCode";
        if (is_array($errorMsg)) {
            $errorMsg = implode('; ', $errorMsg);
        }
        writeLog("API error on $method $endpoint: $errorMsg", 'ERROR');
        return ['error' => $errorMsg];
    }

    /**
     * Enforce rate limit: 150 requests per 60 seconds.
     */
    private function enforceRateLimit(): void {
        $now = time();
        // Prune old timestamps
        $this->requestTimestamps = array_filter(
            $this->requestTimestamps,
            fn($ts) => ($now - $ts) < RATE_LIMIT_WINDOW
        );

        if (count($this->requestTimestamps) >= RATE_LIMIT_MAX) {
            $oldest = min($this->requestTimestamps);
            $waitTime = RATE_LIMIT_WINDOW - ($now - $oldest) + 1;
            writeLog("Rate limit reached, sleeping {$waitTime}s", 'WARN');
            sleep($waitTime);
        }
    }

    /**
     * Retry a request with exponential backoff on 429.
     */
    private function retryWithBackoff(string $endpoint, array $data, string $method, bool $isMultipart, int $attempt = 0): array {
        $delays = [2, 5, 10];
        if ($attempt >= count($delays)) {
            writeLog("Rate limit retry exhausted on $endpoint", 'ERROR');
            return ['error' => 'Rate limited by ExoClick API after multiple retries'];
        }

        $delay = $delays[$attempt];
        writeLog("429 on $endpoint, retry #{$attempt} in {$delay}s", 'WARN');
        sleep($delay);

        $token = $this->getToken();
        if (!$token) {
            return ['error' => 'Not authenticated'];
        }

        $url = EXOCLICK_API_BASE . $endpoint;
        $ch = $this->getPersistentHandle();
        $headers = ["Authorization: Bearer $token"];

        // Reset handle state for retry
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, null);

        if ($method === 'GET') {
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT    => 30,
            ]);
        } elseif ($isMultipart) {
            curl_setopt_array($ch, [
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT    => 60,
            ]);
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt_array($ch, [
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT    => 30,
            ]);
        }

        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $this->requestTimestamps[] = time();

        if ($httpCode === 429) {
            return $this->retryWithBackoff($endpoint, $data, $method, $isMultipart, $attempt + 1);
        }

        $decoded = json_decode($resp, true) ?: [];
        if ($httpCode >= 200 && $httpCode < 300) {
            return $decoded;
        }

        $errorMsg = $decoded['error'] ?? $decoded['message'] ?? "HTTP $httpCode";
        if (is_array($errorMsg)) $errorMsg = implode('; ', $errorMsg);
        return ['error' => $errorMsg];
    }

    // =========================================================================
    // Campaign methods
    // =========================================================================

    /**
     * Fetch ExoClick country ID → ISO2 mapping.
     * Cached in data/country_map.json.
     * @return array { "56" => "BE", "250" => "FR", ... }
     */
    public function getCountryMap(): array {
        $cacheFile = __DIR__ . '/../data/country_map.json';
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && count($cached) > 100) {
                return $cached;
            }
        }

        $resp = $this->apiCall('/collections/countries?limit=300', [], 'GET');
        $map = [];
        if (isset($resp['result']) && is_array($resp['result'])) {
            foreach ($resp['result'] as $c) {
                $map[(string)$c['id']] = $c['iso2'] ?? 'XX';
            }
        }

        if (count($map) > 0) {
            @file_put_contents($cacheFile, json_encode($map));
            writeLog("Cached " . count($map) . " country mappings");
        }

        return $map;
    }

    /**
     * Fetch targeted countries for a single campaign.
     * @return array List of ISO2 country codes, e.g. ["US","CA","GB"]
     */
    public function getCampaignCountries(int $campaignId, array $countryMap): array {
        $resp = $this->apiCall("/campaigns/$campaignId", [], 'GET');
        $countries = [];

        $targeted = $resp['result']['countries']['targeted'] ?? [];
        foreach ($targeted as $entry) {
            $cid = (string)($entry['id'] ?? 0);
            $iso2 = $countryMap[$cid] ?? null;
            if ($iso2 && $iso2 !== 'XX') {
                $countries[] = $iso2;
            }
        }

        return $countries;
    }

    /**
     * Fetch campaign groups.
     * @return array { idgroup => group_name, ... }
     */
    public function getCampaignGroups(): array {
        $resp = $this->apiCall('/campaigns/groups', [], 'GET');
        $groups = [];
        if (isset($resp['result']) && is_array($resp['result'])) {
            foreach ($resp['result'] as $g) {
                $id = $g['id'] ?? $g['idgroup'] ?? null;
                $name = $g['name'] ?? '';
                if ($id !== null) {
                    $groups[(string)$id] = $name;
                }
            }
        }
        writeLog("Fetched " . count($groups) . " campaign groups");
        return $groups;
    }

    /**
     * Fetch campaigns, optionally filtered by group name keyword.
     * Set CAMPAIGN_GROUP_FILTER in config.php to filter (e.g. 'propush'),
     * or leave empty to show all campaigns.
     * Attaches group_name to each campaign.
     */
    public function getCampaigns(): array {
        $groupFilter = defined('CAMPAIGN_GROUP_FILTER') ? CAMPAIGN_GROUP_FILTER : '';

        // Fetch groups
        $groups = $this->getCampaignGroups();
        $matchedGroupIds = [];
        foreach ($groups as $gid => $gname) {
            if ($groupFilter === '' || stripos($gname, $groupFilter) !== false) {
                $matchedGroupIds[(string)$gid] = $gname;
            }
        }

        $campaigns = [];
        $limit = 500;
        $offset = 0;

        do {
            $resp = $this->apiCall("/campaigns?limit=$limit&offset=$offset", [], 'GET');
            if (isset($resp['error']) || !isset($resp['result']) || !is_array($resp['result'])) {
                break;
            }

            foreach ($resp['result'] as $camp) {
                $idgroup = $camp['idgroup'] ?? null;
                $groupKey = $idgroup !== null ? (string)$idgroup : null;

                // Only include campaigns in matched groups
                if ($groupKey === null || !isset($matchedGroupIds[$groupKey])) {
                    continue;
                }

                $adType = (int)($camp['advertiser_ad_type'] ?? $camp['idadtype'] ?? 0);
                $campaigns[] = [
                    'id'         => (int)($camp['id'] ?? 0),
                    'name'       => $camp['name'] ?? '',
                    'status'     => (int)($camp['status'] ?? 0),
                    'idadtype'   => $adType,
                    'idgroup'    => $idgroup,
                    'group_name' => $matchedGroupIds[$groupKey],
                    'format'     => FORMAT_CATEGORY_MAP[$adType] ?? 'unknown',
                ];
            }

            $fetched = count($resp['result']);
            $offset += $fetched;
        } while ($fetched >= $limit);

        writeLog("Fetched " . count($campaigns) . " campaigns (from " . count($matchedGroupIds) . " matched groups)");
        return $campaigns;
    }

    /**
     * Upload a file to ExoClick library.
     * POST /library/file (multipart)
     * @return array {file_id: string|null, error: string|null}
     */
    public function uploadFile(string $filePath, string $fileName): array {
        if (!file_exists($filePath)) {
            return ['file_id' => null, 'error' => 'File not found'];
        }

        $cfile = new CURLFile($filePath, mime_content_type($filePath), $fileName);
        $resp = $this->apiCall('/library/file', ['file' => $cfile], 'POST', true);

        if (isset($resp['error'])) {
            writeLog("File upload failed ($fileName): " . $resp['error'], 'ERROR');
            return ['file_id' => null, 'error' => $resp['error']];
        }

        $fileId = $resp['id'] ?? $resp['file_id'] ?? $resp['result']['id'] ?? null;
        if (!$fileId) {
            writeLog("File upload: no file_id in response for $fileName", 'ERROR');
            return ['file_id' => null, 'error' => 'No file ID returned'];
        }

        writeLog("Uploaded file $fileName → file_id=$fileId");
        return ['file_id' => (string)$fileId, 'error' => null];
    }

    /**
     * Get the idoffer for a campaign by reading its first existing variation.
     * @return int|null  The offer ID, or null if not found
     */
    public function getCampaignOfferId(int $campaignId): ?int {
        $resp = $this->apiCall("/campaigns/$campaignId", [], 'GET');
        $variations = $resp['result']['variations'] ?? [];
        foreach ($variations as $v) {
            $offerId = $v['offer_id'] ?? null;
            if ($offerId) {
                return (int)$offerId;
            }
        }
        return null;
    }

    /**
     * Fetch all offers (landing page groups).
     * GET /offers
     * @return array List of offers with their landing pages
     */
    public function getOffers(): array {
        $resp = $this->apiCall('/offers', [], 'GET');
        $offers = [];
        $result = $resp['result'] ?? $resp;
        if (is_array($result)) {
            foreach ($result as $offer) {
                if (!is_array($offer) || !isset($offer['id'])) continue;
                $lps = [];
                foreach (($offer['landing_pages'] ?? []) as $lp) {
                    $lps[] = [
                        'id'   => $lp['id'] ?? null,
                        'name' => $lp['name'] ?? '',
                        'url'  => $lp['url'] ?? '',
                    ];
                }
                $offers[] = [
                    'id'            => (int)$offer['id'],
                    'name'          => $offer['name'] ?? '',
                    'landing_pages' => $lps,
                ];
            }
        }
        writeLog("Fetched " . count($offers) . " offers");
        return $offers;
    }

    /**
     * Create a variation (creative) on a campaign.
     * POST /campaigns/{id}/variation (singular, NOT /variations)
     * @param int $campaignId
     * @param array $payload  Variation fields (varies by format)
     * @return array {variation_id: string|null, error: string|null}
     */
    public function createVariation(int $campaignId, array $payload): array {
        // Endpoint is singular: /variation — requires multipart/form-data (NOT JSON)
        $resp = $this->apiCall("/campaigns/$campaignId/variation", $payload, 'POST', true);

        if (isset($resp['error'])) {
            writeLog("Create variation failed (campaign=$campaignId): " . $resp['error'], 'ERROR');
            return ['variation_id' => null, 'error' => $resp['error']];
        }

        // API returns empty body on success (HTTP 200), or a body with an ID
        $varId = $resp['id'] ?? $resp['idvariation'] ?? $resp['result']['id'] ?? null;

        writeLog("Created variation on campaign $campaignId" . ($varId ? " (id=$varId)" : ""));
        return ['variation_id' => $varId ? (string)$varId : 'created', 'error' => null];
    }

    /**
     * Create multiple variations on a campaign concurrently via curl_multi.
     * @param int $campaignId
     * @param array $payloads  Array of multipart payload arrays
     * @param int $maxConcurrent  Max simultaneous requests (default 8)
     * @return array  Results in same order as input: [{variation_id, error}, ...]
     */
    public function createVariationsBatch(int $campaignId, array $payloads, int $maxConcurrent = 8): array {
        if (empty($payloads)) return [];

        $token = $this->getToken();
        if (!$token) {
            return array_fill(0, count($payloads), ['variation_id' => null, 'error' => 'Not authenticated']);
        }

        $url = EXOCLICK_API_BASE . "/campaigns/$campaignId/variation";
        $results = array_fill(0, count($payloads), null);
        $mh = curl_multi_init();
        $active = [];     // index => curl handle
        $pending = [];    // queue of payload indices not yet started
        $retries = [];    // index => retry count

        // Build pending queue
        for ($i = 0; $i < count($payloads); $i++) {
            $pending[] = $i;
            $retries[$i] = 0;
        }

        // Helper: create a curl handle for a payload index
        $makeHandle = function(int $idx) use ($url, $token, $payloads): \CurlHandle {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payloads[$idx],
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FORBID_REUSE   => false,
            ]);
            return $ch;
        };

        // Seed initial batch
        while (count($active) < $maxConcurrent && !empty($pending)) {
            $idx = array_shift($pending);
            $this->enforceRateLimit();
            $ch = $makeHandle($idx);
            curl_multi_add_handle($mh, $ch);
            $active[(int)$ch] = $idx;
            $this->requestTimestamps[] = time();
        }

        // Process
        do {
            $status = curl_multi_exec($mh, $running);
            if ($status > CURLM_OK) break;

            // Check for completed handles
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $chId = (int)$ch;
                $idx = $active[$chId] ?? null;
                if ($idx === null) {
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    continue;
                }
                unset($active[$chId]);

                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $resp = curl_multi_getcontent($ch);
                $curlError = curl_error($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($curlError) {
                    $results[$idx] = ['variation_id' => null, 'error' => "Connection error: $curlError"];
                    writeLog("Batch variation curl error (campaign=$campaignId, idx=$idx): $curlError", 'ERROR');
                } elseif ($httpCode === 401 && $this->apiToken) {
                    // Token expired — refresh and retry this one
                    writeLog("Batch got 401, refreshing token", 'WARN');
                    $this->bearerToken = null;
                    @unlink(TOKEN_CACHE_FILE);
                    $loginResult = $this->login($this->apiToken);
                    if ($loginResult['success']) {
                        $token = $this->bearerToken;
                        // Re-queue this payload
                        array_unshift($pending, $idx);
                    } else {
                        $results[$idx] = ['variation_id' => null, 'error' => 'Token expired and refresh failed'];
                    }
                } elseif ($httpCode === 429) {
                    // Rate limited — backoff and retry
                    if ($retries[$idx] < 3) {
                        $delay = [2, 5, 10][$retries[$idx]];
                        writeLog("Batch 429 on idx=$idx, retry #{$retries[$idx]} in {$delay}s", 'WARN');
                        sleep($delay);
                        $retries[$idx]++;
                        array_unshift($pending, $idx);
                    } else {
                        $results[$idx] = ['variation_id' => null, 'error' => 'Rate limited after multiple retries'];
                    }
                } elseif ($httpCode >= 200 && $httpCode < 300) {
                    $decoded = json_decode($resp, true) ?: [];
                    $varId = $decoded['id'] ?? $decoded['idvariation'] ?? $decoded['result']['id'] ?? null;
                    $results[$idx] = ['variation_id' => $varId ? (string)$varId : 'created', 'error' => null];
                    writeLog("Batch created variation on campaign $campaignId idx=$idx" . ($varId ? " (id=$varId)" : ""));
                } else {
                    $decoded = json_decode($resp, true) ?: [];
                    $errorMsg = $decoded['error'] ?? $decoded['message'] ?? "HTTP $httpCode";
                    if (is_array($errorMsg)) $errorMsg = implode('; ', $errorMsg);
                    $results[$idx] = ['variation_id' => null, 'error' => $errorMsg];
                    writeLog("Batch variation error (campaign=$campaignId, idx=$idx): $errorMsg", 'ERROR');
                }

                // Queue next pending
                if (!empty($pending)) {
                    $nextIdx = array_shift($pending);
                    $this->enforceRateLimit();
                    $newCh = $makeHandle($nextIdx);
                    curl_multi_add_handle($mh, $newCh);
                    $active[(int)$newCh] = $nextIdx;
                    $this->requestTimestamps[] = time();
                }
            }

            // Avoid busy-wait
            if ($running > 0) {
                curl_multi_select($mh, 0.1);
            }
        } while ($running > 0 || !empty($active));

        curl_multi_close($mh);

        // Fill any nulls (shouldn't happen, but safety)
        for ($i = 0; $i < count($results); $i++) {
            if ($results[$i] === null) {
                $results[$i] = ['variation_id' => null, 'error' => 'Unknown error'];
            }
        }

        writeLog("Batch complete: campaign=$campaignId, " . count($payloads) . " payloads processed");
        return $results;
    }
}
