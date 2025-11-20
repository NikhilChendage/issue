<?php
/* Auto-location + Image/Video uploader + Admin Panel + Excel Reports
   Database: social_issues_db
   Tables: issues, admins
*/
// Railway MySQL Connection
session_start();
$host = "yamanote.proxy.rlwy.net";
$port = "56152";
$db   = "railway";
$user = "root";
$pass = "LjQTQMZpgUTpMgGvLnLWvKLcwrCZmqjW";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(Exception $e) {
    die("DB Error: " . $e->getMessage());
}


function is_admin(){ return isset($_SESSION['admin']); }

// Function to get exact location name from coordinates using OpenStreetMap Nominatim
function getExactLocationName($lat, $lon) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lon&addressdetails=1&zoom=18";
    
    $options = [
        'http' => [
            'header' => "User-Agent: CommunityIssueReporter/1.0\r\n"
        ]
    ];
    
    $context = stream_context_create($options);
    
    try {
        $response = file_get_contents($url, false, $context);
        $data = json_decode($response, true);
        
        if($data && isset($data['address'])) {
            $addr = $data['address'];
            
            // Try to get the most specific location name in order of preference
            if(isset($addr['neighbourhood']) && !empty($addr['neighbourhood'])) {
                return $addr['neighbourhood'];
            }
            if(isset($addr['suburb']) && !empty($addr['suburb'])) {
                return $addr['suburb'];
            }
            if(isset($addr['village']) && !empty($addr['village'])) {
                return $addr['village'];
            }
            if(isset($addr['town']) && !empty($addr['town'])) {
                return $addr['town'];
            }
            if(isset($addr['city']) && !empty($addr['city'])) {
                return $addr['city'];
            }
            if(isset($addr['county']) && !empty($addr['county'])) {
                return $addr['county'];
            }
            if(isset($addr['state']) && !empty($addr['state'])) {
                return $addr['state'];
            }
            
            // Fallback to display_name - extract first meaningful part
            if(isset($data['display_name'])) {
                $parts = explode(',', $data['display_name']);
                // Return the first non-empty, meaningful part
                foreach($parts as $part) {
                    $part = trim($part);
                    if(!empty($part) && !is_numeric($part)) {
                        return $part;
                    }
                }
            }
        }
        
        // Final fallback
        return "Location at $lat, $lon";
        
    } catch(Exception $e) {
        return "Location at $lat, $lon";
    }
}

// Function to generate Excel report
function generateExcelReport($issues, $start_date = null, $end_date = null) {
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="issues_report_'.date('Y-m-d').'.xls"');
    header('Cache-Control: max-age=0');
    
    // Create Excel content
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>ID</th>";
    echo "<th>Problem Name</th>";
    echo "<th>Location</th>";
    echo "<th>Description</th>";
    echo "<th>Priority</th>";
    echo "<th>Status</th>";
    echo "<th>Reported Date</th>";
    echo "</tr>";
    
    foreach($issues as $issue) {
        // Extract location name from stored format
        $location_text = $issue['location'];
        $location_display = $location_text;
        
        if(strpos($location_text, 'Coordinates:') !== false && strpos($location_text, 'Location:') !== false) {
            $lines = explode("\n", $location_text);
            $location_display = str_replace('Location: ', '', $lines[1]);
        }
        
        echo "<tr>";
        echo "<td>" . $issue['id'] . "</td>";
        echo "<td>" . htmlspecialchars($issue['problem_name']) . "</td>";
        echo "<td>" . htmlspecialchars($location_display) . "</td>";
        echo "<td>" . htmlspecialchars($issue['description']) . "</td>";
        echo "<td>" . htmlspecialchars($issue['priority']) . "</td>";
        echo "<td>" . htmlspecialchars($issue['status']) . "</td>";
        echo "<td>" . date('Y-m-d H:i', strtotime($issue['created_at'])) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    exit;
}

$msg = "";
$current_panel = $_GET['panel'] ?? 'dashboard';

// Date filter variables
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';
$priority_filter = $_GET['priority_filter'] ?? '';

/* ========== ADMIN LOGIN ========== */
if(isset($_POST['login'])){
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';

    $st = $pdo->prepare("SELECT * FROM admins WHERE username=?");
    $st->execute([$u]);
    $a = $st->fetch(PDO::FETCH_ASSOC);

    if($a && $p == $a['password']){
        $_SESSION['admin'] = $u;
        $msg = "Login successful.";
        $current_panel = 'admin';
    } else {
        $msg = "Invalid admin username or password.";
    }
}

/* ========== LOGOUT ========== */
if(isset($_GET['logout'])){
    session_destroy();
    header("Location: index.php");
    exit;
}

/* ========== SUBMIT ISSUE (PUBLIC) ========== */
if(isset($_POST['submit_issue'])){
    $pname    = trim($_POST['problem_name'] ?? '');
    $loc      = trim($_POST['location'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'Medium';

    // Check if location contains both coordinates and location name (from our JavaScript)
    if(strpos($loc, '||') !== false) {
        $parts = explode('||', $loc);
        $coordinates = trim($parts[0]);
        $location_name = trim($parts[1]);
        
        // Store both in readable format
        $loc = "Coordinates: $coordinates\nLocation: $location_name";
    }
    // Check if it's just coordinates and convert to location name
    elseif(preg_match('/^-?\d+\.?\d*,\s*-?\d+\.?\d*$/', $loc)) {
        list($lat, $lon) = explode(',', $loc);
        $lat = trim($lat);
        $lon = trim($lon);
        $location_name = getExactLocationName($lat, $lon);
        
        // Store both coordinates and location name
        $loc = "Coordinates: $lat, $lon\nLocation: $location_name";
    }

    if($pname=="" || $loc=="" || $desc==""){
        $msg = "Please fill all required fields.";
    } else {
        $uploadDir = "uploads/";
        if(!is_dir($uploadDir)) mkdir($uploadDir,0777,true);

        $img = null;
        $vid = null;

        // Multiple file support
        if(!empty($_FILES['media']['name'][0])){
            foreach($_FILES['media']['name'] as $i=>$fn){
                if($_FILES['media']['error'][$i] === UPLOAD_ERR_OK){
                    $ext = strtolower(pathinfo($fn,PATHINFO_EXTENSION));
                    $new = time().rand(1000,9999).".".$ext;
                    $dest = $uploadDir.$new;

                    if(move_uploaded_file($_FILES['media']['tmp_name'][$i],$dest)){
                        if(!$img && in_array($ext,['jpg','jpeg','png','gif','webp'])){
                            $img = $dest;
                        }
                        if(!$vid && in_array($ext,['mp4','mkv','mov','avi','webm','3gp'])){
                            $vid = $dest;
                        }
                    }
                }
            }
        }

        $st = $pdo->prepare("INSERT INTO issues(problem_name,location,description,priority,image_path,video_path)
                             VALUES(?,?,?,?,?,?)");
        $st->execute([$pname,$loc,$desc,$priority,$img,$vid]);

        $msg = "Your issue has been submitted successfully.";
        $current_panel = 'user';
    }
}

/* ========== UPDATE STATUS (ADMIN) ========== */
if(isset($_POST['update_status']) && is_admin()){
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? 'Pending';

    $st = $pdo->prepare("UPDATE issues SET status=? WHERE id=?");
    $st->execute([$status,$id]);

    $msg = "Status updated for Issue #$id.";
    $current_panel = 'admin';
}

/* ========== DELETE ISSUE (ADMIN) ========== */
if(isset($_POST['delete_issue']) && is_admin()){
    $id = (int)($_POST['id'] ?? 0);

    $st = $pdo->prepare("SELECT image_path, video_path FROM issues WHERE id=?");
    $st->execute([$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);

    if($f){
        if($f['image_path'] && file_exists($f['image_path'])) unlink($f['image_path']);
        if($f['video_path'] && file_exists($f['video_path'])) unlink($f['video_path']);
    }

    $pdo->prepare("DELETE FROM issues WHERE id=?")->execute([$id]);

    $msg = "Issue #$id deleted.";
    $current_panel = 'admin';
}

/* ========== GENERATE EXCEL REPORT ========== */
if(isset($_GET['generate_report']) && is_admin()){
    // Build query with filters
    $query = "SELECT * FROM issues WHERE 1=1";
    $params = [];
    
    if(!empty($start_date)) {
        $query .= " AND DATE(created_at) >= ?";
        $params[] = $start_date;
    }
    
    if(!empty($end_date)) {
        $query .= " AND DATE(created_at) <= ?";
        $params[] = $end_date;
    }
    
    if(!empty($status_filter) && $status_filter !== 'all') {
        $query .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    if(!empty($priority_filter) && $priority_filter !== 'all') {
        $query .= " AND priority = ?";
        $params[] = $priority_filter;
    }
    
    $query .= " ORDER BY created_at DESC";
    
    $st = $pdo->prepare($query);
    $st->execute($params);
    $filtered_issues = $st->fetchAll(PDO::FETCH_ASSOC);
    
    generateExcelReport($filtered_issues, $start_date, $end_date);
}

/* ========== FETCH ALL ISSUES ========== */
$issues = [];
try{
    // Build query with filters
    $query = "SELECT * FROM issues WHERE 1=1";
    $params = [];
    
    if(!empty($start_date)) {
        $query .= " AND DATE(created_at) >= ?";
        $params[] = $start_date;
    }
    
    if(!empty($end_date)) {
        $query .= " AND DATE(created_at) <= ?";
        $params[] = $end_date;
    }
    
    if(!empty($status_filter) && $status_filter !== 'all') {
        $query .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    if(!empty($priority_filter) && $priority_filter !== 'all') {
        $query .= " AND priority = ?";
        $params[] = $priority_filter;
    }
    
    $query .= " ORDER BY created_at DESC";
    
    $st = $pdo->prepare($query);
    $st->execute($params);
    $issues = $st->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){
    // table might not exist yet; ignore to avoid crash
}

// Get stats for dashboard
$total_issues = count($issues);
$pending_issues = 0;
$resolved_issues = 0;
$critical_issues = 0;

foreach($issues as $issue) {
    if($issue['status'] === 'Pending') $pending_issues++;
    if($issue['status'] === 'Completed') $resolved_issues++;
    if($issue['priority'] === 'Critical') $critical_issues++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Community Issue Reporter - <?php echo ucfirst($current_panel); ?> Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --accent: #f72585;
            --success: #4cc9f0;
            --warning: #f8961e;
            --danger: #e63946;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --gradient-primary: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            --gradient-secondary: linear-gradient(135deg, #7209b7 0%, #560bad 100%);
            --gradient-success: linear-gradient(135deg, #4cc9f0 0%, #3a86ff 100%);
            --gradient-user: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-admin: linear-gradient(135deg, #8360c3 0%, #2ebf91 100%);
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 15px 40px rgba(0, 0, 0, 0.12);
            --border-radius: 16px;
            --border-radius-sm: 10px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: <?php 
                if($current_panel === 'dashboard') echo 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'; 
                elseif($current_panel === 'user') echo 'var(--gradient-user)';
                else echo 'var(--gradient-admin)';
            ?>;
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Navigation */
        .nav-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
        }

        .nav-brand i {
            margin-right: 10px;
            font-size: 1.8rem;
        }

        .nav-links {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .nav-link {
            padding: 10px 20px;
            border-radius: var(--border-radius-sm);
            text-decoration: none;
            color: var(--dark);
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link.active {
            background: var(--gradient-primary);
            color: white;
        }

        .nav-link:hover:not(.active) {
            background: rgba(67, 97, 238, 0.1);
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 40px;
            padding: 40px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            color: var(--dark);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 100" preserveAspectRatio="none"><path d="M0,0 L1000,0 L1000,100 L0,100 Z" fill="rgba(67, 97, 238, 0.05)"></path></svg>');
            background-size: cover;
        }

        .header h1 {
            font-size: 3rem;
            margin-bottom: 15px;
            font-weight: 700;
            position: relative;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header p {
            font-size: 1.2rem;
            color: var(--gray);
            position: relative;
            max-width: 600px;
            margin: 0 auto;
        }

        .header i {
            margin-right: 15px;
            font-size: 2.8rem;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 30px;
            margin-bottom: 30px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--gradient-primary);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--gray-light);
        }

        .card-header i {
            font-size: 1.8rem;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-right: 15px;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background-color: rgba(67, 97, 238, 0.1);
        }

        .card-header h2 {
            color: var(--dark);
            font-size: 1.6rem;
            font-weight: 600;
        }

        /* Forms */
        .form-group {
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--dark);
        }

        .required::after {
            content: " *";
            color: var(--danger);
        }

        input, textarea, select {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            font-family: inherit;
            background: var(--light);
        }

        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
            background: white;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 25px;
            border: none;
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            color: white;
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-primary {
            background: var(--gradient-primary);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(67, 97, 238, 0.4);
        }

        .btn-secondary {
            background: var(--gradient-primary);
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(67, 97, 238, 0.4);
        }

        .btn-danger {
            background: var(--gradient-secondary);
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(114, 9, 183, 0.4);
        }

        .btn-success {
            background: var(--success);
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(76, 201, 240, 0.4);
        }

        .btn-excel {
            background: linear-gradient(135deg, #217346 0%, #1e6e3e 100%);
        }

        .btn-excel:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(33, 115, 70, 0.4);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.9rem;
        }

        .btn-block {
            width: 100%;
        }

        /* Location Input Group */
        .location-input-group {
            display: flex;
            gap: 10px;
            align-items: stretch;
        }

        .location-input-group input {
            flex: 1;
            margin-bottom: 0;
        }

        .location-input-group .btn {
            white-space: nowrap;
            min-width: 120px;
        }

        /* Messages */
        .message {
            padding: 15px 20px;
            border-radius: var(--border-radius-sm);
            margin-bottom: 25px;
            font-weight: 500;
            display: flex;
            align-items: center;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .message::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 5px;
        }

        .message-success {
            background: rgba(76, 201, 240, 0.1);
            color: #0a6c7e;
            border-left: 5px solid var(--success);
        }

        .message-error {
            background: rgba(230, 57, 70, 0.1);
            color: #a31521;
            border-left: 5px solid var(--danger);
        }

        .message-info {
            background: rgba(67, 97, 238, 0.1);
            color: #2c3e9c;
            border-left: 5px solid var(--primary);
        }

        .message i {
            margin-right: 15px;
            font-size: 1.2rem;
        }

        /* Location Preview */
        .location-preview {
            background: var(--light);
            border: 2px solid var(--gray-light);
            border-radius: var(--border-radius-sm);
            padding: 15px;
            margin-top: 10px;
            display: none;
        }

        .location-preview.active {
            display: block;
        }

        .location-coordinates {
            font-family: monospace;
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .location-name {
            font-weight: 600;
            color: var(--primary);
            font-size: 1.1rem;
            margin-bottom: 8px;
        }

        .location-confidence {
            font-size: 0.85rem;
            color: var(--success);
            font-weight: 600;
        }

        .location-note {
            font-size: 0.85rem;
            color: var(--gray);
            font-style: italic;
            margin-top: 8px;
        }

        /* Dashboard Specific */
        .navigation-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .nav-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 30px;
            text-align: center;
            transition: var(--transition);
            text-decoration: none;
            color: inherit;
            display: block;
            position: relative;
            overflow: hidden;
        }

        .nav-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
        }

        .nav-card.user::before {
            background: var(--gradient-primary);
        }

        .nav-card.admin::before {
            background: var(--gradient-secondary);
        }

        .nav-card.view::before {
            background: var(--gradient-success);
        }

        .nav-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-hover);
        }

        .nav-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            color: white;
        }

        .nav-card.user .nav-icon {
            background: var(--gradient-primary);
        }

        .nav-card.admin .nav-icon {
            background: var(--gradient-secondary);
        }

        .nav-card.view .nav-icon {
            background: var(--gradient-success);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 25px;
            display: flex;
            align-items: center;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .stat-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            font-size: 1.8rem;
            color: white;
        }

        .stat-total .stat-icon { background: var(--gradient-primary); }
        .stat-pending .stat-icon { background: var(--gradient-secondary); }
        .stat-resolved .stat-icon { background: var(--gradient-success); }
        .stat-critical .stat-icon { background: linear-gradient(135deg, #e63946 0%, #f72585 100%); }

        .stat-info h3 {
            font-size: 2rem;
            margin-bottom: 5px;
            color: var(--dark);
        }

        .stat-info p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            background: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th {
            background: var(--gradient-primary);
            color: white;
            padding: 18px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 1rem;
        }

        th:first-child {
            border-top-left-radius: var(--border-radius);
        }

        th:last-child {
            border-top-right-radius: var(--border-radius);
        }

        td {
            padding: 18px 20px;
            border-bottom: 1px solid var(--gray-light);
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover {
            background: rgba(67, 97, 238, 0.03);
        }

        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
            min-width: 120px;
        }

        .status-pending {
            background: rgba(248, 150, 30, 0.15);
            color: #b56a07;
            border: 1px solid rgba(248, 150, 30, 0.3);
        }

        .status-in-progress {
            background: rgba(67, 97, 238, 0.15);
            color: #2c3e9c;
            border: 1px solid rgba(67, 97, 238, 0.3);
        }

        .status-completed {
            background: rgba(76, 201, 240, 0.15);
            color: #0a6c7e;
            border: 1px solid rgba(76, 201, 240, 0.3);
        }

        .priority-high {
            color: var(--danger);
            font-weight: 600;
            background: rgba(230, 57, 70, 0.1);
            padding: 5px 12px;
            border-radius: 6px;
        }

        .priority-critical {
            color: white;
            font-weight: 700;
            text-transform: uppercase;
            background: var(--gradient-secondary);
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
        }

        .media-cell {
            max-width: 200px;
        }

        .media-cell img {
            max-width: 100%;
            border-radius: 8px;
            margin-bottom: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            transition: var(--transition);
        }

        .media-cell img:hover {
            transform: scale(1.05);
        }

        .media-cell video {
            max-width: 100%;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .no-media {
            color: var(--gray);
            font-size: 0.9rem;
            font-style: italic;
            padding: 10px;
            text-align: center;
            background: var(--gray-light);
            border-radius: 6px;
        }

        .action-form {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .action-form select {
            flex: 1;
        }

        /* File Upload */
        .file-upload {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-upload input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .file-upload-label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: var(--light);
            border: 2px dashed var(--gray-light);
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
        }

        .file-upload-label:hover {
            border-color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        .file-upload-label i {
            margin-right: 10px;
            color: var(--primary);
        }

        /* Admin Info */
        .admin-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            padding: 20px;
            background: rgba(67, 97, 238, 0.05);
            border-radius: var(--border-radius-sm);
            border-left: 5px solid var(--primary);
        }

        .admin-info p {
            margin: 0;
            font-weight: 600;
            color: var(--dark);
        }

        /* Footer */
        .footer {
            text-align: center;
            margin-top: 50px;
            padding: 25px;
            color: white;
            font-size: 0.9rem;
        }

        /* Location Display */
        .location-display {
            white-space: pre-line;
            line-height: 1.4;
        }

        .coordinates {
            font-size: 0.85rem;
            color: var(--gray);
            font-family: monospace;
        }

        .address {
            font-weight: 600;
            color: var(--dark);
        }

        /* Filter Form */
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 0.9rem;
            margin-bottom: 5px;
            color: var(--gray);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .filter-actions .btn {
            height: fit-content;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .nav-container {
                flex-direction: column;
                text-align: center;
            }
            
            .nav-links {
                justify-content: center;
            }
            
            .header h1 {
                font-size: 2.2rem;
            }
            
            .navigation-cards,
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .action-form {
                flex-direction: column;
            }
            
            .location-input-group {
                flex-direction: column;
            }
            
            .location-input-group .btn {
                width: 100%;
            }
            
            .admin-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .card-header i {
                margin-right: 0;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Navigation -->
        <div class="nav-container">
            <a href="?panel=dashboard" class="nav-brand">
                <i class="fas fa-hands-helping"></i>
                <span>Community Issue Reporter</span>
            </a>
            <div class="nav-links">
                <a href="?panel=dashboard" class="nav-link <?php echo $current_panel === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard
                </a>
                <a href="?panel=user" class="nav-link <?php echo $current_panel === 'user' ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i>
                    User Panel
                </a>
                <a href="?panel=admin" class="nav-link <?php echo $current_panel === 'admin' ? 'active' : ''; ?>">
                    <i class="fas fa-user-shield"></i>
                    Admin Panel
                </a>
                <a href="?panel=view" class="nav-link <?php echo $current_panel === 'view' ? 'active' : ''; ?>">
                    <i class="fas fa-list-alt"></i>
                    View Issues
                </a>
                <?php if(is_admin()): ?>
                    <a href="?logout=1" class="nav-link" style="background: rgba(230, 57, 70, 0.1); color: var(--danger);">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Messages -->
        <?php if(!empty($msg)): ?>
            <div class="message <?php 
                if(strpos($msg, 'successful') !== false) echo 'message-success'; 
                elseif(strpos($msg, 'Invalid') !== false) echo 'message-error';
                else echo 'message-info';
            ?>">
                <i class="fas <?php 
                    if(strpos($msg, 'successful') !== false) echo 'fa-check-circle'; 
                    elseif(strpos($msg, 'Invalid') !== false) echo 'fa-exclamation-circle';
                    else echo 'fa-info-circle';
                ?>"></i>
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <!-- Dashboard Panel -->
        <?php if($current_panel === 'dashboard'): ?>
            <div class="header">
                <h1><i class="fas fa-hands-helping"></i> Community Issue Reporter</h1>
                <p>Welcome to our community platform. Report issues, track progress, and help make our neighborhood better.</p>
            </div>

            <div class="navigation-cards">
                <a href="?panel=user" class="nav-card user">
                    <div class="nav-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <h3>User Panel</h3>
                    <p>Report new issues and track your submissions</p>
                    <div class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> Access User Panel
                    </div>
                </a>

                <a href="?panel=admin" class="nav-card admin">
                    <div class="nav-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h3>Admin Panel</h3>
                    <p>Manage issues, update status, and oversee community reports</p>
                    <div class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> Access Admin Panel
                    </div>
                </a>

                <a href="?panel=view" class="nav-card view">
                    <div class="nav-icon">
                        <i class="fas fa-list-alt"></i>
                    </div>
                    <h3>View Issues</h3>
                    <p>Browse all reported community issues and their status</p>
                    <div class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i> View All Issues
                    </div>
                </a>
            </div>

            <div class="stats-container">
                <div class="stat-card stat-total">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $total_issues; ?></h3>
                        <p>Total Issues Reported</p>
                    </div>
                </div>
                <div class="stat-card stat-pending">
                    <div class="stat-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $pending_issues; ?></h3>
                        <p>Pending Issues</p>
                    </div>
                </div>
                <div class="stat-card stat-resolved">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $resolved_issues; ?></h3>
                        <p>Resolved Issues</p>
                    </div>
                </div>
                <div class="stat-card stat-critical">
                    <div class="stat-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $critical_issues; ?></h3>
                        <p>Critical Issues</p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list-alt"></i>
                    <h2>Recently Reported Issues</h2>
                </div>

                <?php if(empty($issues)): ?>
                    <div style="text-align: center; padding: 40px; color: var(--gray);">
                        <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
                        <h3>No Issues Reported Yet</h3>
                        <p>Be the first to report a community issue</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Problem</th>
                                    <th>Location</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $recent_issues = array_slice($issues, 0, 5);
                                foreach($recent_issues as $r): 
                                ?>
                                    <tr>
                                        <td><strong>#<?php echo (int)$r['id']; ?></strong></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($r['problem_name']); ?></strong>
                                            <?php if(!empty($r['description'])): ?>
                                                <br><small style="color: var(--gray);"><?php echo substr(htmlspecialchars($r['description']), 0, 50); ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="location-display">
                                            <?php
                                            $location_text = $r['location'];
                                            // Check if location contains coordinates and location name format
                                            if(strpos($location_text, 'Coordinates:') !== false && strpos($location_text, 'Location:') !== false) {
                                                $lines = explode("\n", $location_text);
                                                $coordinates = str_replace('Coordinates: ', '', $lines[0]);
                                                $location_name = str_replace('Location: ', '', $lines[1]);
                                                echo '<div class="coordinates">' . htmlspecialchars($coordinates) . '</div>';
                                                echo '<div class="address">' . htmlspecialchars($location_name) . '</div>';
                                            } else {
                                                echo htmlspecialchars($location_text);
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="<?php 
                                                if($r['priority'] == 'High') echo 'priority-high';
                                                elseif($r['priority'] == 'Critical') echo 'priority-critical';
                                            ?>">
                                                <?php echo htmlspecialchars($r['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $r['status'])); ?>">
                                                <?php echo htmlspecialchars($r['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <!-- User Panel -->
        <?php elseif($current_panel === 'user'): ?>
            <div class="header">
                <h1><i class="fas fa-user"></i> User Panel</h1>
                <p>Report community issues and help improve our neighborhood</p>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-plus-circle"></i>
                    <h2>Report New Issue</h2>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="problem_name" class="required">Problem Name</label>
                        <input type="text" id="problem_name" name="problem_name" placeholder="e.g., Pothole on Main Street" required>
                    </div>

                    <div class="form-group">
                        <label for="loc" class="required">Location</label>
                        <div class="location-input-group">
                            <input type="text" id="loc" name="location" placeholder="Type your location or click 'Get My Location'" required>
                            <button type="button" id="getLocationBtn" class="btn btn-secondary">
                                <i class="fas fa-location-arrow"></i> Get My Location
                            </button>
                        </div>
                        
                        <!-- Location Preview -->
                        <div id="locationPreview" class="location-preview">
                            <div class="location-coordinates" id="locationCoordinates"></div>
                            <div class="location-name" id="locationName"></div>
                            <div class="location-confidence">
                                <i class="fas fa-check-circle"></i> Exact location detected
                            </div>
                            <div class="location-note">
                                <i class="fas fa-info-circle"></i> 
                                The system has detected your exact location name
                            </div>
                        </div>
                        
                        <small style="color: var(--gray); display: block; margin-top: 8px;">You can type your location manually or use the button to detect automatically</small>
                    </div>

                    <div class="form-group">
                        <label for="description" class="required">Description</label>
                        <textarea id="description" name="description" rows="4" placeholder="Please describe the issue in detail..." required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="priority">Priority Level</label>
                        <select id="priority" name="priority">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                            <option value="Critical">Critical</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="media">Upload Image/Video (Optional)</label>
                        <div class="file-upload">
                            <input type="file" id="media" name="media[]" multiple accept="image/*,video/*">
                            <div class="file-upload-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span>Click to upload or drag and drop</span>
                            </div>
                        </div>
                        <small style="color: var(--gray); display: block; margin-top: 8px;">You can select multiple files (images and videos)</small>
                    </div>

                    <button type="submit" name="submit_issue" class="btn btn-primary btn-block">
                        <i class="fas fa-paper-plane"></i> Submit Issue Report
                    </button>
                </form>
            </div>

        <!-- Admin Panel -->
        <?php elseif($current_panel === 'admin'): ?>
            <div class="header">
                <h1><i class="fas fa-user-shield"></i> Admin Panel</h1>
                <p>Manage and resolve community reported issues</p>
            </div>

            <?php if(!is_admin()): ?>
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-lock"></i>
                        <h2>Administrator Login</h2>
                    </div>
                    <p style="margin-bottom: 20px; color: var(--gray);">Login as administrator to manage and resolve reported issues.</p>
                    <form method="post">
                        <div class="form-group">
                            <label for="username" class="required">Username</label>
                            <input type="text" id="username" name="username" placeholder="Admin username" required>
                        </div>

                        <div class="form-group">
                            <label for="password" class="required">Password</label>
                            <input type="password" id="password" name="password" placeholder="Admin password" required>
                        </div>

                        <button type="submit" name="login" class="btn btn-primary btn-block">
                            <i class="fas fa-sign-in-alt"></i> Login to Admin Panel
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="admin-info">
                    <p><i class="fas fa-user-circle"></i> Logged in as <b><?php echo htmlspecialchars($_SESSION['admin']); ?></b></p>
                    <div>
                        <a href="?logout=1" class="btn btn-danger">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>

                <div class="stats-container">
                    <div class="stat-card stat-total">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $total_issues; ?></h3>
                            <p>Total Issues</p>
                        </div>
                    </div>
                    <div class="stat-card stat-pending">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $pending_issues; ?></h3>
                            <p>Pending</p>
                        </div>
                    </div>
                    <div class="stat-card stat-resolved">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $resolved_issues; ?></h3>
                            <p>Resolved</p>
                        </div>
                    </div>
                    <div class="stat-card stat-critical">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?php echo $critical_issues; ?></h3>
                            <p>Critical</p>
                        </div>
                    </div>
                </div>

                <!-- Date Filter and Report Generation -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-filter"></i>
                        <h2>Filter Issues & Generate Report</h2>
                    </div>
                    <form method="get">
                        <input type="hidden" name="panel" value="admin">
                        <div class="filter-form">
                            <div class="filter-group">
                                <label for="start_date">Start Date</label>
                                <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                            </div>
                            <div class="filter-group">
                                <label for="end_date">End Date</label>
                                <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                            </div>
                            <div class="filter-group">
                                <label for="status_filter">Status</label>
                                <select id="status_filter" name="status_filter">
                                    <option value="all" <?php echo $status_filter === 'all' || empty($status_filter) ? 'selected' : ''; ?>>All Status</option>
                                    <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="In Progress" <?php echo $status_filter === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="priority_filter">Priority</label>
                                <select id="priority_filter" name="priority_filter">
                                    <option value="all" <?php echo $priority_filter === 'all' || empty($priority_filter) ? 'selected' : ''; ?>>All Priority</option>
                                    <option value="Low" <?php echo $priority_filter === 'Low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="Medium" <?php echo $priority_filter === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="High" <?php echo $priority_filter === 'High' ? 'selected' : ''; ?>>High</option>
                                    <option value="Critical" <?php echo $priority_filter === 'Critical' ? 'selected' : ''; ?>>Critical</option>
                                </select>
                            </div>
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                                <a href="?panel=admin" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                                <button type="submit" name="generate_report" value="1" class="btn btn-excel">
                                    <i class="fas fa-file-excel"></i> Export to Excel
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-tasks"></i>
                        <h2>Manage Issues</h2>
                    </div>

                    <?php if(empty($issues)): ?>
                        <div style="text-align: center; padding: 40px; color: var(--gray);">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
                            <h3>No Issues Reported Yet</h3>
                            <p>No community issues have been reported yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Problem</th>
                                        <th>Location</th>
                                        <th>Priority</th>
                                        <th>Media</th>
                                        <th>Status</th>
                                        <th>Reported</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($issues as $r): ?>
                                        <tr>
                                            <td><strong>#<?php echo (int)$r['id']; ?></strong></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($r['problem_name']); ?></strong>
                                                <?php if(!empty($r['description'])): ?>
                                                    <br><small style="color: var(--gray);"><?php echo substr(htmlspecialchars($r['description']), 0, 50); ?>...</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="location-display">
                                                <?php
                                                $location_text = $r['location'];
                                                // Check if location contains coordinates and location name format
                                                if(strpos($location_text, 'Coordinates:') !== false && strpos($location_text, 'Location:') !== false) {
                                                    $lines = explode("\n", $location_text);
                                                    $coordinates = str_replace('Coordinates: ', '', $lines[0]);
                                                    $location_name = str_replace('Location: ', '', $lines[1]);
                                                    echo '<div class="coordinates">' . htmlspecialchars($coordinates) . '</div>';
                                                    echo '<div class="address">' . htmlspecialchars($location_name) . '</div>';
                                                } else {
                                                    echo htmlspecialchars($location_text);
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="<?php 
                                                    if($r['priority'] == 'High') echo 'priority-high';
                                                    elseif($r['priority'] == 'Critical') echo 'priority-critical';
                                                ?>">
                                                    <?php echo htmlspecialchars($r['priority']); ?>
                                                </span>
                                            </td>
                                            <td class="media-cell">
                                                <?php if(!empty($r['image_path'])): ?>
                                                    <img src="<?php echo htmlspecialchars($r['image_path']); ?>" alt="Issue Image">
                                                <?php endif; ?>
                                                <?php if(!empty($r['video_path'])): ?>
                                                    <video controls width="160">
                                                        <source src="<?php echo htmlspecialchars($r['video_path']); ?>">
                                                        Your browser does not support the video tag.
                                                    </video>
                                                <?php endif; ?>
                                                <?php if(empty($r['image_path']) && empty($r['video_path'])): ?>
                                                    <span class="no-media">No media</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $r['status'])); ?>">
                                                    <?php echo htmlspecialchars($r['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                if(isset($r['created_at'])) {
                                                    echo date('M j, Y', strtotime($r['created_at']));
                                                } else {
                                                    echo 'Recently';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <!-- Status Update -->
                                                <form method="post" class="action-form">
                                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                    <select name="status">
                                                        <option value="Pending" <?php if($r['status']==='Pending') echo 'selected'; ?>>Pending</option>
                                                        <option value="In Progress" <?php if($r['status']==='In Progress') echo 'selected'; ?>>In Progress</option>
                                                        <option value="Completed" <?php if($r['status']==='Completed') echo 'selected'; ?>>Completed</option>
                                                    </select>
                                                    <button type="submit" name="update_status" class="btn btn-success btn-sm">
                                                        <i class="fas fa-sync-alt"></i>
                                                    </button>
                                                </form>

                                                <!-- Delete -->
                                                <form method="post" onsubmit="return confirm('Are you sure you want to delete Issue #<?php echo (int)$r['id']; ?>?');" class="action-form">
                                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                    <button type="submit" name="delete_issue" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <!-- View Issues Panel -->
        <?php elseif($current_panel === 'view'): ?>
            <div class="header">
                <h1><i class="fas fa-list-alt"></i> View Community Issues</h1>
                <p>Browse all reported issues and their current status</p>
            </div>

            <!-- Date Filter for View Panel -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-filter"></i>
                    <h2>Filter Issues</h2>
                </div>
                <form method="get">
                    <input type="hidden" name="panel" value="view">
                    <div class="filter-form">
                        <div class="filter-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="status_filter">Status</label>
                            <select id="status_filter" name="status_filter">
                                <option value="all" <?php echo $status_filter === 'all' || empty($status_filter) ? 'selected' : ''; ?>>All Status</option>
                                <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="In Progress" <?php echo $status_filter === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Completed" <?php echo $status_filter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="priority_filter">Priority</label>
                            <select id="priority_filter" name="priority_filter">
                                <option value="all" <?php echo $priority_filter === 'all' || empty($priority_filter) ? 'selected' : ''; ?>>All Priority</option>
                                <option value="Low" <?php echo $priority_filter === 'Low' ? 'selected' : ''; ?>>Low</option>
                                <option value="Medium" <?php echo $priority_filter === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="High" <?php echo $priority_filter === 'High' ? 'selected' : ''; ?>>High</option>
                                <option value="Critical" <?php echo $priority_filter === 'Critical' ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                            <a href="?panel=view" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                            <?php if(is_admin()): ?>
                                <button type="submit" name="generate_report" value="1" class="btn btn-excel">
                                    <i class="fas fa-file-excel"></i> Export to Excel
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-list-ul"></i>
                    <h2>All Reported Issues</h2>
                </div>

                <?php if(empty($issues)): ?>
                    <div style="text-align: center; padding: 40px; color: var(--gray);">
                        <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
                        <h3>No Issues Reported Yet</h3>
                        <p>Be the first to report a community issue</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Problem</th>
                                    <th>Location</th>
                                    <th>Priority</th>
                                    <th>Media</th>
                                    <th>Status</th>
                                    <th>Reported</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($issues as $r): ?>
                                    <tr>
                                        <td><strong>#<?php echo (int)$r['id']; ?></strong></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($r['problem_name']); ?></strong>
                                            <?php if(!empty($r['description'])): ?>
                                                <br><small style="color: var(--gray);"><?php echo substr(htmlspecialchars($r['description']), 0, 80); ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="location-display">
                                            <?php
                                            $location_text = $r['location'];
                                            // Check if location contains coordinates and location name format
                                            if(strpos($location_text, 'Coordinates:') !== false && strpos($location_text, 'Location:') !== false) {
                                                $lines = explode("\n", $location_text);
                                                $coordinates = str_replace('Coordinates: ', '', $lines[0]);
                                                $location_name = str_replace('Location: ', '', $lines[1]);
                                                echo '<div class="coordinates">' . htmlspecialchars($coordinates) . '</div>';
                                                echo '<div class="address">' . htmlspecialchars($location_name) . '</div>';
                                            } else {
                                                echo htmlspecialchars($location_text);
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="<?php 
                                                if($r['priority'] == 'High') echo 'priority-high';
                                                elseif($r['priority'] == 'Critical') echo 'priority-critical';
                                            ?>">
                                                <?php echo htmlspecialchars($r['priority']); ?>
                                            </span>
                                        </td>
                                        <td class="media-cell">
                                            <?php if(!empty($r['image_path'])): ?>
                                                <img src="<?php echo htmlspecialchars($r['image_path']); ?>" alt="Issue Image">
                                            <?php endif; ?>
                                            <?php if(!empty($r['video_path'])): ?>
                                                <video controls width="160">
                                                    <source src="<?php echo htmlspecialchars($r['video_path']); ?>">
                                                    Your browser does not support the video tag.
                                                </video>
                                            <?php endif; ?>
                                            <?php if(empty($r['image_path']) && empty($r['video_path'])): ?>
                                                <span class="no-media">No media</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $r['status'])); ?>">
                                                <?php echo htmlspecialchars($r['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            if(isset($r['created_at'])) {
                                                echo date('M j, Y', strtotime($r['created_at']));
                                            } else {
                                                echo 'Recently';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> Community Issue Reporter | Making our community better, one issue at a time</p>
        </div>
    </div>

    <script>
    // Location detection functionality for user panel - UPDATED FOR EXACT LOCATION NAMES
    <?php if($current_panel === 'user'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const locInput = document.getElementById("loc");
        const getLocationBtn = document.getElementById("getLocationBtn");
        const locationPreview = document.getElementById("locationPreview");
        const locationCoordinates = document.getElementById("locationCoordinates");
        const locationName = document.getElementById("locationName");
        
        // Function to get current location
        function getCurrentLocation() {
            if(!navigator.geolocation){
                showLocationError("GPS not supported by your browser");
                return;
            }
            
            // Show loading state
            getLocationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Detecting...';
            getLocationBtn.disabled = true;
            
            navigator.geolocation.getCurrentPosition(async (pos)=>{
                const lat = pos.coords.latitude.toFixed(6);
                const lon = pos.coords.longitude.toFixed(6);
                const coordinates = `${lat}, ${lon}`;
                
                // Show coordinates immediately
                locationCoordinates.textContent = `Coordinates: ${coordinates}`;
                locationName.textContent = "Detecting exact location name...";
                locationPreview.classList.add('active');
                
                try {
                    // Use OpenStreetMap Nominatim API to get exact location name
                    const response = await fetch(
                        `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lon}&addressdetails=1&zoom=18`
                    );
                    
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    
                    const data = await response.json();
                    
                    let locationNameText = "Unknown Location";
                    
                    if(data && data.address) {
                        // Try to get the most specific location name in order of preference
                        const addr = data.address;
                        
                        if(addr.neighbourhood && addr.neighbourhood.trim() !== '') {
                            locationNameText = addr.neighbourhood;
                        } else if(addr.suburb && addr.suburb.trim() !== '') {
                            locationNameText = addr.suburb;
                        } else if(addr.village && addr.village.trim() !== '') {
                            locationNameText = addr.village;
                        } else if(addr.town && addr.town.trim() !== '') {
                            locationNameText = addr.town;
                        } else if(addr.city && addr.city.trim() !== '') {
                            locationNameText = addr.city;
                        } else if(addr.county && addr.county.trim() !== '') {
                            locationNameText = addr.county;
                        } else if(addr.state && addr.state.trim() !== '') {
                            locationNameText = addr.state;
                        } else if(data.display_name) {
                            // Fallback to display_name - take first meaningful part
                            const parts = data.display_name.split(',');
                            for(let part of parts) {
                                part = part.trim();
                                if(part && !part.match(/^\d/)) { // Skip numeric parts
                                    locationNameText = part;
                                    break;
                                }
                            }
                        }
                    } else if(data.display_name) {
                        const parts = data.display_name.split(',');
                        for(let part of parts) {
                            part = part.trim();
                            if(part && !part.match(/^\d/)) {
                                locationNameText = part;
                                break;
                            }
                        }
                    }
                    
                    // Update the display
                    locationName.textContent = locationNameText;
                    
                    // Set the input value to include both coordinates and exact location name
                    locInput.value = `${coordinates} || ${locationNameText}`;
                    
                    showLocationSuccess(`Exact location detected: ${locationNameText}`);
                    
                } catch(error) {
                    console.error('Error fetching location:', error);
                    // Fallback to coordinates only
                    locationName.textContent = "Could not detect location name";
                    locInput.value = coordinates;
                    showLocationSuccess("Location detected (coordinates only)");
                } finally {
                    // Reset button state
                    resetLocationButton();
                }
                
            }, (err)=>{
                // User denied location or error occurred
                let errorMessage = "Type your location manually (e.g., Sunmitr Colony, Warnanagar)";
                
                switch(err.code) {
                    case err.PERMISSION_DENIED:
                        errorMessage = "Location access denied. Please type your location manually";
                        break;
                    case err.POSITION_UNAVAILABLE:
                        errorMessage = "Location unavailable. Please type your location manually";
                        break;
                    case err.TIMEOUT:
                        errorMessage = "Location request timeout. Please type your location manually";
                        break;
                }
                
                showLocationError(errorMessage);
                resetLocationButton();
                locationPreview.classList.remove('active');
            }, {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 60000
            });
        }
        
        // Function to show location success message
        function showLocationSuccess(message) {
            // Create or update success message
            let successMsg = document.getElementById('locationSuccessMsg');
            if (!successMsg) {
                successMsg = document.createElement('div');
                successMsg.id = 'locationSuccessMsg';
                successMsg.className = 'message message-success';
                successMsg.style.marginTop = '10px';
                successMsg.style.marginBottom = '0';
                getLocationBtn.parentNode.appendChild(successMsg);
            }
            successMsg.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
            
            // Remove any existing error message
            const errorMsg = document.getElementById('locationErrorMsg');
            if (errorMsg) {
                errorMsg.remove();
            }
        }
        
        // Function to show location error message
        function showLocationError(message) {
            // Create or update error message
            let errorMsg = document.getElementById('locationErrorMsg');
            if (!errorMsg) {
                errorMsg = document.createElement('div');
                errorMsg.id = 'locationErrorMsg';
                errorMsg.className = 'message message-error';
                errorMsg.style.marginTop = '10px';
                errorMsg.style.marginBottom = '0';
                getLocationBtn.parentNode.appendChild(errorMsg);
            }
            errorMsg.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
            
            // Remove any existing success message
            const successMsg = document.getElementById('locationSuccessMsg');
            if (successMsg) {
                successMsg.remove();
            }
        }
        
        // Function to reset location button state
        function resetLocationButton() {
            getLocationBtn.innerHTML = '<i class="fas fa-location-arrow"></i> Get My Location';
            getLocationBtn.disabled = false;
        }
        
        // Add click event listener to the button
        getLocationBtn.addEventListener('click', getCurrentLocation);
        
        // Hide preview when user starts typing manually
        locInput.addEventListener('input', function() {
            if(!this.value.includes('||')) {
                locationPreview.classList.remove('active');
            }
        });
    });
    <?php endif; ?>
    </script>
</body>
</html>


