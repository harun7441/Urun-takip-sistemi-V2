<?php
// Oturum uzatma işlemi
header('Content-Type: application/json');

// Cookie kontrolü
if (!isset($_COOKIE['logged_in_user']) || $_COOKIE['logged_in_user'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Oturum bulunamadı']);
    exit();
}

if (!isset($_COOKIE['login_time'])) {
    echo json_encode(['success' => false, 'message' => 'Login zamanı bulunamadı']);
    exit();
}

try {
    // Yeni login zamanını ayarla (şimdiki zaman)
    $new_login_time = time();
    
    // Cookie'leri güncelle
    setcookie('login_time', $new_login_time, time() + (24*60*60), '/'); // 24 saat
    setcookie('logged_in_user', 'admin', time() + (24*60*60), '/'); // User cookie'sini de yenile
    
    // Başarı yanıtı
    echo json_encode([
        'success' => true, 
        'message' => 'Oturum 10 dakika uzatıldı',
        'new_login_time' => $new_login_time
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Sunucu hatası: ' . $e->getMessage()]);
}
?>