<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Service;

use OCA\IEPhotoWatermark\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;

final class GalleryService {
    private const MAX_DEPTH = 12;
    private const MAX_SCANNED_FILES = 5000;

    public function __construct(
        private IRootFolder $rootFolder,
        private IUserManager $userManager,
        private IConfig $config,
    ) {
    }

    /**
     * @return array{photos:list<array{id:int,name:string,photographer:string,mtime:int}>,total:int}
     */
    public function latestPhotos(int $limit = 24): array {
        if (!$this->isConfigured()) {
            return ['photos' => [], 'total' => 0];
        }

        $target = $this->configuredTargetFolder();
        $photos = [];
        $scanned = 0;
        $this->collectPhotos($target, $target, $photos, $scanned, 0);
        usort($photos, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return [
            'photos' => array_slice($photos, 0, max(1, min(60, $limit))),
            'total' => count($photos),
        ];
    }

    public function photoById(int $id): ?File {
        if ($id <= 0 || !$this->isConfigured()) {
            return null;
        }

        $target = $this->configuredTargetFolder();
        foreach ($target->getById($id) as $node) {
            if ($node instanceof File
                && $node->isReadable()
                && $this->isProcessedPhoto($node)
                && $target->isSubNode($node)) {
                return $node;
            }
        }
        return null;
    }

    private function isConfigured(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'target_uid', '') !== '';
    }

    private function configuredTargetFolder(): Folder {
        $uid = $this->config->getAppValue(Application::APP_ID, 'target_uid', '');
        $path = $this->config->getAppValue(Application::APP_ID, 'target_path', '/');
        if ($uid === '' || $this->userManager->get($uid) === null) {
            throw new \RuntimeException('Gallery destination is not configured');
        }

        $root = $this->rootFolder->getUserFolder($uid);
        $clean = trim(str_replace('\\', '/', $path), '/');
        if ($clean === '') {
            return $root;
        }
        $node = $root->get($clean);
        if (!$node instanceof Folder) {
            throw new \RuntimeException('Gallery destination is not a folder');
        }
        return $node;
    }

    /**
     * @param list<array{id:int,name:string,photographer:string,mtime:int}> $photos
     */
    private function collectPhotos(
        Folder $folder,
        Folder $base,
        array &$photos,
        int &$scanned,
        int $depth,
    ): void {
        if ($depth > self::MAX_DEPTH || $scanned >= self::MAX_SCANNED_FILES) {
            return;
        }

        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof Folder) {
                $this->collectPhotos($node, $base, $photos, $scanned, $depth + 1);
                continue;
            }
            if (!$node instanceof File) {
                continue;
            }

            $scanned++;
            if ($node->isReadable() && $this->isProcessedPhoto($node)) {
                $photos[] = [
                    'id' => $node->getId(),
                    'name' => $node->getName(),
                    'photographer' => $this->photographerLabel($node, $base),
                    'mtime' => $node->getMTime(),
                ];
            }
            if ($scanned >= self::MAX_SCANNED_FILES) {
                return;
            }
        }
    }

    private function isProcessedPhoto(File $file): bool {
        return preg_match('/^IEPHOTO@KMTIL_[0-9]{4,}\.(?:jpe?g|png|webp)$/i', $file->getName()) === 1;
    }

    private function photographerLabel(File $file, Folder $base): string {
        $basePath = rtrim($base->getPath(), '/');
        $parentPath = $file->getParent()->getPath();
        if (!str_starts_with($parentPath, $basePath)) {
            return 'ส่วนกลาง';
        }
        $relative = trim(substr($parentPath, strlen($basePath)), '/');
        if ($relative === '') {
            return 'ส่วนกลาง';
        }
        $parts = explode('/', $relative);
        return (string)end($parts);
    }
}
