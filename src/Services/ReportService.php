<?php

namespace App\Services;

use App\Core\Database;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;
use TCPDF;

class ReportService
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function getPartywiseReport(array $filters): array
    {
        $sql = "
            SELECT pt.name AS party_name,
                   o.order_no,
                   p.name AS product_name,
                   c.name AS company_name,
                   c.code AS company_code,
                   o.order_qty_trucks AS ordered_trucks,
                   COALESCE(d.total_dispatched, 0) AS dispatched_trucks,
                   (o.order_qty_trucks - COALESCE(d.total_dispatched, 0)) AS pending_trucks,
                   o.order_date,
                   o.status,
                   o.priority
            FROM orders o
            JOIN parties pt ON o.party_id = pt.id
            JOIN products p ON o.product_id = p.id
            JOIN companies c ON o.company_id = c.id
            LEFT JOIN (
                SELECT order_id, SUM(dispatch_qty_trucks) AS total_dispatched
                FROM dispatches
                GROUP BY order_id
            ) d ON o.id = d.order_id
            WHERE o.order_date BETWEEN ? AND ?
        ";
        
        $params = [$filters['start_date'], $filters['end_date']];
        
        if (!empty($filters['party_id'])) {
            $sql .= " AND pt.id = ?";
            $params[] = $filters['party_id'];
        }
        
        if (!empty($filters['product_id'])) {
            $sql .= " AND p.id = ?";
            $params[] = $filters['product_id'];
        }
        
        $sql .= " ORDER BY c.name, pt.name, o.order_date DESC";
        
        // Add pagination if specified
        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
            
            if (isset($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int)$filters['offset'];
            }
        }
        
        return $this->database->fetchAll($sql, $params);
    }
    
    public function getPartywiseReportCount(array $filters): int
    {
        $sql = "
            SELECT COUNT(*) as count
            FROM orders o
            JOIN parties pt ON o.party_id = pt.id
            JOIN products p ON o.product_id = p.id
            JOIN companies c ON o.company_id = c.id
            WHERE o.order_date BETWEEN ? AND ?
        ";
        
        $params = [$filters['start_date'], $filters['end_date']];
        
        if (!empty($filters['party_id'])) {
            $sql .= " AND pt.id = ?";
            $params[] = $filters['party_id'];
        }
        
        if (!empty($filters['product_id'])) {
            $sql .= " AND p.id = ?";
            $params[] = $filters['product_id'];
        }
        
        $result = $this->database->fetch($sql, $params);
        return (int)$result['count'];
    }
    
    public function generatePdfReport(array $data, array $filters): string
    {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Order Processing System');
        $pdf->SetAuthor('System Generated');
        $pdf->SetTitle('Party-wise Report');
        $pdf->SetSubject('Order and Dispatch Report');
        
        // Set default header data
        $pdf->SetHeaderData('', 0, 'Order Processing System', 'Party-wise Report');
        
        // Set header and footer fonts
        $pdf->setHeaderFont(['helvetica', '', 12]);
        $pdf->setFooterFont(['helvetica', '', 8]);
        
        // Set default monospaced font
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        
        // Set margins
        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        
        // Set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        
        // Add a page
        $pdf->AddPage();
        
        // Set font
        $pdf->SetFont('helvetica', '', 10);
        
        // Report header
        $html = '<h2 style="text-align: center;">Party-wise Order Report</h2>';
        $html .= '<p style="text-align: center;">Period: ' . $filters['start_date'] . ' to ' . $filters['end_date'] . '</p>';
        $html .= '<p style="text-align: center;">Generated on: ' . date('Y-m-d H:i:s') . '</p>';
        
        // We'll create separate tables for each company, so no main table header here
        
        $totalOrdered = 0;
        $totalDispatched = 0;
        $totalPending = 0;
        
        // Group data by company
        $groupedData = [];
        foreach ($data as $row) {
            $companyName = $row['company_name'];
            if (!isset($groupedData[$companyName])) {
                $groupedData[$companyName] = [];
            }
            $groupedData[$companyName][] = $row;
        }
        
        // Process each company group
        foreach ($groupedData as $companyName => $companyData) {
            $companyOrdered = 0;
            $companyDispatched = 0;
            $companyPending = 0;
            
            // Company header
            $html .= '<h3 style="margin-top: 20px; margin-bottom: 10px; color: #2c5aa0; border-bottom: 2px solid #2c5aa0; padding-bottom: 5px;">';
            $html .= htmlspecialchars($companyName) . ' (' . count($companyData) . ' orders)';
            $html .= '</h3>';
            
            // Company table with equal column spacing
            $html .= '<table style="width: 100%; margin-bottom: 20px; border-collapse: separate; border-spacing: 0; border: 2px solid #000; table-layout: fixed;">';
            
            // Define optimized column widths to accommodate longer headers
            $html .= '<colgroup>';
            $html .= '<col style="width: 16%;">';  // Party Name
            $html .= '<col style="width: 12%;">';  // Order No
            $html .= '<col style="width: 16%;">';  // Product Type
            $html .= '<col style="width: 10%;">';  // Priority
            $html .= '<col style="width: 15%;">';  // Ordered Trucks
            $html .= '<col style="width: 16%;">';  // Dispatched Trucks (wider for longer text)
            $html .= '<col style="width: 15%;">';  // Pending Trucks
            $html .= '</colgroup>';
            
            $html .= '<thead>';
            $html .= '<tr style="height: 55px;">';
            $html .= '<th style="background-color: #f0f0f0; text-align: center; padding: 12px 1px; border: 1px solid #000; font-weight: bold; font-size: 8px; white-space: nowrap; vertical-align: middle; overflow: hidden;">Party Name</th>';
            $html .= '<th style="background-color: #f0f0f0; text-align: center; padding: 12px 1px; border: 1px solid #000; font-weight: bold; font-size: 8px; white-space: nowrap; vertical-align: middle; overflow: hidden;">Order No.</th>';
            $html .= '<th style="background-color: #f0f0f0; text-align: center; padding: 12px 1px; border: 1px solid #000; font-weight: bold; font-size: 8px; white-space: nowrap; vertical-align: middle; overflow: hidden;">Product Type</th>';
            $html .= '<th style="background-color: #f0f0f0; text-align: center; padding: 12px 1px; border: 1px solid #000; font-weight: bold; font-size: 8px; white-space: nowrap; vertical-align: middle; overflow: hidden;">Priority</th>';
            $html .= '<th style="background-color: #f0f0f0; text-align: center; padding: 12px 1px; border: 1px solid #000; font-weight: bold; font-size: 8px; white-space: nowrap; vertical-align: middle; overflow: hidden;">Ordered Trucks</th>';
            $html .= '<th style="background-color: #f0f0f0; text-align: center; padding: 12px 1px; border: 1px solid #000; font-weight: bold; font-size: 8px; white-space: nowrap; vertical-align: middle; overflow: hidden;">Dispatched Trucks</th>';
            $html .= '<th style="background-color: #f0f0f0; text-align: center; padding: 12px 1px; border: 1px solid #000; font-weight: bold; font-size: 8px; white-space: nowrap; vertical-align: middle; overflow: hidden;">Pending Trucks</th>';
            $html .= '</tr>';
            $html .= '</thead>';
            $html .= '<tbody>';
            
            // Company data rows
            foreach ($companyData as $row) {
                // Truncate long text to fit optimized column widths
                $partyName = strlen($row['party_name']) > 14 ? substr($row['party_name'], 0, 14) . '...' : $row['party_name'];
                $productName = strlen($row['product_name']) > 12 ? substr($row['product_name'], 0, 12) . '...' : $row['product_name'];
                
                $priorityText = $row['priority'] === 'urgent' ? 'URGENT' : 'NORMAL';
                $priorityStyle = $row['priority'] === 'urgent' 
                    ? 'color: #dc3545; font-weight: bold; font-size: 8px;'
                    : 'color: #6c757d; font-weight: bold; font-size: 8px;';
                
                $html .= '<tr style="height: 40px;">';
                $html .= '<td style="padding: 10px 2px; text-align: center; vertical-align: middle; border: 1px solid #000; font-size: 8px; white-space: nowrap; overflow: hidden;">' . htmlspecialchars($partyName) . '</td>';
                $html .= '<td style="padding: 10px 2px; text-align: center; vertical-align: middle; border: 1px solid #000; font-size: 8px; font-weight: bold; white-space: nowrap; overflow: hidden;">' . htmlspecialchars($row['order_no']) . '</td>';
                $html .= '<td style="padding: 10px 2px; text-align: center; vertical-align: middle; border: 1px solid #000; font-size: 8px; white-space: nowrap; overflow: hidden;">' . htmlspecialchars($productName) . '</td>';
                $html .= '<td style="padding: 10px 2px; text-align: center; vertical-align: middle; border: 1px solid #000; ' . $priorityStyle . ' white-space: nowrap; overflow: hidden;">' . $priorityText . '</td>';
                $html .= '<td style="padding: 10px 2px; text-align: center; vertical-align: middle; border: 1px solid #000; font-size: 9px; font-weight: bold; white-space: nowrap; overflow: hidden;">' . $row['ordered_trucks'] . '</td>';
                $html .= '<td style="padding: 10px 2px; text-align: center; vertical-align: middle; border: 1px solid #000; font-size: 9px; font-weight: bold; white-space: nowrap; overflow: hidden;">' . $row['dispatched_trucks'] . '</td>';
                $html .= '<td style="padding: 10px 2px; text-align: center; vertical-align: middle; border: 1px solid #000; font-size: 9px; font-weight: bold; white-space: nowrap; overflow: hidden;">' . $row['pending_trucks'] . '</td>';
                $html .= '</tr>';
                
                $companyOrdered += $row['ordered_trucks'];
                $companyDispatched += $row['dispatched_trucks'];
                $companyPending += $row['pending_trucks'];
            }
            
            // Company subtotal row
            $html .= '<tr style="background-color: #e9ecef; height: 45px;">';
            $html .= '<td colspan="4" style="padding: 15px 3px; text-align: center; font-weight: bold; vertical-align: middle; border: 2px solid #000; font-size: 10px; white-space: nowrap;">Company Total:</td>';
            $html .= '<td style="padding: 15px 3px; text-align: center; font-weight: bold; color: #0d6efd; vertical-align: middle; border: 2px solid #000; font-size: 10px; white-space: nowrap;">' . $companyOrdered . '</td>';
            $html .= '<td style="padding: 15px 3px; text-align: center; font-weight: bold; color: #198754; vertical-align: middle; border: 2px solid #000; font-size: 10px; white-space: nowrap;">' . $companyDispatched . '</td>';
            $html .= '<td style="padding: 15px 3px; text-align: center; font-weight: bold; color: #fd7e14; vertical-align: middle; border: 2px solid #000; font-size: 10px; white-space: nowrap;">' . $companyPending . '</td>';
            $html .= '</tr>';
            
            $html .= '</tbody>';
            $html .= '</table>';
            
            $totalOrdered += $companyOrdered;
            $totalDispatched += $companyDispatched;
            $totalPending += $companyPending;
        }
        
        // Grand totals summary with fixed layout
        $html .= '<div style="margin-top: 25px; padding: 20px; background-color: #333333; color: white; text-align: center; border: 3px solid #000;">';
        $html .= '<h3 style="color: white; margin-bottom: 20px; font-weight: bold; font-size: 18px;">GRAND TOTAL SUMMARY</h3>';
        $html .= '<table style="width: 100%; color: white; border-collapse: separate; border-spacing: 0; table-layout: fixed;">';
        $html .= '<colgroup>';
        $html .= '<col style="width: 33.33%;">';
        $html .= '<col style="width: 33.33%;">';
        $html .= '<col style="width: 33.34%;">';
        $html .= '</colgroup>';
        $html .= '<tr>';
        $html .= '<td style="text-align: center; font-size: 14px; font-weight: bold; border: 2px solid white; padding: 20px 10px; height: 60px; vertical-align: middle;">Total Ordered<br><span style="font-size: 22px; font-weight: bold;">' . $totalOrdered . '</span></td>';
        $html .= '<td style="text-align: center; font-size: 14px; font-weight: bold; border: 2px solid white; padding: 20px 10px; height: 60px; vertical-align: middle;">Total Dispatched<br><span style="font-size: 22px; font-weight: bold;">' . $totalDispatched . '</span></td>';
        $html .= '<td style="text-align: center; font-size: 14px; font-weight: bold; border: 2px solid white; padding: 20px 10px; height: 60px; vertical-align: middle;">Total Pending<br><span style="font-size: 22px; font-weight: bold;">' . $totalPending . '</span></td>';
        $html .= '</tr>';
        $html .= '</table>';
        $html .= '</div>';
        
        $pdf->writeHTML($html, true, false, true, false, '');
        
        return $pdf->Output('', 'S');
    }
    
    public function generateExcelReport(array $data, array $filters): Xlsx
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set document properties
        $spreadsheet->getProperties()
            ->setCreator('Order Processing System')
            ->setTitle('Party-wise Report')
            ->setSubject('Order and Dispatch Report')
            ->setDescription('Party-wise order and dispatch report');
        
        // Report header
        $sheet->setCellValue('A1', 'Order Processing System');
        $sheet->setCellValue('A2', 'Party-wise Order Report');
        $sheet->setCellValue('A3', 'Period: ' . $filters['start_date'] . ' to ' . $filters['end_date']);
        $sheet->setCellValue('A4', 'Generated on: ' . date('Y-m-d H:i:s'));
        
        // Style header
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3:A4')->getFont()->setSize(10);
        
        // We'll create separate sections for each company, so start with row 6
        $currentRow = 6;
        
        $totalOrdered = 0;
        $totalDispatched = 0;
        $totalPending = 0;
        
        // Group data by company
        $groupedData = [];
        foreach ($data as $rowData) {
            $companyName = $rowData['company_name'];
            if (!isset($groupedData[$companyName])) {
                $groupedData[$companyName] = [];
            }
            $groupedData[$companyName][] = $rowData;
        }
        
        // Process each company group
        foreach ($groupedData as $companyName => $companyData) {
            $companyOrdered = 0;
            $companyDispatched = 0;
            $companyPending = 0;
            
            // Company header
            $sheet->setCellValue('A' . $currentRow, $companyName . ' (' . count($companyData) . ' orders)');
            $sheet->mergeCells('A' . $currentRow . ':G' . $currentRow);
            $sheet->getRowDimension($currentRow)->setRowHeight(30);
            $sheet->getStyle('A' . $currentRow)->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A' . $currentRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('2C5AA0');
            $sheet->getStyle('A' . $currentRow)->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $currentRow++;
            
            // Table headers for this company
            $headers = ['Party Name', 'Order No.', 'Product Type', 'Priority', 'Ordered Trucks', 'Dispatched Trucks', 'Pending Trucks'];
            $col = 'A';
            $sheet->getRowDimension($currentRow)->setRowHeight(30);
            
            foreach ($headers as $header) {
                $sheet->setCellValue($col . $currentRow, $header);
                $sheet->getStyle($col . $currentRow)->getFont()->setBold(true);
                $sheet->getStyle($col . $currentRow)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('E0E0E0');
                $sheet->getStyle($col . $currentRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $col++;
            }
            $currentRow++;
            
            // Company data rows
            foreach ($companyData as $rowData) {
                $priorityText = strtoupper($rowData['priority']);
                
                $sheet->getRowDimension($currentRow)->setRowHeight(25);
                
                $sheet->setCellValue('A' . $currentRow, $rowData['party_name']);
                $sheet->setCellValue('B' . $currentRow, $rowData['order_no']);
                $sheet->setCellValue('C' . $currentRow, $rowData['product_name']);
                $sheet->setCellValue('D' . $currentRow, $priorityText);
                $sheet->setCellValue('E' . $currentRow, $rowData['ordered_trucks']);
                $sheet->setCellValue('F' . $currentRow, $rowData['dispatched_trucks']);
                $sheet->setCellValue('G' . $currentRow, $rowData['pending_trucks']);
                
                // Apply center alignment to all cells in this row
                $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
                
                // Style order number as bold
                $sheet->getStyle('B' . $currentRow)->getFont()->setBold(true);
                
                // Style numeric columns as bold
                $sheet->getStyle('E' . $currentRow)->getFont()->setBold(true);
                $sheet->getStyle('F' . $currentRow)->getFont()->setBold(true);
                $sheet->getStyle('G' . $currentRow)->getFont()->setBold(true);
                
                // Style urgent priority in red in priority column
                if ($rowData['priority'] === 'urgent') {
                    $sheet->getStyle('D' . $currentRow)->getFont()->getColor()->setRGB('ED1D25');
                }
                
                $companyOrdered += $rowData['ordered_trucks'];
                $companyDispatched += $rowData['dispatched_trucks'];
                $companyPending += $rowData['pending_trucks'];
                
                $currentRow++;
            }
            
            // Company subtotal row
            $sheet->getRowDimension($currentRow)->setRowHeight(30);
            $sheet->setCellValue('A' . $currentRow, 'Company Total:');
            $sheet->mergeCells('A' . $currentRow . ':D' . $currentRow);
            $sheet->setCellValue('E' . $currentRow, $companyOrdered);
            $sheet->setCellValue('F' . $currentRow, $companyDispatched);
            $sheet->setCellValue('G' . $currentRow, $companyPending);
            
            // Style subtotal row
            $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->getFont()->setBold(true);
            $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E9ECEF');
            $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
            
            // Color code the totals
            $sheet->getStyle('E' . $currentRow)->getFont()->getColor()->setRGB('0D6EFD'); // Blue for ordered
            $sheet->getStyle('F' . $currentRow)->getFont()->getColor()->setRGB('198754'); // Green for dispatched  
            $sheet->getStyle('G' . $currentRow)->getFont()->getColor()->setRGB('FD7E14'); // Orange for pending
            
            $totalOrdered += $companyOrdered;
            $totalDispatched += $companyDispatched;
            $totalPending += $companyPending;
            
            $currentRow += 2; // Add space between companies
        }
        
        // Grand totals row
        $sheet->getRowDimension($currentRow)->setRowHeight(35);
        $sheet->setCellValue('A' . $currentRow, 'GRAND TOTAL');
        $sheet->mergeCells('A' . $currentRow . ':D' . $currentRow);
        $sheet->setCellValue('E' . $currentRow, $totalOrdered);
        $sheet->setCellValue('F' . $currentRow, $totalDispatched);
        $sheet->setCellValue('G' . $currentRow, $totalPending);
        
        // Style grand totals row
        $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setRGB('333333');
        $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A' . $currentRow . ':G' . $currentRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Set column widths to match web/PDF proportions exactly
        $sheet->getColumnDimension('A')->setWidth(25); // Party Name - 20%
        $sheet->getColumnDimension('B')->setWidth(18); // Order No - 14%
        $sheet->getColumnDimension('C')->setWidth(23); // Product Type - 18%
        $sheet->getColumnDimension('D')->setWidth(12); // Priority - 10%
        $sheet->getColumnDimension('E')->setWidth(16); // Ordered Trucks - 13%
        $sheet->getColumnDimension('F')->setWidth(16); // Dispatched Trucks - 13%
        $sheet->getColumnDimension('G')->setWidth(15); // Pending Trucks - 12%
        
        // All columns are already center-aligned above, no additional alignment needed
        
        $writer = new Xlsx($spreadsheet);
        return $writer;
    }
    
    public function getActiveParties(): array
    {
        $sql = "SELECT id, name FROM parties WHERE is_active = 1 ORDER BY name";
        return $this->database->fetchAll($sql);
    }
    
    public function getActiveProducts(): array
    {
        $sql = "SELECT id, name FROM products WHERE is_active = 1 ORDER BY name";
        return $this->database->fetchAll($sql);
    }
}

