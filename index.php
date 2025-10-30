<?php
// Render.com specific setup
if (getenv('RENDER')) {
    // Render pe hum environment variables use karenge
    $_ENV = getenv();
}

// Enable error reporting based on environment
require_once 'config.php';

$environment = Config::get('ENVIRONMENT', 'production');
if ($environment === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// -------------------- SECURE CONFIG --------------------
define('BOT_TOKEN', Config::get('BOT_TOKEN', '7928919721:AAEM-62e16367cP9HPMFCpqhSc00f3YjDkQ'));
define('CHANNEL_ID', Config::get('CHANNEL_ID', '-1003181705395'));
define('GROUP_CHANNEL_ID', Config::get('GROUP_CHANNEL_ID', '-1003083386043'));
define('BACKUP_CHANNEL_ID', Config::get('BACKUP_CHANNEL_ID', '-1002964109368'));
define('BOT_ID', Config::get('BOT_ID', '7928919721'));
define('BOT_USERNAME', Config::get('BOT_USERNAME', '@TadkaMovieBot'));
define('OWNER_ID', Config::get('OWNER_ID', '1080317415'));
define('APP_API_ID', Config::get('APP_API_ID', '21944581'));
define('APP_API_HASH', Config::get('APP_API_HASH', '7b1c174a5cd3466e25a976c39a791737'));
define('MAINTENANCE_MODE', Config::get('MAINTENANCE_MODE', 'false') === 'true');

define('CSV_FILE', Config::get('CSV_FILE', 'movies.csv'));
define('USERS_FILE', Config::get('USERS_FILE', 'users.json'));
define('STATS_FILE', Config::get('STATS_FILE', 'bot_stats.json'));
define('BACKUP_DIR', Config::get('BACKUP_DIR', 'backups/'));
define('POPULAR_SEARCHES_FILE', Config::get('POPULAR_SEARCHES_FILE', 'popular_searches.json'));
define('CACHE_EXPIRY', (int)Config::get('CACHE_EXPIRY', 300));
define('ITEMS_PER_PAGE', (int)Config::get('ITEMS_PER_PAGE', 5));
// -------------------------------------------------------

// ==============================
// FILE UPLOAD BOT CONFIGURATION
// ==============================
define('MAX_FILE_SIZE', 2 * 1024 * 1024 * 1024);
define('FOUR_GB', 4 * 1024 * 1024 * 1024);
define('CHUNK_SIZE', 64 * 1024);
define('DEFAULT_WATERMARK', "@EntertainmentTadka786");
define('HARDCODED_THUMBNAIL', "thumb.png");
define('METADATA_FILE', "metadata.json");
define('RETRY_COUNT', 3);
define('VIDEO_WIDTH', 1280);
define('VIDEO_HEIGHT', 720);

// ==============================
// GOOGLE DRIVE CONFIG
// ==============================
define('GOOGLE_DRIVE_CLIENT_ID', Config::get('GOOGLE_DRIVE_CLIENT_ID', ''));
define('GOOGLE_DRIVE_CLIENT_SECRET', Config::get('GOOGLE_DRIVE_CLIENT_SECRET', ''));
define('GOOGLE_DRIVE_REFRESH_TOKEN', Config::get('GOOGLE_DRIVE_REFRESH_TOKEN', ''));
define('GOOGLE_DRIVE_FOLDER_ID', Config::get('GOOGLE_DRIVE_FOLDER_ID', ''));

// ==============================
// TEMPORARY MAINTENANCE MODE
// ==============================
if (MAINTENANCE_MODE) {
    $update = json_decode(file_get_contents('php://input'), true);
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $maintenance_msg = "🛠️ <b>Bot Under Maintenance</b>\n\n";
        $maintenance_msg .= "We're temporarily unavailable for updates.\n";
        $maintenance_msg .= "Will be back in few days!\n\n";
        $maintenance_msg .= "Thanks for patience 🙏";
        sendMessage($chat_id, $maintenance_msg, null, 'HTML');
    }
    exit;
}

// File initialization
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0, 'message_logs' => []]));
    @chmod(USERS_FILE, 0600);
}

if (!file_exists(CSV_FILE)) {
    file_put_contents(CSV_FILE, "movie_name,message_id,date\n");
    @chmod(CSV_FILE, 0600);
}

if (!file_exists(STATS_FILE)) {
    file_put_contents(STATS_FILE, json_encode([
        'total_movies' => 0, 
        'total_users' => 0, 
        'total_searches' => 0, 
        'last_updated' => date('Y-m-d H:i:s')
    ]));
    @chmod(STATS_FILE, 0600);
}

if (!file_exists(POPULAR_SEARCHES_FILE)) {
    file_put_contents(POPULAR_SEARCHES_FILE, json_encode([]));
    @chmod(POPULAR_SEARCHES_FILE, 0600);
}

if (!file_exists(BACKUP_DIR)) {
    @mkdir(BACKUP_DIR, 0755, true);
}

// memory caches
$movie_messages = array();
$movie_cache = array();
$waiting_users = array();
$popular_searches_cache = array();

// File Upload Bot State
$file_bot_state = [
    'metadata' => [],
    'thumb_mode' => 'preview',
    'thumb_opacity' => 70,
    'thumb_textsize' => 18,
    'thumb_position' => 'top-right',
    'split' => false,
    'new_name' => null,
    'custom_thumb' => null
];

$file_queue = [];
$queue_processing = false;

// ==============================
// GOOGLE DRIVE SERVICE
// ==============================
class GoogleDriveService {
    private $access_token;
    
    public function __construct() {
        $this->access_token = $this->getAccessToken();
    }
    
    private function getAccessToken() {
        if (empty(GOOGLE_DRIVE_CLIENT_ID) || empty(GOOGLE_DRIVE_CLIENT_SECRET) || empty(GOOGLE_DRIVE_REFRESH_TOKEN)) {
            throw new Exception("Google Drive configuration missing");
        }
        
        $url = 'https://oauth2.googleapis.com/token';
        $data = [
            'client_id' => GOOGLE_DRIVE_CLIENT_ID,
            'client_secret' => GOOGLE_DRIVE_CLIENT_SECRET,
            'refresh_token' => GOOGLE_DRIVE_REFRESH_TOKEN,
            'grant_type' => 'refresh_token'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }
    
    public function downloadFile($file_id, $destination_path) {
        $url = "https://www.googleapis.com/drive/v3/files/{$file_id}?alt=media";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->access_token}",
                "User-Agent: Telegram-Bot"
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10
        ]);
        
        $file_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 && $file_content) {
            file_put_contents($destination_path, $file_content);
            return true;
        }
        
        return false;
    }
    
    public function getFileInfo($file_id) {
        $url = "https://www.googleapis.com/drive/v3/files/{$file_id}?fields=name,size,mimeType,modifiedTime";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->access_token}"
            ]
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true);
    }
}

// ==============================
// ENHANCED SEARCH SYSTEM
// ==============================
function update_popular_searches($query) {
    global $popular_searches_cache;
    
    $popular_searches = json_decode(file_get_contents(POPULAR_SEARCHES_FILE), true);
    
    if (!isset($popular_searches[$query])) {
        $popular_searches[$query] = 0;
    }
    $popular_searches[$query]++;
    
    // Keep only top 50 searches
    arsort($popular_searches);
    $popular_searches = array_slice($popular_searches, 0, 50, true);
    
    file_put_contents(POPULAR_SEARCHES_FILE, json_encode($popular_searches, JSON_PRETTY_PRINT));
    $popular_searches_cache = $popular_searches;
}

function get_popular_searches($limit = 10) {
    global $popular_searches_cache;
    
    if (empty($popular_searches_cache)) {
        if (file_exists(POPULAR_SEARCHES_FILE)) {
            $popular_searches_cache = json_decode(file_get_contents(POPULAR_SEARCHES_FILE), true);
        } else {
            $popular_searches_cache = [];
        }
    }
    
    return array_slice($popular_searches_cache, 0, $limit, true);
}

function get_search_suggestions($query) {
    $common_mistakes = [
        'avng' => 'avengers',
        'kgf' => 'kgf chapter',
        'puspa' => 'pushpa',
        'animl' => 'animal',
        'spiderman' => 'spider-man'
    ];
    
    $suggestions = [];
    $query_lower = strtolower($query);
    
    // Check common spelling mistakes
    foreach ($common_mistakes as $wrong => $correct) {
        if (similar_text($query_lower, $wrong) > 70) {
            $suggestions[] = $correct;
        }
    }
    
    // Find similar movie names from database
    $all_movies = get_all_movies_list();
    foreach ($all_movies as $movie) {
        $movie_name = strtolower($movie['movie_name']);
        similar_text($query_lower, $movie_name, $similarity);
        if ($similarity > 60 && $similarity < 90) {
            $suggestions[] = $movie_name;
        }
        if (count($suggestions) >= 3) break;
    }
    
    return array_slice(array_unique($suggestions), 0, 3);
}

function show_categorized_results($chat_id, $query, $results) {
    $exact_matches = [];
    $partial_matches = [];
    $similar_matches = [];
    
    foreach ($results as $movie => $data) {
        if ($data['score'] >= 90) {
            $exact_matches[$movie] = $data;
        } elseif ($data['score'] >= 70) {
            $partial_matches[$movie] = $data;
        } else {
            $similar_matches[$movie] = $data;
        }
    }
    
    $msg = "🔍 Search Results for \"$query\"\n\n";
    
    if (!empty($exact_matches)) {
        $msg .= "🎯 EXACT MATCHES (" . count($exact_matches) . "):\n";
        foreach (array_slice($exact_matches, 0, 5) as $movie => $data) {
            $msg .= "• $movie (" . $data['count'] . " entries)\n";
        }
        $msg .= "\n";
    }
    
    if (!empty($partial_matches)) {
        $msg .= "📋 PARTIAL MATCHES (" . count($partial_matches) . "):\n";
        foreach (array_slice($partial_matches, 0, 5) as $movie => $data) {
            $msg .= "• $movie (" . $data['count'] . " entries)\n";
        }
        $msg .= "\n";
    }
    
    if (!empty($similar_matches)) {
        $msg .= "💡 SIMILAR MOVIES (" . count($similar_matches) . "):\n";
        foreach (array_slice($similar_matches, 0, 3) as $movie => $data) {
            $msg .= "• $movie (" . $data['count'] . " entries)\n";
        }
    }
    
    sendMessage($chat_id, $msg);
}

function update_search_history($user_id, $query) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    
    if (!isset($users_data['users'][$user_id]['search_history'])) {
        $users_data['users'][$user_id]['search_history'] = [];
    }
    
    // Add new search to history (max 20 entries)
    array_unshift($users_data['users'][$user_id]['search_history'], [
        'query' => $query,
        'timestamp' => time(),
        'results_count' => 0
    ]);
    
    // Keep only last 20 searches
    $users_data['users'][$user_id]['search_history'] = array_slice(
        $users_data['users'][$user_id]['search_history'], 0, 20
    );
    
    file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
}

function show_search_history($chat_id, $user_id) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $history = $users_data['users'][$user_id]['search_history'] ?? [];
    
    if (empty($history)) {
        sendMessage($chat_id, "📝 You haven't searched for any movies yet!");
        return;
    }
    
    $msg = "📋 Your Search History:\n\n";
    $i = 1;
    foreach (array_slice($history, 0, 10) as $search) {
        $time_ago = time() - $search['timestamp'];
        $hours_ago = floor($time_ago / 3600);
        
        if ($hours_ago < 1) {
            $time_text = "Just now";
        } elseif ($hours_ago < 24) {
            $time_text = $hours_ago . " hours ago";
        } else {
            $time_text = floor($hours_ago / 24) . " days ago";
        }
        
        $msg .= "$i. \"{$search['query']}\" - $time_text\n";
        $i++;
    }
    
    if (count($history) > 10) {
        $msg .= "\n... and " . (count($history) - 10) . " more searches";
    }
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🗑️ Clear History', 'callback_data' => 'clear_history'],
                ['text' => '📊 Popular Searches', 'callback_data' => 'show_popular']
            ]
        ]
    ];
    
    sendMessage($chat_id, $msg, $keyboard);
}

function show_popular_searches($chat_id) {
    $popular_searches = get_popular_searches(10);
    
    if (empty($popular_searches)) {
        sendMessage($chat_id, "📊 No popular searches yet!");
        return;
    }
    
    $msg = "🔥 Popular Searches:\n\n";
    $i = 1;
    foreach ($popular_searches as $query => $count) {
        $msg .= "$i. \"$query\" - $count searches\n";
        $i++;
    }
    
    sendMessage($chat_id, $msg);
}

function handle_search_filters($chat_id, $command, $user_id) {
    $parts = explode(' ', $command);
    $filters = [];
    
    foreach ($parts as $part) {
        if (strpos($part, '=') !== false) {
            list($key, $value) = explode('=', $part, 2);
            $filters[strtolower(trim($key))] = strtolower(trim($value));
        }
    }
    
    if (empty($filters)) {
        sendMessage($chat_id, 
            "🔍 Search Filters Usage:\n\n" .
            "• `/search quality=720p` - 720p movies\n" .
            "• `/search year=2024` - 2024 movies\n" . 
            "• `/search language=hindi` - Hindi movies\n" .
            "• `/search genre=action` - Action movies\n\n" .
            "💡 Combine filters: `/search quality=1080p year=2024`"
        );
        return;
    }
    
    // Apply filters to search
    $all_movies = get_all_movies_list();
    $filtered_movies = [];
    
    foreach ($all_movies as $movie) {
        $movie_lower = strtolower($movie['movie_name']);
        $match = true;
        
        foreach ($filters as $filter_key => $filter_value) {
            switch ($filter_key) {
                case 'quality':
                    $qualities = ['480p', '720p', '1080p', '4k', 'hdrip', 'webrip', 'bluray'];
                    $quality_match = false;
                    foreach ($qualities as $quality) {
                        if ($filter_value == $quality && strpos($movie_lower, $quality) !== false) {
                            $quality_match = true;
                            break;
                        }
                    }
                    if (!$quality_match) $match = false;
                    break;
                    
                case 'year':
                    if (strpos($movie_lower, $filter_value) === false) $match = false;
                    break;
                    
                case 'language':
                    $languages = ['hindi', 'english', 'tamil', 'telugu', 'malayalam'];
                    $lang_match = false;
                    foreach ($languages as $lang) {
                        if ($filter_value == $lang && strpos($movie_lower, $lang) !== false) {
                            $lang_match = true;
                            break;
                        }
                    }
                    if (!$lang_match) $match = false;
                    break;
                    
                case 'genre':
                    $genre_map = [
                        'action' => ['action', 'fight', 'war', 'adventure'],
                        'comedy' => ['comedy', 'funny', 'humor', 'laugh'],
                        'drama' => ['drama', 'emotional', 'story'],
                        'horror' => ['horror', 'scary', 'ghost', 'thriller'],
                        'romance' => ['romance', 'love', 'romantic']
                    ];
                    $genre_match = false;
                    if (isset($genre_map[$filter_value])) {
                        foreach ($genre_map[$filter_value] as $genre_term) {
                            if (strpos($movie_lower, $genre_term) !== false) {
                                $genre_match = true;
                                break;
                            }
                        }
                    }
                    if (!$genre_match) $match = false;
                    break;
            }
        }
        
        if ($match) $filtered_movies[] = $movie;
    }
    
    // Show filtered results
    if (!empty($filtered_movies)) {
        $msg = "🔍 Filtered Search Results:\n\n";
        $msg .= "📋 Filters: " . implode(', ', array_keys($filters)) . "\n";
        $msg .= "🎬 Found: " . count($filtered_movies) . " movies\n\n";
        
        foreach (array_slice($filtered_movies, 0, 10) as $movie) {
            $msg .= "• " . $movie['movie_name'] . "\n";
        }
        
        if (count($filtered_movies) > 10) {
            $msg .= "\n• ... and " . (count($filtered_movies) - 10) . " more";
        }
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🎬 Send All Results', 'callback_data' => 'send_filtered:' . base64_encode(json_encode($filters))],
                    ['text' => '🔄 New Search', 'callback_data' => 'new_search']
                ]
            ]
        ];
        
        sendMessage($chat_id, $msg, $keyboard);
    } else {
        sendMessage($chat_id, "❌ No movies found with the specified filters.");
    }
}

// ==============================
// FILE UPLOAD BOT FUNCTIONS
// ==============================
function human_readable_size($size, $suffix = "B") {
    $units = ["", "K", "M", "G", "T"];
    foreach ($units as $u) {
        if ($size < 1024) {
            return sprintf("%.2f%s%s", $size, $u, $suffix);
        }
        $size /= 1024;
    }
    return sprintf("%.2fP%s", $size, $suffix);
}

function is_video_file($filename) {
    $video_ext = ['.mp4', '.mkv', '.avi', '.mov', '.wmv', '.flv', '.webm', '.m4v', '.3gp', '.ogg', '.mpeg', '.mpg', '.ts', '.vob', '.m4v'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array('.' . $ext, $video_ext);
}

function calc_checksum($file_path) {
    $md5 = md5_file($file_path);
    $sha1 = sha1_file($file_path);
    return [$md5, $sha1];
}

function split_file($source_path, $dest_dir, $part_size = null) {
    if ($part_size === null) {
        $part_size = MAX_FILE_SIZE;
    }
    
    $parts = [];
    $total = filesize($source_path);
    $num_parts = ceil($total / $part_size);
    
    $source_handle = fopen($source_path, "rb");
    if (!$source_handle) {
        throw new Exception("Cannot open source file: $source_path");
    }
    
    for ($idx = 0; $idx < $num_parts; $idx++) {
        $part_name = $dest_dir . "/" . basename($source_path) . ".part" . sprintf("%03d", $idx + 1);
        $parts[] = $part_name;
        
        $part_handle = fopen($part_name, "wb");
        if (!$part_handle) {
            fclose($source_handle);
            throw new Exception("Cannot create part file: $part_name");
        }
        
        $remaining = $part_size;
        while ($remaining > 0) {
            $chunk_size = min(CHUNK_SIZE, $remaining);
            $chunk = fread($source_handle, $chunk_size);
            if ($chunk === false || strlen($chunk) === 0) {
                break;
            }
            fwrite($part_handle, $chunk);
            $remaining -= strlen($chunk);
        }
        fclose($part_handle);
    }
    
    fclose($source_handle);
    return $parts;
}

function resize_video($input_path, $output_path) {
    try {
        if (!copy($input_path, $output_path)) {
            throw new Exception("Copy failed");
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function create_watermark_thumb($video_path, $tmp_dir, $opacity = 70, $text_size = 18, $position = "top-right") {
    try {
        $thumb_path = $tmp_dir . "/" . pathinfo($video_path, PATHINFO_FILENAME) . "_thumb.jpg";
        
        $cmd = [
            "ffmpeg", "-y",
            "-i", $video_path,
            "-ss", "00:00:05",
            "-vframes", "1",
            "-q:v", "1",
            "-vf", "scale=430:241:flags=lanczos",
            $thumb_path
        ];
        
        $result = shell_exec(implode(" ", $cmd) . " 2>&1");
        
        if (!file_exists($thumb_path)) {
            return null;
        }
        
        $img = imagecreatefromjpeg($thumb_path);
        if (!$img) {
            return null;
        }
        
        $current_width = imagesx($img);
        $current_height = imagesy($img);
        
        if ($current_width != VIDEO_WIDTH || $current_height != VIDEO_HEIGHT) {
            $resized = imagecreatetruecolor(VIDEO_WIDTH, VIDEO_HEIGHT);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, VIDEO_WIDTH, VIDEO_HEIGHT, $current_width, $current_height);
            imagedestroy($img);
            $img = $resized;
        }
        
        $txt = imagecreatetruecolor(VIDEO_WIDTH, VIDEO_HEIGHT);
        imagesavealpha($txt, true);
        $transparent = imagecolorallocatealpha($txt, 0, 0, 0, 127);
        imagefill($txt, 0, 0, $transparent);
        
        $font_paths = [
            "arialbd.ttf", "arial.ttf", 
            "C:/Windows/Fonts/arialbd.ttf",
            "C:/Windows/Fonts/arial.ttf",
            "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf",
            "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
        ];
        
        $font = null;
        foreach ($font_paths as $font_path) {
            if (file_exists($font_path)) {
                $font = $font_path;
                break;
            }
        }
        
        $bbox = imagettfbbox($text_size, 0, $font, DEFAULT_WATERMARK);
        $tw = $bbox[2] - $bbox[0];
        $th = $bbox[3] - $bbox[1];
        $margin = 10;
        
        if ($position == "top-left") {
            $x = $margin;
            $y = $margin + $th;
        } elseif ($position == "top-right") {
            $x = VIDEO_WIDTH - $tw - $margin;
            $y = $margin + $th;
        } elseif ($position == "bottom-left") {
            $x = $margin;
            $y = VIDEO_HEIGHT - $margin;
        } else {
            $x = VIDEO_WIDTH - $tw - $margin;
            $y = VIDEO_HEIGHT - $margin;
        }
        
        $shadow_color = imagecolorallocatealpha($txt, 0, 0, 0, (int)(127 * $opacity / 100));
        $text_color = imagecolorallocatealpha($txt, 255, 255, 255, (int)(127 * $opacity / 100));
        
        imagettftext($txt, $text_size, 0, $x+1, $y+1, $shadow_color, $font, DEFAULT_WATERMARK);
        imagettftext($txt, $text_size, 0, $x, $y, $text_color, $font, DEFAULT_WATERMARK);
        
        imagecopy($img, $txt, 0, 0, 0, 0, VIDEO_WIDTH, VIDEO_HEIGHT);
        
        imagejpeg($img, $thumb_path, 95);
        
        imagedestroy($img);
        imagedestroy($txt);
        
        return $thumb_path;
        
    } catch (Exception $e) {
        return null;
    }
}

function download_telegram_file($file_id, $destination) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/getFile?file_id=$file_id";
    $response = json_decode(file_get_contents($url), true);
    
    if (!$response || !$response['ok']) {
        throw new Exception("Cannot get file path");
    }
    
    $file_path = $response['result']['file_path'];
    $download_url = "https://api.telegram.org/file/bot{$BOT_TOKEN}/$file_path";
    
    $file_content = file_get_contents($download_url);
    if ($file_content === false) {
        throw new Exception("Cannot download file");
    }
    
    file_put_contents($destination, $file_content);
}

function send_telegram_video($video_path, $caption, $thumb_path = null, $duration = 0, $chat_id = null) {
    if ($chat_id === null) {
        $chat_id = OWNER_ID;
    }
    
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendVideo";
    
    $post_data = [
        'chat_id' => $chat_id,
        'caption' => $caption,
        'duration' => $duration,
        'width' => VIDEO_WIDTH,
        'height' => VIDEO_HEIGHT,
        'supports_streaming' => true,
        'video' => new CURLFile(realpath($video_path))
    ];
    
    if ($thumb_path && file_exists($thumb_path)) {
        $post_data['thumb'] = new CURLFile(realpath($thumb_path));
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

function send_telegram_document($document_path, $caption, $thumb_path = null, $chat_id = null) {
    if ($chat_id === null) {
        $chat_id = OWNER_ID;
    }
    
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument";
    
    $post_data = [
        'chat_id' => $chat_id,
        'caption' => $caption,
        'document' => new CURLFile(realpath($document_path))
    ];
    
    if ($thumb_path && file_exists($thumb_path)) {
        $post_data['thumb'] = new CURLFile(realpath($thumb_path));
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

function send_telegram_photo($photo_path, $caption, $chat_id = null) {
    if ($chat_id === null) {
        $chat_id = OWNER_ID;
    }
    
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendPhoto";
    
    $post_data = [
        'chat_id' => $chat_id,
        'caption' => $caption,
        'photo' => new CURLFile(realpath($photo_path))
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

// ==============================
// Stats
// ==============================
function update_stats($field, $increment = 1) {
    if (!file_exists(STATS_FILE)) return;
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    if (!file_exists(STATS_FILE)) return [];
    return json_decode(file_get_contents(STATS_FILE), true);
}

// ==============================
// Caching / CSV loading
// ==============================
function load_and_clean_csv($filename = CSV_FILE) {
    global $movie_messages;
    if (!file_exists($filename)) {
        file_put_contents($filename, "movie_name,message_id,date\n");
        return [];
    }

    $data = [];
    $handle = fopen($filename, "r");
    if ($handle !== FALSE) {
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3 && (!empty(trim($row[0])))) {
                $movie_name = trim($row[0]);
                $message_id_raw = isset($row[1]) ? trim($row[1]) : '';
                $date = isset($row[2]) ? trim($row[2]) : '';
                $video_path = isset($row[3]) ? trim($row[3]) : '';

                $entry = [
                    'movie_name' => $movie_name,
                    'message_id_raw' => $message_id_raw,
                    'date' => $date,
                    'video_path' => $video_path
                ];
                if (is_numeric($message_id_raw)) {
                    $entry['message_id'] = intval($message_id_raw);
                } else {
                    $entry['message_id'] = null;
                }

                $data[] = $entry;

                $movie = strtolower($movie_name);
                if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
                $movie_messages[$movie][] = $entry;
            }
        }
        fclose($handle);
    }

    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats['total_movies'] = count($data);
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));

    $handle = fopen($filename, "w");
    fputcsv($handle, array('movie_name','message_id','date','video_path'));
    foreach ($data as $row) {
        fputcsv($handle, [$row['movie_name'], $row['message_id_raw'], $row['date'], $row['video_path']]);
    }
    fclose($handle);

    return $data;
}

function get_cached_movies() {
    global $movie_cache;
    if (!empty($movie_cache) && (time() - $movie_cache['timestamp']) < CACHE_EXPIRY) {
        return $movie_cache['data'];
    }
    $movie_cache = [
        'data' => load_and_clean_csv(),
        'timestamp' => time()
    ];
    return $movie_cache['data'];
}

function load_movies_from_csv() {
    return get_cached_movies();
}

// ==============================
// Telegram API helpers
// ==============================
function apiRequest($method, $params = array(), $is_multipart = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    if ($is_multipart) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        if ($res === false) {
            error_log("CURL ERROR: " . curl_error($ch));
        }
        curl_close($ch);
    } else {
        $options = array(
            'http' => array(
                'method' => 'POST',
                'content' => http_build_query($params),
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
            )
        );
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            error_log("apiRequest failed for method $method");
        }
        return $result;
    }
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    $result = apiRequest('sendMessage', $data);
    return json_decode($result, true);
}

function copyMessage($chat_id, $from_chat_id, $message_id) {
    return apiRequest('copyMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    $result = apiRequest('forwardMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
    return $result;
}

function answerCallbackQuery($callback_query_id, $text = null) {
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    apiRequest('answerCallbackQuery', $data);
}

function editMessage($chat_id, $message_obj, $new_text, $reply_markup = null) {
    if (is_array($message_obj) && isset($message_obj['message_id'])) {
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_obj['message_id'],
            'text' => $new_text
        ];
        if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
        apiRequest('editMessageText', $data);
    }
}

// ==============================
// DELIVERY LOGIC - FIXED (CHANNEL NAME & VIEWS WILL SHOW)
// ==============================
function deliver_item_to_chat($chat_id, $item) {
    if (!empty($item['message_id']) && is_numeric($item['message_id'])) {
        $result = json_decode(forwardMessage($chat_id, CHANNEL_ID, $item['message_id']), true);
        
        if ($result && $result['ok']) {
            return true;
        } else {
            copyMessage($chat_id, CHANNEL_ID, $item['message_id']);
            return true;
        }
    }

    $text = "🎬 " . ($item['movie_name'] ?? 'Unknown') . "\n";
    $text .= "Ref: " . ($item['message_id_raw'] ?? 'N/A') . "\n";
    $text .= "Date: " . ($item['date'] ?? 'N/A') . "\n";
    sendMessage($chat_id, $text, null, 'HTML');
    return false;
}

// ==============================
// Pagination helpers
// ==============================
function get_all_movies_list() {
    $all = get_cached_movies();
    return $all;
}

function paginate_movies(array $all, int $page): array {
    $total = count($all);
    if ($total === 0) {
        return [
            'total' => 0,
            'total_pages' => 1, 
            'page' => 1,
            'slice' => []
        ];
    }
    
    $total_pages = (int)ceil($total / ITEMS_PER_PAGE);
    $page = max(1, min($page, $total_pages));
    $start = ($page - 1) * ITEMS_PER_PAGE;
    
    return [
        'total' => $total,
        'total_pages' => $total_pages,
        'page' => $page,
        'slice' => array_slice($all, $start, ITEMS_PER_PAGE)
    ];
}

function forward_page_movies($chat_id, array $page_movies) {
    $total = count($page_movies);
    if ($total === 0) return;
    
    $progress_msg = sendMessage($chat_id, "⏳ Forwarding {$total} movies...");
    
    $i = 1;
    $success_count = 0;
    
    foreach ($page_movies as $m) {
        $success = deliver_item_to_chat($chat_id, $m);
        if ($success) $success_count++;
        
        if ($i % 3 === 0) {
            editMessage($chat_id, $progress_msg, "⏳ Forwarding... ({$i}/{$total})");
        }
        
        usleep(500000);
        $i++;
    }
    
    editMessage($chat_id, $progress_msg, "✅ Successfully forwarded {$success_count}/{$total} movies");
}

function build_totalupload_keyboard(int $page, int $total_pages): array {
    $kb = ['inline_keyboard' => []];
    
    $nav_row = [];
    if ($page > 1) {
        $nav_row[] = ['text' => '⬅️ Previous', 'callback_data' => 'tu_prev_' . ($page - 1)];
    }
    
    $nav_row[] = ['text' => "📄 $page/$total_pages", 'callback_data' => 'current_page'];
    
    if ($page < $total_pages) {
        $nav_row[] = ['text' => 'Next ➡️', 'callback_data' => 'tu_next_' . ($page + 1)];
    }
    
    if (!empty($nav_row)) {
        $kb['inline_keyboard'][] = $nav_row;
    }
    
    $action_row = [];
    $action_row[] = ['text' => '🎬 Send This Page', 'callback_data' => 'tu_view_' . $page];
    $action_row[] = ['text' => '🛑 Stop', 'callback_data' => 'tu_stop'];
    
    $kb['inline_keyboard'][] = $action_row;
    
    if ($total_pages > 5) {
        $jump_row = [];
        if ($page > 1) {
            $jump_row[] = ['text' => '⏮️ First', 'callback_data' => 'tu_prev_1'];
        }
        if ($page < $total_pages) {
            $jump_row[] = ['text' => 'Last ⏭️', 'callback_data' => 'tu_next_' . $total_pages];
        }
        if (!empty($jump_row)) {
            $kb['inline_keyboard'][] = $jump_row;
        }
    }
    
    return $kb;
}

// ==============================
// /totalupload controller - IMPROVED
// ==============================
function totalupload_controller($chat_id, $page = 1) {
    $all = get_all_movies_list();
    if (empty($all)) {
        sendMessage($chat_id, "📭 Koi movies nahi mili! Pehle kuch movies add karo.");
        return;
    }
    
    $pg = paginate_movies($all, (int)$page);
    
    forward_page_movies($chat_id, $pg['slice']);
    
    $title = "🎬 <b>Total Uploads</b>\n\n";
    $title .= "📊 <b>Statistics:</b>\n";
    $title .= "• Total Movies: <b>{$pg['total']}</b>\n";
    $title .= "• Current Page: <b>{$pg['page']}/{$pg['total_pages']}</b>\n";
    $title .= "• Showing: <b>" . count($pg['slice']) . " movies</b>\n\n";
    
    $title .= "📋 <b>Current Page Movies:</b>\n";
    $i = 1;
    foreach ($pg['slice'] as $movie) {
        $movie_name = htmlspecialchars($movie['movie_name'] ?? 'Unknown');
        $title .= "$i. {$movie_name}\n";
        $i++;
    }
    
    $title .= "\n📍 Use buttons to navigate or resend current page";
    
    $kb = build_totalupload_keyboard($pg['page'], $pg['total_pages']);
    sendMessage($chat_id, $title, $kb, 'HTML');
}

// ==============================
// Append movie
// ==============================
function append_movie($movie_name, $message_id_raw, $date = null, $video_path = '') {
    if (empty(trim($movie_name))) return;
    if ($date === null) $date = date('d-m-Y');
    $entry = [$movie_name, $message_id_raw, $date, $video_path];
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, $entry);
    fclose($handle);

    global $movie_messages, $movie_cache, $waiting_users;
    $movie = strtolower(trim($movie_name));
    $item = [
        'movie_name' => $movie_name,
        'message_id_raw' => $message_id_raw,
        'date' => $date,
        'video_path' => $video_path,
        'message_id' => is_numeric($message_id_raw) ? intval($message_id_raw) : null
    ];
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    $movie_messages[$movie][] = $item;
    $movie_cache = [];

    foreach ($waiting_users as $query => $users) {
        if (strpos($movie, $query) !== false) {
            foreach ($users as $user_data) {
                list($user_chat_id, $user_id) = $user_data;
                deliver_item_to_chat($user_chat_id, $item);
                sendMessage($user_chat_id, "✅ '$query' ab channel me add ho gaya!\n\n📢 Join: @EntertainmentTadka786\n💬 Help: @EntertainmentTadka7860");
            }
            unset($waiting_users[$query]);
        }
    }

    update_stats('total_movies', 1);
}

// ==============================
// Search & language & points
// ==============================
function smart_search($query) {
    global $movie_messages;
    $query_lower = strtolower(trim($query));
    $results = array();
    foreach ($movie_messages as $movie => $entries) {
        $score = 0;
        if ($movie == $query_lower) $score = 100;
        elseif (strpos($movie, $query_lower) !== false) $score = 80 - (strlen($movie) - strlen($query_lower));
        else {
            similar_text($movie, $query_lower, $similarity);
            if ($similarity > 60) $score = $similarity;
        }
        if ($score > 0) $results[$movie] = ['score'=>$score,'count'=>count($entries)];
    }
    uasort($results, function($a,$b){return $b['score'] - $a['score'];});
    return array_slice($results,0,10);
}

function detect_language($text) {
    $hindi_keywords = ['फिल्म','मूवी','डाउनलोड','हिंदी'];
    $english_keywords = ['movie','download','watch','print'];
    $h=0;$e=0;
    foreach ($hindi_keywords as $k) if (strpos($text,$k)!==false) $h++;
    foreach ($english_keywords as $k) if (stripos($text,$k)!==false) $e++;
    return $h>$e ? 'hindi' : 'english';
}

function send_multilingual_response($chat_id, $message_type, $language) {
    $responses = [
        'hindi'=>[
            'welcome' => "🎬 Boss, kis movie ki talash hai?",
            'found' => "✅ Mil gayi! Movie forward ho rahi hai...",
            'not_found' => "😔 Yeh movie abhi available nahi hai!\n\n📝 Aap ise request kar sakte hain: @EntertainmentTadka7860\n💾 Backups check karo: @ETBackup\n\n🔔 Jab bhi yeh add hogi, main automatically bhej dunga!",
            'searching' => "🔍 Dhoondh raha hoon... Zara wait karo"
        ],
        'english'=>[
            'welcome' => "🎬 Boss, which movie are you looking for?",
            'found' => "✅ Found it! Forwarding the movie...",
            'not_found' => "😔 This movie isn't available yet!\n\n📝 You can request it here: @EntertainmentTadka7860\n💾 Check backups: @ETBackup\n\n🔔 I'll send it automatically once it's added!",
            'searching' => "🔍 Searching... Please wait"
        ]
    ];
    sendMessage($chat_id, $responses[$language][$message_type]);
}

function update_user_points($user_id, $action) {
    $points_map = ['search'=>1,'found_movie'=>5,'daily_login'=>10];
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    if (!isset($users_data['users'][$user_id]['points'])) $users_data['users'][$user_id]['points'] = 0;
    $users_data['users'][$user_id]['points'] += ($points_map[$action] ?? 0);
    $users_data['users'][$user_id]['last_activity'] = date('Y-m-d H:i:s');
    file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
}

// ==============================
// GOOGLE DRIVE DOWNLOAD HANDLER
// ==============================
function is_google_drive_url($url) {
    $patterns = [
        'drive.google.com/file/d/',
        'drive.google.com/open?id=',
        'docs.google.com/uc?id=',
        'drive.google.com/uc?id='
    ];
    
    foreach ($patterns as $pattern) {
        if (strpos($url, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

function extract_google_drive_id($url) {
    if (preg_match('/\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return $matches[1];
    }
    
    if (preg_match('/[&?]id=([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return $matches[1];
    }
    
    if (preg_match('/uc\?id=([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return $matches[1];
    }
    
    return null;
}

function handle_google_drive_download($chat_id, $gdrive_url, $user_id = null) {
    try {
        if (!is_google_drive_url($gdrive_url)) {
            sendMessage($chat_id, "❌ Invalid Google Drive URL!\n\n💡 Example: https://drive.google.com/file/d/1ABC123xyz/view");
            return;
        }
        
        $file_id = extract_google_drive_id($gdrive_url);
        if (!$file_id) {
            sendMessage($chat_id, "❌ Could not extract File ID from URL");
            return;
        }
        
        sendMessage($chat_id, "🔍 Checking Google Drive file...");
        
        $drive_service = new GoogleDriveService();
        $file_info = $drive_service->getFileInfo($file_id);
        
        if (!isset($file_info['name'])) {
            sendMessage($chat_id, "❌ File not found or access denied!\n\n⚠️ Make sure the file is publicly accessible");
            return;
        }
        
        $file_name = $file_info['name'];
        $file_size = $file_info['size'] ?? 0;
        
        $file_details = "📁 <b>File Details:</b>\n\n";
        $file_details .= "📄 Name: <code>{$file_name}</code>\n";
        $file_details .= "📦 Size: " . human_readable_size($file_size) . "\n";
        $file_details .= "🔗 Type: " . ($file_info['mimeType'] ?? 'Unknown') . "\n\n";
        $file_details .= "⏳ Downloading...";
        
        $progress_msg = sendMessage($chat_id, $file_details, null, 'HTML');
        
        $temp_dir = sys_get_temp_dir() . '/gdrive_downloads_' . uniqid();
        mkdir($temp_dir, 0755, true);
        $download_path = $temp_dir . '/' . $file_name;
        
        $download_success = $drive_service->downloadFile($file_id, $download_path);
        
        if (!$download_success || !file_exists($download_path)) {
            editMessage($chat_id, $progress_msg, "❌ Download failed!\n\n⚠️ File might be too large or restricted");
            rrmdir($temp_dir);
            return;
        }
        
        $final_size = filesize($download_path);
        editMessage($chat_id, $progress_msg, 
            "✅ Download Complete!\n\n" .
            "📄 File: <code>{$file_name}</code>\n" .
            "📦 Size: " . human_readable_size($final_size) . "\n\n" .
            "🔄 Processing for Telegram..."
        );
        
        handle_downloaded_file($chat_id, $download_path, $file_name);
        
        rrmdir($temp_dir);
        
    } catch (Exception $e) {
        sendMessage($chat_id, "❌ Error: " . $e->getMessage());
    }
}

function handle_downloaded_file($chat_id, $file_path, $original_name) {
    $file_size = filesize($file_path);
    $max_telegram_size = 2 * 1024 * 1024 * 1024;
    
    if ($file_size > $max_telegram_size) {
        $options = [
            'inline_keyboard' => [
                [
                    ['text' => '🔪 Split into Parts', 'callback_data' => 'split_gdrive:' . base64_encode($file_path)],
                    ['text' => '🎥 Compress Video', 'callback_data' => 'compress_gdrive:' . base64_encode($file_path)]
                ],
                [
                    ['text' => '❌ Cancel', 'callback_data' => 'cancel_gdrive']
                ]
            ]
        ];
        
        sendMessage($chat_id,
            "📦 <b>Large File Downloaded</b>\n\n" .
            "📄 File: <code>{$original_name}</code>\n" .
            "📦 Size: " . human_readable_size($file_size) . "\n\n" .
            "⚠️ File is too large for Telegram\n" .
            "Choose handling method:",
            $options, 'HTML'
        );
        return;
    }
    
    if (is_video_file($file_path)) {
        process_and_upload_video($chat_id, $file_path, $original_name);
    } else {
        upload_telegram_document($chat_id, $file_path, $original_name);
    }
}

function process_and_upload_video($chat_id, $video_path, $original_name) {
    $temp_dir = sys_get_temp_dir() . '/video_process_' . uniqid();
    mkdir($temp_dir, 0755, true);
    
    $thumb_path = $temp_dir . '/thumb.jpg';
    
    try {
        sendMessage($chat_id, "🎥 Processing video...");
        
        create_watermark_thumb($video_path, $temp_dir);
        
        $ffprobe_cmd = "ffprobe -v quiet -print_format json -show_format -show_streams \"$video_path\"";
        $ffprobe_output = shell_exec($ffprobe_cmd);
        $video_info = json_decode($ffprobe_output, true);
        $duration = 0;
        
        if ($video_info && isset($video_info['streams'])) {
            foreach ($video_info['streams'] as $stream) {
                if ($stream['codec_type'] == 'video') {
                    $duration = (int)($video_info['format']['duration'] ?? 0);
                    break;
                }
            }
        }
        
        $caption = "📁 <b>{$original_name}</b>\n";
        $caption .= "📦 Size: " . human_readable_size(filesize($video_path)) . "\n";
        $caption .= "⏱️ Duration: {$duration}s\n\n";
        $caption .= "🎬 Via @TadkaMovieBot";
        
        send_telegram_video($chat_id, $video_path, $caption, $thumb_path, $duration);
        
        sendMessage($chat_id, "✅ Video uploaded successfully!");
        
    } catch (Exception $e) {
        sendMessage($chat_id, "❌ Video processing failed: " . $e->getMessage());
    } finally {
        rrmdir($temp_dir);
    }
}

function upload_telegram_document($chat_id, $file_path, $caption = null) {
    if (!$caption) {
        $caption = basename($file_path);
    }
    
    $caption = "📁 <b>{$caption}</b>\n";
    $caption .= "📦 Size: " . human_readable_size(filesize($file_path)) . "\n\n";
    $caption .= "📎 Via @TadkaMovieBot";
    
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument";
    
    $post_data = [
        'chat_id' => $chat_id,
        'document' => new CURLFile($file_path),
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300
    ]);
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}

function handle_gdrive_split_upload($chat_id, $file_path_encoded) {
    $file_path = base64_decode($file_path_encoded);
    
    if (!file_exists($file_path)) {
        sendMessage($chat_id, "❌ File not found for splitting!");
        return;
    }
    
    sendMessage($chat_id, "🔪 Splitting large file into parts...");
    
    try {
        $temp_dir = sys_get_temp_dir() . '/split_files_' . uniqid();
        mkdir($temp_dir, 0755, true);
        
        $parts = split_file($file_path, $temp_dir, 1.9 * 1024 * 1024 * 1024);
        
        sendMessage($chat_id, "📦 Split into " . count($parts) . " parts. Uploading...");
        
        $total_parts = count($parts);
        $uploaded = 0;
        
        foreach ($parts as $index => $part_path) {
            $part_num = $index + 1;
            $caption = "📦 Part {$part_num}/{$total_parts} of " . basename($file_path);
            
            upload_telegram_document($chat_id, $part_path, $caption);
            unlink($part_path);
            
            $uploaded++;
            
            if ($uploaded % 2 === 0) {
                sendMessage($chat_id, "⏳ Uploaded {$uploaded}/{$total_parts} parts...");
            }
        }
        
        sendMessage($chat_id, "✅ All parts uploaded successfully!");
        rrmdir($temp_dir);
        unlink($file_path);
        
    } catch (Exception $e) {
        sendMessage($chat_id, "❌ Error splitting file: " . $e->getMessage());
    }
}

function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    rrmdir($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        rmdir($dir);
    }
}

// ==============================
// ENHANCED ADVANCED SEARCH
// ==============================
function enhanced_advanced_search($chat_id, $query, $user_id = null) {
    update_search_history($user_id, $query);
    update_popular_searches($query);
    
    $suggestions = get_search_suggestions($query);
    $results = smart_search($query);
    
    if (!empty($results)) {
        show_categorized_results($chat_id, $query, $results);
        
        $keyboard = ['inline_keyboard' => []];
        foreach (array_slice(array_keys($results), 0, 5) as $movie) {
            $keyboard['inline_keyboard'][] = [[ 'text' => "🎬 " . ucwords($movie), 'callback_data' => $movie ]];
        }
        sendMessage($chat_id, "🚀 Top Matches:", $keyboard);
        
        if ($user_id) update_user_points($user_id, 'found_movie');
    } else {
        if (!empty($suggestions)) {
            $suggestion_msg = "😔 No exact matches found!\n\n";
            $suggestion_msg .= "💡 Did you mean:\n";
            foreach ($suggestions as $suggestion) {
                $suggestion_msg .= "• `$suggestion`\n";
            }
            sendMessage($chat_id, $suggestion_msg);
        } else {
            $lang = detect_language($query);
            send_multilingual_response($chat_id, 'not_found', $lang);
            
            global $waiting_users;
            $q = strtolower(trim($query));
            if (!isset($waiting_users[$q])) $waiting_users[$q] = [];
            $waiting_users[$q][] = [$chat_id, $user_id ?? $chat_id];
        }
    }
    
    update_stats('total_searches', 1);
    if ($user_id) update_user_points($user_id, 'search');
}

// ==============================
// FILE UPLOAD BOT COMMAND HANDLERS
// ==============================
function handle_file_upload_commands($message) {
    global $file_bot_state;
    
    $user_id = $message['from']['id'];
    $text = $message['text'] ?? '';
    
    if ($user_id != OWNER_ID) {
        sendMessage($user_id, "❌ Access denied. You are not authorized to use file upload features.");
        return;
    }
    
    $command = explode(' ', $text)[0];
    
    switch ($command) {
        case '/setname':
            handle_set_name($message);
            break;
            
        case '/clearname':
            handle_clear_name($message);
            break;
            
        case '/metadata':
            handle_metadata($message);
            break;
            
        case '/setthumb':
            handle_set_thumbnail($message);
            break;
            
        case '/view_thumb':
            handle_view_thumbnail($message);
            break;
            
        case '/del_thumb':
            handle_delete_thumbnail($message);
            break;
            
        default:
            if (isset($message['document']) || isset($message['video']) || isset($message['audio'])) {
                handle_file_upload($message);
            }
            break;
    }
}

function handle_set_name($message) {
    global $file_bot_state;
    
    $args = explode(' ', $message['text'], 2);
    if (count($args) < 2) {
        sendMessage($message['chat']['id'], "❌ Usage: `/setname <filename.ext>`", null, 'HTML');
        return;
    }
    
    $file_bot_state["new_name"] = trim($args[1]);
    sendMessage($message['chat']['id'], "✅ Name set: `{$args[1]}`", null, 'HTML');
}

function handle_clear_name($message) {
    global $file_bot_state;
    
    $file_bot_state["new_name"] = null;
    sendMessage($message['chat']['id'], "✅ Name cleared.");
}

function handle_metadata($message) {
    global $file_bot_state;
    
    $args = explode(' ', $message['text'], 2);
    if (count($args) < 2) {
        sendMessage($message['chat']['id'], "❌ Usage: `/metadata key=value`\n\nExample: `/metadata title=Movie quality=1080p year=2024`", null, 'HTML');
        return;
    }
    
    if (!isset($file_bot_state["metadata"])) {
        $file_bot_state["metadata"] = [];
    }
    
    $pairs = explode(' ', $args[1]);
    $changes = [];
    
    foreach ($pairs as $pair) {
        if (strpos($pair, '=') !== false) {
            list($k, $v) = explode('=', $pair, 2);
            $k = trim(strtolower($k));
            $v = trim($v);
            $file_bot_state["metadata"][$k] = $v;
            $changes[] = "• `$k` = `$v`";
        }
    }
    
    if ($changes) {
        sendMessage($message['chat']['id'], "✅ Metadata Updated\n" . implode("\n", $changes), null, 'HTML');
    } else {
        sendMessage($message['chat']['id'], "❌ No valid key=value pairs found!", null, 'HTML');
    }
}

function handle_set_thumbnail($message) {
    global $file_bot_state;
    
    try {
        $thumb_path = "custom_thumb.jpg";
        
        if (isset($message['photo'])) {
            $photo = end($message['photo']);
            $file_id = $photo['file_id'];
            download_telegram_file($file_id, $thumb_path);
        } elseif (isset($message['document']) && strpos($message['document']['mime_type'], 'image/') === 0) {
            $file_id = $message['document']['file_id'];
            download_telegram_file($file_id, $thumb_path);
        } else {
            sendMessage($message['chat']['id'], "❌ Send a photo or image file with `/setthumb`", null, 'HTML');
            return;
        }
        
        $img = imagecreatefromstring(file_get_contents($thumb_path));
        if (!$img) {
            throw new Exception("Cannot process image");
        }
        
        $target_width = VIDEO_WIDTH;
        $target_height = VIDEO_HEIGHT;
        
        $orig_width = imagesx($img);
        $orig_height = imagesy($img);
        $img_ratio = $orig_width / $orig_height;
        $target_ratio = $target_width / $target_height;
        
        if ($img_ratio > $target_ratio) {
            $new_height = $target_height;
            $new_width = (int)($target_height * $img_ratio);
            $resized = imagecreatetruecolor($new_width, $new_height);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
            
            $left = (int)(($new_width - $target_width) / 2);
            $cropped = imagecreatetruecolor($target_width, $target_height);
            imagecopy($cropped, $resized, 0, 0, $left, 0, $target_width, $target_height);
            
            imagedestroy($resized);
            imagedestroy($img);
            $img = $cropped;
        } else {
            $new_width = $target_width;
            $new_height = (int)($target_width / $img_ratio);
            $resized = imagecreatetruecolor($new_width, $new_height);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
            
            $top = (int)(($new_height - $target_height) / 2);
            $cropped = imagecreatetruecolor($target_width, $target_height);
            imagecopy($cropped, $resized, 0, 0, 0, $top, $target_width, $target_height);
            
            imagedestroy($resized);
            imagedestroy($img);
            $img = $cropped;
        }
        
        imagejpeg($img, $thumb_path, 95);
        imagedestroy($img);
        
        $file_bot_state["custom_thumb"] = $thumb_path;
        
        $size = getimagesize($thumb_path);
        sendMessage(
            $message['chat']['id'],
            "✅ Custom thumbnail set!\nSize: {$size[0]}×{$size[1]}\nQuality: 95%\n\nVideo & Thumbnail dimensions: " . VIDEO_WIDTH . "x" . VIDEO_HEIGHT, 
            null, 'HTML'
        );
        
    } catch (Exception $e) {
        sendMessage($message['chat']['id'], "❌ Error setting thumbnail: `{$e->getMessage()}`", null, 'HTML');
    }
}

function handle_view_thumbnail($message) {
    global $file_bot_state;
    
    $custom_thumb_path = $file_bot_state["custom_thumb"] ?? null;
    
    if (!$custom_thumb_path || !file_exists($custom_thumb_path)) {
        sendMessage($message['chat']['id'], "❌ No custom thumbnail set!", null, 'HTML');
        return;
    }
    
    $size = filesize($custom_thumb_path);
    $img_info = getimagesize($custom_thumb_path);
    $dimensions = "{$img_info[0]}×{$img_info[1]}";
    
    send_telegram_photo(
        $custom_thumb_path,
        "📷 Custom Thumbnail\nSize: " . human_readable_size($size) . "\nDimensions: $dimensions\nQuality: 95%",
        $message['chat']['id']
    );
}

function handle_delete_thumbnail($message) {
    global $file_bot_state;
    
    $custom_thumb_path = $file_bot_state["custom_thumb"] ?? null;
    
    if (!$custom_thumb_path) {
        sendMessage($message['chat']['id'], "❌ No custom thumbnail to delete!", null, 'HTML');
        return;
    }
    
    try {
        if (file_exists($custom_thumb_path)) {
            unlink($custom_thumb_path);
        }
        $file_bot_state["custom_thumb"] = null;
        sendMessage($message['chat']['id'], "✅ Custom thumbnail deleted!");
    } catch (Exception $e) {
        sendMessage($message['chat']['id'], "❌ Error deleting thumbnail: `{$e->getMessage()}`", null, 'HTML');
    }
}

function handle_file_upload($message) {
    global $file_queue, $queue_processing;
    
    $file_queue[] = $message;
    process_upload_queue();
}

function process_upload_queue() {
    global $file_queue, $queue_processing;
    
    if ($queue_processing || empty($file_queue)) {
        return;
    }
    
    $queue_processing = true;
    
    while (!empty($file_queue)) {
        $message = array_shift($file_queue);
        process_single_file($message);
    }
    
    $queue_processing = false;
}

function process_single_file($message) {
    global $file_bot_state;
    
    $tmp_dir = sys_get_temp_dir() . "/rename_bot_" . uniqid();
    mkdir($tmp_dir, 0755, true);
    
    $new_name = $file_bot_state["new_name"] ?? null;
    $metadata = $file_bot_state["metadata"] ?? [];
    $custom_thumb_path = $file_bot_state["custom_thumb"] ?? null;
    
    try {
        if (isset($message['document'])) {
            $orig_name = $message['document']['file_name'] ?? 'file';
            $file_id = $message['document']['file_id'];
        } elseif (isset($message['video'])) {
            $orig_name = $message['video']['file_name'] ?? 'video.mp4';
            $file_id = $message['video']['file_id'];
        } elseif (isset($message['audio'])) {
            $orig_name = $message['audio']['file_name'] ?? 'audio';
            $file_id = $message['audio']['file_id'];
        } else {
            throw new Exception("Unsupported file type");
        }
        
        $download_path = $tmp_dir . "/" . $orig_name;
        sendMessage($message['chat']['id'], "📥 Downloading `$orig_name`", null, 'HTML');
        
        download_telegram_file($file_id, $download_path);
        
        if ($new_name) {
            $target_path = $tmp_dir . "/" . $new_name;
            rename($download_path, $target_path);
            $file_to_process = $target_path;
        } else {
            $file_to_process = $download_path;
        }

        $files_to_upload = [$file_to_process];
        $total_parts = count($files_to_upload);
        
        foreach ($files_to_upload as $idx => $p) {
            $part_num = $idx + 1;
            $thumb_path = null;
            $caption = "";
            $duration = 0;
            $is_video = is_video_file($p);
            $final_video_path = $p;
            
            if ($is_video) {
                $ffprobe_cmd = "ffprobe -v quiet -print_format json -show_format -show_streams \"$p\"";
                $ffprobe_output = shell_exec($ffprobe_cmd);
                $video_info = json_decode($ffprobe_output, true);
                
                if ($video_info && isset($video_info['streams'])) {
                    foreach ($video_info['streams'] as $stream) {
                        if ($stream['codec_type'] == 'video') {
                            $duration = (int)($video_info['format']['duration'] ?? 0);
                            break;
                        }
                    }
                }
                
                sendMessage($message['chat']['id'], "🔄 Processing video...", null, 'HTML');
                $resized_path = $tmp_dir . "/" . pathinfo($p, PATHINFO_FILENAME) . "_resized.mp4";
                
                if (resize_video($p, $resized_path)) {
                    $final_video_path = $resized_path;
                } else {
                    $final_video_path = $p;
                }
                
                $thumb_path = create_watermark_thumb(
                    $final_video_path, $tmp_dir,
                    $file_bot_state["thumb_opacity"],
                    $file_bot_state["thumb_textsize"], 
                    $file_bot_state["thumb_position"]
                );
                
                $caption = "**" . basename($p) . "**\n**Size:** " . human_readable_size(filesize($p)) . "\n**Duration:** {$duration}s\n**Dimensions:** " . VIDEO_WIDTH . "x" . VIDEO_HEIGHT;
                
            } else {
                $caption = "**" . basename($p) . "**\n**Size:** " . human_readable_size(filesize($p));
            }
            
            if ($metadata) {
                $caption .= "\n\n**Metadata:**";
                foreach ($metadata as $k => $v) {
                    $caption .= "\n• **" . ucfirst($k) . ":** `$v`";
                }
            }
            
            list($md5, $sha1) = calc_checksum($p);
            $caption .= "\n\n**Checksum:**\n**MD5:** `$md5`\n**SHA1:** `$sha1`";
            
            if ($total_parts > 1) {
                $caption .= "\n**Part:** $part_num/$total_parts";
            }

            for ($attempt = 1; $attempt <= RETRY_COUNT; $attempt++) {
                try {
                    sendMessage($message['chat']['id'], "📤 Uploading `" . basename($p) . "` ($part_num/$total_parts)\nAttempt $attempt", null, 'HTML');
                    
                    $final_thumb = null;
                    if ($custom_thumb_path && file_exists($custom_thumb_path)) {
                        $final_thumb = $custom_thumb_path;
                    } elseif ($thumb_path && file_exists($thumb_path)) {
                        $final_thumb = $thumb_path;
                    }
                    
                    if ($is_video) {
                        $result = send_telegram_video($final_video_path, $caption, $final_thumb, $duration);
                    } else {
                        $result = send_telegram_document($p, $caption, $final_thumb);
                    }
                    
                    $result_data = json_decode($result, true);
                    if ($result_data && $result_data['ok']) {
                        break;
                    } else {
                        throw new Exception("Upload failed: " . ($result_data['description'] ?? 'Unknown error'));
                    }
                    
                } catch (Exception $e) {
                    if ($attempt == RETRY_COUNT) {
                        sendMessage($message['chat']['id'], "❌ Upload Failed after " . RETRY_COUNT . " attempts:\n`{$e->getMessage()}`", null, 'HTML');
                    } else {
                        sendMessage($message['chat']['id'], "⚠️ Retrying ($attempt/" . RETRY_COUNT . ")", null, 'HTML');
                        sleep(2);
                    }
                }
            }

            try {
                if (file_exists($p)) {
                    unlink($p);
                }
                if (isset($resized_path) && file_exists($resized_path)) {
                    unlink($resized_path);
                }
                if ($thumb_path && file_exists($thumb_path)) {
                    unlink($thumb_path);
                }
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }

        sendMessage($message['chat']['id'], "✅ Processing Complete!\nAll files uploaded successfully.", null, 'HTML');
        
    } catch (Exception $e) {
        sendMessage($message['chat']['id'], "❌ Error\n`{$e->getMessage()}`", null, 'HTML');
    } finally {
        try {
            rrmdir($tmp_dir);
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }
}

// ==============================
// Other commands
// ==============================
function check_date($chat_id) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua."); return; }
    $date_counts = [];
    $h=fopen(CSV_FILE,'r'); if ($h!==FALSE) {
        fgetcsv($h);
        while (($r=fgetcsv($h))!==FALSE) if (count($r)>=3) { $d=$r[2]; if(!isset($date_counts[$d])) $date_counts[$d]=0; $date_counts[$d]++; }
        fclose($h);
    }
    krsort($date_counts);
    $msg = "📅 Movies Upload Record\n\n";
    $total_days=0; $total_movies=0;
    foreach ($date_counts as $date=>$count) { $msg .= "➡️ $date: $count movies\n"; $total_days++; $total_movies += $count; }
    $msg .= "\n📊 Summary:\n";
    $msg .= "• Total Days: $total_days\n• Total Movies: $total_movies\n• Average per day: " . round($total_movies / max(1,$total_days),2);
    sendMessage($chat_id,$msg,null,'HTML');
}

function test_csv($chat_id) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id,"⚠️ CSV file not found."); return; }
    $h = fopen(CSV_FILE,'r');
    if ($h!==FALSE) {
        fgetcsv($h);
        $i=1; $msg="";
        while (($r=fgetcsv($h))!==FALSE) {
            if (count($r)>=3) {
                $line = "$i. {$r[0]} | ID/Ref: {$r[1]} | Date: {$r[2]}\n";
                if (strlen($msg) + strlen($line) > 4000) { sendMessage($chat_id,$msg); $msg=""; }
                $msg .= $line; $i++;
            }
        }
        fclose($h);
        if (!empty($msg)) sendMessage($chat_id,$msg);
    }
}

function show_csv_data($chat_id, $show_all = false) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "❌ CSV file not found.");
        return;
    }
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle === FALSE) {
        sendMessage($chat_id, "❌ Error opening CSV file.");
        return;
    }
    
    fgetcsv($handle);
    
    $movies = [];
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3) {
            $movies[] = $row;
        }
    }
    fclose($handle);
    
    if (empty($movies)) {
        sendMessage($chat_id, "📊 CSV file is empty.");
        return;
    }
    
    $movies = array_reverse($movies);
    
    $limit = $show_all ? count($movies) : 10;
    $movies = array_slice($movies, 0, $limit);
    
    $message = "📊 CSV Movie Database\n\n";
    $message .= "📁 Total Movies: " . count($movies) . "\n";
    if (!$show_all) {
        $message .= "🔍 Showing latest 10 entries\n";
        $message .= "📋 Use '/checkcsv all' for full list\n\n";
    } else {
        $message .= "📋 Full database listing\n\n";
    }
    
    $i = 1;
    foreach ($movies as $movie) {
        $movie_name = $movie[0] ?? 'N/A';
        $message_id = $movie[1] ?? 'N/A';
        $date = $movie[2] ?? 'N/A';
        
        $message .= "$i. 🎬 " . htmlspecialchars($movie_name) . "\n";
        $message .= "   📝 ID: $message_id\n";
        $message .= "   📅 Date: $date\n\n";
        
        $i++;
        
        if (strlen($message) > 3000) {
            sendMessage($chat_id, $message, null, 'HTML');
            $message = "📊 Continuing...\n\n";
        }
    }
    
    $message .= "💾 File: " . CSV_FILE . "\n";
    $message .= "⏰ Last Updated: " . date('Y-m-d H:i:s', filemtime(CSV_FILE));
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// Group Message Filter
// ==============================
function is_valid_movie_query($text) {
    $text = strtolower(trim($text));
    
    if (strpos($text, '/') === 0) {
        return true;
    }
    
    if (strlen($text) < 3) {
        return false;
    }
    
    $invalid_phrases = [
        'good morning', 'good night', 'hello', 'hi ', 'hey ', 'thank you', 'thanks',
        'welcome', 'bye', 'see you', 'ok ', 'okay', 'yes', 'no', 'maybe',
        'how are you', 'whats up', 'anyone', 'someone', 'everyone',
        'problem', 'issue', 'help', 'question', 'doubt', 'query'
    ];
    
    foreach ($invalid_phrases as $phrase) {
        if (strpos($text, $phrase) !== false) {
            return false;
        }
    }
    
    $movie_patterns = [
        'movie', 'film', 'video', 'download', 'watch', 'hd', 'full', 'part',
        'series', 'episode', 'season', 'bollywood', 'hollywood'
    ];
    
    foreach ($movie_patterns as $pattern) {
        if (strpos($text, $pattern) !== false) {
            return true;
        }
    }
    
    if (preg_match('/^[a-zA-Z0-9\s\-\.\,]{3,}$/', $text)) {
        return true;
    }
    
    return false;
}

// ==============================
// Main update processing (webhook)
// ==============================
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    get_cached_movies();

    // Channel post handling
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $message_id = $message['message_id'];
        $chat_id = $message['chat']['id'];

        if ($chat_id == CHANNEL_ID) {
            $text = '';

            if (isset($message['caption'])) {
                $text = $message['caption'];
            }
            elseif (isset($message['text'])) {
                $text = $message['text'];
            }
            elseif (isset($message['document'])) {
                $text = $message['document']['file_name'];
            }
            else {
                $text = 'Uploaded Media - ' . date('d-m-Y H:i');
            }

            if (!empty(trim($text))) {
                append_movie($text, $message_id, date('d-m-Y'), '');
            }
        }
    }

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        $chat_type = $message['chat']['type'] ?? 'private';

        // GROUP MESSAGE FILTERING
        if ($chat_type !== 'private') {
            if (strpos($text, '/') === 0) {
                // Commands allow karo
            } else {
                if (!is_valid_movie_query($text)) {
                    return;
                }
            }
        }

        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        if (!isset($users_data['users'][$user_id])) {
            $users_data['users'][$user_id] = [
                'first_name' => $message['from']['first_name'] ?? '',
                'last_name' => $message['from']['last_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'joined' => date('Y-m-d H:i:s'),
                'last_active' => date('Y-m-d H:i:s'),
                'points' => 0
            ];
            $users_data['total_requests'] = ($users_data['total_requests'] ?? 0) + 1;
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
            update_stats('total_users', 1);
        }
        $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
        file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));

        if (strpos($text, '/') === 0) {
            $parts = explode(' ', $text);
            $command = $parts[0];
            
            // File upload commands
            if (in_array($command, ['/setname', '/clearname', '/metadata', '/setthumb', '/view_thumb', '/del_thumb'])) {
                handle_file_upload_commands($message);
            }
            // Original movie bot commands
            elseif ($command == '/checkdate') check_date($chat_id);
            elseif ($command == '/totalupload') totalupload_controller($chat_id, 1);
            elseif ($command == '/testcsv') test_csv($chat_id);
            elseif ($command == '/checkcsv') {
                $show_all = (isset($parts[1]) && strtolower($parts[1]) == 'all');
                show_csv_data($chat_id, $show_all);
            }
            elseif ($command == '/start') {
                $welcome = "🎬 Welcome to TadkaMovieBot!\n\n";
                $welcome .= "🤖 <b>Your Smart Movie Companion</b>\n\n";
                $welcome .= "🔍 <b>How to use:</b>\n";
                $welcome .= "• Simply type any movie name\n";
                $welcome .= "• Use English or Hindi\n";
                $welcome .= "• Partial names also work\n\n";
                $welcome .= "📥 <b>Google Drive Download:</b>\n";
                $welcome .= "• Paste Google Drive link\n";
                $welcome .= "• Or use /gdrive [url]\n\n";
                $welcome .= "🔧 <b>Advanced Features:</b>\n";
                $welcome .= "• /search filters - Quality, year, language\n";
                $welcome .= "• /history - Your search history\n";
                $welcome .= "• /status - Bot status\n\n";
                $welcome .= "📢 Join: @EntertainmentTadka786\n";
                $welcome .= "💬 Request/Help: @EntertainmentTadka7860\n";
                $welcome .= "💾 Backups: @ETBackup";
                sendMessage($chat_id, $welcome, null, 'HTML');
                update_user_points($user_id, 'daily_login');
            }
            elseif ($command == '/help') {
                $help = "🤖 TadkaMovieBot - Complete Help\n\n";
                $help .= "🔍 <b>Search Commands:</b>\n";
                $help .= "• Type movie name directly\n";
                $help .= "• /search filters - Advanced search\n";
                $help .= "• /history - Search history\n\n";
                $help .= "📥 <b>Download Commands:</b>\n";
                $help .= "• /gdrive [url] - Google Drive download\n";
                $help .= "• Paste Google Drive link directly\n\n";
                $help .= "📊 <b>Info Commands:</b>\n";
                $help .= "• /checkdate - Upload statistics\n";
                $help .= "• /totalupload - All movies\n";
                $help .= "• /testcsv - Test data\n";
                $help .= "• /checkcsv - CSV data\n";
                $help .= "• /status - Bot status\n\n";
                $help .= "📢 Join: @EntertainmentTadka786\n";
                $help .= "💬 Help: @EntertainmentTadka7860\n";
                $help .= "💾 Backups: @ETBackup";
                sendMessage($chat_id, $help, null, 'HTML');
            }
            elseif ($command == '/status') {
                $stats = get_stats();
                $users_data = json_decode(file_get_contents(USERS_FILE), true);
                $total_users = count($users_data['users'] ?? []);
                $popular_searches = get_popular_searches(5);
                
                $status_msg = "🤖 <b>TadkaMovieBot Status</b>\n\n";
                $status_msg .= "🟢 <b>System Online</b>\n";
                $status_msg .= "✅ All services running\n\n";
                $status_msg .= "📊 <b>Statistics:</b>\n";
                $status_msg .= "• Movies: " . ($stats['total_movies'] ?? 0) . "\n";
                $status_msg .= "• Users: " . $total_users . "\n";
                $status_msg .= "• Searches: " . ($stats['total_searches'] ?? 0) . "\n\n";
                
                if (!empty($popular_searches)) {
                    $status_msg .= "🔥 <b>Popular Searches:</b>\n";
                    $i = 1;
                    foreach ($popular_searches as $query => $count) {
                        $status_msg .= "$i. $query ($count)\n";
                        $i++;
                        if ($i > 3) break;
                    }
                    $status_msg .= "\n";
                }
                
                $status_msg .= "📢 Channel: @EntertainmentTadka786\n";
                $status_msg .= "💬 Help: @EntertainmentTadka7860\n";
                $status_msg .= "💾 Backup: @ETBackup";
                sendMessage($chat_id, $status_msg, null, 'HTML');
            }
            elseif ($command == '/gdrive' || $command == '/gdl') {
                $parts = explode(' ', $text, 2);
                if (count($parts) > 1 && is_google_drive_url($parts[1])) {
                    handle_google_drive_download($chat_id, $parts[1], $user_id);
                } else {
                    sendMessage($chat_id, 
                        "📥 <b>Google Drive Download</b>\n\n" .
                        "🔗 <b>Usage:</b> <code>/gdrive [google_drive_url]</code>\n\n" .
                        "💡 <b>Examples:</b>\n" .
                        "• <code>/gdrive https://drive.google.com/file/d/ABC123/view</code>\n" .
                        "• Just paste the Google Drive link\n\n" .
                        "⚠️ <b>Supported formats:</b>\n" .
                        "• drive.google.com/file/d/FILE_ID\n" .
                        "• drive.google.com/open?id=FILE_ID\n" .
                        "• docs.google.com/uc?id=FILE_ID",
                        null, 'HTML'
                    );
                }
            }
            elseif ($command == '/history') {
                show_search_history($chat_id, $user_id);
            }
            elseif (strpos($command, '/search') === 0) {
                handle_search_filters($chat_id, $command, $user_id);
            }
        } else if (!empty(trim($text))) {
            if (is_google_drive_url($text)) {
                handle_google_drive_download($chat_id, $text, $user_id);
            } else {
                $lang = detect_language($text);
                send_multilingual_response($chat_id, 'searching', $lang);
                enhanced_advanced_search($chat_id, $text, $user_id);
            }
        }
        
        // Handle file uploads
        if (isset($message['document']) || isset($message['video']) || isset($message['audio'])) {
            handle_file_upload($message);
        }
    }

    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $data = $query['data'];

        global $movie_messages;
        $movie_lower = strtolower($data);
        if (isset($movie_messages[$movie_lower])) {
            $entries = $movie_messages[$movie_lower];
            $cnt = 0;
            foreach ($entries as $entry) {
                deliver_item_to_chat($chat_id, $entry);
                usleep(200000);
                $cnt++;
            }
            sendMessage($chat_id, "✅ '$data' ke $cnt messages forward ho gaye!\n\n📢 Join: @EntertainmentTadka786\n💬 Help: @EntertainmentTadka7860");
            answerCallbackQuery($query['id'], "🎬 $cnt items sent!");
        }
        elseif (strpos($data, 'tu_prev_') === 0) {
            $page = (int)str_replace('tu_prev_','', $data);
            totalupload_controller($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_next_') === 0) {
            $page = (int)str_replace('tu_next_','', $data);
            totalupload_controller($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_view_') === 0) {
            $page = (int)str_replace('tu_view_','', $data);
            $all = get_all_movies_list();
            $pg = paginate_movies($all, $page);
            forward_page_movies($chat_id, $pg['slice']);
            answerCallbackQuery($query['id'], "Re-sent current page movies");
        }
        elseif ($data === 'tu_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totalupload to start again.");
            answerCallbackQuery($query['id'], "Stopped");
        }
        elseif ($data === 'current_page') {
            answerCallbackQuery($query['id'], "You're on this page");
        }
        elseif (strpos($data, 'split_gdrive:') === 0) {
            $file_path_encoded = str_replace('split_gdrive:', '', $data);
            handle_gdrive_split_upload($chat_id, $file_path_encoded);
            answerCallbackQuery($query['id'], "Splitting file...");
        }
        elseif (strpos($data, 'compress_gdrive:') === 0) {
            $file_path_encoded = str_replace('compress_gdrive:', '', $data);
            sendMessage($chat_id, "🎥 Video compression feature coming soon!");
            answerCallbackQuery($query['id'], "Compression in development");
        }
        elseif ($data === 'cancel_gdrive') {
            sendMessage($chat_id, "❌ Google Drive download cancelled.");
            answerCallbackQuery($query['id'], "Cancelled");
        }
        elseif ($data === 'clear_history') {
            $users_data = json_decode(file_get_contents(USERS_FILE), true);
            if (isset($users_data['users'][$query['from']['id']]['search_history'])) {
                $users_data['users'][$query['from']['id']]['search_history'] = [];
                file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
            }
            editMessage($chat_id, $message, "🗑️ Search history cleared!");
            answerCallbackQuery($query['id'], "History cleared");
        }
        elseif ($data === 'show_popular') {
            show_popular_searches($chat_id);
            answerCallbackQuery($query['id'], "Popular searches");
        }
        elseif (strpos($data, 'send_filtered:') === 0) {
            $filters_encoded = str_replace('send_filtered:', '', $data);
            $filters = json_decode(base64_decode($filters_encoded), true);
            
            $all_movies = get_all_movies_list();
            $filtered_movies = [];
            
            foreach ($all_movies as $movie) {
                $movie_lower = strtolower($movie['movie_name']);
                $match = true;
                
                foreach ($filters as $filter_key => $filter_value) {
                    // Simplified filter logic for sending
                    if (strpos($movie_lower, $filter_value) === false) {
                        $match = false;
                        break;
                    }
                }
                
                if ($match) $filtered_movies[] = $movie;
            }
            
            if (!empty($filtered_movies)) {
                sendMessage($chat_id, "📤 Sending " . count($filtered_movies) . " filtered movies...");
                foreach ($filtered_movies as $movie) {
                    deliver_item_to_chat($chat_id, $movie);
                    usleep(500000);
                }
                sendMessage($chat_id, "✅ All filtered movies sent!");
            }
            answerCallbackQuery($query['id'], "Sending filtered results");
        }
        elseif ($data === 'new_search') {
            sendMessage($chat_id, "🔍 Enter your new search query:");
            answerCallbackQuery($query['id'], "New search");
        }
        else {
            sendMessage($chat_id, "❌ Movie not found: " . $data);
            answerCallbackQuery($query['id'], "❌ Movie not available");
        }
    }

    if (date('H:i') == '00:00') {
        // Auto backup
        $backup_files = [CSV_FILE, USERS_FILE, STATS_FILE, POPULAR_SEARCHES_FILE];
        $backup_dir = BACKUP_DIR . date('Y-m-d');
        if (!file_exists($backup_dir)) mkdir($backup_dir, 0755, true);
        foreach ($backup_files as $f) if (file_exists($f)) copy($f, $backup_dir . '/' . basename($f) . '.bak');
        $old = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
        if (count($old) > 7) {
            usort($old, function($a,$b){return filemtime($a)-filemtime($b);});
            foreach (array_slice($old, 0, count($old)-7) as $d) {
                $files = glob($d . '/*'); foreach ($files as $ff) @unlink($ff); @rmdir($d);
            }
        }
    }
}

// Manual setup
if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $result = apiRequest('setWebhook', ['url' => $webhook_url]);
    echo "<h1>Webhook Setup - TadkaMovieBot</h1>";
    echo "<p>Result: " . htmlspecialchars($result) . "</p>";
    echo "<p>Webhook URL: " . htmlspecialchars($webhook_url) . "</p>";
    $bot_info = json_decode(apiRequest('getMe'), true);
    if ($bot_info && isset($bot_info['ok']) && $bot_info['ok']) {
        echo "<h2>Bot Info</h2>";
        echo "<p>Name: " . htmlspecialchars($bot_info['result']['first_name']) . "</p>";
        echo "<p>Username: @" . htmlspecialchars($bot_info['result']['username']) . "</p>";
        echo "<p>Bot ID: " . htmlspecialchars($bot_info['result']['id']) . "</p>";
        echo "<p>Channel: @EntertainmentTadka786</p>";
        echo "<p>Help Group: @EntertainmentTadka7860</p>";
        echo "<p>Backup Channel: @ETBackup</p>";
    }
    exit;
}

if (!isset($update) || !$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    echo "<h1>🎬 TadkaMovieBot - Complete Movie System</h1>";
    echo "<p><strong>Telegram Bot:</strong> @TadkaMovieBot</p>";
    echo "<p><strong>Telegram Channel:</strong> @EntertainmentTadka786</p>";
    echo "<p><strong>Help Group:</strong> @EntertainmentTadka7860</p>";
    echo "<p><strong>Backup Channel:</strong> @ETBackup</p>";
    echo "<p><strong>Status:</strong> ✅ Running</p>";
    echo "<p><strong>Total Movies:</strong> " . ($stats['total_movies'] ?? 0) . "</p>";
    echo "<p><strong>Total Users:</strong> " . count($users_data['users'] ?? []) . "</p>";
    echo "<p><strong>Total Searches:</strong> " . ($stats['total_searches'] ?? 0) . "</p>";
    echo "<h3>🚀 Quick Setup</h3>";
    echo "<p><a href='?setwebhook=1'>Set Webhook Now</a></p>";
    echo "<h3>📋 Complete Features</h3>";
    echo "<ul>";
    echo "<li>Smart Movie Search with suggestions</li>";
    echo "<li>Google Drive Download integration</li>";
    echo "<li>Advanced search filters</li>";
    echo "<li>Search history & popular searches</li>";
    echo "<li>File upload with watermark</li>";
    echo "<li>Multi-language support (Hindi/English)</li>";
    echo "<li>Pagination system</li>";
    echo "<li>User statistics & tracking</li>";
    echo "<li>Automatic backups</li>";
    echo "</ul>";
}
?>