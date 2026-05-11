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
                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
                    $reader->setReadDataOnly(true);
                    
                    $spreadsheet = $reader->load($filePath);
                    $sheetNames = $spreadsheet->getSheetNames();
                    $current_sheet = (!$target_sheet || !in_array($target_sheet, $sheetNames)) ? ($sheetNames[0] ?? null) : $target_sheet;

                    if ($current_sheet) {
                        $worksheet = $spreadsheet->getSheetByName($current_sheet);
                        if ($worksheet) $data = $worksheet->toArray();
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
        ini_set('memory_limit', '1024M'); 

        $stmt = $this->conn->prepare("SELECT file_path FROM generated_reports WHERE id = ?");
        $stmt->execute([$report_id]);
        $filename = $stmt->fetchColumn();

        if (!$filename) return ['error' => 'Report not found.'];
        $filePath = "../uploads/reports/" . $filename;
        if (!file_exists($filePath)) return ['error' => 'Report file missing from server.'];

        $data = []; $sheetNames = []; $current_sheet = null;
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
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
                    if ($worksheet) $data = $worksheet->toArray();
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

        if (!empty($month)) { 
            $period_condition .= " AND mp.month = ?"; 
            $sub_params[] = $month; 
        }
        if (!empty($period_id)) { 
            $period_condition .= " AND mp.id = ?"; 
            $sub_params[] = $period_id; 
        }

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

        if (!empty($type) && $type != 'All') {
            $sql .= " AND ct.type_code = ?";
            $main_params[] = $type;
        }

        $sql .= " GROUP BY pv.id ORDER BY ct.type_code, c.category_name, b.brand_name, p.product_name, pv.specifications";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($main_params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getGeneratedReports($type = 'All', $year = 'All') {
        $sql = "SELECT gr.*, p.province_name 
                FROM generated_reports gr 
                LEFT JOIN provinces p ON gr.province_id = p.id 
                WHERE 1=1";
        $params = [];

        if (!empty($year) && $year != 'All') {
            $sql .= " AND gr.target_year = ?";
            $params[] = $year;
        }

        if ($type == 'Provincial') {
            $sql .= " AND gr.report_type = 'Provincial'";
        } elseif ($type == 'Regional') {
            $sql .= " AND gr.report_type = 'Regional'";
        }

        $sql .= " ORDER BY gr.created_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRegionalReport($year, $month = null, $period_id = null, $type = null) {
        $sub_params = [$year];
        $period_condition = "mp.year = ?";

        if (!empty($month)) { 
            $period_condition .= " AND mp.month = ?"; 
            $sub_params[] = $month; 
        }
        if (!empty($period_id)) { 
            $period_condition .= " AND mp.id = ?"; 
            $sub_params[] = $period_id; 
        }

        $sql = "SELECT ct.type_code, c.category_name, b.brand_name, p.product_name, pv.specifications, 
                       MIN(CASE WHEN pr_filtered.province_id = 1 THEN pr_filtered.actual_price END) as p1_min,
                       MAX(CASE WHEN pr_filtered.province_id = 1 THEN pr_filtered.actual_price END) as p1_max,
                       MIN(CASE WHEN pr_filtered.province_id = 4 THEN pr_filtered.actual_price END) as p4_min,
                       MAX(CASE WHEN pr_filtered.province_id = 4 THEN pr_filtered.actual_price END) as p4_max,
                       MIN(CASE WHEN pr_filtered.province_id = 5 THEN pr_filtered.actual_price END) as p5_min,
                       MAX(CASE WHEN pr_filtered.province_id = 5 THEN pr_filtered.actual_price END) as p5_max,
                       MIN(CASE WHEN pr_filtered.province_id = 2 THEN pr_filtered.actual_price END) as p2_min,
                       MAX(CASE WHEN pr_filtered.province_id = 2 THEN pr_filtered.actual_price END) as p2_max,
                       MIN(CASE WHEN pr_filtered.province_id = 3 THEN pr_filtered.actual_price END) as p3_min,
                       MAX(CASE WHEN pr_filtered.province_id = 3 THEN pr_filtered.actual_price END) as p3_max
                FROM products p
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

        if (!empty($type) && $type != 'All') {
            $sql .= " AND ct.type_code = ?";
            $main_params[] = $type;
        }

        $sql .= " GROUP BY pv.id ORDER BY ct.type_code, c.category_name, b.brand_name, p.product_name, pv.specifications";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($main_params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllProducts() {
        $sql = "SELECT pv.id as variant_id, p.id as product_id, 
                       ct.type_code, c.category_name, b.brand_name, 
                       p.product_name, pv.specifications, pv.srp
                FROM product_variants pv
                JOIN products p ON pv.product_id = p.id
                JOIN commodity_types ct ON p.type_id = ct.id
                JOIN categories c ON p.category_id = c.id
                JOIN brands b ON p.brand_id = b.id
                ORDER BY ct.type_code, c.category_name, b.brand_name, p.product_name";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProductHistory() {
        $this->conn->exec("CREATE TABLE IF NOT EXISTS product_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            variant_id INT,
            old_name VARCHAR(255),
            new_name VARCHAR(255),
            old_specs VARCHAR(255),
            new_specs VARCHAR(255),
            old_srp DECIMAL(10,2),
            new_srp DECIMAL(10,2),
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        $sql = "SELECT h.*, b.brand_name, c.category_name, ct.type_code
                FROM product_history h
                JOIN product_variants pv ON h.variant_id = pv.id
                JOIN products p ON pv.product_id = p.id
                JOIN brands b ON p.brand_id = b.id
                JOIN categories c ON p.category_id = c.id
                JOIN commodity_types ct ON p.type_id = ct.id
                ORDER BY h.changed_at DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllProductVariants() {
        $sql = "SELECT pv.id as variant_id, b.brand_name, p.product_name, pv.specifications, ct.type_code 
                FROM product_variants pv
                JOIN products p ON pv.product_id = p.id
                JOIN brands b ON p.brand_id = b.id
                JOIN commodity_types ct ON p.type_id = ct.id
                ORDER BY p.product_name ASC, b.brand_name ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // UPDATED: Now groups ALL stores that match the absolute lowest or highest price
    public function getMarketExtremes($variant_id, $year, $month = null, $province_id = 'All') {
        $params = [$variant_id, $year];
        $cond = "WHERE pr.variant_id = ? AND mp.year = ? AND pr.actual_price > 0 AND UPPER(st.store_name) NOT LIKE '%PRICE FREEZE%'";
        
        if (!empty($month)) {
            $cond .= " AND mp.month = ?";
            $params[] = $month;
        }
        if ($province_id != 'All') {
            $cond .= " AND st.province_id = ?";
            $params[] = $province_id;
        }

        // Step 1: Find the absolute minimum and maximum values first
        $agg_sql = "SELECT MIN(pr.actual_price) as min_price, MAX(pr.actual_price) as max_price
                    FROM price_records pr
                    JOIN stores st ON pr.store_id = st.id
                    JOIN monitoring_periods mp ON pr.period_id = mp.id
                    $cond";
        $stmtAgg = $this->conn->prepare($agg_sql);
        $stmtAgg->execute($params);
        $agg_data = $stmtAgg->fetch(PDO::FETCH_ASSOC);

        $min_data = false;
        $max_data = false;

        if ($agg_data && $agg_data['min_price'] !== null) {
            $min_price = $agg_data['min_price'];
            $max_price = $agg_data['max_price'];

            // Step 2: Fetch ALL DISTINCT stores that have this exact minimum price
            $min_sql = "SELECT DISTINCT st.store_name
                        FROM price_records pr
                        JOIN stores st ON pr.store_id = st.id
                        JOIN monitoring_periods mp ON pr.period_id = mp.id
                        $cond AND pr.actual_price = ?";
            $minParams = $params;
            $minParams[] = $min_price;
            $stmtMin = $this->conn->prepare($min_sql);
            $stmtMin->execute($minParams);
            $min_stores = $stmtMin->fetchAll(PDO::FETCH_COLUMN);

            $min_data = [
                'actual_price' => $min_price,
                'store_name' => implode(", ", $min_stores)
            ];

            // Step 3: Fetch ALL DISTINCT stores that have this exact maximum price
            $max_sql = "SELECT DISTINCT st.store_name
                        FROM price_records pr
                        JOIN stores st ON pr.store_id = st.id
                        JOIN monitoring_periods mp ON pr.period_id = mp.id
                        $cond AND pr.actual_price = ?";
            $maxParams = $params;
            $maxParams[] = $max_price;
            $stmtMax = $this->conn->prepare($max_sql);
            $stmtMax->execute($maxParams);
            $max_stores = $stmtMax->fetchAll(PDO::FETCH_COLUMN);

            $max_data = [
                'actual_price' => $max_price,
                'store_name' => implode(", ", $max_stores)
            ];
        }

        return [
            'lowest' => $min_data,
            'highest' => $max_data
        ];
    }

    public function getTrendData($variant_id, $year, $month = null, $province_id = 'All') {
        $sub_params = [$variant_id];
        
        // UPDATED: Filters out prices <= 0 and ignores "PRICE FREEZE" from affecting graph minimums
        $store_cond = " AND pr.actual_price > 0 AND UPPER(st.store_name) NOT LIKE '%PRICE FREEZE%' ";
        
        if ($province_id != 'All') {
            $store_cond .= " AND st.province_id = ? ";
            $sub_params[] = $province_id;
        }

        if (empty($month)) {
            // Yearly View - Force 12 month alignment, safely joining prices
            $sql = "SELECT mp.month as period_label, '' as date_range_label,
                           MIN(pr_filtered.actual_price) as min_price, MAX(pr_filtered.actual_price) as max_price
                    FROM monitoring_periods mp
                    LEFT JOIN (
                        SELECT pr.period_id, pr.actual_price
                        FROM price_records pr
                        JOIN stores st ON pr.store_id = st.id
                        WHERE pr.variant_id = ? $store_cond
                    ) pr_filtered ON mp.id = pr_filtered.period_id
                    WHERE mp.year = ?
                    GROUP BY mp.month
                    ORDER BY FIELD(mp.month, 'January','February','March','April','May','June','July','August','September','October','November','December')";
            
            $params = array_merge($sub_params, [$year]);
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Structure to guarantee all 12 months appear on the chart axis
            $structured_data = [];
            $all_months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            
            foreach ($all_months as $m) {
                $found = null;
                foreach ($raw_data as $row) {
                    if ($row['period_label'] == $m) {
                        $found = $row;
                        break;
                    }
                }
                if ($found) {
                    $structured_data[] = $found;
                } else {
                    $structured_data[] = [
                        'period_label' => $m,
                        'date_range_label' => '',
                        'min_price' => null,
                        'max_price' => null
                    ];
                }
            }
            return $structured_data;

        } else {
            // Monthly View - Week by week breakdown
            $sql = "SELECT CONCAT('Week ', mp.week_number) as period_label, MAX(mp.date_range_label) as date_range_label,
                           MIN(pr_filtered.actual_price) as min_price, MAX(pr_filtered.actual_price) as max_price
                    FROM monitoring_periods mp
                    LEFT JOIN (
                        SELECT pr.period_id, pr.actual_price
                        FROM price_records pr
                        JOIN stores st ON pr.store_id = st.id
                        WHERE pr.variant_id = ? $store_cond
                    ) pr_filtered ON mp.id = pr_filtered.period_id
                    WHERE mp.year = ? AND mp.month = ?
                    GROUP BY mp.week_number
                    ORDER BY mp.week_number ASC";
            
            $params = array_merge($sub_params, [$year, $month]);
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    public function getDashboardStats() {
        $stats = [
            'total_products' => 0,
            'total_stores' => 0,
            'total_reports' => 0,
            'total_prices' => 0
        ];

        try {
            $stats['total_products'] = $this->conn->query("SELECT COUNT(*) FROM products")->fetchColumn();
            $stats['total_stores'] = $this->conn->query("SELECT COUNT(*) FROM stores")->fetchColumn();
            
            $this->conn->exec("CREATE TABLE IF NOT EXISTS generated_reports (id INT AUTO_INCREMENT PRIMARY KEY)");
            $stats['total_reports'] = $this->conn->query("SELECT COUNT(*) FROM generated_reports")->fetchColumn();
            
            $stats['total_prices'] = $this->conn->query("SELECT COUNT(*) FROM price_records")->fetchColumn();
        } catch (Exception $e) {
        }

        return $stats;
    }
}
?>