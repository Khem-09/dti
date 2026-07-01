<?php
if (file_exists('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
}

class Admin {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getProvinces() {
        $stmt = $this->conn->prepare("SELECT * FROM provinces ORDER BY id ASC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // UPGRADED: Add Province now accepts aliases
    public function addProvince($province_name, $aliases = null) {
        $check = $this->conn->prepare("SELECT id FROM provinces WHERE LOWER(province_name) = ?");
        $check->execute([strtolower(trim($province_name))]);
        if ($check->fetchColumn()) {
            throw new Exception("This province already exists in the system.");
        }
        $stmt = $this->conn->prepare("INSERT INTO provinces (province_name, aliases) VALUES (?, ?)");
        return $stmt->execute([trim($province_name), trim($aliases)]);
    }

    // NEW: Update Province
    public function updateProvince($id, $province_name, $aliases) {
        $stmt = $this->conn->prepare("UPDATE provinces SET province_name = ?, aliases = ? WHERE id = ?");
        return $stmt->execute([trim($province_name), trim($aliases), $id]);
    }

    // NEW: Delete Province
    public function deleteProvince($id) {
        try {
            $stmt = $this->conn->prepare("DELETE FROM provinces WHERE id = ?");
            $stmt->execute([$id]);
        } catch (Exception $e) {
            throw new Exception("Cannot delete this province. It is currently linked to existing stores or uploaded files.");
        }
    }

    public function getAvailableYears() {
        $stmt = $this->conn->prepare("SELECT DISTINCT year FROM monitoring_periods WHERE EXISTS (SELECT 1 FROM price_records WHERE period_id = monitoring_periods.id) ORDER BY year DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAvailableMonths($year) {
        $stmt = $this->conn->prepare("SELECT DISTINCT month FROM monitoring_periods WHERE year = ? AND EXISTS (SELECT 1 FROM price_records WHERE period_id = monitoring_periods.id) ORDER BY FIELD(month, 'January','February','March','April','May','June','July','August','September','October','November','December')");
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAvailableWeeks($year, $month) {
        $stmt = $this->conn->prepare("SELECT DISTINCT id, date_range_label FROM monitoring_periods WHERE year = ? AND month = ? AND EXISTS (SELECT 1 FROM price_records WHERE period_id = monitoring_periods.id) ORDER BY week_number ASC");
        $stmt->execute([$year, $month]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUploadedFiles($province_id = null, $year = null) {
        $sql = "SELECT u.*, p.province_name FROM uploaded_files u JOIN provinces p ON u.province_id = p.id WHERE 1=1";
        $params = [];
        if (!empty($province_id)) { $sql .= " AND u.province_id = ?"; $params[] = $province_id; }
        if (!empty($year) && $year != 'All') { $sql .= " AND u.target_year = ?"; $params[] = $year; }
        $sql .= " ORDER BY u.uploaded_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

   public function getExcelPreview($file_id, $target_sheet = null) {
        set_time_limit(0);
        ini_set('memory_limit', '1024M'); 

        $stmt = $this->conn->prepare("SELECT original_filename FROM uploaded_files WHERE id = ?");
        $stmt->execute([$file_id]);
        $filename = $stmt->fetchColumn();

        if (!$filename) return ['error' => 'File not found.'];
        $filePath = "../uploads/" . $filename;
        if (!file_exists($filePath)) return ['error' => 'File missing from uploads folder.'];

        $data = []; $sheetNames = []; $current_sheet = null;
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if ($ext == 'csv') {
            $sheetNames = ['CSV Data']; $current_sheet = 'CSV Data';
            if (($handle = fopen($filePath, "r")) !== FALSE) {
                while (($line = fgetcsv($handle, 10000, ",")) !== FALSE) {
                    $data[] = $line; 
                }
                fclose($handle);
            }
        } else {
            if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                try {
                    $inputFileType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($filePath);
                    /** @var \PhpOffice\PhpSpreadsheet\Reader\IReader $reader */
                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
                    $reader->setReadDataOnly(false); 
                    
                    $spreadsheet = $reader->load($filePath);
                    $sheetNames = $spreadsheet->getSheetNames();
                    
                    $current_sheet = (!$target_sheet || !in_array($target_sheet, $sheetNames)) ? ($sheetNames[0] ?? null) : $target_sheet;

                    if ($current_sheet) {
                        $worksheet = $spreadsheet->getSheetByName($current_sheet);
                        if ($worksheet) {
                            $highestRow = min($worksheet->getHighestRow(), 500); 
                            $highestCol = $worksheet->getHighestColumn();
                            $highestColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

                            for ($row = 1; $row <= $highestRow; $row++) {
                                $rowData = [];
                                for ($col = 1; $col <= $highestColIdx; $col++) {
                                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                                    $cell = $worksheet->getCell($colLetter . $row);
                                    try {
                                        $val = $cell->getCalculatedValue();
                                    } catch (Exception $calcError) {
                                        $val = $cell->getValue();
                                    }
                                    $rowData[] = $val;
                                }
                                $data[] = $rowData;
                            }
                        }
                    }
                    $spreadsheet->disconnectWorksheets(); unset($spreadsheet);
                } catch (Exception $e) { return ['error' => 'Error: ' . $e->getMessage()]; }
            }
        }
        $cleanData = [];
        foreach ($data as $row) { if (array_filter($row)) $cleanData[] = $row; }
        return ['data' => $cleanData, 'sheets' => $sheetNames, 'current_sheet' => $current_sheet];
    }

    public function getReportPreview($report_id, $target_sheet = null) {
        set_time_limit(0);
        ini_set('memory_limit', '1024M'); 
        $stmt = $this->conn->prepare("SELECT file_path FROM generated_reports WHERE id = ?");
        $stmt->execute([$report_id]);
        $filename = $stmt->fetchColumn();
        if (!$filename) return ['error' => 'Report not found.'];
        $filePath = "../uploads/reports/" . $filename;
        if (!file_exists($filePath)) return ['error' => 'Report file missing from server.'];
        $data = []; $sheetNames = []; $current_sheet = null;
        if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            try {
                $inputFileType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($filePath);
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($filePath);
                $sheetNames = $spreadsheet->getSheetNames();
                $current_sheet = (!$target_sheet || !in_array($target_sheet, $sheetNames)) ? ($sheetNames[0] ?? null) : $target_sheet;
                if ($current_sheet) {
                    $worksheet = $spreadsheet->getSheetByName($current_sheet);
                    if ($worksheet) {
                        $highestRow = min($worksheet->getHighestRow(), 500); 
                        $highestCol = $worksheet->getHighestColumn();
                        $highestColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);
                        for ($row = 1; $row <= $highestRow; $row++) {
                            $rowData = [];
                            for ($col = 1; $col <= $highestColIdx; $col++) {
                                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                                $rowData[] = $worksheet->getCell($colLetter . $row)->getValue();
                            }
                            $data[] = $rowData;
                        }
                    }
                }
                $spreadsheet->disconnectWorksheets(); unset($spreadsheet);
            } catch (Exception $e) { return ['error' => 'Error: ' . $e->getMessage()]; }
        }
        $cleanData = [];
        foreach ($data as $row) { if (array_filter($row)) $cleanData[] = $row; }
        return ['data' => $cleanData, 'sheets' => $sheetNames, 'current_sheet' => $current_sheet];
    }

    public function getProvincialReport($province_id, $year, $month = null, $period_id = null, $type = null) {
        $sub_params = [$province_id, $year];
        $period_condition = "s.province_id = ? AND mp.year = ?";
        if (!empty($month)) { $period_condition .= " AND mp.month = ?"; $sub_params[] = $month; }
        if (!empty($period_id)) { $period_condition .= " AND mp.id = ?"; $sub_params[] = $period_id; }
        $sql = "SELECT ct.type_code, c.category_name, b.brand_name, p.product_name, pv.specifications, 
                       MIN(pr_filtered.actual_price) as lowest_price, MAX(pr_filtered.actual_price) as highest_price
                FROM products p
                JOIN product_variants pv ON p.id = pv.product_id
                JOIN commodity_types ct ON p.type_id = ct.id
                JOIN categories c ON p.category_id = c.id
                JOIN brands b ON p.brand_id = b.id
                LEFT JOIN (
                    SELECT pr.variant_id, pr.actual_price
                    FROM price_records pr
                    JOIN stores s ON pr.store_id = s.id
                    JOIN monitoring_periods mp ON pr.period_id = mp.id
                    WHERE $period_condition AND pr.actual_price > 0
                ) pr_filtered ON pv.id = pr_filtered.variant_id
                WHERE 1=1 ";
        $main_params = $sub_params;
        if (!empty($type) && $type != 'All') { $sql .= " AND ct.type_code = ?"; $main_params[] = $type; }
        $sql .= " GROUP BY pv.id ORDER BY ct.type_code, c.category_name, b.brand_name, p.product_name, pv.specifications";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($main_params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getGeneratedReports($type = 'All', $year = 'All') {
        $sql = "SELECT gr.*, p.province_name FROM generated_reports gr LEFT JOIN provinces p ON gr.province_id = p.id WHERE 1=1";
        $params = [];
        if (!empty($year) && $year != 'All') { $sql .= " AND gr.target_year = ?"; $params[] = $year; }
        if ($type == 'Provincial') { $sql .= " AND gr.report_type = 'Provincial'"; } 
        elseif ($type == 'Regional') { $sql .= " AND gr.report_type = 'Regional'"; }
        $sql .= " ORDER BY gr.created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRegionalReport($year, $month = null, $period_id = null, $type = null) {
        $provStmt = $this->conn->query("SELECT id, province_name FROM provinces ORDER BY id ASC");
        $allProvinces = $provStmt->fetchAll(PDO::FETCH_ASSOC);

        $sub_params = [$year];
        $period_condition = "mp.year = ?";
        if (!empty($month)) { $period_condition .= " AND mp.month = ?"; $sub_params[] = $month; }
        if (!empty($period_id)) { $period_condition .= " AND mp.id = ?"; $sub_params[] = $period_id; }
        
        $sql = "SELECT ct.type_code, c.category_name, b.brand_name, p.product_name, pv.specifications ";
        
        foreach ($allProvinces as $prov) {
            $pid = $prov['id'];
            $sql .= ", MIN(CASE WHEN pr_filtered.province_id = {$pid} THEN pr_filtered.actual_price END) as p{$pid}_min ";
            $sql .= ", MAX(CASE WHEN pr_filtered.province_id = {$pid} THEN pr_filtered.actual_price END) as p{$pid}_max ";
        }
        
        $sql .= " FROM products p
                JOIN product_variants pv ON p.id = pv.product_id
                JOIN commodity_types ct ON p.type_id = ct.id
                JOIN categories c ON p.category_id = c.id
                JOIN brands b ON p.brand_id = b.id
                LEFT JOIN (
                    SELECT pr.variant_id, pr.actual_price, st.province_id
                    FROM price_records pr
                    JOIN stores st ON pr.store_id = st.id
                    JOIN monitoring_periods mp ON pr.period_id = mp.id
                    WHERE $period_condition AND pr.actual_price > 0
                ) pr_filtered ON pv.id = pr_filtered.variant_id
                WHERE 1=1 ";
                
        $main_params = $sub_params;
        if (!empty($type) && $type != 'All') { $sql .= " AND ct.type_code = ?"; $main_params[] = $type; }
        $sql .= " GROUP BY pv.id ORDER BY ct.type_code, c.category_name, b.brand_name, p.product_name, pv.specifications";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($main_params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'provinces' => $allProvinces,
            'data' => $data
        ];
    }

    public function getAllProducts() {
        $sql = "SELECT pv.id as variant_id, p.id as product_id, ct.type_code, c.category_name, b.brand_name, p.product_name, pv.specifications, pv.srp
                FROM product_variants pv JOIN products p ON pv.product_id = p.id JOIN commodity_types ct ON p.type_id = ct.id JOIN categories c ON p.category_id = c.id JOIN brands b ON p.brand_id = b.id
                ORDER BY ct.type_code, c.category_name, b.brand_name, p.product_name";
        $stmt = $this->conn->prepare($sql); $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProductHistory() {
        $sql = "SELECT h.*, b.brand_name, c.category_name, ct.type_code FROM product_history h JOIN product_variants pv ON h.variant_id = pv.id JOIN products p ON pv.product_id = p.id JOIN brands b ON p.brand_id = b.id JOIN categories c ON p.category_id = c.id JOIN commodity_types ct ON p.type_id = ct.id ORDER BY h.changed_at DESC";
        $stmt = $this->conn->prepare($sql); $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllProductVariants() {
        $sql = "SELECT pv.id as variant_id, b.brand_name, p.product_name, pv.specifications, ct.type_code FROM product_variants pv JOIN products p ON pv.product_id = p.id JOIN brands b ON p.brand_id = b.id JOIN commodity_types ct ON p.type_id = ct.id ORDER BY p.product_name ASC, b.brand_name ASC";
        $stmt = $this->conn->prepare($sql); $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMarketExtremes($variant_id, $year, $month = null, $province_id = 'All') {
        $params = [$variant_id, $year];
        $cond = "WHERE pr.variant_id = ? AND mp.year = ? AND pr.actual_price > 0 AND UPPER(st.store_name) NOT LIKE '%PRICE FREEZE%'";
        if (!empty($month)) { $cond .= " AND mp.month = ?"; $params[] = $month; }
        if ($province_id != 'All') { $cond .= " AND st.province_id = ?"; $params[] = $province_id; }
        
        $agg_sql = "SELECT MIN(pr.actual_price) as min_price, MAX(pr.actual_price) as max_price FROM price_records pr JOIN stores st ON pr.store_id = st.id JOIN monitoring_periods mp ON pr.period_id = mp.id $cond";
        $stmtAgg = $this->conn->prepare($agg_sql); $stmtAgg->execute($params);
        $agg_data = $stmtAgg->fetch(PDO::FETCH_ASSOC);
        
        $min_data = false; $max_data = false; $itemizedData = [];

        if ($agg_data && $agg_data['min_price'] !== null) {
            $min_price = $agg_data['min_price']; $max_price = $agg_data['max_price'];
            
            $min_sql = "SELECT DISTINCT st.store_name FROM price_records pr JOIN stores st ON pr.store_id = st.id JOIN monitoring_periods mp ON pr.period_id = mp.id $cond AND pr.actual_price = ?";
            $mP = $params; $mP[] = $min_price; $sMin = $this->conn->prepare($min_sql); $sMin->execute($mP);
            $min_data = ['actual_price' => $min_price, 'store_name' => implode(", ", $sMin->fetchAll(PDO::FETCH_COLUMN))];
            
            $max_sql = "SELECT DISTINCT st.store_name FROM price_records pr JOIN stores st ON pr.store_id = st.id JOIN monitoring_periods mp ON pr.period_id = mp.id $cond AND pr.actual_price = ?";
            $xP = $params; $xP[] = $max_price; $sMax = $this->conn->prepare($max_sql); $sMax->execute($xP);
            $max_data = ['actual_price' => $max_price, 'store_name' => implode(", ", $sMax->fetchAll(PDO::FETCH_COLUMN))];

            $itemized_sql = "
                SELECT st.store_name, pr.actual_price, prov.province_name 
                FROM price_records pr 
                JOIN stores st ON pr.store_id = st.id 
                JOIN provinces prov ON st.province_id = prov.id
                JOIN monitoring_periods mp ON pr.period_id = mp.id 
                $cond
                ORDER BY prov.province_name ASC, pr.actual_price ASC";
            
            $stmtItemized = $this->conn->prepare($itemized_sql);
            $stmtItemized->execute($params);
            $raw_items = $stmtItemized->fetchAll(PDO::FETCH_ASSOC);

            foreach($raw_items as $item) {
                $pName = $item['province_name'];
                if(!isset($itemizedData[$pName])) {
                    $itemizedData[$pName] = [];
                }
                $exists = false;
                foreach($itemizedData[$pName] as $existingStore) {
                    if($existingStore['store'] === $item['store_name']) {
                        $exists = true; break;
                    }
                }
                if(!$exists) {
                    $itemizedData[$pName][] = ['store' => $item['store_name'], 'price' => $item['actual_price']];
                }
            }
        }
        return ['lowest' => $min_data, 'highest' => $max_data, 'itemized' => $itemizedData];
    }

    public function getTrendData($variant_id, $year, $month = null, $province_id = 'All') {
        $sub_params = [$variant_id];
        $store_cond = " AND pr.actual_price > 0 AND UPPER(st.store_name) NOT LIKE '%PRICE FREEZE%' ";
        if ($province_id != 'All') { $store_cond .= " AND st.province_id = ? "; $sub_params[] = $province_id; }
        if (empty($month)) {
            $sql = "SELECT mp.month as period_label, MIN(pr_f.actual_price) as min_price, MAX(pr_f.actual_price) as max_price FROM monitoring_periods mp LEFT JOIN (SELECT pr.period_id, pr.actual_price FROM price_records pr JOIN stores st ON pr.store_id = st.id WHERE pr.variant_id = ? $store_cond) pr_f ON mp.id = pr_f.period_id WHERE mp.year = ? GROUP BY mp.month ORDER BY FIELD(mp.month, 'January','February','March','April','May','June','July','August','September','October','November','December')";
            $params = array_merge($sub_params, [$year]); $stmt = $this->conn->prepare($sql); $stmt->execute($params); $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $all_m = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            $structured = [];
            foreach ($all_m as $m) {
                $found = null;
                foreach ($raw_data as $row) { if ($row['period_label'] == $m) { $found = $row; break; } }
                $structured[] = $found ? $found : ['period_label' => $m, 'min_price' => null, 'max_price' => null];
            }
            return $structured;
        } else {
            $sql = "SELECT CONCAT('Week ', mp.week_number) as period_label, MIN(pr_f.actual_price) as min_price, MAX(pr_f.actual_price) as max_price FROM monitoring_periods mp LEFT JOIN (SELECT pr.period_id, pr.actual_price FROM price_records pr JOIN stores st ON pr.store_id = st.id WHERE pr.variant_id = ? $store_cond) pr_f ON mp.id = pr_f.period_id WHERE mp.year = ? AND mp.month = ? GROUP BY mp.week_number ORDER BY mp.week_number ASC";
            $params = array_merge($sub_params, [$year, $month]); $stmt = $this->conn->prepare($sql); $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    public function getDashboardStats() {
        $stats = ['total_products' => 0, 'total_stores' => 0, 'total_reports' => 0, 'total_prices' => 0];
        try {
            $stats['total_products'] = $this->conn->query("SELECT COUNT(*) FROM products")->fetchColumn();
            $stats['total_stores'] = $this->conn->query("SELECT COUNT(*) FROM stores")->fetchColumn();
            $stats['total_reports'] = $this->conn->query("SELECT COUNT(*) FROM generated_reports")->fetchColumn();
            $stats['total_prices'] = $this->conn->query("SELECT COUNT(*) FROM price_records")->fetchColumn();
        } catch (Exception $e) {}
        return $stats;
    }

    public function getSRPComplianceReport($province_id, $year, $month = null, $period_id = null) {
        $params = [$province_id, $year];
        $period_cond = "st.province_id = ? AND mp.year = ?";
        if (!empty($month)) { $period_cond .= " AND mp.month = ?"; $params[] = $month; }
        if (!empty($period_id)) { $period_cond .= " AND mp.id = ?"; $params[] = $period_id; }

        $sql = "SELECT pv.id as variant_id, b.brand_name, p.product_name, pv.specifications, pv.srp, 
                       st.store_name, pr.actual_price, ct.type_code, c.category_name,
                       mp.date_range_label, mp.month, mp.year, mp.week_number
                FROM price_records pr 
                JOIN product_variants pv ON pr.variant_id = pv.id 
                JOIN products p ON pv.product_id = p.id 
                JOIN brands b ON p.brand_id = b.id 
                JOIN stores st ON pr.store_id = st.id 
                JOIN monitoring_periods mp ON pr.period_id = mp.id 
                JOIN commodity_types ct ON p.type_id = ct.id
                JOIN categories c ON p.category_id = c.id
                WHERE $period_cond AND pr.actual_price > 0 
                ORDER BY b.brand_name, p.product_name, mp.month, mp.week_number";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($results as $row) {
            $vid = $row['variant_id'];
            if (!isset($grouped[$vid])) {
                $grouped[$vid] = [
                    'brand_name' => $row['brand_name'], 
                    'product_name' => $row['product_name'], 
                    'specifications' => $row['specifications'], 
                    'srp' => $row['srp'],
                    'type_code' => $row['type_code'],
                    'category_name' => $row['category_name'],
                    'above_stores' => [], 
                    'below_stores' => [],
                    'no_srp_stores' => [] 
                ];
            }
            
            $dateStr = !empty($row['date_range_label']) ? $row['date_range_label'] : "W{$row['week_number']} {$row['month']}";
            $storeData = ['store' => $row['store_name'], 'price' => $row['actual_price'], 'date' => $dateStr];
            
            $srp = $row['srp'];
            if (empty($srp) || $srp <= 0) {
                $grouped[$vid]['no_srp_stores'][] = $storeData; 
            } else if ($row['actual_price'] > $srp) { 
                $grouped[$vid]['above_stores'][] = $storeData; 
            } else { 
                $grouped[$vid]['below_stores'][] = $storeData; 
            }
        }
        return array_values($grouped);
    }
}
?>