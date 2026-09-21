<?php
/**
 * หน้าหลัก — รายการผู้ทดสอบ
 * URL: https://www.youngza.com/IMU/CollectData_Web/index.php
 */
require_once 'config.php';
$pdo = getDB();

// ดึงรายการผู้ทดสอบ + สถิติ
$subjects = $pdo->query("
    SELECT s.*,
           (SELECT COUNT(*) FROM cd_records WHERE subject_id = s.id) as record_count,
           (SELECT COUNT(*) FROM cd_records r JOIN cd_fall_types ft ON r.fall_type_id = ft.id 
            WHERE r.subject_id = s.id AND ft.category = 'fall') as fall_count
    FROM cd_subjects s ORDER BY s.id DESC
")->fetchAll();

// นับสถิติรวม
$stats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM cd_subjects) as total_subjects,
        (SELECT COUNT(*) FROM cd_devices) as total_devices,
        (SELECT COUNT(*) FROM cd_records) as total_records,
        (SELECT COUNT(*) FROM cd_sensor_data) as total_data_points
")->fetch();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollectData — ระบบเก็บข้อมูลการทดลอง</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <a href="index.php" class="logo">📊 CollectData</a>
        <a href="index.php" class="active">ผู้ทดสอบ</a>
        <a href="cd_trial.php">ข้อมูลการทดลอง</a>
        <a href="devices.php">อุปกรณ์</a>
    </nav>

    <div class="container">
        <!-- สถิติ -->
        <div class="grid grid-4" style="margin-bottom:24px">
            <div class="stat-card">
                <div class="stat-icon">👤</div>
                <div class="stat-value"><?= $stats['total_subjects'] ?></div>
                <div class="stat-label">ผู้ทดสอบ</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📱</div>
                <div class="stat-value"><?= $stats['total_devices'] ?></div>
                <div class="stat-label">อุปกรณ์</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📝</div>
                <div class="stat-value"><?= $stats['total_records'] ?></div>
                <div class="stat-label">บันทึก</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📈</div>
                <div class="stat-value"><?= number_format($stats['total_data_points']) ?></div>
                <div class="stat-label">จุดข้อมูล</div>
            </div>
        </div>

        <!-- Header -->
        <div class="page-header">
            <h1>👤 รายการผู้ทดสอบ</h1>
            <button class="btn btn-primary" onclick="openModal('addSubjectModal')">+ เพิ่มผู้ทดสอบ</button>
        </div>

        <!-- รายการ -->
        <?php if (empty($subjects)): ?>
            <div class="empty-state">
                <div class="icon">👤</div>
                <p>ยังไม่มีผู้ทดสอบ</p>
                <button class="btn btn-primary" onclick="openModal('addSubjectModal')">+ เพิ่มผู้ทดสอบคนแรก</button>
            </div>
        <?php else: ?>
            <div class="grid grid-2">
                <?php foreach ($subjects as $s): ?>
                    <div class="card" style="cursor:pointer" onclick="location.href='subject.php?id=<?= $s['id'] ?>'">
                        <div class="card-header">
                            <h2>บุคคลที่ <?= $s['id'] ?> — <?= htmlspecialchars($s['name']) ?></h2>
                            <span class="badge badge-recording"><?= $s['record_count'] ?> บันทึก</span>
                        </div>
                        <div style="display:flex;gap:20px;font-size:13px;color:var(--text-secondary)">
                            <?php if ($s['age']): ?><span>อายุ: <?= $s['age'] ?> ปี</span><?php endif; ?>
                            <?php if ($s['height_cm']): ?><span>สูง: <?= $s['height_cm'] ?> ซม.</span><?php endif; ?>
                            <?php if ($s['weight_kg']): ?><span>หนัก: <?= $s['weight_kg'] ?> กก.</span><?php endif; ?>
                            <span>เพศ: <?= $s['gender'] == 'M' ? 'ชาย' : ($s['gender'] == 'F' ? 'หญิง' : 'อื่นๆ') ?></span>
                        </div>
                        <div style="margin-top:10px;font-size:13px">
                            <span class="badge badge-fall">ล้ม <?= $s['fall_count'] ?></span>
                            <span class="badge badge-adl">ADL <?= $s['record_count'] - $s['fall_count'] ?></span>
                        </div>
                        <?php if ($s['notes']): ?>
                            <div style="margin-top:8px;font-size:12px;color:var(--text-secondary)">📝 <?= htmlspecialchars($s['notes']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal เพิ่มผู้ทดสอบ -->
    <div class="modal-overlay" id="addSubjectModal">
        <div class="modal">
            <h2>+ เพิ่มผู้ทดสอบ</h2>
            <form method="POST" action="api/actions.php?action=add_subject" id="addSubjectForm">
                <div class="form-group">
                    <label>ชื่อ-สกุล *</label>
                    <input type="text" name="name" required placeholder="เช่น สมชาย ใจดี">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>อายุ (ปี)</label>
                        <input type="number" name="age" placeholder="65">
                    </div>
                    <div class="form-group">
                        <label>เพศ</label>
                        <select name="gender">
                            <option value="M">ชาย</option>
                            <option value="F">หญิง</option>
                            <option value="O">อื่นๆ</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>ส่วนสูง (ซม.)</label>
                        <input type="number" step="0.1" name="height_cm" placeholder="165">
                    </div>
                    <div class="form-group">
                        <label>น้ำหนัก (กก.)</label>
                        <input type="number" step="0.1" name="weight_kg" placeholder="55">
                    </div>
                </div>
                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="notes" rows="2" placeholder="ข้อมูลเพิ่มเติม..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addSubjectModal')">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">✓ เพิ่มผู้ทดสอบ</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        // Submit form via AJAX -> redirect back
        document.getElementById('addSubjectForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const form = new FormData(this);
            const res = await fetch('api/actions.php?action=add_subject', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('ข้อผิดพลาด: ' + data.error);
            }
        });

        // Close modal on overlay click
        document.querySelectorAll('.modal-overlay').forEach(el => {
            el.addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('active');
            });
        });
    </script>
</body>
</html>
