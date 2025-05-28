<?php
// config/database.php
class Database {
    private $host = 'localhost';
    private $username = 'root';
    private $password = '';
    private $database = 'galeri_seni';
    private $conn;

    public function __construct() {
        $this->conn = new mysqli($this->host, $this->username, $this->password);
        
        if ($this->conn->connect_error) {
            die('Koneksi database gagal: ' . $this->conn->connect_error);
        }
        
        // Membuat database jika belum ada
        $this->conn->query("CREATE DATABASE IF NOT EXISTS {$this->database}");
        $this->conn->select_db($this->database);
        
        $this->createTables();
    }
    
    private function createTables() {
        // Tabel users
        $this->conn->query("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('seniman', 'pengunjung', 'admin') DEFAULT 'pengunjung',
            nama_lengkap VARCHAR(100),
            bio TEXT,
            avatar VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Tabel karya_seni
        $this->conn->query("CREATE TABLE IF NOT EXISTS karya_seni (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            judul VARCHAR(100) NOT NULL,
            deskripsi TEXT,
            kategori ENUM('2D', '3D', 'Animasi', 'Digital Art', 'Mixed Media') NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            harga DECIMAL(12,2) DEFAULT 0,
            status ENUM('tersedia', 'terjual') DEFAULT 'tersedia',
            views INT DEFAULT 0,
            likes INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        
        // Tabel pameran
        $this->conn->query("CREATE TABLE IF NOT EXISTS pameran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_pameran VARCHAR(100) NOT NULL,
            deskripsi TEXT,
            lokasi VARCHAR(100),
            tanggal_mulai DATE,
            tanggal_selesai DATE,
            gambar VARCHAR(255),
            status ENUM('aktif', 'selesai', 'akan_datang') DEFAULT 'akan_datang',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Tabel komentar
        $this->conn->query("CREATE TABLE IF NOT EXISTS komentar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            karya_id INT NOT NULL,
            user_id INT NOT NULL,
            komentar TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (karya_id) REFERENCES karya_seni(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        
        // Tabel transaksi
        $this->conn->query("CREATE TABLE IF NOT EXISTS transaksi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembeli_id INT NOT NULL,
            karya_id INT NOT NULL,
            harga DECIMAL(12,2) NOT NULL,
            status ENUM('pending', 'berhasil', 'gagal') DEFAULT 'pending',
            metode_pembayaran VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (pembeli_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (karya_id) REFERENCES karya_seni(id) ON DELETE CASCADE
        )");
        
        // Tabel likes
        $this->conn->query("CREATE TABLE IF NOT EXISTS likes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            karya_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_like (user_id, karya_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (karya_id) REFERENCES karya_seni(id) ON DELETE CASCADE
        )");
    }
    
    public function getConnection() {
        return $this->conn;
    }
}

// classes/User.php
class User {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function register($username, $email, $password, $role = 'pengunjung', $nama_lengkap = '') {
        // Validasi input
        if (empty($username) || empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Semua field harus diisi'];
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Format email tidak valid'];
        }
        
        // Cek apakah username atau email sudah ada
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return ['success' => false, 'message' => 'Username atau email sudah terdaftar'];
        }
        
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Insert user baru
        $stmt = $this->conn->prepare("INSERT INTO users (username, email, password, role, nama_lengkap) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $username, $email, $hashed_password, $role, $nama_lengkap);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Registrasi berhasil'];
        } else {
            return ['success' => false, 'message' => 'Registrasi gagal'];
        }
    }
    
    public function login($email, $password) {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                session_start();
                $_SESSION['user'] = $user;
                return ['success' => true, 'message' => 'Login berhasil', 'user' => $user];
            }
        }
        return ['success' => false, 'message' => 'Email atau password salah'];
    }
    
    public function logout() {
        session_start();
        session_destroy();
        return ['success' => true, 'message' => 'Logout berhasil'];
    }
    
    public function getUserById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
}

// classes/KaryaSeni.php
class KaryaSeni {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function uploadKarya($user_id, $judul, $deskripsi, $kategori, $file, $harga = 0) {
        // Validasi file
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowed_types)) {
            return ['success' => false, 'message' => 'Format file tidak didukung'];
        }
        
        // Validasi ukuran file (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Ukuran file terlalu besar (max 5MB)'];
        }
        
        // Upload file
        $upload_dir = 'uploads/karya/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $file_name = uniqid() . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            // Simpan ke database
            $stmt = $this->conn->prepare("INSERT INTO karya_seni (user_id, judul, deskripsi, kategori, file_path, harga) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssd", $user_id, $judul, $deskripsi, $kategori, $file_path, $harga);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Karya berhasil diunggah'];
            } else {
                unlink($file_path); // Hapus file jika gagal simpan ke database
                return ['success' => false, 'message' => 'Gagal menyimpan data karya'];
            }
        } else {
            return ['success' => false, 'message' => 'Gagal mengupload file'];
        }
    }
    
    public function getAllKarya($limit = 20, $offset = 0) {
        $stmt = $this->conn->prepare("
            SELECT k.*, u.username, u.nama_lengkap 
            FROM karya_seni k 
            JOIN users u ON k.user_id = u.id 
            ORDER BY k.created_at DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    public function getKaryaById($id) {
        // Update views
        $this->conn->query("UPDATE karya_seni SET views = views + 1 WHERE id = $id");
        
        $stmt = $this->conn->prepare("
            SELECT k.*, u.username, u.nama_lengkap, u.bio 
            FROM karya_seni k 
            JOIN users u ON k.user_id = u.id 
            WHERE k.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    public function getKaryaByUser($user_id) {
        $stmt = $this->conn->prepare("SELECT * FROM karya_seni WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    public function likeKarya($user_id, $karya_id) {
        // Cek apakah sudah di-like
        $stmt = $this->conn->prepare("SELECT id FROM likes WHERE user_id = ? AND karya_id = ?");
        $stmt->bind_param("ii", $user_id, $karya_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Unlike
            $stmt = $this->conn->prepare("DELETE FROM likes WHERE user_id = ? AND karya_id = ?");
            $stmt->bind_param("ii", $user_id, $karya_id);
            $stmt->execute();
            
            // Update counter
            $this->conn->query("UPDATE karya_seni SET likes = likes - 1 WHERE id = $karya_id");
            return ['success' => true, 'action' => 'unlike'];
        } else {
            // Like
            $stmt = $this->conn->prepare("INSERT INTO likes (user_id, karya_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $karya_id);
            $stmt->execute();
            
            // Update counter
            $this->conn->query("UPDATE karya_seni SET likes = likes + 1 WHERE id = $karya_id");
            return ['success' => true, 'action' => 'like'];
        }
    }
}

// classes/Komentar.php
class Komentar {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function addKomentar($karya_id, $user_id, $komentar) {
        $stmt = $this->conn->prepare("INSERT INTO komentar (karya_id, user_id, komentar) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $karya_id, $user_id, $komentar);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Komentar berhasil ditambahkan'];
        } else {
            return ['success' => false, 'message' => 'Gagal menambahkan komentar'];
        }
    }
    
    public function getKomentarByKarya($karya_id) {
        $stmt = $this->conn->prepare("
            SELECT k.*, u.username, u.nama_lengkap 
            FROM komentar k 
            JOIN users u ON k.user_id = u.id 
            WHERE k.karya_id = ? 
            ORDER BY k.created_at DESC
        ");
        $stmt->bind_param("i", $karya_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}

// classes/Pameran.php
class Pameran {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function createPameran($nama, $deskripsi, $lokasi, $tanggal_mulai, $tanggal_selesai, $gambar = '') {
        $stmt = $this->conn->prepare("INSERT INTO pameran (nama_pameran, deskripsi, lokasi, tanggal_mulai, tanggal_selesai, gambar) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $nama, $deskripsi, $lokasi, $tanggal_mulai, $tanggal_selesai, $gambar);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Pameran berhasil dibuat'];
        } else {
            return ['success' => false, 'message' => 'Gagal membuat pameran'];
        }
    }
    
    public function getAllPameran() {
        $result = $this->conn->query("SELECT * FROM pameran ORDER BY tanggal_mulai DESC");
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    public function getPameranById($id) {
        $stmt = $this->conn->prepare("SELECT * FROM pameran WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
}

// classes/Transaksi.php
class Transaksi {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function createTransaksi($pembeli_id, $karya_id, $harga, $metode_pembayaran) {
        // Cek apakah karya masih tersedia
        $stmt = $this->conn->prepare("SELECT status FROM karya_seni WHERE id = ?");
        $stmt->bind_param("i", $karya_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $karya = $result->fetch_assoc();
        
        if ($karya['status'] == 'terjual') {
            return ['success' => false, 'message' => 'Karya sudah terjual'];
        }
        
        // Buat transaksi
        $stmt = $this->conn->prepare("INSERT INTO transaksi (pembeli_id, karya_id, harga, metode_pembayaran) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iids", $pembeli_id, $karya_id, $harga, $metode_pembayaran);
        
        if ($stmt->execute()) {
            $transaksi_id = $this->conn->insert_id;
            
            // Update status karya
            $this->conn->query("UPDATE karya_seni SET status = 'terjual' WHERE id = $karya_id");
            
            return ['success' => true, 'message' => 'Transaksi berhasil dibuat', 'transaksi_id' => $transaksi_id];
        } else {
            return ['success' => false, 'message' => 'Gagal membuat transaksi'];
        }
    }
    
    public function getTransaksiByUser($user_id) {
        $stmt = $this->conn->prepare("
            SELECT t.*, k.judul, k.file_path, u.username as seniman 
            FROM transaksi t 
            JOIN karya_seni k ON t.karya_id = k.id 
            JOIN users u ON k.user_id = u.id 
            WHERE t.pembeli_id = ? 
            ORDER BY t.created_at DESC
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}

// Utility functions
function isLoggedIn() {
    session_start();
    return isset($_SESSION['user']);
}

function getCurrentUser() {
    session_start();
    return $_SESSION['user'] ?? null;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'Baru saja';
    if ($time < 3600) return floor($time/60) . ' menit yang lalu';
    if ($time < 86400) return floor($time/3600) . ' jam yang lalu';
    if ($time < 2592000) return floor($time/86400) . ' hari yang lalu';
    if ($time < 31104000) return floor($time/2592000) . ' bulan yang lalu';
    
    return floor($time/31104000) . ' tahun yang lalu';
}

// Initialize database and classes
$database = new Database();
$conn = $database->getConnection();

$userClass = new User($conn);
$karyaClass = new KaryaSeni($conn);
$komentarClass = new Komentar($conn);
$pameranClass = new Pameran($conn);
$transaksiClass = new Transaksi($conn);

// Insert sample data (run once)
function insertSampleData($conn) {
    // Sample admin user
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    $conn->query("INSERT IGNORE INTO users (username, email, password, role, nama_lengkap) VALUES 
        ('admin', 'admin@galeri.com', '$admin_password', 'admin', 'Administrator')");
    
    // Sample exhibitions
    $conn->query("INSERT IGNORE INTO pameran (nama_pameran, deskripsi, lokasi, tanggal_mulai, tanggal_selesai, status) VALUES 
        ('Pameran Seni Digital Modern', 'Pameran karya seni digital terbaru dari seniman Indonesia', 'Galeri Nasional', '2025-06-01', '2025-06-30', 'akan_datang'),
        ('Festival Kreativitas Digital', 'Festival tahunan seni digital dan teknologi', 'Jakarta Convention Center', '2025-07-15', '2025-07-20', 'akan_datang')");
}

// Uncomment to insert sample data (run once)
// insertSampleData($conn);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Galeri Seni Digital Interaktif</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0a0a1a, #1a1a2e);
            color: #fff; 
            line-height: 1.6;
        }

        /* Header & Navigasi */
        header {
            background: rgba(0, 0, 0, 0.9);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        nav {
            display: flex;
            justify-content: space-between;
            padding: 15px 40px;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }

        nav .logo {
            font-size: 1.8rem;
            font-weight: bold;
            color: #fff;
            text-decoration: none;
            background: linear-gradient(45deg, #6200ea, #b970ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        nav .menu {
            display: flex;
            align-items: center;
        }

        nav .menu a {
            color: #fff;
            margin-left: 25px;
            text-decoration: none;
            transition: all 0.3s;
            padding: 8px 16px;
            border-radius: 20px;
        }

        nav .menu a:hover {
            background: rgba(98, 0, 234, 0.2);
            color: #b970ff;
        }

        .btn-login {
            background: linear-gradient(45deg, #6200ea, #b970ff);
            color: white !important;
            padding: 10px 20px !important;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(98, 0, 234, 0.4);
        }

        /* Hero Section */
        .hero {
            background: url('https://cdn.pixabay.com/photo/2017/08/30/12/45/girl-2696947_1280.jpg');
            background-size: cover;
            background-position: center;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(98, 0, 234, 0.8), rgba(185, 112, 255, 0.6));
            z-index: 1;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 800px;
            padding: 20px;
            animation: fadeInUp 1s ease-out;
        }

        .hero-content h1 {
            font-size: 4rem;
            margin-bottom: 20px;
            text-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
            background: linear-gradient(45deg, #fff, #e1bee7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-content p {
            font-size: 1.3rem;
            margin-bottom: 30px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .btn-explore {
            background: linear-gradient(45deg, #6200ea, #b970ff);
            color: #fff;
            padding: 15px 35px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: bold;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-explore:hover {
            background: linear-gradient(45deg, #b970ff, #6200ea);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(98, 0, 234, 0.5);
        }

        /* Galeri Section */
        .galeri {
            padding: 100px 20px;
            background: linear-gradient(135deg, #0d0d25, #1a1a2e);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-title {
            text-align: center;
            font-size: 3rem;
            margin-bottom: 20px;
            position: relative;
            background: linear-gradient(45deg, #6200ea, #b970ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .section-subtitle {
            text-align: center;
            font-size: 1.2rem;
            color: #ccc;
            margin-bottom: 60px;
        }

        .galeri-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-bottom: 50px;
        }

        .karya-card {
            background: rgba(30, 30, 60, 0.6);
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .karya-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(98, 0, 234, 0.3);
            border-color: rgba(185, 112, 255, 0.5);
        }

        .karya-image {
            height: 250px;
            overflow: hidden;
            position: relative;
        }

        .karya-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }

        .karya-card:hover .karya-image img {
            transform: scale(1.1);
        }

        .karya-info {
            padding: 25px;
        }

        .karya-info h3 {
            font-size: 1.4rem;
            margin-bottom: 10px;
            color: #fff;
        }

        .karya-info .artist {
            color: #b970ff;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }

        .karya-info .stats {
            display: flex;
            justify-content: space-between;
            color: #aaa;
            font-size: 0.9rem;
        }

        /* Kategori Section */
        .kategori {
            padding: 100px 20px;
            background: rgba(10, 10, 26, 0.8);
        }

        .kategori-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .kategori-item {
            text-align: center;
            padding: 40px 20px;
            background: rgba(30, 30, 60, 0.4);
            border-radius: 15px;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .kategori-item:hover {
            background: rgba(98, 0, 234, 0.2);
            border-color: #6200ea;
            transform: translateY(-5px);
        }

        .kategori-icon {
            font-size: 3rem;
            margin-bottom: 20px;