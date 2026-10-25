<?php
// Dynamic header branding for Admin/Agent panels
if (!isset($admin_brand_safe) || !isset($admin_logo_safe)) {
    $admin_brand_name = 'Admin Panel';
    $admin_logo_path = '';
    if (!isset($conn) && file_exists(__DIR__ . '/db.php')) {
        require_once __DIR__ . '/db.php';
    }
    if (isset($conn) && !$conn->connect_error) {
        $brand_q = @$conn->query("SELECT * FROM settings WHERE id=1");
        if ($brand_q && $brand_q->num_rows > 0) {
            $brand_settings = $brand_q->fetch_assoc();
            $admin_brand_name = !empty($brand_settings['admin_panel_name']) ? $brand_settings['admin_panel_name'] : (!empty($brand_settings['site_name']) ? $brand_settings['site_name'] : $admin_brand_name);
            $admin_logo_path = $brand_settings['app_logo'] ?? '';
        }
    }
    $admin_brand_safe = htmlspecialchars($admin_brand_name, ENT_QUOTES, 'UTF-8');
    $admin_logo_safe = htmlspecialchars($admin_logo_path, ENT_QUOTES, 'UTF-8');
}

$header_admin_name = $_SESSION['admin_name'] ?? 'Administrator';
if (isset($conn) && !$conn->connect_error && !empty($_SESSION['admin_id'])) {
    require_once __DIR__ . '/admin_auth_helper.php';
    $header_admin = wcb_admin_get_by_id($conn, (int) $_SESSION['admin_id']);
    if ($header_admin && !empty($header_admin['name'])) {
        $header_admin_name = $header_admin['name'];
        $_SESSION['admin_name'] = $header_admin_name;
    }
}
$header_admin_name_safe = htmlspecialchars($header_admin_name, ENT_QUOTES, 'UTF-8');
$clean_admin_logo = '';
if (!empty($admin_logo_path)) {
    if (function_exists('get_admin_logo_src')) {
        $clean_admin_logo = get_admin_logo_src($admin_logo_path);
    } else {
        $lpath = trim((string) $admin_logo_path);
        $clean_admin_logo = (strpos($lpath, 'data:') === 0 || strpos($lpath, 'http') === 0) ? $lpath : ('../' . ltrim($lpath, '/'));
    }
}
?>
<link rel="shortcut icon" href="../favicon.png?v=<?php echo time(); ?>">
<link rel="icon" type="image/png" href="../favicon.png?v=<?php echo time(); ?>">
<header
    class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 fixed top-0 right-0 left-64 z-10 shadow-sm">
    <div>
        <h2 class="text-lg font-bold text-gray-700 capitalize"><?php echo basename($_SERVER['PHP_SELF'], '.php'); ?>
            Overview</h2>
        <p class="text-xs text-green-600 flex items-center">
            <span class="w-2 h-2 rounded-full bg-green-500 mr-2 animate-pulse"></span> System Operational
        </p>
    </div>

    <div class="flex items-center gap-4">
        <div class="hidden sm:flex items-center gap-2 px-3 py-2 rounded-xl bg-gray-50 border border-gray-200">
            <?php if (!empty($clean_admin_logo)): ?>
                <img src="<?php echo htmlspecialchars($clean_admin_logo, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo time(); ?>"
                    class="h-8 w-8 object-contain rounded" alt="Logo" onerror="this.style.display='none'">
            <?php endif; ?>
            <span class="text-sm font-bold text-gray-800 max-w-[180px] truncate"><?php echo $admin_brand_safe; ?></span>
        </div>
        <div class="text-right">
            <p class="text-sm font-bold text-gray-800"><?php echo $header_admin_name_safe; ?></p>
            <p class="text-xs text-gray-500">Administrator</p>
        </div>

        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <details class="admin-profile-dropdown relative">
                <summary title="Admin Menu" aria-label="Open admin profile menu"
                    class="list-none cursor-pointer h-10 w-10 rounded-full bg-gray-800 hover:bg-indigo-700 flex items-center justify-center text-white font-bold border-2 border-gray-300 transition focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <i class="fas fa-user"></i>
                </summary>
                <div
                    class="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-xl border border-gray-200 overflow-hidden z-50">
                    <a href="admin_profile.php#credentials"
                        class="flex items-center gap-3 px-4 py-3 text-sm font-bold text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                        <i class="fas fa-key w-4 text-center"></i>
                        <span>Change Password</span>
                    </a>
                    <form method="POST" action="logout.php" class="border-t border-gray-100">
                        <button type="submit"
                            class="w-full flex items-center gap-3 px-4 py-3 text-sm font-bold text-red-600 hover:bg-red-50 transition text-left">
                            <i class="fas fa-sign-out-alt w-4 text-center"></i>
                            <span>Logout</span>
                        </button>
                    </form>
                </div>
            </details>
        <?php endif; ?>
    </div>
</header>

<script>
    // Global Client-side Auto Image Compressor to prevent 413 Request Entity Too Large
    (function () {
        function compressImageFile(file, maxWidth, maxHeight, quality, callback) {
            if (!file || !file.type || !file.type.match(/^image\/(jpeg|jpg|png|webp)$/i)) {
                return callback(file);
            }
            // If file is already smaller than 600KB, no compression needed
            if (file.size <= 600 * 1024) {
                return callback(file);
            }
            var reader = new FileReader();
            reader.onload = function (e) {
                var img = new Image();
                img.onload = function () {
                    var width = img.width;
                    var height = img.height;
                    if (width > maxWidth) {
                        height = Math.round((height * maxWidth) / width);
                        width = maxWidth;
                    }
                    if (height > maxHeight) {
                        width = Math.round((width * maxHeight) / height);
                        height = maxHeight;
                    }
                    var canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    var ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, width, height);
                    canvas.toBlob(function (blob) {
                        if (blob && blob.size < file.size) {
                            var cleanName = file.name.replace(/\.[^/.]+$/, "") + ".jpg";
                            var compressedFile = new File([blob], cleanName, {
                                type: 'image/jpeg',
                                lastModified: Date.now()
                            });
                            callback(compressedFile);
                        } else {
                            callback(file);
                        }
                    }, 'image/jpeg', quality);
                };
                img.onerror = function () { callback(file); };
                img.src = e.target.result;
            };
            reader.onerror = function () { callback(file); };
            reader.readAsDataURL(file);
        }

        document.addEventListener('change', function (e) {
            var input = e.target;
            if (input && input.type === 'file' && input.files && input.files[0]) {
                var file = input.files[0];
                if (file && file.type && file.type.match(/^image\//i) && file.size > 600 * 1024) {
                    compressImageFile(file, 1920, 1080, 0.85, function (compressed) {
                        try {
                            var dt = new DataTransfer();
                            dt.items.add(compressed);
                            input.files = dt.files;
                        } catch (err) { }
                    });
                }
            }
        }, true);
    })();
</script>