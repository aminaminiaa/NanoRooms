# NanoRooms

> **A lightweight, secure, and self-hosted communication tool designed for organizations in restricted internet regions. No data tracking, no external libs. Share confidential files safely even on unstable connections.**

**NanoRooms** is a single-file, database-free chat solution built with pure PHP and Vanilla JS. It is optimized for performance (approx. 50KB core), works seamlessly on low-bandwidth networks (2G), and requires zero installation dependencies. Perfect for private teams, secure file sharing, and emergency communication.

![NanoRooms Preview](https://via.placeholder.com/800x400?text=App+Screenshot+Placeholder)

### ✨ Features

* **Zero Dependencies:** No database (MySQL/SQLite) required. Uses efficient JSON flat-file storage.
* **Ultra-Lightweight:** The entire core logic and UI is contained within a single file (~50KB).
* **Resilient:** Designed to function smoothly on slow or unstable internet connections (2G/EDGE).
* **Rich Media:** Support for **Voice Recording**, Image uploading, and generic File sharing.
* **Modern UI:** Fully responsive design with built-in **Dark/Light Mode**.
* **Privacy Focused:** Self-hosted (you own the data). No external trackers, analytics, or CDN dependencies.
* **Feature Packed:**
    * Multi-room support (General, News, etc.)
    * Message Reactions (Emoji) & Replies
    * Edit & Delete messages
    * Context Menu (Right-click / Long-press)
    * User Authentication & Whitelist support

---

### ⚙️ Usage & Configuration

1.  **Download:** Clone the repository or download the `index.php` file.
2.  **Upload:** Place the file on your PHP-supported host (Shared host, VPS, or Localhost).
3.  **Permissions:** Ensure the directory is writable (`755` or `777`). The script needs to create JSON files and an `uploads` folder automatically.
4.  **Config:** Open the file and edit the `$config` array at the top:

    ```php
    $config = [
        'password'       => 'YOUR_ADMIN_PASSWORD', // Global system password
        'refresh_rate'   => 5000,                  // Update interval in ms
        'rooms' => [
            'general' => 'General Chat',
            'news'    => 'Announcements',
        ]
    ];
    ```

5.  **⚠️ Important Note on Fonts:**
    The default CSS references a font file named `Alibaba.ttf`. Due to licensing restrictions, this font file is **not included**.
    * **Action Required:** Please search for `@font-face` in the code and change the `src` to your own font path, or remove it to use the system default font.

---

### 🚀 Requirements

* **PHP:** Version 7.4 or higher.
* **Extensions:** Standard `json` extension enabled.
* **Storage:** Write permissions on the server.

---

### 🧩 Developer

Developed by **[Your Name/Handle]**.
Found a bug or have a suggestion? Please report it via the **Issues** section.

---
---

# نانو رومز (NanoRooms)

> **ابزار ارتباطی لایت‌ویت (سبک)، امن و خود-میزبان (Self-hosted)؛ مناسب برای فعالیت در کشورهای دارای محدودیت اینترنت. بدون ردیابی اطلاعات و کتابخانه‌های خارجی. اشتراک‌گذاری امن اسناد محرمانه حتی در اتصالات ناپایدار.**

**نانو رومز** یک راهکار چت بدون نیاز به دیتابیس است که با PHP خالص و جاوا اسکریپت نوشته شده است. این ابزار برای نهایت کارایی بهینه شده (هسته حدود ۵۰ کیلوبایت)، در شبکه‌های ضعیف (2G) به خوبی کار می‌کند و به هیچ پیش‌نیاز نصبی احتیاج ندارد. ایده‌آل برای تیم‌های خصوصی، اشتراک‌گذاری امن فایل‌ها و ارتباطات در شرایط بحرانی.

### ✨ ویژگی‌ها

* **بدون وابستگی:** بدون نیاز به دیتابیس (MySQL). استفاده از سیستم ذخیره‌سازی فایل JSON.
* **فوق‌العاده سبک:** تمام منطق و رابط کاربری در یک فایل واحد (~۵۰ کیلوبایت) قرار دارد.
* **پایداری بالا:** طراحی شده برای عملکرد روان در اینترنت‌های کند و ناپایدار (2G/EDGE).
* **چندرسانه‌ای:** قابلیت **ضبط صدا (Voice)**، ارسال تصویر و انواع فایل.
* **رابط کاربری مدرن:** کاملاً ریسپانسیو (واکنش‌گرا) همراه با حالت **تاریک و روشن (Dark/Light Mode)**.
* **حریم خصوصی:** میزبانی شخصی (مالکیت کامل داده‌ها). بدون ردیاب و کتابخانه‌های خارجی.
* **امکانات کامل:**
    * پشتیبانی از چند اتاق گفتگو (عمومی، اخبار و...)
    * واکنش به پیام‌ها (Reactions) و ریپلای (Reply)
    * ویرایش و حذف پیام‌ها
    * منوی راست‌کلیک (در دسکتاپ) و تاچ طولانی (در موبایل)
    * سیستم احراز هویت و قابلیت لیست سفید (Whitelist)

---

### ⚙️ نحوه استفاده و پیکربندی

1.  **دانلود:** فایل پروژه را دانلود کنید.
2.  **آپلود:** فایل را در هاست خود (هاست اشتراکی یا سرور شخصی) آپلود کنید.
3.  **مجوزها (Permissions):** مطمئن شوید پوشه قابلیت نوشتن (Write) دارد. اسکریپت نیاز دارد فایل‌های JSON و پوشه `uploads` را بسازد. (اگر خطا داشتید پرمیشن را روی `755` بگذارید).
4.  **تنظیمات:** فایل را باز کنید و آرایه `$config` را در ابتدای فایل ویرایش کنید:

    ```php
    $config = [
        'password'       => 'YOUR_PASSWORD', // رمز عبور سامانه
        'refresh_rate'   => 5000,            // سرعت بروزرسانی (میلی‌ثانیه)
        'rooms' => [
            'general' => 'گفتگوی عمومی',
            'news'    => 'اخبار و اطلاعیه',
        ]
    ];
    ```

5.  **⚠️ نکته مهم درباره فونت:**
    در کد CSS این پروژه به فونت `Alibaba.ttf` اشاره شده است. به دلیل قوانین لایسنس، فایل این فونت در ریپازیتوری قرار ندارد.
    * **اقدام لازم:** لطفا در بخش استایل‌ها (CSS)، قسمت `@font-face` را پیدا کرده و آدرس آن را به فونت دلخواه خود تغییر دهید یا آن را حذف کنید تا از فونت پیش‌فرض سیستم استفاده شود.

---

### 🚀 نیازمندی‌ها

* **PHP:** نسخه ۷.۴ یا بالاتر.
* **افزونه‌ها:** فعال بودن افزونه استاندارد `json`.
* **فضا:** دسترسی نوشتن (Write Permission) روی سرور برای ذخیره تاریخچه چت و فایل‌ها.

---

### 🧩 توسعه‌دهنده

توسعه داده شده توسط **[نام شما]**.
در صورت وجود باگ، پیشنهاد یا ایده‌ی جدید، از طریق بخش **Issues** در GitHub اطلاع دهید.