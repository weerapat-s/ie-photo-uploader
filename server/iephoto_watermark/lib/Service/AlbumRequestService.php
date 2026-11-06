<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Service;

use OCA\IEPhotoWatermark\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Folder requests: a logged-in user asks for an album, the decision is made from Discord.
 *
 * Discord *buttons* would need a registered Discord application with an interactions endpoint
 * (Ed25519-signed callbacks). This deployment only has a plain incoming webhook, which cannot
 * carry components, so the message links to two signed URLs instead. The signature is an HMAC
 * over the request id and the action keyed with the Nextcloud instance secret, so the links
 * cannot be forged or edited into the opposite decision.
 */
final class AlbumRequestService {
    private const STORE_KEY = 'album_requests';
    private const WEBHOOK_KEY = 'discord_webhook';
    private const MAX_KEPT = 200;

    public function __construct(
        private IConfig $config,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private IClientService $clientService,
        private UploadService $uploadService,
    ) {
    }

    public function webhookUrl(): string {
        return $this->config->getAppValue(Application::APP_ID, self::WEBHOOK_KEY, '');
    }

    public function saveWebhookUrl(string $url): void {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^https://(canary\.|ptb\.)?discord\.com/api/webhooks/#', $url)) {
            throw new \RuntimeException('ต้องเป็น Discord webhook URL (https://discord.com/api/webhooks/...)');
        }
        $this->config->setAppValue(Application::APP_ID, self::WEBHOOK_KEY, $url);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array {
        $decoded = json_decode($this->config->getAppValue(Application::APP_ID, self::STORE_KEY, '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, 'is_array'));
    }

    /** @param list<array<string,mixed>> $requests */
    private function save(array $requests): void {
        $this->config->setAppValue(
            Application::APP_ID,
            self::STORE_KEY,
            json_encode(array_slice($requests, 0, self::MAX_KEPT), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    private function find(string $id): ?array {
        foreach ($this->all() as $request) {
            if (($request['id'] ?? '') === $id) {
                return $request;
            }
        }
        return null;
    }

    /** Unforgeable per-action signature. Keyed with the instance secret, never exposed. */
    public function signature(string $id, string $action): string {
        $secret = (string)$this->config->getSystemValue('secret', '');
        return substr(hash_hmac('sha256', $id . '|' . $action, $secret), 0, 32);
    }

    private function decideUrl(string $id, string $action): string {
        return $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.request.decide',
            ['id' => $id, 'action' => $action, 'sig' => $this->signature($id, $action)],
        );
    }

    /**
     * Record a new request and announce it. A failed Discord post must not lose the request,
     * so the store is written first and the notification result is reported separately.
     *
     * @return array{id:string,notified:bool,error:string}
     */
    public function submit(string $name, string $note): array {
        $name = trim($name);
        if (!mb_check_encoding($name, 'UTF-8') || $name === '') {
            throw new \RuntimeException('กรุณาระบุชื่ออัลบั้ม');
        }
        if (mb_strlen($name) > 80) {
            throw new \RuntimeException('ชื่ออัลบั้มยาวเกินไป');
        }
        $note = mb_substr(trim($note), 0, 300);
        if ($note !== '' && !mb_check_encoding($note, 'UTF-8')) {
            $note = '';
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException('ต้องเข้าสู่ระบบก่อน');
        }

        $requests = $this->all();
        foreach ($requests as $existing) {
            if (($existing['status'] ?? '') === 'pending' && ($existing['name'] ?? '') === $name) {
                throw new \RuntimeException('มีคำขอชื่อนี้รออนุมัติอยู่แล้ว');
            }
        }

        $id = bin2hex(random_bytes(8));
        array_unshift($requests, [
            'id' => $id,
            'name' => $name,
            'note' => $note,
            'status' => 'pending',
            'uid' => $user->getUID(),
            'displayName' => $user->getDisplayName(),
            'createdAt' => time(),
            'decidedAt' => 0,
            'decidedFrom' => '',
            'viewToken' => '',
            'uploadToken' => '',
        ]);
        $this->save($requests);

        $error = '';
        $notified = false;
        try {
            $notified = $this->announce($id, $name, $note, $user->getDisplayName(), $user->getUID());
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return ['id' => $id, 'notified' => $notified, 'error' => $error];
    }

    private function announce(string $id, string $name, string $note, string $who, string $uid): bool {
        $webhook = $this->webhookUrl();
        if ($webhook === '') {
            throw new \RuntimeException('ยังไม่ได้ตั้งค่า Discord webhook ในหน้าแอดมิน');
        }

        $lines = [
            '**ชื่องาน:** ' . $name,
            '**ผู้ขอ:** ' . $who . ' (' . $uid . ')',
        ];
        if ($note !== '') {
            $lines[] = '**หมายเหตุ:** ' . $note;
        }
        $lines[] = '';
        $lines[] = '[✅ อนุญาต](' . $this->decideUrl($id, 'approve') . ')'
            . '  •  [❌ ไม่อนุญาต](' . $this->decideUrl($id, 'deny') . ')';

        $client = $this->clientService->newClient();
        $response = $client->post($webhook, [
            'json' => [
                'username' => 'IE-PHOTO',
                'embeds' => [[
                    'title' => '📸 คำขอสร้างโฟลเดอร์รูป',
                    'description' => implode("\n", $lines),
                    'color' => 0xE52D52,
                ]],
            ],
            'timeout' => 15,
        ]);
        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    /**
     * Approve or deny. Idempotent: a second click reports the decision already made rather than
     * creating the album twice.
     *
     * @return array<string,mixed>
     */
    public function decide(string $id, string $action, string $signature): array {
        if (!in_array($action, ['approve', 'deny'], true)) {
            throw new \RuntimeException('คำสั่งไม่ถูกต้อง');
        }
        if (!hash_equals($this->signature($id, $action), $signature)) {
            throw new \RuntimeException('ลิงก์ไม่ถูกต้องหรือหมดอายุ');
        }
        $request = $this->find($id);
        if ($request === null) {
            throw new \RuntimeException('ไม่พบคำขอนี้');
        }

        if (($request['status'] ?? '') !== 'pending') {
            $request['alreadyDecided'] = true;
            return $request;
        }

        if ($action === 'approve') {
            $album = $this->uploadService->createAlbum((string)$request['name']);
            $request['viewToken'] = $album['viewToken'];
            $request['uploadToken'] = $album['uploadToken'];
            $request['status'] = 'approved';
        } else {
            $request['status'] = 'denied';
        }
        $request['decidedAt'] = time();

        $requests = $this->all();
        foreach ($requests as $index => $existing) {
            if (($existing['id'] ?? '') === $id) {
                $requests[$index] = $request;
                break;
            }
        }
        $this->save($requests);

        try {
            $this->announceDecision($request);
        } catch (\Throwable) {
            // the decision itself is already stored; a failed follow-up post must not undo it
        }

        $request['alreadyDecided'] = false;
        return $request;
    }

    private function announceDecision(array $request): void {
        $webhook = $this->webhookUrl();
        if ($webhook === '') {
            return;
        }
        $approved = ($request['status'] ?? '') === 'approved';
        $lines = ['**ชื่องาน:** ' . ($request['name'] ?? ''), '**ผู้ขอ:** ' . ($request['displayName'] ?? '')];
        if ($approved) {
            $lines[] = '';
            $lines[] = '**ลิงก์อัปโหลด (ส่งให้ช่างภาพ):** ' . $this->uploadLink((string)$request['uploadToken']);
            $lines[] = '**ลิงก์ดูรูป (ส่งได้ทุกคน):** ' . $this->viewLink((string)$request['viewToken']);
        }
        $client = $this->clientService->newClient();
        $client->post($webhook, [
            'json' => [
                'username' => 'IE-PHOTO',
                'embeds' => [[
                    'title' => $approved ? '✅ อนุมัติแล้ว' : '❌ ไม่อนุมัติ',
                    'description' => implode("\n", $lines),
                    'color' => $approved ? 0x1C7A4A : 0x8B857D,
                ]],
            ],
            'timeout' => 15,
        ]);
    }

    public function uploadLink(string $token): string {
        if ($token === '') {
            return '';
        }
        $base = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.page.index');
        return $base . (str_contains($base, '?') ? '&' : '?') . 'album=' . rawurlencode($token);
    }

    public function viewLink(string $token): string {
        if ($token === '') {
            return '';
        }
        return $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.gallery.album', ['token' => $token],
        );
    }
}
