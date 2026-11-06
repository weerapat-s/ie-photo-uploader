<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Controller;

use OCA\IEPhotoWatermark\AppInfo\Application;
use OCA\IEPhotoWatermark\Service\GalleryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IPreview;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

final class GalleryController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IPreview $preview,
        private GalleryService $gallery,
    ) {
        parent::__construct($appName, $request);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $this->addShareMeta(
            'IE-PHOTO Gallery',
            'ดูและดาวน์โหลดรูปกิจกรรมทุกงานของคณะครุศาสตร์อุตสาหกรรมฯ สจล. ฟรี ไม่ต้องสมัคร',
        );
        $user = $this->userSession->getUser();
        $isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());
        return new TemplateResponse(Application::APP_ID, 'gallery', [
            'albumsUrl' => $this->urlGenerator->linkToRoute('ie_drive.gallery.albums'),
            'albumUrlTemplate' => $this->urlGenerator->linkToRoute(
                Application::APP_ID . '.gallery.album',
                ['token' => '__TOKEN__'],
            ),
            'adminUrl' => $isAdmin
                ? $this->urlGenerator->linkToRoute('ie_drive.album.index')
                : '',
        ], TemplateResponse::RENDER_AS_PUBLIC);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function album(string $token = ''): TemplateResponse {
        $this->addShareMeta(
            'อัลบั้มรูป IE-PHOTO',
            'เปิดดูและดาวน์โหลดรูปจากอัลบั้มนี้ได้ทันที ขนาดเว็บหรือไฟล์ต้นฉบับ',
        );
        return new TemplateResponse(Application::APP_ID, 'album', [
            'token' => $token,
            'dataUrl' => $this->urlGenerator->linkToRoute('ie_drive.gallery.album', ['token' => $token]),
            'trackViewUrl' => $this->urlGenerator->linkToRoute(
                'ie_drive.gallery.trackView',
                ['token' => $token],
            ),
            'downloadSelectedUrl' => $this->urlGenerator->linkToRoute(
                'ie_drive.gallery.downloadSelected',
                ['token' => $token],
            ),
            'galleryUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.gallery.index'),
        ], TemplateResponse::RENDER_AS_PUBLIC);
    }

    /** Description + Open Graph tags so shared links (LINE, Facebook) get a proper preview card. */
    private function addShareMeta(string $title, string $description): void {
        $image = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath(Application::APP_ID, 'watermark.png'),
        );
        Util::addHeader('meta', ['name' => 'description', 'content' => $description]);
        Util::addHeader('meta', ['property' => 'og:site_name', 'content' => 'IE-PHOTO']);
        Util::addHeader('meta', ['property' => 'og:type', 'content' => 'website']);
        Util::addHeader('meta', ['property' => 'og:title', 'content' => $title]);
        Util::addHeader('meta', ['property' => 'og:description', 'content' => $description]);
        Util::addHeader('meta', ['property' => 'og:image', 'content' => $image]);
        Util::addHeader('meta', ['name' => 'theme-color', 'content' => '#fcf8f5']);
    }

    #[NoCSRFRequired]
    public function themePreview(): TemplateResponse|NotFoundResponse {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
            return new NotFoundResponse();
        }

        return new TemplateResponse(Application::APP_ID, 'theme_preview', [
            'albumsUrl' => $this->urlGenerator->linkToRoute('ie_drive.gallery.albums'),
            'currentThemeUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.gallery.index'),
            'uploadUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index'),
        ]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function thumbnail(int $id): DataDisplayResponse|NotFoundResponse {
        $file = $this->gallery->photoById($id);
        if ($file === null) {
            return new NotFoundResponse();
        }
        try {
            $preview = $this->preview->getPreview($file, 960, 720, true);
            $response = new DataDisplayResponse($preview->getContent(), Http::STATUS_OK, [
                'Content-Type' => $preview->getMimeType(),
            ]);
            $response->cacheFor(3600, false, true);
            return $response;
        } catch (\Throwable) {
            return new NotFoundResponse();
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function photo(int $id): StreamResponse|NotFoundResponse {
        $file = $this->gallery->photoById($id);
        if ($file === null) {
            return new NotFoundResponse();
        }
        try {
            $response = new StreamResponse($file->fopen('r'));
            $response->addHeader('Content-Type', $file->getMimeType());
            $response->addHeader('Content-Disposition', 'inline; filename="' . rawurlencode($file->getName()) . '"');
            $response->cacheFor(3600, false, true);
            return $response;
        } catch (\Throwable) {
            return new NotFoundResponse();
        }
    }
}
