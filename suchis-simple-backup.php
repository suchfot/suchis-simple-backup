<?php
/**
 * Plugin Name: Suchis Simple Backup
 * Plugin URI: https://www.derperformer.com
 * Description: Einfaches Backup (Dateien + optional DB) als ZIP nach wp-content/backups inkl. Download-Links.
 * Version: 1.2.4
 * Author: Christian Suchanek
 * Author URI: https://www.derperformer.com/wordperessplugin/suchis-simple-backup-dein-wordpress-backup-plugin/
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Hinweis: Dieses Plugin wurde mit Unterstützung von KI generiert.
 */

use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

if (!defined('ABSPATH')) {
    exit;
}

define('SSBHF_BACKUP_DIR', WP_CONTENT_DIR . '/backups');
define('SSBHF_TMP_DIR', WP_CONTENT_DIR . '/uploads/ssb-tmp');

add_action(
    'admin_menu', function () {
        if (!current_user_can('manage_options')) {
            return;
        }

        add_management_page(
            'Suchis Simple Backup',
            'Suchis Simple Backup',
            'manage_options',
            'ssb-simple-backup',
            'Ssbhf_Render_page'
        );
    }
);

add_action('admin_post_ssbhf_run', 'Ssbhf_Run_backup');
add_action('admin_post_ssbhf_download', 'Ssbhf_Download_backup');
add_action('admin_post_ssbhf_delete', 'Ssbhf_Delete_backup');

/**
 * Render the backup management page in the WordPress admin.
 *
 * @return void
 */
function Ssbhf_Render_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = isset($_GET['ssb_notice']) ? sanitize_text_field(wp_unslash($_GET['ssb_notice'])) : '';
    $file   = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';

    echo '<div class="wrap">';
    echo '<h1>Suchis Simple Backup</h1>';

    if (!class_exists('\ZipArchive')) {
        echo '<div class="notice notice-error"><p><strong>ZipArchive fehlt.</strong> Bitte PHP-Extension <code>zip</code> aktivieren.</p></div>';
        echo '</div>';
        return;
    }

    if ($notice === 'created' && $file) {
        $dl = wp_nonce_url(admin_url('admin-post.php?action=ssbhf_download&file=' . rawurlencode($file)), 'ssbhf_download');
        echo '<div class="notice notice-success"><p>Backup erstellt: <code>' . esc_html($file) . '</code> &nbsp; <a class="button button-small" href="' . esc_url($dl) . '">Download</a></p></div>';
    } elseif ($notice === 'failed') {
        $msg = isset($_GET['msg']) ? sanitize_text_field(wp_unslash($_GET['msg'])) : 'Backup fehlgeschlagen.';
        echo '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>';
    } elseif ($notice === 'deleted') {
        echo '<div class="notice notice-success"><p>Backup gelöscht.</p></div>';
    }

    echo '<p>Backups liegen in <code>wp-content/backups</code>. Dieser Ordner wird beim ZIP-Erstellen automatisch <strong>ausgeschlossen</strong>, damit sich Backups nicht selbst aufblasen.</p>';

    $run_url = wp_nonce_url(admin_url('admin-post.php?action=ssbhf_run'), 'ssbhf_run');
    echo '<p><a class="button button-primary" href="' . esc_url($run_url) . '">Backup jetzt erstellen (Dateien + DB)</a></p>';

    echo '<hr><h2>Vorhandene Backups</h2>';

    $files = Ssbhf_List_backups();
    if (!$files) {
        echo '<p><em>Noch keine Backups vorhanden.</em></p>';
    } else {
        echo '<table class="widefat striped" style="max-width: 980px">';
        echo '<thead><tr><th>Datei</th><th>Größe</th><th>Datum</th><th>Aktionen</th></tr></thead><tbody>';
        foreach ($files as $f) {
            $dl = wp_nonce_url(admin_url('admin-post.php?action=ssbhf_download&file=' . rawurlencode($f['name'])), 'ssbhf_download');
            $del = wp_nonce_url(admin_url('admin-post.php?action=ssbhf_delete&file=' . rawurlencode($f['name'])), 'ssbhf_delete');
            echo '<tr>';
            echo '<td><code>' . esc_html($f['name']) . '</code></td>';
            echo '<td>' . esc_html(size_format($f['size'])) . '</td>';
            echo '<td>' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $f['mtime'])) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($dl) . '">Download</a> ';
            echo '<a class="button button-small" href="' . esc_url($del) . '" onclick="return confirm(\'Backup wirklich löschen?\')">Löschen</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<p style="margin-top:12px; font-size: 12px; opacity: .7;">Author: Christian Suchanek · <a href="mailto:christian.suchanek@gmail.com">christian.suchanek@gmail.com</a></p>';
    echo '</div>';
}

/**
 * Run the backup process, creating a ZIP file with site files and database.
 *
 * @return void
 */
function Ssbhf_Run_backup(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Forbidden', 403);
    }
    check_admin_referer('ssbhf_run');

    Ssbhf_Ensure_dirs();

    $host = parse_url(home_url(), PHP_URL_HOST);
    $host = $host ? preg_replace('~[^a-zA-Z0-9\._-]~', '-', $host) : 'site';
    $filename = 'wp-backup_files-db_' . $host . '_' . gmdate('Y-m-d_His') . '.zip';

    $zip_path = Ssbhf_Backup_path($filename);
    $tmp_sql  = trailingslashit(SSBHF_TMP_DIR) . 'database_' . $host . '_' . gmdate('Ymd_His') . '.sql';

    // 1) DB dump to tmp (best-effort)
    $db_ok = Ssbhf_Dump_db($tmp_sql);

    // 2) Create ZIP
    $zip = new \ZipArchive();
    $open = $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    if ($open !== true) {
        @unlink($tmp_sql);
        $msg = 'ZIP konnte nicht erstellt werden (Schreibrechte?)';
        wp_safe_redirect(admin_url('tools.php?page=ssb-simple-backup&ssb_notice=failed&msg=' . rawurlencode($msg)));
        exit;
    }

    if ($db_ok && file_exists($tmp_sql)) {
        $zip->addFile($tmp_sql, 'database.sql');
    }

    $root = rtrim(realpath(ABSPATH), '/\\');
    $backup_dir = rtrim(realpath(SSBHF_BACKUP_DIR), '/\\');

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $fi) {
        if (!$fi->isFile()) {
            continue;
        }

        $path = $fi->getRealPath();
        if (!$path) {
            continue;
        }

        // Exclude backup dir to prevent self-bloat
        if ($backup_dir && str_starts_with($path, $backup_dir . DIRECTORY_SEPARATOR)) {
            continue;
        }

        $rel = ltrim(str_replace($root, '', $path), '/\\');
        if ($rel === '') {
            continue;
        }

        $zip->addFile($path, $rel);
    }

    $zip->close();
    @unlink($tmp_sql);

    // Sanity
    if (!file_exists($zip_path) || filesize($zip_path) < 100) {
        $msg = 'ZIP wurde nicht korrekt geschrieben (Datei fehlt/zu klein).';
        wp_safe_redirect(admin_url('tools.php?page=ssb-simple-backup&ssb_notice=failed&msg=' . rawurlencode($msg)));
        exit;
    }

    wp_safe_redirect(admin_url('tools.php?page=ssb-simple-backup&ssb_notice=created&file=' . rawurlencode($filename)));
    exit;
}

/**
 * Dump the WordPress database to a SQL file.
 *
 * @param string $tmp_sql Path to the temporary SQL file.
 *
 * @return bool True if the dump was successful, false otherwise.
 */
function Ssbhf_Dump_db(string $tmp_sql): bool
{
    global $wpdb;

    $fh = @fopen($tmp_sql, 'wb');
    if (!$fh) {
        return false;
    }

    fwrite($fh, "-- Suchis Simple Backup DB Dump\n");
    fwrite($fh, "-- Site: " . home_url() . "\n");
    fwrite($fh, "-- Date: " . gmdate('c') . " (UTC)\n\n");
    fwrite($fh, "SET NAMES utf8mb4;\n");
    fwrite($fh, "SET time_zone = '+00:00';\n");
    fwrite($fh, "SET foreign_key_checks = 0;\n\n");

    $tables = $wpdb->get_col('SHOW TABLES');
    if (!is_array($tables) || empty($tables)) {
        fwrite($fh, "\n-- No tables found.\nSET foreign_key_checks = 1;\n");
        fclose($fh);
        return true;
    }

    foreach ($tables as $table) {
        $tq = Ssbhf_bt($table);
        fwrite($fh, "\n-- Table: {$tq}\n");
        fwrite($fh, "DROP TABLE IF EXISTS {$tq};\n");

        $create = $wpdb->get_row("SHOW CREATE TABLE {$tq}", ARRAY_N);
        if (!is_array($create) || empty($create[1])) {
            fwrite($fh, "-- WARNING: SHOW CREATE TABLE failed for {$tq}\n");
            continue;
        }
        fwrite($fh, $create[1] . ";\n\n");

        // Stream rows in smaller chunks
        $offset = 0;
        $limit = 500;

        while (true) {
            $sql = $wpdb->prepare("SELECT * FROM {$tq} LIMIT %d OFFSET %d", $limit, $offset);
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (!is_array($rows) || empty($rows)) {
                break;
            }

            $cols = array_keys($rows[0]);
            $col_list = implode(',', array_map('Ssbhf_bt', $cols));

            fwrite($fh, "INSERT INTO {$tq} ({$col_list}) VALUES\n");
            $n = count($rows);
            for ($i = 0; $i < $n; $i++) {
                $r = $rows[$i];
                $vals = [];
                foreach ($cols as $c) {
                    $vals[] = Ssbhf_Sql_value($wpdb, $r[$c] ?? null);
                }
                $line = '(' . implode(',', $vals) . ')';
                $line .= ($i < $n - 1) ? ",\n" : ";\n";
                fwrite($fh, $line);
            }

            $offset += $n;
        }
    }

    fwrite($fh, "\nSET foreign_key_checks = 1;\n");
    fclose($fh);
    return true;
}

/**
 * Escape a database identifier (table or column name) with backticks.
 *
 * @param string $name The identifier to escape.
 *
 * @return string The escaped identifier.
 */
function Ssbhf_bt(string $name): string
{
    $name = str_replace('`', '``', $name);
    return '`' . $name . '`';
}

/**
 * Convert a value to its SQL representation.
 *
 * @param object $wpdb  The WordPress database object.
 * @param mixed  $value The value to convert.
 *
 * @return string The SQL-escaped value.
 */
function Ssbhf_Sql_value($wpdb, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    $s = (string)$value;

    if (isset($wpdb->dbh) && $wpdb->dbh instanceof mysqli) {
        $s = mysqli_real_escape_string($wpdb->dbh, $s);
    } else {
        $s = str_replace(["\\", "\0", "\n", "\r", "\x1a", "'", "\""], ["\\\\", "\\0", "\\n", "\\r", "\\Z", "\\'", '\\"'], $s);
    }
    return "'" . $s . "'";
}

/**
 * Download a backup file.
 *
 * @return void
 */
function Ssbhf_Download_backup(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Forbidden', 403);
    }
    check_admin_referer('ssbhf_download');

    $name = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
    if (!$name || !str_ends_with($name, '.zip')) {
        wp_die('Invalid file', 400);
    }

    $path = Ssbhf_Backup_path($name);
    if (!file_exists($path)) {
        wp_die('Not found', 404);
    }

    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($name) . '"');
    header('Content-Length: ' . filesize($path));

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        wp_die('Cannot read file', 500);
    }

    while (!feof($fh)) {
        echo fread($fh, 1024 * 1024);
        @flush();
    }
    fclose($fh);
    exit;
}

/**
 * Delete a backup file.
 *
 * @return void
 */
function Ssbhf_Delete_backup(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Forbidden', 403);
    }
    check_admin_referer('ssbhf_delete');

    $name = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
    if (!$name || !str_ends_with($name, '.zip')) {
        wp_die('Invalid file', 400);
    }

    $path = Ssbhf_Backup_path($name);
    if (file_exists($path)) {
        @unlink($path);
    }

    wp_safe_redirect(admin_url('tools.php?page=ssb-simple-backup&ssb_notice=deleted'));
    exit;
}

/**
 * Ensure backup and temporary directories exist with proper security.
 *
 * @return void
 */
function Ssbhf_Ensure_dirs(): void
{
    if (!is_dir(SSBHF_BACKUP_DIR)) {
        wp_mkdir_p(SSBHF_BACKUP_DIR);
    }
    if (!is_dir(SSBHF_TMP_DIR)) {
        wp_mkdir_p(SSBHF_TMP_DIR);
    }

    $htaccess = SSBHF_BACKUP_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Deny from all\n");
    }
    $index = SSBHF_BACKUP_DIR . '/index.html';
    if (!file_exists($index)) {
        @file_put_contents($index, "");
    }
}

/**
 * Get the full path to a backup file.
 *
 * @param string $filename The backup filename.
 *
 * @return string The full path to the backup file.
 */
function Ssbhf_Backup_path(string $filename): string
{
    Ssbhf_Ensure_dirs();
    return trailingslashit(SSBHF_BACKUP_DIR) . $filename;
}

/**
 * List all backup files in the backup directory.
 *
 * @return array An array of backup file information (name, size, mtime).
 */
function Ssbhf_List_backups(): array
{
    if (!is_dir(SSBHF_BACKUP_DIR)) {
        return [];
    }
    $items = glob(SSBHF_BACKUP_DIR . '/*.zip');
    if (!$items) {
        return [];
    }

    $out = [];
    foreach ($items as $path) {
        $out[] = ['name' => basename($path), 'size' => filesize($path), 'mtime' => filemtime($path)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}
