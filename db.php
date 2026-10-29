<?php
/**
 * db.php
 * Koneksi database menggunakan PDO
 */

require_once __DIR__ . '/config.php';

/**
 * Mengembalikan instance PDO (singleton pattern)
 * @return PDO
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Di production, jangan tampilkan detail error
            die(json_encode(['error' => 'Koneksi database gagal: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

/**
 * Menjalankan query SELECT dan mengembalikan semua baris
 * @param string $sql  Query SQL dengan named placeholder
 * @param array  $params Parameter binding
 * @return array
 */
function db_query(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Menjalankan query SELECT dan mengembalikan satu baris
 */
function db_row(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Menjalankan query INSERT/UPDATE/DELETE
 * @return int Last insert ID atau jumlah baris yang terpengaruh
 */
function db_execute(string $sql, array $params = []): int {
    $pdo  = db();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    // Kembalikan last insert id jika INSERT, else rowCount
    return (stripos(trim($sql), 'INSERT') === 0)
        ? (int) $pdo->lastInsertId()
        : $stmt->rowCount();
}

/**
 * Jalankan migrasi otomatis yang dibutuhkan (idempotent / aman dijalankan berulang)
 */
function run_auto_migrations(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    // v5: tambah super_admin ke ENUM role &mdash; cek dulu apakah sudah ada
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($col && strpos($col['Type'], 'super_admin') === false) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','admin','wali_kelas','guru','siswa','kepala_sekolah') NOT NULL DEFAULT 'siswa'");
    }

    // v5: buat tabel raport_publikasi jika belum ada
    $pdo->exec("CREATE TABLE IF NOT EXISTS raport_publikasi (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        kelas_id        INT NOT NULL,
        tahun_ajaran_id INT NOT NULL,
        jenis_raport    ENUM('bulanan','sts','semester') NOT NULL,
        bulan           TINYINT DEFAULT NULL,
        is_published    TINYINT(1) NOT NULL DEFAULT 0,
        published_by    INT DEFAULT NULL,
        published_at    DATETIME DEFAULT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_pub (kelas_id, tahun_ajaran_id, jenis_raport, bulan),
        FOREIGN KEY (kelas_id) REFERENCES kelas(id) ON DELETE CASCADE,
        FOREIGN KEY (tahun_ajaran_id) REFERENCES tahun_ajaran(id) ON DELETE CASCADE,
        FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // v5: buat akun superadmin default jika belum ada
    $exists = $pdo->query("SELECT COUNT(*) FROM users WHERE username='superadmin' OR role='super_admin'")->fetchColumn();
    if (!$exists) {
        // password = "password" (hash bcrypt)
        $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        $pdo->prepare("INSERT INTO users (username,password,nama_lengkap,role,is_active) VALUES (?,?,?,?,1)")
            ->execute(['superadmin', $hash, 'Super Administrator', 'super_admin']);
    }
}

/**
 * Ambil info sekolah (singleton, dari tabel sekolah_info)
 */
function sekolah_info(): array {
    static $info = null;
    if ($info === null) {
        $info = db_row('SELECT * FROM sekolah_info ORDER BY id LIMIT 1') ?? [
            'nama_yayasan'   => '',
            'nama_sekolah'   => 'NAMA SEKOLAH',
            'alamat'         => '',
            'telp'           => '',
            'website'        => '',
            'email'          => '',
            'npsn'           => '',
            'kota'           => '',
            'logo'           => null,
            'kriteria_a_min' => 92,
            'kriteria_b_min' => 83,
            'kriteria_c_min' => 76,
        ];
    }
    return $info;
}
