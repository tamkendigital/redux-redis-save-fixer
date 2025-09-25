<?php
/**
 * Plugin Name: Redux Fixer V2
 * Description: Enhanced Redux backup and restore system using site list
 * Version: 2.0
 * Author: Abdallh Alhmd
 */

// تضمين قائمة المواقع
require_once __DIR__ . '/redux-sites-list.php';

// دالة للحصول على مسار النسخة الاحتياطية
function get_redux_backup_path($blog_id = null) {
    if (!$blog_id) {
        $blog_id = get_current_blog_id();
    }
    
    $upload_dir = wp_upload_dir();
    $backup_dir = $upload_dir['basedir'] . '/redux_backups';
    
    // إنشاء المجلد إذا لم يكن موجوداً
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
    }
    
    return $backup_dir . "/homey_options_blog_{$blog_id}.json";
}

// دالة للحفظ التلقائي
function save_redux_backup($options, $blog_id = null) {
    if (!$blog_id) {
        $blog_id = get_current_blog_id();
    }
    
    $backup_file = get_redux_backup_path($blog_id);
    $result = file_put_contents($backup_file, json_encode($options, JSON_PRETTY_PRINT));
    
    if ($result !== false) {
        error_log("Redux Fixer V2: Backup saved for blog {$blog_id} at {$backup_file}");
        return true;
    } else {
        error_log("Redux Fixer V2: Failed to save backup for blog {$blog_id}");
        return false;
    }
}

// دالة للاسترجاع التلقائي
function restore_redux_backup($blog_id = null) {
    if (!$blog_id) {
        $blog_id = get_current_blog_id();
    }
    
    $backup_file = get_redux_backup_path($blog_id);
    
    if (!file_exists($backup_file)) {
        error_log("Redux Fixer V2: No backup file found for blog {$blog_id}");
        return false;
    }
    
    $backup_content = file_get_contents($backup_file);
    if (empty($backup_content)) {
        error_log("Redux Fixer V2: Backup file is empty for blog {$blog_id}");
        return false;
    }
    
    $backup_data = json_decode($backup_content, true);
    if (empty($backup_data)) {
        error_log("Redux Fixer V2: Invalid backup data for blog {$blog_id}");
        return false;
    }
    
    // حذف الخيار الحالي أولاً
    delete_option('homey_options');
    
    // إضافة النسخة الاحتياطية
    $result = add_option('homey_options', $backup_data, '', 'no');
    
    if ($result) {
        error_log("Redux Fixer V2: Successfully restored backup for blog {$blog_id}");
        return true;
    } else {
        error_log("Redux Fixer V2: Failed to restore backup for blog {$blog_id}");
        return false;
    }
}

// حفظ النسخة الاحتياطية عند تحديث الإعدادات
add_action('redux/options/homey_options/saved', function($options) {
    $current_blog_id = get_current_blog_id();
    
    // التحقق من أن الموقع في قائمة المواقع المدعومة
    if (is_redux_site($current_blog_id)) {
        save_redux_backup($options, $current_blog_id);
    }
});

// استعادة فورية عند wp_loaded - للمواقع المدعومة فقط
add_action('wp_loaded', function() {
    static $restore_attempted = false;
    
    // منع الاسترجاع المتكرر
    if ($restore_attempted) {
        return;
    }
    
    $current_blog_id = get_current_blog_id();
    
    // التحقق من أن الموقع في قائمة المواقع المدعومة
    if (!is_redux_site($current_blog_id)) {
        error_log("Redux Fixer V2: Site {$current_blog_id} is not in the supported list, skipping restore");
        return;
    }
    
    $current = get_option('homey_options');
    
    // تسجيل تفصيلي للمراقبة
    error_log("Redux Fixer V2: Supported site {$current_blog_id} - Current options: " . (empty($current) ? 'EMPTY' : 'EXISTS'));
    
    // التحقق من أن النسخة الحالية هي النسخة الصحيحة وليس النسخة الافتراضية
    $is_default_options = false;
    if (!empty($current)) {
        // التحقق من بعض المؤشرات التي تدل على النسخة الافتراضية
        $default_indicators = array(
            'homey_site_mode' => 'per_day',
            'homey_calendar_months' => '12',
            'weekBegins' => '1'
        );
        
        $matches = 0;
        foreach ($default_indicators as $key => $value) {
            if (isset($current[$key]) && $current[$key] === $value) {
                $matches++;
            }
        }
        
        // إذا تطابق 3 من 3، فهذه النسخة الافتراضية
        $is_default_options = ($matches >= 3);
        
        error_log("Redux Fixer V2: Site {$current_blog_id} - Default options check: " . ($is_default_options ? 'YES (default)' : 'NO (custom)'));
    }
    
    if (empty($current) || $is_default_options) {
        $restore_attempted = true;
        
        // محاولة الاسترجاع من النظام الجديد
        $restore_result = restore_redux_backup($current_blog_id);
        
        if (!$restore_result) {
            // fallback للنسخة القديمة إذا لم توجد نسخة جديدة
            $old_backup_file = ABSPATH . "wp-content/homey_options_backup_blog_{$current_blog_id}.json";
            if (file_exists($old_backup_file)) {
                $backup = json_decode(file_get_contents($old_backup_file), true);
                if (!empty($backup)) {
                    delete_option('homey_options');
                    $result = add_option('homey_options', $backup, '', 'no');
                    error_log("Redux Fixer V2: Fallback restore for site {$current_blog_id} - Result: " . ($result ? 'SUCCESS' : 'FAILED'));
                }
            }
        }
    }
});

// إنشاء النسخة الاحتياطية الأولية للمواقع المدعومة
add_action('init', function() {
    static $initial_backup_created = false;
    
    if (!$initial_backup_created) {
        $initial_backup_created = true;
        
        $redux_sites = get_redux_sites();
        $backup_count = 0;
        $error_count = 0;
        
        foreach ($redux_sites as $blog_id => $domain) {
            switch_to_blog($blog_id);
            
            $options = get_option('homey_options');
            if (!empty($options)) {
                $result = save_redux_backup($options, $blog_id);
                if ($result) {
                    $backup_count++;
                    error_log("Redux Fixer V2: Created initial backup for site {$blog_id} ({$domain})");
                } else {
                    $error_count++;
                    error_log("Redux Fixer V2: Failed to create initial backup for site {$blog_id} ({$domain})");
                }
            } else {
                error_log("Redux Fixer V2: No homey_options found for site {$blog_id} ({$domain})");
            }
            
            restore_current_blog();
        }
        
        error_log("Redux Fixer V2: Initial backup completed - Success: {$backup_count}, Errors: {$error_count}");
    }
});
