<?php

declare(strict_types=1);

namespace CMS\Core\Agent;

/**
 * Immutable snapshot of one planned agent.
 *
 * Paths are relative to the project root and are never exposed to the
 * browser: the dashboard only needs the presence flag, not filesystem
 * layout, which must stay undisclosed outside debug mode.
 */
final class AgentDefinition
{
    public function __construct(
        public readonly string $number,
        public readonly string $slug,
        public readonly string $title,
        public readonly AgentStatus $status,
        public readonly bool $specPresent,
        public readonly int $specBytes,
        public readonly ?string $specPath = null,
    ) {
    }

    /** Stable identifier used for anchors and data attributes. */
    public function id(): string
    {
        return 'agent-' . strtolower($this->number . '-' . $this->slug);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'number' => $this->number,
            'slug' => $this->slug,
            'title' => $this->title,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'spec_present' => $this->specPresent,
            'spec_bytes' => $this->specBytes,
        ];
    }
}
