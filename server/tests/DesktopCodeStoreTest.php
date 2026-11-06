<?php
declare(strict_types=1);
require __DIR__ . '/../iephoto_watermark/lib/Service/DesktopCodeStore.php';

use OCA\IEPhotoWatermark\Service\DesktopCodeStore;

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FAIL: $msg\n"); exit(1); } echo "ok - $msg\n"; }

$blob = '[]'; $now = 1_000_000;
// Closures take the variables by reference: PHP arrow functions (fn) would freeze their values.
$store = new DesktopCodeStore(
    function () use (&$blob) { return $blob; },
    function (string $j) use (&$blob) { $blob = $j; },
    function () use (&$now) { return $now; },
);

$made = $store->create('ChalkGames 2569', 'ฟุตบอล', 7);
check((bool)preg_match('/^[A-Z2-9]{5}(-[A-Z2-9]{5}){3}$/', $made['code']), 'code format XXXXX-XXXXX-XXXXX-XXXXX');
check(!str_contains($blob, $made['code']) && !str_contains($blob, str_replace('-', '', $made['code'])), 'raw code never stored');
check(!isset($made['record']['hash']), 'public record hides hash');

$rec = $store->verify(strtolower(str_replace('-', ' ', $made['code'])));
check($rec !== null && $rec['album'] === 'ฟุตบอล' && $rec['label'] === 'ChalkGames 2569', 'verify tolerates case/spaces');
check($store->verify('AAAAA-AAAAA-AAAAA-AAAAA') === null, 'unknown code rejected');
check($store->verify('') === null, 'empty code rejected');

$now += 7 * 86400 + 1;
check($store->verify($made['code']) === null, 'expired code rejected');
$now -= 10;

check($store->revoke($made['record']['id']) === true, 'revoke returns true');
check($store->verify($made['code']) === null, 'revoked code rejected');
check($store->revoke('nope') === false, 'revoke unknown id false');
check(count($store->list()) === 1 && !isset($store->list()[0]['hash']), 'list hides hash');

$threw = false; try { $store->create('  ', '', 7); } catch (\InvalidArgumentException) { $threw = true; }
check($threw, 'blank label rejected');
check($store->create('x', '', 999)['record']['expiresAt'] === $now + 90 * 86400, 'days capped at 90');
echo "all passed\n";
