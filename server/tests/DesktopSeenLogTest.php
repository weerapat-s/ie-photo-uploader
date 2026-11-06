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
// locked(): returns closure value, releases lock, validates ids
check($log->locked('abc123', $sha, static fn() => 42) === 42, 'locked returns closure value');
check($log->locked('abc123', $sha, static fn() => 'again') === 'again', 'lock released after success');
$threw = false; try { $log->locked('abc123', $sha, static function () { throw new \LogicException('boom'); }); } catch (\LogicException) { $threw = true; }
check($threw, 'locked propagates closure exception');
check($log->locked('abc123', $sha, static fn() => 'after-throw') === 'after-throw', 'lock released after exception');
$threw = false; try { $log->locked('../x', $sha, static fn() => 1); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'locked rejects bad code id');
$threw = false; try { $log->locked('abc123', 'zz', static fn() => 1); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'locked rejects bad sha');

// remember(): atomic, no temp files, JSON valid
for ($i = 0; $i < 200; $i++) {
    $log->remember('abc999', hash('sha256', (string)$i), "n$i.jpg");
}
check((glob($dir . '/*.tmp') ?: []) === [], 'no tmp files left');
$decoded = json_decode((string)file_get_contents($dir . '/abc999.json'), true);
check(is_array($decoded) && count($decoded) === 200, 'json valid with 200 entries');
check($log->find('abc999', hash('sha256', '137')) === 'n137.jpg', 'entry readable');

// two different shas in same bucket both persist
$first = null; $second = null; $prefix = null;
for ($i = 1000; $i < 100000 && $second === null; $i++) {
    $h = hash('sha256', (string)$i);
    if ($first === null) { $first = $h; $prefix = substr($h, 0, 2); }
    elseif (substr($h, 0, 2) === $prefix) { $second = $h; }
}
check($second !== null && $first !== $second, 'found same-bucket shas');
$log->locked('abc777', $first, function () use ($log, $first) { $log->remember('abc777', $first, 'one.jpg'); });
$log->locked('abc777', $second, function () use ($log, $second) { $log->remember('abc777', $second, 'two.jpg'); });
check($log->find('abc777', $first) === 'one.jpg' && $log->find('abc777', $second) === 'two.jpg', 'same-bucket shas both persist');
echo "all passed\n";
