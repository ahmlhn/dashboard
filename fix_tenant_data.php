<?php
// fix_tenant_data.php
// Script untuk memperbaiki data tenant_id = 0 menjadi tenant_id yang benar (1 atau sesuai sesi login)

require_once 'config.php';

// Pastikan hanya admin yang bisa akses (atau yang tahu logic ini)
// Jika dijalankan lewat browser dan sudah login sebagai admin, $_SESSION aman.
// Jika belum login, kita set default ke 1.

$target_tenant = 1;
if (isset($TENANT_ID) && $TENANT_ID > 0) {
    $target_tenant = $TENANT_ID;
}

echo "<h1>Perbaikan Data Tenant</h1>";
echo "Target Tenant ID: <strong>$target_tenant</strong> (Default: 1)<br><br>";

if (isset($_GET['run']) && $_GET['run'] == 'true') {
    // 1. Update Installations
    $q1 = mysqli_query($conn, "UPDATE noci_installations SET tenant_id = $target_tenant WHERE tenant_id = 0");
    $af1 = mysqli_affected_rows($conn);
    echo "• Updated Installations: <strong>$af1</strong> baris.<br>";

    // 2. Update POPs (opsional, tapi penting agar match)
    $q2 = mysqli_query($conn, "UPDATE noci_pops SET tenant_id = $target_tenant WHERE tenant_id = 0");
    $af2 = mysqli_affected_rows($conn);
    echo "• Updated POPs: <strong>$af2</strong> baris.<br>";

    // 3. Update Users (Hati-hati, tapi jika 0 biasanya salah import/insert lama)
    $q3 = mysqli_query($conn, "UPDATE noci_users SET tenant_id = $target_tenant WHERE tenant_id = 0");
    $af3 = mysqli_affected_rows($conn);
    echo "• Updated Users: <strong>$af3</strong> baris.<br>";

    echo "<br><h3 style='color:green'>Selesai! Silakan hapus file ini setelah selesai.</h3>";
    echo "<a href='dashboard.php'>Kembali ke Dashboard</a>";
} else {
    echo "Script ini akan mengubah semua data dengan tenant_id=0 menjadi tenant_id=$target_tenant.<br>";
    echo "Pastikan Anda yakin melakukan ini.<br><br>";
    echo "<a href='?run=true' style='padding:10px 20px; background:blue; color:white; text-decoration:none; border-radius:5px;'>JALANKAN PERBAIKAN SEKARANG</a>";
}
?>
