<?php
/**
 * Plugin Name: Goodwaty Attendance
 * Description: حضور وانصراف المتدربين باستخدام QR Code ديناميكي + تحقق من الموقع.
 * Version: 1.0.3
 * Author: Yahya Bakr
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shortcode لعرض QR Code
 */
function goodwaty_generate_qr_shortcode($atts) {
    global $wpdb;

    $expires_in_seconds = 120;
    $remaining = $expires_in_seconds - (time() % $expires_in_seconds);
    $token = hash('sha256', time() . wp_rand());

    $table_name = $wpdb->prefix . "goodwaty_tokens";
    $wpdb->query("
        CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) DEFAULT CHARSET=utf8mb4;
    ");

    $wpdb->insert($table_name, array(
        'token' => $token,
        'created_at' => current_time('mysql')
    ));

    $url = site_url("/checkin/?token=" . $token . "&type=attendance");
    $qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($url);

    ob_start();
    ?>
    <div style="text-align:center; margin:20px;">
        <h3>امسح الكود للتسجيل</h3>
        <img src="<?php echo esc_url($qr_api); ?>" alt="QR Code" />
        <p>سيتغير الكود بعد: <strong><?php echo $remaining; ?> ثانية</strong></p>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('goodwaty_qr', 'goodwaty_generate_qr_shortcode');


/**
 * صفحة التحقق من QR (checkin)
 */
function goodwaty_checkin_page() {
    global $wpdb;

    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    $type  = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'attendance';

    if (empty($token)) {
        return "<p>⚠️ رابط غير صالح.</p>";
    }

    $table_tokens = $wpdb->prefix . "goodwaty_tokens";
    $table_logs   = $wpdb->prefix . "goodwaty_attendance";

    $wpdb->query("
        CREATE TABLE IF NOT EXISTS $table_logs (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            phone VARCHAR(20) NOT NULL,
            token VARCHAR(255) NOT NULL,
            type VARCHAR(20) NOT NULL,
            latitude VARCHAR(50) NULL,
            longitude VARCHAR(50) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) DEFAULT CHARSET=utf8mb4;
    ");

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_tokens WHERE token = %s AND created_at >= (NOW() - INTERVAL 2 MINUTE)", $token),
        ARRAY_A
    );

    if (!$row) {
        return "<p>⚠️ التوكين غير صالح أو منتهي.</p>";
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone'])) {
        $phone = sanitize_text_field($_POST['phone']);
        $lat   = isset($_POST['latitude']) ? sanitize_text_field($_POST['latitude']) : '';
        $lng   = isset($_POST['longitude']) ? sanitize_text_field($_POST['longitude']) : '';

        if (empty($phone)) {
            return "<p>⚠️ يجب إدخال رقم الهاتف.</p>";
        }

        $table_students = $wpdb->prefix . "goodwaty_students";
        $student = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_students WHERE phone = %s", $phone),
            ARRAY_A
        );

        if (!$student) {
            return "<p>⚠️ هذا الرقم غير مسجل في قائمة المتدربين.</p>";
        }

        $wpdb->insert($table_logs, array(
            'phone' => $phone,
            'token' => $token,
            'type'  => $type,
            'latitude' => $lat,
            'longitude' => $lng,
            'created_at' => current_time('mysql')
        ));

        return "<p>✅ تم تسجيل $type بنجاح للرقم: <strong>$phone</strong></p>";
    }

    ob_start();
    ?>
    <h3>تسجيل <?php echo ($type === 'leave') ? 'انصراف' : 'حضور'; ?></h3>
    <form method="post" id="attendanceForm" onsubmit="return checkLocation(this);">
        <label>رقم الهاتف:</label><br/>
        <input type="text" name="phone" required><br/><br/>

        <input type="hidden" name="latitude" id="latitude">
        <input type="hidden" name="longitude" id="longitude">

        <button type="submit">تسجيل</button>
    </form>

    <script>
    function checkLocation(form) {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                const userLat = position.coords.latitude;
                const userLng = position.coords.longitude;

                // 📍 جمعية البر بالباحة
                // const hallLat = 20.0108358;
                // const hallLng = 41.4676247;

                  const hallLat = 30.1331151;
                const hallLng = 31.2764006;

                const distance = getDistance(userLat, userLng, hallLat, hallLng);

                if (distance <= 100) { // 100 متر
                    document.getElementById('latitude').value = userLat;
                    document.getElementById('longitude').value = userLng;
                    form.submit();
                } else {
                    alert("❌ يجب أن تكون داخل مكان الحضور (جمعية البر بالباحة) لتأكيد تسجيلك");
                }
            }, function(error) {
                alert("⚠️ لم يتم السماح بالوصول للموقع");
            });
            return false;
        }
        return true;
    }

    function getDistance(lat1, lon1, lat2, lon2) {
        const R = 6371e3;
        const toRad = (deg) => deg * Math.PI / 180;

        const dLat = toRad(lat2 - lat1);
        const dLon = toRad(lon2 - lon1);
        const a =
            Math.sin(dLat/2) * Math.sin(dLat/2) +
            Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
            Math.sin(dLon/2) * Math.sin(dLon/2);

        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('goodwaty_checkin', 'goodwaty_checkin_page');


/**
 * إنشاء جدول المتدربين عند التفعيل
 */
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $table_students = $wpdb->prefix . "goodwaty_students";
    $wpdb->query("
        CREATE TABLE IF NOT EXISTS $table_students (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            PRIMARY KEY (id)
        ) $charset;
    ");
});


/**
 * تقرير الحضور
 */
function goodwaty_report_page() {
    global $wpdb;
    $table_logs = $wpdb->prefix . "goodwaty_attendance";
    $table_students = $wpdb->prefix . "goodwaty_students";

    $rows = $wpdb->get_results("
        SELECT l.id, s.name, l.phone, l.type, l.latitude, l.longitude, l.created_at
        FROM $table_logs l
        LEFT JOIN $table_students s ON l.phone = s.phone
        ORDER BY l.created_at DESC
    ", ARRAY_A);

    if (!$rows) {
        return "<p>⚠️ لا توجد سجلات حتى الآن.</p>";
    }

    ob_start();
    ?>
    <h3>تقرير الحضور والانصراف</h3>
    <table cellpadding="8" cellspacing="0" style="width:100%; border-collapse:collapse;">
        <thead>
            <tr>
                <th>الاسم</th>
                <th>رقم الهاتف</th>
                <th>النوع</th>
                <th>الوقت</th>
                <th>الموقع</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?php echo esc_html($row['name']); ?></td>
                <td><?php echo esc_html($row['phone']); ?></td>
                <td><?php echo ($row['type'] === 'leave') ? 'انصراف' : 'حضور'; ?></td>
                <td><?php echo esc_html($row['created_at']); ?></td>
                <td>
                    <?php if ($row['latitude'] && $row['longitude']): ?>
                        <a href="https://maps.google.com/?q=<?php echo $row['latitude']; ?>,<?php echo $row['longitude']; ?>" target="_blank">عرض الموقع</a>
                    <?php else: ?>- <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}
add_shortcode('goodwaty_report', 'goodwaty_report_page');


/**
 * منيو لوحة التحكم
 */
add_action('admin_menu', function() {
    add_menu_page(
        'الحضور',
        'الحضور',
        'manage_options',
        'goodwaty-attendance',
        'goodwaty_students_page',
        'dashicons-welcome-learn-more',
        6
    );

    add_submenu_page(
        'goodwaty-attendance',
        'المتدربون',
        'المتدربون',
        'manage_options',
        'goodwaty-attendance',
        'goodwaty_students_page'
    );

    add_submenu_page(
        'goodwaty-attendance',
        'تقرير الحضور',
        'تقرير الحضور',
        'manage_options',
        'goodwaty-report',
        'goodwaty_report_admin_page'
    );
});


/**
 * صفحة إدارة المتدربين
 */
function goodwaty_students_page() {
    global $wpdb;
    $table_students = $wpdb->prefix . "goodwaty_students";

    if (isset($_POST['add_student'])) {
        $name  = sanitize_text_field($_POST['name']);
        $phone = sanitize_text_field($_POST['phone']);

        if (!empty($name) && !empty($phone)) {
            $wpdb->insert($table_students, [
                'name'  => $name,
                'phone' => $phone
            ]);
            echo '<div class="updated"><p>✅ تم إضافة المتدرب.</p></div>';
        }
    }

    if (isset($_GET['delete'])) {
        $id = intval($_GET['delete']);
        $wpdb->delete($table_students, ['id' => $id]);
        echo '<div class="updated"><p>🗑️ تم حذف المتدرب.</p></div>';
    }

    $students = $wpdb->get_results("SELECT * FROM $table_students ORDER BY id DESC", ARRAY_A);
    ?>
    <div class="wrap">
        <h1>إدارة المتدربين</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label>الاسم</label></th>
                    <td><input type="text" name="name" required></td>
                </tr>
                <tr>
                    <th><label>رقم الهاتف</label></th>
                    <td><input type="text" name="phone" required></td>
                </tr>
            </table>
            <p><button type="submit" name="add_student" class="button button-primary">➕ إضافة متدرب</button></p>
        </form>

        <h2>قائمة المتدربين</h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>الاسم</th>
                    <th>رقم الهاتف</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($students): foreach ($students as $st): ?>
                <tr>
                    <td><?php echo esc_html($st['name']); ?></td>
                    <td><?php echo esc_html($st['phone']); ?></td>
                    <td>
                        <a href="?page=goodwaty-attendance&delete=<?php echo $st['id']; ?>" onclick="return confirm('هل أنت متأكد من الحذف؟');">حذف</a>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="3">لا يوجد متدربين بعد.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}


/**
 * صفحة التقرير داخل لوحة التحكم
 */
function goodwaty_report_admin_page() {
    echo '<div class="wrap"><h1>تقرير الحضور</h1>';
    echo do_shortcode('[goodwaty_report]');
    echo '</div>';
}




// صفحة استيراد المتدربين
add_action('admin_menu', function () {
    add_submenu_page(
        'goodwaty-attendance',
        'استيراد المتدربين',
        'استيراد المتدربين',
        'manage_options',
        'goodwaty-attendance-import',
        'goodwaty_attendance_import_page'
    );
});

function goodwaty_attendance_import_page() {
    global $wpdb;
    $table = $wpdb->prefix . "goodwaty_students";

    if (isset($_POST['submit']) && !empty($_FILES['import_file']['tmp_name'])) {
        $file = fopen($_FILES['import_file']['tmp_name'], 'r');
        $row = 0;
        $added = 0;
        while (($data = fgetcsv($file, 1000, ",")) !== FALSE || ($data = fgetcsv($file, 1000, ";")) !== FALSE) {
            if ($row == 0) { $row++; continue; } // تخطي الهيدر
            if (empty($data[0]) || empty($data[1])) { continue; }

            $name  = sanitize_text_field($data[0]);
            $phone = preg_replace('/\D/', '', $data[1]); // فقط الأرقام

            if (!empty($name) && !empty($phone)) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE phone = %s", $phone));
                if (!$exists) {
                    $wpdb->insert($table, [
                        'name' => $name,
                        'phone' => $phone,
                        
                    ]);
                    $added++;
                }
            }
            $row++;
        }
        fclose($file);

        echo '<div class="updated"><p>✅ تم استيراد ' . $added . ' متدرب بنجاح!</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>استيراد المتدربين من CSV</h1>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="import_file" accept=".csv" required>
            <br><br>
            <input type="submit" name="submit" class="button button-primary" value="رفع واستيراد">
        </form>
    </div>
    <?php
}

