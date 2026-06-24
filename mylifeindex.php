<?php
// ============================================================
// SHS Live TV · PRO · M3U Player with Analytics
// Single-file PHP solution using GitHub M3U playlist
// ============================================================

// --- configuration ---
define('M3U_URL', 'https://raw.githubusercontent.com/alcmystry-crypto/livesports/refs/heads/main/onlysports.m3u');
define('CACHE_FILE', __DIR__ . '/m3u_cache.json');
define('STATS_FILE', __DIR__ . '/stats.json');
define('CACHE_TTL', 300); // 5 minutes cache
define('SITE_NAME', 'SHAHRIAR HASAN SHOVON');
define('COPYRIGHT_START', 2021);

// --- analytics functions ---
function initStats() {
    if (!file_exists(STATS_FILE)) {
        $default = [
            'total_views' => 0,
            'total_viewers' => 0,
            'total_traffic_mb' => 0,
            'active_viewers' => 0,
            'stream_stats' => [],
            'sessions' => []
        ];
        file_put_contents(STATS_FILE, json_encode($default));
    }
    return json_decode(file_get_contents(STATS_FILE), true);
}

function saveStats($stats) {
    file_put_contents(STATS_FILE, json_encode($stats));
}

function trackView($streamId, $streamName) {
    $stats = initStats();
    $sessionId = isset($_COOKIE['viewer_session']) ? $_COOKIE['viewer_session'] : null;
    
    if (!$sessionId) {
        $sessionId = bin2hex(random_bytes(16));
        setcookie('viewer_session', $sessionId, time() + 86400 * 30, '/');
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $sessionKey = md5($sessionId . $ip);
    
    // Check if this is a new viewer (unique)
    $isNewViewer = !isset($stats['sessions'][$sessionKey]);
    
    // Check if viewer is active (within last 5 minutes)
    $isActive = false;
    if (isset($stats['sessions'][$sessionKey])) {
        $lastSeen = $stats['sessions'][$sessionKey]['last_seen'] ?? 0;
        if (time() - $lastSeen < 300) {
            $isActive = true;
        }
    }
    
    // Update session
    $stats['sessions'][$sessionKey] = [
        'ip' => $ip,
        'user_agent' => $userAgent,
        'last_seen' => time(),
        'stream_id' => $streamId,
        'views' => ($stats['sessions'][$sessionKey]['views'] ?? 0) + 1
    ];
    
    // Update stream stats
    if (!isset($stats['stream_stats'][$streamId])) {
        $stats['stream_stats'][$streamId] = [
            'name' => $streamName,
            'views' => 0,
            'unique_viewers' => 0,
            'traffic_kb' => 0
        ];
    }
    
    $stats['stream_stats'][$streamId]['views']++;
    if ($isNewViewer) {
        $stats['stream_stats'][$streamId]['unique_viewers']++;
        $stats['total_viewers']++;
    }
    
    // Add traffic (simulate 500KB-3MB per view)
    $trafficKb = rand(500, 3000);
    $stats['stream_stats'][$streamId]['traffic_kb'] += $trafficKb;
    $stats['total_traffic_mb'] += ($trafficKb / 1024);
    $stats['total_views']++;
    
    // Calculate active viewers (last 5 minutes)
    $activeCount = 0;
    $now = time();
    foreach ($stats['sessions'] as $key => $session) {
        if ($now - $session['last_seen'] < 300) {
            $activeCount++;
        } else {
            // Remove inactive sessions older than 1 hour
            if ($now - $session['last_seen'] > 3600) {
                unset($stats['sessions'][$key]);
            }
        }
    }
    $stats['active_viewers'] = $activeCount;
    
    saveStats($stats);
    return $stats;
}

function getAnalytics() {
    $stats = initStats();
    
    // Recalculate active viewers
    $activeCount = 0;
    $now = time();
    foreach ($stats['sessions'] as $key => $session) {
        if ($now - $session['last_seen'] < 300) {
            $activeCount++;
        }
    }
    $stats['active_viewers'] = $activeCount;
    
    return [
        'total_views' => $stats['total_views'] ?? 0,
        'total_viewers' => $stats['total_viewers'] ?? 0,
        'total_traffic_mb' => round($stats['total_traffic_mb'] ?? 0, 1),
        'active_viewers' => $activeCount,
        'stream_stats' => $stats['stream_stats'] ?? []
    ];
}

// --- fetch and parse M3U ---
function fetchM3UPlaylist() {
    // Check cache first
    if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE) < CACHE_TTL)) {
        $cached = json_decode(file_get_contents(CACHE_FILE), true);
        if ($cached && isset($cached['streams']) && !empty($cached['streams'])) {
            return $cached['streams'];
        }
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, M3U_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($content)) {
        if (file_exists(CACHE_FILE)) {
            $cached = json_decode(file_get_contents(CACHE_FILE), true);
            if ($cached && isset($cached['streams'])) {
                return $cached['streams'];
            }
        }
        return getFallbackStreams();
    }

    $streams = parseM3U($content);
    
    if (empty($streams)) {
        return getFallbackStreams();
    }

    file_put_contents(CACHE_FILE, json_encode([
        'streams' => $streams,
        'fetched_at' => time()
    ]));

    return $streams;
}

function parseM3U($content) {
    $lines = explode("\n", $content);
    $streams = [];
    $current = null;
    $index = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        if (strpos($line, '#EXTINF:') === 0) {
            $current = ['name' => 'Unknown', 'url' => ''];
            
            if (preg_match('/#EXTINF:[^,]*,?\s*(.+)$/', $line, $matches)) {
                $name = trim($matches[1]);
                $name = preg_replace('/\s*\([^)]*\)\s*/', '', $name);
                $name = preg_replace('/\s*\[[^\]]*\]\s*/', '', $name);
                $name = trim($name);
                if (empty($name)) $name = 'Stream ' . ($index + 1);
                $current['name'] = $name;
            }
            $current['index'] = $index++;
        } elseif (strpos($line, '#') === 0) {
            continue;
        } elseif ($current && strpos($line, 'http') === 0) {
            $current['url'] = $line;
            if (!empty($current['url']) && !empty($current['name'])) {
                $streams[] = $current;
            }
            $current = null;
        }
    }

    if ($current && empty($current['url'])) {
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if (strpos($line, 'http') === 0) {
                $current['url'] = $line;
                if (!empty($current['url']) && !empty($current['name'])) {
                    $streams[] = $current;
                }
                break;
            }
        }
    }

    return $streams;
}

function getFallbackStreams() {
    return [
        ['name' => 'Sports 1', 'url' => 'https://example.com/stream1.m3u8'],
        ['name' => 'Sports 2', 'url' => 'https://example.com/stream2.m3u8'],
        ['name' => 'Sports 3', 'url' => 'https://example.com/stream3.m3u8'],
        ['name' => 'Sports 4', 'url' => 'https://example.com/stream4.m3u8'],
        ['name' => 'Sports 5', 'url' => 'https://example.com/stream5.m3u8']
    ];
}

// --- handle API requests ---
function handleApi($streams) {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'playlist':
            echo json_encode($streams);
            break;
            
        case 'streams':
            $streamList = array_map(function($s, $i) {
                return ['id' => $i, 'name' => $s['name'], 'url' => $s['url']];
            }, $streams, array_keys($streams));
            echo json_encode($streamList);
            break;
            
        case 'stats':
            $stats = getAnalytics();
            echo json_encode($stats);
            break;
            
        case 'track':
            $streamId = isset($_GET['stream_id']) ? (int)$_GET['stream_id'] : 0;
            $streamName = isset($_GET['name']) ? $_GET['name'] : 'Unknown';
            if ($streamId >= 0) {
                trackView($streamId, $streamName);
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid stream_id']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// --- main ---
$streams = fetchM3UPlaylist();
$currentYear = date('Y');

if (isset($_GET['api'])) {
    handleApi($streams);
}

$currentStreamId = isset($_GET['play']) ? (int)$_GET['play'] : 0;
if ($currentStreamId < 0 || $currentStreamId >= count($streams)) {
    $currentStreamId = 0;
}

// Track view if playing
if ($currentStreamId >= 0 && isset($streams[$currentStreamId])) {
    trackView($currentStreamId, $streams[$currentStreamId]['name']);
}

$analytics = getAnalytics();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" />
    <title>SHS Live TV · M3U Player · Analytics</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        
        body {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            margin: 0;
            background: #0c0f14;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(26, 35, 46, 0.8) 0%, rgba(10, 13, 18, 0.95) 90%),
                radial-gradient(circle at 80% 70%, rgba(101, 167, 252, 0.05) 0%, transparent 50%);
            transition: background 0.5s;
            position: relative;
            overflow-x: hidden;
        }
        
        body[data-theme="light"] {
            background: #e8edf2;
            background-image: 
                radial-gradient(circle at 20% 30%, rgba(245, 248, 252, 0.9) 0%, rgba(220, 227, 236, 0.95) 90%),
                radial-gradient(circle at 80% 70%, rgba(59, 123, 201, 0.05) 0%, transparent 50%);
        }
        
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            pointer-events: none;
            z-index: 0;
            animation: orbFloat 15s ease-in-out infinite alternate;
        }
        .orb-1 {
            width: 400px;
            height: 400px;
            top: -100px;
            left: -100px;
            background: rgba(101, 167, 252, 0.08);
        }
        .orb-2 {
            width: 500px;
            height: 500px;
            bottom: -150px;
            right: -150px;
            background: rgba(136, 255, 89, 0.06);
            animation-delay: -5s;
            animation-duration: 20s;
        }
        body[data-theme="light"] .orb-1 {
            background: rgba(59, 123, 201, 0.06);
        }
        body[data-theme="light"] .orb-2 {
            background: rgba(59, 123, 201, 0.04);
        }
        
        @keyframes orbFloat {
            0% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(50px, -30px) scale(1.1); }
            66% { transform: translate(-30px, 40px) scale(0.9); }
            100% { transform: translate(20px, -20px) scale(1.05); }
        }
        
        .player-card {
            max-width: 1200px;
            width: 100%;
            background: linear-gradient(145deg, rgba(22, 31, 41, 0.92) 0%, rgba(15, 21, 30, 0.95) 100%);
            border-radius: 48px;
            padding: 28px 28px 32px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(181, 255, 239, 0.08);
            position: relative;
            z-index: 1;
            backdrop-filter: blur(10px);
        }
        body[data-theme="light"] .player-card {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.92) 0%, rgba(242, 246, 252, 0.95) 100%);
            border-color: rgba(0, 20, 40, 0.08);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.10), 0 0 0 1px rgba(0, 0, 0, 0.03);
        }
        
        .header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 12px;
            gap: 12px;
        }
        
        .title-group h1 {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #eeff59 0%, #b7ff59 45%, #88ff59 80%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin: 0;
            line-height: 1.2;
        }
        body[data-theme="light"] .title-group h1 {
            background: linear-gradient(135deg, #1a5a8a, #3b7bc9, #4a8be0);
            -webkit-background-clip: text;
            background-clip: text;
        }
        
        .title-group .badge-m3u {
            background: rgba(101, 167, 252, 0.18);
            border: 1px solid #65a7fc;
            color: #b5ffef;
            padding: 0 16px;
            border-radius: 60px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            margin-left: 12px;
            line-height: 26px;
            vertical-align: middle;
        }
        body[data-theme="light"] .title-group .badge-m3u {
            background: rgba(59, 123, 201, 0.10);
            border-color: #3b7bc9;
            color: #1a5a8a;
        }
        
        .sub-tag {
            color: #b5ffef;
            font-weight: 400;
            font-size: 0.85rem;
            background: rgba(101, 167, 252, 0.08);
            padding: 6px 18px 6px 16px;
            border-radius: 60px;
            border-left: 3px solid #65a7fc;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 6px;
            border: 1px solid rgba(181, 255, 239, 0.1);
        }
        body[data-theme="light"] .sub-tag {
            color: #1a2a3a;
            background: rgba(59, 123, 201, 0.06);
            border-left-color: #3b7bc9;
        }
        
        .theme-toggle {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(181, 255, 239, 0.08);
            color: #b5ffef;
            padding: 4px 16px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            backdrop-filter: blur(4px);
            line-height: 26px;
        }
        body[data-theme="light"] .theme-toggle {
            background: rgba(0, 0, 0, 0.02);
            border-color: rgba(0, 20, 40, 0.08);
            color: #1a2a3a;
        }
        .theme-toggle:hover {
            border-color: #65a7fc66;
            color: #fff;
            transform: scale(1.05);
        }
        body[data-theme="light"] .theme-toggle:hover {
            color: #1a2a3a;
            border-color: #3b7bc9;
        }
        
        /* Analytics Bar */
        .analytics-bar {
            display: flex;
            gap: 16px;
            padding: 12px 18px;
            background: rgba(0, 0, 0, 0.25);
            border-radius: 20px;
            margin-bottom: 18px;
            flex-wrap: wrap;
            border: 1px solid rgba(181, 255, 239, 0.06);
            animation: fadeInUp 0.8s ease 0.5s both;
        }
        body[data-theme="light"] .analytics-bar {
            background: rgba(0, 0, 0, 0.03);
            border-color: rgba(0, 20, 40, 0.06);
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .analytics-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
            color: #b5ffef;
            transition: all 0.3s;
            flex: 1;
            min-width: 120px;
        }
        body[data-theme="light"] .analytics-item {
            color: #1a2a3a;
        }
        .analytics-item:hover {
            transform: scale(1.02);
        }
        .analytics-item .label {
            opacity: 0.6;
            font-weight: 400;
        }
        .analytics-item .value {
            font-weight: 700;
            font-size: 0.95rem;
            background: rgba(101, 167, 252, 0.10);
            padding: 2px 14px;
            border-radius: 20px;
            line-height: 28px;
            border: 1px solid rgba(101, 167, 252, 0.10);
            min-width: 50px;
            text-align: center;
            transition: all 0.3s;
        }
        body[data-theme="light"] .analytics-item .value {
            background: rgba(59, 123, 201, 0.06);
            border-color: rgba(59, 123, 201, 0.08);
        }
        .analytics-item .value:hover {
            transform: scale(1.05);
            border-color: rgba(101, 167, 252, 0.3);
        }
        .analytics-item .value.active-value {
            color: #88ff59;
        }
        body[data-theme="light"] .analytics-item .value.active-value {
            color: #2a8a3a;
        }
        
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 20px;
            margin: 18px 0 22px;
        }
        @media (max-width: 900px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .video-wrapper {
            background: #0b1017;
            border-radius: 32px;
            overflow: hidden;
            box-shadow: inset 0 4px 20px rgba(0, 0, 0, 0.7), 0 8px 24px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(181, 255, 239, 0.06);
            aspect-ratio: 16 / 9;
            position: relative;
        }
        body[data-theme="light"] .video-wrapper {
            background: #d0d9e6;
            border-color: rgba(0, 20, 40, 0.08);
        }
        video {
            width: 100%;
            height: 100%;
            display: block;
            background: #0a0e14;
            object-fit: contain;
        }
        
        .playlist-panel {
            background: rgba(18, 26, 36, 0.75);
            backdrop-filter: blur(8px);
            border-radius: 28px;
            padding: 18px 8px 12px 18px;
            border: 1px solid rgba(181, 255, 239, 0.06);
            box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.4);
        }
        body[data-theme="light"] .playlist-panel {
            background: rgba(240, 245, 252, 0.80);
            border-color: rgba(0, 20, 40, 0.06);
        }
        
        .playlist-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #b5ffef;
            font-size: 0.8rem;
            font-weight: 600;
            padding-right: 14px;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 8px;
        }
        body[data-theme="light"] .playlist-header {
            color: #1a2a3a;
        }
        .playlist-header .left {
            background: rgba(101, 167, 252, 0.12);
            padding: 4px 20px 4px 18px;
            border-radius: 40px;
            color: #eeff59;
            border: 1px solid rgba(238, 255, 89, 0.2);
            font-size: 0.75rem;
        }
        body[data-theme="light"] .playlist-header .left {
            background: rgba(59, 123, 201, 0.10);
            color: #1a5a8a;
        }
        .playlist-header .count-badge {
            background: rgba(181, 255, 239, 0.06);
            padding: 4px 16px;
            border-radius: 40px;
            color: #b5ffef;
            border: 1px solid rgba(101, 167, 252, 0.15);
            font-size: 0.7rem;
        }
        body[data-theme="light"] .playlist-header .count-badge {
            background: rgba(0, 80, 160, 0.04);
            color: #1a2a3a;
            border-color: rgba(0, 80, 160, 0.08);
        }
        
        .extra-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 14px;
            padding-right: 10px;
            align-items: center;
        }
        .extra-controls .btn-sm {
            background: rgba(30, 42, 60, 0.6);
            border: 1px solid rgba(181, 255, 239, 0.08);
            color: #b5ffef;
            padding: 4px 14px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            backdrop-filter: blur(4px);
        }
        body[data-theme="light"] .extra-controls .btn-sm {
            background: rgba(230, 240, 250, 0.6);
            border-color: rgba(0, 20, 40, 0.08);
            color: #1a2a3a;
        }
        .extra-controls .btn-sm:hover {
            background: rgba(101, 167, 252, 0.2);
            border-color: #65a7fc66;
            color: #fff;
            transform: scale(1.05);
        }
        body[data-theme="light"] .extra-controls .btn-sm:hover {
            color: #1a2a3a;
            border-color: #3b7bc9;
        }
        .extra-controls .search-box {
            background: rgba(12, 20, 30, 0.6);
            border: 1px solid rgba(181, 255, 239, 0.1);
            border-radius: 40px;
            padding: 4px 16px 4px 18px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex: 1;
            min-width: 120px;
            backdrop-filter: blur(4px);
        }
        body[data-theme="light"] .extra-controls .search-box {
            background: rgba(255, 255, 255, 0.7);
            border-color: rgba(0, 20, 40, 0.08);
        }
        .extra-controls .search-box input {
            background: transparent;
            border: none;
            color: #d0def0;
            padding: 6px 0;
            font-size: 0.8rem;
            width: 100%;
            outline: none;
        }
        body[data-theme="light"] .extra-controls .search-box input {
            color: #1a2a3a;
        }
        .extra-controls .search-box input::placeholder {
            color: #6d87b0;
        }
        body[data-theme="light"] .extra-controls .search-box input::placeholder {
            color: #6a7a8a;
        }
        .extra-controls .search-box span {
            color: #6d87b0;
        }
        body[data-theme="light"] .extra-controls .search-box span {
            color: #6a7a8a;
        }
        
        .playlist-items {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 220px;
            overflow-y: auto;
            padding-right: 10px;
        }
        .playlist-items::-webkit-scrollbar { width: 5px; }
        .playlist-items::-webkit-scrollbar-track {
            background: #1f2937;
            border-radius: 20px;
        }
        body[data-theme="light"] .playlist-items::-webkit-scrollbar-track {
            background: #d0d9e6;
        }
        .playlist-items::-webkit-scrollbar-thumb {
            background: #65a7fc;
            border-radius: 20px;
        }
        body[data-theme="light"] .playlist-items::-webkit-scrollbar-thumb {
            background: #3b7bc9;
        }
        
        .playlist-item {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(28, 38, 50, 0.6);
            backdrop-filter: blur(2px);
            padding: 10px 16px 10px 18px;
            border-radius: 24px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid transparent;
            color: #d0def0;
            font-weight: 450;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3);
        }
        body[data-theme="light"] .playlist-item {
            background: rgba(255, 255, 255, 0.7);
            color: #1a2634;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        }
        .playlist-item:hover {
            background: rgba(40, 56, 78, 0.7);
            border-color: #65a7fc55;
            transform: translateX(4px) scale(1.02);
        }
        body[data-theme="light"] .playlist-item:hover {
            background: rgba(200, 215, 235, 0.7);
            border-color: #3b7bc955;
        }
        .playlist-item.active {
            background: rgba(30, 50, 80, 0.7);
            border-color: #65a7fc;
            box-shadow: 0 0 0 1px #65a7fc, 0 8px 24px rgba(101, 167, 252, 0.2);
            color: #ffffff;
        }
        body[data-theme="light"] .playlist-item.active {
            background: rgba(200, 220, 250, 0.8);
            border-color: #3b7bc9;
            color: #0a1a2a;
        }
        
        .playlist-item .index-badge {
            background: #1f2d40;
            color: #b5ffef;
            border-radius: 40px;
            padding: 0 10px;
            font-size: 0.6rem;
            font-weight: 700;
            line-height: 22px;
            min-width: 26px;
            text-align: center;
            border: 1px solid rgba(181, 255, 239, 0.05);
            flex-shrink: 0;
        }
        body[data-theme="light"] .playlist-item .index-badge {
            background: #dbe3ed;
            color: #1a2a3a;
        }
        .playlist-item.active .index-badge {
            background: #65a7fc;
            color: #0b111a;
            border-color: #88ff59;
        }
        body[data-theme="light"] .playlist-item.active .index-badge {
            background: #3b7bc9;
            color: #ffffff;
        }
        
        .playlist-item .stream-name {
            flex: 1;
            font-size: 0.8rem;
            word-break: break-word;
        }
        .playlist-item .stream-name::before {
            content: "⏵";
            font-size: 0.6rem;
            opacity: 0.3;
            margin-right: 4px;
            color: #b5ffef;
        }
        body[data-theme="light"] .playlist-item .stream-name::before {
            color: #4a6a8a;
        }
        .playlist-item.active .stream-name::before {
            opacity: 1;
            color: #eeff59;
        }
        
        .status-badge {
            background: rgba(40, 52, 72, 0.6);
            border-radius: 60px;
            padding: 0 10px;
            font-size: 0.5rem;
            font-weight: 700;
            color: #8aa3c9;
            line-height: 20px;
            border: 1px solid rgba(255, 255, 255, 0.02);
            text-transform: uppercase;
            white-space: nowrap;
            flex-shrink: 0;
        }
        body[data-theme="light"] .status-badge {
            background: rgba(200, 210, 225, 0.6);
            color: #3a4a5a;
        }
        .playlist-item.active .status-badge {
            background: #65a7fc22;
            color: #eeff59;
            border-color: #eeff5944;
        }
        body[data-theme="light"] .playlist-item.active .status-badge {
            background: #3b7bc922;
            color: #1a5a8a;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 14px;
            padding-right: 6px;
            flex-wrap: wrap;
        }
        .btn {
            background: rgba(30, 42, 60, 0.7);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(181, 255, 239, 0.08);
            color: #c8daf5;
            font-weight: 600;
            font-size: 0.7rem;
            padding: 6px 18px;
            border-radius: 60px;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        body[data-theme="light"] .btn {
            background: rgba(230, 240, 250, 0.7);
            border-color: rgba(0, 20, 40, 0.08);
            color: #1a2a3a;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        }
        .btn-primary {
            background: linear-gradient(135deg, #65a7fc, #4a8be0);
            border-color: #65a7fc;
            color: #0c131e;
            font-weight: 700;
            box-shadow: 0 4px 16px rgba(101, 167, 252, 0.25);
        }
        body[data-theme="light"] .btn-primary {
            background: linear-gradient(135deg, #4a8be0, #3b7bc9);
            border-color: #3b7bc9;
            color: #ffffff;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #88ff59, #65a7fc);
            border-color: #b5ffef;
            color: #0a0f18;
            box-shadow: 0 0 30px #65a7fc55;
            transform: scale(1.05);
        }
        .btn:hover {
            background: rgba(50, 72, 100, 0.7);
            border-color: #88ff5988;
            color: #f0faff;
            transform: scale(1.05);
        }
        body[data-theme="light"] .btn:hover {
            background: rgba(180, 200, 225, 0.7);
            color: #0a1a2a;
        }
        .btn:active { transform: scale(0.95); }
        
        .footer-meta {
            display: flex;
            justify-content: space-between;
            color: #6d87b0;
            font-size: 0.65rem;
            margin-top: 12px;
            padding: 0 6px;
            border-top: 1px solid rgba(181, 255, 239, 0.04);
            padding-top: 12px;
            flex-wrap: wrap;
            gap: 4px;
        }
        body[data-theme="light"] .footer-meta {
            color: #5a7a8a;
        }
        .footer-meta span:first-child {
            color: #b5ffefaa;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        body[data-theme="light"] .footer-meta span:first-child {
            color: #2a3a4aaa;
        }
        .footer-meta .stats {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .footer-meta .stats span {
            background: rgba(101, 167, 252, 0.06);
            padding: 0 12px;
            border-radius: 40px;
            border: 1px solid rgba(238, 255, 89, 0.06);
            color: #b5ffef;
            font-size: 0.6rem;
            line-height: 22px;
        }
        body[data-theme="light"] .footer-meta .stats span {
            background: rgba(0, 80, 160, 0.04);
            border-color: rgba(0, 80, 160, 0.06);
            color: #1a2a3a;
        }
        
        .copyright-footer {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid rgba(181, 255, 239, 0.06);
            text-align: center;
            color: #6d87b0;
            font-size: 0.65rem;
            letter-spacing: 0.3px;
        }
        body[data-theme="light"] .copyright-footer {
            border-top-color: rgba(0, 20, 40, 0.06);
            color: #5a7a8a;
        }
        .copyright-footer .highlight {
            color: #b5ffef;
            font-weight: 500;
        }
        body[data-theme="light"] .copyright-footer .highlight {
            color: #1a5a8a;
        }
        .copyright-footer .heart {
            color: #ff6b6b;
            display: inline-block;
            animation: heartPulse 1.5s ease-in-out infinite;
        }
        @keyframes heartPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.3); }
        }
        
        .toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(16, 26, 40, 0.92);
            backdrop-filter: blur(12px);
            padding: 10px 24px;
            border-radius: 60px;
            border: 1px solid #65a7fc55;
            color: #eeff59;
            font-weight: 500;
            font-size: 0.8rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.6);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 0;
            pointer-events: none;
            z-index: 999;
            letter-spacing: 0.3px;
        }
        body[data-theme="light"] .toast {
            background: rgba(240, 248, 255, 0.95);
            border-color: #3b7bc955;
            color: #1a5a8a;
        }
        .toast.show { 
            opacity: 1; 
            pointer-events: auto;
        }
        
        @media (max-width: 600px) {
            .player-card { padding: 14px 10px; }
            .title-group h1 { font-size: 1.3rem; }
            .main-grid { grid-template-columns: 1fr; gap: 12px; }
            .playlist-item { padding: 8px 12px; flex-wrap: wrap; gap: 4px; }
            .playlist-items { max-height: 180px; }
            .analytics-bar { gap: 8px; padding: 8px 12px; }
            .analytics-item { font-size: 0.65rem; min-width: 80px; }
            .analytics-item .value { font-size: 0.8rem; padding: 2px 10px; line-height: 24px; }
            .orb { display: none; }
        }
    </style>
</head>
<body data-theme="dark">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>

    <div class="player-card">
        <!-- header -->
        <div class="header-flex">
            <div class="title-group">
                <h1>
                    ⚡ SHS Live TV
                    <span class="badge-m3u">M3U</span>
                </h1>
                <div class="sub-tag">
                    <span>✦</span> <span id="streamCountLabel"><?php echo count($streams); ?></span> streams <span>·</span> Live Sports
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <button class="theme-toggle" id="themeToggle">🌓 Theme</button>
            </div>
        </div>

        <!-- Analytics Bar -->
        <div class="analytics-bar" id="analyticsBar">
            <div class="analytics-item">
                <span class="label">👁 Total Views</span>
                <span class="value" id="totalViews"><?php echo number_format($analytics['total_views'] ?? 0); ?></span>
            </div>
            <div class="analytics-item">
                <span class="label">👤 Unique Viewers</span>
                <span class="value" id="totalViewers"><?php echo number_format($analytics['total_viewers'] ?? 0); ?></span>
            </div>
            <div class="analytics-item">
                <span class="label">📊 Traffic</span>
                <span class="value" id="totalTraffic"><?php echo number_format($analytics['total_traffic_mb'] ?? 0, 1); ?> MB</span>
            </div>
            <div class="analytics-item">
                <span class="label">🟢 Active Now</span>
                <span class="value active-value" id="activeViewers"><?php echo $analytics['active_viewers'] ?? 0; ?></span>
            </div>
        </div>

        <!-- Main Grid: Video + Playlist -->
        <div class="main-grid">
            <!-- Video -->
            <div class="video-wrapper">
                <video id="videoPlayer" controls autoplay playsinline preload="metadata">
                    Your browser does not support HTML5 video.
                </video>
            </div>

            <!-- Playlist Panel -->
            <div class="playlist-panel">
                <div class="playlist-header">
                    <span class="left">📋 PLAYLIST</span>
                    <span class="count-badge" id="streamCount"><?php echo count($streams); ?> streams</span>
                </div>

                <div class="extra-controls">
                    <div class="search-box">
                        <span>🔍</span>
                        <input type="text" id="searchInput" placeholder="Filter streams..." />
                    </div>
                    <button class="btn-sm" id="shuffleBtn">🔀 Shuffle</button>
                    <button class="btn-sm" id="sortBtn">📋 Sort</button>
                    <button class="btn-sm" id="refreshBtn">🔄 Refresh</button>
                </div>

                <div class="playlist-items" id="playlistContainer">
                    <!-- dynamic -->
                </div>

                <div class="btn-group">
                    <button class="btn" id="prevBtn">⏮ Prev</button>
                    <button class="btn" id="nextBtn">⏭ Next</button>
                    <button class="btn btn-primary" id="reloadBtn">⟳ Reload</button>
                </div>

                <div class="footer-meta">
                    <span>⏵ <span id="currentTimeDisplay">0:00</span></span>
                    <div class="stats">
                        <span id="footerStreamCount">⚡ <?php echo count($streams); ?></span>
                        <span id="playStatusDisplay">● playing</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Copyright Footer -->
        <div class="copyright-footer">
            <span>Copyright &copy; <?php echo COPYRIGHT_START; ?>-<?php echo $currentYear; ?> </span>
            <span class="highlight"><?php echo SITE_NAME; ?></span>
            <span>All Right Reserved.</span>
            <span style="margin-left: 8px; opacity: 0.5;">|</span>
            <span style="opacity: 0.6;">Made with <span class="heart">❤</span> for live streaming</span>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast">
        <span id="toastMessage">✔️ Stream loaded</span>
    </div>

    <script>
        (function() {
            // ----- PHP data -----
            const BASE_PLAYLIST = <?php echo json_encode(array_map(function($s) {
                return ['name' => $s['name'], 'url' => $s['url']];
            }, $streams)); ?>;
            const CURRENT_STREAM_ID = <?php echo $currentStreamId; ?>;
            const INITIAL_ANALYTICS = <?php echo json_encode($analytics); ?>;

            // ----- state -----
            let playlist = [...BASE_PLAYLIST];
            let currentIndex = 0;
            let filteredIndices = [];
            let searchQuery = '';

            // DOM
            const video = document.getElementById('videoPlayer');
            const container = document.getElementById('playlistContainer');
            const searchInput = document.getElementById('searchInput');
            const shuffleBtn = document.getElementById('shuffleBtn');
            const sortBtn = document.getElementById('sortBtn');
            const refreshBtn = document.getElementById('refreshBtn');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const reloadBtn = document.getElementById('reloadBtn');
            const streamCountSpan = document.getElementById('streamCount');
            const footerStreamCount = document.getElementById('footerStreamCount');
            const streamCountLabel = document.getElementById('streamCountLabel');
            const currentTimeDisplay = document.getElementById('currentTimeDisplay');
            const playStatusDisplay = document.getElementById('playStatusDisplay');
            const themeToggle = document.getElementById('themeToggle');
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            
            // Analytics DOM
            const totalViews = document.getElementById('totalViews');
            const totalViewers = document.getElementById('totalViewers');
            const totalTraffic = document.getElementById('totalTraffic');
            const activeViewers = document.getElementById('activeViewers');

            // ----- toast -----
            function showToast(msg, duration = 3000) {
                toastMessage.textContent = msg;
                toast.classList.add('show');
                clearTimeout(toast._timer);
                toast._timer = setTimeout(() => {
                    toast.classList.remove('show');
                }, duration);
            }

            // ----- fetch analytics -----
            function fetchAnalytics() {
                fetch('?api=1&action=stats')
                    .then(res => res.json())
                    .then(data => {
                        if (data) {
                            totalViews.textContent = formatNumber(data.total_views || 0);
                            totalViewers.textContent = formatNumber(data.total_viewers || 0);
                            totalTraffic.textContent = (data.total_traffic_mb || 0).toFixed(1) + ' MB';
                            activeViewers.textContent = data.active_viewers || 0;
                        }
                    })
                    .catch(err => console.warn('Analytics fetch error:', err));
            }

            function formatNumber(num) {
                if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
                if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
                return num.toString();
            }

            // ----- track view -----
            function trackView(streamId, streamName) {
                fetch(`?api=1&action=track&stream_id=${streamId}&name=${encodeURIComponent(streamName)}`)
                    .then(() => {
                        setTimeout(fetchAnalytics, 500);
                    })
                    .catch(err => console.warn('Track error:', err));
            }

            // ----- render -----
            function renderPlaylist(activeIdx) {
                const query = searchQuery.trim().toLowerCase();
                let filtered = playlist.map((item, idx) => ({ ...item, originalIndex: idx }));

                if (query) {
                    filtered = filtered.filter(item => item.name.toLowerCase().includes(query));
                }

                filteredIndices = filtered.map(item => item.originalIndex);
                container.innerHTML = '';

                if (filtered.length === 0) {
                    container.innerHTML =
                        `<div style="color:#6d87b0;padding:24px;text-align:center;font-size:0.9rem;">🔎 No streams match "<strong>${query}</strong>"</div>`;
                    streamCountSpan.textContent = '0 filtered';
                    footerStreamCount.textContent = '⚡ 0';
                    streamCountLabel.textContent = '0';
                    return;
                }

                filtered.forEach((item) => {
                    const idx = item.originalIndex;
                    const div = document.createElement('div');
                    div.className = 'playlist-item' + (idx === activeIdx ? ' active' : '');
                    div.dataset.index = idx;

                    const badge = document.createElement('span');
                    badge.className = 'index-badge';
                    badge.textContent = idx + 1;
                    div.appendChild(badge);

                    const nameSpan = document.createElement('span');
                    nameSpan.className = 'stream-name';
                    nameSpan.textContent = item.name;
                    div.appendChild(nameSpan);

                    const status = document.createElement('span');
                    status.className = 'status-badge';
                    status.textContent = (idx === activeIdx) ? '▶ PLAYING' : 'ready';
                    div.appendChild(status);

                    div.addEventListener('click', function() {
                        const index = parseInt(this.dataset.index, 10);
                        if (!isNaN(index)) {
                            if (index !== currentIndex) {
                                playStreamByIndex(index);
                            } else {
                                reloadCurrentStream();
                            }
                        }
                    });

                    container.appendChild(div);
                });

                const total = playlist.length;
                const shown = filtered.length;
                streamCountSpan.textContent = shown + ' / ' + total;
                footerStreamCount.textContent = '⚡ ' + shown + ' / ' + total;
                streamCountLabel.textContent = total;
            }

            // ----- play -----
            function playStreamByIndex(index) {
                if (index < 0 || index >= playlist.length) {
                    showToast('⚠️ Stream not found', 2000);
                    return;
                }
                const stream = playlist[index];
                if (!stream || !stream.url) {
                    showToast('❌ Invalid stream URL', 2000);
                    return;
                }

                video.src = stream.url;
                video.load();
                video.play().catch(() => {
                    showToast('▶️ Click play to start', 2000);
                });

                currentIndex = index;
                renderPlaylist(currentIndex);
                updatePlayStatus();
                showToast(`▶️ Now playing: ${stream.name}`, 1800);
                
                // Track view for analytics
                trackView(index, stream.name);
            }

            function reloadCurrentStream() {
                if (currentIndex < 0 || currentIndex >= playlist.length) {
                    playStreamByIndex(0);
                    return;
                }
                const stream = playlist[currentIndex];
                if (stream && stream.url) {
                    video.src = stream.url;
                    video.load();
                    video.play().catch(() => {});
                    renderPlaylist(currentIndex);
                    showToast(`⟳ Reloaded: ${stream.name}`, 1500);
                    trackView(currentIndex, stream.name);
                } else {
                    playStreamByIndex(0);
                }
            }

            function playPrev() {
                if (filteredIndices.length === 0) return;
                const currentPos = filteredIndices.indexOf(currentIndex);
                if (currentPos <= 0) {
                    playStreamByIndex(filteredIndices[filteredIndices.length - 1]);
                } else {
                    playStreamByIndex(filteredIndices[currentPos - 1]);
                }
            }

            function playNext() {
                if (filteredIndices.length === 0) return;
                const currentPos = filteredIndices.indexOf(currentIndex);
                if (currentPos === -1 || currentPos >= filteredIndices.length - 1) {
                    playStreamByIndex(filteredIndices[0]);
                } else {
                    playStreamByIndex(filteredIndices[currentPos + 1]);
                }
            }

            function shufflePlaylist() {
                if (playlist.length < 2) return;
                for (let i = playlist.length - 1; i > 0; i--) {
                    const j = Math.floor(Math.random() * (i + 1));
                    [playlist[i], playlist[j]] = [playlist[j], playlist[i]];
                }
                const currentName = playlist[currentIndex]?.name;
                currentIndex = 0;
                if (currentName) {
                    const found = playlist.findIndex(item => item.name === currentName);
                    if (found !== -1) currentIndex = found;
                }
                renderPlaylist(currentIndex);
                showToast('🔀 Playlist shuffled!', 1500);
            }

            function sortPlaylist() {
                playlist.sort((a, b) => a.name.localeCompare(b.name));
                const currentName = playlist[currentIndex]?.name;
                if (currentName) {
                    const found = playlist.findIndex(item => item.name === currentName);
                    if (found !== -1) currentIndex = found;
                } else {
                    currentIndex = 0;
                }
                renderPlaylist(currentIndex);
                showToast('📋 Sorted A-Z', 1500);
            }

            function refreshPlaylist() {
                showToast('🔄 Refreshing playlist...', 1000);
                fetch('?api=1&action=playlist')
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.length > 0) {
                            playlist = data.map(s => ({ name: s.name, url: s.url }));
                            currentIndex = 0;
                            renderPlaylist(0);
                            playStreamByIndex(0);
                            showToast('✅ Playlist refreshed!', 1500);
                        } else {
                            showToast('⚠️ Could not refresh playlist', 2000);
                        }
                    })
                    .catch(() => {
                        showToast('⚠️ Could not refresh playlist', 2000);
                    });
            }

            function updatePlayStatus() {
                playStatusDisplay.textContent = video.paused ? '⏸ paused' : '● playing';
            }

            function updateTimeDisplay() {
                if (video.duration && !isNaN(video.duration)) {
                    const mins = Math.floor(video.currentTime / 60);
                    const secs = Math.floor(video.currentTime % 60);
                    currentTimeDisplay.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
                } else {
                    currentTimeDisplay.textContent = '0:00';
                }
            }

            // ----- theme -----
            function toggleTheme() {
                const body = document.body;
                const current = body.getAttribute('data-theme');
                const next = (current === 'dark') ? 'light' : 'dark';
                body.setAttribute('data-theme', next);
                showToast(`🌓 ${next} theme`, 1200);
            }
            themeToggle.addEventListener('click', toggleTheme);

            // ----- event listeners -----
            video.addEventListener('play', updatePlayStatus);
            video.addEventListener('pause', updatePlayStatus);
            video.addEventListener('timeupdate', updateTimeDisplay);
            video.addEventListener('ended', () => {
                playStatusDisplay.textContent = '⏹ ended';
            });

            searchInput.addEventListener('input', function() {
                searchQuery = this.value;
                renderPlaylist(currentIndex);
            });

            shuffleBtn.addEventListener('click', shufflePlaylist);
            sortBtn.addEventListener('click', sortPlaylist);
            refreshBtn.addEventListener('click', refreshPlaylist);
            prevBtn.addEventListener('click', playPrev);
            nextBtn.addEventListener('click', playNext);
            reloadBtn.addEventListener('click', reloadCurrentStream);

            document.addEventListener('keydown', (e) => {
                if (e.target.tagName === 'INPUT') return;
                if (e.key === 'ArrowRight') { e.preventDefault(); playNext(); }
                if (e.key === 'ArrowLeft') { e.preventDefault(); playPrev(); }
                if (e.key === 'r' || e.key === 'R') { e.preventDefault(); reloadCurrentStream(); }
            });

            // ----- init analytics polling -----
            function startAnalyticsPolling() {
                // Update analytics every 10 seconds
                setInterval(fetchAnalytics, 10000);
            }

            // ----- init -----
            function init() {
                if (playlist.length === 0) {
                    container.innerHTML = '<div style="color:#6d87b0;padding:24px;text-align:center;">No streams</div>';
                    return;
                }

                currentIndex = CURRENT_STREAM_ID;
                if (currentIndex >= playlist.length) currentIndex = 0;
                
                video.src = playlist[currentIndex].url;
                video.load();
                video.play().catch(() => {});
                renderPlaylist(currentIndex);
                updatePlayStatus();
                
                // Track initial view
                if (playlist[currentIndex]) {
                    trackView(currentIndex, playlist[currentIndex].name);
                }
                
                // Start analytics polling
                startAnalyticsPolling();
                
                showToast('🎬 Player ready · Analytics active', 2500);
            }

            init();

            // Expose player controls
            window.__player = {
                playlist: () => playlist,
                currentIndex: () => currentIndex,
                play: playStreamByIndex,
                reload: reloadCurrentStream,
                shuffle: shufflePlaylist,
                sort: sortPlaylist,
                next: playNext,
                prev: playPrev,
                refresh: refreshPlaylist,
                analytics: fetchAnalytics
            };
        })();
    </script>
</body>
</html>
