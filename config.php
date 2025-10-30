<?php
class Config {
    private static $config = null;
    
    public static function get($key, $default = null) {
        if (self::$config === null) {
            self::loadConfig();
        }
        
        return self::$config[$key] ?? $default;
    }
    
    private static function loadConfig() {
        // Environment variables se config load karo
        self::$config = [
            'ENVIRONMENT' => $_ENV['ENVIRONMENT'] ?? 'production',
            'BOT_TOKEN' => $_ENV['BOT_TOKEN'] ?? '',
            'CHANNEL_ID' => $_ENV['CHANNEL_ID'] ?? '',
            'GROUP_CHANNEL_ID' => $_ENV['GROUP_CHANNEL_ID'] ?? '',
            'BACKUP_CHANNEL_ID' => $_ENV['BACKUP_CHANNEL_ID'] ?? '',
            'BOT_ID' => $_ENV['BOT_ID'] ?? '',
            'BOT_USERNAME' => $_ENV['BOT_USERNAME'] ?? '',
            'OWNER_ID' => $_ENV['OWNER_ID'] ?? '',
            'APP_API_ID' => $_ENV['APP_API_ID'] ?? '',
            'APP_API_HASH' => $_ENV['APP_API_HASH'] ?? '',
            'GOOGLE_DRIVE_CLIENT_ID' => $_ENV['GOOGLE_DRIVE_CLIENT_ID'] ?? '',
            'GOOGLE_DRIVE_CLIENT_SECRET' => $_ENV['GOOGLE_DRIVE_CLIENT_SECRET'] ?? '',
            'GOOGLE_DRIVE_REFRESH_TOKEN' => $_ENV['GOOGLE_DRIVE_REFRESH_TOKEN'] ?? '',
            'GOOGLE_DRIVE_FOLDER_ID' => $_ENV['GOOGLE_DRIVE_FOLDER_ID'] ?? '',
            'MAINTENANCE_MODE' => $_ENV['MAINTENANCE_MODE'] ?? 'false'
        ];
        
        error_log("Config loaded: " . json_encode(array_keys(self::$config)));
    }
}
?>
