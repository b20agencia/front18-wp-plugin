<?php
class Front18_API {
    public function init() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        // Rota do Webhook: POST /wp-json/front18/v1/sync
        register_rest_route( 'front18/v1', '/sync', array(
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => array( $this, 'handle_webhook' ),
            'permission_callback' => array( $this, 'check_permission' )
        ) );

        // Rota de Listagem de Imagens Mídia: GET /wp-json/front18/v1/media
        register_rest_route( 'front18/v1', '/media', array(
            'methods'  => WP_REST_Server::READABLE,
            'callback' => array( $this, 'get_media_library' ),
            'permission_callback' => array( $this, 'check_permission' )
        ) );
    }

    public function check_permission( WP_REST_Request $request ) {
        $api_key = get_option( 'front18_api_key', '' );
        if ( empty( $api_key ) ) return false;
        
        // Autorização via header Bearer
        $auth_header = $request->get_header( 'authorization' );
        if ( $auth_header && strpos( $auth_header, 'Bearer ' ) === 0 ) {
            $token = substr( $auth_header, 7 );
            if ( $token === $api_key ) return true;
        }
        
        // Ou autorização simples mandando a API key no payload JSON
        $body_key = $request->get_param( 'api_key' );
        if ( $body_key === $api_key ) return true;
        
        return false;
    }

    public function handle_webhook( WP_REST_Request $request ) {
        $rules = $request->get_param( 'rules' );
        
        if ( ! is_array( $rules ) ) {
            return new WP_Error( 'invalid_payload', __( 'Payload inválido. É esperado um objeto de regras.', 'front18' ), array( 'status' => 400 ) );
        }
        
        // Cuidado Extremo Nível Bancário: Sanitização das regras recebidas
        $sanitized_rules = array(
            'global' => ! empty( $rules['global'] ),
            'home'   => ! empty( $rules['home'] ),
            'cpts'   => ( isset( $rules['cpts'] ) && is_array( $rules['cpts'] ) ) ? array_map( 'sanitize_text_field', $rules['cpts'] ) : array()
        );
        
        $config_payload = $request->get_param( 'config' );
        if ( is_array( $config_payload ) ) {
            $sanitized_config = array(
                'level'         => isset( $config_payload['level'] ) ? (int) $config_payload['level'] : 1,
                'display_mode'  => sanitize_text_field( $config_payload['display_mode'] ?? 'global_lock' ),
                'color_bg'      => sanitize_hex_color( $config_payload['color_bg'] ?? '#0f172a' ) ?: '#0f172a',
                'color_primary' => sanitize_hex_color( $config_payload['color_primary'] ?? '#6366f1' ) ?: '#6366f1',
                'color_text'    => sanitize_hex_color( $config_payload['color_text'] ?? '#f8fafc' ) ?: '#f8fafc',
                'blur_amount'   => isset( $config_payload['blur_amount'] ) ? (int) $config_payload['blur_amount'] : 25,
                'blur_selector' => isset( $config_payload['blur_selector'] ) ? map_deep( wp_unslash( $config_payload['blur_selector'] ), 'sanitize_text_field' ) : 'img, video, iframe, [data-front18="locked"]',
            );
            update_option( 'front18_synced_config', $sanitized_config );
        }
        
        $protected_ids = $request->get_param( 'protected_ids' );
        if ( is_array( $protected_ids ) ) {
            $ids = array_filter( array_map( 'intval', $protected_ids ) );
            update_option( 'front18_protected_media_ids', $ids );
        }

        update_option( 'front18_synced_rules', $sanitized_rules );
        update_option( 'front18_last_sync', current_time( 'mysql' ) );
        
        return rest_ensure_response( array( 
            'success'   => true, 
            'message'   => __( 'Regras Sincronizadas com o SaaS via Push.', 'front18' ),
            'timestamp' => current_time( 'mysql' ), 
            'rules'     => $sanitized_rules 
        ) );
    }

    public function get_media_library( WP_REST_Request $request ) {
        $page     = $request->get_param( 'page' ) ? (int) $request->get_param( 'page' ) : 1;
        $per_page = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 48;
        $search   = $request->get_param( 'search' ) ? sanitize_text_field( wp_unslash( $request->get_param( 'search' ) ) ) : '';
        $folder   = $request->get_param( 'folder' ) ? sanitize_text_field( wp_unslash( $request->get_param( 'folder' ) ) ) : '';
        
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC'
        );

        if ( ! empty( $search ) ) {
            $args['s'] = $search;
        }

        if ( ! empty( $folder ) && $folder !== 'all' ) {
            $parts = explode( '/', $folder );
            if ( count( $parts ) === 2 ) {
                $args['year']     = (int) $parts[0];
                $args['monthnum'] = (int) $parts[1];
            }
        }
        
        $query = new WP_Query( $args );
        $media = array();

        foreach ( $query->posts as $post ) {
            $url = wp_get_attachment_image_url( $post->ID, 'medium' );
            if ( ! $url ) {
                $url = wp_get_attachment_url( $post->ID );
            }
            
            $media[] = array(
                'id'    => $post->ID,
                'title' => $post->post_title,
                'url'   => $url
            );
        }

        $folders_data = array();

        // Ghost Scanner V2.0 e Scraper de Pastas
        if ( $page === 1 ) {
            global $wpdb;
            
            // 1. Extrai todas as pastas/tamanhos ativos do servidor para o Dropdown Front18
            $months = $wpdb->get_results( "
                SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month
                FROM $wpdb->posts
                WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'
                ORDER BY post_date DESC
            " );
            
            foreach ( $months as $month ) {
                if ( empty($month->year) || empty($month->month) ) continue;
                $month_str = zeroise( $month->month, 2 );
                $folders_data[] = array(
                    'label' => date_i18n( 'F Y', mktime( 0, 0, 0, $month->month, 1, $month->year ) ),
                    'value' => $month->year . '/' . $month_str
                );
            }

            // 2. Ghost Tracker entra em ação só se não houver pesquisa explícita
            if ( empty($search) && (empty($folder) || $folder === 'all') ) {
                $ghost_media = array();
                $ghost_dict  = get_option( 'front18_ghost_media_dict', array() );
                
                // Reúne todos os JSONs de páginas locais e opções de Temas (Customizer)
                $elementor = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data'" );
                $themes    = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'theme_mods_%'" );
                
                // Fusão Limpa da Estrutura do Site (Removendo escapes de JSON)
                $big_string = stripslashes( implode( ' ', array_merge( $elementor, $themes ) ) );
                
                // Regex Cirúrgica: Extrai qualquer URL que pareça uma fotografia ou banner de estrutura de Layout Isolado
                preg_match_all( '/(https?:\/\/[^"\',\\\\\}\{\s\(\)]+\.(?:png|jpg|jpeg|webp))/i', $big_string, $matches );
                
                if ( ! empty( $matches[1] ) ) {
                    $urls = array_unique( $matches[1] );
                    foreach ( $urls as $ghost_url ) {
                        // Força a exposição de TODAS as mídias do layout. 
                        // Mesmo que o WP conheça a imagem, o MimeType dela pode estar corrompido, escondendo-a do SaaS.
                        $fake_id = abs( crc32( $ghost_url ) ); // ID seguro e único baseado no texto
                        
                        $ghost_dict[ $fake_id ] = $ghost_url;
                        $ghost_media[] = array(
                            'id'    => $fake_id,
                            'title' => 'Ghost Tracker (Elementor/Background)',
                            'url'   => $ghost_url
                        );
                    }
                }
                
                if ( isset($ghost_media) && ! empty( $ghost_media ) ) {
                    update_option( 'front18_ghost_media_dict', $ghost_dict );
                    // Insere os fantasmas no início da matriz do SaaS
                    $media = array_merge( $ghost_media, $media );
                    // Atualiza o contador de itens da Query para que o SaaS exiba o número aumentado
                    $query->found_posts += count( $ghost_media );
                }
            }
        }
        
        $protected_ids = get_option( 'front18_protected_media_ids', array() );

        return rest_ensure_response( array(
            'success'       => true,
            'total_items'   => $query->found_posts,
            'total_pages'   => $query->max_num_pages,
            'current_page'  => $page,
            'protected_ids' => is_array($protected_ids) ? array_map('intval', $protected_ids) : array(),
            'folders'       => $page === 1 ? $folders_data : null,
            'data'          => $media
        ) );
    }
}
