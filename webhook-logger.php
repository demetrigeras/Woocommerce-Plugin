<?php


// Log the request
$log_data = array(
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'raw_body' => file_get_contents('php://input'),
    'parsed_body' => json_decode(file_get_contents('php://input'), true),
    'get_params' => $_GET,
    'post_params' => $_POST
);

// Write to log file
file_put_contents('webhook-debug.log', json_encode($log_data, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

// Also log to error log
error_log('🔍 WEBHOOK DEBUG: ' . json_encode($log_data, JSON_PRETTY_PRINT));

// Return success response
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(array('status' => 'success', 'message' => 'Webhook logged'));
?>
