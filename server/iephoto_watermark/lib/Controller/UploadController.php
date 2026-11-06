<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Controller;

use OCA\IEPhotoWatermark\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

final class UploadController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private UploadService $service,
    ) {
        parent::__construct($appName, $request);
    }

    // Public: the universal upload link fetches this to fill the sport dropdown.
    #[PublicPage]
    #[NoAdminRequired]
    #[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
    public function sports(): JSONResponse {
        return new JSONResponse(['sports' => $this->service->publicSports()]);
    }

    // Public upload endpoint: anyone with the link may submit photos. Uploads are still
    // constrained to images, renamed, watermarked and written only under the admin-configured
    // destination. Rate limited per IP so an open link cannot be used to flood storage.
    //
    // These attributes must stay directly above upload(): when the sports() method was added it
    // was inserted between them and this function, which silently stripped upload() of
    // #[PublicPage] and made every anonymous upload fail with a 401.
    #[PublicPage]
    #[NoAdminRequired]
    #[AnonRateLimit(limit: 120, period: 300)]
    public function upload(): JSONResponse {
        $uploaderName = (string)$this->request->getParam('uploader', '');
        $watermarkPosition = (string)$this->request->getParam('watermarkPosition', 'top-left');
        // Watermark strength as a percentage; clamp to a sane, visible range.
        $watermarkOpacity = (int)$this->request->getParam('watermarkOpacity', 100);
        $watermarkOpacity = max(10, min(100, $watermarkOpacity));
        // Which album the link points at. Empty falls back to the admin-configured destination.
        $albumToken = (string)$this->request->getParam('album', '');
        $sport = (string)$this->request->getParam('sport', '');
        $sportVenue = (string)$this->request->getParam('sportVenue', '');
        $files = $this->request->getUploadedFile('photos');
        if (!is_array($files) || !isset($files['name'], $files['tmp_name'], $files['error'])) {
            return new JSONResponse(['error' => 'ไม่พบไฟล์ที่อัปโหลด'], 400);
        }

        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
        $results = [];

        foreach ($names as $index => $name) {
            try {
                if (($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new \RuntimeException('อัปโหลดไม่สำเร็จ (รหัส ' . ($errors[$index] ?? -1) . ')');
                }
                $processed = $this->service->processUpload(
                    (string)($tmpNames[$index] ?? ''),
                    (string)$name,
                    $uploaderName,
                    $watermarkPosition,
                    $watermarkOpacity,
                    'black',
                    $albumToken,
                    $sport,
                    $sportVenue,
                );
                $results[] = [
                    'source' => $name,
                    'name' => $processed['name'],
                    'folder' => $processed['folder'],
                    'ok' => true,
                ];
            } catch (\Throwable $e) {
                $results[] = ['source' => $name, 'error' => $e->getMessage(), 'ok' => false];
            }
        }

        return new JSONResponse(['results' => $results]);
    }
}
