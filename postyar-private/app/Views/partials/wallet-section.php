<?php
// $balance, $transactions, $points
// These variables must be set before including this partial.
$tf = \WHCM\Domain\TextFormat::class;
?>
<div class="card">
    <h2>💰 کیف پول و تراکنش‌ها</h2>
    <p style="color:var(--text-muted); font-size:0.85rem; line-height:1.7; margin-bottom:1.5rem;">
        موجودی کیف پول و تاریخچه تراکنش‌های مالی شما در این بخش نمایش داده می‌شود.
    </p>

    <!-- موجودی کیف پول -->
    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:1rem; margin-bottom:1.5rem;">
        <div style="background:linear-gradient(135deg, rgba(52,211,153,0.25) 0%, rgba(52,211,153,0.05) 100%); border:1px solid rgba(52,211,153,0.4); border-radius:16px; padding:1.25rem; text-align:center;">
            <div style="font-size:0.8rem; color:#82D9A2; margin-bottom:0.3rem;">💰 موجودی کیف پول</div>
            <div style="font-size:2rem; font-weight:900; color:#34D399;"><?php echo $tf::fa_num($balance); ?></div>
            <div style="font-size:0.75rem; color:#B4B2BE; margin-top:0.15rem;">تومان</div>
        </div>
        <div style="background:linear-gradient(135deg, rgba(239,164,91,0.25) 0%, rgba(239,164,91,0.05) 100%); border:1px solid rgba(239,164,91,0.4); border-radius:16px; padding:1.25rem; text-align:center;">
            <div style="font-size:0.8rem; color:#FFC078; margin-bottom:0.3rem;">⭐ امتیازات</div>
            <div style="font-size:2rem; font-weight:900; color:#F5BC82;"><?php echo $tf::fa_num($points); ?></div>
            <div style="font-size:0.75rem; color:#B4B2BE; margin-top:0.15rem;">امتیاز</div>
        </div>
        <div style="background:linear-gradient(135deg, rgba(99,102,241,0.25) 0%, rgba(99,102,241,0.05) 100%); border:1px solid rgba(99,102,241,0.4); border-radius:16px; padding:1.25rem; text-align:center;">
            <div style="font-size:0.8rem; color:#A5B4FC; margin-bottom:0.3rem;">💳 ارزش امتیازات</div>
            <div style="font-size:2rem; font-weight:900; color:#A5B4FC;"><?php echo $tf::fa_num($points * 10); ?></div>
            <div style="font-size:0.75rem; color:#B4B2BE; margin-top:0.15rem;">تومان</div>
        </div>
    </div>

    <!-- تبدیل امتیاز به کیف پول -->
    <div style="background:#1C1C28; border:1px dashed #34D399; border-radius:12px; padding:1rem; margin-bottom:1.5rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem;">
        <div>
            <span style="color:#B4B2BE; font-size:0.85rem;">تبدیل امتیاز به موجودی کیف پول:</span>
            <span style="color:#4A4857; font-size:0.8rem;"> (هر ۱۰ امتیاز = ۱۰ تومان)</span>
        </div>
        <form action="<?php echo \WHCM\Core\Bootstrap::getRouteUrl('/dashboard/convert-points'); ?>" method="POST" style="display:flex; align-items:center; gap:0.5rem;">
            <?php echo \WHCM\Core\Csrf::field(); ?>
            <input type="number" name="points" min="1" max="<?php echo (int)$points; ?>" placeholder="تعداد امتیاز" required
                style="width:120px; background:#1C1C28; color:white; border:1px solid #2A2A38; border-radius:8px; padding:0.5rem; text-align:center;">
            <button type="submit" class="btn btn-success" style="padding:0.5rem 1rem; font-size:0.85rem;">💰 تبدیل</button>
        </form>
    </div>

    <!-- تاریخچه تراکنش‌ها -->
    <h3 style="font-size:0.95rem; margin-bottom:0.75rem; border-bottom:1px dashed var(--border); padding-bottom:0.4rem; color:#A5B4FC;">📊 تاریخچه تراکنش‌ها</h3>
    <?php if (empty($transactions)): ?>
        <p style="color:var(--text-muted); text-align:center; padding:1.5rem 0;">تراکنشی در کیف پول شما ثبت نشده است.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>نوع</th>
                        <th>مبلغ (تومان)</th>
                        <th>موجودی پس از تراکنش</th>
                        <th>تاریخ</th>
                        <th>توضیحات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td>
                            <?php 
                                $typeLabels = [
                                    'referral_purchase' => '🎯 پاداش زیرمجموعه',
                                    'cashback' => '💳 کش‌بک',
                                    'points_convert' => '⭐ تبدیل امتیاز',
                                    'debit' => '🔴 برداشت',
                                    'credit' => '🟢 واریز',
                                ];
                                echo $typeLabels[$t['type']] ?? $t['type'];
                            ?>
                        </td>
                        <td style="font-weight:700; <?php echo $t['amount'] >= 0 ? 'color:#34D399;' : 'color:#F87171;'; ?>">
                            <?php echo $t['amount'] >= 0 ? '+' : ''; ?><?php echo $tf::fa_num($t['amount']); ?>
                        </td>
                        <td style="color:#A5B4FC;"><?php echo $tf::fa_num($t['balance_after']); ?></td>
                        <td style="font-size:0.85rem;"><?php echo $tf::mysql_to_jalali($t['created_at']); ?></td>
                        <td style="font-size:0.8rem; color:#B4B2BE; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo htmlspecialchars($t['description'] ?? ''); ?>"><?php echo htmlspecialchars(mb_substr($t['description'] ?? '', 0, 40)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
