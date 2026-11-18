<?php
declare(strict_types=1);

/**
 * Shared report output helpers.
 * Excel export is UTF-8 CSV (opens directly in Excel/LibreOffice).
 * PDF output uses the browser's print dialog so the user can Save as PDF.
 */
function dibonkeReportExport(string $filename, array $headers, array $rows): void
{
    if (($_GET['export'] ?? '') !== 'excel') {
        return;
    }
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $filename) ?: 'report';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        $clean = [];
        foreach ($row as $value) {
            $value = $value === null ? '' : (string)$value;
            // Prevent spreadsheet formula execution for untrusted text cells.
            if ($value !== '' && preg_match('/^[=+@\\t\\r]|^-(?![0-9])/', $value)) {
                $value = "'" . $value;
            }
            $clean[] = $value;
        }
        fputcsv($out, $clean);
    }
    fclose($out);
    exit;
}

function dibonkeReportActions(string $filename): void
{
    $params = $_GET;
    unset($params['export']);
    $params['export'] = 'excel';
    $excelUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($params);
    echo '<div class="report-actions" style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">';
    echo '<a class="report-export-btn" href="' . htmlspecialchars($excelUrl, ENT_QUOTES, 'UTF-8') . '">Export to Excel (CSV)</a>';
    echo '<button class="report-export-btn" type="button" onclick="window.print()">Print / Save as PDF</button>';
    echo '</div>';
}

function dibonkeReportPrintStyles(): void
{
    echo '<style>
    .report-export-btn{display:inline-flex;align-items:center;padding:9px 13px;border:0;border-radius:7px;background:#174a7e;color:#fff;text-decoration:none;font:700 12px Arial,sans-serif;cursor:pointer}
    @media print{
      @page{size:auto;margin:12mm}
      .top-header,#appSidebar,.mobile-sidebar-backdrop,.sidebar,.report-actions,form,.btn,button,a.btn,nav,.no-print,footer{display:none!important}
      .content,main.content{margin:0!important;padding:0!important;width:100%!important;max-width:none!important}
      body{background:#fff!important;color:#111!important;font-size:10pt!important}
      .panel,.card,.age-panel,.table-card,.stat,.metric{box-shadow:none!important;border:1px solid #ddd!important;break-inside:avoid}
      table{width:100%!important;border-collapse:collapse!important}
      th,td{color:#111!important;background:#fff!important;border:1px solid #bbb!important}
      a{color:#111!important;text-decoration:none!important}
    }
    </style>';
}
