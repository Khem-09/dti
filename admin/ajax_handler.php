<?php
session_start(); 
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: ../login.php");
        exit();
}

require_once '../classes/database.php';

if (file_exists('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
}

error_reporting(E_ALL);
ini_set('display_errors', 0); 
ob_start(); 

try {
    $database = new Database();
    $conn = $database->getConnection();

    // Password Verification Only (For Secure Backup Auth)
    if (isset($_POST['action']) && $_POST['action'] === 'verify_password_only') {
        if (!isset($_SESSION['admin_id'])) {
            ob_end_clean(); echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit();
        }
        $admin_id = $_SESSION['admin_id'];
        $password = $_POST['password'] ?? '';

        $stmt = $conn->prepare("SELECT password FROM admin WHERE id = ?");
        $stmt->execute([$admin_id]);
        $hash = $stmt->fetchColumn();

        if (password_verify($password, $hash) || $password === $hash) {
            ob_end_clean(); echo json_encode(['status' => 'success']);
        } else {
            ob_end_clean(); echo json_encode(['status' => 'error', 'message' => 'Incorrect password']);
        }
        exit();
    }

    // Download Database Backup Logic
    if (isset($_GET['action']) && $_GET['action'] === 'download_backup') {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="DTI_Database_Backup_' . date('Ymd_His') . '.sql"');
        echo "-- DTI Price Monitoring Database Backup\n";
        echo "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        
        $tables = ['admin', 'brands', 'categories', 'commodity_types', 'monitoring_periods', 'provinces', 'stores', 'products', 'product_variants', 'price_records', 'uploaded_files', 'generated_reports', 'product_history'];
        
        foreach($tables as $table) {
            try {
                $rows = $conn->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
                if($rows && count($rows) > 0) {
                    echo "-- Table: $table\n";
                    foreach($rows as $row) {
                        $keys = array_keys($row);
                        $valStrs = array_map(function($v) { return $v === null ? "NULL" : "'" . addslashes((string)$v) . "'"; }, array_values($row));
                        echo "INSERT INTO `$table` (`" . implode("`,`", $keys) . "`) VALUES (" . implode(",", $valStrs) . ");\n";
                    }
                    echo "\n";
                }
            } catch(Exception $e) {}
        }
        exit();
    }

    header('Content-Type: application/json');

    if (isset($_POST['action']) && $_POST['action'] === 'update_admin_profile') {
        if (!isset($_SESSION['admin_id'])) {
            ob_end_clean(); echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit();
        }
        $admin_id = $_SESSION['admin_id'];
        $firstname = trim($_POST['firstname']);
        $lastname = trim($_POST['lastname']);
        $username = trim($_POST['username']);

        $stmt = $conn->prepare("UPDATE admin SET firstname = ?, lastname = ?, username = ? WHERE id = ?");
        if ($stmt->execute([$firstname, $lastname, $username, $admin_id])) {
            $_SESSION['username'] = $username;
            ob_end_clean(); echo json_encode(['status' => 'success']);
        } else {
            ob_end_clean(); echo json_encode(['status' => 'error', 'message' => 'Failed to update profile.']);
        }
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_admin_password') {
        if (!isset($_SESSION['admin_id'])) {
            ob_end_clean(); echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit();
        }
        $admin_id = $_SESSION['admin_id'];
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];

        $stmt = $conn->prepare("SELECT password FROM admin WHERE id = ?");
        $stmt->execute([$admin_id]);
        $hash = $stmt->fetchColumn();

        if (password_verify($current_password, $hash) || $current_password === $hash) {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE admin SET password = ? WHERE id = ?");
            $upd->execute([$new_hash, $admin_id]);
            ob_end_clean(); echo json_encode(['status' => 'success']);
        } else {
            ob_end_clean(); echo json_encode(['status' => 'error', 'message' => 'Current password is incorrect.']);
        }
        exit();
    }

    function detectProvinceId($filename, $fallback_id) {
        $name = strtolower(str_replace(['_', '-'], ' ', $filename));
        if (strpos($name, 'zamcity') !== false || strpos($name, 'zamboanga city') !== false || strpos($name, 'city') !== false || strpos($name, 'zc') !== false) return 1;
        if (strpos($name, 'zamsur') !== false || strpos($name, 'del sur') !== false || strpos($name, 'zds') !== false) return 2;
        if (strpos($name, 'zamnorte') !== false || strpos($name, 'del norte') !== false || strpos($name, 'zdn') !== false) return 3;
        if (strpos($name, 'sibugay') !== false || strpos($name, 'zamsib') !== false || strpos($name, 'zs' ) !== false || strpos($name, 'ipil') !== false) return 4;
        if (strpos($name, 'isabela') !== false || strpos($name, 'ic') !== false) return 5;
        return !empty($fallback_id) ? $fallback_id : 1; 
    }

    if (isset($_POST['action']) && $_POST['action'] === 'upload_file_only') {
        $target_dir = "../uploads/"; 
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

        if (!isset($_FILES['excel_file'])) throw new Exception("No file uploaded.");

        $original_filename = basename($_FILES["excel_file"]["name"]);
        $fallback_province = $_POST['province_id'] ?? 1;
        $target_year = !empty($_POST['target_year']) ? $_POST['target_year'] : null;
        $final_province_id = detectProvinceId($original_filename, $fallback_province);
        
        $clean_filename = time() . "_" . preg_replace("/[^a-zA-Z0-9.\-_]/", "", $original_filename);
        $target_file = $target_dir . $clean_filename;

        if (move_uploaded_file($_FILES["excel_file"]["tmp_name"], $target_file)) {
            // PHP NO LONGER PARSES THE EXCEL FILE HERE. IT IS INSTANT.
            $stmt = $conn->prepare("INSERT INTO uploaded_files (province_id, target_year, original_filename) VALUES (?, ?, ?)");
            $stmt->execute([$final_province_id, $target_year, $clean_filename]);
            
            ob_end_clean(); 
            echo json_encode([
                'status' => 'success', 
                'file_id' => $conn->lastInsertId(),
                'province_id' => $final_province_id,
                'target_year' => $target_year
            ]);
        } else {
            throw new Exception("Failed to save file physically on the server.");
        }
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'build_and_save_report') {
        set_time_limit(0);
        ini_set('memory_limit', '-1');
        
        $province_id = !empty($_POST['province_id']) ? $_POST['province_id'] : null;
        $year = $_POST['year'];
        
        require_once '../classes/admin.php';
        $adminClass = new Admin($conn);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $wsBN = $spreadsheet->getActiveSheet();
        $wsBN->setTitle('Basic Necessities');
        $wsPC = $spreadsheet->createSheet();
        $wsPC->setTitle('Prime Commodities');

        $bnRows = [];
        $pcRows = [];
        $filename = "";
        $rtype = "";
        $rname = "";

        if ($province_id) {
            $s = $conn->prepare("SELECT province_name FROM provinces WHERE id = ?");
            $s->execute([$province_id]);
            $provName = $s->fetchColumn() ?: "Region_IX";
            $safeName = preg_replace('/[^a-zA-Z0-9]/', '_', $provName);
            $filename = $safeName . "_Full_Report_" . $year . ".xlsx";
            $rtype = 'Provincial';
            $rname = $provName . " Price Report";

            $allData = $adminClass->getProvincialReport($province_id, $year, null, null, 'All');
            $headers = ["#", "Type", "Category", "Brand", "Product Name", "Specs", "Price Range"];
            $bnRows[] = $headers; $pcRows[] = $headers;
            $bnC = 1; $pcC = 1;

            foreach($allData as $row) {
                $pr = "NO DATA";
                if ($row['lowest_price'] !== null) {
                    $pr = ($row['lowest_price'] == $row['highest_price']) ? "PHP " . number_format($row['lowest_price'], 2) : "PHP " . number_format($row['lowest_price'], 2) . " - " . number_format($row['highest_price'], 2);
                }
                $r = ["", $row['type_code'], $row['category_name'], $row['brand_name'], $row['product_name'], $row['specifications'], $pr];
                if ($row['type_code'] == 'BN') { $r[0] = $bnC++; $bnRows[] = $r; } 
                else { $r[0] = $pcC++; $pcRows[] = $r; }
            }
        } else {
            $filename = "Regional_Summary_Report_" . $year . ".xlsx";
            $rtype = 'Regional';
            $rname = "Region IX Complete Summary";

            $allData = $adminClass->getRegionalReport($year, null, null, 'All');
            $headers = ["#", "Type", "Category", "Brand", "Product Name", "Specs", "Zamboanga City", "Zamboanga Sibugay", "Isabela City", "Zamboanga Del Sur", "Zamboanga Del Norte"];
            $bnRows[] = $headers; $pcRows[] = $headers;
            $bnC = 1; $pcC = 1;

            foreach($allData as $row) {
                $fmt = function($min, $max) {
                    if ($min === null) return "NO DATA";
                    return ($min == $max) ? "PHP " . number_format($min, 2) : "PHP " . number_format($min, 2) . " - " . number_format($max, 2);
                };

                $p1 = $fmt($row['p1_min'], $row['p1_max']);
                $p4 = $fmt($row['p4_min'], $row['p4_max']);
                $p5 = $fmt($row['p5_min'], $row['p5_max']);
                $p2 = $fmt($row['p2_min'], $row['p2_max']);
                $p3 = $fmt($row['p3_min'], $row['p3_max']);

                $r = ["", $row['type_code'], $row['category_name'], $row['brand_name'], $row['product_name'], $row['specifications'], $p1, $p4, $p5, $p2, $p3];
                if ($row['type_code'] == 'BN') { $r[0] = $bnC++; $bnRows[] = $r; } 
                else { $r[0] = $pcC++; $pcRows[] = $r; }
            }
        }

        $wsBN->fromArray($bnRows, NULL, 'A1');
        $wsPC->fromArray($pcRows, NULL, 'A1');

        $target_dir = "../uploads/reports/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $filepath = $target_dir . $filename;

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($filepath);

        $admin_id = null;
        if (isset($_SESSION['admin_id'])) $admin_id = $_SESSION['admin_id'];
        elseif (isset($_SESSION['id'])) $admin_id = $_SESSION['id'];
        elseif (isset($_SESSION['user_id'])) $admin_id = $_SESSION['user_id'];

        if (!$admin_id) {
            $admin_query = $conn->query("SELECT id FROM admin ORDER BY id ASC LIMIT 1");
            $admin_id = $admin_query->fetchColumn();
        }

        if (!$admin_id) {
            throw new Exception("CRITICAL ERROR: Your 'admin' table is completely empty! You must have at least one admin account in the database to generate reports.");
        }

        $check = $conn->prepare("SELECT id FROM generated_reports WHERE file_path = ?");
        $check->execute([$filename]);
        
        if ($check->rowCount() == 0) {
            $stmt = $conn->prepare("INSERT INTO generated_reports (admin_id, report_name, report_type, target_year, target_month, file_path, province_id, report_year, file_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$admin_id, $rname, $rtype, (int)$year, 'All', $filename, $province_id, (string)$year, $filename]);
        } else {
            $stmt = $conn->prepare("UPDATE generated_reports SET created_at = CURRENT_TIMESTAMP WHERE file_path = ?");
            $stmt->execute([$filename]);
        }

        ob_end_clean();
        echo json_encode(['status' => 'success']);
        exit();
    }

    $input = file_get_contents('php://input');
    $request = json_decode($input, true);

    if (isset($request['action']) && $request['action'] === 'save_chunk') {
        $file_id = $request['file_id'];
        $province_id = $request['province_id'];
        $srp_date_label = isset($request['srp_date_label']) ? $request['srp_date_label'] : null;
        $chunk = $request['data'];

        // --- UPDATE YEAR AND EXTRACTED SRP DATE ---
        if (count($chunk) > 0 && !empty($chunk[0]['year'])) {
            $actual_year = $chunk[0]['year'];
            if ($srp_date_label) {
                $stmtUpdate = $conn->prepare("UPDATE uploaded_files SET target_year = ?, srp_date_label = ? WHERE id = ?");
                $stmtUpdate->execute([$actual_year, $srp_date_label, $file_id]);
            } else {
                $stmtUpdate = $conn->prepare("UPDATE uploaded_files SET target_year = ? WHERE id = ?");
                $stmtUpdate->execute([$actual_year, $file_id]);
            }
        } elseif ($srp_date_label) {
            $stmtUpdate = $conn->prepare("UPDATE uploaded_files SET srp_date_label = ? WHERE id = ?");
            $stmtUpdate->execute([$srp_date_label, $file_id]);
        }

        $conn->beginTransaction();

        try {
            // MASSIVE SPEED OPTIMIZATION: THE "IN-MEMORY DICTIONARY" METHOD
            $stores = []; $periods = []; $types = []; $cats = []; $brands = []; $prods = []; $variants = [];

            // Load Existing Masterlists into Memory
            $stmt = $conn->prepare("SELECT id, store_name FROM stores WHERE province_id = ?");
            $stmt->execute([$province_id]);
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $stores[$r['store_name']] = $r['id'];

            $stmt = $conn->query("SELECT id, year, month, week_number, date_range_label FROM monitoring_periods");
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $periods[$r['year'].'~_~'.$r['month'].'~_~'.$r['week_number'].'~_~'.$r['date_range_label']] = $r['id'];

            $stmt = $conn->query("SELECT id, type_code FROM commodity_types");
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $types[$r['type_code']] = $r['id'];

            $stmt = $conn->query("SELECT id, category_name FROM categories");
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $cats[$r['category_name']] = $r['id'];

            $stmt = $conn->query("SELECT id, brand_name FROM brands");
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $brands[$r['brand_name']] = $r['id'];

            $stmt = $conn->query("SELECT id, type_id, category_id, brand_id, product_name FROM products");
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $prods[$r['type_id'].'~_~'.$r['category_id'].'~_~'.$r['brand_id'].'~_~'.$r['product_name']] = $r['id'];

            $stmt = $conn->query("SELECT id, product_id, specifications FROM product_variants");
            while($r = $stmt->fetch(PDO::FETCH_ASSOC)) $variants[$r['product_id'].'~_~'.$r['specifications']] = $r['id'];

            // Prepare single-insert statements for *new* items only
            $insStore = $conn->prepare("INSERT INTO stores (store_name, province_id) VALUES (?, ?)");
            $insPeriod = $conn->prepare("INSERT INTO monitoring_periods (year, month, week_number, date_range_label) VALUES (?, ?, ?, ?)");
            $insType = $conn->prepare("INSERT INTO commodity_types (type_code, type_name) VALUES (?, ?)");
            $insCat = $conn->prepare("INSERT INTO categories (category_name) VALUES (?)");
            $insBrand = $conn->prepare("INSERT INTO brands (brand_name) VALUES (?)");
            $insProd = $conn->prepare("INSERT INTO products (type_id, category_id, brand_id, product_name) VALUES (?,?,?,?)");
            $insVariant = $conn->prepare("INSERT INTO product_variants (product_id, specifications, srp) VALUES (?,?,?)");
            $updVariant = $conn->prepare("UPDATE product_variants SET srp = ? WHERE id = ?");
            $updPrice = $conn->prepare("UPDATE price_records SET actual_price = ?, file_id = ? WHERE id = ?");

            $mappedRecords = [];
            $periodIdsForChunk = [];

            // FIRST PASS: Map all text names to Database IDs via RAM. Instantly insert missing.
            foreach ($chunk as $row) {
                $store_name = substr(trim((string)$row['store']), 0, 145);
                $date_label = substr(trim((string)$row['date_label']), 0, 48);
                $type_code  = substr(trim((string)$row['type_code']), 0, 10);
                $type_name  = substr(trim((string)$row['type_name']), 0, 48);
                $cat_name   = substr(trim((string)$row['cat']), 0, 95);
                $brand_name = substr(trim((string)$row['brand']), 0, 95);
                $prod_name  = substr(trim((string)$row['prod']), 0, 145);
                $specs      = substr(trim((string)$row['specs']), 0, 95);
                $srp        = $row['srp'];
                $price      = $row['price'];

                if(!isset($stores[$store_name])) { $insStore->execute([$store_name, $province_id]); $stores[$store_name] = $conn->lastInsertId(); }
                $store_id = $stores[$store_name];

                $pK = $row['year'].'~_~'.$row['month'].'~_~'.$row['week'].'~_~'.$date_label;
                if(!isset($periods[$pK])) { $insPeriod->execute([$row['year'], $row['month'], $row['week'], $date_label]); $periods[$pK] = $conn->lastInsertId(); }
                $period_id = $periods[$pK];

                if(!isset($types[$type_code])) { $insType->execute([$type_code, $type_name]); $types[$type_code] = $conn->lastInsertId(); }
                $type_id = $types[$type_code];

                if(!isset($cats[$cat_name])) { $insCat->execute([$cat_name]); $cats[$cat_name] = $conn->lastInsertId(); }
                $cat_id = $cats[$cat_name];

                if(!isset($brands[$brand_name])) { $insBrand->execute([$brand_name]); $brands[$brand_name] = $conn->lastInsertId(); }
                $brand_id = $brands[$brand_name];

                $prK = $type_id.'~_~'.$cat_id.'~_~'.$brand_id.'~_~'.$prod_name;
                if(!isset($prods[$prK])) { $insProd->execute([$type_id, $cat_id, $brand_id, $prod_name]); $prods[$prK] = $conn->lastInsertId(); }
                $product_id = $prods[$prK];

                $vK = $product_id.'~_~'.$specs;
                if(!isset($variants[$vK])) { 
                    $insVariant->execute([$product_id, $specs, $srp]); $variants[$vK] = $conn->lastInsertId(); 
                } else {
                    if ($srp !== null) $updVariant->execute([$srp, $variants[$vK]]);
                }
                $variant_id = $variants[$vK];

                $mappedRecords[] = [
                    'store_id' => $store_id, 'period_id' => $period_id,
                    'variant_id' => $variant_id, 'price' => $price, 'file_id' => $file_id
                ];
                $periodIdsForChunk[$period_id] = $period_id;
            }

            // BULK PRICE INSERTION 
            $existingPrices = [];
            if (!empty($periodIdsForChunk)) {
                $in = str_repeat('?,', count($periodIdsForChunk) - 1) . '?';
                $stmt = $conn->prepare("SELECT variant_id, store_id, period_id, id FROM price_records WHERE period_id IN ($in)");
                $stmt->execute(array_values($periodIdsForChunk));
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $k = $r['variant_id'].'_'.$r['store_id'].'_'.$r['period_id'];
                    $existingPrices[$k] = $r['id'];
                }
            }

            $insertPlaceholders = [];
            $insertParams = [];

            // SECOND PASS: Sort into Updates vs Mass Inserts
            foreach ($mappedRecords as $rec) {
                $k = $rec['variant_id'].'_'.$rec['store_id'].'_'.$rec['period_id'];
                if (isset($existingPrices[$k])) {
                    if ($rec['price'] !== null) { 
                        $updPrice->execute([$rec['price'], $rec['file_id'], $existingPrices[$k]]); 
                    }
                } else {
                    $insertPlaceholders[] = "(?, ?, ?, ?, ?)";
                    array_push($insertParams, $rec['file_id'], $rec['variant_id'], $rec['store_id'], $rec['period_id'], $rec['price']);
                    $existingPrices[$k] = 'pending_insert'; 
                }
            }

            // Execute the Massive Single Query (LOWERED TO 100 TO PREVENT MAX PACKET CRASH)
            if (!empty($insertPlaceholders)) {
                $chunkSizeLimit = 100; // Adjusted for extreme safety
                $chunkedPlaceholders = array_chunk($insertPlaceholders, $chunkSizeLimit);
                $chunkedParams = array_chunk($insertParams, $chunkSizeLimit * 5); 
                
                foreach($chunkedPlaceholders as $index => $placeholders) {
                    $sql = "INSERT INTO price_records (file_id, variant_id, store_id, period_id, actual_price) VALUES " . implode(", ", $placeholders);
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($chunkedParams[$index]);
                }
            }

            $conn->commit();
            ob_end_clean(); echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $conn->rollBack();
            ob_end_clean(); echo json_encode(['status' => 'error', 'message' => "SQL Error: " . $e->getMessage()]);
        }
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'update_product') {
        $variant_id = $_POST['variant_id'];
        $product_id = $_POST['product_id'];
        $new_name = trim($_POST['product_name']);
        $new_specs = trim($_POST['specifications']);
        $new_srp = !empty($_POST['srp']) ? $_POST['srp'] : null;

        $stmt = $conn->prepare("SELECT p.product_name, pv.specifications, pv.srp FROM product_variants pv JOIN products p ON pv.product_id = p.id WHERE pv.id = ?");
        $stmt->execute([$variant_id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($old) {
            if ($old['product_name'] != $new_name || $old['specifications'] != $new_specs || $old['srp'] != $new_srp) {
                $conn->beginTransaction();
                try {
                    $histStmt = $conn->prepare("INSERT INTO product_history (variant_id, old_name, new_name, old_specs, new_specs, old_srp, new_srp) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $histStmt->execute([
                        $variant_id, 
                        $old['product_name'], $new_name, 
                        $old['specifications'], $new_specs, 
                        $old['srp'], $new_srp
                    ]);

                    $updProd = $conn->prepare("UPDATE products SET product_name = ? WHERE id = ?");
                    $updProd->execute([$new_name, $product_id]);

                    $updVar = $conn->prepare("UPDATE product_variants SET specifications = ?, srp = ? WHERE id = ?");
                    $updVar->execute([$new_specs, $new_srp, $variant_id]);
                    
                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollBack();
                    ob_end_clean(); echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit();
                }
            }
        }
        ob_end_clean();
        echo json_encode(['status' => 'success']);
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'add_product') {
        $type_code = $_POST['type_code'];
        $type_name = ($type_code === 'BN') ? 'Basic Necessity' : 'Prime Commodity';
        $category_name = trim($_POST['category_name']);
        $brand_name = trim($_POST['brand_name']);
        $product_name = trim($_POST['product_name']);
        $specifications = trim($_POST['specifications']);
        $srp = !empty($_POST['srp']) ? $_POST['srp'] : null;

        $conn->beginTransaction();

        try {
            $stmtType = $conn->prepare("SELECT id FROM commodity_types WHERE type_code = ? LIMIT 1");
            $stmtType->execute([$type_code]);
            $type_id = $stmtType->fetchColumn();
            if (!$type_id) {
                $insType = $conn->prepare("INSERT INTO commodity_types (type_code, type_name) VALUES (?, ?)");
                $insType->execute([$type_code, $type_name]);
                $type_id = $conn->lastInsertId();
            }

            $stmtCat = $conn->prepare("SELECT id FROM categories WHERE category_name = ? LIMIT 1");
            $stmtCat->execute([$category_name]);
            $cat_id = $stmtCat->fetchColumn();
            if (!$cat_id) {
                $insCat = $conn->prepare("INSERT INTO categories (category_name) VALUES (?)");
                $insCat->execute([$category_name]);
                $cat_id = $conn->lastInsertId();
            }

            $stmtBrand = $conn->prepare("SELECT id FROM brands WHERE brand_name = ? LIMIT 1");
            $stmtBrand->execute([$brand_name]);
            $brand_id = $stmtBrand->fetchColumn();
            if (!$brand_id) {
                $insBrand = $conn->prepare("INSERT INTO brands (brand_name) VALUES (?)");
                $insBrand->execute([$brand_name]);
                $brand_id = $conn->lastInsertId();
            }

            $stmtProd = $conn->prepare("SELECT id FROM products WHERE type_id = ? AND category_id = ? AND brand_id = ? AND product_name = ? LIMIT 1");
            $stmtProd->execute([$type_id, $cat_id, $brand_id, $product_name]);
            $product_id = $stmtProd->fetchColumn();
            if (!$product_id) {
                $insProd = $conn->prepare("INSERT INTO products (type_id, category_id, brand_id, product_name) VALUES (?, ?, ?, ?)");
                $insProd->execute([$type_id, $cat_id, $brand_id, $product_name]);
                $product_id = $conn->lastInsertId();
            }

            $stmtVar = $conn->prepare("SELECT id FROM product_variants WHERE product_id = ? AND specifications = ? LIMIT 1");
            $stmtVar->execute([$product_id, $specifications]);
            if ($stmtVar->fetchColumn()) {
                throw new Exception("This specific product and specification already exists in the masterlist!");
            }

            $insVar = $conn->prepare("INSERT INTO product_variants (product_id, specifications, srp) VALUES (?, ?, ?)");
            $insVar->execute([$product_id, $specifications, $srp]);
            $variant_id = $conn->lastInsertId(); 

            // --- INITIAL ADDITION LOG ---
            $histStmt = $conn->prepare("INSERT INTO product_history (variant_id, old_name, new_name, old_specs, new_specs, old_srp, new_srp) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $histStmt->execute([
                $variant_id, 
                '[New Entry]', $product_name, 
                '[New Entry]', $specifications, 
                null, $srp
            ]);

            $conn->commit();
            ob_end_clean();
            echo json_encode(['status' => 'success']);

        } catch (Exception $e) {
            $conn->rollBack();
            ob_end_clean();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'delete_product') {
        $variant_id = $_POST['variant_id'];
        
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM price_records WHERE variant_id = ?")->execute([$variant_id]);
            $conn->prepare("DELETE FROM product_history WHERE variant_id = ?")->execute([$variant_id]);
            $conn->prepare("DELETE FROM product_variants WHERE id = ?")->execute([$variant_id]);

            $conn->commit();
            ob_end_clean();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $conn->rollBack();
            ob_end_clean();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit();
    }

} catch (Exception $e) { ob_end_clean(); echo json_encode(['status' => 'error', 'message' => "Server Error: " . $e->getMessage()]); }
?>