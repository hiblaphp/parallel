<?php

declare(strict_types=1);

namespace Hibla\Parallel\Utilities;

/**
 * @internal
 *
 * Cross-platform process tree kill utility.
 *
 * Handles recursive termination of a process and all its descendants,
 * addressing the orphan problem where killing a parent leaves grandchildren
 * running (e.g. sub-workers spawned inside a parallel() worker).
 */
final class ProcessKiller
{
    /**
     * Kill multiple process trees simultaneously.
     *
     * On Windows, launches a fire-and-forget background process executing
     * `taskkill /T`, returning instantly (~0.01s) without blocking.
     *
     * On Linux, performs a single /proc scan to build PID and PGID maps, then
     * kills by process group (atomic, race-free) when possible, falling back
     * to a bottom-up tree walk for processes sharing the parent's PGID.
     *
     * On macOS/BSD, uses pgrep for recursive tree discovery (~30ms).
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
     * Fire-and-forget taskkill for a chunk of PIDs, respecting the Windows
     * command-line length limit by capping chunks at 50 PIDs.
     *
     * @param list<int> $chunk
     */
    private static function killTreesWindowsChunk(array $chunk): void
    {
        $pidArgs = implode(' ', array_map(static fn (int $pid) => "/PID {$pid}", $chunk));
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
     * Single-pass /proc scan: build parentMap and pgidMap, then kill each
     * target either by process group (atomic) or by tree walk (fallback).
     *
     * @param list<int> $pids
     */
    private static function killTreesLinux(array $pids): void
    {
        [$parentMap, $pgidMap] = self::buildProcMaps();

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
     * Scan /proc once and return two maps: [pid => ppid] and [pid => pgid].
     *
     * Uses strrpos(')') to find the end of the comm field, which is robust
     * against process names containing spaces or parentheses.
     *
     * @return array{array<int, int>, array<int, int>}  [$parentMap, $pgidMap]
     */
    private static function buildProcMaps(): array
    {
        $parentMap = [];
        $pgidMap = [];

        $dh = @opendir('/proc');
        if ($dh === false) {
            return [$parentMap, $pgidMap];
        }

        while (($entry = readdir($dh)) !== false) {
            if (! ctype_digit($entry)) {
                continue;
            }

            $stat = @file_get_contents("/proc/$entry/stat");
            if ($stat === false) {
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

        return [$parentMap, $pgidMap];
    }

    /**
     * Parse a /proc/$pid/stat line into [pid, ppid, pgid].
     *
     * Stat fields after the comm: state(0), ppid(1), pgrp(2), ...
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
     * @param  int $pid
     * @param  array<int, int> $parentMap  [pid => ppid]
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
     * @param list<int> $pids
     */
    private static function killTreesUnixFallback(array $pids): void
    {
        foreach ($pids as $pid) {
            self::killTreeUnixFallback($pid);
        }
    }

    /**
     * Recursive pgrep-based tree kill for Unix systems without /proc (~30ms).
     */
    private static function killTreeUnixFallback(int $pid): void
    {
        $output = @shell_exec("pgrep -P {$pid} 2>/dev/null");

        if (\is_string($output) && $output !== '') {
            foreach (explode("\n", trim($output)) as $childPid) {
                if (ctype_digit($childPid) && (int) $childPid > 0) {
                    self::killTreeUnixFallback((int) $childPid);
                }
            }
        }

        self::sendSignal($pid, SIGKILL);
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
     * Send a signal to an entire process group (negative PID).
     */
    private static function sendSignalToGroup(int $pgid, int $signal): void
    {
        \function_exists('posix_kill')
            ? @posix_kill(-$pgid, $signal)
            : @exec("kill -{$signal} -{$pgid} 2>/dev/null");
    }
}
