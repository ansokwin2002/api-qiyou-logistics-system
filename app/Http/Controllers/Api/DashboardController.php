<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\CodCollection;
use App\Models\Cost;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Package;
use App\Models\Shipment;
use Carbon\Carbon;

class DashboardController extends Controller
{
    use ApiResponse;

    public function stats()
    {
        $customerCount = Customer::count();
        $orderCount = Order::count();
        $packageCount = Package::count();
        $shipmentCount = Shipment::count();

        $inTransitCount = Package::where('status', Package::STATUS_IN_TRANSIT)->count();
        $outForDeliveryCount = Package::where('status', Package::STATUS_OUT_FOR_DELIVERY)->count();
        $readyForPickupCount = Package::where('status', Package::STATUS_READY_FOR_PICKUP)->count();
        $deliveredCount = Package::where('status', Package::STATUS_DELIVERED)->count();
        $pickedUpCount = Package::where('status', Package::STATUS_PICKED_UP)->count();

        $totalFee = (float) Order::where('status', Order::STATUS_COMPLETED)->sum('estimated_fee');
        $totalCost = (float) Cost::sum('amount');
        $codCollected = (float) CodCollection::sum('collected_amount');
        $codExpected = (float) Order::where('payment_method', 'cod')->sum('estimated_fee');

        $pendingDeliveries = Delivery::where('type', Delivery::TYPE_DELIVERY)
            ->whereIn('status', [Delivery::STATUS_PENDING, Delivery::STATUS_OUT_FOR_DELIVERY])
            ->count();
        $pendingPickups = Delivery::where('type', Delivery::TYPE_PICKUP)
            ->where('status', Delivery::STATUS_PENDING)
            ->count();

        $monthly = Order::where('created_at', '>=', Carbon::now()->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d') as date, COUNT(*) as orders")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $this->ok([
            'counts' => [
                'customers' => $customerCount,
                'orders' => $orderCount,
                'packages' => $packageCount,
                'shipments' => $shipmentCount,
            ],
            'package_status' => [
                'in_transit' => $inTransitCount,
                'out_for_delivery' => $outForDeliveryCount,
                'ready_for_pickup' => $readyForPickupCount,
                'delivered' => $deliveredCount,
                'picked_up' => $pickedUpCount,
            ],
            'financials' => [
                'total_fee' => round($totalFee, 2),
                'total_cost' => round($totalCost, 2),
                'profit' => round($totalFee - $totalCost, 2),
                'cod_expected' => round($codExpected, 2),
                'cod_collected' => round($codCollected, 2),
                'cod_outstanding' => round($codExpected - $codCollected, 2),
            ],
            'pending' => [
                'deliveries' => $pendingDeliveries,
                'pickups' => $pendingPickups,
            ],
            'monthly_orders' => $monthly,
        ]);
    }
}