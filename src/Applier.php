<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches;

use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;

/**
 * Applies a sequence of patches to the project with git apply, the same tool ece-patches uses.
 *
 * Adobe's monthly patches are sequential and non-cumulative: once October is applied, July
 * can no longer be recognised on its own. So "is this patch applied?" is answered for the
 * sequence as a whole: the longest leading run of steps that can be undone, newest first,
 * is already in place. Everything is rehearsed on a scratch copy of the files involved
 * before any project file changes.
 */
class Applier
{
    /** @var ProcessExecutor */
    private $process;

    /** @var IOInterface */
    private $io;

    public function __construct(ProcessExecutor $process, IOInterface $io)
    {
        $this->process = $process;
        $this->io = $io;
    }

    /**
     * @param array<int, array{label: string, content: string}> $steps applied in order
     */
    public function apply(array $steps, string $projectDir): void
    {
        $count = $this->appliedCount($steps, $projectDir);
        $applied = array_slice($steps, 0, $count);
        $pending = [];

        $scratch = $this->scratchCopy($steps, $projectDir);
        try {
            foreach (array_slice($steps, $count) as $step) {
                if (!$this->git(['apply', '--check'], $step['content'], $scratch)) {
                    // A step applied earlier on its own (one touching files nothing else patches)
                    // need not sit inside the leading run; recognise it directly.
                    if ($this->git(['apply', '--reverse', '--check'], $step['content'], $scratch)) {
                        $applied[] = $step;
                        continue;
                    }
                    throw new PatchException(sprintf(
                        '%s cannot be applied. If the project also applies this patch another way '
                        . '(m2-hotfixes, an older install), remove the duplicate or reinstall the affected '
                        . 'Magento packages. No project files were changed.',
                        $step['label']
                    ));
                }
                $this->git(['apply'], $step['content'], $scratch, true);
                $pending[] = $step;
            }
        } finally {
            $this->remove($scratch);
        }

        foreach ($applied as $step) {
            $this->io->write(sprintf('  - %s is already applied', $step['label']), true, IOInterface::VERBOSE);
        }
        foreach ($pending as $step) {
            $this->git(['apply'], $step['content'], $projectDir, true);
            $this->io->write(sprintf('  - Applied %s', $step['label']));
        }
    }

    /**
     * Counts how many leading steps are already in place in the project.
     *
     * @param array<int, array{label: string, content: string}> $steps
     */
    public function appliedCount(array $steps, string $projectDir): int
    {
        for ($count = count($steps); $count > 0; $count--) {
            $scratch = $this->scratchCopy($steps, $projectDir);
            try {
                $undone = true;
                foreach (array_reverse(array_slice($steps, 0, $count)) as $step) {
                    if (!$this->git(['apply', '--reverse'], $step['content'], $scratch)) {
                        $undone = false;
                        break;
                    }
                }
            } finally {
                $this->remove($scratch);
            }
            if ($undone) {
                return $count;
            }
        }

        return 0;
    }

    /**
     * Copies every existing file the steps touch into a fresh scratch directory.
     *
     * @param array<int, array{label: string, content: string}> $steps
     */
    private function scratchCopy(array $steps, string $projectDir): string
    {
        $scratch = sys_get_temp_dir() . '/mcp-scratch-' . bin2hex(random_bytes(6));
        mkdir($scratch, 0700);
        foreach ($steps as $step) {
            foreach (Diff::paths($step['content']) as $path) {
                if (strpos($path, '..') !== false) {
                    throw new PatchException(sprintf('%s contains an unsafe path: %s', $step['label'], $path));
                }
                $source = $projectDir . '/' . $path;
                $target = $scratch . '/' . $path;
                if (is_file($source) && !is_file($target)) {
                    @mkdir(dirname($target), 0700, true);
                    copy($source, $target);
                }
            }
        }

        return $scratch;
    }

    private function remove(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * @param string[] $args
     */
    private function git(array $args, string $patch, string $cwd, bool $mustSucceed = false): bool
    {
        $file = tempnam(sys_get_temp_dir(), 'mcp');
        file_put_contents($file, $patch);
        try {
            $command = 'git ' . implode(' ', array_map([ProcessExecutor::class, 'escape'], array_merge($args, [$file])));
            $output = '';
            $ok = $this->process->execute($command, $output, $cwd) === 0;
            if (!$ok && $mustSucceed) {
                throw new PatchException(trim($command . "\n" . $this->process->getErrorOutput()));
            }

            return $ok;
        } finally {
            unlink($file);
        }
    }
}
