<?php

declare(strict_types=1);

namespace CMS\Core\Agent;

/**
 * Lifecycle state of a planned implementation agent.
 *
 * The set is intentionally closed: the admin dashboard renders a fixed
 * badge per case, so an unknown value would silently lose its styling.
 */
enum AgentStatus: string
{
    case Done = 'done';
    case InProgress = 'in_progress';
    case Pending = 'pending';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Done => 'Selesai',
            self::InProgress => 'Dikerjakan',
            self::Pending => 'Belum',
            self::Unknown => 'Tidak diketahui',
        };
    }
}
