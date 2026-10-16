<?php
/**
 * หน้าผู้ทดสอบ — บันทึกการล้ม + กราฟแยกประเภท
 * URL: subject.php?id=1
 */
require_once 'config.php';
$pdo = getDB();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: index.php'); exit; }

// ดึงข้อมูลผู้ทดสอบ
$subject = $pdo->prepare("SELECT * FROM cd_subjects WHERE id = ?");
$subject->execute([$id]);
$subject = $subject->fetch();
if (!$subject) { header('Location: index.php'); exit; }

function getActivityDurationSec(string $code, string $category): int {
    $c = strtolower($code);
    if ($category === 'fall' || strpos($c, 'fall_') === 0) {
        return 15;
    }
    if (in_array($c, ['d05', 'd06', 'd07', 'd08', 'd17', 'adl_stairs', 'adl_stairs_up', 'adl_stairs_down'], true)) {
        return 25;
    }
    if (in_array($c, ['d01', 'd02', 'd03', 'd04', 'adl_walk', 'adl_jog'], true)) {
        return 15;
    }
    return 12;
}

$fallTypeSelectGroups = [
    [
        'label' => 'ประเภท 1 การล้ม (Fall)',
        'category' => 'fall',
        'items' => [
            ['code' => 'fall_forward',  'name_th' => 'F01 การล้มไปข้างหน้า',  'name_en' => 'F01 Fall Forward',       'duration_sec' => 15],
            ['code' => 'fall_backward', 'name_th' => 'F02 การล้มไปข้างหลัง',  'name_en' => 'F02 Fall Backward',      'duration_sec' => 15],
            ['code' => 'fall_left',     'name_th' => 'F03 การล้มไปด้านซ้าย',  'name_en' => 'F03 Fall Left',          'duration_sec' => 15],
            ['code' => 'fall_right',    'name_th' => 'F04 การล้มไปด้านขวา',   'name_en' => 'F04 Fall Right',         'duration_sec' => 15],
            ['code' => 'fall_sitting',  'name_th' => 'F05 การล้มจากท่านั่ง',   'name_en' => 'F05 Fall from Sitting',  'duration_sec' => 15],
        ],
    ],
    [
        'label' => 'ประเภท 2 กิจวัตรประจำวัน (ADL)',
        'category' => 'adl',
        'items' => [
            ['code' => 'adl_stand',       'name_th' => 'D01 การยืนปกติ',          'name_en' => 'D01 Standing',              'duration_sec' => 12],
            ['code' => 'adl_sit',         'name_th' => 'D02 การนั่งปกติ',          'name_en' => 'D02 Sitting',               'duration_sec' => 12],
            ['code' => 'adl_walk',        'name_th' => 'D03 การเดินปกติ',         'name_en' => 'D03 Walking',               'duration_sec' => 15],
            ['code' => 'adl_jog',         'name_th' => 'D04 การวิ่งเหยาะ',         'name_en' => 'D04 Jogging',               'duration_sec' => 15],
            ['code' => 'adl_sit_down',    'name_th' => 'D05 การนั่งลงปกติ',        'name_en' => 'D05 Normal Sit Down',       'duration_sec' => 12],
            ['code' => 'adl_chair_up',    'name_th' => 'D06 การลุกขึ้นจากเก้าอี้',  'name_en' => 'D06 Stand Up from Chair',   'duration_sec' => 12],
            ['code' => 'adl_stairs_up',   'name_th' => 'D07 การขึ้นบันได',         'name_en' => 'D07 Upstairs',              'duration_sec' => 25],
            ['code' => 'adl_stairs_down', 'name_th' => 'D08 การลงบันได',          'name_en' => 'D08 Downstairs',            'duration_sec' => 25],
            ['code' => 'adl_pickup',      'name_th' => 'D09 การก้มเก็บของ',        'name_en' => 'D09 Pick Up Object',        'duration_sec' => 12],
            ['code' => 'adl_change_pose', 'name_th' => 'D10 การเปลี่ยนท่าทางปกติ', 'name_en' => 'D10 Normal Posture Change', 'duration_sec' => 12],
        ],
    ],
    [
        'label' => 'ประเภท 3 คล้ายล้มแต่ไม่ล้ม (Near-Fall)',
        'category' => 'other',
        'items' => [
            ['code' => 'impact_forward',  'name_th' => 'N01 การเกือบล้มไปข้างหน้าแต่ทรงตัวได้', 'name_en' => 'N01 Near Forward Fall',          'duration_sec' => 12],
            ['code' => 'impact_backward', 'name_th' => 'N02 การเกือบล้มไปข้างหลังแต่ทรงตัวได้', 'name_en' => 'N02 Near Backward Fall',         'duration_sec' => 12],
            ['code' => 'impact_left',     'name_th' => 'N03 การเกือบล้มไปด้านซ้ายแต่ทรงตัวได้', 'name_en' => 'N03 Near Left Fall',             'duration_sec' => 12],
            ['code' => 'impact_right',    'name_th' => 'N04 การเกือบล้มไปด้านขวาแต่ทรงตัวได้',  'name_en' => 'N04 Near Right Fall',            'duration_sec' => 12],
            ['code' => 'impact_stumble',  'name_th' => 'N05 การสะดุดแต่ไม่ล้ม',                 'name_en' => 'N05 Stumble Without Fall',       'duration_sec' => 12],
            ['code' => 'impact_hard_sit', 'name_th' => 'N06 การนั่งลงแรงแต่ไม่ล้ม',             'name_en' => 'N06 Hard Sit Without Fall',      'duration_sec' => 12],
            ['code' => 'impact_stop',     'name_th' => 'N07 การหยุดเดินหรือวิ่งกะทันหัน',        'name_en' => 'N07 Sudden Stop',                'duration_sec' => 12],
            ['code' => 'impact_device',   'name_th' => 'N08 อุปกรณ์ถูกกระแทกแต่ผู้สวมใส่ไม่ล้ม', 'name_en' => 'N08 Device Impact Without Fall', 'duration_sec' => 12],
        ],
    ],
    [
        'label' => 'ประเภท 4 ทดสอบระบบ (Test / Exclude)',
        'category' => 'other',
        'items' => [
            ['code' => 'exclude_device', 'name_th' => 'T01 การทดสอบอุปกรณ์',                    'name_en' => 'T01 Device Test',         'duration_sec' => 12],
            ['code' => 'exclude_wifi',   'name_th' => 'T02 การทดสอบระบบเครือข่าย (Wi-Fi / Server)', 'name_en' => 'T02 Wi-Fi / Server Test', 'duration_sec' => 12],
            ['code' => 'exclude_sensor', 'name_th' => 'T03 การทดสอบเซนเซอร์',                   'name_en' => 'T03 Sensor Test',         'duration_sec' => 12],
            ['code' => 'exclude_error',  'name_th' => 'T04 ข้อมูลผิดพลาด (ไม่ใช้เทรนโมเดล)',    'name_en' => 'T04 Invalid Data',        'duration_sec' => 12],
        ],
    ],
];

$ensureFallType = $pdo->prepare("
    INSERT INTO cd_fall_types (code, name_th, name_en, category)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        name_th = VALUES(name_th),
        name_en = VALUES(name_en),
        category = VALUES(category)
");
foreach ($fallTypeSelectGroups as $group) {
    foreach ($group['items'] as $item) {
        $ensureFallType->execute([
            $item['code'],
            $item['name_th'],
            $item['name_en'],
            $group['category'],
        ]);
    }
}

// อัปเดตรหัสเก่าในฐานข้อมูล (ถ้ามี) ให้เป็นรูปแบบเดียวกันทั้งหมด
$legacyFallTypes = [
    ['code' => 'fall_lateral',      'name_th' => 'F06 การล้มไปด้านข้าง',  'name_en' => 'F06 Lateral Fall',       'category' => 'fall'],
    ['code' => 'near_fall',         'name_th' => 'N00 การเกือบล้ม',       'name_en' => 'N00 Near Fall',          'category' => 'other'],
    ['code' => 'adl_stairs',        'name_th' => 'D11 การขึ้นลงบันได',     'name_en' => 'D11 Stairs',             'category' => 'adl'],
    ['code' => 'custom_1782373044', 'name_th' => 'T00 การทดสอบอุปกรณ์',   'name_en' => 'T00 Testing the device', 'category' => 'other'],
];
$updLegacyStmt = $pdo->prepare("UPDATE cd_fall_types SET name_th = ?, name_en = ?, category = ? WHERE code = ?");
foreach ($legacyFallTypes as $lf) {
    $updLegacyStmt->execute([$lf['name_th'], $lf['name_en'], $lf['category'], $lf['code']]);
}

// หากมีหลายรายการของผู้ทดสอบนี้ที่ค้างสถานะ recording พร้อมกันแต่ยังไม่มีข้อมูล (0 จุด) จากระบบเก่า ให้รีเซ็ตเป็นรอส่งคำสั่ง
$stuckCheck = $pdo->prepare("
    SELECT id FROM cd_records r
    WHERE r.subject_id = ? AND r.status = 'recording'
      AND (SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = r.id) = 0
");
$stuckCheck->execute([$id]);
$stuckIds = $stuckCheck->fetchAll(PDO::FETCH_COLUMN);
if (count($stuckIds) > 1) {
    $pdo->prepare("
        UPDATE cd_records r
        SET r.status = 'completed'
        WHERE r.subject_id = ? AND r.status = 'recording'
          AND (SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = r.id) = 0
    ")->execute([$id]);
}

// ดึงบันทึกทั้งหมดของผู้ทดสอบ
$records = $pdo->prepare("
    SELECT r.*, ft.name_th as fall_type_name, ft.code as fall_type_code, ft.category,
           d.name as device_name,
           t.trial_label as trial_label,
           (SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = r.id) as data_points
    FROM cd_records r
    JOIN cd_fall_types ft ON r.fall_type_id = ft.id
    JOIN cd_devices d ON r.device_id = d.id
    LEFT JOIN cd_trial t ON r.trial_id = t.id
    WHERE r.subject_id = ?
    ORDER BY ft.category, ft.id, r.trial_number
");
$records->execute([$id]);
$records = $records->fetchAll();

// จัดกลุ่มตามประเภท
$grouped = [];
foreach ($records as $r) {
    $key = $r['fall_type_code'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'name' => $r['fall_type_name'],
            'category' => $r['category'],
            'duration_sec' => getActivityDurationSec($r['fall_type_code'], $r['category']),
            'records' => []
        ];
    }
    $grouped[$key]['records'][] = $r;
}

// ดึงรายการอุปกรณ์ + ประเภทการล้ม + รอบทดลอง
$devices = $pdo->query("SELECT * FROM cd_devices ORDER BY name")->fetchAll();
$fallTypeRows = $pdo->query("SELECT * FROM cd_fall_types")->fetchAll();
$fallTypesByCode = [];
foreach ($fallTypeRows as $ft) {
    $fallTypesByCode[$ft['code']] = $ft;
}
$trials = $pdo->prepare("SELECT * FROM cd_trial WHERE subject_id = ? ORDER BY id DESC");
$trials->execute([$id]);
$trials = $trials->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บุคคลที่ <?= $id ?> — <?= htmlspecialchars($subject['name']) ?></title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/app.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <style>
        .record-row { cursor: pointer; }
        .record-row:hover { background: rgba(0,210,255,0.08); }
        .record-row.selected-record { background: rgba(0,210,255,0.14); outline: 1px solid var(--accent); }
        .chart-status {
            color: var(--text-secondary);
            font-size: 13px;
            margin: 8px 0 0;
        }
        @keyframes pulseWait {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.55; }
        }
        .pulse-wait {
            animation: pulseWait 1.5s infinite ease-in-out;
        }
    </style>
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
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="index.php">หน้าหลัก</a> → บุคคลที่ <?= $id ?>
        </div>

        <!-- ข้อมูลผู้ทดสอบ -->
        <div class="page-header">
            <div>
                <h1>👤 บุคคลที่ <?= $id ?> — <?= htmlspecialchars($subject['name']) ?></h1>
                <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;display:flex;gap:16px">
                    <?php if($subject['age']): ?><span>อายุ: <?= $subject['age'] ?> ปี</span><?php endif; ?>
                    <?php if($subject['height_cm']): ?><span>สูง: <?= $subject['height_cm'] ?> ซม.</span><?php endif; ?>
                    <?php if($subject['weight_kg']): ?><span>หนัก: <?= $subject['weight_kg'] ?> กก.</span><?php endif; ?>
                    <span>เพศ: <?= $subject['gender']=='M'?'ชาย':($subject['gender']=='F'?'หญิง':'อื่นๆ') ?></span>
                </div>
            </div>
            <div style="display:flex;gap:8px">
                <button class="btn btn-primary" onclick="openModal('addRecordModal')">+ เพิ่มบันทึก</button>
                <button class="btn btn-danger btn-sm" onclick="confirmDeleteSubject(<?= $id ?>)">🗑️ ลบ</button>
            </div>
        </div>

        <!-- สถิติ -->
        <div class="grid grid-3" style="margin-bottom:24px">
            <div class="stat-card">
                <div class="stat-value"><?= count($records) ?></div>
                <div class="stat-label">บันทึกทั้งหมด</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_filter($records, fn($r) => $r['category'] == 'fall')) ?></div>
                <div class="stat-label">บันทึกการล้ม</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_filter($records, fn($r) => $r['category'] == 'adl')) ?></div>
                <div class="stat-label">บันทึก ADL</div>
            </div>
        </div>

        <?php if (empty($records)): ?>
            <div class="empty-state">
                <div class="icon">📝</div>
                <p>ยังไม่มีบันทึกสำหรับผู้ทดสอบนี้</p>
                <button class="btn btn-primary" onclick="openModal('addRecordModal')">+ เพิ่มบันทึกแรก</button>
            </div>
        <?php else: ?>
            <!-- บันทึกแยกตามประเภท -->
            <?php foreach ($grouped as $code => $group): ?>
                <div class="card">
                    <div class="card-header">
                        <h2>
                            <span class="badge badge-<?= $group['category'] ?>"><?= $group['category'] == 'fall' ? 'การล้ม' : ($group['category'] == 'adl' ? 'ADL' : 'อื่นๆ') ?></span>
                            <?= htmlspecialchars($group['name']) ?> (<?= (int)$group['duration_sec'] ?> วินาที)
                        </h2>
                        <span style="font-size:13px;color:var(--text-secondary)"><?= count($group['records']) ?> รายการ</span>
                    </div>

                    <?php
                    $chartKey = preg_replace('/[^A-Za-z0-9_-]/', '_', $code);
                    $initialRecord = $group['records'][0];
                    foreach ($group['records'] as $candidateRecord) {
                        if ((int)$candidateRecord['data_points'] > 0) {
                            $initialRecord = $candidateRecord;
                            break;
                        }
                    }
                    $initialRecordTitle = 'รอบ #' . $initialRecord['trial_number'];
                    if ($initialRecord['trial_label']) {
                        $initialRecordTitle .= ' - ' . $initialRecord['trial_label'];
                    }
                    ?>

                    <!-- ตาราง -->
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>ลำดับ</th>
                                    <th>อุปกรณ์</th>
                                    <th>รอบทดสอบ</th>
                                    <th>จุดข้อมูล</th>
                                    <th>หมายเหตุ</th>
                                    <th>วันที่</th>
                                    <th>การดำเนินการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($group['records'] as $r): ?>
                                    <?php
                                    $recordTitle = 'รอบ #' . $r['trial_number'];
                                    if ($r['trial_label']) {
                                        $recordTitle .= ' - ' . $r['trial_label'];
                                    }
                                    $pts = (int)$r['data_points'];
                                    $isWaitingEsp = ($r['status'] === 'recording');
                                    $isDone = ($r['status'] === 'completed' && $pts > 0);
                                    $isCancelled = ($r['status'] === 'cancelled');
                                    ?>
                                    <tr class="record-row<?= ((int)$r['id'] === (int)$initialRecord['id']) ? ' selected-record' : '' ?>"
                                        data-chart-key="<?= htmlspecialchars($chartKey) ?>"
                                        data-record-id="<?= (int)$r['id'] ?>"
                                        data-record-title="<?= htmlspecialchars($recordTitle) ?>"
                                        data-record-status="<?= htmlspecialchars($r['status']) ?>"
                                        data-record-points="<?= $pts ?>">
                                        <td>#<?= $r['trial_number'] ?></td>
                                        <td><?= htmlspecialchars($r['device_name']) ?></td>
                                        <td>
                                            <?php if ($r['trial_label']): ?>
                                                <span class="badge badge-recording"><?= htmlspecialchars($r['trial_label']) ?></span>
                                            <?php else: ?>
                                                <span style="color:var(--text-secondary);font-size:12px">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td id="pts_cell_<?= (int)$r['id'] ?>"><?= number_format($pts) ?></td>
                                        <td style="font-size:12px;color:var(--text-secondary)"><?= htmlspecialchars($r['notes'] ?? '-') ?></td>
                                        <td style="font-size:12px"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                                        <td id="action_cell_<?= (int)$r['id'] ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                            <?php if ($isWaitingEsp): ?>
                                                <span class="badge badge-recording pulse-wait" id="status_badge_<?= (int)$r['id'] ?>">
                                                    <?= $pts > 0 ? '📥 กำลังรับข้อมูล (' . number_format($pts) . ' จุด)...' : '⏳ รอ ESP32 ส่งข้อมูล...' ?>
                                                </span>
                                                <button class="btn btn-outline btn-sm" onclick="cancelCommand(<?= (int)$r['id'] ?>)" title="ยกเลิกการรอรับข้อมูลจาก ESP32">✘ ยกเลิก</button>
                                            <?php elseif ($isDone): ?>
                                                <span class="badge badge-adl" id="status_badge_<?= (int)$r['id'] ?>">✔ เสร็จสิ้น</span>
                                                <button class="btn btn-outline btn-sm" onclick="sendCommand(<?= (int)$r['id'] ?>, true)" title="ส่งคำสั่งบันทึกใหม่ไปที่ ESP32">📡 ส่งคำสั่งซ้ำ</button>
                                            <?php elseif ($isCancelled): ?>
                                                <span class="badge badge-fall" id="status_badge_<?= (int)$r['id'] ?>">✘ ยกเลิก</span>
                                                <button class="btn btn-primary btn-sm" onclick="sendCommand(<?= (int)$r['id'] ?>, false)" title="ส่งคำสั่งเริ่มบันทึกไปที่ ESP32">📡 ส่งคำสั่ง</button>
                                            <?php else: ?>
                                                <span class="badge badge-other" id="status_badge_<?= (int)$r['id'] ?>">⚪ รอส่งคำสั่ง</span>
                                                <button class="btn btn-primary btn-sm" onclick="sendCommand(<?= (int)$r['id'] ?>, false)" title="ส่งคำสั่งเริ่มบันทึกไปที่ ESP32">📡 ส่งคำสั่ง</button>
                                            <?php endif; ?>
                                            <a href="record.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm" title="ดูกราฟละเอียด">📈</a>
                                            <button class="btn btn-danger btn-sm" onclick="confirmDeleteRecord(<?= (int)$r['id'] ?>)" title="ลบบันทึก">🗑️</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- กราฟ Preview: แสดงกราฟตามรายการที่เลือก (ถ้ามีข้อมูล) -->
                    <?php 
                    $firstRecord = $initialRecord;
                    if ((int)$firstRecord['data_points'] > 0):
                    ?>
                        <div style="margin-top:16px">
                            <div class="chart-container">
                                <h3 id="accel_title_<?= htmlspecialchars($chartKey) ?>" style="color:#000000">ตัวอย่างกราฟ — รอบ #<?= $firstRecord['trial_number'] ?> (ความเร่ง XYZ)</h3>
                                <canvas id="accel_<?= htmlspecialchars($chartKey) ?>" height="120"></canvas>
                            </div>
                            <div class="chart-container">
                                <h3 id="gyro_title_<?= htmlspecialchars($chartKey) ?>" style="color:#000000">ตัวอย่างกราฟ — รอบ #<?= $firstRecord['trial_number'] ?> (ไจโรสโคป XYZ)</h3>
                                <canvas id="gyro_<?= htmlspecialchars($chartKey) ?>" height="120"></canvas>
                            </div>
                        </div>
                        <script>
                            window.addEventListener('load', () => {
                                loadSelectedRecordCharts(
                                    '<?= $chartKey ?>',
                                    '<?= (int)$firstRecord['id'] ?>',
                                    <?= json_encode($initialRecordTitle, JSON_UNESCAPED_UNICODE) ?>
                                );
                            });
                        </script>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Modal เพิ่มบันทึก -->
    <div class="modal-overlay" id="addRecordModal">
        <div class="modal">
            <h2>+ เพิ่มบันทึกข้อมูล</h2>
            <form id="addRecordForm">
                <input type="hidden" name="subject_id" value="<?= $id ?>">

                <div class="form-group">
                    <label>ประเภทท่าทาง *</label>
                    <select name="fall_type_id" id="fallTypeSelect" required>
                        <?php foreach ($fallTypeSelectGroups as $group): ?>
                            <optgroup label="<?= htmlspecialchars($group['label']) ?>">
                                <?php foreach ($group['items'] as $item): ?>
                                    <?php if (isset($fallTypesByCode[$item['code']])): $ft = $fallTypesByCode[$item['code']]; ?>
                                        <option value="<?= $ft['id'] ?>"><?= htmlspecialchars($ft['name_th']) ?> (<?= (int)$item['duration_sec'] ?> วินาที)</option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>อุปกรณ์ *</label>
                    <select name="device_id" required>
                        <?php if (empty($devices)): ?>
                            <option value="" disabled>— ยังไม่มีอุปกรณ์ กรุณาเพิ่มที่หน้าอุปกรณ์ —</option>
                        <?php endif; ?>
                        <?php foreach ($devices as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>รอบทดสอบ (Trial)</label>
                    <select name="trial_id">
                        <option value="">— ไม่ระบุ —</option>
                        <?php foreach ($trials as $tr): ?>
                            <option value="<?= $tr['id'] ?>"><?= htmlspecialchars($tr['trial_label'] ?: 'Trial #' . $tr['id']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>ลำดับในรอบ</label>
                        <input type="number" name="trial_number" value="1" min="1">
                    </div>
                    <div class="form-group">
                        <label>การดำเนินการเริ่มต้น</label>
                        <select name="status">
                            <option value="pending" selected>⚪ รอส่งคำสั่ง (กดปุ่มส่งคำสั่งในตาราง)</option>
                            <option value="recording">📡 ส่งคำสั่งไปยัง ESP32 ทันที</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="notes" rows="2" placeholder="บันทึกเพิ่มเติม..."></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addRecordModal')">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">✓ เพิ่มบันทึก</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) {
            const modal = document.getElementById(id);
            modal.classList.add('active');
            if (id === 'addRecordModal') {
                const statusSelect = modal.querySelector('select[name="status"]');
                if (statusSelect) statusSelect.value = 'pending';
            }
        }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        const selectedRecordDataCache = new Map();
        const selectedRecordRequestToken = new Map();
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

        if (window.Chart) {
            Chart.defaults.color = chartTextColor;
            Chart.defaults.font.family = chartFontFamily;
            Chart.defaults.font.weight = '700';
        }

        function clearChartCanvas(canvas) {
            if (!canvas) return;
            if (!window.Chart) return;
            const existingChart = Chart.getChart(canvas);
            if (existingChart) existingChart.destroy();
            canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
        }

        function getChartStatusElement(chartKey) {
            let status = document.getElementById('chart_status_' + chartKey);
            if (status) return status;

            const gyroCanvas = document.getElementById('gyro_' + chartKey);
            status = document.createElement('p');
            status.id = 'chart_status_' + chartKey;
            status.className = 'chart-status';
            status.hidden = true;
            if (gyroCanvas) gyroCanvas.insertAdjacentElement('afterend', status);
            return status;
        }

        function makeSelectedDataset(label, values, borderColor, styleIndex, pointInterval) {
            return {
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
                tension: 0.12
            };
        }

        function createSelectedChart(canvas, labels, datasets) {
            if (!window.Chart) return null;
            clearChartCanvas(canvas);
            return new Chart(canvas, {
                type: 'line',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    animation: false,
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
                            titleColor: chartTextColor,
                            bodyColor: chartTextColor,
                            footerColor: chartTextColor,
                            titleFont: chartLegendFont,
                            bodyFont: chartLegendFont,
                            footerFont: chartLegendFont
                        }
                    },
                    scales: {
                        x: { display: true, ticks: { color: chartTextColor, maxTicksLimit: 10, font: chartTickFont }, grid: { color: '#2a2a4a' }, title: { display: true, text: 'เวลา (วินาที)', color: chartTextColor, font: chartLegendFont } },
                        y: { ticks: { color: chartTextColor, font: chartTickFont }, grid: { color: '#2a2a4a' } }
                    },
                    elements: { line: { borderWidth: 1.8 } }
                }
            });
        }

        async function loadSelectedRecordCharts(chartKey, recordId, recordTitle) {
            const accelCanvas = document.getElementById('accel_' + chartKey);
            const gyroCanvas = document.getElementById('gyro_' + chartKey);
            if (!accelCanvas || !gyroCanvas) return;

            const accelTitle = document.getElementById('accel_title_' + chartKey);
            const gyroTitle = document.getElementById('gyro_title_' + chartKey);
            const status = getChartStatusElement(chartKey);
            const token = Symbol(recordId);
            selectedRecordRequestToken.set(chartKey, token);

            if (accelTitle) accelTitle.textContent = 'ตัวอย่างกราฟ — ' + recordTitle + ' (ความเร่ง XYZ)';
            if (gyroTitle) gyroTitle.textContent = 'ตัวอย่างกราฟ — ' + recordTitle + ' (ไจโรสโคป XYZ)';
            if (!window.Chart) {
                status.textContent = 'ไม่สามารถโหลดไลบรารีกราฟได้';
                status.hidden = false;
                return;
            }
            status.textContent = 'กำลังโหลดข้อมูลกราฟ...';
            status.hidden = false;

            try {
                if (!selectedRecordDataCache.has(recordId)) {
                    const response = await fetch('api/actions.php?action=get_sensor_data&record_id=' + encodeURIComponent(recordId));
                    selectedRecordDataCache.set(recordId, await response.json());
                }
                if (selectedRecordRequestToken.get(chartKey) !== token) return;

                const result = selectedRecordDataCache.get(recordId);
                if (!result.success || !result.data.length) {
                    clearChartCanvas(accelCanvas);
                    clearChartCanvas(gyroCanvas);
                    status.textContent = 'ไม่มีข้อมูลเซ็นเซอร์สำหรับรายการนี้';
                    status.hidden = false;
                    return;
                }

                const d = result.data;
                const labels = d.map(p => (Number(p.timestamp_ms) / 1000).toFixed(2));
                const pointInterval = Math.max(1, Math.ceil(d.length / 24));
                const gValues = d.map(p => {
                    const ax = Number(p.accel_x);
                    const ay = Number(p.accel_y);
                    const az = Number(p.accel_z);
                    return Math.sqrt(ax * ax + ay * ay + az * az) / 9.81;
                });

                createSelectedChart(accelCanvas, labels, [
                    makeSelectedDataset('Accel X', d.map(p => Number(p.accel_x)), '#ff6384', 0, pointInterval),
                    makeSelectedDataset('Accel Y', d.map(p => Number(p.accel_y)), '#36a2eb', 1, pointInterval),
                    makeSelectedDataset('Accel Z', d.map(p => Number(p.accel_z)), '#ffce56', 2, pointInterval),
                    makeSelectedDataset('G', gValues, '#000000', 3, pointInterval)
                ]);
                createSelectedChart(gyroCanvas, labels, [
                    makeSelectedDataset('Gyro X', d.map(p => Number(p.gyro_x)), '#ff9f40', 0, pointInterval),
                    makeSelectedDataset('Gyro Y', d.map(p => Number(p.gyro_y)), '#4bc0c0', 1, pointInterval),
                    makeSelectedDataset('Gyro Z', d.map(p => Number(p.gyro_z)), '#9966ff', 2, pointInterval)
                ]);
                status.hidden = true;
            } catch (error) {
                if (selectedRecordRequestToken.get(chartKey) !== token) return;
                clearChartCanvas(accelCanvas);
                clearChartCanvas(gyroCanvas);
                status.textContent = 'ไม่สามารถโหลดข้อมูลกราฟได้';
                status.hidden = false;
            }
        }

        document.querySelectorAll('.record-row').forEach(row => {
            row.addEventListener('click', event => {
                if (event.target.closest('a, button')) return;

                const chartKey = row.dataset.chartKey;
                document.querySelectorAll('.record-row').forEach(otherRow => {
                    if (otherRow.dataset.chartKey === chartKey) {
                        otherRow.classList.toggle('selected-record', otherRow === row);
                    }
                });
                loadSelectedRecordCharts(chartKey, row.dataset.recordId, row.dataset.recordTitle);
            });
        });

        // Submit เพิ่มบันทึก
        document.getElementById('addRecordForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const form = new FormData(this);

            const res = await fetch('api/actions.php?action=add_record', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                showError('ข้อผิดพลาด: ' + data.error);
            }
        });

        async function sendCommand(id, hasExistingData = false) {
            if (hasExistingData) {
                const confirmed = await confirmAction({
                    title: 'ส่งคำสั่งบันทึกซ้ำ?',
                    text: 'ข้อมูลเซ็นเซอร์เดิมของรอบนี้จะถูกล้าง เพื่อรอรับข้อมูลชุดใหม่จาก ESP32',
                    confirmButtonText: '📡 ส่งคำสั่ง'
                });
                if (!confirmed) return;
            }
            const form = new FormData();
            form.append('id', id);
            const res = await fetch('api/actions.php?action=send_command', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                showError('ข้อผิดพลาด: ' + (data.error || 'ไม่สามารถส่งคำสั่งได้'));
            }
        }

        async function cancelCommand(id) {
            const form = new FormData();
            form.append('id', id);
            const res = await fetch('api/actions.php?action=cancel_command', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                showError('ข้อผิดพลาด: ' + (data.error || 'ไม่สามารถยกเลิกคำสั่งได้'));
            }
        }

        // ระบบตรวจสอบสถานะอัตโนมัติเมื่ออยู่ในโหมด "รอ ESP32 ส่งข้อมูล..."
        (function startEsp32Polling() {
            const waitingRows = Array.from(document.querySelectorAll('.record-row[data-record-status="recording"]'));
            if (waitingRows.length === 0) return;

            const waitingIds = new Set(waitingRows.map(r => Number(r.dataset.recordId)));
            const prevPointsMap = new Map();
            const idleTicksMap = new Map();
            let finalizeTargetId = 0;

            waitingRows.forEach(r => {
                const rid = Number(r.dataset.recordId);
                prevPointsMap.set(rid, Number(r.dataset.recordPoints || 0));
                idleTicksMap.set(rid, 0);
            });

            const pollTimer = setInterval(async () => {
                try {
                    let url = 'api/actions.php?action=check_recording_status&subject_id=<?= (int)$id ?>';
                    if (finalizeTargetId > 0) {
                        url += '&finalize_id=' + encodeURIComponent(finalizeTargetId);
                        finalizeTargetId = 0;
                    }
                    const res = await fetch(url, { cache: 'no-store' });
                    const data = await res.json();
                    if (!data.success || !Array.isArray(data.records)) return;

                    for (const rec of data.records) {
                        const rid = Number(rec.id);
                        if (!waitingIds.has(rid)) continue;

                        const pts = Number(rec.data_points || 0);
                        const ptsCell = document.getElementById('pts_cell_' + rid);
                        if (ptsCell) ptsCell.textContent = pts.toLocaleString();

                        // กรณีที่ 1: ESP32 ส่งข้อมูลครบและเปลี่ยนสถานะเป็น completed แล้ว
                        if (rec.status === 'completed' && pts > 0) {
                            clearInterval(pollTimer);
                            if (window.Swal) {
                                await Swal.fire({
                                    icon: 'success',
                                    title: 'เสร็จสิ้น!',
                                    text: `ได้รับข้อมูลจาก ESP32 จำนวน ${pts.toLocaleString()} จุดข้อมูลเรียบร้อยแล้ว`,
                                    timer: 1800,
                                    showConfirmButton: false
                                });
                            }
                            location.reload();
                            return;
                        }

                        // กรณีที่ 2: กำลังทยอยรับข้อมูลจาก ESP32 (หรือเฟิร์มแวร์รุ่นที่ไม่ส่ง is_final)
                        if (rec.status === 'recording' && pts > 0) {
                            const badge = document.getElementById('status_badge_' + rid);
                            if (badge) {
                                badge.textContent = `📥 กำลังรับข้อมูล (${pts.toLocaleString()} จุด)...`;
                            }
                            const prevPts = prevPointsMap.get(rid) || 0;
                            if (pts > prevPts) {
                                prevPointsMap.set(rid, pts);
                                idleTicksMap.set(rid, 0);
                            } else {
                                const ticks = (idleTicksMap.get(rid) || 0) + 1;
                                idleTicksMap.set(rid, ticks);
                                if (ticks >= 2) {
                                    // ข้อมูลนิ่งครบ 2 รอบการเช็ค (~4 วินาที) ให้เปลี่ยนสถานะเป็นเสร็จสิ้นอัตโนมัติ
                                    finalizeTargetId = rid;
                                }
                            }
                        }
                    }
                } catch (err) {
                    console.warn('Polling error:', err);
                }
            }, 2000);
        })();

        async function confirmDeleteRecord(id) {
            const confirmed = await confirmAction({
                title: 'ลบบันทึกนี้?',
                text: 'ข้อมูลบันทึกและข้อมูลเซ็นเซอร์ที่เกี่ยวข้องจะถูกลบด้วย',
                confirmButtonText: 'ลบ'
            });
            if (confirmed) deleteRecord(id);
        }

        async function deleteRecord(id) {
            const form = new FormData();
            form.append('id', id);
            const res = await fetch('api/actions.php?action=delete_record', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                showError('ข้อผิดพลาด: ' + data.error);
            }
        }

        async function confirmDeleteSubject(id) {
            const confirmed = await confirmAction({
                title: 'ลบผู้ทดสอบนี้?',
                text: 'ข้อมูลผู้ทดสอบและบันทึกที่เกี่ยวข้องจะถูกลบออกจากระบบ',
                confirmButtonText: 'ลบ'
            });
            if (confirmed) deleteSubject(id);
        }

        async function deleteSubject(id) {
            const form = new FormData();
            form.append('id', id);
            const res = await fetch('api/actions.php?action=delete_subject', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                location.href = 'index.php';
            } else {
                showError('ข้อผิดพลาด: ' + data.error);
            }
        }

        // Close modal on overlay click
        document.querySelectorAll('.modal-overlay').forEach(el => {
            el.addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('active');
            });
        });
    </script>
</body>
</html>
