<?php
/**
 * @file api/migration_cache.php
 *
 * Sistem klasifikasi SDG — Migrasi cache ke database (Anti-Timeout / AJAX Batch)
 *
 * File mandiri (bukan bagian dari sciecola/sangia-scieco/SDGs-analytics — tidak
 * memakai namespace, autoload, atau class dari repo manapun) yang dirancang
 * untuk berjalan berdampingan dengan api/SDG_Classification_API.php di
 * https://wizdam.sangia.org/, membaca cache .json.gz yang dihasilkannya di
 * api/cache/, dan memindahkannya ke database MySQL/MariaDB.
 *
 * Kenapa mandiri: cache ini hanya bisa "dibaca dengan benar" oleh format yang
 * dihasilkan SDG_Classification_API.php sendiri, dan API itu belum memakai
 * namespace/OOP — jadi file migrasi ini sengaja ditulis dengan gaya yang sama
 * (flat, prosedural, satu file) supaya bisa langsung diunggah ke folder api/
 * yang sama tanpa dependensi tambahan.
 *
 * Desain tabel mempertimbangkan (bukan memakai kode dari) tiga repo:
 *   - mokesano/sciecola        → publications/publication_authors/work_sdgs
 *   - mokesano/sangia-scieco   → institutions + researchers + publications
 *                                 dengan FK yang sama, plus pola "cache mentah
 *                                 disimpan sebagai JSON" (author_profiles_cache,
 *                                 analysis_history) yang diadaptasi di sini
 *                                 sebagai kolom raw_profile_json/raw_data_json.
 *   - mokesano/SDGs-analytics  → versi awal SDG_Classification_API.php yang
 *                                 sama persis (works.researcher_id,
 *                                 work_sdgs.sdg_code, kolom skor granular
 *                                 keyword/similarity/causal/impact terpisah).
 * Tujuannya supaya nanti gampang dikonsolidasikan ke sangia-apis, tanpa
 * mengharuskan wizdam.sangia.org bergantung pada kode repo manapun sekarang.
 *
 * Endpoint:
 *   GET ?action=page                                  -> panel kontrol HTML
 *   GET ?action=status&key=...                        -> jumlah file cache + tes koneksi DB
 *   GET ?action=run&key=...&phase=researchers&offset=0&limit=8
 *   GET ?action=run&key=...&phase=publications&offset=0&limit=8
 *   GET ?action=finalize&key=...                      -> agregasi researcher_sdg_expertise
 *
 * @author Rochmady and Wizdam Team
 * @version 1.0.0
 * @license MIT
 */

// ===================================================================
// KONFIGURASI — GANTI BAGIAN INI SEBELUM DIUNGGAH
// ===================================================================

// Sama persis dengan $CACHE_DIR di SDG_Classification_API.php — taruh file
// ini di folder api/ yang sama supaya otomatis menunjuk ke cache yang benar.
$CACHE_DIR = __DIR__ . '/cache';

$DB_CONFIG = [
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'GANTI_NAMA_DATABASE',
    'username' => 'GANTI_USERNAME',
    'password' => 'GANTI_PASSWORD',
    'charset'  => 'utf8mb4',
];

// Kunci rahasia untuk mengakses endpoint ini (bukan .env — langsung di sini
// sesuai gaya SDG_Classification_API.php). WAJIB diganti; endpoint menolak
// semua request selain ?action=page selama nilainya masih diawali "GANTI_".
define('MIGRATION_KEY', 'GANTI_DENGAN_STRING_RAHASIA_ANDA_SENDIRI');

define('BATCH_SIZE', 8); // jumlah file cache diproses per request AJAX
define('API_VERSION_DEFAULT', 'v5.2.0');

error_reporting(E_ALL & ~E_NOTICE);

// ===================================================================
// ROUTER
// ===================================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action      = isset($_GET['action']) ? $_GET['action'] : 'page';
$providedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';

if ($action !== 'page') {
    header('Content-Type: application/json; charset=utf-8');

    if (MIGRATION_KEY === '' || strpos(MIGRATION_KEY, 'GANTI_') === 0) {
        http_response_code(503);
        echo json_encode([
            'status'  => 'error',
            'message' => 'MIGRATION_KEY belum diganti dari nilai default di dalam file ini. Edit cache_to_db_batch.php dulu.',
        ]);
        exit;
    }
    if ($providedKey === '' || !hash_equals(MIGRATION_KEY, $providedKey)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Akses ditolak: key tidak valid.']);
        exit;
    }
}

if ($action === 'page') {
    // Selalu tampilkan panel, walau DB belum dikonfigurasi — status koneksi
    // dicek belakangan lewat tombol "CEK STATUS CACHE" (?action=status),
    // bukan saat memuat halaman.
    render_page($providedKey);
    exit;
}

try {
    $pdo = get_pdo($DB_CONFIG);
    ensure_schema($pdo);

    switch ($action) {
        case 'status':
            echo json_encode(action_status($pdo, $CACHE_DIR), JSON_UNESCAPED_UNICODE);
            break;

        case 'run':
            $phase  = isset($_GET['phase']) ? $_GET['phase'] : 'researchers';
            $offset = max(0, (int) (isset($_GET['offset']) ? $_GET['offset'] : 0));
            $limit  = min(50, max(1, (int) (isset($_GET['limit']) ? $_GET['limit'] : BATCH_SIZE)));
            echo json_encode(run_batch($pdo, $CACHE_DIR, $phase, $offset, $limit), JSON_UNESCAPED_UNICODE);
            break;

        case 'finalize':
            echo json_encode(finalize_sdg_expertise($pdo), JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => "Action tidak dikenali: {$action}"], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ===================================================================
// KONEKSI & SKEMA DATABASE (PDO polos, tanpa class)
// ===================================================================

function get_pdo(array $cfg): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset={$cfg['charset']}";
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/** Membuat tabel jika belum ada. Aman dipanggil berulang kali (idempotent). */
function ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS institutions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS researchers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            orcid VARCHAR(19) NOT NULL,
            name VARCHAR(255) NOT NULL,
            institution_id INT UNSIGNED NULL,
            raw_profile_json LONGTEXT NULL COMMENT 'payload cache orcid_init/orcid legacy penuh',
            cache_updated_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_orcid (orcid),
            INDEX idx_institution (institution_id),
            INDEX idx_name (name),
            CONSTRAINT fk_researchers_institution FOREIGN KEY (institution_id)
                REFERENCES institutions(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS publications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            doi VARCHAR(255) NOT NULL,
            title TEXT NOT NULL,
            abstract TEXT NULL,
            raw_data_json LONGTEXT NULL COMMENT 'work record penuh dari cache (sdgs, confidence, dll)',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_doi (doi)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS publication_authors (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            publication_id BIGINT UNSIGNED NOT NULL,
            orcid VARCHAR(19) NULL,
            author_name VARCHAR(255) NOT NULL,
            author_order INT UNSIGNED DEFAULT 1,
            is_corresponding TINYINT(1) DEFAULT 0,
            INDEX idx_orcid (orcid),
            INDEX idx_publication (publication_id),
            CONSTRAINT fk_pubauthors_publication FOREIGN KEY (publication_id)
                REFERENCES publications(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_sdgs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            publication_id BIGINT UNSIGNED NOT NULL,
            sdg_number TINYINT UNSIGNED NOT NULL,
            sdg_code VARCHAR(6) NOT NULL COMMENT 'contoh: SDG6',
            sdg_version VARCHAR(10) NOT NULL DEFAULT '" . API_VERSION_DEFAULT . "',
            confidence DECIMAL(6,3) NULL,
            contributor_type VARCHAR(50) NULL,
            keyword_score DECIMAL(6,3) NULL,
            similarity_score DECIMAL(6,3) NULL,
            substantive_score DECIMAL(6,3) NULL,
            causal_score DECIMAL(6,3) NULL,
            impact_score DECIMAL(6,3) NULL,
            classification_detail LONGTEXT NULL COMMENT 'blok detailed_analysis[SDGn] penuh',
            classified_at DATETIME NULL,
            UNIQUE KEY uniq_work_sdg (publication_id, sdg_number, sdg_version),
            INDEX idx_sdg_code (sdg_code),
            INDEX idx_contributor (contributor_type),
            CONSTRAINT fk_worksdgs_publication FOREIGN KEY (publication_id)
                REFERENCES publications(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS researcher_sdg_expertise (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            orcid VARCHAR(19) NOT NULL,
            sdg_number TINYINT UNSIGNED NOT NULL,
            expertise_level ENUM('beginner','intermediate','expert') NOT NULL,
            publications_count INT UNSIGNED DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_orcid_sdg (orcid, sdg_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cache_migration_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cache_file VARCHAR(255) NOT NULL,
            cache_type VARCHAR(30) NOT NULL,
            status VARCHAR(20) NOT NULL COMMENT 'ok|error|skipped',
            detail TEXT NULL,
            migrated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cache_file (cache_file)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/** INSERT ... ON DUPLICATE KEY UPDATE lalu SELECT id — dipakai untuk publications & researchers. */
function db_upsert_get_id(PDO $pdo, string $table, array $data, string $uniqueCol): int
{
    $cols        = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $colList     = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $updates     = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", array_diff($cols, [$uniqueCol])));

    $sql = "INSERT INTO `$table` ($colList) VALUES ($placeholders)";
    if ($updates !== '') {
        $sql .= " ON DUPLICATE KEY UPDATE $updates";
    } else {
        $sql .= " ON DUPLICATE KEY UPDATE `$uniqueCol` = `$uniqueCol`";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));

    $sel = $pdo->prepare("SELECT id FROM `$table` WHERE `$uniqueCol` = ? LIMIT 1");
    $sel->execute([$data[$uniqueCol]]);
    $row = $sel->fetch();
    return (int) $row['id'];
}

/** INSERT ... ON DUPLICATE KEY UPDATE tanpa perlu id balik (dipakai untuk work_sdgs, institutions). */
function db_upsert(PDO $pdo, string $table, array $data, array $conflictCols): void
{
    $cols        = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $colList     = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $updateCols  = array_diff($cols, $conflictCols);
    $updates     = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $updateCols));

    $sql = "INSERT INTO `$table` ($colList) VALUES ($placeholders)";
    $sql .= $updates !== ''
        ? " ON DUPLICATE KEY UPDATE $updates"
        : " ON DUPLICATE KEY UPDATE `{$conflictCols[0]}` = `{$conflictCols[0]}`";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
}

function db_fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ===================================================================
// PENCARIAN FILE CACHE (format sama persis dgn SDG_Classification_API.php)
// ===================================================================

function find_files(string $cacheDir, string $pattern): array
{
    $files = glob($cacheDir . '/' . $pattern) ?: [];
    sort($files);
    return $files;
}

/** orcid_*.json.gz yang BUKAN orcid_init_* atau orcid_batch_* (cache "full" lama). */
function find_legacy_orcid_files(string $cacheDir): array
{
    $all = find_files($cacheDir, 'orcid_*.json.gz');
    return array_values(array_filter($all, function (string $f): bool {
        $base = basename($f);
        return strpos($base, 'orcid_init_') !== 0 && strpos($base, 'orcid_batch_') !== 0;
    }));
}

function files_for_phase(string $cacheDir, string $phase): array
{
    if ($phase === 'researchers') {
        return array_merge(find_files($cacheDir, 'orcid_init_*.json.gz'), find_legacy_orcid_files($cacheDir));
    }
    if ($phase === 'publications') {
        return array_merge(
            find_files($cacheDir, 'orcid_batch_*.json.gz'),
            find_legacy_orcid_files($cacheDir),
            find_files($cacheDir, 'article_*.json.gz')
        );
    }
    return [];
}

function classify_file(string $path): string
{
    $base = basename($path);
    if (strpos($base, 'orcid_init_') === 0)  return 'orcid_init';
    if (strpos($base, 'orcid_batch_') === 0) return 'orcid_batch';
    if (strpos($base, 'article_') === 0)     return 'article';
    if (strpos($base, 'orcid_') === 0)       return 'orcid_legacy';
    return 'unknown';
}

function read_cache_file(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $json = @gzdecode($raw);
    if ($json === false) return null;
    $data = json_decode($json, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : null;
}

// ===================================================================
// STATUS
// ===================================================================

function action_status(PDO $pdo, string $cacheDir): array
{
    if (!is_dir($cacheDir)) {
        return ['status' => 'error', 'message' => 'Cache directory tidak ditemukan: ' . $cacheDir];
    }

    $counts = [
        'orcid_init'   => count(find_files($cacheDir, 'orcid_init_*.json.gz')),
        'orcid_batch'  => count(find_files($cacheDir, 'orcid_batch_*.json.gz')),
        'orcid_legacy' => count(find_legacy_orcid_files($cacheDir)),
        'article'      => count(find_files($cacheDir, 'article_*.json.gz')),
    ];
    $counts['total_files'] = array_sum($counts);

    $dbConnected = false;
    $dbError     = null;
    try {
        db_fetch_one($pdo, 'SELECT 1 AS ok');
        $dbConnected = true;
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }

    return [
        'status'                   => 'success',
        'cache_dir'                => $cacheDir,
        'file_counts'              => $counts,
        'researchers_phase_files'  => count(files_for_phase($cacheDir, 'researchers')),
        'publications_phase_files' => count(files_for_phase($cacheDir, 'publications')),
        'db_connected'             => $dbConnected,
        'db_error'                 => $dbError,
    ];
}

// ===================================================================
// BATCH RUNNER
// ===================================================================

function run_batch(PDO $pdo, string $cacheDir, string $phase, int $offset, int $limit): array
{
    if (!in_array($phase, ['researchers', 'publications'], true)) {
        throw new InvalidArgumentException("Phase tidak dikenali: {$phase}");
    }

    $files = files_for_phase($cacheDir, $phase);
    $total = count($files);
    $batch = array_slice($files, $offset, $limit);

    if (empty($batch)) {
        return [
            'status' => 'success', 'phase' => $phase, 'offset' => $offset, 'limit' => $limit,
            'processed' => 0, 'total' => $total, 'logs' => [],
            'is_done' => true, 'next_offset' => $offset,
        ];
    }

    $logs   = [];
    $counts = [
        'researchers' => 0, 'institutions' => 0,
        'publications' => 0, 'sdg_links' => 0, 'authors_linked' => 0,
        'skipped' => 0, 'errors' => 0,
    ];

    foreach ($batch as $file) {
        $base = basename($file);
        try {
            $pdo->beginTransaction();

            $payload = read_cache_file($file);
            if ($payload === null) {
                $counts['errors']++;
                $logs[] = "[SKIP] {$base} — file rusak atau tidak bisa didekompresi";
                log_cache_file($pdo, $base, classify_file($file), 'error', 'gagal decode gzip/json');
                $pdo->commit();
                continue;
            }

            $kind = classify_file($file);

            if ($phase === 'researchers') {
                $result = migrate_researcher_payload($pdo, $payload);
            } else {
                $result = migrate_publication_payload($pdo, $kind, $payload);
            }

            foreach ($result['counts'] as $k => $v) {
                $counts[$k] = (isset($counts[$k]) ? $counts[$k] : 0) + $v;
            }
            $logs[] = "[OK] {$base} — " . $result['summary'];
            log_cache_file($pdo, $base, $kind, 'ok', $result['summary']);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $counts['errors']++;
            $logs[] = "[ERROR] {$base} — " . $e->getMessage();
        }
    }

    $nextOffset = $offset + count($batch);
    $isDone     = $nextOffset >= $total;

    return [
        'status' => 'success', 'phase' => $phase, 'offset' => $offset, 'limit' => $limit,
        'processed' => count($batch), 'total' => $total, 'counts' => $counts, 'logs' => $logs,
        'is_done' => $isDone, 'next_offset' => $nextOffset,
    ];
}

function log_cache_file(PDO $pdo, string $filename, string $kind, string $status, string $detail): void
{
    db_upsert($pdo, 'cache_migration_log', [
        'cache_file'  => substr($filename, 0, 255),
        'cache_type'  => $kind,
        'status'      => $status,
        'detail'      => substr($detail, 0, 2000),
        'migrated_at' => date('Y-m-d H:i:s'),
    ], ['cache_file']);
}

// ===================================================================
// FASE PENELITI -> institutions, researchers
// ===================================================================

function migrate_researcher_payload(PDO $pdo, array $payload): array
{
    $info = isset($payload['personal_info']) ? $payload['personal_info'] : null;
    if (empty($info) || empty($info['orcid'])) {
        return ['counts' => ['skipped' => 1], 'summary' => 'tidak ada personal_info/orcid, dilewati'];
    }

    $orcid = trim((string) $info['orcid']);
    if (!preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/i', $orcid)) {
        return ['counts' => ['skipped' => 1], 'summary' => "format ORCID tidak valid: {$orcid}"];
    }

    $institutionId     = null;
    $institutionsAdded = 0;
    if (!empty($info['institutions']) && is_array($info['institutions'])) {
        foreach ($info['institutions'] as $instName) {
            $instName = trim((string) $instName);
            if ($instName === '') continue;
            $id = upsert_institution($pdo, $instName);
            if ($institutionId === null) $institutionId = $id;
            $institutionsAdded++;
        }
    }

    $name = trim((string) (isset($info['name']) ? $info['name'] : '')) ?: $orcid;

    db_upsert_get_id($pdo, 'researchers', [
        'orcid'            => $orcid,
        'name'             => substr($name, 0, 255),
        'institution_id'   => $institutionId,
        'raw_profile_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'cache_updated_at' => date('Y-m-d H:i:s'),
    ], 'orcid');

    return [
        'counts'  => ['researchers' => 1, 'institutions' => $institutionsAdded],
        'summary' => "researcher {$orcid} ({$name}) upserted",
    ];
}

function upsert_institution(PDO $pdo, string $name): int
{
    static $memo = [];
    $key = strtolower($name);
    if (isset($memo[$key])) return $memo[$key];

    $id = db_upsert_get_id($pdo, 'institutions', ['name' => substr($name, 0, 255)], 'name');
    $memo[$key] = $id;
    return $id;
}

function lookup_researcher_name(PDO $pdo, string $orcid): ?string
{
    static $memo = [];
    if (array_key_exists($orcid, $memo)) return $memo[$orcid];

    $row = db_fetch_one($pdo, 'SELECT name FROM researchers WHERE orcid = ? LIMIT 1', [$orcid]);
    $memo[$orcid] = $row ? $row['name'] : null;
    return $memo[$orcid];
}

// ===================================================================
// FASE PUBLIKASI -> publications, work_sdgs, publication_authors
// ===================================================================

function migrate_publication_payload(PDO $pdo, string $kind, array $payload): array
{
    $counts     = ['publications' => 0, 'sdg_links' => 0, 'authors_linked' => 0, 'skipped' => 0];
    $sdgVersion = extract_sdg_version($payload);

    if ($kind === 'article') {
        $pubId = migrate_publication_work($pdo, $payload, $sdgVersion, $counts);
        if ($pubId && !empty($payload['authors']) && is_array($payload['authors'])) {
            foreach (array_values($payload['authors']) as $i => $authorName) {
                link_publication_author($pdo, $pubId, null, (string) $authorName, $i + 1, $i === 0, $counts);
            }
        }
        return ['counts' => $counts, 'summary' => "artikel diproses, {$counts['sdg_links']} SDG"];
    }

    if ($kind === 'orcid_batch') {
        $orcid = isset($payload['orcid']) ? trim((string) $payload['orcid']) : null;
        $name  = $orcid ? lookup_researcher_name($pdo, $orcid) : null;
        $works = isset($payload['works']) ? $payload['works'] : [];
        foreach ($works as $work) {
            $pubId = migrate_publication_work($pdo, $work, $sdgVersion, $counts);
            if ($pubId && $orcid) {
                link_publication_author($pdo, $pubId, $orcid, $name, 1, false, $counts);
            }
        }
        return ['counts' => $counts, 'summary' => count($works) . " karya (orcid_batch, {$orcid})"];
    }

    if ($kind === 'orcid_legacy') {
        $orcid = isset($payload['personal_info']['orcid']) ? trim((string) $payload['personal_info']['orcid']) : null;
        $name  = trim((string) (isset($payload['personal_info']['name']) ? $payload['personal_info']['name'] : ''))
                 ?: ($orcid ? lookup_researcher_name($pdo, $orcid) : null);
        $works = isset($payload['works']) ? $payload['works'] : [];
        foreach ($works as $work) {
            $pubId = migrate_publication_work($pdo, $work, $sdgVersion, $counts);
            if ($pubId && $orcid) {
                link_publication_author($pdo, $pubId, $orcid, $name, 1, false, $counts);
            }
        }
        return ['counts' => $counts, 'summary' => count($works) . " karya (legacy, {$orcid})"];
    }

    return ['counts' => $counts, 'summary' => 'format cache tidak dikenali'];
}

function extract_sdg_version(array $payload): string
{
    $v = isset($payload['api_version']) ? $payload['api_version'] : API_VERSION_DEFAULT;
    return is_string($v) && $v !== '' ? $v : API_VERSION_DEFAULT;
}

function normalize_doi(string $doi): string
{
    return strtolower(trim($doi));
}

/** Upsert publications + work_sdgs untuk satu work. Return publication_id, atau null jika DOI kosong. */
function migrate_publication_work(PDO $pdo, array $work, string $sdgVersion, array &$counts): ?int
{
    $doi = normalize_doi((string) (isset($work['doi']) ? $work['doi'] : ''));
    if ($doi === '') {
        $counts['skipped']++;
        return null;
    }

    $title = isset($work['title']) ? $work['title'] : $doi;
    if (is_array($title)) $title = isset($title[0]) ? $title[0] : $doi;

    $pubId = db_upsert_get_id($pdo, 'publications', [
        'doi'           => substr($doi, 0, 255),
        'title'         => substr((string) $title, 0, 65535),
        'abstract'      => isset($work['abstract']) ? $work['abstract'] : null,
        'raw_data_json' => json_encode($work, JSON_UNESCAPED_UNICODE),
    ], 'doi');
    $counts['publications']++;

    $detailed = isset($work['detailed_analysis']) ? $work['detailed_analysis'] : [];
    if (is_array($detailed)) {
        foreach ($detailed as $sdgKey => $analysis) {
            $sdgNumber = (int) preg_replace('/[^0-9]/', '', (string) $sdgKey);
            if ($sdgNumber < 1 || $sdgNumber > 17) continue;

            $components = isset($analysis['components']) ? $analysis['components'] : [];

            db_upsert($pdo, 'work_sdgs', [
                'publication_id'         => $pubId,
                'sdg_number'              => $sdgNumber,
                'sdg_code'                => 'SDG' . $sdgNumber,
                'sdg_version'             => substr($sdgVersion, 0, 10),
                'confidence'              => isset($analysis['score']) ? $analysis['score'] : null,
                'contributor_type'        => isset($analysis['contributor_type']['type']) ? $analysis['contributor_type']['type'] : null,
                'keyword_score'           => isset($components['keyword_score']) ? $components['keyword_score'] : null,
                'similarity_score'        => isset($components['similarity_score']) ? $components['similarity_score'] : null,
                'substantive_score'       => isset($components['substantive_score']) ? $components['substantive_score'] : null,
                'causal_score'            => isset($components['causal_score']) ? $components['causal_score'] : null,
                'impact_score'            => isset($components['impact_score']) ? $components['impact_score'] : null,
                'classification_detail'   => json_encode($analysis, JSON_UNESCAPED_UNICODE),
                'classified_at'           => date('Y-m-d H:i:s'),
            ], ['publication_id', 'sdg_number', 'sdg_version']);
            $counts['sdg_links']++;
        }
    }

    return $pubId;
}

/** publication_authors tidak punya unique key selain id, jadi dedupe manual di sini. */
function link_publication_author(
    PDO $pdo,
    int $publicationId,
    ?string $orcid,
    ?string $name,
    int $order,
    bool $isCorresponding,
    array &$counts
): void {
    if ($orcid) {
        $existing = db_fetch_one(
            $pdo,
            'SELECT id FROM publication_authors WHERE publication_id = ? AND orcid = ? LIMIT 1',
            [$publicationId, $orcid]
        );
    } else {
        $existing = db_fetch_one(
            $pdo,
            'SELECT id FROM publication_authors WHERE publication_id = ? AND author_name = ? LIMIT 1',
            [$publicationId, (string) $name]
        );
    }
    if ($existing) return;

    $stmt = $pdo->prepare("
        INSERT INTO publication_authors (publication_id, orcid, author_name, author_order, is_corresponding)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $publicationId,
        $orcid,
        substr((string) ($name ?: $orcid ?: 'Unknown'), 0, 255),
        max(1, $order),
        $isCorresponding ? 1 : 0,
    ]);
    $counts['authors_linked']++;
}

// ===================================================================
// FINALIZE — agregasi work_sdgs -> researcher_sdg_expertise
// (mencerminkan apa yang dihitung ?action=summary di SDG_Classification_API.php)
// ===================================================================

function finalize_sdg_expertise(PDO $pdo): array
{
    $sql = "
        INSERT INTO researcher_sdg_expertise (orcid, sdg_number, expertise_level, publications_count)
        SELECT
            pa.orcid,
            ws.sdg_number,
            CASE
                WHEN AVG(ws.confidence) >= 0.60 THEN 'expert'
                WHEN AVG(ws.confidence) >= 0.35 THEN 'intermediate'
                ELSE 'beginner'
            END AS expertise_level,
            COUNT(DISTINCT ws.publication_id) AS publications_count
        FROM work_sdgs ws
        INNER JOIN publication_authors pa ON pa.publication_id = ws.publication_id
        WHERE pa.orcid IS NOT NULL
        GROUP BY pa.orcid, ws.sdg_number
        ON DUPLICATE KEY UPDATE
            expertise_level    = VALUES(expertise_level),
            publications_count = VALUES(publications_count),
            updated_at         = NOW()
    ";

    $stmt = $pdo->query($sql);

    return [
        'status'        => 'success',
        'message'       => 'researcher_sdg_expertise diperbarui dari work_sdgs + publication_authors',
        'rows_affected' => $stmt->rowCount(),
    ];
}

// ===================================================================
// PANEL KONTROL HTML
// ===================================================================

function render_page(string $key): void
{
    header('Content-Type: text/html; charset=utf-8');
    $keyAttr = htmlspecialchars($key, ENT_QUOTES);
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Wizdam — Cache to DB Migrator</title>
<style>
body{font-family:monospace;background:#1a1a1a;color:#ddd;padding:20px}
.container{max-width:960px;margin:0 auto;background:#2d2d2d;padding:20px;border:1px solid #444;border-radius:8px}
input[type=text]{background:#111;border:1px solid #555;color:#ddd;padding:8px;width:320px;border-radius:4px}
button{background:#ff4757;color:#fff;border:none;padding:12px 24px;cursor:pointer;font-size:16px;font-weight:bold;border-radius:4px;margin-top:10px}
button:hover{background:#ff6b81}
button:disabled{background:#555;cursor:not-allowed}
.log-box{height:420px;overflow-y:auto;background:#000;border:1px solid #555;margin-top:15px;padding:10px;font-size:13px;line-height:1.5;border-radius:4px}
.ok{color:#2ed573;display:block;border-bottom:1px dashed #333;padding-bottom:2px}
.err{color:#ff4757;display:block}
.phase{color:#1e90ff;font-weight:bold;margin-top:10px;display:block}
#status{margin-top:15px;font-size:1.1em;font-weight:bold}
</style>
</head>
<body>
<div class="container">
  <h2>🗄️ Wizdam — Cache to Database Migrator</h2>
  <p>Memindahkan cache SDG_Classification_API.php (orcid_init / orcid_batch / orcid legacy / article) di <code>api/cache/</code> ke database, bertahap dan anti-timeout.</p>

  <div>
    <label>Migration key: </label>
    <input type="text" id="key" value="<?php echo $keyAttr; ?>" placeholder="isi MIGRATION_KEY dari file ini">
    <br>
    <button onclick="checkStatus()">CEK STATUS CACHE</button>
    <button onclick="start()" id="btnStart">MULAI MIGRASI</button>
  </div>

  <div id="status">Status: menunggu instruksi...</div>
  <div class="log-box" id="logs"></div>
</div>

<script>
function key() { return document.getElementById('key').value.trim(); }

function checkStatus() {
    setStatus('Mengecek cache & koneksi database...', '#1e90ff');
    fetch('?action=status&key=' + encodeURIComponent(key()))
        .then(r => r.json())
        .then(d => {
            if (d.status !== 'success') { log('[ERROR] ' + d.message, 'err'); return; }
            log('<strong>Cache:</strong> ' + JSON.stringify(d.file_counts));
            log('<strong>DB connected:</strong> ' + d.db_connected + (d.db_error ? (' — ' + d.db_error) : ''));
            setStatus('Status OK. Siap migrasi.', '#2ed573');
        })
        .catch(e => log('[ERROR] ' + e.message, 'err'));
}

function start() {
    document.getElementById('btnStart').disabled = true;
    runPhase('researchers', 0);
}

function runPhase(phase, offset) {
    setStatus('Fase "' + phase + '" — offset ' + offset + ' ...', '#1e90ff');
    fetch('?action=run&key=' + encodeURIComponent(key()) + '&phase=' + phase + '&offset=' + offset)
        .then(r => r.text())
        .then(t => { try { return JSON.parse(t); } catch (e) { throw new Error('Respon bukan JSON (kemungkinan timeout): ' + t.substring(0, 300)); } })
        .then(d => {
            if (d.status === 'error') { log('[ERROR] ' + d.message, 'err'); return; }
            if (d.processed > 0) {
                log('<span class="phase">▶ ' + phase + ' [' + d.offset + '-' + (d.offset + d.processed) + ' / ' + d.total + ']</span>');
                (d.logs || []).forEach(l => log(l));
            }
            if (!d.is_done) {
                setTimeout(() => runPhase(phase, d.next_offset), 400);
            } else if (phase === 'researchers') {
                runPhase('publications', 0);
            } else {
                finalize();
            }
        })
        .catch(e => log('[ERROR] ' + e.message, 'err'));
}

function finalize() {
    setStatus('Merangkum SDG expertise per peneliti...', '#1e90ff');
    fetch('?action=finalize&key=' + encodeURIComponent(key()))
        .then(r => r.json())
        .then(d => {
            log('<strong>' + (d.message || d.status) + '</strong> (' + (d.rows_affected ?? 0) + ' baris)');
            setStatus('🔥 MIGRASI SELESAI.', '#2ed573');
            document.getElementById('btnStart').disabled = false;
        })
        .catch(e => log('[ERROR] ' + e.message, 'err'));
}

function log(html, cls) {
    const div = document.createElement('div');
    div.className = cls || 'ok';
    div.innerHTML = html;
    const box = document.getElementById('logs');
    box.appendChild(div);
    box.scrollTop = box.scrollHeight;
}

function setStatus(text, color) {
    const el = document.getElementById('status');
    el.innerText = 'Status: ' + text;
    el.style.color = color;
}
</script>
</body>
</html>
    <?php
}