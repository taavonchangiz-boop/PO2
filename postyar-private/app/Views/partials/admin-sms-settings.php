<?php
/** تنظیمات مستقل پنل‌های پیامکی */
$tf = \WHCM\Domain\TextFormat::class;
$activeSms = (string)($sms_settings['sms_provider_active'] ?? 'smsir');
$smsProviders = \WHCM\Core\SmsProviderRegistry::all();
$recipient_count = count($active_users ?? []);
?>

<!-- ======================================== -->
<!-- ۱. تنظیمات اتصال پنل پیامک -->
<!-- ======================================== -->
<div class="card" style="margin-bottom:1.5rem;">
    <h2>📱 تنظیمات پنل پیامک</h2>
    <p style="color:var(--text-muted);font-size:.85rem;line-height:1.8;margin-bottom:1.5rem;">پنل موردنظر را انتخاب کنید. فقط تنظیمات همان پنل نمایش داده می‌شود و اطلاعات پنل‌های دیگر در این صفحه دخالتی ندارند.</p>

    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/save-sms-config'); ?>" method="POST">
        <?php echo \WHCM\Core\Csrf::field(); ?>
        <div class="form-group" style="max-width:520px;margin-bottom:1.25rem;">
            <label for="sms_provider_active">پنل پیامکی مورد استفاده:</label>
            <select name="sms_provider_active" id="sms_provider_active" style="width:100%;padding:.75rem;border-radius:10px;background:#1C1C28;color:#fff;border:1px solid #3A3025;">
                <?php foreach ($smsProviders as $id => $meta): ?>
                    <option value="<?php echo htmlspecialchars($id); ?>" <?php echo $activeSms === $id ? 'selected' : ''; ?>><?php echo htmlspecialchars($meta['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php foreach ($smsProviders as $id => $meta):
            $enabled = (($sms_settings['sms_provider_' . $id . '_enabled'] ?? '0') === '1');
        ?>
            <section class="sms-provider-card" data-provider="<?php echo htmlspecialchars($id); ?>" style="display:none;border:1px solid #2A2A38;border-radius:14px;padding:1.25rem;background:#1C1C28;margin-bottom:1rem;">
                <h3 style="margin:0 0 1rem;color:#fff;">تنظیمات <?php echo htmlspecialchars($meta['name']); ?></h3>
                <label style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;cursor:pointer;">
                    <input type="checkbox" name="sms_enabled_<?php echo htmlspecialchars($id); ?>" value="1" <?php echo $enabled ? 'checked' : ''; ?> style="width:19px;height:19px;accent-color:#34D399;">
                    <span>فعال کردن این پنل</span>
                </label>
                <div class="form-row">
                <?php foreach ($meta['fields'] as $field):
                    $secret = in_array($field, ['api_key','password'], true);
                    $labelMap = ['api_key'=>'کلید دسترسی','line_number'=>'شماره خط ارسال','username'=>'نام کاربری','password'=>'رمز عبور','sender'=>'شماره/نام فرستنده','base_url'=>'نشانی سرویس'];
                    $prefix='sms_provider_'.$id.'_';
                    $stored=$sms_settings[$prefix.$field] ?? '';
                ?>
                    <div class="form-group">
                        <label><?php echo $labelMap[$field] ?? $field; ?>:</label>
                        <input type="<?php echo $secret ? 'password' : 'text'; ?>" name="sms_<?php echo htmlspecialchars($id.'_'.$field); ?>" value="<?php echo $secret ? '' : htmlspecialchars($stored); ?>" placeholder="<?php echo $secret && $stored !== '' ? 'مقدار فعلی ذخیره شده است؛ برای تغییر مقدار جدید وارد کنید' : ($field === 'base_url' ? 'نشانی سرویس را در صورت نیاز وارد کنید' : ''); ?>" autocomplete="off" style="width:100%;box-sizing:border-box;padding:.65rem;border-radius:10px;background:#1C1C28;color:white;border:1px solid #3A3025;direction:ltr;text-align:left;">
                    </div>
                <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-top:.5rem;">
                    <button type="submit" class="btn btn-success" style="min-width:170px;padding:.7rem 1.2rem;">ذخیره تنظیمات</button>
                    <button type="submit" name="sms_provider_active" value="<?php echo htmlspecialchars($id); ?>" formaction="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/test-sms'); ?>" class="btn" style="min-width:170px;padding:.7rem 1.2rem;background:#5C9FE7;color:#fff;border:0;border-radius:10px;font-weight:800;">آزمون اتصال</button>
                </div>
            </section>
        <?php endforeach; ?>

        <div id="sms-test-phone" style="display:none;margin-top:1rem;border:1px dashed #3A3025;border-radius:12px;padding:1rem;">
            <label for="test_phone">شماره موبایل برای آزمون ارسال:</label>
            <input type="text" name="test_phone" id="test_phone" placeholder="۰۹۱۲۳۴۵۶۷۸۹" style="width:100%;max-width:360px;padding:.65rem;border-radius:10px;background:#1C1C28;color:#fff;border:1px solid #3A3025;direction:ltr;text-align:left;margin-top:.4rem;">
            <p style="font-size:.75rem;color:#B4B2BE;margin:.5rem 0 0;">برای پنل «اس‌ام‌اس.آی‌آر» آزمون می‌تواند یک پیام واقعی آزمایشی ارسال کند.</p>
        </div>
    </form>
</div>

<script>
(function(){
    function syncSms(){
        var select=document.getElementById('sms_provider_active');
        if(!select)return;
        var value=select.value;
        document.querySelectorAll('.sms-provider-card').forEach(function(card){
            card.style.display=(card.getAttribute('data-provider')===value)?'block':'none';
        });
        var phone=document.getElementById('sms-test-phone');
        if(phone) phone.style.display=value?'block':'none';
    }
    document.addEventListener('DOMContentLoaded',function(){
        var select=document.getElementById('sms_provider_active');
        if(select) select.addEventListener('change',syncSms);
        syncSms();
    });
})();
</script>

<!-- ۲. جدول قالب‌های پیامک -->
<!-- ======================================== -->
<div class="card" style="margin-bottom:1.5rem;">
    <h2>📋 قالب‌های پیامک</h2>
    <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-bottom:1rem;">
        هر قالب مربوط به یک رویداد خاص است و شناسه قالب پنل اس‌ام‌اس.آی‌آر را دریافت می‌کند.
    </p>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>کلید رویداد</th>
                    <th>نام قالب</th>
                    <th>شناسه (TemplateId)</th>
                    <th>وضعیت</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $t): ?>
                <tr>
                    <td data-label="کلید رویداد">
                        <code style="background:rgba(99,102,241,0.15); color:#A5B4FC; padding:0.2rem 0.5rem; border-radius:6px; font-size:0.8rem; direction:ltr; display:inline-block;"><?php echo htmlspecialchars((string)($t['event_key'] ?? '')); ?></code>
                    </td>
                    <td data-label="نام قالب" style="color:white; font-weight:600;"><?php echo htmlspecialchars((string)($t['template_name'] ?? '')); ?></td>
                    <td data-label="شناسه" style="direction:ltr; text-align:left;"><?php echo $tf::fa_digits((string)($t['template_id'] ?? '')); ?></td>
                    <td data-label="وضعیت">
                        <span class="badge badge-<?php echo ($t['is_active'] ?? 0) ? 'approved' : 'pending'; ?>">
                            <?php echo ($t['is_active'] ?? 0) ? 'فعال ✔' : 'غیرفعال'; ?>
                        </span>
                    </td>
                    <td data-label="عملیات">
                        <button type="button" class="btn btn-outline btn-sm" style="background:rgba(99,102,241,0.15); color:#A5B4FC; border:1px solid rgba(99,102,241,0.3); padding:0.35rem 0.7rem; font-size:0.78rem; border-radius:8px; cursor:pointer;" onclick='editSmsTemplate(<?php echo json_encode($t, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE); ?>)'>✏️ ویرایش</button>
                        <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/delete-sms-template'); ?>" method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف این قالب مطمئن هستید؟');">
                            <?php echo \WHCM\Core\Csrf::field(); ?>
                            <input type="hidden" name="template_db_id" value="<?php echo $t['id']; ?>">
                            <button type="submit" class="btn btn-danger" style="padding:0.35rem 0.7rem; font-size:0.78rem; border-radius:8px;">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- فرم افزودن/ویرایش قالب (مدال) -->
    <div style="margin-top:1.25rem; padding-top:1.25rem; border-top:1px dashed var(--border);">
        <h3 style="font-size:0.95rem; margin-bottom:1rem; color:#A5B4FC;">➕ افزودن قالب جدید / ویرایش</h3>
        <form id="sms-template-form" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/save-sms-template'); ?>" method="POST">
            <?php echo \WHCM\Core\Csrf::field(); ?>
            <input type="hidden" name="template_db_id" id="sms_tpl_db_id" value="0">
            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label for="sms_event_key">کلید رویداد:</label>
                    <select name="event_key" id="sms_event_key" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                        <option value="">-- انتخاب کنید --</option>
                        <option value="registration">ثبت‌نام کاربر جدید</option>
                        <option value="payment_confirm">تایید تراکنش پرداخت</option>
                        <option value="subscription_expiry">یادآوری انقضای اشتراک</option>
                        <option value="password_reset">بازنشانی رمز عبور</option>
                        <option value="bulk_notification">اطلاع‌رسانی عمومی</option>
                        <option value="custom">سفارشی (وارد کنید)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="sms_tpl_name">نام قالب:</label>
                    <input type="text" name="template_name" id="sms_tpl_name" placeholder="مثال: تایید پرداخت" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                </div>
            </div>
            <div class="form-row" style="margin-bottom:1rem;">
                <div class="form-group">
                    <label for="sms_tpl_id">شناسه قالب (TemplateId):</label>
                    <input type="number" name="template_id" id="sms_tpl_id" placeholder="مثال: 78432" min="1" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left;">
                </div>
                <div class="form-group">
                    <label for="sms_tpl_params">پارامترها (JSON):</label>
                    <input type="text" name="parameters" id="sms_tpl_params" value='[]' placeholder='["name", "amount"]' style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left; font-family:monospace;">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:1rem;">
                <label style="display:flex; align-items:center; gap:0.75rem; cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1" checked style="width:18px; height:18px; accent-color:#34D399;">
                    <span style="color:white; font-size:0.9rem;">قالب فعال باشد</span>
                </label>
            </div>
            <button type="submit" class="btn btn-success" style="width:100%;">ذخیره قالب 📱✔</button>
        </form>
    </div>
</div>

<!-- ======================================== -->
<!-- ۳. ارسال پیامک انبوه -->
<!-- ======================================== -->
<div class="card" style="margin-bottom:1.5rem;">
    <h2>📢 ارسال پیامک انبوه</h2>
    <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-bottom:1.5rem;">
        ارسال پیامک گروهی به کاربران بر اساس قالب تعریف شده.
    </p>

    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/send-bulk-sms'); ?>" method="POST">
        <?php echo \WHCM\Core\Csrf::field(); ?>

        <div class="form-group" style="margin-bottom:1.25rem;">
            <label for="bulk_recipient_type">نوع گیرندگان:</label>
            <select name="recipient_type" id="bulk_recipient_type" onchange="toggleBulkPhoneInput()" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                <option value="active">کاربران فعال (دارای شماره موبایل)</option>
                <option value="all">همه کاربران (دارای شماره موبایل)</option>
                <option value="manual">شماره دستی</option>
            </select>
            <div style="font-size:0.78rem; color:var(--text-muted); margin-top:0.35rem;">
                تعداد کاربران فعال دارای شماره: <strong style="color:#A5B4FC;"><?php echo $tf::fa_digits($recipient_count); ?></strong> نفر
            </div>
        </div>

        <div class="form-group" id="bulk_manual_phones_group" style="margin-bottom:1.25rem; display:none;">
            <label for="bulk_manual_phones">شماره‌ها (هر خط یک شماره یا با کاما جدا):</label>
            <textarea name="manual_phones" id="bulk_manual_phones" rows="3" placeholder="09123456789&#10;09129876543" style="width:100%; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; padding:0.75rem; direction:ltr; text-align:left; font-family:monospace;"></textarea>
        </div>

        <div class="form-group" style="margin-bottom:1.25rem;">
            <label for="bulk_template_id">قالب پیامک:</label>
            <select name="bulk_template_id" id="bulk_template_id" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                <option value="0">-- انتخاب قالب --</option>
                <?php foreach ($templates as $t): if ($t['is_active']): ?>
                    <option value="<?php echo $t['template_id']; ?>"><?php echo htmlspecialchars((string)($t['template_name'] ?? '')) . ' (' . $tf::fa_digits((string)($t['template_id'] ?? '')) . ')'; ?></option>
                <?php endif; endforeach; ?>
            </select>
        </div>

        <h3 style="font-size:0.9rem; margin-bottom:0.75rem; border-bottom:1px dashed var(--border); padding-bottom:0.4rem; color:#A5B4FC;">🔧 پارامترهای قالب</h3>
        <div class="form-row" style="margin-bottom:1rem;">
            <div class="form-group">
                <label for="param1_name">نام پارامتر ۱:</label>
                <input type="text" name="param1_name" id="param1_name" placeholder="name" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left;">
            </div>
            <div class="form-group">
                <label for="param1_value">مقدار پارامتر ۱:</label>
                <input type="text" name="param1_value" id="param1_value" placeholder="علی" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
            </div>
        </div>
        <div class="form-row" style="margin-bottom:1.5rem;">
            <div class="form-group">
                <label for="param2_name">نام پارامتر ۲:</label>
                <input type="text" name="param2_name" id="param2_name" placeholder="amount" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left;">
            </div>
            <div class="form-group">
                <label for="param2_value">مقدار پارامتر ۲:</label>
                <input type="text" name="param2_value" id="param2_value" placeholder="۳۰۰,۰۰۰" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
            </div>
        </div>

        <div style="background:rgba(239,164,91,0.08); border:1px solid rgba(239,164,91,0.25); border-radius:10px; padding:0.75rem; margin-bottom:1.25rem; font-size:0.82rem; color:#F5BC82;">
            💡 تخمین هزینه: هر پیامک تقریباً <strong>۱۵ تومان</strong> است. ارسال به <strong><?php echo $tf::fa_digits($recipient_count); ?></strong> شماره = حدود <strong><?php echo $tf::fa_digits($recipient_count * 15); ?></strong> تومان
        </div>

        <button type="submit" class="btn btn-success" style="width:100%;" onclick="return confirm('آیا از ارسال پیامک انبوه مطمئن هستید؟ این عمل غیرقابل بازگشت است.');">📨 ارسال پیامک انبوه</button>
    </form>
</div>

<!-- ======================================== -->
<!-- ۴. لاگ ارسال پیامک -->
<!-- ======================================== -->
<div class="card">
    <h2>📝 لاگ ارسال پیامک‌ها</h2>
    <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-bottom:1rem;">
        تاریخچه ارسال‌های اخیر (۵۰ مورد آخر). فیلتر بر اساس وضعیت یا شماره موبایل امکان‌پذیر است.
    </p>

    <!-- فیلتر -->
    <form method="GET" action="/index.php" style="display:flex; gap:0.75rem; margin-bottom:1.25rem; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="route" value="/hnnh/sms-settings">
        <div class="form-group" style="flex:1; min-width:150px; margin-bottom:0;">
            <label for="log_filter_status">وضعیت:</label>
            <select name="filter_status" id="log_filter_status" style="width:100%; padding:0.5rem; border-radius:8px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                <option value="">همه</option>
                <option value="success" <?php echo ($filter_status ?? '') === 'success' ? 'selected' : ''; ?>>موفق ✔</option>
                <option value="failed" <?php echo ($filter_status ?? '') === 'failed' ? 'selected' : ''; ?>>ناموفق ✘</option>
                <option value="rate_limited" <?php echo ($filter_status ?? '') === 'rate_limited' ? 'selected' : ''; ?>>محدود شده ⏳</option>
            </select>
        </div>
        <div class="form-group" style="flex:1; min-width:150px; margin-bottom:0;">
            <label for="log_filter_phone">شماره موبایل:</label>
            <input type="text" name="filter_phone" id="log_filter_phone" value="<?php echo htmlspecialchars($filter_phone ?? ''); ?>" placeholder="0912..." style="width:100%; padding:0.5rem; border-radius:8px; background:#1C1C28; color:white; border:1px solid #2A2A38; direction:ltr; text-align:left;">
        </div>
        <button type="submit" class="btn btn-outline" style="background:rgba(99,102,241,0.15); color:#A5B4FC; border:1px solid rgba(99,102,241,0.3); padding:0.5rem 1rem; border-radius:8px; white-space:nowrap; margin-bottom:0;">🔍 فیلتر</button>
        <a href="/index.php?route=%2Fhnnh%2Fsms-settings" class="btn btn-outline" style="background:rgba(15,15,20,0.045); color:var(--text-muted); border:1px solid var(--border); padding:0.5rem 1rem; border-radius:8px; white-space:nowrap; text-decoration:none; margin-bottom:0;">پاک کردن فیلتر</a>
    </form>

    <?php if (empty($logs)): ?>
        <p style="color: var(--text-muted); text-align: center; padding: 2rem 0;">هیچ لاگی وجود ندارد.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>تاریخ</th>
                        <th>شماره</th>
                        <th>قالب</th>
                        <th>وضعیت</th>
                        <th>کد پاسخ</th>
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
                        <td data-label="شماره" style="direction:ltr; text-align:left; font-size:0.85rem;"><?php echo htmlspecialchars($l['phone'] ?? ''); ?></td>
                        <td data-label="قالب" style="font-size:0.82rem; color:#A5B4FC;"><?php echo htmlspecialchars($l['template_name'] ?? ($l['event_key'] ?? '—')); ?></td>
                        <td data-label="وضعیت">
                            <?php
                                $st = $l['status'] ?? 'pending';
                                $badge_class = 'pending';
                                $st_text = 'در انتظار ⏳';
                                if ($st === 'success') { $badge_class = 'approved'; $st_text = 'موفق ✔'; }
                                elseif ($st === 'failed') { $badge_class = 'pending'; $st_text = 'ناموفق ✘'; }
                                elseif ($st === 'rate_limited') { $badge_class = 'pending'; $st_text = 'محدود ⏳'; }
                            ?>
                            <span class="badge badge-<?php echo $badge_class; ?>"><?php echo $st_text; ?></span>
                        </td>
                        <td data-label="کد پاسخ" style="direction:ltr; font-size:0.82rem;"><?php echo htmlspecialchars($l['response_code'] ?? '—'); ?></td>
                        <td data-label="خطا" style="font-size:0.78rem; color:#F87171; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($l['error_message'] ?? ''); ?>"><?php echo htmlspecialchars($l['error_message'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- اسکریپت فرم پیامک -->
<script>
function editSmsTemplate(tpl) {
    document.getElementById('sms_tpl_db_id').value = tpl.id || 0;
    document.getElementById('sms_event_key').value = tpl.event_key || '';
    document.getElementById('sms_tpl_name').value = tpl.template_name || '';
    document.getElementById('sms_tpl_id').value = (tpl.template_id !== null && tpl.template_id !== undefined) ? tpl.template_id : '';
    document.getElementById('sms_tpl_params').value = tpl.parameters || '[]';

    var activeCheckbox = document.querySelector('#sms-template-form input[name="is_active"]');
    if (activeCheckbox) activeCheckbox.checked = (tpl.is_active == 1);

    document.getElementById('sms-template-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function toggleBulkPhoneInput() {
    var sel = document.getElementById('bulk_recipient_type').value;
    var group = document.getElementById('bulk_manual_phones_group');
    group.style.display = (sel === 'manual') ? 'block' : 'none';
}
</script>
