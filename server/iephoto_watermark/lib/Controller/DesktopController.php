<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Controller;

use OCA\IEPhotoWatermark\Service\DesktopCodeStore;
use OCA\IEPhotoWatermark\Service\DesktopSeenLog;
use OCA\IEPhotoWatermark\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** Endpoints for the IE-PHOTO Uploader desktop app. Auth = per-event code in X-IEPhoto-Code. */
final class DesktopController extends Controller {
    private const MAX_BYTES = 95 * 1024 * 1024;

    public function __construct(
        string $appName,
        IRequest $request,
        private UploadService $uploads,
        private DesktopCodeStore $codes,
        private DesktopSeenLog $seen,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /** @return array<string,mixed>|null */
    private function codeRecord(): ?array {
        return $this->codes->verify((string)$this->request->getHeader('X-IEPhoto-Code'));
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 60, period: 300)]
    public function config(): JSONResponse {
        $record = $this->codeRecord();
        if ($record === null) {
            return new JSONResponse(['error' => 'invalid_code'], 401);
        }
        $album = (string)($record['album'] ?? '');
        $sports = $album !== ''
            ? [['name' => $album, 'colour' => $this->uploads->colourForAlbum($album)]]
            : $this->uploads->desktopSports();
        $logo = dirname(__DIR__, 2) . '/img/watermark.png';
        return new JSONResponse([
            'label' => $record['label'],
            'album' => $album,
            'expiresAt' => $record['expiresAt'],
            'positions' => $this->uploads->watermarkPositions(),
            'defaultPosition' => 'top-left',
            'sports' => $sports,
            'logoUrl' => '/custom_apps/iephoto_watermark/img/watermark.png',
            'logoSha256' => is_file($logo) ? hash_file('sha256', $logo) : '',
            'widthRatio' => 0.07,
            'marginRatio' => 0.02,
            'jpegQuality' => 96,
            'maxUploadMb' => 95,
        ]);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 3000, period: 300)]
    public function upload(): JSONResponse {
        $record = $this->codeRecord();
        if ($record === null) {
            return new JSONResponse(['error' => 'invalid_code'], 401);
        }
        $file = $this->request->getUploadedFile('photo');
        $err = is_array($file) ? ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return new JSONResponse(['error' => 'ไฟล์ใหญ่เกิน 95MB'], 413);
        }
        if (!is_array($file) || $err !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) {
            return new JSONResponse(['error' => 'ไม่พบไฟล์ที่อัปโหลด'], 422);
        }
        if ((int)($file['size'] ?? 0) > self::MAX_BYTES) {
            return new JSONResponse(['error' => 'ไฟล์ใหญ่เกิน 95MB'], 413);
        }
        $sha = strtolower((string)$this->request->getParam('sha256', ''));
        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            return new JSONResponse(['error' => 'sha256 ไม่ถูกต้อง'], 422);
        }
        $codeId = (string)$record['id'];
        $album = (string)($record['album'] ?? '') !== '' ? (string)$record['album'] : (string)$this->request->getParam('sport', '');
        try {
            return $this->seen->locked($codeId, $sha, function () use ($codeId, $sha, $file, $album): JSONResponse {
                $existing = $this->seen->find($codeId, $sha);
                if ($existing !== null) {
                    return new JSONResponse(['name' => $existing, 'duplicate' => true]);
                }
                $stored = $this->uploads->storeDesktopUpload(
                    $file['tmp_name'],
                    (string)$this->request->getParam('originalName', (string)($file['name'] ?? 'photo.jpg')),
                    (string)$this->request->getParam('uploader', ''),
                    $album,
                    (string)$this->request->getParam('position', 'top-left'),
                );
                try {
                    $this->seen->remember($codeId, $sha, $stored['name']);
                } catch (\Throwable $e) {
                    $this->logger->warning('iephoto_watermark: could not record desktop upload', ['exception' => $e]);
                }
                return new JSONResponse(['name' => $stored['name'], 'folder' => $stored['folder'], 'duplicate' => false]);
            });
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], 422);
        } catch (\OCP\Files\InvalidPathException | \OCP\Files\NotPermittedException | \OCP\Files\NotFoundException | \OCP\Files\GenericFileException $e) {
            return new JSONResponse(['error' => 'บันทึกไฟล์ไม่สำเร็จ'], 422);
        } catch (\Throwable $e) {
            $this->logger->error('iephoto_watermark: desktop upload failed', ['exception' => $e]);
            return new JSONResponse(['error' => 'เซิร์ฟเวอร์ขัดข้อง'], 500);
        }
    }
}
