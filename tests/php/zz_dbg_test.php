<?php
final class BPZZDbgTest {
  public function testDbg(): void {
    $f = sys_get_temp_dir().'/zz-'.bin2hex(random_bytes(4)).'.sqlite';
    $pdo = App\Database::open($f);
    (new App\Migrator($pdo))->migrate();
    $pdo->exec("INSERT INTO content_reports (adventure_id,target_type,scene_id,reporter_key,reason) VALUES (1,'scene',5,'u:2','spam')");
    $rows = $pdo->query('SELECT id, reporter_key, created_at, scene_id FROM content_reports')->fetchAll(PDO::FETCH_ASSOC);
    $svc = new App\ReportService($pdo);
    $dup = $svc->findDuplicate(1,'u:2','scene','spam',5,null,null);
    throw new RuntimeException(json_encode([$rows, $dup, gmdate('Y-m-d\TH:i:s\Z', time()-86400)]));
  }
}
