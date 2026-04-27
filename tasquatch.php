<?php
/**
 * Plugin Name: Tasquatch
 * Plugin URI:  https://bestsitedesigner.com
 * Description: Multi-tenant portal for fiber optic splicing contractors. Project management, splice tracking, GC dashboards, and field submission workflows.
 * Version:     1.0.0
 * Author:      Dustin R / Best Site Designer
 * License:     Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// CONSTANTS — add to wp-config.php:
//   define( 'ANTHROPIC_API_KEY', 'sk-ant-...' );
//   define( 'GOOGLE_MAPS_API_KEY', 'AIza...' );
//   define( 'N8N_WEBHOOK_SECRET', 'your-secret-token' );
//   define( 'N8N_PROVISION_WEBHOOK_URL', 'https://your-n8n.com/webhook/...' );
// ============================================================

define( 'TELECOM_DB_VERSION', '1.1.0' );
define( 'TELECOM_PLUGIN_VERSION', '1.0.0' );

// ============================================================
// ACF COMPATIBILITY HELPERS
// Falls back to update_post_meta / get_post_meta if ACF is
// not active. Keeps the plugin functional without ACF.
// ============================================================

function telecom_update_field( $key, $value, $post_id ) {
    if ( function_exists( 'update_field' ) ) {
        update_field( $key, $value, $post_id );
    } else {
        update_post_meta( $post_id, $key, $value );
    }
}

function telecom_get_field( $key, $post_id ) {
    if ( function_exists( 'get_field' ) ) {
        return get_field( $key, $post_id );
    } else {
        return get_post_meta( $post_id, $key, true );
    }
}

// ============================================================
// USER MANAGEMENT HELPERS
// ============================================================

function tasquatch_get_user_role( $user_id = null ) {
    if ( ! $user_id ) $user_id = get_current_user_id();
    $user = get_userdata( $user_id );
    if ( ! $user ) return '';
    foreach ( [ 'administrator', 'gc', 'splicing_company', 'worker', 'splicer' ] as $role ) {
        if ( in_array( $role, (array) $user->roles ) ) return $role;
    }
    return '';
}

function tasquatch_get_project_members( $project_id, $role = null, $status = 'accepted' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'tasquatch_project_members';
    $sql   = "SELECT * FROM {$table} WHERE project_id = %d";
    $args  = [ $project_id ];
    if ( $role ) { $sql .= " AND role = %s"; $args[] = $role; }
    if ( $status ) { $sql .= " AND status = %s"; $args[] = $status; }
    return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
}

function tasquatch_user_can_access_project( $user_id, $project_id ) {
    global $wpdb;
    $user = get_userdata( $user_id );
    if ( ! $user ) return false;
    if ( in_array( 'administrator', (array) $user->roles ) ) return true;
    $gc_user_id = telecom_get_field( 'gc_user_id', $project_id );
    if ( (int) $gc_user_id === (int) $user_id ) return true;
    $table = $wpdb->prefix . 'tasquatch_project_members';
    $found = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$table} WHERE project_id = %d AND user_id = %d AND status = 'accepted'",
        $project_id, $user_id
    ) );
    if ( $found ) return true;
    $workers_table = $wpdb->prefix . 'tasquatch_workers';
    $company_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT splicing_company_user_id FROM {$workers_table} WHERE worker_user_id = %d", $user_id
    ) );
    if ( $company_id ) {
        $company_found = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE project_id = %d AND user_id = %d AND status = 'accepted'",
            $project_id, $company_id
        ) );
        if ( $company_found ) return true;
    }
    return false;
}

function tasquatch_get_workers_for_company( $company_user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'tasquatch_workers';
    $ids   = $wpdb->get_col( $wpdb->prepare(
        "SELECT worker_user_id FROM {$table} WHERE splicing_company_user_id = %d", $company_user_id
    ) );
    if ( empty( $ids ) ) return [];
    $users = [];
    foreach ( $ids as $id ) {
        $u = get_userdata( $id );
        if ( $u ) $users[] = $u;
    }
    return $users;
}

function tasquatch_send_invite_email( $to_email, $inviter_name, $project_title, $accept_url, $is_new_user = false, $temp_password = '' ) {
    $subject = "{$inviter_name} invited you to a project on Tasquatch";
    if ( $is_new_user ) {
        $message = "Hi,\n\n{$inviter_name} has added you to the project \"{$project_title}\" on Tasquatch.\n\n";
        $message .= "Your account has been created. Log in with:\nEmail: {$to_email}\nTemporary password: {$temp_password}\n\n";
        $message .= "Accept the project invite here:\n{$accept_url}\n\nWelcome to Tasquatch.";
    } else {
        $message = "Hi,\n\n{$inviter_name} has invited you to join the project \"{$project_title}\" on Tasquatch.\n\n";
        $message .= "Click the link below to accept:\n{$accept_url}\n\nThis link expires in 7 days.";
    }
    wp_mail( $to_email, $subject, $message );
}

function tasquatch_send_worker_created_email( $to_email, $company_name, $temp_password, $login_url ) {
    $subject = "You've been added to {$company_name} on Tasquatch";
    $message = "Hi,\n\n{$company_name} has created a Tasquatch account for you.\n\n";
    $message .= "Log in with:\nEmail: {$to_email}\nTemporary password: {$temp_password}\n\n";
    $message .= "Set your password here:\n{$login_url}";
    wp_mail( $to_email, $subject, $message );
}





// ============================================================
// ACTIVATION: Create custom DB table + flush rewrite rules
// ============================================================

register_activation_hook( __FILE__, 'telecom_activate' );

function telecom_activate() {
    telecom_create_tables();
    telecom_register_project_cpt();
    flush_rewrite_rules();
}


// ============================================================
// DEACTIVATION: Cleanup rewrite rules
// ============================================================

register_deactivation_hook( __FILE__, 'telecom_deactivate' );

function telecom_deactivate() {
    unregister_post_type( 'project' );
    flush_rewrite_rules();
}

// ============================================================
// ADMIN SETTINGS PAGE
// ============================================================

add_action( 'admin_menu', 'tasquatch_add_settings_page' );

function tasquatch_add_settings_page() {
    add_options_page(
        'Tasquatch Settings',
        'Tasquatch',
        'manage_options',
        'tasquatch-settings',
        'tasquatch_render_settings_page'
    );
}

add_action( 'admin_init', 'tasquatch_register_settings' );

function tasquatch_register_settings() {
    register_setting( 'tasquatch_settings_group', 'tasquatch_billing_enabled', [
        'type'              => 'boolean',
        'default'           => false,
        'sanitize_callback' => 'rest_sanitize_boolean',
    ] );
    register_setting( 'tasquatch_settings_group', 'tasquatch_project_fee', [
        'type'              => 'number',
        'default'           => 150,
        'sanitize_callback' => 'absint',
    ] );
}

function tasquatch_render_settings_page() {
    $billing_enabled = get_option( 'tasquatch_billing_enabled', false );
    $project_fee     = get_option( 'tasquatch_project_fee', 150 );
    ?>
    <div class="wrap">
        <h1>Tasquatch Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'tasquatch_settings_group' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Project Billing</th>
                    <td>
                        <label>
                            <input type="checkbox" name="tasquatch_billing_enabled" value="1"
                                <?php checked( $billing_enabled, true ); ?> />
                            Require payment before accessing a new project
                        </label>
                        <p class="description">When enabled, projects will be locked after provisioning until payment is received. When disabled, projects activate immediately.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Project Fee (USD)</th>
                    <td>
                        <input type="number" name="tasquatch_project_fee" value="<?php echo esc_attr( $project_fee ); ?>" min="0" step="1" style="width:100px;" />
                        <p class="description">Fee charged per project when billing is enabled.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>
        <h2>System Status</h2>
        <table class="form-table">
            <tr>
                <th>Billing</th>
                <td><?php echo $billing_enabled ? '<span style="color:green;">Enabled — $' . esc_html( $project_fee ) . ' per project</span>' : '<span style="color:#888;">Disabled — projects activate immediately</span>'; ?></td>
            </tr>
            <tr>
                <th>n8n Webhook URL</th>
                <td><?php echo defined( 'N8N_PROVISION_WEBHOOK_URL' ) ? '<span style="color:green;">Configured</span>' : '<span style="color:red;">Not configured — add N8N_PROVISION_WEBHOOK_URL to wp-config.php</span>'; ?></td>
            </tr>
            <tr>
                <th>n8n Webhook Secret</th>
                <td><?php echo defined( 'N8N_WEBHOOK_SECRET' ) ? '<span style="color:green;">Configured</span>' : '<span style="color:red;">Not configured — add N8N_WEBHOOK_SECRET to wp-config.php</span>'; ?></td>
            </tr>
            <tr>
                <th>Google Maps API Key</th>
                <td><?php echo defined( 'GOOGLE_MAPS_API_KEY' ) ? '<span style="color:green;">Configured</span>' : '<span style="color:red;">Not configured — add GOOGLE_MAPS_API_KEY to wp-config.php</span>'; ?></td>
            </tr>
            <tr>
                <th>Anthropic API Key</th>
                <td><?php echo defined( 'ANTHROPIC_API_KEY' ) ? '<span style="color:green;">Configured</span>' : '<span style="color:red;">Not configured — add ANTHROPIC_API_KEY to wp-config.php</span>'; ?></td>
            </tr>
            <tr>
                <th>DB Version</th>
                <td><?php echo esc_html( get_option( 'telecom_db_version', 'not set' ) ); ?></td>
            </tr>
        </table>
    </div>
    <?php
}

// ============================================================
// BILLING HELPER: Check if billing is enabled
// ============================================================

function tasquatch_billing_enabled() {
    return (bool) get_option( 'tasquatch_billing_enabled', false );
}

function tasquatch_project_fee() {
    return (int) get_option( 'tasquatch_project_fee', 150 );
}




// ============================================================
// DB: Create / upgrade wp_splice_submissions table
// ============================================================

function telecom_create_tables() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'splice_submissions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        project_id bigint(20) NOT NULL,
        splice_location varchar(255) NOT NULL,
        lat decimal(10,7) DEFAULT NULL,
        lng decimal(10,7) DEFAULT NULL,
        status varchar(20) DEFAULT 'pending',
        splicer_user_id bigint(20) DEFAULT NULL,
        notes text DEFAULT NULL,
        dropbox_photo_url varchar(500) DEFAULT NULL,
        submitted_at datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY project_id (project_id),
        KEY status (status)
    ) {$charset_collate};";

    // Project members table
    $members_table = $wpdb->prefix . 'tasquatch_project_members';
    $sql2 = "CREATE TABLE {$members_table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        project_id bigint(20) NOT NULL,
        user_id bigint(20) DEFAULT NULL,
        role varchar(30) NOT NULL,
        invited_by bigint(20) DEFAULT NULL,
        invited_email varchar(255) DEFAULT NULL,
        invite_token varchar(64) DEFAULT NULL,
        status varchar(20) DEFAULT 'pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        accepted_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        KEY project_id (project_id),
        KEY user_id (user_id),
        KEY invite_token (invite_token),
        KEY status (status)
    ) {$charset_collate};";

    // Worker-company relationship table
    $workers_table = $wpdb->prefix . 'tasquatch_workers';
    $sql3 = "CREATE TABLE {$workers_table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        splicing_company_user_id bigint(20) NOT NULL,
        worker_user_id bigint(20) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY splicing_company_user_id (splicing_company_user_id),
        KEY worker_user_id (worker_user_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    dbDelta( $sql2 );
    dbDelta( $sql3 );

    update_option( 'telecom_db_version', TELECOM_DB_VERSION );
}


// ============================================================
// DB: Run upgrade check on every load (handles deployments
//     that bypass the activation hook, e.g. WP CLI, rsync)
// ============================================================

add_action( 'plugins_loaded', 'telecom_run_db_upgrades' );

// Ensure roles always exist — runs on every load if missing
add_action( 'plugins_loaded', 'tasquatch_ensure_roles' );

function tasquatch_ensure_roles() {
    if ( ! get_role( 'splicing_company' ) ) {
        add_role( 'splicing_company', 'Splicing Company', [
            'read'         => true,
            'edit_posts'   => false,
            'upload_files' => true,
        ] );
    }
    if ( ! get_role( 'worker' ) ) {
        add_role( 'worker', 'Worker', [
            'read'         => true,
            'edit_posts'   => false,
            'upload_files' => false,
        ] );
    }
    if ( ! get_role( 'gc' ) ) {
        add_role( 'gc', 'General Contractor', [
            'read'         => true,
            'edit_posts'   => false,
            'upload_files' => true,
        ] );
    }
}

function telecom_run_db_upgrades() {
    $installed = get_option( 'telecom_db_version', '0' );

    if ( version_compare( $installed, TELECOM_DB_VERSION, '<' ) ) {
        telecom_create_tables();
    }

    // Future schema changes:
    // if ( version_compare( $installed, '1.1.0', '<' ) ) { ... }
}


// ============================================================
// CPT: Register 'project' post type
// ============================================================

add_action( 'init', 'telecom_register_project_cpt' );

function telecom_register_project_cpt() {

    // Prevent double-registration (called both from activation hook and init)
    if ( post_type_exists( 'project' ) ) {
        return;
    }

    $labels = [
        'name'               => 'Projects',
        'singular_name'      => 'Project',
        'add_new'            => 'Add New Project',
        'add_new_item'       => 'Add New Project',
        'edit_item'          => 'Edit Project',
        'new_item'           => 'New Project',
        'view_item'          => 'View Project',
        'search_items'       => 'Search Projects',
        'not_found'          => 'No projects found',
        'not_found_in_trash' => 'No projects found in trash',
        'menu_name'          => 'Telecom Projects',
    ];

    $args = [
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_rest'       => true,   // Required for ACF Pro REST field resolution
        'query_var'          => true,
        'rewrite'            => [ 'slug' => 'project', 'with_front' => false ],
        'capability_type'    => 'post',
        'map_meta_cap'       => true,   // Per-post cap checks so GCs can't edit each other's projects
        'has_archive'        => false,  // Dashboard shortcode replaces archive
        'hierarchical'       => false,
        'menu_position'      => 20,
        'menu_icon'          => 'dashicons-networking',
        'supports'           => [ 'title', 'author', 'custom-fields' ],
    ];

    register_post_type( 'project', $args );
}


// ============================================================
// USER ROLES: Register gc + splicer roles on activation
// ============================================================

register_activation_hook( __FILE__, 'telecom_register_roles' );

function telecom_register_roles() {

    // GC — general contractor
    add_role( 'gc', 'General Contractor', [
        'read'         => true,
        'edit_posts'   => false,
        'upload_files' => true,
    ] );

    // Splicing Company — subcontractor company owner
    add_role( 'splicing_company', 'Splicing Company', [
        'read'         => true,
        'edit_posts'   => false,
        'upload_files' => true,
    ] );

    // Worker — field splicer, managed by splicing company
    add_role( 'worker', 'Worker', [
        'read'         => true,
        'edit_posts'   => false,
        'upload_files' => false,
    ] );

    // Keep splicer role for backwards compatibility
    add_role( 'splicer', 'Splicer', [
        'read'         => true,
        'edit_posts'   => false,
        'upload_files' => false,
    ] );
}


// ============================================================
// STYLES: Enqueue plugin CSS
// ============================================================

add_action( 'wp_enqueue_scripts', 'telecom_enqueue_styles' );

// Allow KML/KMZ uploads globally for the plugin
add_filter( 'upload_mimes', 'telecom_allowed_mime_types' );
add_filter( 'wp_check_filetype_and_ext', 'telecom_check_filetype', 10, 4 );

function telecom_allowed_mime_types( $mimes ) {
    $mimes['kml'] = 'application/vnd.google-earth.kml+xml';
    $mimes['kmz'] = 'application/vnd.google-earth.kmz';
    return $mimes;
}

function telecom_check_filetype( $data, $file, $filename, $mimes ) {
    $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    if ( $ext === 'kml' ) {
        $data['ext']  = 'kml';
        $data['type'] = 'application/vnd.google-earth.kml+xml';
    }
    if ( $ext === 'kmz' ) {
        $data['ext']  = 'kmz';
        $data['type'] = 'application/vnd.google-earth.kmz';
    }
    return $data;
}

function telecom_enqueue_styles() {
    wp_enqueue_style(
        'telecom-architect',
        plugin_dir_url( __FILE__ ) . 'tasquatch.css',
        [],
        TELECOM_PLUGIN_VERSION
    );
}


// ============================================================
// REST ENDPOINTS: Register all routes
// ============================================================

add_action( 'rest_api_init', 'telecom_register_rest_routes' );

function telecom_register_rest_routes() {

    // --- Anthropic API proxy (existing) ---
    register_rest_route( 'telecom/v1', '/chat', [
        'methods'             => 'POST',
        'callback'            => 'telecom_chat_endpoint',
        'permission_callback' => 'telecom_require_logged_in',
    ] );

    // --- Provisioning status poll (frontend polling during project setup) ---
    register_rest_route( 'telecom/v1', '/project-status/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'telecom_project_status_endpoint',
        'permission_callback' => 'telecom_require_logged_in',
        'args'                => [
            'id' => [
                'validate_callback' => function( $param ) {
                    return is_numeric( $param );
                },
            ],
        ],
    ] );

    // --- n8n callback: provisioning complete ---
    register_rest_route( 'telecom/v1', '/provision-project', [
        'methods'             => 'POST',
        'callback'            => 'telecom_provision_project_endpoint',
        'permission_callback' => 'telecom_verify_n8n_secret',
    ] );

    // --- n8n callback: splice submission update ---
    register_rest_route( 'telecom/v1', '/splice-submission', [
        'methods'             => 'POST',
        'callback'            => 'telecom_splice_submission_endpoint',
        'permission_callback' => 'telecom_verify_n8n_secret',
    ] );

    // --- Map data: splice locations for a project ---
    register_rest_route( 'telecom/v1', '/project-locations/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'telecom_project_locations_endpoint',
        'permission_callback' => 'telecom_require_logged_in',
        'args'                => [
            'id' => [
                'validate_callback' => function( $param ) {
                    return is_numeric( $param );
                },
            ],
        ],
    ] );
}


// ============================================================
// PERMISSION CALLBACKS
// ============================================================

function telecom_require_logged_in() {
    return is_user_logged_in();
}

function telecom_verify_n8n_secret( WP_REST_Request $request ) {
    $secret = defined( 'N8N_WEBHOOK_SECRET' ) ? N8N_WEBHOOK_SECRET : '';

    if ( empty( $secret ) ) {
        return new WP_Error( 'misconfigured', 'N8N_WEBHOOK_SECRET is not defined.', [ 'status' => 500 ] );
    }

    $provided = $request->get_header( 'X-Telecom-Secret' );

    if ( ! hash_equals( $secret, (string) $provided ) ) {
        return new WP_Error( 'forbidden', 'Invalid webhook secret.', [ 'status' => 403 ] );
    }

    return true;
}


// ============================================================
// REST CALLBACK: POST /telecom/v1/chat
// Anthropic API proxy for [telecom_architect] advisor chat
// ============================================================

function telecom_chat_endpoint( WP_REST_Request $request ) {

    if ( ! defined( 'ANTHROPIC_API_KEY' ) || empty( ANTHROPIC_API_KEY ) ) {
        return new WP_Error( 'misconfigured', 'ANTHROPIC_API_KEY is not defined.', [ 'status' => 500 ] );
    }

    $body     = $request->get_json_params();
    $messages = isset( $body['messages'] ) ? $body['messages'] : [];

    if ( empty( $messages ) || ! is_array( $messages ) ) {
        return new WP_Error( 'bad_request', 'messages array is required.', [ 'status' => 400 ] );
    }

    $payload = [
        'model'      => 'claude-opus-4-5',
        'max_tokens' => 1024,
        'system'     => 'You are a senior telecom infrastructure architect advisor. You help fiber optic project managers, GCs, and splicers with technical questions about fiber splicing projects, OTDR testing, splice planning, BEAD program compliance, and project reporting. Be concise and technical.',
        'messages'   => $messages,
    ];

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 60,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => ANTHROPIC_API_KEY,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'api_error', $response->get_error_message(), [ 'status' => 502 ] );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        $msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Anthropic API error.';
        return new WP_Error( 'anthropic_error', $msg, [ 'status' => $code ] );
    }

    return rest_ensure_response( $data );
}


// ============================================================
// REST CALLBACK: GET /telecom/v1/project-status/{id}
// Used by frontend JS to poll during provisioning
// ============================================================

function telecom_project_status_endpoint( WP_REST_Request $request ) {
    $project_id = absint( $request->get_param( 'id' ) );

    $post = get_post( $project_id );

    if ( ! $post || $post->post_type !== 'project' ) {
        return new WP_Error( 'not_found', 'Project not found.', [ 'status' => 404 ] );
    }

    // Access control: admins see all; GCs and members see their own projects
    $current_user = wp_get_current_user();
    $is_admin = in_array( 'administrator', $current_user->roles );
    if ( ! $is_admin ) {
        $gc_user_id = telecom_get_field( 'gc_user_id', $project_id );
        if ( (int) $gc_user_id !== (int) $current_user->ID ) {
            if ( ! tasquatch_user_can_access_project( $current_user->ID, $project_id ) ) {
                return new WP_Error( 'forbidden', 'Access denied.', [ 'status' => 403 ] );
            }
        }
    }

    $status       = telecom_get_field( 'status', $project_id );
    $splice_total = telecom_get_field( 'splice_total', $project_id );
    $sheet_url    = telecom_get_field( 'sheet_url', $project_id );
    $jotform_id   = telecom_get_field( 'jotform_form_id', $project_id );
    $dropbox_url  = telecom_get_field( 'dropbox_folder_url', $project_id );

    return rest_ensure_response( [
        'project_id'         => $project_id,
        'status'             => $status,
        'splice_total'       => (int) $splice_total,
        'title'              => get_the_title( $project_id ),
        'sheet_url'          => $sheet_url,
        'jotform_form_id'    => $jotform_id,
        'dropbox_folder_url' => $dropbox_url,
    ] );
}


// ============================================================
// REST CALLBACK: POST /telecom/v1/provision-project
// n8n calls this when provisioning succeeds or fails
// ============================================================

function telecom_provision_project_endpoint( WP_REST_Request $request ) {
    global $wpdb;

    $body       = $request->get_json_params();
    $project_id = isset( $body['project_id'] ) ? absint( $body['project_id'] ) : 0;
    $status     = isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : '';

    if ( ! $project_id || ! $status ) {
        return new WP_Error( 'bad_request', 'project_id and status are required.', [ 'status' => 400 ] );
    }

    $post = get_post( $project_id );
    if ( ! $post || $post->post_type !== 'project' ) {
        return new WP_Error( 'not_found', 'Project not found.', [ 'status' => 404 ] );
    }

    $allowed_statuses = [ 'active', 'failed' ];
    if ( ! in_array( $status, $allowed_statuses, true ) ) {
        return new WP_Error( 'bad_request', 'status must be active or failed.', [ 'status' => 400 ] );
    }

    // --- Handle failure ---
    if ( $status === 'failed' ) {
        telecom_update_field( 'status', 'failed', $project_id );

        $error_message = isset( $body['error_message'] ) ? sanitize_textarea_field( $body['error_message'] ) : 'Unknown error.';

        wp_mail(
            get_option( 'admin_email' ),
            "[Telecom Portal] Provisioning FAILED — Project #{$project_id}",
            "Project ID: {$project_id}\nTitle: " . get_the_title( $project_id ) . "\n\nError: {$error_message}\n\nLog in to investigate: " . admin_url( "post.php?post={$project_id}&action=edit" )
        );

        return rest_ensure_response( [ 'success' => true, 'status' => 'failed' ] );
    }

    // --- Handle success ---
    // Required fields from n8n payload
    $jotform_embed     = isset( $body['jotform_embed'] )     ? wp_kses( $body['jotform_embed'], [ 'iframe' => [ 'src' => [], 'width' => [], 'height' => [], 'frameborder' => [], 'style' => [], 'scrolling' => [], 'allowtransparency' => [], 'allowfullscreen' => [], 'id' => [], 'class' => [] ] ] ) : '';
    $jotform_form_id   = isset( $body['jotform_form_id'] )   ? sanitize_text_field( $body['jotform_form_id'] ) : '';
    $sheet_url         = isset( $body['sheet_url'] )         ? esc_url_raw( $body['sheet_url'] ) : '';
    $dropbox_folder    = isset( $body['dropbox_folder_url'] ) ? esc_url_raw( $body['dropbox_folder_url'] ) : '';
    $splice_total      = isset( $body['splice_total'] )      ? absint( $body['splice_total'] ) : 0;
    $map_center_lat    = isset( $body['map_center_lat'] )    ? floatval( $body['map_center_lat'] ) : 0;
    $map_center_lng    = isset( $body['map_center_lng'] )    ? floatval( $body['map_center_lng'] ) : 0;
    $splice_locations  = isset( $body['splice_locations'] )  ? $body['splice_locations'] : [];

    // Update ACF fields
    // Respect billing toggle — pending_payment if billing on, active if off
    $new_status = tasquatch_billing_enabled() ? 'pending_payment' : 'active';
    telecom_update_field( 'status', $new_status, $project_id );
    telecom_update_field( 'jotform_embed',     $jotform_embed,   $project_id );
    telecom_update_field( 'jotform_form_id',   $jotform_form_id, $project_id );
    telecom_update_field( 'sheet_url',         $sheet_url,       $project_id );
    telecom_update_field( 'dropbox_folder_url', $dropbox_folder, $project_id );
    telecom_update_field( 'splice_total',      $splice_total,    $project_id );
    telecom_update_field( 'map_center_lat',    $map_center_lat,  $project_id );
    telecom_update_field( 'map_center_lng',    $map_center_lng,  $project_id );

    // Pre-populate wp_splice_submissions — one row per splice location
    // n8n sends: [{ "name": "SP-001", "lat": 44.123, "lng": -89.456 }, ...]
    if ( ! empty( $splice_locations ) && is_array( $splice_locations ) ) {
        $table = $wpdb->prefix . 'splice_submissions';

        foreach ( $splice_locations as $location ) {
            $name = isset( $location['name'] ) ? sanitize_text_field( $location['name'] ) : '';
            $lat  = isset( $location['lat'] )  ? floatval( $location['lat'] ) : null;
            $lng  = isset( $location['lng'] )  ? floatval( $location['lng'] ) : null;

            if ( empty( $name ) ) {
                continue;
            }

            // Check if row already exists (idempotent — safe to re-provision)
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE project_id = %d AND splice_location = %s",
                    $project_id,
                    $name
                )
            );

            if ( ! $exists ) {
                $wpdb->insert(
                    $table,
                    [
                        'project_id'     => $project_id,
                        'splice_location' => $name,
                        'lat'            => $lat,
                        'lng'            => $lng,
                        'status'         => 'pending',
                    ],
                    [ '%d', '%s', '%f', '%f', '%s' ]
                );
            }
        }
    }

    return rest_ensure_response( [
        'success'    => true,
        'status'     => 'active',
        'project_id' => $project_id,
    ] );
}


// ============================================================
// REST CALLBACK: POST /telecom/v1/splice-submission
// n8n calls this after JotForm submission is processed
// ============================================================

function telecom_splice_submission_endpoint( WP_REST_Request $request ) {
    global $wpdb;

    $body           = $request->get_json_params();
    $project_id     = isset( $body['project_id'] )       ? absint( $body['project_id'] ) : 0;
    $splice_location = isset( $body['splice_location'] ) ? sanitize_text_field( $body['splice_location'] ) : '';
    $splicer_user_id = isset( $body['splicer_user_id'] ) ? absint( $body['splicer_user_id'] ) : null;
    $notes          = isset( $body['notes'] )             ? sanitize_textarea_field( $body['notes'] ) : '';
    $photo_url      = isset( $body['dropbox_photo_url'] ) ? esc_url_raw( $body['dropbox_photo_url'] ) : '';
    $submitted_at   = isset( $body['submitted_at'] )     ? sanitize_text_field( $body['submitted_at'] ) : current_time( 'mysql' );

    if ( ! $project_id || ! $splice_location ) {
        return new WP_Error( 'bad_request', 'project_id and splice_location are required.', [ 'status' => 400 ] );
    }

    $table = $wpdb->prefix . 'splice_submissions';

    $updated = $wpdb->update(
        $table,
        [
            'status'           => 'complete',
            'splicer_user_id'  => $splicer_user_id,
            'notes'            => $notes,
            'dropbox_photo_url' => $photo_url,
            'submitted_at'     => $submitted_at,
        ],
        [
            'project_id'      => $project_id,
            'splice_location' => $splice_location,
        ],
        [ '%s', '%d', '%s', '%s', '%s' ],
        [ '%d', '%s' ]
    );

    if ( $updated === false ) {
        // $wpdb->last_error has details — log it and alert
        $err = $wpdb->last_error;
        wp_mail(
            get_option( 'admin_email' ),
            "[Telecom Portal] Splice submission DB update FAILED — Project #{$project_id}",
            "Project ID: {$project_id}\nSplice Location: {$splice_location}\nDB Error: {$err}\n\nRaw payload:\n" . wp_json_encode( $body )
        );
        return new WP_Error( 'db_error', 'Failed to update submission record.', [ 'status' => 500 ] );
    }

    if ( $updated === 0 ) {
        // Row didn't exist — this means provisioning missed it or location name mismatch
        wp_mail(
            get_option( 'admin_email' ),
            "[Telecom Portal] Splice submission — no matching row — Project #{$project_id}",
            "Project ID: {$project_id}\nSplice Location: {$splice_location}\n\nNo row was updated. Location name may not match pre-populated records.\n\nRaw payload:\n" . wp_json_encode( $body )
        );
        return new WP_Error( 'not_found', "No pending record found for location: {$splice_location}", [ 'status' => 404 ] );
    }

    return rest_ensure_response( [
        'success'        => true,
        'project_id'     => $project_id,
        'splice_location' => $splice_location,
        'rows_updated'   => $updated,
    ] );
}


// ============================================================
// REST CALLBACK: GET /telecom/v1/project-locations/{id}
// Returns all splice locations for the map
// ============================================================

function telecom_project_locations_endpoint( WP_REST_Request $request ) {
    global $wpdb;

    $project_id = absint( $request->get_param( 'id' ) );

    $post = get_post( $project_id );
    if ( ! $post || $post->post_type !== 'project' ) {
        return new WP_Error( 'not_found', 'Project not found.', [ 'status' => 404 ] );
    }

    // Access control: GCs see their own projects; splicers see assigned projects
    $current_user = wp_get_current_user();
    if ( ! in_array( 'administrator', $current_user->roles ) ) {
        if ( in_array( 'gc', $current_user->roles ) ) {
            $gc_user_id = telecom_get_field( 'gc_user_id', $project_id );
            if ( (int) $gc_user_id !== (int) $current_user->ID ) {
                return new WP_Error( 'forbidden', 'Access denied.', [ 'status' => 403 ] );
            }
        } elseif ( in_array( 'splicer', $current_user->roles ) ) {
            $assigned = telecom_get_field( 'assigned_splicers', $project_id );
            $assigned_ids = is_array( $assigned ) ? array_map( 'absint', $assigned ) : [];
            if ( ! in_array( (int) $current_user->ID, $assigned_ids, true ) ) {
                return new WP_Error( 'forbidden', 'Access denied.', [ 'status' => 403 ] );
            }
        }
    }

    $table   = $wpdb->prefix . 'splice_submissions';
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT splice_location, lat, lng, status, splicer_user_id, submitted_at, dropbox_photo_url
             FROM {$table}
             WHERE project_id = %d
             ORDER BY splice_location ASC",
            $project_id
        ),
        ARRAY_A
    );

    // Resolve splicer display names
    foreach ( $results as &$row ) {
        if ( ! empty( $row['splicer_user_id'] ) ) {
            $user = get_userdata( (int) $row['splicer_user_id'] );
            $row['splicer_name'] = $user ? $user->display_name : 'Unknown';
        } else {
            $row['splicer_name'] = '';
        }
        $row['lat'] = $row['lat'] !== null ? floatval( $row['lat'] ) : null;
        $row['lng'] = $row['lng'] !== null ? floatval( $row['lng'] ) : null;
    }
    unset( $row );

    return rest_ensure_response( $results );
}

// ============================================================
// SHORTCODE: [telecom_project_map project_id="X"]
// Google Maps embed with red/green splice markers
// ============================================================

add_shortcode( 'telecom_project_map', 'telecom_project_map_shortcode' );

function telecom_project_map_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '<p>Please log in.</p>';
    }

    $atts = shortcode_atts( [ 'project_id' => 0 ], $atts, 'telecom_project_map' );
    $project_id = absint( $atts['project_id'] );

    if ( ! $project_id ) {
        $project_id = get_the_ID();
    }

    if ( ! $project_id ) {
        return '<p>No project ID specified.</p>';
    }

    if ( ! defined( 'GOOGLE_MAPS_API_KEY' ) || empty( GOOGLE_MAPS_API_KEY ) ) {
        return '<p>Google Maps API key is not configured.</p>';
    }

    $map_center_lat = floatval( telecom_get_field( 'map_center_lat', $project_id ) );
    $map_center_lng = floatval( telecom_get_field( 'map_center_lng', $project_id ) );

    if ( ! $map_center_lat && ! $map_center_lng ) {
        $map_center_lat = 44.0;
        $map_center_lng = -89.0;
    }

    $nonce         = wp_create_nonce( 'wp_rest' );
    $locations_url = rest_url( 'telecom/v1/project-locations/' . $project_id );
    $api_key       = GOOGLE_MAPS_API_KEY;

    ob_start();
    ?>
    <div class="tl-map-wrapper">
        <div class="tl-map-toolbar">
            <div class="tl-map-legend">
                <span class="tl-legend-dot tl-legend-complete"></span> Complete
                <span class="tl-legend-dot tl-legend-pending" style="margin-left:12px;"></span> Pending
            </div>
            <div class="tl-map-stats">
                <span id="tl-map-complete-count-<?php echo $project_id; ?>">—</span> complete &nbsp;·&nbsp;
                <span id="tl-map-pending-count-<?php echo $project_id; ?>">—</span> pending
            </div>
            <button id="tl-map-refresh-<?php echo $project_id; ?>" class="tl-btn tl-btn-secondary tl-btn-sm">↻ Refresh</button>
        </div>
        <div id="tl-map-<?php echo esc_attr( $project_id ); ?>" class="tl-map-canvas"></div>
        <div id="tl-map-error-<?php echo esc_attr( $project_id ); ?>" class="tl-map-error" style="display:none;">
            Failed to load map data. Please refresh.
        </div>
    </div>

    <script>
    (function() {
        var projectId    = <?php echo (int) $project_id; ?>;
        var mapCenter    = { lat: <?php echo (float) $map_center_lat; ?>, lng: <?php echo (float) $map_center_lng; ?> };
        var locationsUrl = <?php echo wp_json_encode( $locations_url ); ?>;
        var nonce        = <?php echo wp_json_encode( $nonce ); ?>;
        var mapEl        = document.getElementById('tl-map-' + projectId);
        var errorEl      = document.getElementById('tl-map-error-' + projectId);
        var map, markers = [], activeInfoWindow = null;

        function initMap() {
            map = new google.maps.Map(mapEl, {
                zoom:      13,
                center:    mapCenter,
                mapTypeId: 'roadmap',
                mapId:     'DEMO_MAP_ID',
                styles: [
                    { featureType: 'poi', stylers: [{ visibility: 'off' }] },
                    { featureType: 'transit', stylers: [{ visibility: 'off' }] }
                ]
            });
            loadMarkers();
        }

        function clearMarkers() {
            markers.forEach(function(m) { m.setMap(null); });
            markers = [];
            if (activeInfoWindow) { activeInfoWindow.close(); activeInfoWindow = null; }
        }

        function loadMarkers() {
            fetch(locationsUrl, {
                headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
            })
            .then(function(res) {
                if (!res.ok) throw new Error('Failed to load locations');
                return res.json();
            })
            .then(function(locations) {
                clearMarkers();
                var completeCount = 0, pendingCount = 0;

                locations.forEach(function(loc) {
                    if (!loc.lat || !loc.lng) return;
                    var isComplete = loc.status === 'complete';
                    if (isComplete) completeCount++; else pendingCount++;

                    var marker = new google.maps.Marker({
                        position: { lat: parseFloat(loc.lat), lng: parseFloat(loc.lng) },
                        map:      map,
                        title:    loc.splice_location,
                        icon: {
                            path:        google.maps.SymbolPath.CIRCLE,
                            scale:       8,
                            fillColor:   isComplete ? '#22c55e' : '#ef4444',
                            fillOpacity: 1,
                            strokeColor: isComplete ? '#16a34a' : '#dc2626',
                            strokeWeight: 1.5
                        }
                    });

                    var content = '<div class="tl-map-popup">';
                    content += '<strong>' + loc.splice_location + '</strong>';
                    content += '<span class="tl-map-popup-badge ' + (isComplete ? 'tl-map-popup-complete' : 'tl-map-popup-pending') + '">';
                    content += isComplete ? 'Complete' : 'Pending';
                    content += '</span>';
                    if (isComplete) {
                        if (loc.splicer_name) content += '<div class="tl-map-popup-row">Splicer: ' + loc.splicer_name + '</div>';
                        if (loc.submitted_at) content += '<div class="tl-map-popup-row">Submitted: ' + loc.submitted_at + '</div>';
                        if (loc.dropbox_photo_url) content += '<div class="tl-map-popup-row"><a href="' + loc.dropbox_photo_url + '" target="_blank">View photo</a></div>';
                    }
                    content += '</div>';

                    var infoWindow = new google.maps.InfoWindow({ content: content });
                    marker.addListener('click', function() {
                        if (activeInfoWindow) activeInfoWindow.close();
                        activeInfoWindow = infoWindow;
                        infoWindow.open(map, marker);
                    });
                    markers.push(marker);
                });

                document.getElementById('tl-map-complete-count-' + projectId).textContent = completeCount;
                document.getElementById('tl-map-pending-count-' + projectId).textContent  = pendingCount;
                errorEl.style.display = 'none';
            })
            .catch(function(err) {
                console.error('Map load error:', err);
                errorEl.style.display = 'block';
            });
        }

        document.getElementById('tl-map-refresh-' + projectId).addEventListener('click', loadMarkers);

        if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
            initMap();
        } else {
            var callbackName = 'tlMapInit_' + projectId;
            window[callbackName] = initMap;
            var script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js?key=<?php echo esc_js( $api_key ); ?>&callback=' + callbackName + '&loading=async&v=weekly';
            script.async = true;
            script.defer = true;
            document.head.appendChild(script);
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}



// ============================================================
// SHORTCODE: [telecom_architect]
// AI advisor chat interface — Anthropic API proxy frontend
// ============================================================

add_shortcode( 'telecom_architect', 'telecom_architect_shortcode' );

function telecom_architect_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please log in to access the Telecom Architect advisor.</p>';
    }

    wp_enqueue_script(
        'telecom-architect-chat',
        plugin_dir_url( __FILE__ ) . 'telecom-architect-chat.js',
        [],
        TELECOM_PLUGIN_VERSION,
        true
    );

    wp_localize_script( 'telecom-architect-chat', 'telecomArchitect', [
        'restUrl' => rest_url( 'telecom/v1/chat' ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
    ] );

    ob_start();
    ?>
    <div id="telecom-architect-chat" class="telecom-chat-wrapper">
        <div class="telecom-chat-messages" id="telecom-chat-messages">
            <div class="telecom-chat-message assistant">
                <strong>Telecom Architect</strong>
                <p>Hello! I'm your fiber optic project advisor. Ask me anything about splice planning, OTDR testing, BEAD compliance, or project reporting.</p>
            </div>
        </div>
        <div class="telecom-chat-input-row">
            <textarea id="telecom-chat-input" placeholder="Ask a question..." rows="2"></textarea>
            <button id="telecom-chat-send">Send</button>
        </div>
        <div id="telecom-chat-error" class="telecom-chat-error" style="display:none;"></div>
    </div>

    <script>
    (function() {
        const messagesEl = document.getElementById('telecom-chat-messages');
        const inputEl    = document.getElementById('telecom-chat-input');
        const sendBtn    = document.getElementById('telecom-chat-send');
        const errorEl    = document.getElementById('telecom-chat-error');
        const history    = [];

        function appendMessage(role, text) {
            const div    = document.createElement('div');
            div.className = 'telecom-chat-message ' + role;
            const label  = document.createElement('strong');
            label.textContent = role === 'user' ? 'You' : 'Telecom Architect';
            const p      = document.createElement('p');
            p.textContent = text;
            div.appendChild(label);
            div.appendChild(p);
            messagesEl.appendChild(div);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        function showError(msg) {
            errorEl.textContent = msg;
            errorEl.style.display = 'block';
            setTimeout(() => { errorEl.style.display = 'none'; }, 6000);
        }

        async function sendMessage() {
            const text = inputEl.value.trim();
            if (!text) return;

            inputEl.value   = '';
            sendBtn.disabled = true;
            appendMessage('user', text);
            history.push({ role: 'user', content: text });

            try {
                const res = await fetch(telecomArchitect.restUrl, {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce':   telecomArchitect.nonce,
                    },
                    body: JSON.stringify({ messages: history }),
                });

                const data = await res.json();

                if (!res.ok) {
                    const msg = data.message || 'An error occurred.';
                    showError('Error: ' + msg);
                    history.pop();
                    return;
                }

                const reply = data.content && data.content[0] ? data.content[0].text : '(no response)';
                history.push({ role: 'assistant', content: reply });
                appendMessage('assistant', reply);

            } catch (err) {
                showError('Network error. Please try again.');
                history.pop();
            } finally {
                sendBtn.disabled = false;
                inputEl.focus();
            }
        }

        sendBtn.addEventListener('click', sendMessage);
        inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}


// ============================================================
// SHORTCODE: [telecom_add_project]
// KML/KMZ upload modal — creates project post, triggers n8n
// ============================================================

add_shortcode( 'telecom_add_project', 'telecom_add_project_shortcode' );

function telecom_add_project_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please log in.</p>';
    }

    $current_user = wp_get_current_user();
    $allowed_roles = [ 'administrator', 'gc' ];
    if ( ! array_intersect( $allowed_roles, $current_user->roles ) ) {
        return '<p>You do not have permission to create projects.</p>';
    }

    ob_start();

    $telecom_config = [
        'restUrl'   => rest_url( 'telecom/v1/add-project' ),
        'statusUrl' => rest_url( 'telecom/v1/project-status/' ),
        'nonce'     => wp_create_nonce( 'wp_rest' ),
    ];
    ?>
    <script>
    var telecomAddProject = <?php echo wp_json_encode( $telecom_config ); ?>;
    </script>

    <!-- Add Project Button -->
    <button id="telecom-open-modal" class="telecom-btn telecom-btn-primary">+ Add New Project</button>

    <!-- Modal Overlay -->
    <div id="telecom-modal-overlay" class="telecom-modal-overlay" style="display:none;">
        <div class="telecom-modal">
            <div class="telecom-modal-header">
                <h2>New Project</h2>
                <button id="telecom-modal-close" class="telecom-modal-close">&times;</button>
            </div>

            <!-- Step 1: Upload Form -->
            <div id="telecom-modal-form-step">
                <div class="telecom-form-group">
                    <label for="telecom-project-name">Project Name <span class="required">*</span></label>
                    <input type="text" id="telecom-project-name" placeholder="e.g. Wausau Ring — Zone 4" />
                </div>
                <div class="telecom-form-group">
                    <label for="telecom-kml-file">KML / KMZ File <span class="required">*</span></label>
                    <input type="file" id="telecom-kml-file" accept=".kml,.kmz" />
                    <p class="telecom-field-hint">Export your splice point map from Google Earth or your GIS tool as KML or KMZ.</p>
                </div>
                <div id="telecom-form-error" class="telecom-error-msg" style="display:none;"></div>
                <div class="telecom-modal-footer">
                    <button id="telecom-submit-project" class="telecom-btn telecom-btn-primary">Create Project</button>
                    <button id="telecom-cancel-modal" class="telecom-btn telecom-btn-secondary">Cancel</button>
                </div>
            </div>

            <!-- Step 2: Provisioning status -->
            <div id="telecom-modal-provisioning-step" style="display:none;">
                <div class="telecom-provisioning-status">
                    <div id="telecom-provision-spinner" class="telecom-spinner"></div>
                    <p id="telecom-provision-message">Your project is being created. This usually takes 30–60 seconds...</p>
                    <div id="telecom-provision-progress" class="telecom-provision-progress">
                        <div id="telecom-provision-progress-bar" class="telecom-provision-progress-bar"></div>
                    </div>
                </div>
                <!-- Shown on failure -->
                <div id="telecom-provision-error" style="display:none;">
                    <p class="telecom-error-msg">Project setup failed. Our team has been notified.</p>
                    <button id="telecom-retry-btn" class="telecom-btn telecom-btn-secondary">Try Again</button>
                </div>
            </div>

            <!-- Step 3: Success -->
            <div id="telecom-modal-success-step" style="display:none;">
                <div class="telecom-success-msg">
                    <span class="telecom-success-icon">&#10003;</span>
                    <h3>Project Ready!</h3>
                    <p id="telecom-success-details"></p>
                    <button id="telecom-view-project" class="telecom-btn telecom-btn-primary">View Project</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function($) {
        // --- DOM refs ---
        const overlay       = document.getElementById('telecom-modal-overlay');
        const openBtn       = document.getElementById('telecom-open-modal');
        const closeBtn      = document.getElementById('telecom-modal-close');
        const cancelBtn     = document.getElementById('telecom-cancel-modal');
        const submitBtn     = document.getElementById('telecom-submit-project');
        const retryBtn      = document.getElementById('telecom-retry-btn');
        const viewBtn       = document.getElementById('telecom-view-project');
        const formStep      = document.getElementById('telecom-modal-form-step');
        const provStep      = document.getElementById('telecom-modal-provisioning-step');
        const successStep   = document.getElementById('telecom-modal-success-step');
        const formError     = document.getElementById('telecom-form-error');
        const provMessage   = document.getElementById('telecom-provision-message');
        const provError     = document.getElementById('telecom-provision-error');
        const provSpinner   = document.getElementById('telecom-provision-spinner');
        const progressBar   = document.getElementById('telecom-provision-progress-bar');
        const successDetail = document.getElementById('telecom-success-details');

        let pollInterval  = null;
        let pollCount     = 0;
        let currentProjectId = null;
        let currentProjectUrl = null;
        const POLL_INTERVAL_MS = 4000;
        const POLL_MAX         = 60; // 4 min timeout

        // --- Modal open/close ---
        openBtn.addEventListener('click', () => {
            overlay.style.display = 'flex';
            showStep('form');
        });

        function closeModal() {
            overlay.style.display = 'none';
            stopPolling();
            resetForm();
        }

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal();
        });

        retryBtn.addEventListener('click', function() {
            showStep('form');
        });

        viewBtn.addEventListener('click', function() {
            if (currentProjectUrl) {
                window.location.href = currentProjectUrl;
            }
        });

        // --- Show/hide steps ---
        function showStep(step) {
            formStep.style.display    = step === 'form'        ? 'block' : 'none';
            provStep.style.display    = step === 'provisioning' ? 'block' : 'none';
            successStep.style.display = step === 'success'     ? 'block' : 'none';
        }

        // --- Form validation + submit ---
        submitBtn.addEventListener('click', function() {
            const name = document.getElementById('telecom-project-name').value.trim();
            const file = document.getElementById('telecom-kml-file').files[0];

            formError.style.display = 'none';

            if (!name) {
                showFormError('Project name is required.');
                return;
            }
            if (!file) {
                showFormError('Please select a KML or KMZ file.');
                return;
            }
            const ext = file.name.split('.').pop().toLowerCase();
            if (!['kml', 'kmz'].includes(ext)) {
                showFormError('Only .kml and .kmz files are accepted.');
                return;
            }

            submitProject(name, file);
        });

        function showFormError(msg) {
            formError.textContent = msg;
            formError.style.display = 'block';
        }

        function resetForm() {
            document.getElementById('telecom-project-name').value = '';
            document.getElementById('telecom-kml-file').value = '';
            formError.style.display = 'none';
            provError.style.display = 'none';
            pollCount = 0;
            currentProjectId = null;
            currentProjectUrl = null;
            progressBar.style.width = '0%';
        }

        // --- Submit project via REST ---
        async function submitProject(name, file) {
            submitBtn.disabled = true;
            showStep('provisioning');
            provMessage.textContent = 'Uploading file and creating project...';
            provError.style.display = 'none';
            provSpinner.style.display = 'block';

            const formData = new FormData();
            formData.append('project_name', name);
            formData.append('kml_file', file);

            try {
                const res = await fetch(telecomAddProject.restUrl, {
                    method: 'POST',
                    headers: { 'X-WP-Nonce': telecomAddProject.nonce },
                    body: formData,
                });

                const data = await res.json();

                if (!res.ok) {
                    handleProvisionError(data.message || 'Failed to create project.');
                    return;
                }

                currentProjectId  = data.project_id;
                currentProjectUrl = data.project_url;
                provMessage.textContent = 'Project created. Waiting for setup to complete...';
                startPolling(data.project_id);

            } catch(err) {
                handleProvisionError('Network error. Please try again.');
            } finally {
                submitBtn.disabled = false;
            }
        }

        // --- Polling ---
        function startPolling(projectId) {
            pollCount = 0;
            pollInterval = setInterval(() => pollStatus(projectId), POLL_INTERVAL_MS);
        }

        function stopPolling() {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }

        async function pollStatus(projectId) {
            pollCount++;

            // Animate progress bar (fake progress, caps at 90% until done)
            const progress = Math.min(90, (pollCount / POLL_MAX) * 100);
            progressBar.style.width = progress + '%';

            if (pollCount >= POLL_MAX) {
                stopPolling();
                handleProvisionError('Setup is taking longer than expected. Please refresh the page or contact support.');
                return;
            }

            try {
                const res = await fetch(telecomAddProject.statusUrl + projectId, {
                    headers: { 'X-WP-Nonce': telecomAddProject.nonce },
                });
                const data = await res.json();

                if (data.status === 'active') {
                    stopPolling();
                    progressBar.style.width = '100%';
                    successDetail.textContent = data.title + ' is ready with ' + data.splice_total + ' splice locations loaded.';
                    showStep('success');
                } else if (data.status === 'failed') {
                    stopPolling();
                    handleProvisionError('Project setup failed. Our team has been notified.');
                }
                // status === 'provisioning' — keep polling

            } catch(err) {
                // Network hiccup — keep polling, don't abort
                console.warn('Poll error:', err);
            }
        }

        function handleProvisionError(msg) {
            provSpinner.style.display = 'none';
            provError.querySelector('p').textContent = msg;
            provError.style.display = 'block';
        }

    })(jQuery);
    </script>
    <?php
    return ob_get_clean();
}


// ============================================================
// REST ENDPOINT: POST /telecom/v1/add-project
// Handles KML upload, creates project CPT post, fires n8n webhook
// ============================================================

add_action( 'rest_api_init', function() {
    register_rest_route( 'telecom/v1', '/add-project', [
        'methods'             => 'POST',
        'callback'            => 'telecom_add_project_endpoint',
        'permission_callback' => 'telecom_can_create_project',
    ] );
} );

function telecom_can_create_project() {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    $user = wp_get_current_user();
    return array_intersect( [ 'administrator', 'gc' ], $user->roles );
}

function telecom_add_project_endpoint( WP_REST_Request $request ) {
    global $wpdb;

    // --- Validate inputs ---
    $project_name = sanitize_text_field( $request->get_param( 'project_name' ) );
    if ( empty( $project_name ) ) {
        return new WP_Error( 'bad_request', 'project_name is required.', [ 'status' => 400 ] );
    }

    $files = $request->get_file_params();
    if ( empty( $files['kml_file'] ) || $files['kml_file']['error'] !== UPLOAD_ERR_OK ) {
        return new WP_Error( 'bad_request', 'A valid KML or KMZ file is required.', [ 'status' => 400 ] );
    }

    $file = $files['kml_file'];
    $ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, [ 'kml', 'kmz' ], true ) ) {
        return new WP_Error( 'bad_request', 'Only .kml and .kmz files are accepted.', [ 'status' => 400 ] );
    }

    // --- Read file contents directly — no storage in WordPress ---
    $kml_contents = file_get_contents( $file['tmp_name'] );
    if ( $kml_contents === false ) {
        return new WP_Error( 'upload_failed', 'Could not read uploaded file.', [ 'status' => 500 ] );
    }

    // --- Create project CPT post immediately ---
    $current_user_id = get_current_user_id();

    $post_id = wp_insert_post( [
        'post_title'  => $project_name,
        'post_type'   => 'project',
        'post_status' => 'publish',
        'post_author' => $current_user_id,
    ], true );

    if ( is_wp_error( $post_id ) ) {
        return new WP_Error( 'post_failed', $post_id->get_error_message(), [ 'status' => 500 ] );
    }

    // --- Set ACF fields ---
    telecom_update_field( 'gc_user_id', $current_user_id, $post_id );
    telecom_update_field( 'status',     'provisioning',   $post_id );

    // --- Auto-add creator as project member based on their role ---
    $creator_role  = tasquatch_get_user_role( $current_user_id );
    $member_role   = in_array( $creator_role, [ 'gc', 'administrator' ] ) ? 'gc' : 'splicing_company';
    $members_table = $wpdb->prefix . 'tasquatch_project_members';
    $wpdb->insert( $members_table, [
        'project_id'    => $post_id,
        'user_id'       => $current_user_id,
        'role'          => $member_role,
        'invited_by'    => $current_user_id,
        'invited_email' => wp_get_current_user()->user_email,
        'status'        => 'accepted',
    ] );

    // --- Forward file + metadata to n8n as multipart ---
    $webhook_url = defined( 'N8N_PROVISION_WEBHOOK_URL' ) ? N8N_PROVISION_WEBHOOK_URL : '';

    if ( ! empty( $webhook_url ) ) {
        $webhook_secret = defined( 'N8N_WEBHOOK_SECRET' ) ? N8N_WEBHOOK_SECRET : '';

        $boundary = '----TelecomBoundary' . md5( time() );

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"project_id\"\r\n\r\n";
        $body .= $post_id . "\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"project_name\"\r\n\r\n";
        $body .= $project_name . "\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"gc_user_id\"\r\n\r\n";
        $body .= $current_user_id . "\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"kml_file\"; filename=\"" . sanitize_file_name( $file['name'] ) . "\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $kml_contents . "\r\n";

        $body .= "--{$boundary}--\r\n";

                $webhook_response = wp_remote_post( add_query_arg( [
    'project_id'   => $post_id,
    'project_name' => rawurlencode( $project_name ),
], $webhook_url ), [
            'timeout' => 15,
            'headers' => [
                'Content-Type'     => 'multipart/form-data; boundary=' . $boundary,
                'X-Telecom-Secret' => $webhook_secret,
            ],
            'body' => $body,
        ] );

        if ( is_wp_error( $webhook_response ) ) {
            error_log( '[Telecom] n8n webhook failed for project ' . $post_id . ': ' . $webhook_response->get_error_message() );
            wp_mail(
                get_option( 'admin_email' ),
                '[Telecom Portal] n8n webhook trigger FAILED — Project #' . $post_id,
                "Project ID: {$post_id}
Project Name: {$project_name}

Webhook Error: " . $webhook_response->get_error_message()
            );
        }
    } else {
        error_log( '[Telecom] N8N_PROVISION_WEBHOOK_URL is not defined. Project ' . $post_id . ' created but provisioning not triggered.' );
    }

        return rest_ensure_response( [
        'success'     => true,
        'project_id'  => $post_id,
        'project_url' => get_permalink( $post_id ),
        'status'      => 'provisioning',
    ] );
}


// ============================================================
// SHORTCODE: [telecom_project_list]
// GC / splicer dashboard project list
// Stub: Full implementation in Phase 1 Step 7
// ============================================================

add_shortcode( 'telecom_project_list', 'telecom_project_list_shortcode' );

function telecom_project_list_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<p>Please log in.</p>';
    }

    global $wpdb;
    $current_user = wp_get_current_user();
    $is_admin     = in_array( 'administrator', $current_user->roles );
    $user_role    = tasquatch_get_user_role( $current_user->ID );

    $allowed_roles = [ 'gc', 'splicing_company', 'administrator', 'splicer', 'worker' ];
    if ( ! in_array( $user_role, $allowed_roles ) ) {
        return '<p>You do not have permission to view this page.</p>';
    }

    $table         = $wpdb->prefix . 'splice_submissions';
    $members_table = $wpdb->prefix . 'tasquatch_project_members';

    // --- Query projects based on role ---
    if ( $is_admin ) {
        $args = [ 'post_type' => 'project', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC' ];
    } else {
        // Find all projects this user is a member of
        $member_project_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT project_id FROM {$members_table} WHERE user_id = %d AND status = 'accepted'",
            $current_user->ID
        ) );
        // Also projects where gc_user_id = current user (legacy)
        $gc_projects = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'project' AND p.post_status = 'publish'
             AND pm.meta_key = 'gc_user_id' AND pm.meta_value = %d",
            $current_user->ID
        ) );
        $all_project_ids = array_unique( array_merge( $member_project_ids, $gc_projects ) );
        if ( empty( $all_project_ids ) ) {
            return '<div class="telecom-project-list"><div class="telecom-empty-state"><p>No projects yet.</p></div></div>';
        }
        $args = [ 'post_type' => 'project', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC', 'post__in' => $all_project_ids ];
    }

    $projects = new WP_Query( $args );
    if ( ! $projects->have_posts() ) {
        return '<div class="telecom-project-list"><div class="telecom-empty-state"><p>No projects yet.</p></div></div>';
    }

    $status_labels = [
        'provisioning'    => [ 'label' => 'Setting up',  'class' => 'tl-badge-provisioning' ],
        'pending_payment' => [ 'label' => 'Awaiting Payment', 'class' => 'tl-badge-provisioning' ],
        'active'          => [ 'label' => 'Active',       'class' => 'tl-badge-active' ],
        'complete'        => [ 'label' => 'Complete',     'class' => 'tl-badge-complete' ],
        'failed'          => [ 'label' => 'Failed',       'class' => 'tl-badge-failed' ],
        'archived'        => [ 'label' => 'Archived',     'class' => 'tl-badge-archived' ],
    ];

    // Stats
    $project_ids       = wp_list_pluck( $projects->posts, 'ID' );
    $id_placeholders   = implode( ',', array_fill( 0, count( $project_ids ), '%d' ) );
    $completed_splices = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE project_id IN ({$id_placeholders}) AND status = 'complete'", ...$project_ids ) );
    $total_splices     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE project_id IN ({$id_placeholders})", ...$project_ids ) );
    $active_count      = 0;
    foreach ( $projects->posts as $p ) {
        if ( telecom_get_field( 'status', $p->ID ) === 'active' ) $active_count++;
    }

    ob_start();
    ?>
    <div class="telecom-project-list">
        <div class="tl-stats-row">
            <div class="tl-stat-card"><div class="tl-stat-label">Total Projects</div><div class="tl-stat-value"><?php echo count( $project_ids ); ?></div></div>
            <div class="tl-stat-card"><div class="tl-stat-label">Active</div><div class="tl-stat-value"><?php echo $active_count; ?></div></div>
            <div class="tl-stat-card"><div class="tl-stat-label">Splices Complete</div><div class="tl-stat-value"><?php echo $completed_splices; ?></div></div>
            <div class="tl-stat-card"><div class="tl-stat-label">Total Splices</div><div class="tl-stat-value"><?php echo $total_splices; ?></div></div>
        </div>
        <div class="tl-cards">
        <?php while ( $projects->have_posts() ) : $projects->the_post();
            $post_id      = get_the_ID();
            $status       = telecom_get_field( 'status', $post_id ) ?: 'provisioning';
            $splice_total = (int) telecom_get_field( 'splice_total', $post_id );
            $sheet_url    = telecom_get_field( 'sheet_url', $post_id );
            $dropbox_url  = telecom_get_field( 'dropbox_folder_url', $post_id );
            $created      = get_the_date( 'M j, Y' );
            $card_class   = 'tl-card-' . $status;

            $splice_completed = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE project_id = %d AND status = 'complete'", $post_id
            ) );
            $progress_pct = $splice_total > 0 ? round( ( $splice_completed / $splice_total ) * 100 ) : 0;

            // Splice dates
            $first_splice = $wpdb->get_var( $wpdb->prepare(
                "SELECT MIN(submitted_at) FROM {$table} WHERE project_id = %d AND status = 'complete' AND submitted_at IS NOT NULL", $post_id
            ) );
            $last_splice = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(submitted_at) FROM {$table} WHERE project_id = %d AND status = 'complete' AND submitted_at IS NOT NULL", $post_id
            ) );

            // Duration
            $duration_str = '';
            if ( $first_splice ) {
                $first_dt = new DateTime( $first_splice );
                if ( $splice_completed >= $splice_total && $last_splice ) {
                    $last_dt      = new DateTime( $last_splice );
                    $days         = $first_dt->diff( $last_dt )->days;
                    $duration_str = 'Completed in ' . $days . ' day' . ( $days !== 1 ? 's' : '' );
                } else {
                    $now  = new DateTime();
                    $days = $first_dt->diff( $now )->days;
                    $duration_str = $days . ' day' . ( $days !== 1 ? 's' : '' ) . ' in progress';
                }
            }

            // Project members — GC and Splicing Company pills
            $gc_members = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$members_table} WHERE project_id = %d AND role = 'gc'", $post_id
            ) );
            $sc_members = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$members_table} WHERE project_id = %d AND role = 'splicing_company'", $post_id
            ) );

            $has_gc = ! empty( $gc_members );
            $has_sc = ! empty( $sc_members );
            $show_invite = ! ( $has_gc && $has_sc );

            $badge = isset( $status_labels[$status] ) ? $status_labels[$status] : [ 'label' => ucfirst($status), 'class' => 'tl-badge-provisioning' ];
        ?>
        <div class="tl-project-card <?php echo esc_attr( $card_class ); ?>">
            <div class="tl-project-card-header">
                <div class="tl-project-title-row">
                    <h3 class="tl-project-name"><?php echo esc_html( get_the_title() ); ?></h3>
                    <span class="tl-badge <?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span>
                </div>
                <p class="tl-project-meta">Created <?php echo esc_html( $created ); ?></p>
            </div>

            <!-- Company pills -->
            <div class="tl-company-pills">
                <?php foreach ( $gc_members as $m ) :
                    $u = $m->user_id ? get_userdata( $m->user_id ) : null;
                    $name = $u ? ( get_user_meta( $u->ID, 'company_name', true ) ?: $u->display_name ) : $m->invited_email;
                    $pill_class = $m->status === 'accepted' ? 'tl-pill-accepted' : 'tl-pill-pending';
                ?>
                <span class="tl-company-pill <?php echo esc_attr( $pill_class ); ?>" title="General Contractor">
                    <?php echo esc_html( $name ); ?>
                </span>
                <?php endforeach; ?>
                <?php foreach ( $sc_members as $m ) :
                    $u = $m->user_id ? get_userdata( $m->user_id ) : null;
                    $name = $u ? ( get_user_meta( $u->ID, 'company_name', true ) ?: $u->display_name ) : $m->invited_email;
                    $pill_class = $m->status === 'accepted' ? 'tl-pill-accepted' : 'tl-pill-pending';
                ?>
                <span class="tl-company-pill <?php echo esc_attr( $pill_class ); ?>" title="Splicing Company">
                    <?php echo esc_html( $name ); ?>
                </span>
                <?php endforeach; ?>

            </div>

            <?php if ( $status === 'provisioning' || $status === 'pending_payment' ) : ?>
                <div class="tl-provisioning-notice"><span class="tl-spinner-small"></span>
                    <?php echo $status === 'pending_payment' ? 'Payment required to activate this project.' : 'Setting up your project…'; ?>
                </div>
            <?php elseif ( $status === 'failed' ) : ?>
                <div class="tl-failed-notice">Project setup failed. Please contact support.</div>
            <?php else : ?>
                <div class="tl-progress-section">
                    <div class="tl-progress-label">
                        <span><strong><?php echo $splice_completed; ?></strong> of <?php echo $splice_total; ?> splices complete</span>
                        <span><?php echo $progress_pct; ?>%</span>
                    </div>
                    <div class="tl-progress-bar-track">
                        <div class="tl-progress-bar-fill" style="width:<?php echo $progress_pct; ?>%;"></div>
                    </div>
                </div>

                <?php if ( $first_splice || $duration_str ) : ?>
                <div class="tl-splice-dates">
                    <?php if ( $first_splice ) : ?>
                    <span>First splice: <?php echo esc_html( date( 'M j, Y', strtotime( $first_splice ) ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $last_splice && $splice_completed >= $splice_total ) : ?>
                    <span>Last splice: <?php echo esc_html( date( 'M j, Y', strtotime( $last_splice ) ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $duration_str ) : ?>
                    <span class="tl-duration"><?php echo esc_html( $duration_str ); ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="tl-project-actions">
                    <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" class="tl-btn tl-btn-primary">View Project</a>
                    <?php if ( $sheet_url ) : ?><a href="<?php echo esc_url( $sheet_url ); ?>" target="_blank" class="tl-btn tl-btn-secondary">Open Report</a><?php endif; ?>
                    <?php if ( $dropbox_url ) : ?><a href="<?php echo esc_url( $dropbox_url ); ?>" target="_blank" class="tl-btn tl-btn-secondary">Photos</a><?php endif; ?>
                    <?php if ( $show_invite && in_array( $status, [ 'active', 'complete' ] ) ) : ?>
                    <button class="tl-btn tl-btn-secondary" onclick="tqOpenInviteModal(<?php echo (int)$post_id; ?>, '<?php echo esc_js( get_the_title() ); ?>')">+ Invite Contractor</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endwhile; wp_reset_postdata(); ?>
        </div>
    </div>

    <!-- Invite Contractor Modal -->
    <div id="tq-invite-modal-overlay" class="telecom-modal-overlay" style="display:none;">
        <div class="telecom-modal">
            <div class="telecom-modal-header">
                <h2>Invite Contractor</h2>
                <button onclick="tqCloseInviteModal()" class="telecom-modal-close">&times;</button>
            </div>
            <div id="tq-invite-modal-body">
                <div id="tq-invite-prev-list" style="margin-bottom:16px;display:none;">
                    <p style="font-size:13px;font-weight:500;color:#555;margin-bottom:8px;">Previously worked with:</p>
                    <div id="tq-invite-prev-pills"></div>
                </div>
                <div class="tq-form-group">
                    <label>Or enter email address</label>
                    <input type="email" id="tq-invite-email-input" placeholder="contractor@example.com">
                </div>
                <div id="tq-invite-modal-error" class="tq-alert tq-alert-error" style="display:none;"></div>
                <div id="tq-invite-modal-success" class="tq-alert tq-alert-success" style="display:none;"></div>
                <div class="telecom-modal-footer">
                    <button onclick="tqSendInvite()" class="tl-btn tl-btn-primary">Send Invite</button>
                    <button onclick="tqCloseInviteModal()" class="tl-btn tl-btn-secondary">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    var tqCurrentProjectId = null;
    var tqInviteNonce = '<?php echo wp_create_nonce( 'tq_invite_nonce' ); ?>';
    var tqInviteRestUrl = '<?php echo esc_js( rest_url( 'telecom/v1/invite-contractor' ) ); ?>';
    var tqPrevContractorsUrl = '<?php echo esc_js( rest_url( 'telecom/v1/previous-contractors' ) ); ?>';

    function tqOpenInviteModal(projectId, projectTitle) {
        tqCurrentProjectId = projectId;
        document.getElementById('tq-invite-modal-overlay').style.display = 'flex';
        document.getElementById('tq-invite-email-input').value = '';
        document.getElementById('tq-invite-modal-error').style.display = 'none';
        document.getElementById('tq-invite-modal-success').style.display = 'none';
        // Load previous contractors
        fetch(tqPrevContractorsUrl + '?project_id=' + projectId, {
            headers: { 'X-WP-Nonce': tqInviteNonce }
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.length > 0) {
                var pillsEl = document.getElementById('tq-invite-prev-pills');
                pillsEl.innerHTML = '';
                data.forEach(function(c) {
                    var pill = document.createElement('span');
                    pill.className = 'tl-company-pill tl-pill-accepted';
                    pill.style.cursor = 'pointer';
                    pill.textContent = c.company_name;
                    pill.onclick = function() {
                        document.getElementById('tq-invite-email-input').value = c.email;
                    };
                    pillsEl.appendChild(pill);
                });
                document.getElementById('tq-invite-prev-list').style.display = 'block';
            }
        });
    }

    function tqCloseInviteModal() {
        document.getElementById('tq-invite-modal-overlay').style.display = 'none';
        tqCurrentProjectId = null;
    }

    function tqSendInvite() {
        var email = document.getElementById('tq-invite-email-input').value.trim();
        var errEl = document.getElementById('tq-invite-modal-error');
        var sucEl = document.getElementById('tq-invite-modal-success');
        errEl.style.display = 'none';
        sucEl.style.display = 'none';

        if (!email) { errEl.textContent = 'Please enter an email address.'; errEl.style.display = 'block'; return; }

        fetch(tqInviteRestUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': tqInviteNonce },
            body: JSON.stringify({ project_id: tqCurrentProjectId, invite_email: email })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                sucEl.textContent = 'Invite sent to ' + email + '!';
                sucEl.style.display = 'block';
                setTimeout(function() { tqCloseInviteModal(); location.reload(); }, 2000);
            } else {
                errEl.textContent = data.message || 'Failed to send invite.';
                errEl.style.display = 'block';
            }
        })
        .catch(function() {
            errEl.textContent = 'Network error. Please try again.';
            errEl.style.display = 'block';
        });
    }

    document.getElementById('tq-invite-modal-overlay').addEventListener('click', function(e) {
        if (e.target === this) tqCloseInviteModal();
    });
    </script>
    <?php
    return ob_get_clean();
}


// ============================================================
// SHORTCODE: [tasquatch_worker_manager]
// Splicing company manages their workers
// ============================================================

add_shortcode( 'tasquatch_worker_manager', 'tasquatch_worker_manager_shortcode' );

function tasquatch_worker_manager_shortcode() {
    if ( ! is_user_logged_in() ) return '<p>Please log in.</p>';

    $current_user = wp_get_current_user();
    $role         = tasquatch_get_user_role( $current_user->ID );

    if ( ! in_array( $role, [ 'splicing_company', 'administrator' ] ) ) {
        return '<p>Only splicing companies can manage workers.</p>';
    }

    global $wpdb;
    $error   = '';
    $success = '';

    // Handle add worker
    if ( isset( $_POST['tasquatch_add_worker_nonce'] ) && wp_verify_nonce( $_POST['tasquatch_add_worker_nonce'], 'tasquatch_add_worker' ) ) {
        $first_name = sanitize_text_field( $_POST['worker_first_name'] ?? '' );
        $last_name  = sanitize_text_field( $_POST['worker_last_name'] ?? '' );
        $email      = sanitize_email( $_POST['worker_email'] ?? '' );

        if ( empty( $first_name ) || empty( $last_name ) ) {
            $error = 'First and last name are required.';
        } elseif ( ! is_email( $email ) ) {
            $error = 'A valid email address is required.';
        } elseif ( email_exists( $email ) ) {
            // User exists — just add to company
            $existing_user = get_user_by( 'email', $email );
            $workers_table = $wpdb->prefix . 'tasquatch_workers';
            $already = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$workers_table} WHERE splicing_company_user_id = %d AND worker_user_id = %d",
                $current_user->ID, $existing_user->ID
            ) );
            if ( $already ) {
                $error = 'That worker is already on your team.';
            } else {
                $wpdb->insert( $workers_table, [
                    'splicing_company_user_id' => $current_user->ID,
                    'worker_user_id'           => $existing_user->ID,
                ] );
                $success = $existing_user->display_name . ' has been added to your team.';
            }
        } else {
            // Create new user
            $temp_password = wp_generate_password( 12, false );
            $username      = sanitize_user( strtolower( $first_name . '.' . $last_name . rand( 10, 99 ) ) );
            while ( username_exists( $username ) ) {
                $username = sanitize_user( strtolower( $first_name . '.' . $last_name . rand( 100, 999 ) ) );
            }

            $worker_id = wp_create_user( $username, $temp_password, $email );

            if ( is_wp_error( $worker_id ) ) {
                $error = $worker_id->get_error_message();
            } else {
                $worker = new WP_User( $worker_id );
                $worker->set_role( 'worker' );
                update_user_meta( $worker_id, 'first_name', $first_name );
                update_user_meta( $worker_id, 'last_name', $last_name );
                wp_update_user( [ 'ID' => $worker_id, 'display_name' => $first_name . ' ' . $last_name ] );

                $workers_table = $wpdb->prefix . 'tasquatch_workers';
                $wpdb->insert( $workers_table, [
                    'splicing_company_user_id' => $current_user->ID,
                    'worker_user_id'           => $worker_id,
                ] );

                $company_name = get_user_meta( $current_user->ID, 'company_name', true ) ?: $current_user->display_name;
                tasquatch_send_worker_created_email( $email, $company_name, $temp_password, wp_login_url() );
                $success = "{$first_name} {$last_name} has been added to your team and sent a login email.";
            }
        }
    }

    // Handle remove worker
    if ( isset( $_POST['tasquatch_remove_worker_nonce'] ) && wp_verify_nonce( $_POST['tasquatch_remove_worker_nonce'], 'tasquatch_remove_worker' ) ) {
        $worker_id     = absint( $_POST['worker_id'] ?? 0 );
        $workers_table = $wpdb->prefix . 'tasquatch_workers';
        $wpdb->delete( $workers_table, [
            'splicing_company_user_id' => $current_user->ID,
            'worker_user_id'           => $worker_id,
        ] );
        $success = 'Worker removed from your team.';
    }

    $workers = tasquatch_get_workers_for_company( $current_user->ID );

    ob_start();
    ?>
    <div class="tq-worker-manager">
        <div class="tq-section-header">
            <h3>My Workers</h3>
        </div>

        <?php if ( $error ) : ?>
            <div class="tq-alert tq-alert-error"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>
        <?php if ( $success ) : ?>
            <div class="tq-alert tq-alert-success"><?php echo esc_html( $success ); ?></div>
        <?php endif; ?>

        <!-- Add Worker Form -->
        <div class="tq-add-worker-form">
            <h4>Add a Worker</h4>
            <form method="post">
                <?php wp_nonce_field( 'tasquatch_add_worker', 'tasquatch_add_worker_nonce' ); ?>
                <div class="tq-form-row">
                    <div class="tq-form-group">
                        <label>First Name</label>
                        <input type="text" name="worker_first_name" required>
                    </div>
                    <div class="tq-form-group">
                        <label>Last Name</label>
                        <input type="text" name="worker_last_name" required>
                    </div>
                    <div class="tq-form-group">
                        <label>Email Address</label>
                        <input type="email" name="worker_email" required>
                    </div>
                    <div class="tq-form-group tq-form-group-btn">
                        <button type="submit" class="tl-btn tl-btn-primary">Add Worker</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Worker List -->
        <?php if ( empty( $workers ) ) : ?>
            <p class="tq-empty">No workers yet. Add your first worker above.</p>
        <?php else : ?>
            <table class="tq-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $workers as $worker ) : ?>
                    <tr>
                        <td><?php echo esc_html( $worker->display_name ); ?></td>
                        <td><?php echo esc_html( $worker->user_email ); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'tasquatch_remove_worker', 'tasquatch_remove_worker_nonce' ); ?>
                                <input type="hidden" name="worker_id" value="<?php echo esc_attr( $worker->ID ); ?>">
                                <button type="submit" class="tl-btn tl-btn-secondary tl-btn-sm"
                                    onclick="return confirm('Remove this worker from your team?')">Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


// ============================================================
// SHORTCODE: [tasquatch_invite_contractor project_id="X"]
// Invite a GC or Splicing Company to a project
// ============================================================

add_shortcode( 'tasquatch_invite_contractor', 'tasquatch_invite_contractor_shortcode' );

function tasquatch_invite_contractor_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) return '<p>Please log in.</p>';

    $atts       = shortcode_atts( [ 'project_id' => 0 ], $atts );
    $project_id = absint( $atts['project_id'] ) ?: get_the_ID();

    if ( ! $project_id ) return '<p>No project specified.</p>';

    $current_user = wp_get_current_user();
    $role         = tasquatch_get_user_role( $current_user->ID );

    if ( ! tasquatch_user_can_access_project( $current_user->ID, $project_id ) ) {
        return '<p>Access denied.</p>';
    }

    global $wpdb;
    $error   = '';
    $success = '';

    if ( isset( $_POST['tasquatch_invite_nonce'] ) && wp_verify_nonce( $_POST['tasquatch_invite_nonce'], 'tasquatch_invite_' . $project_id ) ) {
        $invite_email = sanitize_email( $_POST['invite_email'] ?? '' );

        if ( ! is_email( $invite_email ) ) {
            $error = 'Please enter a valid email address.';
        } elseif ( $invite_email === $current_user->user_email ) {
            $error = 'You cannot invite yourself.';
        } else {
            $members_table = $wpdb->prefix . 'tasquatch_project_members';
            $project_title = get_the_title( $project_id );
            $inviter_name  = $current_user->display_name;

            // Determine invite role — opposite of inviter
            $invite_role = in_array( $role, [ 'gc' ] ) ? 'splicing_company' : 'gc';

            // Check if already invited
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$members_table} WHERE project_id = %d AND invited_email = %s",
                $project_id, $invite_email
            ) );

            if ( $existing ) {
                $error = 'That email has already been invited to this project.';
            } else {
                $invite_token = bin2hex( random_bytes( 32 ) );
                $existing_user = get_user_by( 'email', $invite_email );

                $wpdb->insert( $members_table, [
                    'project_id'    => $project_id,
                    'user_id'       => $existing_user ? $existing_user->ID : null,
                    'role'          => $invite_role,
                    'invited_by'    => $current_user->ID,
                    'invited_email' => $invite_email,
                    'invite_token'  => $invite_token,
                    'status'        => 'pending',
                ] );

                $accept_url  = add_query_arg( [
                    'tasquatch_accept' => $invite_token,
                ], home_url( '/dashboard' ) );

                $is_new_user = ! $existing_user;
                tasquatch_send_invite_email( $invite_email, $inviter_name, $project_title, $accept_url, $is_new_user );

                $success = "Invite sent to {$invite_email}.";
            }
        }
    }

    // Current members
    $members = tasquatch_get_project_members( $project_id, null, null );

    ob_start();
    ?>
    <div class="tq-invite-section">
        <h4>Invite Contractor</h4>

        <?php if ( $error ) : ?>
            <div class="tq-alert tq-alert-error"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>
        <?php if ( $success ) : ?>
            <div class="tq-alert tq-alert-success"><?php echo esc_html( $success ); ?></div>
        <?php endif; ?>

        <form method="post" class="tq-invite-form">
            <?php wp_nonce_field( 'tasquatch_invite_' . $project_id, 'tasquatch_invite_nonce' ); ?>
            <div class="tq-form-row">
                <div class="tq-form-group">
                    <label>Email address</label>
                    <input type="email" name="invite_email" placeholder="contractor@example.com" required>
                </div>
                <div class="tq-form-group tq-form-group-btn">
                    <button type="submit" class="tl-btn tl-btn-primary">Send Invite</button>
                </div>
            </div>
        </form>

        <?php if ( ! empty( $members ) ) : ?>
        <div class="tq-member-list">
            <h5>Project Members</h5>
            <table class="tq-table">
                <thead>
                    <tr><th>Name / Email</th><th>Role</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $members as $member ) :
                    $member_user = $member->user_id ? get_userdata( $member->user_id ) : null;
                    $display     = $member_user ? $member_user->display_name : $member->invited_email;
                    $role_label  = $member->role === 'gc' ? 'General Contractor' : 'Splicing Company';
                    $status_class = $member->status === 'accepted' ? 'tl-badge-complete' : 'tl-badge-provisioning';
                ?>
                <tr>
                    <td><?php echo esc_html( $display ); ?></td>
                    <td><?php echo esc_html( $role_label ); ?></td>
                    <td><span class="tl-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( ucfirst( $member->status ) ); ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


// ============================================================
// SHORTCODE: [tasquatch_assign_workers project_id="X"]
// Splicing company assigns their workers to a project
// ============================================================

add_shortcode( 'tasquatch_assign_workers', 'tasquatch_assign_workers_shortcode' );

function tasquatch_assign_workers_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) return '<p>Please log in.</p>';

    $atts       = shortcode_atts( [ 'project_id' => 0 ], $atts );
    $project_id = absint( $atts['project_id'] ) ?: get_the_ID();
    if ( ! $project_id ) return '<p>No project specified.</p>';

    $current_user = wp_get_current_user();
    $role         = tasquatch_get_user_role( $current_user->ID );

    if ( ! in_array( $role, [ 'splicing_company', 'administrator' ] ) ) {
        return '';
    }

    if ( ! tasquatch_user_can_access_project( $current_user->ID, $project_id ) ) {
        return '<p>Access denied.</p>';
    }

    global $wpdb;
    $success = '';

    if ( isset( $_POST['tasquatch_assign_nonce'] ) && wp_verify_nonce( $_POST['tasquatch_assign_nonce'], 'tasquatch_assign_' . $project_id ) ) {
        $worker_id     = absint( $_POST['worker_id'] ?? 0 );
        $action        = sanitize_text_field( $_POST['assign_action'] ?? 'assign' );
        $members_table = $wpdb->prefix . 'tasquatch_project_members';

        if ( $action === 'assign' ) {
            $already = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$members_table} WHERE project_id = %d AND user_id = %d AND role = 'worker'",
                $project_id, $worker_id
            ) );
            if ( ! $already ) {
                $worker = get_userdata( $worker_id );
                $wpdb->insert( $members_table, [
                    'project_id'    => $project_id,
                    'user_id'       => $worker_id,
                    'role'          => 'worker',
                    'invited_by'    => $current_user->ID,
                    'invited_email' => $worker ? $worker->user_email : '',
                    'status'        => 'accepted',
                ] );
            }
            tasquatch_sync_assigned_splicers( $project_id );
            $success = 'Worker assigned to project.';
        } elseif ( $action === 'unassign' ) {
            $wpdb->delete( $members_table, [
                'project_id' => $project_id,
                'user_id'    => $worker_id,
                'role'       => 'worker',
            ] );
            tasquatch_sync_assigned_splicers( $project_id );
            $success = 'Worker removed from project.';
        }
    }

    $my_workers       = tasquatch_get_workers_for_company( $current_user->ID );
    $assigned_members = tasquatch_get_project_members( $project_id, 'worker', 'accepted' );
    $assigned_ids     = array_column( $assigned_members, 'user_id' );

    ob_start();
    ?>
    <div class="tq-assign-workers">
        <h4>Assign Workers</h4>
        <?php if ( $success ) : ?>
            <div class="tq-alert tq-alert-success"><?php echo esc_html( $success ); ?></div>
        <?php endif; ?>

        <?php if ( empty( $my_workers ) ) : ?>
            <p class="tq-empty">You have no workers yet. <a href="<?php echo esc_url( home_url( '/workers' ) ); ?>">Add workers to your team</a> first.</p>
        <?php else : ?>
            <table class="tq-table">
                <thead>
                    <tr><th>Worker</th><th>Status</th><th>Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ( $my_workers as $worker ) :
                    $is_assigned = in_array( $worker->ID, $assigned_ids );
                ?>
                <tr>
                    <td><?php echo esc_html( $worker->display_name ); ?></td>
                    <td>
                        <span class="tl-badge <?php echo $is_assigned ? 'tl-badge-complete' : 'tl-badge-archived'; ?>">
                            <?php echo $is_assigned ? 'Assigned' : 'Not assigned'; ?>
                        </span>
                    </td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field( 'tasquatch_assign_' . $project_id, 'tasquatch_assign_nonce' ); ?>
                            <input type="hidden" name="worker_id" value="<?php echo esc_attr( $worker->ID ); ?>">
                            <input type="hidden" name="assign_action" value="<?php echo $is_assigned ? 'unassign' : 'assign'; ?>">
                            <button type="submit" class="tl-btn tl-btn-secondary tl-btn-sm">
                                <?php echo $is_assigned ? 'Remove' : 'Assign'; ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}


// ============================================================
// ACCEPT INVITE — runs on every page load, checks for token
// ============================================================

add_action( 'template_redirect', 'tasquatch_handle_invite_accept' );

function tasquatch_handle_invite_accept() {
    $token = sanitize_text_field( $_GET['tasquatch_accept'] ?? '' );
    if ( ! $token ) return;

    global $wpdb;
    $members_table = $wpdb->prefix . 'tasquatch_project_members';

    $member = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$members_table} WHERE invite_token = %s AND status = 'pending'",
        $token
    ) );

    if ( ! $member ) return;

    // If not logged in — redirect to register/login with token preserved
    if ( ! is_user_logged_in() ) {
        wp_redirect( add_query_arg( 'invite', $token, home_url( '/register' ) ) );
        exit;
    }

    tasquatch_accept_invite_by_token( $token, get_current_user_id() );
    wp_redirect( get_permalink( $member->project_id ) );
    exit;
}

function tasquatch_accept_invite_by_token( $token, $user_id ) {
    global $wpdb;
    $members_table = $wpdb->prefix . 'tasquatch_project_members';

    $member = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$members_table} WHERE invite_token = %s",
        $token
    ) );

    if ( ! $member ) return false;

    $wpdb->update(
        $members_table,
        [
            'user_id'     => $user_id,
            'status'      => 'accepted',
            'accepted_at' => current_time( 'mysql' ),
        ],
        [ 'invite_token' => $token ]
    );

    return true;
}


// ============================================================
// REST: GET pending invites for current user
// ============================================================

add_action( 'rest_api_init', function() {
    register_rest_route( 'telecom/v1', '/my-invites', [
        'methods'             => 'GET',
        'callback'            => 'tasquatch_my_invites_endpoint',
        'permission_callback' => 'telecom_require_logged_in',
    ] );
} );

function tasquatch_my_invites_endpoint() {
    global $wpdb;
    $user          = wp_get_current_user();
    $members_table = $wpdb->prefix . 'tasquatch_project_members';

    $invites = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$members_table} WHERE invited_email = %s AND status = 'pending'",
        $user->user_email
    ) );

    $result = [];
    foreach ( $invites as $invite ) {
        $inviter      = get_userdata( $invite->invited_by );
        $result[] = [
            'id'            => $invite->id,
            'project_id'    => $invite->project_id,
            'project_title' => get_the_title( $invite->project_id ),
            'invited_by'    => $inviter ? $inviter->display_name : 'Unknown',
            'role'          => $invite->role,
            'accept_url'    => add_query_arg( 'tasquatch_accept', $invite->invite_token, home_url( '/dashboard' ) ),
        ];
    }

    return rest_ensure_response( $result );
}

// ============================================================
// REST: POST /telecom/v1/invite-contractor
// Modal invite submission
// ============================================================

add_action( 'rest_api_init', function() {
    register_rest_route( 'telecom/v1', '/invite-contractor', [
        'methods'             => 'POST',
        'callback'            => 'tasquatch_invite_contractor_endpoint',
        'permission_callback' => 'telecom_require_logged_in',
    ] );
    register_rest_route( 'telecom/v1', '/previous-contractors', [
        'methods'             => 'GET',
        'callback'            => 'tasquatch_previous_contractors_endpoint',
        'permission_callback' => 'telecom_require_logged_in',
    ] );
} );

function tasquatch_invite_contractor_endpoint( WP_REST_Request $request ) {
    global $wpdb;

    $body         = $request->get_json_params();
    $project_id   = absint( $body['project_id'] ?? 0 );
    $invite_email = sanitize_email( $body['invite_email'] ?? '' );
    $current_user = wp_get_current_user();

    if ( ! $project_id || ! is_email( $invite_email ) ) {
        return new WP_Error( 'bad_request', 'project_id and valid invite_email are required.', [ 'status' => 400 ] );
    }

    if ( $invite_email === $current_user->user_email ) {
        return new WP_Error( 'bad_request', 'You cannot invite yourself.', [ 'status' => 400 ] );
    }

    if ( ! tasquatch_user_can_access_project( $current_user->ID, $project_id ) ) {
        return new WP_Error( 'forbidden', 'Access denied.', [ 'status' => 403 ] );
    }

    $members_table = $wpdb->prefix . 'tasquatch_project_members';
    $project_title = get_the_title( $project_id );
    $inviter_name  = get_user_meta( $current_user->ID, 'company_name', true ) ?: $current_user->display_name;

    // Determine invite role
    $my_role     = tasquatch_get_user_role( $current_user->ID );
    $invite_role = in_array( $my_role, [ 'gc' ] ) ? 'splicing_company' : 'gc';

    // Check already invited
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$members_table} WHERE project_id = %d AND invited_email = %s",
        $project_id, $invite_email
    ) );
    if ( $existing ) {
        return new WP_Error( 'already_invited', 'That email has already been invited to this project.', [ 'status' => 400 ] );
    }

    $invite_token  = bin2hex( random_bytes( 32 ) );
    $existing_user = get_user_by( 'email', $invite_email );

    $wpdb->insert( $members_table, [
        'project_id'    => $project_id,
        'user_id'       => $existing_user ? $existing_user->ID : null,
        'role'          => $invite_role,
        'invited_by'    => $current_user->ID,
        'invited_email' => $invite_email,
        'invite_token'  => $invite_token,
        'status'        => 'pending',
    ] );

    $accept_url = add_query_arg( [ 'tasquatch_accept' => $invite_token ], home_url( '/dashboard' ) );
    tasquatch_send_invite_email( $invite_email, $inviter_name, $project_title, $accept_url, ! $existing_user );

    return rest_ensure_response( [ 'success' => true, 'message' => 'Invite sent.' ] );
}

function tasquatch_previous_contractors_endpoint( WP_REST_Request $request ) {
    global $wpdb;

    $current_user  = wp_get_current_user();
    $project_id    = absint( $request->get_param( 'project_id' ) );
    $members_table = $wpdb->prefix . 'tasquatch_project_members';
    $my_role       = tasquatch_get_user_role( $current_user->ID );
    $other_role    = in_array( $my_role, [ 'gc' ] ) ? 'splicing_company' : 'gc';

    // Find all projects current user has been on
    $my_projects = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT project_id FROM {$members_table} WHERE user_id = %d AND status = 'accepted'",
        $current_user->ID
    ) );

    if ( empty( $my_projects ) ) return rest_ensure_response( [] );

    $placeholders = implode( ',', array_fill( 0, count( $my_projects ), '%d' ) );

    // Find contractors of the other role on those projects
    $contractors = $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT user_id FROM {$members_table}
         WHERE project_id IN ({$placeholders}) AND role = %s AND status = 'accepted' AND user_id != %d",
        ...array_merge( $my_projects, [ $other_role, $current_user->ID ] )
    ) );

    $result = [];
    foreach ( $contractors as $c ) {
        if ( ! $c->user_id ) continue;
        $u = get_userdata( $c->user_id );
        if ( ! $u ) continue;
        $company = get_user_meta( $u->ID, 'company_name', true ) ?: $u->display_name;

        // Don't show if already on this project
        if ( $project_id ) {
            $on_project = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$members_table} WHERE project_id = %d AND user_id = %d",
                $project_id, $u->ID
            ) );
            if ( $on_project ) continue;
        }

        $result[] = [
            'user_id'      => $u->ID,
            'company_name' => $company,
            'email'        => $u->user_email,
        ];
    }

    return rest_ensure_response( $result );
}

// ============================================================
// Update assign_workers to also update assigned_splicers ACF
// ============================================================

function tasquatch_sync_assigned_splicers( $project_id ) {
    global $wpdb;
    $members_table = $wpdb->prefix . 'tasquatch_project_members';

    $worker_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT user_id FROM {$members_table} WHERE project_id = %d AND role = 'worker' AND status = 'accepted'",
        $project_id
    ) );

    telecom_update_field( 'assigned_splicers', array_map( 'intval', $worker_ids ), $project_id );
}
