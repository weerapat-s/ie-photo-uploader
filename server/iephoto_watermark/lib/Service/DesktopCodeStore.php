<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Service;

/**
 * Per-event codes for the desktop uploader. Pure: storage and clock are injected so this can be
 * tested without Nextcloud. Only sha256(code) is stored; the code is shown to the admin once.
 */
final class DesktopCodeStore {
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private \Closure $load,
        private \Closure $save,
        private \Closure $now,
    ) {
    }

    /** @return array{code:string,record:array<string,mixed>} */
    public function create(string $label, string $album, int $days): array {
        $label = trim($label);
        if ($label === '' || preg_match('/^.{1,80}$/su', $label) !== 1) {
            throw new \InvalidArgumentException('ชื่องานไม่ถูกต้อง');
        }
        $days = max(1, min(90, $days));
        $code = '';
        for ($i = 0; $i < 20; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $now = ($this->now)();
        $record = [
            'id' => bin2hex(random_bytes(6)),
            'label' => $label,
            'album' => trim($album),
            'hash' => hash('sha256', $code),
            'createdAt' => $now,
            'expiresAt' => $now + $days * 86400,
            'revoked' => false,
        ];
        $all = $this->all();
        $all[] = $record;
        $this->write($all);
        return ['code' => implode('-', str_split($code, 5)), 'record' => $this->publicRecord($record)];
    }

    /** @return array<string,mixed>|null public record when the code is valid now */
    public function verify(string $code): ?array {
        $norm = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($norm) !== 20) {
            return null;
        }
        $hash = hash('sha256', $norm);
        $now = ($this->now)();
        foreach ($this->all() as $record) {
            if (hash_equals((string)$record['hash'], $hash) && empty($record['revoked']) && (int)$record['expiresAt'] > $now) {
                return $this->publicRecord($record);
            }
        }
        return null;
    }

    public function revoke(string $id): bool {
        $all = $this->all();
        foreach ($all as $i => $record) {
            if ($record['id'] === $id) {
                $all[$i]['revoked'] = true;
                $this->write($all);
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string,mixed>> */
    public function list(): array {
        return array_map(fn (array $r): array => $this->publicRecord($r), $this->all());
    }

    /** @return list<array<string,mixed>> */
    private function all(): array {
        $decoded = json_decode((string)($this->load)(), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(
            $decoded,
            static fn ($r): bool => is_array($r) && isset($r['id'], $r['hash'], $r['expiresAt'], $r['label']),
        ));
    }

    /** @param list<array<string,mixed>> $all */
    private function write(array $all): void {
        ($this->save)((string)json_encode(array_values($all), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $record */
    private function publicRecord(array $record): array {
        unset($record['hash']);
        return $record;
    }
}
