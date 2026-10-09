<?php
require __DIR__ . '/includes/bootstrap.php';
$u = require_role('admin', 'support');

$breakdownId = (int)($_POST['breakdown_id'] ?? $_GET['breakdown_id'] ?? 0);
$sectorId = (int)($_POST['sector_id'] ?? $_GET['sector_id'] ?? 0);
$quoteCompanyId = (int)($_POST['company_id'] ?? $_GET['company_id'] ?? 0);
$editId = (int)($_POST['quotation_id'] ?? $_GET['quotation_id'] ?? 0);
$editQuote = null;
if ($editId) {
    $est = $pdo->prepare('SELECT * FROM quotations WHERE id=?');
    $est->execute([$editId]);
    $editQuote = $est->fetch();
    if (!$editQuote || !can_access_sector($u, (int)$editQuote['sector_id'])) {
        http_response_code(404);
        exit('Not found');
    }
    $breakdownId = (int)($editQuote['breakdown_id'] ?? 0);
    $sectorId = (int)$editQuote['sector_id'];
    $quoteCompanyId = (int)($editQuote['company_id'] ?? 0);
}
$quote = [
    'quote_date' => $editQuote['quote_date'] ?? date('Y-m-d'),
    'sector_name' => '',
    'sector_address' => '',
    'contact_name' => trim((string)($_POST['contact_name'] ?? $_GET['contact_name'] ?? ($editQuote['contact_name'] ?? ''))),
    'contact_phone' => trim((string)($_POST['contact_phone'] ?? $_GET['contact_phone'] ?? ($editQuote['contact_phone'] ?? ''))),
    'machine_model' => trim((string)($_POST['machine_model'] ?? $_GET['machine_model'] ?? '')),
    'breakdown' => trim((string)($_POST['breakdown'] ?? $_GET['breakdown'] ?? ($editQuote['breakdown'] ?? ''))),
    'remark' => $editQuote['remark'] ?? '',
];

if ($breakdownId && !$editQuote) {
    $st = $pdo->prepare('SELECT b.*, s.name AS sector_name, s.address AS sector_address
        FROM breakdowns b JOIN sectors s ON s.id=b.sector_id WHERE b.id=?');
    $st->execute([$breakdownId]);
    $row = $st->fetch();
    if (!$row || !can_access_sector($u, (int)$row['sector_id']) || empty($row['technician_required'])) {
        http_response_code(404);
        exit('Not found');
    }
    if (!$quoteCompanyId && !empty($row['company_id'])) $quoteCompanyId = (int)$row['company_id'];
    $quote['sector_name'] = $row['sector_name'];
    $quote['sector_address'] = $row['sector_address'] ?? '';
    $quote['contact_name'] = trim((string)($_POST['contact_name'] ?? $_GET['contact_name'] ?? $row['client_name']));
    $quote['contact_phone'] = trim((string)($_POST['contact_phone'] ?? $_GET['contact_phone'] ?? ($row['contact_phone'] ?? '')));
    $quote['machine_model'] = trim((string)($_POST['machine_model'] ?? $_GET['machine_model'] ?? $row['system_name']));
    $quote['breakdown'] = trim((string)($_POST['breakdown'] ?? $_GET['breakdown'] ?? ($row['description'] ?? '')));
} else {
    foreach (accessible_sectors($u) as $sector) {
        if ((int)$sector['id'] === $sectorId) {
            $quote['sector_name'] = $sector['name'];
            $quote['sector_address'] = $sector['address'] ?? '';
            break;
        }
    }
    if ($quote['sector_name'] === '') {
        http_response_code(404);
        exit('Not found');
    }
}

$companyOptions = $pdo->query('SELECT id,name,job_tag FROM companies ORDER BY name')->fetchAll();
$machines = $pdo->query('SELECT id,category,brand,model,model_code FROM machines ORDER BY category,brand,model,model_code')->fetchAll();
$accessories = $pdo->query('SELECT id,name,brand,price FROM accessories ORDER BY name')->fetchAll();
$accessoryBrandTags = $pdo->query('SELECT name FROM accessory_brand_tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$machineRows = [];
$rowsJson = (string)($_POST['machine_rows'] ?? $_GET['machine_rows'] ?? '');
if ($rowsJson !== '') {
    $decodedRows = json_decode($rowsJson, true);
    if (is_array($decodedRows)) {
        foreach ($decodedRows as $machineRow) {
            if (!is_array($machineRow)) continue;
            $machineRows[] = [
                'id' => max(0, (int)($machineRow['id'] ?? 0)),
                'category' => trim((string)($machineRow['category'] ?? '')),
                'brand' => trim((string)($machineRow['brand'] ?? '')),
                'model' => trim((string)($machineRow['model'] ?? '')),
                'model_code' => trim((string)($machineRow['model_code'] ?? '')),
            ];
        }
    } else {
        flash('Could not restore the quotation machine list.', 'error');
    }
}
if (!$machineRows && $editQuote) {
    foreach (json_decode($editQuote['machines_json'], true) ?: [] as $m) {
        $machineRows[] = ['id' => 0, 'category' => (string)($m['category'] ?? ''), 'brand' => (string)($m['brand'] ?? ''), 'model' => (string)($m['model'] ?? ''), 'model_code' => (string)($m['model_code'] ?? '')];
    }
}
if (!$machineRows) {
    $matchedMachine = null;
    foreach ($machines as $machine) {
        if ($quote['machine_model'] !== '' && (
            strcasecmp($machine['model'], $quote['machine_model']) === 0
            || ($machine['model_code'] !== '' && strcasecmp($machine['model_code'], $quote['machine_model']) === 0)
        )) {
            $matchedMachine = $machine;
            break;
        }
    }
    $machineRows[] = $matchedMachine
        ? ['id' => (int)$matchedMachine['id'], 'category' => $matchedMachine['category'], 'brand' => $matchedMachine['brand'], 'model' => $matchedMachine['model'], 'model_code' => $matchedMachine['model_code']]
        : ['id' => 0, 'category' => '', 'brand' => '', 'model' => $quote['machine_model'], 'model_code' => ''];
}

$accessoryRows = [];
$accessoryRowsJson = (string)($_POST['accessory_rows'] ?? $_GET['accessory_rows'] ?? '');
if ($accessoryRowsJson !== '') {
    $decodedAccessoryRows = json_decode($accessoryRowsJson, true);
    if (is_array($decodedAccessoryRows)) {
        foreach ($decodedAccessoryRows as $accessoryRow) {
            if (!is_array($accessoryRow)) continue;
            $accessoryRows[] = [
                'id' => max(0, (int)($accessoryRow['id'] ?? 0)),
                'name' => trim((string)($accessoryRow['name'] ?? '')),
                'brand' => trim((string)($accessoryRow['brand'] ?? '')),
                'price' => trim((string)($accessoryRow['price'] ?? '')),
                'quantity' => trim((string)($accessoryRow['quantity'] ?? '')),
                'discount_price' => trim((string)($accessoryRow['discount_price'] ?? '')),
            ];
        }
    } else {
        flash('Could not restore the quotation accessory list.', 'error');
    }
}

if (!$accessoryRows && $editQuote) {
    foreach (json_decode($editQuote['accessories_json'], true) ?: [] as $a) {
        $accessoryRows[] = ['id' => 0, 'name' => (string)($a['name'] ?? ''), 'brand' => (string)($a['brand'] ?? ''), 'price' => (string)($a['price'] ?? ''),
            'quantity' => (string)($a['quantity'] ?? ''), 'discount_price' => ($a['discount_price'] ?? null) === null ? '' : (string)$a['discount_price']];
    }
}

$returnedRows = [];
$returnedRowsJson = (string)($_POST['returned_rows'] ?? $_GET['returned_rows'] ?? '');
if ($returnedRowsJson !== '') {
    $decodedReturned = json_decode($returnedRowsJson, true);
    foreach (is_array($decodedReturned) ? $decodedReturned : [] as $ret) {
        if (!is_array($ret)) continue;
        $returnedRows[] = ['name' => trim((string)($ret['name'] ?? '')), 'brand' => trim((string)($ret['brand'] ?? '')),
            'quantity' => trim((string)($ret['quantity'] ?? '')), 'note' => trim((string)($ret['note'] ?? ''))];
    }
} elseif ($editQuote) {
    foreach (json_decode((string)($editQuote['returned_json'] ?? ''), true) ?: [] as $ret) {
        $returnedRows[] = ['name' => (string)($ret['name'] ?? ''), 'brand' => (string)($ret['brand'] ?? ''), 'quantity' => (string)($ret['quantity'] ?? ''), 'note' => (string)($ret['note'] ?? '')];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_quotation') {
    $quoteSectorId = $editQuote ? (int)$editQuote['sector_id'] : ($breakdownId ? (int)$row['sector_id'] : $sectorId);
    try {
        if ($quote['contact_phone'] !== '' && !preg_match('/^[0-9]{10}$/', $quote['contact_phone'])) {
            throw new InvalidArgumentException('Phone number must be exactly 10 digits.');
        }
        $quoteDate = (string)($_POST['quote_date'] ?? '');
        $dateObj = DateTime::createFromFormat('Y-m-d', $quoteDate);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $quoteDate) throw new InvalidArgumentException('Enter a valid date.');
        $savedAccessories = [];
        $total = 0.0;
        foreach ($accessoryRows as $i => $a) {
            if ($a['name'] === '' && $a['price'] === '') continue;
            $n = $i + 1;
            if ($a['name'] === '' || !is_numeric($a['price']) || (float)$a['price'] < 0) throw new InvalidArgumentException("Accessory $n: enter a name and a valid price.");
            $price = round((float)$a['price'], 2);
            $qty = $a['quantity'] === '' ? 1 : (ctype_digit($a['quantity']) ? (int)$a['quantity'] : -1);
            if ($qty < 0) throw new InvalidArgumentException("Accessory $n: quantity must be a whole number (0 or more).");
            $discount = null;
            if ($a['discount_price'] !== '') {
                if (!is_numeric($a['discount_price']) || (float)$a['discount_price'] < 0) throw new InvalidArgumentException("Accessory $n: enter a valid discounted price.");
                $discount = round((float)$a['discount_price'], 2);
                if ($discount > $price) throw new InvalidArgumentException("Accessory $n: the discounted price cannot be higher than the original price.");
            }
            $line = ($discount ?? $price) * $qty;
            $total += $line;
            $savedAccessories[] = ['name' => $a['name'], 'brand' => $a['brand'], 'price' => $price, 'quantity' => $qty, 'discount_price' => $discount, 'line_total' => round($line, 2)];
        }
        $savedMachines = array_values(array_filter(array_map(fn($m) => [
            'category' => $m['category'], 'brand' => $m['brand'], 'model' => $m['model'], 'model_code' => $m['model_code'],
        ], $machineRows), fn($m) => $m['category'] . $m['brand'] . $m['model'] . $m['model_code'] !== ''));
        $savedReturned = [];
        foreach ($returnedRows as $i => $ret) {
            if ($ret['name'] === '' && $ret['brand'] === '' && $ret['quantity'] === '' && $ret['note'] === '') continue;
            $n = $i + 1;
            if ($ret['name'] === '' || mb_strlen($ret['name']) > 120 || mb_strlen($ret['brand']) > 120 || mb_strlen($ret['note']) > 255) {
                throw new InvalidArgumentException("Returned item $n: enter a name (max 120 characters) and a note of up to 255 characters.");
            }
            if ($ret['quantity'] !== '' && !ctype_digit($ret['quantity'])) throw new InvalidArgumentException("Returned item $n: quantity must be a whole number.");
            $savedReturned[] = ['name' => $ret['name'], 'brand' => $ret['brand'], 'quantity' => $ret['quantity'] === '' ? 1 : (int)$ret['quantity'], 'note' => $ret['note']];
        }
        if (!$savedMachines && !$savedAccessories && !$savedReturned) throw new InvalidArgumentException('Add at least one machine, accessory or returned item before saving.');
        $remark = trim((string)($_POST['remark'] ?? ''));
        $snapshot = ['quote_date' => $quoteDate, 'contact_name' => mb_substr($quote['contact_name'], 0, 190), 'contact_phone' => mb_substr($quote['contact_phone'], 0, 32),
            'breakdown' => $quote['breakdown'], 'remark' => $remark, 'machines_json' => json_encode($savedMachines, JSON_THROW_ON_ERROR),
            'accessories_json' => json_encode($savedAccessories, JSON_THROW_ON_ERROR), 'total' => round($total, 2),
            'returned_json' => json_encode($savedReturned, JSON_THROW_ON_ERROR)];
        $jobNo = null;
        // Quotations opened from the unsaved breakdown form have no link yet; match the breakdown by sector, company, contact phone and system.
        if (!$breakdownId && !$editQuote && $quoteCompanyId && $quote['contact_phone'] !== '') {
            $mst = $pdo->prepare('SELECT id FROM breakdowns WHERE sector_id=? AND company_id=? AND contact_phone=? AND technician_required=1 AND system_name=?
                AND NOT EXISTS (SELECT 1 FROM quotations q WHERE q.breakdown_id=breakdowns.id) ORDER BY id DESC LIMIT 1');
            $mst->execute([$quoteSectorId, $quoteCompanyId, $quote['contact_phone'], $quote['machine_model']]);
            $breakdownId = (int)$mst->fetchColumn();
        }
        $syncProblem = function () use ($pdo, $breakdownId, $quote) {
            if ($breakdownId && $quote['breakdown'] !== '') $pdo->prepare('UPDATE breakdowns SET description=? WHERE id=?')->execute([$quote['breakdown'], $breakdownId]);
        };
        if ($editQuote) {
            $pdo->beginTransaction();
            quotation_ensure_baseline($editQuote);
            quotation_add_revision($editId, $snapshot, (int)$u['id']);
            $pdo->prepare('UPDATE quotations SET quote_date=?,contact_name=?,contact_phone=?,breakdown=?,remark=?,machines_json=?,accessories_json=?,total=?,returned_json=? WHERE id=?')
                ->execute([$snapshot['quote_date'], $snapshot['contact_name'], $snapshot['contact_phone'], $snapshot['breakdown'], $snapshot['remark'], $snapshot['machines_json'], $snapshot['accessories_json'], $snapshot['total'], $snapshot['returned_json'], $editId]);
            $syncProblem();
            $pdo->commit();
            flash('Quotation updated. The previous version is kept below.');
            redirect('quotation_view.php?id=' . $editId);
        }
        if (!$quoteCompanyId && $pdo->query("SELECT 1 FROM companies WHERE job_tag IS NOT NULL AND job_tag <> '' LIMIT 1")->fetchColumn()) {
            throw new InvalidArgumentException('Select a company so the Job ID can be generated.');
        }
        $pdo->beginTransaction();
        if ($quoteCompanyId) {
            $cst = $pdo->prepare('SELECT job_tag, last_job_no FROM companies WHERE id=? FOR UPDATE');
            $cst->execute([$quoteCompanyId]);
            $co = $cst->fetch();
            if (!$co) throw new InvalidArgumentException('Select a valid company.');
            if ($co['job_tag'] !== null && $co['job_tag'] !== '') {
                $next = (int)$co['last_job_no'] + 1;
                $jobNo = $co['job_tag'] . str_pad((string)$next, 2, '0', STR_PAD_LEFT);
                $pdo->prepare('UPDATE companies SET last_job_no=? WHERE id=?')->execute([$next, $quoteCompanyId]);
            }
        }
        $pdo->prepare('INSERT INTO quotations (breakdown_id,sector_id,created_by,quote_date,contact_name,contact_phone,breakdown,remark,machines_json,accessories_json,total,company_id,job_no,returned_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$breakdownId ?: null, $quoteSectorId, (int)$u['id'], $snapshot['quote_date'], $snapshot['contact_name'], $snapshot['contact_phone'], $snapshot['breakdown'], $snapshot['remark'],
                $snapshot['machines_json'], $snapshot['accessories_json'], $snapshot['total'], $quoteCompanyId ?: null, $jobNo, $snapshot['returned_json']]);
        quotation_add_revision((int)$pdo->lastInsertId(), $snapshot, (int)$u['id']);
        $syncProblem();
        $pdo->commit();
        flash($jobNo ? "Quotation saved. Job ID: $jobNo" : 'Quotation saved.');
        redirect(($u['role'] === 'admin' ? 'admin/records.php' : 'support/records.php') . '?sector=' . $quoteSectorId . '&tab=quotations');
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getMessage(), 'error');
        $quote['quote_date'] = (string)($_POST['quote_date'] ?? $quote['quote_date']);
        $quote['remark'] = trim((string)($_POST['remark'] ?? ''));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['create_machine', 'create_accessory'], true)) {
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }
    $rowIndex = (int)($_POST['row_index'] ?? -1);
    try {
        if (($_POST['action'] ?? '') === 'create_machine') {
            if (!isset($machineRows[$rowIndex])) throw new InvalidArgumentException('Select a valid machine row.');
            $machineForm = $machineRows[$rowIndex];
            foreach (['category', 'brand', 'model'] as $field) {
                if ($machineForm[$field] === '' || mb_strlen($machineForm[$field]) > 120) {
                    throw new InvalidArgumentException('Enter a category, brand, and model (maximum 120 characters each).');
                }
            }
            if (mb_strlen($machineForm['model_code']) > 120) {
                throw new InvalidArgumentException('Model code must be 120 characters or fewer.');
            }

            $findMachine = $pdo->prepare('SELECT id FROM machines WHERE category=? AND brand=? AND model=? AND model_code=? LIMIT 1');
            $findMachine->execute([$machineForm['category'], $machineForm['brand'], $machineForm['model'], $machineForm['model_code']]);
            $machineId = (int)($findMachine->fetchColumn() ?: 0);
            if (!$machineId) {
                $pdo->beginTransaction();
                $addTag = $pdo->prepare('INSERT IGNORE INTO machine_tags (kind,name) VALUES (?,?)');
                foreach (['category', 'brand', 'model'] as $field) $addTag->execute([$field, $machineForm[$field]]);
                $pdo->prepare('INSERT INTO machines (category,brand,model,model_code) VALUES (?,?,?,?)')
                    ->execute([$machineForm['category'], $machineForm['brand'], $machineForm['model'], $machineForm['model_code']]);
                $machineId = (int)$pdo->lastInsertId();
                $pdo->commit();
            }
            $machineRows[$rowIndex]['id'] = $machineId;
            $notice = 'Machine saved to inventory and selected for this quotation.';
        } else {
            if (!isset($accessoryRows[$rowIndex])) throw new InvalidArgumentException('Select a valid accessory row.');
            $accessoryForm = $accessoryRows[$rowIndex];
            $brand = trim($accessoryForm['brand']);
            $price = $accessoryForm['price'];
            if ($accessoryForm['name'] === '' || mb_strlen($accessoryForm['name']) > 120
                || mb_strlen($brand) > 120
                || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $price)) {
                throw new InvalidArgumentException('Enter an accessory name, an optional brand (max 120 characters), and a valid non-negative price.');
            }
            $pdo->beginTransaction();
            if ($brand !== '') {
                $pdo->prepare('INSERT IGNORE INTO accessory_brand_tags (name) VALUES (?)')->execute([$brand]);
            }
            $findAccessory = $pdo->prepare('SELECT id FROM accessories WHERE name=? AND price=? AND (brand=? OR (brand IS NULL AND ? IS NULL)) LIMIT 1');
            $brandValue = $brand === '' ? null : $brand;
            $findAccessory->execute([$accessoryForm['name'], $price, $brandValue, $brandValue]);
            $accessoryId = (int)($findAccessory->fetchColumn() ?: 0);
            if (!$accessoryId) {
                $pdo->prepare('INSERT INTO accessories (name,brand,price) VALUES (?,?,?)')->execute([$accessoryForm['name'], $brandValue, $price]);
                $accessoryId = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
            $accessoryRows[$rowIndex]['id'] = $accessoryId;
            $notice = 'Accessory saved to inventory and selected for this quotation.';
        }
        $returnParams = [
            'breakdown_id' => $breakdownId,
            'sector_id' => $sectorId,
            'company_id' => $quoteCompanyId,
            'quotation_id' => $editId,
            'contact_name' => $quote['contact_name'],
            'contact_phone' => $quote['contact_phone'],
            'machine_model' => $quote['machine_model'],
            'breakdown' => $quote['breakdown'],
            'machine_rows' => json_encode($machineRows, JSON_THROW_ON_ERROR),
            'accessory_rows' => json_encode($accessoryRows, JSON_THROW_ON_ERROR),
            'returned_rows' => json_encode($returnedRows, JSON_THROW_ON_ERROR),
        ];
        flash($notice);
        redirect('quotation.php?' . http_build_query($returnParams));
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('Could not save the item to inventory.', 'error');
    }
}

page_header('Make Quotations');
?>
<div class="max-w-3xl mx-auto bg-white rounded shadow p-6">
  <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
    <div><h1 class="text-2xl font-bold">Quotation<?php if ($editQuote): ?> <span class="text-slate-500 text-lg">— editing Job ID <?= e($editQuote['job_no'] ?: '#' . $editId) ?></span><?php endif; ?></h1></div>
    <?php if ($editQuote): ?><a class="text-sm text-blue-600 print:hidden" href="<?= url('quotation_view.php?id=' . $editId) ?>">&larr; Back to quotation</a><?php elseif ($breakdownId): ?><a class="text-sm text-blue-600 print:hidden" href="<?= url('breakdown.php?id=' . $breakdownId) ?>">&larr; Back to breakdown</a><?php endif; ?>
  </div>
  <div class="grid md:grid-cols-2 gap-4 print:gap-2">
    <label class="text-sm print:flex print:items-center print:gap-1">Date<span class="hidden print:inline">:</span><input id="quote-date" type="date" value="<?= e($quote['quote_date']) ?>" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0 print:mt-0 print:py-0 print:w-auto"></label>
    <label class="text-sm print:hidden">Company (for Job ID)
      <select id="quote-company" <?= $editQuote ? 'disabled' : 'required' ?> class="mt-1 w-full border rounded px-3 py-2">
        <option value="">— Select company —</option>
        <?php foreach ($companyOptions as $co): ?><option value="<?= (int)$co['id'] ?>" <?= $quoteCompanyId === (int)$co['id'] ? 'selected' : '' ?>><?= e($co['name']) ?><?= $co['job_tag'] ? ' (' . e($co['job_tag']) . ')' : '' ?></option><?php endforeach; ?>
      </select></label>
    <label class="text-sm print:hidden">Sector name<input value="<?= e($quote['sector_name']) ?>" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"></label>
    <label class="text-sm md:col-span-2 print:hidden">Sector address<textarea rows="2" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"><?= e($quote['sector_address']) ?></textarea></label>
    <label class="text-sm print:flex print:items-center print:gap-1">Contacted by<span class="print:hidden"> (client side)</span><span class="hidden print:inline">:</span><input id="quote-contact-name" value="<?= e($quote['contact_name']) ?>" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0 print:mt-0 print:py-0 print:w-auto"></label>
    <label class="text-sm print:flex print:items-center print:gap-1">Phone number<span class="hidden print:inline">:</span><input id="quote-contact-phone" type="tel" minlength="10" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" title="Enter exactly 10 digits" value="<?= e($quote['contact_phone']) ?>" class="mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0 print:mt-0 print:py-0 print:w-auto"></label>
    <section class="md:col-span-2">
      <div class="hidden print:block mb-2"><h2 class="font-semibold">Machines</h2></div>
      <div class="flex flex-wrap items-center gap-2 mb-2 print:hidden">
        <h2 class="font-semibold">Machines</h2>
      </div>
      <div id="machine-rows" class="space-y-3"></div>
      <div class="flex justify-end mt-3 print:hidden">
        <button type="button" id="add-machine-row" class="border border-slate-400 rounded px-3 py-1.5 text-sm">+ Add another machine</button>
      </div>
    </section>
    <section class="md:col-span-2">
      <div class="hidden print:block mb-2"><h2 class="font-semibold">Accessories</h2></div>
      <div class="flex flex-wrap items-center gap-2 mb-2 print:hidden">
        <h2 class="font-semibold">Accessories</h2>
      </div>
      <div id="accessory-rows" class="space-y-3 print:hidden"></div>
      <table id="accessory-print-table" class="hidden print:table w-full text-sm border-collapse mt-1">
        <thead><tr class="border-b border-t">
          <th class="text-left py-1 pr-2">Name</th><th class="text-left py-1 pr-2">Brand</th><th class="text-right py-1 pr-2">Price</th><th class="text-right py-1 pr-2">Qty</th><th class="text-right py-1 pr-2">Discounted price</th><th class="text-right py-1">Line total</th>
        </tr></thead>
        <tbody></tbody>
      </table>
      <div class="flex justify-end mt-3 font-bold">Total: <span id="accessory-total" class="ml-2">0.00</span></div>
      <div class="flex justify-end mt-3 print:hidden">
        <button type="button" id="add-accessory-row" class="border border-slate-400 rounded px-3 py-1.5 text-sm">+ Add another accessory</button>
      </div>
    </section>
    <section class="md:col-span-2">
      <div class="flex flex-wrap items-center gap-2 mb-2"><h2 class="font-semibold">Items received from customer</h2><span class="text-xs text-slate-500 print:hidden">Returned or provided by the customer (no price)</span></div>
      <div id="returned-rows" class="space-y-3 print:hidden"></div>
      <table id="returned-print-table" class="hidden print:table w-full text-sm border-collapse mt-1">
        <thead><tr class="border-b border-t"><th class="text-left py-1 pr-2">Name</th><th class="text-left py-1 pr-2">Brand</th><th class="text-right py-1 pr-2">Qty</th><th class="text-left py-1">Note</th></tr></thead>
        <tbody></tbody>
      </table>
      <div class="flex justify-end mt-3 print:hidden">
        <button type="button" id="add-returned-row" class="border border-slate-400 rounded px-3 py-1.5 text-sm">+ Add received item</button>
      </div>
    </section>
    <label class="text-sm md:col-span-2 font-bold">Breakdown<textarea id="quote-breakdown" rows="4" class="font-normal mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"><?= e($quote['breakdown']) ?></textarea></label>
    <label class="text-sm md:col-span-2 font-bold">Remark<textarea id="quote-remark" rows="3" class="font-normal mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"><?= e($quote['remark']) ?></textarea></label>
  </div>
  <section class="quote-signatures mt-10 grid grid-cols-2 gap-8 text-sm">
    <div>
      <div class="signature-line"></div>
      <div>Customer Signature</div>
      <p class="mt-4"><strong>Please Note:</strong> Clear the goods within 3 months.</p>
    </div>
    <div>
      <div class="signature-line"></div>
      <div>Authorized Signature</div>
    </div>
  </section>
  <div class="mt-6 print:hidden flex flex-wrap gap-2"><button type="button" id="save-quote" class="bg-green-600 hover:bg-green-700 text-white rounded px-4 py-2"><?= $editQuote ? 'Save changes' : 'Save quotation' ?></button><button type="button" id="print-quote" class="bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2">Print / Save as PDF</button></div>
</div>
<template id="machine-row-template">
  <div class="machine-row grid gap-3 md:grid-cols-2 border rounded p-3">
    <div class="md:col-span-2 flex justify-between items-center print:hidden">
      <h3 class="font-medium">Machine <span class="machine-row-number"></span></h3>
      <button type="button" class="remove-machine-row text-sm text-red-600">Remove</button>
    </div>
    <label class="text-sm print:hidden">Select from inventory
      <input type="search" class="machine-inventory-search mt-1 w-full border rounded px-3 py-2" list="machine-inventory-list" autocomplete="off" placeholder="Type to search or pick from the list">
      <select class="machine-inventory-select hidden">
        <option value="">— Choose a machine —</option>
        <?php foreach ($machines as $machine): ?>
          <option value="<?= (int)$machine['id'] ?>" data-category="<?= e($machine['category']) ?>" data-brand="<?= e($machine['brand']) ?>" data-model="<?= e($machine['model']) ?>" data-model-code="<?= e($machine['model_code']) ?>">
            <?= e($machine['category'] . ' · ' . $machine['brand'] . ' · ' . $machine['model'] . ($machine['model_code'] !== '' ? ' · ' . $machine['model_code'] : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="machine-print-summary hidden print:grid print:grid-cols-4 print:gap-4 md:col-span-2"></div>
    <label class="text-sm print:hidden">Category<input data-field="category" maxlength="120" class="machine-field mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"></label>
    <label class="text-sm print:hidden">Brand<input data-field="brand" maxlength="120" class="machine-field mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"></label>
    <label class="text-sm print:hidden">Model<input data-field="model" maxlength="120" class="machine-field mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"></label>
    <label class="text-sm print:hidden">Model code<input data-field="model_code" maxlength="120" class="machine-field mt-1 w-full border rounded px-3 py-2 print:border-0 print:px-0"></label>
    <?php if ($u['role'] === 'admin'): ?><button type="button" class="save-machine-to-inventory md:col-span-2 w-fit bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-1.5 text-sm print:hidden">Add this machine to inventory</button><?php endif; ?>
  </div>
</template>
<template id="accessory-row-template">
  <div class="accessory-row grid gap-3 md:grid-cols-2 border rounded p-3">
    <div class="md:col-span-2 flex justify-between items-center print:hidden">
      <h3 class="font-medium">Accessory <span class="accessory-row-number"></span></h3>
      <button type="button" class="remove-accessory-row text-sm text-red-600">Remove</button>
    </div>
    <label class="text-sm print:hidden">Select from inventory
      <input type="search" class="accessory-inventory-search mt-1 w-full border rounded px-3 py-2" list="accessory-inventory-list" autocomplete="off" placeholder="Type to search or pick from the list">
      <select class="accessory-inventory-select hidden">
        <option value="">— Choose an accessory —</option>
        <?php foreach ($accessories as $accessory): ?>
          <option value="<?= (int)$accessory['id'] ?>" data-name="<?= e($accessory['name']) ?>" data-brand="<?= e($accessory['brand'] ?? '') ?>" data-price="<?= e($accessory['price']) ?>">
            <?= e($accessory['name'] . ($accessory['brand'] ? ' · ' . $accessory['brand'] : '') . ' · ' . number_format((float)$accessory['price'], 2)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="accessory-print-summary hidden md:col-span-2"></div>
    <label class="text-sm print:hidden">Name<input data-field="name" maxlength="120" class="accessory-field mt-1 w-full border rounded px-3 py-2"></label>
    <label class="text-sm print:hidden">Brand <span class="text-slate-400">(optional)</span><input data-field="brand" list="accessory-quotation-brands" maxlength="120" autocomplete="off" placeholder="Type or select a brand" class="accessory-field mt-1 w-full border rounded px-3 py-2"></label>
    <label class="text-sm print:hidden">Price<input data-field="price" type="number" min="0" max="99999999.99" step="0.01" class="accessory-field mt-1 w-full border rounded px-3 py-2"></label>
    <label class="text-sm print:hidden">Quantity<input data-field="quantity" type="number" min="0" step="1" placeholder="1" class="accessory-extra-field mt-1 w-full border rounded px-3 py-2"></label>
    <label class="text-sm print:hidden">Discounted price <span class="text-slate-400">(optional)</span><input data-field="discount_price" type="number" max="99999999.99" step="0.01" class="accessory-extra-field mt-1 w-full border rounded px-3 py-2"></label>
    <label class="text-sm print:hidden">Discount % <span class="text-slate-400">(auto-calculated)</span><input data-field="discount_percent" type="number" min="0" max="100" step="0.01" class="accessory-extra-field mt-1 w-full border rounded px-3 py-2"></label>
    <p class="accessory-error md:col-span-2 text-sm text-red-600 hidden print:hidden"></p>
    <?php if ($u['role'] === 'admin'): ?><button type="button" class="save-accessory-to-inventory md:col-span-2 w-fit bg-blue-600 hover:bg-blue-700 text-white rounded px-3 py-1.5 text-sm print:hidden">Add this accessory to inventory</button><?php endif; ?>
  </div>
</template>
<template id="returned-row-template">
  <div class="returned-row grid gap-3 md:grid-cols-2 border rounded p-3">
    <div class="md:col-span-2 flex justify-between items-center">
      <h3 class="font-medium">Received item <span class="returned-row-number"></span></h3>
      <button type="button" class="remove-returned-row text-sm text-red-600">Remove</button>
    </div>
    <label class="text-sm md:col-span-2">Select from accessories
      <input type="search" class="returned-pick mt-1 w-full border rounded px-3 py-2" list="returned-accessory-list" autocomplete="off" placeholder="Type to search or pick from the accessories list">
    </label>
    <label class="text-sm">Name<input data-field="name" maxlength="120" class="mt-1 w-full border rounded px-3 py-2"></label>
    <label class="text-sm">Brand <span class="text-slate-400">(optional)</span><input data-field="brand" list="accessory-quotation-brands" maxlength="120" autocomplete="off" class="mt-1 w-full border rounded px-3 py-2"></label>
    <label class="text-sm">Quantity<input data-field="quantity" type="number" min="1" step="1" placeholder="1" class="mt-1 w-full border rounded px-3 py-2"></label>
    <label class="text-sm">Note <span class="text-slate-400">(optional, e.g. condition)</span><input data-field="note" maxlength="255" class="mt-1 w-full border rounded px-3 py-2"></label>
  </div>
</template>
<datalist id="returned-accessory-list"><?php foreach ($accessories as $accessory): ?><option value="<?= e($accessory['name'] . ($accessory['brand'] ? ' · ' . $accessory['brand'] : '')) ?>"></option><?php endforeach; ?></datalist>
<datalist id="accessory-quotation-brands"><?php foreach ($accessoryBrandTags as $brandTag): ?><option value="<?= e($brandTag) ?>"></option><?php endforeach; ?></datalist>
<script>
(function () {
  const box = document.getElementById('machine-rows');
  const template = document.getElementById('machine-row-template');
  const initialRows = <?= json_encode($machineRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const accessoryBox = document.getElementById('accessory-rows');
  const accessoryTemplate = document.getElementById('accessory-row-template');
  const initialAccessoryRows = <?= json_encode($accessoryRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const returnedBox = document.getElementById('returned-rows');
  const returnedTemplate = document.getElementById('returned-row-template');
  const initialReturnedRows = <?= json_encode($returnedRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const accessoryCatalog = <?= json_encode(array_map(fn($a) => ['label' => $a['name'] . ($a['brand'] ? ' · ' . $a['brand'] : ''), 'name' => $a['name'], 'brand' => $a['brand'] ?? ''], $accessories), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const csrf = <?= json_encode(csrf_token()) ?>;
  const breakdownId = <?= $breakdownId ?>;
  const sectorId = <?= $sectorId ?>;

  function currentRows() {
    return [...box.querySelectorAll('.machine-row')].map(row => ({
      id: Number(row.dataset.machineId || 0),
      category: row.querySelector('[data-field="category"]').value.trim(),
      brand: row.querySelector('[data-field="brand"]').value.trim(),
      model: row.querySelector('[data-field="model"]').value.trim(),
      model_code: row.querySelector('[data-field="model_code"]').value.trim()
    }));
  }

  function labelOf(option) { return option.textContent.replace(/\s+/g, ' ').trim(); }
  function setupInventorySearch(row, kind) {
    const select = row.querySelector('.' + kind + '-inventory-select');
    const search = row.querySelector('.' + kind + '-inventory-search');
    const listId = kind + '-inventory-list';
    let list = document.getElementById(listId);
    if (!list) {
      list = document.createElement('datalist');
      list.id = listId;
      [...select.options].filter(o => o.value).forEach(o => {
        const item = document.createElement('option');
        item.value = labelOf(o);
        list.appendChild(item);
      });
      document.body.appendChild(list);
    }
    const sync = () => {
      const option = select.selectedOptions[0];
      search.value = option && option.value ? labelOf(option) : '';
    };
    search.addEventListener('input', () => {
      const match = [...select.options].find(o => o.value && labelOf(o).toLowerCase() === search.value.trim().toLowerCase());
      if (match) { select.value = match.value; select.dispatchEvent(new Event('change')); }
    });
    select.addEventListener('change', sync);
    return sync;
  }

  function currentAccessoryRows() {
    return [...accessoryBox.querySelectorAll('.accessory-row')].map(row => ({
      id: Number(row.dataset.accessoryId || 0),
      name: row.querySelector('[data-field="name"]').value.trim(),
      brand: row.querySelector('[data-field="brand"]').value.trim(),
      price: row.querySelector('[data-field="price"]').value.trim(),
      quantity: row.querySelector('[data-field="quantity"]').value.trim(),
      discount_price: row.querySelector('[data-field="discount_price"]').value.trim()
    }));
  }

  function currentReturnedRows() {
    return [...returnedBox.querySelectorAll('.returned-row')].map(row => ({
      name: row.querySelector('[data-field="name"]').value.trim(),
      brand: row.querySelector('[data-field="brand"]').value.trim(),
      quantity: row.querySelector('[data-field="quantity"]').value.trim(),
      note: row.querySelector('[data-field="note"]').value.trim()
    }));
  }

  function refreshReturnedRows() {
    const tbody = document.querySelector('#returned-print-table tbody');
    tbody.replaceChildren();
    [...returnedBox.querySelectorAll('.returned-row')].forEach((row, index) => {
      row.querySelector('.returned-row-number').textContent = '#' + (index + 1);
      const val = field => row.querySelector('[data-field="' + field + '"]').value.trim();
      if (val('name') === '') return;
      const tr = document.createElement('tr');
      [[val('name'), 'text-left pr-2'], [val('brand'), 'text-left pr-2'], [val('quantity') || '1', 'text-right pr-2'], [val('note'), 'text-left']].forEach(([text, cls]) => {
        const td = document.createElement('td');
        td.textContent = text;
        td.className = 'py-1 ' + cls;
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
  }

  function addReturnedRow(data = {}) {
    const row = returnedTemplate.content.firstElementChild.cloneNode(true);
    for (const field of ['name', 'brand', 'quantity', 'note']) row.querySelector('[data-field="' + field + '"]').value = data[field] || '';
    const pick = row.querySelector('.returned-pick');
    pick.addEventListener('input', () => {
      const match = accessoryCatalog.find(a => a.label.toLowerCase() === pick.value.trim().toLowerCase());
      if (!match) return;
      row.querySelector('[data-field="name"]').value = match.name;
      row.querySelector('[data-field="brand"]').value = match.brand;
      refreshReturnedRows();
    });
    row.querySelectorAll('[data-field]').forEach(input => input.addEventListener('input', refreshReturnedRows));
    row.querySelector('.remove-returned-row').addEventListener('click', () => {
      row.remove();
      refreshReturnedRows();
    });
    returnedBox.appendChild(row);
    refreshReturnedRows();
  }

  function refreshNumbers() {
    [...box.querySelectorAll('.machine-row')].forEach((row, index) => {
      row.querySelector('.machine-row-number').textContent = '#' + (index + 1);
      const summary = row.querySelector('.machine-print-summary');
      summary.replaceChildren();
      for (const field of ['category', 'brand', 'model', 'model_code']) {
        const value = document.createElement('span');
        value.textContent = row.querySelector('[data-field="' + field + '"]').value;
        summary.appendChild(value);
      }
    });
  }

  function addRow(data = {}) {
    const row = template.content.firstElementChild.cloneNode(true);
    row.dataset.machineId = Number(data.id || 0);
    for (const field of ['category', 'brand', 'model', 'model_code']) {
      row.querySelector('[data-field="' + field + '"]').value = data[field] || '';
    }
    if (data.id) row.querySelector('.machine-inventory-select').value = String(data.id);
    const syncMachine = setupInventorySearch(row, 'machine');
    syncMachine();
    row.querySelector('.machine-inventory-select').addEventListener('change', event => {
      const option = event.currentTarget.selectedOptions[0];
      for (const [field, dataKey] of Object.entries({category: 'category', brand: 'brand', model: 'model', model_code: 'modelCode'})) {
        row.querySelector('[data-field="' + field + '"]').value = option.dataset[dataKey] || '';
      }
      row.dataset.machineId = event.currentTarget.value || '0';
      refreshNumbers();
    });
    row.querySelectorAll('.machine-field').forEach(input => input.addEventListener('input', () => {
      row.dataset.machineId = '0';
      row.querySelector('.machine-inventory-select').value = '';
      syncMachine();
      refreshNumbers();
    }));
    row.querySelector('.remove-machine-row').addEventListener('click', () => {
      row.remove();
      if (!box.children.length) addRow();
      refreshNumbers();
    });
    const saveButton = row.querySelector('.save-machine-to-inventory');
    if (saveButton) saveButton.addEventListener('click', () => {
      const rowIndex = [...box.querySelectorAll('.machine-row')].indexOf(row);
      const form = document.createElement('form');
      form.method = 'post';
      form.action = <?= json_encode(url('quotation.php')) ?>;
      const values = {
        csrf,
        action: 'create_machine',
        row_index: String(rowIndex),
        breakdown_id: String(breakdownId),
      quotation_id: String(<?= $editId ?>),
        sector_id: String(sectorId),
      company_id: document.getElementById('quote-company').value,
        contact_name: document.getElementById('quote-contact-name').value,
        contact_phone: document.getElementById('quote-contact-phone').value,
        machine_model: '',
        breakdown: document.getElementById('quote-breakdown').value,
        machine_rows: JSON.stringify(currentRows()),
        accessory_rows: JSON.stringify(currentAccessoryRows()),
        returned_rows: JSON.stringify(currentReturnedRows())
      };
      for (const [name, value] of Object.entries(values)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      }
      document.body.appendChild(form);
      form.submit();
    });
    box.appendChild(row);
    refreshNumbers();
  }

  let accessoriesInvalid = false;

  function refreshAccessoryRows() {
    const tbody = document.querySelector('#accessory-print-table tbody');
    tbody.replaceChildren();
    let total = 0;
    let hasError = false;
    [...accessoryBox.querySelectorAll('.accessory-row')].forEach((row, index) => {
      row.querySelector('.accessory-row-number').textContent = '#' + (index + 1);
      const val = field => row.querySelector('[data-field="' + field + '"]').value.trim();
      const price = parseFloat(val('price'));
      const qtyParsed = parseInt(val('quantity'), 10);
      const qty = isNaN(qtyParsed) ? 1 : Math.max(0, qtyParsed);
      const discountRaw = val('discount_price');
      const discount = discountRaw === '' ? NaN : parseFloat(discountRaw);
      const error = row.querySelector('.accessory-error');
      const invalid = !isNaN(discount) && ((!isNaN(price) && discount > price) || discount < 0);
      error.textContent = !invalid ? '' : (discount < 0 ? 'The discount is larger than the price, so the discounted price would be negative.' : 'Discounted price (' + discount.toFixed(2) + ') cannot be higher than the original price (' + price.toFixed(2) + ').');
      error.classList.toggle('hidden', !invalid);
      row.querySelector('[data-field="discount_price"]').classList.toggle('border-red-500', invalid);
      if (invalid) hasError = true;
      const unit = !isNaN(discount) && !invalid ? discount : (isNaN(price) ? 0 : price);
      const line = unit * qty;
      if (val('name') === '' && isNaN(price)) return;
      total += line;
      const tr = document.createElement('tr');
      const cells = [[val('name'), 'text-left'], [val('brand'), 'text-left'], [isNaN(price) ? '' : price.toFixed(2), 'text-right'], [String(qty), 'text-right'], [isNaN(discount) ? '' : discount.toFixed(2), 'text-right'], [line.toFixed(2), 'text-right']];
      cells.forEach(([text, cls], n) => {
        const td = document.createElement('td');
        td.textContent = text;
        td.className = 'py-1 ' + cls + (n < 5 ? ' pr-2' : '');
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    document.getElementById('accessory-total').textContent = total.toFixed(2);
    accessoriesInvalid = hasError;
  }

  function addAccessoryRow(data = {}) {
    const row = accessoryTemplate.content.firstElementChild.cloneNode(true);
    row.dataset.accessoryId = Number(data.id || 0);
    row.querySelector('[data-field="name"]').value = data.name || '';
    row.querySelector('[data-field="brand"]').value = data.brand || '';
    row.querySelector('[data-field="price"]').value = data.price || '';
    row.querySelector('[data-field="quantity"]').value = data.quantity || '';
    row.querySelector('[data-field="discount_price"]').value = data.discount_price || '';
    if (data.id) row.querySelector('.accessory-inventory-select').value = String(data.id);
    const syncAccessory = setupInventorySearch(row, 'accessory');
    syncAccessory();
    row.querySelector('.accessory-inventory-select').addEventListener('change', event => {
      const option = event.currentTarget.selectedOptions[0];
      row.querySelector('[data-field="name"]').value = option.dataset.name || '';
      row.querySelector('[data-field="brand"]').value = option.dataset.brand || '';
      row.querySelector('[data-field="price"]').value = option.dataset.price || '';
      row.dataset.accessoryId = event.currentTarget.value || '0';
      refreshAccessoryRows();
    });
    row.querySelectorAll('.accessory-field').forEach(input => input.addEventListener('input', () => {
      row.dataset.accessoryId = '0';
      row.querySelector('.accessory-inventory-select').value = '';
      syncAccessory();
      refreshAccessoryRows();
    }));
    const priceInput = row.querySelector('[data-field="price"]');
    const discountInput = row.querySelector('[data-field="discount_price"]');
    const percentInput = row.querySelector('[data-field="discount_percent"]');
    const percentFromPrice = () => {
      const price = parseFloat(priceInput.value), d = parseFloat(discountInput.value);
      percentInput.value = (isNaN(price) || isNaN(d) || price <= 0) ? '' : (((price - d) / price) * 100).toFixed(2);
    };
    discountInput.addEventListener('input', () => { percentFromPrice(); refreshAccessoryRows(); });
    percentInput.addEventListener('input', () => {
      const price = parseFloat(priceInput.value), p = parseFloat(percentInput.value);
      if (percentInput.value.trim() === '') discountInput.value = '';
      else if (!isNaN(price) && !isNaN(p)) discountInput.value = (price - price * p / 100).toFixed(2);
      refreshAccessoryRows();
    });
    priceInput.addEventListener('input', () => { if (percentInput.value.trim() !== '') percentInput.dispatchEvent(new Event('input')); });
    percentFromPrice();
    row.querySelectorAll('.accessory-extra-field').forEach(input => input.addEventListener('input', refreshAccessoryRows));
    row.querySelector('.remove-accessory-row').addEventListener('click', () => {
      row.remove();
      if (!accessoryBox.children.length) addAccessoryRow();
      refreshAccessoryRows();
    });
    const saveButton = row.querySelector('.save-accessory-to-inventory');
    if (saveButton) saveButton.addEventListener('click', () => {
      const rowIndex = [...accessoryBox.querySelectorAll('.accessory-row')].indexOf(row);
      const form = document.createElement('form');
      form.method = 'post';
      form.action = <?= json_encode(url('quotation.php')) ?>;
      const values = {
        csrf,
        action: 'create_accessory',
        row_index: String(rowIndex),
        breakdown_id: String(breakdownId),
      quotation_id: String(<?= $editId ?>),
        sector_id: String(sectorId),
      company_id: document.getElementById('quote-company').value,
        contact_name: document.getElementById('quote-contact-name').value,
        contact_phone: document.getElementById('quote-contact-phone').value,
        machine_model: '',
        breakdown: document.getElementById('quote-breakdown').value,
        machine_rows: JSON.stringify(currentRows()),
        accessory_rows: JSON.stringify(currentAccessoryRows()),
        returned_rows: JSON.stringify(currentReturnedRows())
      };
      for (const [name, value] of Object.entries(values)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      }
      document.body.appendChild(form);
      form.submit();
    });
    accessoryBox.appendChild(row);
    refreshAccessoryRows();
  }

  document.getElementById('print-quote').addEventListener('click', () => {
    const phoneInput = document.getElementById('quote-contact-phone');
    if (!phoneInput.checkValidity()) {
      phoneInput.reportValidity();
      return;
    }
    refreshAccessoryRows();
    if (accessoriesInvalid) {
      alert('Please fix the accessory discounted price: it cannot be higher than the original price.');
      return;
    }
    window.print();
  });
  document.getElementById('quote-contact-phone').addEventListener('input', e => {
    e.target.value = e.target.value.replace(/\D/g, '').slice(0, 10);
  });
  document.getElementById('save-quote').addEventListener('click', () => {
    const phoneInput = document.getElementById('quote-contact-phone');
    if (!phoneInput.checkValidity()) {
      phoneInput.reportValidity();
      return;
    }
    refreshAccessoryRows();
    if (accessoriesInvalid) {
      alert('Please fix the accessory discounted price: it cannot be higher than the original price.');
      return;
    }
    const form = document.createElement('form');
    form.method = 'post';
    form.action = <?= json_encode(url('quotation.php')) ?>;
    const values = {
      csrf,
      action: 'save_quotation',
      breakdown_id: String(breakdownId),
      quotation_id: String(<?= $editId ?>),
      sector_id: String(sectorId),
      company_id: document.getElementById('quote-company').value,
      quote_date: document.getElementById('quote-date').value,
      contact_name: document.getElementById('quote-contact-name').value,
      contact_phone: document.getElementById('quote-contact-phone').value,
      machine_model: <?= json_encode($quote['machine_model']) ?>,
      breakdown: document.getElementById('quote-breakdown').value,
      remark: document.getElementById('quote-remark').value,
      machine_rows: JSON.stringify(currentRows()),
      accessory_rows: JSON.stringify(currentAccessoryRows()),
      returned_rows: JSON.stringify(currentReturnedRows())
    };
    for (const [name, value] of Object.entries(values)) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
  });
  document.getElementById('add-machine-row').addEventListener('click', () => addRow());
  document.getElementById('add-accessory-row').addEventListener('click', () => addAccessoryRow());
  document.getElementById('add-returned-row').addEventListener('click', () => addReturnedRow());
  initialReturnedRows.forEach(addReturnedRow);
  initialRows.forEach(addRow);
  (initialAccessoryRows.length ? initialAccessoryRows : [{}]).forEach(addAccessoryRow);
})();
</script>
<style>
.signature-line { width: 50%; height: 4rem; border-bottom: 1px dotted #334155; margin-bottom: 0.5rem; }
@media print {
  nav, footer { display: none !important; }
  body { background: white !important; }
  .shadow { box-shadow: none !important; }
  .machine-row { display: block !important; border: 0 !important; padding: 0 !important; margin-bottom: 0.25rem; break-inside: avoid; }
  .machine-print-summary { white-space: normal; }
  .accessory-row { display: block !important; border: 0 !important; padding: 0 !important; margin-bottom: 0.25rem; break-inside: avoid; }
  .accessory-print-summary { white-space: normal; }
  .returned-row { display: none !important; }
  .quote-signatures { break-before: page; page-break-before: always; break-inside: avoid; page-break-inside: avoid; }
}
</style>
<?php page_footer(); ?>
