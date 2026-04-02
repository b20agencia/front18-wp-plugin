<?php

class Front18_Admin {

    public function init() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        
        // AJAX Endpoints
        add_action( 'wp_ajax_front18_search_posts', array( $this, 'ajax_search_posts' ) );
        add_action( 'wp_ajax_front18_sync_now', array( $this, 'ajax_sync_now' ) );
        
        // Meta Box for Individual Pages
        add_action( 'add_meta_boxes', array( $this, 'add_post_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_post_meta_boxes' ) );
    }

    public function register_settings() {
        register_setting( 'front18_options_group', 'front18_enabled', 'rest_sanitize_boolean' );
        register_setting( 'front18_options_group', 'front18_api_key', 'sanitize_text_field' );
        register_setting( 'front18_options_group', 'front18_debug_mode', 'rest_sanitize_boolean' );
        register_setting( 'front18_options_group', 'front18_sdk_url', array( $this, 'sanitize_sdk_url' ) );
        register_setting( 'front18_options_group', 'front18_global_object', 'sanitize_text_field' );
        register_setting( 'front18_options_group', 'front18_token_key', 'sanitize_text_field' );
    }

    public function sanitize_ids( $input ) {
        if ( empty( $input ) ) return '';
        if ( is_array( $input ) ) {
            $clean_ids = array_map( 'intval', $input );
            return implode( ',', $clean_ids );
        }
        $ids = explode( ',', $input );
        $clean_ids = array();
        foreach ( $ids as $id ) {
            $id = trim( $id );
            if ( is_numeric( $id ) ) $clean_ids[] = intval( $id );
        }
        return implode( ',', $clean_ids );
    }

    public function sanitize_sdk_url( $url ) {
        $clean_url = esc_url_raw( $url );
        $parsed = wp_parse_url($clean_url);

        // Nível Produto SaaS: Regex estrito autorizando servidores Oficiais (incluindo subdomínios nativos B2B e VDS)
        if ( empty($parsed['host']) || !preg_match('/(^|\.)(front18\.com|b20robots\.com\.br|bulafacil\.com)$/', $parsed['host']) ) {
            return 'https://front18.com/public/sdk/front18.js';
        }
        return $clean_url;
    }

    public function add_menu_page() {
        add_menu_page(
            __( 'Front18', 'front18' ),
            __( 'Front18', 'front18' ),
            'manage_options',
            'front18-integration',
            array( $this, 'render_admin_page' ),
            'dashicons-shield',
            80
        );
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( 'toplevel_page_front18-integration' !== $hook ) return;

        // Select2 for beautiful multi-selection
        wp_enqueue_script( 'jquery' );
        wp_enqueue_style( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css' );
        wp_enqueue_script( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true );

        wp_localize_script( 'select2', 'front18_ajax', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'front18_admin_nonce' )
        ));

        wp_add_inline_style( 'wp-admin', '
            .front18-admin-wrap { max-width: 850px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
            .front18-glass-panel { background: #0f172a; border-radius: 16px; padding: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.05); color: #f8fafc; position: relative; overflow: hidden; }
            .front18-glass-panel::before { content: ""; position: absolute; top: -100px; right: -100px; width: 300px; height: 300px; background: radial-gradient(circle, rgba(59,130,246,0.15) 0%, rgba(0,0,0,0) 70%); border-radius: 50%; pointer-events: none; }
            
            .front18-header { text-align: center; margin-bottom: 25px; }
            .front18-header h1 { font-size: 32px; font-weight: 800; background: linear-gradient(135deg, #f87171, #f43f5e); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin: 0 0 10px; border:none; padding:0; line-height:1.2; }
            .front18-header p { font-size: 15px; color: #94a3b8; margin: 0; }
            
            /* Status Badge */
            .front18-status-box { text-align: center; margin-bottom: 30px; }
            .front18-badge { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 40px; font-weight: 600; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
            .badge-on { background: rgba(16, 185, 129, 0.1); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.2); box-shadow: 0 0 15px rgba(16, 185, 129, 0.1); }
            .badge-off { background: rgba(100, 116, 139, 0.1); color: #94a3b8; border: 1px solid rgba(100, 116, 139, 0.2); }
            .badge-err { background: rgba(239, 68, 68, 0.1); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.2); box-shadow: 0 0 15px rgba(239, 68, 68, 0.1); }

            /* Cards */
            .front18-card { background: rgba(30, 41, 59, 0.5); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 25px; margin-bottom: 25px; transition: transform 0.2s, box-shadow 0.2s; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
            .front18-card:hover { border-color: rgba(255,255,255,0.1); box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
            .front18-card h2 { margin: 0 0 5px; font-size: 18px; font-weight: 600; color: #f8fafc; border: none; padding: 0; }
            .front18-card .card-desc { margin: 0 0 20px; color: #94a3b8; font-size: 13px; }
            
            .front18-row { display: flex; align-items: center; justify-content: space-between; padding: 15px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
            .front18-row:last-child { border-bottom: none; padding-bottom: 0; }
            .front18-row-focus { background: rgba(15, 23, 42, 0.5); padding: 15px; border-radius: 8px; margin-bottom: 10px; border: 1px solid rgba(244, 63, 94, 0.1); }
            .front18-col { flex: 1; padding-right: 20px; }
            .front18-row-title { font-weight: 600; font-size: 15px; color: #e2e8f0; }
            .front18-row-desc { font-size: 13px; color: #64748b; margin-top: 4px; line-height: 1.5; }
            
            /* Toggles Modernized */
            .front18-switch { position: relative; display: inline-block; width: 50px; height: 26px; flex-shrink: 0; }
            .front18-switch input { opacity: 0; width: 0; height: 0; }
            .front18-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(100,116,139,0.3); transition: .4s; border-radius: 30px; border: 1px solid rgba(255,255,255,0.05); }
            .front18-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: #94a3b8; transition: .4s cubic-bezier(0.175, 0.885, 0.32, 1.275); border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
            .front18-switch input:checked + .front18-slider { background-color: #f43f5e; border-color: #f43f5e; box-shadow: 0 0 10px rgba(244,63,94,0.3); }
            .front18-switch input:checked + .front18-slider:before { transform: translateX(24px); background-color: #fff; }

            /* Inputs Modernized */
            .front18-input { width: 100%; background: rgba(15,23,42,0.8); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 12px 16px; font-size: 14px; color: #f8fafc; font-family: monospace; transition: all 0.3s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1); }
            .front18-input:focus { border-color: #f43f5e; box-shadow: 0 0 0 3px rgba(244,63,94,0.2), inset 0 2px 4px rgba(0,0,0,0.1); outline: none; }
            .front18-input::placeholder { color: #475569; }
            
            /* Select2 Custom Dark */
            .select2-container--default .select2-selection--multiple { background-color: rgba(15,23,42,0.8) !important; border: 1px solid rgba(255,255,255,0.1) !important; border-radius: 8px !important; min-height: 44px !important; padding: 2px 8px !important; }
            .select2-container--default.select2-container--focus .select2-selection--multiple { border-color: #f43f5e !important; }
            .select2-container--default .select2-selection--multiple .select2-selection__choice { background-color: #1e293b !important; border: 1px solid rgba(255,255,255,0.1) !important; color: #e2e8f0 !important; border-radius: 4px !important; padding: 4px 8px !important; margin-top: 6px !important; }
            .select2-container--default .select2-selection--multiple .select2-selection__choice__remove { color: #ef4444 !important; margin-right: 5px !important; border-right: none !important; }
            .select2-dropdown { background-color: #1e293b !important; border: 1px solid rgba(255,255,255,0.1) !important; color: #e2e8f0 !important; border-radius: 8px !important; box-shadow: 0 10px 25px rgba(0,0,0,0.5) !important; }
            .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable { background-color: #f43f5e !important; color: white !important; }
            .select2-container--default .select2-search--inline .select2-search__field { color: #f8fafc !important; margin-top: 8px !important; font-family: inherit; }
            
            /* Debug Details */
            .front18-debug-details summary { cursor: pointer; font-weight: 600; color: #94a3b8; outline: none; padding: 15px; background: rgba(255,255,255,0.02); border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); transition: background 0.3s; }
            .front18-debug-details summary:hover { background: rgba(255,255,255,0.05); color: #f8fafc; }

            /* Submit Button */
            .front18-btn-submit { background: linear-gradient(135deg, #f43f5e, #be123c); color: white; border: none; padding: 14px 40px; font-size: 16px; font-weight: 700; border-radius: 8px; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(244,63,94,0.3); display: inline-flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 1px; }
            .front18-btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(244,63,94,0.4); color: white; }
        ' );
    }

    public function ajax_search_posts() {
        check_ajax_referer( 'front18_admin_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $term = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        
        $args = array(
            's' => $term,
            'post_type' => 'any', // Search any post type (posts, pages, products)
            'post_status' => 'publish',
            'posts_per_page' => 10,
        );
        $query = new WP_Query($args);
        $results = array();
        
        if ($query->have_posts()) {
            foreach ($query->posts as $p) {
                $type_obj = get_post_type_object($p->post_type);
                $type_name = $type_obj ? $type_obj->labels->singular_name : $p->post_type;
                $results[] = array('id' => $p->ID, 'text' => $p->post_title . ' (' . $type_name . ')');
            }
        }
        wp_send_json($results);
    }

    public function ajax_sync_now() {
        check_ajax_referer( 'front18_admin_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();
        
        $api_key = get_option( 'front18_api_key', '' );
        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => __( 'API Key não configurada.', 'front18' ) ) );
        }
        
        // A arquitetura Real Front18 é PUSH (O SaaS quem empurra as config pro WP via Dashboard)
        // O Botão 'Sincronizar Agora' no WP apenas força a persistência local de fallback
        // Para puxar as regras manualmente, um endpoint precisará ser criado no index.php do SaaS!
        
        $time = current_time( 'mysql' );
        update_option( 'front18_last_sync', $time );
        
        wp_send_json_success( array( 
            'message' => __( 'Recarregado! (Aguardando PUSH do Painel SaaS):', 'front18' ), 
            'time' => wp_date('d/m/Y H:i:s', strtotime($time)) 
        ) );
    }

    private function get_post_titles_for_select($comma_ids) {
        $arr = array();
        if (empty($comma_ids)) return $arr;
        $ids = explode(',', $comma_ids);
        foreach ($ids as $id) {
            $p = get_post($id);
            if ($p) {
                $type_obj = get_post_type_object($p->post_type);
                $type_name = $type_obj ? $type_obj->labels->singular_name : $p->post_type;
                $arr[$id] = $p->post_title . ' (' . $type_name . ')';
            }
        }
        return $arr;
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $enabled       = get_option( 'front18_enabled', false );
        $api_key       = get_option( 'front18_api_key', '' );
        
        // Sync & Scope settings
        $include_ids   = get_option( 'front18_include_ids', '' );
        $exclude_ids   = get_option( 'front18_exclude_ids', '' );
        $last_sync     = get_option( 'front18_last_sync', false );
        
        // Advanced
        $debug_mode    = get_option( 'front18_debug_mode', false );
        $sdk_url       = get_option( 'front18_sdk_url', 'https://front18.com/public/sdk/front18.js' );
        $global_object = get_option( 'front18_global_object', 'Front18' );
        $token_key     = get_option( 'front18_token_key', 'api-key' );

        $inc_posts = $this->get_post_titles_for_select($include_ids);
        $exc_posts = $this->get_post_titles_for_select($exclude_ids);

        // Status Badge Logic
        if ( $enabled && !empty($api_key) ) {
            $badge_class = 'badge-on';
            $badge_text = __( '🟢 Front18 Ativo e Protegendo este site', 'front18' );
        } elseif ( $enabled && empty($api_key) ) {
            $badge_class = 'badge-err';
            $badge_text = __( '🔴 API Key Ausente! Proteção interrompida', 'front18' );
        } else {
            $badge_class = 'badge-off';
            $badge_text = __( '⚪ Front18 Desativado', 'front18' );
        }

        ?>
        <div class="wrap front18-admin-wrap">
            <div class="front18-glass-panel">
                <div class="front18-header">
                    <h1><?php esc_html_e( 'Front18 Security', 'front18' ); ?></h1>
                    <p><?php esc_html_e( 'O MasterHub corporativo atuando dentro do seu WordPress. Total opacidade antes mesmo da página renderizar.', 'front18' ); ?></p>
                </div>
    
                <div class="front18-status-box">
                    <div class="front18-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_text); ?></div>
                </div>

            <?php settings_errors('front18_options_group'); ?>

            <?php if ( empty($api_key) ) : ?>
                <div class="notice notice-error is-dismissible" style="margin-left:0; margin-bottom:20px; border-left-color:#dc2626;">
                    <p><strong><?php esc_html_e( 'Bloqueio Inativo:', 'front18' ); ?></strong> <?php _e( 'Insira a sua <b style="color:#b91c1c;">SaaS API Key / Client ID</b> abaixo para que a blindagem do Front18 comece a atuar.', 'front18' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'front18_options_group' ); ?>

                <!-- 1 & 2. ATIVAÇÃO E CONFIG BÁSICA -->
                <div class="front18-card">
                    <h2><?php esc_html_e( '1. Configuração Principal', 'front18' ); ?></h2>
                    <p class="card-desc"><?php esc_html_e( 'Ative o motor de defesa e conecte com o seu painel do Front18.', 'front18' ); ?></p>

                    <div class="front18-row">
                        <div class="front18-col">
                            <div class="front18-row-title"><?php esc_html_e( 'Ativar Front18', 'front18' ); ?></div>
                            <div class="front18-row-desc"><?php esc_html_e( 'Enquanto ativo, todas as páginas selecionadas sofrerão restrição visual instantânea.', 'front18' ); ?></div>
                        </div>
                        <label class="front18-switch">
                            <input type="checkbox" name="front18_enabled" value="1" <?php checked( 1, $enabled, true ); ?> />
                            <span class="front18-slider"></span>
                        </label>
                    </div>

                    <div class="front18-row" style="flex-direction: column; align-items: stretch; gap: 10px;">
                        <div class="front18-col" style="padding:0;">
                            <div class="front18-row-title"><?php esc_html_e( 'SaaS API Key / Client ID', 'front18' ); ?> <span style="color:#ef4444;">*</span></div>
                            <div class="front18-row-desc" style="margin-bottom:8px;"><?php esc_html_e( 'Cole seu Token fornecido no painel do Front18 para que a rede valide sua proteção.', 'front18' ); ?></div>
                        </div>
                        <div style="position: relative; display: flex; align-items: center;">
                            <input type="password" id="front18_api_key_input" name="front18_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="front18-input" placeholder="<?php esc_attr_e( 'Cole sua chave aqui...', 'front18' ); ?>" autocomplete="off" style="padding-right: 40px;" />
                            <span id="front18_toggle_apikey" style="position: absolute; right: 15px; cursor: pointer; color: #94a3b8; display: flex; align-items: center; justify-content: center; height: 100%; transition: color 0.2s;" title="<?php esc_attr_e('Mostrar/Ocultar chave', 'front18'); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- 2. SINCRONIZAÇÃO SAAS -->
                <div class="front18-card">
                    <h2><?php esc_html_e( '2. Nuvem Front18 (SaaS)', 'front18' ); ?></h2>
                    <p class="card-desc"><?php esc_html_e( 'As regras de acesso (Global, Produtos, Home) agora são controladas 100% no seu painel SaaS.', 'front18' ); ?></p>

                    <div class="front18-row front18-row-focus" style="border-color: rgba(52, 211, 153, 0.2);">
                        <div class="front18-col">
                            <div class="front18-row-title" style="color:#f8fafc;"><?php esc_html_e( 'Status da Sincronização', 'front18' ); ?></div>
                            <div class="front18-row-desc" id="front18_sync_status">
                                <?php if ($last_sync): ?>
                                    <span style="color:#34d399;">✔️ Última sincronização: <b id="front18_sync_time"><?php echo esc_html(wp_date('d/m/Y H:i:s', strtotime($last_sync))); ?></b></span>
                                <?php else: ?>
                                    <span style="color:#fbbf24;">⚠️ Aguardando primeira sincronização com a sua API Key.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" id="front18_btn_sync" class="front18-btn-submit" style="padding: 10px 20px; font-size: 13px; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(52, 211, 153, 0.3); color: #34d399; box-shadow: none;">
                            <?php esc_html_e( '🔄 Sincronizar Agora', 'front18' ); ?>
                        </button>
                    </div>
                </div>

                <!-- AS REGRAS AGORA SÃO 100% GERENCIADAS PELO SAAS FRONT18.COM -->

                <!-- 5. DEBUG / ADVANCED -->
                <details class="front18-debug-details">
                    <summary><?php esc_html_e( 'Painel de Desenvolvedor e Integração Customizada', 'front18' ); ?></summary>
                    <div class="front18-card" style="margin-top: 15px;">
                        <div class="front18-row" style="flex-direction: column; align-items: stretch; gap: 8px;">
                            <div class="front18-row-title"><?php esc_html_e( 'URL Base do SDK Front18', 'front18' ); ?></div>
                            <input type="text" name="front18_sdk_url" class="front18-input" value="<?php echo esc_url( $sdk_url ); ?>" />
                        </div>
                        <div style="display: flex; gap: 20px; margin-top: 15px;">
                            <div style="flex:1;">
                                <div class="front18-row-title" style="margin-bottom:8px;"><?php esc_html_e( 'Objeto Padrão (JS)', 'front18' ); ?></div>
                                <input type="text" name="front18_global_object" class="front18-input" value="<?php echo esc_attr( $global_object ); ?>" />
                            </div>
                            <div style="flex:1;">
                                <div class="front18-row-title" style="margin-bottom:8px;"><?php esc_html_e( 'Chave Interna Token', 'front18' ); ?></div>
                                <input type="text" name="front18_token_key" class="front18-input" value="<?php echo esc_attr( $token_key ); ?>" />
                            </div>
                        </div>
                        <div class="front18-row" style="margin-top:15px; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                            <div class="front18-col">
                                <div class="front18-row-title"><?php esc_html_e( 'Ligar Telemetria Console (F12)', 'front18' ); ?></div>
                                <div class="front18-row-desc"><?php esc_html_e( 'Grava logs de Render Timeline e Retenção no painel de desenvolvedor do navegador.', 'front18' ); ?></div>
                            </div>
                            <label class="front18-switch">
                                <input type="checkbox" name="front18_debug_mode" value="1" <?php checked( 1, $debug_mode, true ); ?> />
                                <span class="front18-slider"></span>
                            </label>
                        </div>
                    </div>
                </details>

                <div style="text-align: right; margin-top: 35px; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 25px;">
                    <button type="submit" name="submit" id="submit" class="front18-btn-submit">
                        <?php esc_html_e( 'Salvar Blindagem Mestra', 'front18' ); ?>
                    </button>
                </div>
            </form>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                // Sincronização Ajax
                $('#front18_btn_sync').on('click', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    $btn.html('⏳ Sincronizando...').css('opacity', '0.7');
                    
                    $.post(front18_ajax.ajaxurl, {
                        action: 'front18_sync_now',
                        security: front18_ajax.nonce
                    }, function(res) {
                        if (res.success) {
                            $('#front18_sync_status').html('<span style="color:#34d399;">✔️ ' + res.data.message + ' <b id="front18_sync_time">' + res.data.time + '</b></span>');
                        } else {
                            $('#front18_sync_status').html('<span style="color:#ef4444;">❌ ' + res.data.message + '</span>');
                        }
                    }).fail(function() {
                        $('#front18_sync_status').html('<span style="color:#ef4444;">❌ Erro de rede ao contatar a API.</span>');
                    }).always(function() {
                        $btn.html('🔄 Sincronizar Agora').css('opacity', '1');
                    });
                });

                // Alternar visibilidade da API Key
                $('#front18_toggle_apikey').on('click', function() {
                    var $input = $('#front18_api_key_input');
                    var $icon = $(this);
                    
                    if ($input.attr('type') === 'password') {
                        $input.attr('type', 'text');
                        $icon.html('<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>');
                        $icon.css('color', '#f8fafc');
                    } else {
                        $input.attr('type', 'password');
                        $icon.html('<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>');
                        $icon.css('color', '#94a3b8');
                    }
                });
            });
        </script>
        <?php
    }

    // META BOX FUNCTIONS
    public function add_post_meta_boxes() {
        $post_types = get_post_types( array( 'public' => true ) );
        foreach ( $post_types as $pt ) {
            add_meta_box( 'front18_meta_box', __( '🛡️ Defesa Front18', 'front18' ), array( $this, 'render_meta_box' ), $pt, 'side', 'high' );
        }
    }

    public function render_meta_box( $post ) {
        $val = get_post_meta( $post->ID, '_front18_protect', true );
        if (empty($val)) $val = 'default';
        wp_nonce_field( 'front18_save_meta', 'front18_meta_nonce' );
        ?>
        <p style="font-size:13px; color:#64748b; margin-top:0;"><?php esc_html_e( 'Deseja forçar uma regra específica unicamente para esta página?', 'front18' ); ?></p>
        <select name="front18_protect_override" style="width:100%; margin-bottom: 10px;">
            <option value="default" <?php selected($val, 'default'); ?>><?php esc_html_e( 'Automático (Seguir Painel Principal)', 'front18' ); ?></option>
            <option value="protect" <?php selected($val, 'protect'); ?>><?php esc_html_e( '🔴 Forçar Proteção (Bloquear Sempre)', 'front18' ); ?></option>
            <option value="unprotect" <?php selected($val, 'unprotect'); ?>><?php esc_html_e( '🟢 Forçar Acesso (Liberar Sempre)', 'front18' ); ?></option>
        </select>
        <?php
    }

    public function save_post_meta_boxes( $post_id ) {
        if ( ! isset( $_POST['front18_meta_nonce'] ) || ! wp_verify_nonce( $_POST['front18_meta_nonce'], 'front18_save_meta' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Fallback robusto garantindo valor estrito (Proteção se o front-end sumir)
        $val = isset($_POST['front18_protect_override']) ? sanitize_text_field($_POST['front18_protect_override']) : 'default';
        update_post_meta( $post_id, '_front18_protect', $val );
    }
}
