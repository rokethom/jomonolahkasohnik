<?php

namespace App\Services;

class BotService
{
    public function welcome(): string
    {
        return 'Halo, ada yang bisa dibantu? Silakan jelaskan kendala Anda.';
    }

    public function replyFor(?string $message): ?string
    {
        $text = mb_strtolower((string) $message);

        return match (true) {
            str_contains($text, 'cancel'), str_contains($text, 'batal') => 'Baik, pembatalan perlu approval operator. Tuliskan alasan pembatalan dan lampirkan foto jika ada.',
            str_contains($text, 'komplain'), str_contains($text, 'keluhan') => 'Maaf atas kendalanya. Ceritakan kronologinya, operator kami akan bantu cek.',
            str_contains($text, 'harga'), str_contains($text, 'tarif') => 'Harga dihitung otomatis berdasarkan jarak, titik tambahan, service fee, dan extra charge area tertentu.',
            default => null,
        };
    }

    public function shouldAssignHuman(?string $message): bool
    {
        return $this->replyFor($message) === null;
    }
}
