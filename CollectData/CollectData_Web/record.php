<?php
/**
 * หน้าบันทึก — กราฟเส้นความเร่ง + ไจโรสโคป XYZ
 * URL: record.php?id=1
 */
require_once 'config.php';
$pdo = getDB();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: index.php'); exit; }

// ดึงข้อมูลบันทึก
$record = $pdo->prepare("
    SELECT r.*, ft.name_th as fall_type_name, ft.code as fall_type_code, ft.category,
           d.name as device_name, s.name as subject_name, s.id as subject_id
    FROM cd_records r
    JOIN cd_fall_types ft ON r.fall_type_id = ft.id
    JOIN cd_devices d ON r.device_id = d.id
    JOIN cd_subjects s ON r.subject_id = s.id
    WHERE r.id = ?
");
$record->execute([$id]);
$record = $record->fetch();
if (!$record) { header('Location: index.php'); exit; }

// นับจุดข้อมูล
$dataCount = $pdo->prepare("SELECT COUNT(*) as cnt FROM cd_sensor_data WHERE record_id = ?");
$dataCount->execute([$id]);
$dataCount = $dataCount->fetch()['cnt'];

// ดึงบันทึกอื่นของผู้ทดสอบเดียวกัน ประเภทเดียวกัน
$siblings = $pdo->prepare("
    SELECT r.id, r.trial_number, (SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = r.id) as data_points
    FROM cd_records r
    WHERE r.subject_id = ? AND r.fall_type_id = ?
    ORDER BY r.trial_number
");
$siblings->execute([$record['subject_id'], $record['fall_type_id']]);
$siblings = $siblings->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บันทึก #<?= $id ?> — <?= htmlspecialchars($record['fall_type_name']) ?></title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
    <nav class="navbar">
        <a href="index.php" class="logo">📊 CollectData</a>
        <a href="index.php">ผู้ทดสอบ</a>
        <a href="cd_trial.php">ข้อมูลการทดลอง</a>
        <a href="devices.php">อุปกรณ์</a>
    </nav>

    <div class="container">
        <div class="breadcrumb">
            <a href="index.php">หน้าหลัก</a> →
            <a href="subject.php?id=<?= $record['subject_id'] ?>">บุคคลที่ <?= $record['subject_id'] ?></a> →
            บันทึก #<?= $id ?>
        </div>

        <!-- ข้อมูลบันทึก -->
        <div class="card">
            <div class="card-header">
                <h2>
                    <span class="badge badge-<?= $record['category'] ?>">
                        <?= $record['category']=='fall'?'การล้ม':($record['category']=='adl'?'ADL':'อื่นๆ') ?>
                    </span>
                    <?= htmlspecialchars($record['fall_type_name']) ?> — รอบ #<?= $record['trial_number'] ?>
                </h2>
            </div>
            <div style="display:flex;gap:24px;flex-wrap:wrap;font-size:13px;color:var(--text-secondary)">
                <span>👤 <?= htmlspecialchars($record['subject_name']) ?></span>
                <span>📱 <?= htmlspecialchars($record['device_name']) ?></span>
                <span>📈 <?= number_format($dataCount) ?> จุดข้อมูล</span>
                <span>📅 <?= date('d/m/Y H:i:s', strtotime($record['created_at'])) ?></span>
            </div>
            <?php if ($record['notes']): ?>
                <div style="margin-top:8px;font-size:13px;padding:8px 12px;background:var(--bg-primary);border-radius:6px">
                    📝 <?= htmlspecialchars($record['notes']) ?>
                </div>
            <?php endif; ?>
            <?php if ($dataCount > 0): ?>
                <div style="margin-top:12px;">
                    <a href="api/actions.php?action=export_sensor_data&record_id=<?= $id ?>" class="btn btn-success">📥 ส่งออกข้อมูลเซ็นเซอร์ (CSV)</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- เลือกดูรอบอื่น -->
        <?php if (count($siblings) > 1): ?>
            <div class="card" style="padding:12px 20px">
                <span style="font-size:13px;color:var(--text-secondary)">รอบทดสอบอื่นของประเภทนี้: </span>
                <?php foreach ($siblings as $s): ?>
                    <a href="record.php?id=<?= $s['id'] ?>"
                       class="btn btn-sm <?= $s['id'] == $id ? 'btn-primary' : 'btn-outline' ?>"
                       style="margin:2px">
                        #<?= $s['trial_number'] ?> (<?= $s['data_points'] ?> จุด)
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($dataCount == 0): ?>
            <div class="empty-state">
                <div class="icon">📈</div>
                <p>ยังไม่มีข้อมูลเซ็นเซอร์ในบันทึกนี้</p>
                <p style="font-size:13px;color:var(--text-secondary)">
                    ให้ ESP32 ส่งข้อมูลไปที่ API:<br>
                    <code style="color:var(--accent)">POST /IMU/CollectData_Web/api/receive_data.php</code><br>
                    พร้อม <code>record_id = <?= $id ?></code>
                </p>
            </div>
        <?php else: ?>
            <!-- กราฟความเร่ง -->
            <div class="chart-container">
                <h3>📊 ความเร่ง (Accelerometer) — แกน X, Y, Z</h3>
                <canvas id="accelChart" height="200"></canvas>
            </div>

            <!-- กราฟไจโรสโคป -->
            <div class="chart-container">
                <h3>🔄 ไจโรสโคป (Gyroscope) — แกน X, Y, Z</h3>
                <canvas id="gyroChart" height="200"></canvas>
            </div>

            <!-- กราฟขนาดเวกเตอร์ -->
            <div class="chart-container">
                <h3>💠 ขนาดเวกเตอร์ (Magnitude)</h3>
                <canvas id="magChart" height="150"></canvas>
            </div>

            <script>
                // โหลดข้อมูลเซ็นเซอร์แล้วสร้างกราฟ
                fetch('api/actions.php?action=get_sensor_data&record_id=<?= $id ?>')
                    .then(r => r.json())
                    .then(res => {
                        if (!res.success || !res.data.length) return;
                        const d = res.data;

                        // แปลง timestamp เป็นวินาที (เริ่มจาก 0)
                        const t0 = d[0].timestamp_ms;
                        const labels = d.map(p => ((p.timestamp_ms - t0) / 1000).toFixed(3));

                        const chartOptions = {
                            responsive: true,
                            animation: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { labels: { color: '#a0a0b0', font: { size: 12 } } },
                                tooltip: { enabled: true }
                            },
                            scales: {
                                x: {
                                    ticks: { color: '#666', maxTicksLimit: 15, font: { size: 10 } },
                                    grid: { color: '#2a2a4a' },
                                    title: { display: true, text: 'เวลา (วินาที)', color: '#888' }
                                },
                                y: {
                                    ticks: { color: '#666', font: { size: 10 } },
                                    grid: { color: '#2a2a4a' }
                                }
                            },
                            elements: { point: { radius: 0 }, line: { borderWidth: 1.5 } }
                        };

                        // กราฟความเร่ง
                        new Chart(document.getElementById('accelChart'), {
                            type: 'line',
                            data: {
                                labels,
                                datasets: [
                                    { label: 'Accel X (m/s²)', data: d.map(p => p.accel_x), borderColor: '#ff6384', backgroundColor: 'rgba(255,99,132,0.1)', fill: false },
                                    { label: 'Accel Y (m/s²)', data: d.map(p => p.accel_y), borderColor: '#36a2eb', backgroundColor: 'rgba(54,162,235,0.1)', fill: false },
                                    { label: 'Accel Z (m/s²)', data: d.map(p => p.accel_z), borderColor: '#ffce56', backgroundColor: 'rgba(255,206,86,0.1)', fill: false }
                                ]
                            },
                            options: { ...chartOptions, scales: { ...chartOptions.scales, y: { ...chartOptions.scales.y, title: { display: true, text: 'm/s²', color: '#888' } } } }
                        });

                        // กราฟไจโรสโคป
                        new Chart(document.getElementById('gyroChart'), {
                            type: 'line',
                            data: {
                                labels,
                                datasets: [
                                    { label: 'Gyro X (°/s)', data: d.map(p => p.gyro_x), borderColor: '#ff9f40', fill: false },
                                    { label: 'Gyro Y (°/s)', data: d.map(p => p.gyro_y), borderColor: '#4bc0c0', fill: false },
                                    { label: 'Gyro Z (°/s)', data: d.map(p => p.gyro_z), borderColor: '#9966ff', fill: false }
                                ]
                            },
                            options: { ...chartOptions, scales: { ...chartOptions.scales, y: { ...chartOptions.scales.y, title: { display: true, text: '°/s', color: '#888' } } } }
                        });

                        // กราฟขนาดเวกเตอร์
                        const accelMag = d.map(p => Math.sqrt(p.accel_x**2 + p.accel_y**2 + p.accel_z**2));
                        const gyroMag = d.map(p => Math.sqrt(p.gyro_x**2 + p.gyro_y**2 + p.gyro_z**2));

                        new Chart(document.getElementById('magChart'), {
                            type: 'line',
                            data: {
                                labels,
                                datasets: [
                                    { label: 'Accel Magnitude', data: accelMag, borderColor: '#00e676', fill: false },
                                    { label: 'Gyro Magnitude', data: gyroMag, borderColor: '#ea80fc', fill: false, yAxisID: 'y1' }
                                ]
                            },
                            options: {
                                ...chartOptions,
                                scales: {
                                    ...chartOptions.scales,
                                    y: { ...chartOptions.scales.y, title: { display: true, text: 'm/s²', color: '#888' }, position: 'left' },
                                    y1: { ticks: { color: '#666', font: { size: 10 } }, grid: { drawOnChartArea: false }, title: { display: true, text: '°/s', color: '#888' }, position: 'right' }
                                }
                            }
                        });
                    });
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
