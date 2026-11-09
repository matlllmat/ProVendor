<?php
// includes/flask_client.php
// Calls the Python server running on this machine.
//
// Uses PHP's built-in HTTP streams instead of curl: curl depends on DLLs that can
// fail to load under Apache after a PHP update, which took down every feature
// that talks to Python. 127.0.0.1 skips Windows' slow failed IPv6 attempt.

const FLASK_BASE_URL = 'http://127.0.0.1:5000';

// POSTs a JSON string. Returns [?string $body, int $status, ?string $error], where
// $error is set only when the server couldn't be reached or didn't answer in time.
function flaskRequest(string $path, string $json, int $timeout): array
{
    $context = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $json,
        'timeout'       => $timeout,
        'ignore_errors' => true,        // still read the body of a 4xx or 5xx reply
    ]]);

    $body = @file_get_contents(FLASK_BASE_URL . $path, false, $context);
    if ($body === false) {
        return [null, 0, error_get_last()['message'] ?? 'No response from the Python server.'];
    }

    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    preg_match('#^HTTP/\S+\s+(\d+)#', $headers[0] ?? '', $match);

    return [$body, (int) ($match[1] ?? 0), null];
}
