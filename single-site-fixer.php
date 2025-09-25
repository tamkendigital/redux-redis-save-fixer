<?php
/**
 * Plugin Name: Fix Redux + Redis Reset Issue
 * Description: Prevents Redux from resetting settings when Redis cache is flushed.
 * Version: 1.0
 * Author: Your Name
 */


// حفظ النسخة الاحتياطية عند تحديث الإعدادات
add_action('redux/options/homey_options/saved', function($options) {
    $current_blog_id = get_current_blog_id();
    $backup_file = ABSPATH . "wp-content/homey_options_backup_blog_{$current_blog_id}.json";
    file_put_contents($backup_file, json_encode($options));
    error_log("Homey: Backup saved for blog {$current_blog_id}");
});

// استعادة فورية عند wp_loaded - كل موقع يسترجع نسخته الخاصة
add_action('wp_loaded', function() {
    static $restore_attempted = false;
    
    // منع الاسترجاع المتكرر
    if ($restore_attempted) {
        return;
    }
    
    $current = get_option('homey_options');
    $current_blog_id = get_current_blog_id();
    
    // تسجيل تفصيلي للمراقبة
    error_log("Homey Debug: Blog {$current_blog_id} - Current options: " . (empty($current) ? 'EMPTY' : 'EXISTS'));
    
    // التحقق من أننا في الموقع الصحيح
    if ($current_blog_id != 98) {
        error_log("Homey Debug: Not on blog 98, skipping restore");
        return;
    }
    
    if (empty($current)) {
        $restore_attempted = true;
        $backup_file = ABSPATH . "wp-content/homey_options_backup_blog_{$current_blog_id}.json";
        
        if (file_exists($backup_file)) {
            $backup = json_decode(file_get_contents($backup_file), true);
            if (!empty($backup)) {
                // محاولة حذف الخيار أولاً إذا كان موجود
                delete_option('homey_options');
                
                // إضافة الخيار الجديد
                $result = add_option('homey_options', $backup, '', 'no');
                error_log("Homey: Emergency restore for blog {$current_blog_id} - Result: " . ($result ? 'SUCCESS' : 'FAILED'));
                
                // التحقق من أن الخيار تم إضافته
                $check = get_option('homey_options');
                error_log("Homey: After restore check for blog {$current_blog_id} - Options exist: " . (empty($check) ? 'NO' : 'YES'));
            } else {
                error_log("Homey: Backup file exists but is empty for blog {$current_blog_id}");
            }
        } else {
            error_log("Homey: No backup file found for blog {$current_blog_id}");
            // fallback للنسخة العامة إذا لم توجد نسخة خاصة بالموقع
            $general_backup_file = ABSPATH . 'wp-content/homey_options_emergency_backup.json';
            if (file_exists($general_backup_file)) {
                $backup = json_decode(file_get_contents($general_backup_file), true);
                if (!empty($backup)) {
                    $result = update_option('homey_options', $backup, false);
                    error_log("Homey: General backup restore for blog {$current_blog_id} - Result: " . ($result ? 'SUCCESS' : 'FAILED'));
                }
            }
        }
    }
});
