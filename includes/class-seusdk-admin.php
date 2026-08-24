<?php

class Front18_Admin {

    public function init() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        
        // CSS Global para travar o Tamanho do Ícone na Sidebar (20x20 pixels strict)
        add_action( 'admin_head', array( $this, 'fix_menu_icon_size' ) );
        
        // AJAX Endpoints
        add_action( 'wp_ajax_front18_search_posts', array( $this, 'ajax_search_posts' ) );
        add_action( 'wp_ajax_front18_sync_now', array( $this, 'ajax_sync_now' ) );

        // Seleção de mídia dentro do wp-admin (lê a Biblioteca local; salva e empurra para o SaaS)
        add_action( 'wp_ajax_front18_list_media', array( $this, 'ajax_list_media' ) );
        add_action( 'wp_ajax_front18_save_media', array( $this, 'ajax_save_media' ) );
        
        // Meta Box for Individual Pages
        add_action( 'add_meta_boxes', array( $this, 'add_post_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_post_meta_boxes' ) );
    }

    public function fix_menu_icon_size() {
        // Trava de prioridade CSS para contornar qualquer bug nativo das antigas branches do wp-admin
        echo '<style>#toplevel_page_front18-integration .wp-menu-image img { max-width: 20px !important; max-height: 20px !important; object-fit: contain; margin-top: -2px; }</style>';
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

        // Nível Produto SaaS: Regex super estrita autorizando APENAS o servidor Oficial e subs nativos
        if ( empty($parsed['host']) || !preg_match('/(^|\.)(front18\.com)$/', $parsed['host']) ) {
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
            FRONT18_PLUGIN_URL . 'assets/favicon.png', // Utiliza o ícone visual oficial do produto
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
            .badge-on  { background: rgba(16,185,129,0.1);  color: #34d399; border: 1px solid rgba(16,185,129,0.2);  box-shadow: 0 0 15px rgba(16,185,129,0.1); }
            .badge-off { background: rgba(100,116,139,0.1); color: #94a3b8; border: 1px solid rgba(100,116,139,0.2); }
            .badge-err { background: rgba(239,68,68,0.1);   color: #f87171; border: 1px solid rgba(239,68,68,0.2);   box-shadow: 0 0 15px rgba(239,68,68,0.1); }

            /* Cards */
            .front18-card { background: rgba(30,41,59,0.5); border: 1px solid rgba(255,255,255,0.05); border-radius: 12px; padding: 25px; margin-bottom: 25px; transition: transform 0.2s, box-shadow 0.2s; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
            .front18-card:hover { border-color: rgba(255,255,255,0.1); box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
            .front18-card h2 { margin: 0 0 5px; font-size: 18px; font-weight: 600; color: #f8fafc; border: none; padding: 0; }
            .front18-card .card-desc { margin: 0 0 20px; color: #94a3b8; font-size: 13px; }

            .front18-row { display: flex; align-items: center; justify-content: space-between; padding: 15px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
            .front18-row:last-child { border-bottom: none; padding-bottom: 0; }
            .front18-row-focus { background: rgba(15,23,42,0.5); padding: 15px; border-radius: 8px; margin-bottom: 10px; border: 1px solid rgba(244,63,94,0.1); }
            .front18-col { flex: 1; padding-right: 20px; }
            .front18-row-title { font-weight: 600; font-size: 15px; color: #e2e8f0; }
            .front18-row-desc  { font-size: 13px; color: #64748b; margin-top: 4px; line-height: 1.5; }

            /* Status Grid (card proteção atual) */
            .front18-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-top: 5px; }
            .front18-stat-cell { background: rgba(15,23,42,0.7); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; padding: 14px 16px; text-align: center; }
            .front18-stat-value { font-size: 22px; font-weight: 800; color: #f8fafc; line-height: 1; }
            .front18-stat-label { font-size: 11px; color: #64748b; margin-top: 5px; text-transform: uppercase; letter-spacing: 0.5px; }

            /* Shortcodes */
            .front18-shortcode-block { background: rgba(15,23,42,0.7); border: 1px solid rgba(99,102,241,0.25); border-radius: 8px; padding: 14px 18px; margin-bottom: 12px; }
            .front18-shortcode-block code { display: block; font-family: monospace; font-size: 13px; color: #a5b4fc; margin-bottom: 6px; }
            .front18-shortcode-block small { color: #64748b; font-size: 12px; line-height: 1.5; }

            /* Toggles */
            .front18-switch { position: relative; display: inline-block; width: 50px; height: 26px; flex-shrink: 0; }
            .front18-switch input { opacity: 0; width: 0; height: 0; }
            .front18-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(100,116,139,0.3); transition: .4s; border-radius: 30px; border: 1px solid rgba(255,255,255,0.05); }
            .front18-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: #94a3b8; transition: .4s cubic-bezier(0.175,0.885,0.32,1.275); border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
            .front18-switch input:checked + .front18-slider { background-color: #f43f5e; border-color: #f43f5e; box-shadow: 0 0 10px rgba(244,63,94,0.3); }
            .front18-switch input:checked + .front18-slider:before { transform: translateX(24px); background-color: #fff; }

            /* Inputs */
            .front18-input { width: 100%; background: rgba(15,23,42,0.8); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 12px 16px; font-size: 14px; color: #f8fafc; font-family: monospace; transition: all 0.3s; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1); }
            .front18-input:focus { border-color: #f43f5e; box-shadow: 0 0 0 3px rgba(244,63,94,0.2), inset 0 2px 4px rgba(0,0,0,0.1); outline: none; }
            .front18-input::placeholder { color: #475569; }

            /* Select2 Dark */
            .select2-container--default .select2-selection--multiple { background-color: rgba(15,23,42,0.8) !important; border: 1px solid rgba(255,255,255,0.1) !important; border-radius: 8px !important; min-height: 44px !important; padding: 2px 8px !important; }
            .select2-container--default.select2-container--focus .select2-selection--multiple { border-color: #f43f5e !important; }
            .select2-container--default .select2-selection--multiple .select2-selection__choice { background-color: #1e293b !important; border: 1px solid rgba(255,255,255,0.1) !important; color: #e2e8f0 !important; border-radius: 4px !important; padding: 4px 8px !important; margin-top: 6px !important; }
            .select2-container--default .select2-selection--multiple .select2-selection__choice__remove { color: #ef4444 !important; margin-right: 5px !important; border-right: none !important; }
            .select2-dropdown { background-color: #1e293b !important; border: 1px solid rgba(255,255,255,0.1) !important; color: #e2e8f0 !important; border-radius: 8px !important; box-shadow: 0 10px 25px rgba(0,0,0,0.5) !important; }
            .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable { background-color: #f43f5e !important; color: white !important; }
            .select2-container--default .select2-search--inline .select2-search__field { color: #f8fafc !important; margin-top: 8px !important; font-family: inherit; }

            /* Debug */
            .front18-debug-details summary { cursor: pointer; font-weight: 600; color: #94a3b8; outline: none; padding: 15px; background: rgba(255,255,255,0.02); border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); transition: background 0.3s; }
            .front18-debug-details summary:hover { background: rgba(255,255,255,0.05); color: #f8fafc; }

            /* Submit */
            .front18-btn-submit { background: linear-gradient(135deg, #f43f5e, #be123c); color: white; border: none; padding: 14px 40px; font-size: 16px; font-weight: 700; border-radius: 8px; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 15px rgba(244,63,94,0.3); display: inline-flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 1px; }
            .front18-btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(244,63,94,0.4); color: white; }

            /* Abas */
            .front18-nav-tabs { border-bottom: 1px solid rgba(255,255,255,0.08); margin: 0 0 25px; padding-left: 0; }
            .front18-nav-tabs .nav-tab { background: transparent; border: none; border-bottom: 2px solid transparent; color: #94a3b8; font-size: 14px; font-weight: 600; padding: 12px 18px; margin: 0 4px -1px 0; border-radius: 0; transition: color 0.2s, border-color 0.2s; }
            .front18-nav-tabs .nav-tab:hover { color: #f8fafc; background: transparent; }
            .front18-nav-tabs .nav-tab-active, .front18-nav-tabs .nav-tab-active:hover, .front18-nav-tabs .nav-tab-active:focus { color: #f8fafc; border-bottom-color: #f43f5e; background: transparent; box-shadow: none; }
            .front18-nav-tabs .nav-tab:focus { box-shadow: none; outline: 2px solid rgba(244,63,94,0.5); outline-offset: 2px; }
            .front18-tabpanel[hidden] { display: none; }

            /* Seleção de mídia */
            .front18-scope-choice { display: flex; gap: 12px; margin: 20px 0; flex-wrap: wrap; }
            .front18-scope-opt { flex: 1; min-width: 220px; display: flex; align-items: flex-start; gap: 10px; padding: 14px 16px; border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; cursor: pointer; transition: border-color 0.2s, background 0.2s; }
            .front18-scope-opt:hover { border-color: rgba(244,63,94,0.4); }
            .front18-scope-opt input { margin-top: 3px; accent-color: #f43f5e; }
            .front18-scope-opt span { display: flex; flex-direction: column; gap: 3px; }
            .front18-scope-opt strong { color: #f8fafc; font-size: 14px; }
            .front18-scope-opt small { color: #94a3b8; font-size: 12px; line-height: 1.4; }
            .front18-media-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 16px; }
            .front18-media-bulk { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
            .front18-media-counter { color: #94a3b8; font-size: 13px; }
            .front18-media-counter b { color: #f8fafc; }
            .front18-btn-ghost { background: rgba(255,255,255,0.04); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 9px 16px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.2s, border-color 0.2s; }
            .front18-btn-ghost:hover { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.2); color: #fff; }
            .front18-media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 10px; }
            .front18-media-item { position: relative; border-radius: 8px; overflow: hidden; cursor: pointer; border: 2px solid transparent; background: rgba(255,255,255,0.03); aspect-ratio: 1 / 1; }
            .front18-media-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
            .front18-media-item .f18-check { position: absolute; top: 6px; left: 6px; width: 20px; height: 20px; border-radius: 5px; background: rgba(15,23,42,0.7); border: 2px solid rgba(255,255,255,0.6); display: flex; align-items: center; justify-content: center; }
            .front18-media-item .f18-check::after { content: ""; width: 5px; height: 9px; border: solid transparent; border-width: 0 2px 2px 0; transform: rotate(45deg); margin-top: -2px; }
            .front18-media-item.f18-on { border-color: #f43f5e; }
            .front18-media-item.f18-on .f18-check { background: #f43f5e; border-color: #f43f5e; }
            .front18-media-item.f18-on .f18-check::after { border-color: #fff; }
            .front18-media-item .f18-title { position: absolute; bottom: 0; left: 0; right: 0; padding: 4px 6px; font-size: 10px; color: #fff; background: linear-gradient(transparent, rgba(0,0,0,0.75)); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .front18-media-empty { text-align: center; color: #94a3b8; padding: 30px; }
            .front18-media-savebar { display: flex; align-items: center; gap: 14px; margin-top: 24px; border-top: 1px solid rgba(255,255,255,0.07); padding-top: 20px; }
            .front18-media-status { font-size: 13px; color: #94a3b8; }
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

    // =========================================================================
    // Seleção de mídia dentro do wp-admin
    // =========================================================================

    /**
     * Lista a Biblioteca de Mídia local para a grade. Reaproveita o get_media_library da API REST
     * (mesma paginação, busca, pasta, intervalo de datas e modo ids_only do "Selecionar todos"),
     * mas por admin-ajax autenticado por nonce + manage_options — sem expor o webhook_secret no
     * navegador. Antes essa listagem era o painel SaaS fazendo proxy de centenas de imagens.
     */
    public function ajax_list_media() {
        check_ajax_referer( 'front18_admin_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Sem permissão' ), 403 );
        }

        if ( ! class_exists( 'Front18_API' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-seusdk-api.php';
        }

        $req = new WP_REST_Request( 'GET', '/front18/v1/media' );
        foreach ( array( 'page', 'per_page', 'search', 'folder', 'mime_type', 'orderby', 'order', 'date_from', 'date_to', 'ids_only' ) as $p ) {
            if ( isset( $_POST[ $p ] ) ) {
                $req->set_param( $p, wp_unslash( $_POST[ $p ] ) );
            }
        }
        if ( ! $req->get_param( 'page' ) )      { $req->set_param( 'page', 1 ); }
        if ( ! $req->get_param( 'per_page' ) )  { $req->set_param( 'per_page', 60 ); }
        if ( ! $req->get_param( 'mime_type' ) ) { $req->set_param( 'mime_type', 'image' ); }
        if ( ! $req->get_param( 'orderby' ) )   { $req->set_param( 'orderby', 'date' ); }
        if ( ! $req->get_param( 'order' ) )     { $req->set_param( 'order', 'DESC' ); }

        $api  = new Front18_API();
        $resp = $api->get_media_library( $req );
        $data = ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
        wp_send_json_success( $data );
    }

    /**
     * Salva a seleção escolhida na grade. O plugin passa a ser o DONO da seleção: guarda local e
     * empurra para o SaaS (sync reverso), para o SDK na página servir a lista nova. Sem o push, o
     * track.php seguiria devolvendo a lista antiga do cache.
     */
    public function ajax_save_media() {
        check_ajax_referer( 'front18_admin_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Sem permissão' ), 403 );
        }

        $ids_raw = isset( $_POST['ids'] ) ? json_decode( wp_unslash( $_POST['ids'] ), true ) : array();
        if ( ! is_array( $ids_raw ) ) { $ids_raw = array(); }
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids_raw ), static function ( $v ) { return $v > 0; } ) ) );

        $scope = ( isset( $_POST['scope'] ) && $_POST['scope'] === 'selected_only' ) ? 'selected_only' : 'all';
        // Sem seleção não há whitelist — selected_only vazio desprotegeria o site em silêncio.
        if ( $scope === 'selected_only' && empty( $ids ) ) { $scope = 'all'; }

        // O plugin é o dono: guarda local.
        update_option( 'front18_protected_media_ids', $ids );
        $cfg = get_option( 'front18_synced_config', array() );
        if ( ! is_array( $cfg ) ) { $cfg = array(); }
        $cfg['protection_scope'] = $scope;
        if ( $scope === 'selected_only' ) { $cfg['display_mode'] = 'blur_media'; }
        update_option( 'front18_synced_config', $cfg );

        $push = $this->push_selection_to_saas( $ids, $scope );

        wp_send_json_success( array(
            'total' => count( $ids ),
            'scope' => $scope,
            'push'  => $push,
        ) );
    }

    /**
     * Empurra a seleção para o SaaS (POST /public/api/wp_selection.php), autenticado por
     * api_key + webhook_secret. A base do SaaS é derivada da URL do SDK. Sem webhook_secret o
     * canal ainda não foi estabelecido: é preciso um sync normal (painel -> plugin) antes.
     */
    private function push_selection_to_saas( array $ids, $scope ) {
        $api_key = get_option( 'front18_api_key', '' );
        $secret  = get_option( 'front18_webhook_secret', '' );
        if ( empty( $api_key ) || empty( $secret ) ) {
            return array( 'ok' => false, 'reason' => 'sem_canal' );
        }

        // A API vive ao lado do SDK: .../sdk/front18.js -> .../api/wp_selection.php. Derivar por
        // vizinhança acompanha instalações com ou sem /public/ no caminho, sem hardcode do prefixo.
        $sdk_url = get_option( 'front18_sdk_url', 'https://front18.com/public/sdk/front18.js' );
        if ( strpos( $sdk_url, '/sdk/' ) !== false ) {
            $endpoint = preg_replace( '#/sdk/[^/?\#]+.*$#', '/api/wp_selection.php', $sdk_url );
        } else {
            $parts = wp_parse_url( $sdk_url );
            if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
                return array( 'ok' => false, 'reason' => 'url_invalida' );
            }
            $endpoint = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/public/api/wp_selection.php';
        }
        if ( empty( $endpoint ) ) {
            return array( 'ok' => false, 'reason' => 'url_invalida' );
        }

        $resp = wp_remote_post( $endpoint, array(
            'timeout' => 20,
            'headers' => array(
                'Content-Type'     => 'application/json',
                'X-API-KEY'        => $api_key,
                'X-Front18-Secret' => $secret,
            ),
            'body' => wp_json_encode( array(
                'protected_media_ids' => $ids,
                'protection_scope'    => $scope,
            ) ),
        ) );

        if ( is_wp_error( $resp ) ) {
            return array( 'ok' => false, 'reason' => 'rede', 'detail' => $resp->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $json = json_decode( wp_remote_retrieve_body( $resp ), true );
        return array( 'ok' => ( $code === 200 && ! empty( $json['success'] ) ), 'http' => $code );
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
            $badge_text = __( 'Front18 Ativo e Protegendo este site', 'front18' );
        } elseif ( $enabled && empty($api_key) ) {
            $badge_class = 'badge-err';
            $badge_text = __( 'API Key Ausente! Proteção interrompida', 'front18' );
        } else {
            $badge_class = 'badge-off';
            $badge_text = __( 'Front18 Desativado', 'front18' );
        }

        ?>
        <div class="wrap front18-admin-wrap">
            <div class="front18-glass-panel">
                <div class="front18-header">
                    <img src="<?php echo esc_url( FRONT18_PLUGIN_URL . 'assets/logo.png' ); ?>" alt="Front18 Security Logo" style="max-height: 55px; margin-bottom: 20px; display: block; margin-left: auto; margin-right: auto;" />
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

            <?php $abaAtiva = ( ! empty( $api_key ) && $last_sync ) ? 'protecao' : 'conexao'; ?>
            <h2 class="nav-tab-wrapper front18-nav-tabs" role="tablist">
                <a href="#" class="nav-tab front18-tab<?php echo $abaAtiva === 'conexao' ? ' nav-tab-active' : ''; ?>" data-tab="conexao"><?php esc_html_e( 'Conexão', 'front18' ); ?></a>
                <a href="#" class="nav-tab front18-tab<?php echo $abaAtiva === 'protecao' ? ' nav-tab-active' : ''; ?>" data-tab="protecao"><?php esc_html_e( 'Proteção', 'front18' ); ?></a>
                <a href="#" class="nav-tab front18-tab" data-tab="midia"><?php esc_html_e( 'Seleção de Mídia', 'front18' ); ?></a>
                <a href="#" class="nav-tab front18-tab" data-tab="avancado"><?php esc_html_e( 'Avançado', 'front18' ); ?></a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields( 'front18_options_group' ); ?>

                <div class="front18-tabpanel" data-panel="conexao"<?php echo $abaAtiva !== 'conexao' ? ' hidden' : ''; ?>>
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
                    <p class="card-desc"><?php esc_html_e( 'As regras de acesso (Global, Produtos, Home) são controladas 100% no seu painel SaaS.', 'front18' ); ?></p>

                    <div class="front18-row front18-row-focus" style="border-color: rgba(52, 211, 153, 0.2);">
                        <div class="front18-col">
                            <div class="front18-row-title" style="color:#f8fafc;"><?php esc_html_e( 'Status da Sincronização', 'front18' ); ?></div>
                            <div class="front18-row-desc" id="front18_sync_status">
                                <?php if ($last_sync): ?>
                                    <span style="color:#34d399;">Última sincronização: <b id="front18_sync_time"><?php echo esc_html(wp_date('d/m/Y H:i:s', strtotime($last_sync))); ?></b></span>
                                <?php else: ?>
                                    <span style="color:#fbbf24;">Aguardando primeira sincronização com a sua API Key.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" id="front18_btn_sync" class="front18-btn-submit" style="padding: 10px 20px; font-size: 13px; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(52, 211, 153, 0.3); color: #34d399; box-shadow: none;">
                            <?php esc_html_e( 'Sincronizar Agora', 'front18' ); ?>
                        </button>
                    </div>
                </div>
                </div><!-- /painel conexao -->

                <div class="front18-tabpanel" data-panel="protecao"<?php echo $abaAtiva !== 'protecao' ? ' hidden' : ''; ?>>
                <!-- 3. STATUS DA PROTEÇÃO ATUAL (Resumo das Regras Sincronizadas) -->
                <?php
                $synced_config  = get_option( 'front18_synced_config', array() );
                $synced_rules   = get_option( 'front18_synced_rules', array() );
                $protected_ids  = get_option( 'front18_protected_media_ids', array() );
                $mode_labels    = array(
                    'global_lock' => __( 'Bloqueio Global', 'front18' ),
                    'granular'    => __( 'Granular', 'front18' ),
                    'blur_media'  => __( 'Blur de Mídia', 'front18' ),
                );
                $current_mode   = ! empty( $synced_config['display_mode'] ) ? $synced_config['display_mode'] : 'global_lock';
                $mode_label     = isset( $mode_labels[ $current_mode ] ) ? $mode_labels[ $current_mode ] : $current_mode;
                $level          = isset( $synced_config['level'] ) ? (int) $synced_config['level'] : 1;
                $level_labels   = array( 1 => __( 'Blur', 'front18' ), 2 => __( 'Oculto', 'front18' ), 3 => __( 'Removido', 'front18' ) );
                $level_label    = isset( $level_labels[ $level ] ) ? $level_labels[ $level ] : 'N/A';
                $scope_parts    = array();
                if ( ! empty( $synced_rules['global'] ) ) $scope_parts[] = __( 'Global', 'front18' );
                if ( ! empty( $synced_rules['home'] )   ) $scope_parts[] = __( 'Home', 'front18' );
                if ( ! empty( $synced_rules['cpts'] )   ) $scope_parts[] = implode( ', ', (array) $synced_rules['cpts'] );
                $scope_str      = empty( $scope_parts ) ? __( 'Nenhum', 'front18' ) : implode( ' + ', $scope_parts );
                $media_count    = is_array( $protected_ids ) ? count( $protected_ids ) : 0;

                // protection_scope ('all' x 'selected_only') e o eixo que mais confunde: e ele que
                // decide se TUDO e protegido ou so a lista. O painel nao o mostrava. Traduzimos o
                // efeito real para uma frase em portugues claro, combinando modo + escopo.
                $scope_media = ! empty( $synced_config['protection_scope'] ) ? $synced_config['protection_scope'] : 'all';
                if ( $current_mode === 'global_lock' ) {
                    $resumo_efeito = __( 'A pagina inteira fica bloqueada atras do portao de idade para quem ainda nao verificou.', 'front18' );
                } elseif ( $scope_media === 'selected_only' ) {
                    $resumo_efeito = sprintf( __( 'Apenas as %d midias selecionadas ficam borradas. O resto do site fica livre.', 'front18' ), $media_count );
                } else {
                    $resumo_efeito = __( 'Todas as imagens, videos e iframes ficam borrados para quem ainda nao verificou a idade.', 'front18' );
                }
                ?>
                <?php if ( $last_sync ) : ?>
                <div class="front18-card" style="border-color: rgba(99,102,241,0.2);">
                    <h2><?php esc_html_e( '3. Proteção Ativa Agora', 'front18' ); ?></h2>
                    <p class="card-desc"><?php esc_html_e( 'Resumo em tempo real das configurações que o SaaS está aplicando neste site.', 'front18' ); ?></p>

                    <div style="display:flex; align-items:flex-start; gap:12px; flex-wrap:wrap; justify-content:space-between; background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,0.25); border-radius:12px; padding:14px 16px; margin-bottom:16px;">
                        <div style="flex:1; min-width:240px;">
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#a5b4fc; font-weight:700; margin-bottom:4px;"><?php esc_html_e( 'O que o visitante vê', 'front18' ); ?></div>
                            <div style="font-size:14px; color:#e2e8f0; line-height:1.5;"><?php echo esc_html( $resumo_efeito ); ?></div>
                        </div>
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Abre a home numa aba nova. Para ver o efeito (blur/portao), use uma janela ANONIMA — se voce ja verificou a idade neste navegador, o site mostra tudo liberado.', 'front18' ); ?>" style="white-space:nowrap; background:rgba(15,23,42,0.8); border:1px solid rgba(52,211,153,0.35); color:#34d399; padding:10px 16px; border-radius:8px; font-weight:600; font-size:13px; text-decoration:none;">
                            <?php esc_html_e( 'Ver como visitante', 'front18' ); ?>
                        </a>
                    </div>
                    <p style="font-size:11px; color:#94a3b8; margin:-8px 0 16px;"><?php esc_html_e( 'Dica: teste sempre numa janela anônima. No seu navegador normal, se você já passou pela verificação, o site te mostra tudo liberado — isso é o comportamento correto, não uma falha.', 'front18' ); ?></p>

                    <div class="front18-status-grid">
                        <div class="front18-stat-cell">
                            <div class="front18-stat-value" style="font-size:15px;color:#a5b4fc;"><?php echo esc_html( $mode_label ); ?></div>
                            <div class="front18-stat-label"><?php esc_html_e( 'Modo', 'front18' ); ?></div>
                        </div>
                        <div class="front18-stat-cell">
                            <div class="front18-stat-value" style="color:#f87171;"><?php echo esc_html( $level ); ?></div>
                            <div class="front18-stat-label"><?php echo esc_html( $level_label ); ?> &mdash; <?php esc_html_e( 'Nível', 'front18' ); ?></div>
                        </div>
                        <div class="front18-stat-cell">
                            <div class="front18-stat-value"><?php echo esc_html( $media_count ); ?></div>
                            <div class="front18-stat-label"><?php esc_html_e( 'Mídias protegidas', 'front18' ); ?></div>
                        </div>
                        <div class="front18-stat-cell" style="grid-column: span 2;">
                            <div class="front18-stat-value" style="font-size:13px; color:#94a3b8;"><?php echo esc_html( $scope_str ); ?></div>
                            <div class="front18-stat-label"><?php esc_html_e( 'Escopo', 'front18' ); ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                </div><!-- /painel protecao -->

                <div class="front18-tabpanel" data-panel="midia" hidden>
                    <div class="front18-card">
                        <h2><?php esc_html_e( 'Seleção de Mídia', 'front18' ); ?></h2>
                        <p class="card-desc" style="line-height:1.7;">
                            <?php esc_html_e( 'Escolha, na sua própria Biblioteca de Mídia, exatamente o que é protegido. Tudo acontece dentro do WordPress — suas imagens não saem daqui. Ao salvar, a escolha vale no site na hora.', 'front18' ); ?>
                        </p>

                        <!-- Escopo da proteção -->
                        <div class="front18-scope-choice">
                            <label class="front18-scope-opt">
                                <input type="radio" name="f18_scope" value="all" checked />
                                <span><strong><?php esc_html_e( 'Proteger toda a mídia', 'front18' ); ?></strong><small><?php esc_html_e( 'Borra todas as imagens; a lista abaixo só reforça.', 'front18' ); ?></small></span>
                            </label>
                            <label class="front18-scope-opt">
                                <input type="radio" name="f18_scope" value="selected_only" />
                                <span><strong><?php esc_html_e( 'Proteger só as selecionadas', 'front18' ); ?></strong><small><?php esc_html_e( 'Só o que estiver marcado abaixo é protegido.', 'front18' ); ?></small></span>
                            </label>
                        </div>

                        <!-- Filtros -->
                        <div class="front18-media-toolbar">
                            <div style="flex:1; min-width:150px;">
                                <div class="front18-row-desc" style="margin-bottom:4px;"><?php esc_html_e( 'Buscar por nome', 'front18' ); ?></div>
                                <input type="text" id="f18_media_search" class="front18-input" placeholder="<?php esc_attr_e( 'ex.: banner, capa...', 'front18' ); ?>" />
                            </div>
                            <div>
                                <div class="front18-row-desc" style="margin-bottom:4px;"><?php esc_html_e( 'De', 'front18' ); ?></div>
                                <input type="date" id="f18_media_from" class="front18-input" />
                            </div>
                            <div>
                                <div class="front18-row-desc" style="margin-bottom:4px;"><?php esc_html_e( 'Até', 'front18' ); ?></div>
                                <input type="date" id="f18_media_to" class="front18-input" />
                            </div>
                            <div>
                                <div class="front18-row-desc" style="margin-bottom:4px;"><?php esc_html_e( 'Pasta (mês/ano)', 'front18' ); ?></div>
                                <select id="f18_media_folder" class="front18-input"><option value="all"><?php esc_html_e( 'Todas', 'front18' ); ?></option></select>
                            </div>
                            <button type="button" id="f18_media_apply" class="front18-btn-ghost"><?php esc_html_e( 'Filtrar', 'front18' ); ?></button>
                        </div>

                        <!-- Ações em massa + contador -->
                        <div class="front18-media-bulk">
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <button type="button" id="f18_select_all" class="front18-btn-ghost"><?php esc_html_e( 'Selecionar todas (do filtro)', 'front18' ); ?></button>
                                <button type="button" id="f18_select_none" class="front18-btn-ghost"><?php esc_html_e( 'Limpar seleção', 'front18' ); ?></button>
                            </div>
                            <div class="front18-media-counter"><b id="f18_media_count">0</b> <?php esc_html_e( 'selecionadas', 'front18' ); ?></div>
                        </div>

                        <!-- Grade -->
                        <div id="f18_media_grid" class="front18-media-grid" aria-live="polite"></div>
                        <div id="f18_media_empty" class="front18-media-empty" style="display:none;"><?php esc_html_e( 'Nenhuma mídia encontrada com esses filtros.', 'front18' ); ?></div>
                        <div style="text-align:center; margin-top:16px;">
                            <button type="button" id="f18_media_more" class="front18-btn-ghost" style="display:none;"><?php esc_html_e( 'Carregar mais', 'front18' ); ?></button>
                        </div>

                        <!-- Salvar -->
                        <div class="front18-media-savebar">
                            <button type="button" id="f18_media_save" class="front18-btn-submit"><?php esc_html_e( 'Salvar seleção', 'front18' ); ?></button>
                            <span id="f18_media_status" class="front18-media-status"></span>
                        </div>
                    </div>
                </div><!-- /painel midia -->

                <div class="front18-tabpanel" data-panel="avancado" hidden>
                <!-- 4. SHORTCODES -->
                <details class="front18-debug-details">
                    <summary><?php esc_html_e( 'Como proteger partes específicas da página (Shortcodes)', 'front18' ); ?></summary>
                    <div class="front18-card" style="margin-top: 15px;">
                        <p class="card-desc" style="margin-bottom: 20px; line-height: 1.7;">
                            <?php esc_html_e( 'Por padrão, o Front18 protege a página inteira quando ativado. Mas às vezes você quer proteger apenas um bloco — uma foto, um vídeo, uma seção de conteúdo premium. Para isso, use os recursos abaixo diretamente no editor de páginas.', 'front18' ); ?>
                        </p>

                        <div class="front18-shortcode-block">
                            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                                <code>[front18]</code>
                                <span style="background: rgba(99,102,241,0.15); color:#a5b4fc; font-size:11px; padding:2px 8px; border-radius:20px; font-weight:600;"><?php esc_html_e( 'Avançado', 'front18' ); ?></span>
                            </div>
                            <small>
                                <strong><?php esc_html_e( 'Quando usar:', 'front18' ); ?></strong>
                                <?php esc_html_e( 'Quando você quer inserir o ponto de controle do SDK em um local exato da página — por exemplo, dentro de um template PHP ou construtor de página personalizado. Na maioria dos casos você não precisa deste shortcode.', 'front18' ); ?>
                            </small>
                        </div>

                        <div class="front18-shortcode-block">
                            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                                <code>[front18_lock]Conteúdo aqui[/front18_lock]</code>
                                <span style="background: rgba(248,113,113,0.15); color:#fca5a5; font-size:11px; padding:2px 8px; border-radius:20px; font-weight:600;"><?php esc_html_e( 'Mais usado', 'front18' ); ?></span>
                            </div>
                            <small>
                                <strong><?php esc_html_e( 'Quando usar:', 'front18' ); ?></strong>
                                <?php esc_html_e( 'Para proteger apenas um bloco específico — uma imagem, um vídeo, uma seção de conteúdo premium — sem precisar ativar a proteção global na página inteira. Cole este shortcode no editor Gutenberg ou no editor clássico em volta do conteúdo que deseja proteger.', 'front18' ); ?>
                            </small>
                        </div>

                        <div class="front18-shortcode-block">
                            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                                <code>&lt;div data-front18="locked"&gt;...&lt;/div&gt;</code>
                                <span style="background: rgba(52,211,153,0.15); color:#6ee7b7; font-size:11px; padding:2px 8px; border-radius:20px; font-weight:600;"><?php esc_html_e( 'Para devs', 'front18' ); ?></span>
                            </div>
                            <small>
                                <strong><?php esc_html_e( 'Quando usar:', 'front18' ); ?></strong>
                                <?php esc_html_e( 'Tem o mesmo efeito que [front18_lock], mas escrito em HTML puro. Use quando estiver editando um template de tema (.php), um bloco HTML do Gutenberg, ou um construtor de páginas (Elementor, Divi) que não aceita shortcodes aninhados.', 'front18' ); ?>
                            </small>
                        </div>
                    </div>
                </details>

                <!-- 5. CONFIGURAÇÕES AVANÇADAS -->
                <details class="front18-debug-details">
                    <summary><?php esc_html_e( 'Configurações Avançadas (não altere sem orientação da Front18)', 'front18' ); ?></summary>
                    <div class="front18-card" style="margin-top: 15px;">

                        <p class="card-desc" style="margin-bottom: 20px; line-height: 1.7; color: #fbbf24;">
                            <?php esc_html_e( 'Estes campos são preenchidos automaticamente durante a ativação. Só altere se a equipe Front18 solicitar, ou se você estiver usando um ambiente de staging/homologação com URL diferente.', 'front18' ); ?>
                        </p>

                        <div class="front18-row" style="flex-direction: column; align-items: stretch; gap: 8px;">
                            <div class="front18-row-title">
                                <?php esc_html_e( 'URL do Script Front18', 'front18' ); ?>
                            </div>
                            <div class="front18-row-desc" style="margin-bottom:6px;">
                                <?php esc_html_e( 'Endereço onde o script de proteção está hospedado. Padrão: servidor Front18. Altere apenas se estiver usando CDN próprio ou ambiente de testes.', 'front18' ); ?>
                            </div>
                            <input type="text" name="front18_sdk_url" class="front18-input" value="<?php echo esc_url( $sdk_url ); ?>" />
                        </div>

                        <div style="display: flex; gap: 20px; margin-top: 20px;">
                            <div style="flex:1;">
                                <div class="front18-row-title" style="margin-bottom:4px;">
                                    <?php esc_html_e( 'Nome do Objeto JavaScript', 'front18' ); ?>
                                </div>
                                <div class="front18-row-desc" style="margin-bottom:8px; font-size:12px;">
                                    <?php esc_html_e( 'Variável global criada no browser (window.Front18). Padrão: Front18.', 'front18' ); ?>
                                </div>
                                <input type="text" name="front18_global_object" class="front18-input" value="<?php echo esc_attr( $global_object ); ?>" />
                            </div>
                            <div style="flex:1;">
                                <div class="front18-row-title" style="margin-bottom:4px;">
                                    <?php esc_html_e( 'Parâmetro do Token', 'front18' ); ?>
                                </div>
                                <div class="front18-row-desc" style="margin-bottom:8px; font-size:12px;">
                                    <?php esc_html_e( 'Nome interno do campo que transporta o token de autenticação. Padrão: api-key.', 'front18' ); ?>
                                </div>
                                <input type="text" name="front18_token_key" class="front18-input" value="<?php echo esc_attr( $token_key ); ?>" />
                            </div>
                        </div>

                        <div class="front18-row" style="margin-top:20px; border-top: 1px solid rgba(255,255,255,0.07); padding-top: 20px;">
                            <div class="front18-col">
                                <div class="front18-row-title">
                                    <?php esc_html_e( 'Modo Debug (Log no Console do Navegador)', 'front18' ); ?>
                                </div>
                                <div class="front18-row-desc">
                                    <?php esc_html_e( 'Quando ativo, o Front18 exibe mensagens detalhadas no Console do navegador (F12 → Aba Console). Use apenas para diagnosticar problemas — nunca deixe ligado em produção, pois expõe informações internas do SDK.', 'front18' ); ?>
                                    <br><span style="color:#f87171; font-size:11px; margin-top:4px; display:block;"><?php esc_html_e( 'Desligue após o diagnóstico.', 'front18' ); ?></span>
                                </div>
                            </div>
                            <label class="front18-switch">
                                <input type="checkbox" name="front18_debug_mode" value="1" <?php checked( 1, $debug_mode, true ); ?> />
                                <span class="front18-slider"></span>
                            </label>
                        </div>
                    </div>
                </details>
                </div><!-- /painel avancado -->

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
                // Abas
                $('.front18-nav-tabs .front18-tab').on('click', function(e) {
                    e.preventDefault();
                    var alvo = $(this).data('tab');
                    $('.front18-nav-tabs .front18-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    $('.front18-tabpanel').prop('hidden', true);
                    $('.front18-tabpanel[data-panel="' + alvo + '"]').prop('hidden', false);
                });

                // Seleção de mídia (grade lê a Biblioteca local; salva e empurra para o SaaS)
                (function() {
                    var sel = new Set();
                    var page = 1, totalPages = 1, loaded = false, seeded = false;
                    var $grid = $('#f18_media_grid'), $count = $('#f18_media_count'),
                        $empty = $('#f18_media_empty'), $more = $('#f18_media_more'),
                        $status = $('#f18_media_status');

                    function params(extra) {
                        return $.extend({
                            action: 'front18_list_media',
                            security: front18_ajax.nonce,
                            search: $('#f18_media_search').val() || '',
                            date_from: $('#f18_media_from').val() || '',
                            date_to: $('#f18_media_to').val() || '',
                            folder: $('#f18_media_folder').val() || 'all',
                            mime_type: 'image'
                        }, extra || {});
                    }

                    function esc(s) { return $('<div></div>').text(s == null ? '' : s).html(); }
                    function updateCount() { $count.text(sel.size); }

                    function renderItems(items) {
                        items.forEach(function(m) {
                            var on = sel.has(m.id);
                            var $it = $('<div class="front18-media-item"></div>').attr('data-id', m.id);
                            $it.append($('<img loading="lazy" alt="" />').attr('src', m.url || m.full_url || ''));
                            $it.append('<span class="f18-check"></span>');
                            $it.append($('<span class="f18-title"></span>').text(m.title || ('#' + m.id)));
                            if (on) { $it.addClass('f18-on'); }
                            $grid.append($it);
                        });
                    }

                    function load(reset) {
                        if (reset) { page = 1; $grid.empty(); }
                        $status.text('Carregando...');
                        $.post(front18_ajax.ajaxurl, params({ page: page, per_page: 60 }))
                            .done(function(res) {
                                $status.text('');
                                var d = (res && res.data) ? res.data : {};
                                var items = d.data || [];
                                if (!seeded && Array.isArray(d.protected_ids)) {
                                    d.protected_ids.forEach(function(id) { sel.add(parseInt(id, 10)); });
                                    seeded = true; updateCount();
                                }
                                if (page === 1 && d.folders) {
                                    var $f = $('#f18_media_folder');
                                    $f.find('option').not('[value="all"]').remove();
                                    (d.folders || []).forEach(function(fo) {
                                        $f.append($('<option></option>').attr('value', fo.value).text(fo.label));
                                    });
                                }
                                totalPages = d.total_pages || 1;
                                renderItems(items);
                                $empty.toggle((page === 1) && items.length === 0);
                                $more.toggle(page < totalPages);
                                loaded = true;
                            })
                            .fail(function() { $status.text('Falha ao carregar a biblioteca.'); });
                    }

                    // Carrega ao abrir a aba pela 1a vez (evita puxar a biblioteca em toda visita ao admin).
                    $('.front18-nav-tabs .front18-tab[data-tab="midia"]').on('click', function() {
                        if (!loaded) { load(true); }
                    });
                    $('#f18_media_apply').on('click', function() { load(true); });
                    $('#f18_media_more').on('click', function() { page++; load(false); });

                    $grid.on('click', '.front18-media-item', function() {
                        var id = parseInt($(this).attr('data-id'), 10);
                        if (sel.has(id)) { sel.delete(id); $(this).removeClass('f18-on'); }
                        else { sel.add(id); $(this).addClass('f18-on'); }
                        updateCount();
                    });

                    $('#f18_select_none').on('click', function() {
                        sel.clear();
                        $grid.find('.front18-media-item').removeClass('f18-on');
                        updateCount();
                    });

                    $('#f18_select_all').on('click', function() {
                        $status.text('Marcando todas do filtro...');
                        $.post(front18_ajax.ajaxurl, params({ ids_only: 1 }))
                            .done(function(res) {
                                $status.text('');
                                var d = (res && res.data) ? res.data : {};
                                (d.all_ids || []).forEach(function(id) { sel.add(parseInt(id, 10)); });
                                $grid.find('.front18-media-item').each(function() {
                                    if (sel.has(parseInt($(this).attr('data-id'), 10))) { $(this).addClass('f18-on'); }
                                });
                                updateCount();
                            })
                            .fail(function() { $status.text('Falha ao marcar todas.'); });
                    });

                    $('#f18_media_save').on('click', function() {
                        var scope = $('input[name="f18_scope"]:checked').val() || 'all';
                        var ids = [];
                        sel.forEach(function(id) { ids.push(id); });
                        var $btn = $(this).prop('disabled', true).css('opacity', 0.7);
                        $status.text('Salvando...');
                        $.post(front18_ajax.ajaxurl, {
                            action: 'front18_save_media',
                            security: front18_ajax.nonce,
                            ids: JSON.stringify(ids),
                            scope: scope
                        }).done(function(res) {
                            if (res && res.success) {
                                var d = res.data || {}, push = d.push || {};
                                var msg = 'Salvo: ' + (d.total || 0) + ' selecionadas.';
                                if (push.ok) { msg += ' Aplicado no site.'; }
                                else if (push.reason === 'sem_canal') { msg += ' Sincronize com o painel Front18 uma vez para publicar no site.'; }
                                else { msg += ' Aviso: nao foi possivel publicar no site agora.'; }
                                $status.text(msg);
                            } else {
                                $status.text('Falha ao salvar.');
                            }
                        }).fail(function() { $status.text('Falha de rede ao salvar.'); })
                        .always(function() { $btn.prop('disabled', false).css('opacity', 1); });
                    });
                })();

                // Sincronização Ajax
                $('#front18_btn_sync').on('click', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    $btn.html('Sincronizando...').css('opacity', '0.7');
                    
                    $.post(front18_ajax.ajaxurl, {
                        action: 'front18_sync_now',
                        security: front18_ajax.nonce
                    }, function(res) {
                        if (res.success) {
                            $('#front18_sync_status').html('<span style="color:#34d399;">' + res.data.message + ' <b id="front18_sync_time">' + res.data.time + '</b></span>');
                        } else {
                            $('#front18_sync_status').html('<span style="color:#ef4444;">' + res.data.message + '</span>');
                        }
                    }).fail(function() {
                        $('#front18_sync_status').html('<span style="color:#ef4444;">Erro de rede ao contatar a API.</span>');
                    }).always(function() {
                        $btn.html('Sincronizar Agora').css('opacity', '1');
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
            add_meta_box( 'front18_meta_box', __( 'Defesa Front18', 'front18' ), array( $this, 'render_meta_box' ), $pt, 'side', 'high' );
        }
    }

    public function render_meta_box( $post ) {
        $val = get_post_meta( $post->ID, '_front18_protect', true );
        if ( empty( $val ) ) $val = 'default';
        wp_nonce_field( 'front18_save_meta', 'front18_meta_nonce' );

        // Calcula status atual (considerando regras globais sincronizadas)
        $enabled      = get_option( 'front18_enabled', false );
        $api_key      = get_option( 'front18_api_key', '' );
        $synced_rules = get_option( 'front18_synced_rules', array() );
        $is_global    = ! empty( $synced_rules['global'] );

        if ( ! $enabled || empty( $api_key ) ) {
            $status_html = '<span style="color:#94a3b8;">' . esc_html__( 'Front18 desativado', 'front18' ) . '</span>';
        } elseif ( $val === 'protect' ) {
            $status_html = '<span style="color:#f87171;">' . esc_html__( 'Forçado como PROTEGIDO', 'front18' ) . '</span>';
        } elseif ( $val === 'unprotect' ) {
            $status_html = '<span style="color:#34d399;">' . esc_html__( 'Forçado como LIVRE', 'front18' ) . '</span>';
        } elseif ( $is_global ) {
            $status_html = '<span style="color:#f87171;">' . esc_html__( 'Protegido (Regra Global ativa)', 'front18' ) . '</span>';
        } else {
            $post_type    = get_post_type( $post->ID );
            $cpts         = isset( $synced_rules['cpts'] ) && is_array( $synced_rules['cpts'] ) ? $synced_rules['cpts'] : array();
            if ( $post_type && in_array( $post_type, $cpts, true ) ) {
                $status_html = '<span style="color:#f87171;">' . esc_html__( 'Protegido (Regra de CPT ativa)', 'front18' ) . '</span>';
            } else {
                $status_html = '<span style="color:#34d399;">' . esc_html__( 'Não protegido pelas regras atuais', 'front18' ) . '</span>';
            }
        }
        ?>
        <p style="font-size:12px; background:#f1f5f9; padding:8px 10px; border-radius:4px; margin:0 0 12px;"><strong><?php esc_html_e( 'Status atual:', 'front18' ); ?></strong> <?php echo $status_html; // phpcs:ignore -- saída HTML segura ?></p>
        <p style="font-size:13px; color:#64748b; margin-top:0;"><?php esc_html_e( 'Deseja forçar uma regra específica unicamente para esta página?', 'front18' ); ?></p>
        <select name="front18_protect_override" style="width:100%; margin-bottom: 10px;">
            <option value="default"    <?php selected( $val, 'default' ); ?>><?php esc_html_e( 'Automático (Seguir Painel Principal)', 'front18' ); ?></option>
            <option value="protect"    <?php selected( $val, 'protect' ); ?>><?php esc_html_e( 'Forçar Proteção (Bloquear Sempre)', 'front18' ); ?></option>
            <option value="unprotect"  <?php selected( $val, 'unprotect' ); ?>><?php esc_html_e( 'Forçar Acesso (Liberar Sempre)', 'front18' ); ?></option>
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
