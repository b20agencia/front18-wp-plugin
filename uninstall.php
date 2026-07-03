<?php
// Se não for chamado pelo WordPress, aborta.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * 🧹 Rotina Automática de Limpeza de Produto (Nível SaaS)
 * Protege o banco de dados do cliente (wp_options e wp_postmeta)
 * caso ele decida remover o plugin por definitivo no painel administrativo do WordPress.
 *
 * Mantido sincronizado com todas as opções criadas em seusdk-wp-plugin.php
 */

// 1. Limpa todas as opções escalares e arrays do plugin (incluindo opções das versões 1.0.x e 1.1.0)
$options = array(
    // Configuração principal
    'front18_enabled',
    'front18_api_key',
    'front18_debug_mode',
    'front18_sdk_url',
    'front18_global_object',
    'front18_token_key',

    // Escopo por ID (herdado da v1.0.x)
    'front18_include_ids',
    'front18_exclude_ids',

    // Regras sincronizadas via SaaS (introduzidas na v1.0.1+)
    'front18_synced_rules',
    'front18_synced_config',
    'front18_protected_media_ids',
    'front18_ghost_media_dict',
    'front18_last_sync',

    // Escopo legado (v1.0.0) — substituídas por front18_synced_rules na v1.1.0
    'front18_scope_global',
    'front18_scope_home',

    // [MANUTENÇÃO FUTURA] - Para garantir a escalabilidade, inclua nesse array
    // o nome de novos campos introduzidos em versões avançadas.
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// 2. Remove opções dinâmicas baseadas em Custom Post Types (formato legado v1.0.0)
$post_types = get_post_types( array( 'public' => true ) );
foreach ( $post_types as $cpt ) {
    delete_option( 'front18_scope_cpt_' . $cpt );
}

// 3. Remove transients de cache gerados pelo Ghost Tracker (v1.1.0+)
delete_transient( 'front18_ghost_tracker_cache' );

// 4. Garbage Collection: Remove metadados de posts (overrides de Meta Box)
global $wpdb;
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
        '_front18_protect'
    )
);

// Fim da desinstalação segura e completa.
