<?php
require_once __DIR__ . '/bootstrap.php';
$cfg = pr_manifest($argv[1] ?? '');
if (empty($cfg['transactions']) || is_file($cfg['run'] . '/runtime-summary.json')) throw new RuntimeException('Active owned transaction release required.');
$db = new PDO('sqlite:' . pr_owned_path($cfg, $cfg['release'] . '/storage/fixture.sqlite'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($db->query('SELECT name FROM fixture_marker')->fetchColumn() !== 'dujiaoka-admin-browser-v1') throw new RuntimeException('Synthetic transaction database required.');
$action = $argv[2] ?? '';
$before = $db->query('SELECT * FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if ($action === 'install-fault') {
    $db->exec("CREATE TRIGGER release_coupon_fault AFTER UPDATE OF ret ON coupons WHEN NEW.ret < OLD.ret BEGIN SELECT RAISE(ABORT, 'Injected production coupon failure'); END");
} elseif ($action === 'remove-fault') {
    $db->exec('DROP TRIGGER release_coupon_fault');
} elseif ($action === 'early-job') {
    $id = filter_var($argv[3] ?? '', FILTER_VALIDATE_INT);
    $job = array_values(array_filter($before, function ($r) use ($id) { return (int)$r['id'] === $id; }));
    if (count($job) !== 1 || $job[0]['reserved_at'] !== null || (int)$job[0]['attempts'] !== 0 || (int)$job[0]['available_at'] <= time()) throw new RuntimeException('One original future job required.');
    $statement = $db->prepare('UPDATE jobs SET available_at=? WHERE id=?'); $statement->execute([time(), $id]);
    if ($statement->rowCount() !== 1) throw new RuntimeException('Early job control failed.');
} elseif ($action === 'age-orders') {
    $sns = json_decode(file_get_contents($cfg['run'] . '/transaction-orders.json'), true, 512, JSON_THROW_ON_ERROR);
    if (count($sns) !== 3 || count(array_unique($sns)) !== 3) throw new RuntimeException('Three newly created order identities required.');
    $seed = json_decode(file_get_contents($cfg['run'].'/seed-proof.json'),true,512,JSON_THROW_ON_ERROR);
    $aged = (new DateTimeImmutable('@'.(time()-360)))->setTimezone(new DateTimeZone($seed['timezone']))->format('Y-m-d H:i:s');
    $db->beginTransaction();
    foreach ($sns as $sn) {
        if (!preg_match('/^[A-Z0-9]{16}$/D', $sn)) throw new RuntimeException('New synthetic order identity required.');
        $s = $db->prepare('UPDATE orders SET created_at=? WHERE id>2 AND status=1 AND order_sn=?');
        $s->execute([$aged, $sn]);
        if ($s->rowCount() !== 1) throw new RuntimeException('Owned age control failed.');
    }
    $db->commit();
} else throw new RuntimeException('Known transaction probe action required.');
$after = $db->query('SELECT * FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($before as $i => $row) if ($after[$i]['payload'] !== $row['payload']) throw new RuntimeException('Original queued payload was changed.');
ql_json($cfg['run'] . '/probe-' . $action . '.json', ['action'=>$action,'before_jobs'=>$before,'after_jobs'=>$after,'time'=>time(),'status'=>'PASS']);
echo 'Owned transaction probe ', $action, ' PASS', PHP_EOL;
