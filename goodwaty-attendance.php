<?php
/**
 * Plugin Name: Goodwaty Attendance
 * Description: حضور وانصراف المتدربين باستخدام QR Code ديناميكي + تحقق من الموقع + تقرير يومي.
 * Version: 1.0.4
 * Author: Yahya Bakr
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/*------------------------------
  Helpers
------------------------------*/
function goodwaty_json_success($data = []) {
    wp_send_json( array_merge(['success' => true], $data) );
}
function goodwaty_json_error($message = 'Error', $data = []) {
    wp_send_json( array_merge(['success' => false, 'message' => $message], $data) );
}

/*------------------------------
  QR Shortcode (with auto-refresh)
  [goodwaty_qr type="attendance|leave" expires="70"]
------------------------------*/
function goodwaty_generate_qr_shortcode($atts) {
    global $wpdb;

    $atts = shortcode_atts([
        'type'    => 'attendance',
        'expires' => '70', // ثواني
    ], $atts);

    $type = in_array($atts['type'], ['attendance','leave']) ? $atts['type'] : 'attendance';
    $expires_in_seconds = max(20, intval($atts['expires'])); // أضمن حد أدنى 20 ثانية

    //  tokens table
    $table_tokens = $wpdb->prefix . "goodwaty_tokens";
    $wpdb->query("
        CREATE TABLE IF NOT EXISTS $table_tokens (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) DEFAULT CHARSET=utf8mb4;
    ");

    // first Token for QR
    $token = hash('sha256', time() . wp_rand());
    $wpdb->insert($table_tokens, [
        'token' => $token,
        'created_at' => current_time('mysql')
    ]);

    $url    = site_url("/checkin/?token=" . $token . "&type=" . $type);
    $qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" . urlencode($url);
    $remaining = $expires_in_seconds - (time() % $expires_in_seconds);

    ob_start(); ?>
    <div class="goodwaty-qr-wrap" style="text-align:center; margin:20px;">
        <h3><?php echo ($type === 'leave') ? 'امسح الكود لتسجيل الانصراف' : 'امسح الكود لتسجيل الحضور'; ?></h3>
        <img style="justify-self: anchor-center;" id="goodwaty-qr-img" src="<?php echo esc_url($qr_api); ?>" alt="QR Code" />
        <p>سيتغير الكود بعد: <strong id="goodwaty-qr-count"><?php echo intval($remaining); ?></strong> ثانية</p>
    </div>
    <script>
    (function(){
        var expires = <?php echo intval($expires_in_seconds); ?>;
        var type    = <?php echo json_encode($type); ?>;
        var counter = document.getElementById('goodwaty-qr-count');
        var img     = document.getElementById('goodwaty-qr-img');

        function tick(){
            var val = parseInt(counter.textContent, 10);
            if (val <= 1) {
                refreshQR();
            } else {
                counter.textContent = (val - 1).toString();
            }
        }

        function refreshQR(){
            //new request to get token by AJAX
            var xhr = new XMLHttpRequest();
            xhr.open('POST', <?php echo json_encode(admin_url('admin-ajax.php')); ?>, true);
            xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
            xhr.onload = function(){
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.qr_url) {
                        img.src = res.qr_url + '&_cb=' + Date.now(); // منع الكاش
                        counter.textContent = expires.toString();
                    }
                } catch(e) {}
            };
            xhr.send('action=goodwaty_new_qr&nonce=<?php echo wp_create_nonce('goodwaty_new_qr'); ?>&type=' + encodeURIComponent(type));
        }

        setInterval(tick, 1000);
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('goodwaty_qr', 'goodwaty_generate_qr_shortcode');

/*------------------------------
 use ajax to generate new QR code without reloading the page
------------------------------*/
function goodwaty_new_qr_ajax() {
    if ( ! isset($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'goodwaty_new_qr') ) {
        goodwaty_json_error('Bad nonce');
    }
    $type = (isset($_POST['type']) && in_array($_POST['type'], ['attendance','leave'])) ? sanitize_text_field($_POST['type']) : 'attendance';

    global $wpdb;
    $table_tokens = $wpdb->prefix . "goodwaty_tokens";
    $token = hash('sha256', time() . wp_rand());

    $wpdb->insert($table_tokens, [
        'token' => $token,
        'created_at' => current_time('mysql')
    ]);

    $url    = site_url("/checkin/?token=" . $token . "&type=" . $type);
    $qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=" . urlencode($url);

    goodwaty_json_success(['qr_url' => $qr_api]);
}
add_action('wp_ajax_goodwaty_new_qr', 'goodwaty_new_qr_ajax');
add_action('wp_ajax_nopriv_goodwaty_new_qr', 'goodwaty_new_qr_ajax');

/*------------------------------
(checkin) + geofence
------------------------------*/
function goodwaty_checkin_page() {
    global $wpdb;

    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    $type  = isset($_GET['type'])  ? sanitize_text_field($_GET['type'])  : 'attendance';

    if (empty($token)) return "<p>⚠️ رابط غير صالح.</p>";

    $table_tokens = $wpdb->prefix . "goodwaty_tokens";
    $table_logs   = $wpdb->prefix . "goodwaty_attendance";

    // create logs table if not exists
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

    // expire old tokens (older than 2 minutes)
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_tokens WHERE token = %s AND created_at >= (NOW() - INTERVAL 2 MINUTE)", $token),
        ARRAY_A
    );
    if (!$row) return "<p>⚠️ التوكين غير صالح أو منتهي.</p>";

    // form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone'])) {
        $phone = sanitize_text_field($_POST['phone']);
        $lat   = isset($_POST['latitude'])  ? sanitize_text_field($_POST['latitude'])  : '';
        $lng   = isset($_POST['longitude']) ? sanitize_text_field($_POST['longitude']) : '';
        if (empty($phone)) return "<p>⚠️ يجب إدخال رقم الهاتف.</p>";

        // validate student
        $table_students = $wpdb->prefix . "goodwaty_students";
        $student = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_students WHERE phone = %s", $phone),
            ARRAY_A
        );
        if (!$student) return "<p>⚠️ هذا الرقم  $phone غير مسجل في قائمة المتدربين.</p>";

        // submit log
        $wpdb->insert($table_logs, [
            'phone'      => $phone,
            'token'      => $token,
            'type'       => in_array($type, ['attendance','leave']) ? $type : 'attendance',
            'latitude'   => $lat,
            'longitude'  => $lng,
            'created_at' => current_time('mysql')
        ]);

        return "<p>✅ أهلاً وسهلاً بك " . esc_html($student['name']) . " في مركز قيمة وقدوة للتدريب. تم تسجيل " . ( $type === 'leave' ? 'الانصراف' : 'الحضور' ) . " بنجاح في دورة إدارة المشاريع التنموية PMD Pro. رقم هاتفك هو: <strong>" . esc_html($phone) . "</strong>.</p>";
    }

    ob_start(); ?>
    <h3>تسجيل <?php echo ($type === 'leave') ? 'انصراف' : 'حضور'; ?></h3>
    <form method="post" id="attendanceForm" onsubmit="return checkLocation(this);">
        <label>رقم الهاتف:</label><br/>
<div style="display:flex;align-items:center;gap:5px;">
    <span style="padding:6px 10px; background:#eee; border:1px solid #ccc; border-radius:4px 0 0 4px;">
        +966
    </span>
    <input type="text" name="phone" required 
           style="flex:1; border:1px solid #ccc; border-radius:0 4px 4px 0;" 
           placeholder="5XXXXXXXX">
</div>
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

                // 
                         // 📍 جمعية البر بالباحة
                const hallLat = 20.0161623;
                const hallLng = 41.4642785;
                // const hallLat = 30.1331151;
                // const hallLng = 31.2764006;

                const distance = getDistance(userLat, userLng, hallLat, hallLng); // meters
                if (distance <= 200) {
                    document.getElementById('latitude').value  = userLat;
                    document.getElementById('longitude').value = userLng;
                    form.submit();
                } else {
                    alert("❌ يجب أن تكون داخل مكان الحضور( جمعية البر بالباحة) لتأكيد تسجيلك");
                }
            }, function() {
                alert("⚠️ لم يتم السماح بالوصول للموقع");
            });
            return false;
        }
        return true;
    }
    function getDistance(lat1, lon1, lat2, lon2) {
        const R = 6371e3, toRad = d => d * Math.PI / 180;
        const dLat = toRad(lat2 - lat1), dLon = toRad(lon2 - lon1);
        const a = Math.sin(dLat/2)**2 + Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLon/2)**2;
        return 2*R*Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('goodwaty_checkin', 'goodwaty_checkin_page');

/*------------------------------
 installation: create students table
------------------------------*/
register_activation_hook(__FILE__, function() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table_students = $wpdb->prefix . "goodwaty_students";
    $wpdb->query("
        CREATE TABLE IF NOT EXISTS $table_students (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY phone_unique (phone)
        ) $charset;
    ");
});



/*------------------------------
reports  [goodwaty_report from="2025-09-07" to="2025-09-11"]
------------------------------*/
function goodwaty_report_page($atts = []) {
    global $wpdb;

    $atts = shortcode_atts([
        'from' => '',
        'to'   => '',
        'student' => 0
    ], $atts);

    $table_logs     = $wpdb->prefix . "goodwaty_attendance";
    $table_students = $wpdb->prefix . "goodwaty_students";

    $from    = sanitize_text_field($_GET['from'] ?? $atts['from']);
    $to      = sanitize_text_field($_GET['to'] ?? $atts['to']);
    $student = intval($_GET['student'] ?? $atts['student']);

    $where_sql = '1=1';
    $params = [];

    if ($from && $to) {
        $where_sql = 'DATE(l.created_at) BETWEEN %s AND %s';
        $params = [$from, $to];
    } elseif ($from) {
        $where_sql = 'DATE(l.created_at) >= %s';
        $params = [$from];
    } elseif ($to) {
        $where_sql = 'DATE(l.created_at) <= %s';
        $params = [$to];
    }

    if ($student) {
        $where_sql .= ' AND s.id = %d';
        $params[] = $student;
    }

    // ======reports sumary======
    $sqlSummary = "
        SELECT
            COALESCE(s.name,'-') AS name,
            l.phone,
            DATE(l.created_at) AS day,
            MIN(CASE WHEN l.type='attendance' THEN l.created_at END) AS first_checkin,
            MAX(CASE WHEN l.type='leave' THEN l.created_at END) AS last_checkout
        FROM $table_logs l
        LEFT JOIN $table_students s
            ON TRIM(l.phone) = TRIM(s.phone) COLLATE utf8mb4_general_ci
        WHERE $where_sql
        GROUP BY l.phone, DATE(l.created_at), s.name
        ORDER BY day DESC, name ASC
    ";
    $summaryRows = $params ? $wpdb->get_results($wpdb->prepare($sqlSummary, ...$params), ARRAY_A) : $wpdb->get_results($sqlSummary, ARRAY_A);

    // ======detils reports
    $sqlDetail = "
        SELECT COALESCE(s.name,'-') AS name, l.phone, l.type, l.latitude, l.longitude, l.created_at
        FROM $table_logs l
        LEFT JOIN $table_students s
            ON TRIM(l.phone) = TRIM(s.phone) COLLATE utf8mb4_general_ci
        WHERE $where_sql
        ORDER BY l.created_at DESC
    ";
    $detailRows = $params ? $wpdb->get_results($wpdb->prepare($sqlDetail, ...$params), ARRAY_A) : $wpdb->get_results($sqlDetail, ARRAY_A);

    ob_start(); ?>

    <!-- ====== filter= -->
    <form method="get" style="margin-bottom:20px;">
        <input type="hidden" name="page" value="goodwaty-report">
        <label>من: <input type="date" name="from" value="<?php echo esc_attr($from); ?>"></label>
        <label>إلى: <input type="date" name="to" value="<?php echo esc_attr($to); ?>"></label>
        <label>طالب: 
            <select name="student">
                <option value="0">الكل</option>
                <?php 
                $students = $wpdb->get_results("SELECT id,name FROM $table_students ORDER BY name ASC", ARRAY_A);
                foreach ($students as $s): ?>
                    <option value="<?php echo $s['id']; ?>" <?php selected($student, $s['id']); ?>><?php echo esc_html($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="button button-primary">فلترة</button>
        <a href="<?php echo admin_url('admin-post.php?action=export_goodwaty_csv&from='.urlencode($from).'&to='.urlencode($to).'&student='.$student); ?>" class="button button-secondary">⬇️ تصدير CSV</a>
    </form>

    <!-- ======chart== -->
    <canvas id="attendanceChart" style="max-width:600px; margin-bottom:30px;"></canvas>

    <!-- ====== table of summary ====== -->
    <h3>ملخّص الحضور/الانصراف اليومي</h3>
    <table border="1" cellpadding="8" cellspacing="0" style="width:100%; border-collapse:collapse; margin-bottom:20px;">
        <thead>
            <tr>
                <th>التاريخ</th>
                <th>الاسم</th>
                <th>رقم الهاتف</th>
                <th>أول حضور</th>
                <th>آخر انصراف</th>
                <th>المدة (ساعات)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($summaryRows as $r):
            $duration = '-';
            if (!empty($r['first_checkin']) && !empty($r['last_checkout'])) {
                $start = strtotime($r['first_checkin']);
                $end   = strtotime($r['last_checkout']);
                if ($end > $start) $duration = round( ($end - $start) / 3600, 2 );
            } ?>
            <tr>
                <td><?php echo esc_html($r['day']); ?></td>
                <td><?php echo esc_html($r['name']); ?></td>
                <td><?php echo esc_html($r['phone']); ?></td>
                <td><?php echo $r['first_checkin'] ? esc_html($r['first_checkin']) : '-'; ?></td>
                <td><?php echo $r['last_checkout'] ? esc_html($r['last_checkout']) : '-'; ?></td>
                <td><?php echo is_numeric($duration) ? $duration : '-'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ====== السجل التفصيلي ====== -->
    <h3>السجل التفصيلي</h3>
    <table border="1" cellpadding="8" cellspacing="0" style="width:100%; border-collapse:collapse;">
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
        <?php foreach ($detailRows as $row): ?>
            <tr>
                <td><?php echo esc_html($row['name']); ?></td>
                <td><?php echo esc_html($row['phone']); ?></td>
                <td><?php echo ($row['type'] === 'leave') ? 'انصراف' : 'حضور'; ?></td>
                <td><?php echo esc_html($row['created_at']); ?></td>
                <td>
                    <?php if (!empty($row['latitude']) && !empty($row['longitude'])): ?>
                        <a href="https://maps.google.com/?q=<?php echo $row['latitude']; ?>,<?php echo $row['longitude']; ?>" target="_blank">عرض الموقع</a>
                    <?php else: ?>- <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ====== شارت.js ====== -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const ctx = document.getElementById('attendanceChart').getContext('2d');
        const attendanceData = <?php 
            $chart = [];
            foreach ($summaryRows as $r) {
                if (!isset($chart[$r['day']])) $chart[$r['day']] = 0;
                $chart[$r['day']]++;
            }
            echo json_encode(array_values($chart));
        ?>;
        const attendanceLabels = <?php echo json_encode(array_keys($chart)); ?>;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: attendanceLabels,
                datasets: [{
                    label: 'عدد الحضور لكل يوم',
                    data: attendanceData,
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive:true,
                plugins: {
                    legend: { display:true },
                    title: { display:true, text:'تقرير الحضور اليومي' }
                },
                scales: {
                    y: { beginAtZero:true }
                }
            }
        });
    </script>

    <?php
    return ob_get_clean();
}


add_shortcode('goodwaty_report', 'goodwaty_report_page');



/*------------------------------
ammin panel -------------------*/
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

    add_submenu_page(
        'goodwaty-attendance',
        'استيراد المتدربين',
        'استيراد المتدربين',
        'manage_options',
        'goodwaty-attendance-import',
        'goodwaty_attendance_import_page'
    );
});

/*------------------------------
admin students managemnt-------*/
function goodwaty_students_page() {
    global $wpdb;
    $table_students = $wpdb->prefix . "goodwaty_students";

    if (isset($_POST['add_student'])) {
        $name  = sanitize_text_field($_POST['name']);
        $phone = preg_replace('/\D/','', sanitize_text_field($_POST['phone'])); // أرقام فقط
        if (!empty($name) && !empty($phone)) {
            $wpdb->insert($table_students, ['name'=>$name, 'phone'=>$phone]);
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

/*------------------------------
report admin page
------------------------------*/
function goodwaty_report_admin_page() {
    echo '<div class="wrap"><h1>تقرير الحضور</h1>';
    echo do_shortcode('[goodwaty_report]');
    echo '</div>';
}

/*------------------------------
  import students from CSV
------------------------------*/
function goodwaty_attendance_import_page() {
    global $wpdb;
    $table = $wpdb->prefix . "goodwaty_students";

    if (isset($_POST['submit']) && !empty($_FILES['import_file']['tmp_name'])) {
        $file = fopen($_FILES['import_file']['tmp_name'], 'r');
        $row = 0; $added = 0;

        while (($data = fgetcsv($file, 1000, ",")) !== FALSE) {
            if ($row == 0) { $row++; continue; } 
            if (empty($data[0]) || empty($data[1])) { continue; }

            $name  = sanitize_text_field($data[0]);
            $phone = preg_replace('/\D/', '', $data[1]); 

            if (!empty($name) && !empty($phone)) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE phone = %s", $phone));
                if (!$exists) {
                    $wpdb->insert($table, ['name' => $name, 'phone' => $phone]);
                    $added++;
                }
            }
            $row++;
        }
        fclose($file);
        echo '<div class="updated"><p>✅ تم استيراد ' . intval($added) . ' متدرب بنجاح!</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>استيراد المتدربين من CSV</h1>
        <p>صيغة الأعمدة: <code>الاسم,رقم الجوال</code></p>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="import_file" accept=".csv" required>
            <br><br>
            <input type="submit" name="submit" class="button button-primary" value="رفع واستيراد">
        </form>
    </div>
    <?php
}
