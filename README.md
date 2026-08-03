# IT Asset Management System (IT-AMS)

![IT Asset Management Cover](https://via.placeholder.com/1200x400.png?text=IT+Asset+Management+System)

A lightweight, high-performance IT Asset Management System designed specifically for internal IT departments. Built to streamline inventory tracking, assignments, maintenance, and auditing without the bloat of heavy frameworks. Fully optimized for 2D barcode scanners and fast, frictionless daily operations.

## 🌟 Key Features

*   **Universal Quick Search:** A single, autofocus search bar that handles Hostnames, Serial Numbers, Asset Tags, and Employee Names. Highly optimized for USB 2D Barcode Scanners.
*   **Asset & Accessory Tracking:** Complete lifecycle management from procurement to retirement. Handles parent-child relationships (e.g., PC with multiple monitors).
*   **Borrow & Lease Alerts:** Automated dashboards and tracking for leased or borrowed equipment, complete with 30/60/90-day expiry notifications.
*   **2D Floor Plan Visualizer:** Upload your office layout and pin assets directly onto the map to easily locate equipment physically.
*   **Consumables & Software Licenses:** Track stock levels of IT consumables (ink, cables) and manage software license allocations seamlessly.
*   **Detailed Audit Logging:** Every single action (assign, return, repair, retire) is automatically logged to provide a bulletproof history of asset custody.
*   **Multilingual Support (i18n):** Easily toggle between English and Thai interfaces.

## 🛠️ Tech Stack

This project was built focusing on **speed, security, and simplicity**. It uses a lightweight procedural architecture that is extremely easy to deploy on any local network server.

*   **Backend:** PHP 8+ (PDO), SQLite3
*   **Frontend:** HTML5, Vanilla JavaScript, CSS Variables
*   **Styling:** Bootstrap 5 (via CDN), Bootstrap Icons

## 🚀 Installation & Setup

1.  **Clone the Repository:**
    ```bash
    git clone https://github.com/yourusername/asset-mgr.git
    cd asset-mgr
    ```

2.  **Start a PHP Server:**
    Since it uses SQLite, no complex database setup is required. The database initializes automatically.
    ```bash
    php -S localhost:8000
    ```

3.  **Access the Application:**
    Open `http://localhost:8000` in your web browser. 

4.  **Edit Mode (PIN):**
    By default, the system has a simple Edit Mode toggle protected by a PIN to prevent accidental modifications by unauthorized users. 

## 📂 Directory Structure

```
├── db/                 # Auto-generated SQLite database (ignored in git)
├── includes/           # Core logic, DB connection, helpers, translations
├── assets/             # CSS and JavaScript files
├── uploads/            # Attached files and Floor Plan images
├── index.php           # Main Dashboard
├── assets.php          # Asset Management
├── users.php           # User & Custody Management
├── actions.php         # Transaction Handlers
└── ...
```

## 🛡️ Security & Integrity

*   **CSRF Protection:** All mutating operations (`POST` requests) are secured with CSRF tokens.
*   **Prepared Statements:** 100% of database queries use PDO Prepared Statements to prevent SQL Injection.
*   **Data Integrity Constraints:** Strict SQLite constraints enforce unique identifiers (`hostname`, `serial_number`) and valid statuses.

## 📝 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---
*Created as a demonstration of clean, functional, and user-centric web development.*
