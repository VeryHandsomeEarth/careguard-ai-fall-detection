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
            'records' => []
        ];
    }
    $grouped[$key]['records'][] = $r;
}

// ดึงรายการอุปกรณ์ + ประเภทการล้ม + รอบทดลอง
$devices = $pdo->query("SELECT * FROM cd_devices ORDER BY name")->fetchAll();
$fallTypes = $pdo->query("SELECT * FROM cd_fall_types ORDER BY category, id")->fetchAll();
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
                <button class="btn btn-danger btn-sm" onclick="if(confirm('ลบผู้ทดสอบนี้?'))deleteSubject(<?= $id ?>)">🗑️ ลบ</button>
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
                            <?= htmlspecialchars($group['name']) ?>
                        </h2>
                        <span style="font-size:13px;color:var(--text-secondary)"><?= count($group['records']) ?> รายการ</span>
                    </div>

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
                                    <tr>
                                        <td>#<?= $r['trial_number'] ?></td>
                                        <td><?= htmlspecialchars($r['device_name']) ?></td>
                                        <td>
                                            <?php if ($r['trial_label']): ?>
                                                <span class="badge badge-recording"><?= htmlspecialchars($r['trial_label']) ?></span>
                                            <?php else: ?>
                                                <span style="color:var(--text-secondary);font-size:12px">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= number_format($r['data_points']) ?></td>
                                        <td style="font-size:12px;color:var(--text-secondary)"><?= htmlspecialchars($r['notes'] ?? '-') ?></td>
                                        <td style="font-size:12px"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                                        <td style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
                                            <?php if ($r['status'] === 'recording'): ?>
                                                <span class="badge badge-adl">● กำลังบันทึก</span>
                                                <button class="btn btn-success btn-sm" onclick="updateStatus(<?= $r['id'] ?>, 'completed')" title="เปลี่ยนเป็นเสร็จสิ้น">✅ เสร็จ</button>
                                            <?php elseif ($r['status'] === 'completed'): ?>
                                                <span class="badge badge-other">✔ เสร็จสิ้น</span>
                                                <button class="btn btn-outline btn-sm" onclick="updateStatus(<?= $r['id'] ?>, 'recording')" title="เปิดบันทึกต่อ">▶ บันทึกต่อ</button>
                                            <?php else: ?>
                                                <span class="badge badge-fall">✘ ยกเลิก</span>
                                            <?php endif; ?>
                                            <a href="record.php?id=<?= $r['id'] ?>" class="btn btn-outline btn-sm">📈</a>
                                            <button class="btn btn-danger btn-sm" onclick="if(confirm('ลบบันทึกนี้?'))deleteRecord(<?= $r['id'] ?>)">🗑️</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- กราฟ Preview: แสดงกราฟเฉพาะรายการแรก (ถ้ามีข้อมูล) -->
                    <?php 
                    $firstRecord = $group['records'][0];
                    if ($firstRecord['data_points'] > 0):
                    ?>
                        <div style="margin-top:16px">
                            <div class="chart-container">
                                <h3>ตัวอย่างกราฟ — รอบ #<?= $firstRecord['trial_number'] ?> (ความเร่ง XYZ)</h3>
                                <canvas id="accel_<?= $code ?>" height="120"></canvas>
                            </div>
                            <div class="chart-container">
                                <h3>ตัวอย่างกราฟ — รอบ #<?= $firstRecord['trial_number'] ?> (ไจโรสโคป XYZ)</h3>
                                <canvas id="gyro_<?= $code ?>" height="120"></canvas>
                            </div>
                        </div>
                        <script>
                            fetch('api/actions.php?action=get_sensor_data&record_id=<?= $firstRecord['id'] ?>')
                                .then(r => r.json())
                                .then(res => {
                                    if (!res.success || !res.data.length) return;
                                    const d = res.data;
                                    const labels = d.map((p,i) => (p.timestamp_ms / 1000).toFixed(2));
                                    const cfg = (ctx, datasets) => new Chart(ctx, {
                                        type: 'line',
                                        data: { labels, datasets },
                                        options: {
                                            responsive: true,
                                            animation: false,
                                            plugins: { legend: { labels: { color: '#a0a0b0', font: { size: 11 } } } },
                                            scales: {
                                                x: { display: true, ticks: { color: '#666', maxTicksLimit: 10, font: { size: 10 } }, grid: { color: '#2a2a4a' }, title: { display: true, text: 'เวลา (วินาที)', color: '#888' } },
                                                y: { ticks: { color: '#666', font: { size: 10 } }, grid: { color: '#2a2a4a' } }
                                            },
                                            elements: { point: { radius: 0 }, line: { borderWidth: 1.5 } }
                                        }
                                    });
                                    cfg(document.getElementById('accel_<?= $code ?>'), [
                                        { label: 'Accel X', data: d.map(p => p.accel_x), borderColor: '#ff6384' },
                                        { label: 'Accel Y', data: d.map(p => p.accel_y), borderColor: '#36a2eb' },
                                        { label: 'Accel Z', data: d.map(p => p.accel_z), borderColor: '#ffce56' }
                                    ]);
                                    cfg(document.getElementById('gyro_<?= $code ?>'), [
                                        { label: 'Gyro X', data: d.map(p => p.gyro_x), borderColor: '#ff9f40' },
                                        { label: 'Gyro Y', data: d.map(p => p.gyro_y), borderColor: '#4bc0c0' },
                                        { label: 'Gyro Z', data: d.map(p => p.gyro_z), borderColor: '#9966ff' }
                                    ]);
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
            <h2>+ เพิ่มบันทึกการล้ม/ADL</h2>
            <form id="addRecordForm">
                <input type="hidden" name="subject_id" value="<?= $id ?>">

                <div class="form-group">
                    <label>ประเภท *</label>
                    <select name="fall_type_id" id="fallTypeSelect" required>
                        <optgroup label="🔴 การล้ม">
                            <?php foreach ($fallTypes as $ft): if($ft['category']=='fall'): ?>
                                <option value="<?= $ft['id'] ?>"><?= htmlspecialchars($ft['name_th']) ?></option>
                            <?php endif; endforeach; ?>
                        </optgroup>
                        <optgroup label="🟢 กิจวัตรประจำวัน (ADL)">
                            <?php foreach ($fallTypes as $ft): if($ft['category']=='adl'): ?>
                                <option value="<?= $ft['id'] ?>"><?= htmlspecialchars($ft['name_th']) ?></option>
                            <?php endif; endforeach; ?>
                        </optgroup>
                        <optgroup label="🟡 อื่นๆ">
                            <?php foreach ($fallTypes as $ft): if($ft['category']=='other'): ?>
                                <option value="<?= $ft['id'] ?>"><?= htmlspecialchars($ft['name_th']) ?></option>
                            <?php endif; endforeach; ?>
                            <option value="__new__">➕ เพิ่มประเภทใหม่...</option>
                        </optgroup>
                    </select>
                </div>

                <!-- ฟอร์มเพิ่มประเภทใหม่ (ซ่อนไว้) -->
                <div id="newTypeFields" style="display:none;background:var(--bg-primary);padding:14px;border-radius:8px;margin-bottom:16px">
                    <div class="form-group">
                        <label>ชื่อประเภทใหม่ (ภาษาไทย) *</label>
                        <input type="text" id="newTypeName" placeholder="เช่น กลิ้งจากเตียง">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>ชื่อภาษาอังกฤษ</label>
                            <input type="text" id="newTypeNameEn" placeholder="Roll from bed">
                        </div>
                        <div class="form-group">
                            <label>หมวดหมู่</label>
                            <select id="newTypeCategory">
                                <option value="fall">การล้ม</option>
                                <option value="adl">กิจวัตรประจำวัน</option>
                                <option value="other" selected>อื่นๆ</option>
                            </select>
                        </div>
                    </div>
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
                        <label>สถานะ</label>
                        <select name="status">
                            <option value="completed">เสร็จแล้ว</option>
                            <option value="recording">กำลังบันทึก</option>
                            <option value="cancelled">ยกเลิก</option>
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
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        // แสดง/ซ่อนฟอร์มเพิ่มประเภทใหม่
        document.getElementById('fallTypeSelect').addEventListener('change', function() {
            document.getElementById('newTypeFields').style.display = this.value === '__new__' ? 'block' : 'none';
        });

        // Submit เพิ่มบันทึก
        document.getElementById('addRecordForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const form = new FormData(this);

            // ถ้าเลือก "เพิ่มประเภทใหม่"
            if (form.get('fall_type_id') === '__new__') {
                const newName = document.getElementById('newTypeName').value.trim();
                if (!newName) { alert('กรุณากรอกชื่อประเภทใหม่'); return; }

                const typeForm = new FormData();
                typeForm.append('name_th', newName);
                typeForm.append('name_en', document.getElementById('newTypeNameEn').value.trim());
                typeForm.append('category', document.getElementById('newTypeCategory').value);

                const typeRes = await fetch('api/actions.php?action=add_fall_type', { method: 'POST', body: typeForm });
                const typeData = await typeRes.json();
                if (!typeData.success) { alert('เพิ่มประเภทไม่สำเร็จ: ' + typeData.error); return; }
                form.set('fall_type_id', typeData.id);
            }

            const res = await fetch('api/actions.php?action=add_record', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                alert('ข้อผิดพลาด: ' + data.error);
            }
        });

        async function updateStatus(id, newStatus) {
            const form = new FormData();
            form.append('id', id);
            form.append('status', newStatus);
            await fetch('api/actions.php?action=update_record_status', { method: 'POST', body: form });
            location.reload();
        }

        async function deleteRecord(id) {
            const form = new FormData();
            form.append('id', id);
            await fetch('api/actions.php?action=delete_record', { method: 'POST', body: form });
            location.reload();
        }

        async function deleteSubject(id) {
            const form = new FormData();
            form.append('id', id);
            await fetch('api/actions.php?action=delete_subject', { method: 'POST', body: form });
            location.href = 'index.php';
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
