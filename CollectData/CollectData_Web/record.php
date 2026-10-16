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

$codeLower = strtolower($record['fall_type_code'] ?? '');
$durationSec = 12;
if ($record['category'] === 'fall' || strpos($codeLower, 'fall_') === 0) {
    $durationSec = 15;
} elseif (in_array($codeLower, ['d05', 'd06', 'd07', 'd08', 'd17', 'adl_stairs', 'adl_stairs_up', 'adl_stairs_down'], true)) {
    $durationSec = 25;
} elseif (in_array($codeLower, ['d01', 'd02', 'd03', 'd04', 'adl_walk', 'adl_jog'], true)) {
    $durationSec = 15;
}

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
    <title>บันทึก #<?= $id ?> — <?= htmlspecialchars($record['fall_type_name']) ?> (<?= $durationSec ?> วินาที)</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
    <nav class="navbar">
        <a href="index.php" class="logo">📊 CollectData</a>
        <a href="index.php">ผู้ทดสอบ</a>
        <a href="cd_trial.php">ข้อมูลการทดลอง</a>
        <a href="devices.php">อุปกรณ์</a>
        <div class="theme-switcher">
            <label for="themeSelect">ธีม</label>
            <select id="themeSelect" aria-label="เลือกธีมสี">
                <option value="dark">เข้ม</option>
                <option value="light">สว่าง</option>
                <option value="forest">เขียว</option>
                <option value="rose">ชมพู</option>
            </select>
        </div>
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
                    <?= htmlspecialchars($record['fall_type_name']) ?> (<?= $durationSec ?> วินาที) — รอบ #<?= $record['trial_number'] ?>
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
                <h3 style="color:#000000">📊 ความเร่ง (Accelerometer) — แกน X, Y, Z, G</h3>
                <canvas id="accelChart" height="200"></canvas>
            </div>

            <!-- กราฟไจโรสโคป -->
            <div class="chart-container">
                <h3 style="color:#000000">🔄 ไจโรสโคป (Gyroscope) — แกน X, Y, Z</h3>
                <canvas id="gyroChart" height="200"></canvas>
            </div>

            <!-- กราฟขนาดเวกเตอร์ -->
            <div class="chart-container">
                <h3 style="color:#000000">💠 ขนาดเวกเตอร์ (Magnitude)</h3>
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
                        const pointInterval = Math.max(1, Math.ceil(d.length / 24));
                        const chartStyles = [
                            { borderDash: [], pointStyle: 'circle' },
                            { borderDash: [8, 4], pointStyle: 'rectRot' },
                            { borderDash: [2, 4], pointStyle: 'triangle' },
                            { borderDash: [10, 3, 2, 3], pointStyle: 'star' }
                        ];
                        const chartTextColor = '#000000';
                        const chartFontFamily = "'Noto Sans Thai', 'Segoe UI', sans-serif";
                        const chartLegendFont = { family: chartFontFamily, size: 11, weight: '700' };
                        const chartTickFont = { family: chartFontFamily, size: 10, weight: '700' };

                        Chart.defaults.color = chartTextColor;
                        Chart.defaults.font.family = chartFontFamily;
                        Chart.defaults.font.weight = '700';

                        const makeDataset = (label, values, borderColor, styleIndex, extra = {}) => ({
                            label,
                            data: values,
                            borderColor,
                            backgroundColor: borderColor,
                            pointBorderColor: borderColor,
                            pointBackgroundColor: borderColor,
                            borderDash: chartStyles[styleIndex].borderDash,
                            pointStyle: chartStyles[styleIndex].pointStyle,
                            pointRadius: ctx => ctx.dataIndex % pointInterval === 0 ? 3 : 0,
                            pointHoverRadius: 5,
                            pointHitRadius: 8,
                            tension: 0.12,
                            fill: false,
                            ...extra
                        });

                        const chartOptions = {
                            responsive: true,
                            animation: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: {
                                    labels: {
                                        color: chartTextColor,
                                        font: chartLegendFont,
                                        usePointStyle: true,
                                        pointStyleWidth: 12
                                    }
                                },
                                tooltip: {
                                    enabled: true,
                                    titleColor: chartTextColor,
                                    bodyColor: chartTextColor,
                                    footerColor: chartTextColor,
                                    titleFont: chartLegendFont,
                                    bodyFont: chartLegendFont,
                                    footerFont: chartLegendFont
                                }
                            },
                            scales: {
                                x: {
                                    ticks: { color: chartTextColor, maxTicksLimit: 15, font: chartTickFont },
                                    grid: { color: '#2a2a4a' },
                                    title: { display: true, text: 'เวลา (วินาที)', color: chartTextColor, font: chartLegendFont }
                                },
                                y: {
                                    ticks: { color: chartTextColor, font: chartTickFont },
                                    grid: { color: '#2a2a4a' }
                                }
                            },
                            elements: { line: { borderWidth: 1.8 } }
                        };
                        const gValues = d.map(p => {
                            const ax = Number(p.accel_x);
                            const ay = Number(p.accel_y);
                            const az = Number(p.accel_z);
                            return Math.sqrt(ax * ax + ay * ay + az * az) / 9.81;
                        });

                        // กราฟความเร่ง
                        new Chart(document.getElementById('accelChart'), {
                            type: 'line',
                            data: {
                                labels,
                                datasets: [
                                    makeDataset('Accel X (m/s²)', d.map(p => Number(p.accel_x)), '#ff6384', 0),
                                    makeDataset('Accel Y (m/s²)', d.map(p => Number(p.accel_y)), '#36a2eb', 1),
                                    makeDataset('Accel Z (m/s²)', d.map(p => Number(p.accel_z)), '#ffce56', 2),
                                    makeDataset('G', gValues, '#000000', 3)
                                ]
                            },
                            options: { ...chartOptions, scales: { ...chartOptions.scales, y: { ...chartOptions.scales.y, title: { display: true, text: 'm/s² / G', color: chartTextColor, font: chartLegendFont } } } }
                        });

                        // กราฟไจโรสโคป
                        new Chart(document.getElementById('gyroChart'), {
                            type: 'line',
                            data: {
                                labels,
                                datasets: [
                                    makeDataset('Gyro X (°/s)', d.map(p => Number(p.gyro_x)), '#ff9f40', 0),
                                    makeDataset('Gyro Y (°/s)', d.map(p => Number(p.gyro_y)), '#4bc0c0', 1),
                                    makeDataset('Gyro Z (°/s)', d.map(p => Number(p.gyro_z)), '#9966ff', 2)
                                ]
                            },
                            options: { ...chartOptions, scales: { ...chartOptions.scales, y: { ...chartOptions.scales.y, title: { display: true, text: '°/s', color: chartTextColor, font: chartLegendFont } } } }
                        });

                        // กราฟขนาดเวกเตอร์
                        const accelMag = d.map(p => Math.sqrt(p.accel_x**2 + p.accel_y**2 + p.accel_z**2));
                        const gyroMag = d.map(p => Math.sqrt(p.gyro_x**2 + p.gyro_y**2 + p.gyro_z**2));

                        new Chart(document.getElementById('magChart'), {
                            type: 'line',
                            data: {
                                labels,
                                datasets: [
                                    makeDataset('Accel Magnitude', accelMag, '#00e676', 0),
                                    makeDataset('Gyro Magnitude', gyroMag, '#ea80fc', 1, { yAxisID: 'y1' })
                                ]
                            },
                            options: {
                                ...chartOptions,
                                scales: {
                                    ...chartOptions.scales,
                                    y: { ...chartOptions.scales.y, title: { display: true, text: 'm/s²', color: chartTextColor, font: chartLegendFont }, position: 'left' },
                                    y1: { ticks: { color: chartTextColor, font: chartTickFont }, grid: { drawOnChartArea: false }, title: { display: true, text: '°/s', color: chartTextColor, font: chartLegendFont }, position: 'right' }
                                }
                            }
                        });
                    });
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
