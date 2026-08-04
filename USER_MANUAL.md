# 📘 คู่มือระบบ IT Asset Management System
### Internal IT Tool — ฉบับสมบูรณ์สำหรับพนักงาน IT ใหม่

> **Version:** 2.0 · **Last Updated:** August 2026  
> **Tech Stack:** PHP 8+ · SQLite3 · Bootstrap 5 · Vanilla JS  
> **ผู้ใช้งาน:** แผนก IT เท่านั้น (ระบบ Internal ไม่เปิดสาธารณะ)

---

## 📑 สารบัญ

1. [ภาพรวมระบบ](#1-ภาพรวมระบบ)
2. [การเข้าใช้งานและ Edit Mode](#2-การเข้าใช้งานและ-edit-mode)
3. [หน้า Dashboard (หน้าแรก)](#3-หน้า-dashboard-หน้าแรก)
4. [จัดการพนักงาน (Users)](#4-จัดการพนักงาน-users)
5. [จัดการอุปกรณ์หลัก (Device Assets)](#5-จัดการอุปกรณ์หลัก-device-assets)
6. [จัดการอุปกรณ์เสริม (Accessories)](#6-จัดการอุปกรณ์เสริม-accessories)
7. [จัดการซอฟต์แวร์และ License (Software)](#7-จัดการซอฟต์แวร์และ-license-software)
8. [จัดการของสิ้นเปลือง (Consumables)](#8-จัดการของสิ้นเปลือง-consumables)
9. [ใบรับมอบ/คืนอุปกรณ์ (Handover Portal)](#9-ใบรับมอบคืนอุปกรณ์-handover-portal)
10. [พิมพ์เอกสาร Certificate (Print)](#10-พิมพ์เอกสาร-certificate-print)
11. [ขั้นตอนลาออก (Resignation Workflow)](#11-ขั้นตอนลาออก-resignation-workflow)
12. [Exchange Portal (เคาน์เตอร์ยืม-คืน)](#12-exchange-portal-เคาน์เตอร์ยืม-คืน)
13. [Incoming Orders (ติดตามของสั่งซื้อ)](#13-incoming-orders-ติดตามของสั่งซื้อ)
14. [Maintenance (ซ่อมบำรุง)](#14-maintenance-ซ่อมบำรุง)
15. [2D Map (แผนผังชั้น)](#15-2d-map-แผนผังชั้น)
16. [Audit Log (ประวัติทั้งหมด)](#16-audit-log-ประวัติทั้งหมด)
17. [Export & Import (นำเข้า/ส่งออกข้อมูล)](#17-export--import-นำเข้าส่งออกข้อมูล)
18. [Barcode & QR Scanner](#18-barcode--qr-scanner)
19. [Settings (ตั้งค่าระบบ)](#19-settings-ตั้งค่าระบบ)
20. [สถานะอุปกรณ์ทั้งหมด](#20-สถานะอุปกรณ์ทั้งหมด)
21. [FAQ & แก้ปัญหาเบื้องต้น](#21-faq--แก้ปัญหาเบื้องต้น)

---

## 1. ภาพรวมระบบ

### ระบบนี้ทำอะไรได้?
ระบบ IT Asset Management ใช้สำหรับจัดการทรัพย์สิน IT ทั้งหมดขององค์กร ตั้งแต่:

| หมวด | ตัวอย่าง |
|------|---------|
| **Device Assets** (อุปกรณ์หลัก) | Laptop, Desktop PC, Server, Switch, Printer, Tag Printer |
| **Accessories** (อุปกรณ์เสริม) | Monitor, Keyboard, Mouse, Webcam, Docking Station, Headset |
| **Software & Licenses** | Microsoft 365, AutoCAD, SAP, Dynamics AX พร้อม License Key |
| **Consumables** (ของสิ้นเปลือง) | หมึกพิมพ์, สาย HDMI, สาย LAN, กระดาษ A4 |
| **Digital Accounts** (ทรัพย์สินดิจิทัล) | Corporate Email, VPN, Remote Access |

### Architecture
```
📁 /Asset_mgr2/
├── index.php              → Dashboard หน้าแรก
├── users.php              → จัดการพนักงาน
├── assets.php             → จัดการอุปกรณ์หลัก
├── accessories.php        → จัดการอุปกรณ์เสริม
├── software.php           → จัดการซอฟต์แวร์ & License
├── consumables.php        → จัดการของสิ้นเปลือง
├── handover_portal.php    → ศูนย์รวมใบรับมอบ/คืน
├── print_handover.php     → พิมพ์ใบ Certificate
├── resign_review.php      → ตรวจสอบก่อนลาออก
├── exchange.php           → เคาน์เตอร์ยืม-คืนด่วน
├── incoming.php           → ติดตามของสั่งซื้อ
├── maintenance.php        → ซ่อมบำรุง & RMA
├── audit.php              → ประวัติ Audit Log
├── export.php             → ส่งออก CSV / Backup
├── import.php             → นำเข้าจาก CSV
├── map_manager.php        → จัดการแผนผัง 2D
├── map_view.php           → ดูแผนผังแบบ Interactive
├── settings.php           → ตั้งค่าระบบทั้งหมด
├── scanner.php            → สแกน Barcode ด้วยกล้อง
├── scan.php               → หน้าตรวจสอบจาก QR Code
├── actions.php            → ตัวประมวลผล POST ทั้งหมด
├── attachments.php        → อัปโหลด/ดาวน์โหลดไฟล์แนบ
├── print_labels.php       → พิมพ์สติกเกอร์ Barcode
├── /db/it_assets.db       → ฐานข้อมูล SQLite
├── /includes/             → ไฟล์ระบบ (DB, Auth, Layout)
├── /assets/               → CSS, JS
└── /uploads/              → ไฟล์แนบที่อัปโหลด
```

### ข้อจำกัดของข้อมูล (กฎเหล็ก)
- **Hostname** และ **Serial Number** ต้องไม่ซ้ำกันในระบบ
- **สถานะอุปกรณ์** มีได้แค่ 5 สถานะ: `in_stock`, `deployed`, `repair`, `retired`, `lost`
- **ประเภทกรรมสิทธิ์** มีได้แค่ 3 แบบ: `Buy`, `Rent`, `Borrow`
- **ทุกการเปลี่ยนแปลง** จะถูกบันทึกลง Audit Log อัตโนมัติ

---

## 2. การเข้าใช้งานและ Edit Mode

### เปิดใช้งานระบบ
ระบบเปิดใช้งานผ่าน Web Browser:
```
http://localhost:8030
```
*(หรือ IP Address ของเครื่อง Server ที่รันระบบ)*

### โหมดการใช้งาน
ระบบมี 2 โหมด:

| โหมด | สิทธิ์ | วิธีเข้า |
|------|-------|---------|
| **🔒 Read-Only Mode** | ดูข้อมูลได้อย่างเดียว ไม่สามารถแก้ไข/เพิ่ม/ลบได้ | เปิดเว็บมาจะเป็นโหมดนี้เสมอ |
| **✏️ Edit Mode** | ทำได้ทุกอย่าง (เพิ่ม, แก้ไข, ลบ, แจก, คืน) | กดปุ่ม 🔒 ที่มุมขวาบน แล้วใส่ PIN |

### วิธีเปิด Edit Mode
1. กดไอคอน **🔒 กุญแจ** ที่มุมขวาบนของหน้าจอ
2. ใส่รหัส **Security PIN** (ค่าเริ่มต้น: `123456`)
3. กด **Unlock** → ไอคอนจะเปลี่ยนเป็น **🔓** สีเขียว
4. Edit Mode จะหมดอายุอัตโนมัติตามเวลาที่ตั้งไว้ใน Settings

> [!IMPORTANT]
> ปุ่มที่มี class `edit-only` จะมองเห็นและกดได้เฉพาะใน Edit Mode เท่านั้น เช่น ปุ่มเพิ่ม/แก้ไข/ลบข้อมูล

### วิธีเปลี่ยน PIN
ไปที่ **Settings** → แท็บ **Access & Backup** → กรอก PIN ใหม่ → บันทึก

---

## 3. หน้า Dashboard (หน้าแรก)

**URL:** `http://localhost:8030/index.php`

### สิ่งที่เห็นเมื่อเปิด Dashboard

#### 🔢 KPI Cards (ตัวเลขสำคัญด้านบน)
| Card | ความหมาย |
|------|---------|
| **Total Hardware** | จำนวนอุปกรณ์หลัก (Assets) ทั้งหมดในระบบ |
| **Deployed** | จำนวนอุปกรณ์ที่แจกให้พนักงานไปแล้ว |
| **In Stock** | จำนวนอุปกรณ์ที่คงเหลือในคลัง |
| **Repair / Lost** | จำนวนอุปกรณ์ที่กำลังซ่อม + หาย |

#### ⚠️ Needs Attention (รายการที่ต้องดูแล)
- **Expiring Leases** — เครื่องเช่า (Rent) ที่กำลังจะหมดสัญญา
- **Expiring Warranties** — อุปกรณ์ที่ Warranty กำลังจะหมด
- **Expiring Software** — License ซอฟต์แวร์ที่กำลังจะหมดอายุ
- **Overdue Loans** — อุปกรณ์ที่ยืมไปแล้วเกินกำหนดคืน
- **Repair ETAs** — อุปกรณ์ที่ส่งซ่อมแล้วเกินกำหนด ETA

#### 🔍 Universal Search (ค้นหากลาง)
- ช่องค้นหาตรงกลาง สามารถค้นหาได้ด้วย: **ชื่อพนักงาน**, **Hostname**, **Serial Number**, **Service Tag**
- รองรับ **2D Barcode Scanner** (ยิง Barcode แล้ว Enter อัตโนมัติ)

---

## 4. จัดการพนักงาน (Users)

**URL:** `http://localhost:8030/users.php`

### 4.1 เพิ่มพนักงานใหม่
1. เปิด Edit Mode
2. กดปุ่ม **"+ Add Employee"**
3. กรอกข้อมูล:
   - **Employee ID** — รหัสพนักงาน (ห้ามซ้ำ)
   - **Full Name** — ชื่อ-นามสกุล (ภาษาอังกฤษ)
   - **Department** — แผนก
   - **Email** — อีเมลองค์กร (เช่น `john.doe@example.com`)
   - **Location** — สถานที่ประจำ
4. กด **Save**

> [!IMPORTANT]
> **อีเมลสำคัญมาก!** อีเมลที่กรอกตรงนี้จะถูกใช้เป็น "ทรัพย์สินดิจิทัล" อัตโนมัติในทุกใบ Certificate (Handover, Return, Resignation Clearance) และในหน้า Resignation Review

### 4.2 แจกอุปกรณ์ให้พนักงาน (Quick Assign)
1. เปิด Edit Mode
2. คลิกที่ชื่อพนักงานเพื่อกางรายละเอียด (Expandable Row)
3. จะเห็นช่อง **"Scan or type serial/hostname..."** ← ช่องนี้จะ autofocus กระพริบรอเสมอ
4. **ยิง Barcode Scanner** ที่ตัวเครื่อง หรือพิมพ์ Serial/Hostname แล้ว Enter
5. ระบบจะ:
   - เปลี่ยนสถานะอุปกรณ์เป็น `deployed`
   - ผูกชื่อพนักงาน
   - บันทึก Log อัตโนมัติ

### 4.3 รับคืนอุปกรณ์ (Return)
1. เปิด Edit Mode
2. คลิกที่ชื่อพนักงานเพื่อกางรายละเอียด
3. จะเห็นรายการอุปกรณ์ทั้งหมดที่คนนี้ถือ
4. กดปุ่ม **🔄 Return** ข้างชิ้นที่ต้องการ
5. ระบบจะคืนของเข้าสต็อก `in_stock` ทันที

### 4.4 ดูพนักงานลาออก
- กดแท็บ **"Resigned"** ด้านบนตาราง
- จะแสดงรายชื่อพนักงานที่ถูกประมวลผลลาออกแล้ว พร้อมวันที่และหมายเหตุ

### 4.5 พิมพ์ใบ Handover / Return
- คลิกกางรายละเอียดพนักงาน
- กดปุ่ม **📄 Handover** / **📄 Exchange** / **📄 Return**
- ระบบจะเปิดหน้า Certificate พร้อมพิมพ์ในแท็บใหม่

---

## 5. จัดการอุปกรณ์หลัก (Device Assets)

**URL:** `http://localhost:8030/assets.php`

### 5.1 เพิ่มอุปกรณ์ใหม่
1. เปิด Edit Mode
2. กดปุ่ม **"+ Add Asset"**
3. กรอกข้อมูลที่จำเป็น:
   - **Category** — ประเภท (เช่น Laptop, Desktop, Switch, Printer)
   - **Hostname** — ชื่อเครื่อง (ห้ามซ้ำ)
   - **Serial Number** — หมายเลข Serial (ห้ามซ้ำ)
   - **Service Tag** — Service Tag (ถ้ามี)
   - **Model** — รุ่นเครื่อง
   - **Ownership** — กรรมสิทธิ์: Buy / Rent / Borrow
   - **Status** — สถานะเริ่มต้น (ปกติจะเป็น `in_stock`)
   - **Location** — สถานที่เก็บ
4. กด **Save**

### 5.2 Bulk Actions (ทำหลายชิ้นพร้อมกัน)
ติ๊กเลือกหลายรายการแล้วใช้ Toolbar ด้านบน:

| ปุ่ม | การทำงาน |
|------|---------|
| **Assign Location** | กำหนด Location ให้หลายชิ้นพร้อมกัน |
| **Assign User** | แจกของให้พนักงานหลายชิ้นพร้อมกัน |
| **Change Status** | เปลี่ยนสถานะหลายชิ้น |
| **Batch Edit** | แก้ไขข้อมูลหลายชิ้นพร้อมกัน |
| **Vendor Return** | บันทึกการส่งคืน Vendor |
| **Delete** | ลบถาวร (ต้องระบุเหตุผล) |
| **Export CSV** | ส่งออกเป็นไฟล์ CSV |
| **Print Labels** | พิมพ์สติกเกอร์ Barcode |

### 5.3 Actions รายชิ้น (คลิกปุ่ม ⋮ ท้ายแถว)
- **View History** — ดูประวัติทั้งหมด
- **Assign** — แจกให้พนักงาน
- **Return to Stock** — คืนเข้าคลัง
- **Borrow/Loan** — ให้ยืมพร้อมกำหนดวันคืน
- **Send to Repair** — ส่งซ่อม (ระบุร้าน/ETA)
- **Mark Fixed** — ซ่อมเสร็จ กลับเข้าสต็อก
- **RMA Replace** — เปลี่ยนเครื่องตาม RMA Warranty
- **Vendor Return** — ส่งคืน Vendor
- **Retire** — ปลดระวาง
- **Delete** — ลบถาวร

### 5.4 ดูประวัติอุปกรณ์ (History)
**URL:** `http://localhost:8030/history.php?asset_id=X`

หน้านี้แสดง:
- ข้อมูลทางเทคนิคทั้งหมดของเครื่อง
- อายุเครื่อง, นับถอยหลัง EOL
- แผนที่ 2D แสดงตำแหน่งที่ตั้ง
- ไฟล์แนบ (PDF, รูปภาพ)
- Timeline ย้อนหลังทุกการเคลื่อนไหว

---

## 6. จัดการอุปกรณ์เสริม (Accessories)

**URL:** `http://localhost:8030/accessories.php`

### การทำงานเหมือน Assets แต่สำหรับอุปกรณ์เสริม:
- Monitor, Keyboard, Mouse, Webcam, Docking Station, Headset, Charger ฯลฯ
- สามารถผูกกับ **Parent Asset** ได้ (เช่น Monitor ผูกกับ Laptop)
- มี Bulk Actions เหมือนกัน
- มี Barcode/QR สำหรับ Scan เหมือนกัน

### เพิ่ม Accessory ใหม่
1. เปิด Edit Mode → กด **"+ Add Accessory"**
2. กรอก: Category, Name, Serial, Model, Location
3. (ถ้าต้องการ) เลือก **Parent Asset** เพื่อผูกกับเครื่องหลัก
4. กด **Save**

---

## 7. จัดการซอฟต์แวร์และ License (Software)

**URL:** `http://localhost:8030/software.php`

### โครงสร้างข้อมูล (3 ระดับ)
```
Software (ซอฟต์แวร์)
  └── Batch (ชุดที่ซื้อ / PO)
       └── License Key (รหัส License แต่ละตัว)
```

### ขั้นตอนการเพิ่มซอฟต์แวร์ใหม่
1. **สร้างซอฟต์แวร์:** กด **"+ Add Software"** → กรอกชื่อ, Vendor, หมวดหมู่
2. **สร้าง Batch:** กด **"+ Add Batch"** ในซอฟต์แวร์ที่สร้าง → กรอก PO Number, วันที่ซื้อ, License Type
3. **เพิ่ม License Keys:** กด **"+ Add Keys"** → กรอก License Key ทีละตัว หรือหลายตัว

### แจก License ให้พนักงาน
- ไปที่หน้า **Users** → คลิกกางรายละเอียดพนักงาน
- หรือไปที่ License Key แล้วกด **Assign**

### License Status
| สถานะ | ความหมาย |
|-------|---------|
| `active` | พร้อมใช้งาน (ว่างอยู่) |
| `assigned` | แจกให้พนักงานแล้ว |
| `expired` | หมดอายุ |
| `revoked` | ถูกเพิกถอน |

---

## 8. จัดการของสิ้นเปลือง (Consumables)

**URL:** `http://localhost:8030/consumables.php`

### วิธีใช้งาน
1. **Stock In (รับเข้า):** กดปุ่ม **"+ Stock In"** → เลือกรายการ → กรอกจำนวน
2. **Stock Out (จ่ายออก):** กดปุ่ม **"📤 Stock Out"** → เลือกรายการ → กรอกจำนวน → ระบุชื่อผู้รับ
3. **Audit (ตรวจนับ):** กดปุ่ม **"🔍 Audit"** → กรอกจำนวนจริงที่นับได้

### ระบบคำนวณยอดคงเหลืออัตโนมัติ
```
ยอดคงเหลือ = ผลรวม IN - ผลรวม OUT
```

### แจ้งเตือน Low Stock
- ตั้งค่า threshold ได้ที่ **Settings → Alerts** (ค่าเริ่มต้น: ≤ 5 ชิ้น)

---

## 9. ใบรับมอบ/คืนอุปกรณ์ (Handover Portal)

**URL:** `http://localhost:8030/handover_portal.php`

### หน้านี้คืออะไร?
ศูนย์รวมการจัดการเอกสาร Certificate ทั้งหมดที่ออกให้พนักงาน พร้อมติดตามสถานะการเซ็นเอกสาร

### Summary Cards
| Card | ความหมาย |
|------|---------|
| **Total Certificates** | จำนวนใบ Certificate ทั้งหมดที่ออก |
| **Pending Signatures** | ใบที่ยังไม่ได้เซ็น (รอลายเซ็น) |
| **Signed & Filed** | ใบที่เซ็นแล้วและแนบไฟล์แล้ว |

### ขั้นตอนการทำงาน
1. **ออกใบ Certificate:**
   - กดปุ่ม **"+ Create Slip"**
   - เลือกพนักงาน, เลือกประเภท (Handover / Return / Resignation)
   - ระบบสร้างเลขที่เอกสาร (Doc No.) อัตโนมัติ
   
2. **พิมพ์เอกสาร:**
   - กดปุ่ม **🖨️ Print** ที่รายการ Certificate
   - ระบบจะเปิดหน้า Certificate ขนาด A4 พร้อมพิมพ์

3. **แนบไฟล์เอกสารที่เซ็นแล้ว:**
   - เมื่อพนักงานเซ็นเอกสารจริงแล้ว
   - กดปุ่ม **"📎 Attach Signed Slip"**
   - อัปโหลดไฟล์ PDF หรือรูปภาพ (JPG/PNG)
   - สถานะจะเปลี่ยนเป็น **✅ Signed & Complete**

4. **ดาวน์โหลดเอกสารที่เซ็นแล้ว:**
   - กดลิงก์ชื่อไฟล์ในคอลัมน์ Signed File

---

## 10. พิมพ์เอกสาร Certificate (Print)

### เอกสาร 3 ประเภท

#### 📄 1. IT Asset Handover Certificate (ใบรับมอบ)
**ใช้เมื่อ:** แจกอุปกรณ์ให้พนักงานใหม่ หรือแจกของเพิ่ม

**URL:** `print_handover.php?user_id=X&type=assign`

**เนื้อหาในเอกสาร:**
- หัวกระดาษ: ชื่อบริษัท + โลโก้ + เลขที่เอกสาร
- ตาราง 5 คอลัมน์: NO. | DESCRIPTION | QTY | SERIAL NO. / TAG | REMARK
- รายการอุปกรณ์หลัก + อุปกรณ์เสริม + Software License
- **แถว Microsoft 365 & Corporate Email** (ใส่อีเมลพนักงานอัตโนมัติ)
- ช่องลายเซ็น 4 คน: Employee, HOD, Head of IT, IT Administrator

#### 📄 2. IT Asset Return Certificate (ใบส่งคืน)
**ใช้เมื่อ:** พนักงานนำอุปกรณ์มาคืน หรือเปลี่ยนเครื่อง

**URL:** `print_handover.php?user_id=X&type=return`

**เนื้อหา:** เหมือนใบรับมอบ แต่หัวเอกสารเปลี่ยนเป็น "RETURN CERTIFICATE"

#### 📄 3. IT Resignation Clearance Certificate (ใบคืน + ปิดระบบลาออก)
**ใช้เมื่อ:** พนักงานลาออก ต้องคืนของและปิดสิทธิ์ทั้งหมด

**URL:** `print_handover.php?user_id=X&type=return&reason=resignation`

**เนื้อหาพิเศษเพิ่มเติม:**
- แถว **Microsoft 365 & Corporate Email Account** พร้อมอีเมลจริง
- แถว **Corporate VPN & Remote Network Access**
- **Official IT Resignation Clearance Checklist 4 ข้อ:**
  1. ☐ All assigned IT equipment has been returned and verified
  2. ☐ Corporate email account has been disabled
  3. ☐ VPN and remote network access has been revoked
  4. ☐ ERP / Dynamics AX user access has been terminated

### ฟีเจอร์พิเศษในหน้าพิมพ์
- **คลิกช่อง REMARK** เพื่อพิมพ์ข้อความได้ก่อนพิมพ์ (หรือเว้นว่างไว้เขียนด้วยมือ)
- **กดปุ่ม + เพิ่มรายการ** ที่ด้านล่างตาราง เพื่อเพิ่มรายการ non-inventory (เช่น กุญแจ, บัตร)
- **สี Header/Cell พิมพ์ออกมาครบ** (ระบบบังคับ print-color-adjust)
- **ไม่มี Header/Footer ของ Browser** เมื่อพิมพ์ (ซ่อนอัตโนมัติ)

---

## 11. ขั้นตอนลาออก (Resignation Workflow)

**URL:** `resign_review.php?user_id=X`

### ขั้นตอนเต็มสำหรับ Off-boarding พนักงานลาออก

#### ขั้นที่ 1: เปิดหน้า Resignation Review
- ไปที่ **Users** → คลิกที่พนักงานที่จะลาออก → กด **"🚪 Resign"**
- ระบบจะแสดงรายการทรัพย์สินทั้งหมดของพนักงานแบ่งเป็น 3 หมวด:

| หมวด | รายการ |
|------|--------|
| **Device Assets** | Laptop, Desktop, Printer ทุกชิ้นที่ถือครอง |
| **Accessories** | Monitor, Keyboard, Headset ทุกชิ้น |
| **Digital Accounts & Email** | MS365 + อีเมลองค์กร, VPN, Software License ทั้งหมด |

#### ขั้นที่ 2: เลือกสถานะแต่ละชิ้น
สำหรับแต่ละรายการ เลือก:
- **✅ คืนครบ** — อุปกรณ์คืนสมบูรณ์ → ย้ายเข้า `in_stock`
- **❌ หาย** — อุปกรณ์หาย → เปลี่ยนเป็น `lost`
- **🔧 ชำรุด** — อุปกรณ์พัง → เปลี่ยนเป็น `repair`
- **🚫 เพิกถอนสิทธิ์ (Disable)** — สำหรับ Email/VPN/License

> [!TIP]
> กดปุ่ม **"เลือกทั้งหมด: คืนครบ"** เพื่อติ๊กทุกชิ้นเป็น "คืนครบ" ในคลิกเดียว

#### ขั้นที่ 3: พิมพ์ใบ Clearance Form
- กดปุ่มสีแดง **"🖨️ Print Clearance Form (ใบคืนอุปกรณ์)"** มุมขวาบน
- พิมพ์ลงกระดาษ A4 ให้พนักงานเซ็นรับรอง

#### ขั้นที่ 4: กดยืนยันลาออก
- ระบุ **วันที่ลาออก** + **หมายเหตุ** (ถ้ามี)
- กดปุ่ม **"🚪 ดำเนินการลาออก"**
- ระบบจะ:
  1. เปลี่ยนสถานะอุปกรณ์ทุกชิ้นตามที่เลือก
  2. เพิกถอน Software License ทั้งหมด
  3. บันทึกวันที่ลาออก + หมายเหตุ
  4. ย้ายพนักงานไปหมวด **"Resigned"** ถาวร
  5. บันทึก Log ทั้งหมดอัตโนมัติ

---

## 12. Exchange Portal (เคาน์เตอร์ยืม-คืน)

**URL:** `http://localhost:8030/exchange.php`

### หน้านี้คืออะไร?
หน้าสำหรับการ Check-in / Check-out แบบรวดเร็ว เหมาะสำหรับใช้เป็น **Kiosk** หน้าเคาน์เตอร์ IT

### การใช้งาน
1. **Check-Out (แจกของ):**
   - เลือกพนักงาน → เลือกอุปกรณ์จากสต็อก → กด Assign
   
2. **Check-In (รับคืน):**
   - Scan หรือพิมพ์ Serial/Hostname → กด Return

3. **Recent Activity Feed:**
   - แสดงรายการยืม-คืนล่าสุดแบบ Real-time

---

## 13. Incoming Orders (ติดตามของสั่งซื้อ)

**URL:** `http://localhost:8030/incoming.php`

### วิธีใช้งาน
1. **เพิ่ม Order:** กด **"+ Add Order"** → กรอก Category, Model, จำนวน, Vendor, PO, ETA
2. **รับของ (Proceed/Receive):**
   - เมื่อของมาถึง กดปุ่ม **"📦 Receive"**
   - ระบบจะถามให้กรอก Hostname และ Serial Number ของแต่ละชิ้น
   - กด Confirm → ระบบสร้าง Asset ใหม่ในสถานะ `in_stock` อัตโนมัติ
3. **สถานะ Order:**
   - `ordered` — สั่งแล้ว รอของ
   - `partial` — รับบางส่วนแล้ว
   - `received` — รับครบแล้ว
   - `cancelled` — ยกเลิก

---

## 14. Maintenance (ซ่อมบำรุง)

**URL:** `http://localhost:8030/maintenance.php`

### Dashboard ซ่อมบำรุง
| Card | ความหมาย |
|------|---------|
| **Currently in Repair** | จำนวนชิ้นที่กำลังซ่อมอยู่ |
| **Fixed (90 Days)** | จำนวนชิ้นที่ซ่อมเสร็จใน 90 วันที่ผ่านมา |
| **Avg. Repair Duration** | จำนวนวันเฉลี่ยที่ใช้ซ่อม |
| **Overdue ETAs** | จำนวนชิ้นที่เกินกำหนด ETA |

### ขั้นตอนส่งซ่อม
1. ไปที่ **Assets** → เลือกเครื่อง → กด **"🔧 Send to Repair"**
2. กรอก: ชื่อร้านซ่อม, วันที่ส่ง, ETA (กำหนดรับคืน), หมายเหตุ
3. สถานะเปลี่ยนเป็น `repair`

### ซ่อมเสร็จ
1. ไปที่ **Maintenance** → กดปุ่ม **"✅ Mark Fixed"** ที่รายการนั้น
2. สถานะเปลี่ยนกลับเป็น `in_stock`

---

## 15. 2D Map (แผนผังชั้น)

### Map Manager (จัดการแผนผัง)
**URL:** `http://localhost:8030/map_manager.php`

1. **อัปโหลด Floor Plan:** กด **"+ Upload Map"** → เลือกรูป JPG/PNG
2. **วาด Zone:** คลิกเพื่อวาด Polygon ล้อมรอบพื้นที่ → เลือก Location ที่ต้องการเชื่อมโยง

### Map View (ดูแผนผัง Interactive)
**URL:** `http://localhost:8030/map_view.php`

- แสดง Pin จุดที่ตั้งอุปกรณ์ตาม Location
- Hover ดู Tooltip ข้อมูลอุปกรณ์
- กรองตาม Category
- ลาก Pin เพื่อเปลี่ยนตำแหน่ง (Edit Mode)

---

## 16. Audit Log (ประวัติทั้งหมด)

**URL:** `http://localhost:8030/audit.php`

### ฟีเจอร์
- ค้นหาและกรอง Log ทั้งระบบ
- กรองตาม: **วันที่ From-To**, **ประเภท Action**, **ประเภท Item** (Asset/Accessory/Software)
- รวม Log จากทุกตาราง: `logs`, `software_license_logs`, `consumable_logs`, `incoming_orders`
- แสดง Icon สีตาม Action Type

---

## 17. Export & Import (นำเข้า/ส่งออกข้อมูล)

### Export (ส่งออก)
**URL:** `http://localhost:8030/export.php`

| ตัวเลือก | รายละเอียด |
|----------|-----------|
| **Full Inventory** | ส่งออก Asset ทั้งหมดเป็น CSV |
| **By Category** | เลือกส่งออกเฉพาะ Category |
| **Custom Filter** | กรองตามเงื่อนไขก่อนส่งออก |
| **Employee List** | ส่งออกรายชื่อพนักงาน |
| **Transaction Logs** | ส่งออก Log ทั้งหมด |
| **Database Backup** | ดาวน์โหลดไฟล์ `.db` สำรอง |

> [!NOTE]
> ไฟล์ CSV ที่ส่งออกจะมี UTF-8 BOM เปิดใน Excel ได้โดยไม่ต้องแปลง Encoding

### Import (นำเข้า)
**URL:** `http://localhost:8030/import.php`

1. **ดาวน์โหลด Template:** กดปุ่ม **"📥 Download Template"**
2. **กรอกข้อมูล** ลงใน CSV Template
3. **อัปโหลด CSV** → ระบบจะ Parse และแสดง Preview
4. **ตรวจสอบ Diff:**
   - 🆕 `New` — รายการใหม่
   - 📝 `Update` — รายการที่มีอยู่แล้วจะถูกอัปเดต (แสดง side-by-side diff)
   - ⛔ `Error` — รายการที่มีปัญหา (เช่น ซ้ำ, ข้อมูลไม่ถูกต้อง)
5. **กด Confirm** → บันทึกลงฐานข้อมูล

---

## 18. Barcode & QR Scanner

### พิมพ์สติกเกอร์ Barcode
**URL:** `http://localhost:8030/print_labels.php?ids=1,2,3`

- เลือก Assets จากหน้า Assets → กด **"🏷️ Print Labels"**
- ระบบจะสร้างสติกเกอร์ Barcode แถบบน-ล่าง พร้อมพิมพ์

### Scanner ด้วยกล้อง
**URL:** `http://localhost:8030/scanner.php`

- เปิดกล้อง Webcam เพื่อสแกน Barcode/QR Code
- เมื่อสแกนสำเร็จจะเปิดหน้า Asset Detail อัตโนมัติ

### หน้า Scan (Mobile)
**URL:** `http://localhost:8030/scan.php?id=X`

- หน้า Mobile-Optimized สำหรับดูข้อมูลเครื่องจาก QR Code
- เปิดจากมือถือได้

---

## 19. Settings (ตั้งค่าระบบ)

**URL:** `http://localhost:8030/settings.php`

### Tab 1: 📍 Locations (สถานที่)
- เพิ่ม/ลบ Location (เช่น "Head Office - Floor 1", "Server Room")
- ลบได้เฉพาะ Location ที่ไม่มีอุปกรณ์/พนักงานผูกอยู่

### Tab 2: 🔧 Column Designer (ออกแบบคอลัมน์)
- ลากเรียง, ซ่อน/แสดง, เปลี่ยนชื่อคอลัมน์ในตาราง Assets
- เพิ่ม Custom Column ได้ (ระบบจะ `ALTER TABLE` อัตโนมัติ)

### Tab 3: 🔔 Alerts (แจ้งเตือน)
| การตั้งค่า | ค่าเริ่มต้น | ความหมาย |
|-----------|-----------|---------|
| Lease Alert Days | 30 วัน | แจ้งเตือนก่อนสัญญาเช่าหมด |
| Warranty Alert Days | 30 วัน | แจ้งเตือนก่อน Warranty หมด |
| Software Alert Days | 30 วัน | แจ้งเตือนก่อน License หมดอายุ |
| Consumable Low Stock | 5 ชิ้น | แจ้งเตือนเมื่อของสิ้นเปลืองเหลือน้อย |

### Tab 4: 🎨 Branding (แบรนด์)
- อัปโหลดโลโก้บริษัท (แสดงในใบ Certificate)
- ตั้งชื่อองค์กร + ชื่อแผนก

### Tab 5: 🔐 Access & Backup
| ตัวเลือก | รายละเอียด |
|----------|-----------|
| **Security PIN** | เปลี่ยนรหัส PIN สำหรับ Edit Mode |
| **Session Timeout** | ตั้งเวลาหมดอายุ Edit Mode (5-720 นาที) |
| **QR Code Base URL** | URL สำหรับ QR Code บนสติกเกอร์ |
| **📥 Download DB Backup** | ดาวน์โหลดไฟล์ฐานข้อมูล `.db` |
| **⚠️ Factory Reset** | ลบข้อมูลทั้งหมดและเริ่มใหม่ (ต้องใส่ PIN ยืนยัน) |

---

## 20. สถานะอุปกรณ์ทั้งหมด

### สถานะอุปกรณ์ (Asset/Accessory Status)
| สถานะ | ไอคอน | ความหมาย | เปลี่ยนเป็นได้ |
|-------|------|---------|-------------|
| `in_stock` | 🟢 | อยู่ในคลัง พร้อมแจก | deployed, repair, retired, lost |
| `deployed` | 🔵 | แจกให้พนักงานแล้ว | in_stock (return), repair, retired, lost |
| `repair` | 🟡 | กำลังซ่อม | in_stock (fixed), retired, lost |
| `retired` | ⚫ | ปลดระวางแล้ว | — (สถานะสุดท้าย) |
| `lost` | 🔴 | หาย | — (สถานะสุดท้าย) |

### สถานะ License
| สถานะ | ความหมาย |
|-------|---------|
| `active` | พร้อมใช้งาน (ว่าง) |
| `assigned` | แจกให้พนักงานแล้ว |
| `expired` | หมดอายุ |
| `revoked` | ถูกเพิกถอน |

### ประเภทกรรมสิทธิ์ (Ownership)
| ประเภท | ความหมาย | ข้อสังเกต |
|--------|---------|---------|
| `Buy` | ซื้อขาด | มี Warranty (end_of_life_date) |
| `Rent` | เช่า | มีวันหมดสัญญา (lease_expiry) |
| `Borrow` | ยืม | มีกำหนดคืน (borrow_due_date) |

---

## 21. FAQ & แก้ปัญหาเบื้องต้น

### ❓ ปุ่มเพิ่ม/แก้ไข/ลบ กดไม่ได้
**คำตอบ:** ยังไม่ได้เปิด Edit Mode → กดไอคอน 🔒 มุมขวาบน → ใส่ PIN

### ❓ ลืม Security PIN
**คำตอบ:** ค่าเริ่มต้นคือ `123456` · ถ้าเปลี่ยนแล้วลืม ต้องแก้ไขจากฐานข้อมูลโดยตรง:
```sql
UPDATE system_settings SET setting_value = '123456' WHERE setting_key = 'edit_pin';
```

### ❓ เพิ่ม Asset แล้วขึ้น Error "UNIQUE constraint"
**คำตอบ:** Hostname หรือ Serial Number ซ้ำกับรายการที่มีอยู่แล้ว → ตรวจสอบและแก้ไขให้ไม่ซ้ำ

### ❓ พิมพ์เอกสารแล้วสีไม่ออก
**คำตอบ:** ระบบตั้งค่า `print-color-adjust: exact` ไว้แล้ว แต่ใน Chrome ให้ตรวจสอบว่าไม่ได้ปิด "Background graphics" ในหน้า Print Preview

### ❓ Scanner ยิง Barcode แล้วไม่ทำงาน
**คำตอบ:** ตรวจสอบว่า:
1. Cursor อยู่ในช่อง Input (ควร autofocus อยู่แล้ว)
2. Scanner ตั้งค่าส่ง Enter หลังยิง (Suffix: CR/LF)
3. Barcode/Serial ที่ยิงตรงกับข้อมูลในระบบ

### ❓ อยากสำรองข้อมูล (Backup)
**คำตอบ:** ไปที่ **Settings** → แท็บ **Access & Backup** → กด **"📥 Download Database Backup"** → ระบบจะดาวน์โหลดไฟล์ `it_assets.db` มาให้

### ❓ อยากรีเซ็ตระบบทั้งหมด (Factory Reset)
**คำตอบ:** ไปที่ **Settings** → แท็บ **Access & Backup** → Danger Zone → กด **"⚠️ Factory Reset"** → ใส่ PIN ยืนยัน → ข้อมูลทั้งหมดจะถูกลบและเริ่มต้นใหม่

> [!CAUTION]
> Factory Reset ลบข้อมูลทั้งหมดถาวร! ดาวน์โหลด Backup ก่อนเสมอ

### ❓ ไฟล์ฐานข้อมูลอยู่ที่ไหน?
**คำตอบ:** `/db/it_assets.db` (SQLite3 Database)

### ❓ ไฟล์แนบที่อัปโหลดเก็บที่ไหน?
**คำตอบ:** `/uploads/attachments/` (PDF, รูปภาพ, เอกสารที่แนบ)

### ❓ จะเพิ่ม Custom Column ในตาราง Asset ได้ยังไง?
**คำตอบ:** **Settings** → แท็บ **Column Designer** → กด **"+ Add Custom Column"** → ตั้งชื่อ → ระบบจะเพิ่มคอลัมน์ในฐานข้อมูลอัตโนมัติ

---

## 📌 Quick Reference Card (สรุปการใช้งานด่วน)

| ทำอะไร | ไปที่ไหน | ขั้นตอน |
|--------|---------|--------|
| เพิ่มพนักงานใหม่ | Users → + Add Employee | กรอกข้อมูล + อีเมล → Save |
| แจกเครื่องให้คน | Users → คลิกชื่อ → Scan | ยิง Barcode ที่เครื่อง → Enter |
| รับคืนเครื่อง | Users → คลิกชื่อ → Return | กดปุ่ม 🔄 ข้างชิ้นนั้น |
| เพิ่มอุปกรณ์ใหม่ | Assets → + Add Asset | กรอก Hostname/Serial → Save |
| ส่งซ่อม | Assets → ⋮ → Send to Repair | กรอกร้านซ่อม/ETA → Save |
| ซ่อมเสร็จ | Maintenance → ✅ Mark Fixed | กดปุ่ม 1 คลิก |
| พิมพ์ใบรับมอบ | Users → คลิกชื่อ → 📄 Handover | Print ในแท็บใหม่ |
| ลาออก (Off-board) | Users → 🚪 Resign → Review | เลือกสถานะทุกชิ้น → ยืนยัน |
| สำรองข้อมูล | Settings → Access & Backup | กด Download DB Backup |
| ดู Log ทั้งหมด | Audit Log | ค้นหา/กรองตามเงื่อนไข |

---

> 📝 **หมายเหตุ:** คู่มือนี้ครอบคลุมทุกฟีเจอร์ของระบบ ณ เดือนสิงหาคม 2026  
> หากมีคำถามเพิ่มเติม ให้ตรวจสอบที่หน้า **Audit Log** เพื่อดูประวัติการทำงานย้อนหลัง
