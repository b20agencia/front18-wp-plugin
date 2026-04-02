<?php
// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * 🧹 Rotina Automática de Limpeza de Produto (Nível SaaS)
 * Protege o banco de dados do cliente (wp_options e wp_postmeta) 
 * caso ele decida remover o plugin por definitivo no painel administrativo do WordPress.
 */

// 1. Limpa todas as opções escalares isoladas (Global Scope Settings)
$options = array(
    'front18_enabled',
    'front18_api_key',
    'front18_scope_global',
    'front18_scope_home',
    'front18_include_ids',
    'front18_exclude_ids',
    'front18_debug_mode',
    'front18_sdk_url',
    'front18_global_object',
    'front18_token_key'
    // [MANUTENÇÃO FUTURA] - Para garantir a escalabilidade, inclua nesse array o nome de novos campos introduzidos em versões avançadas.
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// 2. Limpa toda e qualquer array dinâmica gerada baseada em Post Types (Custom Scopes)
$post_types = get_post_types( array( 'public' => true ) );
foreach ( $post_types as $cpt ) {
    delete_option( 'front18_scope_cpt_' . $cpt );
}

// 3. Limpeza Extrema (Garbage Collection): Varre Tabelas de Metadados órfãs no banco
function front18_delete_all_post_meta_orphans() {
    global $wpdb;
    
    // Deleta os overrides de bypass criados nas Meta Boxes da barra lateral do Post
    // [MANUTENÇÃO FUTURA] - Caso a estrutura de metas evolua, aplique varreduras com "LIKE" aqui ou adicione múltiplas requisições limitadas a metakeys especificas.
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_front18_protect'" );
}
front18_delete_all_post_meta_orphans();

// Fim da desinstalação segura e blindada.
