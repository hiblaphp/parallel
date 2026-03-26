<?php

declare(strict_types=1);

namespace Hibla\Parallel\Utilities;

/**
 * Cross-platform process tree kill utility.
 *
 * Handles recursive termination of a process and all its descendants,
 * addressing the orphan problem where killing a parent leaves grandchildren
 * running.
 */
final class ProcessKiller
{
    /**
     * Kill multiple process trees simultaneously.
     *
     * @param list<int> $pids
     */
    public static function killTreesAsync(array $pids): void
    {
        if (\count($pids) === 0) {
            return;
        }

        match (true) {
            PHP_OS_FAMILY === 'Windows' => self::killTreesWindows($pids),
            PHP_OS_FAMILY === 'Linux' && is_dir('/proc') => self::killTreesLinux($pids),
            \in_array(PHP_OS_FAMILY, ['Darwin', 'BSD'], true) => self::killTreesMacBsd($pids),
            default => self::killTreesUnixFallback($pids),
        };
    }

    /**
     * @param list<int> $pids
     */
    private static function killTreesWindows(array $pids): void
    {
        foreach (array_chunk($pids, 50) as $chunk) {
            self::killTreesWindowsChunk($chunk);
        }
    }

    /**
     * Fire-and-forget taskkill for a chunk of PIDs.
     *
     * @param list<int> $chunk
     */
    private static function killTreesWindowsChunk(array $chunk): void
    {
        $pidArgs = implode(' ', array_map(static fn(int $pid) => "/PID {$pid}", $chunk));
        $cmd = "cmd /c start /B taskkill /F /T {$pidArgs} >nul 2>nul";

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', 'NUL', 'w'],
            2 => ['file', 'NUL', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($cmd, $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);

        if (\is_resource($process)) {
            @fclose($pipes[0]);
            @proc_close($process);
        }
    }

    /**
     * Linux-specific strategy using /proc for map building.
     *
     * @param list<int> $pids
     */
    private static function killTreesLinux(array $pids): void
    {
        [$parentMap, $pgidMap] = self::buildProcMaps();

        if ($parentMap !== []) {
            self::killTreesUnixMapped($pids, $parentMap, $pgidMap);
        } else {
            self::killTreesUnixFallback($pids);
        }
    }

    /**
     * macOS and BSD strategy using ps for map building.
     *
     * @param list<int> $pids
     */
    private static function killTreesMacBsd(array $pids): void
    {
        [$parentMap, $pgidMap] = self::buildPsMaps();

        if ($parentMap !== []) {
            self::killTreesUnixMapped($pids, $parentMap, $pgidMap);
        } else {
            self::killTreesUnixFallback($pids);
        }
    }

    /**
     * Shared logic for Unix-like systems to kill processes based on maps.
     *
     * @param list<int> $pids
     * @param array<int, int> $parentMap
     * @param array<int, int> $pgidMap
     */
    private static function killTreesUnixMapped(array $pids, array $parentMap, array $pgidMap): void
    {
        $killedPgids = [];

        foreach ($pids as $pid) {
            $pgid = $pgidMap[$pid] ?? null;

            if ($pgid !== null && $pgid === $pid) {
                if (! \in_array($pgid, $killedPgids, true)) {
                    $killedPgids[] = $pgid;
                    self::sendSignalToGroup($pgid, SIGKILL);
                }
            } else {
                $descendants = self::collectDescendants($pid, $parentMap);
                foreach (array_reverse($descendants) as $descendantPid) {
                    self::sendSignal($descendantPid, SIGKILL);
                }
            }
        }
    }

    /**
     * Single-pass ps scan for macOS/BSD systems.
     *
     * @return array{array<int, int>, array<int, int>}
     */
    private static function buildPsMaps(): array
    {
        $parentMap = [];
        $pgidMap = [];

        $output = @shell_exec('ps -eo pid,ppid,pgid 2>/dev/null');

        if (! \is_string($output) || $output === '') {
            return [$parentMap, $pgidMap];
        }

        $lines = explode("\n", trim($output));
        array_shift($lines);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line);

            if (isset($parts[2])) {
                $pid = (int) $parts[0];
                $ppid = (int) $parts[1];
                $pgid = (int) $parts[2];

                $parentMap[$pid] = $ppid;
                $pgidMap[$pid] = $pgid;
            }
        }

        return [$parentMap, $pgidMap];
    }

   /**
     * Scan /proc once and return two maps: [pid => ppid] and[pid => pgid].
     *
     * Uses strrpos(')') to find the end of the comm field, which is robust
     * against process names containing spaces or parentheses.
     *
     * @return array{array<int, int>, array<int, int>}  [$parentMap, $pgidMap]
     */
    private static function buildProcMaps(): array
    {
        $parentMap =[];
        $pgidMap =[];

        // Temporarily intercept and suppress all warnings generated by file 
        // operations in this block. Test runners (Pest/PHPUnit) often ignore 
        // the '@' operator, so a custom error handler is required to cleanly 
        // handle the TOCTOU race condition when processes exit mid-scan.
        set_error_handler(static fn() => true);

        try {
            $dh = opendir('/proc');
            if ($dh === false) {
                return [$parentMap, $pgidMap];
            }

            while (($entry = readdir($dh)) !== false) {
                if (! ctype_digit($entry)) {
                    continue;
                }

                $stat = file_get_contents("/proc/$entry/stat");
                
                if ($stat === false || $stat === '') {
                    continue;
                }

                $parsed = self::parseProcStat($stat);
                if ($parsed === null) {
                    continue;
                }

                [$pid, $ppid, $pgid] = $parsed;

                $parentMap[$pid] = $ppid;
                $pgidMap[$pid] = $pgid;
            }

            closedir($dh);
        } finally {
            restore_error_handler();
        }

        return[$parentMap, $pgidMap];
    }

    /**
     * Parse a /proc/$pid/stat line into [pid, ppid, pgid].
     *
     * @return array{int, int, int}|null
     */
    private static function parseProcStat(string $stat): ?array
    {
        $rp = strrpos($stat, ')');
        if ($rp === false) {
            return null;
        }

        $fields = explode(' ', ltrim(substr($stat, $rp + 1)));

        if (\count($fields) < 3) {
            return null;
        }

        $pid = (int) strtok($stat, ' ');
        $ppid = (int) $fields[1];
        $pgid = (int) $fields[2];

        return [$pid, $ppid, $pgid];
    }

    /**
     * @param int $pid
     * @param array<int, int> $parentMap
     * @return list<int>
     */
    private static function collectDescendants(int $pid, array $parentMap): array
    {
        $childMap = [];
        foreach ($parentMap as $child => $parent) {
            $childMap[$parent][] = $child;
        }

        $result = [];
        $queue = [$pid];

        while ($queue !== []) {
            $current = array_shift($queue);
            $result[] = $current;
            foreach ($childMap[$current] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $result;
    }

    /**
     * Fallback strategy using pgrep for recursive discovery.
     *
     * @param list<int> $pids
     */
    private static function killTreesUnixFallback(array $pids): void
    {
        foreach ($pids as $pid) {
            $output = @shell_exec("pgrep -P {$pid} 2>/dev/null");

            if (\is_string($output) && $output !== '') {
                foreach (explode("\n", trim($output)) as $childPid) {
                    $childPid = trim($childPid);
                    if (ctype_digit($childPid) && (int) $childPid > 0) {
                        self::killTreesUnixFallback([(int) $childPid]);
                    }
                }
            }

            self::sendSignal($pid, SIGKILL);
        }
    }

    /**
     * Send a signal to a single process.
     */
    private static function sendSignal(int $pid, int $signal): void
    {
        \function_exists('posix_kill')
            ? @posix_kill($pid, $signal)
            : @exec("kill -{$signal} {$pid} 2>/dev/null");
    }

    /**
     * Send a signal to an entire process group.
     */
    private static function sendSignalToGroup(int $pgid, int $signal): void
    {
        \function_exists('posix_kill')
            ? @posix_kill(-$pgid, $signal)
            : @exec("kill -{$signal} -{$pgid} 2>/dev/null");
    }
}