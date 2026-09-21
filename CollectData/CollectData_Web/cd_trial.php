<?php
/**
 * ข้อมูลการทดลอง — รายการบันทึกพร้อมตัวกรองตามประเภทและหมวดหมู่
 * URL: cd_trial.php
 */
require_once 'config.php';
$pdo = getDB();

// ดึงรายชื่อผู้ทดสอบและประเภทการเคลื่อนไหวเพื่อทำ dropdown filter
$subjects = $pdo->query("SELECT id, name FROM cd_subjects ORDER BY id")->fetchAll();
$fall_types = $pdo->query("SELECT id, code, name_th, category FROM cd_fall_types ORDER BY category, id")->fetchAll();

// กำหนดเงื่อนไขกรองข้อมูล (Filters)
$where_clauses = [];
$params = [];

$filter_category = $_GET['category'] ?? '';
$filter_subject = intval($_GET['subject_id'] ?? 0);
$filter_type = intval($_GET['fall_type_id'] ?? 0);

if (!empty($filter_category)) {
    $where_clauses[] = "ft.category = ?";
    $params[] = $filter_category;
}
if ($filter_subject > 0) {
    $where_clauses[] = "r.subject_id = ?";
    $params[] = $filter_subject;
}
if ($filter_type > 0) {
    $where_clauses[] = "r.fall_type_id = ?";
    $params[] = $filter_type;
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// 1. ดึงข้อมูลสถิติของข้อมูลที่ผ่านการกรอง
$stats_query = "
    SELECT 
        COUNT(*) as total_records,
        SUM(CASE WHEN ft.category = 'fall' THEN 1 ELSE 0 END) as total_falls,
        SUM(CASE WHEN ft.category = 'adl' THEN 1 ELSE 0 END) as total_adls,
        SUM(CASE WHEN ft.category = 'other' THEN 1 ELSE 0 END) as total_others,
        IFNULL(SUM((SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = r.id)), 0) as total_data_points
    FROM cd_records r
    JOIN cd_fall_types ft ON r.fall_type_id = ft.id
    $where_sql
";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($params);
$filtered_stats = $stats_stmt->fetch();

// 2. ดึงรายการบันทึกตามตัวกรอง
$query = "
    SELECT r.*,
           s.name as subject_name,
           d.name as device_name,
           ft.name_th as fall_type_name,
           ft.category as fall_type_category,
           (SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = r.id) as data_points
    FROM cd_records r
    JOIN cd_subjects s ON r.subject_id = s.id
    JOIN cd_devices d ON r.device_id = d.id
    JOIN cd_fall_types ft ON r.fall_type_id = ft.id
    $where_sql
    ORDER BY r.id DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อมูลการทดลอง — CollectData</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar">
        <a href="index.php" class="logo">📊 CollectData</a>
        <a href="index.php">ผู้ทดสอบ</a>
        <a href="cd_trial.php" class="active">ข้อมูลการทดลอง</a>
        <a href="devices.php">อุปกรณ์</a>
    </nav>

    <div class="container">
        <!-- Header -->
        <div class="page-header">
            <h1>🧪 ข้อมูลการทดลอง</h1>
        </div>

        <!-- Filter Card -->
        <div class="card" style="margin-bottom: 24px;">
            <h3 style="margin-bottom: 16px; color: var(--accent); display: flex; align-items: center; gap: 8px;">
                🔍 ค้นหาและกรองข้อมูล
            </h3>
            <form method="GET" action="cd_trial.php" id="filterForm">
                <div class="grid grid-3" style="gap: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>หมวดหมู่การเคลื่อนไหว</label>
                        <select name="category" id="filterCategory" onchange="filterTypesByCategory()">
                            <option value="">— ทั้งหมด —</option>
                            <option value="fall" <?= $filter_category == 'fall' ? 'selected' : '' ?>>ล้ม (Fall)</option>
                            <option value="adl" <?= $filter_category == 'adl' ? 'selected' : '' ?>>ชีวิตประจำวัน (ADL)</option>
                            <option value="other" <?= $filter_category == 'other' ? 'selected' : '' ?>>อื่นๆ (Other)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>ประเภทการเคลื่อนไหว</label>
                        <select name="fall_type_id" id="filterType">
                            <option value="">— ทั้งหมด —</option>
                            <?php foreach ($fall_types as $ft): ?>
                                <option value="<?= $ft['id'] ?>" data-category="<?= $ft['category'] ?>" <?= $filter_type == $ft['id'] ? 'selected' : '' ?>>
                                    [<?= strtoupper($ft['category']) ?>] <?= htmlspecialchars($ft['name_th']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>ผู้ทดสอบ</label>
                        <select name="subject_id">
                            <option value="">— ทั้งหมด —</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $filter_subject == $s['id'] ? 'selected' : '' ?>>
                                    บุคคลที่ <?= $s['id'] ?> — <?= htmlspecialchars($s['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 16px; display: flex; gap: 10px; justify-content: flex-end;">
                    <a href="cd_trial.php" class="btn btn-outline">ล้างค่า</a>
                    <button type="button" class="btn btn-success" onclick="exportCSV()">📥 ส่งออก CSV</button>
                    <button type="submit" class="btn btn-primary">🔍 ค้นหา</button>
                </div>
            </form>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-4" style="margin-bottom: 24px;">
            <div class="stat-card">
                <div class="stat-icon">📝</div>
                <div class="stat-value"><?= number_format(intval($filtered_stats['total_records'] ?? 0)) ?></div>
                <div class="stat-label">บันทึกที่พบ</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🚨</div>
                <div class="stat-value"><?= number_format(intval($filtered_stats['total_falls'] ?? 0)) ?></div>
                <div class="stat-label">ล้ม (Fall)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🏃‍♂️</div>
                <div class="stat-value"><?= number_format(intval($filtered_stats['total_adls'] ?? 0)) ?></div>
                <div class="stat-label">ชีวิตประจำวัน (ADL)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📈</div>
                <div class="stat-value"><?= number_format(intval($filtered_stats['total_data_points'] ?? 0)) ?></div>
                <div class="stat-label">จำนวนจุดข้อมูลรวม</div>
            </div>
        </div>

        <!-- Records Table -->
        <div class="card">
            <div class="card-header">
                <h2>📊 รายการข้อมูลการทดลอง</h2>
            </div>
            
            <?php if (empty($records)): ?>
                <div class="empty-state" style="padding: 40px 0;">
                    <div class="icon">📊</div>
                    <p>ไม่พบข้อมูลการบันทึกตามเงื่อนไขที่เลือก</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>ผู้ทดสอบ</th>
                                <th>หมวดหมู่</th>
                                <th>ประเภท</th>
                                <th>อุปกรณ์</th>
                                <th>จุดข้อมูล</th>
                                <th>สถานะ</th>
                                <th>วันที่บันทึก</th>
                                <th>การดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $r): ?>
                                <tr>
                                    <td style="font-weight:600; color:var(--accent);">#<?= $r['id'] ?></td>
                                    <td>
                                        <a href="subject.php?id=<?= $r['subject_id'] ?>" style="color:var(--accent); text-decoration:none; font-weight: 500;">
                                            👤 <?= htmlspecialchars($r['subject_name']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $r['fall_type_category'] ?>">
                                            <?= $r['fall_type_category'] == 'fall' ? 'ล้ม (Fall)' : ($r['fall_type_category'] == 'adl' ? 'ADL' : 'อื่นๆ') ?>
                                        </span>
                                    </td>
                                    <td style="font-weight:500;"><?= htmlspecialchars($r['fall_type_name']) ?> (รอบ #<?= $r['trial_number'] ?>)</td>
                                    <td style="font-size:13px; color:var(--text-secondary);"><?= htmlspecialchars($r['device_name']) ?></td>
                                    <td>
                                        <span class="badge badge-recording" style="font-weight: 500;">
                                            <?= number_format(intval($r['data_points'])) ?> จุด
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: rgba(255,255,255,0.05); color: var(--text-secondary);">
                                            <?= $r['status'] == 'completed' ? 'เสร็จสิ้น' : ($r['status'] == 'recording' ? 'กำลังบันทึก' : 'ยกเลิก') ?>
                                        </span>
                                    </td>
                                    <td style="font-size:12px; color:var(--text-secondary);"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                                    <td>
                                        <div style="display: flex; gap: 8px;">
                                            <a href="record.php?id=<?= $r['id'] ?>" class="btn btn-primary btn-sm">📊 ดูกราฟ</a>
                                            <button class="btn btn-danger btn-sm" onclick="if(confirm('คุณต้องการลบบันทึกการทดลองนี้หรือไม่? (ข้อมูลดิบจะถูกลบทั้งหมด)')) deleteRecord(<?= $r['id'] ?>)">🗑️ ลบ</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function filterTypesByCategory() {
            const categorySelect = document.getElementById('filterCategory');
            const typeSelect = document.getElementById('filterType');
            const selectedCategory = categorySelect.value;
            
            // Store currently selected type
            const currentSelectedType = typeSelect.value;
            
            // Filter options
            let hasMatchingSelected = false;
            for (let i = 0; i < typeSelect.options.length; i++) {
                const option = typeSelect.options[i];
                const optCategory = option.getAttribute('data-category');
                
                if (!optCategory) {
                    // Keep the "All" option visible
                    option.style.display = 'block';
                    continue;
                }
                
                if (selectedCategory === '' || optCategory === selectedCategory) {
                    option.style.display = 'block';
                    if (option.value === currentSelectedType) {
                        hasMatchingSelected = true;
                    }
                } else {
                    option.style.display = 'none';
                }
            }
            
            // If the currently selected type is hidden, reset dropdown to empty
            if (!hasMatchingSelected && currentSelectedType !== '') {
                typeSelect.value = '';
            }
        }

        function exportCSV() {
            const form = document.getElementById('filterForm');
            const params = new URLSearchParams(new FormData(form)).toString();
            window.location.href = 'api/actions.php?action=export_records&' + params;
        }

        async function deleteRecord(id) {
            const form = new FormData();
            form.append('id', id);
            const res = await fetch('api/actions.php?action=delete_record', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('ข้อผิดพลาด: ' + data.error);
            }
        }

        // Run once on page load to apply initial state
        document.addEventListener('DOMContentLoaded', filterTypesByCategory);
    </script>
</body>
</html>
