<?php
// Error logging enable karo
error_log("GoogleDriveBot starting...");
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Render.com specific setup
if (getenv('RENDER')) {
    $_ENV = getenv();
    error_log("Running on Render.com environment");
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
define('BOT_USERNAME', Config::get('BOT_USERNAME', '@TadkaMovieBot'));
define('OWNER_ID', Config::get('OWNER_ID', '1080317415'));

// ==============================
// GOOGLE DRIVE CONFIG
// ==============================
define('GOOGLE_DRIVE_CLIENT_ID', Config::get('GOOGLE_DRIVE_CLIENT_ID', ''));
define('GOOGLE_DRIVE_CLIENT_SECRET', Config::get('GOOGLE_DRIVE_CLIENT_SECRET', ''));
define('GOOGLE_DRIVE_REFRESH_TOKEN', Config::get('GOOGLE_DRIVE_REFRESH_TOKEN', ''));
define('GOOGLE_DRIVE_FOLDER_ID', Config::get('GOOGLE_DRIVE_FOLDER_ID', ''));

// ==============================
// FILE CONFIG
// ==============================
define('MAX_FILE_SIZE', 2 * 1024 * 1024 * 1024); // 2GB
define('CHUNK_SIZE', 64 * 1024);
define('RETRY_COUNT', 3);
define('DAILY_DOWNLOAD_LIMIT', 10); // Daily download limit per user

// File initialization
if (!file_exists('users.json')) {
    file_put_contents('users.json', json_encode(['users' => [], 'total_downloads' => 0]));
}
if (!file_exists('banned_users.json')) {
    file_put_contents('banned_users.json', json_encode([]));
}
if (!file_exists('user_files.json')) {
    file_put_contents('user_files.json', json_encode([]));
}
if (!file_exists('bot_state.json')) {
    file_put_contents('bot_state.json', json_encode([
        'custom_caption' => '',
        'custom_thumbnail' => '',
        'current_rename' => '',
        'batch_queue' => [],
        'user_limits' => []
    ]));
}

// ==============================
// STATE MANAGEMENT
// ==============================
$bot_state = json_decode(file_get_contents('bot_state.json'), true);

function save_bot_state() {
    global $bot_state;
    file_put_contents('bot_state.json', json_encode($bot_state, JSON_PRETTY_PRINT));
}

// ==============================
// CUSTOM CAPTION TEMPLATE
// ==============================
function generate_custom_caption($file_name, $file_size) {
    global $bot_state;
    
    // Agar custom caption set hai toh use karo, nahi toh default
    if (!empty($bot_state['custom_caption'])) {
        return $bot_state['custom_caption'] . "\n\n📁 File: $file_name\n📦 Size: " . human_readable_size($file_size);
    }
    
    $caption = "✨ 𝗦𝗛𝗢𝗪 𝗧𝗜𝗠𝗘 𝟮𝟬𝟮𝟱 ✨\n\n";
    $caption .= "🎞️ 𝟭𝟬𝟴𝟬𝗽 𝗛𝗘𝗩𝗖 𝗪𝗘𝗕-𝗗𝗟\n";
    $caption .= "🔊 𝗛𝗶𝗻𝗱𝗶 𝟮.𝟬 + 𝗧𝗲𝗹𝘂𝗴𝘂 𝟱.𝟭\n";
    $caption .= "📄 𝗘𝗻𝗴𝗹𝗶𝘀𝗵 𝗦𝘂𝗯𝘁𝗶𝘁𝗹𝗲𝘀\n";
    $caption .= "💿 𝗠𝗣𝟰 𝗙𝗼𝗿𝗺𝗮𝘁\n\n";
    $caption .= "━━━━━━━━━━━━━━━━━━━━\n";
    $caption .= "🎯 𝗠𝗮𝗶𝗻: @EntertainmentTadka786\n";
    $caption .= "📩 𝗥𝗲𝗾𝘂𝗲𝘀𝘁: @EntertainmentTadka7860\n";
    $caption .= "🛡️ 𝗕𝗮𝗰𝗸𝘂𝗽: @ETBackup\n\n";
    $caption .= "📁 𝗙𝗶𝗹𝗲: " . $file_name . "\n";
    $caption .= "📦 𝗦𝗶𝘇𝗲: " . human_readable_size($file_size);
    
    return $caption;
}

function generate_part_caption($file_name, $part_num, $total_parts, $file_size) {
    global $bot_state;
    
    if (!empty($bot_state['custom_caption'])) {
        return $bot_state['custom_caption'] . "\n\n📁 File: $file_name [Part $part_num/$total_parts]\n📦 Size: " . human_readable_size($file_size);
    }
    
    $caption = "✨ 𝗦𝗛𝗢𝗪 𝗧𝗜𝗠𝗘 𝟮𝟬𝟮𝟱 ✨\n\n";
    $caption .= "🎞️ 𝟭𝟬𝟴𝟬𝗽 𝗛𝗘𝗩𝗖 𝗪𝗘𝗕-𝗗𝗟\n";
    $caption .= "🔊 𝗛𝗶𝗻𝗱𝗶 𝟮.𝟬 + 𝗧𝗲𝗹𝘂𝗴𝘂 𝟱.𝟭\n";
    $caption .= "📄 𝗘𝗻𝗴𝗹𝗶𝘀𝗵 𝗦𝘂𝗯𝘁𝗶𝘁𝗹𝗲𝘀\n";
    $caption .= "💿 𝗠𝗣𝟰 𝗙𝗼𝗿𝗺𝗮𝘁\n\n";
    $caption .= "━━━━━━━━━━━━━━━━━━━━\n";
    $caption .= "🎯 𝗠𝗮𝗶𝗻: @EntertainmentTadka786\n";
    $caption .= "📩 𝗥𝗲𝗾𝘂𝗲𝘀𝘁: @EntertainmentTadka7860\n";
    $caption .= "🛡️ 𝗕𝗮𝗰𝗸𝘂𝗽: @ETBackup\n\n";
    $caption .= "📁 𝗙𝗶𝗹𝗲: " . $file_name . " [Part " . $part_num . "/" . $total_parts . "]\n";
    $caption .= "📦 𝗦𝗶𝘇𝗲: " . human_readable_size($file_size);
    
    return $caption;
}

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
    
    public function listFolder($folder_id) {
        $url = "https://www.googleapis.com/drive/v3/files?q='{$folder_id}'+in+parents&fields=files(id,name,size,mimeType)";
        
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
// UTILITY FUNCTIONS
// ==============================
function human_readable_size($size, $suffix = "B") {
    if ($size == 0) return "0B";
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

function create_zip($files, $zip_path) {
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE) === TRUE) {
        foreach ($files as $file) {
            if (file_exists($file)) {
                $zip->addFile($file, basename($file));
            }
        }
        $zip->close();
        return true;
    }
    return false;
}

// ==============================
// SECURITY & LIMIT FUNCTIONS
// ==============================
function is_banned($user_id) {
    $banned = json_decode(file_get_contents('banned_users.json'), true);
    return in_array($user_id, $banned);
}

function ban_user($user_id) {
    $banned = json_decode(file_get_contents('banned_users.json'), true);
    if (!in_array($user_id, $banned)) {
        $banned[] = $user_id;
        file_put_contents('banned_users.json', json_encode($banned, JSON_PRETTY_PRINT));
    }
}

function unban_user($user_id) {
    $banned = json_decode(file_get_contents('banned_users.json'), true);
    $banned = array_diff($banned, [$user_id]);
    file_put_contents('banned_users.json', json_encode(array_values($banned), JSON_PRETTY_PRINT));
}

function check_daily_limit($user_id) {
    $users_data = json_decode(file_get_contents('users.json'), true);
    $user_data = $users_data['users'][$user_id] ?? [];
    
    $today = date('Y-m-d');
    $last_download_date = $user_data['last_download_date'] ?? '';
    $daily_count = $user_data['daily_download_count'] ?? 0;
    
    if ($last_download_date != $today) {
        // New day, reset counter
        $users_data['users'][$user_id]['daily_download_count'] = 0;
        $users_data['users'][$user_id]['last_download_date'] = $today;
        file_put_contents('users.json', json_encode($users_data, JSON_PRETTY_PRINT));
        return true;
    }
    
    return $daily_count < DAILY_DOWNLOAD_LIMIT;
}

function increment_download_count($user_id) {
    $users_data = json_decode(file_get_contents('users.json'), true);
    $today = date('Y-m-d');
    
    if (!isset($users_data['users'][$user_id])) {
        $users_data['users'][$user_id] = [];
    }
    
    $users_data['users'][$user_id]['daily_download_count'] = ($users_data['users'][$user_id]['daily_download_count'] ?? 0) + 1;
    $users_data['users'][$user_id]['last_download_date'] = $today;
    $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
    
    file_put_contents('users.json', json_encode($users_data, JSON_PRETTY_PRINT));
}

function add_user_file($user_id, $file_name, $file_size, $gdrive_url) {
    $user_files = json_decode(file_get_contents('user_files.json'), true);
    
    if (!isset($user_files[$user_id])) {
        $user_files[$user_id] = [];
    }
    
    $user_files[$user_id][] = [
        'file_name' => $file_name,
        'file_size' => $file_size,
        'gdrive_url' => $gdrive_url,
        'download_time' => date('Y-m-d H:i:s')
    ];
    
    // Keep only last 50 files
    if (count($user_files[$user_id]) > 50) {
        $user_files[$user_id] = array_slice($user_files[$user_id], -50);
    }
    
    file_put_contents('user_files.json', json_encode($user_files, JSON_PRETTY_PRINT));
}

// ==============================
// TELEGRAM API FUNCTIONS
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
        return $res;
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

function editMessage($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text
    ];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    $result = apiRequest('editMessageText', $data);
    return json_decode($result, true);
}

function answerCallbackQuery($callback_query_id, $text = null) {
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    apiRequest('answerCallbackQuery', $data);
}

function sendDocument($chat_id, $document_path, $caption = null, $thumb_path = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument";
    
    $post_data = [
        'chat_id' => $chat_id,
        'document' => new CURLFile($document_path),
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ];
    
    if ($thumb_path && file_exists($thumb_path)) {
        $post_data['thumb'] = new CURLFile($thumb_path);
    }
    
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

// ==============================
// NEW FEATURES IMPLEMENTATION
// ==============================

// 1. FILE MANAGEMENT COMMANDS
function handle_rename($chat_id, $text, $user_id) {
    global $bot_state;
    
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        sendMessage($chat_id, "❌ Usage: /rename new_filename.mp4");
        return;
    }
    
    $new_name = trim($parts[1]);
    $bot_state['current_rename'] = $new_name;
    save_bot_state();
    
    sendMessage($chat_id, "✅ File name set to: `$new_name`\n\nNext Google Drive download will use this name.", null, 'HTML');
}

function handle_caption($chat_id, $text, $user_id) {
    global $bot_state;
    
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        sendMessage($chat_id, "❌ Usage: /caption \"Your custom caption text\"");
        return;
    }
    
    $caption = trim($parts[1]);
    $bot_state['custom_caption'] = $caption;
    save_bot_state();
    
    sendMessage($chat_id, "✅ Custom caption set!\n\nNext downloads will use this caption.");
}

function handle_thumbnail($chat_id, $message, $user_id) {
    global $bot_state;
    
    if (isset($message['photo'])) {
        $photo = end($message['photo']);
        $file_id = $photo['file_id'];
        
        $thumb_path = "custom_thumb.jpg";
        download_telegram_file($file_id, $thumb_path);
        
        $bot_state['custom_thumbnail'] = $thumb_path;
        save_bot_state();
        
        sendMessage($chat_id, "✅ Custom thumbnail set!");
    } else {
        sendMessage($chat_id, "❌ Please send a photo with /thumbnail command");
    }
}

function handle_view_thumbnail($chat_id, $user_id) {
    global $bot_state;
    
    $thumb_path = $bot_state['custom_thumbnail'] ?? '';
    
    if (!$thumb_path || !file_exists($thumb_path)) {
        sendMessage($chat_id, "❌ No custom thumbnail set!");
        return;
    }
    
    sendDocument($chat_id, $thumb_path, "📷 Current Custom Thumbnail");
}

function handle_delete_thumbnail($chat_id, $user_id) {
    global $bot_state;
    
    $thumb_path = $bot_state['custom_thumbnail'] ?? '';
    
    if ($thumb_path && file_exists($thumb_path)) {
        unlink($thumb_path);
    }
    
    $bot_state['custom_thumbnail'] = '';
    save_bot_state();
    
    sendMessage($chat_id, "✅ Custom thumbnail deleted!");
}

// 2. BATCH PROCESSING
function handle_batch_start($chat_id, $user_id) {
    global $bot_state;
    
    $bot_state['batch_queue'][$user_id] = [];
    save_bot_state();
    
    sendMessage($chat_id, 
        "📦 Batch Mode Started!\n\n" .
        "Send Google Drive links one by one.\n" .
        "When done, send /endbatch to process all files.\n" .
        "Send /cancelbatch to cancel."
    );
}

function handle_batch_add($chat_id, $text, $user_id) {
    global $bot_state;
    
    if (!isset($bot_state['batch_queue'][$user_id])) {
        sendMessage($chat_id, "❌ Batch mode not started. Use /batch first.");
        return;
    }
    
    if (is_google_drive_url($text)) {
        $bot_state['batch_queue'][$user_id][] = $text;
        $count = count($bot_state['batch_queue'][$user_id]);
        save_bot_state();
        
        sendMessage($chat_id, "✅ Link added to batch! Total: $count links\n\nSend more links or /endbatch to process.");
    } else {
        sendMessage($chat_id, "❌ Invalid Google Drive URL");
    }
}

function handle_batch_end($chat_id, $user_id) {
    global $bot_state;
    
    if (!isset($bot_state['batch_queue'][$user_id]) || empty($bot_state['batch_queue'][$user_id])) {
        sendMessage($chat_id, "❌ No links in batch queue!");
        return;
    }
    
    $links = $bot_state['batch_queue'][$user_id];
    $total = count($links);
    
    sendMessage($chat_id, "🔄 Processing $total files in batch...");
    
    $processed = 0;
    foreach ($links as $index => $link) {
        sendMessage($chat_id, "📥 Processing file " . ($index + 1) . "/$total...");
        handle_google_drive_download($chat_id, $link, $user_id);
        $processed++;
        
        // Small delay between downloads
        sleep(2);
    }
    
    unset($bot_state['batch_queue'][$user_id]);
    save_bot_state();
    
    sendMessage($chat_id, "✅ Batch processing completed! $processed/$total files processed.");
}

function handle_batch_cancel($chat_id, $user_id) {
    global $bot_state;
    
    if (isset($bot_state['batch_queue'][$user_id])) {
        $count = count($bot_state['batch_queue'][$user_id]);
        unset($bot_state['batch_queue'][$user_id]);
        save_bot_state();
        
        sendMessage($chat_id, "❌ Batch cancelled! $count links removed from queue.");
    } else {
        sendMessage($chat_id, "❌ No active batch to cancel.");
    }
}

function handle_queue($chat_id, $user_id) {
    global $bot_state;
    
    $user_queue = $bot_state['batch_queue'][$user_id] ?? [];
    $total_queued = count($user_queue);
    
    $msg = "📊 Your Download Queue\n\n";
    $msg .= "Total files in queue: $total_queued\n\n";
    
    if ($total_queued > 0) {
        $msg .= "Queued links:\n";
        foreach ($user_queue as $index => $link) {
            $msg .= ($index + 1) . ". " . substr($link, 0, 50) . "...\n";
        }
        $msg .= "\nUse /endbatch to process all.";
    } else {
        $msg .= "No files in queue. Use /batch to start batch download.";
    }
    
    sendMessage($chat_id, $msg);
}

// 3. ADMIN COMMANDS
function handle_broadcast($chat_id, $text, $user_id) {
    if ($user_id != OWNER_ID) {
        sendMessage($chat_id, "❌ Access denied. Owner only command.");
        return;
    }
    
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        sendMessage($chat_id, "❌ Usage: /broadcast your message here");
        return;
    }
    
    $message = trim($parts[1]);
    $users_data = json_decode(file_get_contents('users.json'), true);
    $total_users = count($users_data['users'] ?? []);
    
    sendMessage($chat_id, "📢 Broadcasting to $total_users users...");
    
    $sent = 0;
    $failed = 0;
    
    foreach ($users_data['users'] as $user_id => $user_data) {
        try {
            sendMessage($user_id, "📢 Announcement:\n\n$message\n\n- " . BOT_USERNAME);
            $sent++;
            // Rate limiting
            usleep(100000); // 0.1 second delay
        } catch (Exception $e) {
            $failed++;
        }
    }
    
    sendMessage($chat_id, "✅ Broadcast completed!\nSent: $sent\nFailed: $failed");
}

function handle_users($chat_id, $user_id) {
    if ($user_id != OWNER_ID) {
        sendMessage($chat_id, "❌ Access denied. Owner only command.");
        return;
    }
    
    $users_data = json_decode(file_get_contents('users.json'), true);
    $total_users = count($users_data['users'] ?? []);
    $total_downloads = $users_data['total_downloads'] ?? 0;
    
    // Today's active users
    $today = date('Y-m-d');
    $active_today = 0;
    foreach ($users_data['users'] as $user_data) {
        if (($user_data['last_active'] ?? '') >= $today) {
            $active_today++;
        }
    }
    
    $msg = "📊 Users Statistics\n\n";
    $msg .= "👥 Total Users: $total_users\n";
    $msg .= "📥 Total Downloads: $total_downloads\n";
    $msg .= "🟢 Active Today: $active_today\n\n";
    
    // Recent users
    $msg .= "🆕 Recent Users:\n";
    $recent_users = array_slice($users_data['users'], -5, 5, true);
    $i = 1;
    foreach ($recent_users as $uid => $udata) {
        $username = $udata['username'] ?? 'No username';
        $msg .= "$i. User $uid ($username)\n";
        $i++;
    }
    
    sendMessage($chat_id, $msg);
}

function handle_cleanup($chat_id, $user_id) {
    if ($user_id != OWNER_ID) {
        sendMessage($chat_id, "❌ Access denied. Owner only command.");
        return;
    }
    
    // Clean temporary files
    $temp_dirs = glob(sys_get_temp_dir() . '/*gdrive_downloads*');
    $temp_dirs = array_merge($temp_dirs, glob(sys_get_temp_dir() . '/*split_files*'));
    
    $cleaned = 0;
    foreach ($temp_dirs as $dir) {
        if (is_dir($dir)) {
            rrmdir($dir);
            $cleaned++;
        }
    }
    
    sendMessage($chat_id, "🧹 Cleanup completed! $cleaned temporary directories removed.");
}

// 4. USER FEATURES
function handle_myfiles($chat_id, $user_id) {
    $user_files = json_decode(file_get_contents('user_files.json'), true);
    $files = $user_files[$user_id] ?? [];
    
    if (empty($files)) {
        sendMessage($chat_id, "📭 You haven't downloaded any files yet!");
        return;
    }
    
    $total_files = count($files);
    $total_size = 0;
    
    $msg = "📁 Your Downloaded Files\n\n";
    $msg .= "Total Files: $total_files\n\n";
    
    // Show last 10 files
    $recent_files = array_slice($files, -10);
    $recent_files = array_reverse($recent_files);
    
    $i = 1;
    foreach ($recent_files as $file) {
        $file_name = $file['file_name'];
        $file_size = human_readable_size($file['file_size']);
        $time = date('M j, H:i', strtotime($file['download_time']));
        
        $msg .= "$i. $file_name\n";
        $msg .= "   📦 $file_size • 🕒 $time\n\n";
        
        $total_size += $file['file_size'];
        $i++;
    }
    
    $msg .= "💾 Total Size: " . human_readable_size($total_size);
    
    sendMessage($chat_id, $msg);
}

function handle_speedtest($chat_id, $user_id) {
    $test_file_url = "https://proof.ovh.net/files/10Mb.dat";
    $temp_file = sys_get_temp_dir() . '/speedtest_' . uniqid() . '.dat';
    
    sendMessage($chat_id, "🚀 Starting speed test...");
    
    $start_time = microtime(true);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $test_file_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FILE => fopen($temp_file, 'w'),
        CURLOPT_TIMEOUT => 30
    ]);
    
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $end_time = microtime(true);
    
    if ($http_code === 200 && file_exists($temp_file)) {
        $file_size = filesize($temp_file);
        $download_time = $end_time - $start_time;
        $speed_mbps = ($file_size * 8) / ($download_time * 1000000); // Mbps
        
        unlink($temp_file);
        
        $msg = "📊 Speed Test Results\n\n";
        $msg .= "📦 File Size: " . human_readable_size($file_size) . "\n";
        $msg .= "⏱️ Download Time: " . round($download_time, 2) . "s\n";
        $msg .= "🚀 Download Speed: " . round($speed_mbps, 2) . " Mbps\n";
        $msg .= "📍 Server: OVH\n";
        
        if ($speed_mbps > 50) {
            $msg .= "✅ Excellent speed!";
        } elseif ($speed_mbps > 20) {
            $msg .= "👍 Good speed!";
        } else {
            $msg .= "⚠️ Slow speed detected";
        }
        
        sendMessage($chat_id, $msg);
    } else {
        sendMessage($chat_id, "❌ Speed test failed!");
    }
}

// 5. ADVANCED GOOGLE DRIVE FEATURES
function handle_folder_download($chat_id, $text, $user_id) {
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        sendMessage($chat_id, "❌ Usage: /folder Google_Drive_Folder_URL");
        return;
    }
    
    $folder_url = trim($parts[1]);
    
    if (!is_google_drive_url($folder_url)) {
        sendMessage($chat_id, "❌ Invalid Google Drive URL!");
        return;
    }
    
    $folder_id = extract_google_drive_id($folder_url);
    if (!$folder_id) {
        sendMessage($chat_id, "❌ Could not extract Folder ID");
        return;
    }
    
    sendMessage($chat_id, "📁 Scanning Google Drive folder...");
    
    try {
        $drive_service = new GoogleDriveService();
        $folder_contents = $drive_service->listFolder($folder_id);
        
        if (!isset($folder_contents['files']) || empty($folder_contents['files'])) {
            sendMessage($chat_id, "❌ Folder is empty or inaccessible!");
            return;
        }
        
        $file_count = count($folder_contents['files']);
        sendMessage($chat_id, "📊 Found $file_count files in folder. Starting download...");
        
        $downloaded = 0;
        foreach ($folder_contents['files'] as $file) {
            if ($file['mimeType'] != 'application/vnd.google-apps.folder') { // Skip subfolders
                $file_url = "https://drive.google.com/file/d/" . $file['id'] . "/view";
                sendMessage($chat_id, "📥 Downloading: " . $file['name']);
                handle_google_drive_download($chat_id, $file_url, $user_id);
                $downloaded++;
                sleep(1); // Rate limiting
            }
        }
        
        sendMessage($chat_id, "✅ Folder download completed! $downloaded files downloaded.");
        
    } catch (Exception $e) {
        sendMessage($chat_id, "❌ Error: " . $e->getMessage());
    }
}

// 6. SYSTEM MONITORING
function handle_logs($chat_id, $user_id) {
    if ($user_id != OWNER_ID) {
        sendMessage($chat_id, "❌ Access denied. Owner only command.");
        return;
    }
    
    $log_file = __DIR__ . '/error.log';
    if (!file_exists($log_file)) {
        sendMessage($chat_id, "📭 No log file found.");
        return;
    }
    
    $logs = file_get_contents($log_file);
    $lines = explode("\n", $logs);
    $recent_logs = array_slice($lines, -20); // Last 20 lines
    
    $log_text = "📋 Recent Logs\n\n";
    $log_text .= implode("\n", $recent_logs);
    
    if (strlen($log_text) > 4000) {
        $log_text = substr($log_text, -4000);
    }
    
    sendMessage($chat_id, $log_text);
}

function handle_storage($chat_id, $user_id) {
    if ($user_id != OWNER_ID) {
        sendMessage($chat_id, "❌ Access denied. Owner only command.");
        return;
    }
    
    $free = disk_free_space("/");
    $total = disk_total_space("/");
    $used = $total - $free;
    
    $usage_percent = round(($used / $total) * 100, 2);
    
    $msg = "💾 Storage Usage\n\n";
    $msg .= "📊 Usage: $usage_percent%\n";
    $msg .= "💿 Total: " . human_readable_size($total) . "\n";
    $msg .= "📁 Used: " . human_readable_size($used) . "\n";
    $msg .= "🆓 Free: " . human_readable_size($free) . "\n\n";
    
    if ($usage_percent > 90) {
        $msg .= "⚠️ Storage almost full!";
    } elseif ($usage_percent > 70) {
        $msg .= "ℹ️ Storage usage is high";
    } else {
        $msg .= "✅ Storage is healthy";
    }
    
    sendMessage($chat_id, $msg);
}

// 7. SECURITY FEATURES
function handle_ban($chat_id, $text, $user_id) {
    if ($user_id != OWNER_ID) {
        sendMessage($chat_id, "❌ Access denied. Owner only command.");
        return;
    }
    
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        sendMessage($chat_id, "❌ Usage: /ban user_id");
        return;
    }
    
    $ban_user_id = intval(trim($parts[1]));
    ban_user($ban_user_id);
    
    sendMessage($chat_id, "✅ User $ban_user_id has been banned.");
}

function handle_unban($chat_id, $text, $user_id) {
    if ($user_id != OWNER_ID) {
        sendMessage($chat_id, "❌ Access denied. Owner only command.");
        return;
    }
    
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        sendMessage($chat_id, "❌ Usage: /unban user_id");
        return;
    }
    
    $unban_user_id = intval(trim($parts[1]));
    unban_user($unban_user_id);
    
    sendMessage($chat_id, "✅ User $unban_user_id has been unbanned.");
}

function handle_limit($chat_id, $text, $user_id) {
    if ($user_id != OWNER_ID) {
        sendMessage($chat_id, "❌ Access denied. Owner only command.");
        return;
    }
    
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        sendMessage($chat_id, "❌ Usage: /limit number (e.g., /limit 5)");
        return;
    }
    
    $new_limit = intval(trim($parts[1]));
    if ($new_limit < 1 || $new_limit > 100) {
        sendMessage($chat_id, "❌ Limit must be between 1 and 100");
        return;
    }
    
    define('DAILY_DOWNLOAD_LIMIT', $new_limit);
    sendMessage($chat_id, "✅ Daily download limit set to $new_limit per user.");
}

// ==============================
// GOOGLE DRIVE URL HANDLING
// ==============================
function is_google_drive_url($url) {
    $patterns = [
        'drive.google.com/file/d/',
        'drive.google.com/open?id=',
        'docs.google.com/uc?id=',
        'drive.google.com/uc?id=',
        'drive.google.com/drive/folders/'
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
    
    if (preg_match('/\/drive\/folders\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
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

// ==============================
// MAIN GOOGLE DRIVE HANDLER
// ==============================
function handle_google_drive_download($chat_id, $gdrive_url, $user_id = null) {
    // Security checks
    if (is_banned($user_id)) {
        sendMessage($chat_id, "❌ You are banned from using this bot.");
        return;
    }
    
    if (!check_daily_limit($user_id)) {
        sendMessage($chat_id, "❌ Daily download limit reached! Try again tomorrow.");
        return;
    }
    
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
        
        // Apply rename if set
        global $bot_state;
        if (!empty($bot_state['current_rename'])) {
            $file_name = $bot_state['current_rename'];
            $bot_state['current_rename'] = ''; // Reset after use
            save_bot_state();
        }
        
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
            editMessage($chat_id, $progress_msg['result']['message_id'], "❌ Download failed!\n\n⚠️ File might be too large or restricted");
            rrmdir($temp_dir);
            return;
        }
        
        $final_size = filesize($download_path);
        editMessage($chat_id, $progress_msg['result']['message_id'], 
            "✅ Download Complete!\n\n" .
            "📄 File: <code>{$file_name}</code>\n" .
            "📦 Size: " . human_readable_size($final_size) . "\n\n" .
            "🔄 Processing for Telegram..."
        );
        
        // Add to user's file history
        add_user_file($user_id, $file_name, $final_size, $gdrive_url);
        
        // Increment download count
        increment_download_count($user_id);
        
        handle_downloaded_file($chat_id, $download_path, $file_name);
        
        rrmdir($temp_dir);
        
    } catch (Exception $e) {
        sendMessage($chat_id, "❌ Error: " . $e->getMessage());
    }
}

function handle_downloaded_file($chat_id, $file_path, $original_name) {
    $file_size = filesize($file_path);
    $max_telegram_size = 2 * 1024 * 1024 * 1024; // 2GB
    
    if ($file_size > $max_telegram_size) {
        $options = [
            'inline_keyboard' => [
                [
                    ['text' => '🔪 Split into Parts', 'callback_data' => 'split_gdrive:' . base64_encode($file_path)],
                    ['text' => '📤 Upload as is', 'callback_data' => 'upload_gdrive:' . base64_encode($file_path)]
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
    
    // Direct upload for files under 2GB
    upload_telegram_file($chat_id, $file_path, $original_name);
}

function upload_telegram_file($chat_id, $file_path, $file_name = null) {
    if (!$file_name) {
        $file_name = basename($file_path);
    }
    
    $file_size = filesize($file_path);
    $caption = generate_custom_caption($file_name, $file_size);
    
    // Use custom thumbnail if available
    global $bot_state;
    $thumb_path = $bot_state['custom_thumbnail'] ?? '';
    
    $result = sendDocument($chat_id, $file_path, $caption, $thumb_path);
    $result_data = json_decode($result, true);
    
    if ($result_data && $result_data['ok']) {
        sendMessage($chat_id, "✅ File uploaded successfully!");
        
        // Update download stats
        $users_data = json_decode(file_get_contents('users.json'), true);
        $users_data['total_downloads'] = ($users_data['total_downloads'] ?? 0) + 1;
        file_put_contents('users.json', json_encode($users_data, JSON_PRETTY_PRINT));
    } else {
        sendMessage($chat_id, "❌ Upload failed! File might be too large.");
    }
    
    // Clean up
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    
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
        
        $parts = split_file($file_path, $temp_dir, 1.9 * 1024 * 1024 * 1024); // Split into 1.9GB parts
        
        sendMessage($chat_id, "📦 Split into " . count($parts) . " parts. Uploading...");
        
        $total_parts = count($parts);
        $uploaded = 0;
        $file_name = basename($file_path);
        
        foreach ($parts as $index => $part_path) {
            $part_num = $index + 1;
            $part_size = filesize($part_path);
            $caption = generate_part_caption($file_name, $part_num, $total_parts, $part_size);
            
            // Upload each part
            upload_telegram_file($chat_id, $part_path, $file_name . " [Part $part_num/$total_parts]");
            
            $uploaded++;
            
            if ($uploaded % 2 === 0) {
                sendMessage($chat_id, "⏳ Uploaded {$uploaded}/{$total_parts} parts...");
            }
        }
        
        sendMessage($chat_id, "✅ All parts uploaded successfully!");
        rrmdir($temp_dir);
        
        // Update download stats
        $users_data = json_decode(file_get_contents('users.json'), true);
        $users_data['total_downloads'] = ($users_data['total_downloads'] ?? 0) + 1;
        file_put_contents('users.json', json_encode($users_data, JSON_PRETTY_PRINT));
        
    } catch (Exception $e) {
        sendMessage($chat_id, "❌ Error splitting file: " . $e->getMessage());
    }
}

function handle_gdrive_direct_upload($chat_id, $file_path_encoded) {
    $file_path = base64_decode($file_path_encoded);
    
    if (!file_exists($file_path)) {
        sendMessage($chat_id, "❌ File not found!");
        return;
    }
    
    sendMessage($chat_id, "📤 Uploading file directly (this may take a while)...");
    
    try {
        $file_name = basename($file_path);
        upload_telegram_file($chat_id, $file_path, $file_name);
        
    } catch (Exception $e) {
        sendMessage($chat_id, "❌ Error uploading file: " . $e->getMessage());
    }
}

// ==============================
// USER MANAGEMENT
// ==============================
function update_user_stats($user_id) {
    $users_data = json_decode(file_get_contents('users.json'), true);
    
    if (!isset($users_data['users'][$user_id])) {
        $users_data['users'][$user_id] = [
            'first_name' => '',
            'username' => '',
            'joined' => date('Y-m-d H:i:s'),
            'last_active' => date('Y-m-d H:i:s'),
            'download_count' => 0,
            'daily_download_count' => 0,
            'last_download_date' => ''
        ];
    }
    
    $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
    
    file_put_contents('users.json', json_encode($users_data, JSON_PRETTY_PRINT));
}

// ==============================
// MAIN UPDATE PROCESSING
// ==============================
$update = json_decode(file_get_contents('php://input'), true);

if ($update) {
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        
        // Security check
        if (is_banned($user_id)) {
            sendMessage($chat_id, "❌ You are banned from using this bot.");
            exit;
        }
        
        // Update user stats
        update_user_stats($user_id);
        
        if (strpos($text, '/') === 0) {
            $parts = explode(' ', $text);
            $command = $parts[0];
            
            // NEW COMMANDS HANDLING
            switch ($command) {
                // File Management
                case '/rename':
                    handle_rename($chat_id, $text, $user_id);
                    break;
                case '/caption':
                    handle_caption($chat_id, $text, $user_id);
                    break;
                case '/thumbnail':
                    handle_thumbnail($chat_id, $message, $user_id);
                    break;
                case '/viewthumb':
                    handle_view_thumbnail($chat_id, $user_id);
                    break;
                case '/delthumb':
                    handle_delete_thumbnail($chat_id, $user_id);
                    break;
                    
                // Batch Processing
                case '/batch':
                    handle_batch_start($chat_id, $user_id);
                    break;
                case '/endbatch':
                    handle_batch_end($chat_id, $user_id);
                    break;
                case '/cancelbatch':
                    handle_batch_cancel($chat_id, $user_id);
                    break;
                case '/queue':
                    handle_queue($chat_id, $user_id);
                    break;
                    
                // Admin Commands
                case '/broadcast':
                    handle_broadcast($chat_id, $text, $user_id);
                    break;
                case '/users':
                    handle_users($chat_id, $user_id);
                    break;
                case '/cleanup':
                    handle_cleanup($chat_id, $user_id);
                    break;
                    
                // User Features
                case '/myfiles':
                    handle_myfiles($chat_id, $user_id);
                    break;
                case '/speedtest':
                    handle_speedtest($chat_id, $user_id);
                    break;
                    
                // Advanced Google Drive
                case '/folder':
                    handle_folder_download($chat_id, $text, $user_id);
                    break;
                    
                // System Monitoring
                case '/logs':
                    handle_logs($chat_id, $user_id);
                    break;
                case '/storage':
                    handle_storage($chat_id, $user_id);
                    break;
                    
                // Security Features
                case '/ban':
                    handle_ban($chat_id, $text, $user_id);
                    break;
                case '/unban':
                    handle_unban($chat_id, $text, $user_id);
                    break;
                case '/limit':
                    handle_limit($chat_id, $text, $user_id);
                    break;
                    
                // Original Commands
                case '/start':
                case '/help':
                    $help_msg = "🤖 <b>Google Drive Download Bot</b>\n\n";
                    $help_msg .= "📥 <b>Basic Commands:</b>\n";
                    $help_msg .= "• Send Google Drive link directly\n";
                    $help_msg .= "• /gdrive [url] - Download file\n";
                    $help_msg .= "• /batch - Multiple files download\n";
                    $help_msg .= "• /queue - View download queue\n\n";
                    
                    $help_msg .= "🛠️ <b>File Management:</b>\n";
                    $help_msg .= "• /rename - Change file name\n";
                    $help_msg .= "• /caption - Set custom caption\n";
                    $help_msg .= "• /thumbnail - Set custom thumbnail\n";
                    $help_msg .= "• /viewthumb - View thumbnail\n";
                    $help_msg .= "• /delthumb - Delete thumbnail\n\n";
                    
                    $help_msg .= "📊 <b>User Features:</b>\n";
                    $help_msg .= "• /myfiles - Download history\n";
                    $help_msg .= "• /speedtest - Speed test\n";
                    $help_msg .= "• /stats - Bot statistics\n\n";
                    
                    $help_msg .= "🔧 <b>Advanced:</b>\n";
                    $help_msg .= "• /folder - Download entire folder\n";
                    $help_msg .= "• /storage - Server storage\n\n";
                    
                    if ($user_id == OWNER_ID) {
                        $help_msg .= "⚙️ <b>Admin Commands:</b>\n";
                        $help_msg .= "• /broadcast - Send message to all users\n";
                        $help_msg .= "• /users - User statistics\n";
                        $help_msg .= "• /cleanup - Clean temporary files\n";
                        $help_msg .= "• /logs - View system logs\n";
                        $help_msg .= "• /ban - Ban user\n";
                        $help_msg .= "• /unban - Unban user\n";
                        $help_msg .= "• /limit - Set download limit\n";
                    }
                    
                    sendMessage($chat_id, $help_msg, null, 'HTML');
                    break;
                    
                case '/gdrive':
                case '/gdl':
                    $parts = explode(' ', $text, 2);
                    if (count($parts) > 1 && is_google_drive_url($parts[1])) {
                        handle_google_drive_download($chat_id, $parts[1], $user_id);
                    } else {
                        sendMessage($chat_id, 
                            "❌ <b>Invalid Usage</b>\n\n" .
                            "🔗 <b>Correct format:</b>\n" .
                            "<code>/gdrive https://drive.google.com/file/d/ABC123/view</code>\n\n" .
                            "💡 <b>Or simply paste the Google Drive link</b>",
                            null, 'HTML'
                        );
                    }
                    break;
                    
                case '/stats':
                    $users_data = json_decode(file_get_contents('users.json'), true);
                    $total_users = count($users_data['users'] ?? []);
                    $total_downloads = $users_data['total_downloads'] ?? 0;
                    
                    // Daily limit info
                    $daily_count = $users_data['users'][$user_id]['daily_download_count'] ?? 0;
                    $remaining = DAILY_DOWNLOAD_LIMIT - $daily_count;
                    
                    $stats_msg = "📊 <b>Bot Statistics</b>\n\n";
                    $stats_msg .= "👥 Total Users: <b>{$total_users}</b>\n";
                    $stats_msg .= "📥 Total Downloads: <b>{$total_downloads}</b>\n";
                    $stats_msg .= "📅 Your Downloads Today: <b>{$daily_count}/" . DAILY_DOWNLOAD_LIMIT . "</b>\n";
                    $stats_msg .= "🎯 Remaining Today: <b>{$remaining}</b>\n";
                    $stats_msg .= "🟢 Status: <b>Online</b>\n\n";
                    $stats_msg .= "🤖 " . BOT_USERNAME;
                    
                    sendMessage($chat_id, $stats_msg, null, 'HTML');
                    break;
                    
                default:
                    sendMessage($chat_id, 
                        "❌ Unknown command!\n\n" .
                        "💡 Use /help to see available commands\n" .
                        "🔗 Or simply paste a Google Drive link to download"
                    );
                    break;
            }
        } 
        else if (!empty(trim($text))) {
            // Check if user is in batch mode
            global $bot_state;
            if (isset($bot_state['batch_queue'][$user_id])) {
                handle_batch_add($chat_id, $text, $user_id);
            } else if (is_google_drive_url($text)) {
                handle_google_drive_download($chat_id, $text, $user_id);
            } else {
                sendMessage($chat_id,
                    "🤖 <b>Google Drive Bot</b>\n\n" .
                    "📥 Send me a Google Drive link to download and upload to Telegram.\n\n" .
                    "💡 <b>Example URLs:</b>\n" .
                    "• <code>https://drive.google.com/file/d/ABC123/view</code>\n" .
                    "• <code>https://drive.google.com/open?id=ABC123</code>\n\n" .
                    "🔧 Use /help for all commands",
                    null, 'HTML'
                );
            }
        }
        
        // Handle file uploads for thumbnail
        if (isset($message['photo']) && strpos($text ?? '', '/thumbnail') !== false) {
            handle_thumbnail($chat_id, $message, $user_id);
        }
    }

    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $data = $query['data'];
        $user_id = $query['from']['id'];
        
        // Security check
        if (is_banned($user_id)) {
            sendMessage($chat_id, "❌ You are banned from using this bot.");
            exit;
        }
        
        update_user_stats($user_id);

        if (strpos($data, 'split_gdrive:') === 0) {
            $file_path_encoded = str_replace('split_gdrive:', '', $data);
            handle_gdrive_split_upload($chat_id, $file_path_encoded);
            answerCallbackQuery($query['id'], "Splitting file...");
        }
        elseif (strpos($data, 'upload_gdrive:') === 0) {
            $file_path_encoded = str_replace('upload_gdrive:', '', $data);
            handle_gdrive_direct_upload($chat_id, $file_path_encoded);
            answerCallbackQuery($query['id'], "Uploading file...");
        }
        elseif ($data === 'cancel_gdrive') {
            sendMessage($chat_id, "❌ Download cancelled.");
            answerCallbackQuery($query['id'], "Cancelled");
        }
    }
}

// Manual setup
if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $result = apiRequest('setWebhook', ['url' => $webhook_url]);
    echo "<h1>Google Drive Bot - Webhook Setup</h1>";
    echo "<p>Result: " . htmlspecialchars($result) . "</p>";
    echo "<p>Webhook URL: " . htmlspecialchars($webhook_url) . "</p>";
    
    $bot_info = json_decode(apiRequest('getMe'), true);
    if ($bot_info && isset($bot_info['ok']) && $bot_info['ok']) {
        echo "<h2>Bot Info</h2>";
        echo "<p>Name: " . htmlspecialchars($bot_info['result']['first_name']) . "</p>";
        echo "<p>Username: @" . htmlspecialchars($bot_info['result']['username']) . "</p>";
        echo "<p>Bot ID: " . htmlspecialchars($bot_info['result']['id']) . "</p>";
    }
    
    echo "<h3>🚀 All Features Added</h3>";
    echo "<ul>";
    echo "<li>✅ File Renaming (/rename)</li>";
    echo "<li>✅ Custom Captions (/caption)</li>";
    echo "<li>✅ Thumbnail Management (/thumbnail, /viewthumb, /delthumb)</li>";
    echo "<li>✅ Batch Downloads (/batch, /endbatch, /cancelbatch)</li>";
    echo "<li>✅ Queue Management (/queue)</li>";
    echo "<li>✅ Admin Broadcast (/broadcast)</li>";
    echo "<li>✅ User Statistics (/users)</li>";
    echo "<li>✅ System Cleanup (/cleanup)</li>";
    echo "<li>✅ Download History (/myfiles)</li>";
    echo "<li>✅ Speed Test (/speedtest)</li>";
    echo "<li>✅ Folder Download (/folder)</li>";
    echo "<li>✅ System Logs (/logs)</li>";
    echo "<li>✅ Storage Monitoring (/storage)</li>";
    echo "<li>✅ User Banning (/ban, /unban)</li>";
    echo "<li>✅ Download Limits (/limit)</li>";
    echo "<li>✅ Daily Download Limits (Auto)</li>";
    echo "<li>✅ User File History</li>";
    echo "<li>✅ Security Features</li>";
    echo "</ul>";
    
    exit;
}

if (!isset($update) || !$update) {
    $users_data = json_decode(file_get_contents('users.json'), true);
    echo "<h1>🤖 Google Drive Download Bot - COMPLETE</h1>";
    echo "<p><strong>Bot:</strong> " . BOT_USERNAME . "</p>";
    echo "<p><strong>Status:</strong> ✅ Running with All Features</p>";
    echo "<p><strong>Total Users:</strong> " . count($users_data['users'] ?? []) . "</p>";
    echo "<p><strong>Total Downloads:</strong> " . ($users_data['total_downloads'] ?? 0) . "</p>";
    
    echo "<h3>🎯 All Commands Available</h3>";
    echo "<p><strong>File Management:</strong> /rename, /caption, /thumbnail, /viewthumb, /delthumb</p>";
    echo "<p><strong>Batch Processing:</strong> /batch, /endbatch, /cancelbatch, /queue</p>";
    echo "<p><strong>User Features:</strong> /myfiles, /speedtest, /stats</p>";
    echo "<p><strong>Advanced:</strong> /folder, /storage</p>";
    echo "<p><strong>Admin:</strong> /broadcast, /users, /cleanup, /logs, /ban, /unban, /limit</p>";
    
    echo "<h3>🔧 Setup</h3>";
    echo "<p><a href='?setwebhook=1'>Set Webhook Now</a></p>";
}
?>
