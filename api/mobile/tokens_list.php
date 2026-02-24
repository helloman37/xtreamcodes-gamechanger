<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$ctx = _gc_mobile_require_admin($pdo);

$admin_id = (int)$ctx['admin_id'];

$cols = _gc_table_cols($pdo, 'mobile_tokens');

$fields = [
  "id",
  (isset($cols['created_at']) ? "created_at" : "NULL AS created_at"),
  (isset($cols['expires_at']) ? "expires_at" : "NULL AS expires_at"),
];

if (isset($cols['last_used_at'])) {
  $fields[] = "last_used_at";
} elseif (isset($cols['last_seen'])) {
  $fields[] = "last_seen AS last_used_at";
} else {
  $fields[] = "NULL AS last_used_at";
}

$fields[] = (isset($cols['revoked_at']) ? "revoked_at" : "NULL AS revoked_at");
$fields[] = (isset($cols['device_id']) ? "device_id" : "NULL AS device_id");
$fields[] = (isset($cols['device_name']) ? "device_name" : "NULL AS device_name");
$fields[] = (isset($cols['last_ip']) ? "last_ip" : "NULL AS last_ip");

$sql = "
  SELECT " . implode(", ", $fields) . "
  FROM mobile_tokens
  WHERE admin_id=?
  ORDER BY id DESC
  LIMIT 50
";

$st = $pdo->prepare($sql);
$st->execute([$admin_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

_gc_json(['ok'=>true,'items'=>$rows,'count'=>count($rows)]);
