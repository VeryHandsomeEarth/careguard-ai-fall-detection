# 🛡️ CareGuard AI: Elderly Fall Detection Device with Embedded Artificial Intelligence on a Microcontroller
### อุปกรณ์ตรวจจับการล้มของผู้สูงอายุด้วยปัญญาประดิษฐ์ฝังตัวบนไมโครคอนโทรลเลอร์

[![ESP32](https://img.shields.io/badge/Hardware-ESP32%20Dual--Core-red?logo=espressif)](https://www.espressif.com/)
[![TinyML](https://img.shields.io/badge/AI-TinyML%20MLP-blue?logo=tensorflow)](https://scikit-learn.org/)
[![FreeRTOS](https://img.shields.io/badge/RTOS-FreeRTOS%20Dual--Core-orange)](https://www.freertos.org/)
[![PlatformIO](https://img.shields.io/badge/IDE-PlatformIO-yellow?logo=platformio)](https://platformio.org/)
[![PHP](https://img.shields.io/badge/Backend-PHP%20%2F%20MySQL-777BB4?logo=php)](https://www.php.net/)
[![Leaflet](https://img.shields.io/badge/Maps-Leaflet%20%2F%20OpenStreetMap-199900?logo=leaflet)](https://leafletjs.com/)
[![License](https://img.shields.io/badge/License-Academic%20Research-green)](#)

---

## 📖 บทคัดย่อ / Overview

โครงงานนี้นำเสนอการพัฒนาระบบและอุปกรณ์อัจฉริยะสำหรับตรวจจับการล้มของผู้สูงอายุแบบพกพา โดยใช้สถาปัตยกรรม **Edge AI (TinyML)** ฝังตัวทำงานบนไมโครคอนโทรลเลอร์ **ESP32** ร่วมกับเซ็นเซอร์วัดความเร่งและไจโรสโคป 6 แกน (**MPU-6050**) ทำให้สามารถประมวลผลวิเคราะห์การล้มได้แบบเรียลไทม์บนอุปกรณ์โดยไม่ต้องพึ่งพาการเชื่อมต่ออินเทอร์เน็ตในการวินิจฉัย

ระบบทำงานบนระบบปฏิบัติการเวลาจริง **FreeRTOS Dual-Core** โดยแยกแกนประมวลผลการอ่านเซ็นเซอร์และการตรวจจับด้วยโมเดลโครงข่ายประสาทเทียม (MLP) ออกจากแกนประมวลผลเครือข่ายและระบบระบุตำแหน่งพิกัดดาวเทียม (**GPS NEO-7M**) อย่างอิสระ เมื่อตรวจพบเหตุการณ์การล้ม อุปกรณ์จะส่งเสียงไซเรนเตือน พร้อมส่งพิกัดภูมิศาสตร์และแจ้งเตือนฉุกเฉินแบบทันทีทันใดไปยัง **CareGuard Web Dashboard** และ **Telegram Bot** ช่วยให้ผู้ดูแลสามารถเข้าช่วยเหลือผู้สูงอายุได้อย่างรวดเร็วและปลอดภัย

---

## 🖼️ สถาปัตยกรรมระบบ (System Architecture)

### 1. ภาพรวมสถาปัตยกรรมทั้งระบบ (System Architecture Overview)
![System Architecture Overview](docs/images/System%20Architecture%20overview.png)

### 2. ไดอะแกรมโมเดลปัญญาประดิษฐ์ฝังตัว (TinyML Model Diagram)
![TinyML Model Diagram](docs/images/TinyML%20model%20diagram.png)

### 3. โฟลว์ชาร์ตกระบวนการทำงานหลัก (Main Workflow Flowchart)
![Main Workflow Flowchart](docs/images/Main%20workflow%20flowchart.png)

---

## ✨ คุณสมบัติเด่นของระบบ (Key Highlights)

- 🧠 **On-Device TinyML Inference**: วิเคราะห์การเคลื่อนไหวผ่านโมเดล MLP Neural Network (18 Inputs $\rightarrow$ 32 $\rightarrow$ 16 $\rightarrow$ 1 Output) ทำงานตรงบน ESP32 ได้โดยไม่ต้องพึ่งพาคลาวด์
- ⚡ **FreeRTOS Dual-Core Architecture**:
  - **Core 1 (imuFallTask - Priority High)**: ประมวลผล IMU MPU-6050 ที่ความถี่สุ่ม 50Hz (20ms Window) สกัด 18 คุณลักษณะ ตรวจจับทิศทางการล้ม และควบคุมเสียงเตือน ปราศจากการถูกบล็อกโดย I/O หรือเน็ตเวิร์ก
  - **Core 0 (netGpsTask - Priority Normal)**: อ่านสัญญาณดาวเทียม GPS NEO-7M ซิงค์พิกัดแบบเรียลไทม์ รับส่งข้อความผ่าน FreeRTOS Queue ส่งข้อมูลขึ้น Web Dashboard และส่งแจ้งเตือน Telegram Bot
- 📍 **ระบบระบุตำแหน่งคู่ (Dual Positioning System)**:
  - **กลางแจ้ง (Outdoor)**: พิกัดจากดาวเทียมจริงผ่าน GPS Module NEO-7M (ระดับความแม่นยำสูง)
  - **ภายในอาคาร (Indoor)**: สลับเข้าสู่ระบบ Wi-Fi Geolocation / RSSI Tracking อัตโนมัติเมื่อสัญญาณดาวเทียมถูกบดบัง
- 🔔 **การแจ้งเตือนหลายระดับ (Multi-Channel Alert)**:
  - ไซเรนเตือนบนอุปกรณ์ (Buzzer ขา D5) พร้อมปุ่มกดยืนยันการช่วยเหลือ (D18 Button)
  - แจ้งเตือนสดบน Web Monitoring Dashboard พร้อมเสียง Siren และหมุดกะพริบบนแผนที่
  - แจ้งเตือนฉุกเฉินผ่าน Telegram Bot พร้อมลิงก์ Google Maps ไปยังจุดเกิดเหตุทันที
- 👥 **ระบบแดชบอร์ดแบบแบ่งบทบาท (Role-Based Access Control)**:
  - **Admin**: ดูภาพรวมอุปกรณ์ทุกตัวในระบบ จัดการสมาชิก ตรวจสอบสถานะการเชื่อมต่อ และดูประวัติการล้มย้อนหลัง
  - **User (ผู้ดูแลทั่วไป)**: เข้าถึงเฉพาะอุปกรณ์ที่จับคู่กับตนเอง รับการแจ้งเตือน และดูตำแหน่งของผู้สูงอายุในความดูแล

---

## 📂 โครงสร้างคลังไฟล์ (Repository Structure)

```text
careguard-ai-fall-detection/
│
├── README.md                                    # เอกสารแนะนำและคู่มือการใช้งานระบบ
├── .gitignore                                   # กรองไฟล์ขยะ build artifacts และข้อมูลลับ
│
├── CollectData/                                 # [โมดูลเก็บข้อมูลสำหรับการฝึกสอน AI]
│   ├── CollectData_Hardware/                    # เฟิร์มแวร์ ESP32 เก็บข้อมูลดิบเซ็นเซอร์ MPU-6050 รุ่น 1
│   ├── CollectData_HardwareV2/                  # เฟิร์มแวร์ ESP32 เก็บข้อมูลดิบเซ็นเซอร์ MPU-6050 รุ่น 2
│   └── CollectData_Web/                         # ระบบ Web Application บันทึกรอบการทดลองเก็บข้อมูลอาสาสมัคร
│       ├── cd_trial.php                         # จัดการรอบการทดลอง (Trials)
│       ├── record.php                           # หน้าบันทึกสตรีมข้อมูลเซ็นเซอร์
│       ├── subject.php                          # จัดการข้อมูลประวัติอาสาสมัคร
│       ├── devices.php                          # ระบบเชื่อมต่ออุปกรณ์เก็บข้อมูล
│       ├── setup.sql                            # โครงสร้างฐานข้อมูลสำหรับเก็บ Dataset
│       └── config.example.php                   # ตัวอย่างไฟล์ตั้งค่าการเชื่อมต่อฐานข้อมูล
│
├── CareGuardAi/                                 # [โมดูลระบบตรวจจับการล้มและมอนิเตอร์ริ่ง]
│   ├── Hardware1/                               # เฟิร์มแวร์อุปกรณ์หลัก (TinyML + GPS NEO-7M + ไซเรน)
│   │   ├── src/
│   │   │   ├── main.cpp                         # ซอร์สโค้ดหลัก FreeRTOS Dual-Core & Task Scheduling
│   │   │   ├── fall_detection.h                 # อัลกอริทึมการสกัด 18 Features และระบบตรวจจับสำรอง
│   │   │   └── fall_model.h                     # โมเดล MLP Weights & Biases ภาษา C++ บน ESP32
│   │   └── platformio.ini                       # คอนฟิกูเรชัน PlatformIO สำหรับบอร์ด ESP32
│   │
│   ├── Hardware2/                               # เฟิร์มแวร์อุปกรณ์เสริม (TinyML + Wi-Fi RSSI + นับก้าว)
│   │   ├── src/
│   │   │   ├── main.cpp                         # ซอร์สโค้ดระบบนับก้าว ตรวจจับการล้ม และส่ง RSSI
│   │   │   ├── fall_detection.h                 # โมดูลวิเคราะห์การล้ม
│   │   │   └── fall_model.h                     # โมเดล TinyML Weights
│   │   └── platformio.ini                       # คอนฟิกูเรชัน PlatformIO
│   │
│   └── SoftwarePHP/                             # ระบบ Web Application มอนิเตอร์ริ่งและแดชบอร์ดหลัก
│       ├── api/                                 # RESTful API Endpoints (auth, falls, gps, devices, ฯลฯ)
│       ├── config/                              # การตั้งค่าฐานข้อมูล, Geolocation, Telegram, Session
│       ├── public/                              # หน้าบ้าน UI แดชบอร์ด (index.html, แผนที่ Leaflet, JS/CSS)
│       ├── setup.sql                            # สคริปต์สร้างตารางฐานข้อมูล CareGuard AI
│       └── .env.example                         # แม่แบบไฟล์การตั้งค่าสภาพแวดล้อมระบบ
│
├── dataset/                                     # [ชุดข้อมูลสำหรับการทดลองและวิจัย]
│   ├── SisFall_Dataset/                         # ชุดข้อมูลมาตรฐาน SisFall Benchmark (SA01-SA23, SE01-SE15)
│   └── CollectData_Web_Dataset/                 # ชุดข้อมูลจริงจากการทดลองกับอาสาสมัคร 13 ท่าน (CSV Records)
│
└── docs/                                        # [เอกสารประกอบโครงงานและงานวิจัย]
    ├── ปริญญานิพนธ์_ฉบับสมบูรณ์_CareGuardAI_TinyML_V2.docx  # รายงานปริญญานิพนธ์ฉบับสมบูรณ์
    ├── CareGuard_Project_Presentation.pptx                  # สไลด์นำเสนอโครงงาน
    └── images/                                              # ไดอะแกรมและแผนภาพประกอบระบบ
        ├── System Architecture overview.png
        ├── TinyML model diagram.png
        └── Main workflow flowchart.png
```

---

## 🧠 โมเดล TinyML และการตรวจจับการล้ม (TinyML Fall Detection)

### 1. การสกัดคุณลักษณะ (Feature Extraction)
ระบบเก็บข้อมูลจาก MPU-6050 (ความเร่ง $a_x, a_y, a_z$ และความเร็วเชิงมุม $g_x, g_y, g_z$) โดยใช้ Sliding Window ขนาด 50 ตัวอย่าง (1 วินาที ที่ 50Hz) เพื่อคำนวณ 18 คุณลักษณะสำคัญ:
1. Signal Vector Magnitude ของความเร่ง ($SVM_{acc} = \sqrt{a_x^2 + a_y^2 + a_z^2}$)
2. Signal Vector Magnitude ของไจโร ($SVM_{gyro} = \sqrt{g_x^2 + g_y^2 + g_z^2}$)
3. ค่าเฉลี่ย (Mean) ของแต่ละแกน ($a_x, a_y, a_z, g_x, g_y, g_z$)
4. ค่าสูงสุด (Max) และต่ำสุด (Min)
5. ความแปรปรวน (Variance)
6. Signal Magnitude Area (SMA)

### 2. โครงสร้างโครงข่ายประสาทเทียม (MLP Neural Network Architecture)
- **Input Layer**: 18 Features (ผ่านการทำ Standard Normalization)
- **Hidden Layer 1**: 32 Neurons (Activation: ReLU)
- **Hidden Layer 2**: 16 Neurons (Activation: ReLU)
- **Output Layer**: 1 Neuron (Activation: Sigmoid $\rightarrow$ Fall Probability $[0.0, 1.0]$)
- **Forward Pass C++ Implementation**: ทำงานผ่านฟังก์ชันเวกเตอร์โดยตรงบน ESP32 ใช้เวลาประมวลผลต่อรอบไม่เกิน **5 มิลลิวินาที**

### 3. ระบบสำรอง (Threshold-based Fallback)
เพื่อความปลอดภัยสูงสุด ระบบมีกลไกตรวจสอบค่าความเร่งกระแทก (Impact Acceleration > 2.5g) และการเปลี่ยนระนาบมุมเอียง (Orientation Change > 60 องศา) ควบคู่กับค่าความมั่นใจของโมเดล AI

---

## 🔌 ข้อมูลฮาร์ดแวร์และการเชื่อมต่อพิน (Hardware Pinouts)

### Hardware 1 (อุปกรณ์ตรวจจับหลัก พร้อม GPS และไซเรน)
| โมดูล / อุปกรณ์ | พินบน ESP32 | หน้าที่การทำงาน |
|----------------|------------|----------------|
| **MPU-6050 (SDA)** | GPIO 21 | ข้อมูลเซ็นเซอร์ตรวจจับการเคลื่อนไหว (I2C) |
| **MPU-6050 (SCL)** | GPIO 22 | สัญญาณนาฬิกา I2C |
| **GPS NEO-7M (TX)** | GPIO 16 (RX2) | รับพิกัดดาวเทียม NMEA เข้าไมโครคอนโทรลเลอร์ |
| **GPS NEO-7M (RX)** | GPIO 17 (TX2) | ส่งคำสั่งตั้งค่าไปยังโมดูล GPS |
| **Buzzer / Siren** | GPIO 5 | ส่งเสียงเตือนภัยฉุกเฉินเมื่อตรวจพบการล้ม |
| **Emergency Button** | GPIO 18 | ปุ่มยืนยันการช่วยเหลือ / ยกเลิกการเตือนฉุกเฉิน |

---

## 📊 ชุดข้อมูลที่ใช้ในงานวิจัย (Datasets)

1. **SisFall Benchmark Dataset**:
   - ชุดข้อมูลมาตรฐานสำหรับการวิจัยการล้มและกิจกรรมในชีวิตประจำวัน (ADLs)
   - ประกอบด้วยข้อมูลจากอาสาสมัคร 38 คน แบ่งเป็นวัยผู้ใหญ่หนุ่มสาว 23 คน (SA01–SA23) และผู้สูงอายุ 15 คน (SE01–SE15)
   - ครอบคลุม 19 กิจกรรม ADL (เดิน, วิ่ง, ลุกนั่ง, ก้าวขึ้นบันได ฯลฯ) และ 15 รูปแบบการล้ม (ล้มไปข้างหน้า, หงายหลัง, ล้มตะแคง ฯลฯ) รวมกว่า 4,700 ไฟล์
2. **CollectData_Web_Dataset (Custom Empirical Dataset)**:
   - ข้อมูลการทดลองที่บันทึกผ่านระบบ `CollectData_Web` ร่วมกับอุปกรณ์ตรวจจับจริง
   - บันทึกการทดลองจากการจำลองกิจกรรมและการล้มของอาสาสมัครจำนวน 13 คน กว่า 50 ชุดข้อมูล ในรูปแบบ CSV พร้อมการติดป้ายกำกับ (Labeling)

---

## 🚀 วิธีการติดตั้งและเริ่มต้นใช้งาน (Getting Started)

### 1. การติดตั้งเฟิร์มแวร์ ESP32 (Hardware1 & Hardware2)
1. ติดตั้ง [VS Code](https://code.visualstudio.com/) พร้อมส่วนขยาย [PlatformIO IDE](https://platformio.org/)
2. เปิดโฟลเดอร์ `CareGuardAi/Hardware1` หรือ `CareGuardAi/Hardware2`
3. เชื่อมต่อบอร์ด ESP32 ผ่านสาย Micro-USB / Type-C
4. คอมไพล์และแฟลชเฟิร์มแวร์:
   ```bash
   pio run --target upload
   ```
5. เมื่อเปิดเครื่องครั้งแรก ESP32 จะปล่อยสัญญาณ Wi-Fi Access Point ชื่อ `CareGuard_Setup` ให้เชื่อมต่อเพื่อตั้งค่า Wi-Fi และ URL ของเซิร์ฟเวอร์

### 2. การติดตั้งระบบเว็บเซิร์ฟเวอร์ (SoftwarePHP)
1. อัปโหลดโฟลเดอร์ `CareGuardAi/SoftwarePHP` ไปยัง Web Server (Apache/Nginx) ที่รองรับ PHP 7.4+ และ MySQL
2. นำเข้าฐานข้อมูล:
   - สร้างฐานข้อมูล MySQL
   - อิมพอร์ตไฟล์ `CareGuardAi/SoftwarePHP/setup.sql`
3. คัดลอกและตั้งค่าสภาพแวดล้อม:
   ```bash
   cp .env.example .env
   ```
   แก้ไขไฟล์ `.env` ใส่ข้อมูลเชื่อมต่อ MySQL, API Key, และ Telegram Bot Token
4. เข้าใช้งานแดชบอร์ดผ่านเบราว์เซอร์ที่ URL ที่คุณติดตั้ง

---

## 👥 ผู้จัดทำและสถาบันการศึกษา (Authors & Credits)

- **โครงงาน**: อุปกรณ์ตรวจจับการล้มของผู้สูงอายุด้วยปัญญาประดิษฐ์ฝังตัวบนไมโครคอนโทรลเลอร์ (Elderly fall detection device with embedded artificial intelligence on a microcontroller)
- **ระบบย่อ**: CareGuard AI — Elderly Fall Detection System
- **สถาบัน**: สาขาวิชาวิศวกรรมคอมพิวเตอร์ คณะวิศวกรรมศาสตร์ มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน วิทยาเขตขอนแก่น (RMUTI Khon Kaen)
- **บัญชี GitHub**: [@thanapatle-rmuti](https://github.com/thanapatle-rmuti)

---
*เอกสารและซอร์สโค้ดนี้จัดทำขึ้นเพื่อการศึกษาและการวิจัยทางวิชาการ*
