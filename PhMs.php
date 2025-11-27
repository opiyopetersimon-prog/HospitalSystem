<?php
// Prison_Hospital_Management_System_Index.php
// Single-file demo PHP application using SQLite.
// Features implemented:
// - Register staff and up to 10 dependants (with photos)
// - Log visits, admissions and discharge outcomes
// - Search by hospital number, name, station, force/file number, rank
// - Simple dashboards (charts) using Chart.js
// - Export to CSV (Excel), printable PDF (browser print), and JPG via html2canvas
// NOTE: This is a demo single-file app for prototyping. For production, separate concerns into multiple files and secure file uploads and authentication.

// ------------------- Setup -------------------
$dbFile = __DIR__ . '/data.db';
$uploadsDir = __DIR__ . '/uploads';
if (!file_exists($uploadsDir)) mkdir($uploadsDir, 0755, true);
$init = !file_exists($dbFile);
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($init) {
    // Create tables
    $db->exec("CREATE TABLE staff (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        hospital_number TEXT UNIQUE,
        full_name TEXT,
        dob TEXT,
        gender TEXT,
        telephone TEXT,
        force_file_number TEXT,
        station TEXT,
        rank TEXT,
        photo TEXT,
        created_at TEXT
    )");

    $db->exec("CREATE TABLE dependant (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        staff_id INTEGER,
        name TEXT,
        dob TEXT,
        relation TEXT,
        photo TEXT,
        FOREIGN KEY(staff_id) REFERENCES staff(id)
    )");

    $db->exec("CREATE TABLE visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        staff_id INTEGER,
        date_visit TEXT,
        reason TEXT,
        condition TEXT,
        type TEXT,
        admitted INTEGER DEFAULT 0,
        date_admission TEXT,
        outcome TEXT,
        referral_destination TEXT,
        discharge_date TEXT,
        notes TEXT,
        created_at TEXT,
        FOREIGN KEY(staff_id) REFERENCES staff(id)
    )");
}

// ------------------- Helpers -------------------
function slugFileName($name) {
    $time = time();
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    return $time . '_' . $name;
}

function handleUpload($field, $uploadsDir) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $tmp = $_FILES[$field]['tmp_name'];
    $name = basename($_FILES[$field]['name']);
    $safe = slugFileName($name);
    $dest = $uploadsDir . '/' . $safe;
    move_uploaded_file($tmp, $dest);
    return 'uploads/' . $safe;
}

// ------------------- Routing (simple) -------------------
$action = $_REQUEST['action'] ?? 'home';

// --------- Handle create staff ---------
if ($action === 'create_staff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $hospital_number = $_POST['hospital_number'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $dob = $_POST['dob'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $telephone = $_POST['telephone'] ?? '';
    $force_file_number = $_POST['force_file_number'] ?? '';
    $station = $_POST['station'] ?? '';
    $rank = $_POST['rank'] ?? '';
    $photo = handleUpload('photo', $uploadsDir);
    $stmt = $db->prepare('INSERT OR REPLACE INTO staff (hospital_number, full_name, dob, gender, telephone, force_file_number, station, rank, photo, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$hospital_number, $full_name, $dob, $gender, $telephone, $force_file_number, $station, $rank, $photo, date('c')]);
    header('Location: ?action=view_staff&hospital_number=' . urlencode($hospital_number));
    exit;
}

// --------- Handle add dependant ---------
if ($action === 'add_dependant' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $staff_id = $_POST['staff_id'] ?? 0;
    $name = $_POST['dep_name'] ?? '';
    $dob = $_POST['dep_dob'] ?? '';
    $relation = $_POST['dep_relation'] ?? '';
    $photo = handleUpload('dep_photo', $uploadsDir);
    $stmt = $db->prepare('SELECT COUNT(*) FROM dependant WHERE staff_id = ?');
    $stmt->execute([$staff_id]);
    $count = $stmt->fetchColumn();
    if ($count >= 10) {
        header('Location: ?action=view_staff&id=' . $staff_id . '&error=Max+dependants+reached');
        exit;
    }
    $stmt = $db->prepare('INSERT INTO dependant (staff_id, name, dob, relation, photo) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$staff_id, $name, $dob, $relation, $photo]);
    header('Location: ?action=view_staff&id=' . $staff_id);
    exit;
}

// --------- Handle log visit ---------
if ($action === 'log_visit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $staff_id = $_POST['staff_id'] ?? 0;
    $date_visit = $_POST['date_visit'] ?? date('Y-m-d');
    $reason = $_POST['reason'] ?? '';
    $condition = $_POST['condition'] ?? '';
    $type = $_POST['type'] ?? '';
    $admitted = isset($_POST['admitted']) ? 1 : 0;
    $date_admission = $_POST['date_admission'] ?? null;
    $outcome = $_POST['outcome'] ?? null;
    $referral_destination = $_POST['referral_destination'] ?? null;
    $discharge_date = $_POST['discharge_date'] ?? null;
    $notes = $_POST['notes'] ?? null;
    $stmt = $db->prepare('INSERT INTO visits (staff_id, date_visit, reason, condition, type, admitted, date_admission, outcome, referral_destination, discharge_date, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$staff_id, $date_visit, $reason, $condition, $type, $admitted, $date_admission, $outcome, $referral_destination, $discharge_date, $notes, date('c')]);
    header('Location: ?action=view_staff&id=' . $staff_id);
    exit;
}

// --------- Export CSV ---------
if ($action === 'export_csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="export_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Hospital No','Full Name','DOB','Gender','Telephone','Force/File No','Station','Rank']);
    $stmt = $db->query('SELECT hospital_number, full_name, dob, gender, telephone, force_file_number, station, rank FROM staff');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $row);
    fclose($out);
    exit;
}

// --------- Search ---------
$search = $_GET['search'] ?? '';
$searchQuery = '';
$params = [];
if ($search !== '') {
    $searchQuery = "WHERE hospital_number LIKE ? OR full_name LIKE ? OR station LIKE ? OR force_file_number LIKE ? OR rank LIKE ?";
    $like = "%$search%";
    $params = array_fill(0,5,$like);
}

$staffListStmt = $db->prepare('SELECT id, hospital_number, full_name, station, rank FROM staff ' . $searchQuery . ' ORDER BY created_at DESC');
$staffListStmt->execute($params);
$staffList = $staffListStmt->fetchAll(PDO::FETCH_ASSOC);

// --------- Stats for dashboard ---------
$stats = [];
$stats['total_staff'] = $db->query('SELECT COUNT(*) FROM staff')->fetchColumn();
$stats['total_visits'] = $db->query('SELECT COUNT(*) FROM visits')->fetchColumn();
$stats['admissions'] = $db->query('SELECT COUNT(*) FROM visits WHERE admitted = 1')->fetchColumn();
$stats['deaths'] = $db->query("SELECT COUNT(*) FROM visits WHERE outcome = 'Died'")->fetchColumn();
$stats['discharged'] = $db->query("SELECT COUNT(*) FROM visits WHERE outcome = 'Discharged' OR outcome = 'Recovered'")->fetchColumn();
$stats['referred'] = $db->query("SELECT COUNT(*) FROM visits WHERE outcome = 'Referred'")->fetchColumn();

// ------------------- Render HTML -------------------
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prisons Staff Hospital Management</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
:root{--bg:#f5f7fb;--card:#fff;--muted:#6b7280;--accent:#0ea5a4}
*{box-sizing:border-box;font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,'Helvetica Neue',Arial}
body{margin:0;background:var(--bg);color:#0f172a}
.container{max-width:1200px;margin:24px auto;padding:16px}
.header{display:flex;align-items:center;justify-content:space-between}
.brand{display:flex;gap:12px;align-items:center}
.logo{width:56px;height:56px;background:linear-gradient(135deg,#06b6d4,#0891b2);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700}
.card{background:var(--card);padding:18px;border-radius:12px;box-shadow:0 8px 20px rgba(2,6,23,0.06);}
.grid{display:grid;gap:16px}
.grid-cols-3{grid-template-columns:repeat(3,1fr)}
.grid-cols-2{grid-template-columns:repeat(2,1fr)}
.form-row{display:flex;gap:12px}
.input,textarea,select{width:100%;padding:10px;border-radius:8px;border:1px solid #e6eef8}
.btn{display:inline-block;padding:10px 14px;border-radius:8px;background:var(--accent);color:#fff;border:none;cursor:pointer}
.small{font-size:13px;color:var(--muted)}
.table{width:100%;border-collapse:collapse;margin-top:12px}
.table th,.table td{padding:10px;border-bottom:1px solid #eef2f7;text-align:left}
.preview{width:72px;height:72px;border-radius:6px;object-fit:cover}
.search-bar{display:flex;gap:8px}
.controls{display:flex;gap:8px;align-items:center}
.badge{display:inline-block;padding:6px 8px;border-radius:999px;background:#eef2ff;color:#1e3a8a;font-weight:600;font-size:12px}
.footer{margin-top:20px;text-align:center;color:var(--muted);font-size:13px}
@media(max-width:900px){.grid-cols-3{grid-template-columns:1fr}.form-row{flex-direction:column}}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="brand">
            <div class="logo">PH</div>
            <div>
                <h2 style="margin:0">Prisons Staff Hospital Management</h2>
                <div class="small">Manage staff, dependants, visits and admissions</div>
            </div>
        </div>
        <div class="controls">
            <form method="get" class="search-bar" style="margin:0">
                <input class="input" name="search" placeholder="Search by hospital no, name, station..." value="<?= htmlspecialchars($search) ?>">
                <button class="btn">Search</button>
            </form>
            <a class="btn" href="?action=export_csv">Export CSV</a>
            <button class="btn" id="exportJpgBtn">Export JPG</button>
        </div>
    </div>

    <div style="height:16px"></div>

    <div class="grid grid-cols-3">
        <div class="card">
            <h3 style="margin-top:0">Register Staff</h3>
            <form method="post" enctype="multipart/form-data" action="?action=create_staff">
                <div class="form-row"><input class="input" name="hospital_number" placeholder="Hospital Number" required><input class="input" name="full_name" placeholder="Full Name" required></div>
                <div class="form-row"><input class="input" name="dob" placeholder="Date of Birth (YYYY-MM-DD)"><select class="input" name="gender"><option>Male</option><option>Female</option></select></div>
                <div class="form-row"><input class="input" name="telephone" placeholder="Telephone"><input class="input" name="force_file_number" placeholder="Force/File Number"></div>
                <div class="form-row"><input class="input" name="station" placeholder="Station"><input class="input" name="rank" placeholder="Rank"></div>
                <div style="margin-top:8px">Photo <input type="file" name="photo" accept="image/*"></div>
                <div style="margin-top:12px"><button class="btn">Save Staff</button></div>
            </form>
        </div>

        <div class="card">
            <h3 style="margin-top:0">Quick Stats</h3>
            <div style="display:flex;gap:12px;align-items:center">
                <div>
                    <div class="small">Total Staff</div>
                    <div style="font-weight:700;font-size:20px"><?= $stats['total_staff'] ?></div>
                </div>
                <div>
                    <div class="small">Total Visits</div>
                    <div style="font-weight:700;font-size:20px"><?= $stats['total_visits'] ?></div>
                </div>
                <div>
                    <div class="small">Admissions</div>
                    <div style="font-weight:700;font-size:20px"><?= $stats['admissions'] ?></div>
                </div>
            </div>
            <div style="margin-top:12px"><canvas id="dashboardChart" height="120"></canvas></div>
        </div>

        <div class="card">
            <h3 style="margin-top:0">Latest Staff</h3>
            <table class="table">
                <thead><tr><th>Hospital No</th><th>Name</th><th>Station</th><th></th></tr></thead>
                <tbody>
                <?php foreach($staffList as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['hospital_number']) ?></td>
                        <td><?= htmlspecialchars($s['full_name']) ?></td>
                        <td><?= htmlspecialchars($s['station']) ?></td>
                        <td><a href="?action=view_staff&id=<?= $s['id'] ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="height:16px"></div>

    <?php if ($action === 'view_staff' && isset($_GET['id'])):
        $id = (int)$_GET['id'];
        $stmt = $db->prepare('SELECT * FROM staff WHERE id = ?'); $stmt->execute([$id]); $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$staff) { echo '<div class="card">Staff not found</div>'; } else {
            $deps = $db->prepare('SELECT * FROM dependant WHERE staff_id = ?'); $deps->execute([$id]); $deps = $deps->fetchAll(PDO::FETCH_ASSOC);
            $visits = $db->prepare('SELECT * FROM visits WHERE staff_id = ? ORDER BY date_visit DESC'); $visits->execute([$id]); $visits = $visits->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="grid grid-cols-2">
        <div class="card">
            <div style="display:flex;gap:12px;align-items:center">
                <img src="<?= $staff['photo'] ?: 'https://via.placeholder.com/90' ?>" class="preview">
                <div>
                    <h3 style="margin:0"><?= htmlspecialchars($staff['full_name']) ?></h3>
                    <div class="small">Hospital No: <?= htmlspecialchars($staff['hospital_number']) ?></div>
                    <div class="small">Station: <?= htmlspecialchars($staff['station']) ?> • Rank: <?= htmlspecialchars($staff['rank']) ?></div>
                    <div class="small">Telephone: <?= htmlspecialchars($staff['telephone']) ?></div>
                </div>
            </div>

            <hr style="margin:12px 0">
            <h4>Dependants (<?= count($deps) ?>/10)</h4>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <?php foreach($deps as $d): ?>
                    <div style="width:120px;" class="small card">
                        <img src="<?= $d['photo'] ?: 'https://via.placeholder.com/120' ?>" style="width:100%;height:80px;object-fit:cover;border-radius:6px">
                        <div style="padding:6px 0"><strong><?= htmlspecialchars($d['name']) ?></strong><br><span class="small"><?= htmlspecialchars($d['relation']) ?></span></div>
                    </div>
                <?php endforeach; ?>
                <?php if (count($deps) < 10): ?>
                <div style="width:220px;" class="card small">
                    <form method="post" enctype="multipart/form-data" action="?action=add_dependant">
                        <input type="hidden" name="staff_id" value="<?= $id ?>">
                        <input class="input" name="dep_name" placeholder="Dependant Name" required>
                        <input class="input" name="dep_relation" placeholder="Relation" required>
                        <input class="input" name="dep_dob" placeholder="DOB (YYYY-MM-DD)">
                        Photo <input type="file" name="dep_photo" accept="image/*"><div style="margin-top:8px"><button class="btn">Add Dependant</button></div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h4>Log Visit / Admission</h4>
            <form method="post" action="?action=log_visit">
                <input type="hidden" name="staff_id" value="<?= $id ?>">
                <div class="form-row"><input class="input" name="date_visit" value="<?= date('Y-m-d') ?>"><input class="input" name="type" placeholder="Type (Exam/Treatment/Checkup)"></div>
                <div class="form-row"><input class="input" name="reason" placeholder="Reason"><input class="input" name="condition" placeholder="Condition (stable/critical)"></div>
                <div><label><input type="checkbox" name="admitted" value="1"> Admit patient</label></div>
                <div class="form-row"><input class="input" name="date_admission" placeholder="Date of admission"><select class="input" name="outcome"><option value="">--Outcome--</option><option>Recovered</option><option>Discharged</option><option>Died</option><option>Referred</option></select></div>
                <div class="form-row"><input class="input" name="referral_destination" placeholder="Referral destination"><input class="input" name="discharge_date" placeholder="Discharge date"></div>
                <div><textarea class="input" name="notes" placeholder="Notes"></textarea></div>
                <div style="margin-top:8px"><button class="btn">Save Visit</button></div>
            </form>

            <hr style="margin:12px 0">
            <h4>Visit History</h4>
            <table class="table">
                <thead><tr><th>Date</th><th>Type</th><th>Condition</th><th>Admitted</th><th>Outcome</th></tr></thead>
                <tbody>
                <?php foreach($visits as $v): ?>
                    <tr>
                        <td><?= htmlspecialchars($v['date_visit']) ?></td>
                        <td><?= htmlspecialchars($v['type']) ?></td>
                        <td><?= htmlspecialchars($v['condition']) ?></td>
                        <td><?= $v['admitted'] ? 'Yes' : 'No' ?></td>
                        <td><?= htmlspecialchars($v['outcome']) ?> <?= $v['referral_destination'] ? '→ ' . htmlspecialchars($v['referral_destination']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php }
    endif; ?>

    <div class="footer card">Built for demonstration • Remember to secure in production</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
// Dashboard chart
const ctx = document.getElementById('dashboardChart');
if (ctx) {
    const chart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Admissions','Deaths','Discharged','Referred'],
            datasets: [{
                data: [<?= (int)$stats['admissions'] ?>, <?= (int)$stats['deaths'] ?>, <?= (int)$stats['discharged'] ?>, <?= (int)$stats['referred'] ?>],
                backgroundColor: ['#60a5fa','#f87171','#34d399','#fbbf24']
            }]
        },
        options: {plugins:{legend:{position:'bottom'}}}
    });
}

// Export JPG
document.getElementById('exportJpgBtn').addEventListener('click', function(){
    html2canvas(document.body, {scale:1}).then(canvas => {
        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/jpeg', 0.9);
        link.download = 'hospital_dashboard_' + Date.now() + '.jpg';
        link.click();
    });
});
</script>
</body>
</html>
