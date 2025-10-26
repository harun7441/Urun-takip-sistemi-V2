<?php
// Oturum süreli giriş sistemi
$timeout_minutes = 15; // İstediğiniz süreyi buradan değiştirin (dakika)

// Cookie kontrolü: herhangi bir kullanıcı adı ile giriş yapılmışsa kabul et
if (!isset($_COOKIE['logged_in_user'])) {
    header('Location: login.php');
    exit();
}

// Süre kontrolü
if (!isset($_COOKIE['login_time'])) {
    // Login zamanı yoksa logout yap
    setcookie('logged_in_user', '', time() - 3600, '/');
    setcookie('login_time', '', time() - 3600, '/');
    header('Location: login.php?timeout=1');
    exit();
}

$login_time = (int)$_COOKIE['login_time'];
$current_time = time();
$elapsed_minutes = ($current_time - $login_time) / 60;

// Süre dolmuşsa logout yap
if ($elapsed_minutes > $timeout_minutes) {
    setcookie('logged_in_user', '', time() - 3600, '/');
    setcookie('login_time', '', time() - 3600, '/');
    header('Location: login.php?timeout=1');
    exit();
}

// Kalan süreyi hesapla
$remaining_minutes = $timeout_minutes - $elapsed_minutes;
$remaining_seconds = ($remaining_minutes - floor($remaining_minutes)) * 60;

// JavaScript için sabit süre
$remaining_time = 900;

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;


// Veritabanı bağlantısı
$host = 'localhost';
$dbname = 'aslanbob_motortakip';
$username = 'root';
$password = '';



try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Bağlantı hatası: " . $e->getMessage());
}
// ********************** VERİ SİLME İŞLEMİ İÇİN **********************
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sil']) && $_POST['sil'] == '1') {
        $sil_id = $_POST['id'] ?? $id;
        $silQuery = "DELETE FROM el_aletleri WHERE id = :id";
        $silStmt = $conn->prepare($silQuery);
        $silStmt->bindParam(':id', $sil_id);
        if ($silStmt->execute()) {
            // Silme sonrası mevcut GET filtrelerini koruyarak yönlendir
            $returnParams = $_GET;
            // Eğer POST sırasında filtreler formdan geliyorsa ve $_GET boşsa, örneğin REQUEST_URI kullanılabilir
            if (isset($returnParams['id'])) unset($returnParams['id']);
            $qs = http_build_query($returnParams);
            $redirect = $_SERVER['PHP_SELF'] . ($qs ? ('?' . $qs) : '');
            header("Location: " . $redirect);
            exit();
        } else {
            $errorInfo = $silStmt->errorInfo();
            echo "<div style='color:red'>Silme hatası: " . htmlspecialchars($errorInfo[2]) . "</div>";
        }
    }
// ********************** EXCEL ÇIKTISI İÇİN KODLAR **********************

if (isset($_POST['excel_export_xlsx'])) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Başlıklar
    $sheet->fromArray([
        ['ID', 'Geliş Tarihi', 'Kategori', 'Firma', 'Motor Açıklama', 'Açıklama', 'Tamir Durumu', 'Expertiz Tarihi', 'Teklif Tarihi', 'Onay Tarihi', 'Hazır Olma Tarihi', 'Fatura Tarihi', 'Teslim Tarihi', 'Gecikme Açıklaması']
    ], null, 'A1');

    // Filtreleri al
    $firma_filter = isset($_GET['firma']) ? $_GET['firma'] : '';
    $motor_tanimi_filter = isset($_GET['motor_tanimi']) ? $_GET['motor_tanimi'] : '';
    $kategori_filter = isset($_GET['Kategori']) ? $_GET['Kategori'] : '';
    $aciklama_filter = isset($_GET['aciklama']) ? $_GET['aciklama'] : '';

    // Sorgu ve parametreler
    $query = "SELECT * FROM el_aletleri WHERE 1";
    $params = [];

    if (!empty($firma_filter)) {
        $query .= " AND firma LIKE :firma";
        $params[':firma'] = "%$firma_filter%";
    }
    if (!empty($motor_tanimi_filter)) {
    $query .= " AND (
        motor_tanimi LIKE :arama
        OR firma LIKE :arama
        OR Kategori LIKE :arama
        OR aciklama_detay LIKE :arama
        OR aciklama LIKE :arama
        OR tamir_durumu LIKE :arama
        OR gecikme_aciklamasi LIKE :arama
    )";
    $params[':arama'] = "%$motor_tanimi_filter%";
}
    if (!empty($kategori_filter)) {
        $query .= " AND Kategori = :Kategori";
        $params[':Kategori'] = $kategori_filter;
    }
    if (!empty($aciklama_filter)) {
        $query .= " AND aciklama = :aciklama";
        $params[':aciklama'] = $aciklama_filter;
    }

    $query .= " ORDER BY id ASC";

    // Verileri çek
    $exportStmt = $conn->prepare($query);
    $exportStmt->execute($params);
    $rows = [];
    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            $row['id'],
            $row['gelis_tarihi'],
            $row['Kategori'],
            $row['firma'],
            $row['motor_tanimi'],
            $row['aciklama'],
            $row['tamir_durumu'],
            $row['expertiz_tarihi'],
            $row['teklif_tarihi'],
            $row['onay_tarihi'],
            $row['hazir_olma_tarihi'],
            $row['fatura_tarihi'],
            $row['teslim_tarihi'],
            $row['gecikme_aciklamasi']
        ];
    }
    $sheet->fromArray($rows, null, 'A2');

    // Dosyayı indir
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="el_aletleri_listesi.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}


// Firma ekleme işlemi 
if (isset($_POST['yeni_firma_ekle']) && !empty($_POST['yeni_firma'])) {
    $yeniFirma = trim($_POST['yeni_firma']);
    $firmaEkleQuery = "INSERT INTO firmalar (firma_adi) VALUES (:firma_adi)";
    $firmaEkleStmt = $conn->prepare($firmaEkleQuery);
    $firmaEkleStmt->execute([':firma_adi' => $yeniFirma]);
    $queryString = $_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: " . $_SERVER['PHP_SELF'] . $queryString);
exit();
}

// Silme işlemi
if (isset($_GET['sil_id'])) {
    $sil_id = $_GET['sil_id'];
    $silQuery = "DELETE FROM el_aletleri WHERE id = :id";
    $silStmt = $conn->prepare($silQuery);
    $silStmt->bindParam(':id', $sil_id);

    if ($silStmt->execute()) {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Düzenleme işlemi
if (isset($_POST['id'])) {
    // Otomatik açıklama ata
    $_POST['aciklama'] = otomatikAciklama($_POST);

    $id = $_POST['id'];
    $gelis_tarihi = !empty($_POST['gelis_tarihi']) ? $_POST['gelis_tarihi'] : null;
    $firma = $_POST['firma'];
    $motor_tanimi = $_POST['motor_aciklama'];
    $aciklama_detay = $_POST['aciklama_detay'];
    $aciklama = $_POST['aciklama'];
    $tamir_durumu = $_POST['tamir_durumu'];
    $expertiz_tarihi = !empty($_POST['expertiz_tarihi']) ? $_POST['expertiz_tarihi'] : null;
    $teklif_tarihi = !empty($_POST['teklif_tarihi']) ? $_POST['teklif_tarihi'] : null;
    $onay_tarihi = !empty($_POST['onay_tarihi']) ? $_POST['onay_tarihi'] : null;
    $hazir_olma_tarihi = !empty($_POST['hazir_olma_tarihi']) ? $_POST['hazir_olma_tarihi'] : null;
    $fatura_tarihi = !empty($_POST['fatura_tarihi']) ? $_POST['fatura_tarihi'] : null;
    $teslim_tarihi = !empty($_POST['teslim_tarihi']) ? $_POST['teslim_tarihi'] : null;
    $gecikme_aciklamasi = $_POST['gecikme_aciklamasi'];
    $gecikme_aciklamasi = $_POST['gecikme_aciklamasi'];

  $updateQuery = "UPDATE el_aletleri SET
    gelis_tarihi = :gelis_tarihi,
    Kategori = :Kategori,
    firma = :firma,
    motor_tanimi = :motor_tanimi,
    aciklama_detay = :aciklama_detay,
    aciklama = :aciklama,
    tamir_durumu = :tamir_durumu,
    expertiz_tarihi = :expertiz_tarihi,
    teklif_tarihi = :teklif_tarihi,
    onay_tarihi = :onay_tarihi,
    hazir_olma_tarihi = :hazir_olma_tarihi,
    fatura_tarihi = :fatura_tarihi,
    teslim_tarihi = :teslim_tarihi,
    gecikme_aciklamasi = :gecikme_aciklamasi
    WHERE id = :id";

    $stmt = $conn->prepare($updateQuery);

 $stmt->execute([
    ':gelis_tarihi' => $gelis_tarihi,
    ':Kategori' => $_POST['Kategori'],
    ':firma' => $firma,
    ':motor_tanimi' => $motor_tanimi,
    ':aciklama' => $aciklama,
    ':aciklama_detay' => $aciklama_detay,
    ':tamir_durumu' => $tamir_durumu,
    ':expertiz_tarihi' => $expertiz_tarihi,
    ':teklif_tarihi' => $teklif_tarihi,
    ':onay_tarihi' => $onay_tarihi,
    ':hazir_olma_tarihi' => $hazir_olma_tarihi,
    ':fatura_tarihi' => $fatura_tarihi,
    ':teslim_tarihi' => $teslim_tarihi,
    ':gecikme_aciklamasi' => $gecikme_aciklamasi,
    ':id' => $id
]);

    // Güncelleme sonrası mevcut filtreleri koruyarak yönlendir
    $returnParams = $_GET; // POST sırasında da mevcut GET parametreleri burada bulunur
    if (isset($returnParams['id'])) unset($returnParams['id']); // id parametresini kaldır
    $qs = http_build_query($returnParams);
    $redirect = $_SERVER['PHP_SELF'] . ($qs ? ('?' . $qs) : '');
    header("Location: " . $redirect);
    exit();
}

// Sayfa bilgisi
$limit = 50; // Sayfa başına gösterilecek kayıt sayısı
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit; // Hangi kayıttan başlanacağını hesapla

// Filtreleme değişkenlerini al
$firma_filter = isset($_GET['firma']) ? $_GET['firma'] : '';
$motor_tanimi_filter = isset($_GET['motor_tanimi']) ? $_GET['motor_tanimi'] : '';
$kategori_filter = isset($_GET['Kategori']) ? $_GET['Kategori'] : '';
$aciklama_filter = isset($_GET['aciklama']) ? $_GET['aciklama'] : '';
$show_completed = isset($_GET['show_completed']) ? $_GET['show_completed'] : 'on';

// Toplam kayıt sayısını filtrelere göre hesapla
$countQuery = "SELECT COUNT(*) FROM el_aletleri WHERE 1";
$countParams = [];

if (!empty($firma_filter)) {
    $countQuery .= " AND firma LIKE :firma";
    $countParams[':firma'] = "%$firma_filter%";
}
if (!empty($motor_tanimi_filter)) {
    $countQuery .= " AND (
        motor_tanimi LIKE :arama
        OR firma LIKE :arama
        OR Kategori LIKE :arama
        OR aciklama_detay LIKE :arama
        OR aciklama LIKE :arama
        OR tamir_durumu LIKE :arama
        OR gecikme_aciklamasi LIKE :arama
    )";
    $countParams[':arama'] = "%$motor_tanimi_filter%";
}
if (!empty($kategori_filter)) {
    $countQuery .= " AND Kategori = :Kategori";
    $countParams[':Kategori'] = $kategori_filter;
}
if (!empty($aciklama_filter)) {
    $countQuery .= " AND aciklama = :aciklama";
    $countParams[':aciklama'] = $aciklama_filter;
}

// İşi bitenler toggle kontrolü
if ($show_completed == 'on') {
    $countQuery .= " AND (teslim_tarihi IS NULL OR teslim_tarihi = '0000-00-00')";
}

$countStmt = $conn->prepare($countQuery);
$countStmt->execute($countParams);
$totalRecords = $countStmt->fetchColumn(); // Toplam kayıt sayısını al
$totalPages = ceil($totalRecords / $limit); // Toplam sayfa sayısını hesapla


function otomatikAciklama($post) {
    if ($post['tamir_durumu'] === 'HAZIR' && !empty($post['teslim_tarihi']) && empty($post['fatura_tarihi'])) {
        return "teslim edilmiş fatura kesilmemiş"; // kırmızı
    }
    if ($post['tamir_durumu'] === 'HAZIR' && !empty($post['fatura_tarihi']) && !empty($post['teslim_tarihi'])) {
        return "teslim edilmiş fatura kesilmiş tüm işlemler bitmiş"; // krem
    }
    if ($post['tamir_durumu'] === 'HAZIR' && !empty($post['fatura_tarihi']) && empty($post['teslim_tarihi'])) {
        return "fatura kesilmiş teslim edilmemiş"; // mor
    }
    if ($post['tamir_durumu'] === 'HAZIR' && !empty($post['hazir_olma_tarihi']) && empty($post['teslim_tarihi'])) {
        return "hazır fatura kesilmemiş gitmemiş"; // sarı
    }
    if ($post['tamir_durumu'] === 'ONAYLANDI' && !empty($post['onay_tarihi'])) {
        return "onaylandı"; // yeşil
    }
    if ($post['tamir_durumu'] === 'ONAY BEKLIYOR' && !empty($post['teklif_tarihi'])) {
        return "onay bekliyor"; // turuncu
    }
    if ($post['tamir_durumu'] === 'EXPERTIZ' && !empty($post['expertiz_tarihi'])) {
        return "expertizi yapıldı teklif gönderilmedi"; // mavi
    }
    if ($post['tamir_durumu'] === 'GELDI') {
        return "yeni geldi"; // beyaz
    }
    if ($post['tamir_durumu'] === 'IADE' && empty($post['teslim_tarihi'])) {
        return "iade teslim edilmemiş"; // gri
    }
    if ($post['tamir_durumu'] === 'GARANTI' && empty($post['teslim_tarihi'])) {
        return "garanti teslim edilmemiş"; // gri
    }
    if ($post['tamir_durumu'] === 'IADE' && !empty($post['teslim_tarihi'])) {
        return "teslim edilmiş fatura kesilmiş tüm işlemler bitmiş"; // krem
    }
    if ($post['tamir_durumu'] === 'GARANTI' && !empty($post['teslim_tarihi'])) {
        return "teslim edilmiş fatura kesilmiş tüm işlemler bitmiş"; // krem
    }
    if ($post['tamir_durumu'] === 'PARÇA BEKLIYOR') {
        return "parça bekliyor"; // pembe
    }
    if ($post['tamir_durumu'] === 'DISARIDA') {
        return "dışarıya tamire gönderilmiş"; // kahve
    }

    // Burada artık "yeni geldi" yok
    return ""; // veya: return null;
}


// Veri ekleme işlemi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['id'])) {
    try {
        // Açıklamayı otomatik ata
        $_POST['aciklama'] = otomatikAciklama($_POST);

        // Boş tarih alanlarını NULL olarak ayarla ve tarih formatını kontrol et
        $gelis_tarihi = !empty($_POST['gelis_tarihi']) ? date('Y-m-d', strtotime($_POST['gelis_tarihi'])) : null;
        $expertiz_tarihi = !empty($_POST['expertiz_tarihi']) ? date('Y-m-d', strtotime($_POST['expertiz_tarihi'])) : null;
        $teklif_tarihi = !empty($_POST['teklif_tarihi']) ? date('Y-m-d', strtotime($_POST['teklif_tarihi'])) : null;
        $onay_tarihi = !empty($_POST['onay_tarihi']) ? date('Y-m-d', strtotime($_POST['onay_tarihi'])) : null;
        $hazir_olma_tarihi = !empty($_POST['hazir_olma_tarihi']) ? date('Y-m-d', strtotime($_POST['hazir_olma_tarihi'])) : null;
        $fatura_tarihi = !empty($_POST['fatura_tarihi']) ? date('Y-m-d', strtotime($_POST['fatura_tarihi'])) : null;
        $teslim_tarihi = !empty($_POST['teslim_tarihi']) ? date('Y-m-d', strtotime($_POST['teslim_tarihi'])) : null;

        // SQL sorgusu
       $stmt = $conn->prepare("INSERT INTO el_aletleri (gelis_tarihi, Kategori, firma, motor_tanimi, aciklama, aciklama_detay, tamir_durumu, expertiz_tarihi, teklif_tarihi, onay_tarihi, hazir_olma_tarihi, fatura_tarihi, teslim_tarihi, gecikme_aciklamasi) 
VALUES (:gelis_tarihi, :Kategori, :firma, :motor_tanimi, :aciklama, :aciklama_detay, :tamir_durumu, :expertiz_tarihi, :teklif_tarihi, :onay_tarihi, :hazir_olma_tarihi, :fatura_tarihi, :teslim_tarihi, :gecikme_aciklamasi)");


        // Verileri bağla ve sorguyu çalıştır
        $stmt->execute([
    ':gelis_tarihi' => $gelis_tarihi,
    ':Kategori' => $_POST['Kategori'],
    ':firma' => $_POST['firma'],
    ':motor_tanimi' => $_POST['motor_aciklama'],
    ':aciklama' => $_POST['aciklama'],
    ':aciklama_detay' => $_POST['aciklama_detay'],
    ':tamir_durumu' => $_POST['tamir_durumu'],
    ':expertiz_tarihi' => $expertiz_tarihi,
    ':teklif_tarihi' => $teklif_tarihi,
    ':onay_tarihi' => $onay_tarihi,
    ':hazir_olma_tarihi' => $hazir_olma_tarihi,
    ':fatura_tarihi' => $fatura_tarihi,
    ':teslim_tarihi' => $teslim_tarihi,
    ':gecikme_aciklamasi' => $_POST['gecikme_aciklamasi']
]);

        // Sayfayı yenile
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } catch (PDOException $e) {

        echo "Hata: " . $e->getMessage();
    }
}


// Filtreleme değişkenlerini al
$firma_filter = isset($_GET['firma']) ? $_GET['firma'] : '';
$motor_tanimi_filter = isset($_GET['motor_tanimi']) ? $_GET['motor_tanimi'] : '';
$kategori_filter = isset($_GET['Kategori']) ? $_GET['Kategori'] : '';
$aciklama_filter = isset($_GET['aciklama']) ? $_GET['aciklama'] : '';


// Sıralama parametrelerini al
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'id';
$order = (isset($_GET['order']) && strtolower($_GET['order']) == 'asc') ? 'ASC' : 'DESC';

// Tarih alanları için varsayılan sıralama DESC (yeni→eski) olsun
$dateFields = ['gelis_tarihi', 'expertiz_tarihi', 'teklif_tarihi', 'onay_tarihi', 'hazir_olma_tarihi', 'fatura_tarihi', 'teslim_tarihi'];
if (in_array($sort, $dateFields) && !isset($_GET['order'])) {
    $order = 'DESC'; // İlk tıklamada tarihler için DESC
}

// Güvenlik için sadece izin verilen sütunlarda sıralama yap
$allowedSorts = [
    'id', 'gelis_tarihi', 'Kategori', 'firma', 'motor_tanimi', 'aciklama', 'tamir_durumu',
    'expertiz_tarihi', 'teklif_tarihi', 'onay_tarihi', 'hazir_olma_tarihi', 'fatura_tarihi', 'teslim_tarihi'
];
if (!in_array($sort, $allowedSorts)) $sort = 'id';



// Sayfalama için veri çekme sorgusu
$query = "SELECT * FROM el_aletleri WHERE 1";
$params = [];

if (!empty($firma_filter)) {
    $query .= " AND firma LIKE :firma";
    $params[':firma'] = "%$firma_filter%";
}

if (!empty($motor_tanimi_filter)) {
    $query .= " AND (
        motor_tanimi LIKE :arama
        OR firma LIKE :arama
        OR Kategori LIKE :arama
        OR aciklama_detay LIKE :arama
        OR aciklama LIKE :arama
        OR gecikme_aciklamasi LIKE :arama
    )";
    $params[':arama'] = "%$motor_tanimi_filter%";
}
if (!empty($kategori_filter)) {
    $query .= " AND Kategori = :Kategori";
    $params[':Kategori'] = $kategori_filter;
}
if (!empty($aciklama_filter)) {
    $query .= " AND aciklama = :aciklama";
    $params[':aciklama'] = $aciklama_filter;
}

// İşi bitenler toggle kontrolü
if ($show_completed == 'on') {
    $query .= " AND (teslim_tarihi IS NULL OR teslim_tarihi = '0000-00-00')";
}

// Tarih alanları için özel sıralama (NULL değerleri sona koy)
$dateFields = ['gelis_tarihi', 'expertiz_tarihi', 'teklif_tarihi', 'onay_tarihi', 'hazir_olma_tarihi', 'fatura_tarihi', 'teslim_tarihi'];

if (in_array($sort, $dateFields)) {
    // Tarih alanları: NULL'lar en sona
    $query .= " ORDER BY $sort IS NULL, $sort $order";
} else {
    $query .= " ORDER BY $sort $order";
}

$query .= " LIMIT $limit OFFSET $offset"; // Sayfalama eklenmiş sorgu
$stmt = $conn->prepare($query);
$stmt->execute($params);
$veriler = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Tamir durumlarını veritabanından çek
$tamirDurumlariQuery = "SELECT * FROM tamir_durumlari";
$tamirDurumlariStmt = $conn->prepare($tamirDurumlariQuery);
$tamirDurumlariStmt->execute();
$tamirDurumlari = $tamirDurumlariStmt->fetchAll(PDO::FETCH_ASSOC);

// Firmaları veritabanından çek
$firmalarQuery = "SELECT * FROM firmalar ORDER BY firma_adi ASC";
$firmalarStmt = $conn->prepare($firmalarQuery);
$firmalarStmt->execute();
$firmalar = $firmalarStmt->fetchAll(PDO::FETCH_ASSOC);

// Kategorileri veritabanından çek
$kategoriQuery = "SELECT * FROM kategori";
$kategoriStmt = $conn->prepare($kategoriQuery);
$kategoriStmt->execute();
$kategoriler = $kategoriStmt->fetchAll(PDO::FETCH_ASSOC);


// Motor tanımlarını veritabanından çek (otocomplete için)
$motorTanimlariQuery = "SELECT DISTINCT motor_tanimi FROM el_aletleri WHERE motor_tanimi IS NOT NULL AND motor_tanimi != '' ORDER BY motor_tanimi ASC";
$motorTanimlariStmt = $conn->prepare($motorTanimlariQuery);
$motorTanimlariStmt->execute();
$motorTanimlari = $motorTanimlariStmt->fetchAll(PDO::FETCH_ASSOC);

// Açıklama verilerini veritabanından çek
$aciklamaQuery = "SELECT * FROM aciklama_tablo"; // Tablo adınızı uygun şekilde değiştirin
$aciklamaStmt = $conn->prepare($aciklamaQuery);
$aciklamaStmt->execute();
$aciklamalar = $aciklamaStmt->fetchAll(PDO::FETCH_ASSOC);

// satır renklerinin tanımlaması
$renkler = [
    "hazır fatura kesilmemiş gitmemiş" => "#FFFF00",      // Sarı
    "onay bekliyor" => "#E5732B",                         // Turuncu
    "onaylandı" => "#8BC34A",                             // Yeşil
    "iade teslim edilmemiş" => "#8C8C8C",                 // Gri
    "yeni geldi" => "#FFFFFF",                             // Beyaz
    "expertizi yapıldı teklif gönderilmedi" => "#176D8C", // koyu Mavi
    "parça bekliyor" => "#E89DEB",                        // Pembe
    "garanti teslim edilmemiş" => "#8C8C8C",              // Gri
    "dışarıya tamire gönderilmiş" => "#7B3F00",           // Kahverengi
    "teslim edilmiş fatura kesilmiş tüm işlemler bitmiş" => "#F9D6B6", // Ten rengi
    "fatura kesilmiş teslim edilmemiş" => "#7B3FA0",      // Mor
    "teslim edilmiş fatura kesilmemiş" => "#FF0000",      // Kırmızı
];

// Güncelleme işlemi için veriyi çekme ve silme işlemi
if (isset($_GET['id'])) {
    $id = $_GET['id'];

    

    // Güncelleme için veriyi çek
    $query = "SELECT * FROM el_aletleri WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>


<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motor Takibi Yönetim Paneli</title>
    <!-- Favicon hatasını önle -->
    <link rel="icon" href="data:,">
    <script>
    // Gerçek zamanlı oturum süresi
    let remainingTime = <?php echo floor($remaining_minutes * 60); ?>; // PHP'den kalan saniye
    let sessionTimer;
    
    let warningShown = false; // Uyarının bir kez gösterilmesi için
    
    function updateTimer() {
        const minutes = Math.floor(remainingTime / 60);
        const seconds = remainingTime % 60;
        const timeText = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        
        // Excel yanındaki oturum sayacını güncelle
        const timerExcel = document.getElementById('session-timer-excel');
        if (timerExcel) {
            timerExcel.textContent = timeText;
            
            // Excel yanındaki sayaç beyaz olduğu için renk değişimi yapmıyoruz
            // Ama opaklık ile uyarı verebiliriz
            if (remainingTime <= 120) {
                timerExcel.style.opacity = '1';
                timerExcel.style.textShadow = '0 0 5px rgba(255,0,0,0.5)';
            } else {
                timerExcel.style.opacity = '1';
                timerExcel.style.textShadow = 'none';
            }
        }
            
        // Son 20 saniyede uyarı mesajı göster
        if (remainingTime <= 20 && remainingTime > 0 && !warningShown) {
                warningShown = true;
                showLogoutWarning();
            }
            
            // Süre bittiğinde logout
            if (remainingTime <= 0) {
                clearInterval(sessionTimer);
                hideLogoutWarning(); // Uyarıyı gizle
                alert('Oturum süreniz doldu. Tekrar giriş yapmanız gerekiyor.');
                window.location.href = 'logout.php';
                return;
            }
        
        remainingTime--;
    }
    
    // Uyarı mesajını göster
    function showLogoutWarning() {
        // Uyarı div'i oluştur
        const warningDiv = document.createElement('div');
        warningDiv.id = 'logout-warning';
        warningDiv.innerHTML = `
            <div style="
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: linear-gradient(135deg, #dc3545, #c82333);
                color: white;
                padding: 25px 35px;
                border-radius: 12px;
                box-shadow: 0 8px 32px rgba(220,53,69,0.4);
                z-index: 10000;
                text-align: center;
                font-family: Arial, sans-serif;
                border: 3px solid #fff;
                animation: pulse 1s infinite;
                min-width: 300px;
            ">
                <h3 style="margin: 0 0 15px 0; font-size: 1.3em;">⚠️ UYARI!</h3>
                <p style="margin: 0 0 20px 0; font-size: 1.1em; font-weight: bold;">
                    Oturum 20 saniye içinde kapatılacak!
                </p>
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button onclick="extendSession()" style="
                        background: linear-gradient(135deg, #28a745, #20c997);
                        color: white;
                        border: none;
                        padding: 12px 20px;
                        border-radius: 8px;
                        font-size: 1em;
                        font-weight: bold;
                        cursor: pointer;
                        transition: all 0.3s ease;
                        box-shadow: 0 4px 12px rgba(40,167,69,0.3);
                    " onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                        ⏰ Uzat (+10 dk)
                    </button>
                    <button onclick="logoutNow()" style="
                        background: linear-gradient(135deg, #6c757d, #5a6268);
                        color: white;
                        border: none;
                        padding: 12px 20px;
                        border-radius: 8px;
                        font-size: 1em;
                        font-weight: bold;
                        cursor: pointer;
                        transition: all 0.3s ease;
                        box-shadow: 0 4px 12px rgba(108,117,125,0.3);
                    " onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                        🚪 Çıkış Yap
                    </button>
                </div>
            </div>
            <div style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 9999;
            "></div>
        `;
        
        // CSS animasyon ekle
        if (!document.getElementById('pulse-style')) {
            const style = document.createElement('style');
            style.id = 'pulse-style';
            style.textContent = `
                @keyframes pulse {
                    0% { transform: translate(-50%, -50%) scale(1); }
                    50% { transform: translate(-50%, -50%) scale(1.05); }
                    100% { transform: translate(-50%, -50%) scale(1); }
                }
            `;
            document.head.appendChild(style);
        }
        
        document.body.appendChild(warningDiv);
    }
    
    // Uyarı mesajını gizle
    function hideLogoutWarning() {
        const warningDiv = document.getElementById('logout-warning');
        if (warningDiv) {
            warningDiv.remove();
        }
    }
    
    // Oturumu uzat (+10 dakika)
    function extendSession() {
        // AJAX ile sunucuya oturum uzatma isteği gönder
        fetch('extend_session.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 10 dakika (600 saniye) ekle
                remainingTime += 600;
                warningShown = false; // Uyarıyı tekrar gösterebilmek için
                hideLogoutWarning(); // Uyarı penceresini kapat
                
                // Başarı mesajı göster
                showSuccessMessage('Oturum 10 dakika uzatıldı! ⏰');
            } else {
                alert('Oturum uzatılırken hata oluştu!');
            }
        })
        .catch(error => {
            console.error('Hata:', error);
            alert('Bağlantı hatası!');
        });
    }
    
    // Hemen çıkış yap
    function logoutNow() {
        clearInterval(sessionTimer);
        hideLogoutWarning();
        window.location.href = 'logout.php';
    }


    
    // Başarı mesajı göster
    function showSuccessMessage(message) {
        const successDiv = document.createElement('div');
        successDiv.innerHTML = `
            <div style="
                position: fixed;
                top: 20px;
                right: 20px;
                background: linear-gradient(135deg, #28a745, #20c997);
                color: white;
                padding: 15px 25px;
                border-radius: 8px;
                box-shadow: 0 4px 16px rgba(40,167,69,0.3);
                z-index: 10001;
                font-family: Arial, sans-serif;
                font-weight: bold;
                animation: slideIn 0.3s ease;
            ">
                ${message}
            </div>
        `;
        
        // Slide animasyonu ekle
        if (!document.getElementById('slide-style')) {
            const style = document.createElement('style');
            style.id = 'slide-style';
            style.textContent = `
                @keyframes slideIn {
                    0% { transform: translateX(100%); }
                    100% { transform: translateX(0); }
                }
            `;
            document.head.appendChild(style);
        }
        
        document.body.appendChild(successDiv);
        
        // 3 saniye sonra kaldır
        setTimeout(() => {
            successDiv.remove();
        }, 3000);
    }
    
    // Timer'ı başlat
    document.addEventListener('DOMContentLoaded', function() {
        updateTimer(); // İlk güncelleme
        sessionTimer = setInterval(updateTimer, 1000); // Her saniye güncelle
    });
    
    // Sadece sekme/tarayıcı gerçekten kapatılırken logout çalışsın
    window.addEventListener('pagehide', function (e) {
    if (e.persisted === false) { // Sekme gerçekten kapanıyor veya tarayıcıdan çıkılıyor
        if (navigator.sendBeacon) {
            navigator.sendBeacon('logout_ajax.php');
        } else {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'logout_ajax.php', false);
            xhr.send();
        }
    }
});
    </script>
    </script>
    <style>
    /* Session Timer Styles */


    #logout-btn:hover {
        background: #c82333;
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    #logout-btn svg {
        stroke: white;
    }
    
    #oturum_modal {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100vw; height: 100vh;
        background: rgba(0,0,0,0.35);
        align-items: center;
        justify-content: center;
        z-index: 99999;
    }
    #oturum_modal .modal-icerik {
        background: #fff;
        padding: 32px 28px 24px 28px;
        border-radius: 12px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.18);
        text-align: center;
        min-width: 280px;
    }
    #oturum_modal .modal-icerik h3 {
        margin: 0 0 12px 0;
        font-size: 1.25rem;
        color: #d8000c;
    }
    #oturum_modal .modal-icerik button {
        background: #007bff;
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 10px 28px;
        font-size: 1.08rem;
        margin-top: 18px;
        cursor: pointer;
        transition: background 0.2s;
    }
    #oturum_modal .modal-icerik button:hover {
        background: #0056b3;
    }
    </style>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Motor Takibi Yönetim Paneli</title>
    <style>
        body {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            font-family: Arial, sans-serif;
            margin: 0;
        }
        .sidebar {
            width: 300px;
            padding: 20px;
            background: #f4f4f4;
        }
        .content {
            flex: 1;
            padding: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            table-layout: fixed;
            font-size: 13px; /* Font boyutu küçültüldü */
        }
        th, td {
            border: 1px solid black;
            padding: 4px 6px; /* Padding küçültüldü */
            text-align: center; /* Metinler orta hizalı */
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            line-height: 1.3; /* Satır yüksekliği ayarlandı */
        }
        th { 
            font-size: 12px;
            font-weight: bold;
            border: 1px solid black;
        }
        
        td {
            border: 1px solid #e9ecef;
        }
        button { cursor: pointer; }
        .pagination { margin-top: 20px; }
        .pagination a { margin-right: 5px; text-decoration: none; }
        .pagination button { padding: 5px 10px; cursor: pointer; }
        .disabled { color: grey; cursor: not-allowed; }

        /* Kompakt Toggle Switch */
        .compact-toggle .slider {
            transition: all 0.4s ease;
        }
        
        .compact-toggle .slider span {
            transition: all 0.4s ease;
        }
        
        .compact-toggle input:checked + .slider {
            background-color: #28a745 !important;
        }
        
        .compact-toggle input:checked + .slider span {
            left: 22px !important;
        }
        
        .compact-toggle .slider:hover {
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        
        .toggle-switch input[type="checkbox"] {
            display: none;
        }
        
        .toggle-label {
            display: block;
            width: 80px;
            height: 35px;
            background: #dc3545;
            border-radius: 20px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }
        
        .toggle-label:after {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 29px;
            height: 29px;
            background: white;
            border-radius: 50%;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .toggle-switch input:checked + .toggle-label {
            background: #28a745;
        }
        
        .toggle-switch input:checked + .toggle-label:after {
            left: 48px;
        }
        
        .toggle-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 11px;
            font-weight: bold;
            color: white;
            pointer-events: none;
        }

        /* Table Responsive - Okunabilir Tasarım */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -10px;
            padding: 0 10px;
            background: #f8f9fa;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .table-responsive table {
            min-width: 1200px;
            background: white;
            border-radius: 6px;
            overflow: hidden;
        }
        
        /* Sticky Header */
        .table-responsive th {
            position: sticky;
            top: 0;
            z-index: 10;
            background-color: #f4f4f4;
            color: black;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Row Hover Effects */
        .table-responsive tr:hover {
            background-color: rgba(102, 126, 234, 0.1) !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }
        
        /* Zebra Striping */
        .table-responsive tr:nth-child(even) {
            background-color: rgba(0,0,0,0.02);
        }
        
        /* Custom Scrollbar */
        .table-responsive::-webkit-scrollbar {
            height: 12px;
        }
        
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 6px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border-radius: 6px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(45deg, #5a6fd8, #6a42a0);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                width: 100vw; /* Mobilde tam genişlik */
                left: -100vw; /* Başlangıçta tamamen gizli */
            }
            
            .sidebar.active {
                left: 0;
            }
            
            #sidebar-toggle {
                top: 15px;
                left: 15px;
                padding: 10px;
            }
            
            .content {
                padding: 15px;
            }
            
            table, th, td {
                font-size: 0.75em;
                padding: 2px 3px;
            }
            
            .table-responsive table {
                min-width: 900px;
            }
            
            /* Mobilde hover efektini azalt */
            .table-responsive tr:hover {
                transform: none;
                box-shadow: none;
            }
            
            .table-responsive::-webkit-scrollbar {
                height: 8px;
            }
            
            /* Düzenle butonunu mobilde daha küçük yap */
            .actions-column button {
                padding: 2px 6px !important;
                font-size: 9px !important;
            }
        }
        
        @media (max-width: 480px) {
            .sidebar {
                padding: 70px 15px 15px 15px;
            }
            
            .sidebar .panel {
                padding: 12px;
            }
            
            /* Çok küçük ekranlarda bazı sütunları gizle */
            .table-responsive table {
                min-width: 600px;
            }
            
            table, th, td {
                font-size: 0.65em;
                padding: 1px 2px;
            }
            
            /* Gereksiz sütunları gizle */
            th:nth-child(6), td:nth-child(6), /* Açıklama Detay */
            th:nth-child(8), td:nth-child(8), /* Expertiz Tarihi */
            th:nth-child(9), td:nth-child(9), /* Teklif Tarihi */
            th:nth-child(13), td:nth-child(13) /* Gecikme Açıklaması */ {
                display: none;
            }
            
            /* Touch-friendly improvements */
            .actions-column button {
                padding: 8px 12px !important;
                font-size: 10px !important;
                min-height: 36px;
                min-width: 60px;
            }
            
            #sidebar-toggle {
                padding: 15px !important;
                width: 50px;
                height: 50px;
            }
            
            /* Pagination touch-friendly */
            .pagination button {
                padding: 10px 15px;
                margin: 5px;
                min-height: 40px;
            }
            
            .sidebar .panel input,
            .sidebar .panel select,
            .sidebar .panel textarea {
                padding: 8px;
                font-size: 16px; /* iOS zoom önleme */
            }
            
            .content {
                padding: 10px;
            }
            
            table, th, td {
                font-size: 0.85em;
            }
        }
        @media (max-width: 500px) {
            .sidebar, .content {
                padding: 4px;
            }
            table, th, td {
                font-size: 0.85em;
            }
            th, td {
                padding: 4px;
            }
            .pagination button {
                padding: 4px 7px;
                font-size: 0.9em;
            }
        }
        .pagination { margin-top: 20px; }
        .pagination a { margin-right: 5px; text-decoration: none; }
        .pagination button { padding: 5px 10px; cursor: pointer; }
        .disabled { color: grey; cursor: not-allowed; }


        table th.id-column, table td.id-column {
    width: 90px !important; /* Genişliği artırıldı */
    text-align: center;
}

        table th.actions-column, table td.actions-column {
        width: 100px; /* İşlemler sütununun genişliği */
        text-align: center; /* Metni ortalar */
    }

    .content select {
        font-size: 12px; /* Yazı boyutunu küçült */
        padding: 2px 4px; /* İç boşlukları küçült */
        height: 25px; /* Yüksekliği ayarla */
        width: 120px; /* Genişliği ayarla */
        border-radius: 15px; /* Köşeleri yuvarla */
    }
    label {
        font-weight: bold;
        color: #333333 !important; /* Label rengini siyaha yakın yap */
    }

table th.sira-column, table td.sira-column {
    width: 70px !important;   /* Daha küçük bir genişlik */
    text-align: center;
    padding-left: 0;
    padding-right: 0;
}

.pagination {
    margin-top: 24px;
    display: flex;
    align-items: center;
    gap: 8px;
    justify-content: center;
}
.pagination button {
    background: linear-gradient(90deg, #43cea2 0%, #185a9d 100%);
    color: #fff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-size: 15px;
    font-weight: bold;
    box-shadow: 0 2px 8px rgba(24,90,157,0.10);
    cursor: pointer;
    transition: background 0.2s, transform 0.2s;
    outline: none;
}
.pagination button:hover:not(:disabled) {
    background: linear-gradient(90deg, #185a9d 0%, #43cea2 100%);
    transform: translateY(-2px) scale(1.05);
}
.pagination .disabled button,
.pagination button:disabled {
    background: #ccc !important;
    color: #888 !important;
    cursor: not-allowed !important;
    box-shadow: none;
    transform: none;
}
.pagination span {
    font-size: 16px;
    font-weight: bold;
    color: #185a9d;
    margin: 0 8px;
}

/* Accordion Styles for Sidebar */
.accordion {
    background: linear-gradient(135deg, #43cea2, #185a9d);
    color: white;
    cursor: pointer;
    padding: 15px 20px;
    width: 100%;
    border: none;
    text-align: left;
    outline: none;
    font-size: 16px;
    font-weight: bold;
    transition: all 0.3s ease;
    border-radius: 8px;
    margin-bottom: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.accordion.active, .accordion:hover {
    background: linear-gradient(135deg, #185a9d, #43cea2);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.accordion:after {
    content: '\25BC'; /* Down arrow */
    float: right;
    transform: rotate(0deg);
    transition: transform 0.3s ease;
}

.accordion.active:after {
    transform: rotate(180deg);
}

.panel {
    padding: 15px;
    display: none;
    background: rgba(255,255,255,0.9);
    overflow: hidden;
    margin-bottom: 15px;
    border-radius: 8px;
    border: 2px solid #e9ecef;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
}

/* Form Styles within Sidebar */
.sidebar .panel form {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.sidebar .panel label {
    font-weight: bold;
    color: #2c3e50;
    font-size: 14px;
}

.sidebar .panel input,
.sidebar .panel select,
.sidebar .panel textarea {
    padding: 10px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.3s ease;
}

.sidebar .panel input:focus,
.sidebar .panel select:focus,
.sidebar .panel textarea:focus {
    outline: none;
    border-color: #43cea2;
    box-shadow: 0 0 0 3px rgba(67, 206, 162, 0.1);
}

.sidebar .panel button[type="submit"] {
    background: linear-gradient(135deg, #4CAF50, #45A049);
    color: white;
    padding: 12px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(76, 175, 80, 0.2);
}

.sidebar .panel button[type="submit"]:hover {
    background: linear-gradient(135deg, #45A049, #4CAF50);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
}





/* Sidebar açıkken content sağa kayar (sadece büyük ekranlarda) */
@media (min-width: 1200px) {
    .sidebar.active ~ .content {
        margin-left: 350px;
    }
}

/* Sidebar içerik stilleri */
.sidebar h2 {
    color: #2c3e50;
    margin-bottom: 20px;
    font-size: 1.4em;
    text-align: center;
    border-bottom: 2px solid #43cea2;
    padding-bottom: 10px;
}

/* Toggle Button Styles */
#sidebar-toggle {
    position: fixed !important;
    top: 20px !important;
    left: 20px !important;
    z-index: 10000 !important; /* En üstte olmalı */
    background: linear-gradient(135deg, #43cea2, #185a9d) !important;
    color: white !important;
    border: none !important;
    padding: 15px !important;
    border-radius: 8px !important;
    cursor: pointer !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2) !important;
    transition: all 0.3s ease !important;
    width: 50px !important;
    height: 50px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

#sidebar-toggle:hover {
    background: linear-gradient(135deg, #185a9d, #43cea2) !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 16px rgba(0,0,0,0.3) !important;
}

#sidebar-toggle:active {
    transform: translateY(0) scale(0.95) !important;
}

/* Overlay Styles */
#sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 9998 !important; /* Sidebar'dan bir alt seviye */
    display: none;
    opacity: 0;
    transition: opacity 0.3s ease;
}

#sidebar-overlay.active {
    display: block;
    opacity: 1;
}
.sidebar h2 {
    font-size: 1.1em;
    margin-bottom: 10px;
}
.accordion {
    font-size: 13px;
    padding: 8px;
}
.panel label,
.panel input,
.panel select,
.panel textarea,
.panel button {
    font-size: 12px;
}
@media (max-width: 768px) {
    .sidebar {
        width: 100% !important;
        min-width: unset;
        padding: 10px 4px !important;
    }
}










.table-responsive {
  width: 100%;
  overflow-x: auto;
}
@media (max-width: 800px) {
  table, th, td { font-size: 1.08em; }
  .table-responsive { margin: 0 -8px; }
}
@media (max-width: 500px) {
  table, th, td { font-size: 1.13em; }
  th, td { padding: 14px 8px; }
}








    
    /* SIDEBAR OVERRIDE - En son tanım kazanır */
    .sidebar {
        position: fixed !important;
        top: 0 !important;
        left: -400px !important; /* Daha fazla sola kaydır - tamamen gizli */
        width: 350px !important;
        height: 100vh !important;
        padding: 80px 20px 20px 20px !important;
        background: #ffffff !important; /* Şeffaf değil, tamamen beyaz */
        box-shadow: 4px 0 20px rgba(0,0,0,0.3) !important;
        z-index: 9999 !important; /* Daha yüksek z-index */
        overflow-y: auto !important;
        transition: left 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94) !important;
        border-right: 3px solid #43cea2 !important;
        display: block !important;
        visibility: hidden !important; /* Başlangıçta görünmez */
    }
    
    .sidebar.active {
        left: 0 !important;
        visibility: visible !important; /* Açıldığında görünür */
    }
    
    body {
        font-family: Arial, sans-serif !important;
        margin: 0 !important;
        background: #f5f5f5 !important;
        display: block !important; /* Flex kapat */
    }
    
    /* Content alanının z-index'ini düşür */
    .content {
        position: relative !important;
        z-index: 1 !important;
        padding-top: 80px !important; /* Üstten boşluk */
    }
    
    /* Content alanındaki label'ları düzelt */
    .content label {
        color: #333333 !important;
        font-weight: bold !important;
        margin-right: 8px !important;
    }

    /* Edit panel action bar (sticky inside the sidebar) */
    .sidebar .action-bar {
        position: sticky !important;
        bottom: 0 !important;
        padding: 12px 14px !important;
        background: linear-gradient(180deg, rgba(255,255,255,0.95), rgba(250,250,250,0.98)) !important;
        border-top: 1px solid #e9ecef !important;
        display: flex !important;
        justify-content: center !important;
        z-index: 10000 !important;
    }

    .sidebar .action-bar-inner {
        display: flex !important;
        gap: 8px !important;
        width: 100% !important;
        align-items: center !important;
    }

    .sidebar .action-bar .btn-delete {
        flex: 0 0 110px !important;
        background: #e74c3c !important;
        color: white !important;
        padding: 10px 12px !important;
        border: none !important;
        border-radius: 8px !important;
        cursor: pointer !important;
        font-weight: 700 !important;
        box-shadow: 0 4px 12px rgba(231,76,60,0.15) !important;
    }

    .sidebar .action-bar .btn-update {
        flex: 1 1 auto !important;
        background: linear-gradient(135deg, #2196F3, #1E88E5) !important;
        color: white !important;
        padding: 10px 12px !important;
        border: none !important;
        border-radius: 8px !important;
        cursor: pointer !important;
        transition: background 0.3s !important;
    }

    .sidebar .action-bar .btn-cancel {
        flex: 0 0 110px !important;
        background: linear-gradient(135deg, #95a5a6, #7f8c8d) !important;
        color: white !important;
        padding: 10px 12px !important;
        border: none !important;
        border-radius: 8px !important;
        cursor: pointer !important;
        transition: background 0.3s !important;
    }

    /* Ensure panel bottom has space so fields aren't hidden behind the action-bar */
    .sidebar .panel, .sidebar form {
        padding-bottom: 110px !important; /* room for action-bar (including mobile stacked buttons) */
    }

    /* Small screens: stack action buttons for better touch access */
    @media (max-width: 480px) {
        .sidebar .action-bar-inner { flex-direction: column !important; }
        .sidebar .action-bar .btn-delete,
        .sidebar .action-bar .btn-update,
        .sidebar .action-bar .btn-cancel { flex: 1 1 auto !important; width: 100% !important; }
    }

    /* Fallback: sabit (fixed) action-bar - JS ile eklenebilir */
    .sidebar .action-bar-fixed {
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important; /* overridden dynamically by JS */
        width: 320px !important; /* overridden dynamically by JS */
        padding: 12px 14px !important;
        background: linear-gradient(180deg, #ffffff, #fafafa) !important;
        border-top: 1px solid #e9ecef !important;
        box-shadow: 0 -6px 18px rgba(0,0,0,0.12) !important;
        z-index: 100000 !important;
    }

    </style>


    <script>
document.addEventListener("DOMContentLoaded", function() {
    console.log('DOM yüklendi'); // Debug mesajı
    
    // Sidebar elements
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggleBtn = document.getElementById('sidebar-toggle');
    
    // Element kontrolü
    console.log('Sidebar:', sidebar);
    console.log('Overlay:', overlay); 
    console.log('Toggle Button:', toggleBtn);
    
    // Toggle sidebar function
    function toggleSidebar() {
        console.log('Toggle sidebar clicked'); // Debug mesajı
        
        if (sidebar.classList.contains('active')) {
            // Sidebar'ı kapat
            console.log('Sidebar kapanıyor');
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            sidebar.style.left = '-400px';
            sidebar.style.visibility = 'hidden';
            overlay.style.opacity = '0';
            setTimeout(() => overlay.style.display = 'none', 300);
        } else {
            // Sidebar'ı aç
            console.log('Sidebar açılıyor');
            sidebar.classList.add('active');
            overlay.classList.add('active');
            sidebar.style.visibility = 'visible';
            sidebar.style.left = '0px';
            overlay.style.display = 'block';
            setTimeout(() => overlay.style.opacity = '1', 10);
        }
    }
    
    // Close sidebar function
    function closeSidebar() {
        console.log('Sidebar kapanıyor - closeSidebar');
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        sidebar.style.left = '-400px';
        sidebar.style.visibility = 'hidden';
        overlay.style.opacity = '0';
        setTimeout(() => overlay.style.display = 'none', 300);
    }
    
    // Başlangıçta sidebar'ı kesinlikle gizle
    sidebar.classList.remove('active');
    overlay.classList.remove('active');
    sidebar.style.left = '-400px';
    sidebar.style.visibility = 'hidden';
    overlay.style.display = 'none';
    overlay.style.opacity = '0';
    
    // Toggle button click
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Toggle butonuna tıklandı');
            toggleSidebar();
        });
    }
    
    // Overlay click to close
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }
    
    // ESC key to close
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('active')) {
            closeSidebar();
        }
    });
    
    // Accordion functionality
    var acc = document.getElementsByClassName("accordion");
    for (var i = 0; i < acc.length; i++) {
        acc[i].addEventListener("click", function() {
            this.classList.toggle("active");
            var panel = this.nextElementSibling;
            if (panel.style.display === "block") {
                panel.style.display = "none";
            } else {
                panel.style.display = "block";
            }
        });
    }
    
    // Auto-open first accordion if editing
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('id')) {
        // Güncelleme modu - sidebar'ı aç
        setTimeout(() => {
            toggleSidebar();
            // Güncelleme accordion'ını aç
            const accordions = document.getElementsByClassName("accordion");
            for (let i = 0; i < accordions.length; i++) {
                if (accordions[i].textContent.includes('Güncelle')) {
                    accordions[i].click();
                    break;
                }
            }
        }, 100);
    }
});

// Filtreleri temizle fonksiyonu
function temizleFiltreler() {
    // Basit sayfa yönlendirmesi
    window.location.href = window.location.pathname;
}

// Güncelleme işlemini iptal et
function cancelEdit() {
    // Mevcut filtreleri koruyarak ana sayfaya dön
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.delete('id'); // id parametresini kaldır
    
    const newUrl = urlParams.toString() ? 
        window.location.pathname + '?' + urlParams.toString() : 
        window.location.pathname;
    
    window.location.href = newUrl;
}

// İşi bitenler toggle fonksiyonu
function toggleCompleted() {
    const checkbox = document.getElementById('completedToggle');
    
    console.log('Toggle clicked! Checkbox durumu:', checkbox.checked);
    
    // Yeni durumu localStorage'a kaydet
    localStorage.setItem('showCompleted', checkbox.checked.toString());
    
    // URL parametrelerini güncelle
    const urlParams = new URLSearchParams(window.location.search);
    
    if (checkbox.checked) {
        urlParams.set('show_completed', 'on');
    } else {
        urlParams.set('show_completed', 'off');
    }
    
    console.log('LocalStorage kaydedildi:', checkbox.checked);
    console.log('Yeni URL:', window.location.pathname + '?' + urlParams.toString());
    
    // Sayfayı yenile
    window.location.href = window.location.pathname + '?' + urlParams.toString();
}

// Sayfa yüklendiğinde localStorage'dan durumu kontrol et
document.addEventListener('DOMContentLoaded', function() {
    const checkbox = document.getElementById('completedToggle');
    const savedState = localStorage.getItem('showCompleted');
    
    console.log('Sayfa yüklendi. LocalStorage durumu:', savedState);
    
    if (savedState !== null) {
        // localStorage'dan kaydedilen durumu uygula
        const isChecked = savedState === 'true';
        checkbox.checked = isChecked;
        
        console.log('Checkbox localStorage durumuna ayarlandı:', isChecked);
        
        // URL parametresini kontrol et ve gerekirse güncelle
        const urlParams = new URLSearchParams(window.location.search);
        const urlShowCompleted = urlParams.get('show_completed');
        
        // URL ile localStorage uyumsuzsa URL'i güncelle
        if ((isChecked && urlShowCompleted !== 'on') || (!isChecked && urlShowCompleted !== 'off')) {
            if (isChecked) {
                urlParams.set('show_completed', 'on');
            } else {
                urlParams.set('show_completed', 'off');
            }
            
            // Sessizce URL'i güncelle (sayfa yenileme olmadan)
            window.history.replaceState({}, '', '?' + urlParams.toString());
            console.log('URL localStorage durumuna göre güncellendi');
        }
    } else {
        // İlk kez geliyorsa URL parametresine göre localStorage'ı ayarla
        const urlParams = new URLSearchParams(window.location.search);
        const urlShowCompleted = urlParams.get('show_completed');
        
        // Eğer URL'de parametre yoksa varsayılan olarak 'on' (bitenler gizli) yap
        const defaultState = urlShowCompleted ? (urlShowCompleted === 'on') : true;
        
        localStorage.setItem('showCompleted', defaultState.toString());
        checkbox.checked = defaultState;
        
        console.log('İlk ziyaret. LocalStorage varsayılan duruma ayarlandı:', defaultState);
    }
});


</script>

<script>
// Drag-to-scroll for .table-responsive using Pointer Events with threshold
(function(){
    // Run after DOM ready so the container exists
    document.addEventListener('DOMContentLoaded', function(){
        // inject necessary CSS for cursor states
        const css = `
        .table-responsive { cursor: grab; -webkit-overflow-scrolling: touch; }
        .table-responsive.dragging { cursor: grabbing; user-select: none; }
        `;
        const style = document.createElement('style'); style.appendChild(document.createTextNode(css));
        document.head.appendChild(style);

        const container = document.querySelector('.table-responsive');
        if (!container) return;

        // Ensure touch action allows pointer-based horizontal dragging
        // 'pan-y' lets the browser handle vertical scrolling but disables horizontal
        // native panning so our pointermove can control horizontal scroll.
        container.style.touchAction = container.style.touchAction || 'pan-y';

        let isDown = false;
        let startX = 0;
        let scrollLeft = 0;
        let dragging = false;
        const threshold = 6; // px before starting to drag to allow clicks

        container.addEventListener('pointerdown', function(e){
            // Ignore interactions started on form controls
            const tag = (e.target && e.target.tagName) ? e.target.tagName.toUpperCase() : '';
            if (['INPUT','TEXTAREA','SELECT','BUTTON','A','LABEL'].includes(tag)) return;

            // prevent default to stop native gestures from stealing the pointer
            if (e.cancelable) e.preventDefault();

            isDown = true;
            dragging = false;
            startX = e.clientX;
            scrollLeft = container.scrollLeft;
            if (container.setPointerCapture) {
                try { container.setPointerCapture(e.pointerId); } catch(err) { /* ignore */ }
            }
        });

        container.addEventListener('pointermove', function(e){
            if (!isDown) return;
            const dx = e.clientX - startX;
            if (!dragging && Math.abs(dx) < threshold) return; // don't start until threshold
            if (!dragging) {
                dragging = true;
                container.classList.add('dragging');
                document.body.style.userSelect = 'none';
            }

            // preventDefault helps on some browsers / devices to avoid native scrolling
            if (e.cancelable) e.preventDefault();

            container.scrollLeft = scrollLeft - dx;
        });

        function stopDrag(e){
            if (!isDown) return;
            isDown = false;
            dragging = false;
            container.classList.remove('dragging');
            document.body.style.userSelect = '';
            try { if (e && e.pointerId && container.releasePointerCapture) container.releasePointerCapture(e.pointerId); } catch(err){}
        }

        container.addEventListener('pointerup', stopDrag);
        container.addEventListener('pointercancel', stopDrag);
        container.addEventListener('pointerleave', stopDrag);
    });

})();
</script>
</head> 


<body>


<!-- Sidebar Toggle Butonu -->
<button id="sidebar-toggle">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="3" y1="6" x2="21" y2="6"></line>
        <line x1="3" y1="12" x2="21" y2="12"></line>
        <line x1="3" y1="18" x2="21" y2="18"></line>
    </svg>
</button>

<!-- Overlay -->
<div id="sidebar-overlay"></div>

<a href="<?= $_SERVER['PHP_SELF'] ?>">
    <img src="logo.png" alt="Logo" style="position: absolute; top: 20px; right: 20px; width: 160px; height: auto; z-index: 100;">
</a>
<!-- Yeni kayıt formu -->

<div class="sidebar" id="sidebar">
    <button class="accordion">Yeni Kayıt Ekle</button>
    <div class="panel">
        <form method="POST" style="display: flex; flex-direction: column; gap: 10px;">
            <label>Geliş Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="gelis_tarihi" id="gelis_tarihi_input" required>
    <button type="button" onclick="document.getElementById('gelis_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>

            <label>Kategori:</label>
            <select name="Kategori" required>
                <option value="">Seçiniz</option>
                <?php foreach ($kategoriler as $kategori): ?>
                    <option value="<?= htmlspecialchars($kategori['KategoriAdi']) ?>">
                        <?= htmlspecialchars($kategori['KategoriAdi']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Firma:</label>
            <select name="firma" required>
                <option value="">Seçiniz</option>
                <?php foreach ($firmalar as $firma): ?>
                    <option value="<?= htmlspecialchars($firma['firma_adi']) ?>">
                        <?= htmlspecialchars($firma['firma_adi']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Motor Açıklama:</label>
            <input type="text" name="motor_aciklama" required placeholder="Motor tanımını giriniz">

            <label>Açıklama:</label>
            <input type="text" name="aciklama_detay" required placeholder="Açıklama giriniz">

            <label>Tamir Durumu:</label>
            <select name="tamir_durumu">
                <option value="">Seçiniz</option>
                <?php foreach ($tamirDurumlari as $durum): ?>
                    <option value="<?= htmlspecialchars($durum['durum']) ?>">
                        <?= htmlspecialchars($durum['durum']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Expertiz Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="expertiz_tarihi" id="expertiz_tarihi_input">
    <button type="button" onclick="document.getElementById('expertiz_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>
           <!-- Teklif Tarihi -->
<label>Teklif Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="teklif_tarihi" id="teklif_tarihi_input">
    <button type="button" onclick="document.getElementById('teklif_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>

<!-- Onay Tarihi -->
<label>Onay Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="onay_tarihi" id="onay_tarihi_input">
    <button type="button" onclick="document.getElementById('onay_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>

<!-- Hazır Olma Tarihi -->
<label>Hazır Olma Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="hazir_olma_tarihi" id="hazir_olma_tarihi_input">
    <button type="button" onclick="document.getElementById('hazir_olma_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>

<!-- Fatura Tarihi -->
<label>Fatura Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="fatura_tarihi" id="fatura_tarihi_input">
    <button type="button" onclick="document.getElementById('fatura_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>

<!-- Teslim Tarihi -->
<label>Teslim Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="teslim_tarihi" id="teslim_tarihi_input">
    <button type="button" onclick="document.getElementById('teslim_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>
            <label>Gecikme Açıklaması:</label>
            <textarea name="gecikme_aciklamasi"></textarea>
            <button type="submit" style="background: linear-gradient(135deg, #4CAF50, #45A049); color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; transition: background 0.3s;">Ekle</button>
        </form>
    </div>
 <!-- Firma Ekle -->

    <button class="accordion">Firma Ekle</button>
    <div class="panel">
        <form method="POST" style="margin: 10px 0; display: flex; gap: 8px; align-items: center;">
            <label for="yeni_firma" style="font-weight:bold;">Yeni Firma:</label>
            <input type="text" name="yeni_firma" id="yeni_firma" required placeholder="Firma adı giriniz">
            <button type="submit" name="yeni_firma_ekle" style="background: #4CAF50; color: #fff; border: none; padding: 6px 16px; border-radius: 8px;">Ekle</button>
        </form>
    </div>

 <!-- Toplu güncelleme -->

<!-- Tüm Kayıtları Güncelle butonu -->
<a href="?tum_kayitlari_guncelle=1" style="
    display: inline-block;
    background: linear-gradient(90deg, #43cea2 0%, #185a9d 100%);
    color: #fff;
    font-weight: bold;
    padding: 10px 24px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 15px;
    box-shadow: 0 2px 8px rgba(24,90,157,0.15);
    margin: 12px 0;
    transition: background 0.3s, transform 0.2s;
    text-align: center;
">
    &#8635; Tüm Kayıtları Güncelle
</a>
<?php if (isset($_GET['tum_kayitlari_guncelle'])) {
    // yukarıdaki foreach burada çalıştırılır

    // Tüm kayıtları al
$stmt = $conn->query("SELECT * FROM el_aletleri");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Her kayıt için güncelle
foreach ($rows as $row) {
    // Otomatik açıklamayı oluştur
    $row['aciklama'] = otomatikAciklama($row);

    // Güncelleme sorgusu
    $updateQuery = "UPDATE el_aletleri SET
        gelis_tarihi = :gelis_tarihi,
        Kategori = :Kategori,
        firma = :firma,
        motor_tanimi = :motor_tanimi,
        aciklama_detay = :aciklama_detay,
        aciklama = :aciklama,
        tamir_durumu = :tamir_durumu,
        expertiz_tarihi = :expertiz_tarihi,
        teklif_tarihi = :teklif_tarihi,
        onay_tarihi = :onay_tarihi,
        hazir_olma_tarihi = :hazir_olma_tarihi,
        fatura_tarihi = :fatura_tarihi,
        teslim_tarihi = :teslim_tarihi,
        gecikme_aciklamasi = :gecikme_aciklamasi
        WHERE id = :id";

    $stmtUpdate = $conn->prepare($updateQuery);

    $stmtUpdate->execute([
        ':gelis_tarihi' => $row['gelis_tarihi'],
        ':Kategori' => $row['Kategori'],
        ':firma' => $row['firma'],
        ':motor_tanimi' => $row['motor_tanimi'],
        ':aciklama_detay' => $row['aciklama_detay'],
        ':aciklama' => $row['aciklama'],
        ':tamir_durumu' => $row['tamir_durumu'],
        ':expertiz_tarihi' => $row['expertiz_tarihi'],
        ':teklif_tarihi' => $row['teklif_tarihi'],
        ':onay_tarihi' => $row['onay_tarihi'],
        ':hazir_olma_tarihi' => $row['hazir_olma_tarihi'],
        ':fatura_tarihi' => $row['fatura_tarihi'],
        ':teslim_tarihi' => $row['teslim_tarihi'],
        ':gecikme_aciklamasi' => $row['gecikme_aciklamasi'],
        ':id' => $row['id']
    ]);
}
}
?>

    <button class="accordion">Güncelle</button>
    <div class="panel">
        <?php if (isset($data)): ?>
<form method="POST" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>" style="display: flex; flex-direction: column; gap: 10px;">        <input type="hidden" name="id" value="<?= $data['id'] ?>">

        <!-- Geliş Tarihi -->
<label>Geliş Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="gelis_tarihi" id="guncelle_gelis_tarihi_input" value="<?= htmlspecialchars($data['gelis_tarihi']) ?>" required>
    <button type="button" onclick="document.getElementById('guncelle_gelis_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>

        <label>Kategori:</label>
<select name="Kategori">
    <option value="">Seçiniz</option>
    <?php foreach ($kategoriler as $kategori): ?>
        <option value="<?= htmlspecialchars($kategori['KategoriAdi']) ?>"
            <?= isset($data['Kategori']) && $data['Kategori'] == $kategori['KategoriAdi'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($kategori['KategoriAdi']) ?>
        </option>
    <?php endforeach; ?>
</select>
        <label>Firma:</label>
<select name="firma" required>
    <option value="">Seçiniz</option>
    <?php foreach ($firmalar as $firma): ?>
        <option value="<?= htmlspecialchars($firma['firma_adi']) ?>" 
            <?= isset($data['firma']) && $data['firma'] == $firma['firma_adi'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($firma['firma_adi']) ?>
        </option>
        
    <?php endforeach; ?>
</select>

        <label>Motor Açıklama:</label>
<input type="text" name="motor_aciklama" required value="<?= isset($data['motor_tanimi']) ? htmlspecialchars($data['motor_tanimi']) : '' ?>">


        <label>Açıklama:</label>
<select name="aciklama" disabled style="background:#eee; cursor:not-allowed;">
    <option value=""><?= isset($data['aciklama']) ? htmlspecialchars($data['aciklama']) : 'Seçiniz' ?></option>
</select>

 <label>Açıklama:</label>
<input type="text" name="aciklama_detay" required value="<?= isset($data['aciklama_detay']) ? htmlspecialchars($data['aciklama_detay']) : '' ?>">

        <label>Tamir Durumu:</label>
<select name="tamir_durumu">
    <option value="">Seçiniz</option>
    <?php foreach ($tamirDurumlari as $durum): ?>
        <option value="<?= htmlspecialchars($durum['durum']) ?>" 
            <?= isset($data['tamir_durumu']) && $data['tamir_durumu'] == $durum['durum'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($durum['durum']) ?>
        </option>
    <?php endforeach; ?>
</select>

        <!-- Expertiz Tarihi -->
<label>Expertiz Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="expertiz_tarihi" id="guncelle_expertiz_tarihi_input" value="<?= !empty($data['expertiz_tarihi']) ? htmlspecialchars($data['expertiz_tarihi']) : '' ?>">
    <button type="button" onclick="document.getElementById('guncelle_expertiz_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>

<!-- Teklif Tarihi -->
<label>Teklif Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="teklif_tarihi" id="guncelle_teklif_tarihi_input" value="<?= !empty($data['teklif_tarihi']) ? htmlspecialchars($data['teklif_tarihi']) : '' ?>">
    <button type="button" onclick="document.getElementById('guncelle_teklif_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>

<!-- Onay Tarihi -->
<label>Onay Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="onay_tarihi" id="guncelle_onay_tarihi_input" value="<?= !empty($data['onay_tarihi']) ? htmlspecialchars($data['onay_tarihi']) : '' ?>">
    <button type="button" onclick="document.getElementById('guncelle_onay_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>

<!-- Hazır Olma Tarihi -->
<label>Hazır Olma Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="hazir_olma_tarihi" id="guncelle_hazir_olma_tarihi_input" value="<?= !empty($data['hazir_olma_tarihi']) ? htmlspecialchars($data['hazir_olma_tarihi']) : '' ?>">
    <button type="button" onclick="document.getElementById('guncelle_hazir_olma_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>

<!-- Fatura Tarihi -->
<label>Fatura Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="fatura_tarihi" id="guncelle_fatura_tarihi_input" value="<?= !empty($data['fatura_tarihi']) ? htmlspecialchars($data['fatura_tarihi']) : '' ?>">
    <button type="button" onclick="document.getElementById('guncelle_fatura_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>

<!-- Teslim Tarihi -->
<label>Teslim Tarihi:</label>
<div style="display:flex;align-items:center;gap:4px;">
    <input type="date" name="teslim_tarihi" id="guncelle_teslim_tarihi_input" value="<?= !empty($data['teslim_tarihi']) ? htmlspecialchars($data['teslim_tarihi']) : '' ?>">
    <button type="button" onclick="document.getElementById('guncelle_teslim_tarihi_input').value='';" style="background:none;border:none;color:#e74c3c;font-size:18px;cursor:pointer;" title="Tarihi temizle">✕</button>
</div>
        
        <label>Gecikme Açıklaması:</label>
        <textarea name="gecikme_aciklamasi"><?= htmlspecialchars($data['gecikme_aciklamasi']) ?></textarea>

        <div class="action-bar">
            <div class="action-bar-inner">
                <button type="submit" name="sil" value="1" onclick="return confirm('Bu kaydı silmek istediğinize emin misiniz?');" class="btn-delete">Sil</button>
                <button type="submit" name="guncelle" class="btn-update">Güncelle</button>
                <button type="button" onclick="cancelEdit()" class="btn-cancel">İptal</button>
            </div>
        </div>
    </form>
    <?php endif; ?>
    </div>



</div>



<!-- Filtreleme kısmı -->

<div class="content">

    <form method="GET" style="margin-top: 40px; padding-top: 20px; border-top: 2px solid #e9ecef;"
    <label>Firma:</label>
<select name="firma" >
    <option value="">Tümü</option>
    <?php foreach ($firmalar as $firma): ?>
        <option value="<?= htmlspecialchars($firma['firma_adi']) ?>" 
            <?= isset($_GET['firma']) && $_GET['firma'] == $firma['firma_adi'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($firma['firma_adi']) ?>
        </option>
    <?php endforeach; ?>
</select>


<label>Kategori:</label>
<select name="Kategori">
    <option value="">Tümü</option>
    <?php foreach ($kategoriler as $kategori): ?>
        <option value="<?= htmlspecialchars($kategori['KategoriAdi']) ?>"
            <?= isset($_GET['Kategori']) && $_GET['Kategori'] == $kategori['KategoriAdi'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($kategori['KategoriAdi']) ?>
        </option>
    <?php endforeach; ?>
</select>


<label>Genel Arama:</label>
<input list="motorTanimlari" name="motor_tanimi" value="<?= isset($_GET['motor_tanimi']) ? htmlspecialchars($_GET['motor_tanimi']) : '' ?>" placeholder="Genel arama...">
<datalist id="motorTanimlari">
    <?php if (isset($motorTanimlari) && is_array($motorTanimlari)): ?>
        <?php foreach ($motorTanimlari as $motor): ?>
            <option value="<?= htmlspecialchars($motor['motor_tanimi']) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</datalist>

<label>Renk ile Filtrele:</label>
<select name="aciklama">
    <option value="">Tümü</option>
    <?php foreach ($renkler as $aciklamaKey => $renkKodu): ?>
        <option value="<?= htmlspecialchars($aciklamaKey) ?>"
            style="background-color: <?= $renkKodu ?>; color: <?= ($renkKodu == '#FFFFFF' ? '#000' : '#fff') ?>;"
            <?= isset($_GET['aciklama']) && $_GET['aciklama'] == $aciklamaKey ? 'selected' : '' ?>>
            <?= htmlspecialchars($aciklamaKey) ?>
        </option>
    <?php endforeach; ?>
</select>

        <button type="submit" style="background-color: #4CAF50; color: white; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; transition: 0.3s;">Filtrele</button>
      

<button type="button" id="temizle-btn" onclick="temizleFiltreler()" style="background-color: #f44336; color: white; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; transition: 0.3s;">Temizle</button>

   <form method="post" style="display:none;">
    <button type="submit" name="excel_export_xlsx" disabled style="display:none;">Excel</button>
</form>





<div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
    <form method="post" style="display:inline; margin: 0;">
        <input type="hidden" name="firma" value="<?= htmlspecialchars($_GET['firma'] ?? '') ?>">
        <input type="hidden" name="Kategori" value="<?= htmlspecialchars($_GET['Kategori'] ?? '') ?>">
        <input type="hidden" name="motor_tanimi" value="<?= htmlspecialchars($_GET['motor_tanimi'] ?? '') ?>">
        <input type="hidden" name="aciklama" value="<?= htmlspecialchars($_GET['aciklama'] ?? '') ?>">
        <button type="submit" name="excel_export_xlsx" style="background: linear-gradient(90deg, #43cea2 0%, #185a9d 100%); color: #fff; border: none; padding: 10px 24px; border-radius: 8px; font-size: 15px; font-weight: bold; box-shadow: 0 2px 8px rgba(24,90,157,0.15); cursor: pointer; transition: background 0.3s, transform 0.2s; display: flex; align-items: center; gap: 8px;">
            Excel
        </button>
    </form>
    
    <!-- Oturum Bilgisi Excel'in Yanında -->
    <div style="display: inline-flex; align-items: center; margin-left: 20px; padding: 8px 15px; background: linear-gradient(135deg, #43cea2, #185a9d); color: white; border-radius: 20px; font-size: 13px; font-weight: 500; box-shadow: 0 2px 8px rgba(24,90,157,0.15);">
        <span style="font-size: 14px; margin-right: 6px;">🕐</span>
        <span style="margin-right: 6px;">Oturum:</span>
        <span id="session-timer-excel" style="font-weight: 600; min-width: 40px;">15:00</span>
        <button onclick="extendSession()" title="Oturumu 10 dakika uzat" style="background: rgba(255,255,255,0.2); color: white; border: none; border-radius: 12px; padding: 3px 8px; font-size: 11px; cursor: pointer; margin-left: 8px; transition: all 0.2s;">
            +10dk
        </button>
    </div>
    
    <!-- Çıkış Butonu -->
    <a href="logout.php" style="display: inline-flex; align-items: center; margin-left: 15px; padding: 8px 15px; background: #dc3545; color: white; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 500; box-shadow: 0 2px 8px rgba(220,53,69,0.2); transition: all 0.2s;" title="Çıkış Yap">
        <span style="font-size: 14px; margin-right: 6px;">🚪</span>
        <span>Çıkış</span>
    </a>
    
    <!-- Kompakt İşi Bitenler Toggle -->
    <div style="display: inline-flex; align-items: center; margin-left: 15px; padding: 8px 12px; background: #f8f9fa; border-radius: 6px; border: 1px solid #dee2e6;">
        <span style="font-size: 12px; font-weight: 500; color: #495057; margin-right: 8px;">İşi Bitenler:</span>
        <label class="compact-toggle" style="position: relative; display: inline-block; width: 40px; height: 20px;">
            <input type="checkbox" id="completedToggle" <?= $show_completed == 'on' ? 'checked' : '' ?> onchange="toggleCompleted()" style="opacity: 0; width: 0; height: 0;">
            <span class="slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 20px; <?= $show_completed == 'on' ? 'background-color: #28a745;' : '' ?>">
                <span style="position: absolute; content: ''; height: 16px; width: 16px; left: <?= $show_completed == 'on' ? '22px' : '2px' ?>; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></span>
            </span>
        </label>
        <span style="font-size: 11px; color: #6c757d; margin-left: 8px;">
            <?= $show_completed == 'on' ? 'BİTENLERİ GİZLE' : 'BİTENLERİ GÖSTER' ?>
        </span>
    </div>
    <?php if (isset($_SESSION['user'])): ?>
    <?php endif; ?>
</div>



<div class="table-responsive">
    <table>
    <tr>
    <th class="id-column">ID</th>
<th>
    <a href="?<?= http_build_query(array_merge($_GET, [
        'sort' => 'gelis_tarihi',
        'order' => (isset($_GET['sort']) && $_GET['sort'] == 'gelis_tarihi' && isset($_GET['order']) && $_GET['order'] == 'desc') ? 'asc' : 'desc'
    ])) ?>" style="color: inherit; text-decoration: none;">
        Geliş Tarihi
        <?php if(isset($_GET['sort']) && $_GET['sort'] == 'gelis_tarihi'): ?>
            <?= (isset($_GET['order']) && strtolower($_GET['order']) == 'desc') ? '▼' : '▲' ?>
        <?php endif; ?>
    </a>
</th>    
<th>
    <a href="?<?= http_build_query(array_merge($_GET, [
        'sort' => 'Kategori',
        'order' => (isset($_GET['sort']) && $_GET['sort'] == 'Kategori' && (!isset($_GET['order']) || $_GET['order'] == 'asc') ? 'desc' : 'asc')
    ])) ?>" style="color: inherit; text-decoration: none;">
        Kategori
        <?php if(isset($_GET['sort']) && $_GET['sort'] == 'Kategori'): ?>
            <?= (isset($_GET['order']) && strtolower($_GET['order']) == 'desc') ? '▼' : '▲' ?>
        <?php endif; ?>
    </a>
</th><th>
    <a href="?<?= http_build_query(array_merge($_GET, [
        'sort' => 'firma',
        'order' => (isset($_GET['sort']) && $_GET['sort'] == 'firma' && (!isset($_GET['order']) || $_GET['order'] == 'asc') ? 'desc' : 'asc')
    ])) ?>" style="color: inherit; text-decoration: none;">
        Firma
        <?php if(isset($_GET['sort']) && $_GET['sort'] == 'firma'): ?>
            <?= (isset($_GET['order']) && strtolower($_GET['order']) == 'desc') ? '▼' : '▲' ?>
        <?php endif; ?>
    </a>
</th>   
<th>
    <a href="?<?= http_build_query(array_merge($_GET, [
        'sort' => 'motor_tanimi',
        'order' => (isset($_GET['sort']) && $_GET['sort'] == 'motor_tanimi' && (!isset($_GET['order']) || $_GET['order'] == 'asc') ? 'desc' : 'asc')
    ])) ?>" style="color: inherit; text-decoration: none;">
        Motor Açıklama
        <?php if(isset($_GET['sort']) && $_GET['sort'] == 'motor_tanimi'): ?>
            <?= (isset($_GET['order']) && strtolower($_GET['order']) == 'desc') ? '▼' : '▲' ?>
        <?php endif; ?>
    </a>
</th>
    <th>Açıklama Detay</th>
    <th>Tamir Durumu</th>
    <th>
        <a href="?<?= http_build_query(array_merge($_GET, [
            'sort' => 'expertiz_tarihi',
            'order' => (isset($_GET['sort']) && $_GET['sort'] == 'expertiz_tarihi' && isset($_GET['order']) && $_GET['order'] == 'desc') ? 'asc' : 'desc'
        ])) ?>" style="color: inherit; text-decoration: none;">
            Expertiz Tarihi
            <?php if(isset($_GET['sort']) && $_GET['sort'] == 'expertiz_tarihi'): ?>
                <?= (isset($_GET['order']) && strtolower($_GET['order']) == 'desc') ? '▼' : '▲' ?>
            <?php endif; ?>
        </a>
    </th>
    <th>
        <a href="?<?= http_build_query(array_merge($_GET, [
            'sort' => 'teklif_tarihi',
            'order' => (isset($_GET['sort']) && $_GET['sort'] == 'teklif_tarihi' && isset($_GET['order']) && $_GET['order'] == 'desc') ? 'asc' : 'desc'
        ])) ?>" style="color: inherit; text-decoration: none;">
            Teklif Tarihi
            <?php if(isset($_GET['sort']) && $_GET['sort'] == 'teklif_tarihi'): ?>
                <?= (isset($_GET['order']) && strtolower($_GET['order']) == 'desc') ? '▼' : '▲' ?>
            <?php endif; ?>
        </a>
    </th>
    <th>
        <a href="?<?= http_build_query(array_merge($_GET, [
            'sort' => 'onay_tarihi',
            'order' => (isset($_GET['sort']) && $_GET['sort'] == 'onay_tarihi' && isset($_GET['order']) && $_GET['order'] == 'desc') ? 'asc' : 'desc'
        ])) ?>" style="color: inherit; text-decoration: none;">
            Onay Tarihi
            <?php if(isset($_GET['sort']) && $_GET['sort'] == 'onay_tarihi'): ?>
                <?= (isset($_GET['order']) && strtolower($_GET['order']) == 'desc') ? '▼' : '▲' ?>
            <?php endif; ?>
        </a>
    </th>
    <th>
        <a href="?<?= http_build_query(array_merge($_GET, [
            'sort' => 'hazir_olma_tarihi',
            'order' => (isset($_GET['sort']) && $_GET['sort'] == 'hazir_olma_tarihi' && isset($_GET['order']) && $_GET['order'] == 'desc') ? 'asc' : 'desc'
        ])) ?>" style="color: inherit; text-decoration: none;">
            Hazır Olma Tarihi
            <?php if(isset($_GET['sort']) && $_GET['sort'] == 'hazir_olma_tarihi'): ?>
                <?= (isset($_GET['order']) && strtolower($_GET['order']) == 'desc') ? '▼' : '▲' ?>
            <?php endif; ?>
        </a>
    </th>
    <th>
        <a href="?<?= http_build_query(array_merge($_GET, [
            'sort' => 'fatura_tarihi',
            'order' => (isset($_GET['sort']) && $_GET['sort'] == 'fatura_tarihi' && isset($_GET['order']) && $_GET['order'] == 'desc') ? 'asc' : 'desc'
        ])) ?>" style="color: inherit; text-decoration: none;">
            Fatura Tarihi
            <?php if(isset($_GET['sort']) && $_GET['sort'] == 'fatura_tarihi'): ?>
                <?= (isset($_GET['order']) && strtolower($_GET['order']) == 'desc') ? '▼' : '▲' ?>
            <?php endif; ?>
        </a>
    </th>
    <th>
        <a href="?<?= http_build_query(array_merge($_GET, [
            'sort' => 'teslim_tarihi',
            'order' => (isset($_GET['sort']) && $_GET['sort'] == 'teslim_tarihi' && isset($_GET['order']) && $_GET['order'] == 'desc') ? 'asc' : 'desc'
        ])) ?>" style="color: inherit; text-decoration: none;">
            Teslim Tarihi
            <?php if(isset($_GET['sort']) && $_GET['sort'] == 'teslim_tarihi'): ?>
                <?= (isset($_GET['order']) && strtolower($_GET['order']) == 'desc') ? '▼' : '▲' ?>
            <?php endif; ?>
        </a>
    </th>
    <th>Gecikme Açıklaması</th>
    <th class="actions-column">İşlemler</th>
</tr>

   <?php foreach ($veriler as $veri): ?>
    <?php
        $aciklama = strtolower(trim($veri['aciklama']));
        $renk = isset($renkler[$aciklama]) ? $renkler[$aciklama] : "#ffffff";
    ?>
    <tr style="background-color: <?= $renk ?>;">
<td class="id-column" title="<?= htmlspecialchars($veri['id']) ?>">
    <?= !empty($veri['id']) ? htmlspecialchars($veri['id']) : '' ?>
</td>    <td title="<?= !empty($veri['gelis_tarihi']) && $veri['gelis_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['gelis_tarihi'])) : '' ?>">
    <?= !empty($veri['gelis_tarihi']) && $veri['gelis_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['gelis_tarihi'])) : '' ?>
</td>
<td title="<?= htmlspecialchars($veri['Kategori'] ?? '') ?>">
    <?= htmlspecialchars($veri['Kategori'] ?? '') ?>
</td>
<td title="<?= htmlspecialchars($veri['firma'] ?? '') ?>">
    <?= htmlspecialchars($veri['firma'] ?? '') ?>
</td>
<td title="<?= htmlspecialchars($veri['motor_tanimi'] ?? '') ?>">
    <?= htmlspecialchars($veri['motor_tanimi'] ?? '') ?>
</td>

<td title="<?= htmlspecialchars($veri['aciklama_detay'] ?? '') ?>">
    <?= htmlspecialchars($veri['aciklama_detay'] ?? '') ?>
</td>
<td title="<?= htmlspecialchars($veri['tamir_durumu'] ?? '') ?>">
    <?= htmlspecialchars($veri['tamir_durumu'] ?? '') ?>
</td>
<td title="<?= !empty($veri['expertiz_tarihi']) && $veri['expertiz_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['expertiz_tarihi'])) : '' ?>">
    <?= !empty($veri['expertiz_tarihi']) && $veri['expertiz_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['expertiz_tarihi'])) : '' ?>
</td>
<td title="<?= !empty($veri['teklif_tarihi']) && $veri['teklif_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['teklif_tarihi'])) : '' ?>">
    <?= !empty($veri['teklif_tarihi']) && $veri['teklif_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['teklif_tarihi'])) : '' ?>
</td>
<td title="<?= !empty($veri['onay_tarihi']) && $veri['onay_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['onay_tarihi'])) : '' ?>">
    <?= !empty($veri['onay_tarihi']) && $veri['onay_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['onay_tarihi'])) : '' ?>
</td>
<td title="<?= !empty($veri['hazir_olma_tarihi']) && $veri['hazir_olma_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['hazir_olma_tarihi'])) : '' ?>">
    <?= !empty($veri['hazir_olma_tarihi']) && $veri['hazir_olma_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['hazir_olma_tarihi'])) : '' ?>
</td>
<td title="<?= !empty($veri['fatura_tarihi']) && $veri['fatura_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['fatura_tarihi'])) : '' ?>">
    <?= !empty($veri['fatura_tarihi']) && $veri['fatura_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['fatura_tarihi'])) : '' ?>
</td>
<td title="<?= !empty($veri['teslim_tarihi']) && $veri['teslim_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['teslim_tarihi'])) : '' ?>">
    <?= !empty($veri['teslim_tarihi']) && $veri['teslim_tarihi'] != '0000-00-00' ? date('d-m-Y', strtotime($veri['teslim_tarihi'])) : '' ?>
</td>
<td title="<?= htmlspecialchars($veri['gecikme_aciklamasi'] ?? '') ?>">
    <?= htmlspecialchars($veri['gecikme_aciklamasi'] ?? '') ?>
</td>
    <td class="actions-column" style="text-align: center; vertical-align: middle;">
    <div style="display: flex; justify-content: center; align-items: center; height: 100%;">
        <?php
        // Mevcut filtreleri koruyarak düzenleme linki oluştur
        $editFilters = $_GET;
        $editFilters['id'] = $veri['id'];
        $editLink = '?' . http_build_query($editFilters);
        ?>
        <a href="<?= $editLink ?>" style="text-decoration: none;">
            <button type="button" style="background-color: #3498db; color: white; padding: 6px 12px; font-size: 13px; border: none; border-radius: 6px; cursor: pointer; transition: background-color 0.3s; display: flex; align-items: center; justify-content: center;">Düzenle</button>
        </a>
    </div>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>

    <!-- Sayfalama butonları -->

<?php
// Sayfalama için mevcut GET parametrelerini koru
$currentFilters = [];
if (!empty($firma_filter)) $currentFilters['firma'] = $firma_filter;
if (!empty($motor_tanimi_filter)) $currentFilters['motor_tanimi'] = $motor_tanimi_filter;
if (!empty($kategori_filter)) $currentFilters['Kategori'] = $kategori_filter;
if (!empty($aciklama_filter)) $currentFilters['aciklama'] = $aciklama_filter;
if (!empty($sort)) $currentFilters['sort'] = $sort;
if (!empty($order)) $currentFilters['order'] = $order;

// Sayfa parametrelerini oluştur
$firstPageParams = array_merge($currentFilters, ['page' => 1]);
$prevPageParams = array_merge($currentFilters, ['page' => $page - 1]);
$nextPageParams = array_merge($currentFilters, ['page' => $page + 1]);
$lastPageParams = array_merge($currentFilters, ['page' => $totalPages]);
?>

<div class="pagination">
    <a href="?<?= http_build_query($firstPageParams) ?>" class="<?= $page == 1 ? 'disabled' : '' ?>"><button type="button" <?= $page == 1 ? 'disabled' : '' ?>>İlk</button></a>
    <a href="?<?= http_build_query($prevPageParams) ?>" class="<?= $page == 1 ? 'disabled' : '' ?>"><button type="button" <?= $page == 1 ? 'disabled' : '' ?>>Geri</button></a>

    <?php
    // Kaç sayfa numarası gösterilecek
    $show = 2; // aktif sayfanın sağında ve solunda kaç sayfa gözüksün
    $start = max(1, $page - $show);
    $end = min($totalPages, $page + $show);

    if ($start > 1) echo '<span>...</span>';
    for ($i = $start; $i <= $end; $i++):
        $params = array_merge($currentFilters, ['page' => $i]);
    ?>
        <a href="?<?= http_build_query($params) ?>" class="<?= $i == $page ? 'disabled' : '' ?>">
            <button type="button" <?= $i == $page ? 'disabled' : '' ?> style="<?= $i == $page ? 'background:#185a9d;color:#fff;' : '' ?>">
                <?= $i ?>
            </button>
        </a>
    <?php endfor;
    if ($end < $totalPages) echo '<span>...</span>';
    ?>

    <a href="?<?= http_build_query($nextPageParams) ?>" class="<?= $page == $totalPages ? 'disabled' : '' ?>"><button type="button" <?= $page == $totalPages ? 'disabled' : '' ?>>İleri</button></a>
    <a href="?<?= http_build_query($lastPageParams) ?>" class="<?= $page == $totalPages ? 'disabled' : '' ?>"><button type="button" <?= $page == $totalPages ? 'disabled' : '' ?>>Son</button></a>
    <span>Sayfa: <?= $page ?> / <?= $totalPages ?></span>
</div>


</div>

</body>
</html>

<script>
// Otomatik sütun genişliği hesaplama
(function(){
    function measureTextWidth(text, font) {
        const canvas = measureTextWidth._canvas || (measureTextWidth._canvas = document.createElement('canvas'));
        const ctx = canvas.getContext('2d');
        ctx.font = font || getComputedStyle(document.body).font;
        return ctx.measureText(text).width;
    }

    function applyColGroup(table) {
        if (!table) return;
        const thead = table.querySelector('thead');
        const tbody = table.querySelector('tbody');
        // If no thead/tbody, operate on first row header and all body rows
        const headerRow = thead ? thead.querySelector('tr') : table.querySelector('tr');
        const bodyRows = Array.from(table.querySelectorAll('tr')).slice(thead?1:1);

        // If no rows found, skip
        if (!headerRow) return;

        const headers = Array.from(headerRow.children);
        const colCount = headers.length;

        // Compute max width per column (in px)
        const colWidths = new Array(colCount).fill(0);

        // Consider header text
        headers.forEach((th, i) => {
            const font = getComputedStyle(th).font || getComputedStyle(document.body).font;
            const txt = th.textContent.trim() || th.innerText || '';
            const w = Math.ceil(measureTextWidth(txt, font)) + 24; // padding allowance
            colWidths[i] = Math.max(colWidths[i], w);
        });

        // Consider body cells (first up to 200 rows to keep it fast)
        const maxRows = Math.min(200, table.querySelectorAll('tr').length);
        for (let r = 0; r < maxRows; r++) {
            const row = table.querySelectorAll('tr')[r + (thead?1:1)];
            if (!row) break;
            const cells = Array.from(row.children);
            for (let c = 0; c < Math.min(cells.length, colCount); c++) {
                const cell = cells[c];
                const font = getComputedStyle(cell).font || getComputedStyle(document.body).font;
                const txt = cell.getAttribute('title') || cell.textContent.trim() || cell.innerText || '';
                const w = Math.ceil(measureTextWidth(txt, font)) + 24;
                if (w > colWidths[c]) colWidths[c] = w;
            }
        }

        // Apply min/max caps and total width check
        const minCol = 50; // minimum px per column
        const maxCol = 800; // maximum px per column
        for (let i = 0; i < colWidths.length; i++) {
            colWidths[i] = Math.max(minCol, Math.min(maxCol, colWidths[i]));
        }

        // Remove existing colgroup if present
        const existing = table.querySelector('colgroup.autosize');
        if (existing) existing.remove();

        const colgroup = document.createElement('colgroup');
        colgroup.className = 'autosize';
        colWidths.forEach(w => {
            const col = document.createElement('col');
            col.style.width = w + 'px';
            colgroup.appendChild(col);
        });

        // Insert at the top of table
        table.insertBefore(colgroup, table.firstChild);
    }

    // Debounce helper
    function debounce(fn, wait){
        let t;
        return function(){
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, arguments), wait);
        };
    }

    function autosizeAllTables(){
        const tables = document.querySelectorAll('.table-responsive table');
        tables.forEach(table => applyColGroup(table));
    }

    // Run on DOM ready and on resize (debounced)
    document.addEventListener('DOMContentLoaded', function(){
        try { autosizeAllTables(); } catch(e){ console.warn('autosize error', e); }
    });
    window.addEventListener('resize', debounce(function(){
        try { autosizeAllTables(); } catch(e){ console.warn('autosize error', e); }
    }, 220));

})();
</script>

<script>
// Ensure action-bar visible fallback: if sticky action-bar is outside viewport, make it fixed over the sidebar
// Simple debounce implementation (used by multiple helpers)
function debounce(fn, wait){
    let t;
    return function(){
        const ctx = this, args = arguments;
        clearTimeout(t);
        t = setTimeout(function(){ fn.apply(ctx, args); }, wait || 100);
    };
}

function ensureActionBarVisible() {
    try {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        const actionBar = sidebar.querySelector('.action-bar');
        if (!actionBar) return;

        const rect = actionBar.getBoundingClientRect();
        const inView = rect.top < window.innerHeight && rect.bottom > 0;

        // If sidebar is currently open/active, always keep action-bar fixed and visible
        if (sidebar.classList.contains('active')) {
            actionBar.classList.add('action-bar-fixed');
            const srect = sidebar.getBoundingClientRect();
            actionBar.style.left = srect.left + 'px';
            actionBar.style.width = srect.width + 'px';
            actionBar.style.display = 'flex';
            actionBar.style.zIndex = '100000';
        } else if (!inView) {
            // apply fixed fallback when sidebar not active but action-bar offscreen
            actionBar.classList.add('action-bar-fixed');
            const srect = sidebar.getBoundingClientRect();
            actionBar.style.left = srect.left + 'px';
            actionBar.style.width = srect.width + 'px';
            actionBar.style.display = 'flex';
            actionBar.style.zIndex = '100000';
        } else {
            // remove fallback if present and sidebar not active
            if (actionBar.classList.contains('action-bar-fixed')) {
                actionBar.classList.remove('action-bar-fixed');
                actionBar.style.left = '';
                actionBar.style.width = '';
                actionBar.style.display = '';
                actionBar.style.zIndex = '';
            }
        }
    } catch (e) {
        console.warn('ensureActionBarVisible error', e);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Run on load
    setTimeout(ensureActionBarVisible, 120);

    // On resize, re-evaluate
    window.addEventListener('resize', function(){
        setTimeout(ensureActionBarVisible, 80);
    });

    // Observe sidebar class changes (open/close) to re-evaluate and keep action-bar fixed when sidebar open
    const sidebar = document.getElementById('sidebar');
    if (sidebar && 'MutationObserver' in window) {
        const mo = new MutationObserver(function(){
            // If sidebar is active/open, always make action-bar fixed and aligned to sidebar
            const actionBar = sidebar.querySelector('.action-bar');
            if (sidebar.classList.contains('active')) {
                if (actionBar) {
                    actionBar.classList.add('action-bar-fixed');
                    const srect = sidebar.getBoundingClientRect();
                    actionBar.style.left = srect.left + 'px';
                    actionBar.style.width = srect.width + 'px';
                    actionBar.style.display = 'flex';
                    actionBar.style.zIndex = '100000';
                }
            } else {
                if (actionBar && actionBar.classList.contains('action-bar-fixed')) {
                    actionBar.classList.remove('action-bar-fixed');
                    actionBar.style.left = '';
                    actionBar.style.width = '';
                    actionBar.style.display = '';
                    actionBar.style.zIndex = '';
                }
            }

            // also run visibility fallback just in case
            setTimeout(ensureActionBarVisible, 80);
        });
        mo.observe(sidebar, { attributes: true, attributeFilter: ['class', 'style'] });

        // Also handle scrolling inside sidebar
        sidebar.addEventListener('scroll', debounce(ensureActionBarVisible, 150));
    }
});

</script>
