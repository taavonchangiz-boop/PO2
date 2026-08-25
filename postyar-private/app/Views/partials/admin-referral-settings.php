<?php
// $settings
// These variables must be set before including this partial.
$tf = \WHCM\Domain\TextFormat::class;
$enabled = ($settings['enabled'] ?? '0') === '1';
?>
<div class="card">
    <h2>🎯 تنظیمات سیستم زیرمجموعه‌گیری و کیف پول</h2>
    <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-bottom:1.5rem;">
        پیکربندی پاداش‌ها، سقف زیرمجموعه‌ها و نرخ‌های سیستم معرفی دوستان پُست‌یار.
    </p>

    <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/hnnh/save-referral-settings'); ?>" method="POST">
        <?php echo \WHCM\Core\Csrf::field(); ?>

        <!-- فعال/غیرفعال -->
        <div class="form-group" style="margin-bottom:1.5rem;">
            <label style="display:flex; align-items:center; gap:0.75rem; cursor:pointer;">
                <input type="checkbox" name="enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?> 
                    style="width:20px; height:20px; accent-color:#34D399;">
                <span style="color:white; font-weight:700; font-size:0.95rem;">فعال بودن سیستم زیرمجموعه‌گیری</span>
            </label>
        </div>

        <!-- پاداش ثبت‌نام -->
        <h3 style="font-size:0.95rem; margin-top:1.5rem; margin-bottom:0.75rem; border-bottom:1px dashed var(--border); padding-bottom:0.4rem; color:#A5B4FC;">🎁 پاداش ثبت‌نام (برای معرف)</h3>
        <div class="form-row" style="margin-bottom:1.5rem;">
            <div class="form-group">
                <label for="reg-reward-type">نوع پاداش:</label>
                <select name="register_reward_type" id="reg-reward-type" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                    <option value="points" <?php echo ($settings['register_reward_type'] ?? '') === 'points' ? 'selected' : ''; ?>>امتیاز</option>
                    <option value="days" <?php echo ($settings['register_reward_type'] ?? '') === 'days' ? 'selected' : ''; ?>>تمدید اشتراک (روز)</option>
                    <option value="percent" <?php echo ($settings['register_reward_type'] ?? '') === 'percent' ? 'selected' : ''; ?>>درصد (فقط خرید اول)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="reg-reward-value">مقدار پاداش:</label>
                <input type="number" name="register_reward_value" id="reg-reward-value" 
                    value="<?php echo htmlspecialchars($settings['register_reward_value'] ?? '100'); ?>" min="0" step="any"
                    style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
            </div>
        </div>

        <!-- پاداش خرید اول -->
        <h3 style="font-size:0.95rem; margin-top:1.5rem; margin-bottom:0.75rem; border-bottom:1px dashed var(--border); padding-bottom:0.4rem; color:#A5B4FC;">💳 پاداش خرید اول زیرمجموعه (برای معرف)</h3>
        <div class="form-row" style="margin-bottom:1.5rem;">
            <div class="form-group">
                <label for="fp-reward-type">نوع پاداش:</label>
                <select name="first_purchase_reward_type" id="fp-reward-type" style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
                    <option value="percent" <?php echo ($settings['first_purchase_reward_type'] ?? '') === 'percent' ? 'selected' : ''; ?>>درصد از مبلغ خرید</option>
                    <option value="points" <?php echo ($settings['first_purchase_reward_type'] ?? '') === 'points' ? 'selected' : ''; ?>>امتیاز ثابت</option>
                </select>
            </div>
            <div class="form-group">
                <label for="fp-reward-value">مقدار پاداش:</label>
                <input type="number" name="first_purchase_reward_value" id="fp-reward-value" 
                    value="<?php echo htmlspecialchars($settings['first_purchase_reward_value'] ?? '10'); ?>" min="0" step="any"
                    style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
            </div>
        </div>

        <!-- محدودیت‌ها -->
        <h3 style="font-size:0.95rem; margin-top:1.5rem; margin-bottom:0.75rem; border-bottom:1px dashed var(--border); padding-bottom:0.4rem; color:#A5B4FC;">⚙ محدودیت‌ها و سقف‌ها</h3>
        <div class="form-row" style="margin-bottom:1.5rem;">
            <div class="form-group">
                <label for="max-ref">حداکثر زیرمجموعه برای هر کاربر:</label>
                <input type="number" name="max_referrals_per_user" id="max-ref" 
                    value="<?php echo htmlspecialchars($settings['max_referrals_per_user'] ?? '100'); ?>" min="1"
                    style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
            </div>
            <div class="form-group">
                <label for="monthly-cap">سقف پاداش ماهانه (تومان):</label>
                <input type="number" name="monthly_reward_cap" id="monthly-cap" 
                    value="<?php echo htmlspecialchars($settings['monthly_reward_cap'] ?? '500000'); ?>" min="0"
                    style="width:100%; padding:0.6rem; border-radius:10px; background:#1C1C28; color:white; border:1px solid #2A2A38;">
            </div>
        </div>

        <button type="submit" class="btn btn-success" style="width:100%;">ذخیره تنظیمات زیرمجموعه‌گیری 🎯✔</button>
    </form>
</div>
