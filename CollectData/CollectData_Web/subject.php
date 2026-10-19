<?php
/**
 * หน้าผู้ทดสอบ — บันทึกท่าล้ม (F01-F05) & กิจวัตรปกติ (D01-D06)
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
    $c = strtoupper($code);
    if ($category === 'fall' || strpos($c, 'F') === 0 || stripos($code, 'fall') !== false) {
        return 2;
    }
    return 10;
}

// ==================== กำหนด 11 ท่ามาตรฐาน (5 ท่าล้ม + 6 ท่า ADL) ====================
$fallTypeSelectGroups = [
    [
        'label' => 'ประเภท 1 การล้ม (Fall) — 2 วินาที (ก่อนล้ม 1 วิ + หลังล้ม 1 วิ)',
        'category' => 'fall',
        'items' => [
            ['code' => 'F01', 'name_th' => 'F01 การล้มไปข้างหน้า',  'name_en' => 'F01 Fall Forward',   'duration_sec' => 2],
            ['code' => 'F02', 'name_th' => 'F02 การล้มไปข้างหลัง',  'name_en' => 'F02 Fall Backward',  'duration_sec' => 2],
            ['code' => 'F03', 'name_th' => 'F03 การล้มไปด้านซ้าย',  'name_en' => 'F03 Fall Left',      'duration_sec' => 2],
            ['code' => 'F04', 'name_th' => 'F04 การล้มไปด้านขวา',   'name_en' => 'F04 Fall Right',     'duration_sec' => 2],
            ['code' => 'F05', 'name_th' => 'F05 การล้มทรุดตัวแนวดิ่ง', 'name_en' => 'F05 Vertical Fall', 'duration_sec' => 2],
        ],
    ],
    [
        'label' => 'ประเภท 2 กิจวัตรประจำวัน (ADL) — 10 วินาที (ยืนเตรียมตัว 1 วิ + ทำท่า 8 วิ + ยืน 1 วิ)',
        'category' => 'adl',
        'items' => [
            ['code' => 'D01', 'name_th' => 'D01 การเดิน',            'name_en' => 'D01 Walking',             'duration_sec' => 10],
            ['code' => 'D02', 'name_th' => 'D02 การวิ่ง',            'name_en' => 'D02 Jogging',             'duration_sec' => 10],
            ['code' => 'D03', 'name_th' => 'D03 การขึ้นบันได',       'name_en' => 'D03 Upstairs',            'duration_sec' => 10],
            ['code' => 'D04', 'name_th' => 'D04 การก้มตัว',          'name_en' => 'D04 Bending',             'duration_sec' => 10],
            ['code' => 'D05', 'name_th' => 'D05 การลุกนั่ง',         'name_en' => 'D05 Sit to Stand',        'duration_sec' => 10],
            ['code' => 'D06', 'name_th' => 'D06 การลุกนั่งแบบเร็ว',   'name_en' => 'D06 Quick Sit to Stand',  'duration_sec' => 10],
        ],
    ],
];

// ตรวจสอบและลงทะเบียน 11 ท่ามาตรฐานลง cd_fall_types
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

// อัปเดตรหัสเก่าที่บันทึกไว้ในอดีตให้ชี้ไปยังรหัสมาตรฐาน 11 ท่า
$legacyMap = [
    'fall_forward'    => 'F01',
    'fall_backward'   => 'F02',
    'fall_left'       => 'F03',
    'fall_right'      => 'F04',
    'fall_lateral'    => 'F03',
    'fall_sitting'    => 'F05',
    'adl_walk'        => 'D01',
    'adl_jog'         => 'D02',
    'adl_stairs'      => 'D03',
    'adl_stairs_up'   => 'D03',
    'adl_stairs_down' => 'D03',
    'adl_pickup'      => 'D04',
    'adl_bend'        => 'D04',
    'adl_sit'         => 'D05',
    'adl_sit_down'    => 'D05',
    'adl_chair_up'    => 'D06',
    'adl_stand'       => 'D01',
    'adl_change_pose' => 'D04'
];
foreach ($legacyMap as $oldCode => $newCode) {
    $checkOld = $pdo->prepare("SELECT id FROM cd_fall_types WHERE code = ?");
    $checkOld->execute([$oldCode]);
    $oldId = $checkOld->fetchColumn();

    $checkNew = $pdo->prepare("SELECT id FROM cd_fall_types WHERE code = ?");
    $checkNew->execute([$newCode]);
    $newId = $checkNew->fetchColumn();

    if ($oldId && $newId && (int)$oldId !== (int)$newId) {
        $pdo->prepare("UPDATE cd_records SET fall_type_id = ? WHERE fall_type_id = ?")->execute([$newId, $oldId]);
    }
}

// ดึงรายการประเภททั้งหมดจาก DB
$fallTypeRows = $pdo->query("SELECT * FROM cd_fall_types ORDER BY category, id")->fetchAll();
$fallTypesByCode = [];
foreach ($fallTypeRows as $ft) {
    $fallTypesByCode[$ft['code']] = $ft;
}

// ดึงบันทึกทั้งหมดของผู้ทดสอบนี้
$recordsStmt = $pdo->prepare("
    SELECT r.*, ft.name_th as fall_type_name, ft.code as fall_type_code, ft.category,
           d.name as device_name,
           (SELECT COUNT(*) FROM cd_sensor_data WHERE record_id = r.id) as data_points
    FROM cd_records r
    JOIN cd_fall_types ft ON r.fall_type_id = ft.id
    JOIN cd_devices d ON r.device_id = d.id
    WHERE r.subject_id = ?
    ORDER BY ft.category, ft.code, r.trial_number ASC
");
$recordsStmt->execute([$id]);
$allRecords = $recordsStmt->fetchAll();

// จัดกลุ่มบันทึกตามท่าทาง (Posture Grouping)
$grouped = [];
foreach ($allRecords as $r) {
    $key = $r['fall_type_code'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'fall_type_id' => (int)$r['fall_type_id'],
            'code' => $r['fall_type_code'],
            'name' => $r['fall_type_name'],
            'category' => $r['category'],
            'duration_sec' => getActivityDurationSec($r['fall_type_code'], $r['category']),
            'records' => []
        ];
    }
    $grouped[$key]['records'][] = $r;
}

// ดึงอุปกรณ์ที่ลงทะเบียนไว้
$devices = $pdo->query("SELECT * FROM cd_devices ORDER BY name")->fetchAll();
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
        .posture-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 16px;
            overflow: hidden;
            transition: box-shadow 0.2s, border-color 0.2s;
        }
        .posture-card:hover {
            box-shadow: var(--shadow);
            border-color: var(--accent);
        }
        .posture-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            gap: 16px;
            flex-wrap: wrap;
            background: linear-gradient(90deg, rgba(255,255,255,0.01), rgba(0,210,255,0.02));
        }
        .posture-title-area {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            user-select: none;
            flex: 1;
            min-width: 260px;
        }
        .posture-title {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .posture-actions-area {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .posture-accordion {
            border-top: 1px solid var(--border);
            background: var(--bg-primary);
            padding: 0;
            display: none;
        }
        .posture-accordion.open {
            display: block;
        }
        .trial-row:hover {
            background: var(--row-hover);
        }
        .chart-collapse-row {
            background: rgba(0, 0, 0, 0.22);
            border-bottom: 2px solid var(--accent);
        }
        .count-badge {
            font-size: 13px;
            color: var(--text-secondary);
            margin-left: 4px;
            white-space: nowrap;
        }
        @keyframes pulseWait {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.55; }
        }
        .pulse-wait {
            animation: pulseWait 1.5s infinite ease-in-out;
        }
        .btn-icon {
            padding: 6px 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
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
                <div class="stat-value"><?= count($allRecords) ?></div>
                <div class="stat-label">บันทึกทั้งหมด</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_filter($allRecords, fn($r) => $r['category'] == 'fall')) ?></div>
                <div class="stat-label">บันทึกการล้ม</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_filter($allRecords, fn($r) => $r['category'] == 'adl')) ?></div>
                <div class="stat-label">บันทึก ADL</div>
            </div>
        </div>

        <?php if (empty($grouped)): ?>
            <div class="empty-state">
                <div class="icon">📝</div>
                <p>ยังไม่มีรายการบันทึกสำหรับผู้ทดสอบนี้</p>
                <button class="btn btn-primary" onclick="openModal('addRecordModal')">+ เพิ่มบันทึกแรก</button>
            </div>
        <?php else: ?>
            <!-- รายการท่าทาง (แบบแถวคล้ายรูปต้นแบบพร้อม Accordion เปิด/ปิดกราฟ) -->
            <?php foreach ($grouped as $code => $group): ?>
                <?php
                $groupKey = preg_replace('/[^A-Za-z0-9_-]/', '_', $code);
                $recCount = count($group['records']);
                $latestRec = $group['records'][$recCount - 1];
                $hasRecording = false;
                $hasCompleted = false;
                $activeRecId = 0;
                $activePts = 0;

                foreach ($group['records'] as $r) {
                    if ($r['status'] === 'recording') {
                        $hasRecording = true;
                        $activeRecId = (int)$r['id'];
                        $activePts = (int)$r['data_points'];
                        break;
                    }
                    if ((int)$r['data_points'] > 0) {
                        $hasCompleted = true;
                    }
                }
                $targetSendRecId = $activeRecId > 0 ? $activeRecId : (int)$latestRec['id'];
                ?>
                <div class="posture-card" id="posture_card_<?= $groupKey ?>">
                    <div class="posture-header-row">
                        <!-- ฝั่งซ้าย: ป้ายกำกับ + ชื่อท่า + ระยะเวลากำหนด -->
                        <div class="posture-title-area" onclick="togglePostureAccordion('<?= $groupKey ?>')">
                            <span class="badge <?= $group['category'] == 'fall' ? 'badge-fall' : 'badge-adl' ?>">
                                <?= $group['category'] == 'fall' ? 'การล้ม' : 'ADL' ?>
                            </span>
                            <span class="posture-title">
                                <?= htmlspecialchars($group['name']) ?>
                            </span>
                            <span style="font-size:12px;color:var(--text-secondary)">
                                (<?= (int)$group['duration_sec'] ?> วินาที)
                            </span>
                        </div>

                        <!-- ฝั่งขวา: สถานะ + ปุ่มส่งคำสั่ง + ปุ่มดูกราฟ + ปุ่มลบ + จำนวนรายการ -->
                        <div class="posture-actions-area">
                            <?php if ($hasRecording): ?>
                                <span class="badge badge-recording pulse-wait" id="group_status_badge_<?= $activeRecId ?>">
                                    <?= $activePts > 0 ? '📥 กำลังรับข้อมูล (' . number_format($activePts) . ' จุด)...' : '⏳ รอ ESP32 ส่งข้อมูล...' ?>
                                </span>
                                <button class="btn btn-danger btn-sm" onclick="stopCommand(<?= $activeRecId ?>)" title="สั่งให้ ESP32 สรุปข้อมูลและปิดรอบ">⏹️ หยุด</button>
                                <button class="btn btn-outline btn-sm" onclick="cancelCommand(<?= $activeRecId ?>)" title="ยกเลิกการรอรับข้อมูล">✘ ยกเลิก</button>
                            <?php elseif ($hasCompleted): ?>
                                <span class="badge badge-adl">✔ เสร็จสิ้น</span>
                                <button class="btn btn-primary btn-sm" onclick="sendCommand(<?= $targetSendRecId ?>, true)" title="ส่งคำสั่งเริ่มบันทึกใหม่ไปที่ ESP32">📡 ส่งคำสั่ง</button>
                            <?php else: ?>
                                <span class="badge badge-other">⚪ รอส่งคำสั่ง</span>
                                <button class="btn btn-primary btn-sm" onclick="sendCommand(<?= $targetSendRecId ?>, false)" title="ส่งคำสั่งเริ่มบันทึกไปที่ ESP32">📡 ส่งคำสั่ง</button>
                            <?php endif; ?>

                            <button class="btn btn-outline btn-sm btn-icon" id="btn_acc_<?= $groupKey ?>" onclick="togglePostureAccordion('<?= $groupKey ?>')" title="เปิด/ปิดดูรายการและกราฟ">
                                📈
                            </button>

                            <button class="btn btn-danger btn-sm btn-icon" onclick="confirmDeletePosture(<?= (int)$group['fall_type_id'] ?>, '<?= htmlspecialchars($group['name']) ?>')" title="ลบรายการท่านี้ทั้งหมด">
                                🗑️
                            </button>

                            <span class="count-badge"><?= $recCount ?> รายการ</span>
                        </div>
                    </div>

                    <!-- Accordion Dropdown: แสดงตารางไฟล์บันทึกในท่านี้ (กดเปิด/ปิดกราฟของแต่ละไฟล์ได้) -->
                    <div id="accordion_<?= $groupKey ?>" class="posture-accordion">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:80px">รอบที่</th>
                                        <th>อุปกรณ์</th>
                                        <th>จำนวนจุดข้อมูล</th>
                                        <th>วันที่บันทึก</th>
                                        <th>สถานะ</th>
                                        <th style="text-align:right">การดำเนินการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['records'] as $r): ?>
                                        <?php
                                        $rid = (int)$r['id'];
                                        $pts = (int)$r['data_points'];
                                        $isRec = ($r['status'] === 'recording');
                                        $isDone = ($pts > 0);
                                        ?>
                                        <tr class="trial-row" id="trial_row_<?= $rid ?>" data-record-id="<?= $rid ?>" data-record-status="<?= htmlspecialchars($r['status']) ?>" data-record-points="<?= $pts ?>">
                                            <td style="font-weight:700">#<?= $r['trial_number'] ?></td>
                                            <td><?= htmlspecialchars($r['device_name']) ?></td>
                                            <td id="pts_cell_<?= $rid ?>"><?= number_format($pts) ?> จุด</td>
                                            <td style="font-size:12px"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                                            <td>
                                                <?php if ($isRec): ?>
                                                    <span class="badge badge-recording pulse-wait" id="status_badge_<?= $rid ?>">
                                                        <?= $pts > 0 ? '📥 กำลังรับข้อมูล...' : '⏳ รอ ESP32...' ?>
                                                    </span>
                                                <?php elseif ($isDone): ?>
                                                    <span class="badge badge-adl" id="status_badge_<?= $rid ?>">✔ เสร็จสิ้น</span>
                                                <?php else: ?>
                                                    <span class="badge badge-other" id="status_badge_<?= $rid ?>">⚪ รอส่งคำสั่ง</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right;white-space:nowrap;display:flex;justify-content:flex-end;gap:6px;align-items:center;">
                                                <?php if ($isDone): ?>
                                                    <button class="btn btn-outline btn-sm" id="btn_chart_<?= $rid ?>" onclick="toggleTrialChart(<?= $rid ?>, <?= $r['trial_number'] ?>, '<?= htmlspecialchars($group['name']) ?>')">
                                                        📈 ดูกราฟ
                                                    </button>
                                                    <button class="btn btn-outline btn-sm" onclick="sendCommand(<?= $rid ?>, true)" title="ส่งคำสั่งซ้ำเพื่อบันทึกทับรอบนี้">📡 ส่งคำสั่งซ้ำ</button>
                                                    <a href="api/actions.php?action=export_sensor_data&record_id=<?= $rid ?>" class="btn btn-outline btn-sm" title="ส่งออก CSV">📥 CSV</a>
                                                <?php elseif ($isRec): ?>
                                                    <button class="btn btn-danger btn-sm" onclick="stopCommand(<?= $rid ?>)">⏹️ หยุด</button>
                                                    <button class="btn btn-outline btn-sm" onclick="cancelCommand(<?= $rid ?>)">✘ ยกเลิก</button>
                                                <?php else: ?>
                                                    <button class="btn btn-primary btn-sm" onclick="sendCommand(<?= $rid ?>, false)">📡 ส่งคำสั่ง</button>
                                                <?php endif; ?>
                                                <button class="btn btn-danger btn-sm" onclick="confirmDeleteRecord(<?= $rid ?>)" title="ลบรอบนี้">🗑️</button>
                                            </td>
                                        </tr>

                                        <!-- ส่วนแสดงกราฟของไฟล์รอบนี้ (เปิด/ปิดอิสระ เพื่อไม่ให้ต้องเลื่อนยาว) -->
                                        <tr id="chart_row_<?= $rid ?>" class="chart-collapse-row" style="display:none;">
                                            <td colspan="6" style="padding:16px 20px;">
                                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                                                    <h4 style="margin:0;color:var(--accent);font-size:15px;">
                                                        📊 กราฟข้อมูลรอบที่ #<?= $r['trial_number'] ?> — <?= htmlspecialchars($group['name']) ?>
                                                        <span style="font-weight:normal;color:var(--text-secondary);font-size:13px">(<span id="chart_pts_label_<?= $rid ?>"><?= number_format($pts) ?></span> จุดข้อมูล)</span>
                                                    </h4>
                                                    <button class="btn btn-outline btn-sm" onclick="toggleTrialChart(<?= $rid ?>)">✖ ปิดกราฟ</button>
                                                </div>
                                                <div style="display:grid;grid-template-columns:1fr;gap:14px;">
                                                    <div class="chart-container" style="margin-bottom:0;background:#ffffff;border-radius:10px;padding:14px;">
                                                        <h5 style="color:#000000;margin-bottom:6px;font-size:13px;font-weight:700;">แรงเร่งความเร่ง (Accelerometer X, Y, Z และแรงลัพธ์ G)</h5>
                                                        <canvas id="accel_canvas_<?= $rid ?>" height="110"></canvas>
                                                    </div>
                                                    <div class="chart-container" style="margin-bottom:0;background:#ffffff;border-radius:10px;padding:14px;">
                                                        <h5 style="color:#000000;margin-bottom:6px;font-size:13px;font-weight:700;">ความเร็วเชิงมุม (Gyroscope X, Y, Z — rad/s)</h5>
                                                        <canvas id="gyro_canvas_<?= $rid ?>" height="110"></canvas>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Modal เพิ่มบันทึก (ปรับตามข้อกำหนด: เหลือแค่ เลือกท่า + เลือกอุปกรณ์ + ปุ่มสร้าง) -->
    <div class="modal-overlay" id="addRecordModal">
        <div class="modal">
            <h2>+ เพิ่มบันทึกข้อมูล</h2>
            <form id="addRecordForm">
                <input type="hidden" name="subject_id" value="<?= $id ?>">

                <div class="form-group">
                    <label>เลือกประเภทท่าทาง *</label>
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
                    <label>เลือกอุปกรณ์ ESP32 *</label>
                    <select name="device_id" required>
                        <?php if (empty($devices)): ?>
                            <option value="" disabled>— ยังไม่มีอุปกรณ์ กรุณาเพิ่มที่เมนูอุปกรณ์ก่อน —</option>
                        <?php endif; ?>
                        <?php foreach ($devices as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?> (<?= htmlspecialchars($d['mac_address']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addRecordModal')">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">✓ สร้างรายการ</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.classList.add('active');
        }
        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.classList.remove('active');
        }

        // เปิด/ปิด Accordion ของกลุ่มท่าทาง
        function togglePostureAccordion(groupKey) {
            const acc = document.getElementById('accordion_' + groupKey);
            const btn = document.getElementById('btn_acc_' + groupKey);
            if (!acc) return;
            const isOpen = acc.classList.contains('open');
            if (isOpen) {
                acc.classList.remove('open');
                if (btn) btn.classList.remove('btn-primary');
            } else {
                acc.classList.add('open');
                if (btn) btn.classList.add('btn-primary');
            }
        }

        // จัดการแคชข้อมูลกราฟและอ็อบเจ็กต์ Chart.js
        const chartDataCache = new Map();
        const activeCharts = new Map();

        const chartColors = {
            ax: '#ff6384',
            ay: '#36a2eb',
            az: '#ffce56',
            g:  '#111827',
            gx: '#ff9f40',
            gy: '#4bc0c0',
            gz: '#9966ff'
        };

        // เปิด/ปิด กราฟของไฟล์รอบที่ระบุ
        async function toggleTrialChart(recordId, trialNum = 0, postureName = '') {
            const row = document.getElementById('chart_row_' + recordId);
            const btn = document.getElementById('btn_chart_' + recordId);
            if (!row) return;

            const isShown = row.style.display !== 'none';
            if (isShown) {
                row.style.display = 'none';
                if (btn) btn.textContent = '📈 ดูกราฟ';
                return;
            }

            row.style.display = 'table-row';
            if (btn) btn.textContent = '📉 ปิดกราฟ';

            // หากเคยวาดกราฟรอบนี้แล้ว ไม่ต้อง fetch ใหม่
            if (activeCharts.has(recordId)) return;

            const accelCanvas = document.getElementById('accel_canvas_' + recordId);
            const gyroCanvas = document.getElementById('gyro_canvas_' + recordId);
            if (!accelCanvas || !gyroCanvas) return;

            try {
                let resData;
                if (chartDataCache.has(recordId)) {
                    resData = chartDataCache.get(recordId);
                } else {
                    const res = await fetch('api/actions.php?action=get_sensor_data&record_id=' + encodeURIComponent(recordId));
                    resData = await res.json();
                    if (resData.success) {
                        chartDataCache.set(recordId, resData);
                    }
                }

                if (!resData || !resData.success || !resData.data.length) {
                    accelCanvas.parentElement.innerHTML = '<p style="color:#666;font-size:13px;padding:12px;">ไม่มีข้อมูลเซ็นเซอร์</p>';
                    return;
                }

                const d = resData.data;
                const labels = d.map(p => (Number(p.timestamp_ms) / 1000).toFixed(2));
                const pointInterval = Math.max(1, Math.ceil(d.length / 25));

                const gValues = d.map(p => {
                    const ax = Number(p.accel_x);
                    const ay = Number(p.accel_y);
                    const az = Number(p.accel_z);
                    return Math.sqrt(ax * ax + ay * ay + az * az) / 9.81;
                });

                const commonOptions = {
                    responsive: true,
                    animation: false,
                    plugins: {
                        legend: {
                            labels: {
                                color: '#000000',
                                font: { family: "'Noto Sans Thai', sans-serif", size: 11, weight: '700' },
                                usePointStyle: true
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: { color: '#000000', maxTicksLimit: 12, font: { weight: '600', size: 10 } },
                            grid: { color: '#e5e7eb' },
                            title: { display: true, text: 'เวลา (วินาที)', color: '#000000', font: { weight: '700' } }
                        },
                        y: {
                            ticks: { color: '#000000', font: { weight: '600', size: 10 } },
                            grid: { color: '#e5e7eb' }
                        }
                    },
                    elements: { line: { borderWidth: 1.8 } }
                };

                const accelChart = new Chart(accelCanvas, {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [
                            { label: 'Accel X', data: d.map(p => Number(p.accel_x)), borderColor: chartColors.ax, backgroundColor: chartColors.ax, pointRadius: ctx => ctx.dataIndex % pointInterval === 0 ? 2.5 : 0, tension: 0.1 },
                            { label: 'Accel Y', data: d.map(p => Number(p.accel_y)), borderColor: chartColors.ay, backgroundColor: chartColors.ay, pointRadius: ctx => ctx.dataIndex % pointInterval === 0 ? 2.5 : 0, tension: 0.1 },
                            { label: 'Accel Z', data: d.map(p => Number(p.accel_z)), borderColor: chartColors.az, backgroundColor: chartColors.az, pointRadius: ctx => ctx.dataIndex % pointInterval === 0 ? 2.5 : 0, tension: 0.1 },
                            { label: 'G (รวม)', data: gValues, borderColor: chartColors.g, backgroundColor: chartColors.g, borderDash: [4, 4], pointRadius: ctx => ctx.dataIndex % pointInterval === 0 ? 2.5 : 0, tension: 0.1 }
                        ]
                    },
                    options: commonOptions
                });

                const gyroChart = new Chart(gyroCanvas, {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [
                            { label: 'Gyro X', data: d.map(p => Number(p.gyro_x)), borderColor: chartColors.gx, backgroundColor: chartColors.gx, pointRadius: ctx => ctx.dataIndex % pointInterval === 0 ? 2.5 : 0, tension: 0.1 },
                            { label: 'Gyro Y', data: d.map(p => Number(p.gyro_y)), borderColor: chartColors.gy, backgroundColor: chartColors.gy, pointRadius: ctx => ctx.dataIndex % pointInterval === 0 ? 2.5 : 0, tension: 0.1 },
                            { label: 'Gyro Z', data: d.map(p => Number(p.gyro_z)), borderColor: chartColors.gz, backgroundColor: chartColors.gz, pointRadius: ctx => ctx.dataIndex % pointInterval === 0 ? 2.5 : 0, tension: 0.1 }
                        ]
                    },
                    options: commonOptions
                });

                activeCharts.set(recordId, { accelChart, gyroChart });
            } catch (err) {
                console.error('Chart load error:', err);
            }
        }

        // สร้างรายการบันทึกใหม่
        document.getElementById('addRecordForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const form = new FormData(this);

            const res = await fetch('api/actions.php?action=add_record', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                showError('ข้อผิดพลาด: ' + (data.error || 'ไม่สามารถสร้างรายการได้'));
            }
        });

        // ส่งคำสั่งเริ่มบันทึกไปยัง ESP32
        async function sendCommand(id, hasExistingData = false) {
            if (hasExistingData) {
                const confirmed = await confirmAction({
                    title: 'ส่งคำสั่งเริ่มรอบใหม่?',
                    text: 'ข้อมูลเดิมของรอบนี้จะถูกล้างเพื่อเตรียมรับข้อมูลชุดใหม่จาก ESP32',
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

        async function stopCommand(id) {
            const confirmed = await confirmAction({
                title: 'สั่งหยุดบันทึกรอบนี้?',
                text: 'ESP32 จะบันทึกจุดสุดท้ายและสรุปผลเป็นเสร็จสิ้นทันที',
                confirmButtonText: '⏹️ สั่งหยุด',
                confirmButtonColor: '#ef4444'
            });
            if (!confirmed) return;

            const form = new FormData();
            form.append('id', id);
            const res = await fetch('api/actions.php?action=stop_command', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'info',
                        title: 'ส่งคำสั่งหยุดแล้ว',
                        text: 'ESP32 จะสรุปสถานะเป็นเสร็จสิ้น',
                        timer: 1500,
                        showConfirmButton: false
                    });
                }
            } else {
                showError('ข้อผิดพลาด: ' + (data.error || 'ไม่สามารถหยุดคำสั่งได้'));
            }
        }

        // ระบบตรวจสอบสถานะอัตโนมัติ (Polling) เมื่อรอ ESP32 ส่งข้อมูล
        (function startEsp32Polling() {
            const waitingRows = Array.from(document.querySelectorAll('.trial-row[data-record-status="recording"]'));
            if (waitingRows.length === 0) return;

            const waitingIds = new Set(waitingRows.map(r => Number(r.dataset.recordId)));
            const prevPointsMap = new Map();
            const idleTicksMap = new Map();
            let finalizeTargetId = 0;

            waitingRows.forEach(r => {
                const rid = Number(r.dataset.recordId);
                prevPointsMap.set(rid, Number(r.dataset.recordPoints || 0));
                idleTicksMap.set(rid, 0);

                // เปิด Accordion ของกลุ่มที่มีแถวรออยู่โดยอัตโนมัติ
                const postureCard = r.closest('.posture-card');
                if (postureCard) {
                    const acc = postureCard.querySelector('.posture-accordion');
                    if (acc) acc.classList.add('open');
                }
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
                        if (ptsCell) ptsCell.textContent = pts.toLocaleString() + ' จุด';

                        const groupBadge = document.getElementById('group_status_badge_' + rid);
                        if (groupBadge) {
                            groupBadge.textContent = pts > 0 ? `📥 กำลังรับข้อมูล (${pts.toLocaleString()} จุด)...` : '⏳ รอ ESP32 ส่งข้อมูล...';
                        }

                        // ESP32 ส่งจบแล้วและสถานะเป็น completed
                        if (rec.status === 'completed' && pts > 0) {
                            clearInterval(pollTimer);
                            if (window.Swal) {
                                await Swal.fire({
                                    icon: 'success',
                                    title: 'เสร็จสิ้น!',
                                    text: `ได้รับข้อมูลจาก ESP32 ครบถ้วนจำนวน ${pts.toLocaleString()} จุดข้อมูล`,
                                    timer: 1800,
                                    showConfirmButton: false
                                });
                            }
                            location.reload();
                            return;
                        }

                        if (rec.status === 'recording' && pts > 0) {
                            const prevPts = prevPointsMap.get(rid) || 0;
                            if (pts > prevPts) {
                                prevPointsMap.set(rid, pts);
                                idleTicksMap.set(rid, 0);
                            } else {
                                const ticks = (idleTicksMap.get(rid) || 0) + 1;
                                idleTicksMap.set(rid, ticks);
                                if (ticks >= 2) {
                                    finalizeTargetId = rid;
                                }
                            }
                        }
                    }
                } catch (err) {
                    console.warn('Polling error:', err);
                }
            }, 1500);
        })();

        // ลบทั้งกลุ่มท่าทาง
        async function confirmDeletePosture(fallTypeId, postureName) {
            const confirmed = await confirmAction({
                title: 'ลบรายการท่านี้ทั้งหมด?',
                text: `ข้อมูลบันทึกและเซ็นเซอร์ทั้งหมดของ "${postureName}" สำหรับผู้ทดสอบนี้จะถูกลบ`,
                confirmButtonText: 'ลบทั้งหมด',
                confirmButtonColor: '#ef4444'
            });
            if (!confirmed) return;

            const form = new FormData();
            form.append('subject_id', <?= $id ?>);
            form.append('fall_type_id', fallTypeId);
            const res = await fetch('api/actions.php?action=delete_posture_records', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                showError('ข้อผิดพลาด: ' + (data.error || 'ไม่สามารถลบรายการได้'));
            }
        }

        // ลบบันทึกรอบเดี่ยว
        async function confirmDeleteRecord(id) {
            const confirmed = await confirmAction({
                title: 'ลบรอบนี้?',
                text: 'ข้อมูลบันทึกและข้อมูลเซ็นเซอร์ของรอบนี้จะถูกลบออกจากระบบ',
                confirmButtonText: 'ลบ'
            });
            if (confirmed) {
                const form = new FormData();
                form.append('id', id);
                const res = await fetch('api/actions.php?action=delete_record', { method: 'POST', body: form });
                const data = await res.json();
                if (data.success) {
                    location.reload();
                } else {
                    showError('ข้อผิดพลาด: ' + (data.error || 'ไม่สามารถลบได้'));
                }
            }
        }

        // ลบผู้ทดสอบ
        async function confirmDeleteSubject(id) {
            const confirmed = await confirmAction({
                title: 'ลบผู้ทดสอบนี้?',
                text: 'ข้อมูลผู้ทดสอบและบันทึกทั้งหมดจะถูกลบออกจากระบบ',
                confirmButtonText: 'ลบ'
            });
            if (confirmed) {
                const form = new FormData();
                form.append('id', id);
                const res = await fetch('api/actions.php?action=delete_subject', { method: 'POST', body: form });
                const data = await res.json();
                if (data.success) {
                    location.href = 'index.php';
                } else {
                    showError('ข้อผิดพลาด: ' + (data.error || 'ไม่สามารถลบผู้ทดสอบได้'));
                }
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
