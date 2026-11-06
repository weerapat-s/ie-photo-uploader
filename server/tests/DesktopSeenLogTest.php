<?php
declare(strict_types=1);
require __DIR__ . '/../iephoto_watermark/lib/Service/DesktopSeenLog.php';

use OCA\IEPhotoWatermark\Service\DesktopSeenLog;

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } echo "ok - $msg\n"; }

$dir = sys_get_temp_dir() . '/seen-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($dir): void {
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($dir);
    }
});
$log = new DesktopSeenLog($dir);
$sha = str_repeat('a', 64);
check($log->find('abc123', $sha) === null, 'unknown sha');
$log->remember('abc123', $sha, 'IEPHOTO@KMTIL_0001.jpg');
check((new DesktopSeenLog($dir))->find('abc123', $sha) === 'IEPHOTO@KMTIL_0001.jpg', 'persisted');
check($log->find('def456', $sha) === null, 'scoped per code');
$threw = false; try { $log->remember('../x', $sha, 'n'); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'bad code id rejected');
$threw = false; try { $log->find('abc123', 'zz'); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'bad sha rejected');
echo "all passed\n";
