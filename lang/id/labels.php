<?php

/*
| Cultiv One - Label yang dipilih saat runtime.
| ---------------------------------------------------------------------------
| Lihat catatan di lang/en/labels.php. Nilai di sini diambil dari config atau
| database (channel penjualan, metode pembayaran, nama modul), jadi semuanya
| dikunci di balik berlingkang titik agar tidak pernah tertukar dengan nama file
| bahasa — itulah yang membuat __('POS') pernah mengembalikan array.
*/

return [
    'channel' => [
        'POS'           => 'POS',
        'Online Store'  => 'Toko Online',
        'WhatsApp'      => 'WhatsApp',
        'Marketplace'   => 'Marketplace',
        'Manual'        => 'Manual',
    ],

    'payment' => [
        'Cash'          => 'Tunai',
        'Bank Transfer' => 'Transfer Bank',
        'QRIS'          => 'QRIS',
        'Debit Card'    => 'Kartu Debit',
        'Credit Card'   => 'Kartu Kredit',
        'E-Wallet'      => 'Dompet Digital',
        'Other'         => 'Lainnya',
    ],

    'module' => [
        'POS'                 => 'POS',
        'Point of Sale (POS)' => 'Kasir (POS)',
    ],

    // Sale::STATUSES
    'order' => [
        'draft'      => 'Draf',
        'pending'    => 'Menunggu',
        'processing' => 'Diproses',
        'completed'  => 'Selesai',
        'cancelled'  => 'Dibatalkan',
        'refunded'   => 'Dikembalikan',
    ],
];
