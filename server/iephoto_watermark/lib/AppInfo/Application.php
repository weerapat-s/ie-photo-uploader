<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\AppInfo;

use OCA\IEPhotoWatermark\Listener\NodeCreatedListener;
use OCA\IEPhotoWatermark\Service\DesktopCodeStore;
use OCA\IEPhotoWatermark\Service\DesktopSeenLog;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\IConfig;
use Psr\Container\ContainerInterface;

final class Application extends App implements IBootstrap {
    public const APP_ID = 'iephoto_watermark';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Auto-process images dropped into the configured event folder through the regular
        // Nextcloud Files / desktop-sync interface. The listener is scoped to that folder only;
        // the background job waits for each file to finish uploading before watermarking it.
        $context->registerEventListener(NodeCreatedEvent::class, NodeCreatedListener::class);
        $context->registerService(DesktopCodeStore::class, static function (ContainerInterface $c): DesktopCodeStore {
            $config = $c->get(IConfig::class);
            return new DesktopCodeStore(
                static fn (): string => $config->getAppValue(self::APP_ID, 'desktop_codes', '[]'),
                static function (string $json) use ($config): void { $config->setAppValue(self::APP_ID, 'desktop_codes', $json); },
                static fn (): int => time(),
            );
        });
        $context->registerService(DesktopSeenLog::class, static function (ContainerInterface $c): DesktopSeenLog {
            $dir = rtrim((string)$c->get(IConfig::class)->getSystemValue('datadirectory', ''), '/');
            return new DesktopSeenLog($dir . '/iephoto_watermark/desktop_seen');
        });
    }

    public function boot(IBootContext $context): void {
    }
}
