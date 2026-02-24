<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$admin = _gc_mobile_require_admin($pdo);

$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 25);
if ($limit < 5) $limit = 5;
if ($limit > 100) $limit = 100;

if ($q === '') _gc_json(['ok'=>true,'items'=>[],'count'=>0]);

$like = '%' . $q . '%';

$cols = _gc_table_cols($pdo, 'users');

// required
$select = ['id', 'username'];

// optional (only if column exists)
$optional = ['status','reseller_id','exp_date','max_connections','enabled','created_at'];
foreach ($optional as $c) {
  if (isset($cols[$c])) $select[] = $c;
}

$sql = "SELECT " . implode(', ', $select) . " FROM users
        WHERE username LIKE ? OR CAST(id AS CHAR) = ?
        ORDER BY id DESC
        LIMIT $limit";

$st = $pdo->prepare($sql);
$st->execute([$like, $q]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// normalize missing keys expected by app
foreach ($rows as &$r) {
  if (!array_key_exists('status', $r)) $r['status'] = null;
  if (!array_key_exists('reseller_id', $r)) $r['reseller_id'] = null;
  if (!array_key_exists('exp_date', $r)) $r['exp_date'] = null;
  if (!array_key_exists('max_connections', $r)) $r['max_connections'] = null;
}
unset($r);

_gc_json(['ok'=>true,'items'=>$rows,'count'=>count($rows)]);
