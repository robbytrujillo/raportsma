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
