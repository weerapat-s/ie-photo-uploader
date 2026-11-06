<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Controller;

use OCA\IEPhotoWatermark\AppInfo\Application;
use OCA\IEPhotoWatermark\Service\UploadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

final class AdminController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private UploadService $service,
        private \OCA\IEPhotoWatermark\Service\AlbumRequestService $requests,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);
    }

    private function isAdmin(): bool {
        $user = $this->userSession->getUser();
        return $user !== null && $this->groupManager->isAdmin($user->getUID());
    }

    #[NoCSRFRequired]
    public function index(): TemplateResponse|NotFoundResponse {
        if (!$this->isAdmin()) {
            return new NotFoundResponse();
        }
        return new TemplateResponse(Application::APP_ID, 'admin', [
            'configUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.config'),
            'foldersUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.folders'),
            'statsUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.stats'),
            'processUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.processFolder'),
            'albumsUrl' => $this->urlGenerator->linkToRoute('ie_drive.gallery.albums'),
            'albumApiUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.albums'),
            'requestsUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.requests'),
            'pendingUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.pending'),
            'scopesUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.saveScopes'),
            'webhookUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.saveWebhook'),
            'requestPageUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.request.index'),
            'destinationLocked' => $this->service->destinationLocked(),
            'albumViewBase' => $this->urlGenerator->linkToRouteAbsolute(
                Application::APP_ID . '.gallery.album', ['token' => '__TOKEN__'],
            ),
            'albumUploadBase' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index'),
            'uploadUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index'),
        ]);
    }

    public function config(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }
        return new JSONResponse($this->service->adminConfig());
    }

    public function save(string $uid = '', string $path = '/'): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }
        // The destination is pinned to the current event on purpose: every photo that comes in
        // should land under it, and an accidental change here silently sends a whole shoot to the
        // wrong folder. Unlock deliberately from the server with:
        //   occ config:app:set iephoto_watermark destination_locked --value=0
        if ($this->service->destinationLocked()) {
            return new JSONResponse([
                'error' => 'โฟลเดอร์ปลายทางถูกล็อกไว้ที่งานปัจจุบัน — ปลดล็อกที่เซิร์ฟเวอร์ก่อนจึงจะเปลี่ยนได้',
            ], 409);
        }
        try {
            $this->service->saveDestination($uid, $path);
            return new JSONResponse(['ok' => true, 'destination' => $this->service->destinationLabel()]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    public function saveScopes(string $uid = '', string $scopes = ''): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'ต้องเป็นผู้ดูแลระบบ'], 403);
        }
        try {
            return new JSONResponse(['ok' => true, 'scopes' => $this->service->saveScanScopes($uid, $scopes)]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    public function folders(string $uid = ''): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }
        try {
            return new JSONResponse(['folders' => $this->service->listFoldersForUser($uid)]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    public function saveWebhook(string $url = ''): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'ต้องเป็นผู้ดูแลระบบ'], 403);
        }
        try {
            $this->requests->saveWebhookUrl($url);
            return new JSONResponse(['ok' => true, 'webhookSet' => $this->requests->webhookUrl() !== '']);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    public function requests(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'ต้องเป็นผู้ดูแลระบบ'], 403);
        }
        return new JSONResponse([
            'requests' => $this->requests->all(),
            'webhookSet' => $this->requests->webhookUrl() !== '',
        ]);
    }

    public function pending(string $owner = ''): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'ต้องเป็นผู้ดูแลระบบ'], 403);
        }
        return new JSONResponse($this->service->pendingFolders($owner));
    }

    public function albums(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'ต้องเป็นผู้ดูแลระบบ'], 403);
        }
        return new JSONResponse([
            'albums' => $this->service->listAlbums(),
            'root' => $this->service->albumsRootLabel(),
        ]);
    }

    public function createAlbum(string $name = '', string $venue = 'outdoor'): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'ต้องเป็นผู้ดูแลระบบ'], 403);
        }
        try {
            return new JSONResponse(['album' => $this->service->createAlbum($name, $venue)]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    public function createFolder(string $uid = '', string $path = ''): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }
        try {
            $this->service->createFolderForUser($uid, $path);
            return new JSONResponse(['ok' => true, 'folders' => $this->service->listFoldersForUser($uid)]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Rename + watermark photos already sitting in a folder (uploaded via Files / desktop client).
     * Processes one batch per call; the front end repeats until `done` so no single request runs
     * long enough to hit the PHP/Apache timeout.
     */
    public function processFolder(
        string $uid = '',
        string $path = '',
        int $limit = 10,
        string $watermarkPosition = 'top-left',
        int $watermarkOpacity = 100,
        string $watermarkColour = 'black',
    ): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }
        try {
            return new JSONResponse($this->service->processExistingFolder(
                $uid,
                $path,
                $limit,
                $watermarkPosition,
                $watermarkOpacity,
                $watermarkColour,
            ));
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[NoCSRFRequired]
    public function stats(): JSONResponse {
        if (!$this->isAdmin()) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }
        try {
            return new JSONResponse($this->service->adminStats());
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }
}
