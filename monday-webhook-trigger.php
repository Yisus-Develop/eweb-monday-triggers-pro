<?php
/**
 * Plugin Name: EWEB Monday Triggers Pro
 * Description: High-performance webhook trigger engine for Monday.com. Synchronize WordPress events with your boards in real-time. Part of the EWEB Plugin Suite.
 * Version: 1.1.2
 * Author: Yisus Develop
 * Requires PHP:      8.1
 */

if (!defined('WPINC')) {
    die;
}

// 2. Capture Hook from CF7
add_action('wpcf7_mail_sent', 'monday_trigger_webhook_on_sent');

// 3. Database Setup on Activation
register_activation_hook(__FILE__, 'monday_integration_create_db');
function monday_integration_create_db() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'monday_leads_log';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        email varchar(100) DEFAULT '' NOT NULL,
        source varchar(100) DEFAULT '' NOT NULL,
        status varchar(50) DEFAULT '' NOT NULL,
        response_body text NOT NULL,
        full_payload longtext NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function monday_trigger_webhook_on_sent($contact_form) {
    $submission = WPCF7_Submission::get_instance();
    if (!$submission) return;

    $data = $submission->get_posted_data();
    monday_send_to_handler($data, "Form: " . $contact_form->title());
}

    // 3. Logic to Send Webhook
function monday_send_to_handler($data, $source = "Manual Test") {
    global $wpdb;
    $table_name = $wpdb->prefix . 'monday_leads_log';
    
    // URL del handler robusto
    $webhook_url = content_url('/monday-integration/webhook-handler.php');
    
    $response = wp_remote_post($webhook_url, [
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'body'    => json_encode($data),
        'timeout' => 20,
        'blocking' => true,
    ]);

    // Verificar que la tabla existe
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    if (!$table_exists) {
        monday_integration_create_db();
    }

    // Registro en la Base de Datos SQL (¡ESTO ES LO QUE FALTABA!)
    $wpdb->insert($table_name, [
        'time'          => current_time('mysql'),
        'email'         => $data['email'] ?? $data['your-email'] ?? $data['ea_email'] ?? 'N/A',
        'source'        => $source,
        'status'        => is_wp_error($response) ? 'Error' : wp_remote_retrieve_response_code($response),
        'response_body' => is_wp_error($response) ? $response->get_error_message() : substr(wp_remote_retrieve_body($response), 0, 500),
        'full_payload'  => json_encode($data)
    ]);
    
    return $response;
}

// 4. Admin Menu & Dashboard
add_action('admin_menu', function() {
    add_menu_page('Monday Leads', 'Monday Leads', 'manage_options', 'monday-monitor', 'monday_monitor_page_html', 'dashicons-chart-line');
});

function monday_monitor_page_html() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'monday_leads_log';

    // Re-enviar Registro
    if (isset($_POST['monday_resend_log']) && isset($_POST['log_id'])) {
        check_admin_referer('monday_resend_log_' . $_POST['log_id']);
        $log_id = intval($_POST['log_id']);
        $log = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $log_id));
        
        if ($log) {
            $payload = json_decode($log->full_payload, true);
            $response = monday_send_to_handler($payload, "Re-envío Manual (ID: $log_id)");
            
            $code = is_wp_error($response) ? 'Error' : wp_remote_retrieve_response_code($response);
            $body = is_wp_error($response) ? $response->get_error_message() : substr(wp_remote_retrieve_body($response), 0, 500);
            
            // Actualizar el log existente con el nuevo resultado
            $wpdb->update($table_name, 
                ['status' => $code, 'response_body' => $body, 'time' => current_time('mysql')],
                ['id' => $log_id]
            );
            
            echo '<div class="updated"><p>🚀 Re-envío completado. Status: <strong>' . $code . '</strong></p></div>';
        }
    }

    // Eliminación masiva (Bulk Actions)
    if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete' && !empty($_POST['log_ids'])) {
        check_admin_referer('bulk-logs');
        $log_ids = array_map('intval', $_POST['log_ids']);
        $placeholders = implode(',', array_fill(0, count($log_ids), '%d'));
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE id IN ($placeholders)", $log_ids));
        if ($deleted) {
            echo '<div class="updated"><p>✅ ' . $deleted . ' registro(s) eliminado(s) correctamente.</p></div>';
        }
    }

    // Eliminar registro individual
    if (isset($_POST['monday_delete_log']) && isset($_POST['log_id'])) {
        check_admin_referer('monday_delete_log_' . $_POST['log_id']);
        $log_id = intval($_POST['log_id']);
        $deleted = $wpdb->delete($table_name, ['id' => $log_id], ['%d']);
        if ($deleted) {
            echo '<div class="updated"><p>✅ Registro eliminado correctamente.</p></div>';
        } else {
            echo '<div class="error"><p>❌ Error al eliminar el registro.</p></div>';
        }
    }

    // Test trigger
    if (isset($_POST['monday_test_trigger'])) {
        monday_send_to_handler([
            'nombre' => 'Test DB ' . date('H:i:s'), 
            'email' => 'test_db@enlaweb.co', 
            'pais_cf7' => 'España', 
            'perfil' => 'empresa'
        ], "Dashboard Test");
        echo '<div class="updated"><p>¡Test lanzado y guardado en DB!</p></div>';
    }

    // Paginación
    $per_page = 50;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Búsqueda
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $where = $search ? $wpdb->prepare("WHERE email LIKE %s OR source LIKE %s", "%$search%", "%$search%") : '';
    
    // Total de registros
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where");
    $total_pages = ceil($total_items / $per_page);
    
    // Obtener registros
    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name $where ORDER BY id DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));
    ?>
    <div class="wrap">
        <h1>📊 Monitor de Integración Monday.com</h1>
        
        <div style="display: flex; justify-content: space-between; margin: 20px 0;">
            <form method="post">
                <input type="submit" name="monday_test_trigger" class="button button-primary" value="Enviar Lead de Prueba">
            </form>
            
            <form method="get" style="display: flex; gap: 10px;">
                <input type="hidden" name="page" value="monday-monitor">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Buscar por email u origen...">
                <input type="submit" class="button" value="Buscar">
                <?php if ($search): ?>
                    <a href="?page=monday-monitor" class="button">Limpiar</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <form method="post" id="bulk-action-form">
                    <?php wp_nonce_field('bulk-logs'); ?>
                    <label for="bulk-action-selector-top" class="screen-reader-text">Seleccionar acción masiva</label>
                    <select name="action" id="bulk-action-selector-top">
                        <option value="-1">Acciones masivas</option>
                        <option value="bulk_delete">Eliminar</option>
                    </select>
                    <input type="submit" class="button action" value="Aplicar" onclick="return confirm('¿Estás seguro de eliminar los registros seleccionados?');">
                </form>
            </div>
            <div class="alignleft actions">
                <span class="displaying-num"><?php echo number_format_i18n($total_items); ?> registros</span>
            </div>
            <?php if ($total_pages > 1): ?>
            <div class="tablenav-pages">
                <span class="pagination-links">
                    <?php if ($current_page > 1): ?>
                        <a class="first-page button" href="?page=monday-monitor&paged=1<?php echo $search ? '&s=' . urlencode($search) : ''; ?>">«</a>
                        <a class="prev-page button" href="?page=monday-monitor&paged=<?php echo $current_page - 1; ?><?php echo $search ? '&s=' . urlencode($search) : ''; ?>">‹</a>
                    <?php endif; ?>
                    
                    <span class="paging-input">
                        Página <?php echo $current_page; ?> de <?php echo $total_pages; ?>
                    </span>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a class="next-page button" href="?page=monday-monitor&paged=<?php echo $current_page + 1; ?><?php echo $search ? '&s=' . urlencode($search) : ''; ?>">›</a>
                        <a class="last-page button" href="?page=monday-monitor&paged=<?php echo $total_pages; ?><?php echo $search ? '&s=' . urlencode($search) : ''; ?>">»</a>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-1" onclick="
                            var checkboxes = document.querySelectorAll('input[name=\'log_ids[]\']');
                            checkboxes.forEach(function(checkbox) { checkbox.checked = this.checked; }, this);
                        ">
                    </td>
                    <th style="width: 150px;">Fecha</th>
                    <th>Email</th>
                    <th style="width: 150px;">Origen</th>
                    <th style="width: 80px;">Status</th>
                    <th style="width: 150px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px;">
                        <?php echo $search ? 'No se encontraron resultados.' : 'No hay registros aún. Envía un lead de prueba.'; ?>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <th scope="row" class="check-column">
                            <input type="checkbox" name="log_ids[]" value="<?php echo $log->id; ?>" form="bulk-action-form">
                        </th>
                        <td><?php echo esc_html($log->time); ?></td>
                        <td><strong><?php echo esc_html($log->email); ?></strong></td>
                        <td><?php echo esc_html($log->source); ?></td>
                        <td>
                            <span style="color: <?php echo $log->status == 200 ? 'green' : 'red'; ?>; font-weight: bold;">
                                <?php echo esc_html($log->status); ?>
                            </span>
                        </td>
                        <td style="display: flex; gap: 5px;">
                            <form method="post" style="display: inline;">
                                <?php wp_nonce_field('monday_resend_log_' . $log->id); ?>
                                <input type="hidden" name="log_id" value="<?php echo $log->id; ?>">
                                <button type="submit" name="monday_resend_log" class="button button-small button-primary" title="Re-procesar este lead">🔄 Re-enviar</button>
                            </form>

                            <button type="button" class="button button-small" onclick="
                                var modal = document.getElementById('json-modal-<?php echo $log->id; ?>');
                                modal.style.display = 'block';
                            ">Ver JSON</button>
                            
                            <form method="post" style="display: inline;" onsubmit="return confirm('¿Estás seguro de eliminar este registro?\n\nEmail: <?php echo esc_js($log->email); ?>\nFecha: <?php echo esc_js($log->time); ?>');">
                                <?php wp_nonce_field('monday_delete_log_' . $log->id); ?>
                                <input type="hidden" name="log_id" value="<?php echo $log->id; ?>">
                                <button type="submit" name="monday_delete_log" class="button button-small" style="color: #b32d2e;">🗑️ Eliminar</button>
                            </form>
                            
                            <!-- Modal -->
                            <div id="json-modal-<?php echo $log->id; ?>" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5);">
                                <div style="background-color:#fff; margin:5% auto; padding:20px; width:80%; max-width:800px; border-radius:5px; max-height:80vh; overflow:auto;">
                                    <div style="display:flex; justify-content:space-between; margin-bottom:15px;">
                                        <h3>Payload Completo</h3>
                                        <button onclick="document.getElementById('json-modal-<?php echo $log->id; ?>').style.display='none'" style="cursor:pointer; font-size:20px; border:none; background:none;">&times;</button>
                                    </div>
                                    <pre style="background:#f5f5f5; padding:15px; border-radius:3px; overflow:auto;"><?php echo esc_html(json_encode(json_decode($log->full_payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="pagination-links">
                    <?php if ($current_page > 1): ?>
                        <a class="first-page button" href="?page=monday-monitor&paged=1<?php echo $search ? '&s=' . urlencode($search) : ''; ?>">«</a>
                        <a class="prev-page button" href="?page=monday-monitor&paged=<?php echo $current_page - 1; ?><?php echo $search ? '&s=' . urlencode($search) : ''; ?>">‹</a>
                    <?php endif; ?>
                    
                    <span class="paging-input">
                        Página <?php echo $current_page; ?> de <?php echo $total_pages; ?>
                    </span>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a class="next-page button" href="?page=monday-monitor&paged=<?php echo $current_page + 1; ?><?php echo $search ? '&s=' . urlencode($search) : ''; ?>">›</a>
                        <a class="last-page button" href="?page=monday-monitor&paged=<?php echo $total_pages; ?><?php echo $search ? '&s=' . urlencode($search) : ''; ?>">»</a>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

