<?php
/** تنظیمات مستقل درگاه‌های پرداخت */
$activePayment = (string)($provider_settings['payment_gateway_active'] ?? 'manual');
?>
<div class="card" style="margin-top:1rem;">
    <h2>💳 تنظیمات درگاه‌های پرداخت</h2>
    <p style="color:#B4B2BE;line-height:1.9">ابتدا درگاه موردنظر را انتخاب کنید؛ فقط تنظیمات همان درگاه نمایش داده می‌شود. پس از ذخیره، برای اطمینان از کامل بودن تنظیمات، آزمون اتصال را اجرا کنید.</p>

    <form method="post" action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/save-provider-settings'); ?>">
        <?php echo \WHCM\Core\Csrf::field(); ?>
        <div class="form-group" style="max-width:520px;margin-bottom:1.25rem;">
            <label for="payment_gateway_active">درگاه پرداخت مورد استفاده:</label>
            <select name="payment_gateway_active" id="payment_gateway_active" style="width:100%;padding:.75rem;border-radius:10px;background:#1C1C28;color:white;border:1px solid #3A3025;">
                <option value="manual" <?php echo $activePayment === 'manual' ? 'selected' : ''; ?>>پرداخت دستی / کارت‌به‌کارت</option>
                <?php foreach ($payment_providers as $id => $meta): ?>
                    <option value="<?php echo htmlspecialchars($id); ?>" <?php echo $activePayment === $id ? 'selected' : ''; ?>><?php echo htmlspecialchars($meta['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="payment-provider-cards">
        <?php foreach ($payment_providers as $id => $meta):
            $prefix = 'payment_gateway_' . $id . '_';
            $enabled = (($provider_settings[$prefix . 'enabled'] ?? '0') === '1');
        ?>
            <section class="payment-provider-card" data-provider="<?php echo htmlspecialchars($id); ?>" style="display:none;border:1px solid #2A2A38;border-radius:14px;padding:1.25rem;background:#1C1C28;margin-bottom:1rem;">
                <h3 style="margin:0 0 1rem;color:white;">تنظیمات <?php echo htmlspecialchars($meta['name']); ?></h3>
                <label style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;cursor:pointer;">
                    <input type="checkbox" name="payment_enabled_<?php echo htmlspecialchars($id); ?>" value="1" <?php echo $enabled ? 'checked' : ''; ?> style="width:19px;height:19px;accent-color:#34D399;">
                    <span>فعال کردن این درگاه</span>
                </label>
                <div class="form-row">
                <?php foreach ($meta['fields'] as $field):
                    $secret = in_array($field, ['api_key','password','secret'], true);
                    $stored = $provider_settings[$prefix . $field] ?? '';
                    $labelMap = [
                        'merchant_id'=>'شناسه پذیرنده','merchant'=>'شناسه پذیرنده','api_key'=>'کلید دسترسی','sandbox'=>'حالت آزمایشی','callback_url'=>'نشانی بازگشت','gateway_url'=>'نشانی درگاه','start_url'=>'نشانی شروع پرداخت','request_url'=>'نشانی درخواست','verify_url'=>'نشانی تأیید','terminal_id'=>'شماره پایانه','username'=>'نام کاربری','password'=>'رمز عبور','merchant_code'=>'کد پذیرنده','certificate_path'=>'مسیر گواهی','wsdl_url'=>'نشانی سرویس','service_url'=>'نشانی سرویس','pin'=>'رمز پذیرنده','http_method'=>'روش درخواست','secret'=>'کلید محرمانه'
                    ];
                ?>
                    <div class="form-group">
                        <label><?php echo $labelMap[$field] ?? $field; ?>:</label>
                        <?php if ($field === 'sandbox'): ?>
                            <select name="payment_<?php echo htmlspecialchars($id . '_' . $field); ?>" style="width:100%;padding:.65rem;border-radius:10px;background:#1C1C28;color:white;border:1px solid #3A3025;">
                                <option value="0" <?php echo $stored === '0' ? 'selected' : ''; ?>>واقعی</option><option value="1" <?php echo $stored === '1' ? 'selected' : ''; ?>>آزمایشی</option>
                            </select>
                        <?php elseif ($field === 'http_method'): ?>
                            <select name="payment_<?php echo htmlspecialchars($id . '_' . $field); ?>" style="width:100%;padding:.65rem;border-radius:10px;background:#1C1C28;color:white;border:1px solid #3A3025;">
                                <option value="POST" <?php echo strtoupper($stored ?: 'POST') === 'POST' ? 'selected' : ''; ?>>ارسال</option><option value="GET" <?php echo strtoupper($stored ?: '') === 'GET' ? 'selected' : ''; ?>>دریافت</option>
                            </select>
                        <?php else: ?>
                            <input type="<?php echo $secret ? 'password' : 'text'; ?>" name="payment_<?php echo htmlspecialchars($id . '_' . $field); ?>" value="<?php echo $secret ? '' : htmlspecialchars($stored); ?>" placeholder="<?php echo $secret && $stored !== '' ? 'مقدار فعلی ذخیره شده است؛ برای تغییر مقدار جدید وارد کنید' : ''; ?>" autocomplete="off" style="width:100%;box-sizing:border-box;padding:.65rem;border-radius:10px;background:#1C1C28;color:white;border:1px solid #3A3025;direction:ltr;text-align:left;">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
                <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-top:.5rem;">
                    <button type="submit" class="btn btn-success" style="min-width:170px;padding:.7rem 1.2rem;">ذخیره تنظیمات</button>
                    <button type="submit" name="payment_gateway_id" value="<?php echo htmlspecialchars($id); ?>" formaction="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/test-payment-connection'); ?>" class="btn" style="min-width:170px;padding:.7rem 1.2rem;background:#5C9FE7;color:#fff;border:0;border-radius:10px;font-weight:800;">آزمون اتصال</button>
                </div>
            </section>
        <?php endforeach; ?>
        </div>

        <div id="payment-manual-card" style="display:none;border:1px solid #2A2A38;border-radius:14px;padding:1.25rem;background:#1C1C28;margin-bottom:1rem;">
            <h3 style="margin-top:0;color:white;">پرداخت دستی / کارت‌به‌کارت</h3>
            <p style="color:#B4B2BE;line-height:1.8;margin-bottom:1rem;">در این روش کاربر رسید پرداخت را ثبت می‌کند و مدیر آن را بررسی و تأیید می‌کند.</p>
            <button type="submit" class="btn btn-success" style="min-width:170px;padding:.7rem 1.2rem;">ذخیره تنظیمات</button>
        </div>
    </form>
</div>

<script>
(function(){
    function showPayment(){
        var select=document.getElementById('payment_gateway_active');
        if(!select)return;
        var value=select.value;
        document.querySelectorAll('.payment-provider-card').forEach(function(card){
            card.style.display=(card.getAttribute('data-provider')===value)?'block':'none';
        });
        var manual=document.getElementById('payment-manual-card');
        if(manual) manual.style.display=(value==='manual')?'block':'none';
    }
    document.addEventListener('DOMContentLoaded',function(){
        var select=document.getElementById('payment_gateway_active');
        if(select) select.addEventListener('change',showPayment);
        showPayment();
    });
})();
</script>
