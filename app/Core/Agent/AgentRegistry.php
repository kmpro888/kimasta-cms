<?php

declare(strict_types=1);

namespace CMS\Core\Agent;

use CMS\Core\Filesystem\FilesystemInterface;

/**
 * Read-only view of the planned implementation agents.
 *
 * The registry is deliberately filesystem-backed rather than database
 * backed: agent status is a build-time fact about the repository, and a
 * dashboard that queried MySQL to render it would contradict the
 * cache-first principle the project is built around.
 *
 * Source of truth is `need-agent.md`, which lists every planned agent and
 * its mark. `docs/agents/*.md` supplies the actual specification files.
 */
final class AgentRegistry
{
    /** Maps the checklist glyph in need-agent.md to a status. */
    private const STATUS_GLYPHS = [
        '\u{2705}' => AgentStatus::Done,        // white heavy check mark
        '\u{23F3}' => AgentStatus::Pending,     // hourglass with flowing sand
        '\u{1F504}' => AgentStatus::InProgress, // counterclockwise arrows
    ];

    public function __construct(
        private readonly FilesystemInterface $filesystem,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @return list<AgentDefinition>
     *
     * An unreadable checklist yields an empty list rather than throwing:
     * the dashboard is diagnostic tooling and must still render when the
     * repository is in an unexpected state.
     */
    public function all(): array
    {
        $checklist = $this->readChecklist();

        if ($checklist === null) {
            return [];
        }

        $specs = $this->specFiles();
        $agents = [];

        foreach ($checklist as $entry) {
            $specPath = $this->matchSpec($entry['number'], $entry['slug'], $specs);
            $present = $specPath !== null;
            $bytes = $present ? $this->sizeOf($specPath) : 0;

            $status = $entry['status'];

            // A checklist mark without a spec file is a stale checklist,
            // not a finished module. Reporting it as pending prevents the
            // dashboard from claiming work that does not exist on disk.
            if ($status === AgentStatus::Done && !$present) {
                $status = AgentStatus::Pending;
            }

            if ($status === AgentStatus::Pending && $present) {
                $status = AgentStatus::InProgress;
            }

            $agents[] = new AgentDefinition(
                number: $entry['number'],
                slug: $entry['slug'],
                title: $entry['title'],
                status: $status,
                specPresent: $present,
                specBytes: $bytes,
                specPath: $specPath,
            );
        }

        return $agents;
    }

    /**
     * @return array{total: int, done: int, in_progress: int, pending: int, spec_files: int, percent: int}
     */
    public function summary(): array
    {
        $agents = $this->all();
        $total = count($agents);
        $done = 0;
        $inProgress = 0;
        $pending = 0;
        $specFiles = 0;

        foreach ($agents as $agent) {
            if ($agent->specPresent) {
                $specFiles++;
            }

            match ($agent->status) {
                AgentStatus::Done => $done++,
                AgentStatus::InProgress => $inProgress++,
                AgentStatus::Pending => $pending++,
                AgentStatus::Unknown => null,
            };
        }

        return [
            'total' => $total,
            'done' => $done,
            'in_progress' => $inProgress,
            'pending' => $pending,
            'spec_files' => $specFiles,
            'percent' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
        ];
    }

    public function find(string $id): ?AgentDefinition
    {
        foreach ($this->all() as $agent) {
            if ($agent->id() === $id) {
                return $agent;
            }
        }

        return null;
    }

    /**
     * Read the first section of a spec file, used by the detail panel.
     *
     * Only the leading markdown lines are returned; the full file is never
     * streamed to the browser, so an unrelated section containing internal
     * notes cannot leak by accident.
     */
    public function excerpt(AgentDefinition $agent, int $maxLines = 40): string
    {
        if ($agent->specPath === null) {
            return '';
        }

        $lines = $this->filesystem->readLines($agent->specPath, max(1, $maxLines));

        return implode("\n", $lines);
    }

    /**
     * @return list<array{number: string, slug: string, title: string, status: AgentStatus}>|null
     */
    private function readChecklist(): ?array
    {
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . 'need-agent.md';
        $content = $this->filesystem->get($path);

        if ($content === null) {
            return null;
        }

        $entries = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            // Expected shape: `<branch> Agent-01-Core-Platform.md   <check> sudah`
            if (!preg_match('/Agent-(\d+[A-Z]?)-([A-Za-z0-9-]+)\.md[^\S\r\n]*(.*)$/u', $line, $m)) {
                continue;
            }

            $tail = trim($m[3]);
            $status = AgentStatus::Unknown;

            foreach (self::STATUS_GLYPHS as $glyph => $mapped) {
                if ($tail !== '' && str_contains($tail, $glyph)) {
                    $status = $mapped;
                    break;
                }
            }

            $entries[] = [
                'number' => $m[1],
                'slug' => $m[2],
                'title' => $this->humanize($m[2]),
                'status' => $status,
            ];
        }

        return $entries;
    }

    /** @return array<string, string> map of lowercase filename => absolute path */
    private function specFiles(): array
    {
        $dir = $this->projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'agents';

        if (!is_dir($dir)) {
            return [];
        }

        $names = @scandir($dir);

        if ($names === false) {
            return [];
        }

        $result = [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $name;

            if (is_file($path)) {
                $result[strtolower($name)] = $path;
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $specs
     */
    private function matchSpec(string $number, string $slug, array $specs): ?string
    {
        $candidates = [
            strtolower('Agent-' . $number . '-' . $slug . '.md'),
            strtolower('Agent-' . $number . '.md'),
        ];

        foreach ($candidates as $candidate) {
            if (isset($specs[$candidate])) {
                return $specs[$candidate];
            }
        }

        // Fall back to a numeric-prefix match so `Agent-14A-...` still finds
        // a file stored as `Agent-14a-comments.md` on case-sensitive hosts.
        $prefix = strtolower('agent-' . $number . '-');

        foreach ($specs as $name => $path) {
            if (str_starts_with($name, $prefix)) {
                return $path;
            }
        }

        return null;
    }

    private function sizeOf(string $path): int
    {
        $size = @filesize($path);

        return $size === false ? 0 : $size;
    }

    private function humanize(string $slug): string
    {
        return trim(str_replace(['-', '_'], ' ', $slug));
    }
}
