<?php
/**
 * จัดการอุปกรณ์ ESP32
 * URL: https://www.youngza.com/IMU/CollectData_Web/devices.php
 */
require_once 'config.php';
$pdo = getDB();

// ดึงรายการอุปกรณ์
$devices = $pdo->query("
    SELECT d.*,
           (SELECT COUNT(*) FROM cd_records WHERE device_id = d.id) as record_count
    FROM cd_devices d ORDER BY d.id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อุปกรณ์ — CollectData</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar">
        <a href="index.php" class="logo">📊 CollectData</a>
        <a href="index.php">ผู้ทดสอบ</a>
        <a href="cd_trial.php">ข้อมูลการทดลอง</a>
        <a href="devices.php" class="active">อุปกรณ์</a>
    </nav>

    <div class="container">
        <div class="page-header">
            <h1>📱 จัดการอุปกรณ์ ESP32</h1>
            <button class="btn btn-primary" onclick="openModal('addDeviceModal')">+ เพิ่มอุปกรณ์</button>
        </div>

        <?php if (empty($devices)): ?>
            <div class="empty-state">
                <div class="icon">📱</div>
                <p>ยังไม่มีอุปกรณ์</p>
                <p style="font-size:13px;color:var(--text-secondary)">เพิ่มอุปกรณ์ ESP32 ก่อนเริ่มเก็บข้อมูล</p>
                <button class="btn btn-primary" onclick="openModal('addDeviceModal')">+ เพิ่มอุปกรณ์แรก</button>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>ชื่ออุปกรณ์</th>
                                <th>MAC Address</th>
                                <th>คำอธิบาย</th>
                                <th>บันทึก</th>
                                <th>วันที่เพิ่ม</th>
                                <th>การดำเนินการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devices as $d): ?>
                                <tr>
                                    <td style="font-weight:600;color:var(--accent)">#<?= $d['id'] ?></td>
                                    <td><?= htmlspecialchars($d['name']) ?></td>
                                    <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($d['mac_address'] ?? '-') ?></td>
                                    <td style="font-size:12px;color:var(--text-secondary)"><?= htmlspecialchars($d['description'] ?? '-') ?></td>
                                    <td><span class="badge badge-recording"><?= $d['record_count'] ?></span></td>
                                    <td style="font-size:12px"><?= date('d/m/Y', strtotime($d['created_at'])) ?></td>
                                    <td>
                                        <button class="btn btn-danger btn-sm" onclick="if(confirm('ลบอุปกรณ์นี้?'))deleteDevice(<?= $d['id'] ?>)">🗑️ ลบ</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- API Info -->
        <div class="card" style="margin-top:24px">
            <h2 style="color:var(--accent);margin-bottom:12px">📡 API สำหรับ ESP32</h2>
            <div class="alert alert-info" style="margin-bottom:16px;">
                <strong>วิธีใช้งานระบบใหม่:</strong>
                <ol class="mb-0 mt-2" style="margin-left: 20px;">
                    <li>อัปโหลดโค้ดลง ESP32 และเปิด Serial Monitor</li>
                    <li>หากเชื่อมเน็ตไม่ได้ ให้ใช้มือถือต่อ <strong class="text-warning">WiFi: CollectData_AP</strong> รหัส <strong class="text-warning">12345678</strong> เพื่อตั้งค่า</li>
                    <li>ดู <strong class="text-warning">MAC Address</strong> จาก Serial Monitor และนำมากด <strong>+ เพิ่มอุปกรณ์</strong> ด้านบน</li>
                    <li>ไปที่หน้า <strong>ผู้ทดสอบ</strong> -> กด <strong>+ เพิ่มบันทึก</strong> จะเป็นสถานะ <span class="badge badge-recording">กำลังบันทึก</span></li>
                    <li>กดปุ่ม <strong>Boot (GPIO0)</strong> บน ESP32 เครื่องจะบันทึกและส่งข้อมูลเข้าบันทึกนั้นอัตโนมัติ!</li>
                </ol>
            </div>
            
            <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px">ส่งข้อมูลจาก ESP32 ไปที่ URL นี้:</p>
            <div style="background:var(--bg-primary);padding:12px;border-radius:8px;font-family:monospace;font-size:13px;margin-bottom:12px">
                POST https://www.youngza.com/IMU/CollectData_Web/api/receive_data.php
            </div>
            <p style="font-size:13px;color:var(--text-secondary);margin-bottom:8px">รูปแบบ JSON:</p>
            <div style="background:var(--bg-primary);padding:12px;border-radius:8px;font-family:monospace;font-size:12px;white-space:pre;overflow-x:auto">{
  "mac_address": "AA:BB:CC:DD:EE:FF",
  "data": [
    {"t": 0, "ax": 0.12, "ay": -9.81, "az": 0.05, "gx": 0.01, "gy": 0.02, "gz": 0.00},
    {"t": 20, "ax": 0.15, "ay": -9.78, "az": 0.08, "gx": 0.03, "gy": 0.01, "gz": 0.02}
  ]
}</div>
        </div>
    </div>

    <!-- Modal เพิ่มอุปกรณ์ -->
    <div class="modal-overlay" id="addDeviceModal">
        <div class="modal">
            <h2>+ เพิ่มอุปกรณ์ ESP32</h2>
            <form id="addDeviceForm">
                <div class="form-group">
                    <label>ชื่ออุปกรณ์ *</label>
                    <input type="text" name="name" required placeholder="เช่น ESP32-Board1">
                </div>
                <div class="form-group">
                    <label>MAC Address</label>
                    <input type="text" name="mac_address" placeholder="AA:BB:CC:DD:EE:FF">
                </div>
                <div class="form-group">
                    <label>คำอธิบาย</label>
                    <textarea name="description" rows="2" placeholder="รายละเอียดอุปกรณ์..."></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal('addDeviceModal')">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">✓ เพิ่มอุปกรณ์</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        document.getElementById('addDeviceForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const form = new FormData(this);
            const res = await fetch('api/actions.php?action=add_device', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('ข้อผิดพลาด: ' + data.error);
            }
        });

        async function deleteDevice(id) {
            const form = new FormData();
            form.append('id', id);
            await fetch('api/actions.php?action=delete_device', { method: 'POST', body: form });
            location.reload();
        }

        document.querySelectorAll('.modal-overlay').forEach(el => {
            el.addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('active');
            });
        });
    </script>
</body>
</html>
