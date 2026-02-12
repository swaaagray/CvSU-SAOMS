<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president']);

$orgId = getCurrentOrganizationId();

// Fetch organization name and description
$orgName = '';
$orgDescription = '';
$orgStmt = $conn->prepare("SELECT org_name, description FROM organizations WHERE id = ?");
$orgStmt->bind_param("i", $orgId);
$orgStmt->execute();
$orgStmt->bind_result($orgName, $orgDescription);
$orgStmt->fetch();
$orgStmt->close();

// Sanitize organization name and create a dynamic filename
$safeOrgName = preg_replace('/[^a-zA-Z0-9_]+/', ' ', trim($orgName));
$baseFilename = $safeOrgName . '_Membership Status';

$selected_section = $_GET['section'] ?? '';
$selected_status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$format = $_GET['format'] ?? 'csv';

// Append filters to the filename if they are active
if ($selected_section !== '' && $selected_section !== 'All') {
    $safeSection = preg_replace('/[^a-zA-Z0-9_]+/', '-', trim($selected_section));
    $baseFilename .= '_Section ' . $safeSection;
}
if ($selected_status !== '' && $selected_status !== 'All') {
    $safeStatus = preg_replace('/[^a-zA-Z0-9_]+/', ' ', trim($selected_status));
    $baseFilename .= ' ' . $safeStatus;
}
if ($search !== '') {
    $safeSearch = preg_replace('/[^a-zA-Z0-9_]+/', ' ', trim($search));
    $baseFilename .= '_search_' . $safeSearch;
}

// Build query with filters (no LIMIT)
$query = "SELECT student_name, student_number, sex, course, section, org_status FROM student_data WHERE organization_id = ?";
$params = [$orgId];
$types = "i";
if ($selected_section !== '' && $selected_section !== 'All') {
    $query .= " AND section = ?";
    $params[] = $selected_section;
    $types .= "s";
}
if ($selected_status !== '' && $selected_status !== 'All') {
    $query .= " AND org_status = ?";
    $params[] = $selected_status;
    $types .= "s";
}
if ($search !== '') {
    $query .= " AND (student_number LIKE ? OR student_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}
$query .= " ORDER BY student_name ASC";
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$headers = ['#', 'Student Name', 'Student Number', 'Sex', 'Course', 'Section', 'Status'];
$csv_header = ['Student Name', 'Student Number', 'Sex', 'Course', 'Section', 'Status'];

// Prepare title row (single, purely a title)
$title = 'List of Membership Status - ' . $orgName;
if ($selected_section !== '' && $selected_section !== 'All') {
    $title .= ' - Section: ' . $selected_section;
}

// Add numbering to students array
$numbered_students = [];
foreach ($students as $i => $row) {
    array_unshift($row, $i + 1);
    $numbered_students[] = $row;
}

if ($format === 'csv') {
    // Clean output buffer and ensure no leading whitespace
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $baseFilename . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $csv_header);
    foreach ($students as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

if ($format === 'excel') {
    require_once __DIR__ . '/phpspreadsheet/vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    // Table header and data start at row 1
    $headerRowNum = 1;
    $sheet->fromArray($headers, NULL, 'A' . $headerRowNum);
    $sheet->fromArray($numbered_students, NULL, 'A' . ($headerRowNum + 1));
    // Style the header row
    $headerCellRange = 'A' . $headerRowNum . ':G' . $headerRowNum;
    $headerStyle = [
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => [
                'rgb' => '007bff', // Bootstrap primary blue
            ],
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ];
    $sheet->getStyle($headerCellRange)->applyFromArray($headerStyle);
    // Set white background for the rest of the table
    $rowCount = count($numbered_students);
    $tableStartRow = $headerRowNum + 1;
    $tableEndRow = $tableStartRow + $rowCount - 1;
    $tableRange = 'A' . $tableStartRow . ':G' . $tableEndRow;
    $sheet->getStyle($tableRange)->applyFromArray([
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => [
                'rgb' => 'FFFFFF',
            ],
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ]);
    // Set borders for all cells
    $sheet->getStyle('A1:G' . $tableEndRow)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
            ],
        ],
    ]);
    // Set column widths for better appearance
    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    // Add standard row height for all rows (for Excel)
    for ($i = 1; $i <= $tableEndRow; $i++) {
        $sheet->getRowDimension($i)->setRowHeight(22);
    }
    $sheet->setShowGridlines(true);
    $spreadsheet->setActiveSheetIndex(0);
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $baseFilename . '.xlsx"');
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
} else if ($format === 'pdf') {
    require_once __DIR__ . '/phpspreadsheet/vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    // Insert PDF title rows
    $sheet->fromArray(['List of Membership'], NULL, 'A1');
    $sheet->mergeCells('A1:G1');
    $sheet->getStyle('A1:G1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 16,
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ]);
    $sheet->fromArray([$orgName], NULL, 'A2');
    $sheet->mergeCells('A2:G2');
    $sheet->getStyle('A2:G2')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 13,
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ]);
    $rowPointer = 3;
    if ($selected_section !== '' && $selected_section !== 'All') {
        $sheet->fromArray(['Section: ' . $selected_section], NULL, 'A' . $rowPointer);
        $sheet->mergeCells('A' . $rowPointer . ':G' . $rowPointer);
        $sheet->getStyle('A' . $rowPointer . ':G' . $rowPointer)->applyFromArray([
            'font' => [
                'italic' => true,
                'size' => 11,
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ],
        ]);
        $rowPointer++;
    }
    // Add a blank row for spacing
    $sheet->fromArray([''], NULL, 'A' . $rowPointer);
    $sheet->mergeCells('A' . $rowPointer . ':G' . $rowPointer);
    $rowPointer++;
    // Table header and data start at $rowPointer
    $headerRowNum = $rowPointer;
    $sheet->fromArray($headers, NULL, 'A' . $headerRowNum);
    $sheet->fromArray($numbered_students, NULL, 'A' . ($headerRowNum + 1));
    // Style the header row
    $headerCellRange = 'A' . $headerRowNum . ':G' . $headerRowNum;
    $headerStyle = [
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => [
                'rgb' => '007bff', // Bootstrap primary blue
            ],
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ];
    $sheet->getStyle($headerCellRange)->applyFromArray($headerStyle);
    // Set white background for the rest of the table
    $rowCount = count($numbered_students);
    $tableStartRow = $headerRowNum + 1;
    $tableEndRow = $tableStartRow + $rowCount - 1;
    $tableRange = 'A' . $tableStartRow . ':G' . $tableEndRow;
    $sheet->getStyle($tableRange)->applyFromArray([
        'fill' => [
            'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
            'startColor' => [
                'rgb' => 'FFFFFF',
            ],
        ],
        'alignment' => [
            'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
            'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
        ],
    ]);
    // Set borders for all cells (only for the table, not the title/org/section rows)
    $borderStartRow = 1;
    if ($format === 'pdf') {
        $borderStartRow = $headerRowNum; // Only table rows get borders
    }
    $sheet->getStyle('A' . $borderStartRow . ':G' . $tableEndRow)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
            ],
        ],
    ]);
    // Set column widths for better appearance
    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    // Add standard row height for all rows (for PDF)
    for ($i = 1; $i <= $tableEndRow; $i++) {
        $sheet->getRowDimension($i)->setRowHeight(22);
    }
    $sheet->setShowGridlines(true);
    $spreadsheet->setActiveSheetIndex(0);
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
        header('Content-Disposition: attachment; filename="' . $baseFilename . '.pdf"');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, $pdfWriterType);
        $writer->save('php://output');
        exit;
    } else {
        http_response_code(500);
        echo 'PDF export requires mPDF, Dompdf, or TCPDF to be installed.';
        exit;
    }
}

// If format not recognized
http_response_code(400);
echo 'Invalid export format.'; 