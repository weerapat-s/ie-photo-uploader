<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Listener;

use OCA\IEPhotoWatermark\AppInfo\Application;
use OCA\IEPhotoWatermark\BackgroundJob\ProcessImageJob;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\File;
use OCP\IConfig;

/** @template-implements IEventListener<NodeCreatedEvent> */
final class NodeCreatedListener implements IEventListener {
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private IJobList $jobList,
        private IConfig $config,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof NodeCreatedEvent) {
            return;
        }
        $node = $event->getNode();
        if (!$node instanceof File) {
            return; // folders ignored
        }

        $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            return;
        }
        if (str_starts_with($node->getName(), 'IEPHOTO@KMTIL_')) {
            return; // already a processed file
        }

        // Only files dropped inside the configured event folder are auto-processed — never the
        // whole Nextcloud. (Everything else is left untouched, as before.)
        $uid = $this->config->getAppValue(Application::APP_ID, 'target_uid', '');
        $target = trim($this->config->getAppValue(Application::APP_ID, 'target_path', ''), '/');
        if ($uid === '' || $target === '') {
            return;
        }
        $prefix = '/' . $uid . '/files/' . $target . '/';
        if (!str_starts_with($node->getPath(), $prefix)) {
            return;
        }
        // Never touch the originals backups.
        $relative = substr($node->getPath(), strlen($prefix));
        if (str_contains($relative, 'ต้นฉบับ') || str_contains($relative, 'ไฟล์เสีย ต้องอัปใหม่')) {
            return;
        }

        // Hand off to the background job, which waits for the file to settle before processing.
        $this->jobList->add(ProcessImageJob::class, [
            'nodeId' => $node->getId(),
            'size' => -1,
            'stableSince' => 0,
        ]);
    }
}
