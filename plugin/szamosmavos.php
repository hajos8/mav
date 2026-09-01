<?php
/*
Plugin Name: SzámosMÁVos
Description: Élő magyarországi vonatinformációk térképen.
Version: 0.3.0
Author: Bandi
*/

if (!defined('ABSPATH')) exit;

define('SZAMOSMAVOS_FEED_URL', 'https://cdn.holavonat.is/train_data_v3.json');

/** Return the live feed, cached briefly to avoid hammering the API. */
function szamosmavos_get_trains() {
    $cached = get_transient('szamosmavos_trains_v3');
    if ($cached !== false) return $cached;
    $response = wp_remote_get(SZAMOSMAVOS_FEED_URL, array(
        'timeout' => 12,
        'headers' => array('Accept' => 'application/json'),
    ));
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) return null;
    set_transient('szamosmavos_trains_v3', $data, 55);
    return $data;
}

function szamosmavos_value($row, $keys, $default = null) {
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') return $row[$key];
    }
    return $default;
}

/** Normalize small field-name differences between API feed versions. */
function szamosmavos_normalize_train($row, $service_date = '') {
    if (!is_array($row)) return null;
    $trip = isset($row['trip']) && is_array($row['trip']) ? $row['trip'] : array();
    $position = isset($row['position']) && is_array($row['position']) ? $row['position'] : array();
    $lat = szamosmavos_value($row, array('lat', 'latitude', 'y'), szamosmavos_value($position, array('lat', 'latitude')));
    $lon = szamosmavos_value($row, array('lon', 'lng', 'longitude', 'x'), szamosmavos_value($position, array('lon', 'lng', 'longitude')));
    if (!is_numeric($lat) || !is_numeric($lon)) return null;
    $lat = (float) $lat;
    $lon = (float) $lon;
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) return null;

    $stoptimes = isset($trip['stoptimes']) && is_array($trip['stoptimes']) ? $trip['stoptimes'] : array();
    $relationship = isset($row['stopRelationship']['stop']) && is_array($row['stopRelationship']['stop'])
        ? $row['stopRelationship']['stop'] : array();
    $current_stop_name = (string) szamosmavos_value($relationship, array('name'), '');
    $delay_seconds = null;

    // nextStop.arrivalDelay is commonly zero in this feed. The matching
    // stoptime contains the actual current arrival/departure delay.
    foreach ($stoptimes as $stoptime) {
        $stop_name = isset($stoptime['stop']['name']) ? (string) $stoptime['stop']['name'] : '';
        if ($current_stop_name !== '' && $stop_name === $current_stop_name) {
            $arrival_delay = szamosmavos_value($stoptime, array('arrivalDelay'), 0);
            $departure_delay = szamosmavos_value($stoptime, array('departureDelay'), 0);
            $delay_seconds = max((int) $arrival_delay, (int) $departure_delay);
            break;
        }
    }
    if ($delay_seconds === null) {
        $arrival = isset($trip['arrivalStoptime']) && is_array($trip['arrivalStoptime']) ? $trip['arrivalStoptime'] : array();
        $next_stop = isset($row['nextStop']) && is_array($row['nextStop']) ? $row['nextStop'] : array();
        $delay_seconds = szamosmavos_value($arrival, array('arrivalDelay', 'departureDelay'),
            szamosmavos_value($next_stop, array('arrivalDelay', 'departureDelay'), null));
    }
    $delay = $delay_seconds === null
        ? szamosmavos_value($row, array('delay', 'delayMinutes', 'delay_minutes', 'lateness'), 0)
        : round(((int) $delay_seconds) / 60);
    if (is_string($delay) && preg_match('/-?\d+/', $delay, $match)) $delay = $match[0];
    $number = (string) szamosmavos_value($trip, array('tripNumber', 'domesticResTrainNumber'),
        szamosmavos_value($row, array('trainNumber', 'train_number', 'number', 'train'), ''));
    $name = (string) szamosmavos_value($trip, array('tripShortName'),
        szamosmavos_value($row, array('name', 'trainName', 'train_name', 'displayName'), ''));
    $first_stop = $stoptimes ? reset($stoptimes) : array();
    $last_stop = $stoptimes ? end($stoptimes) : array();
    $from = isset($first_stop['stop']['name']) ? $first_stop['stop']['name'] : '';
    $to = isset($last_stop['stop']['name']) ? $last_stop['stop']['name'] : szamosmavos_value($trip, array('tripHeadsign'), '');
    $departure_seconds = isset($first_stop['scheduledDeparture']) ? (int) $first_stop['scheduledDeparture'] : null;
    $departure_time = $departure_seconds === null ? '' : sprintf(
        '%02d:%02d',
        floor((($departure_seconds % 86400) + 86400) % 86400 / 3600),
        floor(((($departure_seconds % 86400) + 86400) % 86400 % 3600) / 60)
    );
    $route = isset($trip['route']) && is_array($trip['route']) ? $trip['route'] : array();

    $heading = szamosmavos_value($row, array('heading', 'bearing'), null);
    $heading = is_numeric($heading) ? fmod(((float) $heading + 360), 360) : null;

    return array(
        'id' => sanitize_text_field((string) szamosmavos_value($row, array('vehicleId', 'id', 'trainId'), szamosmavos_value($trip, array('id', 'gtfsId'), $number))),
        'number' => sanitize_text_field($number),
        'name' => sanitize_text_field($name),
        'label' => sanitize_text_field(trim($number . ' ' . $name)),
        'mode' => sanitize_key((string) szamosmavos_value($route, array('mode'), 'RAIL')),
        'lat' => $lat,
        'lon' => $lon,
        'heading' => $heading,
        'delay' => is_numeric($delay) ? (int) $delay : 0,
        'from' => sanitize_text_field((string) $from),
        'to' => sanitize_text_field((string) $to),
        'departureTime' => $departure_time,
        'nextStop' => sanitize_text_field((string) szamosmavos_value($relationship, array('name'), '')),
        'date' => sanitize_text_field((string) szamosmavos_value($row, array('date', 'serviceDate'), $service_date ?: wp_date('Y-m-d'))),
    );
}

function szamosmavos_get_normalized_trains() {
    $data = szamosmavos_get_trains();
    if ($data === null) return null;
    $timestamp = !empty($data['timestamp']) ? strtotime($data['timestamp']) : false;
    $service_date = $timestamp ? wp_date('Y-m-d', $timestamp) : wp_date('Y-m-d');
    foreach (array('vehiclePositions', 'trains', 'vehicles', 'data') as $container) {
        if (isset($data[$container]) && is_array($data[$container])) {
            $data = $data[$container];
            break;
        }
    }
    $trains = array();
    foreach ($data as $row) {
        $train = szamosmavos_normalize_train($row, $service_date);
        if ($train !== null) $trains[] = $train;
    }
    szamosmavos_store_napfeny_trains($trains, $service_date);
    return $trains;
}

/** Persist every Napfény service seen today so completed trains remain listed. */
function szamosmavos_store_napfeny_trains($trains, $date) {
    if ($date !== wp_date('Y-m-d')) return;
    $daily = get_option('szamosmavos_napfeny_daily', array());
    $changed = false;
    if (!isset($daily['date']) || $daily['date'] !== $date) {
        $daily = array('date' => $date, 'trains' => array());
        $changed = true;
    }
    foreach ($trains as $train) {
        if (stripos(remove_accents($train['label']), 'napfeny') === false) continue;
        $key = $train['departureTime'] . '|' . $train['from'] . '|' . $train['number'];
        $previous = isset($daily['trains'][$key]) ? $daily['trains'][$key] : null;
        if (is_array($previous)) unset($previous['lastSeenAt']);
        if ($previous === $train) continue;
        $train['lastSeenAt'] = current_time('c');
        $daily['trains'][$key] = $train;
        $changed = true;
    }
    if ($changed) update_option('szamosmavos_napfeny_daily', $daily, false);
}

function szamosmavos_get_stored_napfeny_trains() {
    $daily = get_option('szamosmavos_napfeny_daily', array());
    if (!isset($daily['date']) || $daily['date'] !== wp_date('Y-m-d') || empty($daily['trains'])) {
        return array();
    }
    return array_values($daily['trains']);
}

/**
 * Return all Napfény InterCity trains in the live feed for YYYY-MM-DD.
 * Defaults to today in the configured WordPress timezone.
 */
function szamosmavos_get_napfeny_intercity($date = '') {
    $date = $date ?: wp_date('Y-m-d');
    $trains = szamosmavos_get_normalized_trains();
    if ($trains === null) return null;
    return array_values(array_filter($trains, function ($train) use ($date) {
        $text = remove_accents($train['number'] . ' ' . $train['name']);
        return $train['date'] === $date && stripos($text, 'napfeny') !== false;
    }));
}

/** Return every live train matching an id, number, or partial name. */
function szamosmavos_search_live_trains($query, $trains = null) {
    $query = trim(remove_accents((string) $query));
    if ($query === '') return array();
    if ($trains === null) $trains = szamosmavos_get_normalized_trains();
    if ($trains === null) return null;

    $exact = array_values(array_filter($trains, function ($train) use ($query) {
        return strcasecmp(remove_accents($train['id']), $query) === 0
            || strcasecmp(remove_accents($train['number']), $query) === 0;
    }));
    if ($exact) return $exact;

    return array_values(array_filter($trains, function ($train) use ($query) {
        return stripos(remove_accents($train['label']), $query) !== false;
    }));
}

/** Find the first currently live train for code that needs one result. */
function szamosmavos_find_live_train($query) {
    $matches = szamosmavos_search_live_trains($query);
    return $matches ? reset($matches) : null;
}

function szamosmavos_rest_trains(WP_REST_Request $request) {
    $trains = szamosmavos_get_normalized_trains();
    if ($trains === null) {
        return new WP_Error('feed_unavailable', 'A vonatadatok most nem érhetők el.', array('status' => 503));
    }
    $query = (string) $request->get_param('search');
    if ($query !== '') {
        $trains = szamosmavos_search_live_trains($query, $trains);
    }
    return rest_ensure_response(array('updatedAt' => current_time('c'), 'trains' => $trains));
}

function szamosmavos_rest_napfeny() {
    // Fetching normal data also updates today's persistent Napfény store.
    $live_trains = szamosmavos_get_normalized_trains();
    $stored_trains = szamosmavos_get_stored_napfeny_trains();
    if ($live_trains === null && !$stored_trains) {
        return new WP_Error('feed_unavailable', 'A járatadatok most nem érhetők el.', array('status' => 503));
    }
    return rest_ensure_response(array(
        'updatedAt' => current_time('c'),
        'date' => wp_date('Y-m-d'),
        'trains' => $stored_trains,
    ));
}

function szamosmavos_register_rest_routes() {
    register_rest_route('szamosmavos/v1', '/trains', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'szamosmavos_rest_trains',
        'permission_callback' => '__return_true',
        'args' => array(
            'search' => array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function ($value) {
                    return is_string($value) && strlen($value) <= 300;
                },
            ),
        ),
    ));
    register_rest_route('szamosmavos/v1', '/napfeny', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'szamosmavos_rest_napfeny',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'szamosmavos_register_rest_routes');

function szamosmavos_shortcode() {
    wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4');
    wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true);
    $id = wp_unique_id('szamosmavos-');
    ob_start(); ?>
    <style>
        .<?php echo esc_attr($id); ?>-viewport {
            box-sizing:border-box;
            position:relative;
            left:50%;
            width:calc(100vw - 32px) !important;
            max-width:none !important;
            margin-left:0 !important;
            padding:0;
            transform:translateX(-50%);
            display:flex;
            justify-content:center;
        }
        #<?php echo esc_attr($id); ?> { width:100%; max-width:1500px; margin:0 auto; font-family:system-ui,-apple-system,sans-serif; color:#17212b; }
        #<?php echo esc_attr($id); ?> form { display:flex; flex-wrap:wrap; align-items:end; gap:10px; padding:16px; background:#f7f9fb; border:1px solid #dfe5ea; border-radius:14px 14px 0 0; }
        #<?php echo esc_attr($id); ?> .search-field { display:flex; flex:1 1 280px; flex-direction:column; gap:5px; margin:0; font-size:13px; font-weight:650; }
        #<?php echo esc_attr($id); ?> input[type="search"] { box-sizing:border-box; width:100%; min-height:42px; padding:9px 12px; border:1px solid #bcc7d1; border-radius:8px; background:#fff; font:inherit; }
        #<?php echo esc_attr($id); ?> button { min-height:42px; padding:8px 15px; border:0; border-radius:8px; background:#1368ce; color:#fff; font:600 14px system-ui,-apple-system,sans-serif; cursor:pointer; }
        #<?php echo esc_attr($id); ?> button[data-all] { background:#e5ebf0; color:#263746; }
        #<?php echo esc_attr($id); ?> button:hover { filter:brightness(.94); }
        #<?php echo esc_attr($id); ?> .tram-toggle { display:flex; align-items:center; gap:7px; min-height:42px; margin:0; padding:0 5px; font-size:14px; white-space:nowrap; }
        #<?php echo esc_attr($id); ?> .tram-toggle input { width:17px; height:17px; accent-color:#1368ce; }
        #<?php echo esc_attr($id); ?> .map-meta { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px; padding:10px 14px; border-right:1px solid #dfe5ea; border-left:1px solid #dfe5ea; background:#fff; }
        #<?php echo esc_attr($id); ?> .status { margin:0; font-size:14px; font-weight:600; }
        #<?php echo esc_attr($id); ?> .legend { display:flex; flex-wrap:wrap; gap:10px; margin:0; padding:0; list-style:none; font-size:12px; color:#52616d; }
        #<?php echo esc_attr($id); ?> .legend li { display:flex; align-items:center; gap:4px; }
        #<?php echo esc_attr($id); ?> .legend i { display:block; width:10px; height:10px; border-radius:50%; }
        #<?php echo esc_attr($id); ?> .map { height:min(72vh,720px); min-height:480px; overflow:hidden; border:1px solid #dfe5ea; border-radius:0 0 14px 14px; box-shadow:0 8px 26px rgba(23,33,43,.1); }
        #<?php echo esc_attr($id); ?> .leaflet-popup-content-wrapper { border-radius:10px; }
        @media (max-width:600px) {
            #<?php echo esc_attr($id); ?> form { align-items:stretch; }
            #<?php echo esc_attr($id); ?> button { flex:1; }
            #<?php echo esc_attr($id); ?> .tram-toggle { flex-basis:100%; }
            #<?php echo esc_attr($id); ?> .map { min-height:420px; }
        }
    </style>
    <div class="<?php echo esc_attr($id); ?>-viewport">
    <section id="<?php echo esc_attr($id); ?>" class="szamosmavos">
        <form>
            <label class="search-field" for="<?php echo esc_attr($id); ?>-query">
                Élő jármű keresése
                <input id="<?php echo esc_attr($id); ?>-query" type="search" placeholder="Vonatszám vagy név, például: Napfény">
            </label>
            <button type="submit">Keresés</button>
            <button type="button" data-all>Összes vonat</button>
            <label class="tram-toggle">
                <input type="checkbox" data-show-trams checked>
                Villamosok
            </label>
        </form>
        <div class="map-meta">
            <p class="status" role="status">Vonatok betöltése…</p>
            <ul class="legend" aria-label="Késési színek">
                <li><i style="background:#2e7d32"></i>0 perc</li>
                <li><i style="background:#fbc02d"></i>1–4 perc</li>
                <li><i style="background:#ef6c00"></i>5–14 perc</li>
                <li><i style="background:#795548"></i>15–59 perc</li>
                <li><i style="background:#c62828"></i>60+ perc</li>
            </ul>
        </div>
        <div class="map"></div>
    </section>
    </div>
    <script>
    (function () {
    let leafletAttempts = 0;
    function initMap() {
        const root = document.getElementById(<?php echo wp_json_encode($id); ?>);
        if (!root) return;
        if (typeof L === 'undefined') {
            leafletAttempts++;
            if (leafletAttempts < 100) {
                window.setTimeout(initMap, 100);
            } else {
                root.querySelector('.status').textContent = 'A térképkönyvtár nem tölthető be.';
            }
            return;
        }
        const endpoint = <?php echo wp_json_encode(rest_url('szamosmavos/v1/trains')); ?>;
        const status = root.querySelector('.status');
        const input = root.querySelector('input[type="search"]');
        const tramToggle = root.querySelector('[data-show-trams]');
        let lastTrains = [];
        let lastFocus = false;
        const map = L.map(root.querySelector('.map')).setView([47.16, 19.5], 7);
        const markers = L.layerGroup().addTo(map);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18, attribution: '&copy; OpenStreetMap közreműködők'
        }).addTo(map);
        function esc(value) {
            const span = document.createElement('span');
            span.textContent = String(value || '');
            return span.innerHTML;
        }
        function draw(trains, focus) {
            markers.clearLayers();
            const bounds = [];
            const visibleTrains = trains.filter(function (train) {
                const isTram = String(train.mode).toUpperCase().startsWith('TRAM');
                return tramToggle.checked || !isTram;
            });
            visibleTrains.forEach(function (train) {
                const delay = Number(train.delay) || 0;
                const color = delay <= 0 ? '#2e7d32'
                    : delay < 5 ? '#fbc02d'
                    : delay < 15 ? '#ef6c00'
                    : delay < 60 ? '#795548'
                    : '#c62828';
                const isTram = String(train.mode).toUpperCase().startsWith('TRAM');
                const heading = train.heading === null ? null : Number(train.heading);
                const tramArrow = Number.isFinite(heading)
                    ? '<span style="position:absolute;z-index:2;left:50%;top:-10px;' +
                        'width:12px;margin-left:-6px;text-align:center;color:#263238;' +
                        'font-size:12px;line-height:12px;text-shadow:0 0 2px #fff;' +
                        'transform-origin:50% 23px;' +
                        'transform:rotate(' + heading + 'deg)">▲</span>'
                    : '';
                const trainGraphic = Number.isFinite(heading)
                    ? '<svg width="26" height="26" viewBox="0 0 32 32" style="display:block;transform:rotate(' + heading + 'deg);filter:drop-shadow(0 1px 2px #555)">' +
                        '<path d="M16 2 L27 27 L16 22 L5 27 Z" fill="' + color + '" stroke="#fff" stroke-width="2.5" stroke-linejoin="round"/></svg>'
                    : '<span style="display:block;width:14px;height:14px;margin:5px;border:2px solid #fff;' +
                        'border-radius:50%;background:' + color + ';box-shadow:0 1px 4px #555"></span>';
                const tramGraphic =
                    '<span title="Villamos" style="position:relative;display:flex;width:26px;height:26px;' +
                        'align-items:center;justify-content:center;border:2px solid #fff;' +
                        'border-radius:7px;background:' + color + ';font-size:17px;' +
                        'line-height:1;box-shadow:0 1px 4px #333">🚋' + tramArrow + '</span>';
                const marker = isTram
                    ? L.marker([train.lat, train.lon], {
                        icon: L.divIcon({
                            className: '',
                            iconSize: [30, 30],
                            iconAnchor: [15, 15],
                            popupAnchor: [0, -16],
                            html: tramGraphic
                        })
                    })
                    : L.marker([train.lat, train.lon], {
                        icon: L.divIcon({
                            className: '',
                            iconSize: [26, 26],
                            iconAnchor: [13, 13],
                            popupAnchor: [0, -14],
                            html: trainGraphic
                        })
                    });
                marker.bindPopup('<strong>' + esc(train.label || train.number || (isTram ? 'Villamos' : 'Vonat')) + '</strong><br>' +
                    esc([train.from, train.to].filter(Boolean).join(' → ')) +
                    (train.nextStop ? '<br>Következő: ' + esc(train.nextStop) : '') +
                    '<br>Késés: ' + delay + ' perc');
                marker.addTo(markers);
                bounds.push([train.lat, train.lon]);
                if (focus) marker.openPopup();
            });
            if (bounds.length === 1) map.setView(bounds[0], 12);
            else if (focus && bounds.length > 1) map.fitBounds(bounds, {padding:[30,30]});
            status.textContent = visibleTrains.length
                ? visibleTrains.length + ' élő jármű a térképen.'
                : 'Nincs megjeleníthető élő jármű.';
        }
        async function load(search) {
            status.textContent = 'Vonatok betöltése…';
            try {
                const url = new URL(endpoint, window.location.href);
                if (search) url.searchParams.set('search', search);
                const response = await fetch(url.toString(), {headers: {'Accept': 'application/json'}});
                if (!response.ok) throw new Error();
                lastTrains = (await response.json()).trains || [];
                lastFocus = Boolean(search);
                draw(lastTrains, lastFocus);
            } catch (e) {
                status.textContent = 'A vonatadatok most nem tölthetők be.';
            }
        }
        root.querySelector('form').addEventListener('submit', function (e) { e.preventDefault(); load(input.value.trim()); });
        root.querySelector('[data-all]').addEventListener('click', function () { input.value = ''; load(''); });
        tramToggle.addEventListener('change', function () { draw(lastTrains, lastFocus); });
        load('');
        window.setInterval(function () { load(input.value.trim()); }, 60000);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMap);
    } else {
        initMap();
    }
    }());
    </script>
    <?php return ob_get_clean();
}
add_shortcode('szamosmavos_map', 'szamosmavos_shortcode');

/** Shortcode: [szamosmavos_napfeny] */
function szamosmavos_napfeny_shortcode() {
    $id = wp_unique_id('szamosmavos-napfeny-');
    ob_start(); ?>
    <style>
        #<?php echo esc_attr($id); ?> { width:100%; max-width:1100px; margin:28px auto; font-family:system-ui,-apple-system,sans-serif; color:#17212b; }
        #<?php echo esc_attr($id); ?> .napfeny-card { overflow:hidden; border:1px solid #dfe5ea; border-radius:14px; background:#fff; box-shadow:0 8px 26px rgba(23,33,43,.08); }
        #<?php echo esc_attr($id); ?> header { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px; padding:16px 18px; background:#f7f9fb; border-bottom:1px solid #dfe5ea; }
        #<?php echo esc_attr($id); ?> h2 { margin:0; font:700 20px/1.3 system-ui,-apple-system,sans-serif; }
        #<?php echo esc_attr($id); ?> .napfeny-status { margin:0; color:#52616d; font-size:13px; }
        #<?php echo esc_attr($id); ?> .tables { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; padding:16px; }
        #<?php echo esc_attr($id); ?> .route-table { overflow:hidden; border:1px solid #dfe5ea; border-radius:10px; }
        #<?php echo esc_attr($id); ?> .route-table h3 { margin:0; padding:12px 14px; background:#f7f9fb; border-bottom:1px solid #dfe5ea; font:700 15px/1.3 system-ui,-apple-system,sans-serif; }
        #<?php echo esc_attr($id); ?> .table-wrap { overflow-x:auto; }
        #<?php echo esc_attr($id); ?> table { width:100%; margin:0; border:0; border-collapse:collapse; font-size:14px; }
        #<?php echo esc_attr($id); ?> th { padding:11px 14px; background:#fff; color:#52616d; text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        #<?php echo esc_attr($id); ?> td { padding:12px 14px; border-top:1px solid #edf0f2; vertical-align:middle; }
        #<?php echo esc_attr($id); ?> tbody tr:hover { background:#f8fafb; }
        #<?php echo esc_attr($id); ?> .departure-time { font-weight:750; font-variant-numeric:tabular-nums; }
        #<?php echo esc_attr($id); ?> .delay-badge { display:inline-flex; align-items:center; gap:6px; font-weight:700; white-space:nowrap; }
        #<?php echo esc_attr($id); ?> .delay-badge i { display:block; width:9px; height:9px; border-radius:50%; }
        #<?php echo esc_attr($id); ?> .empty { padding:24px; text-align:center; color:#52616d; }
        @media (max-width:800px) { #<?php echo esc_attr($id); ?> .tables { grid-template-columns:1fr; } }
    </style>
    <section id="<?php echo esc_attr($id); ?>">
        <div class="napfeny-card">
            <header>
                <h2>Mai NAPFÉNY InterCity járatok</h2>
                <p class="napfeny-status" role="status">Adatok betöltése…</p>
            </header>
            <div class="tables">
                <section class="route-table">
                    <h3>Szeged → Budapest-Nyugati</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Indulás</th><th>Következő megálló</th><th>Késés</th></tr></thead>
                            <tbody data-direction="szeged"></tbody>
                        </table>
                        <p class="empty" data-empty="szeged" hidden>Ma még nincs eltárolt járat.</p>
                    </div>
                </section>
                <section class="route-table">
                    <h3>Budapest-Nyugati → Szeged</h3>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Indulás</th><th>Következő megálló</th><th>Késés</th></tr></thead>
                            <tbody data-direction="budapest"></tbody>
                        </table>
                        <p class="empty" data-empty="budapest" hidden>Ma még nincs eltárolt járat.</p>
                    </div>
                </section>
            </div>
        </div>
    </section>
    <script>
    (function () {
        const root = document.getElementById(<?php echo wp_json_encode($id); ?>);
        if (!root) return;
        const endpoint = <?php echo wp_json_encode(rest_url('szamosmavos/v1/napfeny')); ?>;
        const szegedBody = root.querySelector('[data-direction="szeged"]');
        const budapestBody = root.querySelector('[data-direction="budapest"]');
        const status = root.querySelector('.napfeny-status');
        const szegedEmpty = root.querySelector('[data-empty="szeged"]');
        const budapestEmpty = root.querySelector('[data-empty="budapest"]');

        function cell(row, text, className) {
            const td = document.createElement('td');
            td.textContent = text || '—';
            if (className) td.className = className;
            row.appendChild(td);
            return td;
        }
        function delayColor(delay) {
            return delay <= 0 ? '#2e7d32' : delay < 5 ? '#fbc02d'
                : delay < 15 ? '#ef6c00' : delay < 60 ? '#795548' : '#c62828';
        }
        function render(trains, updatedAt) {
            szegedBody.replaceChildren();
            budapestBody.replaceChildren();
            trains.sort(function (a, b) {
                return String(a.departureTime).localeCompare(String(b.departureTime));
            });
            trains.forEach(function (train) {
                const row = document.createElement('tr');
                cell(row, train.departureTime, 'departure-time');
                cell(row, train.nextStop);
                const delayCell = document.createElement('td');
                const badge = document.createElement('span');
                const dot = document.createElement('i');
                badge.className = 'delay-badge';
                dot.style.background = delayColor(Number(train.delay) || 0);
                badge.append(dot, document.createTextNode((Number(train.delay) || 0) + ' perc'));
                delayCell.appendChild(badge);
                row.appendChild(delayCell);
                const fromSzeged = String(train.from).toLowerCase().startsWith('szeged');
                (fromSzeged ? szegedBody : budapestBody).appendChild(row);
            });
            szegedEmpty.hidden = szegedBody.children.length > 0;
            budapestEmpty.hidden = budapestBody.children.length > 0;
            status.textContent = trains.length + ' mai járat · frissítve: ' +
                new Date(updatedAt).toLocaleTimeString('hu-HU', {hour:'2-digit', minute:'2-digit'});
        }
        async function loadNapfeny() {
            try {
                const url = new URL(endpoint, window.location.href);
                const response = await fetch(url.toString(), {headers:{'Accept':'application/json'}});
                if (!response.ok) throw new Error();
                const result = await response.json();
                render(result.trains || [], result.updatedAt);
            } catch (error) {
                status.textContent = 'A járatadatok most nem tölthetők be.';
            }
        }
        loadNapfeny();
        window.setInterval(loadNapfeny, 60000);
    }());
    </script>
    <?php return ob_get_clean();
}
add_shortcode('szamosmavos_napfeny', 'szamosmavos_napfeny_shortcode');

/**
 * Homepage shortcode: [szamosmavos_home]
 * Static landing-page content without live API components.
 */
function szamosmavos_home_shortcode() {
    $id = wp_unique_id('szamosmavos-home-');
    ob_start(); ?>
    <style>
        #<?php echo esc_attr($id); ?> { box-sizing:border-box; width:min(1100px,calc(100vw - 32px)); margin:0 auto; padding:72px 16px; font-family:system-ui,-apple-system,sans-serif; color:#17212b; }
        #<?php echo esc_attr($id); ?> .home-intro { max-width:780px; margin:0 auto 54px; text-align:center; }
        #<?php echo esc_attr($id); ?> .eyebrow { display:inline-block; margin-bottom:14px; padding:6px 11px; border-radius:999px; background:#e8f1fd; color:#1368ce; font-size:13px; font-weight:750; }
        #<?php echo esc_attr($id); ?> h1 { margin:0 0 16px; font:800 clamp(38px,7vw,72px)/1 system-ui,-apple-system,sans-serif; letter-spacing:-.04em; }
        #<?php echo esc_attr($id); ?> .lead { margin:0 auto; max-width:650px; color:#52616d; font-size:clamp(17px,2vw,21px); line-height:1.6; }
        #<?php echo esc_attr($id); ?> .features { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px; }
        #<?php echo esc_attr($id); ?> .feature { padding:24px; border:1px solid #dfe5ea; border-radius:14px; background:#fff; box-shadow:0 8px 24px rgba(23,33,43,.06); }
        #<?php echo esc_attr($id); ?> .feature-icon { display:flex; width:42px; height:42px; margin-bottom:18px; align-items:center; justify-content:center; border-radius:10px; background:#e8f1fd; font-size:22px; }
        #<?php echo esc_attr($id); ?> h2 { margin:0 0 8px; font:750 18px/1.3 system-ui,-apple-system,sans-serif; }
        #<?php echo esc_attr($id); ?> .feature p { margin:0; color:#52616d; font-size:14px; line-height:1.6; }
        @media (max-width:700px) {
            #<?php echo esc_attr($id); ?> { padding-top:44px; }
            #<?php echo esc_attr($id); ?> .features { grid-template-columns:1fr; }
        }
    </style>
    <section id="<?php echo esc_attr($id); ?>">
        <header class="home-intro">
            <span class="eyebrow">Élő magyar vasúti információk</span>
            <h1>SzámosMÁVos</h1>
            <p class="lead">Kövesd a magyarországi vonatokat és villamosokat, nézd meg az aktuális késéseket, és találd meg a keresett járatot.</p>
        </header>
        <div class="features">
            <article class="feature">
                <span class="feature-icon" aria-hidden="true">⌖</span>
                <h2>Élő helyzetek</h2>
                <p>Aktuális járműpozíciók és haladási irányok a teljes ország területén.</p>
            </article>
            <article class="feature">
                <span class="feature-icon" aria-hidden="true">◷</span>
                <h2>Aktuális késések</h2>
                <p>Átlátható, színkódolt késési információk, percben megjelenítve.</p>
            </article>
            <article class="feature">
                <span class="feature-icon" aria-hidden="true">⌕</span>
                <h2>Gyors keresés</h2>
                <p>Keress vonatszám vagy járatnév alapján az éppen közlekedő járművek között.</p>
            </article>
        </div>
    </section>
    <?php return ob_get_clean();
}
add_shortcode('szamosmavos_home', 'szamosmavos_home_shortcode');

function szamosmavos_page_permalink($slug) {
    $page = get_page_by_path($slug, OBJECT, 'page');
    return $page ? get_permalink($page) : home_url('/' . trim($slug, '/') . '/');
}

/** Custom site header: [szamosmavos_header] */
function szamosmavos_header_shortcode() {
    $id = wp_unique_id('szamosmavos-header-');
    $map_url = szamosmavos_page_permalink('terkep');
    $napfeny_url = szamosmavos_page_permalink('napfeny-tablazat');
    ob_start(); ?>
    <style>
        .<?php echo esc_attr($id); ?>-wide { box-sizing:border-box; width:100vw; max-width:none!important; margin-left:calc(50% - 50vw)!important; border-bottom:1px solid #dfe5ea; background:rgba(255,255,255,.96); }
        #<?php echo esc_attr($id); ?> { box-sizing:border-box; display:flex; width:min(1500px,100%); min-height:72px; margin:0 auto; padding:12px 24px; align-items:center; justify-content:space-between; gap:24px; font-family:system-ui,-apple-system,sans-serif; }
        #<?php echo esc_attr($id); ?> .brand { color:#17212b; text-decoration:none; font-size:22px; font-weight:800; letter-spacing:-.03em; }
        #<?php echo esc_attr($id); ?> nav { display:flex; flex-wrap:wrap; align-items:center; justify-content:flex-end; gap:6px; }
        #<?php echo esc_attr($id); ?> nav a { padding:9px 12px; border-radius:8px; color:#394956; text-decoration:none; font-size:14px; font-weight:650; }
        #<?php echo esc_attr($id); ?> nav a:hover { background:#e8f1fd; color:#1368ce; }
        @media(max-width:600px) {
            #<?php echo esc_attr($id); ?> { padding:10px 16px; align-items:flex-start; flex-direction:column; gap:5px; }
            #<?php echo esc_attr($id); ?> nav { justify-content:flex-start; }
            #<?php echo esc_attr($id); ?> nav a { padding:7px 8px; }
        }
    </style>
    <div class="<?php echo esc_attr($id); ?>-wide">
        <header id="<?php echo esc_attr($id); ?>">
            <a class="brand" href="<?php echo esc_url(home_url('/')); ?>">SzámosMÁVos</a>
            <nav aria-label="Fő navigáció">
                <a href="<?php echo esc_url(home_url('/')); ?>">Kezdőlap</a>
                <a href="<?php echo esc_url($map_url); ?>">Térkép</a>
                <a href="<?php echo esc_url($napfeny_url); ?>">Napfény táblázat</a>
            </nav>
        </header>
    </div>
    <?php return ob_get_clean();
}
add_shortcode('szamosmavos_header', 'szamosmavos_header_shortcode');

/** Custom site footer: [szamosmavos_footer] */
function szamosmavos_footer_shortcode() {
    $id = wp_unique_id('szamosmavos-footer-');
    $map_url = szamosmavos_page_permalink('terkep');
    $napfeny_url = szamosmavos_page_permalink('napfeny-tablazat');
    ob_start(); ?>
    <style>
        body:has(.<?php echo esc_attr($id); ?>-wide) #wp--skip-link--target {
            box-sizing:border-box;
            display:flex;
            min-height:calc(100vh - var(--wp-admin--admin-bar--height, 0px));
            flex-direction:column;
        }
        body:has(.<?php echo esc_attr($id); ?>-wide) #wp--skip-link--target > .entry-content { width:100%; flex:1 0 auto; }
        .<?php echo esc_attr($id); ?>-wide { box-sizing:border-box; width:100vw; max-width:none!important; margin:48px 0 0 calc(50% - 50vw)!important; background:#17212b; color:#d9e0e5; }
        body:has(.<?php echo esc_attr($id); ?>-wide) #wp--skip-link--target > .<?php echo esc_attr($id); ?>-wide { margin-top:auto!important; }
        #<?php echo esc_attr($id); ?> { box-sizing:border-box; width:min(1200px,100%); margin:0 auto; padding:48px 24px 22px; font-family:system-ui,-apple-system,sans-serif; }
        #<?php echo esc_attr($id); ?> .footer-grid { display:grid; grid-template-columns:2fr 1fr 1fr; gap:40px; padding-bottom:36px; }
        #<?php echo esc_attr($id); ?> .footer-brand { margin:0 0 10px; color:#fff; font-size:22px; font-weight:800; }
        #<?php echo esc_attr($id); ?> .footer-about { max-width:390px; margin:0; color:#9fadb8; font-size:14px; line-height:1.7; }
        #<?php echo esc_attr($id); ?> h2 { margin:0 0 14px; color:#fff; font:700 14px/1.3 system-ui,-apple-system,sans-serif; }
        #<?php echo esc_attr($id); ?> ul { margin:0; padding:0; list-style:none; }
        #<?php echo esc_attr($id); ?> li+li { margin-top:9px; }
        #<?php echo esc_attr($id); ?> a { color:#bdc8d0; text-decoration:none; font-size:14px; }
        #<?php echo esc_attr($id); ?> a:hover { color:#fff; }
        #<?php echo esc_attr($id); ?> .footer-bottom { padding-top:20px; border-top:1px solid #34414b; color:#82919d; font-size:12px; line-height:1.6; }
        @media(max-width:700px) {
            #<?php echo esc_attr($id); ?> .footer-grid { grid-template-columns:1fr 1fr; }
            #<?php echo esc_attr($id); ?> .footer-intro { grid-column:1/-1; }
        }
    </style>
    <div class="<?php echo esc_attr($id); ?>-wide">
        <footer id="<?php echo esc_attr($id); ?>">
            <div class="footer-grid">
                <div class="footer-intro">
                    <p class="footer-brand">SzámosMÁVos</p>
                    <p class="footer-about">Élő magyarországi vasúti és villamosinformációk, aktuális helyzetekkel és késésekkel.</p>
                </div>
                <nav aria-label="Oldalak">
                    <h2>Oldalak</h2>
                    <ul>
                        <li><a href="<?php echo esc_url(home_url('/')); ?>">Kezdőlap</a></li>
                        <li><a href="<?php echo esc_url($map_url); ?>">Térkép</a></li>
                        <li><a href="<?php echo esc_url($napfeny_url); ?>">Napfény táblázat</a></li>
                    </ul>
                </nav>
                <nav aria-label="Hasznos linkek">
                    <h2>Hasznos linkek</h2>
                    <ul>
                        <li><a href="https://holavonat.is" target="_blank" rel="noopener noreferrer">Holavonat</a></li>
                        <li><a href="https://www.mavcsoport.hu" target="_blank" rel="noopener noreferrer">MÁV-csoport</a></li>
                        <li><a href="https://www.openstreetmap.org" target="_blank" rel="noopener noreferrer">OpenStreetMap</a></li>
                    </ul>
                </nav>
            </div>
            <div class="footer-bottom">
                © <?php echo esc_html(wp_date('Y')); ?> SzámosMÁVos. Az információk tájékoztató jellegűek; utazás előtt ellenőrizd a hivatalos MÁV-tájékoztatást.
            </div>
        </footer>
    </div>
    <?php return ob_get_clean();
}
add_shortcode('szamosmavos_footer', 'szamosmavos_footer_shortcode');
