/**
 * ExoClick Bulk Creative Uploader — Frontend SPA
 */

(function() {
    'use strict';

    // =========================================================================
    // State
    // =========================================================================
    const state = {
        authenticated: false,
        campaigns: [],
        selectedCampaignIds: new Set(),
        detectedFormat: null,     // format of selected campaigns
        uploadedFiles: [],        // {id, file, objectUrl, filename, dimensions, fileId, status, errors}
        variationRows: [],        // push only: {id, iconFileId, imageFileId, textFields}
        selectedFileIds: new Set(), // non-push: which uploaded file IDs to create variations for
        sharedTextFields: {},     // non-push: shared text fields across all selected images
        cropAnchorPoint: 0,       // native: crop anchor (0=middleCenter)
        nextFileId: 1,
        nextRowId: 1,
        // Geo features — auto from ExoClick targeting
        unsyncedIds: [],          // campaign IDs not yet synced
        submitting: false,        // true while batch creation is in progress
        offers: [],               // ExoClick offers (landing page groups)
        selectedOfferId: null,    // user-selected offer ID
        // Translation
        translations: {},         // {langCode: [translatedText, ...]} from API
        campaignLangMap: {},      // {campaignId: langCode} resolved mapping
        translatedFields: {},     // {langCode: {fieldName: editedValue}} for native; {langCode: [{title,description}, ...]} for push
        sourceLanguage: 'en',
    };

    // Region presets for geo filtering (matches server GEO_PRESETS)
    const GEO_REGIONS = {
        east_asia:      { label: 'East Asia',       countries: ['JP','KR','TW','HK','CN'] },
        southeast_asia: { label: 'Southeast Asia',  countries: ['TH','VN','PH','ID','MY','SG','MM','KH','LA'] },
        south_asia:     { label: 'South Asia',      countries: ['IN','PK','BD','LK','NP'] },
        africa:         { label: 'Africa',          countries: ['NG','KE','ZA','GH','TZ','UG','EG','MA','DZ','ET','CI','CM','SN'] },
        latam:          { label: 'LATAM',           countries: ['BR','MX','AR','CO','CL','PE','EC','VE'] },
        europe_west:    { label: 'Western Europe',  countries: ['GB','FR','DE','ES','IT','NL','BE','PT','AT','CH','IE'] },
        europe_east:    { label: 'Eastern Europe',  countries: ['PL','CZ','RO','HU','BG','SK','HR','UA','RS'] },
        north_america:  { label: 'North America',   countries: ['US','CA'] },
        middle_east:    { label: 'Middle East',     countries: ['SA','AE','IL','TR','QA','KW','BH','OM','JO','LB'] },
        oceania:        { label: 'Oceania',         countries: ['AU','NZ'] },
    };

    // Format specs (mirrors server config for client-side validation)
    const FORMAT_SPECS = {
        banner: {
            label: 'Banner',
            acceptedSizes: [[300,250],[728,90],[160,600],[468,60],[120,600],[300,100],[300,50],[320,50],[320,480],[580,400]],
            textFields: [],
        },
        native: {
            label: 'Native',
            // Native uses aspect ratios: 1:1, 4:3, 16:9 (10% flex), 300-900px per side
            acceptedSizes: [],
            aspectRatios: [{w:1,h:1,flex:0.1},{w:4,h:3,flex:0.1},{w:16,h:9,flex:0.1}],
            minDimension: 300,
            maxDimension: 900,
            textFields: [
                { name: 'title', label: 'Title', max: 90, required: true },
                { name: 'description', label: 'Description', max: 90, required: true },
                { name: 'brand', label: 'Brand Name', max: 30, required: true },
            ],
        },
        push: {
            label: 'Push Notification',
            icon: { acceptedSizes: [[192,192]] },
            image: { acceptedSizes: [[720,480]] },
            textFields: [
                { name: 'title', label: 'Headline', max: 30, required: true },
                { name: 'description', label: 'Body', max: 90, required: true },
            ],
        },
        video: {
            label: 'Video',
            acceptedSizes: [],
            textFields: [],
        },
        popunder: {
            label: 'Popunder',
            acceptedSizes: [[1600,900],[900,1600]],
            textFields: [],
        },
    };

    // Country ISO2 → LibreTranslate language code (mirrors server COUNTRY_TO_LANG)
    const COUNTRY_TO_LANG = {
        US:'en',GB:'en',AU:'en',NZ:'en',CA:'en',IE:'en',ZA:'en',NG:'en',GH:'en',UG:'en',SG:'en',
        ES:'es',MX:'es',AR:'es',CO:'es',CL:'es',PE:'es',EC:'es',VE:'es',
        BR:'pt',PT:'pt',
        FR:'fr',BE:'fr',CI:'fr',CM:'fr',SN:'fr',MA:'fr',DZ:'fr',
        DE:'de',AT:'de',CH:'de',
        IT:'it',NL:'nl',
        JP:'ja',KR:'ko',CN:'zh',TW:'zh',HK:'zh',
        TH:'th',VN:'vi',ID:'id',PH:'tl',MY:'ms',
        IN:'hi',PK:'ur',BD:'bn',
        PL:'pl',CZ:'cs',RO:'ro',HU:'hu',BG:'bg',SK:'sk',UA:'uk',RS:'sr',HR:'en',
        SA:'ar',AE:'ar',EG:'ar',QA:'ar',KW:'ar',BH:'ar',OM:'ar',JO:'ar',LB:'ar',IL:'he',TR:'tr',
        KE:'sw',TZ:'sw',ET:'en',
        SE:'sv',NO:'nb',DK:'da',FI:'fi',
        LV:'lv',LT:'lt',EE:'et',
        RU:'ru',GR:'el',IR:'fa',
    };

    const LANG_NAMES = {
        en:'English',es:'Spanish',pt:'Portuguese',fr:'French',
        de:'German',it:'Italian',nl:'Dutch',ja:'Japanese',
        ko:'Korean',zh:'Chinese',th:'Thai',vi:'Vietnamese',
        id:'Indonesian',tl:'Tagalog',ms:'Malay',hi:'Hindi',
        ur:'Urdu',bn:'Bengali',pl:'Polish',cs:'Czech',
        ro:'Romanian',hu:'Hungarian',bg:'Bulgarian',sk:'Slovak',
        uk:'Ukrainian',sr:'Serbian',ar:'Arabic',he:'Hebrew',
        tr:'Turkish',sw:'Swahili',sv:'Swedish',nb:'Norwegian',
        da:'Danish',fi:'Finnish',lv:'Latvian',lt:'Lithuanian',
        et:'Estonian',ru:'Russian',el:'Greek',fa:'Persian',
    };

    function getCampaignLanguage(campaign) {
        const countries = campaign.countries || [];
        for (const cc of countries) {
            const lang = COUNTRY_TO_LANG[cc];
            if (lang && lang !== state.sourceLanguage) return lang;
        }
        return COUNTRY_TO_LANG[countries[0]] || 'en';
    }

    // Format category map (mirrors server config)
    const FORMAT_CATEGORY_MAP = {
        0:'banner',1:'banner',4:'banner',13:'banner',14:'banner',30:'banner',53:'banner',
        22:'native',33:'push',35:'push',21:'video',155:'video',7:'popunder',8:'popunder',
        32:'banner',34:'popunder',36:'popunder',
    };

    // =========================================================================
    // Wake Lock — keeps screen/network alive during batch submission
    // =========================================================================
    let wakeLock = null;

    async function acquireWakeLock() {
        if (!('wakeLock' in navigator)) return;
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            wakeLock.addEventListener('release', () => { wakeLock = null; });
        } catch (_) {
            // Wake Lock denied or unsupported — continue without it
        }
    }

    function releaseWakeLock() {
        if (wakeLock) {
            wakeLock.release();
            wakeLock = null;
        }
    }

    // Re-acquire wake lock when tab returns to foreground during submission
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && state.submitting) {
            acquireWakeLock();
        }
    });

    // Warn before closing/navigating away during submission
    function beforeUnloadGuard(e) {
        e.preventDefault();
        e.returnValue = '';
    }

    // =========================================================================
    // DOM refs
    // =========================================================================
    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    const dom = {
        tokenStatus:    $('#token-status'),
        apiTokenInput:  $('#api-token'),
        btnVerify:      $('#btn-verify'),
        authError:      $('#auth-error'),
        stepAuth:       $('#step-auth'),
        stepCampaigns:  $('#step-campaigns'),
        stepMetadata:   $('#step-metadata'),
        campaignSearch: $('#campaign-search'),
        formatFilter:   $('#format-filter'),   // multi-filter div
        groupFilter:    $('#group-filter'),    // multi-filter div
        geoFilter:      $('#geo-filter'),      // multi-filter div
        btnSyncGeos:    $('#btn-sync-geos'),
        geoSyncProgress:$('#geo-sync-progress'),
        geoSyncFill:    $('#geo-sync-fill'),
        geoSyncText:    $('#geo-sync-text'),
        campaignList:   $('#campaign-list'),
        selectedCount:  $('#selected-count'),
        selectedFormat: $('#selected-format'),
        dropZone:       $('#drop-zone'),
        fileInput:      $('#file-input'),
        fileGrid:       $('#file-grid'),
        btnNext:        $('#btn-next'),
        variationRows:  $('#variation-rows'),
        btnAddRow:      $('#btn-add-row'),
        btnSubmit:      $('#btn-submit'),
        progressSection:$('#progress-section'),
        progressFill:   $('#progress-fill'),
        progressText:   $('#progress-text'),
        resultLog:      $('#result-log'),
    };

    // =========================================================================
    // API helpers
    // =========================================================================
    // Detect base path from current page URL (works at /uploader/ or /)
    const BASE_PATH = window.location.pathname.replace(/\/+$/, '').replace(/\/index\.php$/, '') || '';

    async function api(url, options = {}) {
        const fullUrl = BASE_PATH + url;
        const defaults = {
            headers: { 'X-CSRF-Token': window.CSRF_TOKEN },
        };
        if (options.body && !(options.body instanceof FormData)) {
            defaults.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }
        const resp = await fetch(fullUrl, { ...defaults, ...options, headers: { ...defaults.headers, ...options.headers } });
        const data = await resp.json();
        if (!resp.ok) throw new Error(data.error || `HTTP ${resp.status}`);
        return data;
    }

    // =========================================================================
    // Step 1: Auth
    // =========================================================================
    dom.btnVerify.addEventListener('click', async () => {
        const token = dom.apiTokenInput.value.trim();
        if (!token) return;

        dom.btnVerify.disabled = true;
        dom.btnVerify.textContent = 'Verifying...';
        dom.authError.classList.add('hidden');

        try {
            await api('/api/auth', { method: 'POST', body: { api_token: token } });
            state.authenticated = true;
            dom.tokenStatus.textContent = 'Connected';
            dom.tokenStatus.className = 'token-status connected';
            dom.stepAuth.classList.remove('active');
            dom.stepAuth.classList.add('done');
            dom.stepCampaigns.classList.remove('locked');
            dom.stepCampaigns.classList.add('active');
            loadCampaigns();
            loadOffers();
        } catch (err) {
            dom.authError.textContent = err.message;
            dom.authError.classList.remove('hidden');
        } finally {
            dom.btnVerify.disabled = false;
            dom.btnVerify.textContent = 'Verify';
        }
    });

    dom.apiTokenInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') dom.btnVerify.click();
    });

    // =========================================================================
    // Step 2: Campaigns
    // =========================================================================
    // Multi-filter: renders clickable chips, returns Set of selected values
    function renderMultiFilter(container, items, selectedSet) {
        if (!items.length) {
            container.innerHTML = '';
            return;
        }
        const label = container.dataset.label || '';
        container.innerHTML = `<span class="mf-label">${esc(label)}:</span>` +
            items.map(it => {
                const active = selectedSet.has(it.value) ? ' active' : '';
                const cls = it.cls ? ` ${it.cls}` : '';
                return `<button type="button" class="mf-chip${active}${cls}" data-value="${esc(it.value)}">${esc(it.label)}</button>`;
            }).join('');
        container.querySelectorAll('.mf-chip').forEach(btn => {
            btn.addEventListener('click', () => {
                const v = btn.dataset.value;
                if (selectedSet.has(v)) selectedSet.delete(v);
                else selectedSet.add(v);
                btn.classList.toggle('active');
                renderCampaigns();
            });
        });
    }

    // Filter state: Sets of selected values (empty = show all)
    const activeFormats = new Set();
    const activeGroups = new Set();
    const activeGeos = new Set();

    function populateFormatFilter() {
        const formats = [
            { value: 'banner', label: 'Banner' },
            { value: 'native', label: 'Native' },
            { value: 'push', label: 'Push' },
            { value: 'video', label: 'Video' },
            { value: 'popunder', label: 'Popunder' },
        ];
        renderMultiFilter(dom.formatFilter, formats, activeFormats);
    }

    function populateGeoFilter() {
        const items = [
            ...Object.entries(GEO_REGIONS).map(([key, r]) => ({ value: key, label: r.label })),
            { value: '__unsynced__', label: 'Not Synced', cls: 'mf-muted' },
        ];
        renderMultiFilter(dom.geoFilter, items, activeGeos);
    }

    async function loadCampaigns() {
        dom.campaignList.innerHTML = '<div class="placeholder">Loading campaigns...</div>';
        try {
            const data = await api('/api/campaigns', { method: 'GET' });
            state.campaigns = data.campaigns || [];
            state.unsyncedIds = data.unsynced_ids || [];
            populateFormatFilter();
            populateGroupFilter();
            populateGeoFilter();
            updateSyncButton();
            renderCampaigns();
        } catch (err) {
            dom.campaignList.innerHTML = `<div class="placeholder" style="color:var(--error)">Error: ${esc(err.message)}</div>`;
        }
    }

    async function loadOffers() {
        try {
            const data = await api('/api/offers', { method: 'GET' });
            state.offers = data.offers || [];
            // Auto-select first offer if available
            if (state.offers.length > 0 && !state.selectedOfferId) {
                state.selectedOfferId = state.offers[0].id;
            }
        } catch (err) {
            console.error('Failed to load offers:', err);
        }
    }

    function updateSyncButton() {
        const unsynced = state.campaigns.filter(c => c.countries === null).length;
        if (unsynced > 0) {
            dom.btnSyncGeos.textContent = `Sync Geos (${unsynced})`;
            dom.btnSyncGeos.classList.remove('hidden');
        } else {
            dom.btnSyncGeos.textContent = 'Sync Geos';
        }
    }

    function populateGroupFilter() {
        const groups = [...new Set(state.campaigns.map(c => c.group_name).filter(Boolean))].sort();
        const items = groups.map(g => ({ value: g, label: g }));
        renderMultiFilter(dom.groupFilter, items, activeGroups);
    }

    function campaignMatchesRegion(campaign, regionKey) {
        if (!campaign.countries || !Array.isArray(campaign.countries)) return false;
        const region = GEO_REGIONS[regionKey];
        if (!region) return false;
        return campaign.countries.some(cc => region.countries.includes(cc));
    }

    function campaignPrimaryRegion(campaign) {
        if (!campaign.countries || !Array.isArray(campaign.countries) || campaign.countries.length === 0) {
            return campaign.countries === null ? '__unsynced__' : '__other__';
        }
        for (const [key, region] of Object.entries(GEO_REGIONS)) {
            if (campaign.countries.some(cc => region.countries.includes(cc))) return key;
        }
        return '__other__';
    }

    function campaignRegionBadge(campaign) {
        if (campaign.countries === null) {
            return '<span class="geo-badge geo-unsynced">not synced</span>';
        }
        if (campaign.countries.length === 0) {
            return '<span class="geo-badge geo-unassigned">worldwide</span>';
        }
        // Find matching regions
        const matched = [];
        for (const [key, region] of Object.entries(GEO_REGIONS)) {
            if (campaign.countries.some(cc => region.countries.includes(cc))) {
                matched.push({ key, label: region.label });
            }
        }
        if (matched.length === 0) {
            return `<span class="geo-badge geo-unassigned" title="${campaign.countries.join(', ')}">${campaign.countries.slice(0, 3).join(',')}${campaign.countries.length > 3 ? '...' : ''}</span>`;
        }
        if (matched.length === 1) {
            return `<span class="geo-badge geo-${esc(matched[0].key)}">${esc(matched[0].label)}</span>`;
        }
        // Multiple regions — show count with tooltip
        const labels = matched.map(m => m.label).join(', ');
        return `<span class="geo-badge geo-${esc(matched[0].key)}" title="${esc(labels)}">${matched.length} regions</span>`;
    }

    function renderCampaigns() {
        const search = dom.campaignSearch.value.toLowerCase();

        const filtered = state.campaigns.filter(c => {
            if (search && !c.name.toLowerCase().includes(search) && !String(c.id).includes(search)) return false;
            if (activeFormats.size > 0 && !activeFormats.has(c.format)) return false;
            if (activeGroups.size > 0 && !activeGroups.has(c.group_name)) return false;
            if (activeGeos.size > 0) {
                const region = campaignPrimaryRegion(c);
                if (!activeGeos.has(region)) return false;
            }
            return true;
        });

        if (filtered.length === 0) {
            dom.campaignList.innerHTML = '<div class="placeholder">No campaigns found</div>';
            return;
        }

        // Group campaigns by geo region
        const groups = {};
        filtered.forEach(c => {
            const rk = campaignPrimaryRegion(c);
            if (!groups[rk]) groups[rk] = [];
            groups[rk].push(c);
        });

        // Ordered region keys: defined regions first, then __other__, then __unsynced__
        const regionOrder = Object.keys(GEO_REGIONS);
        const orderedKeys = [
            ...regionOrder.filter(k => groups[k]),
            ...(['__other__', '__unsynced__'].filter(k => groups[k])),
        ];

        const regionLabels = {
            ...Object.fromEntries(Object.entries(GEO_REGIONS).map(([k, v]) => [k, v.label])),
            '__other__': 'Other Geos',
            '__unsynced__': 'Not Synced',
        };

        // Determine formats present in the filtered list
        const filteredFormats = new Set(filtered.map(c => c.format).filter(Boolean));
        const allSelectedInFiltered = filtered.length > 0 && filtered.every(c => state.selectedCampaignIds.has(c.id));
        const someSelectedInFiltered = filtered.some(c => state.selectedCampaignIds.has(c.id));
        // Select All is possible when filtered campaigns share one format (or match already-selected format)
        const canSelectAll = filtered.length > 0 && (
            filteredFormats.size === 1 ||
            (state.detectedFormat && filtered.every(c => c.format === state.detectedFormat))
        );

        // Select All / Deselect All bar
        let selectBar = '';
        if (filtered.length > 1) {
            selectBar = `<div class="campaign-select-bar">
                <span class="select-bar-info">${filtered.length} campaigns shown</span>
                <button type="button" class="btn btn-secondary btn-sm" id="btn-select-all-campaigns"
                    ${canSelectAll ? '' : 'disabled title="Filter to a single format first"'}>Select All (${filtered.length})</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btn-deselect-all-campaigns"
                    ${someSelectedInFiltered ? '' : 'disabled'}>Deselect All</button>
            </div>`;
        }

        dom.campaignList.innerHTML = selectBar + orderedKeys.map(rk => {
            const camps = groups[rk];
            const label = regionLabels[rk] || rk;
            const rows = camps.map(c => {
                const checked = state.selectedCampaignIds.has(c.id) ? 'checked' : '';
                const selected = state.selectedCampaignIds.has(c.id) ? 'selected' : '';
                const fmt = c.format || 'unknown';
                const geoBadge = campaignRegionBadge(c);
                const groupBadge = c.group_name
                    ? `<span class="group-badge">${esc(c.group_name)}</span>`
                    : '';
                return `<label class="campaign-item ${selected}" data-id="${c.id}" data-format="${fmt}">
                    <input type="checkbox" ${checked} value="${c.id}">
                    <span class="camp-name">${esc(c.name)}</span>
                    ${groupBadge}
                    ${geoBadge}
                    <span class="format-badge ${fmt}">${fmt}</span>
                    <span class="camp-id">#${c.id}</span>
                </label>`;
            }).join('');
            return `<div class="geo-group">
                <div class="geo-group-header">${esc(label)} <span class="geo-group-count">(${camps.length})</span></div>
                ${rows}
            </div>`;
        }).join('');

        // Bind clicks
        dom.campaignList.querySelectorAll('.campaign-item input').forEach(cb => {
            cb.addEventListener('change', (e) => {
                const id = parseInt(e.target.value, 10);
                const format = e.target.closest('.campaign-item').dataset.format;
                if (e.target.checked) {
                    // Enforce same-format selection
                    if (state.selectedCampaignIds.size > 0 && state.detectedFormat && state.detectedFormat !== format) {
                        e.target.checked = false;
                        alert('All selected campaigns must be the same format. Deselect existing campaigns first.');
                        return;
                    }
                    state.selectedCampaignIds.add(id);
                    state.detectedFormat = format;
                } else {
                    state.selectedCampaignIds.delete(id);
                    if (state.selectedCampaignIds.size === 0) state.detectedFormat = null;
                }
                updateSelectedInfo();
                updateNextButton();
                // Re-render to show selected state
                renderCampaigns();
            });
        });

        // Bind: Select All / Deselect All campaigns
        const btnSelectAll = dom.campaignList.querySelector('#btn-select-all-campaigns');
        const btnDeselectAll = dom.campaignList.querySelector('#btn-deselect-all-campaigns');
        if (btnSelectAll) {
            btnSelectAll.addEventListener('click', () => {
                // Determine the format to select (use existing or the single format in filtered list)
                const format = state.detectedFormat || [...filteredFormats][0];
                filtered.forEach(c => {
                    if (c.format === format) {
                        state.selectedCampaignIds.add(c.id);
                    }
                });
                state.detectedFormat = format;
                updateSelectedInfo();
                updateNextButton();
                renderCampaigns();
            });
        }
        if (btnDeselectAll) {
            btnDeselectAll.addEventListener('click', () => {
                filtered.forEach(c => {
                    state.selectedCampaignIds.delete(c.id);
                });
                if (state.selectedCampaignIds.size === 0) state.detectedFormat = null;
                updateSelectedInfo();
                updateNextButton();
                renderCampaigns();
            });
        }
    }

    function updateSelectedInfo() {
        dom.selectedCount.textContent = state.selectedCampaignIds.size;
        if (state.detectedFormat) {
            dom.selectedFormat.textContent = state.detectedFormat;
            dom.selectedFormat.className = `format-badge ${state.detectedFormat}`;
            dom.selectedFormat.classList.remove('hidden');
        } else {
            dom.selectedFormat.classList.add('hidden');
        }
    }

    function updateNextButton() {
        const hasCampaigns = state.selectedCampaignIds.size > 0;
        const hasFiles = state.uploadedFiles.some(f => f.status === 'uploaded');
        // For push format, we don't require pre-uploaded files (they upload per-row)
        const isPush = state.detectedFormat === 'push';
        dom.btnNext.disabled = !(hasCampaigns && (hasFiles || isPush));
    }

    dom.campaignSearch.addEventListener('input', renderCampaigns);

    // Sync Geos — batch-fetch country targeting from ExoClick
    dom.btnSyncGeos.addEventListener('click', async () => {
        const idsToSync = state.campaigns.filter(c => c.countries === null).map(c => c.id);
        if (idsToSync.length === 0) {
            alert('All campaigns are already synced.');
            return;
        }

        dom.btnSyncGeos.disabled = true;
        dom.geoSyncProgress.classList.remove('hidden');

        const batchSize = 30;
        const total = idsToSync.length;
        let done = 0;

        for (let i = 0; i < total; i += batchSize) {
            const batch = idsToSync.slice(i, i + batchSize);
            dom.geoSyncText.textContent = `${done} / ${total}`;
            dom.geoSyncFill.style.width = `${Math.round((done / total) * 100)}%`;

            try {
                const data = await api('/api/campaign-countries', {
                    method: 'POST',
                    body: { campaign_ids: batch },
                });
                // Update local campaign data with returned countries
                if (data.results) {
                    for (const [cid, countries] of Object.entries(data.results)) {
                        const camp = state.campaigns.find(c => String(c.id) === cid);
                        if (camp) camp.countries = countries;
                    }
                }
            } catch (err) {
                console.error('Geo sync batch failed:', err);
            }

            done += batch.length;
        }

        dom.geoSyncFill.style.width = '100%';
        dom.geoSyncText.textContent = `${total} / ${total} — done`;

        setTimeout(() => {
            dom.geoSyncProgress.classList.add('hidden');
        }, 2000);

        state.unsyncedIds = state.campaigns.filter(c => c.countries === null).map(c => c.id);
        updateSyncButton();
        renderCampaigns();
        dom.btnSyncGeos.disabled = false;
    });

    // =========================================================================
    // Step 2: File Upload
    // =========================================================================
    dom.dropZone.addEventListener('click', () => dom.fileInput.click());
    dom.dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dom.dropZone.classList.add('dragover'); });
    dom.dropZone.addEventListener('dragleave', () => dom.dropZone.classList.remove('dragover'));
    dom.dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dom.dropZone.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });
    dom.fileInput.addEventListener('change', (e) => {
        handleFiles(e.target.files);
        e.target.value = '';
    });

    function handleFiles(fileList) {
        const entries = [];
        for (const file of fileList) {
            const entry = {
                id: state.nextFileId++,
                file: file,
                objectUrl: URL.createObjectURL(file),
                filename: file.name,
                dimensions: null,
                fileId: null,       // ExoClick file_id after upload
                status: 'pending',  // pending, uploading, uploaded, error
                errors: [],
            };

            // Client-side validation
            const format = state.detectedFormat || 'banner';
            const clientErrors = validateFileClient(file, format);
            if (clientErrors.length > 0) {
                entry.status = 'error';
                entry.errors = clientErrors;
            }

            state.uploadedFiles.push(entry);
            entries.push(entry);
        }
        renderFileGrid();

        // Load dimensions for all entries, then upload valid ones concurrently
        const dimensionPromises = entries.map(entry => new Promise((resolve) => {
            const img = new Image();
            img.onload = () => {
                entry.dimensions = [img.naturalWidth, img.naturalHeight];
                renderFileGrid();
                resolve(entry);
            };
            img.onerror = () => {
                entry.status = 'error';
                entry.errors.push('Cannot read image');
                renderFileGrid();
                resolve(entry);
            };
            img.src = entry.objectUrl;
        }));

        Promise.all(dimensionPromises).then(loadedEntries => {
            const uploadable = loadedEntries.filter(e => e.status === 'pending');
            if (uploadable.length === 0) return;
            const tasks = uploadable.map(e => () => uploadFileToServer(e));
            runConcurrent(tasks, 4);
        });
    }

    function validateFileClient(file, format) {
        const errors = [];
        const spec = FORMAT_SPECS[format];
        if (!spec) return errors;

        // MIME
        const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowed.includes(file.type)) {
            errors.push(`Invalid type: ${file.type}`);
        }

        // No file size limit — ExoClick auto-resizes as needed

        return errors;
    }

    async function uploadFileToServer(entry) {
        entry.status = 'uploading';
        renderFileGrid();

        const format = state.detectedFormat || 'banner';
        const formData = new FormData();
        formData.append('file', entry.file);
        formData.append('format', format);

        try {
            const data = await api('/api/upload', {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-Token': window.CSRF_TOKEN },
            });
            entry.fileId = data.file_id;
            entry.dimensions = data.dimensions || entry.dimensions;
            entry.status = 'uploaded';
        } catch (err) {
            entry.status = 'error';
            entry.errors.push(err.message);
        }
        renderFileGrid();
        updateNextButton();
    }

    function renderFileGrid() {
        dom.fileGrid.innerHTML = state.uploadedFiles.map(f => {
            const statusClass = f.status === 'uploaded' ? 'success' : (f.status === 'error' ? 'error' : '');
            const badge = f.status === 'uploaded' ? '<div class="badge ok">&#10003;</div>' :
                          f.status === 'error' ? '<div class="badge fail">!</div>' : '';
            const dims = f.dimensions ? `${f.dimensions[0]}x${f.dimensions[1]}` : '';
            const overlay = f.status === 'uploading' ? '<div class="uploading-overlay"><div class="spinner"></div></div>' : '';
            const errorTip = f.errors.length ? `<div class="error-tooltip">${esc(f.errors[0])}</div>` : '';

            return `<div class="file-card ${statusClass}" data-file-id="${f.id}">
                <button class="remove-btn" data-remove="${f.id}">&times;</button>
                ${badge}
                <img class="thumb" src="${f.objectUrl}" alt="${esc(f.filename)}">
                <div class="fname" title="${esc(f.filename)}">${esc(f.filename)}</div>
                <div class="fdims">${dims}</div>
                ${errorTip}
                ${overlay}
            </div>`;
        }).join('');

        // Remove buttons
        dom.fileGrid.querySelectorAll('.remove-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = parseInt(btn.dataset.remove, 10);
                const idx = state.uploadedFiles.findIndex(f => f.id === id);
                if (idx !== -1) {
                    URL.revokeObjectURL(state.uploadedFiles[idx].objectUrl);
                    state.uploadedFiles.splice(idx, 1);
                }
                renderFileGrid();
                updateNextButton();
            });
        });
    }

    // =========================================================================
    // Step 2 → Step 3 transition
    // =========================================================================
    dom.btnNext.addEventListener('click', () => {
        dom.stepCampaigns.classList.remove('active');
        dom.stepCampaigns.classList.add('done');
        dom.stepMetadata.classList.remove('locked');
        dom.stepMetadata.classList.add('active');

        const format = state.detectedFormat || 'banner';
        if (format === 'push') {
            // Push keeps per-row approach
            state.variationRows = [];
            addVariationRow();
        } else {
            // Native/Banner/Popunder: shared text + multi-image select
            state.sharedTextFields = {};
            state.cropAnchorPoint = 0;
            // Pre-select all successfully uploaded images
            state.selectedFileIds = new Set(
                state.uploadedFiles.filter(f => f.status === 'uploaded').map(f => f.fileId)
            );
        }
        renderMetadataStep();
    });

    // =========================================================================
    // Step 3: Metadata
    // =========================================================================

    // Crop anchor point options for native format
    const CROP_ANCHORS = [
        { id: 0, label: 'Center' },
        { id: 1, label: 'Top Left' },
        { id: 2, label: 'Top Center' },
        { id: 3, label: 'Top Right' },
        { id: 4, label: 'Middle Left' },
        { id: 5, label: 'Middle Right' },
        { id: 6, label: 'Bottom Left' },
        { id: 7, label: 'Bottom Center' },
        { id: 8, label: 'Bottom Right' },
    ];

    function addVariationRow() {
        state.variationRows.push({
            id: state.nextRowId++,
            iconFileId: null,
            imageFileId: null,
            textFields: {},
        });
    }

    dom.btnAddRow.addEventListener('click', () => {
        const format = state.detectedFormat || 'banner';
        if (format === 'push') {
            addVariationRow();
        }
        renderMetadataStep();
    });

    /**
     * Render the offer (landing page group) dropdown.
     */
    function renderOfferDropdown() {
        if (state.offers.length === 0) return '';
        const options = state.offers.map(o => {
            const lpCount = o.landing_pages ? o.landing_pages.length : 0;
            const selected = state.selectedOfferId === o.id ? 'selected' : '';
            return `<option value="${o.id}" ${selected}>${esc(o.name)} (${lpCount} LPs)</option>`;
        }).join('');
        return `<div class="offer-select-section">
            <div class="field-group">
                <label>Landing Page Group</label>
                <select id="offer-select" class="offer-select">
                    <option value="">Auto-detect from campaign</option>
                    ${options}
                </select>
            </div>
        </div>`;
    }

    /**
     * Bind the offer dropdown change event.
     */
    function bindOfferDropdown() {
        const sel = document.getElementById('offer-select');
        if (sel) {
            sel.addEventListener('change', () => {
                state.selectedOfferId = sel.value ? parseInt(sel.value, 10) : null;
            });
        }
    }

    /**
     * Render the metadata step — dispatches to the right layout based on format.
     */
    function renderMetadataStep() {
        const format = state.detectedFormat || 'banner';
        if (format === 'push') {
            dom.btnAddRow.classList.remove('hidden');
            renderPushRows();
        } else {
            dom.btnAddRow.classList.add('hidden');
            renderSharedMetadata();
        }
        bindOfferDropdown();
    }

    /**
     * Native / Banner / Popunder: shared text fields + multi-image checkbox grid.
     */
    function renderSharedMetadata() {
        const format = state.detectedFormat || 'banner';
        const spec = FORMAT_SPECS[format];
        if (!spec) return;

        const uploadedOk = state.uploadedFiles.filter(f => f.status === 'uploaded');
        let html = '';

        // Offer (landing page group) selector
        html += renderOfferDropdown();

        // Shared text fields (native has title/description/brand)
        const textFields = spec.textFields || [];
        if (textFields.length > 0) {
            html += '<div class="shared-fields-section">';
            html += '<h4>Ad Copy (applies to all selected images)</h4>';

            // Translation controls — show when campaigns have non-source-language geos
            const hasTranslatableGeos = getUniqueTargetLanguages().length > 0;
            if (hasTranslatableGeos) {
                const sourceLangOpts = Object.entries(LANG_NAMES)
                    .filter(([code]) => ['en','es','pt','fr','de'].includes(code))
                    .map(([code, name]) => `<option value="${code}" ${state.sourceLanguage === code ? 'selected' : ''}>${esc(name)}</option>`)
                    .join('');
                html += `<div class="translation-controls">
                    <label>Source:</label>
                    <select id="source-lang">${sourceLangOpts}</select>
                    <button class="btn btn-secondary" id="btn-translate">Translate for Campaign Geos</button>
                    <span id="translate-status"></span>
                </div>`;
            }

            html += '<div class="fields">';
            textFields.forEach(tf => {
                const val = state.sharedTextFields[tf.name] || '';
                const count = val.length;
                const overClass = count > tf.max ? 'over' : '';
                html += `<div class="field-group">
                    <label>${esc(tf.label)}${tf.required ? ' *' : ''}</label>
                    <input type="text" class="shared-text-field" data-field="${tf.name}" data-max="${tf.max}"
                           value="${esc(val)}" placeholder="${esc(tf.label)}" maxlength="${tf.max + 10}">
                    <div class="char-count ${overClass}">${count}/${tf.max}</div>
                </div>`;
            });

            // Crop anchor point dropdown (native only)
            if (format === 'native') {
                html += `<div class="field-group">
                    <label>Crop Anchor Point</label>
                    <select id="crop-anchor-select" class="crop-anchor-select">
                        ${CROP_ANCHORS.map(a =>
                            `<option value="${a.id}" ${state.cropAnchorPoint === a.id ? 'selected' : ''}>${esc(a.label)}</option>`
                        ).join('')}
                    </select>
                </div>`;
            }

            html += '</div></div>';

            // Translation review panel (if translations exist)
            html += renderTranslationPanel(textFields);
        }

        // Banner: optional landing URL (shared)
        if (format === 'banner') {
            const val = state.sharedTextFields['landing_url'] || '';
            html += `<div class="shared-fields-section">
                <div class="fields">
                    <div class="field-group full-width">
                        <label>Landing URL (optional, applies to all)</label>
                        <input type="text" class="shared-text-field" data-field="landing_url"
                               value="${esc(val)}" placeholder="https://...">
                    </div>
                </div>
            </div>`;
        }

        // Image selection grid
        html += '<div class="image-select-section">';
        html += `<h4>Select Images (${state.selectedFileIds.size} of ${uploadedOk.length} selected)</h4>`;
        html += '<div class="image-select-grid">';
        uploadedOk.forEach(f => {
            const checked = state.selectedFileIds.has(f.fileId);
            html += `<label class="image-select-card ${checked ? 'selected' : ''}" data-fid="${f.fileId}">
                <input type="checkbox" ${checked ? 'checked' : ''} data-fid="${f.fileId}">
                <img src="${f.objectUrl}" alt="${esc(f.filename)}">
                <div class="fname">${esc(f.filename)}</div>
                <div class="fdims">${f.dimensions ? f.dimensions[0] + 'x' + f.dimensions[1] : ''}</div>
            </label>`;
        });
        html += '</div>';

        // Select all / none buttons
        if (uploadedOk.length > 1) {
            html += `<div class="select-actions">
                <button class="btn btn-secondary btn-sm" id="btn-select-all">Select All</button>
                <button class="btn btn-secondary btn-sm" id="btn-select-none">Select None</button>
            </div>`;
        }
        html += '</div>';

        dom.variationRows.innerHTML = html;

        // Bind: shared text fields
        dom.variationRows.querySelectorAll('.shared-text-field').forEach(input => {
            input.addEventListener('input', () => {
                const field = input.dataset.field;
                const max = parseInt(input.dataset.max, 10);
                state.sharedTextFields[field] = input.value;
                const counter = input.parentElement.querySelector('.char-count');
                if (counter && max) {
                    counter.textContent = `${input.value.length}/${max}`;
                    counter.className = input.value.length > max ? 'char-count over' : 'char-count';
                }
            });
        });

        // Bind: source language dropdown
        const sourceLangSel = dom.variationRows.querySelector('#source-lang');
        if (sourceLangSel) {
            sourceLangSel.addEventListener('change', () => {
                state.sourceLanguage = sourceLangSel.value;
                // Clear existing translations when source changes
                state.translations = {};
                state.translatedFields = {};
                renderSharedMetadata();
            });
        }

        // Bind: translate button
        const btnTranslate = dom.variationRows.querySelector('#btn-translate');
        if (btnTranslate) {
            btnTranslate.addEventListener('click', () => handleTranslateClick('native'));
        }

        // Bind: translation panel editable fields
        dom.variationRows.querySelectorAll('.translation-field').forEach(input => {
            input.addEventListener('input', () => {
                const lang = input.dataset.lang;
                const field = input.dataset.field;
                const max = parseInt(input.dataset.max, 10);
                if (!state.translatedFields[lang]) state.translatedFields[lang] = {};
                state.translatedFields[lang][field] = input.value;
                const counter = input.parentElement.querySelector('.char-count');
                if (counter && max) {
                    counter.textContent = `${input.value.length}/${max}`;
                    counter.className = input.value.length > max ? 'char-count over' : 'char-count';
                }
            });
        });

        // Bind: crop anchor dropdown
        const cropSelect = dom.variationRows.querySelector('#crop-anchor-select');
        if (cropSelect) {
            cropSelect.addEventListener('change', () => {
                state.cropAnchorPoint = parseInt(cropSelect.value, 10);
            });
        }

        // Bind: image checkboxes
        dom.variationRows.querySelectorAll('.image-select-card input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', () => {
                const fid = cb.dataset.fid;
                if (cb.checked) {
                    state.selectedFileIds.add(fid);
                } else {
                    state.selectedFileIds.delete(fid);
                }
                renderSharedMetadata();
            });
        });

        // Bind: select all / none
        const btnAll = dom.variationRows.querySelector('#btn-select-all');
        const btnNone = dom.variationRows.querySelector('#btn-select-none');
        if (btnAll) {
            btnAll.addEventListener('click', () => {
                state.selectedFileIds = new Set(uploadedOk.map(f => f.fileId));
                renderSharedMetadata();
            });
        }
        if (btnNone) {
            btnNone.addEventListener('click', () => {
                state.selectedFileIds.clear();
                renderSharedMetadata();
            });
        }
    }

    // =========================================================================
    // Translation helpers
    // =========================================================================

    /**
     * Get unique target languages from selected campaigns (excluding source language).
     */
    function getUniqueTargetLanguages() {
        const langs = new Set();
        for (const campId of state.selectedCampaignIds) {
            const camp = state.campaigns.find(c => c.id === campId);
            if (!camp) continue;
            const lang = getCampaignLanguage(camp);
            if (lang !== state.sourceLanguage) langs.add(lang);
        }
        return [...langs];
    }

    /**
     * Group selected campaigns by their target language.
     * Returns: {langCode: [campaign, ...]}
     */
    function groupCampaignsByLanguage() {
        const langCampaigns = {};
        for (const campId of state.selectedCampaignIds) {
            const camp = state.campaigns.find(c => c.id === campId);
            if (!camp) continue;
            const lang = getCampaignLanguage(camp);
            if (lang === state.sourceLanguage) continue;
            if (!langCampaigns[lang]) langCampaigns[lang] = [];
            langCampaigns[lang].push(camp);
        }
        return langCampaigns;
    }

    /**
     * Render the translation review panel for native/banner formats.
     * Shows editable translated text per unique target language.
     */
    function renderTranslationPanel(textFields) {
        if (Object.keys(state.translatedFields).length === 0) return '';

        const langCampaigns = groupCampaignsByLanguage();
        if (Object.keys(langCampaigns).length === 0) return '';

        let html = '<div class="translation-panel">';
        html += '<h4>Translations</h4>';

        for (const [lang, campaigns] of Object.entries(langCampaigns)) {
            const langName = LANG_NAMES[lang] || lang;
            const campNames = campaigns.map(c => c.name).join(', ');
            const fields = state.translatedFields[lang] || {};

            html += `<div class="translation-lang-section">
                <div class="translation-lang-header">
                    <span class="translation-lang-name">${esc(langName)}</span>
                    <span class="translation-camp-list" title="${esc(campNames)}">${campaigns.length} campaign${campaigns.length > 1 ? 's' : ''}</span>
                </div>`;

            textFields.forEach(tf => {
                const val = fields[tf.name] || '';
                const count = val.length;
                const overClass = count > tf.max ? 'over' : '';
                html += `<div class="field-group">
                    <label>${esc(tf.label)}</label>
                    <input type="text" class="translation-field" data-lang="${lang}" data-field="${tf.name}" data-max="${tf.max}"
                           value="${esc(val)}" placeholder="${esc(tf.label)} (${esc(langName)})" maxlength="${tf.max + 10}">
                    <div class="char-count ${overClass}">${count}/${tf.max}</div>
                </div>`;
            });

            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    /**
     * Render translation review panel for push format (per-row translations).
     */
    function renderPushTranslationPanel(textFields) {
        if (Object.keys(state.translatedFields).length === 0) return '';

        const langCampaigns = groupCampaignsByLanguage();
        if (Object.keys(langCampaigns).length === 0) return '';

        let html = '<div class="translation-panel">';
        html += '<h4>Translations</h4>';

        for (const [lang, campaigns] of Object.entries(langCampaigns)) {
            const langName = LANG_NAMES[lang] || lang;
            const campNames = campaigns.map(c => c.name).join(', ');
            const rowFields = state.translatedFields[lang] || [];

            html += `<div class="translation-lang-section">
                <div class="translation-lang-header">
                    <span class="translation-lang-name">${esc(langName)}</span>
                    <span class="translation-camp-list" title="${esc(campNames)}">${campaigns.length} campaign${campaigns.length > 1 ? 's' : ''}</span>
                </div>`;

            state.variationRows.forEach((row, rIdx) => {
                const rowData = rowFields[rIdx] || {};
                html += `<div class="translation-row-header">Creative #${rIdx + 1}</div>`;
                textFields.forEach(tf => {
                    const val = rowData[tf.name] || '';
                    const count = val.length;
                    const overClass = count > tf.max ? 'over' : '';
                    html += `<div class="field-group">
                        <label>${esc(tf.label)}</label>
                        <input type="text" class="push-translation-field" data-lang="${lang}" data-row-idx="${rIdx}" data-field="${tf.name}" data-max="${tf.max}"
                               value="${esc(val)}" placeholder="${esc(tf.label)} (${esc(langName)})" maxlength="${tf.max + 10}">
                        <div class="char-count ${overClass}">${count}/${tf.max}</div>
                    </div>`;
                });
            });

            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    /**
     * Handle the Translate button click. Sends texts to /api/translate,
     * stores results, and re-renders.
     * @param {string} mode - 'native' or 'push'
     */
    async function handleTranslateClick(mode) {
        const targetLangs = getUniqueTargetLanguages();
        if (targetLangs.length === 0) {
            alert('All selected campaigns target the source language. No translation needed.');
            return;
        }

        const format = state.detectedFormat || 'banner';
        const spec = FORMAT_SPECS[format];
        const textFieldDefs = spec?.textFields || [];
        if (textFieldDefs.length === 0) return;

        // Collect texts as a flat array
        let texts = [];
        let fieldNames = [];
        if (mode === 'push') {
            // Push: flatten all rows' text fields
            state.variationRows.forEach(row => {
                textFieldDefs.forEach(tf => {
                    texts.push(row.textFields[tf.name] || '');
                    fieldNames.push(tf.name);
                });
            });
        } else {
            // Native/banner: shared text fields
            textFieldDefs.forEach(tf => {
                texts.push(state.sharedTextFields[tf.name] || '');
                fieldNames.push(tf.name);
            });
        }

        // Check that there's something to translate
        if (texts.every(t => !t.trim())) {
            alert('Please fill in the text fields before translating.');
            return;
        }

        const statusEl = document.getElementById('translate-status');
        if (statusEl) statusEl.textContent = 'Translating...';

        try {
            const data = await api('/api/translate', {
                method: 'POST',
                body: {
                    texts: texts,
                    source: state.sourceLanguage,
                    targets: targetLangs,
                },
            });

            state.translations = data.translations || {};

            // Map flat array results back to field names
            if (mode === 'push') {
                // Push: {langCode: [{title, description}, {title, description}, ...]}
                const fieldsPerRow = textFieldDefs.length;
                for (const [lang, translated] of Object.entries(state.translations)) {
                    const rows = [];
                    for (let r = 0; r < state.variationRows.length; r++) {
                        const rowObj = {};
                        textFieldDefs.forEach((tf, fi) => {
                            rowObj[tf.name] = translated[r * fieldsPerRow + fi] || '';
                        });
                        rows.push(rowObj);
                    }
                    if (!state.translatedFields[lang]) state.translatedFields[lang] = rows;
                    else {
                        // Only overwrite if user hasn't edited
                        state.translatedFields[lang] = rows;
                    }
                }
            } else {
                // Native: {langCode: {title, description, brand}}
                for (const [lang, translated] of Object.entries(state.translations)) {
                    const obj = {};
                    textFieldDefs.forEach((tf, i) => {
                        obj[tf.name] = translated[i] || '';
                    });
                    state.translatedFields[lang] = obj;
                }
            }

            if (statusEl) statusEl.textContent = `Translated to ${targetLangs.length} language${targetLangs.length > 1 ? 's' : ''}`;
        } catch (err) {
            if (statusEl) statusEl.textContent = `Translation failed: ${err.message}`;
            console.error('Translation error:', err);
        }

        // Re-render to show translation panel
        renderMetadataStep();
    }

    /**
     * Push format: per-row layout (icon + image upload + text fields per row).
     */
    function renderPushRows() {
        const spec = FORMAT_SPECS['push'];
        const textFields = spec.textFields || [];

        // Translation controls for push format
        let translationControlsHtml = '';
        const hasTranslatableGeos = getUniqueTargetLanguages().length > 0;
        if (hasTranslatableGeos && textFields.length > 0) {
            const sourceLangOpts = Object.entries(LANG_NAMES)
                .filter(([code]) => ['en','es','pt','fr','de'].includes(code))
                .map(([code, name]) => `<option value="${code}" ${state.sourceLanguage === code ? 'selected' : ''}>${esc(name)}</option>`)
                .join('');
            translationControlsHtml = `<div class="translation-controls">
                <label>Source:</label>
                <select id="source-lang">${sourceLangOpts}</select>
                <button class="btn btn-secondary" id="btn-translate">Translate for Campaign Geos</button>
                <span id="translate-status"></span>
            </div>`;
        }

        dom.variationRows.innerHTML = renderOfferDropdown() + translationControlsHtml + state.variationRows.map((row, idx) => {
            let fieldsHtml = '';

            // Push: icon + image upload inline
            fieldsHtml += `<div class="field-group full-width">
                <div class="push-upload-section">
                    <div class="push-slot" data-row="${row.id}" data-slot="icon">
                        <h5>Icon (192x192)</h5>
                        ${row.iconFileId
                            ? `<div style="color:var(--success);font-size:12px">Uploaded &#10003;</div>`
                            : `<div class="dims">Click to upload (max 50KB)</div>`}
                        <input type="file" class="hidden push-file-input" data-row="${row.id}" data-slot="icon" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <div class="push-slot" data-row="${row.id}" data-slot="image">
                        <h5>Image (720x480)</h5>
                        ${row.imageFileId
                            ? `<div style="color:var(--success);font-size:12px">Uploaded &#10003;</div>`
                            : `<div class="dims">Click to upload (max 300KB)</div>`}
                        <input type="file" class="hidden push-file-input" data-row="${row.id}" data-slot="image" accept="image/jpeg,image/png,image/webp">
                    </div>
                </div>
            </div>`;

            // Text fields
            textFields.forEach(tf => {
                const val = row.textFields[tf.name] || '';
                const count = val.length;
                const overClass = count > tf.max ? 'over' : '';
                fieldsHtml += `<div class="field-group">
                    <label>${esc(tf.label)}${tf.required ? ' *' : ''}</label>
                    <input type="text" class="text-field" data-row="${row.id}" data-field="${tf.name}" data-max="${tf.max}"
                           value="${esc(val)}" placeholder="${esc(tf.label)}" maxlength="${tf.max + 10}">
                    <div class="char-count ${overClass}">${count}/${tf.max}</div>
                </div>`;
            });

            const canRemove = state.variationRows.length > 1;

            return `<div class="variation-row" data-row-id="${row.id}">
                <div class="row-header">
                    <h4>Creative #${idx + 1}</h4>
                    ${canRemove ? `<button class="btn btn-danger remove-row" data-row="${row.id}">&times; Remove</button>` : ''}
                </div>
                <div class="fields">${fieldsHtml}</div>
            </div>`;
        }).join('') + renderPushTranslationPanel(textFields);

        // Bind: source language dropdown (push)
        const sourceLangSel = dom.variationRows.querySelector('#source-lang');
        if (sourceLangSel) {
            sourceLangSel.addEventListener('change', () => {
                state.sourceLanguage = sourceLangSel.value;
                state.translations = {};
                state.translatedFields = {};
                renderPushRows();
            });
        }

        // Bind: translate button (push)
        const btnTranslate = dom.variationRows.querySelector('#btn-translate');
        if (btnTranslate) {
            btnTranslate.addEventListener('click', () => handleTranslateClick('push'));
        }

        // Bind: push translation panel editable fields
        dom.variationRows.querySelectorAll('.push-translation-field').forEach(input => {
            input.addEventListener('input', () => {
                const lang = input.dataset.lang;
                const rowIdx = parseInt(input.dataset.rowIdx, 10);
                const field = input.dataset.field;
                const max = parseInt(input.dataset.max, 10);
                if (!state.translatedFields[lang]) state.translatedFields[lang] = [];
                if (!state.translatedFields[lang][rowIdx]) state.translatedFields[lang][rowIdx] = {};
                state.translatedFields[lang][rowIdx][field] = input.value;
                const counter = input.parentElement.querySelector('.char-count');
                if (counter && max) {
                    counter.textContent = `${input.value.length}/${max}`;
                    counter.className = input.value.length > max ? 'char-count over' : 'char-count';
                }
            });
        });

        // Bind: text fields
        dom.variationRows.querySelectorAll('.text-field').forEach(input => {
            input.addEventListener('input', () => {
                const rowId = parseInt(input.dataset.row, 10);
                const field = input.dataset.field;
                const max = parseInt(input.dataset.max, 10);
                const row = state.variationRows.find(r => r.id === rowId);
                if (row) {
                    row.textFields[field] = input.value;
                }
                const counter = input.parentElement.querySelector('.char-count');
                if (counter && max) {
                    counter.textContent = `${input.value.length}/${max}`;
                    counter.className = input.value.length > max ? 'char-count over' : 'char-count';
                }
            });
        });

        // Bind: remove row
        dom.variationRows.querySelectorAll('.remove-row').forEach(btn => {
            btn.addEventListener('click', () => {
                const rowId = parseInt(btn.dataset.row, 10);
                state.variationRows = state.variationRows.filter(r => r.id !== rowId);
                renderMetadataStep();
            });
        });

        // Bind: push file uploads
        dom.variationRows.querySelectorAll('.push-slot').forEach(slot => {
            slot.addEventListener('click', () => {
                const input = slot.querySelector('.push-file-input');
                if (input) input.click();
            });
        });

        dom.variationRows.querySelectorAll('.push-file-input').forEach(input => {
            input.addEventListener('click', (e) => e.stopPropagation());
            input.addEventListener('change', async (e) => {
                const file = e.target.files[0];
                if (!file) return;
                const rowId = parseInt(input.dataset.row, 10);
                const slot = input.dataset.slot;
                const row = state.variationRows.find(r => r.id === rowId);
                if (!row) return;

                const formData = new FormData();
                formData.append('file', file);
                formData.append('format', 'push');
                formData.append('push_slot', slot);

                try {
                    const data = await api('/api/upload', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-CSRF-Token': window.CSRF_TOKEN },
                    });
                    if (slot === 'icon') {
                        row.iconFileId = data.file_id;
                    } else {
                        row.imageFileId = data.file_id;
                    }
                } catch (err) {
                    alert(`Upload failed: ${err.message}`);
                }
                renderMetadataStep();
                e.target.value = '';
            });
        });
    }

    // =========================================================================
    // Step 3: Submit
    // =========================================================================
    dom.btnSubmit.addEventListener('click', async () => {
        const format = state.detectedFormat || 'banner';
        const campaignIds = Array.from(state.selectedCampaignIds);
        let variations;

        if (format === 'push') {
            // Push: per-row variations
            variations = state.variationRows.map(row => ({
                text_fields: row.textFields,
                icon_file_id: row.iconFileId,
                image_file_id: row.imageFileId,
            }));
        } else {
            // Native/Banner/Popunder: one variation per selected image, shared text
            const selectedFiles = Array.from(state.selectedFileIds);
            if (selectedFiles.length === 0) {
                alert('Please select at least one image');
                return;
            }
            variations = selectedFiles.map(fileId => {
                const v = {
                    text_fields: { ...state.sharedTextFields },
                    file_id: fileId,
                };
                if (format === 'native') {
                    v.crop_anchor_point = state.cropAnchorPoint;
                }
                return v;
            });
        }

        // Client-side validation
        const spec = FORMAT_SPECS[format];
        const requiredFields = (spec?.textFields || []).filter(tf => tf.required);
        if (requiredFields.length > 0 && format !== 'push') {
            for (const tf of requiredFields) {
                const val = (state.sharedTextFields[tf.name] || '').trim();
                if (!val) {
                    alert(`${tf.label} is required`);
                    return;
                }
            }
        }

        for (let i = 0; i < variations.length; i++) {
            const v = variations[i];
            if (format === 'push' && !v.icon_file_id) {
                alert(`Creative #${i+1}: Icon image is required for push`);
                return;
            }
        }

        const total = campaignIds.length * variations.length;

        // Prevent screen sleep and accidental tab close during batch
        state.submitting = true;
        await acquireWakeLock();
        window.addEventListener('beforeunload', beforeUnloadGuard);

        dom.btnSubmit.disabled = true;
        dom.btnSubmit.textContent = 'Creating...';
        dom.progressSection.classList.remove('hidden');
        dom.resultLog.classList.remove('hidden');
        dom.resultLog.innerHTML = '';
        dom.progressFill.style.width = '0%';
        dom.progressText.textContent = `0 / ${total}`;

        let completed = 0;
        let successCount = 0;
        let failCount = 0;

        // Build a campaign name lookup for log messages
        const campNameMap = {};
        for (const c of state.campaigns) campNameMap[c.id] = c.name;

        // Single batch request with SSE streaming
        const body = {
            campaign_ids: campaignIds,
            variations: variations,
            format: format,
        };
        if (state.selectedOfferId) {
            body.idoffer = state.selectedOfferId;
        }

        // Build per-campaign text overrides from translations
        if (Object.keys(state.translatedFields).length > 0) {
            const overrides = {};
            for (const campId of campaignIds) {
                const camp = state.campaigns.find(c => c.id === campId);
                if (!camp) continue;
                const lang = getCampaignLanguage(camp);
                if (lang === state.sourceLanguage || !state.translatedFields[lang]) continue;

                if (format === 'push') {
                    // Push: state.translatedFields[lang] is already per-row array [{title,description}, ...]
                    overrides[campId] = state.translatedFields[lang];
                } else {
                    // Native/banner: state.translatedFields[lang] is {title,description,brand}
                    // Replicate for each variation
                    overrides[campId] = variations.map(() => ({...state.translatedFields[lang]}));
                }
            }
            if (Object.keys(overrides).length > 0) {
                body.campaign_text_overrides = overrides;
            }
        }

        try {
            const fullUrl = BASE_PATH + '/api/create-variations-batch';
            const resp = await fetch(fullUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.CSRF_TOKEN,
                },
                body: JSON.stringify(body),
            });

            if (!resp.ok) {
                const errData = await resp.json().catch(() => ({}));
                throw new Error(errData.error || `HTTP ${resp.status}`);
            }

            // Read SSE stream
            const reader = resp.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                // Parse SSE events from buffer
                let eventEnd;
                while ((eventEnd = buffer.indexOf('\n\n')) !== -1) {
                    const eventBlock = buffer.substring(0, eventEnd);
                    buffer = buffer.substring(eventEnd + 2);

                    // Extract data lines
                    const dataLines = eventBlock.split('\n')
                        .filter(l => l.startsWith('data: '))
                        .map(l => l.substring(6));

                    for (const line of dataLines) {
                        let event;
                        try { event = JSON.parse(line); } catch (_) { continue; }

                        if (event.type === 'complete') {
                            // Final summary
                            successCount = event.success_count;
                            failCount = event.fail_count;
                        } else {
                            // Per-variation progress
                            const campName = campNameMap[event.campaign_id] || event.campaign_id;
                            const vNum = event.variation_idx + 1;
                            if (event.success) {
                                logEntry(`OK — creative #${vNum} on "${campName}" (#${event.campaign_id}) → ${event.variation_id}`, 'success');
                                successCount++;
                            } else {
                                logEntry(`FAILED — creative #${vNum} on "${campName}" (#${event.campaign_id}): ${event.error}`, 'error');
                                failCount++;
                            }
                            completed = event.progress_num;
                            const pct = Math.round((completed / total) * 100);
                            dom.progressFill.style.width = `${pct}%`;
                            dom.progressText.textContent = `${completed} / ${total}`;
                        }
                    }
                }
            }
        } catch (err) {
            logEntry(`Batch request failed: ${err.message}`, 'error');
            failCount = total - successCount;
        }

        logEntry('', 'info');
        logEntry(`Done. ${successCount} succeeded, ${failCount} failed out of ${total} total.`,
                 failCount > 0 ? 'warn' : 'success');

        // Release wake lock and navigation guard
        state.submitting = false;
        releaseWakeLock();
        window.removeEventListener('beforeunload', beforeUnloadGuard);

        dom.btnSubmit.disabled = false;
        dom.btnSubmit.textContent = 'Create Variations';
    });

    function logEntry(text, cls) {
        const div = document.createElement('div');
        div.className = `log-entry ${cls}`;
        div.textContent = text;
        dom.resultLog.appendChild(div);
        dom.resultLog.scrollTop = dom.resultLog.scrollHeight;
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    /**
     * Run async tasks with bounded concurrency.
     * @param {Array<() => Promise>} tasks - Array of functions that return promises
     * @param {number} maxConcurrent - Max simultaneous tasks
     * @returns {Promise<Array>} Results in same order as input tasks
     */
    function runConcurrent(tasks, maxConcurrent = 4) {
        return new Promise((resolve) => {
            const results = new Array(tasks.length);
            let nextIdx = 0;
            let completed = 0;

            function runNext() {
                if (nextIdx >= tasks.length) return;
                const idx = nextIdx++;
                tasks[idx]().then(val => {
                    results[idx] = val;
                }).catch(err => {
                    results[idx] = err;
                }).finally(() => {
                    completed++;
                    if (completed === tasks.length) {
                        resolve(results);
                    } else {
                        runNext();
                    }
                });
            }

            const initial = Math.min(maxConcurrent, tasks.length);
            for (let i = 0; i < initial; i++) runNext();
            if (tasks.length === 0) resolve([]);
        });
    }

    function esc(str) {
        const el = document.createElement('span');
        el.textContent = str || '';
        return el.innerHTML;
    }

    // =========================================================================
    // Render static filters immediately (format + geo are static; group needs campaign data)
    populateFormatFilter();
    populateGeoFilter();

    // Auto-auth from server environment
    // =========================================================================
    if (window.AUTO_AUTH) {
        state.authenticated = true;
        dom.tokenStatus.textContent = 'Connected';
        dom.tokenStatus.className = 'token-status connected';
        dom.stepAuth.classList.remove('active');
        dom.stepAuth.classList.add('done');
        dom.stepCampaigns.classList.remove('locked');
        dom.stepCampaigns.classList.add('active');
        loadCampaigns();
        loadOffers();
    }
})();
