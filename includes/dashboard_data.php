<?php

function dashboard_totals(PDO $pdo): array {
    return $pdo->query("SELECT
        (SELECT COUNT(*) FROM users) AS users,
        (SELECT COUNT(*) FROM companies) AS companies,
        (SELECT COUNT(*) FROM technicians) AS technicians,
        (SELECT COUNT(*) FROM accessories) AS accessories,
        (SELECT COUNT(*) FROM sectors) AS sectors,
        (SELECT COUNT(*) FROM machines) AS machines,
        (SELECT COUNT(*) FROM quotations) AS quotations,
        COUNT(*) AS breakdowns,
        COALESCE(SUM(status = 'open'), 0) AS opened,
        COALESCE(SUM(status = 'in_progress'), 0) AS ongoing,
        COALESCE(SUM(status = 'fixed'), 0) AS completed
        FROM breakdowns")->fetch();
}

function dashboard_sector_ranking(PDO $pdo, string $order = 'desc', ?int $limit = null): array {
    $direction = $order === 'asc' ? 'ASC' : 'DESC';
    $sql = "SELECT s.id, s.name, COUNT(b.id) AS total,
        COALESCE(SUM(b.status = 'open'), 0) AS opened,
        COALESCE(SUM(b.status = 'in_progress'), 0) AS ongoing,
        COALESCE(SUM(b.status = 'fixed'), 0) AS completed
        FROM sectors s LEFT JOIN breakdowns b ON b.sector_id = s.id
        GROUP BY s.id, s.name ORDER BY total $direction, s.name ASC, s.id ASC";
    if ($limit !== null) $sql .= ' LIMIT ' . max(0, $limit);
    return $pdo->query($sql)->fetchAll();
}

function dashboard_ranking_table(array $rows): void { ?>
<div class="overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
      <tr><th scope="col" class="p-3">#</th><th scope="col" class="p-3">Sector</th><th scope="col" class="p-3 text-right">Total breakdowns</th><th scope="col" class="p-3 text-right">Opened</th><th scope="col" class="p-3 text-right">Ongoing</th><th scope="col" class="p-3 text-right">Completed</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $index => $row): ?>
      <tr class="border-t hover:bg-slate-50">
        <td class="p-3 text-slate-500"><?= $index + 1 ?></td>
        <th scope="row" class="p-3 text-left font-medium"><a class="text-blue-700 hover:underline" href="<?= e(url('admin/records.php?sector=' . (int)$row['id'])) ?>"><?= e($row['name']) ?></a></th>
        <td class="p-3 text-right font-bold"><?= number_format((int)$row['total']) ?></td>
        <td class="p-3 text-right text-red-700"><?= number_format((int)$row['opened']) ?></td>
        <td class="p-3 text-right text-amber-700"><?= number_format((int)$row['ongoing']) ?></td>
        <td class="p-3 text-right text-green-700"><?= number_format((int)$row['completed']) ?></td>
      </tr>
    <?php endforeach; if (!$rows): ?>
      <tr><td colspan="6" class="p-6 text-center text-slate-500">No sectors yet. Create a sector to see its breakdown totals here.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php }
