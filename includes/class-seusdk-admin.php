<?php

class Front18_Admin {

    public function init() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        
        // CSS Global para travar o Tamanho do Ícone na Sidebar (20x20 pixels strict)
        add_action( 'admin_head', array( $this, 'fix_menu_icon_size' ) );
        
        // AJAX Endpoints
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

        // jQuery para as abas, a grade de mídia e o botão de sincronizar. O antigo Select2 (via CDN
        // externo) saiu junto com o seletor de posts — a proteção agora vem da Seleção de Mídia.
        wp_enqueue_script( 'jquery' );

        wp_localize_script( 'jquery', 'front18_ajax', array(
            'ajaxurl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'front18_admin_nonce' ),
            // IA de sugestão (embarcada; carregada sob demanda só ao clicar em "Analisar").
            // O nsfwjs.min.js ja traz o TensorFlow.js embutido — nao carregar tf separado.
            'ai_nsfw'  => FRONT18_PLUGIN_URL . 'assets/ai/nsfwjs.min.js',
            'ai_model' => FRONT18_PLUGIN_URL . 'assets/ai/',
        ));

        // Painel claro, amplo, nativo-moderno. Paleta e tipografia de sistema (nada carregado de fora).
        wp_add_inline_style( 'wp-admin', '
            .front18-admin-wrap { max-width: 1120px; margin: 22px 20px 60px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #14171c; }
            .front18-glass-panel { background: transparent; padding: 0; border: none; box-shadow: none; color: #14171c; }

            /* App bar (logo + status) */
            .front18-appbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; background: #fff; border: 1px solid #e4e7ec; border-radius: 14px; box-shadow: 0 1px 2px rgba(16,24,40,.05); padding: 16px 22px; margin-bottom: 18px; flex-wrap: wrap; }
            .front18-brand { display: flex; align-items: center; }
            /* A logo da marca e clara (feita para fundo escuro): sobre o app-bar branco ela sumiria.
               Vai num chip escuro — contraste garantido e o vermelho da marca casa com o acento. */
            .front18-logo-chip { background: #14171c; border-radius: 10px; padding: 9px 15px; display: inline-flex; align-items: center; }
            .front18-logo { height: 28px; width: auto; display: block; }

            /* Status pill */
            .front18-badge { display: inline-flex; align-items: center; gap: 9px; padding: 8px 16px; border-radius: 999px; font-weight: 600; font-size: 13px; }
            .front18-badge::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
            .badge-on  { background: #e9f9f0; color: #0a7a44; border: 1px solid #b7e8cc; }
            .badge-off { background: #f1f3f6; color: #5a6472; border: 1px solid #e0e4ea; }
            .badge-err { background: #fdecee; color: #c81e3a; border: 1px solid #f6cdd4; }

            /* Abas */
            .front18-nav-tabs { border-bottom: 1px solid #e4e7ec; margin: 0 0 26px; padding: 0 4px; display: flex; flex-wrap: wrap; gap: 2px; }
            .front18-nav-tabs .nav-tab { background: transparent; border: none; border-bottom: 2.5px solid transparent; color: #5a6472; font-size: 14.5px; font-weight: 600; padding: 13px 16px; margin: 0 2px -1px 0; border-radius: 0; transition: color .15s, border-color .15s; }
            .front18-nav-tabs .nav-tab:hover { color: #14171c; background: transparent; }
            .front18-nav-tabs .nav-tab-active, .front18-nav-tabs .nav-tab-active:hover, .front18-nav-tabs .nav-tab-active:focus { color: #d21b3c; border-bottom-color: #f2354f; background: transparent; box-shadow: none; }
            .front18-nav-tabs .nav-tab:focus { box-shadow: none; outline: 2px solid rgba(242,53,79,.4); outline-offset: 2px; }
            .front18-tabpanel[hidden] { display: none; }

            /* Cards */
            .front18-card { background: #fff; border: 1px solid #e4e7ec; border-radius: 14px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 8px 24px rgba(16,24,40,.05); }
            .front18-card h2 { margin: 0 0 6px; font-size: 20px; font-weight: 800; letter-spacing: -.01em; color: #14171c; border: none; padding: 0; }
            .front18-card .card-desc { margin: 0 0 20px; color: #5a6472; font-size: 14px; line-height: 1.55; }

            .front18-row { display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 16px 0; border-bottom: 1px solid #eef0f3; }
            .front18-row:last-child { border-bottom: none; padding-bottom: 0; }
            .front18-row-focus { background: #f8f9fb; padding: 16px; border-radius: 10px; margin-bottom: 10px; border: 1px solid #e4e7ec; }
            .front18-col { flex: 1; padding-right: 16px; }
            .front18-row-title { font-weight: 650; font-size: 14.5px; color: #14171c; }
            .front18-row-desc  { font-size: 13px; color: #5a6472; margin-top: 4px; line-height: 1.5; }

            /* Highlight "o que o visitante ve" */
            .front18-vis-box { display: flex; align-items: flex-start; gap: 14px; flex-wrap: wrap; justify-content: space-between; background: #fff0f3; border: 1px solid #ffd4dd; border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; }
            .front18-vis-box .vis-eyebrow { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #d21b3c; font-weight: 700; margin-bottom: 4px; }
            .front18-vis-box .vis-text { font-size: 14.5px; color: #14171c; line-height: 1.5; }

            /* Status Grid */
            .front18-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-top: 6px; }
            .front18-stat-cell { background: #f8f9fb; border: 1px solid #e4e7ec; border-radius: 11px; padding: 15px 16px; text-align: center; }
            .front18-stat-value { font-size: 22px; font-weight: 800; color: #14171c; line-height: 1.1; }
            .front18-stat-label { font-size: 11px; color: #7b8494; margin-top: 6px; text-transform: uppercase; letter-spacing: .04em; }

            /* Toggles */
            .front18-switch { position: relative; display: inline-block; width: 46px; height: 26px; flex-shrink: 0; }
            .front18-switch input { opacity: 0; width: 0; height: 0; }
            .front18-slider { position: absolute; cursor: pointer; inset: 0; background-color: #d3d8e0; transition: .3s; border-radius: 30px; }
            .front18-slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: #fff; transition: .25s; border-radius: 50%; box-shadow: 0 1px 3px rgba(16,24,40,.25); }
            .front18-switch input:checked + .front18-slider { background-color: #f2354f; }
            .front18-switch input:checked + .front18-slider:before { transform: translateX(20px); }

            /* Inputs */
            .front18-input { width: 100%; background: #fff; border: 1px solid #d3d8e0; border-radius: 9px; padding: 10px 13px; font-size: 14px; color: #14171c; transition: border-color .15s, box-shadow .15s; }
            .front18-input:focus { border-color: #f2354f; box-shadow: 0 0 0 3px rgba(242,53,79,.12); outline: none; }
            .front18-input::placeholder { color: #98a1b0; }
            select.front18-input { background: #fff; }

            /* Debug/details */
            .front18-debug-details { margin-bottom: 20px; }
            .front18-debug-details summary { cursor: pointer; font-weight: 600; color: #5a6472; outline: none; padding: 14px 16px; background: #f8f9fb; border-radius: 10px; border: 1px solid #e4e7ec; transition: background .2s; }
            .front18-debug-details summary:hover { background: #f1f3f6; color: #14171c; }

            /* Botao primario */
            .front18-btn-submit { background: linear-gradient(150deg, #f2354f, #d21b3c); color: #fff; border: none; padding: 12px 26px; font-size: 14.5px; font-weight: 700; border-radius: 9px; cursor: pointer; transition: filter .15s, transform .05s, box-shadow .15s; box-shadow: 0 4px 14px rgba(210,27,60,.26); display: inline-flex; align-items: center; gap: 8px; }
            .front18-btn-submit:hover { filter: brightness(1.05); color: #fff; box-shadow: 0 6px 18px rgba(210,27,60,.32); }
            .front18-btn-submit:active { transform: translateY(1px); }

            /* Botao secundario */
            .front18-btn-ghost { background: #fff; color: #14171c; border: 1px solid #d3d8e0; border-radius: 9px; padding: 9px 16px; font-size: 13.5px; font-weight: 600; cursor: pointer; transition: background .15s, border-color .15s; }
            .front18-btn-ghost:hover { background: #f8f9fb; border-color: #98a1b0; }

            /* Escopo */
            .front18-scope-choice { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 20px 0; }
            .front18-scope-opt { display: flex; align-items: flex-start; gap: 12px; padding: 16px 18px; border: 1.5px solid #e4e7ec; border-radius: 11px; cursor: pointer; transition: border-color .15s, background .15s, box-shadow .15s; }
            .front18-scope-opt:hover { border-color: #d3d8e0; }
            .front18-scope-opt:has(input:checked) { border-color: #f2354f; background: #fff0f3; box-shadow: 0 0 0 3px rgba(242,53,79,.08); }
            .front18-scope-opt input { margin-top: 3px; accent-color: #f2354f; width: 17px; height: 17px; }
            .front18-scope-opt span { display: flex; flex-direction: column; gap: 3px; }
            .front18-scope-opt strong { color: #14171c; font-size: 14.5px; }
            .front18-scope-opt:has(input:checked) strong { color: #d21b3c; }
            .front18-scope-opt small { color: #5a6472; font-size: 12.5px; line-height: 1.45; }

            /* Grade de midia */
            .front18-media-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 16px; }
            .front18-media-bulk { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 14px; }
            .front18-media-counter { color: #5a6472; font-size: 13.5px; }
            .front18-media-counter b { color: #14171c; font-variant-numeric: tabular-nums; }
            .front18-media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(112px, 1fr)); gap: 12px; }
            .front18-media-item { position: relative; border-radius: 11px; overflow: hidden; cursor: pointer; border: 2px solid transparent; background: #f1f3f6; aspect-ratio: 1 / 1; box-shadow: 0 1px 2px rgba(16,24,40,.06); transition: transform .12s, box-shadow .12s; }
            .front18-media-item:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16,24,40,.10); }
            .front18-media-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
            .front18-media-item .f18-check { position: absolute; top: 7px; left: 7px; width: 22px; height: 22px; border-radius: 6px; background: rgba(255,255,255,.82); border: 2px solid #fff; box-shadow: 0 1px 3px rgba(16,24,40,.25); display: flex; align-items: center; justify-content: center; }
            .front18-media-item .f18-check::after { content: ""; width: 5px; height: 9px; border: solid transparent; border-width: 0 2px 2px 0; transform: rotate(45deg); margin-top: -2px; }
            .front18-media-item.f18-on { border-color: #f2354f; }
            .front18-media-item.f18-on .f18-check { background: #f2354f; border-color: #f2354f; }
            .front18-media-item.f18-on .f18-check::after { border-color: #fff; }
            .front18-media-item .f18-title { position: absolute; bottom: 0; left: 0; right: 0; padding: 14px 8px 6px; font-size: 10.5px; color: #fff; background: linear-gradient(transparent, rgba(0,0,0,.62)); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .front18-media-empty { text-align: center; color: #7b8494; padding: 34px; }
            .front18-media-savebar { display: flex; align-items: center; gap: 16px; margin-top: 24px; border-top: 1px solid #eef0f3; padding-top: 20px; }
            .front18-media-status { font-size: 13.5px; color: #5a6472; }
            .front18-media-status.ok { color: #0a7a44; font-weight: 600; }

            /* IA de sugestão */
            .front18-ai-bar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; justify-content: space-between; background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 12px; padding: 14px 16px; margin: 18px 0; }
            .front18-ai-info { flex: 1; min-width: 240px; font-size: 12.5px; color: #5a6472; line-height: 1.5; }
            .front18-ai-info strong { display: block; color: #5b21b6; font-size: 13px; margin-bottom: 2px; }
            .front18-ai-run { background: #7c3aed; color: #fff; border: none; border-radius: 9px; padding: 9px 18px; font-size: 13.5px; font-weight: 700; cursor: pointer; transition: filter .15s; flex-shrink: 0; display: inline-flex; align-items: center; gap: 8px; }
            .front18-ai-run:hover { filter: brightness(1.06); }
            .front18-ai-run:disabled { opacity: .6; cursor: default; }
            #f18_ai_progresswrap { margin: -4px 0 14px; }
            .front18-ai-progress { font-size: 13px; color: #5b21b6; margin: 0 0 6px; }
            .front18-ai-progressbar { height: 8px; background: #ece9fb; border-radius: 999px; overflow: hidden; }
            .front18-ai-progressbar-fill { height: 100%; width: 0%; background: linear-gradient(90deg, #7c3aed, #a855f7); border-radius: 999px; transition: width .2s ease; }
            .front18-ai-progressbar.f18-indeterminado { position: relative; }
            .front18-ai-progressbar.f18-indeterminado .front18-ai-progressbar-fill { width: 38%; animation: f18aislide 1.1s infinite ease-in-out; }
            @keyframes f18aislide { 0% { margin-left: -40%; } 100% { margin-left: 100%; } }
            @media (prefers-reduced-motion: reduce) { .front18-ai-progressbar.f18-indeterminado .front18-ai-progressbar-fill { animation: none; width: 100%; } }
            .front18-media-item.f18-ai-sug { border-color: #7c3aed; }
            .front18-media-item .f18-ai-flag { position: absolute; top: 7px; right: 7px; background: #7c3aed; color: #fff; font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 999px; letter-spacing: .02em; box-shadow: 0 1px 3px rgba(0,0,0,.25); }

            /* Nota (aviso leve) */
            .front18-note { display: flex; gap: 12px; align-items: flex-start; background: #f8f9fb; border: 1px solid #e4e7ec; border-radius: 10px; padding: 14px 16px; font-size: 13px; color: #5a6472; line-height: 1.55; margin-bottom: 6px; }
            .front18-note b { color: #14171c; }

            .front18-savebar-main { text-align: right; margin-top: 30px; border-top: 1px solid #e4e7ec; padding-top: 22px; }

            @media (max-width: 720px) { .front18-scope-choice { grid-template-columns: 1fr; } }
        ' );
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
        
        $last_sync     = get_option( 'front18_last_sync', false );

        // Avançado
        $debug_mode    = get_option( 'front18_debug_mode', false );
        $sdk_url       = get_option( 'front18_sdk_url', 'https://front18.com/public/sdk/front18.js' );
        $global_object = get_option( 'front18_global_object', 'Front18' );
        $token_key     = get_option( 'front18_token_key', 'api-key' );

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
            <?php // O <h1> + wp-header-end dao ao WordPress a ancora para EMPILHAR os avisos nativos
                  // (os nossos e os de outros plugins) aqui no topo, em vez de no meio do painel. ?>
            <h1 class="screen-reader-text"><?php esc_html_e( 'Front18 Security Integration', 'front18' ); ?></h1>

            <?php settings_errors('front18_options_group'); ?>

            <?php if ( empty($api_key) ) : ?>
                <div class="notice notice-error is-dismissible" style="margin-left:0; margin-bottom:20px; border-left-color:#dc2626;">
                    <p><strong><?php esc_html_e( 'Bloqueio Inativo:', 'front18' ); ?></strong> <?php _e( 'Insira a sua <b style="color:#b91c1c;">SaaS API Key / Client ID</b> abaixo para que a blindagem do Front18 comece a atuar.', 'front18' ); ?></p>
                </div>
            <?php endif; ?>

            <hr class="wp-header-end">

            <div class="front18-glass-panel">
                <div class="front18-appbar">
                    <div class="front18-brand">
                        <span class="front18-logo-chip">
                            <img class="front18-logo" src="<?php echo esc_url( FRONT18_PLUGIN_URL . 'assets/logo.png' ); ?>" alt="Front18 Security Integration" />
                        </span>
                    </div>
                    <span class="front18-badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($badge_text); ?></span>
                </div>

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
                    <h2><?php esc_html_e( 'Ligar e conectar', 'front18' ); ?></h2>
                    <p class="card-desc"><?php esc_html_e( 'Ative a proteção e cole a chave do seu painel Front18. Depois disso, o resto acontece sozinho.', 'front18' ); ?></p>

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
                    <h2><?php esc_html_e( 'Nuvem Front18', 'front18' ); ?></h2>
                    <p class="card-desc"><?php esc_html_e( 'As regras de acesso (Global, Produtos, Home) são controladas 100% no seu painel SaaS.', 'front18' ); ?></p>

                    <div class="front18-row front18-row-focus">
                        <div class="front18-col">
                            <div class="front18-row-title"><?php esc_html_e( 'Status da Sincronização', 'front18' ); ?></div>
                            <div class="front18-row-desc" id="front18_sync_status">
                                <?php if ($last_sync): ?>
                                    <span style="color:#0a7a44;">Última sincronização: <b id="front18_sync_time"><?php echo esc_html(wp_date('d/m/Y H:i:s', strtotime($last_sync))); ?></b></span>
                                <?php else: ?>
                                    <span style="color:#b7791f;">Aguardando primeira sincronização com a sua API Key.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" id="front18_btn_sync" class="front18-btn-ghost">
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
                    <h2><?php esc_html_e( 'Proteção ativa agora', 'front18' ); ?></h2>
                    <p class="card-desc"><?php esc_html_e( 'Resumo em tempo real das configurações que o SaaS está aplicando neste site.', 'front18' ); ?></p>

                    <div class="front18-vis-box">
                        <div style="flex:1; min-width:240px;">
                            <div class="vis-eyebrow"><?php esc_html_e( 'O que o visitante vê', 'front18' ); ?></div>
                            <div class="vis-text"><?php echo esc_html( $resumo_efeito ); ?></div>
                        </div>
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener" class="front18-btn-ghost" title="<?php esc_attr_e( 'Abre a home numa aba nova. Para ver o efeito (blur/portao), use uma janela ANONIMA — se voce ja verificou a idade neste navegador, o site mostra tudo liberado.', 'front18' ); ?>" style="white-space:nowrap; text-decoration:none;">
                            <?php esc_html_e( 'Ver como visitante', 'front18' ); ?>
                        </a>
                    </div>
                    <p class="front18-row-desc" style="margin:-6px 0 16px;"><?php esc_html_e( 'Dica: teste sempre numa janela anônima. No seu navegador normal, se você já passou pela verificação, o site te mostra tudo liberado — isso é o comportamento correto, não uma falha.', 'front18' ); ?></p>

                    <div class="front18-status-grid">
                        <div class="front18-stat-cell">
                            <div class="front18-stat-value" style="font-size:15px;"><?php echo esc_html( $mode_label ); ?></div>
                            <div class="front18-stat-label"><?php esc_html_e( 'Modo', 'front18' ); ?></div>
                        </div>
                        <div class="front18-stat-cell">
                            <div class="front18-stat-value"><?php echo esc_html( $level ); ?></div>
                            <div class="front18-stat-label"><?php echo esc_html( $level_label ); ?> &mdash; <?php esc_html_e( 'Nível', 'front18' ); ?></div>
                        </div>
                        <div class="front18-stat-cell">
                            <div class="front18-stat-value"><?php echo esc_html( $media_count ); ?></div>
                            <div class="front18-stat-label"><?php esc_html_e( 'Mídias protegidas', 'front18' ); ?></div>
                        </div>
                        <div class="front18-stat-cell" style="grid-column: span 2;">
                            <div class="front18-stat-value" style="font-size:13px; color:#5a6472;"><?php echo esc_html( $scope_str ); ?></div>
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

                        <!-- IA: sugestão de conteúdo explícito (roda no navegador; imagens não saem do servidor) -->
                        <div class="front18-ai-bar">
                            <div class="front18-ai-info">
                                <strong><?php esc_html_e( 'Sugestão automática (IA) — em teste', 'front18' ); ?></strong>
                                <?php esc_html_e( 'Varre toda a sua Biblioteca aqui no seu próprio navegador (as imagens não saem do servidor) e pré-seleciona as que parecem +18. É um auxílio que pode errar — revise a grade e desmarque as que não deveriam entrar antes de salvar. A classificação final, e a responsabilidade por ela, é sua.', 'front18' ); ?>
                            </div>
                            <button type="button" id="f18_ai_run" class="front18-ai-run"><?php esc_html_e( 'Analisar imagens', 'front18' ); ?></button>
                        </div>
                        <div id="f18_ai_progresswrap" style="display:none;">
                            <div id="f18_ai_progress" class="front18-ai-progress" aria-live="polite"></div>
                            <div class="front18-ai-progressbar"><div id="f18_ai_progressfill" class="front18-ai-progressbar-fill"></div></div>
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
                <!-- CONFIGURAÇÕES AVANÇADAS -->
                <details class="front18-debug-details">
                    <summary><?php esc_html_e( 'Configurações Avançadas (não altere sem orientação da Front18)', 'front18' ); ?></summary>
                    <div class="front18-card" style="margin-top: 15px;">

                        <p class="card-desc" style="margin-bottom: 20px; line-height: 1.7; color: #b7791f;">
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

                        <div class="front18-row" style="margin-top:20px; border-top: 1px solid #eef0f3; padding-top: 20px;">
                            <div class="front18-col">
                                <div class="front18-row-title">
                                    <?php esc_html_e( 'Modo Debug (Log no Console do Navegador)', 'front18' ); ?>
                                </div>
                                <div class="front18-row-desc">
                                    <?php esc_html_e( 'Quando ativo, o Front18 exibe mensagens detalhadas no Console do navegador (F12 → Aba Console). Use apenas para diagnosticar problemas — nunca deixe ligado em produção, pois expõe informações internas do SDK.', 'front18' ); ?>
                                    <br><span style="color:#c81e3a; font-size:11px; margin-top:4px; display:block;"><?php esc_html_e( 'Desligue após o diagnóstico.', 'front18' ); ?></span>
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

                <div class="front18-savebar-main">
                    <button type="submit" name="submit" id="submit" class="front18-btn-submit">
                        <?php esc_html_e( 'Salvar configurações', 'front18' ); ?>
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
                    var aiFlagged = new Set(); // ids que a IA marcou como possivel +18 (para o selo persistir ao paginar)
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
                            if (aiFlagged.has(m.id)) { $it.addClass('f18-ai-sug'); $it.append('<span class="f18-ai-flag">possível +18</span>'); }
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
                        $status.text('').removeClass('ok');
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
                        $status.text('Salvando...').removeClass('ok');
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
                                $status.text(msg).toggleClass('ok', !!push.ok);
                            } else {
                                $status.text('Falha ao salvar.');
                            }
                        }).fail(function() { $status.text('Falha de rede ao salvar.'); })
                        .always(function() { $btn.prop('disabled', false).css('opacity', 1); });
                    });

                    // ── IA de sugestão: carrega sob demanda; roda no navegador; imagens não saem do servidor ──
                    var aiModel = null;
                    function f18LoadScript(src) {
                        return new Promise(function(resolve, reject) {
                            var s = document.createElement('script');
                            s.src = src;
                            s.onload = resolve;
                            s.onerror = function() { reject(new Error('falha ao carregar recurso da IA')); };
                            document.head.appendChild(s);
                        });
                    }
                    async function f18EnsureModel() {
                        if (aiModel) { return aiModel; }
                        // O nsfwjs.min.js JA empacota o TensorFlow.js inteiro. Carregar o tf.min.js
                        // separado registrava o backend WebGL duas vezes e o fromPixels passava a
                        // devolver um tensor constante (todas as imagens davam o MESMO score). Por
                        // isso carregamos SO o nsfwjs.
                        if (typeof nsfwjs === 'undefined') { await f18LoadScript(front18_ajax.ai_nsfw); }
                        aiModel = await nsfwjs.load(front18_ajax.ai_model, { size: 224 });
                        return aiModel;
                    }
                    // Carrega a imagem FRESCA (crossOrigin p/ canvas limpo, e sem depender do lazy-load
                    // da grade). Assim toda a pagina de imagens e analisada, nao so as visiveis.
                    function f18LoadForAI(src) {
                        return new Promise(function(resolve, reject) {
                            var im = new Image();
                            im.crossOrigin = 'anonymous';
                            var to = setTimeout(function() { reject(new Error('timeout')); }, 20000);
                            im.onload = function() {
                                clearTimeout(to);
                                if (im.decode) { im.decode().then(function() { resolve(im); }).catch(function() { resolve(im); }); }
                                else { resolve(im); }
                            };
                            im.onerror = function() { clearTimeout(to); reject(new Error('load')); };
                            im.src = src;
                        });
                    }

                    // Marca (e pre-seleciona) na grade uma imagem que a IA sugeriu, se ela estiver renderizada.
                    function f18MarcaTile(id) {
                        var tile = $grid.find('.front18-media-item[data-id="' + id + '"]');
                        if (tile.length) {
                            tile.addClass('f18-on f18-ai-sug');
                            if (!tile.find('.f18-ai-flag').length) { tile.append('<span class="f18-ai-flag">possível +18</span>'); }
                        }
                    }

                    $('#f18_ai_run').on('click', async function() {
                        var $btn = $(this);
                        var $prog = $('#f18_ai_progress');
                        var $bar = $('.front18-ai-progressbar'), $fill = $('#f18_ai_progressfill');
                        $('#f18_ai_progresswrap').show();
                        var rotulo = $btn.text();
                        $btn.prop('disabled', true).text('Carregando IA...');
                        $bar.addClass('f18-indeterminado');
                        $prog.text('Carregando o modelo (só na primeira vez, alguns segundos)...');
                        try {
                            var model = await f18EnsureModel();

                            // 1) Levanta TODAS as imagens da biblioteca (respeitando os filtros atuais),
                            //    nao so a pagina carregada na grade — pagina por pagina, so os IDs+URLs.
                            $prog.text('Levantando a lista completa de imagens...');
                            var itens = [], p = 1, tp = 1;
                            do {
                                var resList = await $.post(front18_ajax.ajaxurl, params({ page: p, per_page: 100 }));
                                var dl = (resList && resList.data) ? resList.data : {};
                                (dl.data || []).forEach(function(mm) {
                                    if (mm && mm.id) { itens.push({ id: parseInt(mm.id, 10), url: mm.url || mm.full_url || '' }); }
                                });
                                tp = dl.total_pages || 1;
                                p++;
                            } while (p <= tp);

                            if (!itens.length) { $prog.text('Nenhuma imagem para analisar.'); $bar.removeClass('f18-indeterminado'); return; }

                            // 2) Classifica cada uma (imagem carregada fresca, no navegador).
                            $btn.text('Analisando...');
                            $bar.removeClass('f18-indeterminado');
                            $fill.css('width', '0%');
                            var analisadas = 0, comErro = 0, novas = 0, maiorScore = 0;
                            for (var i = 0; i < itens.length; i++) {
                                $prog.text('Analisando ' + (i + 1) + ' de ' + itens.length + '...');
                                $fill.css('width', Math.round(((i + 1) / itens.length) * 100) + '%');
                                if (!itens[i].url) { comErro++; continue; }
                                var el;
                                try { el = await f18LoadForAI(itens[i].url); }
                                catch (e) { comErro++; continue; }
                                try {
                                    var preds = await model.classify(el);
                                    var mm = {};
                                    preds.forEach(function(pr) { mm[pr.className] = pr.probability; });
                                    var explicito = (mm.Porn || 0) + (mm.Hentai || 0);
                                    var sensual = mm.Sexy || 0;
                                    var score = Math.max(explicito, sensual);
                                    if (score > maiorScore) { maiorScore = score; }
                                    analisadas++;
                                    if (explicito >= 0.45 || sensual >= 0.6) {
                                        var id = itens[i].id;
                                        aiFlagged.add(id);
                                        if (!sel.has(id)) { sel.add(id); novas++; } // PRE-SELECIONA por padrao
                                        f18MarcaTile(id);
                                    }
                                } catch (e) { comErro++; }
                            }
                            updateCount();
                            $fill.css('width', '100%');

                            // Se a IA sugeriu algo, faz sentido o escopo ser "so as selecionadas" —
                            // senao a selecao fina nao teria efeito (o modo "tudo" borra tudo).
                            if (aiFlagged.size > 0) {
                                $('input[name="f18_scope"][value="selected_only"]').prop('checked', true);
                            }

                            var msg = 'Análise: ' + analisadas + ' imagem(ns) lida(s). ' + aiFlagged.size + ' marcada(s) como possível +18 e já pré-selecionada(s).';
                            if (comErro) { msg += ' ' + comErro + ' não pôde(ram) ser lida(s) pelo navegador (outra origem/CDN).'; }
                            if (analisadas && !aiFlagged.size) { msg += ' Maior score visto: ' + Math.round(maiorScore * 100) + '% (abaixo do limiar).'; }
                            else if (aiFlagged.size) { msg += ' Revise a grade e DESMARQUE as que não deveriam entrar; depois clique em Salvar seleção.'; }
                            $prog.text(msg);
                        } catch (e) {
                            $prog.text('Não foi possível analisar agora: ' + (e && e.message ? e.message : 'falha ao carregar a IA') + '.');
                        } finally {
                            $bar.removeClass('f18-indeterminado');
                            $btn.text(rotulo).prop('disabled', false);
                        }
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
                            $('#front18_sync_status').html('<span style="color:#0a7a44;">' + res.data.message + ' <b id="front18_sync_time">' + res.data.time + '</b></span>');
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
                        $icon.css('color', '#14171c');
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
