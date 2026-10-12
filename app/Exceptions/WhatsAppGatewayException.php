<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Kegagalan memanggil gateway WhatsApp. Pesan exception aman ditampilkan ke
 * Tim SAKIP (tidak memuat token/URL). $bisaDiulang = true bila gateway tidak
 * terjangkau/sedang bangun sehingga permintaan yang sama layak dicoba lagi.
 */
class WhatsAppGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $bisaDiulang = false,
        public readonly ?int $statusHttp = null,
        public readonly ?string $kode = null,
    ) {
        parent::__construct($message);
    }
}
