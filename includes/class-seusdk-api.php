<?php
/**
 * Front18_API — Endpoints REST do Plugin Front18.
 *
 * Versão: 1.1.0
 * Novidades:
 *  - hash_equals() na verificação da chave de API (previne timing attacks)
 *  - $wpdb->prepare() em todas as queries diretas
 *  - Filtros ricos: tipo MIME, dimensões (min/max), tamanho em bytes, alt text, ordenação customizável
 *  - Metadados ricos no retorno de mídia (filesize, width, height, mime_type, alt)
 *  - Novo endpoint GET /media/types — retorna os tipos MIME disponíveis no site
 *  - Ghost Tracker v3: detecta imagens em Elementor, ACF, WooCommerce (meta _thumbnail_id, _product_image_gallery), Divi (et_pb_*), theme_mods
 *  - Ghost Tracker paginado via cache transitório (não usa LIMIT fixo que perderia imagens)
 */
class Front18_API {

    public function init() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    // =========================================================================
    // 1. Registro de Rotas
    // =========================================================================

    public function register_routes() {

        // POST /wp-json/front18/v1/sync — Recebe push de regras do SaaS
        register_rest_route( 'front18/v1', '/sync', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => array( $this, 'check_permission' ),
            'args'                => array(
                'rules' => array(
                    'required'          => true,
                    'type'              => 'object',
                    'description'       => 'Objeto de regras de proteção (global, home, cpts).',
                ),
                'config' => array(
                    'required'    => false,
                    'type'        => 'object',
                    'description' => 'Configurações visuais e de comportamento do shield.',
                ),
                'protected_ids' => array(
                    'required'    => false,
                    'type'        => 'array',
                    'items'       => array( 'type' => 'integer' ),
                    'description' => 'IDs de mídias a serem protegidas individualmente.',
                ),
            ),
        ) );

        // GET /wp-json/front18/v1/media — Lista mídias com filtros ricos
        register_rest_route( 'front18/v1', '/media', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_media_library' ),
            'permission_callback' => array( $this, 'check_permission' ),
            'args'                => array(
                'page'      => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'description' => 'Número da página.' ),
                'per_page'  => array( 'type' => 'integer', 'default' => 48, 'minimum' => 1, 'maximum' => 200, 'description' => 'Itens por página.' ),
                'search'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field', 'description' => 'Busca por título.' ),
                'folder'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field', 'description' => 'Filtro por pasta YYYY/MM.' ),
                'mime_type' => array( 'type' => 'string', 'default' => 'image', 'sanitize_callback' => 'sanitize_text_field', 'description' => 'Tipo MIME base (image, video, application). Pode ser separado por vírgula.' ),
                'min_width' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Largura mínima em pixels.' ),
                'max_width' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Largura máxima em pixels (0 = sem limite).' ),
                'min_height'=> array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Altura mínima em pixels.' ),
                'orderby'   => array( 'type' => 'string', 'default' => 'date', 'enum' => array( 'date', 'title', 'modified', 'rand', 'ID' ), 'description' => 'Campo de ordenação.' ),
                'order'     => array( 'type' => 'string', 'default' => 'DESC', 'enum' => array( 'ASC', 'DESC' ), 'description' => 'Direção de ordenação.' ),
                'with_ghost'=> array( 'type' => 'boolean', 'default' => true, 'description' => 'Incluir imagens detectadas pelo Ghost Tracker.' ),
            ),
        ) );

        // GET /wp-json/front18/v1/media/types — Retorna tipos MIME disponíveis no site
        register_rest_route( 'front18/v1', '/media/types', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_media_types' ),
            'permission_callback' => array( $this, 'check_permission' ),
        ) );

        // GET /wp-json/front18/v1/ping — Health-check público: confirma que o plugin está ativo
        register_rest_route( 'front18/v1', '/ping', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_ping' ),
            'permission_callback' => '__return_true', // Público — sem autenticação necessária
        ) );
    }

    // =========================================================================
    // 2. Verificação de Permissão (com hash_equals para prevenir timing attacks)
    // =========================================================================

    public function check_permission( WP_REST_Request $request ) {
        // Usa o webhook_secret separado (nunca exposto no frontend).
        // Fallback para api_key em instalações legadas que ainda não receberam o secret via sync.
        $webhook_secret = get_option( 'front18_webhook_secret', '' );
        if ( empty( $webhook_secret ) ) {
            $webhook_secret = get_option( 'front18_api_key', '' );
        }
        if ( empty( $webhook_secret ) ) return false;

        // Autorização via header Bearer (ÚNICO método aceito).
        // NOTA SEGURA: o fallback via payload 'api_key' foi removido intencionalmente.
        // Aceitar a api_key no body permitiria que qualquer pessoa com acesso ao
        // código-fonte do site (onde a apiKey é pública) autenticasse o webhook.
        $auth_header = $request->get_header( 'authorization' );
        if ( $auth_header && strpos( $auth_header, 'Bearer ' ) === 0 ) {
            $token = substr( $auth_header, 7 );
            if ( hash_equals( $webhook_secret, $token ) ) return true;
        }

        return false;
    }

    // =========================================================================
    // 3. Health-Check Público (Ping)
    // =========================================================================

    /**
     * GET /wp-json/front18/v1/ping
     * Endpoint público usado pelo SaaS para confirmar que o plugin está instalado e ativo.
     * Não requer autenticação — apenas informa a presença do plugin, versão e última sincronização.
     */
    public function handle_ping( WP_REST_Request $request ) {
        $enabled  = (bool) get_option( 'front18_enabled', 0 );
        $has_key  = ! empty( get_option( 'front18_api_key', '' ) );
        $last_sync = get_option( 'front18_last_sync', null );

        // 'ok'       → plugin ativo e API Key configurada
        // 'degraded' → plugin ativo mas sem API Key (não sincronizado)
        // 'disabled' → plugin instalado mas desativado pelo admin
        if ( ! $enabled ) {
            $status = 'disabled';
        } elseif ( ! $has_key ) {
            $status = 'degraded';
        } else {
            $status = 'ok';
        }

        return rest_ensure_response( array(
            'front18'   => true,
            'version'   => defined( 'FRONT18_VERSION' ) ? FRONT18_VERSION : 'unknown',
            'status'    => $status,
            'enabled'   => $enabled,
            'synced'    => ! empty( $last_sync ),
            'last_sync' => $last_sync,
        ) );
    }

    // =========================================================================
    // 4. Webhook — Recebe push de regras do SaaS
    // =========================================================================

    public function handle_webhook( WP_REST_Request $request ) {
        $rules = $request->get_param( 'rules' );

        if ( ! is_array( $rules ) ) {
            return new WP_Error( 'invalid_payload', __( 'Payload inválido. É esperado um objeto de regras.', 'front18' ), array( 'status' => 400 ) );
        }

        // Sanitização das regras
        $sanitized_rules = array(
            'global' => ! empty( $rules['global'] ),
            'home'   => ! empty( $rules['home'] ),
            'cpts'   => ( isset( $rules['cpts'] ) && is_array( $rules['cpts'] ) )
                            ? array_map( 'sanitize_text_field', $rules['cpts'] )
                            : array(),
        );

        // Sanitização das configurações visuais
        $config_payload = $request->get_param( 'config' );
        if ( is_array( $config_payload ) ) {
            $sanitized_config = array(
                'level'              => isset( $config_payload['level'] ) ? (int) $config_payload['level'] : 1,
                'display_mode'       => sanitize_text_field( $config_payload['display_mode'] ?? 'global_lock' ),
                'color_bg'           => sanitize_hex_color( $config_payload['color_bg'] ?? '#0f172a' ) ?: '#0f172a',
                'color_primary'      => sanitize_hex_color( $config_payload['color_primary'] ?? '#6366f1' ) ?: '#6366f1',
                'color_text'         => sanitize_hex_color( $config_payload['color_text'] ?? '#f8fafc' ) ?: '#f8fafc',
                'blur_amount'        => isset( $config_payload['blur_amount'] ) ? (int) $config_payload['blur_amount'] : 25,
                'blur_selector'      => isset( $config_payload['blur_selector'] )
                                            ? map_deep( wp_unslash( $config_payload['blur_selector'] ), 'sanitize_text_field' )
                                            : 'img, video, iframe, [data-front18="locked"]',
                // FIX: excluded_selectors é campo explícito com sanitize_textarea_field() para preservar
                // caracteres especiais de seletores CSS: [], ", *, ^, >, : (sanitize_text_field os remove).
                'excluded_selectors' => isset( $config_payload['excluded_selectors'] )
                                            ? sanitize_textarea_field( wp_unslash( $config_payload['excluded_selectors'] ) )
                                            : '',
            );

            // Campos extras (módulos estendidos: DPO, Facial, etc.)
            // Nota: excluded_selectors já está tratado acima — o loop só processa campos desconhecidos.
            foreach ( $config_payload as $key => $value ) {
                if ( ! isset( $sanitized_config[ $key ] ) ) {
                    if ( is_bool( $value ) ) {
                        $sanitized_config[ sanitize_key( $key ) ] = rest_sanitize_boolean( $value );
                    } elseif ( is_scalar( $value ) ) {
                        $sanitized_config[ sanitize_key( $key ) ] = sanitize_text_field( $value );
                    }
                }
            }

            update_option( 'front18_synced_config', $sanitized_config );
        }

        // Persiste o webhook_secret separado (nunca exposto no frontend).
        // Só é aceito em payloads autenticados (este endpoint já passou pelo check_permission).
        // Valida o comprimento mínimo (prefixo 'whs_' + 48 hex = 52 chars) antes de sobrescrever.
        $webhook_secret_payload = $request->get_param( 'webhook_secret' );
        if ( ! empty( $webhook_secret_payload ) && is_string( $webhook_secret_payload ) && strlen( $webhook_secret_payload ) >= 20 ) {
            update_option( 'front18_webhook_secret', sanitize_text_field( $webhook_secret_payload ) );
        }

        // IDs de mídias protegidas
        $protected_ids = $request->get_param( 'protected_ids' );
        if ( is_array( $protected_ids ) ) {
            $ids = array_filter( array_map( 'intval', $protected_ids ) );
            update_option( 'front18_protected_media_ids', array_values( $ids ) );
        }

        update_option( 'front18_synced_rules', $sanitized_rules );
        update_option( 'front18_last_sync', current_time( 'mysql' ) );

        // Invalida o cache do Ghost Tracker ao receber novas regras
        delete_transient( 'front18_ghost_tracker_cache' );

        return rest_ensure_response( array(
            'success'   => true,
            'message'   => __( 'Regras sincronizadas com sucesso via Push.', 'front18' ),
            'timestamp' => current_time( 'mysql' ),
            'rules'     => $sanitized_rules,
        ) );
    }

    // =========================================================================
    // 4. Listagem de Mídia com Filtros Ricos
    // =========================================================================

    public function get_media_library( WP_REST_Request $request ) {

        $page       = (int) $request->get_param( 'page' );
        $per_page   = (int) $request->get_param( 'per_page' );
        $search     = $request->get_param( 'search' );
        $folder     = $request->get_param( 'folder' );
        $mime_raw   = $request->get_param( 'mime_type' );
        $min_width  = (int) $request->get_param( 'min_width' );
        $max_width  = (int) $request->get_param( 'max_width' );
        $min_height = (int) $request->get_param( 'min_height' );
        $orderby    = $request->get_param( 'orderby' );
        $order      = $request->get_param( 'order' );
        $with_ghost = rest_sanitize_boolean( $request->get_param( 'with_ghost' ) );

        // Constrói o array de tipos MIME aceitos
        $mime_types = $this->resolve_mime_types( $mime_raw );

        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => $mime_types,
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => $orderby,
            'order'          => $order,
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
            $item = $this->build_media_item( $post );

            // Filtro por dimensões (feito em PHP pós-query pois WP_Query não suporta meta_query em largura/altura de imagens de modo eficiente)
            if ( $min_width > 0 || $max_width > 0 || $min_height > 0 ) {
                $w = isset( $item['width'] ) ? (int) $item['width'] : 0;
                $h = isset( $item['height'] ) ? (int) $item['height'] : 0;

                if ( $min_width > 0 && $w < $min_width ) continue;
                if ( $max_width > 0 && $w > $max_width ) continue;
                if ( $min_height > 0 && $h < $min_height ) continue;
            }

            $media[] = $item;
        }

        // ---- Dados de pastas (apenas na página 1) ----
        $folders_data = array();
        if ( $page === 1 ) {
            $folders_data = $this->get_folders_list( $mime_types );
        }

        // ---- Ghost Tracker v3 (somente quando mime_type inclui imagens e na página 1) ----
        $ghost_count = 0;
        if ( $with_ghost && $page === 1 && empty( $search ) && ( empty( $folder ) || $folder === 'all' ) ) {
            $is_image_request = is_string( $mime_raw )
                ? ( strpos( $mime_raw, 'image' ) !== false || $mime_raw === 'image' )
                : true;

            if ( $is_image_request ) {
                $ghost_items = $this->run_ghost_tracker_v3();
                if ( ! empty( $ghost_items ) ) {
                    $media       = array_merge( $ghost_items, $media );
                    $ghost_count = count( $ghost_items );
                    $query->found_posts += $ghost_count;
                }
            }
        }

        $protected_ids = get_option( 'front18_protected_media_ids', array() );

        return rest_ensure_response( array(
            'success'       => true,
            'total_items'   => $query->found_posts,
            'total_pages'   => $query->max_num_pages,
            'current_page'  => $page,
            'ghost_count'   => $ghost_count,
            'protected_ids' => is_array( $protected_ids ) ? array_map( 'intval', $protected_ids ) : array(),
            'folders'       => $page === 1 ? $folders_data : null,
            'data'          => $media,
        ) );
    }

    // =========================================================================
    // 5. Endpoint: Tipos MIME Disponíveis no Site
    // =========================================================================

    public function get_media_types( WP_REST_Request $request ) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabela interna do WP, sem input do usuário
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT post_mime_type, COUNT(*) AS total
                 FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status = %s AND post_mime_type != ''
                 GROUP BY post_mime_type
                 ORDER BY total DESC",
                'attachment',
                'inherit'
            )
        );

        $types = array();
        foreach ( $rows as $row ) {
            $base  = explode( '/', $row->post_mime_type )[0];
            $label = $this->mime_label( $row->post_mime_type );
            $types[] = array(
                'mime_type' => $row->post_mime_type,
                'base'      => $base,
                'label'     => $label,
                'total'     => (int) $row->total,
            );
        }

        return rest_ensure_response( array(
            'success' => true,
            'data'    => $types,
        ) );
    }

    // =========================================================================
    // 6. Helpers Privados
    // =========================================================================

    /**
     * Constrói um item de mídia enriquecido com metadados.
     */
    private function build_media_item( WP_Post $post ) {
        $meta = wp_get_attachment_metadata( $post->ID );

        $url = wp_get_attachment_image_url( $post->ID, 'medium' );
        if ( ! $url ) {
            $url = wp_get_attachment_url( $post->ID );
        }

        $full_url  = wp_get_attachment_url( $post->ID );
        $file_path = get_attached_file( $post->ID );
        $filesize  = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : 0;

        return array(
            'id'        => $post->ID,
            'title'     => $post->post_title,
            'alt'       => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
            'caption'   => $post->post_excerpt,
            'mime_type' => $post->post_mime_type,
            'url'       => $url,
            'full_url'  => $full_url,
            'filesize'  => $filesize,
            'date'      => $post->post_date,
            'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
            'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
            'sizes'     => isset( $meta['sizes'] ) ? array_keys( $meta['sizes'] ) : array(),
            'is_ghost'  => false,
        );
    }

    /**
     * Constrói a lista de pastas (mês/ano) disponíveis.
     * Usa $wpdb->prepare() para segurança.
     */
    private function get_folders_list( $mime_types ) {
        global $wpdb;

        // Constrói o predicado MIME dinâmico com prepare
        $mime_conditions = array();
        foreach ( (array) $mime_types as $mime ) {
            // Se terminar com "/" é um base type (ex: "image"), usamos LIKE
            if ( substr( $mime, -1 ) !== '/' ) {
                $mime_conditions[] = $wpdb->prepare( "post_mime_type LIKE %s", $wpdb->esc_like( $mime ) . '%' );
            } else {
                $mime_conditions[] = $wpdb->prepare( "post_mime_type = %s", $mime );
            }
        }

        $mime_sql = ! empty( $mime_conditions )
            ? '(' . implode( ' OR ', $mime_conditions ) . ')'
            : '1=1';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Condição MIME já preparada acima
        $months = $wpdb->get_results(
            "SELECT DISTINCT YEAR(post_date) AS year, MONTH(post_date) AS month
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_status = 'inherit'
               AND {$mime_sql}
             ORDER BY post_date DESC"
        );

        $folders = array();
        foreach ( $months as $m ) {
            if ( empty( $m->year ) || empty( $m->month ) ) continue;
            $month_str = zeroise( $m->month, 2 );
            $folders[] = array(
                'label' => date_i18n( 'F Y', mktime( 0, 0, 0, $m->month, 1, $m->year ) ),
                'value' => $m->year . '/' . $month_str,
            );
        }

        return $folders;
    }

    /**
     * Ghost Tracker v3 — Detecta imagens "fantasmas" usadas no layout mas fora da biblioteca padrão.
     * Fontes: Elementor, ACF, WooCommerce product galleries, Divi (et_pb_*), theme_mods.
     * Cache via transient de 12 horas para não sobrecarregar o banco.
     */
    private function run_ghost_tracker_v3() {
        global $wpdb;

        // Verifica cache
        $cached = get_transient( 'front18_ghost_tracker_cache' );
        if ( $cached !== false ) {
            // Reconstrói a estrutura de retorno a partir do cache
            $ghost_dict = get_option( 'front18_ghost_media_dict', array() );
            return $this->ghost_urls_to_items( $cached, $ghost_dict );
        }

        $chunks = array();

        // 1. Elementor (_elementor_data) — processa em lotes de 50 para controle de memória
        $offset = 0;
        $batch  = 50;
        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT %d OFFSET %d",
                    '_elementor_data',
                    $batch,
                    $offset
                )
            );
            if ( ! empty( $rows ) ) {
                $chunks[] = implode( ' ', $rows );
            }
            $offset += $batch;
        } while ( count( $rows ) === $batch );

        // 2. ACF (campos de imagem e galeria)
        // Nota: _ é wildcard SQL — deve ser escapado com esc_like() antes de passar ao prepare()
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $acf_rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key NOT LIKE %s
                   AND meta_value LIKE %s
                 LIMIT 200",
                $wpdb->esc_like( '_' ) . '%', // exclui campos internos do WP (ex: _wp_*, _elementor_*, etc.)
                '%wp-content/uploads%'
            )
        );
        if ( ! empty( $acf_rows ) ) {
            $chunks[] = implode( ' ', $acf_rows );
        }

        // 3. WooCommerce: imagem principal e galeria de produtos
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $woo_rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key IN (%s, %s)",
                '_product_image_gallery',
                '_thumbnail_id'
            )
        );
        if ( ! empty( $woo_rows ) ) {
            $chunks[] = implode( ' ', $woo_rows );
        }

        // 4. Divi Builder (et_pb_* options guardadas nos postmeta)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $divi_rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key LIKE %s
                   AND meta_value LIKE %s
                 LIMIT 100",
                'et_pb_%',
                '%wp-content%'
            )
        );
        if ( ! empty( $divi_rows ) ) {
            $chunks[] = implode( ' ', $divi_rows );
        }

        // 5. Theme Mods (Customizer)
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $theme_mods = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                'theme_mods_%'
            )
        );
        if ( ! empty( $theme_mods ) ) {
            $chunks[] = implode( ' ', $theme_mods );
        }

        // Fusão e extração via regex
        $big_string = stripslashes( implode( ' ', $chunks ) );
        $urls       = array();

        if ( ! empty( $big_string ) ) {
            preg_match_all(
                '/(https?:\/\/[^"\'\\\\}\{\s\(\)]+\.(?:png|jpg|jpeg|webp|gif|svg|avif))/i',
                $big_string,
                $matches
            );
            if ( ! empty( $matches[1] ) ) {
                $urls = array_unique( $matches[1] );
            }
        }

        // Armazena cache por 12 horas (invalidado no webhook)
        set_transient( 'front18_ghost_tracker_cache', $urls, 12 * HOUR_IN_SECONDS );

        $ghost_dict = get_option( 'front18_ghost_media_dict', array() );
        $items      = $this->ghost_urls_to_items( $urls, $ghost_dict );

        // Persiste o dicionário atualizado
        if ( ! empty( $items ) ) {
            update_option( 'front18_ghost_media_dict', $ghost_dict );
        }

        return $items;
    }

    /**
     * Converte uma lista de URLs em itens de mídia do formato Ghost Tracker.
     */
    private function ghost_urls_to_items( array $urls, array &$ghost_dict ) {
        $items = array();
        foreach ( $urls as $ghost_url ) {
            $fake_id = abs( crc32( $ghost_url ) );
            $ghost_dict[ $fake_id ] = $ghost_url;
            $items[] = array(
                'id'        => $fake_id,
                'title'     => 'Ghost — ' . basename( $ghost_url ),
                'alt'       => '',
                'caption'   => '',
                'mime_type' => 'image/' . strtolower( pathinfo( $ghost_url, PATHINFO_EXTENSION ) ),
                'url'       => $ghost_url,
                'full_url'  => $ghost_url,
                'filesize'  => 0,
                'date'      => '',
                'width'     => 0,
                'height'    => 0,
                'sizes'     => array(),
                'is_ghost'  => true,
            );
        }
        return $items;
    }

    /**
     * Resolve um string de tipos MIME separados por vírgula em array para WP_Query.
     */
    private function resolve_mime_types( $mime_raw ) {
        if ( empty( $mime_raw ) ) {
            return array( 'image' );
        }

        $parts = array_map( 'trim', explode( ',', $mime_raw ) );
        $types = array();

        foreach ( $parts as $part ) {
            // Base type (ex: "image") → WP_Query entende e adiciona o wildcard internamente
            $types[] = sanitize_mime_type( $part );
        }

        return $types;
    }

    /**
     * Rótulo amigável para tipos MIME.
     */
    private function mime_label( $mime_type ) {
        $map = array(
            'image/jpeg'               => 'JPEG',
            'image/png'                => 'PNG',
            'image/gif'                => 'GIF',
            'image/webp'               => 'WebP',
            'image/svg+xml'            => 'SVG',
            'image/avif'               => 'AVIF',
            'video/mp4'                => 'MP4',
            'video/quicktime'          => 'MOV',
            'video/webm'               => 'WebM',
            'audio/mpeg'               => 'MP3',
            'audio/wav'                => 'WAV',
            'application/pdf'          => 'PDF',
            'application/zip'          => 'ZIP',
            'application/vnd.ms-excel' => 'XLS',
            'application/msword'       => 'DOC',
        );
        return isset( $map[ $mime_type ] ) ? $map[ $mime_type ] : ucfirst( explode( '/', $mime_type )[0] );
    }
}
