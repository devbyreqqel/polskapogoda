<?php
// PolskaPogoda – backend: IMGW + MET Norway + Open-Meteo, wynik uśredniony.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cfg = require __DIR__ . '/config.php';
$cacheDir = __DIR__ . '/cache';
define('UA', 'PolskaPogoda/1.0 (' . ($cfg['contact'] ?? 'kontakt@example.com') . ')');
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
    @file_put_contents($cacheDir . '/.htaccess', "Order deny,allow\nDeny from all\n");
}

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function http_get($url, $timeout = 8) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => UA,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) throw new Exception('HTTP ' . $code);
        return $body;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => UA]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) throw new Exception('Brak odpowiedzi serwera');
    return $body;
}

function get_json($url) {
    $j = json_decode(http_get($url), true);
    if ($j === null) throw new Exception('Niepoprawna odpowiedź JSON');
    return $j;
}

// Cache plikowy; przy błędzie źródła zwraca starsze dane (do 6x TTL), jeśli istnieją.
function cached($key, $ttl, $fn) {
    global $cacheDir;
    $f = $cacheDir . '/' . md5($key) . '.json';
    $age = is_file($f) ? time() - filemtime($f) : PHP_INT_MAX;
    if ($age < $ttl) {
        $d = json_decode(file_get_contents($f), true);
        if ($d !== null) return $d;
    }
    try {
        $d = $fn();
        @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE));
        return $d;
    } catch (Exception $e) {
        if ($age < $ttl * 6) {
            $d = json_decode(file_get_contents($f), true);
            if ($d !== null) return $d;
        }
        throw $e;
    }
}

function num($v) { return (is_numeric($v)) ? (float)$v : null; }

function fold($s) {
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

function haversine($la1, $lo1, $la2, $lo2) {
    $r = 6371; $p = M_PI / 180;
    $a = sin(($la2 - $la1) * $p / 2) ** 2 + cos($la1 * $p) * cos($la2 * $p) * sin(($lo2 - $lo1) * $p / 2) ** 2;
    return 2 * $r * asin(sqrt($a));
}

function circ_mean($degs) {
    if (!$degs) return null;
    $x = $y = 0;
    foreach ($degs as $d) { $x += cos(deg2rad($d)); $y += sin(deg2rad($d)); }
    if (abs($x) < 1e-9 && abs($y) < 1e-9) return null;
    return fmod(rad2deg(atan2($y, $x)) + 360, 360);
}

/* ---------- Kategorie pogody ---------- */
$CATS = [
    'clear'  => ['Bezchmurnie', '☀️'],
    'partly' => ['Częściowe zachmurzenie', '⛅'],
    'cloudy' => ['Pochmurno', '☁️'],
    'fog'    => ['Mgła', '🌫️'],
    'rain'   => ['Deszcz', '🌧️'],
    'snow'   => ['Śnieg', '❄️'],
    'storm'  => ['Burza', '⛈️'],
];
function cat_wmo($c) {
    if ($c <= 1) return 'clear';
    if ($c == 2) return 'partly';
    if ($c == 3) return 'cloudy';
    if ($c == 45 || $c == 48) return 'fog';
    if ($c >= 95) return 'storm';
    if (($c >= 71 && $c <= 77) || $c == 85 || $c == 86) return 'snow';
    return 'rain';
}
function cat_met($code) {
    $c = preg_replace('/_(day|night|polartwilight)$/', '', (string)$code);
    if (strpos($c, 'thunder') !== false) return 'storm';
    if (strpos($c, 'snow') !== false || strpos($c, 'sleet') !== false) return 'snow';
    if (strpos($c, 'rain') !== false) return 'rain';
    if (strpos($c, 'fog') !== false) return 'fog';
    if ($c === 'clearsky' || $c === 'fair') return 'clear';
    if ($c === 'partlycloudy') return 'partly';
    return 'cloudy';
}

/* ---------- Lokalizacja ---------- */
$city = trim((string)($_GET['city'] ?? ''));
if ($city === '' || mb_strlen($city) > 60) out(['error' => 'Podaj nazwę miejscowości.'], 400);

try {
    $geo = cached('geo:' . fold($city), 86400 * 30, function () use ($city) {
        $j = get_json('https://geocoding-api.open-meteo.com/v1/search?' .
            http_build_query(['name' => $city, 'count' => 10, 'language' => 'pl', 'format' => 'json']));
        foreach (($j['results'] ?? []) as $r) {
            if (($r['country_code'] ?? '') === 'PL') {
                return ['name' => $r['name'], 'region' => $r['admin1'] ?? '', 'lat' => $r['latitude'], 'lon' => $r['longitude']];
            }
        }
        throw new Exception('not_found');
    });
} catch (Exception $e) {
    if ($e->getMessage() === 'not_found') out(['error' => 'Nie znaleziono takiej miejscowości w Polsce.'], 404);
    out(['error' => 'Nie udało się ustalić lokalizacji. Spróbuj ponownie za chwilę.'], 502);
}
$lat = $geo['lat']; $lon = $geo['lon'];

/* ---------- Źródło: IMGW ---------- */
$STATIONS = [
 'bialystok'=>[53.13,23.16],'bielsko biala'=>[49.81,19.00],'bydgoszcz'=>[53.10,17.98],'chelm'=>[51.13,23.48],
 'chojnice'=>[53.70,17.55],'czestochowa'=>[50.81,19.12],'elblag'=>[54.17,19.40],'gdansk'=>[54.38,18.47],
 'gorzow wielkopolski'=>[52.74,15.28],'hel'=>[54.60,18.80],'jelenia gora'=>[50.90,15.74],'kalisz'=>[51.76,18.09],
 'kasprowy wierch'=>[49.23,19.98],'katowice'=>[50.24,19.03],'ketrzyn'=>[54.07,21.37],'kielce'=>[50.81,20.69],
 'klodzko'=>[50.43,16.65],'kolobrzeg'=>[54.18,15.58],'koszalin'=>[54.20,16.15],'krakow'=>[50.08,19.80],
 'krosno'=>[49.69,21.77],'legnica'=>[51.19,16.20],'lesko'=>[49.47,22.34],'leszno'=>[51.84,16.53],
 'lebork'=>[54.55,17.75],'lidzbark warminski'=>[54.13,20.58],'lublin'=>[51.22,22.39],'leba'=>[54.75,17.54],
 'lodz'=>[51.72,19.40],'mikolajki'=>[53.79,21.58],'mlawa'=>[53.10,20.36],'nowy sacz'=>[49.62,20.69],
 'olsztyn'=>[53.77,20.42],'opole'=>[50.63,17.97],'ostroleka'=>[53.08,21.57],'pila'=>[53.13,16.75],
 'poznan'=>[52.42,16.83],'przemysl'=>[49.80,22.77],'raciborz'=>[50.08,18.22],'rzeszow'=>[50.11,22.04],
 'sandomierz'=>[50.69,21.72],'siedlce'=>[52.18,22.28],'slubice'=>[52.35,14.56],'sulejow'=>[51.35,19.88],
 'suwalki'=>[54.13,22.95],'szczecin'=>[53.40,14.62],'szczecinek'=>[53.72,16.70],'sniezka'=>[50.74,15.74],
 'swinoujscie'=>[53.92,14.24],'tarnow'=>[50.03,20.99],'terespol'=>[52.07,23.62],'torun'=>[53.04,18.60],
 'ustka'=>[54.58,16.86],'warszawa'=>[52.16,20.96],'wielun'=>[51.22,18.57],'wlodawa'=>[51.55,23.55],
 'wroclaw'=>[51.10,16.89],'zakopane'=>[49.29,19.96],'zamosc'=>[50.70,23.26],'zielona gora'=>[51.93,15.53],
 'biala podlaska'=>[52.04,23.13],
];

function src_imgw($lat, $lon, $cfg) {
    global $STATIONS;
    $all = cached('imgw-synop', $cfg['ttl_imgw'], function () {
        return get_json('https://danepubliczne.imgw.pl/api/data/synop');
    });
    $best = null; $bestD = 1e9;
    foreach ($all as $st) {
        $n = fold($st['stacja'] ?? '');
        foreach ($STATIONS as $key => $c) {
            if ($n === $key || strpos($n, $key . ' ') === 0) {
                $d = haversine($lat, $lon, $c[0], $c[1]);
                if ($d < $bestD) { $bestD = $d; $best = $st; }
                break;
            }
        }
    }
    if (!$best || $bestD > 100) throw new Exception('Brak stacji IMGW w promieniu 100 km');
    $wind = num($best['predkosc_wiatru'] ?? null);
    return [
        'observed' => ($best['data_pomiaru'] ?? '') . ' ' . str_pad($best['godzina_pomiaru'] ?? '', 2, '0', STR_PAD_LEFT) . ':00',
        'note' => 'Stacja: ' . $best['stacja'] . ' (' . round($bestD) . ' km)',
        'temp' => num($best['temperatura'] ?? null),
        'feels' => null,
        'humidity' => num($best['wilgotnosc_wzgledna'] ?? null),
        'pressure' => num($best['cisnienie'] ?? null),
        'wind' => $wind,
        'windDir' => ($wind !== null && $wind > 0) ? num($best['kierunek_wiatru'] ?? null) : null,
        'cat' => null,
        'desc' => null,
    ];
}

/* ---------- Źródło: MET Norway ---------- */
function src_met($lat, $lon, $cfg) {
    $la = round($lat, 4); $lo = round($lon, 4);
    $t = cached('met:' . $la . ',' . $lo, $cfg['ttl_met'], function () use ($la, $lo) {
        $j = get_json('https://api.met.no/weatherapi/locationforecast/2.0/compact?lat=' . $la . '&lon=' . $lo);
        $first = $j['properties']['timeseries'][0] ?? null;
        if (!$first) throw new Exception('Pusta odpowiedź MET Norway');
        return $first;
    });
    $d = $t['data']['instant']['details'] ?? [];
    $sym = $t['data']['next_1_hours']['summary']['symbol_code'] ?? ($t['data']['next_6_hours']['summary']['symbol_code'] ?? null);
    return [
        'observed' => str_replace(['T', 'Z'], [' ', ' UTC'], $t['time'] ?? ''),
        'note' => 'prognoza na bieżącą godzinę',
        'temp' => num($d['air_temperature'] ?? null),
        'feels' => null,
        'humidity' => num($d['relative_humidity'] ?? null),
        'pressure' => num($d['air_pressure_at_sea_level'] ?? null),
        'wind' => num($d['wind_speed'] ?? null),
        'windDir' => num($d['wind_from_direction'] ?? null),
        'cat' => $sym ? cat_met($sym) : null,
        'desc' => null,
    ];
}

/* ---------- Źródło: Open-Meteo ---------- */
function src_openmeteo($lat, $lon, $cfg) {
    $c = cached('om:' . round($lat, 2) . ',' . round($lon, 2), $cfg['ttl_openmeteo'], function () use ($lat, $lon) {
        $j = get_json('https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $lat, 'longitude' => $lon, 'timezone' => 'Europe/Warsaw', 'wind_speed_unit' => 'ms',
            'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,pressure_msl,wind_speed_10m,wind_direction_10m,weather_code',
        ]));
        if (empty($j['current'])) throw new Exception('Pusta odpowiedź Open-Meteo');
        return $j['current'];
    });
    return [
        'observed' => str_replace('T', ' ', $c['time'] ?? ''),
        'note' => null,
        'temp' => num($c['temperature_2m'] ?? null),
        'feels' => num($c['apparent_temperature'] ?? null),
        'humidity' => num($c['relative_humidity_2m'] ?? null),
        'pressure' => num($c['pressure_msl'] ?? null),
        'wind' => num($c['wind_speed_10m'] ?? null),
        'windDir' => num($c['wind_direction_10m'] ?? null),
        'cat' => isset($c['weather_code']) ? cat_wmo((int)$c['weather_code']) : null,
        'desc' => null,
    ];
}

/* ---------- Zbieranie i uśrednianie ---------- */
$defs = [
    ['id' => 'imgw',       'name' => 'IMGW',        'url' => 'https://danepubliczne.imgw.pl', 'fn' => 'src_imgw'],
    ['id' => 'met',        'name' => 'MET Norway',  'url' => 'https://api.met.no',            'fn' => 'src_met'],
    ['id' => 'openmeteo',  'name' => 'Open-Meteo',  'url' => 'https://open-meteo.com',        'fn' => 'src_openmeteo'],
];
$sources = [];
foreach ($defs as $d) {
    $s = ['id' => $d['id'], 'name' => $d['name'], 'url' => $d['url'], 'ok' => false];
    try {
        $s['data'] = $d['fn']($lat, $lon, $cfg);
        $s['ok'] = true;
    } catch (Exception $e) {
        $s['error'] = $e->getMessage();
    }
    $sources[] = $s;
}

function avg_of($sources, $f, $dec = 1) {
    $v = [];
    foreach ($sources as $s) if ($s['ok'] && $s['data'][$f] !== null) $v[] = $s['data'][$f];
    return $v ? round(array_sum($v) / count($v), $dec) : null;
}

$temps = [];
$votes = [];
$dirs = [];
foreach ($sources as $s) {
    if (!$s['ok']) continue;
    if ($s['data']['temp'] !== null) $temps[] = $s['data']['temp'];
    if ($s['data']['cat']) $votes[$s['data']['cat']] = ($votes[$s['data']['cat']] ?? 0) + 1;
    if ($s['data']['windDir'] !== null) $dirs[] = $s['data']['windDir'];
}
$used = count($temps);
if ($used === 0) out(['error' => 'Żadne ze źródeł nie zwróciło danych. Spróbuj ponownie za chwilę.', 'sources' => $sources], 502);

$cat = null;
if ($votes) {
    arsort($votes);
    $cat = array_key_first($votes);
}
$dir = circ_mean($dirs);

out([
    'city' => $geo,
    'avg' => [
        'temp' => avg_of($sources, 'temp'),
        'feels' => avg_of($sources, 'feels'),
        'humidity' => avg_of($sources, 'humidity', 0),
        'pressure' => avg_of($sources, 'pressure', 0),
        'wind' => avg_of($sources, 'wind'),
        'windDir' => $dir !== null ? round($dir) : null,
        'cat' => $cat,
        'catLabel' => $cat ? $CATS[$cat][0] : null,
        'catEmoji' => $cat ? $CATS[$cat][1] : null,
    ],
    'spread' => count($temps) > 1 ? round(max($temps) - min($temps), 1) : null,
    'used' => $used,
    'total' => count($defs),
    'sources' => $sources,
    'catLabels' => array_map(function ($c) { return $c[0]; }, $CATS),
    'catEmojis' => array_map(function ($c) { return $c[1]; }, $CATS),
    'generated' => date('c'),
]);
