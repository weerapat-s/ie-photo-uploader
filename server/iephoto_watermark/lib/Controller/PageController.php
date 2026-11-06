<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Controller;

use OCA\IEPhotoWatermark\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCA\IEPhotoWatermark\Service\UploadService;

final class PageController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private UploadService $service,
    ) {
        parent::__construct($appName, $request);
    }

    // Public so photographers without a Nextcloud account can reach the upload form.
    // The destination folder comes from admin config, never from the visitor's session.
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $user = $this->userSession->getUser();
        $isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());
        return new TemplateResponse(Application::APP_ID, 'main', [
            'uploadUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.upload.upload'),
            'albumToken' => (string)$this->request->getParam('album', ''),
            'sportsUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.upload.sports'),
            'destination' => $this->service->destinationLabel(),
            'configured' => $this->service->isConfigured(),
            'adminUrl' => $isAdmin
                ? $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin.index')
                : '',
        ], TemplateResponse::RENDER_AS_PUBLIC);
    }
}
