<?php
echo "TadkaMovieBot Test Page<br>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Server: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";

// Check if required extensions are loaded
$extensions = ['curl', 'gd', 'json', 'mbstring'];
foreach ($extensions as $ext) {
    echo "Extension $ext: " . (extension_loaded($ext) ? '✅ Loaded' : '❌ Missing') . "<br>";
}

// Check file permissions
$files = ['index.php', 'config.php', 'users.json'];
foreach ($files as $file) {
    echo "File $file: " . (file_exists($file) ? '✅ Exists' : '❌ Missing') . "<br>";
}

echo "Test completed successfully!";
?>
