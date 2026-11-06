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

    /**
     * Run $fn while holding an exclusive lock for this code + sha bucket (first two hex chars,
     * so at most 256 lock files per code). Serialises find -> store -> remember for one file.
     */
    public function locked(string $codeId, string $sha256, \Closure $fn): mixed {
        $this->validate($codeId, $sha256);
        $this->ensureDir();
        $lockFile = rtrim($this->dir, '/') . '/' . $codeId . '-' . substr($sha256, 0, 2) . '.lock';
        $fp = fopen($lockFile, 'c');
        if ($fp === false) {
            throw new \RuntimeException('cannot open lock file');
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException('cannot lock');
            }
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function remember(string $codeId, string $sha256, string $name): void {
        $this->validate($codeId, $sha256);
        $this->ensureDir();
        $file = $this->file($codeId);
        $lock = fopen(rtrim($this->dir, '/') . '/' . $codeId . '.maplock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('cannot open seen log lock');
        }
        $tmp = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('cannot lock seen log');
            }
            $map = $this->read($file);
            $map[$sha256] = $name;
            $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || file_put_contents($tmp, $json) === false) {
                throw new \RuntimeException('cannot write seen log');
            }
            if (!rename($tmp, $file)) {
                throw new \RuntimeException('cannot replace seen log');
            }
            $tmp = null;
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureDir(): void {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0770, true);
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
