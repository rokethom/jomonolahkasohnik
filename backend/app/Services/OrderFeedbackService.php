<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;

class OrderFeedbackService
{
    public const DRIVER_ACCEPTED_KEY = 'feedback_driver_accepted';
    public const AUTO_CANCELLED_KEY = 'feedback_order_auto_cancelled';
    public const CANCELLED_KEY = 'feedback_order_cancelled';

    public function __construct(private readonly SettingService $settings)
    {
    }

    public function driverAccepted(Order $order): array
    {
        return $this->make(
            'driver_accepted',
            'Order diterima driver',
            $this->settings->get(self::DRIVER_ACCEPTED_KEY, 'Pesanan Anda telah diterima oleh {driver_name}. Driver akan segera menuju lokasi.'),
            'success',
            $order,
        );
    }

    public function statusUpdated(Order $order, OrderStatus $oldStatus, OrderStatus $newStatus): ?array
    {
        if ($newStatus === OrderStatus::Cancelled) {
            $autoCancelled = $this->isAutoCancelled($order);

            return $this->make(
                $autoCancelled ? 'order_auto_cancelled' : 'order_cancelled',
                $autoCancelled ? 'Order dibatalkan otomatis' : 'Order dibatalkan',
                $this->settings->get(
                    $autoCancelled ? self::AUTO_CANCELLED_KEY : self::CANCELLED_KEY,
                    $autoCancelled
                        ? 'Maaf, order Anda di {service} dibatalkan otomatis karena batas waktu mencari driver habis. Silakan buat order ulang atau hubungi CS.'
                        : 'Maaf, order Anda di {service} dibatalkan. Alasan: {reason}',
                ),
                'error',
                $order,
            );
        }

        if ($newStatus === OrderStatus::DriverAccepted) {
            return $this->driverAccepted($order);
        }

        if ($newStatus === OrderStatus::Completed) {
            return $this->make(
                'order_completed',
                'Order selesai',
                'Terima kasih, order {order_code} sudah selesai.',
                'success',
                $order,
            );
        }

        return null;
    }

    public function defaultTemplates(): array
    {
        return [
            'driver_accepted' => $this->settings->get(self::DRIVER_ACCEPTED_KEY, 'Pesanan Anda telah diterima oleh {driver_name}. Driver akan segera menuju lokasi.'),
            'order_auto_cancelled' => $this->settings->get(self::AUTO_CANCELLED_KEY, 'Maaf, order Anda di {service} dibatalkan otomatis karena batas waktu mencari driver habis. Silakan buat order ulang atau hubungi CS.'),
            'order_cancelled' => $this->settings->get(self::CANCELLED_KEY, 'Maaf, order Anda di {service} dibatalkan. Alasan: {reason}'),
        ];
    }

    private function make(string $type, string $title, mixed $template, string $tone, Order $order): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'message' => $this->render((string) $template, $order),
            'tone' => $tone,
        ];
    }

    private function render(string $template, Order $order): string
    {
        $reason = $this->cancelReason($order);

        return strtr($template, [
            '{order_code}' => $order->order_code ?? '#'.$order->id,
            '{service}' => strtoupper((string) $order->service_type),
            '{driver_name}' => $order->driver?->user?->name ?? 'driver',
            '{customer_name}' => $order->user?->name ?? 'Customer',
            '{reason}' => $reason,
        ]);
    }

    private function isAutoCancelled(Order $order): bool
    {
        return str_contains(strtolower((string) $order->notes), 'driver timeout');
    }

    private function cancelReason(Order $order): string
    {
        $notes = trim((string) $order->notes);

        if ($this->isAutoCancelled($order)) {
            return 'Batas waktu mencari driver habis (10 menit).';
        }

        if ($notes === '') {
            return 'Dibatalkan tanpa alasan tersimpan.';
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $notes) ?: [])));

        return $lines !== [] ? end($lines) : 'Dibatalkan tanpa alasan tersimpan.';
    }
}
