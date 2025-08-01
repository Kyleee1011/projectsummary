<?php
// diagnostic.php
echo "<h2>Server Diagnostic</h2>";

echo "<h3>PHP Version</h3>";
echo PHP_VERSION . "<br>";

echo "<h3>Required Extensions</h3>";
$extensions = ['zip', 'xml', 'gd', 'mbstring', 'dom', 'xmlwriter'];
foreach ($extensions as $ext) {
    echo $ext . ": " . (extension_loaded($ext) ? "✓ Loaded" : "✗ Missing") . "<br>";
}

echo "<h3>Memory & Limits</h3>";
echo "Memory Limit: " . ini_get('memory_limit') . "<br>";
echo "Max Execution Time: " . ini_get('max_execution_time') . "<br>";
echo "Post Max Size: " . ini_get('post_max_size') . "<br>";
echo "Upload Max Size: " . ini_get('upload_max_filesize') . "<br>";

echo "<h3>Temp Directory</h3>";
echo "Temp Dir: " . sys_get_temp_dir() . "<br>";
echo "Writable: " . (is_writable(sys_get_temp_dir()) ? "Yes" : "No") . "<br>";

echo "<h3>Available Memory</h3>";
echo "Current Usage: " . memory_get_usage(true) / 1024 / 1024 . " MB<br>";
echo "Peak Usage: " . memory_get_peak_usage(true) / 1024 / 1024 . " MB<br>";
?>