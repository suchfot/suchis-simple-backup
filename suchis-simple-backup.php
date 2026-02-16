<?php
/**
 * Plugin Name: Suchis Simple Backup
 * Plugin URI: https://www.derperformer.com
 * Description: Einfaches Backup (Dateien + optional DB) als ZIP nach wp-content/backups inkl. Download-Links.
 * Version: 1.2.4
 * Author: Christian Suchanek
 * Author URI: https://www.derperformer.com/projekte/wordpress-plugins/suchis-simple-backup/
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Hinweis: Dieses Plugin wurde mit Unterstützung von KI generiert.
 */

if (!defined('ABSPATH')) { exit;
}

define('SSBHF_BACKUP_DIR', WP_CONTENT_DIR . '/backups');
define('SSBHF_TMP_DIR', WP_CONTENT_DIR . '/uploads/ssb-tmp');

add_action(
    'admin_menu', function () {
        if (!current_user_can('manage_options')) { return;
        }

        add_management_page(
            'Suchis Simple Backup',
            'Suchis Simple Backup',
            'manage_options',
            'ssb-simple-backup',
            'ssbhf_render_page'
        );
    }
);

add_action('admin_post_ssbhf_run', 'ssbhf_run_backup');
add_action('admin_post_ssbhf_download', 'ssbhf_download_backup');
add_action('admin_post_ssbhf_delete', 'ssbhf_delete_backup');

function ssbhf_render_page(): void
{
    if (!current_user_can('manage_options')) { return;
    }

    $notice = isset($_GET['ssb_notice']) ? sanitize_text_field(wp_unslash($_GET['ssb_notice'])) : '';
    $file   = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';

    echo '<div class="wrap">';
    echo '<h1>Suchis Simple Backup</h1>';

    if (!class_exists('ZipArchive')) {
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

    $files = ssbhf_list_backups();
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

function ssbhf_run_backup(): void
{
    if (!current_user_can('manage_options')) { wp_die('Forbidden', 403);
    }
    check_admin_referer('ssbhf_run');

    ssbhf_ensure_dirs();

    $host = parse_url(home_url(), PHP_URL_HOST);
    $host = $host ? preg_replace('~[^a-zA-Z0-9\._-]~', '-', $host) : 'site';
    $filename = 'wp-backup_files-db_' . $host . '_' . gmdate('Y-m-d_His') . '.zip';

    $zip_path = ssbhf_backup_path($filename);
    $tmp_sql  = trailingslashit(SSBHF_TMP_DIR) . 'database_' . $host . '_' . gmdate('Ymd_His') . '.sql';

    // 1) DB dump to tmp (best-effort)
    $db_ok = ssbhf_dump_db($tmp_sql);

    // 2) Create ZIP
    $zip = new ZipArchive();
    $open = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
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
        if (!$fi->isFile()) { continue;
        }

        $path = $fi->getRealPath();
        if (!$path) { continue;
        }

        // Exclude backup dir to prevent self-bloat
        if ($backup_dir && str_starts_with($path, $backup_dir . DIRECTORY_SEPARATOR)) {
            continue;
        }

        $rel = ltrim(str_replace($root, '', $path), '/\\');
        if ($rel === '') { continue;
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

function ssbhf_dump_db(string $tmp_sql): bool
{
    global $wpdb;

    $fh = @fopen($tmp_sql, 'wb');
    if (!$fh) { return false;
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
        $tq = ssbhf_bt($table);
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
            if (!is_array($rows) || empty($rows)) { break;
            }

            $cols = array_keys($rows[0]);
            $col_list = implode(',', array_map('ssbhf_bt', $cols));

            fwrite($fh, "INSERT INTO {$tq} ({$col_list}) VALUES\n");
            $n = count($rows);
            for ($i = 0; $i < $n; $i++) {
                $r = $rows[$i];
                $vals = [];
                foreach ($cols as $c) {
                    $vals[] = ssbhf_sql_value($wpdb, $r[$c] ?? null);
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

function ssbhf_bt(string $name): string
{
    $name = str_replace('`', '``', $name);
    return '`' . $name . '`';
}

function ssbhf_sql_value($wpdb, $value): string
{
    if ($value === null) { return 'NULL';
    }
    if (is_int($value) || is_float($value)) { return (string)$value;
    }
    if (is_bool($value)) { return $value ? '1' : '0';
    }

    $s = (string)$value;

    if (isset($wpdb->dbh) && $wpdb->dbh instanceof mysqli) {
        $s = mysqli_real_escape_string($wpdb->dbh, $s);
    } else {
        $s = str_replace(["\\", "\0", "\n", "\r", "\x1a", "'", "\""], ["\\\\", "\\0", "\\n", "\\r", "\\Z", "\\'", '\\"'], $s);
    }
    return "'" . $s . "'";
}

function ssbhf_download_backup(): void
{
    if (!current_user_can('manage_options')) { wp_die('Forbidden', 403);
    }
    check_admin_referer('ssbhf_download');

    $name = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
    if (!$name || !str_ends_with($name, '.zip')) { wp_die('Invalid file', 400);
    }

    $path = ssbhf_backup_path($name);
    if (!file_exists($path)) { wp_die('Not found', 404);
    }

    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($name) . '"');
    header('Content-Length: ' . filesize($path));

    $fh = fopen($path, 'rb');
    if ($fh === false) { wp_die('Cannot read file', 500);
    }

    while (!feof($fh)) {
        echo fread($fh, 1024 * 1024);
        @flush();
    }
    fclose($fh);
    exit;
}

function ssbhf_delete_backup(): void
{
    if (!current_user_can('manage_options')) { wp_die('Forbidden', 403);
    }
    check_admin_referer('ssbhf_delete');

    $name = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';
    if (!$name || !str_ends_with($name, '.zip')) { wp_die('Invalid file', 400);
    }

    $path = ssbhf_backup_path($name);
    if (file_exists($path)) { @unlink($path);
    }

    wp_safe_redirect(admin_url('tools.php?page=ssb-simple-backup&ssb_notice=deleted'));
    exit;
}

function ssbhf_ensure_dirs(): void
{
    if (!is_dir(SSBHF_BACKUP_DIR)) { wp_mkdir_p(SSBHF_BACKUP_DIR);
    }
    if (!is_dir(SSBHF_TMP_DIR)) { wp_mkdir_p(SSBHF_TMP_DIR);
    }

    $htaccess = SSBHF_BACKUP_DIR . '/.htaccess';
    if (!file_exists($htaccess)) { @file_put_contents($htaccess, "Deny from all\n");
    }
    $index = SSBHF_BACKUP_DIR . '/index.html';
    if (!file_exists($index)) { @file_put_contents($index, "");
    }
}

function ssbhf_backup_path(string $filename): string
{
    ssbhf_ensure_dirs();
    return trailingslashit(SSBHF_BACKUP_DIR) . $filename;
}

function ssbhf_list_backups(): array
{
    if (!is_dir(SSBHF_BACKUP_DIR)) { return [];
    }
    $items = glob(SSBHF_BACKUP_DIR . '/*.zip');
    if (!$items) { return [];
    }

    $out = [];
    foreach ($items as $path) {
        $out[] = ['name' => basename($path), 'size' => filesize($path), 'mtime' => filemtime($path)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}
