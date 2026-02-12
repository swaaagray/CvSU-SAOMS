<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once __DIR__ . '/phpspreadsheet/vendor/autoload.php';

requireRole(['osas']);

$format = $_GET['format'] ?? 'csv';

// Fetch all colleges, courses, and organizations
$sql = "SELECT 
            colleges.code AS college_code, 
            colleges.name AS college_name, 
            courses.code AS course_code, 
            courses.name AS course_name, 
            organizations.code AS org_code, 
            organizations.org_name AS org_name,
            organizations.president_name AS president_name,
            organizations.adviser_name AS adviser_name
        FROM colleges
        LEFT JOIN courses ON courses.college_id = colleges.id
        LEFT JOIN organizations ON organizations.course_id = courses.id
        ORDER BY colleges.name ASC, courses.name ASC, organizations.org_name ASC";
$result = $conn->query($sql);

// Prepare flat data: [College, Course Code, Course Name, Org Code, Org Name, President, Adviser]
$rows = [];
$last_college = '';
$college_rowspans = [];
$college_rows = [];
if ($result && $result->num_rows > 0) {
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $college = $row['college_name'] . ' - ' . $row['college_code'];
        $course_code = $row['course_code'];
        $course_name = $row['course_name'];
        if (!empty($row['org_code'])) {
            $data[] = [
                $college,
                $course_code,
                $course_name,
                $row['org_code'],
                $row['org_name'],
                $row['president_name'],
                $row['adviser_name']
            ];
        } else {
            $data[] = [
                $college,
                $course_code,
                $course_name,
                '', '', '', ''
            ];
        }
    }
    // Calculate college rowspans for merging
    $rows = [];
    $college_rowspans = [];
    $last_college = null;
    $span_start = 0;
    foreach ($data as $i => $row) {
        if ($row[0] !== $last_college) {
            if ($last_college !== null) {
                $college_rowspans[$span_start] = $i - $span_start;
            }
            $span_start = $i;
            $last_college = $row[0];
        }
        $rows[] = $row;
    }
    if ($last_college !== null) {
        $college_rowspans[$span_start] = count($rows) - $span_start;
    }
}

$headers = ['College', 'Course Code', 'Course Name', 'Organization Code', 'Organization Name', 'President', 'Adviser'];
$filename = 'Registered Colleges, Courses & Organizations';

if ($format === 'csv') {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    $last_college = '';
    foreach ($rows as $i => $row) {
        $row_out = $row;
        if ($row[0] === $last_college) {
            $row_out[0] = '';
        } else {
            $last_college = $row[0];
        }
        fputcsv($output, $row_out);
    }
    fclose($output);
    exit;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if ($format === 'excel' || $format === 'pdf') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    // Title row
    $sheet->setCellValue('A1', 'Registered Colleges, Courses & Organizations');
    $sheet->mergeCells('A1:G1');
    if ($format === 'pdf') {
        $sheet->getStyle('A1:G1')->applyFromArray([
            'font' => [ 'bold' => true, 'size' => 28, 'name' => 'DejaVu Sans' ], // Slightly smaller for PDF
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(44); // Slightly smaller row height
    } else {
        $sheet->getStyle('A1')->applyFromArray([
            'font' => [ 'bold' => true, 'size' => 24 ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(44);
    }
    // Add spacing row
    $sheet->setCellValue('A2', '');
    $sheet->mergeCells('A2:G2');
    $sheet->getRowDimension(2)->setRowHeight(12);
    // Write headers at row 3
    $sheet->fromArray($headers, NULL, 'A3');
    $sheet->getStyle('A3:G3')->applyFromArray([
        'font' => [ 'bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 15 ],
        'fill' => [ 'fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '003366'] ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);
    $sheet->getRowDimension(3)->setRowHeight(32);
    // Write data starting at row 4
    $rowNum = 4;
    foreach ($rows as $i => $row) {
        $sheet->fromArray($row, NULL, 'A' . $rowNum);
        $sheet->getRowDimension($rowNum)->setRowHeight(26); // More spacing between rows
        $rowNum++;
    }
    $endRow = $rowNum - 1;
    // Merge college cells
    foreach ($college_rowspans as $start => $span) {
        if ($span > 1) {
            $mergeStart = $start + 4; // +4 for title, spacing, header, and 1-based
            $mergeEnd = $mergeStart + $span - 1;
            $sheet->mergeCells('A' . $mergeStart . ':A' . $mergeEnd);
        }
    }
    // Center align all columns
    $sheet->getStyle('A1:G' . $endRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A1:G' . $endRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    // Borders for all cells
    $sheet->getStyle('A3:G' . $endRow)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
            ],
        ],
    ]);
    // Set enhanced background color for all data rows
    $sheet->getStyle('A4:G' . $endRow)->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'F7F7F7'], // Very light gray
        ],
    ]);
    // Set data font size for all data rows
    $sheet->getStyle('A4:G' . $endRow)->getFont()->setSize(13);
    // Enable text wrapping for all data cells
    $sheet->getStyle('A4:G' . $endRow)->getAlignment()->setWrapText(true);
    // Set explicit column widths for PDF export to maximize page usage
    if ($format === 'pdf') {
        // Set column widths in millimeters (A4 landscape width is 297mm minus margins)
        // Adjust as needed to fit 7 columns nicely
        $sheet->getColumnDimension('A')->setWidth(45); // College
        $sheet->getColumnDimension('B')->setWidth(18); // Course Code
        $sheet->getColumnDimension('C')->setWidth(35); // Course Name
        $sheet->getColumnDimension('D')->setWidth(22); // Org Code
        $sheet->getColumnDimension('E')->setWidth(38); // Org Name
        $sheet->getColumnDimension('F')->setWidth(28); // President
        $sheet->getColumnDimension('G')->setWidth(28); // Adviser
        // Increase font size for data rows
        for ($i = 4; $i <= $endRow; $i++) {
            $sheet->getStyle('A' . $i . ':G' . $i)->getFont()->setSize(14);
        }
    } else {
        // Excel: keep auto-size for user flexibility
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        // Set smaller row heights and font for Excel
        $sheet->getRowDimension(3)->setRowHeight(20); // Header row
        for ($i = 4; $i <= $endRow; $i++) {
            $sheet->getRowDimension($i)->setRowHeight(16); // Data rows
        }
        // Set data font size to 11 for Excel
        $sheet->getStyle('A4:G' . $endRow)->getFont()->setSize(11);
        // Disable text wrapping for data rows in Excel
        $sheet->getStyle('A4:G' . $endRow)->getAlignment()->setWrapText(false);
    }
    // Note: PDF writers (Mpdf, Dompdf, TCPDF) will repeat header rows on each page automatically for tables.
    // The table will break naturally across pages, and column widths will adapt to content.
    // Add footer with page numbers (for PDF)
    if ($format === 'pdf') {
        $spreadsheet->getActiveSheet()->getHeaderFooter()->setOddFooter('&CPage &P of &N');
        // Remove fit-to-width/height for natural page breaks
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        // Set smaller margins to maximize usable area
        $sheet->getPageMargins()->setTop(0.3);
        $sheet->getPageMargins()->setBottom(0.3);
        $sheet->getPageMargins()->setLeft(0.3);
        $sheet->getPageMargins()->setRight(0.3);
    }
    // Output
    if ($format === 'excel') {
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    } else if ($format === 'pdf') {
        if (ob_get_length()) ob_end_clean();
        // Try to use Mpdf, Dompdf, or Tcpdf (in that order)
        $pdfWriterType = null;
        if (class_exists('Mpdf\\Mpdf')) {
            $pdfWriterType = 'Mpdf';
        } elseif (class_exists('Dompdf\\Dompdf')) {
            $pdfWriterType = 'Dompdf';
        } elseif (class_exists('TCPDF')) {
            $pdfWriterType = 'Tcpdf';
        }
        if ($pdfWriterType) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, $pdfWriterType);
            $writer->save('php://output');
            exit;
        } else {
            http_response_code(500);
            echo 'PDF export requires mPDF, Dompdf, or TCPDF to be installed.';
            exit;
        }
    }
}

http_response_code(400);
echo 'Invalid export format.'; 