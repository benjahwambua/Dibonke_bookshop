<?php
declare(strict_types=1);

/**
 * Shared report output helpers.
 * Excel export uses Excel 2003 XML Spreadsheet format (.xls), which opens
 * as a workbook in Microsoft Excel and LibreOffice without extra libraries.
 * PDF output uses the browser print dialog so the user can Save as PDF.
 */
function dibonkeReportXmlCell($value): string
{
    $value = $value === null ? '' : (string)$value;
    $type = 'String';
    // Preserve identifiers and phone numbers with leading zeroes as text.
    if (is_int($value) || is_float($value) || ($value !== '' && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?$/', $value))) {
        $type = 'Number';
    }
    $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<Cell><Data ss:Type="' . $type . '">' . $escaped . '</Data></Cell>';
}

function dibonkeReportExport(string $filename, array $headers, array $rows): void
{
    if (($_GET['export'] ?? '') !== 'excel') {
        return;
    }

    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $filename) ?: 'report';
    $sheetName = substr(preg_replace('/[^A-Za-z0-9_]/', '_', $safe) ?: 'Report', 0, 31);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe . '.xls"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<?mso-application progid="Excel.Sheet"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    echo '<Worksheet ss:Name="' . htmlspecialchars($sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"><Table>';
    echo '<Row>';
    foreach ($headers as $header) {
        echo dibonkeReportXmlCell($header);
    }
    echo '</Row>';

    foreach ($rows as $row) {
        echo '<Row>';
        foreach ($row as $value) {
            echo dibonkeReportXmlCell($value);
        }
        echo '</Row>';
    }

    echo '</Table></Worksheet></Workbook>';
    exit;
}

function dibonkeReportActions(string $filename): void
{
    $params = $_GET;
    unset($params['export']);
    $params['export'] = 'excel';
    $excelUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($params);
    echo '<div class="report-actions" style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">';
    echo '<a class="report-export-btn" href="' . htmlspecialchars($excelUrl, ENT_QUOTES, 'UTF-8') . '">Export to Excel</a>';
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
