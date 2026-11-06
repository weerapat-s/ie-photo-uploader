<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Service;

use OCA\IEPhotoWatermark\AppInfo\Application;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IPreview;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;

final class UploadService {
    private const PREFIX = 'IEPHOTO@KMTIL_';
    /** Broken uploads live here; never scanned, never processed. */
    private const QUARANTINE = 'ไฟล์เสีย ต้องอัปใหม่';
    /** Shared folder that collects every untouched original (same one the auto job writes to). */
    private const ORIGINALS_SHARE_TOKEN = 'izt5yib4dxWTisP';
    /** Folders the watermark tools may touch, one per line. */
    private const DEFAULT_SCOPES = '/ปีการศึกษา 2569';
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    /** Mark width as a share of the photo's width. */
    private const WATERMARK_WIDTH_RATIO = 0.07;
    private const WATERMARK_POSITIONS = [
        'top-left', 'top-center', 'top-right',
        'middle-left', 'center', 'middle-right',
        'bottom-left', 'bottom-center', 'bottom-right',
    ];

    public function __construct(
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private IAppData $appData,
        private IConfig $config,
        private IShareManager $shareManager,
        private IPreview $preview,
    ) {
    }

    public function isConfigured(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'target_uid', '') !== '';
    }

    public function destinationLabel(): string {
        if (!$this->isConfigured()) {
            return 'แอดมินยังไม่ได้กำหนดโฟลเดอร์ปลายทาง';
        }
        $uid = $this->config->getAppValue(Application::APP_ID, 'target_uid', '');
        $path = $this->config->getAppValue(Application::APP_ID, 'target_path', '/');
        return $uid . ':' . ($path === '' ? '/' : $path);
    }

    /** @return array{users:list<array{uid:string,name:string}>,uid:string,path:string,configured:bool} */
    public function adminConfig(): array {
        $users = [];
        foreach ($this->userManager->search('') as $user) {
            $users[] = ['uid' => $user->getUID(), 'name' => $user->getDisplayName()];
        }
        usort($users, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return [
            'users' => $users,
            'uid' => $this->config->getAppValue(Application::APP_ID, 'target_uid', ''),
            'path' => $this->config->getAppValue(Application::APP_ID, 'target_path', '/'),
            'configured' => $this->isConfigured(),
            'scopes' => implode("\n", array_map(
                static fn (string $scope): string => '/' . $scope,
                $this->scanScopes(),
            )),
        ];
    }

    /**
     * Folders the scan and the batch processor may work on, relative to the owner's root.
     * Anything outside is refused, so a personal folder in the same account can never be renamed
     * and watermarked by accident. An empty setting means the whole space.
     *
     * @return string[]
     */
    public function scanScopes(): array {
        $raw = $this->config->getAppValue(Application::APP_ID, 'scan_scopes', self::DEFAULT_SCOPES);
        $scopes = [];
        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            $clean = $this->cleanPath((string)$line);
            if ($clean !== '') {
                $scopes[] = $clean;
            }
        }
        return array_values(array_unique($scopes));
    }

    /**
     * @return string[] the scopes as stored
     * @throws \RuntimeException when a listed folder does not exist for that owner
     */
    public function saveScanScopes(string $uid, string $raw): array {
        $uid = $uid !== '' ? $uid : $this->config->getAppValue(Application::APP_ID, 'target_uid', '');
        if ($uid === '') {
            throw new \RuntimeException('ยังไม่ได้เลือกเจ้าของพื้นที่');
        }
        $scopes = [];
        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            $clean = $this->cleanPath((string)$line);
            if ($clean === '') {
                continue;
            }
            $this->resolveUserFolder($uid, $clean);   // throws when the folder is not there
            $scopes[] = $clean;
        }
        $scopes = array_values(array_unique($scopes));
        $this->config->setAppValue(Application::APP_ID, 'scan_scopes', implode("\n", array_map(
            static fn (string $scope): string => '/' . $scope,
            $scopes,
        )));
        return $scopes;
    }

    private function assertInScope(Folder $folder, string $uid): void {
        $scopes = $this->scanScopes();
        if ($scopes === []) {
            return;
        }
        try {
            $relative = ltrim($this->relativePath($folder, $uid), '/');
        } catch (\Throwable) {
            throw new \RuntimeException('โฟลเดอร์นี้อยู่นอกขอบเขตที่อนุญาต');
        }
        foreach ($scopes as $scope) {
            if ($relative === $scope || str_starts_with($relative, $scope . '/')) {
                return;
            }
        }
        throw new \RuntimeException('/' . $relative . ' อยู่นอกขอบเขตที่อนุญาต — แก้ได้ที่ช่อง “ขอบเขตที่ให้ใส่ลายน้ำ”');
    }

    /** Destination is pinned by default; flip `destination_locked` to 0 to move to a new event. */
    public function destinationLocked(): bool {
        return $this->config->getAppValue(Application::APP_ID, 'destination_locked', '1') === '1';
    }

    public function saveDestination(string $uid, string $path): void {
        $folder = $this->resolveUserFolder($uid, $path);
        $relative = $this->relativePath($folder, $uid);
        $this->config->setAppValue(Application::APP_ID, 'target_uid', $uid);
        $this->config->setAppValue(Application::APP_ID, 'target_path', $relative);
    }

    /** @return list<string> */
    public function listFoldersForUser(string $uid): array {
        $root = $this->userFolderFor($uid);
        $paths = ['/'];
        $this->collectFolders($root, '', $paths, 0);
        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
        return $paths;
    }

    public function createFolderForUser(string $uid, string $path): void {
        $clean = $this->cleanPath($path);
        if ($clean === '') {
            throw new \RuntimeException('กรุณาระบุชื่อโฟลเดอร์');
        }
        $root = $this->userFolderFor($uid);
        $current = $root;
        foreach (explode('/', $clean) as $part) {
            if ($part === '') {
                continue;
            }
            if ($current->nodeExists($part)) {
                $node = $current->get($part);
                if (!$node instanceof Folder) {
                    throw new \RuntimeException('มีไฟล์ชื่อเดียวกับโฟลเดอร์ที่ต้องการสร้าง');
                }
                $current = $node;
            } else {
                $current = $current->newFolder($part);
            }
        }
    }

    /** @return array{name:string,folder:string} */
    public function processUpload(
        string $tmpPath,
        string $originalName,
        string $uploaderName,
        string $watermarkPosition,
        int $watermarkOpacity = 100,
        string $watermarkColour = 'black',
        string $albumToken = '',
        string $sport = '',
        string $sportVenue = '',
    ): array {
        $watermarkOpacity = max(10, min(100, $watermarkOpacity));
        if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
            throw new \RuntimeException('ไม่พบไฟล์ชั่วคราว');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new \RuntimeException('รองรับเฉพาะ JPG, JPEG, PNG และ WebP');
        }

        if (!$this->isCompleteImage($tmpPath, $extension) && !$this->isRepairable($tmpPath)) {
            throw new \RuntimeException('ไฟล์ต้นฉบับเสียหาย (ข้อมูลขาด ท้ายไฟล์ว่างเปล่า) — มักเกิดจากคัดลอกจาก SD card ไม่สำเร็จ ต้องดึงจากการ์ดใหม่ อัปซ้ำไฟล์เดิมไม่หาย');
        }
        $uploaderName = $this->normalizeUploaderName($uploaderName);
        if (!in_array($watermarkPosition, self::WATERMARK_POSITIONS, true)) {
            throw new \RuntimeException('ตำแหน่งลายน้ำไม่ถูกต้อง');
        }
        if ($sport !== '') {
            // Universal link: photographer chose a sport. Folder and watermark colour both come
            // from the sport's registered venue (indoor = white, outdoor = black), never from the
            // client, so a photographer cannot pick the wrong colour.
            $sportFolder = $this->resolveSportFolder($sport, $sportVenue);
            $watermarkColour = $this->colourForVenue($this->venueForSport($sport));
            $target = $this->uploaderTargetFolder($uploaderName, $sportFolder);
        } else {
            $albumFolder = $this->albumFolderFromUploadToken($albumToken);
            $target = $this->uploaderTargetFolder($uploaderName, $albumFolder);
        }
        $this->archiveOriginal($tmpPath, $originalName);
        $outputPath = tempnam(sys_get_temp_dir(), 'iephoto-web-');
        if ($outputPath === false) {
            throw new \RuntimeException('สร้างไฟล์ชั่วคราวไม่สำเร็จ');
        }

        try {
            $this->watermark($tmpPath, $outputPath, $extension, $watermarkPosition, $watermarkOpacity, $watermarkColour);
            $written = false;
            // Each name is now globally unique (allocateName is atomic), so there is no
            // cross-upload collision to retry for. The loop only re-allocates if a name somehow
            // already exists on disk, and tolerates the files_versions hook throwing after the
            // bytes have already landed.
            $name = $this->allocateName($extension);
            for ($attempt = 0; $attempt < 25 && !$written; $attempt++) {
                if ($target->nodeExists($name)) {
                    $name = $this->allocateName($extension); // extremely rare; skip the taken number
                    continue;
                }
                $stream = fopen($outputPath, 'rb');
                if (!is_resource($stream)) {
                    throw new \RuntimeException('อ่านภาพผลลัพธ์ไม่สำเร็จ');
                }
                try {
                    $target->newFile($name, $stream);
                    $written = true;
                } catch (\OCP\Files\NotFoundException $e) {
                    // files_versions post-write hook can throw AFTER the bytes are written.
                    if ($target->nodeExists($name)) {
                        $written = true;
                    } else {
                        throw $e;
                    }
                } catch (\Throwable $e) {
                    // The name is ours alone; if it exists now, our write landed despite the hook.
                    if ($target->nodeExists($name)) {
                        $written = true;
                    } else {
                        throw $e;
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
            if (!$written) {
                throw new \RuntimeException('บันทึกไฟล์ผลลัพธ์ไม่สำเร็จ');
            }
            $this->recordHistory($originalName, $name, $uploaderName, $watermarkPosition);
            $this->logMapping([
                'via' => 'upload',
                'source' => basename($originalName),
                'output' => $name,
                'folder' => $uploaderName,
                'position' => $watermarkPosition,
                'colour' => $watermarkColour ?? 'black',
            ]);
            return ['name' => $name, 'folder' => $uploaderName];
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * Accept either a path inside the owner's files, a public share link, or a bare share token,
     * and return the folder to work on. Admins normally paste the share link straight from the
     * browser, so treating that as a literal path is what produced
     * "/68030271/files/https:/nextcloud.ienas.site/s/...".
     */
    private function resolveProcessTarget(string $uid, string $path): Folder {
        $token = $this->shareTokenFrom($path);
        if ($token !== '') {
            try {
                $share = $this->shareManager->getShareByToken($token);
            } catch (\Throwable) {
                throw new \RuntimeException('ลิงก์แชร์นี้ใช้ไม่ได้แล้ว');
            }
            $node = $share->getNode();
            if (!$node instanceof Folder) {
                throw new \RuntimeException('ลิงก์แชร์ต้องเป็นโฟลเดอร์');
            }
            return $node;
        }

        $root = $this->userFolderFor($uid);
        $clean = $this->cleanPath($path);
        if ($clean === '') {
            return $root;
        }
        try {
            $node = $root->get($clean);
        } catch (\Throwable) {
            throw new \RuntimeException('ไม่พบโฟลเดอร์: /' . $clean);
        }
        if (!$node instanceof Folder) {
            throw new \RuntimeException('เส้นทางที่เลือกไม่ใช่โฟลเดอร์');
        }
        return $node;
    }

    /** Pull a share token out of a pasted link, or recognise a bare token. */
    private function shareTokenFrom(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#/s/([A-Za-z0-9]+)#', $value, $m) === 1) {
            return $m[1];
        }
        // a bare token: no slashes, no spaces, share-token shaped
        if (preg_match('#^[A-Za-z0-9]{10,32}$#', $value) === 1) {
            return $value;
        }
        return '';
    }

    /**
     * Rename + watermark photos that are already sitting in a folder (uploaded through the
     * Files app / desktop client, which bypasses the upload page entirely).
     *
     * Runs in batches: the caller keeps calling until `done` is true. That keeps each HTTP
     * request short instead of letting a few hundred slow image conversions run into the
     * PHP/Apache timeout.
     *
     * Untouched copies of every original are kept in a sibling "<folder> ต้นฉบับ" tree so the
     * operation stays reversible.
     *
     * @return array{scanned:int,processed:int,failed:int,remaining:int,done:bool,results:list<array<string,mixed>>}
     */
    public function processExistingFolder(
        string $uid,
        string $path,
        int $limit = 10,
        string $watermarkPosition = 'top-left',
        int $watermarkOpacity = 100,
        string $watermarkColour = 'black',
    ): array {
        $limit = max(1, min(50, $limit));
        $watermarkOpacity = max(10, min(100, $watermarkOpacity));
        if (!in_array($watermarkPosition, self::WATERMARK_POSITIONS, true)) {
            throw new \RuntimeException('ตำแหน่งลายน้ำไม่ถูกต้อง');
        }

        $folder = $this->resolveProcessTarget($uid, $path);
        $this->assertInScope($folder, $uid);

        // Two runs on the same folder (a second tab, a restarted loop, a server-side run) used to
        // list the same photos and then fight over them: one renamed and deleted a file while the
        // other was still reading it, which surfaced as "is locked" and "อ่านไฟล์ต้นทางไม่สำเร็จ".
        // One batch per folder at a time; a second caller waits its turn or is told to retry.
        $lock = $this->acquireFolderLock($folder);
        if ($lock === null) {
            return [
                'scanned' => 0, 'processed' => 0, 'failed' => 0, 'remaining' => 0,
                'done' => false, 'busy' => true, 'skipped' => 0,
                'blocked' => 0, 'blockedNames' => [], 'results' => [],
            ];
        }
        try {
            return $this->processFolderBatch($folder, $limit, $watermarkPosition, $watermarkOpacity, $watermarkColour);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource|null an exclusive lock on this folder's batch, or null if still busy */
    private function acquireFolderLock(Folder $folder) {
        $dir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/') . '/iephoto_watermark/locks';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('สร้างไฟล์ล็อกไม่สำเร็จ');
        }
        $handle = fopen($dir . '/batch-' . $folder->getId() . '.lock', 'c');
        if (!is_resource($handle)) {
            throw new \RuntimeException('สร้างไฟล์ล็อกไม่สำเร็จ');
        }
        // A batch takes well under a minute, so a short wait usually gets the turn. Stay far
        // enough under the proxy's 100 s limit that waiting plus working never times out.
        $deadline = microtime(true) + 20;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            usleep(250000);
        } while (microtime(true) < $deadline);
        fclose($handle);
        return null;
    }

    private function processFolderBatch(
        Folder $folder,
        int $limit,
        string $watermarkPosition,
        int $watermarkOpacity,
        string $watermarkColour,
    ): array {
        $backupName = $folder->getName() . ' ต้นฉบับ';
        $parent = $folder->getParent();
        // Never descend into the backup tree, or a second run would re-process the originals.
        $existingBackup = $parent->nodeExists($backupName) ? $parent->get($backupName) : null;
        $backupId = $existingBackup instanceof Folder ? $existingBackup->getId() : 0;

        $pending = [];
        $scanned = 0;
        $blocked = [];
        $this->collectUnprocessed($folder, $backupId, $pending, $scanned, 0, $limit, $blocked);

        // A truncated file can never be watermarked. Leaving it in place is what let dead photos
        // accumulate inside albums, so it is moved into a sibling "<folder> ไฟล์เสีย" tree.
        $blockedNames = [];
        foreach ($blocked as $brokenFile) {
            $blockedNames[] = $brokenFile->getName();
            try {
                $this->quarantineFile($folder, $brokenFile);
            } catch (\Throwable) {
                // leave it where it is; it is already excluded from the batch either way
            }
        }

        $results = [];
        $processed = 0;
        $failed = 0;
        $skipped = 0;

        foreach (array_slice($pending, 0, $limit) as $file) {
            $originalName = $file->getName();
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            // Listed in the file cache but no longer on disk: already handled or moved away.
            if (!$file->getStorage()->file_exists($file->getInternalPath())) {
                $skipped++;
                continue;
            }
            $tmpIn = tempnam(sys_get_temp_dir(), 'iep-in-');
            $tmpOut = tempnam(sys_get_temp_dir(), 'iep-out-');
            try {
                $stream = $file->fopen('r');
                if (!is_resource($stream)) {
                    throw new \RuntimeException('อ่านไฟล์ต้นทางไม่สำเร็จ');
                }
                $copied = file_put_contents($tmpIn, $stream);
                fclose($stream);
                if ($copied === false) {
                    throw new \RuntimeException('อ่านไฟล์ต้นทางไม่สำเร็จ');
                }

                // 1. keep the untouched original before anything is overwritten
                $backupFolder = $this->backupFolderFor($folder, $backupName, $file);
                if (!$backupFolder->nodeExists($originalName)) {
                    $this->writeFile($backupFolder, $originalName, $tmpIn);
                }

                if (!$this->isCompleteImage($tmpIn, $extension) && !$this->isRepairable($tmpIn)) {
                    throw new \RuntimeException('ไฟล์ต้นฉบับไม่สมบูรณ์ (อัปโหลดมาไม่ครบ) ข้ามไว้ก่อน กรุณาอัปโหลดใหม่');
                }

                // 2. watermark into a temp file
                $this->watermark($tmpIn, $tmpOut, $extension, $watermarkPosition, $watermarkOpacity, $watermarkColour);

                // 3. write the renamed result next to the original, then drop the original
                $target = $file->getParent();
                $newName = $this->allocateName($extension);
                $this->writeFile($target, $newName, $tmpOut);
                $file->delete();
                $this->warmPreviews($target, $newName);

                $this->logMapping([
                    'via' => 'batch',
                    'source' => $originalName,
                    'output' => $newName,
                    'folder' => $folder->getName(),
                    'backup' => $backupName,
                    'position' => $watermarkPosition,
                    'colour' => $watermarkColour,
                ]);

                $processed++;
                $results[] = ['source' => $originalName, 'name' => $newName, 'ok' => true];
            } catch (\OCP\Lock\LockedException) {
                // Still being written (an upload, the sync client, the auto job). Not an error:
                // it stays in place and a later round picks it up.
                $skipped++;
            } catch (\Throwable $e) {
                $failed++;
                $results[] = ['source' => $originalName, 'error' => $e->getMessage(), 'ok' => false];
            } finally {
                if (is_file($tmpIn)) {
                    unlink($tmpIn);
                }
                if (is_file($tmpOut)) {
                    unlink($tmpOut);
                }
            }
        }

        $remaining = max(0, count($pending) - $processed - $failed);
        return [
            'scanned' => $scanned,
            'processed' => $processed,
            'failed' => $failed,
            'skipped' => $skipped,
            'busy' => false,
            'remaining' => $remaining,
            // Nothing processable was found, so another round would only repeat this one.
            'done' => ($pending === []),
            'blocked' => count($blockedNames),
            'blockedNames' => array_slice($blockedNames, 0, 20),
            'results' => $results,
        ];
    }

    /**
     * Collect images that still carry their camera filename. Stops early once enough candidates
     * for this batch are found so a huge folder does not cost a full walk on every call.
     *
     * @param list<File> $out
     */
    private function collectUnprocessed(
        Folder $folder,
        int $skipFolderId,
        array &$out,
        int &$scanned,
        int $depth,
        int $limit,
        array &$blocked = [],
    ): void {
        if ($depth > 8 || count($out) > $limit) {
            return;
        }
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof Folder) {
                if ($node->getId() === $skipFolderId || str_contains($node->getName(), 'ไฟล์เสีย')) {
                    continue;
                }
                $this->collectUnprocessed($node, $skipFolderId, $out, $scanned, $depth + 1, $limit, $blocked);
                if (count($out) > $limit) {
                    return;
                }
                continue;
            }
            if (!$node instanceof File) {
                continue;
            }
            $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
            if (!in_array($extension, self::EXTENSIONS, true)) {
                continue;
            }
            $scanned++;
            if (preg_match('/^' . preg_quote(self::PREFIX, '/') . '[0-9]{4,}\./i', $node->getName()) === 1) {
                continue;
            }
            // A truncated upload can never be watermarked, and leaving it in the batch meant every
            // round handed back the same unfixable files: nothing progressed and the run never
            // ended. Count it and move on so the batch always advances.
            // Cheap marker test first; only a file that fails it pays for a full decode, and a
            // decodable one is handed to the batch to be repaired rather than quarantined.
            if (!$this->nodeLooksComplete($node, $extension) && !$this->nodeIsRepairable($node)) {
                $blocked[] = $node;
                continue;
            }
            $out[] = $node;
            if (count($out) > $limit) {
                return;
            }
        }
    }

    /**
     * Reads only the trailing marker off the stored file. Called just for the handful of
     * candidates a batch is about to take, never for the whole library.
     */
    /** Decode-test a stored node by way of a temp copy, used only on marker-test failures. */
    private function nodeIsRepairable(File $node): bool {
        if ($node->getSize() <= 0) {
            return false;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'iephoto-probe-');
        if ($tmp === false) {
            return false;
        }
        try {
            $source = $node->fopen('r');
            $target = fopen($tmp, 'wb');
            if (!is_resource($source) || !is_resource($target)) {
                return false;
            }
            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);
            return $this->isRepairable($tmp);
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($tmp);
        }
    }

    private function nodeLooksComplete(File $node, string $extension): bool {
        if ($node->getSize() <= 0) {
            return false;
        }
        try {
            $handle = $node->fopen('r');
            if (!is_resource($handle)) {
                return true;        // unreadable here: let the processing step decide
            }
            try {
                if ($extension === 'jpg' || $extension === 'jpeg') {
                    fseek($handle, -2, SEEK_END);
                    return bin2hex((string)fread($handle, 2)) === 'ffd9';
                }
                if ($extension === 'png') {
                    fseek($handle, -8, SEEK_END);
                    return bin2hex((string)fread($handle, 8)) === '49454e44ae426082';
                }
                return true;
            } finally {
                fclose($handle);
            }
        } catch (\Throwable) {
            return true;
        }
    }

    /** Move a truncated upload into a sibling quarantine tree, keeping its subfolder layout. */
    private function quarantineFile(Folder $base, File $file): void {
        $parent = $base->getParent();
        $name = $base->getName() . ' ไฟล์เสีย';
        $quarantine = $parent->nodeExists($name) ? $parent->get($name) : $parent->newFolder($name);
        if (!$quarantine instanceof Folder) {
            throw new \RuntimeException('สร้างโฟลเดอร์ไฟล์เสียไม่สำเร็จ');
        }
        $basePath = rtrim($base->getPath(), '/');
        $relative = trim(substr($file->getParent()->getPath(), strlen($basePath)), '/');
        $current = $quarantine;
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            $current = $current->nodeExists($part) ? $current->get($part) : $current->newFolder($part);
            if (!$current instanceof Folder) {
                throw new \RuntimeException('สร้างโฟลเดอร์ไฟล์เสียไม่สำเร็จ');
            }
        }
        $target = $file->getName();
        if ($current->nodeExists($target)) {
            $extension = pathinfo($target, PATHINFO_EXTENSION);
            $target = pathinfo($target, PATHINFO_FILENAME) . '-' . time() . '.' . $extension;
        }
        $file->move($current->getPath() . '/' . $target);
    }

    /** The shared originals folder, or null when the share is gone so the caller can fall back. */
    private function originalsFolder(): ?Folder {
        try {
            $node = $this->shareManager->getShareByToken(self::ORIGINALS_SHARE_TOKEN)->getNode();
        } catch (\Throwable) {
            return null;
        }
        return $node instanceof Folder ? $node : null;
    }

    /** Mirror the source folder structure inside the backup tree. */
    private function backupFolderFor(Folder $base, string $backupName, File $file): Folder {
        // Originals used to be written to a sibling folder inside the album, which left a
        // "<folder> ต้นฉบับ" tree sitting next to every processed shoot. They all live in the
        // shared originals folder now, so albums hold only the finished photos.
        $parent = $this->originalsFolder() ?? $base->getParent();
        $backup = $parent->nodeExists($backupName) ? $parent->get($backupName) : $parent->newFolder($backupName);
        if (!$backup instanceof Folder) {
            throw new \RuntimeException('สร้างโฟลเดอร์สำรองต้นฉบับไม่สำเร็จ');
        }
        $basePath = rtrim($base->getPath(), '/');
        $filePath = $file->getParent()->getPath();
        $relative = trim(substr($filePath, strlen($basePath)), '/');
        if ($relative === '') {
            return $backup;
        }
        $current = $backup;
        foreach (explode('/', $relative) as $part) {
            if ($part === '') {
                continue;
            }
            $current = $current->nodeExists($part) ? $current->get($part) : $current->newFolder($part);
            if (!$current instanceof Folder) {
                throw new \RuntimeException('สร้างโฟลเดอร์สำรองต้นฉบับไม่สำเร็จ');
            }
        }
        return $current;
    }

    /**
     * Write a local file into a Nextcloud folder, tolerating the files_versions post-write hook
     * that can throw NotFoundException *after* the bytes already landed.
     */
    /**
     * Build the previews the share and gallery UIs ask for, while this batch still owns the photo.
     *
     * Nextcloud renders previews on demand and inserts the row without handling a conflict, so a
     * freshly processed album opened by several people at once has every viewer starting the same
     * render and all but one getting a 500 from a unique-constraint violation. Rendering them here,
     * one at a time, means the first viewer finds them cached. Never fatal: a missing preview must
     * not undo a watermark that already succeeded.
     */
    private function warmPreviews(Folder $folder, string $name): void {
        try {
            $node = $folder->get($name);
        } catch (\Throwable) {
            return;
        }
        if (!$node instanceof File) {
            return;
        }
        // The sizes the gallery actually asks for: 720x560 is every grid tile, 320x320 the folder
        // mosaics, 2048 uncropped the photo viewer, 128 the Nextcloud Files thumbnail. Warming the
        // wrong sizes was as good as warming nothing — each tile still rendered from the 13 MB
        // original on first view, about 1.3 s each, so a 60-tile page crawled.
        foreach ([[128, 128, true], [320, 320, true], [720, 560, true], [2048, 2048, false]] as [$w, $h, $crop]) {
            try {
                $this->preview->getPreview($node, $w, $h, $crop);
            } catch (\Throwable) {
                // cosmetic only
            }
        }
    }

    private function writeFile(Folder $folder, string $name, string $localPath): void {
        $stream = fopen($localPath, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('อ่านไฟล์ผลลัพธ์ไม่สำเร็จ');
        }
        $existedBefore = $folder->nodeExists($name);
        try {
            $folder->newFile($name, $stream);
        } catch (NotFoundException $e) {
            if ($existedBefore || !$folder->nodeExists($name)) {
                throw $e;
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function userFolderFor(string $uid): Folder {
        if ($uid === '' || $this->userManager->get($uid) === null) {
            throw new \RuntimeException('ไม่พบบัญชีเจ้าของพื้นที่');
        }
        return $this->rootFolder->getUserFolder($uid);
    }

    /**
     * Albums live next to each other under one event folder, so a new album is created as a
     * sibling of the currently configured destination.
     */
    // Sports/albums live directly INSIDE the configured target (event) folder — e.g. the admin
    // points the destination at "ชอล์กเกมส์ปี2569" and each sport (บาส, ฟุตบอล, …) is a child of
    // it. (An earlier version treated the target as one sport and put its siblings as the other
    // sports; that broke once the destination was set to the event folder itself, leaving the
    // sport dropdown empty.)
    private function albumsRootFolder(): Folder {
        return $this->configuredTargetFolder();
    }

    public function albumsRootLabel(): string {
        try {
            $uid = $this->config->getAppValue(Application::APP_ID, 'target_uid', '');
            $clean = $this->cleanPath($this->config->getAppValue(Application::APP_ID, 'target_path', '/'));
            return $uid . ' : /' . $clean;
        } catch (\Throwable) {
            return '';
        }
    }

    /** \return array<string,string> token => album folder name */
    private function uploadTokens(): array {
        $decoded = json_decode($this->config->getAppValue(Application::APP_ID, 'album_upload_tokens', '{}'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $token => $name) {
            if (is_string($token) && is_string($name)) {
                $out[$token] = $name;
            }
        }
        return $out;
    }

    /** \param array<string,string> $tokens */
    private function saveUploadTokens(array $tokens): void {
        $this->config->setAppValue(
            Application::APP_ID,
            'album_upload_tokens',
            json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Resolve the album an upload link points at.
     *
     * The upload token is deliberately NOT the public share token: the share token is handed to
     * everyone allowed to *view* the album, and reusing it here would let any viewer write into
     * it. Uploads stay confined to a direct child of the albums root.
     */
    private function albumFolderFromUploadToken(string $token): ?Folder {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $name = $this->uploadTokens()[$token] ?? '';
        if ($name === '') {
            throw new \RuntimeException('ลิงก์อัปโหลดนี้ใช้ไม่ได้แล้ว');
        }
        $root = $this->albumsRootFolder();
        if (!$root->nodeExists($name)) {
            throw new \RuntimeException('ไม่พบอัลบั้มปลายทาง');
        }
        $node = $root->get($name);
        if (!$node instanceof Folder) {
            throw new \RuntimeException('ปลายทางไม่ใช่โฟลเดอร์');
        }
        return $node;
    }

    private const VENUES = ['indoor', 'outdoor'];

    /** @return array<string,string> sport name => 'indoor'|'outdoor' */
    private function venues(): array {
        $decoded = json_decode($this->config->getAppValue(Application::APP_ID, 'album_venues', '{}'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $name => $venue) {
            if (is_string($name) && in_array($venue, self::VENUES, true)) {
                $out[$name] = $venue;
            }
        }
        return $out;
    }

    /** @param array<string,string> $venues */
    private function saveVenues(array $venues): void {
        $this->config->setAppValue(
            Application::APP_ID,
            'album_venues',
            json_encode($venues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /** Outdoor sports get a black watermark; indoor sports a white one. Default outdoor. */
    public function venueForSport(string $name): string {
        return $this->venues()[$name] ?? 'outdoor';
    }

    private function colourForVenue(string $venue): string {
        return $venue === 'indoor' ? 'white' : 'black';
    }

    /**
     * Public sport list for the upload dropdown: only sports the admin has actually registered a
     * venue for, so the folder + colour are always known. The name is not secret; the safety is
     * that resolveSportFolder() below refuses any name not in this registry.
     *
     * @return list<array{name:string,venue:string}>
     */
    public function publicSports(): array {
        $out = [];
        $venues = $this->venues();
        try {
            $root = $this->albumsRootFolder();
        } catch (\Throwable) {
            return $out;
        }
        foreach ($venues as $name => $venue) {
            if ($root->nodeExists($name) && $root->get($name) instanceof Folder) {
                $out[] = ['name' => $name, 'venue' => $venue];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
        return $out;
    }

    /**
     * Resolve a sport chosen from the universal upload link. Only names in the venue registry are
     * accepted, so a crafted request cannot write into an arbitrary folder.
     */
    /**
     * Reject images whose bytes never fully arrived.
     *
     * An upload cut short (phone loses signal mid-sync) leaves a file of the right length whose
     * tail is zero-padded. libjpeg decodes the rows it has and renders the rest as flat grey, so
     * nothing throws — the photo just silently loses its bottom half. Watermarking and renaming
     * such a file bakes the damage in and hides which photo needs re-uploading, so check the
     * format's end-of-file marker first and leave broken files untouched under their original name.
     */
    /**
     * A missing end-of-file marker does not mean the photo is lost. Nearly every "broken" upload
     * here turned out to be a complete image whose last two bytes never arrived, and re-encoding
     * it produces a perfectly good file. Only a transfer that actually stopped mid-image leaves
     * rows that libjpeg fills with flat grey, and those are the ones worth refusing.
     *
     * @return int number of unusable rows at the bottom, or -1 when the file cannot be decoded
     */
    private function lostBottomRows(string $path): int {
        try {
            $image = new \Imagick();
            $image->readImage($path);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            if ($width < 1 || $height < 1) {
                $image->clear();
                return -1;
            }
            $lost = 0;
            $sample = min($width, 400);
            for ($y = $height - 1; $y >= 0 && $lost < 64; $y--) {
                $row = $image->getImageRegion($sample, 1, 0, $y);
                $flat = count($row->getImageHistogram()) <= 1;
                $row->clear();
                if (!$flat) {
                    break;
                }
                $lost++;
            }
            $image->clear();
            return $lost;
        } catch (\Throwable) {
            return -1;
        }
    }

    /** True when the image decodes with no dead rows, so re-encoding it yields a valid photo. */
    private function isRepairable(string $path): bool {
        return $this->lostBottomRows($path) === 0;
    }

    public function isCompleteImage(string $path, string $extension): bool {
        if (!is_file($path)) {
            return false;
        }
        $size = filesize($path);
        if ($size === false || $size < 128) {
            return false;
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        try {
            $extension = strtolower($extension);
            if ($extension === 'jpg' || $extension === 'jpeg') {
                fseek($handle, -2, SEEK_END);
                return bin2hex((string)fread($handle, 2)) === 'ffd9';       // JPEG EOI
            }
            if ($extension === 'png') {
                fseek($handle, -8, SEEK_END);
                return bin2hex((string)fread($handle, 8)) === '49454e44ae426082'; // PNG IEND
            }
            if ($extension === 'webp') {
                fseek($handle, 0, SEEK_SET);
                $header = (string)fread($handle, 12);
                if (strlen($header) < 12 || substr($header, 0, 4) !== 'RIFF') {
                    return false;
                }
                $declared = unpack('V', substr($header, 4, 4));
                return is_array($declared) && ($declared[1] + 8) <= $size;
            }
            return true;
        } finally {
            fclose($handle);
        }
    }

    private function resolveSportFolder(string $sport, string $newVenue = ''): Folder {
        $sport = trim($sport);
        if ($sport === '') {
            throw new \RuntimeException('กีฬาที่เลือกไม่ถูกต้อง');
        }
        if (!array_key_exists($sport, $this->venues())) {
            // A photographer typed a sport that is not registered yet. Accept it only with an
            // explicit indoor/outdoor choice (that is what decides the watermark colour), and
            // validate the name as strictly as the admin-side createAlbum() does.
            if (!in_array($newVenue, self::VENUES, true)) {
                throw new \RuntimeException('กีฬาที่เลือกไม่ถูกต้อง');
            }
            $this->createAlbum($sport, $newVenue);
        }
        $root = $this->albumsRootFolder();
        if (!$root->nodeExists($sport)) {
            throw new \RuntimeException('ไม่พบโฟลเดอร์กีฬาปลายทาง');
        }
        $node = $root->get($sport);
        if (!$node instanceof Folder) {
            throw new \RuntimeException('ปลายทางไม่ใช่โฟลเดอร์');
        }
        return $node;
    }

    /**
     * Create an album folder, publish a read-only share for viewing, and mint a separate
     * upload token.
     *
     * \return array{name:string,viewToken:string,uploadToken:string,created:bool}
     */
    public function createAlbum(string $name, string $venue = 'outdoor'): array {
        $name = trim($name);
        if (!mb_check_encoding($name, 'UTF-8') || $name === '') {
            throw new \RuntimeException('กรุณาระบุชื่ออัลบั้ม');
        }
        if (mb_strlen($name) > 80) {
            throw new \RuntimeException('ชื่ออัลบั้มยาวเกินไป');
        }
        if (strpbrk($name, '/' . '\\') !== false || str_starts_with($name, '.')) {
            throw new \RuntimeException('ชื่ออัลบั้มห้ามมี / หรือ ' . '\\' . ' และห้ามขึ้นต้นด้วยจุด');
        }
        if (!in_array($venue, self::VENUES, true)) {
            $venue = 'outdoor';
        }
        $venues = $this->venues();
        $venues[$name] = $venue;
        $this->saveVenues($venues);

        $root = $this->albumsRootFolder();
        $created = false;
        if ($root->nodeExists($name)) {
            $folder = $root->get($name);
            if (!$folder instanceof Folder) {
                throw new \RuntimeException('มีไฟล์ชื่อเดียวกันอยู่แล้ว');
            }
        } else {
            $folder = $root->newFolder($name);
            $created = true;
        }

        $viewToken = $this->publicShareTokenFor($folder);

        $tokens = $this->uploadTokens();
        $uploadToken = array_search($name, $tokens, true);
        if (!is_string($uploadToken) || $uploadToken === '') {
            $uploadToken = bin2hex(random_bytes(12));
            $tokens[$uploadToken] = $name;
            $this->saveUploadTokens($tokens);
        }

        return [
            'name' => $name,
            'venue' => $venue,
            'viewToken' => $viewToken,
            'uploadToken' => $uploadToken,
            'created' => $created,
        ];
    }

    /**
     * Token of the folder's upload-only ("file drop") public link, creating it if missing.
     *
     * Kept separate from the view link on purpose: the view link is handed to everyone who should
     * see the photos, so giving it write access would let any viewer add or replace files. This
     * one grants CREATE only — photographers can drop files in but cannot browse or delete what
     * others uploaded. Labelled "upload" so the two links stay tellable apart.
     */
    private function dropShareTokenFor(Folder $folder): string {
        $uid = $this->config->getAppValue(Application::APP_ID, 'target_uid', '');
        try {
            foreach ($this->shareManager->getSharesBy($uid, \OCP\Share\IShare::TYPE_LINK, $folder, false, 50, 0) as $share) {
                if ($share->getLabel() === 'upload') {
                    return (string)$share->getToken();
                }
            }
            $share = $this->shareManager->newShare();
            $share->setNode($folder);
            $share->setShareType(\OCP\Share\IShare::TYPE_LINK);
            $share->setPermissions(\OCP\Constants::PERMISSION_CREATE);
            $share->setSharedBy($uid);
            $share->setLabel('upload');
            return (string)$this->shareManager->createShare($share)->getToken();
        } catch (\Throwable) {
            return '';
        }
    }

    /** Reuse an existing public link for the folder when there is one, else publish a new one. */
    private function publicShareTokenFor(Folder $folder): string {
        $uid = $this->config->getAppValue(Application::APP_ID, 'target_uid', '');
        $existing = $this->shareManager->getSharesBy($uid, \OCP\Share\IShare::TYPE_LINK, $folder, false, 50, 0);
        foreach ($existing as $share) {
            $token = (string)$share->getToken();
            if ($token !== '') {
                return $token;
            }
        }
        $share = $this->shareManager->newShare();
        $share->setNode($folder);
        $share->setShareType(\OCP\Share\IShare::TYPE_LINK);
        $share->setPermissions(\OCP\Constants::PERMISSION_READ);
        $share->setSharedBy($uid);
        $share->setLabel($folder->getName());
        $share = $this->shareManager->createShare($share);
        return (string)$share->getToken();
    }

    /**
     * Albums that already exist under the root, with their links when known.
     *
     * \return list<array<string,mixed>>
     */
    /**
     * Folders that still hold photos the pipeline has not touched.
     *
     * Beats asking the admin to paste a folder link: the server already knows where everything
     * lives, so it reports what is actually outstanding — and separates files that simply have not
     * been processed yet from ones whose bytes never fully arrived, because those two need
     * different actions (run the batch vs re-upload from the camera).
     *
     * @return array{folders:list<array{folder:string,pending:int,broken:int}>,pending:int,broken:int,truncated:bool}
     */
    /**
     * Scans the owner's whole space, not the configured upload target. The target is a single
     * album folder, so a scan rooted there reported "nothing pending" while thousands of photos
     * sat unprocessed in every other album.
     */
    public function pendingFolders(string $uid = '', int $maxFiles = 40000): array {
        $folders = [];
        $seen = 0;
        $uid = $uid !== '' ? $uid : $this->config->getAppValue(Application::APP_ID, 'target_uid', '');
        $empty = ['folders' => [], 'pending' => 0, 'broken' => 0, 'truncated' => false, 'root' => ''];
        if ($uid === '') {
            return $empty;
        }
        // Only the configured folders are scanned, so personal folders in the same account never
        // turn up as work waiting to be watermarked.
        $scopes = $this->scanScopes();
        $roots = [];
        foreach ($scopes === [] ? [''] : $scopes as $scope) {
            try {
                $roots[$scope] = $this->resolveUserFolder($uid, $scope);
            } catch (\Throwable) {
                continue;           // a configured folder that no longer exists
            }
        }
        if ($roots === []) {
            return $empty;
        }

        $walk = function (Folder $folder, string $path) use (&$walk, &$folders, &$seen, $maxFiles): void {
            $pending = 0;
            $broken = 0;
            foreach ($folder->getDirectoryListing() as $node) {
                if ($seen >= $maxFiles) {
                    return;
                }
                if ($node instanceof Folder) {
                    if (str_contains($node->getName(), 'ต้นฉบับ') || str_contains($node->getName(), 'ไฟล์เสีย')) {
                        continue;   // backups and quarantined uploads stay untouched
                    }
                    $walk($node, $path === '' ? $node->getName() : $path . '/' . $node->getName());
                    continue;
                }
                if (!$node instanceof File) {
                    continue;
                }
                $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
                if (!in_array($extension, self::EXTENSIONS, true)) {
                    continue;
                }
                if (str_starts_with($node->getName(), self::PREFIX)) {
                    continue;       // already processed
                }
                $seen++;
                if ($node->getSize() > 0) {
                    $pending++;
                } else {
                    $broken++;     // nothing was ever written: the upload died part way
                }
            }
            if ($pending > 0 || $broken > 0) {
                $folders[] = ['folder' => $path, 'pending' => $pending, 'broken' => $broken];
            }
        };
        foreach ($roots as $scope => $folder) {
            $walk($folder, (string)$scope);
        }

        usort($folders, static fn (array $a, array $b): int => ($b['pending'] + $b['broken']) <=> ($a['pending'] + $a['broken']));
        return [
            'folders' => $folders,
            'pending' => array_sum(array_column($folders, 'pending')),
            'broken' => array_sum(array_column($folders, 'broken')),
            'truncated' => $seen >= $maxFiles,
            'root' => $uid,
            'scopes' => array_keys($roots),
        ];
    }

    public function listAlbums(): array {
        $out = [];
        try {
            $root = $this->albumsRootFolder();
        } catch (\Throwable) {
            return $out;
        }
        $tokens = array_flip($this->uploadTokens());
        foreach ($root->getDirectoryListing() as $node) {
            if (!$node instanceof Folder) {
                continue;
            }
            $name = $node->getName();
            $viewToken = '';
            try {
                $viewToken = $this->publicShareTokenFor($node);
            } catch (\Throwable) {
                // an album without a share simply has no view link yet
            }
            $out[] = [
                'name' => $name,
                'venue' => $this->venueForSport($name),
                'photos' => count($node->getDirectoryListing()),
                'viewToken' => is_string($viewToken) ? $viewToken : '',
                'uploadToken' => is_string($tokens[$name] ?? null) ? $tokens[$name] : '',
                'dropToken' => $this->dropShareTokenFor($node),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
        return $out;
    }

    private function configuredTargetFolder(): Folder {
        $uid = $this->config->getAppValue(Application::APP_ID, 'target_uid', '');
        $path = $this->config->getAppValue(Application::APP_ID, 'target_path', '/');
        if ($uid === '') {
            throw new \RuntimeException('แอดมินยังไม่ได้กำหนดโฟลเดอร์ปลายทาง');
        }
        return $this->resolveUserFolder($uid, $path);
    }

    private function uploaderTargetFolder(string $uploaderName, ?Folder $base = null): Folder {
        $base ??= $this->configuredTargetFolder();
        if ($base->nodeExists($uploaderName)) {
            $node = $base->get($uploaderName);
            if (!$node instanceof Folder) {
                throw new \RuntimeException('มีไฟล์ชื่อเดียวกับผู้ถ่ายภาพ กรุณาใช้ชื่ออื่น');
            }
            return $node;
        }
        try {
            return $base->newFolder($uploaderName);
        } catch (\Throwable $e) {
            if (!$base->nodeExists($uploaderName)) {
                throw $e;
            }
            $node = $base->get($uploaderName);
            if (!$node instanceof Folder) {
                throw new \RuntimeException('สร้างโฟลเดอร์ผู้ถ่ายภาพไม่สำเร็จ');
            }
            return $node;
        }
    }

    private function normalizeUploaderName(string $name): string {
        if (!mb_check_encoding($name, 'UTF-8')) {
            throw new \RuntimeException('ชื่อผู้ถ่ายภาพไม่ถูกต้อง');
        }
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        if ($name === '') {
            throw new \RuntimeException('กรุณาเลือกหรือพิมพ์ชื่อผู้ถ่ายภาพ');
        }
        if ($name === '.' || $name === '..' || mb_strlen($name, 'UTF-8') > 80) {
            throw new \RuntimeException('ชื่อผู้ถ่ายภาพไม่ถูกต้อง');
        }
        if (preg_match('/[\/\\\\\x00-\x1F]/u', $name) !== 0) {
            throw new \RuntimeException('ชื่อผู้ถ่ายภาพห้ามมี / หรือ \\');
        }
        return $name;
    }

    private function resolveUserFolder(string $uid, string $path): Folder {
        $root = $this->userFolderFor($uid);
        $clean = $this->cleanPath($path);
        if ($clean === '') {
            return $root;
        }
        $node = $root->get($clean);
        if (!$node instanceof Folder) {
            throw new \RuntimeException('ปลายทางไม่ใช่โฟลเดอร์');
        }
        return $node;
    }

    private function relativePath(Folder $folder, string $uid): string {
        $prefix = '/' . $uid . '/files';
        $path = $folder->getPath();
        if (!str_starts_with($path, $prefix)) {
            throw new \RuntimeException('โฟลเดอร์อยู่นอกพื้นที่ของผู้ใช้');
        }
        $relative = substr($path, strlen($prefix));
        return $relative === '' ? '/' : $relative;
    }

    private function cleanPath(string $path): string {
        $path = str_replace('\\', '/', trim($path));
        $parts = [];
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '') {
                continue;
            }
            if ($part === '.' || $part === '..' || str_contains($part, "\0")) {
                throw new \RuntimeException('path ไม่ถูกต้อง');
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    /** @param list<string> $paths */
    private function collectFolders(Folder $folder, string $prefix, array &$paths, int $depth): void {
        if ($depth >= 12) {
            return;
        }
        foreach ($folder->getDirectoryListing() as $node) {
            if (!$node instanceof Folder) {
                continue;
            }
            $relative = $prefix . '/' . $node->getName();
            $paths[] = $relative;
            $this->collectFolders($node, $relative, $paths, $depth + 1);
        }
    }

    private function archiveOriginal(string $tmpPath, string $originalName): void {
        try {
            $folder = $this->appData->getFolder('originals');
        } catch (NotFoundException) {
            $folder = $this->appData->newFolder('originals');
        }
        $uid = $this->userSession->getUser()?->getUID() ?? 'unknown';
        $safe = rawurlencode($uid) . '-' . time() . '-' . bin2hex(random_bytes(4)) . '-' . basename($originalName);
        $stream = fopen($tmpPath, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException('เก็บต้นฉบับไม่สำเร็จ');
        }
        $folder->newFile($safe, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    /** @return array{string,int} */
    /**
     * Allocate the next sequential filename atomically across concurrent requests.
     *
     * Parallel uploads used to be unsafe: each request read next_counter from Nextcloud's
     * per-request appconfig cache (loaded once at request start), so two requests in flight
     * could read the same number and one file would overwrite or be lost. The counter now lives
     * in a lock file read INSIDE flock(), which is always fresh and serialised — so every
     * concurrent upload gets a unique number. appconfig is still updated for the admin display.
     */
    private function allocateName(string $extension): string {
        $dir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/') . '/iephoto_watermark';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $fp = @fopen($dir . '/counter.seq', 'c+');
        if ($fp === false) {
            // Lock file unavailable — fall back to the old path. Safe when uploads are serial.
            $n = max(1, (int)$this->config->getAppValue(Application::APP_ID, 'next_counter', '1'));
            $this->config->setAppValue(Application::APP_ID, 'next_counter', (string)($n + 1));
            return self::PREFIX . str_pad((string)$n, 4, '0', STR_PAD_LEFT) . '.' . $extension;
        }
        try {
            flock($fp, LOCK_EX);
            $n = (int)trim((string)stream_get_contents($fp));
            if ($n < 1) {
                // First use: seed from the counter the app has tracked until now.
                $n = max(1, (int)$this->config->getAppValue(Application::APP_ID, 'next_counter', '1'));
            }
            $nextVal = $n + 1;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)$nextVal);
            fflush($fp);
            $this->config->setAppValue(Application::APP_ID, 'next_counter', (string)$nextVal);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return self::PREFIX . str_pad((string)$n, 4, '0', STR_PAD_LEFT) . '.' . $extension;
    }

    private function watermark(string $inputPath, string $outputPath, string $extension, string $position, int $opacity = 100, string $colour = 'black'): void {
        $image = new \Imagick($inputPath);
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        }
        $image->setIteratorIndex(0);
        $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
        $logo = new \Imagick(dirname(__DIR__, 2) . '/img/watermark.png');
        $logo->setIteratorIndex(0);
        $logo->trimImage(0);
        $targetWidth = max(1, (int)round($image->getImageWidth() * self::WATERMARK_WIDTH_RATIO));
        $targetHeight = max(1, (int)round($logo->getImageHeight() * ($targetWidth / $logo->getImageWidth())));
        $logo->resizeImage($targetWidth, $targetHeight, \Imagick::FILTER_LANCZOS, 1);
        // The source PNG is near-white line art; repaint it in the requested colour while keeping
        // its exact alpha shape, so the mark reads against the photo.
        $logo = $this->tint($logo, $colour === 'white' ? 'white' : 'black');
        // Scale the logo's existing alpha channel by the chosen strength, so the PNG's own
        // transparency shape is preserved while the whole mark gets lighter/darker.
        $fraction = max(0.10, min(1.0, $opacity / 100));
        if ($fraction < 1.0) {
            $logo->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $fraction, \Imagick::CHANNEL_ALPHA);
        }
        $margin = (int)round(min($image->getImageWidth(), $image->getImageHeight()) * 0.02);
        [$x, $y] = $this->watermarkCoordinates(
            $image->getImageWidth(),
            $image->getImageHeight(),
            $logo->getImageWidth(),
            $logo->getImageHeight(),
            $margin,
            $position,
        );
        $image->compositeImage($logo, \Imagick::COMPOSITE_OVER, $x, $y);
        $format = $extension === 'jpg' ? 'jpeg' : $extension;
        $image->setImageFormat($format);
        if ($format === 'jpeg') {
            $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality(96);
            // 4:4:4 — no chroma subsampling, so coloured edges and fine detail stay crisp
            // instead of being softened the way the 4:2:0 default does.
            $image->setSamplingFactors(['1x1', '1x1', '1x1']);
        }
        // stripImage() also drops the colour profile, which dulls the image. Keep the ICC
        // profile and strip only the rest (GPS, camera serial, thumbnails).
        $profiles = $image->getImageProfiles('icc', true);
        $image->stripImage();
        if (!empty($profiles['icc'])) {
            $image->profileImage('icc', $profiles['icc']);
        }
        $image->writeImage($outputPath);
        $logo->clear();
        $image->clear();
    }

    /** @return array{int,int} */
    /**
     * Return a recoloured copy of $logo that keeps the original's alpha channel, so only the
     * colour changes and the artwork's soft edges survive.
     */
    private function tint(\Imagick $logo, string $colour): \Imagick {
        $paint = new \Imagick();
        $paint->newImage($logo->getImageWidth(), $logo->getImageHeight(), new \ImagickPixel($colour));
        $paint->setImageFormat('png');
        $paint->setImageColorspace(\Imagick::COLORSPACE_SRGB);
        $paint->compositeImage($logo, \Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        $logo->clear();
        return $paint;
    }

    private function watermarkCoordinates(
        int $imageWidth,
        int $imageHeight,
        int $logoWidth,
        int $logoHeight,
        int $margin,
        string $position,
    ): array {
        $x = match (true) {
            str_ends_with($position, '-left') => $margin,
            str_ends_with($position, '-right') => max(0, $imageWidth - $logoWidth - $margin),
            default => max(0, (int)round(($imageWidth - $logoWidth) / 2)),
        };
        $y = match (true) {
            str_starts_with($position, 'top-') => $margin,
            str_starts_with($position, 'bottom-') => max(0, $imageHeight - $logoHeight - $margin),
            default => max(0, (int)round(($imageHeight - $logoHeight) / 2)),
        };
        return [$x, $y];
    }

    /** @return array<string,mixed> */
    public function adminStats(): array {
        $history = $this->uploadHistory();
        if (!$this->isConfigured()) {
            return [
                'configured' => false,
                'destination' => $this->destinationLabel(),
                'summary' => ['photos' => 0, 'bytes' => 0, 'folders' => 0, 'lastModified' => 0],
                'photographers' => [],
                'history' => $history,
            ];
        }

        $target = $this->configuredTargetFolder();
        $summary = ['photos' => 0, 'bytes' => 0, 'folders' => 0, 'lastModified' => $target->getMTime()];
        $photographers = [];
        foreach ($target->getDirectoryListing() as $node) {
            if (!$node instanceof Folder) {
                continue;
            }
            $summary['folders']++;
            $item = ['name' => $node->getName(), 'photos' => 0, 'bytes' => 0, 'lastModified' => $node->getMTime()];
            $this->collectStorageStats($node, $summary, $item, 0);
            $photographers[] = $item;
        }
        usort($photographers, static fn (array $a, array $b): int => ($b['photos'] ?? 0) <=> ($a['photos'] ?? 0));

        return [
            'configured' => true,
            'destination' => $this->destinationLabel(),
            'summary' => $summary,
            'photographers' => $photographers,
            'history' => $history,
        ];
    }

    /**
     * Append the rename to a permanent, append-only log.
     *
     * recordHistory() keeps only the newest 200 entries in a single appconfig blob, and the batch
     * path never called it at all — so after re-processing an album there was no way to tell which
     * output file came from which original. That cost a full 1250-file re-run when only 75 files
     * actually needed a different watermark colour. One JSON object per line, never rewritten.
     */
    private function logMapping(array $row): void {
        try {
            $dir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/');
            if ($dir === '') {
                return;
            }
            $dir .= '/iephoto_watermark';
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                return;
            }
            $row['ts'] = time();
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                return;
            }
            @file_put_contents($dir . '/mapping.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Logging must never break an upload.
        }
    }

    private function recordHistory(
        string $source,
        string $output,
        string $photographer,
        string $watermarkPosition,
    ): void {
        $history = $this->uploadHistory();
        array_unshift($history, [
            'timestamp' => time(),
            'source' => basename($source),
            'output' => $output,
            'photographer' => $photographer,
            'position' => $watermarkPosition,
            'uploadedBy' => $this->userSession->getUser()?->getDisplayName()
                ?? $this->userSession->getUser()?->getUID()
                ?? 'unknown',
        ]);
        $this->config->setAppValue(
            Application::APP_ID,
            'upload_history',
            json_encode(array_slice($history, 0, 200), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /** @return list<array<string,mixed>> */
    private function uploadHistory(): array {
        $decoded = json_decode(
            $this->config->getAppValue(Application::APP_ID, 'upload_history', '[]'),
            true,
        );
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @param array<string,int> $summary @param array<string,mixed> $photographer */
    private function collectStorageStats(Folder $folder, array &$summary, array &$photographer, int $depth): void {
        if ($depth > 12) {
            return;
        }
        foreach ($folder->getDirectoryListing() as $node) {
            $summary['lastModified'] = max($summary['lastModified'], $node->getMTime());
            $photographer['lastModified'] = max((int)$photographer['lastModified'], $node->getMTime());
            if ($node instanceof Folder) {
                $summary['folders']++;
                $this->collectStorageStats($node, $summary, $photographer, $depth + 1);
                continue;
            }
            if (!$node instanceof File || !in_array(strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                continue;
            }
            $size = $node->getSize();
            $summary['photos']++;
            $summary['bytes'] += $size;
            $photographer['photos']++;
            $photographer['bytes'] += $size;
        }
    }
}
