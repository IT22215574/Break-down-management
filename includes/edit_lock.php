<?php
require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function edit_lock_response(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$u = current_user();
if (!$u) edit_lock_response(['error' => 'Your sign-in session has expired. Sign in again to edit.'], 401);
if (!in_array($u['role'], ['admin', 'support'], true)) {
    edit_lock_response(['error' => 'You are not allowed to edit items.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    edit_lock_response(['error' => 'Your security token expired. Reload the page and try again.'], 419);
}

$type = (string)($_POST['type'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$token = (string)($_POST['token'] ?? '');
$action = (string)($_POST['action'] ?? '');
if (!in_array($type, ['breakdown', 'sector', 'company', 'technician', 'user'], true) ||
    $id < 1 || !preg_match('/^[a-f0-9]{64}$/', $token) ||
    !in_array($action, ['acquire', 'heartbeat', 'release'], true)) {
    edit_lock_response(['error' => 'Invalid edit lock request.'], 400);
}

if ($type !== 'breakdown' && $u['role'] !== 'admin') {
    edit_lock_response(['error' => 'You cannot edit this item.'], 403);
}
if ($type === 'breakdown') {
    $st = $pdo->prepare('SELECT sector_id FROM breakdowns WHERE id=?');
    $st->execute([$id]);
    $sectorId = $st->fetchColumn();
    if (!$sectorId || !can_access_sector($u, (int)$sectorId)) {
        edit_lock_response(['error' => 'You cannot edit this breakdown.'], 403);
    }
} else {
    $table = ['sector' => 'sectors', 'company' => 'companies', 'technician' => 'technicians', 'user' => 'users'][$type];
    $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE id=?");
    $st->execute([$id]);
    if (!$st->fetchColumn()) edit_lock_response(['error' => 'The item no longer exists.'], 404);
}

if ($action === 'release') {
    release_edit_lock($type, $id, $token);
    edit_lock_response(['released' => true]);
}

if ($action === 'heartbeat') {
    $st = $pdo->prepare('UPDATE edit_locks SET expires_at=DATE_ADD(NOW(), INTERVAL 45 SECOND)
        WHERE resource_type=? AND resource_id=? AND owner_session=? AND token=? AND expires_at > NOW()');
    $st->execute([$type, $id, session_id(), $token]);
    edit_lock_response(['renewed' => $st->rowCount() > 0]);
}

try {
    $pdo->exec('DELETE FROM edit_locks WHERE expires_at < NOW() LIMIT 100');
    $pdo->beginTransaction();
    $pdo->prepare("INSERT IGNORE INTO edit_locks (resource_type,resource_id,owner_session,owner_name,token,expires_at)
        VALUES (?,?,?,?,?,DATE_ADD(NOW(), INTERVAL -1 SECOND))")
        ->execute([$type, $id, session_id(), $u['name'], str_repeat('0', 64)]);
    $st = $pdo->prepare('SELECT owner_session,owner_name,token,expires_at > NOW() AS active
        FROM edit_locks WHERE resource_type=? AND resource_id=? FOR UPDATE');
    $st->execute([$type, $id]);
    $lock = $st->fetch();
    if (!$lock || ($lock['active'] && $lock['owner_session'] !== session_id())) {
        $pdo->commit();
        edit_lock_response(['acquired' => false, 'owner' => $lock['owner_name'] ?? 'another user']);
    }
    $existingToken = $lock['owner_session'] === session_id() && $lock['active'] ? $lock['token'] : $token;
    $pdo->prepare('UPDATE edit_locks SET owner_session=?,owner_name=?,token=?,expires_at=DATE_ADD(NOW(), INTERVAL 45 SECOND)
        WHERE resource_type=? AND resource_id=?')->execute([
        session_id(), $u['name'], $existingToken, $type, $id,
    ]);
    $pdo->commit();
    edit_lock_response(['acquired' => true, 'token' => $existingToken]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Edit lock request failed: ' . $e->getMessage());
    edit_lock_response(['error' => 'Could not coordinate this edit.'], 500);
}
