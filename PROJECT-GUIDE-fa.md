# راهنمای توسعه افزونه Atlas Backup Migration

## مشخصات پروژه

- **تاریخ شروع پروژه:** 1405/03/03
- **هدف:** توسعه افزونه پیشرفته وردپرس برای بک‌آپ‌گیری عمیق، ساخت `installer.php` مستقل و انتقال سایت‌به‌سایت.
- **معماری:** PHP شی‌گرا با Namespace، ساختار ماژولار نزدیک به MVC، JavaScript برای AJAX/Fetch API.
- **رابط کاربری:** مدرن، ریسپانسیو و هماهنگ با WordPress Admin یا طراحی مشابه TailwindCSS.
- **استانداردها:** WordPress Coding Standards، امنیت بالا، پردازش chunked برای جلوگیری از timeout و مصرف بالای memory.

## مواردی که باید دانلود یا آماده شوند

### محیط توسعه محلی

- **WordPress آخرین نسخه پایدار**
  - **دانلود از:** wordpress.org
  - **ذخیره در:** مسیر لوکال سرور مانند `~/Sites/atlas-wp` یا `htdocs/atlas-wp`
- **PHP 7.4 یا بالاتر، ترجیحاً PHP 8.1+**
  - **نیاز ضروری:** فعال بودن اکستنشن‌های `zip`, `mysqli`, `json`, `mbstring`, `curl`, `fileinfo`
  - **بررسی:** اجرای `php -m`
- **MySQL/MariaDB**
  - **کاربرد:** تست SQL Dump، import دیتابیس و Site-to-Site Sync
- **WP-CLI**
  - **کاربرد:** اجرای تست‌های وردپرس، پاک‌سازی cache، ساخت داده نمونه
  - **ذخیره پیشنهادی:** `/usr/local/bin/wp`
- **Composer**
  - **کاربرد:** نصب ابزارهای lint و WordPress Coding Standards
  - **ذخیره پیشنهادی پروژه:** `atlas-backup-migration/vendor`
- **Node.js و npm**
  - **کاربرد:** build احتمالی assets، lint جاوااسکریپت و CSS

### ابزارهای استاندارد کدنویسی

- **WordPress Coding Standards**
  - **نصب پیشنهادی:** از طریق Composer داخل ریشه افزونه
  - **ذخیره در:** `atlas-backup-migration/vendor/wp-coding-standards/wpcs`
- **PHP_CodeSniffer**
  - **نصب پیشنهادی:** از طریق Composer
  - **ذخیره در:** `atlas-backup-migration/vendor/bin/phpcs`
- **PHPUnit برای تست وردپرس**
  - **ذخیره در:** `atlas-backup-migration/vendor/bin/phpunit`

### افزونه‌های موردنیاز برای تست Compatibility

- **WooCommerce**
  - **دانلود از:** مخزن رسمی وردپرس یا صفحه رسمی WooCommerce
  - **ذخیره در:** `wp-content/plugins/woocommerce`
  - **هدف تست:** جداول سفارش، متادیتاها، تصاویر محصول و گالری
- **Dokan**
  - **دانلود از:** مخزن رسمی وردپرس یا سایت رسمی Dokan
  - **ذخیره در:** `wp-content/plugins/dokan-lite` یا مسیر نسخه Pro طبق بسته دریافتی
  - **هدف تست:** داده فروشندگان، سفارش‌ها، withdrawal و تنظیمات فروشگاه
- **Elementor**
  - **دانلود از:** مخزن رسمی وردپرس یا سایت Elementor
  - **ذخیره در:** `wp-content/plugins/elementor`
  - **هدف تست:** فایل‌های CSS تولیدی در `wp-content/uploads/elementor`

### داده نمونه برای تست

- **محصولات WooCommerce با تصاویر و گالری**
  - **ذخیره رسانه‌ها:** `wp-content/uploads/YYYY/MM`
  - **هدف:** تست `_thumbnail_id` و `_product_image_gallery`
- **فروشندگان Dokan**
  - **ذخیره داده:** جداول دکان و `wp_usermeta`
- **صفحات Elementor**
  - **ذخیره CSS:** `wp-content/uploads/elementor/css`

## دانلود روی سیستم دیگر و انتقال آفلاین

اگر روی سیستم مقصد اینترنت یا دسترسی مستقیم ندارید، موارد زیر را روی یک سیستم دیگر دانلود کنید، سپس با فلش، شبکه داخلی یا `scp/rsync` به سیستم توسعه منتقل کنید.

### پوشه پیشنهادی برای نگهداری فایل‌های دانلودی

- **روی سیستم دانلودکننده:** `~/Downloads/atlas-backup-migration-deps`
- **روی سیستم توسعه:** `~/atlas-backup-migration-deps`
- **داخل پروژه، فقط در صورت نیاز:** `atlas-backup-migration/.offline-deps`
- **نکته مهم:** پوشه‌های دانلودی و فایل‌های ZIP وابستگی را داخل نسخه نهایی افزونه منتشر نکنید.

### بسته‌های وردپرس و افزونه‌ها

- **WordPress Core**
  - **فایل موردنیاز:** `wordpress-latest.zip`
  - **دانلود رسمی:** `https://wordpress.org/latest.zip`
  - **ذخیره در:** `~/atlas-backup-migration-deps/wordpress/wordpress-latest.zip`
  - **استخراج در مقصد:** مسیر لوکال وردپرس مثل `~/Sites/atlas-wp`
- **WooCommerce**
  - **فایل موردنیاز:** `woocommerce.zip`
  - **دانلود رسمی:** `https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip`
  - **ذخیره در:** `~/atlas-backup-migration-deps/plugins/woocommerce.zip`
  - **استخراج در مقصد:** `wp-content/plugins/woocommerce`
- **Elementor**
  - **فایل موردنیاز:** `elementor.zip`
  - **دانلود رسمی:** `https://downloads.wordpress.org/plugin/elementor.latest-stable.zip`
  - **ذخیره در:** `~/atlas-backup-migration-deps/plugins/elementor.zip`
  - **استخراج در مقصد:** `wp-content/plugins/elementor`
- **Dokan Lite**
  - **فایل موردنیاز:** `dokan-lite.zip`
  - **دانلود رسمی:** `https://downloads.wordpress.org/plugin/dokan-lite.latest-stable.zip`
  - **ذخیره در:** `~/atlas-backup-migration-deps/plugins/dokan-lite.zip`
  - **استخراج در مقصد:** `wp-content/plugins/dokan-lite`
- **Dokan Pro**
  - **فایل موردنیاز:** فایل ZIP دریافتی از حساب کاربری رسمی
  - **ذخیره در:** `~/atlas-backup-migration-deps/plugins/dokan-pro.zip`
  - **استخراج در مقصد:** مسیر افزونه Pro طبق نام پوشه داخل ZIP

### Composer و میرور Packagist

برای نصب ابزارهای توسعه مانند `phpcs`, `phpunit` و `wp-coding-standards/wpcs` می‌توانید Composer را روی سیستم اینترنت‌دار اجرا کنید و پوشه `vendor` را منتقل کنید.

- **Composer PHAR**
  - **دانلود رسمی:** `https://getcomposer.org/download/latest-stable/composer.phar`
  - **ذخیره در:** `~/atlas-backup-migration-deps/tools/composer.phar`
  - **انتقال به مقصد:** `/usr/local/bin/composer` یا اجرای مستقیم با `php composer.phar`
- **میرور Composer/Packagist**
  - **Packagist رسمی:** `https://repo.packagist.org`
  - **Mirror پیشنهادی در صورت کندی/تحریم:** `https://mirrors.aliyun.com/composer/`
  - **تنظیم mirror در سیستم دانلودکننده:**

```bash
composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/
```

- **بازگشت به مخزن رسمی:**

```bash
composer config -g --unset repos.packagist
```

- **دانلود وابستگی‌ها روی سیستم دیگر:**

```bash
cd atlas-backup-migration
composer require --dev squizlabs/php_codesniffer wp-coding-standards/wpcs phpunit/phpunit
composer install --no-dev --prefer-dist
```

- **اگر هدف فقط ابزار توسعه است، این مسیرها را منتقل کنید:**
  - `atlas-backup-migration/vendor`
  - `atlas-backup-migration/composer.json`
  - `atlas-backup-migration/composer.lock`
- **ذخیره در مقصد:** ریشه افزونه، یعنی `wp-content/plugins/atlas-backup-migration/vendor`

### Node.js و بسته‌های فرانت‌اند

اگر در آینده build assets اضافه شد، بسته‌های npm را روی سیستم اینترنت‌دار نصب کنید.

- **Node.js LTS**
  - **دانلود رسمی:** `https://nodejs.org`
  - **ذخیره در:** `~/atlas-backup-migration-deps/tools/node`
- **دانلود وابستگی‌های npm روی سیستم دیگر:**

```bash
cd atlas-backup-migration
npm install
```

- **انتقال در صورت نیاز:**
  - `atlas-backup-migration/node_modules`
  - `atlas-backup-migration/package.json`
  - `atlas-backup-migration/package-lock.json`
- **نکته:** در نسخه فعلی افزونه، `node_modules` لازم نیست چون CSS/JS ساده و بدون build step هستند.

### بسته‌های PHP و اکستنشن‌ها

روی سیستم مقصد باید این اکستنشن‌ها فعال باشند:

- `zip`
- `mysqli`
- `json`
- `mbstring`
- `curl`
- `fileinfo`

برای Ubuntu/Debian می‌توانید روی سیستم اینترنت‌دار بسته‌ها را دانلود و منتقل کنید:

```bash
apt download php-zip php-mysql php-mbstring php-curl php-xml
```

- **ذخیره فایل‌های `.deb`:** `~/atlas-backup-migration-deps/php-packages`
- **نصب در مقصد:** با ابزار مدیریت بسته همان سیستم، ترجیحاً از repository رسمی همان نسخه OS.

### انتقال فایل‌ها به سیستم مقصد

- **با rsync:**

```bash
rsync -av ~/atlas-backup-migration-deps/ user@target:/home/user/atlas-backup-migration-deps/
```

- **با scp:**

```bash
scp -r ~/atlas-backup-migration-deps user@target:/home/user/
```

- **محل نصب افزونه بعد از انتقال:**

```text
wp-content/plugins/atlas-backup-migration
```

### چک‌لیست بعد از انتقال

- فایل‌های ZIP افزونه‌ها استخراج شده‌اند.
- اکستنشن `ZipArchive` فعال است.
- `mysqli` برای importer و installer فعال است.
- پوشه `wp-content/uploads` قابل نوشتن است.
- پوشه `wp-content/uploads/atlas-backup-migration` توسط افزونه ساخته می‌شود.
- اگر `vendor` منتقل شده، فایل‌های آن داخل بسته نهایی public منتشر نشوند مگر برای محیط توسعه.

## محل ذخیره‌سازی فایل‌های افزونه

- **ریشه افزونه:** `wp-content/plugins/atlas-backup-migration`
- **فایل اصلی افزونه:** `wp-content/plugins/atlas-backup-migration/atlas-backup-migration.php`
- **کلاس‌های PHP:** `wp-content/plugins/atlas-backup-migration/includes`
- **قالب‌های پنل و installer:** `wp-content/plugins/atlas-backup-migration/templates`
- **فایل‌های CSS/JS پنل:** `wp-content/plugins/atlas-backup-migration/assets`
- **فایل‌های زبان:** `wp-content/plugins/atlas-backup-migration/languages`

## محل ذخیره خروجی‌ها در زمان اجرا

- **Jobهای بک‌آپ:** `wp-content/uploads/atlas-backup-migration/{job_id}`
- **فایل ZIP خروجی:** `wp-content/uploads/atlas-backup-migration/{job_id}/atlas-package-{job_id}.zip`
- **SQL Dump:** `wp-content/uploads/atlas-backup-migration/{job_id}/database.sql`
- **Installer مستقل:** `wp-content/uploads/atlas-backup-migration/{job_id}/installer.php`
- **Compatibility Manifest:** `wp-content/uploads/atlas-backup-migration/{job_id}/compatibility-manifest.json`
- **Chunkهای موقت Site-to-Site Sync:** `wp-content/uploads/abm-sync-chunks/{transfer_id}`

## ماژول‌های اصلی

### 1. موتور بک‌آپ و Installer

- استفاده از `ZipArchive` برای ساخت ZIP.
- استفاده از AJAX/Batch Processing برای جلوگیری از timeout.
- خروجی دیتابیس باید جدول‌به‌جدول و ردیف‌به‌ردیف batch شود.
- تولید `installer.php` مستقل برای استخراج فایل‌ها، import دیتابیس، بازنویسی URL و پاک‌سازی cache.
- فرمول محاسبه تعداد chunk:

```text
$Total_Chunks = ceil($Total_Size / $Chunk_Size)
```

### 2. Compatibility Layer

- WooCommerce:
  - شناسایی جداول اختصاصی مانند `wp_woocommerce_order_items`, `wp_woocommerce_order_itemmeta`, `wp_wc_orders`
  - حفظ متاهای محصول، تصویر شاخص و گالری
  - آماده‌سازی URLها برای تغییر دامنه
- Dokan:
  - شناسایی جداول و متاهای فروشندگان
  - حفظ اطلاعات فروشگاه، کمیسیون‌ها و withdrawalها
- Elementor:
  - بک‌آپ کامل `wp-content/uploads/elementor`
  - پشتیبانی از Regenerate CSS بعد از restore

### 3. Site-to-Site Sync

- ارتباط بر بستر `WP REST API`.
- تولید token با اعتبار دقیقاً ۴ ساعت:

```php
set_transient($key, $token_data, 14400);
```

- اعتبارسنجی token با `get_transient`.
- انتقال فایل و رسانه باید chunked باشد.
- برای انتقال محصول، payload باید شامل post data، meta، terms، media و checksum باشد.

## قوانین امنیتی ثابت

- همه endpointها باید `current_user_can('manage_options')` یا token معتبر داشته باشند.
- عملیات AJAX باید nonce داشته باشد:

```php
wp_verify_nonce($nonce, $action);
```

- عملیات REST باید با token معتبر و محدود به زمان انجام شود.
- هیچ مسیر فایل خام نباید بدون sanitize استفاده شود.
- دانلود فایل‌های بک‌آپ باید از طریق route امن انجام شود، نه لینک عمومی مستقیم.
- تمام ورودی‌ها باید sanitize و تمام خروجی‌ها escape شوند.

## کلمات کلیدی حیاتی

- `WP_Filesystem`
- `ZipArchive`
- `WP REST API`
- `set_transient`
- `get_transient`
- `wp_create_nonce`
- `wp_verify_nonce`
- `current_user_can`
- `AJAX/Batch Processing`
- `Data Integrity`
- `Regenerate CSS`

## قوانین پاسخ‌دهی و توسعه آینده

- تمام PHPها باید OOP و دارای Namespace باشند.
- کدهای بلند باید به کلاس‌ها و متدهای کوچک‌تر تقسیم شوند.
- محدودیت‌های `memory_limit` و `max_execution_time` باید در طراحی لحاظ شوند.
- برای هر عملیات سنگین، batch/chunk ضروری است.
- فرمول‌ها و منطق محاسباتی باید با قالب ریاضی نوشته شوند؛ مثال:

```text
$Processed_Size = $Current_Chunk * $Chunk_Size
```

- امنیت نباید قربانی سرعت توسعه شود.
