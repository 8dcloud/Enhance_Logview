# Enhanced Log Viewer for Enhance Panel  
*(Built on top of SharedGrid’s `enhance-log-mirror`)*

This project provides a powerful, user-friendly **web-based access-log viewer** designed for sites running under the **Enhance Control Panel**. It pairs naturally with the excellent [`enhance-log-mirror`](https://github.com/SharedGrid/enhance-log-mirror) script by **SharedGrid**, which handles real-time mirroring of per-site logs.

Special Note: If you are a cpFence user (in our estimation the best security product available for Enhance Hosting Panel) then please see that they have released LogSpot v. 2 with a brand-new log retention and viewer built in. If you are utilizing that product, be SURE to check it out, becuase it makes this obsolete:
https://cpfence.app/meet-logspot-v2-smarter-lighter-per-day-filtering-and-insights

---

## 🙏 Acknowledgment

A huge thanks to **SharedGrid** for open-sourcing their `enhance-log-mirror` system.  
Their work provides the reliable per-site log extraction that makes this viewer possible.

🔗 **SharedGrid enhance-log-mirror**  
https://github.com/SharedGrid/enhance-log-mirror

If you are running this viewer, you should almost certainly be running their script as well.

---

## 📌 What This Viewer Is

This is a **drop-in PHP interface** that reads mirrored Enhance access logs and presents them in a clean, modern dashboard, including:

- Per-site log browsing  
- Time-range filtering (hours & days)  
- Search & filter functionality  
- Sorting and pagination  
- Live tailing (auto-refresh)  
- Raw log-line visibility  
- CSV export of filtered results  
- Error-only filtering  
- Top IP / top path / status-class charts  
- Dark-mode optimized UI  

It is intended for **developers, sysadmins, hosting providers**, and anyone who needs a fast, flexible way to inspect web access logs without SSH.

---

## 📁 Directory Structure

When used with `enhance-log-mirror`, your site typically has:

```
/var/www/<site-uuid>/
 ├── public_html/
 │    └── logviewer.php   ← place this file here
 └── access-logs/
      ├── 2025-12-03.log
      ├── 2025-12-04.log
      └── ...
```

Each log file follows the format:

```
YYYY-MM-DD.log
```

containing the mirrored access log entries for that day.

---

## 🚀 Installation

1. Install and configure `enhance-log-mirror` from SharedGrid:  
   https://github.com/SharedGrid/enhance-log-mirror

2. Download or copy `logviewer.php` from this repository.

3. Place `logviewer.php` into the site's `public_html/` folder:
   ```
   /var/www/<site-uuid>/public_html/logviewer.php
   ```

4. Access it in a browser:
   ```
   https://yourdomain.com/logviewer.php
   ```

No database required.  
No config files needed.  
It auto-detects the log directory based on Enhance’s folder structure.

---

## 🧭 Usage Overview

### **Time Range Filtering**
Select from:
- Last 1h, 4h, 6h, 12h, 24h  
- Last 1d, 3d, 7d, 30d  

### **Search**
Search across IP, request, referer, user-agent, or raw log text.

### **Sorting**
Sort by:
- Time  
- IP  
- Status  
- Bytes  
- File  

### **Raw Line Toggle**
Enable *Show Raw Line* to reveal copy/paste-ready log lines.

### **CSV Export**
Click **Download CSV** to export only the *filtered* results.

### **Live Tail**
Enable *Live Tail* to auto-refresh every 5 seconds.

---

## 📊 Built-in Visualizations

The viewer automatically builds charts for:

- 🚦 **HTTP Status Classes** (2xx, 3xx, 4xx, 5xx)  
- 🌍 **Top Client IPs**  
- 📈 **Top Requested Paths**  

Charts use lightweight Chart.js via CDN and require no configuration.

---

## ⚙️ Requirements

- PHP 7.4+  
- Enhance Control Panel with log-mirroring enabled  
- Web browser with JavaScript for charts (optional)

---

## 🛠 Troubleshooting

| Issue | Solution |
|-------|----------|
| *Viewer shows no logs* | Check `../access-logs/` exists and contains `.log` files |
| *Timestamps incorrect* | Ensure server timezone is set correctly (`timedatectl`) |
| *Very large logs slow loading* | Increase MAX_ROWS, or narrow the time range |
| *CSV downloads empty* | Check filters—CSV exports *filtered* rows only |

---

## 📜 License

This viewer is free to use and modify.

Special thanks again to **SharedGrid** for providing the foundational log-mirroring system that makes this tool possible.
