<?php

declare(strict_types=1);

use Hibla\Parallel\Utilities\ProcessKiller;
use Tests\Unit\Support\ProcessFixture;

describe('ProcessKiller', function () {
    describe('guard conditions', function () {

        it('accepts an empty pid list without throwing', function () {
            ProcessKiller::killTreesAsync([]);

            expect(true)->toBeTrue();
        });

        it('silently no-ops on an already-dead pid', function () {
            $proc = ProcessFixture::spawn('sleep 60');

            posix_kill($proc['pid'], SIGKILL);
            ProcessFixture::waitForDeath($proc['pid']);

            ProcessKiller::killTreesAsync([$proc['pid']]);

            expect(true)->toBeTrue();

            proc_close($proc['resource']);
        })->skipOnWindows();
    });

    describe('Linux', function () {

        beforeEach(function () {
            if (PHP_OS_FAMILY !== 'Linux') {
                $this->markTestSkipped('Linux-only test');
            }
        });

        describe('single process (/proc path)', function () {

            it('kills a single running process', function () {
                $proc = ProcessFixture::spawn('sleep 60');
                $pid = $proc['pid'];

                expect(ProcessFixture::isRunning($pid))->toBeTrue('process should be running before kill');

                ProcessKiller::killTreesAsync([$pid]);

                expect(ProcessFixture::waitForDeath($pid))->toBeTrue("pid {$pid} should be dead after kill");

                proc_close($proc['resource']);
            });

            it('kills multiple independent processes in one call', function () {
                $processes = [
                    ProcessFixture::spawn('sleep 60'),
                    ProcessFixture::spawn('sleep 60'),
                    ProcessFixture::spawn('sleep 60'),
                ];

                $pids = array_column($processes, 'pid');

                foreach ($pids as $pid) {
                    expect(ProcessFixture::isRunning($pid))->toBeTrue("pid {$pid} should be running before kill");
                }

                ProcessKiller::killTreesAsync($pids);

                foreach ($pids as $pid) {
                    expect(ProcessFixture::waitForDeath($pid))->toBeTrue("pid {$pid} should be dead after kill");
                }

                foreach ($processes as $proc) {
                    proc_close($proc['resource']);
                }
            });
        });

        describe('process tree (shared PGID, bottom-up walk)', function () {

            it('kills the root and all direct children', function () {
                $proc = ProcessFixture::spawn('bash -c "sleep 60 & sleep 60 & wait"');
                $rootPid = $proc['pid'];

                usleep(100_000);

                $children = ProcessFixture::childPids($rootPid);
                expect($children)->not->toBeEmpty('bash should have spawned children');

                ProcessKiller::killTreesAsync([$rootPid]);

                expect(ProcessFixture::waitForDeath($rootPid))->toBeTrue('root bash should be dead');

                foreach ($children as $childPid) {
                    expect(ProcessFixture::waitForDeath($childPid))->toBeTrue("child {$childPid} should be dead");
                }

                proc_close($proc['resource']);
            });

            it('kills deeply nested grandchildren', function () {
                $proc = ProcessFixture::spawn('bash -c "bash -c \"bash -c \'sleep 60\' &\" & wait"');
                $rootPid = $proc['pid'];

                usleep(300_000);

                ProcessKiller::killTreesAsync([$rootPid]);

                expect(ProcessFixture::waitForDeath($rootPid, 2000))->toBeTrue('root should be dead');

                foreach (ProcessFixture::childPids($rootPid) as $orphan) {
                    expect(ProcessFixture::waitForDeath($orphan, 500))->toBeTrue("orphan {$orphan} should be dead");
                }

                proc_close($proc['resource']);
            });
        });

        describe('process group kill (PGID === PID, atomic path)', function () {

            it('kills an entire process group atomically when target is a group leader', function () {
                $proc = ProcessFixture::spawn(['setsid', 'bash', '-c', 'sleep 60 & sleep 60 & wait']);
                $rootPid = $proc['pid'];

                usleep(100_000);

                expect(ProcessFixture::pgid($rootPid))->toBe($rootPid, 'setsid bash should be its own group leader');

                $children = ProcessFixture::childPids($rootPid);
                expect($children)->not->toBeEmpty('group leader bash should have spawned children');

                ProcessKiller::killTreesAsync([$rootPid]);

                expect(ProcessFixture::waitForDeath($rootPid))->toBeTrue('group leader should be dead');

                foreach ($children as $childPid) {
                    expect(ProcessFixture::waitForDeath($childPid))->toBeTrue("group member {$childPid} should be dead");
                }

                proc_close($proc['resource']);
            });

            it('does not double-kill a group already killed in the same batch', function () {
                $procA = ProcessFixture::spawn(['setsid', 'sleep', '60']);
                $procB = ProcessFixture::spawn(['setsid', 'sleep', '60']);

                $pidA = $procA['pid'];
                $pidB = $procB['pid'];

                expect(ProcessFixture::isRunning($pidA))->toBeTrue();
                expect(ProcessFixture::isRunning($pidB))->toBeTrue();

                ProcessKiller::killTreesAsync([$pidA, $pidB]);

                expect(ProcessFixture::waitForDeath($pidA))->toBeTrue('process A should be dead');
                expect(ProcessFixture::waitForDeath($pidB))->toBeTrue('process B should be dead');

                proc_close($procA['resource']);
                proc_close($procB['resource']);
            });
        });
    });

    describe('macOS', function () {

        beforeEach(function () {
            if (PHP_OS_FAMILY !== 'Darwin') {
                $this->markTestSkipped('macOS-only test');
            }
        });

        describe('single process (pgrep fallback)', function () {

            it('kills a single running process', function () {
                $proc = ProcessFixture::spawn('sleep 60');
                $pid = $proc['pid'];

                expect(ProcessFixture::isRunning($pid))->toBeTrue('process should be running before kill');

                ProcessKiller::killTreesAsync([$pid]);

                expect(ProcessFixture::waitForDeath($pid))->toBeTrue("pid {$pid} should be dead");

                proc_close($proc['resource']);
            });

            it('kills multiple independent processes in one call', function () {
                $processes = [
                    ProcessFixture::spawn('sleep 60'),
                    ProcessFixture::spawn('sleep 60'),
                    ProcessFixture::spawn('sleep 60'),
                ];

                $pids = array_column($processes, 'pid');

                ProcessKiller::killTreesAsync($pids);

                foreach ($pids as $pid) {
                    expect(ProcessFixture::waitForDeath($pid))->toBeTrue("pid {$pid} should be dead");
                }

                foreach ($processes as $proc) {
                    proc_close($proc['resource']);
                }
            });
        });

        describe('process tree (pgrep fallback)', function () {

            it('kills root and all direct children via pgrep', function () {
                $proc = ProcessFixture::spawn('bash -c "sleep 60 & sleep 60 & wait"');
                $rootPid = $proc['pid'];

                usleep(150_000);

                $children = ProcessFixture::childPids($rootPid);
                expect($children)->not->toBeEmpty('bash should have spawned children');

                ProcessKiller::killTreesAsync([$rootPid]);

                expect(ProcessFixture::waitForDeath($rootPid))->toBeTrue('root bash should be dead');

                foreach ($children as $childPid) {
                    expect(ProcessFixture::waitForDeath($childPid))->toBeTrue("child {$childPid} should be dead");
                }

                proc_close($proc['resource']);
            });

            it('kills grandchildren recursively via pgrep', function () {
                $proc = ProcessFixture::spawn('bash -c "bash -c \"bash -c \'sleep 60\' &\" & wait"');
                $rootPid = $proc['pid'];

                usleep(400_000);

                ProcessKiller::killTreesAsync([$rootPid]);

                expect(ProcessFixture::waitForDeath($rootPid, 2000))->toBeTrue('root should be dead');

                foreach (ProcessFixture::childPids($rootPid) as $orphan) {
                    expect(ProcessFixture::waitForDeath($orphan, 500))->toBeTrue("orphan {$orphan} should be dead");
                }

                proc_close($proc['resource']);
            });
        });
    });

    describe('Windows', function () {

        beforeEach(function () {
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->markTestSkipped('Windows-only test');
            }
        });

        describe('single process (taskkill path)', function () {

            it('kills a single running process', function () {
                $proc = ProcessFixture::spawnPhpScript('sleep(60);');
                $pid = $proc['pid'];

                expect(ProcessFixture::isRunning($pid))->toBeTrue('process should be running before kill');

                ProcessKiller::killTreesAsync([$pid]);

                expect(ProcessFixture::waitForDeath($pid, 3000))->toBeTrue("pid {$pid} should be dead after kill");

                proc_close($proc['resource']);
                ProcessFixture::removeTempScript($proc['script']);
            });

            it('kills multiple independent processes in one call', function () {
                $processes = [
                    ProcessFixture::spawnPhpScript('sleep(60);'),
                    ProcessFixture::spawnPhpScript('sleep(60);'),
                    ProcessFixture::spawnPhpScript('sleep(60);'),
                ];

                $pids = array_column($processes, 'pid');

                foreach ($pids as $pid) {
                    expect(ProcessFixture::isRunning($pid))->toBeTrue("pid {$pid} should be running before kill");
                }

                ProcessKiller::killTreesAsync($pids);

                foreach ($pids as $pid) {
                    expect(ProcessFixture::waitForDeath($pid, 3000))->toBeTrue("pid {$pid} should be dead");
                }

                foreach ($processes as $proc) {
                    proc_close($proc['resource']);
                    ProcessFixture::removeTempScript($proc['script']);
                }
            });
        });

        describe('process tree (taskkill /T path)', function () {

            it('kills root and spawned children via taskkill /T', function () {
                $childScript = ProcessFixture::writeTempScript('sleep(60);');

                $proc = ProcessFixture::spawnPhpScript(sprintf(
                    'proc_open([PHP_BINARY, %s], [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]], $p); sleep(60);',
                    var_export($childScript, true)
                ));

                $rootPid = $proc['pid'];

                usleep(500_000);

                $children = ProcessFixture::waitForChildren($rootPid, 3000);
                expect($children)->not->toBeEmpty('root PHP should have spawned a child process');

                ProcessKiller::killTreesAsync([$rootPid]);

                expect(ProcessFixture::waitForDeath($rootPid, 5000))->toBeTrue('root process should be dead');

                foreach ($children as $childPid) {
                    expect(ProcessFixture::waitForDeath($childPid, 5000))->toBeTrue("child {$childPid} should be dead");
                }

                proc_close($proc['resource']);
                ProcessFixture::removeTempScript($proc['script']);
                ProcessFixture::removeTempScript($childScript);
            });

            it('kills a large batch spanning the chunk(50) boundary', function () {
                $processes = [];
                for ($i = 0; $i < 55; $i++) {
                    $processes[] = ProcessFixture::spawnPhpScript('sleep(60);');
                }

                $pids = array_column($processes, 'pid');

                ProcessKiller::killTreesAsync($pids);

                foreach ($pids as $pid) {
                    expect(ProcessFixture::waitForDeath($pid, 5000))->toBeTrue("pid {$pid} should be dead");
                }

                foreach ($processes as $proc) {
                    proc_close($proc['resource']);
                    ProcessFixture::removeTempScript($proc['script']);
                }
            });
        });
    });
});
