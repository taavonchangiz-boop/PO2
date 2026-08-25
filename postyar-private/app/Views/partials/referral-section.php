<?php
// $referralCode, $referralLink, $stats, $history, $points, $enabled
// These variables must be set before including this partial.
if (!isset($enabled)) $enabled = true;
$tf = \WHCM\Domain\TextFormat::class;
?>
<div class="card">
    <h2>🎯 سیستم زیرمجموعه‌گیری</h2>
    <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-bottom:1.5rem;">
        با دعوت دوستان خود از لینک زیرمجموعه‌گیری، امتیاز و پاداش کسب کنید!
    </p>

    <?php if (!$enabled): ?>
        <div style="background:rgba(248,113,113,0.15); border:1px solid rgba(248,113,113,0.3); border-radius:12px; padding:1rem; color:#F87171; text-align:center; margin-bottom:1.5rem;">
            ⚠ سیستم زیرمجموعه‌گیری در حال حاضر غیرفعال است.
        </div>
    <?php endif; ?>

    <!-- کد و لینک معرف -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.5rem;">
        <div class="form-group">
            <label style="color:#B4B2BE; font-size:0.85rem;">کد معرف شما:</label>
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <input type="text" id="ref-code" value="<?php echo htmlspecialchars($referralCode); ?>" readonly
                    style="flex:1; background:#1C1C28; color:#AEC4DC; font-weight:900; font-size:1.05rem; border:1px solid #A5B4FC; border-radius:10px; padding:0.65rem; text-align:center; direction:ltr;">
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('ref-code').value).then(function(){ document.getElementById('copy-ref-toast').style.display='block'; setTimeout(function(){ document.getElementById('copy-ref-toast').style.display='none'; }, 2000); })" 
                    style="background:#A5B4FC; color:white; border:none; border-radius:10px; padding:0.65rem 1rem; cursor:pointer; font-weight:700; white-space:nowrap;">📋 کپی</button>
            </div>
        </div>
        <div class="form-group">
            <label style="color:#B4B2BE; font-size:0.85rem;">لینک دعوت:</label>
            <div style="display:flex; align-items:center; gap:0.5rem;">
                <input type="text" id="ref-link" value="<?php echo htmlspecialchars($referralLink); ?>" readonly
                    style="flex:1; background:#1C1C28; color:#34D399; font-size:0.85rem; border:1px solid #82D9A2; border-radius:10px; padding:0.65rem; direction:ltr; overflow:hidden; text-overflow:ellipsis;">
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('ref-link').value).then(function(){ document.getElementById('copy-ref-toast').style.display='block'; setTimeout(function(){ document.getElementById('copy-ref-toast').style.display='none'; }, 2000); })" 
                    style="background:#82D9A2; color:white; border:none; border-radius:10px; padding:0.65rem 1rem; cursor:pointer; font-weight:700; white-space:nowrap;">🔗 کپی</button>
            </div>
        </div>
    </div>

    <div id="copy-ref-toast" style="display:none; position:fixed; bottom:2rem; left:50%; transform:translateX(-50%); background:#34D399; color:white; padding:0.75rem 1.5rem; border-radius:12px; font-weight:700; z-index:9999; box-shadow:0 8px 25px rgba(0,0,0,0.4);">
        با موفقیت کپی شد! 📋
    </div>

    <!-- آمار زیرمجموعه‌ها -->
    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:1rem; margin-bottom:1.5rem;">
        <div style="background:linear-gradient(135deg, rgba(99,102,241,0.2) 0%, rgba(99,102,241,0.05) 100%); border:1px solid rgba(99,102,241,0.3); border-radius:12px; padding:1rem; text-align:center;">
            <div style="font-size:1.8rem; font-weight:900; color:#A5B4FC;"><?php echo $tf::fa_num($stats['total']); ?></div>
            <div style="font-size:0.8rem; color:#B4B2BE; margin-top:0.25rem;">کل زیرمجموعه‌ها</div>
        </div>
        <div style="background:linear-gradient(135deg, rgba(52,211,153,0.2) 0%, rgba(52,211,153,0.05) 100%); border:1px solid rgba(52,211,153,0.3); border-radius:12px; padding:1rem; text-align:center;">
            <div style="font-size:1.8rem; font-weight:900; color:#34D399;"><?php echo $tf::fa_num($stats['rewarded']); ?></div>
            <div style="font-size:0.8rem; color:#B4B2BE; margin-top:0.25rem;">پاداش‌داده‌شده</div>
        </div>
        <div style="background:linear-gradient(135deg, rgba(239,164,91,0.2) 0%, rgba(239,164,91,0.05) 100%); border:1px solid rgba(239,164,91,0.3); border-radius:12px; padding:1rem; text-align:center;">
            <div style="font-size:1.8rem; font-weight:900; color:#F5BC82;"><?php echo $tf::fa_num($stats['pending']); ?></div>
            <div style="font-size:0.8rem; color:#B4B2BE; margin-top:0.25rem;">در انتظار خرید</div>
        </div>
    </div>

    <!-- امتیازات و تبدیل -->
    <div style="background:#1C1C28; border:1px dashed #A5B4FC; border-radius:12px; padding:1rem; margin-bottom:1.5rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem;">
        <div>
            <span style="color:#B4B2BE; font-size:0.85rem;">امتیازات فعلی شما:</span>
            <span style="font-size:1.3rem; font-weight:900; color:#F5BC82; margin-right:0.5rem;"><?php echo $tf::fa_num($points); ?></span>
            <span style="color:#4A4857; font-size:0.8rem;">امتیاز (هر ۱۰ امتیاز = ۱۰ تومان)</span>
        </div>
        <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/dashboard/convert-points'); ?>" method="POST" style="display:flex; align-items:center; gap:0.5rem;">
            <?php echo \WHCM\Core\Csrf::field(); ?>
            <input type="number" name="points" min="1" max="<?php echo (int)$points; ?>" placeholder="تعداد امتیاز" required
                style="width:120px; background:#1C1C28; color:white; border:1px solid #2A2A38; border-radius:8px; padding:0.5rem; text-align:center;">
            <button type="submit" class="btn btn-success" style="padding:0.5rem 1rem; font-size:0.85rem;">💰 تبدیل به کیف پول</button>
        </form>
    </div>

    <!-- تاریخچه زیرمجموعه‌ها -->
    <h3 style="font-size:0.95rem; margin-bottom:0.75rem; border-bottom:1px dashed var(--border); padding-bottom:0.4rem; color:#A5B4FC;">📋 تاریخچه زیرمجموعه‌ها</h3>
    <?php if (empty($history)): ?>
        <p style="color:var(--text-muted); text-align:center; padding:1.5rem 0;">هنوز زیرمجموعه‌ای ثبت‌نام نکرده است. لینک خود را به اشتراک بگذارید! 🚀</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>نام</th>
                        <th>ایمیل</th>
                        <th>وضعیت</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($h['referred_name'] ?? '—'); ?></td>
                        <td style="direction:ltr; text-align:right; font-size:0.85rem;"><?php echo htmlspecialchars($h['referred_email'] ?? '—'); ?></td>
                        <td>
                            <?php if ($h['status'] === 'rewarded'): ?>
                                <span style="background:rgba(52,211,153,0.2); color:#34D399; padding:0.2rem 0.75rem; border-radius:8px; font-size:0.8rem; font-weight:700;">✅ پاداش داده شده</span>
                            <?php else: ?>
                                <span style="background:rgba(239,164,91,0.2); color:#F5BC82; padding:0.2rem 0.75rem; border-radius:8px; font-size:0.8rem; font-weight:700;">⏳ در انتظار خرید</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.85rem;"><?php echo $tf::mysql_to_jalali($h['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
