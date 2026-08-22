<?php
/**
 * Oregon Scientific WMR Universal Developer Trace Dashboard v2.9
 *
 * Standalone diagnostics dashboard for the hardened WeeWX drivers:
 *   - WMR100 protocol family: WMR100/WMR100N, WMR88/WMR88A, WMR180/A, WMRS200
 *   - WMR200 protocol family: WMR200/WMR200A and compatible W200 variants
 *
 * The page auto-detects the active station by inspecting both JSONL traces and
 * selects the trace with the newest event timestamp. A manual family override and a secure manual trace-file selector
 * are available from the UI.
 */
declare(strict_types=1);



// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------
const TRACE_CANDIDATES = [
    'wmr100' => [
        '/var/log/weewx/wmr100-developer-trace.jsonl',
    ],
    'wmr200' => [
        '/var/log/weewx/wmr200-developer-trace.jsonl',
        '/tmp/wmr200-developer-trace.jsonl', // fallback used by hardened WMR200
    ],
];
const MANUAL_TRACE_ALLOWED_ROOTS = [
    '/var/log/weewx',
    '/tmp',
];
const WEEWX_CONFIG_CANDIDATES = [
    '/etc/weewx/weewx.conf',
    '/home/weewx/weewx.conf',
];
const DRIVER_SOURCE_CANDIDATES = [
    'wmr100' => [
        '/etc/weewx/bin/user/wmr100.py',
        '/usr/share/weewx/user/wmr100.py',
        '/home/weewx/bin/user/wmr100.py',
    ],
    'wmr200' => [
        '/etc/weewx/bin/user/wmr200.py',
        '/usr/share/weewx/user/wmr200.py',
        '/home/weewx/bin/user/wmr200.py',
    ],
];
const TRACE_BACKUPS = 5;
const MAX_RECORDS_SCANNED = 250000;
const DEFAULT_DISPLAY_LIMIT = 250;
const MAX_DISPLAY_LIMIT = 2000;
const LOCAL_TIMEZONE = 'Europe/Rome';
const PAGE_TITLE = 'Oregon Scientific WMR Developer Trace v2.9';
const RECENT_HEALTH_WINDOW = 600;
const DEFAULT_LIVE_REFRESH = 5;
const API_TAIL_LINES = 6000; // retained only for detection/backward compatibility
const LIVE_CACHE_VERSION = 6;
const LIVE_CACHE_ROOT = '/tmp';
const DETECTION_TAIL_LINES = 8000;
const DIAGNOSTIC_TAIL_LINES = 5000;
const SPARKLINE_POINTS = 60;

// Persistent UI source preferences. These cookies intentionally remember only
// the selected trace source/parser, not diagnostic filters or search strings.
const SOURCE_PREF_COOKIE_TTL = 31536000; // 1 year
const SOURCE_PREF_COOKIE_STATION = 'wmr_station_mode';
const SOURCE_PREF_COOKIE_TRACE_MODE = 'wmr_trace_mode';
const SOURCE_PREF_COOKIE_TRACE_FILE = 'wmr_trace_file';

// -----------------------------------------------------------------------------
// Generic helpers
// -----------------------------------------------------------------------------
function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bool_param(string $name, bool $default = false): bool
{
    if (!isset($_GET[$name])) return $default;
    return in_array(strtolower((string)$_GET[$name]), ['1', 'true', 'yes', 'on'], true);
}

function int_param(string $name, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) return $default;
    return max($min, min($max, $value));
}


function source_pref_cookie(string $name, string $default = ''): string
{
    if (!isset($_COOKIE[$name])) return $default;
    return trim((string)$_COOKIE[$name]);
}

function set_source_pref_cookie(string $name, string $value): void
{
    // PHP encodes the cookie value. SameSite=Lax is enough because this is a
    // same-origin standalone diagnostics page and no sensitive token is stored.
    setcookie($name, $value, [
        'expires' => time() + SOURCE_PREF_COOKIE_TTL,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    // Make the new preference visible in the current request as well.
    $_COOKIE[$name] = $value;
}

function format_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return number_format($value, $value >= 10 ? 1 : 2, ',', '.') . ' ' . $unit;
        }
        $value /= 1024;
    }
    return $bytes . ' B';
}

function format_duration(float $seconds): string
{
    $seconds = max(0, (int)round($seconds));
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;
    $parts = [];
    if ($days > 0) $parts[] = $days . 'g';
    if ($hours > 0 || $days > 0) $parts[] = $hours . 'h';
    if ($minutes > 0 || $hours > 0 || $days > 0) $parts[] = $minutes . 'm';
    $parts[] = $seconds . 's';
    return implode(' ', $parts);
}

function timestamp_epoch(?string $timestamp): ?float
{
    if (!$timestamp) return null;
    try {
        return (float)(new DateTimeImmutable($timestamp))->format('U.u');
    } catch (Throwable) {
        return null;
    }
}

function local_timestamp(?string $timestamp, bool $millis = true): string
{
    if (!$timestamp) return '—';
    try {
        $dt = new DateTimeImmutable($timestamp);
        $fmt = $millis ? 'd/m/Y H:i:s.v T' : 'd/m/Y H:i:s T';
        return $dt->setTimezone(new DateTimeZone(LOCAL_TIMEZONE))->format($fmt);
    } catch (Throwable) {
        return (string)$timestamp;
    }
}

function epoch_local(?float $epoch): string
{
    if ($epoch === null) return '—';
    try {
        return (new DateTimeImmutable('@' . (string)(int)$epoch))
            ->setTimezone(new DateTimeZone(LOCAL_TIMEZONE))
            ->format('d/m/Y H:i:s T');
    } catch (Throwable) {
        return '—';
    }
}

function format_age(?float $seconds): string
{
    if ($seconds === null) return 'N/D';
    $seconds = max(0, (int)round($seconds));
    if ($seconds < 60) return $seconds . ' s fa';
    if ($seconds < 3600) return intdiv($seconds, 60) . ' min ' . ($seconds % 60) . ' s fa';
    if ($seconds < 86400) return intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min fa';
    return intdiv($seconds, 86400) . ' g ' . intdiv($seconds % 86400, 3600) . ' h fa';
}

function format_interval(?float $seconds): string
{
    if ($seconds === null) return '—';
    if ($seconds < 1) return number_format($seconds, 2, ',', '.') . ' s';
    if ($seconds < 60) return number_format($seconds, $seconds < 10 ? 1 : 0, ',', '.') . ' s';
    if ($seconds < 3600) return intdiv((int)$seconds, 60) . ' min ' . ((int)$seconds % 60) . ' s';
    return format_duration($seconds);
}

function compass_direction(float $degrees): string
{
    $labels = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSO','SO','OSO','O','ONO','NO','NNO'];
    $index = ((int)round(fmod(($degrees + 360.0), 360.0) / 22.5)) % 16;
    return $labels[$index];
}

function trace_files(string $traceFile, bool $includeRotated): array
{
    $files = [];
    if ($includeRotated) {
        for ($i = TRACE_BACKUPS; $i >= 1; $i--) {
            $candidate = $traceFile . '.' . $i;
            if (is_file($candidate) && is_readable($candidate)) $files[] = $candidate;
        }
    }
    if (is_file($traceFile) && is_readable($traceFile)) $files[] = $traceFile;
    return $files;
}

function tail_lines(string $file, int $maxLines): array
{
    if (!is_file($file) || !is_readable($file) || $maxLines <= 0) return [];
    $fh = fopen($file, 'rb');
    if (!$fh) return [];
    fseek($fh, 0, SEEK_END);
    $pos = ftell($fh);
    if ($pos === false || $pos <= 0) { fclose($fh); return []; }

    $buffer = '';
    $chunk = 65536;
    $newlines = 0;
    while ($pos > 0 && $newlines <= $maxLines) {
        $read = min($chunk, $pos);
        $pos -= $read;
        fseek($fh, $pos, SEEK_SET);
        $part = fread($fh, $read);
        if ($part === false) break;
        $buffer = $part . $buffer;
        $newlines = substr_count($buffer, "\n");
        if (strlen($buffer) > 16 * 1024 * 1024) break;
    }
    fclose($fh);
    $lines = preg_split('/\r?\n/', trim($buffer)) ?: [];
    if (count($lines) > $maxLines) $lines = array_slice($lines, -$maxLines);
    return $lines;
}

function family_from_identifier(?string $value): ?string
{
    $value = strtolower(trim((string)$value));
    if ($value === '') return null;
    if (str_contains($value, 'wmr200')) return 'wmr200';
    foreach (['wmr100','wmr88','wmr180','wmrs200'] as $identifier) {
        if (str_contains($value, $identifier)) return 'wmr100';
    }
    return null;
}

function trace_path_is_allowed(string $path): bool
{
    $path = trim($path);
    if ($path === '' || !str_starts_with($path, '/') || !preg_match('/\.jsonl$/i', basename($path))) return false;
    foreach (MANUAL_TRACE_ALLOWED_ROOTS as $root) {
        $root = rtrim($root, '/');
        if ($path === $root || str_starts_with($path, $root.'/')) return true;
    }
    return false;
}

function detect_weewx_configuration(?array $paths = null): array
{
    $result = [
        'family'=>null, 'station_type'=>null, 'driver'=>null,
        'trace_path'=>null, 'trace_enabled'=>null, 'config_path'=>null,
    ];
    foreach ($paths ?? WEEWX_CONFIG_CANDIDATES as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) continue;

        $sections = [];
        $section = '';
        foreach ($lines as $line) {
            if (preg_match('/^\s*\[([^\[\]]+)\]\s*(?:[#;].*)?$/', $line, $m)) {
                $section = strtolower(trim($m[1]));
                continue;
            }
            if ($section === '' || !preg_match('/^\s*([A-Za-z0-9_.-]+)\s*=\s*(.*?)\s*$/', $line, $m)) continue;
            $value = trim(preg_replace('/\s+[#;].*$/', '', $m[2]) ?? $m[2]);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            $sections[$section][strtolower($m[1])] = $value;
        }

        $stationType = $sections['station']['station_type'] ?? null;
        $stationSection = strtolower(trim((string)$stationType));
        $stationValues = $stationSection !== '' ? ($sections[$stationSection] ?? []) : [];
        $driver = $stationValues['driver'] ?? null;
        $family = family_from_identifier($stationType) ?? family_from_identifier($driver);
        if ($family === null) continue;

        $tracePath = trim((string)($stationValues['developer_trace_path'] ?? ''));
        if (!trace_path_is_allowed($tracePath)) $tracePath = null;
        $enabledRaw = strtolower(trim((string)($stationValues['developer_trace'] ?? '')));
        $enabled = $enabledRaw === '' ? null : in_array($enabledRaw, ['1','true','yes','on'], true);
        return [
            'family'=>$family, 'station_type'=>$stationType, 'driver'=>$driver,
            'trace_path'=>$tracePath, 'trace_enabled'=>$enabled, 'config_path'=>$path,
        ];
    }
    return $result;
}

function validate_manual_trace_path(string $path): array
{
    $path = trim($path);
    if ($path === '') return ['path'=>null, 'error'=>null];
    if (str_contains($path, "\0")) return ['path'=>null, 'error'=>'Percorso trace manuale non valido.'];
    if (!str_starts_with($path, '/')) return ['path'=>null, 'error'=>'Il trace manuale deve usare un percorso assoluto.'];
    if (!preg_match('/\.jsonl$/i', basename($path))) {
        return ['path'=>null, 'error'=>'Il trace manuale deve essere un file .jsonl attivo.'];
    }
    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        return ['path'=>null, 'error'=>'Il file trace manuale non esiste.'];
    }
    if (!is_readable($real)) {
        return ['path'=>null, 'error'=>'Il file trace manuale esiste ma non è leggibile dal web server.'];
    }
    $allowed = false;
    foreach (MANUAL_TRACE_ALLOWED_ROOTS as $root) {
        $rootReal = realpath($root);
        if ($rootReal === false) continue;
        $prefix = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($real === $rootReal || str_starts_with($real, $prefix)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        return ['path'=>null, 'error'=>'Percorso trace manuale non consentito. Sono ammessi /var/log/weewx e /tmp.'];
    }
    return ['path'=>$real, 'error'=>null];
}

function probe_trace_file(string $path, string $familyHint): ?array
{
    if (!is_file($path) || !is_readable($path)) return null;
    $lines = tail_lines($path, DETECTION_TAIL_LINES);
    $latestTimestamp = null;
    $latestEpoch = null;
    $driver = null;
    $version = null;
    $model = null;
    $family = in_array($familyHint, ['wmr100','wmr200'], true) ? $familyHint : 'wmr100';
    $score100 = 0;
    $score200 = 0;

    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $row = json_decode(trim($lines[$i]), true);
        if (!is_array($row)) continue;
        $eventName = (string)($row['event'] ?? '');
        if (str_starts_with($eventName, 'protocol_packet_') || str_starts_with($eventName, 'usb_reopen_') || in_array($eventName, ['usb_read_pipe_stall','protocol_stream_resync','usb_health_snapshot'], true)) $score200 += 3;
        if (str_starts_with($eventName, 'parser_') || in_array($eventName, ['packet_valid','loop_packet','unknown_packet','usb_soft_reinitialisation_start','usb_recovery_start','health_statistics'], true)) $score100 += 3;
        if ($latestTimestamp === null && isset($row['timestamp_utc'])) {
            $latestTimestamp = (string)$row['timestamp_utc'];
            $latestEpoch = timestamp_epoch($latestTimestamp);
        }
        if (isset($row['driver'])) {
            $d = strtoupper((string)$row['driver']);
            if ($d === 'WMR200') { $family = 'wmr200'; $score200 += 20; }
            if ($d === 'WMR100') { $family = 'wmr100'; $score100 += 20; }
            $driver = $d;
        }

        // Hardened WMR100/WMR88 traces repeat driver_version and model on
        // every JSONL event. Do not depend on driver_start still being in the
        // active file after log rotation.
        if (($version === null || $version === '') && isset($row['driver_version'])) {
            $version = (string)$row['driver_version'];
        } elseif (($version === null || $version === '') && isset($row['version'])) {
            $version = (string)$row['version'];
        }
        if (($model === null || $model === '') && isset($row['model'])) {
            $model = (string)$row['model'];
        }

        if (($row['event'] ?? '') === 'driver_start') {
            $driver = (string)($row['driver'] ?? ($family === 'wmr200' ? 'WMR200' : 'WMR100'));
            $version = (string)($row['driver_version'] ?? $row['version'] ?? '');
            $model = (string)($row['model'] ?? '');
            break;
        }
    }
    if ($model !== null && $model !== '') {
        $mu = strtoupper($model);
        if (str_starts_with($mu, 'WMR200')) $score200 += 10;
        if (str_starts_with($mu, 'WMR100') || str_starts_with($mu, 'WMR88') || str_starts_with($mu, 'WMR180') || str_starts_with($mu, 'WMRS200')) $score100 += 10;
    }
    if ($familyHint === 'auto') {
        if ($score200 > $score100) $family = 'wmr200';
        elseif ($score100 > $score200) $family = 'wmr100';
    }
    if ($latestEpoch === null) {
        $mtime = @filemtime($path);
        $latestEpoch = $mtime !== false ? (float)$mtime : 0.0;
    }
    return [
        'family'=>$family, 'path'=>$path, 'latest_timestamp'=>$latestTimestamp,
        'latest_epoch'=>$latestEpoch, 'driver'=>$driver, 'version'=>$version,
        'model'=>$model, 'size'=>(int)@filesize($path), 'mtime'=>(int)@filemtime($path),
    ];
}


function find_latest_driver_start_context(string $traceFile): ?array
{
    // Metadata such as VID/PID, endpoint, profile and timeout thresholds is
    // emitted by WMR100/WMR88 primarily in driver_start. The active JSONL can
    // be a rotated continuation that no longer contains that event. Search the
    // active file first, then the configured backups, without adding those
    // backup records to counters/statistics.
    $candidates = [$traceFile];
    for ($i = 1; $i <= TRACE_BACKUPS; $i++) {
        $candidates[] = $traceFile . '.' . $i;
    }

    foreach ($candidates as $candidate) {
        if (!is_file($candidate) || !is_readable($candidate)) continue;
        $lines = tail_lines($candidate, 50000);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $row = json_decode(trim($lines[$i]), true);
            if (!is_array($row) || ($row['event'] ?? '') !== 'driver_start') continue;
            $row['_context_file'] = $candidate;
            return $row;
        }
    }
    return null;
}

function enrich_source_with_start_context(array $source): array
{
    $path = (string)($source['path'] ?? '');
    if ($path === '') return $source;

    $context = find_latest_driver_start_context($path);
    if ($context === null) return $source;

    $source['startup_context'] = $context;
    $source['startup_timestamp'] = isset($context['timestamp_utc']) ? (string)$context['timestamp_utc'] : null;

    if ((!isset($source['driver']) || !$source['driver']) && isset($context['driver'])) {
        $source['driver'] = (string)$context['driver'];
    }
    if ((!isset($source['version']) || !$source['version'])) {
        $source['version'] = (string)($context['driver_version'] ?? $context['version'] ?? '');
    }
    if ((!isset($source['model']) || !$source['model']) && isset($context['model'])) {
        $source['model'] = (string)$context['model'];
    }
    return $source;
}

function detect_installed_driver_version(string $family, ?array $paths = null): ?array
{
    $family = $family === 'wmr200' ? 'wmr200' : 'wmr100';
    foreach ($paths ?? DRIVER_SOURCE_CANDIDATES[$family] as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        $source = @file_get_contents($path, false, null, 0, 262144);
        if (!is_string($source) || $source === '') continue;
        if (!preg_match('/^\s*DRIVER_VERSION\s*=\s*["\']([^"\']+)["\']/m', $source, $versionMatch)) continue;
        preg_match('/^\s*DRIVER_NAME\s*=\s*["\']([^"\']+)["\']/m', $source, $nameMatch);
        return [
            'version'=>trim($versionMatch[1]),
            'driver'=>isset($nameMatch[1]) ? trim($nameMatch[1]) : strtoupper($family),
            'path'=>$path,
        ];
    }
    return null;
}

function enrich_source_with_installed_driver(array $source, ?array $paths = null): array
{
    $currentVersion = trim((string)($source['version'] ?? ''));
    if ($currentVersion !== '' && strtolower($currentVersion) !== 'sconosciuta') return $source;
    $installed = detect_installed_driver_version((string)($source['family'] ?? 'wmr100'), $paths);
    if ($installed === null) return $source;
    $source['version'] = $installed['version'];
    if (empty($source['driver'])) $source['driver'] = $installed['driver'];
    $source['installed_driver_path'] = $installed['path'];
    $source['version_source'] = 'installed_driver';
    return $source;
}

function detect_trace_source(string $requested = 'auto', string $traceMode = 'auto', string $manualTrace = '', ?array $configPaths = null): array
{
    $requested = strtolower($requested);
    if (!in_array($requested, ['auto','wmr100','wmr200'], true)) $requested = 'auto';

    $traceMode = strtolower($traceMode);
    if (!in_array($traceMode, ['auto','manual'], true)) $traceMode = 'auto';

    // Manual mode is explicit: never silently switch back to another trace.
    if ($traceMode === 'manual') {
        $manual = validate_manual_trace_path($manualTrace);
        if ($manual['path'] !== null) {
            $hint = $requested === 'auto' ? 'auto' : $requested;
            $probe = probe_trace_file((string)$manual['path'], $hint);
            if ($probe !== null) {
                $probe['mode'] = 'manual_trace';
                $probe['trace_mode'] = 'manual';
                $probe['auto_detected'] = false;
                $probe['manual_trace'] = true;
                $probe['manual_trace_error'] = null;
                $probe['probes'] = [$probe];
                return $probe;
            }
        }

        $family = $requested === 'wmr200' ? 'wmr200' : 'wmr100';
        return [
            'family'=>$family,
            'path'=>$manualTrace,
            'latest_timestamp'=>null,'latest_epoch'=>0.0,'driver'=>null,'version'=>null,'model'=>null,
            'size'=>0,'mtime'=>0,'mode'=>'manual_trace','trace_mode'=>'manual','auto_detected'=>false,
            'manual_trace'=>true,
            'manual_trace_error'=>$manual['error'] ?? 'Il trace manuale non è disponibile.',
            'probes'=>[],
        ];
    }

    // Automatic mode deliberately ignores trace_file, even if an old value
    // is still present in the URL. Read WeeWX configuration as a second,
    // independent source of truth: this identifies the family and a custom
    // developer_trace_path even before the JSONL file has been created.
    $config = detect_weewx_configuration($configPaths);
    $probes = [];
    $candidateMap = TRACE_CANDIDATES;
    if ($config['family'] !== null && $config['trace_path'] !== null) {
        array_unshift($candidateMap[$config['family']], $config['trace_path']);
        $candidateMap[$config['family']] = array_values(array_unique($candidateMap[$config['family']]));
    }
    foreach ($candidateMap as $family => $paths) {
        if ($requested !== 'auto' && $requested !== $family) continue;
        foreach ($paths as $path) {
            $probe = probe_trace_file($path, $family);
            if ($probe !== null) $probes[] = $probe;
        }
    }
    usort($probes, fn($a,$b) => ($b['latest_epoch'] <=> $a['latest_epoch']));
    if ($probes) {
        $selected = $probes[0];
        $selected['mode'] = $requested;
        $selected['trace_mode'] = 'auto';
        $selected['auto_detected'] = $requested === 'auto';
        $selected['manual_trace'] = false;
        $selected['manual_trace_error'] = null;
        $selected['detection_basis'] = 'trace';
        $selected['configured_family'] = $config['family'];
        $selected['config_path'] = $config['config_path'];
        $selected['trace_enabled'] = $config['trace_enabled'];
        $selected['probes'] = $probes;
        return $selected;
    }
    $configuredFamily = in_array($config['family'], ['wmr100','wmr200'], true) ? $config['family'] : null;
    $family = $requested === 'wmr200' ? 'wmr200' : ($requested === 'wmr100' ? 'wmr100' : ($configuredFamily ?? 'wmr100'));
    $configuredPath = $configuredFamily === $family ? $config['trace_path'] : null;
    return [
        'family'=>$family,
        'path'=>$configuredPath ?? TRACE_CANDIDATES[$family][0],
        'latest_timestamp'=>null,'latest_epoch'=>0.0,'driver'=>null,'version'=>null,'model'=>null,
        'size'=>0,'mtime'=>0,'mode'=>$requested,'trace_mode'=>'auto',
        'auto_detected'=>$requested==='auto'&&$configuredFamily!==null,
        'manual_trace'=>false,'manual_trace_error'=>null,
        'detection_basis'=>$requested==='auto'&&$configuredFamily!==null?'config':'fallback',
        'configured_family'=>$configuredFamily,'config_path'=>$config['config_path'],
        'trace_enabled'=>$config['trace_enabled'],'probes'=>[],
    ];
}

function family_title(string $family): string
{
    return $family === 'wmr200' ? 'WMR200 / WMR200A' : 'WMR100 / WMR88 / WMR88A';
}

function csv_value(mixed $value): string
{
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_array($value)) return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    return (string)$value;
}

// -----------------------------------------------------------------------------
// Driver/protocol helpers
// -----------------------------------------------------------------------------
function normalize_packet_name(string $family, string $name): string
{
    $n = trim($name);
    if ($family === 'wmr200') {
        $map = [
            'Temperature'=>'temperature_humidity','Pressure'=>'pressure','Wind'=>'wind',
            'Rain'=>'rain','UVI'=>'uv','Status'=>'status','Archive Data'=>'archive',
            'CmdAck'=>'control','EraseAck'=>'control','LiveData'=>'control',
        ];
        return $map[$n] ?? strtolower(str_replace(' ', '_', $n ?: 'unknown'));
    }
    return strtolower($n ?: 'unknown');
}

function packet_label(string $name): string
{
    return [
        'temperature_humidity'=>'Temperatura / umidità','temperature_only'=>'Solo temperatura',
        'pressure'=>'Pressione','wind'=>'Vento','rain'=>'Pioggia','uv'=>'UV',
        'status'=>'Stato sensori','clock'=>'Orologio','archive'=>'Archivio','control'=>'Controllo',
    ][$name] ?? $name;
}

function packet_expected_interval(string $packetName, string $family = 'wmr100', string $model = ''): float
{
    $model = strtoupper($model);
    if ($family === 'wmr100' && in_array($model, ['WMR88','WMR88A','WMR180','WMR180A'], true)) {
        return [
            'wind'=>56.0,'temperature_humidity'=>102.0,'temperature_only'=>102.0,
            'pressure'=>180.0,'rain'=>180.0,'uv'=>180.0,'status'=>300.0,'clock'=>300.0,
        ][$packetName] ?? 180.0;
    }
    return [
        'wind'=>60.0,'temperature_humidity'=>120.0,'temperature_only'=>120.0,
        'pressure'=>180.0,'rain'=>180.0,'uv'=>180.0,'status'=>300.0,'clock'=>300.0,
    ][$packetName] ?? 180.0;
}

function packet_freshness(string $packetName, ?float $age, ?float $averageInterval = null, string $family='wmr100', string $model=''): array
{
    if ($age === null) return ['missing', 'Assente'];
    $base = packet_expected_interval($packetName, $family, $model);
    $warningAt = max($base * 1.6, ($averageInterval ?? 0) * 2.5);
    $staleAt = max($base * 4.0, ($averageInterval ?? 0) * 6.0);
    if ($age <= $warningAt) return ['fresh', 'Aggiornato'];
    if ($age <= $staleAt) return ['delayed', 'Ritardato'];
    return ['stale', 'Obsoleto'];
}

function event_severity(array $row, array $meta = [], string $family='wmr100'): string
{
    $event = (string)($row['event'] ?? 'unknown');
    $declared = strtolower((string)($row['severity'] ?? ''));
    if (in_array($declared, ['critical','error','warning','info'], true)) return $declared;

    if ($event === 'usb_read_timeout') {
        $n = max(1, (int)($row['timeout_consecutive'] ?? 1));
        if ($family === 'wmr200') {
            $warn = max(1,(int)($meta['timeout_warning_threshold'] ?? 2));
            $err = max($warn,(int)($meta['timeout_error_threshold'] ?? 4));
            return $n >= $err ? 'error' : ($n >= $warn ? 'warning' : 'info');
        }
        $warn = max(1,(int)($meta['timeout_warning_threshold'] ?? 8));
        $recovery = max($warn,(int)($meta['timeout_recovery_threshold'] ?? 20));
        return $n >= $recovery ? 'error' : ($n >= $warn ? 'warning' : 'info');
    }

    if ($event === 'health_state_change') {
        $new = strtolower((string)($row['new_state'] ?? $row['health_state'] ?? ''));
        if (in_array($new,['failed','critical'],true)) return 'critical';
        if (in_array($new,['degraded','recovering','warning'],true)) return 'warning';
        return 'info';
    }

    $critical = ['usb_recovery_exhausted','usb_device_not_found','usb_open_failure','usb_reopen_failed','usb_open_failed','trace_writer_failed'];
    $errors = [
        'usb_control_error','usb_read_error','usb_read_unexpected_error','usb_write_error','usb_read_fatal','usb_write_fatal',
        'usb_soft_reinitialisation_failed','usb_recovery_attempt_failed','usb_read_pipe_stall',
        'packet_checksum_error','packet_length_error','packet_too_short','packet_decode_error','usb_report_malformed',
        'protocol_packet_checksum_error','protocol_packet_checksum_dropped','protocol_packet_malformed_dropped','protocol_packet_unhandled_error'
    ];
    $warnings = [
        'usb_release_warning','usb_recovery_start','usb_soft_reinitialisation_start','usb_reopen_begin','usb_clear_halt_failed',
        'parser_buffer_overflow','unknown_packet','packet_unmapped','sensor_value_suspicious','sensor_channel_outside_model_profile',
        'wind_gust_inconsistent','rain_counter_reset','clock_packet_invalid','protocol_stream_resync'
    ];
    if (in_array($event,$critical,true)) return 'critical';
    if (in_array($event,$errors,true)) return 'error';
    if (in_array($event,$warnings,true)) return 'warning';
    return 'info';
}

function event_is_actionable(string $event, string $severity): bool
{
    if (!in_array($severity,['critical','error','warning'],true)) return false;
    if (in_array($event,[
        'usb_recovery_start','usb_soft_reinitialisation_start','usb_reopen_begin','protocol_stream_resync'
    ],true)) return false;
    return true;
}

function parse_hex_bytes(?string $hex): array
{
    if (!$hex) return [];
    $parts = preg_split('/[^0-9A-Fa-f]+/', trim($hex)) ?: [];
    $out=[];
    foreach($parts as $p){ if($p==='')continue; if(strlen($p)>2)return []; $out[]=hexdec($p)&0xFF; }
    return $out;
}

function shifted_frame_candidate(?string $rawHex): ?array
{
    $raw=parse_hex_bytes($rawHex); if(!$raw)return null;
    $names=[0x41=>'rain',0x42=>'temperature_humidity',0x44=>'temperature_only',0x46=>'pressure',0x47=>'uv',0x48=>'wind',0x60=>'clock'];
    $lengths=[0x41=>17,0x42=>12,0x46=>8,0x47=>6,0x48=>11,0x60=>12];
    $candidate=array_merge([0x00],$raw); if(count($candidate)<4)return null;
    $type=$candidate[1]; if(!isset($names[$type]))return null;
    if(isset($lengths[$type])&&count($candidate)!==$lengths[$type])return null;
    if($type===0x44&&count($candidate)<7)return null;
    $calc=array_sum(array_slice($candidate,0,-2))&0xFFFF;
    $received=(($candidate[count($candidate)-1]&0xFF)<<8)+($candidate[count($candidate)-2]&0xFF);
    if($calc!==$received)return null;
    return ['packet_type'=>sprintf('0x%02X',$type),'packet_name'=>$names[$type],'length'=>count($candidate),
        'checksum'=>sprintf('0x%04X',$received),'raw_hex'=>implode(' ',array_map(fn($b)=>sprintf('%02X',$b),$candidate))];
}

function event_details(array $row, string $family='wmr100'): string
{
    $event=(string)($row['event']??'unknown'); $parts=[];
    $preferred=[
        'severity','health_state','previous_state','new_state','classification','reason','error','errno','status','occurrence',
        'packet_id','packet_type','packet_name','command','packet_length','actual_size','expected_size','length',
        'checksum_applicable','checksum_calculated','checksum_received','checksum_drop_total',
        'interface','in_endpoint','endpoint','value','raw_hex','hex','report_length','payload_count',
        'timeout_seconds','timeout_consecutive','timeout_total','timeout_bursts','max_consecutive_timeouts',
        'last_success_utc','seconds_since_last_success','silence_seconds','recovered_timeouts','successful_reads',
        'read_timeouts','read_pipe_stalls','write_transient_errors','malformed_reports','reopens','attempt','retries',
        'recovery_cycle','recovery_attempt','recovery','action','impact','gap_count','stream_gaps','protocol_resyncs',
        'discarded_buffered_bytes','dropped_packet_name','dropped_actual_size','dropped_expected_size','resync_total',
        'sensor','channel','field','max_remote_channels','previous_total','current_total','wind_speed','wind_gust',
        'driver_version','version','model','model_profile','thread'
    ];
    foreach($preferred as $key){
        if(!array_key_exists($key,$row)||$row[$key]===null||$row[$key]==='')continue;
        $v=$row[$key]; if(is_bool($v))$v=$v?'true':'false';
        if(is_array($v))$v=json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $parts[]=$key.'='.(string)$v;
    }
    $record=null;
    if($event==='loop_packet'&&isset($row['fields'])&&is_array($row['fields']))$record=$row['fields'];
    if($event==='protocol_packet_decoded'&&isset($row['record'])&&is_array($row['record']))$record=$row['record'];
    if($record!==null){
        $brief=[]; foreach($record as $k=>$v){if(in_array($k,['dateTime','usUnits'],true)||$v===null)continue;$brief[]=$k.'='.csv_value($v);}
        if($brief)$parts[]='record{'.implode(', ',array_slice($brief,0,14)).'}';
    }
    if($family==='wmr100'&&$event==='unknown_packet'){
        $candidate=shifted_frame_candidate((string)($row['raw_hex']??''));
        if($candidate)$parts[]='possible_shifted_frame='.$candidate['packet_type'].'/'.$candidate['packet_name'].', checksum valid';
    }
    return implode(' · ',$parts);
}

function common_field_from_wmr200(string $key): string
{
    $direct=[
        'pressure'=>'pressure','altimeter'=>'altimeter','wind_speed'=>'windSpeed','wind_gust'=>'windGust','wind_dir'=>'windDir',
        'windchill'=>'windchill','rain'=>'rain','rain_rate'=>'rainRate','rain_hour'=>'hourRain','rain_24'=>'rain24','rain_total'=>'rainTotal',
        'uv'=>'UV','forecast_icon'=>'forecastIcon','battery_status_out'=>'outTempBatteryStatus','battery_status_wind'=>'windBatteryStatus',
        'battery_status_rain'=>'rainBatteryStatus','battery_status_uv'=>'uvBatteryStatus','out_fault'=>'outTempFault','wind_fault'=>'windFault',
        'rain_fault'=>'rainFault','uv_fault'=>'uvFault','clock_unsynchronized'=>'clockUnsynchronized'
    ];
    if(isset($direct[$key]))return $direct[$key];
    // Forward-compatible aliases. The current WMR200 Status packet does not
    // expose console backup-battery state, but future/custom drivers may do so.
    if(in_array($key,['battery_status_console','battery_status_in','battery_status_0'],true))return 'inTempBatteryStatus';
    if(preg_match('/^temperature_(\d+)$/',$key,$m))return channel_field((int)$m[1],'temp');
    if(preg_match('/^humidity_(\d+)$/',$key,$m))return channel_field((int)$m[1],'hum');
    if(preg_match('/^heatindex_(\d+)$/',$key,$m))return channel_field((int)$m[1],'heat');
    if(preg_match('/^battery_status_(\d+)$/',$key,$m))return channel_field((int)$m[1],'battery');
    return $key;
}

function channel_field(int $ch, string $kind): string
{
    if($kind==='temp'){
        if($ch===0)return 'inTemp'; if($ch===1)return 'outTemp'; if($ch<=8)return 'extraTemp'.($ch-1); return 'channelTemp'.$ch;
    }
    if($kind==='hum'){
        if($ch===0)return 'inHumidity'; if($ch===1)return 'outHumidity'; if($ch<=8)return 'extraHumid'.($ch-1); return 'channelHumid'.$ch;
    }
    if($kind==='heat'){
        if($ch===0)return 'inHeatindex'; if($ch===1)return 'heatindex'; if($ch<=8)return 'heatindex'.($ch-1); return 'channelHeatindex'.$ch;
    }
    if($kind==='battery'){
        if($ch===0)return 'inTempBatteryStatus'; if($ch===1)return 'outTempBatteryStatus'; if($ch<=8)return 'extraBatteryStatus'.($ch-1); return 'channelBattery'.$ch;
    }
    return $kind.$ch;
}

function forecast_info(int $code, string $family='wmr100'): array
{
    // WMR100 protocol reference: 0 Partly Cloudy, 1 Rainy, 2 Cloudy,
    // 3 Sunny, 5 Snowy. Value 4 is explicitly unverified in the protocol
    // notes. WMR200 upstream driver additionally defines night variants 4/6.
    $maps = [
        'wmr100' => [
            0 => ['label'=>'Parzialmente nuvoloso','kind'=>'partly-cloudy'],
            1 => ['label'=>'Pioggia','kind'=>'rainy'],
            2 => ['label'=>'Nuvoloso','kind'=>'cloudy'],
            3 => ['label'=>'Soleggiato','kind'=>'sunny'],
            4 => ['label'=>'Codice non documentato','kind'=>'unknown'],
            5 => ['label'=>'Neve','kind'=>'snowy'],
            6 => ['label'=>'Codice non documentato','kind'=>'unknown'],
            7 => ['label'=>'Codice non documentato','kind'=>'unknown'],
        ],
        'wmr200' => [
            0 => ['label'=>'Parzialmente nuvoloso','kind'=>'partly-cloudy'],
            1 => ['label'=>'Pioggia','kind'=>'rainy'],
            2 => ['label'=>'Nuvoloso','kind'=>'cloudy'],
            3 => ['label'=>'Soleggiato','kind'=>'sunny'],
            4 => ['label'=>'Sereno notte','kind'=>'clear-night'],
            5 => ['label'=>'Neve','kind'=>'snowy'],
            6 => ['label'=>'Parzialmente nuvoloso notte','kind'=>'partly-cloudy-night'],
            7 => ['label'=>'Sconosciuto','kind'=>'unknown'],
        ],
    ];
    $family = $family === 'wmr200' ? 'wmr200' : 'wmr100';
    $info = $maps[$family][$code] ?? ['label'=>'Codice sconosciuto','kind'=>'unknown'];
    $info['code'] = $code;
    return $info;
}

function forecast_icon_svg(int $code, string $family='wmr100'): string
{
    $kind = forecast_info($code,$family)['kind'];
    // Inline SVG keeps the dashboard standalone and avoids external assets.
    $sun = '<circle cx="27" cy="24" r="8"/><path d="M27 5v6M27 37v6M8 24h6M40 24h6M13.5 10.5l4.2 4.2M36.3 33.3l4.2 4.2M40.5 10.5l-4.2 4.2M17.7 33.3l-4.2 4.2"/>';
    $cloud = '<path d="M17 39h25a9 9 0 0 0 1.2-17.9A13 13 0 0 0 18.7 18 10.5 10.5 0 0 0 17 39Z"/>';
    $smallCloud = '<path d="M22 40h21a8 8 0 0 0 .7-15.9A11 11 0 0 0 23 22a9 9 0 0 0-1 18Z"/>';
    $moon = '<path d="M36 9a15 15 0 1 0 12 23A16 16 0 0 1 36 9Z"/>';
    $rain = '<path d="M20 45l-3 7M30 45l-3 7M40 45l-3 7"/>';
    $snow = '<path d="M18 45v10M13.7 47.5l8.6 5M22.3 47.5l-8.6 5M34 45v10M29.7 47.5l8.6 5M38.3 47.5l-8.6 5"/>';
    $q = '<circle cx="30" cy="30" r="23"/><path d="M23 23a7 7 0 1 1 10 6.3c-2.4 1.3-3 2.4-3 5.2M30 43h.01"/>';
    $body='';
    if($kind==='sunny') $body=$sun;
    elseif($kind==='partly-cloudy') $body='<g class="sun-part">'.$sun.'</g><g class="cloud-part" transform="translate(4 5)">'.$smallCloud.'</g>';
    elseif($kind==='cloudy') $body='<g transform="translate(1 3)">'.$cloud.'</g>';
    elseif($kind==='rainy') $body='<g transform="translate(1 -1)">'.$cloud.'</g>'.$rain;
    elseif($kind==='snowy') $body='<g transform="translate(1 -1)">'.$cloud.'</g>'.$snow;
    elseif($kind==='clear-night') $body=$moon;
    elseif($kind==='partly-cloudy-night') $body='<g class="moon-part" transform="translate(-4 -2)">'.$moon.'</g><g class="cloud-part" transform="translate(5 6)">'.$smallCloud.'</g>';
    else $body=$q;
    return '<svg class="forecast-svg forecast-'.$kind.'" viewBox="0 0 60 60" role="img" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'.$body.'</svg>';
}

function decode_wmr100_pressure_metadata(?string $rawHex): ?array
{
    $bytes = parse_hex_bytes($rawHex);
    // WMR100/WMR88 pressure packet (0x46):
    //   [0] status
    //   [1] 0x46
    //   [2] station pressure low byte
    //   [3] forecast code (high nibble) + station pressure high nibble
    //   [4] console relative/SLP pressure low byte
    //   [5] previous forecast code (high nibble) + relative pressure high nibble
    //   [6..7] checksum
    //
    // These values are transmitted by the console. In particular, the
    // forecast code is NOT calculated by this dashboard.
    if(count($bytes)!==8 || ($bytes[1]??null)!==0x46) return null;
    return [
        'station_pressure' => (float)(((($bytes[3] & 0x0F) << 8) + $bytes[2])),
        'console_barometer' => (float)(((($bytes[5] & 0x0F) << 8) + $bytes[4])),
        'forecast_code' => (($bytes[3] >> 4) & 0x0F),
        'previous_forecast_code' => (($bytes[5] >> 4) & 0x0F),
    ];
}

function decode_wmr100_forecast(?string $rawHex): ?int
{
    $meta = decode_wmr100_pressure_metadata($rawHex);
    return $meta===null ? null : (int)$meta['forecast_code'];
}

function store_wmr100_pressure_metadata(array &$a, array $data, ?string $timestamp, ?float $epoch): void
{
    $meta = decode_wmr100_pressure_metadata((string)($data['raw_hex']??''));
    if($meta===null) return;

    // gp6 exposes forecastIcon directly in loop_packet. Keep this raw-packet
    // fallback for gp5 and older traces; when a gp6 loop_packet follows, the
    // driver-provided value naturally replaces this entry.
    $a['weather_latest']['forecastIcon'] = [
        'value'=>(int)$meta['forecast_code'],
        'timestamp'=>$timestamp,
        'epoch'=>$epoch,
        'packet'=>'pressure',
        'usUnits'=>null,
        'native_field'=>'forecast_code',
        'source'=>'pressure_packet_raw_fallback',
    ];

    // The second pressure value is transmitted by the console and is useful
    // diagnostically, but it is NOT WeeWX altimeter. Keep a distinct name.
    $a['weather_latest']['consoleBarometer'] = [
        'value'=>(float)$meta['console_barometer'],
        'timestamp'=>$timestamp,
        'epoch'=>$epoch,
        'packet'=>'pressure',
        'usUnits'=>1,
        'native_field'=>'console_barometer',
        'source'=>'pressure_packet_raw',
    ];
    $a['weather_latest']['previousForecastCode'] = [
        'value'=>(int)$meta['previous_forecast_code'],
        'timestamp'=>$timestamp,
        'epoch'=>$epoch,
        'packet'=>'pressure',
        'usUnits'=>null,
        'native_field'=>'previous_forecast_code',
        'source'=>'pressure_packet_raw',
    ];
}

function weather_value(string $key, mixed $value, string $family='wmr100'): string
{
    if($value===null||$value==='')return 'N/A'; $n=is_numeric($value)?(float)$value:null; if($n===null)return (string)$value;
    if(preg_match('/Temp|temperature|dewpoint|windchill|heatindex/i',$key))return number_format($n,1,',','.').' °C';
    if(preg_match('/Humidity|humidity/i',$key))return number_format($n,0,',','.').' %';
    if(in_array($key,['pressure','barometer','altimeter','consoleBarometer'],true))return number_format($n,1,',','.').' hPa';
    if(in_array($key,['windSpeed','windGust'],true)){
        if($family==='wmr200')return number_format($n,1,',','.').' km/h ('.number_format($n/3.6,1,',','.').' m/s)';
        return number_format($n,1,',','.').' m/s ('.number_format($n*3.6,1,',','.').' km/h)';
    }
    if($key==='windDir')return number_format($n,1,',','.').'° '.compass_direction($n);
    if(in_array($key,['rain','rainRate','hourRain','rain24','rainTotal'],true)){
        // WMR200 driver works in METRIC: rain is centimetres. WMR100 rain packet is US: inches.
        $mm=$family==='wmr200'?$n*10.0:$n*25.4;
        $suffix=$key==='rainRate'?' mm/h':' mm';
        return number_format($mm,2,',','.').$suffix;
    }
    if($key==='UV')return number_format($n,1,',','.');
    if(stripos($key,'BatteryStatus')!==false)return ((int)$n===0)?'OK':'Batteria bassa ('.(int)$n.')';
    if(str_ends_with($key,'Fault'))return ((int)$n===0)?'OK':'Guasto';
    if($key==='clockUnsynchronized')return ((int)$n===0)?'Sincronizzato':'Non sincronizzato';
    if($key==='forecastIcon'){ $f=forecast_info((int)$n,$family); return $f['label'].' · codice '.(int)$n; }
    return number_format($n,2,',','.');
}

function battery_state(mixed $value): array
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return ['unknown', 'Non disponibile'];
    }
    return ((int)$value === 0)
        ? ['ok', 'Carica']
        : ['low', 'Bassa'];
}

function sparkline_svg(array $values): string
{
    $values=array_values(array_filter($values,'is_numeric')); if(count($values)<2)return '';
    if(count($values)>SPARKLINE_POINTS)$values=array_slice($values,-SPARKLINE_POINTS);
    $min=min($values);$max=max($values);$range=max(0.000001,$max-$min);$w=116;$hgt=30;$pad=2;$count=count($values);$points=[];
    foreach($values as $i=>$v){$x=$pad+($count===1?0:$i*(($w-2*$pad)/($count-1)));$y=$hgt-$pad-(((float)$v-$min)/$range)*($hgt-2*$pad);$points[]=number_format($x,1,'.','').','.number_format($y,1,'.','');}
    return '<svg class="spark" viewBox="0 0 '.$w.' '.$hgt.'" preserveAspectRatio="none" aria-hidden="true"><polyline points="'.h(implode(' ',$points)).'" fill="none" stroke="currentColor" stroke-width="1.8" vector-effect="non-scaling-stroke"/></svg>';
}

// -----------------------------------------------------------------------------
// Analysis engine
// -----------------------------------------------------------------------------
function new_analysis_state(array $source): array
{
    $ctx = isset($source['startup_context']) && is_array($source['startup_context'])
        ? $source['startup_context'] : [];

    $meta = [
        'family'=>$source['family'],
        'driver'=>$source['driver'] ?? ($source['family']==='wmr200'?'WMR200':'WMR100'),
        'driver_version'=>$source['version']?:'sconosciuta',
        'model'=>$source['model']?:family_title($source['family']),
        'model_profile'=>$source['family']==='wmr200'?'wmr200':'',
        'max_remote_channels'=>$source['family']==='wmr200'?10:3,
        'timeout_warning_threshold'=>$source['family']==='wmr200'?2:8,
        'timeout_error_threshold'=>$source['family']==='wmr200'?4:20,
        'timeout_reinit_threshold'=>$source['family']==='wmr200'?0:12,
        'timeout_recovery_threshold'=>$source['family']==='wmr200'?0:20,
        'driver_start_timestamp'=>$source['startup_timestamp'] ?? null,
    ];

    // Recover WMR100/WMR88 startup-only fields from the active/rotated trace
    // context without counting backup records as live events.
    foreach ([
        'driver','driver_version','version','model','model_profile','vendor_id',
        'product_id','interface','in_endpoint','send_data_request',
        'max_remote_channels','timeout_seconds','timeout_warning_threshold',
        'timeout_reinit_threshold','timeout_recovery_threshold',
        'timeout_error_threshold','archive_interval','erase_archive'
    ] as $key) {
        if (!array_key_exists($key, $ctx) || $ctx[$key] === null || $ctx[$key] === '') continue;
        if ($key === 'version' && !isset($ctx['driver_version'])) {
            $meta['driver_version'] = $ctx[$key];
        } elseif ($key !== 'version') {
            $meta[$key] = $ctx[$key];
        }
    }

    return [
        'family'=>$source['family'],'source'=>$source,
        'records'=>0,'invalid_json'=>0,'bytes'=>0,'scan_stopped'=>false,
        'severity'=>['critical'=>0,'error'=>0,'warning'=>0,'info'=>0],
        'historical_issues'=>0,'recent_issues'=>0,'event_counts'=>[],'packet_counts'=>[],'versions'=>[],
        'first_timestamp'=>null,'last_timestamp'=>null,'last_event_epoch'=>null,'last_rx_timestamp'=>null,'last_rx_epoch'=>null,
        'meta'=>$meta,
        'current_health_state'=>'starting','current_timeout_consecutive'=>0,
        'summary'=>[
            'timeouts'=>0,'max_consecutive_timeouts'=>0,'timeout_episodes'=>0,'timeout_recoveries'=>0,
            'soft_reinit_start'=>0,'soft_reinit_success'=>0,'soft_reinit_failed'=>0,
            'recovery_start'=>0,'recovery_success'=>0,'recovery_attempt_failed'=>0,'recovery_exhausted'=>0,
            'reopen_begin'=>0,'reopen_ok'=>0,'reopen_failed'=>0,'pipe_stalls'=>0,
            'usb_read_errors'=>0,'usb_malformed'=>0,'spurious'=>0,
            'packet_valid'=>0,'packet_complete'=>0,'packet_decoded'=>0,'loop_packets'=>0,'unknown_packets'=>0,
            'checksum_errors'=>0,'length_errors'=>0,'too_short'=>0,'decode_errors'=>0,'malformed_packets'=>0,'unmapped'=>0,
            'parser_resync_requested'=>0,'parser_resync_start'=>0,'parser_synced'=>0,'parser_overflow'=>0,'stream_resync'=>0,
            'sensor_warnings'=>0,
        ],
        'weather_latest'=>[],'weather_history'=>[],'packet_timing'=>[],
        'sessions'=>[],'open_session'=>null,'session_generation'=>0,
        'parser'=>['state'=>'unknown','last_sync'=>null,'last_resync'=>null,'last_resync_reason'=>null,'last_anomaly'=>null],
        'timeline'=>[],'unknown_recent'=>[],'rows'=>[],'recent_events'=>[],
    ];
}

function append_limited(array &$arr, mixed $value, int $limit): void
{
    $arr[]=$value; if(count($arr)>$limit)array_shift($arr);
}

function update_packet_timing(array &$a, string $packetName, ?float $epoch): void
{
    if($epoch===null)return;
    if(!isset($a['packet_timing'][$packetName])||($a['packet_timing'][$packetName]['session']??-1)!==$a['session_generation']){
        $a['packet_timing'][$packetName]=['session'=>$a['session_generation'],'count'=>0,'previous'=>null,'last'=>null,'interval_sum'=>0.0,'interval_count'=>0,'last_interval'=>null,'min_interval'=>null,'max_interval'=>null];
    }
    $t=&$a['packet_timing'][$packetName];$t['count']++;
    if($t['previous']!==null){$interval=max(0.0,$epoch-(float)$t['previous']);$t['last_interval']=$interval;$t['interval_sum']+=$interval;$t['interval_count']++;$t['min_interval']=$t['min_interval']===null?$interval:min($t['min_interval'],$interval);$t['max_interval']=$t['max_interval']===null?$interval:max($t['max_interval'],$interval);}
    $t['previous']=$epoch;$t['last']=$epoch;unset($t);
}

function store_weather_record(array &$a, array $record, string $packetName, ?string $timestamp, ?float $epoch): void
{
    $family=$a['family'];
    foreach($record as $field=>$value){
        if(in_array($field,['dateTime','usUnits','interval','rain_total_last'],true)||$value===null)continue;
        $common=$family==='wmr200'?common_field_from_wmr200((string)$field):(string)$field;
        $a['weather_latest'][$common]=[
            'value'=>$value,'timestamp'=>$timestamp,'epoch'=>$epoch,'packet'=>$packetName,
            'usUnits'=>$record['usUnits']??null,'native_field'=>$field,
            'source'=>$family==='wmr100'?'driver_loop':'driver_decoded',
        ];
        if(is_numeric($value)){
            if(!isset($a['weather_history'][$common]))$a['weather_history'][$common]=[];
            append_limited($a['weather_history'][$common],(float)$value,SPARKLINE_POINTS);
        }
    }
}


function refresh_common_metadata(array &$a, array $data): void
{
    // The hardened WMR100/WMR88 driver repeats driver, driver_version, model,
    // thread and health_state on every trace record. Harvest those common
    // fields continuously so metadata remains available even after rotation.
    if (isset($data['driver']) && (string)$data['driver'] !== '') {
        $a['meta']['driver'] = (string)$data['driver'];
    }
    if (isset($data['driver_version']) && (string)$data['driver_version'] !== '') {
        $a['meta']['driver_version'] = (string)$data['driver_version'];
    } elseif (isset($data['version']) && (string)$data['version'] !== '' &&
              (($a['meta']['driver_version'] ?? '') === '' || ($a['meta']['driver_version'] ?? '') === 'sconosciuta')) {
        $a['meta']['driver_version'] = (string)$data['version'];
    }
    if (isset($data['model']) && (string)$data['model'] !== '') {
        $a['meta']['model'] = (string)$data['model'];
    }

    // These fields are usually present only in driver_start, but accepting
    // them from any event keeps the monitor forward-compatible.
    $direct = [
        'model_profile', 'vendor_id', 'product_id', 'interface', 'in_endpoint',
        'send_data_request', 'max_remote_channels', 'timeout_seconds',
        'timeout_warning_threshold', 'timeout_reinit_threshold',
        'timeout_recovery_threshold', 'timeout_error_threshold',
        'archive_interval', 'erase_archive',
    ];
    foreach ($direct as $key) {
        if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
            $a['meta'][$key] = $data[$key];
        }
    }
}

function start_session(array &$a, array $data, ?string $timestamp): void
{
    $family=$a['family'];
    if($family==='wmr200'){
        $version=(string)($data['version']??$data['driver_version']??'sconosciuta');
        $a['meta']=array_merge($a['meta'],[
            'driver'=>(string)($data['driver']??'WMR200'),'driver_version'=>$version,'model'=>(string)($data['model']??'WMR200'),
            'model_profile'=>'wmr200','archive_interval'=>$data['archive_interval']??null,'erase_archive'=>$data['erase_archive']??null,
            'max_remote_channels'=>10,'timeout_warning_threshold'=>2,'timeout_error_threshold'=>4,
        ]);
    }else{
        $version=(string)($data['driver_version']??$data['version']??'sconosciuta');
        $a['meta']=array_merge($a['meta'],[
            'driver'=>(string)($data['driver']??'WMR100'),'driver_version'=>$version,'model'=>(string)($data['model']??'WMR100'),
            'model_profile'=>(string)($data['model_profile']??''),'vendor_id'=>(string)($data['vendor_id']??''),'product_id'=>(string)($data['product_id']??''),
            'interface'=>$data['interface']??'','in_endpoint'=>(string)($data['in_endpoint']??''),'send_data_request'=>$data['send_data_request']??null,
            'max_remote_channels'=>(int)($data['max_remote_channels']??3),'timeout_seconds'=>(float)($data['timeout_seconds']??15),
            'timeout_warning_threshold'=>(int)($data['timeout_warning_threshold']??8),'timeout_reinit_threshold'=>(int)($data['timeout_reinit_threshold']??12),
            'timeout_recovery_threshold'=>(int)($data['timeout_recovery_threshold']??20),
        ]);
    }
    $a['versions'][$version]=($a['versions'][$version]??0)+1;$a['session_generation']++;
    if($a['open_session']!==null)$a['sessions'][]=$a['open_session'];
    $a['open_session']=['start'=>$timestamp,'stop'=>null,'version'=>$version,'model'=>$a['meta']['model'],'family'=>$family,'timeouts'=>0,'reinit'=>0,'recovery'=>0,'reopens'=>0,'duration'=>null];
}

function process_trace_record(array &$a, array $data, string $rawLine, array $options, float $now): void
{
    $a['records']++;$family=$a['family'];$event=(string)($data['event']??'unknown');
    $timestamp=isset($data['timestamp_utc'])?(string)$data['timestamp_utc']:null;$epoch=timestamp_epoch($timestamp);
    refresh_common_metadata($a,$data);
    if($event==='driver_start')start_session($a,$data,$timestamp);
    $severity=event_severity($data,$a['meta'],$family);$a['severity'][$severity]++;$a['event_counts'][$event]=($a['event_counts'][$event]??0)+1;
    if($timestamp!==null){if($a['first_timestamp']===null)$a['first_timestamp']=$timestamp;$a['last_timestamp']=$timestamp;$a['last_event_epoch']=$epoch;}
    if(isset($data['health_state']))$a['current_health_state']=strtolower((string)$data['health_state']);
    $actionable=event_is_actionable($event,$severity);if($actionable){$a['historical_issues']++;if($epoch!==null&&($now-$epoch)<=RECENT_HEALTH_WINDOW)$a['recent_issues']++;}
    if($epoch!==null&&($now-$epoch)<=RECENT_HEALTH_WINDOW)append_limited($a['recent_events'],['event'=>$event,'severity'=>$severity,'timestamp'=>$timestamp,'data'=>$data],200);

    // Common USB health events.
    if($event==='usb_read_timeout'){
        $a['summary']['timeouts']++;$n=max(1,(int)($data['timeout_consecutive']??1));$a['current_timeout_consecutive']=$n;$a['summary']['max_consecutive_timeouts']=max($a['summary']['max_consecutive_timeouts'],$n);if($n===1)$a['summary']['timeout_episodes']++;if($a['open_session']!==null)$a['open_session']['timeouts']++;
    }elseif($event==='usb_read_recovered'){
        $a['summary']['timeout_recoveries']++;$a['current_timeout_consecutive']=0;$a['current_health_state']='healthy';
        if(isset($data['last_success_utc'])){$a['last_rx_timestamp']=(string)$data['last_success_utc'];$a['last_rx_epoch']=timestamp_epoch($a['last_rx_timestamp']);}elseif($timestamp){$a['last_rx_timestamp']=$timestamp;$a['last_rx_epoch']=$epoch;}
    }elseif($event==='usb_health_snapshot'){
        $a['current_timeout_consecutive']=(int)($data['timeout_consecutive']??0);$a['summary']['max_consecutive_timeouts']=max($a['summary']['max_consecutive_timeouts'],(int)($data['max_consecutive_timeouts']??0));
        if(isset($data['last_success_utc'])){$a['last_rx_timestamp']=(string)$data['last_success_utc'];$a['last_rx_epoch']=timestamp_epoch($a['last_rx_timestamp']);}
    }

    if($family==='wmr100') process_wmr100_record($a,$event,$data,$timestamp,$epoch);
    else process_wmr200_record($a,$event,$data,$timestamp,$epoch);

    if($event==='driver_stop'&&$a['open_session']!==null){
        $a['open_session']['stop']=$timestamp;if($a['open_session']['start']&&$timestamp){$s=timestamp_epoch($a['open_session']['start']);if($s!==null&&$epoch!==null)$a['open_session']['duration']=max(0,$epoch-$s);} $a['sessions'][]=$a['open_session'];$a['open_session']=null;
    }

    $timelineEvents=$family==='wmr200'?
        ['usb_read_timeout','usb_read_recovered','usb_read_pipe_stall','usb_read_error','usb_write_error','usb_reopen_begin','usb_reopen_ok','usb_reopen_failed','protocol_stream_resync','protocol_packet_checksum_dropped','protocol_packet_malformed_dropped']:
        ['usb_read_timeout','usb_soft_reinitialisation_start','usb_soft_reinitialisation_success','usb_soft_reinitialisation_failed','usb_recovery_start','usb_recovery_success','usb_recovery_attempt_failed','usb_recovery_exhausted','usb_read_error','usb_report_malformed','parser_resync_requested','parser_synchronised','unknown_packet'];
    if(in_array($event,$timelineEvents,true))append_limited($a['timeline'],['timestamp'=>$timestamp,'event'=>$event,'severity'=>$severity,'details'=>event_details($data,$family)],50);

    $matches=true;$isTimeout110=$event==='usb_read_timeout'&&(int)($data['errno']??0)===110;
    if(!($options['show_timeout110']??true)&&$isTimeout110)$matches=false;
    if(($options['problems']??true)&&!in_array($severity,['critical','error','warning'],true)&&!(($options['show_timeout110']??true)&&$isTimeout110))$matches=false;
    if(($options['severity']??'all')!=='all'&&$severity!==$options['severity'])$matches=false;
    if(($options['event']??'')!==''&&stripos($event,$options['event'])===false)$matches=false;
    if(($options['q']??'')!==''){$hay=$event.' '.event_details($data,$family).' '.$rawLine;if(stripos($hay,$options['q'])===false)$matches=false;}
    if($matches){$a['rows'][]=['timestamp'=>$timestamp,'severity'=>$severity,'event'=>$event,'direction'=>(string)($data['direction']??''),'sequence'=>(string)($data['sequence']??''),'details'=>event_details($data,$family),'raw'=>$rawLine];if(count($a['rows'])>(int)$options['limit'])array_shift($a['rows']);}
}

function process_wmr100_record(array &$a,string $event,array $data,?string $timestamp,?float $epoch): void
{
    switch($event){
        case 'usb_soft_reinitialisation_start':$a['summary']['soft_reinit_start']++;if($a['open_session']!==null)$a['open_session']['reinit']++;break;
        case 'usb_soft_reinitialisation_success':$a['summary']['soft_reinit_success']++;$a['current_timeout_consecutive']=0;break;
        case 'usb_soft_reinitialisation_failed':$a['summary']['soft_reinit_failed']++;break;
        case 'usb_recovery_start':$a['summary']['recovery_start']++;if($a['open_session']!==null)$a['open_session']['recovery']++;break;
        case 'usb_recovery_success':$a['summary']['recovery_success']++;$a['current_timeout_consecutive']=0;break;
        case 'usb_recovery_attempt_failed':$a['summary']['recovery_attempt_failed']++;break;
        case 'usb_recovery_exhausted':$a['summary']['recovery_exhausted']++;break;
        case 'usb_read_error':case 'usb_read_unexpected_error':$a['summary']['usb_read_errors']++;break;
        case 'usb_report_malformed':$a['summary']['usb_malformed']++;break;
        case 'usb_spurious_no_error':$a['summary']['spurious']++;break;
        case 'packet_checksum_error':$a['summary']['checksum_errors']++;$a['parser']['last_anomaly']=['event'=>$event,'timestamp'=>$timestamp,'data'=>$data];break;
        case 'packet_length_error':$a['summary']['length_errors']++;$a['parser']['last_anomaly']=['event'=>$event,'timestamp'=>$timestamp,'data'=>$data];break;
        case 'packet_too_short':$a['summary']['too_short']++;$a['parser']['last_anomaly']=['event'=>$event,'timestamp'=>$timestamp,'data'=>$data];break;
        case 'packet_decode_error':$a['summary']['decode_errors']++;$a['parser']['last_anomaly']=['event'=>$event,'timestamp'=>$timestamp,'data'=>$data];break;
        case 'packet_unmapped':$a['summary']['unmapped']++;break;
        case 'unknown_packet':$a['summary']['unknown_packets']++;$candidate=shifted_frame_candidate((string)($data['raw_hex']??''));append_limited($a['unknown_recent'],['timestamp'=>$timestamp,'data'=>$data,'candidate'=>$candidate],20);$a['parser']['last_anomaly']=['event'=>$event,'timestamp'=>$timestamp,'data'=>$data,'candidate'=>$candidate];break;
        case 'parser_resync_requested':$a['summary']['parser_resync_requested']++;$a['parser']['state']='resync';$a['parser']['last_resync']=$timestamp;$a['parser']['last_resync_reason']=$data['reason']??null;break;
        case 'parser_resync_start':$a['summary']['parser_resync_start']++;$a['parser']['state']='resync';$a['parser']['last_resync']=$timestamp;$a['parser']['last_resync_reason']=$data['reason']??null;break;
        case 'parser_synchronised':$a['summary']['parser_synced']++;$a['parser']['state']='synced';$a['parser']['last_sync']=$timestamp;break;
        case 'parser_buffer_overflow':$a['summary']['parser_overflow']++;$a['parser']['state']='overflow';$a['parser']['last_anomaly']=['event'=>$event,'timestamp'=>$timestamp,'data'=>$data];break;
        case 'sensor_value_suspicious':case 'sensor_channel_outside_model_profile':case 'wind_gust_inconsistent':case 'clock_packet_invalid':$a['summary']['sensor_warnings']++;break;
        case 'packet_valid':
            $a['summary']['packet_valid']++;$packet=normalize_packet_name('wmr100',(string)($data['packet_name']??'unknown'));$a['packet_counts'][$packet]=($a['packet_counts'][$packet]??0)+1;update_packet_timing($a,$packet,$epoch);
            if($packet==='pressure') store_wmr100_pressure_metadata($a,$data,$timestamp,$epoch);
            if(($a['parser']['state']??'unknown')==='unknown')$a['parser']['state']='synced';if($epoch!==null){$a['last_rx_timestamp']=$timestamp;$a['last_rx_epoch']=$epoch;$a['current_timeout_consecutive']=0;}break;
        case 'loop_packet':
            $a['summary']['loop_packets']++;$packet=normalize_packet_name('wmr100',(string)($data['packet_name']??'unknown'));$fields=isset($data['fields'])&&is_array($data['fields'])?$data['fields']:[];store_weather_record($a,$fields,$packet,$timestamp,$epoch);if($epoch!==null){$a['last_rx_timestamp']=$timestamp;$a['last_rx_epoch']=$epoch;$a['current_timeout_consecutive']=0;}break;
        case 'health_statistics':
            $stats=isset($data['statistics'])&&is_array($data['statistics'])?$data['statistics']:[];if(isset($stats['last_success_utc'])){$a['last_rx_timestamp']=(string)$stats['last_success_utc'];$a['last_rx_epoch']=timestamp_epoch($a['last_rx_timestamp']);}break;
    }
}

function process_wmr200_record(array &$a,string $event,array $data,?string $timestamp,?float $epoch): void
{
    switch($event){
        case 'usb_read_pipe_stall':$a['summary']['pipe_stalls']++;break;
        case 'usb_reopen_begin':$a['summary']['reopen_begin']++;if($a['open_session']!==null)$a['open_session']['reopens']++;break;
        case 'usb_reopen_ok':$a['summary']['reopen_ok']++;$a['current_timeout_consecutive']=0;break;
        case 'usb_reopen_failed':$a['summary']['reopen_failed']++;break;
        case 'usb_read_error':case 'usb_write_error':$a['summary']['usb_read_errors']++;break;
        case 'protocol_packet_checksum_error':case 'protocol_packet_checksum_dropped':$a['summary']['checksum_errors']++;$a['parser']['last_anomaly']=['event'=>$event,'timestamp'=>$timestamp,'data'=>$data];break;
        case 'protocol_packet_malformed_dropped':$a['summary']['malformed_packets']++;$a['parser']['last_anomaly']=['event'=>$event,'timestamp'=>$timestamp,'data'=>$data];break;
        case 'protocol_packet_unhandled_error':$a['summary']['decode_errors']++;$a['parser']['last_anomaly']=['event'=>$event,'timestamp'=>$timestamp,'data'=>$data];break;
        case 'protocol_stream_resync':$a['summary']['stream_resync']++;$a['parser']['state']='resync';$a['parser']['last_resync']=$timestamp;$a['parser']['last_resync_reason']=$data['reason']??null;$a['parser']['last_anomaly']=['event'=>$event,'timestamp'=>$timestamp,'data'=>$data];break;
        case 'protocol_packet_complete':
            $a['summary']['packet_complete']++;$packet=normalize_packet_name('wmr200',(string)($data['packet_name']??'unknown'));$a['packet_counts'][$packet]=($a['packet_counts'][$packet]??0)+1;break;
        case 'protocol_packet_decoded':
            $a['summary']['packet_decoded']++;$packet=normalize_packet_name('wmr200',(string)($data['packet_name']??'unknown'));$record=isset($data['record'])&&is_array($data['record'])?$data['record']:[];
            if(!in_array($packet,['archive','control'],true)){update_packet_timing($a,$packet,$epoch);store_weather_record($a,$record,$packet,$timestamp,$epoch);$a['parser']['state']='synced';$a['parser']['last_sync']=$timestamp;if($epoch!==null){$a['last_rx_timestamp']=$timestamp;$a['last_rx_epoch']=$epoch;$a['current_timeout_consecutive']=0;}}
            break;
        case 'usb_health_snapshot':
            if(isset($data['reopens']))$a['summary']['reopen_ok']=max($a['summary']['reopen_ok'],(int)$data['reopens']);if(isset($data['read_pipe_stalls']))$a['summary']['pipe_stalls']=max($a['summary']['pipe_stalls'],(int)$data['read_pipe_stalls']);break;
    }
}

function finalize_analysis(array &$a,float $now): void
{
    if($a['open_session']!==null){if($a['open_session']['start']&&$a['last_timestamp']){$s=timestamp_epoch($a['open_session']['start']);$e=timestamp_epoch($a['last_timestamp']);if($s!==null&&$e!==null)$a['open_session']['duration']=max(0,$e-$s);} $a['sessions'][]=$a['open_session'];}
    $a['sessions']=array_slice(array_reverse($a['sessions']),0,12);arsort($a['event_counts']);arsort($a['packet_counts']);arsort($a['versions']);
    foreach($a['packet_timing'] as &$t){$t['average_interval']=$t['interval_count']>0?$t['interval_sum']/$t['interval_count']:null;$t['age']=$t['last']!==null?max(0,$now-(float)$t['last']):null;}unset($t);
    $a['last_rx_age']=$a['last_rx_epoch']!==null?max(0,$now-(float)$a['last_rx_epoch']):null;

    // Re-evaluate the rolling health window on every render. This is required
    // by the incremental AJAX cache: old warnings must age out even when no
    // new trace record is written.
    $recentCritical=false;$recentError=false;$recentWarning=false;$recentIssues=0;$stillRecent=[];
    foreach($a['recent_events'] as $r){
        $re=timestamp_epoch((string)($r['timestamp']??''));
        if($re===null||($now-$re)>RECENT_HEALTH_WINDOW)continue;
        $stillRecent[]=$r;
        if(in_array((string)($r['severity']??''),['critical','error','warning'],true))$recentIssues++;
        if(($r['severity']??'')==='critical')$recentCritical=true;elseif(($r['severity']??'')==='error')$recentError=true;elseif(($r['severity']??'')==='warning')$recentWarning=true;
    }
    $a['recent_events']=$stillRecent;$a['recent_issues']=$recentIssues;
    $state=strtolower((string)$a['current_health_state']);$health=['class'=>'ok','label'=>'Operativo','reason'=>'nessuna anomalia attiva negli ultimi '.intdiv(RECENT_HEALTH_WINDOW,60).' minuti'];
    if($recentCritical||in_array($state,['failed','critical'],true))$health=['class'=>'critical','label'=>'Critico','reason'=>'recovery/riapertura fallita o evento critico recente'];
    elseif($recentError||$state==='degraded')$health=['class'=>'error','label'=>'Anomalia attiva','reason'=>'errore recente o comunicazione USB degradata'];
    elseif($recentWarning||in_array($state,['warning','recovering'],true))$health=['class'=>'warning','label'=>'Attenzione','reason'=>'warning o recovery recente'];
    elseif($a['last_rx_age']!==null&&$a['last_rx_age']>600)$health=['class'=>'warning','label'=>'Dati in ritardo','reason'=>'nessuna ricezione valida da '.format_age($a['last_rx_age'])];
    $a['health']=$health;
}

function analyse_trace_raw(array $source,array $files,array $options,int $tailLimit=0): array
{
    $a=new_analysis_state($source);$now=microtime(true);foreach($files as $f)$a['bytes']+=(int)@filesize($f);
    if($tailLimit>0){$active=$source['path'];foreach(tail_lines($active,$tailLimit) as $line){$line=trim($line);if($line==='')continue;$d=json_decode($line,true);if(!is_array($d)){$a['invalid_json']++;continue;}process_trace_record($a,$d,$line,$options,$now);}}
    else{foreach($files as $file){$fh=@fopen($file,'rb');if(!$fh)continue;while(($line=fgets($fh))!==false){if($a['records']>=MAX_RECORDS_SCANNED){$a['scan_stopped']=true;break 2;}$line=trim($line);if($line==='')continue;$d=json_decode($line,true);if(!is_array($d)){$a['invalid_json']++;continue;}process_trace_record($a,$d,$line,$options,$now);}fclose($fh);}}
    return $a;
}

function finalized_analysis(array $raw): array
{
    finalize_analysis($raw,microtime(true));
    return $raw;
}

function analyse_trace(array $source,array $files,array $options,int $tailLimit=0): array
{
    return finalized_analysis(analyse_trace_raw($source,$files,$options,$tailLimit));
}

function live_cache_key(array $source,array $options): string
{
    // The filtered event table is part of the cached state, so filters and row
    // limit deliberately participate in the cache key.
    $filterKey=[
        'limit'=>(int)($options['limit']??DEFAULT_DISPLAY_LIMIT),
        'severity'=>(string)($options['severity']??'all'),
        'event'=>(string)($options['event']??''),
        'q'=>(string)($options['q']??''),
        'problems'=>(bool)($options['problems']??true),
        'show_timeout110'=>(bool)($options['show_timeout110']??true),
    ];
    return sha1(LIVE_CACHE_VERSION.'|'.(string)$source['path'].'|'.(string)$source['family'].'|'.json_encode($filterKey));
}

function live_cache_path(array $source,array $options): string
{
    return LIVE_CACHE_ROOT.'/wmr-dashboard-live-'.live_cache_key($source,$options).'.json';
}

function save_live_cache(array $source,array $options,array $raw,int $offset,?int $inode): void
{
    $path=live_cache_path($source,$options);
    $payload=[
        'version'=>LIVE_CACHE_VERSION,
        'source_path'=>(string)$source['path'],
        'family'=>(string)$source['family'],
        'inode'=>$inode,
        'offset'=>$offset,
        'saved_at'=>time(),
        'state'=>$raw,
    ];
    $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if($json===false)return;
    $tmp=$path.'.'.getmypid().'.tmp';
    if(@file_put_contents($tmp,$json,LOCK_EX)!==false){@chmod($tmp,0600);@rename($tmp,$path);}else{@unlink($tmp);}
}

function load_live_cache(array $source,array $options): ?array
{
    $path=live_cache_path($source,$options);
    if(!is_file($path)||!is_readable($path))return null;
    $data=json_decode((string)@file_get_contents($path),true);
    if(!is_array($data)||($data['version']??null)!==LIVE_CACHE_VERSION)return null;
    if(($data['source_path']??'')!==(string)$source['path']||($data['family']??'')!==(string)$source['family'])return null;
    if(!isset($data['state'])||!is_array($data['state']))return null;
    return $data;
}

function active_file_identity(string $path): array
{
    clearstatcache(true,$path);
    $st=@stat($path);
    if(!is_array($st))return ['inode'=>null,'size'=>0];
    return ['inode'=>isset($st['ino'])?(int)$st['ino']:null,'size'=>isset($st['size'])?(int)$st['size']:0];
}

function complete_jsonl_offset(string $path): int
{
    if(!is_file($path)||!is_readable($path))return 0;
    $size=(int)@filesize($path);if($size<=0)return 0;
    $fh=@fopen($path,'rb');if(!$fh)return 0;
    $read=min(65536,$size);@fseek($fh,$size-$read,SEEK_SET);$tail=(string)fread($fh,$read);fclose($fh);
    $pos=strrpos($tail,"\n");
    if($pos===false)return 0;
    return ($size-$read)+$pos+1;
}

function process_trace_from_offset(array &$raw,array $source,array $options,int $offset): int
{
    $path=(string)$source['path'];$fh=@fopen($path,'rb');if(!$fh)return $offset;
    if($offset>0)@fseek($fh,$offset,SEEK_SET);$now=microtime(true);$lastGood=$offset;
    while(true){
        $lineStart=ftell($fh);$line=fgets($fh);if($line===false)break;
        // Never consume a partially written JSONL record. The next AJAX call
        // will resume at the beginning of this line.
        if(!str_ends_with($line,"\n")){@fseek($fh,$lineStart,SEEK_SET);break;}
        $lastGood=(int)ftell($fh);$trim=trim($line);if($trim==='')continue;
        $d=json_decode($trim,true);if(!is_array($d)){$raw['invalid_json']++;continue;}
        process_trace_record($raw,$d,$trim,$options,$now);
    }
    fclose($fh);return $lastGood;
}

function analyse_trace_incremental(array $source,array $options): array
{
    $path=(string)$source['path'];
    if(!is_file($path)||!is_readable($path))return analyse_trace($source,[],$options,0);
    $id=active_file_identity($path);$cache=load_live_cache($source,$options);$raw=null;$offset=0;$cachedInode=null;
    if($cache!==null){$raw=$cache['state'];$offset=max(0,(int)($cache['offset']??0));$cachedInode=isset($cache['inode'])?(int)$cache['inode']:null;}
    if(!is_array($raw)){
        // First live request: build a coherent baseline from the complete
        // active trace. Normal page loads prime this cache, so this fallback
        // normally happens only for a direct API request.
        $raw=analyse_trace_raw($source,[$path],$options,0);$offset=complete_jsonl_offset($path);
    }else{
        // Rotation creates a new inode. Keep the accumulated diagnostic state
        // and continue from byte zero of the new active file. If the same file
        // was truncated, do the same; this avoids the counters jumping backwards.
        if(($cachedInode!==null&&$id['inode']!==null&&$cachedInode!==$id['inode'])||$id['size']<$offset)$offset=0;
        $offset=process_trace_from_offset($raw,$source,$options,$offset);
        $raw['bytes']=$id['size'];
    }
    save_live_cache($source,$options,$raw,$offset,$id['inode']);
    return finalized_analysis($raw);
}

function prime_live_cache(array $source,array $options,array $raw): void
{
    $path=(string)$source['path'];if(!is_file($path)||!is_readable($path))return;$id=active_file_identity($path);save_live_cache($source,$options,$raw,complete_jsonl_offset($path),$id['inode']);
}

// -----------------------------------------------------------------------------
// Rendering helpers shared by the HTML page and AJAX API
// -----------------------------------------------------------------------------
function reading(array $a,string $field): ?array{return $a['weather_latest'][$field]??null;}
function reading_age(?array $r): ?float{return $r&&isset($r['epoch'])&&$r['epoch']!==null?max(0,microtime(true)-(float)$r['epoch']):null;}

function render_console_strip(array $a): string
{
    $m=$a['meta'];$session=$a['sessions'][0]??null;
    if($session){
        $uptime=format_duration((float)($session['duration']??0));
    }elseif(!empty($m['driver_start_timestamp'])){
        $start=timestamp_epoch((string)$m['driver_start_timestamp']);
        $uptime=$start!==null?format_duration(max(0,microtime(true)-$start)):'N/D';
    }elseif($a['first_timestamp']!==null){
        $start=timestamp_epoch((string)$a['first_timestamp']);
        $uptime=$start!==null?'≥ '.format_duration(max(0,microtime(true)-$start)):'N/D';
    }else{
        $uptime='N/D';
    }
    $parser=strtoupper((string)($a['parser']['state']??'unknown'));$parserClass=in_array($parser,['SYNCED','HEALTHY'],true)?'ok':($parser==='RESYNC'?'warning':'info');
    if($a['family']==='wmr100'){
        $vid=(string)($m['vendor_id']??'?');$pid=(string)($m['product_id']??'?');
        $profile=(string)($m['model_profile']??'');
        $endpoint=(string)($m['in_endpoint']??'');
        $usb=$vid.':'.$pid;
        if($profile!=='')$usb.=' · '.$profile;
        if($endpoint!=='')$usb.=' · EP '.$endpoint;
    }else{
        $usb='WMR200 HID';
    }
    ob_start();?>
    <div class="console-strip">
      <div><span>Console</span><strong><?=h($m['model']??'N/D')?></strong></div>
      <div><span>Famiglia</span><strong><?=h(strtoupper($a['family']))?></strong></div>
      <div><span>Driver</span><strong><?=h($m['driver_version']??'N/D')?></strong></div>
      <?php
        $manualError=($a['source']['manual_trace']??false)&&!empty($a['source']['manual_trace_error']);
        $basis=(string)($a['source']['detection_basis']??'');
        if($manualError){$detectLabel='TRACE MANUALE NON DISPONIBILE';$detectClass='warning';}
        elseif($a['source']['manual_trace']??false){$detectLabel='TRACE MANUALE';$detectClass='info';}
        elseif($basis==='config'){$detectLabel='CONFIG WEEWX';$detectClass='ok';}
        elseif($basis==='trace'&&($a['source']['auto_detected']??false)){$detectLabel='AUTO · TRACE';$detectClass='ok';}
        elseif($basis==='fallback'&&($a['source']['mode']??'auto')==='auto'){$detectLabel='NON RILEVATA';$detectClass='warning';}
        else{$detectLabel='FAMIGLIA MANUALE';$detectClass='info';}
      ?>
      <div><span>Rilevamento</span><strong class="<?=h($detectClass)?>"><?=h($detectLabel)?></strong></div>
      <div><span>USB / profilo</span><strong class="mono"><?=h($a['family']==='wmr100'?$usb:($m['model_profile']??'wmr200'))?></strong></div>
      <div><span>Session uptime</span><strong><?=h($uptime)?></strong></div>
      <div><span>Ultimo RX</span><strong><?=h(format_age($a['last_rx_age']??null))?></strong></div>
      <div><span>Parser</span><strong class="<?=h($parserClass)?>"><?=h($parser)?></strong></div>
      <div><span>Trace</span><strong><?=h(format_bytes((int)$a['bytes']))?></strong></div>
    </div><?php return(string)ob_get_clean();
}

function render_cards(array $a): string
{
    $s=$a['summary'];$integrity=$s['checksum_errors']+$s['length_errors']+$s['too_short']+$s['decode_errors']+$s['malformed_packets'];
    $parserProblems=$s['parser_overflow']+$s['unknown_packets']+$s['stream_resync'];
    $warnThreshold=(int)($a['meta']['timeout_warning_threshold']??($a['family']==='wmr200'?2:8));
    ob_start();?>
      <div class="panel card"><div class="label">Eventi analizzati</div><div class="value"><?=number_format($a['records'],0,',','.')?></div><div class="sub"><?=h(format_bytes((int)$a['bytes']))?></div></div>
      <div class="panel card"><div class="label">Problemi recenti</div><div class="value <?=$a['recent_issues']?'warning':'ok'?>"><?=number_format($a['recent_issues'],0,',','.')?></div><div class="sub">ultimi <?=intdiv(RECENT_HEALTH_WINDOW,60)?> min · storico <?=number_format($a['historical_issues'],0,',','.')?></div></div>
      <div class="panel card"><div class="label">Timeout USB</div><div class="value <?=$a['current_timeout_consecutive']>=$warnThreshold?'warning':'info'?>"><?=number_format($s['timeouts'],0,',','.')?></div><div class="sub"><?=$s['timeout_episodes']?> episodi · consecutivi <?=$a['current_timeout_consecutive']?> · max <?=$s['max_consecutive_timeouts']?></div></div>
      <?php if($a['family']==='wmr100'):?>
      <div class="panel card"><div class="label">Reinit soft</div><div class="value <?=$s['soft_reinit_failed']?'error':($s['soft_reinit_start']?'info':'ok')?>"><?=$s['soft_reinit_success']?>/<?=$s['soft_reinit_start']?></div><div class="sub"><?=$s['soft_reinit_failed']?> fallite</div></div>
      <div class="panel card"><div class="label">Recovery USB</div><div class="value <?=$s['recovery_exhausted']?'critical':($s['recovery_start']?'warning':'ok')?>"><?=$s['recovery_success']?>/<?=$s['recovery_start']?></div><div class="sub"><?=$s['recovery_attempt_failed']?> tentativi falliti · <?=$s['recovery_exhausted']?> esaurite</div></div>
      <?php else:?>
      <div class="panel card"><div class="label">Pipe stall</div><div class="value <?=$s['pipe_stalls']?'error':'ok'?>"><?=$s['pipe_stalls']?></div><div class="sub">EPIPE / clear halt / gap</div></div>
      <div class="panel card"><div class="label">Riaperture USB</div><div class="value <?=$s['reopen_failed']?'critical':($s['reopen_ok']?'warning':'ok')?>"><?=$s['reopen_ok']?>/<?=$s['reopen_begin']?></div><div class="sub"><?=$s['reopen_failed']?> fallite</div></div>
      <?php endif;?>
      <div class="panel card"><div class="label">Integrità pacchetti</div><div class="value <?=$integrity?'error':'ok'?>"><?=$integrity?></div><div class="sub"><?=$s['checksum_errors']?> checksum · <?=$s['malformed_packets']+$s['length_errors']+$s['too_short']?> malformed</div></div>
      <div class="panel card"><div class="label">Pacchetti</div><div class="value"><?=number_format($a['family']==='wmr100'?$s['packet_valid']:$s['packet_decoded'],0,',','.')?></div><div class="sub"><?=$a['family']==='wmr100'?number_format($s['loop_packets'],0,',','.').' LOOP prodotti':number_format($s['packet_complete'],0,',','.').' completi'?></div></div>
      <div class="panel card"><div class="label">Parser / resync</div><div class="value <?=$parserProblems?'warning':'ok'?>"><?=$a['family']==='wmr100'?$s['unknown_packets']:$s['stream_resync']?></div><div class="sub"><?=$a['family']==='wmr100'?$s['parser_resync_requested'].' richieste · '.$s['parser_overflow'].' overflow':$s['stream_resync'].' stream resync · '.$s['malformed_packets'].' malformed'?></div></div>
    <?php return(string)ob_get_clean();
}

function weather_groups(string $family='wmr100'): array
{
    $pressureFields = $family==='wmr200'
        ? ['pressure'=>'Pressione stazione','altimeter'=>'Altimetro','forecastIcon'=>'Previsione console']
        : ['pressure'=>'Pressione stazione / assoluta','consoleBarometer'=>'Pressione relativa console / SLP','forecastIcon'=>'Previsione console'];
    return [
      'temperature_humidity'=>['title'=>'Temperature e umidità','fields'=>['outTemp'=>'Temperatura esterna','outHumidity'=>'Umidità esterna','heatindex'=>'Heat index esterno','inTemp'=>'Temperatura interna','inHumidity'=>'Umidità interna']],
      'pressure'=>['title'=>'Pressione','fields'=>$pressureFields],
      'wind'=>['title'=>'Vento','fields'=>['windSpeed'=>'Velocità','windGust'=>'Raffica','windDir'=>'Direzione','windchill'=>'Wind chill']],
      'rain'=>['title'=>'Pioggia','fields'=>['rainRate'=>'Intensità','hourRain'=>'Ultima ora','rain24'=>'Ultime 24 ore','rainTotal'=>'Totale console','rain'=>'Incremento']],
      'uv'=>['title'=>'Radiazione UV','fields'=>['UV'=>'Indice UV']],
    ];
}

function render_weather(array $a): string
{
    ob_start();foreach(weather_groups($a['family']) as $packet=>$group){$t=$a['packet_timing'][$packet]??null;$avg=$t['average_interval']??null;$age=$t['age']??null;[$cls,$label]=packet_freshness($packet,$age,$avg,$a['family'],(string)($a['meta']['model']??''));$sparkField=['temperature_humidity'=>'outTemp','pressure'=>'pressure','wind'=>'windSpeed','rain'=>'rainRate','uv'=>'UV'][$packet]??'';$spark=$sparkField?sparkline_svg($a['weather_history'][$sparkField]??[]):'';?>
    <article class="weather-card <?=h($cls)?>"><div class="weather-head"><h3><?=h($group['title'])?></h3><div class="weather-head-right"><?=$spark?><span class="freshness <?=h($cls)?>"><?=h($label)?></span></div></div><div class="metrics">
    <?php foreach($group['fields'] as $field=>$flabel):$r=reading($a,$field);?>
      <?php if($field==='forecastIcon'):?>
        <?php if($r):$fc=(int)$r['value'];$fi=forecast_info($fc,$a['family']);?>
        <div class="forecast-metric">
          <div class="forecast-icon-wrap"><?=forecast_icon_svg($fc,$a['family'])?></div>
          <?php
            $forecastSource=(string)($r['source']??'');
            if($a['family']==='wmr100'){
                $forecastSourceLabel=$forecastSource==='driver_loop'
                    ? 'forecastIcon fornito direttamente dal driver (codice nativo console)'
                    : 'fallback compatibilità: codice nativo estratto dal pacchetto pressione 0x46';
            }else{
                $forecastSourceLabel='forecastIcon fornito dal driver WMR200';
            }
          ?>
          <div class="forecast-copy"><span class="metric-name"><?=h($flabel)?></span><strong><?=h($fi['label'])?></strong><span class="forecast-code">Codice console <?=h((string)$fc)?></span><span class="metric-age">letto <?=h(format_age(reading_age($r)))?> · <?=h(local_timestamp($r['timestamp'],false))?></span><span class="metric-age"><?=h($forecastSourceLabel)?></span></div>
        </div>
        <?php else:?>
        <div class="metric"><span class="metric-name"><?=h($flabel)?></span><span class="metric-value">N/A</span></div>
        <?php endif;?>
      <?php else:?>
        <div class="metric"><span class="metric-name"><?=h($flabel)?></span><span class="metric-value"><?=h($r?weather_value($field,$r['value'],$a['family']):'N/A')?></span><?php if($r):?><span class="metric-age">letto <?=h(format_age(reading_age($r)))?> · <?=h(local_timestamp($r['timestamp'],false))?></span><?php endif;?></div>
      <?php endif;?>
    <?php endforeach;?></div>
    <div class="weather-foot">Età pacchetto: <?=h(format_age($age))?> · ultimo intervallo <?=h(format_interval($t['last_interval']??null))?> · media <?=h(format_interval($avg))?><br>Ricezioni sessione: <?=number_format((int)($t['count']??0),0,',','.')?> · atteso ~<?=(int)packet_expected_interval($packet,$a['family'],(string)($a['meta']['model']??''))?> s<?php if($packet==='pressure'&&$a['family']==='wmr100'):?><br><span class="forecast-note">La pressione relativa/SLP e il codice previsione sono trasmessi dalla console. <code>altimeter</code> resta un valore distinto calcolabile da WeeWX.</span><?php endif;?></div></article><?php }return(string)ob_get_clean();
}

function sensor_channel_definition(int $ch): array
{
    if($ch===0)return ['name'=>'CH0 · Console','temp'=>'inTemp','hum'=>'inHumidity','battery'=>'inTempBatteryStatus'];
    if($ch===1)return ['name'=>'CH1 · Sensore esterno','temp'=>'outTemp','hum'=>'outHumidity','battery'=>'outTempBatteryStatus'];
    if($ch<=8){$n=$ch-1;return ['name'=>'CH'.$ch.' · Sensore remoto','temp'=>'extraTemp'.$n,'hum'=>'extraHumid'.$n,'battery'=>'extraBatteryStatus'.$n];}
    return ['name'=>'CH'.$ch.' · Sensore remoto','temp'=>'channelTemp'.$ch,'hum'=>'channelHumid'.$ch,'battery'=>'channelBattery'.$ch];
}

function sensor_channel_limit(array $a): int
{
    $default=$a['family']==='wmr200'?10:3;
    $configured=(int)($a['meta']['max_remote_channels']??$default);
    if($configured<=0)$configured=$default;
    $hardMax=$a['family']==='wmr200'?10:8;
    return max(1,min($hardMax,$configured));
}

function sensor_panel_caption(array $a): string
{
    $model=trim((string)($a['meta']['model']??family_title($a['family'])));
    if($model==='')$model=family_title($a['family']);
    $familyLabel=$a['family']==='wmr200'?'WMR200/WMR200A':'WMR100/WMR88';
    return $model.' · termoigrometri '.$familyLabel.' · CH0–CH'.sensor_channel_limit($a);
}

function sensor_native_mapping(int $ch,string $family): string
{
    if($family==='wmr200')return 'temperature_'.$ch.' / humidity_'.$ch;
    return 'frame T/H CH'.$ch;
}

function battery_definitions(array $a): array
{
    $definitions = [];
    if ($a['family'] === 'wmr100') {
        $max = max(1, min(8, (int)($a['meta']['max_remote_channels'] ?? 3)));
        for ($ch = 0; $ch <= $max; $ch++) {
            $sensor = sensor_channel_definition($ch);
            $definitions[] = [
                'name' => $ch === 0 ? 'Console interna' : ($ch === 1 ? 'Sensore esterno' : 'Sensore remoto CH'.$ch),
                'field' => $sensor['battery'],
                'packet' => 'temperature_humidity',
            ];
        }
    } else {
        $definitions[] = [
            'name'=>'Console / batterie backup',
            'field'=>'inTempBatteryStatus',
            'packet'=>'status',
            'unavailable_label'=>'Non trasmesso dal WMR200',
            'unavailable_note'=>'Il pacchetto Status espone solo le batterie dei sensori esterni',
        ];
        $definitions[] = ['name'=>'Sensore esterno', 'field'=>'outTempBatteryStatus', 'packet'=>'status'];
    }
    $definitions[] = ['name'=>'Anemometro', 'field'=>'windBatteryStatus', 'packet'=>$a['family']==='wmr200'?'status':'wind'];
    $definitions[] = ['name'=>'Pluviometro', 'field'=>'rainBatteryStatus', 'packet'=>$a['family']==='wmr200'?'status':'rain'];
    $definitions[] = ['name'=>'Sensore UV', 'field'=>'uvBatteryStatus', 'packet'=>$a['family']==='wmr200'?'status':'uv'];
    return $definitions;
}

function render_batteries(array $a): string
{
    $items = [];
    $available = 0;
    $low = 0;
    $unknown = 0;
    foreach (battery_definitions($a) as $definition) {
        $reading = reading($a, $definition['field']);
        [$state, $label] = battery_state($reading['value'] ?? null);
        if ($state !== 'unknown') $available++;
        if ($state === 'low') $low++;
        if ($state === 'unknown') $unknown++;
        $items[] = $definition + ['reading'=>$reading, 'state'=>$state, 'label'=>$label];
    }

    $summaryLabel = 'Stato batterie non disponibile';
    if ($low > 0) {
        $summaryLabel = $low.' '.($low === 1 ? 'batteria da sostituire' : 'batterie da sostituire');
    } elseif ($available > 0) {
        $summaryLabel = $unknown > 0 ? 'Batterie rilevate regolari · '.$unknown.' '.($unknown === 1 ? 'non disponibile' : 'non disponibili') : 'Batterie regolari';
    }
    $summaryClass = $low > 0 ? 'has-low' : ($available > 0 ? ($unknown > 0 ? 'partial' : 'all-ok') : 'unknown');

    ob_start(); ?>
    <div class="battery-summary <?= h($summaryClass) ?>">
      <div><span class="battery-summary-icon" aria-hidden="true"><span></span></span><strong><?= h($summaryLabel) ?></strong></div>
      <span><?= $available ?> rilevate su <?= count($items) ?></span>
    </div>
    <div class="battery-grid">
    <?php foreach ($items as $item): $r=$item['reading']; $age=$r?reading_age($r):null; ?>
      <article class="battery-card <?= h($item['state']) ?>">
        <div class="battery-visual" aria-hidden="true"><span></span></div>
        <div class="battery-copy"><strong><?= h($item['name']) ?></strong><span><?= h($r?$item['label']:($item['unavailable_label']??$item['label'])) ?></span><?php if($r): ?><small>letto <?= h(format_age($age)) ?> · <?= h(local_timestamp($r['timestamp'],false)) ?></small><?php else: ?><small><?= h($item['unavailable_note']??'Nessun dato nel trace analizzato') ?></small><?php endif; ?></div>
      </article>
    <?php endforeach; ?>
    </div>
    <?php return (string)ob_get_clean();
}

function render_sensors(array $a): string
{
    $max=sensor_channel_limit($a);ob_start();
    for($ch=0;$ch<=$max;$ch++){
        $d=sensor_channel_definition($ch);$t=reading($a,$d['temp']);$hu=reading($a,$d['hum']);$bat=reading($a,$d['battery']);
        $ages=[];foreach([$t,$hu] as $r)if($r&&isset($r['epoch'])&&$r['epoch']!==null)$ages[]=max(0,microtime(true)-(float)$r['epoch']);
        $age=$ages?max($ages):null;
        [$cls,$label]=packet_freshness('temperature_humidity',$age,null,$a['family'],(string)($a['meta']['model']??''));
        if(!$t&&!$hu){$cls='missing';$label='Non ricevuto';}
        [$batteryClass,$batteryLabel]=battery_state($bat['value']??null);
        $lastTimestamp=$t['timestamp']??($hu['timestamp']??null);
        $weewxMap=$d['temp'].' / '.$d['hum'];
        $nativeMap=sensor_native_mapping($ch,$a['family']);?>
      <article class="sensor-card <?=h($cls)?>"><div class="sensor-head"><strong><?=h($d['name'])?></strong><span class="freshness <?=h($cls)?>"><?=h($label)?></span></div><div class="sensor-body"><div><span>Temperatura</span><b><?=h($t?weather_value($d['temp'],$t['value'],$a['family']):'N/A')?></b></div><div><span>Umidità</span><b><?=h($hu?weather_value($d['hum'],$hu['value'],$a['family']):'N/A')?></b></div><div><span>Batteria</span><b class="<?=h($batteryClass==='low'?'warning':($batteryClass==='ok'?'ok':'muted'))?>"><?=h($bat?$batteryLabel:'N/A')?></b></div><div><span>Età</span><b><?=h(format_age($age))?></b></div><div><span>Ultima lettura</span><b><?=h($lastTimestamp?local_timestamp($lastTimestamp,false):'—')?></b></div><div class="sensor-map"><span>Mapping</span><code>WeeWX: <?=h($weewxMap)?></code><?php if($a['family']==='wmr200'):?><code>Nativo: <?=h($nativeMap)?></code><?php endif;?></div></div></article><?php
    }
    $special=[['Vento','wind','windSpeed','windBatteryStatus'],['Pioggia','rain','rainTotal','rainBatteryStatus'],['UV','uv','UV','uvBatteryStatus']];
    foreach($special as [$name,$packet,$field,$battery]){$r=reading($a,$field);$b=reading($a,$battery);$age=$a['packet_timing'][$packet]['age']??null;[$cls,$label]=packet_freshness($packet,$age,$a['packet_timing'][$packet]['average_interval']??null,$a['family'],(string)($a['meta']['model']??''));?>
      <article class="sensor-card <?=h($cls)?>"><div class="sensor-head"><strong><?=h($name)?></strong><span class="freshness <?=h($cls)?>"><?=h($label)?></span></div><div class="sensor-body"><div><span>Valore</span><b><?=h($r?weather_value($field,$r['value'],$a['family']):'N/A')?></b></div><div><span>Batteria</span><b><?=h($b?weather_value($battery,$b['value'],$a['family']):'N/A')?></b></div><div><span>Ultimo pacchetto</span><b><?=h(format_age($age))?></b></div><div><span>Ricezioni</span><b><?=number_format((int)($a['packet_timing'][$packet]['count']??0),0,',','.')?></b></div></div></article><?php
    }
    return(string)ob_get_clean();
}

function render_protocol(array $a): string
{
    $s=$a['summary'];$p=$a['parser'];$last=$p['last_anomaly']??null;ob_start();?>
    <div class="protocol-grid"><div class="protocol-kpis">
      <div><span><?=$a['family']==='wmr100'?'Frame validi':'Pacchetti completi'?></span><b><?=number_format($a['family']==='wmr100'?$s['packet_valid']:$s['packet_complete'],0,',','.')?></b></div>
      <div><span>Checksum error</span><b class="<?=$s['checksum_errors']?'error':'ok'?>"><?=$s['checksum_errors']?></b></div>
      <div><span>Malformed / length</span><b class="<?=($s['malformed_packets']+$s['length_errors']+$s['too_short'])?'error':'ok'?>"><?=$s['malformed_packets']+$s['length_errors']+$s['too_short']?></b></div>
      <div><span><?=$a['family']==='wmr100'?'Unknown packet':'Stream resync'?></span><b class="<?=($a['family']==='wmr100'?$s['unknown_packets']:$s['stream_resync'])?'warning':'ok'?>"><?=$a['family']==='wmr100'?$s['unknown_packets']:$s['stream_resync']?></b></div>
      <div><span>Resync</span><b><?=$a['family']==='wmr100'?$s['parser_resync_requested']:$s['stream_resync']?></b></div>
      <div><span>Decode error</span><b class="<?=$s['decode_errors']?'error':'ok'?>"><?=$s['decode_errors']?></b></div>
      <div><span>USB read errors</span><b class="<?=$s['usb_read_errors']?'error':'ok'?>"><?=$s['usb_read_errors']?></b></div>
      <div><span>Timeout recovery</span><b><?=$s['timeout_recoveries']?></b></div>
    </div><div class="protocol-detail">
      <h3><?=h(family_title($a['family']))?> · parser <span class="mono"><?=h(strtoupper((string)$p['state']))?></span></h3>
      <?php if($a['family']==='wmr100'):?><p>Protocollo a frame delimitati <code>FF FF</code>. Ultima sincronizzazione: <b><?=h(local_timestamp($p['last_sync']??null,false))?></b><br>Ultimo resync: <b><?=h(local_timestamp($p['last_resync']??null,false))?></b><?php if($p['last_resync_reason']):?> · <?=h($p['last_resync_reason'])?><?php endif;?></p>
      <?php else:?><p>Protocollo WMR200 a comando/lunghezza. Il monitor segue checksum, packet length e <code>protocol_stream_resync</code> della release hardened.</p><?php endif;?>
      <?php if($last):$candidate=$a['family']==='wmr100'?($last['candidate']??shifted_frame_candidate((string)($last['data']['raw_hex']??''))):null;?><div class="anomaly"><strong>Ultima anomalia: <?=h($last['event'])?></strong><small><?=h(local_timestamp($last['timestamp']??null,false))?></small><code><?=h((string)($last['data']['raw_hex']??$last['data']['hex']??event_details($last['data']??[],$a['family'])))?></code><?php if($candidate):?><div class="candidate"><b>Possibile frame WMR100 traslato</b>: aggiungendo <code>00</code> → <?=h($candidate['packet_type'])?> <?=h(packet_label($candidate['packet_name']))?>, checksum <?=h($candidate['checksum'])?> valido.<br><code><?=h($candidate['raw_hex'])?></code></div><?php endif;?></div><?php else:?><p class="muted">Nessuna anomalia di protocollo nel trace analizzato.</p><?php endif;?>
    </div></div><?php return(string)ob_get_clean();
}

function render_timeline(array $a): string
{
    ob_start();$items=array_slice(array_reverse($a['timeline']),0,14);if(!$items){echo'<div class="empty">Nessun evento USB/parser.</div>';return(string)ob_get_clean();}foreach($items as $item){?><div class="timeline-row"><span class="timeline-dot <?=h($item['severity'])?>"></span><div><strong class="mono"><?=h($item['event'])?></strong><small><?=h(local_timestamp($item['timestamp'],false))?></small><?php if($item['details']):?><p><?=h($item['details'])?></p><?php endif;?></div></div><?php }return(string)ob_get_clean();
}

function render_event_rows(array $a): string
{
    ob_start();if(!$a['rows']){echo'<tr><td colspan="5"><div class="empty">Nessun evento corrisponde ai filtri attuali.</div></td></tr>';return(string)ob_get_clean();}foreach(array_reverse($a['rows']) as $row){?><tr><td class="time"><?=h(local_timestamp($row['timestamp']))?></td><td><span class="badge <?=h($row['severity'])?>"><?=h($row['severity'])?></span></td><td class="event"><?=h($row['event'])?><br><small>#<?=h($row['sequence'])?></small></td><td><?=h($row['direction'])?></td><td class="details"><?=h($row['details'])?><details class="raw"><summary>JSON</summary><pre><?=h($row['raw'])?></pre></details></td></tr><?php }return(string)ob_get_clean();
}

function render_sessions(array $a): string
{
    ob_start();if(!$a['sessions']){echo'<div class="empty">Nessuna sessione.</div>';return(string)ob_get_clean();}foreach($a['sessions'] as $s){?><div class="session"><strong><?=h(($s['model']??'WMR').' · '.($s['version']??''))?><?=($s['stop']??null)===null?' · attiva':''?></strong><small><?=h(local_timestamp($s['start']??null,false))?><br>Durata <?=h(format_duration((float)($s['duration']??0)))?> · timeout <?=(int)($s['timeouts']??0)?> · reinit <?=(int)($s['reinit']??0)?> · recovery <?=(int)($s['recovery']??0)?> · reopen <?=(int)($s['reopens']??0)?></small></div><?php }return(string)ob_get_clean();
}

function render_simple_counts(array $counts,int $limit=16): string
{ob_start();$i=0;foreach($counts as $name=>$count){if($i++>=$limit)break;?><div class="list-row"><span class="name mono" title="<?=h($name)?>"><?=h($name)?></span><span class="count"><?=number_format((int)$count,0,',','.')?></span></div><?php }return(string)ob_get_clean();}

function render_driver_versions(array $a): string
{
    $driver=trim((string)($a['meta']['driver']??strtoupper((string)($a['family']??'WMR'))));
    if($driver==='')$driver=strtoupper((string)($a['family']??'WMR'));
    $versions=$a['versions']??[];
    ob_start();
    if($versions){$i=0;foreach($versions as $version=>$count){if($i++>=8)break;$label=$driver.' · '.$version;?><div class="list-row"><span class="name mono" title="<?=h($label)?>"><?=h($label)?></span><span class="count" title="Eventi driver_start"><?=number_format((int)$count,0,',','.')?> avv.</span></div><?php }}
    else{$version=trim((string)($a['meta']['driver_version']??''));$known=$version!==''&&strtolower($version)!=='sconosciuta';$label=$driver.' · '.($known?$version:'versione non rilevata');?><div class="list-row"><span class="name mono" title="<?=h($label)?>"><?=h($label)?></span><span class="count"><?=$known?'corrente':'—'?></span></div><?php }
    return(string)ob_get_clean();
}

function api_payload(array $a): array
{
    return [
        'generated'=>(new DateTimeImmutable('now',new DateTimeZone(LOCAL_TIMEZONE)))->format('d/m/Y H:i:s T'),
        'family'=>$a['family'],'family_title'=>family_title($a['family']),'source'=>$a['source']['path'],'health'=>$a['health'],
        'last_rx_age'=>$a['last_rx_age'],'last_sequence'=>max(array_map('intval',array_column($a['rows'],'sequence'))?:[0]),
        'show_sensors'=>true,'sensor_caption'=>sensor_panel_caption($a),'model'=>(string)($a['meta']['model']??'WMR'),
        'max_remote_channels'=>sensor_channel_limit($a),
        'html'=>[
            'console'=>render_console_strip($a),'cards'=>render_cards($a),'weather'=>render_weather($a),'batteries'=>render_batteries($a),
            'sensors'=>render_sensors($a),'protocol'=>render_protocol($a),'timeline'=>render_timeline($a),'events'=>render_event_rows($a),
            'sessions'=>render_sessions($a),'event_counts'=>render_simple_counts($a['event_counts']),'packet_counts'=>render_simple_counts($a['packet_counts']),
            'versions'=>render_driver_versions($a)
        ]
    ];
}

// -----------------------------------------------------------------------------
// Diagnostic bundle
// -----------------------------------------------------------------------------
function download_diagnostic_bundle(array $source,array $files): never
{
    $active=$source['path'];if(!is_file($active)||!is_readable($active)){http_response_code(404);header('Content-Type:text/plain;charset=UTF-8');echo"Trace non disponibile.\n";exit;}
    $lines=tail_lines($active,DIAGNOSTIC_TAIL_LINES);$options=['limit'=>100,'severity'=>'all','event'=>'','q'=>'','problems'=>false,'show_timeout110'=>true];$analysis=analyse_trace($source,[$active],$options,DIAGNOSTIC_TAIL_LINES);
    $summary=['generated_utc'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),'family'=>$source['family'],'trace_file'=>$active,'trace_size'=>(int)@filesize($active),'meta'=>$analysis['meta'],'health'=>$analysis['health'],'summary'=>$analysis['summary'],'parser'=>$analysis['parser'],'last_rx_timestamp'=>$analysis['last_rx_timestamp'],'last_rx_age_seconds'=>$analysis['last_rx_age']];
    $usb=[];$protocol=[];$unknown=[];foreach($lines as $line){$d=json_decode($line,true);if(!is_array($d))continue;$e=(string)($d['event']??'');if(str_starts_with($e,'usb_')||str_contains($e,'health'))$usb[]=$line;if(str_starts_with($e,'packet_')||str_starts_with($e,'parser_')||str_starts_with($e,'protocol_'))$protocol[]=$line;if($e==='unknown_packet')$unknown[]=$line;}
    $base='wmr-'.$source['family'].'-diagnostic-'.date('Ymd-His');
    if(class_exists('ZipArchive')){$tmp=tempnam(sys_get_temp_dir(),'wmrdiag_');if($tmp===false){http_response_code(500);exit('Errore file temporaneo');}@unlink($tmp);$tmp.='.zip';$zip=new ZipArchive();if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){http_response_code(500);exit('Errore ZIP');}$zip->addFromString('summary.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$zip->addFromString('last-'.DIAGNOSTIC_TAIL_LINES.'-events.jsonl',implode("\n",$lines)."\n");$zip->addFromString('usb-events.jsonl',implode("\n",$usb).($usb?"\n":''));$zip->addFromString('protocol-events.jsonl',implode("\n",$protocol).($protocol?"\n":''));$zip->addFromString('unknown-packets.jsonl',implode("\n",$unknown).($unknown?"\n":''));$zip->addFromString('README.txt',"Universal WMR diagnostic bundle\nFamily: {$source['family']}\nSource: {$active}\nReview before sharing.\n");$zip->close();header('Content-Type:application/zip');header('Content-Disposition:attachment; filename="'.$base.'.zip"');header('Content-Length:'.filesize($tmp));readfile($tmp);@unlink($tmp);exit;}
    header('Content-Type:application/json;charset=UTF-8');header('Content-Disposition:attachment; filename="'.$base.'.json"');echo json_encode(['summary'=>$summary,'events'=>$lines,'usb_events'=>$usb,'protocol_events'=>$protocol,'unknown_packets'=>$unknown],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}

// -----------------------------------------------------------------------------
// Request options and alternate modes
// -----------------------------------------------------------------------------
// Source selection is persistent. Explicit query-string values win; when they
// are absent (plain F5, reopening the page, reset filters), the last selection
// is restored from cookies. Selecting Automatico explicitly overwrites the
// stored manual mode, so there is no hidden fallback to Manuale.
$stationExplicit=array_key_exists('station',$_GET);
$traceModeExplicit=array_key_exists('trace_mode',$_GET);
$traceFileExplicit=array_key_exists('trace_file',$_GET);

$stationRaw=$stationExplicit?(string)$_GET['station']:source_pref_cookie(SOURCE_PREF_COOKIE_STATION,'auto');
$stationMode=strtolower(trim($stationRaw));if(!in_array($stationMode,['auto','wmr100','wmr200'],true))$stationMode='auto';

$traceModeRaw=$traceModeExplicit?(string)$_GET['trace_mode']:source_pref_cookie(SOURCE_PREF_COOKIE_TRACE_MODE,'auto');
$traceMode=strtolower(trim($traceModeRaw));if(!in_array($traceMode,['auto','manual'],true))$traceMode='auto';

$storedManualTrace=source_pref_cookie(SOURCE_PREF_COOKIE_TRACE_FILE,'');
$manualTraceInput=$traceFileExplicit?trim((string)$_GET['trace_file']):$storedManualTrace;

// Persist explicit choices before any HTML/download response is emitted.
if($stationExplicit)set_source_pref_cookie(SOURCE_PREF_COOKIE_STATION,$stationMode);
if($traceModeExplicit)set_source_pref_cookie(SOURCE_PREF_COOKIE_TRACE_MODE,$traceMode);
if($traceFileExplicit&&$manualTraceInput!=='')set_source_pref_cookie(SOURCE_PREF_COOKIE_TRACE_FILE,$manualTraceInput);

$source=detect_trace_source($stationMode,$traceMode,$manualTraceInput);
$source=enrich_source_with_start_context($source);
$source=enrich_source_with_installed_driver($source);
$activeTrace=(string)$source['path'];
$includeRotated=bool_param('rotated',false);$displayLimit=int_param('limit',DEFAULT_DISPLAY_LIMIT,25,MAX_DISPLAY_LIMIT);$liveRefresh=int_param('live',DEFAULT_LIVE_REFRESH,0,60);
$severityFilter=strtolower(trim((string)($_GET['severity']??'all')));$eventFilter=trim((string)($_GET['event']??''));$searchFilter=trim((string)($_GET['q']??''));$onlyProblems=bool_param('problems',true);$showTimeout110=bool_param('show_timeout110',true);
$validSeverities=['all','critical','error','warning','info'];if(!in_array($severityFilter,$validSeverities,true))$severityFilter='all';
$options=['limit'=>$displayLimit,'severity'=>$severityFilter,'event'=>$eventFilter,'q'=>$searchFilter,'problems'=>$onlyProblems,'show_timeout110'=>$showTimeout110];$files=trace_files($activeTrace,$includeRotated);
if(isset($_GET['download'])&&$_GET['download']==='bundle')download_diagnostic_bundle($source,$files);
if(isset($_GET['download'])&&$_GET['download']==='csv'){$analysis=analyse_trace($source,$files,$options,0);header('Content-Type:text/csv;charset=UTF-8');header('Content-Disposition:attachment; filename="wmr-'.$source['family'].'-trace-'.date('Ymd-His').'.csv"');echo"\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,['timestamp_locale','family','severity','event','direction','sequence','details','json']);foreach(array_reverse($analysis['rows']) as $row)fputcsv($out,[local_timestamp($row['timestamp']),$source['family'],$row['severity'],$row['event'],$row['direction'],$row['sequence'],$row['details'],$row['raw']]);fclose($out);exit;}
if(isset($_GET['api'])&&$_GET['api']==='1'){$liveSource=detect_trace_source($stationMode,$traceMode,$manualTraceInput);$liveSource=enrich_source_with_start_context($liveSource);$liveSource=enrich_source_with_installed_driver($liveSource);$analysis=analyse_trace_incremental($liveSource,$options);header('Content-Type:application/json;charset=UTF-8');header('Cache-Control:no-store,max-age=0,must-revalidate');header('Pragma:no-cache');echo json_encode(api_payload($analysis),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
if(!$includeRotated&&count($files)===1){$rawAnalysis=analyse_trace_raw($source,$files,$options,0);prime_live_cache($source,$options,$rawAnalysis);$analysis=finalized_analysis($rawAnalysis);}else{$analysis=analyse_trace($source,$files,$options,0);}$queryForCsv=$_GET;$queryForCsv['download']='csv';unset($queryForCsv['api']);$csvUrl='?'.http_build_query($queryForCsv);$queryForBundle=$_GET;$queryForBundle['download']='bundle';unset($queryForBundle['api']);$bundleUrl='?'.http_build_query($queryForBundle);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(PAGE_TITLE) ?></title>
<style>
:root{color-scheme:dark;--bg:#0b1120;--panel:#111827;--panel2:#172033;--border:#273449;--text:#e5edf7;--muted:#91a0b5;--ok:#32d583;--warn:#fdb022;--error:#f97066;--critical:#ee46bc;--info:#60a5fa;--shadow:0 18px 45px rgba(0,0,0,.22)}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 10% 0,#17233b 0,transparent 32%),var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;font-size:14px}.wrap{max-width:1600px;margin:auto;padding:22px}.top{display:flex;gap:18px;align-items:flex-start;justify-content:space-between;margin-bottom:12px}.title h1{margin:0;font-size:26px;letter-spacing:-.02em}.title p{margin:6px 0 0;color:var(--muted)}.status{display:flex;align-items:center;gap:9px;padding:10px 14px;border:1px solid var(--border);border-radius:999px;background:rgba(17,24,39,.85);box-shadow:var(--shadow);font-weight:700}.dot{width:10px;height:10px;border-radius:50%;background:var(--ok);box-shadow:0 0 16px currentColor}.status.warning .dot{background:var(--warn)}.status.error .dot{background:var(--error)}.status.critical .dot{background:var(--critical)}.panel{background:rgba(17,24,39,.88);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow)}.notice{padding:14px 16px;margin-bottom:16px}.notice.error{border-color:rgba(249,112,102,.5);background:rgba(249,112,102,.08)}.notice.warning{border-color:rgba(253,176,34,.5);background:rgba(253,176,34,.07)}.ok{color:var(--ok)}.warning{color:var(--warn)}.error{color:var(--error)}.critical{color:var(--critical)}.info{color:var(--info)}.muted{color:var(--muted)}[hidden]{display:none!important}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.console-strip{display:grid;grid-template-columns:repeat(9,minmax(115px,1fr));gap:1px;background:var(--border);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:16px}.console-strip>div{background:rgba(17,24,39,.95);padding:10px 12px;min-height:62px}.console-strip span{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em}.console-strip strong{display:block;margin-top:6px;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cards{display:grid;grid-template-columns:repeat(8,minmax(130px,1fr));gap:12px;margin-bottom:16px}.card{padding:14px 15px;min-height:94px}.card .label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em}.card .value{font-size:26px;font-weight:800;margin-top:9px}.card .sub{color:var(--muted);margin-top:4px;font-size:11px;line-height:1.35}
.section-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:15px 16px;border-bottom:1px solid var(--border)}.section-head h2{font-size:16px;margin:0}.section-head span{color:var(--muted);font-size:12px}.weather-panel,.battery-panel,.sensor-panel,.protocol-panel,.timeline-panel{margin-bottom:16px;overflow:hidden}.weather-grid{display:grid;grid-template-columns:repeat(3,minmax(280px,1fr));gap:12px;padding:14px}.weather-card,.sensor-card{background:rgba(8,16,29,.52);border:1px solid var(--border);border-radius:14px;overflow:hidden}.weather-card.fresh,.sensor-card.fresh{border-color:rgba(50,213,131,.38)}.weather-card.delayed,.sensor-card.delayed{border-color:rgba(253,176,34,.48)}.weather-card.stale,.weather-card.missing,.sensor-card.stale,.sensor-card.missing{border-color:rgba(249,112,102,.48)}.weather-head,.sensor-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 13px;border-bottom:1px solid rgba(39,52,73,.7)}.weather-head h3{font-size:14px;margin:0}.weather-head-right{display:flex;align-items:center;gap:10px}.spark{width:116px;height:30px;color:#7ea5df;opacity:.9}.freshness{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;border-radius:999px;padding:4px 8px;white-space:nowrap}.freshness.fresh{background:rgba(50,213,131,.14);color:var(--ok)}.freshness.delayed{background:rgba(253,176,34,.14);color:var(--warn)}.freshness.stale,.freshness.missing{background:rgba(249,112,102,.14);color:var(--error)}.metrics{padding:5px 13px}.metric{display:grid;grid-template-columns:minmax(130px,1fr) auto;gap:12px;padding:7px 0;border-bottom:1px solid rgba(39,52,73,.42)}.metric:last-child{border-bottom:0}.metric-name{color:#aebbd0}.metric-value{text-align:right;font-weight:800;font-variant-numeric:tabular-nums}.metric-age{grid-column:1/-1;text-align:right;color:var(--muted);font-size:10px;margin-top:-5px}.weather-foot{padding:10px 13px;background:rgba(23,32,51,.5);color:var(--muted);font-size:11px;line-height:1.55}
.battery-summary{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 16px;border-bottom:1px solid var(--border);background:rgba(96,165,250,.055)}.battery-summary>div{display:flex;align-items:center;gap:10px}.battery-summary>span{color:var(--muted);font-size:11px}.battery-summary-icon,.battery-visual{position:relative;display:inline-flex;border:2px solid currentColor;border-radius:4px}.battery-summary-icon{width:27px;height:14px}.battery-summary-icon:after,.battery-visual:after{content:"";position:absolute;right:-5px;top:25%;width:3px;height:50%;border-radius:0 2px 2px 0;background:currentColor}.battery-summary-icon span,.battery-visual span{display:block;margin:2px;background:currentColor;border-radius:1px}.battery-summary.all-ok{color:var(--ok)}.battery-summary.has-low{color:var(--warn)}.battery-summary.unknown{color:var(--muted)}.battery-summary.all-ok .battery-summary-icon span{width:100%}.battery-summary.has-low .battery-summary-icon span{width:28%}.battery-summary.unknown .battery-summary-icon span{width:0}.battery-grid{display:grid;grid-template-columns:repeat(4,minmax(210px,1fr));gap:12px;padding:14px}.battery-card{display:grid;grid-template-columns:42px minmax(0,1fr);gap:12px;align-items:center;min-height:86px;padding:12px;background:rgba(8,16,29,.52);border:1px solid var(--border);border-radius:14px}.battery-card.ok{color:var(--ok);border-color:rgba(50,213,131,.38)}.battery-card.low{color:var(--warn);border-color:rgba(253,176,34,.55);background:rgba(253,176,34,.055)}.battery-card.unknown{color:var(--muted)}.battery-visual{width:36px;height:20px}.battery-card.ok .battery-visual span{width:100%}.battery-card.low .battery-visual span{width:25%}.battery-card.unknown .battery-visual span{width:0}.battery-copy{min-width:0}.battery-copy strong,.battery-copy span,.battery-copy small{display:block}.battery-copy strong{color:var(--text);font-size:12px}.battery-copy span{margin-top:3px;font-size:12px;font-weight:800}.battery-copy small{margin-top:5px;color:var(--muted);font-size:9px;line-height:1.35}
.sensor-grid{display:grid;grid-template-columns:repeat(4,minmax(230px,1fr));gap:12px;padding:14px}.sensor-head strong{font-size:13px}.sensor-body{padding:8px 12px}.sensor-body>div{display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid rgba(39,52,73,.42)}.sensor-body>div:last-child{border-bottom:0}.sensor-body span{color:var(--muted);font-size:11px}.sensor-body b{font-size:12px;text-align:right}.sensor-map{display:block!important;padding-top:8px!important}.sensor-map span{display:block;margin-bottom:5px}.sensor-map code{display:block;color:#b8c6d9;font-size:9px;line-height:1.45;word-break:break-word}.sensor-map code+code{margin-top:2px}
.protocol-grid{display:grid;grid-template-columns:minmax(420px,.9fr) minmax(460px,1.1fr);gap:14px;padding:14px}.protocol-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.protocol-kpis>div{background:rgba(8,16,29,.52);border:1px solid var(--border);border-radius:12px;padding:12px}.protocol-kpis span{display:block;color:var(--muted);font-size:10px;text-transform:uppercase}.protocol-kpis b{display:block;font-size:22px;margin-top:8px}.protocol-detail{background:rgba(8,16,29,.52);border:1px solid var(--border);border-radius:12px;padding:14px}.protocol-detail h3{margin:0 0 8px;font-size:14px}.protocol-detail p{color:var(--muted);line-height:1.55}.anomaly{border-top:1px solid var(--border);padding-top:10px}.anomaly small{display:block;color:var(--muted);margin:4px 0 8px}.anomaly code,.candidate code{display:block;background:#07101d;border:1px solid var(--border);border-radius:8px;padding:8px;word-break:break-all;color:#bdc9da;margin-top:6px}.candidate{margin-top:10px;padding:10px;border-radius:9px;background:rgba(96,165,250,.08);color:#c9d8ee;line-height:1.5}
.timeline-list{padding:8px 14px 14px}.timeline-row{position:relative;display:grid;grid-template-columns:16px 1fr;gap:10px;padding:9px 0;border-bottom:1px solid rgba(39,52,73,.5)}.timeline-row:last-child{border-bottom:0}.timeline-dot{width:9px;height:9px;border-radius:50%;margin-top:5px;background:var(--info)}.timeline-dot.warning{background:var(--warn)}.timeline-dot.error{background:var(--error)}.timeline-dot.critical{background:var(--critical)}.timeline-row strong{font-size:12px}.timeline-row small{display:block;color:var(--muted);font-size:10px;margin-top:2px}.timeline-row p{margin:4px 0 0;color:#aab7c8;font-size:11px;line-height:1.4;word-break:break-word}
.filters{padding:14px;margin-bottom:16px}.filters form{display:block}.filter-source-row{display:grid;grid-template-columns:190px minmax(290px,420px);gap:12px;align-items:end;padding-bottom:13px;margin-bottom:13px;border-bottom:1px solid rgba(39,52,73,.72)}.filter-options-row{display:grid;grid-template-columns:auto auto 150px minmax(170px,1fr) minmax(170px,1fr) 95px 105px auto;gap:10px;align-items:end}.filter-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;justify-content:flex-end}.field label{display:block;color:var(--muted);font-size:11px;margin:0 0 6px}.field input,.field select{width:100%;height:38px;border-radius:9px;border:1px solid var(--border);background:#0c1424;color:var(--text);padding:0 10px}.check{height:38px;display:flex;align-items:center;gap:8px;color:var(--muted);white-space:nowrap}.check input{accent-color:var(--info)}button,.button{height:38px;border:0;border-radius:9px;padding:0 14px;background:#2563eb;color:white;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}.button.secondary{background:#253047;color:var(--text);border:1px solid var(--border)}.button.green{background:#087a55}.live-indicator{display:inline-flex;align-items:center;gap:5px;color:var(--muted)}.live-led{width:7px;height:7px;border-radius:50%;background:var(--ok);box-shadow:0 0 8px var(--ok)}.live-led.off{background:#64748b;box-shadow:none}.live-led.error{background:var(--error);box-shadow:0 0 8px var(--error)}.source-switch{display:grid;grid-template-columns:1fr 1fr;height:38px;border:1px solid var(--border);border-radius:10px;overflow:hidden;background:#0c1424}.source-switch label{position:relative;margin:0;cursor:pointer}.source-switch input{position:absolute;opacity:0;pointer-events:none}.source-switch span{height:100%;display:flex;align-items:center;justify-content:center;padding:0 14px;color:var(--muted);font-weight:800;transition:.15s ease}.source-switch label+label span{border-left:1px solid var(--border)}.source-switch input:checked+span{background:#2563eb;color:#fff;box-shadow:inset 0 0 0 1px rgba(255,255,255,.08)}.filter-source-row.manual-active{grid-template-columns:190px minmax(290px,420px) minmax(330px,1fr)}.manual-trace-field{transition:opacity .15s ease}.manual-trace-field.disabled{display:none}.source-help{margin-top:6px;color:var(--muted);font-size:11px;line-height:1.35}
.grid{display:grid;grid-template-columns:minmax(0,2.35fr) minmax(300px,.65fr);gap:16px}.grid>*,.side{min-width:0}.table-wrap{overflow:auto;max-height:680px}table{width:100%;border-collapse:collapse;min-width:980px}th,td{text-align:left;padding:10px 12px;border-bottom:1px solid rgba(39,52,73,.72);vertical-align:top}th{position:sticky;top:0;background:#131c2d;color:#a8b4c7;font-size:10px;text-transform:uppercase;letter-spacing:.05em;z-index:2}tr:hover td{background:rgba(96,165,250,.045)}td.time{white-space:nowrap;color:#c7d2e2;font-variant-numeric:tabular-nums}td.event{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700;white-space:nowrap}.badge{display:inline-flex;padding:3px 8px;border-radius:999px;text-transform:uppercase;font-weight:800;font-size:9px;letter-spacing:.04em}.badge.info{background:rgba(96,165,250,.14);color:#93c5fd}.badge.warning{background:rgba(253,176,34,.14);color:#fdb022}.badge.error{background:rgba(249,112,102,.14);color:#fda29b}.badge.critical{background:rgba(238,70,188,.14);color:#f9a8d4}.details{color:#b4c0d0;line-height:1.45;word-break:break-word}.raw summary{cursor:pointer;color:#7ea5df;margin-top:6px}.raw pre{white-space:pre-wrap;word-break:break-all;background:#08101d;padding:9px;border-radius:8px;color:#91a0b5;font-size:11px}.side{display:flex;flex-direction:column;gap:16px}.list{padding:5px 14px 12px}.list-row{display:flex;justify-content:space-between;gap:12px;padding:9px 2px;border-bottom:1px solid rgba(39,52,73,.55)}.list-row:last-child{border-bottom:0}.list-row .name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.list-row .count{font-weight:800;font-variant-numeric:tabular-nums}.session{padding:11px 2px;border-bottom:1px solid rgba(39,52,73,.55)}.session:last-child{border-bottom:0}.session strong{display:block}.session small{display:block;color:var(--muted);margin-top:4px;line-height:1.45}.empty{padding:24px;text-align:center;color:var(--muted)}.footer{color:var(--muted);font-size:11px;margin-top:15px;display:flex;gap:14px;justify-content:space-between}
@media(max-width:1350px){.console-strip{grid-template-columns:repeat(5,1fr)}.cards{grid-template-columns:repeat(4,1fr)}.filter-source-row{grid-template-columns:180px 300px}.filter-source-row.manual-active{grid-template-columns:180px 300px 1fr}.filter-options-row{grid-template-columns:repeat(4,minmax(150px,1fr))}.protocol-grid{grid-template-columns:1fr}.battery-grid,.sensor-grid{grid-template-columns:repeat(3,1fr)}.grid{grid-template-columns:1fr}}@media(max-width:900px){.weather-grid{grid-template-columns:repeat(2,minmax(260px,1fr))}.battery-grid,.sensor-grid{grid-template-columns:repeat(2,1fr)}.console-strip{grid-template-columns:repeat(3,1fr)}}@media(max-width:900px){.filter-source-row{grid-template-columns:1fr 1fr}.manual-trace-field{grid-column:1/-1}.filter-options-row{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-actions{justify-content:stretch}.filter-actions>*{flex:1 1 140px}}@media(max-width:650px){.wrap{padding:12px}.top{display:block}.status{margin-top:12px;width:max-content}.cards{grid-template-columns:repeat(2,1fr)}.weather-grid,.battery-grid,.sensor-grid{grid-template-columns:1fr}.battery-summary{align-items:flex-start}.console-strip{grid-template-columns:repeat(2,1fr)}.filter-source-row,.filter-options-row{grid-template-columns:1fr}.manual-trace-field{grid-column:auto}.footer{display:block}.footer span{display:block;margin-top:5px}.protocol-kpis{grid-template-columns:repeat(2,1fr)}.spark{display:none}}

.battery-summary.partial{color:var(--info)}.battery-summary.partial .battery-summary-icon span{width:65%}
.forecast-metric{display:grid;grid-template-columns:78px minmax(0,1fr);gap:13px;align-items:center;padding:10px 0;border-bottom:1px solid rgba(39,52,73,.42)}
.forecast-icon-wrap{width:72px;height:72px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(96,165,250,.22);border-radius:16px;background:rgba(96,165,250,.055)}
.forecast-svg{width:60px;height:60px;color:#d6e4f7}.forecast-sunny{color:#fdb022}.forecast-partly-cloudy,.forecast-partly-cloudy-night{color:#c8d5e7}.forecast-cloudy{color:#aebbd0}.forecast-rainy{color:#7eb6ff}.forecast-snowy{color:#dcecff}.forecast-clear-night{color:#c4b5fd}.forecast-unknown{color:var(--muted)}
.forecast-copy{min-width:0;display:flex;flex-direction:column;gap:3px}.forecast-copy strong{font-size:17px;line-height:1.15}.forecast-code{color:#93c5fd;font-size:12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.forecast-copy .metric-age{grid-column:auto;text-align:left;margin:2px 0 0}.forecast-note{color:#93c5fd}
@media(max-width:700px){.forecast-metric{grid-template-columns:64px minmax(0,1fr)}.forecast-icon-wrap{width:60px;height:60px}.forecast-svg{width:50px;height:50px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title"><h1>Oregon Scientific WMR Developer Trace</h1><p><b id="detectedFamily"><?= h(family_title($analysis['family'])) ?></b> · <span id="activeTrace"><?= h($activeTrace) ?></span> · <span class="live-indicator"><span id="liveLed" class="live-led <?= $liveRefresh===0?'off':'' ?>"></span><span id="liveText"><?= $liveRefresh>0?'Live AJAX '.$liveRefresh.' s':'Live disattivato' ?></span></span></p></div>
    <div id="healthBadge" class="status <?= h($analysis['health']['class']) ?>"><span class="dot"></span><span id="healthLabel"><?= h($analysis['health']['label']) ?></span></div>
  </div>

  <?php if(!empty($source['manual_trace_error'])): ?><div class="panel notice error"><strong>Trace manuale non disponibile.</strong> <?= h((string)$source['manual_trace_error']) ?> Percorso richiesto: <span class="mono"><?= h($activeTrace) ?></span>.<br>La modalità manuale non crea il file e non usa fallback: verificare nel driver <span class="mono">developer_trace = true</span>, il percorso configurato e i permessi di lettura del web server, oppure selezionare <strong>Automatico</strong>.</div>
  <?php elseif(!$files&&($source['detection_basis']??'')==='config'): ?><div class="panel notice warning"><strong>Famiglia rilevata dalla configurazione WeeWX: <?= h(strtoupper($analysis['family'])) ?>.</strong> Il file trace configurato <span class="mono"><?= h($activeTrace) ?></span> non esiste o non è leggibile. Configurazione: <span class="mono"><?= h((string)($source['config_path']??'/etc/weewx/weewx.conf')) ?></span><?php if(($source['trace_enabled']??null)===false): ?> · <strong>developer_trace risulta disabilitato</strong><?php endif; ?>.</div>
  <?php elseif(!$files): ?><div class="panel notice error"><strong>Famiglia e trace non rilevati automaticamente.</strong> La pagina non ha trovato un JSONL accessibile né una configurazione WeeWX leggibile in <span class="mono">/etc/weewx/weewx.conf</span> o <span class="mono">/home/weewx/weewx.conf</span>. Verificare configurazione e permessi del web server.</div><?php endif; ?>
  <?php if($analysis['scan_stopped']): ?><div class="panel notice warning">Analisi interrotta al limite di sicurezza di <?= number_format(MAX_RECORDS_SCANNED,0,',','.') ?> record.</div><?php endif; ?>
  <?php if($analysis['invalid_json']>0): ?><div class="panel notice warning">Rilevate <?= number_format($analysis['invalid_json'],0,',','.') ?> righe JSON non valide.</div><?php endif; ?>

  <div id="consoleStrip"><?= render_console_strip($analysis) ?></div>
  <div id="cards" class="cards"><?= render_cards($analysis) ?></div>

  <section class="panel weather-panel"><div class="section-head"><h2>Dati meteo live</h2><span>valori WeeWX prodotti dal driver · mini trend ultimi <?= SPARKLINE_POINTS ?> campioni</span></div><div id="weatherGrid" class="weather-grid"><?= render_weather($analysis) ?></div></section>

  <section class="panel battery-panel"><div class="section-head"><h2>Stato batterie</h2><span>0 = regolare · valore diverso da 0 = batteria bassa · console se supportata</span></div><div id="batteryBody"><?= render_batteries($analysis) ?></div></section>

  <section id="sensorPanel" class="panel sensor-panel"><div class="section-head"><h2>Sensori RF / canali termoigrometrici</h2><span id="sensorPanelCaption"><?= h(sensor_panel_caption($analysis)) ?></span></div><div id="sensorGrid" class="sensor-grid"><?= render_sensors($analysis) ?></div></section>

  <section class="panel protocol-panel"><div class="section-head"><h2>Protocol Monitor</h2><span><?= $analysis['family']==='wmr100' ? 'FF FF framing · checksum · lunghezze · resync' : 'command/length framing · checksum · stream resync' ?></span></div><div id="protocolBody"><?= render_protocol($analysis) ?></div></section>

  <section class="panel timeline-panel"><div class="section-head"><h2>USB / recovery timeline</h2><span>ultimi eventi operativi</span></div><div id="timelineBody" class="timeline-list"><?= render_timeline($analysis) ?></div></section>

  <div class="panel filters"><form method="get" id="traceFilterForm">
    <div class="filter-source-row <?= $traceMode==='manual'?'manual-active':'' ?>" id="filterSourceRow">
      <div class="field">
        <label>Famiglia / parser</label>
        <select name="station" id="stationModeSelect">
          <option value="auto" <?= $stationMode==='auto'?'selected':'' ?>>Rileva automaticamente</option>
          <option value="wmr100" <?= $stationMode==='wmr100'?'selected':'' ?>>WMR100 / WMR88</option>
          <option value="wmr200" <?= $stationMode==='wmr200'?'selected':'' ?>>WMR200</option>
        </select>
      </div>
      <div class="field">
        <label>Modalità sorgente trace</label>
        <div class="source-switch" role="group" aria-label="Modalità sorgente trace">
          <label><input type="radio" name="trace_mode" value="auto" <?= $traceMode==='auto'?'checked':'' ?>><span>Automatico</span></label>
          <label><input type="radio" name="trace_mode" value="manual" <?= $traceMode==='manual'?'checked':'' ?>><span>Manuale</span></label>
        </div>
        <div class="source-help">Automatico sceglie il trace attivo più recente. Manuale usa esclusivamente il file indicato: non lo crea, non abilita il driver e non applica fallback. La scelta resta memorizzata anche dopo F5 e riapertura della pagina.</div>
      </div>
      <div class="field manual-trace-field <?= $traceMode==='manual'?'':'disabled' ?>" id="manualTraceField">
        <label>File trace manuale</label>
        <input id="manualTraceInput" name="trace_file" list="tracePaths" value="<?= h($manualTraceInput) ?>" placeholder="/var/log/weewx/wmr100-developer-trace.jsonl" <?= $traceMode==='manual'?'':'disabled' ?>>
        <datalist id="tracePaths"><option value="/var/log/weewx/wmr100-developer-trace.jsonl"><option value="/var/log/weewx/wmr200-developer-trace.jsonl"><option value="/tmp/wmr200-developer-trace.jsonl"></datalist>
        <div class="source-help">Percorsi consentiti: <span class="mono">/var/log/weewx</span> e <span class="mono">/tmp</span>.</div>
      </div>
    </div>

    <div class="filter-options-row">
      <label class="check"><input type="checkbox" name="problems" value="1" <?= $onlyProblems?'checked':'' ?>> Solo problemi</label>
      <label class="check"><input type="checkbox" name="show_timeout110" value="1" <?= $showTimeout110?'checked':'' ?>> Timeout 110</label>
      <div class="field"><label>Severità</label><select name="severity"><?php foreach($validSeverities as $s): ?><option value="<?= h($s) ?>" <?= $severityFilter===$s?'selected':'' ?>><?= h(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Evento contiene</label><input name="event" value="<?= h($eventFilter) ?>" placeholder="usb_read_timeout"></div>
      <div class="field"><label>Ricerca</label><input name="q" value="<?= h($searchFilter) ?>" placeholder="errno, packet, reason..."></div>
      <div class="field"><label>Righe</label><input type="number" name="limit" min="25" max="<?= MAX_DISPLAY_LIMIT ?>" value="<?= $displayLimit ?>"></div>
      <div class="field"><label>Live AJAX (s)</label><input type="number" name="live" min="0" max="60" value="<?= $liveRefresh ?>"></div>
      <label class="check"><input type="checkbox" name="rotated" value="1" <?= $includeRotated?'checked':'' ?>> Backup ruotati</label>
    </div>

    <div class="filter-actions">
      <button type="submit">Applica</button>
      <a class="button secondary" href="?">Azzera filtri</a>
      <a class="button secondary" href="<?= h($csvUrl) ?>">CSV</a>
      <a class="button green" href="<?= h($bundleUrl) ?>">Bundle diagnostico</a>
    </div>
  </form></div>

  <div class="grid">
    <section class="panel"><div class="section-head"><h2>Eventi selezionati</h2><span id="eventsCaption">ultimi <?= count($analysis['rows']) ?> corrispondenti</span></div><div class="table-wrap"><table><thead><tr><th>Ora locale</th><th>Livello</th><th>Evento</th><th>Dir.</th><th>Dettagli</th></tr></thead><tbody id="eventsBody"><?= render_event_rows($analysis) ?></tbody></table></div></section>
    <aside class="side">
      <section class="panel"><div class="section-head"><h2>Sessioni recenti</h2></div><div id="sessionsBody" class="list"><?= render_sessions($analysis) ?></div></section>
      <section class="panel"><div class="section-head"><h2>Eventi per tipo</h2></div><div id="eventCountsBody" class="list"><?= render_simple_counts($analysis['event_counts']) ?></div></section>
      <section class="panel"><div class="section-head"><h2>Pacchetti</h2></div><div id="packetCountsBody" class="list"><?= render_simple_counts($analysis['packet_counts']) ?></div></section>
      <section class="panel"><div class="section-head"><h2>Versioni driver</h2></div><div id="versionsBody" class="list"><?= render_driver_versions($analysis) ?></div></section>
    </aside>
  </div>

  <div class="footer"><span>Sorgente: <?= h(strtoupper($analysis['family'])) ?> <?= ($analysis['source']['manual_trace']??false)?'(trace manuale)':(($analysis['source']['auto_detected']??false)?'(auto)':'(famiglia manuale)') ?> · <?= h($activeTrace) ?></span><span>Periodo: <?= h(local_timestamp($analysis['first_timestamp'],false)) ?> → <?= h(local_timestamp($analysis['last_timestamp'],false)) ?></span><span>Health corrente: <?= h($analysis['health']['reason']) ?></span><span id="generatedAt">Generato: <?= h((new DateTimeImmutable('now',new DateTimeZone(LOCAL_TIMEZONE)))->format('d/m/Y H:i:s T')) ?></span></div>
</div>
<script>
(() => {
  const modeRadios=[...document.querySelectorAll('input[name="trace_mode"]')];
  const field=document.getElementById('manualTraceField');
  const input=document.getElementById('manualTraceInput');
  const row=document.getElementById('filterSourceRow');
  const station=document.getElementById('stationModeSelect');
  const form=document.getElementById('traceFilterForm');
  const maxAge=<?= SOURCE_PREF_COOKIE_TTL ?>;
  const knownTracePaths=[
    '/var/log/weewx/wmr100-developer-trace.jsonl',
    '/var/log/weewx/wmr200-developer-trace.jsonl',
    '/tmp/wmr200-developer-trace.jsonl'
  ];

  function defaultTracePath(family){
    return family==='wmr200'
      ? '/var/log/weewx/wmr200-developer-trace.jsonl'
      : '/var/log/weewx/wmr100-developer-trace.jsonl';
  }

  function remember(name,value){
    document.cookie=encodeURIComponent(name)+'='+encodeURIComponent(value)+'; Max-Age='+maxAge+'; Path=/; SameSite=Lax';
  }
  function rememberCurrentSource(){
    const selected=modeRadios.find(r=>r.checked)?.value||'auto';
    remember('<?= SOURCE_PREF_COOKIE_TRACE_MODE ?>',selected);
    if(station) remember('<?= SOURCE_PREF_COOKIE_STATION ?>',station.value||'auto');
    if(input && input.value.trim()) remember('<?= SOURCE_PREF_COOKIE_TRACE_FILE ?>',input.value.trim());
  }
  function syncTraceMode(){
    const manual=modeRadios.some(r=>r.checked&&r.value==='manual');
    if(field) field.classList.toggle('disabled',!manual);
    if(row) row.classList.toggle('manual-active',manual);
    if(input) input.disabled=!manual;
    if(manual && input && !input.value){
      input.value=defaultTracePath(station?.value||'auto');
    }
  }

  modeRadios.forEach(r=>r.addEventListener('change',()=>{syncTraceMode();rememberCurrentSource();}));
  station?.addEventListener('change',()=>{
    const manual=modeRadios.some(r=>r.checked&&r.value==='manual');
    if(manual && input && (!input.value.trim() || knownTracePaths.includes(input.value.trim()))){
      input.value=defaultTracePath(station.value||'auto');
    }
    rememberCurrentSource();
  });
  input?.addEventListener('change',rememberCurrentSource);
  input?.addEventListener('blur',rememberCurrentSource);
  form?.addEventListener('submit',rememberCurrentSource);
  syncTraceMode();
})();
(() => {
  const interval = <?= (int)$liveRefresh ?>;
  if (!interval) return;
  const params = new URLSearchParams(window.location.search);
  params.set('api','1'); params.delete('download'); params.delete('rotated');
  let busy = false;
  async function updateLive(){
    if (busy) return; busy = true;
    const led=document.getElementById('liveLed'), text=document.getElementById('liveText');
    try {
      const liveParams=new URLSearchParams(params);liveParams.set('_ts',Date.now().toString());
      const r=await fetch('?' + liveParams.toString(), {cache:'no-store', headers:{'Accept':'application/json','Cache-Control':'no-cache'}});
      if(!r.ok) throw new Error('HTTP '+r.status);
      const d=await r.json();
      const h=d.html||{};
      if(h.console!==undefined) document.getElementById('consoleStrip').innerHTML=h.console;
      if(h.cards!==undefined) document.getElementById('cards').innerHTML=h.cards;
      if(h.weather!==undefined) document.getElementById('weatherGrid').innerHTML=h.weather;
      if(h.batteries!==undefined) document.getElementById('batteryBody').innerHTML=h.batteries;
      const sensorPanel=document.getElementById('sensorPanel');
      if(sensorPanel) sensorPanel.hidden=!d.show_sensors;
      if(d.show_sensors && h.sensors!==undefined){const sg=document.getElementById('sensorGrid');if(sg)sg.innerHTML=h.sensors;const sc=document.getElementById('sensorPanelCaption');if(sc)sc.textContent=d.sensor_caption||((d.model||'WMR')+' · canali termoigrometrici CH0–CH'+(d.max_remote_channels||3));}
      if(h.protocol!==undefined) document.getElementById('protocolBody').innerHTML=h.protocol;
      if(h.timeline!==undefined) document.getElementById('timelineBody').innerHTML=h.timeline;
      if(h.events!==undefined) document.getElementById('eventsBody').innerHTML=h.events;
      if(h.sessions!==undefined) document.getElementById('sessionsBody').innerHTML=h.sessions;
      if(h.event_counts!==undefined) document.getElementById('eventCountsBody').innerHTML=h.event_counts;
      if(h.packet_counts!==undefined) document.getElementById('packetCountsBody').innerHTML=h.packet_counts;
      if(h.versions!==undefined) document.getElementById('versionsBody').innerHTML=h.versions;
      const badge=document.getElementById('healthBadge');
      badge.className='status '+(d.health?.class||'ok'); document.getElementById('healthLabel').textContent=d.health?.label||'Operativo';
      document.getElementById('generatedAt').textContent='Aggiornato: '+(d.generated||'');
      const fam=document.getElementById('detectedFamily'), src=document.getElementById('activeTrace');
      if(fam) fam.textContent=d.family_title||d.family||'WMR'; if(src) src.textContent=d.source||'';
      led.className='live-led'; text.textContent='Live AJAX '+interval+' s';
    } catch(e) {
      led.className='live-led error'; text.textContent='Live: errore '+e.message;
    } finally { busy=false; }
  }
  setTimeout(updateLive, Math.min(1200, interval*1000));
  setInterval(updateLive, interval*1000);
})();
</script>
</body>
</html>
