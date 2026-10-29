<?php
declare(strict_types=1);

namespace Misseye;

use Closure;
use RuntimeException;

final class JsonFile
{
    public function __construct(private string $path, private Closure $validate)
    {
    }

    public function initialize(array $document): void
    {
        $this->access(true, static fn (array &$data) => null, $document);
    }

    public function read(callable $operation): mixed
    {
        return $this->access(false, $operation);
    }

    public function write(callable $operation): mixed
    {
        return $this->access(true, $operation);
    }

    private function access(bool $write, callable $operation, ?array $initial = null): mixed
    {
        // Lock a stable file: replacing the JSON file must not replace its lock.
        $lock = fopen($this->path . '.lock', 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Cannot open JSON storage lock.');
        }
        try {
            $deadline = microtime(true) + 5;
            while (!flock($lock, ($write ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new HttpError(503, 'Trwa zapis danych. Spróbuj ponownie za chwilę.');
                }
                usleep(10000);
            }
            clearstatcache(true, $this->path);
            if (is_file($this->path)) {
                $contents = file_get_contents($this->path);
                if ($contents === false) {
                    throw new RuntimeException('Cannot read JSON storage.');
                }
                $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                ($this->validate)($data);
                $before = $data;
            } elseif ($initial !== null) {
                $data = $initial;
                ($this->validate)($data);
                $before = null;
            } else {
                throw new RuntimeException('JSON storage is missing. Restore it; do not create an empty replacement.');
            }
            $result = $operation($data);
            if ($write && $data !== $before) {
                ($this->validate)($data);
                $this->replace($data);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function replace(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $this->path . '.' . bin2hex(random_bytes(12)) . '.tmp';
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot create temporary JSON storage.');
        }
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Cannot protect JSON storage permissions.');
            }
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write JSON storage.');
                }
                $offset += $written;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Cannot flush JSON storage.');
            }
            fclose($handle);
            $handle = null;
            if (!rename($temporary, $this->path)) {
                throw new RuntimeException('Cannot atomically replace JSON storage.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary) && !unlink($temporary)) {
                error_log('Cannot remove temporary JSON file.');
            }
        }
    }
}
