<?php
/**
 * Plugin Name: Front18 Security Integration
 * Plugin URI:  https://front18.com/wordpress
 * Description: O motor de segurança definitiva (Anti-Flicker) para o SDK Front18. Isola e protege seu conteúdo sensível antes mesmo da página renderizar.
 * Version:     1.0.1
 * Author:      Front18 Engineering
 * Author URI:  https://front18.com
 * Text Domain: front18
 */

// Se for chamado diretamente por um script alheio/hacker, o WordPress aborta a execução instantaneamente.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// ==============================================================================
// 1. Constantes do Plugin
// ==============================================================================
define( 'FRONT18_VERSION', '1.0.1' );
define( 'FRONT18_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRONT18_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FRONT18_OPTION_GROUP', 'front18_options_group' );

// ==============================================================================
// 2. Integração com GitHub: Plugin Update Checker (Atualizações Automáticas)
// ==============================================================================
$puc_path = FRONT18_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

// Verificamos de modo seguro se a biblioteca foi baixada/inserida na pasta para não quebrar o site
if ( file_exists( $puc_path ) ) {
    require_once $puc_path;
    
    $myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://raw.githubusercontent.com/b20agencia/front18-wp-plugin/main/update.json',
        __FILE__,
        'front18-wp-plugin'
    );
} // Fim P.U.C

// ==============================================================================
// 3. Hooks de Ativação e Desativação
// ==============================================================================

/**
 * 🚀 Rotina Executável de Instalação (Activation Hook Nível Produto SaaS)
 * Protege o banco de dados do cliente de quebras por Syntax Error caso usem versões antigas.
 */
function front18_activate_plugin() {
    
    // 1. Checagem de Ambiente Mínimo (Evita a Tela Branca Da Morte Fatal em Servidores Arcaicos)
    if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
        wp_die( 
            __( '<strong>Bloqueio de Segurança Habilitado:</strong> O motor do Front18 Security necessita obrigatoriamente de um Backend rodando PHP 7.4 ou superior para lidar com segurança criptográfica e resiliência de cache.<br><br>Sua versão atual é arcaica ('.PHP_VERSION.') e vulnerável. Atualize o seu painel de hospedagem!', 'front18' ), 
            __( 'Front18 Activation Error', 'front18' ), 
            array( 'back_link' => true ) 
        );
    }

    // 2. Seed Mestre Segura 
    // Usamos add_option invés de update. Assim apenas gravamos os Defaults Prontos pro SaaS SE eles não existirem no BD cliente.
    // Isso garante que se o cliente desativar e reativar o plugin, o progresso/regras dele sejam preservadas!
    add_option( 'front18_enabled', 0 ); // O Plugin deve nascer sempre bloqueado (Disabled = 0) para o cliente não cegar o site sem querer
    add_option( 'front18_sdk_url', 'https://front18.com/public/sdk/front18.js' );
    add_option( 'front18_global_object', 'Front18' );
    add_option( 'front18_token_key', 'api-key' );
}
// Registra o gancho apenas se o Admin clicar explicitamente em "Ativar Plugin" no Repositório do wp-admin
register_activation_hook( __FILE__, 'front18_activate_plugin' );


/**
 * 🔻 Rotina Não-Destrutiva de Desativação
 * Útil para limpar agendamentos (Cron), reescrever regras de cache temporárias ou flush_rewrite_rules
 */
function front18_deactivate_plugin() {
    // Flush de regras é necessário se em versões futuras utilizarmos CPT (Custom Post Types públicos) ou Permalinks
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'front18_deactivate_plugin' );


// ==============================================================================
// 4. Carregamento e Inicialização Inteligente (Eficiência de Memória / Escalabilidade)
// ==============================================================================

function front18_integration_init() {
    
    // A API precisa escutar todas as rotas (frontend/backend) logo ela deve ser inicializada na árvore root
    require_once FRONT18_PLUGIN_DIR . 'includes/class-seusdk-api.php';
    $plugin_api = new Front18_API();
    $plugin_api->init();

    // Carrega Lógica Administrativa SOMENTE quando o usuário está executando o Painel do Admin
    if ( is_admin() ) {
        require_once FRONT18_PLUGIN_DIR . 'includes/class-seusdk-admin.php';
        $plugin_admin = new Front18_Admin();
        $plugin_admin->init();
    } 
    // Caso contrário carrega as lógicas da Interface Pública (Frontend), poupando RAM no Painel e vis-versa.
    else {
        require_once FRONT18_PLUGIN_DIR . 'includes/class-seusdk-frontend.php';
        $plugin_frontend = new Front18_Frontend();
        $plugin_frontend->init();
    }
}
add_action('plugins_loaded', 'front18_integration_init');
