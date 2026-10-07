<?php
require __DIR__ . '/../includes/bootstrap.php';
require_role('admin');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{}'); }
csrf_check();
echo json_encode(['id' => ($_POST['type'] ?? '') === 'user' ? generate_user_login_id() : generate_sector_login_id()]);
