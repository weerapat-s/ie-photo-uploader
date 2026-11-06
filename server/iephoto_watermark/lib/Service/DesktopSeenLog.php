<?php

declare(strict_types=1);

namespace OCA\IEPhotoWatermark\Service;

/**
 * Remembers which source file (sha256) a desktop code already delivered, so a retry after a
 * lost response returns the same name instead of creating a second copy.
 */
final class DesktopSeenLog {
    public function __construct(private string $dir) {
    }

    public function find(string $codeId, string $sha256): ?string {
        $this->validate($codeId, $sha256);
        $map = $this->read($this->file($codeId));
        return $map[$sha256] ?? null;
    }

    public function remember(string $codeId, string $sha256, string $name): void {
        $this->validate($codeId, $sha256);
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0770, true);
        }
        $file = $this->file($codeId);
        $fp = fopen($file, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('cannot open seen log');
        }
        try {
            flock($fp, LOCK_EX);
            $map = json_decode((string)stream_get_contents($fp), true);
            $map = is_array($map) ? $map : [];
            $map[$sha256] = $name;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function file(string $codeId): string {
        return rtrim($this->dir, '/') . '/' . $codeId . '.json';
    }

    /** @return array<string,string> */
    private function read(string $file): array {
        if (!is_file($file)) {
            return [];
        }
        $map = json_decode((string)file_get_contents($file), true);
        return is_array($map) ? $map : [];
    }

    private function validate(string $codeId, string $sha256): void {
        if (preg_match('/^[a-f0-9]{6,32}$/', $codeId) !== 1) {
            throw new \InvalidArgumentException('bad code id');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new \InvalidArgumentException('bad sha256');
        }
    }
}
