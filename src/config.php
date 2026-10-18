<?php
/**
 * ExoClick Bulk Creative Uploader — Configuration
 */

define('EXOCLICK_API_BASE', 'https://api.exoclick.com/v2');

// Authentication — set to true to require session-based login before accessing the UI.
// When false (default), the ExoClick API token entered in Step 1 is the sole auth gate.
define('AUTH_REQUIRED', false);

// Campaign group filter — only show campaigns whose group name contains this substring.
// Empty string shows all campaigns.
define('CAMPAIGN_GROUP_FILTER', '');

// Paths
define('UPLOAD_DIR', __DIR__ . '/../data/uploads/');
define('TOKEN_CACHE_FILE', __DIR__ . '/../data/token_cache.json');
define('LOG_FILE', __DIR__ . '/../logs/uploader.log');
define('GEO_SETS_FILE', __DIR__ . '/../data/geo_sets.json');
define('CAMPAIGN_GEO_FILE', __DIR__ . '/../data/campaign_geo.json');

// LibreTranslate
define('LIBRETRANSLATE_URL', getenv('LIBRETRANSLATE_URL') ?: 'http://localhost:5000');
define('LIBRETRANSLATE_TIMEOUT', 30);

// Rate limiting
define('RATE_LIMIT_MAX', 150);
define('RATE_LIMIT_WINDOW', 60); // seconds

// ExoClick ad format IDs (from /collections/advertiser-ad-types)
// These map to the format categories used in validation
define('FORMAT_CATEGORY_MAP', [
    // Banner formats
    0  => 'banner',  // Banner 300x250
    1  => 'banner',  // Banner 728x90
    4  => 'banner',  // Banner 160x600
    13 => 'banner',  // Mobile 300x50
    14 => 'banner',  // Mobile 300x100
    30 => 'banner',  // Banner 900x250
    53 => 'banner',  // Banner 300x500
    // Native
    22 => 'native',  // Native Ad
    // Push notification
    33 => 'push',    // Push Notifications 192x192 (Icon)
    35 => 'push',    // Push Notifications 720x480 (Icon + Image)
    // Video
    21 => 'video',   // Video
    155 => 'video',  // Vertical Video
    // Popunder
    7  => 'popunder', // Popunders
    // Direct Link
    8  => 'popunder', // Direct Link
    // Email
    32 => 'banner',  // Email Clicks
    // Fullpage Interstitial
    34 => 'popunder', // Mobile Fullpage Interstitial
    36 => 'popunder', // Desktop Fullpage Interstitial
]);

// Format-specific validation specs
define('FORMAT_SPECS', [
    'banner' => [
        'label' => 'Banner',
        'accepted_sizes' => [
            [300, 250], [728, 90], [160, 600], [468, 60], [120, 600],
            [300, 100], [300, 50], [320, 50], [320, 480], [580, 400],
        ],
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'text_fields' => [],
    ],
    'native' => [
        'label' => 'Native',
        // Native uses aspect ratios, not fixed sizes.
        // Accepted ratios: 1:1, 4:3, 16:9 with 10% flexibility, 300-900px per side.
        'accepted_sizes' => [], // empty — validated via aspect_ratios instead
        'aspect_ratios' => [
            ['w' => 1, 'h' => 1, 'flexibility' => 0.1],  // 1:1
            ['w' => 4, 'h' => 3, 'flexibility' => 0.1],  // 4:3
            ['w' => 16, 'h' => 9, 'flexibility' => 0.1], // 16:9
        ],
        'min_dimension' => 300,
        'max_dimension' => 900,
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'text_fields' => [
            'title'       => ['label' => 'Title', 'max' => 90, 'required' => true],
            'description' => ['label' => 'Description', 'max' => 90, 'required' => true],
            'brand'       => ['label' => 'Brand Name', 'max' => 30, 'required' => true],
        ],
    ],
    'push' => [
        'label' => 'Push Notification',
        'images' => [
            'icon' => [
                'accepted_sizes' => [[192, 192]],
                'required' => true,
            ],
            'image' => [
                'accepted_sizes' => [[720, 480]],
                'required' => false,
            ],
        ],
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
        'text_fields' => [
            'title'       => ['label' => 'Headline', 'max' => 30, 'required' => true],
            'description' => ['label' => 'Body', 'max' => 90, 'required' => true],
        ],
    ],
    'video' => [
        'label' => 'Video',
        'accepted_sizes' => [],
        'allowed_mime' => ['video/mp4', 'video/webm'],
        'text_fields' => [],
    ],
    'popunder' => [
        'label' => 'Popunder',
        'accepted_sizes' => [
            [1600, 900], [900, 1600],
        ],
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'text_fields' => [],
    ],
]);

// Geo region presets — seeded into geo_sets.json on first load
define('GEO_PRESETS', [
    'east_asia'      => ['label' => 'East Asia',      'countries' => ['JP','KR','TW','HK','CN']],
    'southeast_asia' => ['label' => 'Southeast Asia',  'countries' => ['TH','VN','PH','ID','MY','SG','MM','KH','LA']],
    'south_asia'     => ['label' => 'South Asia',      'countries' => ['IN','PK','BD','LK','NP']],
    'africa'         => ['label' => 'Africa',          'countries' => ['NG','KE','ZA','GH','TZ','UG','EG','MA','DZ','ET','CI','CM','SN']],
    'latam'          => ['label' => 'LATAM',           'countries' => ['BR','MX','AR','CO','CL','PE','EC','VE']],
    'europe_west'    => ['label' => 'Western Europe',  'countries' => ['GB','FR','DE','ES','IT','NL','BE','PT','AT','CH','IE']],
    'europe_east'    => ['label' => 'Eastern Europe',  'countries' => ['PL','CZ','RO','HU','BG','SK','HR','UA','RS']],
    'north_america'  => ['label' => 'North America',   'countries' => ['US','CA']],
    'middle_east'    => ['label' => 'Middle East',     'countries' => ['SA','AE','IL','TR','QA','KW','BH','OM','JO','LB']],
    'oceania'        => ['label' => 'Oceania',         'countries' => ['AU','NZ']],
]);

// Country ISO2 → LibreTranslate language code
define('COUNTRY_TO_LANG', [
    // English-speaking
    'US'=>'en','GB'=>'en','AU'=>'en','NZ'=>'en','CA'=>'en','IE'=>'en',
    'ZA'=>'en','NG'=>'en','GH'=>'en','UG'=>'en','SG'=>'en',
    // Spanish
    'ES'=>'es','MX'=>'es','AR'=>'es','CO'=>'es','CL'=>'es','PE'=>'es','EC'=>'es','VE'=>'es',
    // Portuguese
    'BR'=>'pt','PT'=>'pt',
    // French
    'FR'=>'fr','BE'=>'fr','CI'=>'fr','CM'=>'fr','SN'=>'fr','MA'=>'fr','DZ'=>'fr',
    // German
    'DE'=>'de','AT'=>'de','CH'=>'de',
    // Italian
    'IT'=>'it',
    // Dutch
    'NL'=>'nl',
    // Japanese, Korean, Chinese
    'JP'=>'ja','KR'=>'ko','CN'=>'zh','TW'=>'zh','HK'=>'zh',
    // Southeast Asia
    'TH'=>'th','VN'=>'vi','ID'=>'id','PH'=>'tl','MY'=>'ms',
    // South Asia
    'IN'=>'hi','PK'=>'ur','BD'=>'bn',
    // Eastern Europe
    'PL'=>'pl','CZ'=>'cs','RO'=>'ro','HU'=>'hu','BG'=>'bg','SK'=>'sk',
    'UA'=>'uk','RS'=>'sr','HR'=>'en',
    // Middle East
    'SA'=>'ar','AE'=>'ar','EG'=>'ar','QA'=>'ar','KW'=>'ar','BH'=>'ar',
    'OM'=>'ar','JO'=>'ar','LB'=>'ar','IL'=>'he','TR'=>'tr',
    // Africa
    'KE'=>'sw','TZ'=>'sw','ET'=>'en',
    // Nordic
    'SE'=>'sv','NO'=>'nb','DK'=>'da','FI'=>'fi',
    // Baltic
    'LV'=>'lv','LT'=>'lt','EE'=>'et',
    // Other
    'RU'=>'ru','GR'=>'el','IR'=>'fa',
]);

define('LANG_NAMES', [
    'en'=>'English','es'=>'Spanish','pt'=>'Portuguese','fr'=>'French',
    'de'=>'German','it'=>'Italian','nl'=>'Dutch','ja'=>'Japanese',
    'ko'=>'Korean','zh'=>'Chinese','th'=>'Thai','vi'=>'Vietnamese',
    'id'=>'Indonesian','tl'=>'Tagalog','ms'=>'Malay','hi'=>'Hindi',
    'ur'=>'Urdu','bn'=>'Bengali','pl'=>'Polish','cs'=>'Czech',
    'ro'=>'Romanian','hu'=>'Hungarian','bg'=>'Bulgarian','sk'=>'Slovak',
    'uk'=>'Ukrainian','sr'=>'Serbian','ar'=>'Arabic','he'=>'Hebrew',
    'tr'=>'Turkish','sw'=>'Swahili','sv'=>'Swedish','nb'=>'Norwegian',
    'da'=>'Danish','fi'=>'Finnish','lv'=>'Latvian','lt'=>'Lithuanian',
    'et'=>'Estonian','ru'=>'Russian','el'=>'Greek','fa'=>'Persian',
]);

/**
 * Write to activity log
 */
function writeLog(string $message, string $level = 'INFO'): void {
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] [$level] $message\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}
