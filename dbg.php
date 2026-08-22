<?php
require '/dev-server/app/bootstrap.php';
$f = sys_get_temp_dir().'/dbg-'.bin2hex(random_bytes(4)).'.sqlite';
$pdo = App\Database::open($f);
(new App\Migrator($pdo))->migrate();
$pdo->exec("INSERT INTO content_reports (adventure_id,target_type,scene_id,reporter_key,reason) VALUES (1,'scene',5,'u:2','spam')");
$s=$pdo->query("SELECT id, created_at, strftime('%Y-%m-%dT%H:%M:%fZ','now','-24 hours') AS cutoff FROM content_reports");
var_dump($s->fetchAll(PDO::FETCH_ASSOC));
$q=$pdo->prepare("SELECT id FROM content_reports WHERE adventure_id=:a AND reporter_key=:k AND target_type=:t AND reason=:r AND IFNULL(scene_id,0)=:s AND IFNULL(choice_id,0)=:c AND IFNULL(submission_id,0)=:b");
$q->execute([':a'=>1,':k'=>'u:2',':t'=>'scene',':r'=>'spam',':s'=>5,':c'=>0,':b'=>0]);
var_dump($q->fetchAll());
