<?php
/**
 * @file public/cache_to_db_batch.php
 *
 * Sistem klasifikasi SDG — Migrasi cache ke database (Anti-Timeout / AJAX Batch)
 *
 * File sementara: dijalankan sekali untuk memindahkan seluruh cache yang sudah
 * menumpuk di api/cache/ (hasil api/SDG_Classification_API.php) ke database
 * sangia_ecosystem, lalu boleh dihapus. Taruh di public/ karena hanya folder
 * ini yang bisa diakses dari luar di struktur sdg-mono — api/ sengaja tidak
 * bisa diakses langsung (dikonfirmasi 404).
 *
 * Mandiri: tidak ada namespace/autoload/class dari sciecola atau repo lain,
 * murni PDO. Struktur tabel disalin persis dari db/schema.sql milik
 * mokesano/sciecola (pemilik kanonik skema sangia_ecosystem — dokumen itu
 * sendiri mendaftarkan sdg-mono sebagai salah satu pemakainya), supaya nanti
 * gampang dikonsolidasikan ke sangia-apis tanpa perlu migrasi ulang.
 *
 * Endpoint:
 *   GET ?action=page                          -> panel kontrol HTML
 *   GET ?action=status                        -> jumlah file cache + tes koneksi DB
 *   GET ?action=run&phase=researchers&offset=0&limit=8
 *   GET ?action=run&phase=publications&offset=0&limit=8
 *   GET ?action=finalize                      -> ringkasan SDG per peneliti -> analytics_snapshots
 *
 * @author Rochmady and Wizdam Team
 * @version 1.0.0
 * @license MIT
 */

// ===================================================================
// KONFIGURASI — GANTI BAGIAN INI SEBELUM DIUNGGAH
// ===================================================================

// public/ dan api/ adalah folder bertetangga langsung di bawah root aplikasi,
// jadi dari public/cache_to_db_batch.php naik satu level lalu masuk api/cache.
$CACHE_DIR = dirname(__DIR__) . '/api/cache';

$DB_CONFIG = [
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'sangia_ecosystem',
    'username' => 'GANTI_USERNAME',
    'password' => 'GANTI_PASSWORD',
    'charset'  => 'utf8mb4',
];

define('BATCH_SIZE', 8); // jumlah file cache diproses per request AJAX
define('SDG_VERSION_DEFAULT', 'v5.2.0');

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

$action = isset($_GET['action']) ? $_GET['action'] : 'page';

if ($action === 'page') {
    render_page();
    exit;
}

header('Content-Type: application/json; charset=utf-8');

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
            echo json_encode(finalize_sdg_snapshots($pdo), JSON_UNESCAPED_UNICODE);
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

/**
 * Membuat tabel jika belum ada, disalin persis dari db/schema.sql milik
 * mokesano/sciecola (pemilik kanonik sangia_ecosystem). Aman dipanggil
 * berulang (idempotent) — kalau skema resmi sudah pernah dijalankan lebih
 * dulu di database ini, semua CREATE TABLE IF NOT EXISTS di sini jadi no-op.
 */
function ensure_schema(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS institutions (
          id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
          name              VARCHAR(255)    NOT NULL,
          acronym           VARCHAR(50)     NULL,
          country           VARCHAR(100)    NULL,
          city              VARCHAR(100)    NULL,
          website_url       VARCHAR(512)    NULL,
          ror_id            VARCHAR(100)    NULL UNIQUE,
          scopus_affil_id   VARCHAR(50)     NULL UNIQUE,
          created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_country (country),
          INDEX idx_name    (name(100))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journals (
          id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
          title             VARCHAR(512)    NOT NULL,
          issn              VARCHAR(10)     NULL,
          e_issn            VARCHAR(10)     NULL,
          publisher         VARCHAR(255)    NULL,
          country           VARCHAR(100)    NULL,
          sjr_score         DECIMAL(10,4)   NULL,
          snip_score        DECIMAL(10,4)   NULL,
          cite_score        DECIMAL(10,4)   NULL,
          h_index           SMALLINT UNSIGNED NULL,
          quartile          TINYINT UNSIGNED  NULL,
          sinta_score       DECIMAL(10,4)   NULL,
          sinta_grade       VARCHAR(5)      NULL,
          scopus_source_id  VARCHAR(50)     NULL,
          created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_issn (issn),
          INDEX idx_title    (title(100))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS researchers (
          orcid               VARCHAR(20)     NOT NULL PRIMARY KEY,
          name                VARCHAR(255)    NOT NULL,
          given_names         VARCHAR(150)    NULL,
          family_name         VARCHAR(150)    NULL,
          email               VARCHAR(255)    NULL,
          institution_id      INT UNSIGNED    NULL,
          scopus_id           VARCHAR(50)     NULL,
          researcher_id       VARCHAR(50)     NULL,
          h_index             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          citation_count      INT UNSIGNED    NOT NULL DEFAULT 0,
          sinta_id            VARCHAR(50)     NULL,
          country             VARCHAR(100)    NULL,
          profile_cache_json  LONGTEXT        NULL,
          cache_expires_at    TIMESTAMP       NULL,
          created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          FOREIGN KEY fk_researchers_institution (institution_id) REFERENCES institutions(id) ON DELETE SET NULL,
          INDEX idx_name        (name(100)),
          INDEX idx_scopus      (scopus_id),
          INDEX idx_institution (institution_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS publications (
          doi               VARCHAR(512)    NOT NULL PRIMARY KEY,
          title             VARCHAR(1024)   NOT NULL,
          abstract          LONGTEXT        NULL,
          publication_year  SMALLINT UNSIGNED NULL,
          type              VARCHAR(50)     NULL,
          journal_id        INT UNSIGNED    NULL,
          volume            VARCHAR(20)     NULL,
          issue             VARCHAR(20)     NULL,
          pages             VARCHAR(50)     NULL,
          publisher         VARCHAR(255)    NULL,
          citation_count    INT UNSIGNED    NOT NULL DEFAULT 0,
          citation_sources  JSON            NULL,
          sdg_cache_json    LONGTEXT        NULL,
          raw_data_json     LONGTEXT        NULL,
          created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          FOREIGN KEY fk_publications_journal (journal_id) REFERENCES journals(id) ON DELETE SET NULL,
          INDEX idx_year    (publication_year),
          INDEX idx_journal (journal_id),
          FULLTEXT INDEX ft_title (title)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS publication_authors (
          id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
          doi               VARCHAR(512)    NOT NULL,
          orcid             VARCHAR(20)     NULL,
          name              VARCHAR(255)    NOT NULL,
          given_names       VARCHAR(150)    NULL,
          family_name       VARCHAR(150)    NULL,
          sequence          TINYINT UNSIGNED NOT NULL DEFAULT 1,
          is_corresponding  TINYINT(1)      NOT NULL DEFAULT 0,
          FOREIGN KEY fk_pa_doi   (doi)   REFERENCES publications(doi)  ON DELETE CASCADE,
          FOREIGN KEY fk_pa_orcid (orcid) REFERENCES researchers(orcid) ON DELETE SET NULL,
          INDEX idx_doi   (doi(191)),
          INDEX idx_orcid (orcid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS work_sdgs (
          id                    INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
          doi                   VARCHAR(512)    NOT NULL,
          sdg_number            TINYINT UNSIGNED NOT NULL,
          sdg_version           VARCHAR(10)     NOT NULL DEFAULT 'v5',
          confidence            DECIMAL(5,4)    NULL,
          classification_detail JSON            NULL,
          classified_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_doi_sdg_ver (doi, sdg_number, sdg_version),
          FOREIGN KEY fk_work_sdgs_doi (doi) REFERENCES publications(doi) ON DELETE CASCADE,
          INDEX idx_sdg (sdg_number),
          INDEX idx_doi (doi(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ecosystem_cache (
          cache_key   VARCHAR(512)    NOT NULL PRIMARY KEY,
          payload     LONGTEXT        NOT NULL,
          expires_at  TIMESTAMP       NOT NULL,
          created_by  VARCHAR(50)     NULL,
          created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS analytics_snapshots (
          id            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
          snapshot_type VARCHAR(50)     NOT NULL,
          entity_type   VARCHAR(30)     NOT NULL,
          entity_id     VARCHAR(255)    NOT NULL,
          period        VARCHAR(20)     NULL,
          data_json     LONGTEXT        NOT NULL,
          computed_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_type_entity (snapshot_type, entity_type, entity_id(100)),
          INDEX idx_period      (period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function db_upsert(PDO $pdo, string $table, array $data, array $conflictCols): void
{
    $cols         = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $colList      = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $updateCols   = array_diff($cols, $conflictCols);
    $updates      = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $updateCols));

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

function db_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
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

    db_upsert($pdo, 'researchers', [
        'orcid'              => $orcid,
        'name'               => substr($name, 0, 255),
        'institution_id'     => $institutionId,
        'profile_cache_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'cache_expires_at'   => date('Y-m-d H:i:s', time() + 7 * 86400),
    ], ['orcid']);

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

    $existing = db_fetch_one($pdo, 'SELECT id FROM institutions WHERE name = ? LIMIT 1', [$name]);
    if ($existing) {
        $memo[$key] = (int) $existing['id'];
        return $memo[$key];
    }

    $stmt = $pdo->prepare('INSERT INTO institutions (name) VALUES (?)');
    $stmt->execute([substr($name, 0, 255)]);
    $id = (int) $pdo->lastInsertId();
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
        $doi = migrate_publication_work($pdo, $payload, $sdgVersion, $counts);
        if ($doi && !empty($payload['authors']) && is_array($payload['authors'])) {
            foreach (array_values($payload['authors']) as $i => $authorName) {
                link_publication_author($pdo, $doi, null, (string) $authorName, $i + 1, $i === 0, $counts);
            }
        }
        return ['counts' => $counts, 'summary' => "artikel diproses, {$counts['sdg_links']} SDG"];
    }

    if ($kind === 'orcid_batch') {
        $orcid = isset($payload['orcid']) ? trim((string) $payload['orcid']) : null;
        $name  = $orcid ? lookup_researcher_name($pdo, $orcid) : null;
        $works = isset($payload['works']) ? $payload['works'] : [];
        foreach ($works as $work) {
            $doi = migrate_publication_work($pdo, $work, $sdgVersion, $counts);
            if ($doi && $orcid) {
                link_publication_author($pdo, $doi, $orcid, $name, 1, false, $counts);
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
            $doi = migrate_publication_work($pdo, $work, $sdgVersion, $counts);
            if ($doi && $orcid) {
                link_publication_author($pdo, $doi, $orcid, $name, 1, false, $counts);
            }
        }
        return ['counts' => $counts, 'summary' => count($works) . " karya (legacy, {$orcid})"];
    }

    // Format tidak dikenali -> jangan hilang, simpan mentah di ecosystem_cache.
    db_upsert($pdo, 'ecosystem_cache', [
        'cache_key'  => 'unrecognized_' . md5(json_encode($payload)),
        'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400),
        'created_by' => 'cache_to_db_batch',
    ], ['cache_key']);

    return ['counts' => $counts, 'summary' => 'format cache tidak dikenali, disimpan ke ecosystem_cache'];
}

function extract_sdg_version(array $payload): string
{
    $v = isset($payload['api_version']) ? $payload['api_version'] : SDG_VERSION_DEFAULT;
    return is_string($v) && $v !== '' ? $v : SDG_VERSION_DEFAULT;
}

function normalize_doi(string $doi): string
{
    return strtolower(trim($doi));
}

/** Upsert publications + work_sdgs untuk satu work. Return DOI, atau null jika DOI kosong. */
function migrate_publication_work(PDO $pdo, array $work, string $sdgVersion, array &$counts): ?string
{
    $doi = normalize_doi((string) (isset($work['doi']) ? $work['doi'] : ''));
    if ($doi === '') {
        $counts['skipped']++;
        return null;
    }

    $title = isset($work['title']) ? $work['title'] : $doi;
    if (is_array($title)) $title = isset($title[0]) ? $title[0] : $doi;

    db_upsert($pdo, 'publications', [
        'doi'            => substr($doi, 0, 512),
        'title'          => substr((string) $title, 0, 1024),
        'abstract'       => isset($work['abstract']) ? $work['abstract'] : null,
        'sdg_cache_json' => json_encode([
            'sdgs'                  => isset($work['sdgs']) ? $work['sdgs'] : [],
            'sdg_confidence'        => isset($work['sdg_confidence']) ? $work['sdg_confidence'] : [],
            'contributor_types'     => isset($work['contributor_types']) ? $work['contributor_types'] : [],
            'contribution_pathways' => isset($work['contribution_pathways']) ? $work['contribution_pathways'] : [],
        ], JSON_UNESCAPED_UNICODE),
        'raw_data_json'  => json_encode($work, JSON_UNESCAPED_UNICODE),
    ], ['doi']);
    $counts['publications']++;

    $detailed = isset($work['detailed_analysis']) ? $work['detailed_analysis'] : [];
    if (is_array($detailed)) {
        foreach ($detailed as $sdgKey => $analysis) {
            $sdgNumber = (int) preg_replace('/[^0-9]/', '', (string) $sdgKey);
            if ($sdgNumber < 1 || $sdgNumber > 17) continue;

            db_upsert($pdo, 'work_sdgs', [
                'doi'                   => $doi,
                'sdg_number'            => $sdgNumber,
                'sdg_version'           => substr($sdgVersion, 0, 10),
                'confidence'            => isset($analysis['score']) ? $analysis['score'] : null,
                'classification_detail' => json_encode($analysis, JSON_UNESCAPED_UNICODE),
            ], ['doi', 'sdg_number', 'sdg_version']);
            $counts['sdg_links']++;
        }
    }

    return $doi;
}

function link_publication_author(
    PDO $pdo,
    string $doi,
    ?string $orcid,
    ?string $name,
    int $sequence,
    bool $isCorresponding,
    array &$counts
): void {
    if ($orcid) {
        $existing = db_fetch_one(
            $pdo,
            'SELECT id FROM publication_authors WHERE doi = ? AND orcid = ? LIMIT 1',
            [$doi, $orcid]
        );
    } else {
        $existing = db_fetch_one(
            $pdo,
            'SELECT id FROM publication_authors WHERE doi = ? AND name = ? LIMIT 1',
            [$doi, (string) $name]
        );
    }
    if ($existing) return;

    // orcid di publication_authors ber-FK ke researchers(orcid) — kalau
    // peneliti itu belum sempat masuk lewat fase "researchers", set NULL
    // saja daripada gagal karena FK, tetap simpan namanya.
    $orcidToStore = $orcid;
    if ($orcid) {
        $exists = db_fetch_one($pdo, 'SELECT orcid FROM researchers WHERE orcid = ? LIMIT 1', [$orcid]);
        if (!$exists) $orcidToStore = null;
    }

    $stmt = $pdo->prepare('
        INSERT INTO publication_authors (doi, orcid, name, sequence, is_corresponding)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $doi,
        $orcidToStore,
        substr((string) ($name ?: $orcid ?: 'Unknown'), 0, 255),
        max(1, $sequence),
        $isCorresponding ? 1 : 0,
    ]);
    $counts['authors_linked']++;
}

// ===================================================================
// FINALIZE — ringkasan SDG per peneliti -> analytics_snapshots
// (mencerminkan apa yang dihitung ?action=summary di SDG_Classification_API.php)
// ===================================================================

function finalize_sdg_snapshots(PDO $pdo): array
{
    $rows = db_fetch_all($pdo, "
        SELECT pa.orcid, ws.sdg_number, AVG(ws.confidence) AS avg_confidence, COUNT(DISTINCT ws.doi) AS work_count
        FROM work_sdgs ws
        INNER JOIN publication_authors pa ON pa.doi = ws.doi
        WHERE pa.orcid IS NOT NULL
        GROUP BY pa.orcid, ws.sdg_number
    ");

    $byOrcid = [];
    foreach ($rows as $row) {
        $level = 'beginner';
        if ($row['avg_confidence'] >= 0.60) $level = 'expert';
        elseif ($row['avg_confidence'] >= 0.35) $level = 'intermediate';

        $byOrcid[$row['orcid']][] = [
            'sdg_number'   => (int) $row['sdg_number'],
            'expertise'    => $level,
            'work_count'   => (int) $row['work_count'],
            'avg_confidence' => round((float) $row['avg_confidence'], 3),
        ];
    }

    $written = 0;
    foreach ($byOrcid as $orcid => $sdgSummary) {
        $existing = db_fetch_one($pdo, "
            SELECT id FROM analytics_snapshots
            WHERE snapshot_type = 'sdg_distribution' AND entity_type = 'researcher'
              AND entity_id = ? AND period = 'all-time' LIMIT 1
        ", [$orcid]);

        if ($existing) {
            $stmt = $pdo->prepare('UPDATE analytics_snapshots SET data_json = ?, computed_at = NOW() WHERE id = ?');
            $stmt->execute([json_encode($sdgSummary, JSON_UNESCAPED_UNICODE), $existing['id']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO analytics_snapshots (snapshot_type, entity_type, entity_id, period, data_json)
                VALUES ('sdg_distribution', 'researcher', ?, 'all-time', ?)
            ");
            $stmt->execute([$orcid, json_encode($sdgSummary, JSON_UNESCAPED_UNICODE)]);
        }
        $written++;
    }

    return [
        'status'        => 'success',
        'message'       => 'analytics_snapshots (sdg_distribution per researcher) diperbarui',
        'researchers'   => $written,
    ];
}

// ===================================================================
// PANEL KONTROL HTML
// ===================================================================

function render_page(): void
{
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Wizdam — Cache to DB Migrator</title>
<style>
body{font-family:monospace;background:#1a1a1a;color:#ddd;padding:20px}
.container{max-width:960px;margin:0 auto;background:#2d2d2d;padding:20px;border:1px solid #444;border-radius:8px}
button{background:#ff4757;color:#fff;border:none;padding:12px 24px;cursor:pointer;font-size:16px;font-weight:bold;border-radius:4px;margin-top:10px;margin-right:8px}
button:hover{background:#ff6b81}
button:disabled{background:#555;cursor:not-allowed}
.log-box{height:420px;overflow-y:auto;background:#000;border:1px solid #555;margin-top:15px;padding:10px;font-size:13px;line-height:1.5;border-radius:4px}
.ok{color:#2ed573;display:block;border-bottom:1px dashed #333;padding-bottom:2px}
.err{color:#ff4757;display:block}
.phase{color:#1e90ff;font-weight:bold;margin-top:10px;display:block}
#status{margin-top:15px;font-size:1.1em;font-weight:bold}
.warn{background:#3a2a00;border:1px solid #ffa502;color:#ffa502;padding:10px;border-radius:4px;margin-bottom:15px;font-size:13px}
</style>
</head>
<body>
<div class="container">
  <h2>🗄️ Wizdam — Cache to Database Migrator</h2>
  <div class="warn">⚠️ File sementara — hapus dari <code>public/</code> setelah migrasi selesai.</div>
  <p>Memindahkan cache SDG_Classification_API.php (orcid_init / orcid_batch / orcid legacy / article) di <code>api/cache/</code> ke database <code>sangia_ecosystem</code>, bertahap dan anti-timeout.</p>

  <div>
    <button onclick="checkStatus()">CEK STATUS CACHE</button>
    <button onclick="start()" id="btnStart">MULAI MIGRASI</button>
  </div>

  <div id="status">Status: menunggu instruksi...</div>
  <div class="log-box" id="logs"></div>
</div>

<script>
function checkStatus() {
    setStatus('Mengecek cache & koneksi database...', '#1e90ff');
    fetch('?action=status')
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
    fetch('?action=run&phase=' + phase + '&offset=' + offset)
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
    setStatus('Merangkum SDG per peneliti...', '#1e90ff');
    fetch('?action=finalize')
        .then(r => r.json())
        .then(d => {
            log('<strong>' + (d.message || d.status) + '</strong> (' + (d.researchers ?? 0) + ' peneliti)');
            setStatus('🔥 MIGRASI SELESAI. Sekarang hapus file ini dari server.', '#2ed573');
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