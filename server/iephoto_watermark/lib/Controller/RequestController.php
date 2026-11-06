<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Controller;

use OCA\IEPhotoWatermark\AppInfo\Application;
use OCA\IEPhotoWatermark\Service\AlbumRequestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

final class RequestController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private AlbumRequestService $service,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);
    }

    // Any signed-in user may ask for an album; only the Discord decision creates it.
    #[NoAdminRequired]
    public function index(): TemplateResponse {
        return new TemplateResponse(Application::APP_ID, 'request', [
            'submitUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.request.submit'),
            'configured' => $this->service->webhookUrl() !== '',
        ]);
    }

    #[NoAdminRequired]
    public function submit(string $name = '', string $note = ''): JSONResponse {
        try {
            return new JSONResponse($this->service->submit($name, $note));
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Reached from the Discord message, so it cannot require a session. Safety comes from the
     * HMAC in the URL, not from being logged in.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function decide(string $id = '', string $action = '', string $sig = ''): TemplateResponse {
        try {
            $result = $this->service->decide($id, $action, $sig);
            return new TemplateResponse(Application::APP_ID, 'decide', [
                'ok' => true,
                'status' => (string)($result['status'] ?? ''),
                'name' => (string)($result['name'] ?? ''),
                'requestedBy' => (string)($result['displayName'] ?? ''),
                'alreadyDecided' => !empty($result['alreadyDecided']),
                'uploadLink' => $this->service->uploadLink((string)($result['uploadToken'] ?? '')),
                'viewLink' => $this->service->viewLink((string)($result['viewToken'] ?? '')),
                'error' => '',
            ], TemplateResponse::RENDER_AS_PUBLIC);
        } catch (\Throwable $e) {
            return new TemplateResponse(Application::APP_ID, 'decide', [
                'ok' => false,
                'status' => '',
                'name' => '',
                'requestedBy' => '',
                'alreadyDecided' => false,
                'uploadLink' => '',
                'viewLink' => '',
                'error' => $e->getMessage(),
            ], TemplateResponse::RENDER_AS_PUBLIC);
        }
    }
}
