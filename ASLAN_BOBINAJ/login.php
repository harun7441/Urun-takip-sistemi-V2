<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Hata mesajı için değişken
$error = '';
if (isset($_GET['timeout']) && $_GET['timeout'] == 1) {
    $error = 'Oturum süresi doldu, lütfen tekrar giriş yapınız.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_username = $_POST['username'] ?? '';
    $input_password = $_POST['password'] ?? '';

    // Veritabanı bağlantısı
    $host = 'localhost';
    $db_username = 'root';
    $db_password = '';
    $dbname = 'aslanbob_motortakip';
    



    $conn = new mysqli($host, $db_username, $db_password, $dbname);
    if ($conn->connect_error) {
        die('Veritabanı bağlantı hatası: ' . $conn->connect_error);
    }

    // SQL injection'a karşı güvenli sorgu
    $stmt = $conn->prepare('SELECT id FROM kullanicilar WHERE kullanici_adi = ? AND sifre = ?');
    $stmt->bind_param('ss', $input_username, $input_password);
    $stmt->execute();
    $stmt->store_result();
    // Kontrol: veritabanında eşleşen kullanıcı var mı?
    if ($stmt->num_rows === 1) {
        // Başarılı giriş: cookie set et ve yönlendir
        setcookie('logged_in_user', $input_username, time() + (24*60*60), '/');
        setcookie('login_time', time(), time() + (24*60*60), '/');
        $stmt->close();
        $conn->close();
        header('Location: index.php');
        exit();
    } else {
        // Başarısız giriş: hata mesajı göster
        $error = 'Kullanıcı adı veya şifre hatalı';
    }

    $stmt->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap</title>
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: Arial, sans-serif;
            background: #f2f2f2;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 40px 30px 32px 30px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.13);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .login-container h2 {
            text-align: center;
            font-size: 2.1rem;
            margin-bottom: 18px;
            margin-top: 0;
        }
        .login-container img {
            margin-bottom: 18px;
            display: block;
            max-width: 180px;
            max-height: 180px;
            width: auto;
            height: auto;
        }
        .login-container input[type="text"],
        .login-container input[type="password"] {
            width: 100%;
            padding: 15px 12px;
            margin: 10px 0 14px 0;
            font-size: 1.08rem;
            border: 1px solid #bbb;
            border-radius: 6px;
            box-sizing: border-box;
        }
        .login-container input[type="submit"] {
            width: 100%;
            padding: 13px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.13rem;
            margin-top: 6px;
            transition: background 0.2s;
        }
        .login-container input[type="submit"]:hover {
            background: #0056b3;
        }
        .error {
            color: #d8000c;
            background: #ffd2d2;
            border: 1px solid #d8000c;
            border-radius: 4px;
            padding: 8px 0;
            text-align: center;
            margin-bottom: 12px;
            width: 100%;
        }
        @media (max-width: 600px) {
            body {
                align-items: flex-start;
                padding-top: 20px;
            }
            .login-container {
                max-width: 98vw;
                padding: 12vw 2vw 10vw 2vw;
                border-radius: 8px;
            }
            .login-container h2 {
                font-size: 1.1rem;
            }
            .login-container img {
                max-width: 90px;
                max-height: 90px;
            }
            .login-container input[type="text"],
            .login-container input[type="password"],
            .login-container input[type="submit"] {
                font-size: 1em;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
    <img src="logo.png" alt="Logo" style="display:block;margin:0 auto 20px auto;max-width:180px;max-height:180px;">
        <h2>Giriş Yap</h2>
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <form method="post" action="">
            <input type="text" name="username" placeholder="Kullanıcı Adı" required>
            <input type="password" name="password" placeholder="Şifre" required>
            <input type="submit" value="Giriş Yap">
        </form>
    </div>
</body>
</html>
