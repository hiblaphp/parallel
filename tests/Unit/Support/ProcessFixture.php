<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

final class ProcessFixture
{
    public static function isRunning(int $pid): bool
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => self::isRunningWindows($pid),
            'Darwin' => self::isRunningMac($pid),
            default => self::isRunningLinux($pid),
        };
    }

    private static function isRunningLinux(int $pid): bool
    {
        $statusPath = "/proc/{$pid}/status";

        if (! file_exists($statusPath)) {
            return false;
        }

        $status = file_get_contents($statusPath);

        return $status !== false && ! str_contains($status, "State:\tZ");
    }

    private static function isRunningMac(int $pid): bool
    {
        exec("ps -p {$pid} -o state= 2>/dev/null", $output, $code);

        return $code === 0 && ! empty($output) && trim($output[0]) !== 'Z';
    }

    private static function isRunningWindows(int $pid): bool
    {
        exec("tasklist /FI \"PID eq {$pid}\" /NH 2>NUL", $output);

        foreach ($output as $line) {
            if (str_contains($line, (string) $pid)) {
                return true;
            }
        }

        return false;
    }

    public static function waitForDeath(int $pid, int $timeoutMs = 1000): bool
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);

        do {
            if (! self::isRunning($pid)) {
                return true;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * Spawn a process and return its PID and resource handle.
     *
     * @param  string|list<string> $cmd
     * @return array{pid: int, resource: resource}
     */
    public static function spawn(string|array $cmd): array
    {
        $pipes = [];
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! \is_resource($proc)) {
            throw new \RuntimeException(
                'Failed to spawn: ' . (is_array($cmd) ? implode(' ', $cmd) : $cmd)
            );
        }

        $status = proc_get_status($proc);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return ['pid' => (int) $status['pid'], 'resource' => $proc];
    }

    /**
     * Spawn a long-running PHP script from a string of code.
     * Writes a temp file to avoid shell-quoting issues on Windows.
     *
     * @return array{pid: int, resource: resource, script: string}
     */
    public static function spawnPhpScript(string $code): array
    {
        $script = self::writeTempScript($code);
        $proc = self::spawn([PHP_BINARY, $script]);

        return ['pid' => $proc['pid'], 'resource' => $proc['resource'], 'script' => $script];
    }

    /**
     * @return list<int>
     */
    public static function childPids(int $parentPid): array
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => self::childPidsWindows($parentPid),
            'Darwin' => self::childPidsMac($parentPid),
            default => self::childPidsLinux($parentPid),
        };
    }

    /**
     * @return list<int>
     */
    private static function childPidsLinux(int $parentPid): array
    {
        $children = [];
        $dh = @opendir('/proc');

        if ($dh === false) {
            return $children;
        }

        while (($entry = readdir($dh)) !== false) {
            if (! ctype_digit($entry)) {
                continue;
            }

            $statPath = "/proc/{$entry}/stat";
            if (! file_exists($statPath)) {
                continue;
            }

            $stat = file_get_contents($statPath);
            if ($stat === false) {
                continue;
            }

            $rp = strrpos($stat, ')');
            if ($rp === false) {
                continue;
            }

            $fields = explode(' ', ltrim(substr($stat, $rp + 1)));

            if (isset($fields[1]) && (int) $fields[1] === $parentPid) {
                $children[] = (int) $entry;
            }
        }

        closedir($dh);

        return $children;
    }

    /**
     * @return list<int>
     */
    private static function childPidsMac(int $parentPid): array
    {
        exec("pgrep -P {$parentPid} 2>/dev/null", $output);

        return array_values(
            array_filter(
                array_map('intval', $output),
                static fn (int $pid) => $pid > 0
            )
        );
    }

    /**
     * @return list<int>
     */
    private static function childPidsWindows(int $parentPid): array
    {
        exec(
            "wmic process where (ParentProcessId={$parentPid}) get ProcessId /FORMAT:VALUE 2>NUL",
            $output
        );

        $children = [];
        foreach ($output as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'ProcessId=')) {
                $pid = (int) substr($line, strlen('ProcessId='));
                if ($pid > 0) {
                    $children[] = $pid;
                }
            }
        }

        return $children;
    }

    public static function pgid(int $pid): ?int
    {
        $statPath = "/proc/{$pid}/stat";

        if (! file_exists($statPath)) {
            return null;
        }

        $stat = file_get_contents($statPath);
        if ($stat === false) {
            return null;
        }

        $rp = strrpos($stat, ')');
        if ($rp === false) {
            return null;
        }

        $fields = explode(' ', ltrim(substr($stat, $rp + 1)));

        return isset($fields[2]) ? (int) $fields[2] : null;
    }

    public static function writeTempScript(string $code): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pktest_' . getmypid() . '_' . uniqid() . '.php';
        file_put_contents($path, "<?php\n" . $code);

        return $path;
    }

    public static function removeTempScript(string $path): void
    {
        @unlink($path);
    }
}
