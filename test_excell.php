<?php
ob_start(); // Start output buffering
require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {
    ini_set('memory_limit', '512M'); // Increase memory limit
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Test');
    $sheet->fromArray(['Test Column'], NULL, 'A1');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="test.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    ob_end_flush(); // Flush buffer
    exit;
} catch (Exception $e) {
    ob_end_clean(); // Clear buffer on error
    error_log("Test script error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}
?>