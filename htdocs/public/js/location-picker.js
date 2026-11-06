/**
 * Arnis Connect - shared location picker (Leaflet).
 *
 * Gives every form a click-to-pin map with a place search box on top:
 *   - View : SATELLITE by default (Esri World Imagery) so venues can be
 *            pinned to the exact building/court, with a Satellite/Streets
 *            toggle bottom-left. All Esri tiles, no API key, and not OSM's
 *            volunteer servers, so no "Access blocked" page.
 *   - Search: queries THREE free indexes in parallel, merges + de-duplicates:
 *              1. Nominatim (OpenStreetMap) - biased toward the Philippines
 *              2. Photon (Komoot)           - different ranking, finds many
 *                                              barangays/POIs Nominatim misses
 *              3. Wikipedia geosearch        - real-world landmarks
 *             Optional: if window.ARNIS_GOOGLE_MAPS_KEY is set (see
 *             config/google_maps_key.php), search uses Google Places instead,
 *             which covers every landmark Google Maps knows.
 *
 * Usage (page must include leaflet.css + leaflet.js before this file):
 *
 *   const picker = createLocationPicker({
 *       containerId: 'addMap',      // div the map renders into
 *       latInputId:  'add_venue_lat', // input auto-filled on pin
 *       lngInputId:  'add_venue_lng'
 *   });
 *   picker.invalidate();                 // call after the container becomes visible
 *   picker.setMarker(10.3157, 123.8854, { zoom: 16 });
 *   picker.flyHome();
 *   picker.clearMarker();
 */
(function (global) {
    'use strict';

    /* Default view: Cebu City (matches the rest of the app's sample data). */
    var DEFAULT_LAT = 10.3157;
    var DEFAULT_LNG = 123.8854;
    var DEFAULT_ZOOM = 13;

    var MAX_RESULTS = 8;

    /* Esri World Imagery - satellite photos, free, same infra as the street
       tiles. Labels overlay keeps road/brgy names readable on top of it. */
    var TILE_SATELLITE_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
    var TILE_SATELLITE_ATTR = 'Satellite &copy; Esri &mdash; Esri, Maxar, Earthstar Geographics, and the GIS User Community';
    var TILE_LABELS_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}';
    var TILE_LABELS_ATTR = 'Labels &copy; Esri';
    var TILE_STREET_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}';
    var TILE_STREET_ATTR = 'Streets &copy; Esri &mdash; Source: Esri, DeLorme, NAVTEQ, USGS, Intermap, iPC, NRCAN, Esri Japan, METI, Esri China (Hong Kong), Esri (Thailand), TomTom';

    /*
     * Free geocoders (all CORS-enabled, no API key):
     *  - Nominatim: viewbox biases results toward the Visayas/Mindanao area
     *    but bounded=0 keeps worldwide results, so anything still matches.
     *  - Photon: Komoot's OSM index; different ranking than Nominatim, and
     *    proximity bias (lat/lon) puts Cebu-area places first.
     *  - Wikipedia: landmark pages that carry coordinates.
     */
    var NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=6'
        + '&viewbox=122.0,8.0,127.0,13.0&bounded=0&q=';
    var PHOTON_URL = 'https://photon.komoot.io/api/?limit=8&lang=en'
        + '&lat=10.3157&lon=123.8854&q=';
    /* action API (not /rest.php): only it returns coordinates with the hits. */
    var WIKI_URL = 'https://en.wikipedia.org/w/api.php?action=query&format=json&origin=*'
        + '&generator=search&gsrnamespace=0&gsrlimit=5&prop=coordinates&gsrsearch=';

    /* A slow provider must never stall the whole search. */
    var FETCH_TIMEOUT_MS = 7000;

    function n7(value) {
        var num = parseFloat(value);
        return isFinite(num) ? num.toFixed(7) : '';
    }

    function isCoord(lat, lng) {
        return isFinite(lat) && isFinite(lng) &&
            lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
    }

    function el(tag, cssText, props) {
        var node = document.createElement(tag);
        if (cssText) node.style.cssText = cssText;
        if (props) {
            Object.keys(props).forEach(function (key) { node[key] = props[key]; });
        }
        return node;
    }

    function fetchJson(url) {
        /* Timeout wrapper: if a provider hangs (rate limit, bad route),
           reject instead of stalling the merged search indefinitely. */
        var request = fetch(url, { headers: { 'Accept': 'application/json' } }).then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        });

        var timeout = new Promise(function (resolve, reject) {
            var timer = setTimeout(function () {
                reject(new Error('Provider timed out.'));
            }, FETCH_TIMEOUT_MS);
            /* Let late responses settle without unhandled rejections. */
            request.then(function () { clearTimeout(timer); }, function () { clearTimeout(timer); });
        });

        return Promise.race([request, timeout]);
    }

    /* ==================================================================
     * Free providers - each resolves to [{lat, lon, label, source}]
     * ================================================================== */

    function searchNominatim(query) {
        return fetchJson(NOMINATIM_URL + encodeURIComponent(query)).then(function (rows) {
            if (!Array.isArray(rows)) return [];
            return rows.map(function (row) {
                return {
                    lat: parseFloat(row.lat),
                    lon: parseFloat(row.lon),
                    label: String(row.display_name || ''),
                    source: 'OSM'
                };
            });
        });
    }

    function searchPhoton(query) {
        return fetchJson(PHOTON_URL + encodeURIComponent(query)).then(function (data) {
            var features = (data && Array.isArray(data.features)) ? data.features : [];
            return features.map(function (f) {
                var coords = (f && f.geometry && f.geometry.coordinates) || [];
                var p = (f && f.properties) || {};
                var parts = [p.name, p.street, p.city || p.district, p.state, p.country]
                    .filter(function (x) { return typeof x === 'string' && x !== ''; });
                /* De-dupe label parts, e.g. name repeated as city. */
                var label = parts.filter(function (x, i) { return parts.indexOf(x) === i; }).join(', ');
                return {
                    lat: parseFloat(coords[1]),
                    lon: parseFloat(coords[0]),
                    label: label || String(p.label || ''),
                    source: 'Komoot'
                };
            });
        });
    }

    function searchWikipedia(query) {
        return fetchJson(WIKI_URL + encodeURIComponent(query)).then(function (data) {
            var pages = (data && data.query && data.query.pages) ? data.query.pages : {};
            return Object.keys(pages).map(function (key) { return pages[key]; }).map(function (page) {
                /* Only keep entries that actually carry coordinates. */
                var coords = (page && Array.isArray(page.coordinates)) ? page.coordinates : [];
                if (!coords.length) return null;
                return {
                    lat: parseFloat(coords[0].lat),
                    lon: parseFloat(coords[0].lon),
                    label: String(page.title || ''),
                    source: 'Wiki'
                };
            }).filter(Boolean);
        });
    }

    /**
     * Merges provider results, dropping entries that sit on top of an
     * already-accepted one (~150 m), and caps the list.
     */
    function mergeResults(lists) {
        var out = [];
        lists.forEach(function (rows) {
            (rows || []).forEach(function (row) {
                if (!row || !isCoord(row.lat, row.lon) || !row.label) return;
                for (var i = 0; i < out.length; i++) {
                    var o = out[i];
                    if (Math.abs(o.lat - row.lat) < 0.0014 &&
                        Math.abs(o.lon - row.lon) < 0.0015) {
                        return;
                    }
                }
                out.push(row);
            });
        });
        return out.slice(0, MAX_RESULTS);
    }

    function settled(promise) {
        return promise.then(function (v) { return { ok: true, v: v }; },
            function () { return { ok: false, v: [] }; });
    }

    function searchFree(query) {
        var jobs = [searchNominatim(query), searchPhoton(query), searchWikipedia(query)];

        /* A second Photon pass with the country appended rescues bare names
           ("Maguikay" -> "Maguikay, Philippines"). */
        if (!/philippin/i.test(query)) {
            jobs.push(searchPhoton(query + ', Philippines'));
        }

        return Promise.all(jobs.map(settled)).then(function (results) {
            return mergeResults(results.map(function (r) { return r.v; }));
        });
    }

    /* ==================================================================
     * Optional Google Places (config/google_maps_key.php)
     * ================================================================== */

    function googleKey() {
        return (typeof global.ARNIS_GOOGLE_MAPS_KEY === 'string')
            ? global.ARNIS_GOOGLE_MAPS_KEY.trim() : '';
    }

    var googlePlacesLib = null;

    function loadGooglePlaces() {
        if (googlePlacesLib) return googlePlacesLib;

        googlePlacesLib = new Promise(function (resolve, reject) {
            var key = googleKey();
            if (!key) { reject(new Error('No Google Maps key configured.')); return; }

            function ready() {
                if (!(global.google && google.maps && google.maps.importLibrary)) {
                    reject(new Error('Google loader unavailable.'));
                    return;
                }
                google.maps.importLibrary('places').then(resolve).catch(function () {
                    reject(new Error('Places library failed to load.'));
                });
            }

            if (global.google && google.maps) { ready(); return; }

            var script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js?key=' +
                encodeURIComponent(key) + '&v=weekly&language=en&region=PH';
            script.async = true;
            script.onload = ready;
            script.onerror = function () { reject(new Error('Google Maps script failed to load.')); };
            document.head.appendChild(script);
        });

        return googlePlacesLib;
    }

    function searchGoogle(query) {
        return loadGooglePlaces().then(function (places) {
            var Place = places.Place ||
                (global.google && google.maps && google.maps.places && google.maps.places.Place);

            if (!Place || typeof Place.searchByText !== 'function') {
                throw new Error('Place.searchByText unavailable.');
            }

            return Place.searchByText({
                textQuery: query,
                languageCode: 'en',
                maxResultCount: MAX_RESULTS,
                fields: ['displayName', 'formattedAddress', 'location']
            }).then(function (results) {
                return (results || []).map(function (place) {
                    if (!place || !place.location) return null;
                    return {
                        lat: place.location.lat(),
                        lon: place.location.lng(),
                        label: String(place.formattedAddress || place.displayName || query),
                        source: 'Google'
                    };
                }).filter(Boolean);
            });
        });
    }

    /** Google first (complete coverage); fall back to the free stack. */
    function searchPlaces(query) {
        if (!googleKey()) return searchFree(query);

        return searchGoogle(query).then(function (rows) {
            /* Google answered but found nothing - widen with free indexes. */
            if (!rows.length) return searchFree(query);
            return rows;
        }, function () {
            /* Bad key / billing not enabled / network - keep working free. */
            return searchFree(query);
        });
    }

    /* ==================================================================
     * Picker
     * ================================================================== */

    /**
     * @param {{containerId:string, latInputId?:string, lngInputId?:string}} config
     * @returns {object|null} picker API, or null when Leaflet/the div is missing
     */
    function createLocationPicker(config) {
        config = config || {};

        var container = document.getElementById(config.containerId);
        if (!container) return null;
        if (container._arnisPicker) return container._arnisPicker;

        if (typeof L === 'undefined') {
            console.warn('location-picker: Leaflet is not loaded on this page.');
            return null;
        }

        var latInput = config.latInputId ? document.getElementById(config.latInputId) : null;
        var lngInput = config.lngInputId ? document.getElementById(config.lngInputId) : null;

        /* --- wrapper so the search box can overlay the map --------------- */
        var wrapper = el('div', 'position:relative;width:100%;');
        container.parentNode.insertBefore(wrapper, container);
        wrapper.appendChild(container);

        /* --- map ---------------------------------------------------------- */
        var map = L.map(container, {
            zoomControl: false,          /* moved to bottom-right: search sits top-left */
            preferCanvas: false
        }).setView([DEFAULT_LAT, DEFAULT_LNG], DEFAULT_ZOOM);

        L.control.zoom({ position: 'bottomright' }).addTo(map);

        /*
         * Base layers: satellite (default) for pinpointing the exact
         * building/court, streets for quick orientation. The labels overlay
         * only rides on the satellite view so road/brgy names stay readable.
         */
        var satelliteLayer = L.tileLayer(TILE_SATELLITE_URL, {
            attribution: TILE_SATELLITE_ATTR,
            maxZoom: 19
        });
        var labelsLayer = L.tileLayer(TILE_LABELS_URL, {
            attribution: TILE_LABELS_ATTR,
            maxZoom: 19
        });
        var streetLayer = L.tileLayer(TILE_STREET_URL, {
            attribution: TILE_STREET_ATTR,
            maxZoom: 19
        });

        var currentBase = null;

        function setBaseLayer(name) {
            if (name === currentBase) return;
            currentBase = name;

            if (name === 'satellite') {
                if (map.hasLayer(streetLayer)) map.removeLayer(streetLayer);
                satelliteLayer.addTo(map);
                labelsLayer.addTo(map);      /* added last -> renders on top */
            } else {
                if (map.hasLayer(satelliteLayer)) map.removeLayer(satelliteLayer);
                if (map.hasLayer(labelsLayer)) map.removeLayer(labelsLayer);
                streetLayer.addTo(map);
            }

            Array.prototype.forEach.call(layerButtons, function (btn) {
                var active = btn.getAttribute('data-layer') === name;
                btn.style.background = active ? '#bf46c7' : '#1d212acc';
                btn.style.color = active ? '#fff' : '#9ca3af';
                btn.style.borderColor = active ? '#bf46c7' : '#374151';
            });
        }

        /* --- Satellite / Streets toggle (bottom-left) ---------------------- */
        var layerButtons = [];

        function makeLayerButton(label, name) {
            var btn = el('button',
                'padding:6px 10px;font-size:9px;font-weight:800;letter-spacing:0.06em;' +
                'text-transform:uppercase;border-radius:8px;cursor:pointer;' +
                'border:1px solid #374151;pointer-events:auto;transition:background .15s;',
                { type: 'button', textContent: label });
            btn.setAttribute('data-layer', name);
            btn.addEventListener('click', function () { setBaseLayer(name); });
            layerButtons.push(btn);
            return btn;
        }

        var marker = null;

        function placeMarker(lat, lng) {
            if (marker) {
                marker.setLatLng([lat, lng]);
            } else {
                marker = L.marker([lat, lng]).addTo(map);
            }
            return marker;
        }

        map.on('click', function (e) {
            if (!latInput || !lngInput) return;
            latInput.value = n7(e.latlng.lat);
            lngInput.value = n7(e.latlng.lng);
            placeMarker(e.latlng.lat, e.latlng.lng);
        });

        /* Keep the pin in sync when coordinates are typed by hand. */
        function syncFromInputs() {
            if (!latInput || !lngInput) return;
            var lat = parseFloat(latInput.value);
            var lng = parseFloat(lngInput.value);
            if (isCoord(lat, lng)) {
                placeMarker(lat, lng);
            } else if (marker) {
                map.removeLayer(marker);
                marker = null;
            }
        }
        if (latInput) latInput.addEventListener('change', syncFromInputs);
        if (lngInput) lngInput.addEventListener('change', syncFromInputs);

        /* --- search overlay ------------------------------------------------ */
        var overlay = el('div',
            'position:absolute;top:8px;left:8px;right:8px;z-index:1000;' +
            'pointer-events:none;font-family:Montserrat,Arial,sans-serif;');

        var row = el('div',
            'display:flex;gap:6px;pointer-events:none;');

        var input = el('input',
            'flex:1;min-width:0;box-sizing:border-box;background:#1d212a;color:#fff;' +
            'border:1px solid #374151;border-radius:12px;padding:10px 14px;font-size:12px;' +
            'outline:none;pointer-events:auto;',
            {
                type: 'search',
                placeholder: 'Search a place or landmark (Maguikay, gym, church)...',
                autocomplete: 'off'
            });

        var button = el('button',
            'background:#bf46c7;color:#fff;border:0;border-radius:12px;padding:0 16px;' +
            'font-size:11px;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;' +
            'cursor:pointer;pointer-events:auto;',
            { type: 'button', textContent: 'Go' });

        var status = el('div',
            'display:none;pointer-events:none;background:#1d212aee;color:#9ca3af;' +
            'border:1px solid #374151;border-top:0;border-radius:0 0 4px 4px;' +
            'padding:6px 12px;font-size:10px;');

        var results = el('div',
            'display:none;pointer-events:auto;max-height:190px;overflow-y:auto;' +
            'background:#1d212a;border:1px solid #374151;border-top:0;border-radius:0 0 12px 12px;' +
            'box-shadow:0 12px 30px rgba(0,0,0,0.45);');

        row.appendChild(input);
        row.appendChild(button);
        overlay.appendChild(row);
        overlay.appendChild(status);
        overlay.appendChild(results);
        wrapper.appendChild(overlay);

        /* --- Satellite / Streets toggle (bottom-left) ---------------------- */
        var layerBar = el('div',
            'position:absolute;bottom:8px;left:8px;z-index:1000;display:flex;gap:4px;' +
            'pointer-events:none;font-family:Montserrat,Arial,sans-serif;');
        layerBar.appendChild(makeLayerButton('Satellite', 'satellite'));
        layerBar.appendChild(makeLayerButton('Streets', 'street'));
        wrapper.appendChild(layerBar);

        /* Default view: satellite + labels, buttons styled to match. */
        setBaseLayer('satellite');

        var busy = false;

        function clearResults() {
            while (results.firstChild) results.removeChild(results.firstChild);
            results.style.display = 'none';
        }

        function pickPlace(lat, lng) {
            map.flyTo([lat, lng], 16, { duration: 0.6 });
            placeMarker(lat, lng);
            if (latInput) latInput.value = n7(lat);
            if (lngInput) lngInput.value = n7(lng);
            clearResults();
            status.style.display = 'none';
        }

        function showStatus(text) {
            status.textContent = text;
            status.style.display = 'block';
        }

        function renderResults(rows, query) {
            busy = false;
            button.disabled = false;

            if (!rows.length) {
                showStatus('No places found for "' + query + '". Try adding the barangay/city, '
                    + 'or zoom the map and click the exact spot.');
                return;
            }

            status.style.display = 'none';

            rows.forEach(function (place) {
                var item = el('button',
                    'display:block;width:100%;text-align:left;padding:8px 12px;' +
                    'background:transparent;border:0;border-bottom:1px solid #2a2f3a;' +
                    'color:#d1d5db;font-size:11px;line-height:1.4;cursor:pointer;',
                    { type: 'button' });

                var line = el('span', 'display:flex;align-items:center;gap:8px;');
                var name = el('span',
                    'flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;');
                name.textContent = place.label;
                var tag = el('span',
                    'flex:none;font-size:8px;font-weight:800;letter-spacing:0.08em;' +
                    'text-transform:uppercase;color:#6b7280;border:1px solid #374151;' +
                    'border-radius:6px;padding:2px 6px;');
                tag.textContent = place.source;

                line.appendChild(name);
                line.appendChild(tag);
                item.appendChild(line);

                item.addEventListener('mouseenter', function () {
                    item.style.background = 'rgba(255,255,255,0.06)';
                });
                item.addEventListener('mouseleave', function () {
                    item.style.background = 'transparent';
                });
                item.addEventListener('click', function () {
                    pickPlace(place.lat, place.lon);
                });
                results.appendChild(item);
            });

            results.style.display = 'block';
        }

        function runSearch() {
            var query = input.value.trim();
            if (!query || busy) return;

            busy = true;
            button.disabled = true;
            clearResults();
            showStatus('Searching for "' + query + '"...');

            searchPlaces(query).then(function (rows) {
                renderResults(rows, query);
            }).catch(function () {
                busy = false;
                button.disabled = false;
                showStatus('Search failed. Check your internet connection and try again.');
            });
        }

        button.addEventListener('click', runSearch);

        /* Enter inside a form would submit it - always run the search instead. */
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                runSearch();
            }
        });

        /* Warm up the Google loader (if configured) so the first search is fast. */
        if (googleKey()) {
            loadGooglePlaces().catch(function () { /* falls back to free search */ });
        }

        /* --- public API ------------------------------------------------------ */
        var api = {
            map: map,

            invalidate: function () {
                try { map.invalidateSize(true); } catch (e) { /* container not visible yet */ }
            },

            setMarker: function (lat, lng, opts) {
                opts = opts || {};
                lat = parseFloat(lat);
                lng = parseFloat(lng);
                if (!isCoord(lat, lng)) return false;
                placeMarker(lat, lng);
                if (latInput) latInput.value = n7(lat);
                if (lngInput) lngInput.value = n7(lng);
                if (opts.pan !== false) {
                    /* animate:false - an instant jump. A CSS zoom animation
                       started while the modal is still opening (or in a
                       background tab) can never finish, leaving the map stuck
                       and silently ignoring later zooms. Instant is also
                       snappier when the modal pops open on an existing venue. */
                    map.setView([lat, lng], opts.zoom || 16, { animate: false });
                }
                return true;
            },

            clearMarker: function () {
                if (marker) {
                    map.removeLayer(marker);
                    marker = null;
                }
            },

            flyHome: function () {
                map.setView([DEFAULT_LAT, DEFAULT_LNG], DEFAULT_ZOOM, { animate: false });
            }
        };

        container._arnisPicker = api;
        return api;
    }

    global.createLocationPicker = createLocationPicker;
})(window);
