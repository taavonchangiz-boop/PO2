<?php
namespace WHCM\Api\Controllers;

use WHCM\Api\MobileApiResponse;
use WHCM\Domain\Advertising;

final class AdvertisingApiController extends \WHCM\Api\MobileApiController
{
    /** Public/authorized active ads for PWA and Android. */
    public function index(): void
    {
        MobileApiResponse::success(Advertising::active(8));
    }

    public function impression(string $id): void
    {
        $campaignId=(int)$id;
        $ok=Advertising::recordEvent($campaignId,'impression',$this->userId());
        MobileApiResponse::success(['recorded'=>$ok]);
    }

    public function click(string $id): void
    {
        $campaignId=(int)$id;
        $ad=Advertising::findPublic($campaignId);
        if (!$ad) { MobileApiResponse::notFound('آگهی فعال یافت نشد.'); return; }
        $recorded=Advertising::recordEvent($campaignId,'click',$this->userId());
        MobileApiResponse::success(['recorded'=>$recorded,'destination_url'=>$ad['destination_url']]);
    }

    public function mine(): void
    {
        MobileApiResponse::success(Advertising::ownerCampaigns($this->userId(),100));
    }

    public function stats(string $id): void
    {
        $campaignId=(int)$id;
        $ownerId=$this->userId();
        $rows=Advertising::statsForOwner($ownerId);
        foreach ($rows as $row) {
            if ((int)$row['id']===$campaignId) {
                MobileApiResponse::success(['campaign'=>$row,'daily'=>Advertising::daily($campaignId,$ownerId)]);
                return;
            }
        }
        MobileApiResponse::notFound('آگهی یافت نشد.');
    }

    public function adminIndex(): void
    {
        $this->requireSuperAdmin();
        $from=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['from']??''))?(string)$_GET['from']:null;
        $to=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)($_GET['to']??''))?(string)$_GET['to']:null;
        MobileApiResponse::success(['campaigns'=>Advertising::adminCampaigns(200),'stats'=>Advertising::statsForAdmin($from,$to)]);
    }

    public function adminStatus(string $id): void
    {
        $this->requireSuperAdmin();
        $data=$this->input(); $status=trim((string)($data['status']??''));
        if (!Advertising::setStatus((int)$id,$status,$this->userId())) { MobileApiResponse::error('تغییر وضعیت آگهی انجام نشد.',422); return; }
        MobileApiResponse::success(['id'=>(int)$id,'status'=>$status]);
    }


    /** Advertising sales: available placements for Web/PWA/Android. */
    public function placements(): void
    {
        MobileApiResponse::success(\WHCM\Domain\AdSales::placements(true));
    }

    /** Create an advertising order. Images may be supplied as pre-uploaded safe URLs by mobile clients. */
    public function createOrder(): void
    {
        $data=$this->input();
        try {
            $id=\WHCM\Domain\AdSales::createRequest($this->userId(),[
                'starts_at'=>(string)($data['starts_at']??''),
                'ends_at'=>(string)($data['ends_at']??''),
                'placements'=>(array)($data['placements']??[]),
                'creatives'=>(array)($data['creatives']??[]),
                'user_notes'=>(string)($data['user_notes']??''),
            ]);
            MobileApiResponse::success(['order_id'=>$id,'status'=>'submitted'],'درخواست تبلیغات ثبت شد.',201);
        } catch(\Throwable $e) {
            MobileApiResponse::error('درخواست تبلیغات نامعتبر است.',422);
        }
    }

    public function orders(): void
    {
        MobileApiResponse::success(\WHCM\Domain\AdSales::ownerOrders($this->userId(),100));
    }

    public function submitPayment(): void
    {
        $data=$this->input();
        $ok=\WHCM\Domain\AdSales::submitCardPayment((int)($data['order_id']??0),$this->userId(),trim((string)($data['payment_reference']??'')),trim((string)($data['receipt_photo']??'')));
        if(!$ok){MobileApiResponse::error('پرداخت قابل ثبت نیست؛ ابتدا مبلغ باید توسط مدیر تایید شده باشد.',422);return;}
        MobileApiResponse::success(['status'=>'payment_submitted'],'رسید پرداخت ثبت شد.');
    }

    public function adminOrders(): void
    {
        $this->requireSuperAdmin();
        MobileApiResponse::success(['orders'=>\WHCM\Domain\AdSales::adminOrders(300)]);
    }

    public function adminQuote(): void
    {
        $this->requireSuperAdmin(); $data=$this->input();
        $ok=\WHCM\Domain\AdSales::quote((int)($data['order_id']??0),(float)($data['quoted_amount']??0),$this->userId(),(string)($data['admin_notes']??''));
        if(!$ok){MobileApiResponse::error('قیمت‌گذاری انجام نشد.',422);return;}
        MobileApiResponse::success(['status'=>'awaiting_payment']);
    }

    public function adminApprovePayment(): void
    {
        $this->requireSuperAdmin(); $data=$this->input();
        $ok=\WHCM\Domain\AdSales::approveCardPayment((int)($data['order_id']??0),$this->userId());
        if(!$ok){MobileApiResponse::error('تایید پرداخت انجام نشد یا قبلاً پردازش شده است.',422);return;}
        MobileApiResponse::success(['status'=>'paid','campaign_activated'=>true]);
    }

    public function create(): void
    {
        MobileApiResponse::error('برای ثبت تبلیغ از مسیر سفارش تبلیغات استفاده کنید.',410);
    }
}
