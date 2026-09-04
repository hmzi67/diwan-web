<?php
/**
 * public_html/api/ landing. Deliberately says nothing useful:
 * enumeration of endpoints is free reconnaissance.
 */
declare(strict_types=1);

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not found']);
