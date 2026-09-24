# DASHBOARD SEBAGAI PUSAT APLIKASI SaaS

## KONTEKS

Aplikasi ini adalah SaaS manajemen bisnis yang sudah memiliki infrastruktur SaaS sebelumnya.

Aplikasi memiliki konsep multi-tenant, sehingga setiap pengguna bekerja di dalam tenant/bisnis yang sesuai dengan hak aksesnya.

`/dashboard` harus menjadi **pusat aplikasi setelah pengguna berhasil login**, tetapi bukan berarti seluruh fitur harus ditampilkan dalam satu halaman dashboard.

Konsep yang harus digunakan adalah:

> `/dashboard` = halaman utama, ringkasan bisnis, dan application shell/navigation.

Sedangkan fitur detail memiliki route masing-masing:

```text
/dashboard

/products
/categories
/brands
/stock
/sales
/purchases
/customers
/suppliers
/reports
/team
/billing
/settings
```

Semua halaman tersebut harus terasa sebagai **SATU APLIKASI SaaS YANG TERINTEGRASI**, bukan sebagai halaman-halaman terpisah.

---

# 1. JANGAN MEMBUAT DASHBOARD SEBAGAI SATU-SATUNYA HALAMAN FITUR

Jangan memasukkan seluruh fungsi aplikasi ke dalam:

```text
/dashboard
```

Dashboard hanya berfungsi sebagai:

* Ringkasan bisnis
* Statistik
* Informasi penting
* Notifikasi
* Alert
* Aktivitas terbaru
* Shortcut menuju fitur
* Status subscription
* Navigasi aplikasi

Contoh isi dashboard:

```text
┌────────────────────────────────────────────────────┐
│                  DASHBOARD                         │
├──────────────┬─────────────────────────────────────┤
│              │                                     │
│ Total Sales  │  Rp 25.000.000                      │
│              │                                     │
│ Transactions │  127                                │
│              │                                     │
│ Profit       │  Rp 8.500.000                       │
│              │                                     │
│ Low Stock    │  7 produk                           │
│              │                                     │
│ Recent Sales / Activities                          │
│                                                    │
└──────────────┴─────────────────────────────────────┘
```

Pengguna kemudian dapat masuk ke fitur detail melalui navigasi.

---

# 2. DASHBOARD ADALAH APPLICATION SHELL

Gunakan konsep:

```text
                    LOGIN
                      │
                      ▼
                  /dashboard
                      │
                      ▼
              APPLICATION SHELL
                      │
       ┌──────────────┼──────────────┐
       │              │              │
    Sidebar         Header        User Menu
       │
       ▼
  PAGE CONTENT
       │
       ├── Products
       ├── Stock
       ├── Sales
       ├── Purchases
       ├── Customers
       ├── Suppliers
       ├── Reports
       ├── Team
       ├── Billing
       └── Settings
```

Artinya:

`/dashboard` adalah pintu masuk dan shell utama aplikasi.

Ketika pengguna membuka:

```text
/products
```

halaman tersebut tetap menggunakan:

* sidebar yang sama
* header yang sama
* user menu yang sama
* branding bisnis yang sama
* layout yang sama
* sistem notifikasi yang sama

Hanya area `PAGE CONTENT` yang berubah menjadi halaman Products.

---

# 3. SEMUA FITUR HARUS DAPAT DIAKSES DARI NAVIGASI DASHBOARD

Sidebar/dashboard navigation harus menyediakan akses ke seluruh fitur yang diizinkan untuk pengguna.

Minimal:

```text
Dashboard
Products
Categories
Brands
Stock
Sales
Purchases
Customers
Suppliers
Reports
Team
Billing
Settings
```

Jangan membuat fitur yang hanya bisa diakses dengan mengetik URL secara manual.

Jika fitur tersedia untuk pengguna, harus ada jalur navigasi yang jelas menuju fitur tersebut.

---

# 4. STRUKTUR NAVIGASI

Gunakan struktur navigasi yang rapi.

Contoh:

```text
Dashboard

Penjualan
 ├── Penjualan
 └── Invoice

Inventory
 ├── Produk
 ├── Kategori
 ├── Brand
 ├── Stok
 └── Riwayat Stok

Pembelian
 ├── Pembelian
 └── Supplier

Pelanggan

Laporan
 ├── Penjualan
 ├── Inventory
 ├── Profit
 └── Pelanggan

Team

Billing

Settings
```

Struktur tersebut boleh disesuaikan dengan fitur yang benar-benar tersedia di aplikasi.

Jangan membuat menu untuk fitur yang belum tersedia.

---

# 5. GUNAKAN NAMED ROUTE

Untuk link navigasi, gunakan named route Laravel jika route tersebut tersedia.

Contoh:

```blade
<a href="{{ route('dashboard') }}">
    Dashboard
</a>

<a href="{{ route('products.index') }}">
    Products
</a>

<a href="{{ route('sales.index') }}">
    Sales
</a>

<a href="{{ route('customers.index') }}">
    Customers
</a>
```

Hindari hard-code URL jika named route sudah tersedia.

Jangan membuat named route baru jika route yang sesuai sudah ada.

---

# 6. NAVIGASI HARUS MENGIKUTI ROLE DAN PERMISSION

Tidak semua pengguna harus melihat semua menu.

Contoh:

### Owner

```text
Dashboard
Products
Stock
Sales
Purchases
Customers
Suppliers
Reports
Team
Billing
Settings
```

### Kasir

```text
Dashboard
Sales
Customers
```

### Gudang

```text
Dashboard
Products
Stock
Purchases
```

### Viewer

```text
Dashboard
Reports
```

Ini hanya contoh.

Gunakan role dan permission yang **sudah ada dalam aplikasi**.

Jangan membuat sistem RBAC kedua.

---

# 7. PENTING — MENYEMBUNYIKAN MENU BUKAN SECURITY

Jika sebuah menu tidak ditampilkan kepada user karena tidak memiliki permission, itu hanya masalah UI.

Backend tetap harus melakukan authorization.

Contoh:

User tidak melihat:

```text
Products → Delete
```

tetapi jika user mencoba:

```text
DELETE /products/10
```

secara manual, backend tetap harus menolak request tersebut.

Gunakan:

* Policy
* Gate
* Permission
* Authorization middleware

sesuai sistem yang sudah ada.

---

# 8. ACTIVE MENU

Sidebar harus mengetahui halaman yang sedang dibuka.

Contoh:

Jika user membuka:

```text
/products
```

maka:

```text
Products
```

harus ditandai sebagai menu aktif.

Jika user membuka:

```text
/products/10/edit
```

Products tetap aktif.

Jika user membuka:

```text
/sales/10
```

Sales tetap aktif.

Jangan membuat indikator aktif hanya berdasarkan URL yang sama persis.

Gunakan pendekatan Laravel seperti:

```blade
request()->routeIs('products.*')
```

jika sesuai dengan struktur named route yang digunakan.

---

# 9. SHARED LAYOUT

Semua halaman tenant harus menggunakan shared layout yang sama.

Contoh struktur Blade:

```text
resources/views/
    layouts/
        app.blade.php

    dashboard/
        index.blade.php

    products/
        index.blade.php
        create.blade.php
        edit.blade.php
        show.blade.php

    sales/
        index.blade.php
        create.blade.php
        show.blade.php

    customers/
        index.blade.php
        create.blade.php
        edit.blade.php
```

Gunakan layout yang sudah tersedia dalam proyek jika memang sudah ada.

Jangan membuat layout kedua tanpa alasan.

---

# 10. CONTOH STRUKTUR HALAMAN

Ketika user membuka:

```text
/products
```

tampilannya harus seperti:

```text
┌───────────────────────────────────────────────────────┐
│ Logo / Nama Bisnis        Notification    User        │
├────────────────┬──────────────────────────────────────┤
│                │                                      │
│ Dashboard      │ Produk                               │
│ Products   ←   │                                      │
│ Stock          │ [Tambah Produk]                      │
│ Sales          │                                      │
│ Purchases      │ Search...                            │
│ Customers      │                                      │
│ Suppliers      │ ┌──────────────────────────────────┐ │
│ Reports        │ │ Produk  SKU  Stok  Harga  ...   │ │
│                │ └──────────────────────────────────┘ │
│ Team           │                                      │
│ Billing        │                                      │
│ Settings       │                                      │
└────────────────┴──────────────────────────────────────┘
```

Ketika user berpindah ke:

```text
/sales
```

sidebar dan header tetap sama.

Yang berubah hanya content area:

```text
┌───────────────────────────────────────────────────────┐
│ Logo / Nama Bisnis        Notification    User        │
├────────────────┬──────────────────────────────────────┤
│                │                                      │
│ Dashboard      │ Penjualan                            │
│ Products       │                                      │
│ Stock          │ [Transaksi Baru]                     │
│ Sales      ←   │                                      │
│ Purchases      │ Daftar transaksi                     │
│ Customers      │                                      │
│ Suppliers      │                                      │
│ Reports        │                                      │
│                │                                      │
│ Team           │                                      │
│ Billing        │                                      │
│ Settings       │                                      │
└────────────────┴──────────────────────────────────────┘
```

---

# 11. MODUL BISNIS TETAP MEMILIKI ROUTE SENDIRI

Jangan membuat semua fitur menggunakan:

```text
/dashboard?module=products
```

atau:

```text
/dashboard?module=sales
```

jika aplikasi sudah menggunakan routing Laravel normal.

Gunakan route terpisah:

```text
/dashboard

/products
/products/create
/products/{product}/edit

/categories
/brands

/stock
/stock/movements

/sales
/sales/create
/sales/{sale}

purchases
/purchases/create
/purchases/{purchase}

customers
/customers/{customer}

suppliers
/suppliers/{supplier}

reports
/reports/sales
/reports/inventory
/reports/profit

/team
/billing
/settings
```

Sesuaikan dengan route/controller aktual yang tersedia.

---

# 12. SEMUA ROUTE TENANT TETAP MENGGUNAKAN TENANT MIDDLEWARE

Semua fitur bisnis tenant harus berada dalam tenant context.

Contoh:

```php
Route::middleware(['auth', 'verified'])->group(function () {

    Route::middleware('tenant')->group(function () {

        Route::get('/dashboard', ...);

        Route::resource('products', ProductsController::class);

        Route::resource('categories', CategoriesController::class);

        Route::resource('brands', BrandsController::class);

        Route::resource('customers', CustomersController::class);

        Route::resource('suppliers', SuppliersController::class);

        Route::resource('sales', SalesController::class);

        Route::resource('purchases', PurchasesController::class);

        // Stock routes sesuai controller aktual
    });
});
```

Jangan meng-copy contoh tersebut secara membabi buta.

Periksa controller aktual terlebih dahulu.

---

# 13. TENANT ISOLATION

Ketika user membuka:

```text
/products
```

produk yang ditampilkan hanya produk milik tenant aktif.

Contoh:

```text
Tenant A
 ├── Product A
 └── Product B

Tenant B
 ├── Product C
 └── Product D
```

Tenant A tidak boleh melihat Product C atau Product D.

Hal yang sama berlaku untuk:

* sales
* purchases
* customers
* suppliers
* stock
* reports
* invoices

---

# 14. DASHBOARD HARUS MENAMPILKAN DATA TENANT AKTIF

Dashboard tidak boleh menampilkan data global seluruh SaaS.

Misalnya:

```text
Total Sales
```

harus berarti:

```text
Total Sales Tenant Aktif
```

bukan:

```text
Total Sales Semua Tenant
```

Begitu juga:

* total produk
* total pelanggan
* total stok
* profit
* transaksi
* laporan

semuanya harus menggunakan tenant context aktif.

---

# 15. BILLING DAN TEAM

`Team` dan `Billing` tetap menjadi bagian dari application shell.

Namun permission tetap berlaku.

Contoh:

Owner dapat melihat:

```text
Team
Billing
```

sedangkan Kasir mungkin tidak.

Jangan menghapus route yang sudah bekerja.

Integrasikan menu dengan sistem existing.

---

# 16. RESPONSIVE DESIGN

Dashboard shell harus responsive.

Desktop:

```text
Sidebar tetap terlihat
```

Mobile:

```text
Sidebar → drawer / mobile menu
```

Semua fitur harus tetap dapat diakses dari perangkat mobile.

Jangan membuat fitur tertentu hanya dapat diakses dari desktop kecuali memang ada alasan teknis.

---

# 17. JANGAN MERUSAK UI EXISTING

Sebelum membuat layout baru:

1. Periksa layout yang sudah ada.
2. Periksa komponen navigation yang sudah ada.
3. Periksa sidebar yang sudah ada.
4. Periksa header yang sudah ada.
5. Periksa sistem responsive yang sudah ada.
6. Gunakan kembali komponen yang sudah tersedia.

Jangan mengganti desain aplikasi secara keseluruhan hanya untuk menambahkan menu.

---

# 18. ROUTE `/PRODUCTS` HARUS BENAR-BENAR DAPAT DIAKSES

Pastikan route berikut benar-benar terdaftar:

```text
GET /products
```

dan mengarah ke controller yang benar.

Verifikasi dengan:

```bash
php artisan route:list
```

Pastikan route:

```text
products.index
```

tersedia.

Jika menggunakan `Route::resource()`, pastikan `ProductsController` benar-benar memiliki method:

```text
index
create
store
show
edit
update
destroy
```

atau sesuaikan route dengan method yang sebenarnya.

---

# 19. JANGAN MENGANGGAP 404 SEBAGAI MASALAH VIEW

Jika `/products` menghasilkan 404:

Periksa secara berurutan:

```text
1. Apakah route terdaftar?
2. Apakah route cache stale?
3. Apakah request mencapai Laravel?
4. Apakah middleware tenant bekerja?
5. Apakah route model binding menyebabkan 404?
6. Apakah controller ada?
7. Apakah view ada?
8. Apakah deployment menggunakan kode terbaru?
```

Jangan langsung mengubah Vercel, middleware, atau database tanpa mengetahui penyebab sebenarnya.

---

# 20. HASIL AKHIR YANG DIINGINKAN

Aplikasi harus terasa seperti:

```text
                    SaaS APPLICATION
                           │
                           ▼
                       DASHBOARD
                           │
              ┌────────────┴────────────┐
              │                         │
          NAVIGATION                CONTENT
              │                         │
              ▼                         ▼
       ┌─────────────┐          ┌────────────────┐
       │ Dashboard   │          │ Current Page   │
       │ Products    │          │                │
       │ Stock       │          │ Products       │
       │ Sales       │          │ Sales          │
       │ Purchases   │          │ Stock          │
       │ Customers   │          │ Reports        │
       │ Suppliers   │          │ etc.           │
       │ Reports     │          │                │
       │ Team        │          │                │
       │ Billing     │          │                │
       │ Settings    │          │                │
       └─────────────┘          └────────────────┘
```

Semua bagian tersebut harus:

* menggunakan autentikasi yang sama
* menggunakan tenant yang sama
* menggunakan user context yang sama
* menggunakan permission yang sama
* menggunakan layout yang sama
* menggunakan navigasi yang sama
* menggunakan branding bisnis yang sama

---

# 21. ACCEPTANCE CRITERIA

Sebelum menyatakan implementasi selesai, pastikan:

### Dashboard

```text
/dashboard
```

dapat dibuka oleh user yang memenuhi authentication/tenant requirements.

### Products

Dari dashboard user dapat membuka:

```text
Products → /products
```

### Stock

Dari dashboard user dapat membuka:

```text
Stock → /stock
```

### Sales

Dari dashboard user dapat membuka:

```text
Sales → /sales
```

### Purchases

Dari dashboard user dapat membuka:

```text
Purchases → /purchases
```

### Customers

Dari dashboard user dapat membuka:

```text
Customers → /customers
```

### Suppliers

Dari dashboard user dapat membuka:

```text
Suppliers → /suppliers
```

### Reports

Dari dashboard user dapat membuka:

```text
Reports → /reports
```

### Team

Dari dashboard user dapat membuka:

```text
Team → /team
```

### Billing

Dari dashboard user dapat membuka:

```text
Billing → /billing
```

Semua halaman tersebut harus menggunakan application shell/layout yang sama.

---

# 22. HASIL YANG TIDAK BOLEH TERJADI

Jangan menghasilkan arsitektur seperti:

```text
/dashboard

/products
   ↓
layout berbeda

/sales
   ↓
layout berbeda

/stock
   ↓
layout berbeda
```

Jangan pula membuat:

```text
/dashboard
   ↓
semua fitur dijejalkan ke satu halaman
```

Yang diinginkan adalah:

```text
                 /dashboard
                      │
             SHARED APPLICATION
                  LAYOUT
                      │
        ┌─────────────┼─────────────┐
        ▼             ▼             ▼
    /products      /sales        /stock
        │             │             │
        └─────────────┼─────────────┘
                      │
              SAME DASHBOARD SHELL
```

---

# 23. ATURAN UTAMA

Pahami konsep berikut sebelum melakukan perubahan:

> Dashboard bukan berarti semua fitur berada di satu halaman.

> Dashboard adalah pusat aplikasi dan application shell.

> Setiap fitur mempunyai halaman/route sendiri.

> Semua halaman fitur tetap menggunakan shared dashboard layout.

> Navigasi dashboard harus menyediakan akses ke semua fitur yang diizinkan oleh role/permission pengguna.

> Tenant isolation dan authorization harus tetap berlaku pada setiap route.

> Jangan membuat sistem baru jika infrastruktur yang dibutuhkan sudah tersedia.

Implementasikan konsep ini ke dalam aplikasi existing tanpa melakukan rewrite yang tidak diperlukan.
