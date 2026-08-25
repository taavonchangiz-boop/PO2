<?php
/**
 * @var array $email_settings
 * @var array $templates
 * @var array $logs
 * @var array $email_stats
 * @var array $active_users
 * @var array $all_users
 */
$tf = \WHCM\Domain\TextFormat::class;
$smtp_enabled = ($email_settings['smtp_enabled'] ?? '') === '1';
$smtp_host = $email_settings['smtp_host'] ?? '';
$smtp_port = $email_settings['smtp_port'] ?? '587';
$smtp_username = $email_settings['smtp_username'] ?? '';
$smtp_password = $email_settings['smtp_password'] ?? '';
$smtp_encryption = $email_settings['smtp_encryption'] ?? 'tls';
$smtp_from_address = $email_settings['smtp_from_address'] ?? '';
$smtp_from_name = $email_settings['smtp_from_name'] ?? '';
$smtp_timeout = $email_settings['smtp_timeout'] ?? '15';
$smtp_auth = ($email_settings['smtp_auth'] ?? '1') === '1';
$smtp_reply_to = $email_settings['smtp_reply_to'] ?? '';
$smtp_reply_name = $email_settings['smtp_reply_name'] ?? '';
$recipient_count = count($active_users ?? []);
?>

<!-- ======================================== -->
<!-- ۱. آمار سریع ایمیل -->
<!-- ======================================== -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
    <div class="card-stat" style="background:linear-gradient(135deg, #312e81 0%, #A5B4FC 100%); border-radius:14px; padding:1.25rem; text-align:center;">
        <div style="font-size:2rem;">📧</div>
        <div style="color:rgba(255,255,255,0.8); font-size:0.8rem; margin-top:0.35rem;">کل ارسال‌ها</div>
        <div style="color:white; font-size:1.5rem; font-weight:800;"><?php echo $tf::fa_digits($email_stats['total_attempts'] ?? (($email_stats['total_sent'] ?? 0) + ($email_stats['total_failed'] ?? 0))); ?></div>
    </div>
    <div class="card-stat" style="background:linear-gradient(135deg, #7f1d1d 0%, #F87171 100%); border-radius:14px; padding:1.25rem; text-align:center;">
        <div style="font-size:2rem;">❌</div>
        <div style="color:rgba(255,255,255,0.8); font-size:0.8rem; margin-top:0.35rem;">ارسال ناموفق</div>
        <div style="color:white; font-size:1.5rem; font-weight:800;"><?php echo $tf::fa_digits($email_stats['total_failed'] ?? 0); ?></div>
    </div>
    <div class="card-stat" style="background:linear-gradient(135deg, #0F0F14 0%, #34D399 100%); border-radius:14px; padding:1.25rem; text-align:center;">
        <div style="font-size:2rem;">📋</div>
        <div style="color:rgba(255,255,255,0.8); font-size:0.8rem; margin-top:0.35rem;">تعداد قالب‌ها</div>
        <div style="color:white; font-size:1.5rem; font-weight:800;"><?php echo $tf::fa_digits(count($templates ?? [])); ?></div>
    </div>
    <div class="card-stat" style="background:linear-gradient(135deg, #065f46 0%, #82D9A2 100%); border-radius:14px; padding:1.25rem; text-align:center;">
        <div style="font-size:2rem;">✅</div>
        <div style="color:rgba(255,255,255,0.8); font-size:0.8rem; margin-top:0.35rem;">ارسال‌های موفق</div>
        <div style="color:white; font-size:1.5rem; font-weight:800;"><?php echo $tf::fa_digits($email_stats['total_success'] ?? $email_stats['total_sent'] ?? 0); ?></div>
    </div>
</div>

<!-- ======================================== -->
<!-- ۲. تنظیمات اتصال سرور ایمیل -->
<!-- ======================================== -->
<div class="card" style="margin-bottom:1.5rem;">
    <h2>📧 تنظیمات سرور ارسال ایمیل</h2>
    <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-bottom:1.5rem;">
        پیکربندی سرور ارسال ایمیل برای ارسال ایمیل. تنظیمات در دیتابیس ذخیره می‌شود.
    </p>

    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/save-email-config'); ?>" method="POST">
        <?php echo \WHCM\Core\Csrf::field(); ?>

        <!-- فعال/غیرفعال -->
        <div class="form-group" style="margin-bottom:1.5rem;">
            <label style="display:flex; align-items:center; gap:0.75rem; cursor:pointer;">
                <input type="checkbox" name="smtp_enabled" value="1" <?php echo $smtp_enabled ? 'checked' : ''; ?> style="width:20px; height:20px; accent-color:#34D399;">
                <span style="color:white; font-weight:700; font-size:0.95rem;">فعال بودن سیستم ایمیل</span>
            </label>
        </div>

        <div class="form-row" style="margin-bottom:1rem;">
            <div class="form-group">
                <label for="smtp_host">سرور ایمیل:</label>
                <input type="text" name="smtp_host" id="smtp_host" value="<?php echo htmlspecialchars($smtp_host); ?>" placeholder="smtp.example.com" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left;">
            </div>
            <div class="form-group">
                <label for="smtp_port">شماره درگاه:</label>
                <input type="number" name="smtp_port" id="smtp_port" value="<?php echo htmlspecialchars($smtp_port); ?>" placeholder="587" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left;">
            </div>
        </div>

        <div class="form-row" style="margin-bottom:1rem;">
            <div class="form-group">
                <label for="smtp_username">نام کاربری:</label>
                <input type="text" name="smtp_username" id="smtp_username" value="<?php echo htmlspecialchars($smtp_username); ?>" placeholder="user@example.com" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left;">
            </div>
            <div class="form-group">
                <label for="smtp_password">رمز عبور:</label>
                <input type="password" name="smtp_password" id="smtp_password" value="" placeholder="<?php echo !empty($smtp_password) ? 'رمز فعلی ذخیره شده است — برای تغییر مقدار جدید وارد کنید' : 'رمز عبور سرور ایمیل'; ?>" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left;">
                <span style="font-size:0.72rem; color:var(--text-muted);">در صورت خالی گذاشتن، رمز قبلی حفظ می‌شود.</span>
            </div>
        </div>

        <div class="form-row" style="margin-bottom:1rem;">
            <div class="form-group">
                <label for="smtp_encryption">رمزنگاری:</label>
                <select name="smtp_encryption" id="smtp_encryption" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                    <option value="tls" <?php echo $smtp_encryption === 'tls' ? 'selected' : ''; ?>>رمزنگاری امن</option>
                    <option value="ssl" <?php echo $smtp_encryption === 'ssl' ? 'selected' : ''; ?>>رمزنگاری گواهی</option>
                    <option value="none" <?php echo $smtp_encryption === 'none' ? 'selected' : ''; ?>>بدون رمزنگاری</option>
                </select>
            </div>
            <div class="form-group">
                <label for="smtp_from_name">نام فرستنده:</label>
                <input type="text" name="smtp_from_name" id="smtp_from_name" value="<?php echo htmlspecialchars($smtp_from_name); ?>" placeholder="پُست‌یار" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
            </div>
        </div>

        <div class="form-row" style="margin-bottom:1rem;">
            <div class="form-group"><label for="smtp_timeout">زمان انتظار (ثانیه):</label><input type="number" min="5" max="60" name="smtp_timeout" id="smtp_timeout" value="<?php echo htmlspecialchars($smtp_timeout); ?>" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left;"></div>
            <div class="form-group" style="display:flex;align-items:center;gap:.5rem;padding-top:1.6rem;"><input type="checkbox" name="smtp_auth" value="1" <?php echo $smtp_auth?'checked':''; ?>> <label>احراز هویت سرور ایمیل</label></div>
        </div>
        <div class="form-row" style="margin-bottom:1rem;">
            <div class="form-group"><label for="smtp_reply_to">پاسخ به:</label><input type="email" name="smtp_reply_to" id="smtp_reply_to" value="<?php echo htmlspecialchars($smtp_reply_to); ?>" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left;"></div>
            <div class="form-group"><label for="smtp_reply_name">نام پاسخ به:</label><input type="text" name="smtp_reply_name" id="smtp_reply_name" value="<?php echo htmlspecialchars($smtp_reply_name); ?>" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;"></div>
        </div>
        <div class="form-group" style="margin-bottom:1.5rem;">
            <label for="smtp_from_address">آدرس ایمیل فرستنده:</label>
            <input type="email" name="smtp_from_address" id="smtp_from_address" value="<?php echo htmlspecialchars($smtp_from_address); ?>" placeholder="noreply@your-domain.ir" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left;">
        </div>

        <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
            <button type="submit" class="btn btn-success" style="flex:1;min-width:180px;">ذخیره تنظیمات ایمیل ✔</button>
            <button type="submit" formaction="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/test-email'); ?>" class="btn" style="flex:1;min-width:180px;background:#5C9FE7;color:#fff;border:0;border-radius:10px;font-weight:800;">آزمون اتصال و ارسال</button>
        </div>
    </form>

</div>

<!-- ======================================== -->
<!-- ۳. جدول قالب‌های ایمیل -->
<!-- ======================================== -->
<div class="card" style="margin-bottom:1.5rem;">
    <h2>📋 قالب‌های ایمیل</h2>
    <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-bottom:1rem;">
        مدیریت قالب‌های ایمیل برای رویدادهای مختلف سیستم. متغیرها با سینتکس <code style="background:rgba(99,102,241,0.15); color:#A5B4FC; padding:0.15rem 0.4rem; border-radius:4px; font-size:0.8rem;">{{name}}</code> جایگزین می‌شوند.
    </p>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>کلید رویداد</th>
                    <th>نام قالب</th>
                    <th>موضوع</th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $t): ?>
                <tr id="email-tpl-row-<?php echo ($t['id'] ?? 0); ?>">
                    <td data-label="کلید رویداد">
                        <code style="background:rgba(99,102,241,0.15); color:#A5B4FC; padding:0.2rem 0.5rem; border-radius:6px; font-size:0.8rem; direction:ltr; display:inline-block;"><?php echo htmlspecialchars(($t['event_key'] ?? '')); ?></code>
                    </td>
                    <td data-label="نام قالب" style="color:white; font-weight:600;"><?php echo htmlspecialchars(($t['template_name'] ?? '')); ?></td>
                    <td data-label="موضوع" style="font-size:0.82rem; color:#B4B2BE; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars(($t['subject'] ?? '')); ?>"><?php echo htmlspecialchars(($t['subject'] ?? '')); ?></td>
                    <td data-label="وضعیت">
                        <span class="badge badge-<?php echo ($t['is_active'] ?? 0) ? 'approved' : 'pending'; ?>">
                            <?php echo ($t['is_active'] ?? 0) ? 'فعال ✔' : 'غیرفعال'; ?>
                        </span>
                    </td>
                    <td data-label="عملیات">
                        <button type="button" class="btn btn-outline btn-sm" style="background:rgba(99,102,241,0.15); color:#A5B4FC; border:1px solid rgba(99,102,241,0.3); padding:0.35rem 0.7rem; font-size:0.78rem; border-radius:8px; cursor:pointer;" onclick='editEmailTemplate(<?php echo json_encode($t, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE); ?>)'>✏️</button>
                        <button type="button" class="btn btn-outline btn-sm" style="background:rgba(52,211,153,0.15); color:#34D399; border:1px solid rgba(52,211,153,0.3); padding:0.35rem 0.7rem; font-size:0.78rem; border-radius:8px; cursor:pointer;" onclick='previewEmailTemplate(<?php echo ($t['id'] ?? 0); ?>)'>👁</button>
                        <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/delete-email-template'); ?>" method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف این قالب مطمئن هستید؟');">
                            <?php echo \WHCM\Core\Csrf::field(); ?>
                            <input type="hidden" name="template_db_id" value="<?php echo ($t['id'] ?? 0); ?>">
                            <button type="submit" class="btn btn-danger" style="padding:0.35rem 0.7rem; font-size:0.78rem; border-radius:8px;">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- فرم افزودن/ویرایش قالب -->
    <div style="margin-top:1.25rem; padding-top:1.25rem; border-top:1px dashed var(--border);">
        <h3 style="font-size:0.95rem; margin-bottom:1rem; color:#A5B4FC;">➕ افزودن قالب جدید / ویرایش</h3>
        <form id="email-template-form" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/save-email-template'); ?>" method="POST">
            <?php echo \WHCM\Core\Csrf::field(); ?>
            <input type="hidden" name="template_db_id" id="email_tpl_db_id" value="0">

            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label for="email_event_key">کلید رویداد:</label>
                    <select name="event_key" id="email_event_key" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                        <option value="">-- انتخاب کنید --</option>
                        <option value="welcome">خوش‌آمدگویی ثبت‌نام</option>
                        <option value="payment_confirm">تاییدیه پرداخت</option>
                        <option value="subscription_expiry">یادآوری انقضای اشتراک</option>
                        <option value="subscription_expired">انقضای اشتراک</option>
                        <option value="password_reset">بازنشانی رمز عبور</option>
                        <option value="ticket_reply">پاسخ به تیکت</option>
                        <option value="custom_notification">اعلان عمومی</option>
                        <option value="custom">سفارشی (وارد کنید)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="email_tpl_name">نام قالب:</label>
                    <input type="text" name="template_name" id="email_tpl_name" placeholder="مثال: خوش‌آمدگویی" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom:1rem;">
                <label for="email_tpl_subject">موضوع ایمیل:</label>
                <input type="text" name="subject" id="email_tpl_subject" placeholder="مثال: خوش آمدید {{name}} عزیز!" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
            </div>

            <div class="form-group" style="margin-bottom:1rem;">
                <label for="email_tpl_body">بدنه HTML ایمیل:</label>
                <textarea name="body_html" id="email_tpl_body" rows="12" placeholder="<h1>سلام {{name}} عزیز</h1><p>به {{app_name}} خوش آمدید</p>" style="width:100%; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; padding:0.75rem; font-family:monospace; font-size:0.82rem; direction:ltr; text-align:left;"></textarea>
            </div>

            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label for="email_tpl_vars">متغیرها (JSON):</label>
                    <input type="text" name="variables" id="email_tpl_vars" value='[]' placeholder='["name", "app_name"]' style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left; font-family:monospace; font-size:0.82rem;">
                </div>
                <div class="form-group" style="display:flex; align-items:flex-end; gap:0.75rem;">
                    <label style="display:flex; align-items:center; gap:0.75rem; cursor:pointer; white-space:nowrap;">
                        <input type="checkbox" name="is_active" value="1" checked style="width:18px; height:18px; accent-color:#34D399;">
                        <span style="color:white; font-size:0.9rem;">فعال</span>
                    </label>
                </div>
            </div>

            <div style="display:flex; gap:0.75rem;">
                <button type="submit" class="btn btn-success" style="flex:1;">ذخیره قالب 📧✔</button>
                <button type="button" class="btn btn-outline" style="background:rgba(99,102,241,0.15); color:#A5B4FC; border:1px solid rgba(99,102,241,0.3); padding:0.6rem 1.2rem; border-radius:10px; cursor:pointer; font-weight:700;" onclick="previewCurrentEmailTemplate()">👁 پیش‌نمایش</button>
            </div>
        </form>

        <!-- راهنمای متغیرها -->
        <div style="margin-top:1rem; background:rgba(99,102,241,0.06); border:1px solid rgba(99,102,241,0.2); border-radius:10px; padding:0.85rem;">
            <h4 style="font-size:0.85rem; color:#A5B4FC; margin-bottom:0.5rem;">📘 متغیرهای قابل استفاده:</h4>
            <div style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                <code style="background:#1C1C28; color:#B4B2BE; padding:0.2rem 0.6rem; border-radius:6px; font-size:0.78rem;">{{name}}</code>
                <code style="background:#1C1C28; color:#B4B2BE; padding:0.2rem 0.6rem; border-radius:6px; font-size:0.78rem;">{{app_name}}</code>
                <code style="background:#1C1C28; color:#B4B2BE; padding:0.2rem 0.6rem; border-radius:6px; font-size:0.78rem;">{{app_url}}</code>
                <code style="background:#1C1C28; color:#B4B2BE; padding:0.2rem 0.6rem; border-radius:6px; font-size:0.78rem;">{{plan_name}}</code>
                <code style="background:#1C1C28; color:#B4B2BE; padding:0.2rem 0.6rem; border-radius:6px; font-size:0.78rem;">{{amount}}</code>
                <code style="background:#1C1C28; color:#B4B2BE; padding:0.2rem 0.6rem; border-radius:6px; font-size:0.78rem;">{{date}}</code>
                <code style="background:#1C1C28; color:#B4B2BE; padding:0.2rem 0.6rem; border-radius:6px; font-size:0.78rem;">{{days_left}}</code>
                <code style="background:#1C1C28; color:#B4B2BE; padding:0.2rem 0.6rem; border-radius:6px; font-size:0.78rem;">{{ticket_subject}}</code>
                <code style="background:#1C1C28; color:#B4B2BE; padding:0.2rem 0.6rem; border-radius:6px; font-size:0.78rem;">{{reset_link}}</code>
                <code style="background:#1C1C28; color:#B4B2BE; padding:0.2rem 0.6rem; border-radius:6px; font-size:0.78rem;">{{message}}</code>
            </div>
        </div>
    </div>
</div>

<!-- ======================================== -->
<!-- ۴. ارسال ایمیل انبوه -->
<!-- ======================================== -->
<div class="card" style="margin-bottom:1.5rem;">
    <h2>📢 ارسال ایمیل انبوه</h2>
    <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-bottom:1.5rem;">
        ارسال گروهی ایمیل به کاربران بر اساس قالب انتخاب‌شده.
    </p>

    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/send-bulk-email'); ?>" method="POST" onsubmit="return confirm('آیا از ارسال ایمیل انبوه مطمئن هستید؟ این عمل غیرقابل بازگشت است.');">
        <?php echo \WHCM\Core\Csrf::field(); ?>

        <div class="form-group" style="margin-bottom:1.25rem;">
            <label for="bulk_email_recipient">نوع گیرندگان:</label>
            <select name="recipient_type" id="bulk_email_recipient" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                <option value="active">کاربران فعال</option>
                <option value="all">همه کاربران</option>
                <option value="subscription">دارای اشتراک فعال</option>
            </select>
            <div style="font-size:0.78rem; color:var(--text-muted); margin-top:0.35rem;">
                تعداد کاربران فعال: <strong style="color:#A5B4FC;"><?php echo $tf::fa_digits($recipient_count); ?></strong> نفر
            </div>
        </div>

        <div class="form-group" style="margin-bottom:1.5rem;">
            <label for="bulk_email_template">قالب ایمیل:</label>
            <select name="bulk_template_id" id="bulk_email_template" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                <option value="0">-- انتخاب قالب --</option>
                <?php foreach ($templates as $t): if (($t['is_active'] ?? 0)): ?>
                    <option value="<?php echo ($t['id'] ?? 0); ?>"><?php echo htmlspecialchars(($t['template_name'] ?? '')) . ' (' . htmlspecialchars(($t['event_key'] ?? '')) . ')'; ?></option>
                <?php endif; endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-success" style="width:100%;">📧 ارسال ایمیل انبوه</button>
    </form>
</div>

<!-- ======================================== -->
<!-- ۵. لاگ ارسال ایمیل -->
<!-- ======================================== -->
<div class="card">
    <h2>📝 لاگ ارسال ایمیل‌ها</h2>
    <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-bottom:1rem;">
        تاریخچه ارسال‌های اخیر (۵۰ مورد آخر).
    </p>

    <!-- فیلتر -->
    <form method="GET" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/email-settings'); ?>" style="display:flex; gap:0.75rem; margin-bottom:1.25rem; align-items:flex-end; flex-wrap:wrap;">
        <div class="form-group" style="flex:1; min-width:150px; margin-bottom:0;">
            <label for="email_log_filter_status">وضعیت:</label>
            <select name="filter_status" id="email_log_filter_status" style="width:100%; padding:0.5rem; border-radius:8px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                <option value="">همه</option>
                <option value="sent" <?php echo ($filter_status ?? '') === 'sent' ? 'selected' : ''; ?>>موفق ✔</option>
                <option value="failed" <?php echo ($filter_status ?? '') === 'failed' ? 'selected' : ''; ?>>ناموفق ✘</option>
            </select>
        </div>
        <button type="submit" class="btn btn-outline" style="background:rgba(99,102,241,0.15); color:#A5B4FC; border:1px solid rgba(99,102,241,0.3); padding:0.5rem 1rem; border-radius:8px; white-space:nowrap; margin-bottom:0;">🔍 فیلتر</button>
        <a href="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/email-settings'); ?>" class="btn btn-outline" style="background:rgba(15,15,20,0.045); color:var(--text-muted); border:1px solid var(--border); padding:0.5rem 1rem; border-radius:8px; white-space:nowrap; text-decoration:none; margin-bottom:0;">پاک کردن فیلتر</a>
    </form>

    <?php if (empty($logs)): ?>
        <p style="color: var(--text-muted); text-align: center; padding: 2rem 0;">هیچ لاگی وجود ندارد.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>گیرنده</th>
                        <th>موضوع</th>
                        <th>وضعیت</th>
                        <th>خطا</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                    <tr>
                        <td data-label="تاریخ" style="font-size:0.8rem; white-space:nowrap;"><?php
                            $created = $l['created_at'] ?? '';
                            if (!empty($created)) {
                                try {
                                    $dt = new \DateTime($created);
                                    $dt->setTimezone(new \DateTimeZone('Asia/Tehran'));
                                    echo $tf::fa_digits($dt->format('Y/m/d H:i'));
                                } catch (\Exception $e) {
                                    echo htmlspecialchars($created);
                                }
                            }
                        ?></td>
                        <td data-label="گیرنده" style="font-size:0.82rem;"><?php
                            echo htmlspecialchars($l['user_name'] ?? '—');
                            echo '<br><span style="color:var(--text-muted); font-size:0.75rem;">' . htmlspecialchars($l['to_address'] ?? '') . '</span>';
                        ?></td>
                        <td data-label="موضوع" style="font-size:0.82rem; color:#B4B2BE; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($l['subject'] ?? ''); ?>"><?php echo htmlspecialchars($l['subject'] ?? '—'); ?></td>
                        <td data-label="وضعیت">
                            <?php
                                $est = $l['status'] ?? 'pending';
                                if ($est === 'sent') {
                                    echo '<span class="badge badge-approved">موفق ✔</span>';
                                } elseif ($est === 'failed') {
                                    echo '<span class="badge badge-pending">ناموفق ✘</span>';
                                } else {
                                    echo '<span class="badge badge-pending">در انتظار ⏳</span>';
                                }
                            ?>
                        </td>
                        <td data-label="خطا" style="font-size:0.78rem; color:#F87171; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($l['error_message'] ?? ''); ?>"><?php echo htmlspecialchars($l['error_message'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- مدال پیش‌نمایش ایمیل -->
<div id="email-preview-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:10000; justify-content:center; align-items:center; padding:2rem;">
    <div style="background:#1C1C28; border:1px solid #2A2A38; border-radius:16px; width:100%; max-width:700px; max-height:85vh; overflow:hidden; display:flex; flex-direction:column;">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-bottom:1px solid #2A2A38;">
            <h3 style="margin:0; color:white; font-size:1rem;">👁 پیش‌نمایش قالب ایمیل</h3>
            <button type="button" onclick="document.getElementById('email-preview-modal').style.display='none'" style="background:none; border:none; color:#B4B2BE; font-size:1.3rem; cursor:pointer;">✖</button>
        </div>
        <div id="email-preview-content" style="flex:1; overflow:auto; padding:1rem; background:#f1f5f9; border-radius:0 0 16px 16px;">
            <!-- محتوای پیش‌نمایش اینجا بارگذاری می‌شود -->
        </div>
    </div>
</div>

<!-- اسکریپت فرم ایمیل -->
<script>
// داده قالب‌ها برای ویرایش سریع
const emailTemplatesData = <?php echo json_encode($templates, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE); ?>;

function editEmailTemplate(tpl) {
    document.getElementById('email_tpl_db_id').value = tpl.id || 0;
    document.getElementById('email_event_key').value = tpl.event_key || '';
    document.getElementById('email_tpl_name').value = tpl.template_name || '';
    document.getElementById('email_tpl_subject').value = tpl.subject || '';
    document.getElementById('email_tpl_body').value = tpl.body_html || '';
    document.getElementById('email_tpl_vars').value = tpl.variables || '[]';

    var activeCheckbox = document.querySelector('#email-template-form input[name="is_active"]');
    if (activeCheckbox) activeCheckbox.checked = (tpl.is_active == 1);

    document.getElementById('email-template-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function previewEmailTemplate(tplId) {
    var tpl = emailTemplatesData.find(function(t) { return t.id == tplId; });
    if (!tpl) return;

    document.getElementById('email-preview-content').innerHTML = '<div style="text-align:center; padding:2rem; color:#4A4857;">در حال بارگذاری...</div>';
    document.getElementById('email-preview-modal').style.display = 'flex';

    var fd = new FormData();
    fd.append('body_html', tpl.body_html);
    fd.append('variables', tpl.variables);

    fetch('<?php echo \WHCM\Core\Bootstrap::getRouteUrl("/hnnh/preview-email-template"); ?>', {
        method: 'POST',
        body: fd
    }).then(function(r) { return r.text(); }).then(function(html) {
        document.getElementById('email-preview-content').innerHTML = html;
    }).catch(function(e) {
        document.getElementById('email-preview-content').innerHTML = '<div style="text-align:center; padding:2rem; color:#F87171;">خطا در بارگذاری پیش‌نمایش</div>';
    });
}

function previewCurrentEmailTemplate() {
    var bodyHtml = document.getElementById('email_tpl_body').value;
    var variables = document.getElementById('email_tpl_vars').value;

    if (!bodyHtml.trim()) {
        alert('لطفاً ابتدا بدنه HTML قالب را وارد کنید.');
        return;
    }

    document.getElementById('email-preview-content').innerHTML = '<div style="text-align:center; padding:2rem; color:#4A4857;">در حال بارگذاری...</div>';
    document.getElementById('email-preview-modal').style.display = 'flex';

    var fd = new FormData();
    fd.append('body_html', bodyHtml);
    fd.append('variables', variables);

    fetch('<?php echo \WHCM\Core\Bootstrap::getRouteUrl("/hnnh/preview-email-template"); ?>', {
        method: 'POST',
        body: fd
    }).then(function(r) { return r.text(); }).then(function(html) {
        document.getElementById('email-preview-content').innerHTML = html;
    }).catch(function(e) {
        document.getElementById('email-preview-content').innerHTML = '<div style="text-align:center; padding:2rem; color:#F87171;">خطا در بارگذاری پیش‌نمایش</div>';
    });
}

// بستن مدال با کلیک بیرون
document.getElementById('email-preview-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>
