<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
$pdo = db();
$plans = $pdo->query("SELECT id,name,price,duration_days,max_streams,is_trial FROM plans ORDER BY price")->fetchAll();
?>

<div class="grid plans-grid">
<?php foreach ($plans as $p):
  // skip trial plans from paid list
  if ((!empty($p['is_trial'])) || stripos((string)$p['name'], 'trial') !== false) { continue; }
?>
  <div class="card plan-card" style="padding:18px;">
    <div class="badge"><?= e($p['name']) ?></div>
    <div class="price">$<?= e($p['price']) ?></div>
    <ul>
      <li><?= (int)$p['duration_days'] ?> days access</li>
      <li><?= (int)$p['max_streams'] ?> connections</li>
      <li>Adult content optional</li>
      <li>Instant delivery after payment</li>
    </ul>
    <div style="margin-top:14px;">
      <a class="btn primary" href="/checkout.php?plan=<?= (int)$p['id'] ?>">Choose Plan</a>
    </div>
  </div>
<?php endforeach; ?>

<?php if (empty($plans)): ?>
  <div class="card" style="padding:18px;">
    <p class="muted">No plans available yet.</p>
  </div>
<?php endif; ?>
</div>
