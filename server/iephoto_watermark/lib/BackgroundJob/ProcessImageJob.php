<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\BackgroundJob;

use OCA\IEPhotoWatermark\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IPreview;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;

/**
 * Auto-process an image dropped into the event folder through the regular Nextcloud Files /
 * desktop-sync interface (NOT the web upload page — that path is handled by UploadService).
 *
 * Flow: wait until the file has stopped growing (a sync/chunked upload writes it in pieces), then
 * watermark it top-left with the colour of its sport's venue, rename it to IEPHOTO@KMTIL_####,
 * and keep the untouched original in the shared "ภาพต้นฉบับ" folder.
 */
final class ProcessImageJob extends QueuedJob {
    private const PREFIX = 'IEPHOTO@KMTIL_';
    private const WIDTH_RATIO = 0.07;
    private const MARGIN_RATIO = 0.02;
    private const POSITION = 'top-left';
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    /** Truncated uploads are moved here so they never sit in an album pretending to be a photo. */
    private const QUARANTINE = 'ไฟล์เสีย ต้องอัปใหม่';
    /** How many settled looks a file gets before it is called broken, in case a write stalled. */
    private const TRUNCATED_STRIKES = 3;
    /** Only start once the file size has held steady for this long. */
    private const SETTLE_SECONDS = 30;
    /** Shared folder that collects the untouched originals (share link izt5yib4dxWTisP). */
    private const ORIGINALS_SHARE_TOKEN = 'izt5yib4dxWTisP';

    public function __construct(
        ITimeFactory $time,
        private IRootFolder $rootFolder,
        private IConfig $config,
        private IShareManager $shareManager,
        private IJobList $jobList,
        private IPreview $preview,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void {
        $nodeId = (int)($argument['nodeId'] ?? 0);
        if ($nodeId <= 0) {
            return;
        }

        $node = $this->rootFolder->getById($nodeId)[0] ?? null;
        if (!$node instanceof File || str_starts_with($node->getName(), self::PREFIX)) {
            return; // already processed, gone, or renamed away
        }

        $extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            return;
        }

        // Scope: only files sitting under the configured event folder.
        $sport = $this->sportForPath($node->getPath());
        if ($sport === null) {
            return;
        }

        // --- settle: only touch a file that has finished uploading ---
        //
        // The file's own mtime already says when the last byte landed, so a file that has been
        // sitting untouched for SETTLE_SECONDS is settled on the very first look. Waiting for two
        // separate observations instead cost an extra full cron cycle (~5 min) for every upload.
        // The size comparison is kept as the fallback for storages with a coarse or unreliable
        // mtime: if the size is still moving, the timer restarts either way.
        $now = $this->time->getTime();
        $size = $node->getSize();
        $prevSize = (int)($argument['size'] ?? -1);
        $stableSince = (int)($argument['stableSince'] ?? 0);
        $mtime = (int)$node->getMTime();

        if ($size <= 0) {
            $this->requeue($nodeId, $size, $now);
            return;
        }
        $settledByAge = $mtime > 0 && ($now - $mtime) >= self::SETTLE_SECONDS;
        $settledByObservation = $size === $prevSize && ($now - $stableSince) >= self::SETTLE_SECONDS;
        if (!$settledByAge && !$settledByObservation) {
            // still being written, or seen too recently — restart the timer on a size change
            $this->requeue($nodeId, $size, $size === $prevSize ? $stableSince : $now);
            return;
        }

        // --- stable: process ---
        $colour = $this->colourForSport($sport);
        $inputPath = tempnam(sys_get_temp_dir(), 'iephoto-auto-in-');
        $outputPath = tempnam(sys_get_temp_dir(), 'iephoto-auto-out-');
        if ($inputPath === false || $outputPath === false) {
            throw new \RuntimeException('Unable to allocate temporary files');
        }

        try {
            $this->copyNodeToPath($node, $inputPath);

            // An interrupted sync leaves a full-length file whose tail is zeros; libjpeg renders
            // the missing part as flat grey without erroring. Never watermark/rename one of those
            // — leave it under its camera name so it is obvious which photo needs re-uploading.
            if (!$this->isCompleteImage($inputPath, $extension) && !$this->isRepairable($inputPath)) {
                // Leaving it in place is what let 47 dead files pile up unnoticed: nothing renames
                // them, so every later run picks them up again and the album keeps a photo that
                // cannot be opened. Give the write a few more chances, then move it out.
                $strikes = (int)($argument['truncated'] ?? 0) + 1;
                if ($strikes < self::TRUNCATED_STRIKES) {
                    $this->jobList->add(self::class, [
                        'nodeId' => $nodeId,
                        'size' => $size,
                        'stableSince' => $stableSince,
                        'truncated' => $strikes,
                    ]);
                    return;
                }
                $this->quarantine($node);
                return;
            }

            $newName = $this->allocateName($extension);

            // Keep the untouched original in the shared folder, named after the new number so the
            // original and its watermarked copy are easy to pair up.
            $this->archiveOriginalToShare($inputPath, $newName);

            $this->watermark($inputPath, $outputPath, $extension, self::POSITION, $colour);
            $this->copyPathToNode($outputPath, $node);
            $node->move($node->getParent()->getPath() . '/' . $newName);
            $this->warmPreviews($node);

            $this->logMapping([
                'via' => 'auto',
                'source' => $node->getName(),
                'output' => $newName,
                'sport' => $sport,
                'colour' => $colour,
            ]);
            $this->logger->info('Auto-watermarked Nextcloud upload', [
                'app' => Application::APP_ID, 'target' => $newName, 'sport' => $sport,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to auto-watermark upload', [
                'app' => Application::APP_ID, 'nodeId' => $nodeId, 'exception' => $e,
            ]);
            throw $e;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    /**
     * Move a broken upload into the quarantine folder and write it to broken.jsonl, so the photo
     * is out of the album and there is a list to hand back to whoever needs to re-upload it.
     */
    private function quarantine(File $node): void {
        $uid = $this->config->getAppValue(Application::APP_ID, 'target_uid', '');
        $target = trim($this->config->getAppValue(Application::APP_ID, 'target_path', ''), '/');
        $prefix = '/' . $uid . '/files/' . $target . '/';
        $relative = substr($node->getPath(), strlen($prefix));
        $originalPath = $node->getPath();

        try {
            $eventFolder = $this->rootFolder->getUserFolder($uid)->get($target);
            if (!$eventFolder instanceof Folder) {
                throw new \RuntimeException('event folder missing');
            }
            $folder = $eventFolder->nodeExists(self::QUARANTINE)
                ? $eventFolder->get(self::QUARANTINE)
                : $eventFolder->newFolder(self::QUARANTINE);
            if (!$folder instanceof Folder) {
                throw new \RuntimeException('quarantine folder is not a folder');
            }

            // Keep the sport/photographer subfolders so it stays obvious where it came from.
            $segments = explode('/', $relative);
            array_pop($segments);
            foreach ($segments as $segment) {
                if ($segment === '') {
                    continue;
                }
                $folder = $folder->nodeExists($segment) ? $folder->get($segment) : $folder->newFolder($segment);
                if (!$folder instanceof Folder) {
                    throw new \RuntimeException('quarantine subfolder is not a folder');
                }
            }

            $name = $node->getName();
            if ($folder->nodeExists($name)) {
                $extension = pathinfo($name, PATHINFO_EXTENSION);
                $name = pathinfo($name, PATHINFO_FILENAME) . '-' . $this->time->getTime() . '.' . $extension;
            }
            $node->move($folder->getPath() . '/' . $name);
        } catch (\Throwable $e) {
            $this->logger->error('Could not quarantine a truncated upload', [
                'app' => Application::APP_ID, 'file' => $originalPath, 'exception' => $e,
            ]);
            return;
        }

        $this->logMapping([
            'via' => 'quarantine',
            'source' => $relative,
            'reason' => 'truncated upload',
        ]);
        $this->logger->warning('Moved a truncated upload to quarantine', [
            'app' => Application::APP_ID, 'file' => $originalPath,
        ]);
    }

    /**
     * Build the previews the share and gallery UIs ask for, right after the photo is finished.
     *
     * Nextcloud generates previews on demand and inserts the row without handling a conflict, so
     * when several viewers open a freshly uploaded album at once every one of them starts the same
     * render and all but the first get a 500 from a unique-constraint violation. Generating them
     * here, one photo at a time in the background job, means the first viewer already finds them
     * cached and nothing races.
     *
     * Failures are swallowed: a missing preview is a cosmetic problem, and it must never undo a
     * watermark that already succeeded.
     */
    private function warmPreviews(File $file): void {
        // The sizes the Files share grid, the mobile client and the photo viewer request.
        // The sizes the gallery actually asks for: 720x560 is every grid tile, 320x320 the folder
        // mosaics, 2048 uncropped the photo viewer, 128 the Nextcloud Files thumbnail. Warming the
        // wrong sizes was as good as warming nothing — each tile still rendered from the 13 MB
        // original on first view, about 1.3 s each, so a 60-tile page crawled.
        $specs = [[128, 128, true], [320, 320, true], [720, 560, true], [2048, 2048, false]];
        foreach ($specs as [$width, $height, $crop]) {
            try {
                $this->preview->getPreview($file, $width, $height, $crop);
            } catch (\Throwable $e) {
                $this->logger->debug('Preview warm-up skipped', [
                    'app' => Application::APP_ID,
                    'file' => $file->getPath(),
                    'size' => $width . 'x' . $height,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function requeue(int $nodeId, int $size, int $stableSince): void {
        $this->jobList->add(self::class, ['nodeId' => $nodeId, 'size' => $size, 'stableSince' => $stableSince]);
    }

    /**
     * Return the sport folder name if the path is inside the event folder, else null.
     * The sport is the first path segment beneath the configured target path.
     */
    private function sportForPath(string $path): ?string {
        $uid = $this->config->getAppValue(Application::APP_ID, 'target_uid', '');
        $target = trim($this->config->getAppValue(Application::APP_ID, 'target_path', ''), '/');
        if ($uid === '' || $target === '') {
            return null;
        }
        $prefix = '/' . $uid . '/files/' . $target . '/';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }
        $rest = substr($path, strlen($prefix));           // e.g. "บาส/ช่างเก้า/DSC_1.jpg" or "บาส/DSC_1.jpg"
        $first = explode('/', $rest)[0] ?? '';
        // Skip the originals backups so we never re-process them.
        if ($first === '' || str_contains($first, 'ต้นฉบับ') || $first === self::QUARANTINE) {
            return null;
        }
        return $first;
    }

    private function colourForSport(string $sport): string {
        $decoded = json_decode($this->config->getAppValue(Application::APP_ID, 'album_venues', '{}'), true);
        $venue = is_array($decoded) ? ($decoded[$sport] ?? 'outdoor') : 'outdoor';
        return $venue === 'indoor' ? 'white' : 'black';
    }

    private function archiveOriginalToShare(string $inputPath, string $name): void {
        try {
            $share = $this->shareManager->getShareByToken(self::ORIGINALS_SHARE_TOKEN);
            $folder = $share->getNode();
        } catch (\Throwable $e) {
            throw new \RuntimeException('ไม่พบโฟลเดอร์ต้นฉบับที่แชร์ไว้: ' . $e->getMessage());
        }
        if (!$folder instanceof Folder) {
            throw new \RuntimeException('ปลายทางต้นฉบับไม่ใช่โฟลเดอร์');
        }
        $source = fopen($inputPath, 'rb');
        if (!is_resource($source)) {
            throw new \RuntimeException('อ่านต้นฉบับไม่สำเร็จ');
        }
        try {
            if ($folder->nodeExists($name)) {
                $existing = $folder->get($name);
                if ($existing instanceof File) {
                    $existing->putContent($source);
                }
            } else {
                $folder->newFile($name, $source);
            }
        } catch (\OCP\Files\NotFoundException $e) {
            // files_versions post-write hook may throw after the bytes landed
            if (!$folder->nodeExists($name)) {
                throw $e;
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /** Atomic, cross-process filename allocation — shares counter.seq with the web upload path. */
    private function allocateName(string $extension): string {
        $dir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/') . '/iephoto_watermark';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $fp = @fopen($dir . '/counter.seq', 'c+');
        if ($fp === false) {
            $n = max(1, (int)$this->config->getAppValue(Application::APP_ID, 'next_counter', '1'));
            $this->config->setAppValue(Application::APP_ID, 'next_counter', (string)($n + 1));
            return self::PREFIX . str_pad((string)$n, 4, '0', STR_PAD_LEFT) . '.' . $extension;
        }
        try {
            flock($fp, LOCK_EX);
            $n = (int)trim((string)stream_get_contents($fp));
            if ($n < 1) {
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

    private function logMapping(array $row): void {
        try {
            $dir = rtrim((string)$this->config->getSystemValue('datadirectory', ''), '/') . '/iephoto_watermark';
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                return;
            }
            $row['ts'] = $this->time->getTime();
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line !== false) {
                @file_put_contents($dir . '/mapping.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable) {
        }
    }

    /** True when the file carries its format's end-of-file marker (i.e. it arrived complete). */
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

    private function isCompleteImage(string $path, string $extension): bool {
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
    }

    private function copyNodeToPath(File $node, string $path): void {
        $source = $node->fopen('r');
        $target = fopen($path, 'wb');
        if (!is_resource($source) || !is_resource($target)) {
            throw new \RuntimeException('Unable to read uploaded image');
        }
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);
    }

    /**
     * Write the watermarked bytes back over the node.
     *
     * Uses putContent() instead of a raw fopen('w') + stream_copy: the raw stream left Nextcloud's
     * filecache holding the PRE-watermark size, so every later read advertised a Content-Length
     * shorter than the file really was and clients got a truncated image (bottom of the photo
     * rendered as flat grey). putContent() goes through the storage API, which updates the size.
     */
    private function copyPathToNode(string $path, File $node): void {
        $source = fopen($path, 'rb');
        if (!is_resource($source)) {
            throw new \RuntimeException('Unable to read watermarked image');
        }
        try {
            $node->putContent($source);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
        // Belt and braces: make sure the cached size matches what is actually on disk.
        try {
            $node->getStorage()->getUpdater()->update($node->getInternalPath());
        } catch (\Throwable) {
            // best-effort; putContent above is the primary path
        }
    }

    private function watermark(string $inputPath, string $outputPath, string $extension, string $position, string $colour): void {
        $image = new \Imagick($inputPath);
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        }
        $image->setIteratorIndex(0);
        $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);

        $logo = new \Imagick(dirname(__DIR__, 2) . '/img/watermark.png');
        $logo->setIteratorIndex(0);
        $logo->trimImage(0);
        $targetWidth = max(1, (int)round($image->getImageWidth() * self::WIDTH_RATIO));
        $targetHeight = max(1, (int)round($logo->getImageHeight() * ($targetWidth / $logo->getImageWidth())));
        $logo->resizeImage($targetWidth, $targetHeight, \Imagick::FILTER_LANCZOS, 1);
        $logo = $this->tint($logo, $colour === 'white' ? 'white' : 'black');

        $margin = (int)round(min($image->getImageWidth(), $image->getImageHeight()) * self::MARGIN_RATIO);
        [$x, $y] = $this->watermarkCoordinates(
            $image->getImageWidth(), $image->getImageHeight(),
            $logo->getImageWidth(), $logo->getImageHeight(), $margin, $position,
        );
        $image->compositeImage($logo, \Imagick::COMPOSITE_OVER, $x, $y);

        $format = $extension === 'jpg' ? 'jpeg' : $extension;
        $image->setImageFormat($format);
        if ($format === 'jpeg') {
            $image->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $image->setImageCompressionQuality(96);
            $image->setSamplingFactors(['1x1', '1x1', '1x1']);
        }
        $profiles = $image->getImageProfiles('icc', true);
        $image->stripImage();
        if (!empty($profiles['icc'])) {
            $image->profileImage('icc', $profiles['icc']);
        }
        $image->writeImage($outputPath);
        $logo->clear();
        $image->clear();
    }

    private function tint(\Imagick $logo, string $colour): \Imagick {
        $paint = new \Imagick();
        $paint->newImage($logo->getImageWidth(), $logo->getImageHeight(), new \ImagickPixel($colour));
        $paint->setImageFormat('png');
        $paint->setImageColorspace(\Imagick::COLORSPACE_SRGB);
        $paint->compositeImage($logo, \Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        $logo->clear();
        return $paint;
    }

    /** @return array{int,int} */
    private function watermarkCoordinates(int $imageWidth, int $imageHeight, int $logoWidth, int $logoHeight, int $margin, string $position): array {
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
}
