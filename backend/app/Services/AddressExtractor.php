<?php

namespace App\Services;

use App\Models\User;

class AddressExtractor
{
    public function storeLocation(string $text): ?string
    {
        $normalized = $this->normalize($text);

        if (preg_match('/\b(?:alamat\s+pembelian|lokasi\s+pembelian|beli(?:nya)?\s+di|belikan\s+di|pesan\s+di|dari)\s+(.+?)(?=\s+\b(?:antar|kirim|tujuan|alamat\s+antar|ke)\b|$)/u', $normalized, $match) === 1) {
            return $this->clean($match[1]);
        }

        if (preg_match('/\b(?:beli|belikan|pesan)\s+.+?\s+((?:pak|bu|toko|warung|resto|rumah\s+makan)\s+.+?)(?=\s+\b(?:antar|kirim|tujuan|alamat\s+antar|ke\s+alamat)\b|$)/u', $normalized, $match) === 1) {
            return $this->clean($match[1]);
        }

        if (preg_match('/\b(?:beli|belikan|pesan)\s+.+?\s+(?:di|dari|ke|pak|bu|toko|warung|resto|rumah\s+makan)\s+(.+?)(?=\s+(?:dan\s+)?\b(?:antar|kirim|tujuan|alamat\s+antar|ke\s+alamat)\b|$)/u', $normalized, $match) === 1) {
            return $this->clean($match[1]);
        }

        if (preg_match('/\b(?:di|dari)\s+(.+?)(?=\s+(?:dan\s+)?(?:kirim|antar)\s+ke\b|$)/u', $normalized, $match) === 1) {
            return $this->clean($match[1]);
        }

        return null;
    }

    public function destination(string $text, User $user): ?string
    {
        $normalized = $this->normalize($text);

        if (preg_match('/\b(?:kirim|antar)\s+ke\s+alamat\s+saya\b/u', $normalized) === 1) {
            return $this->profileAddress($user);
        }

        if (preg_match('/\b(?:alamat\s+antar|alamat\s+tujuan|tujuan|antar\s+ke|kirim\s+ke|ke)\s+(.+)$/u', $normalized, $match) === 1) {
            $destination = $this->clean($match[1]);
            if (in_array($destination, ['alamat saya', 'rumah saya', 'rumah', 'profile'], true)) {
                return $this->profileAddress($user);
            }

            return $destination;
        }

        if (preg_match('/\b(?:dari|jemput\s+di|jemput)\s+.+?\s+ke\s+(.+)$/u', $normalized, $match) === 1) {
            return $this->clean($match[1]);
        }

        if (preg_match('/\b(?:kirim|antar)\s+ke\s+(.+)$/u', $normalized, $match) === 1) {
            return $this->clean($match[1]);
        }

        return null;
    }

    public function profileAddress(User $user): string
    {
        return (string) ($user->address ?: 'Alamat profile belum diisi');
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        return preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    }

    private function clean(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        $value = preg_replace('/^(?:alamat\s+saya|alamat)\s*/u', '', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B.,");
    }
}
