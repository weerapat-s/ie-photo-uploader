<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\AppInfo;

use OCA\IEPhotoWatermark\Listener\NodeCreatedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;

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
    }

    public function boot(IBootContext $context): void {
    }
}
