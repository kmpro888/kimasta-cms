<?php

declare(strict_types=1);

namespace CMS\Core\Http\Controller;

use CMS\Core\Agent\AgentDefinition;

/**
 * Server-rendered HTML for the agent dashboard.
 *
 * Kept as a plain PHP renderer instead of a template engine: the project
 * forbids extra runtime dependencies on shared hosting, and this page has
 * exactly one view. Every interpolated value is escaped with htmlspecialchars
 * because agent titles come from repository filenames.
 */
final class AgentDashboardView
{
    /**
     * @param list<AgentDefinition> $agents
     * @param array{total: int, done: int, in_progress: int, pending: int, spec_files: int, percent: int} $summary
     */
    public static function render(
        array $agents,
        array $summary,
        ?AgentDefinition $selected,
        string $excerpt,
    ): string {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $rows = '';

        foreach ($agents as $agent) {
            $data = $agent->toArray();
            $isSelected = $selected !== null && $selected->id() === $data['id'];
            $classes = 'agent-row status-' . $e($data['status']) . ($isSelected ? ' is-selected' : '');
            $href = '?agent=' . rawurlencode((string) $data['id']);

            $rows .= '<tr class="' . $classes . '" data-status="' . $e($data['status']) . '" data-title="' . $e($data['title']) . '">'
                . '<td class="col-num">' . $e($data['number']) . '</td>'
                . '<td class="col-name"><a href="' . $e($href) . '">' . $e($data['title']) . '</a></td>'
                . '<td class="col-status"><span class="badge badge-' . $e($data['status']) . '">' . $e($data['status_label']) . '</span></td>'
                . '<td class="col-spec">' . ($data['spec_present']
                    ? '<span class="spec-ok">' . $e(self::humanBytes((int) $data['spec_bytes'])) . '</span>'
                    : '<span class="spec-missing">&mdash;</span>') . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="empty">Daftar agent tidak ditemukan. Periksa <code>need-agent.md</code>.</td></tr>';
        }

        $detail = '';

        if ($selected !== null) {
            $detail = '<section class="detail" id="detail">'
                . '<header class="detail-head">'
                . '<h2>Agent ' . $e($selected->number) . ' &mdash; ' . $e($selected->title) . '</h2>'
                . '<a class="detail-close" href="." aria-label="Tutup detail">&times;</a>'
                . '</header>'
                . '<div class="detail-meta">'
                . '<span class="badge badge-' . $e($selected->status->value) . '">' . $e($selected->status->label()) . '</span>'
                . '<span>Spesifikasi: ' . ($selected->specPresent ? 'tersedia' : 'belum ada') . '</span>'
                . '</div>'
                . ($excerpt !== ''
                    ? '<pre class="excerpt">' . $e($excerpt) . '</pre>'
                    : '<p class="empty">File spesifikasi belum tersedia untuk agent ini.</p>')
                . '</section>';
        }

        $cards = self::statCards($summary, $e);

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Agent Dashboard &middot; Lightweight Portal CMS</title>
<style>
:root {
  --bg: #0f1115;
  --panel: #171a21;
  --panel-2: #1e222b;
  --border: #2a2f3a;
  --text: #e6e9ef;
  --muted: #8b94a7;
  --accent: #4f8cff;
  --ok: #2fbf71;
  --warn: #e0a53a;
  --idle: #5a6274;
}
* { box-sizing: border-box; }
body {
  margin: 0;
  font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  background: var(--bg);
  color: var(--text);
  line-height: 1.5;
}
.wrap { max-width: 1080px; margin: 0 auto; padding: 32px 20px 64px; }
header.page-head { margin-bottom: 28px; }
header.page-head h1 { margin: 0 0 4px; font-size: 22px; letter-spacing: -0.01em; }
header.page-head p { margin: 0; color: var(--muted); font-size: 14px; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 24px; }
.stat { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; }
.stat .value { font-size: 24px; font-weight: 650; font-variant-numeric: tabular-nums; }
.stat .label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; }
.progress { height: 6px; background: var(--panel-2); border-radius: 999px; overflow: hidden; margin-top: 10px; }
.progress > span { display: block; height: 100%; background: var(--ok); }
.toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; flex-wrap: wrap; }
.toolbar input[type="search"] {
  flex: 1 1 240px; min-width: 180px; padding: 9px 12px; border-radius: 8px;
  border: 1px solid var(--border); background: var(--panel); color: var(--text); font-size: 14px;
}
.toolbar input[type="search"]:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
.filters { display: flex; gap: 6px; flex-wrap: wrap; }
.filters button {
  padding: 7px 12px; border-radius: 999px; border: 1px solid var(--border);
  background: var(--panel); color: var(--muted); font-size: 13px; cursor: pointer;
}
.filters button[aria-pressed="true"] { background: var(--accent); border-color: var(--accent); color: #fff; }
table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
th, td { padding: 11px 14px; text-align: left; font-size: 14px; border-bottom: 1px solid var(--border); }
th { background: var(--panel-2); font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--muted); }
tbody tr:last-child td { border-bottom: none; }
tbody tr.is-selected { background: rgba(79, 140, 255, 0.08); }
td.col-num { width: 72px; color: var(--muted); font-variant-numeric: tabular-nums; }
td.col-status { width: 140px; }
td.col-spec { width: 120px; color: var(--muted); font-variant-numeric: tabular-nums; }
td a { color: var(--text); text-decoration: none; }
td a:hover { color: var(--accent); text-decoration: underline; }
.badge { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.badge-done { background: rgba(47, 191, 113, 0.15); color: var(--ok); }
.badge-in_progress { background: rgba(224, 165, 58, 0.15); color: var(--warn); }
.badge-pending { background: rgba(90, 98, 116, 0.25); color: var(--muted); }
.badge-unknown { background: rgba(90, 98, 116, 0.2); color: var(--muted); }
.spec-ok { color: var(--ok); }
.spec-missing { color: var(--idle); }
.empty { color: var(--muted); text-align: center; padding: 24px; }
.detail { margin-top: 24px; background: var(--panel); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.detail-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 16px; background: var(--panel-2); border-bottom: 1px solid var(--border); }
.detail-head h2 { margin: 0; font-size: 16px; }
.detail-close { color: var(--muted); text-decoration: none; font-size: 20px; line-height: 1; }
.detail-close:hover { color: var(--text); }
.detail-meta { display: flex; gap: 14px; align-items: center; padding: 12px 16px; color: var(--muted); font-size: 13px; }
.excerpt { margin: 0; padding: 16px; overflow-x: auto; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; line-height: 1.65; color: #c7cddb; white-space: pre-wrap; word-break: break-word; }
[hidden] { display: none !important; }
@media (max-width: 640px) {
  td.col-spec, th.col-spec { display: none; }
  .wrap { padding: 20px 12px 48px; }
}
</style>
</head>
<body>
<div class="wrap">
  <header class="page-head">
    <h1>Agent Dashboard</h1>
    <p>Status implementasi agent Lightweight Portal CMS &mdash; dibaca langsung dari <code>need-agent.md</code> dan <code>docs/agents/</code>.</p>
  </header>

  <div class="stats">
    {$cards}
  </div>

  <div class="toolbar">
    <input type="search" id="agent-search" placeholder="Cari agent berdasarkan nama atau nomor&hellip;" aria-label="Cari agent">
    <div class="filters" role="group" aria-label="Filter status">
      <button type="button" data-filter="all" aria-pressed="true">Semua</button>
      <button type="button" data-filter="done" aria-pressed="false">Selesai</button>
      <button type="button" data-filter="in_progress" aria-pressed="false">Dikerjakan</button>
      <button type="button" data-filter="pending" aria-pressed="false">Belum</button>
    </div>
  </div>

  <table id="agent-table">
    <thead>
      <tr>
        <th scope="col">#</th>
        <th scope="col">Agent</th>
        <th scope="col">Status</th>
        <th scope="col">Spesifikasi</th>
      </tr>
    </thead>
    <tbody>
      {$rows}
    </tbody>
  </table>

  {$detail}
</div>
<script>
(function () {
  "use strict";

  var search = document.getElementById("agent-search");
  var table = document.getElementById("agent-table");
  var buttons = Array.prototype.slice.call(document.querySelectorAll(".filters button"));

  if (!table) { return; }

  var rows = Array.prototype.slice.call(table.querySelectorAll("tbody tr[data-status]"));
  var activeFilter = "all";

  function apply() {
    var term = (search && search.value ? search.value : "").trim().toLowerCase();
    var visible = 0;

    rows.forEach(function (row) {
      var statusMatch = activeFilter === "all" || row.getAttribute("data-status") === activeFilter;
      var haystack = (row.textContent || "").toLowerCase();
      var termMatch = term === "" || haystack.indexOf(term) !== -1;
      var show = statusMatch && termMatch;
      row.hidden = !show;
      if (show) { visible++; }
    });

    var empty = document.getElementById("no-results");
    if (!empty) {
      empty = document.createElement("tr");
      empty.id = "no-results";
      empty.innerHTML = '<td colspan="4" class="empty">Tidak ada agent yang cocok dengan filter.</td>';
      table.querySelector("tbody").appendChild(empty);
    }
    empty.hidden = visible !== 0;
  }

  buttons.forEach(function (button) {
    button.addEventListener("click", function () {
      activeFilter = button.getAttribute("data-filter") || "all";
      buttons.forEach(function (other) {
        other.setAttribute("aria-pressed", other === button ? "true" : "false");
      });
      apply();
    });
  });

  if (search) { search.addEventListener("input", apply); }
})();
</script>
</body>
</html>
HTML;
    }

    /**
     * @param array{total: int, done: int, in_progress: int, pending: int, spec_files: int, percent: int} $summary
     */
    private static function statCards(array $summary, callable $e): string
    {
        $cards = [
            ['Total agent', $summary['total'], ''],
            ['Selesai', $summary['done'], 'badge-done'],
            ['Dikerjakan', $summary['in_progress'], 'badge-in_progress'],
            ['Belum', $summary['pending'], 'badge-pending'],
        ];

        $html = '';

        foreach ($cards as [$label, $value, $class]) {
            $html .= '<div class="stat">'
                . '<div class="value">' . $e($value) . '</div>'
                . '<div class="label">' . $e($label) . '</div>'
                . '</div>';
        }

        $html .= '<div class="stat">'
            . '<div class="value">' . $e($summary['percent']) . '%</div>'
            . '<div class="label">Progres</div>'
            . '<div class="progress"><span style="width:' . $e($summary['percent']) . '%"></span></div>'
            . '</div>';

        return $html;
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        return round($bytes / 1024, 1) . ' KB';
    }
}
