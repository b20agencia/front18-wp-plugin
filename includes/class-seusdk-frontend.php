<?php

/**
 * Front18_Frontend — Injeção de scripts e CSS no front-end do site.
 *
 * Versão: 1.1.0
 * Novidades:
 *  - Propriedades privadas de cache para get_option() (sem chamadas duplicadas)
 *  - update_option() removido da _calculate_scope() — migração movida para hook de upgrade
 *  - Cache buster gerado via PHP com gmdate('Ymd') em vez de JavaScript
 *  - Lógica de escopo extraída do _calculate_scope em métodos pequenos e legíveis
 */
class Front18_Frontend {

    // =========================================================================
    // Cache interno de options (carregados 1x por request)
    // =========================================================================

    /** @var bool|null */
    private $should_run_cached = null;

    /** @var array */
    private $synced_config = null;

    /** @var array */
    private $synced_rules = null;

    /** @var array */
    private $protected_media = null;

    // =========================================================================
    // Inicialização
    // =========================================================================

    public function init() {
        add_action( 'wp_head', array( $this, 'inject_anti_flicker' ), 0 );
        add_action( 'wp_head', array( $this, 'inject_sdk_loader' ), 1 );
        add_shortcode( 'front18',      array( $this, 'render_shortcode' ) );
        add_shortcode( 'front18_lock', array( $this, 'render_lock_shortcode' ) );
        add_filter( 'language_attributes', array( $this, 'inject_html_class' ), 99 );
    }

    // =========================================================================
    // Acessores internos com cache (cada option é buscada 1x por request)
    // =========================================================================

    private function get_synced_config() {
        if ( $this->synced_config === null ) {
            $this->synced_config = get_option( 'front18_synced_config', array() );
        }
        return $this->synced_config;
    }

    private function get_synced_rules() {
        if ( $this->synced_rules === null ) {
            $this->synced_rules = get_option( 'front18_synced_rules', false );
        }
        return $this->synced_rules;
    }

    private function get_protected_media() {
        if ( $this->protected_media === null ) {
            $this->protected_media = get_option( 'front18_protected_media_ids', array() );
            if ( ! is_array( $this->protected_media ) ) {
                $this->protected_media = array();
            }
        }
        return $this->protected_media;
    }

    // =========================================================================
    // Anti-Flicker & Injeção HTML
    // =========================================================================

    public function inject_html_class( $attributes ) {
        if ( ! $this->should_run() ) return $attributes;

        $config       = $this->get_synced_config();
        $display_mode = ! empty( $config['display_mode'] ) ? $config['display_mode'] : 'global_lock';

        $payload = $attributes . ' class="front18-hide"';

        // Blindagem Nuclear Zero-Ms contra Plugins de Cache (WP Rocket / LiteSpeed CSS Defer)
        if ( $display_mode !== 'granular' && $display_mode !== 'blur_media' ) {
            $payload .= ' style="opacity: 0.01 !important; pointer-events: none !important;"';
        }

        return $payload;
    }

    public function inject_anti_flicker() {
        if ( ! $this->should_run() ) return;

        $config           = $this->get_synced_config();
        $protected_ids    = $this->get_protected_media();

        $display_mode     = ! empty( $config['display_mode'] ) ? $config['display_mode'] : 'global_lock';
        $color_bg         = ! empty( $config['color_bg'] )     ? $config['color_bg']     : '#0f172a';
        $blur_amount      = isset( $config['blur_amount'] )    ? (int) $config['blur_amount']  : 25;
        $blur_selector    = ! empty( $config['blur_selector'] ) ? $config['blur_selector'] : 'img, video, iframe, [data-front18="locked"]';
        $protection_level = isset( $config['level'] )          ? (int) $config['level']        : 1;

        // Seletores excluídos do blur (AdSense, rodapé, iframes utilitários do Google, etc.)
        // IMPORTANTE: lista hardcoded garante proteção mesmo que o banco ainda tenha valor corrompido.
        $adsense_hardcoded = [
            'ins.adsbygoogle',
            '.adsbygoogle',
            'iframe[src*="googlesyndication.com"]',
            'iframe[src*="doubleclick.net"]',
            '[id^="google_ads"]',
            '.google-auto-placed',
            'iframe[src*="fundingchoicesmessages.google.com"]',
            'iframe[src*="google.com/recaptcha"]',
            'iframe[src*="google.com/search"]',
            'footer iframe',
            '.site-footer iframe',
            '.footer iframe',
            'body > iframe[style*="display:none"]',
            'body > iframe[style*="display: none"]',
            'body > iframe[src="about:blank"]',
            'body > iframe:not([src])',
            '[id^="aswift_"]',
            '[id^="google_ads_iframe_"]',
            '.adsbygoogle-noablate',
        ];

        // Mescla os seletores do banco (customizados pelo usuário) com os hardcoded do AdSense.
        // Prioridade: banco > hardcoded. Se o banco estiver vazio/corrompido, usa só os hardcoded.
        $from_db_raw = ! empty( $config['excluded_selectors'] ) ? $config['excluded_selectors'] : '';
        // Normaliza whitespace interno dos seletores (ex: "footer  iframe" -> "footer iframe")
        // para evitar duplicatas ao comparar com a lista hardcoded.
        $from_db = array_filter( array_map( function( $s ) {
            return preg_replace( '/\s+/', ' ', trim( $s ) );
        }, explode( ',', $from_db_raw ) ) );

        // Adiciona os hardcoded que ainda não estão na lista do banco
        foreach ( $adsense_hardcoded as $hc ) {
            $already = false;
            $hc_norm = preg_replace( '/\s+/', ' ', $hc ); // normaliza o hardcoded também
            foreach ( $from_db as $db_sel ) {
                if ( strcasecmp( $db_sel, $hc_norm ) === 0 ) { $already = true; break; }
            }
            if ( ! $already ) $from_db[] = $hc_norm;
        }

        $excluded_selectors_raw = implode( ', ', $from_db );

        // Formata seletores de exclusão com prefixo html.front18-hide
        $formatted_exclusions = implode( ', ', array_map( function( $sel ) {
            return 'html.front18-hide ' . trim( $sel );
        }, array_filter( array_map( 'trim', explode( ',', $excluded_selectors_raw ) ), function( $s ) {
            return ! empty( $s );
        } ) ) );

        $locked_tag_selector = 'html.front18-hide [data-front18="locked"], html.front18-hide .front18-locked';

        // Arquitetura híbrida: seletor genérico + granularidade
        $formatted_selectors = implode( ', ', array_map( function( $sel ) {
            return 'html.front18-hide ' . trim( $sel );
        }, explode( ',', $blur_selector ) ) );

        if ( empty( $formatted_selectors ) ) {
            $formatted_selectors = 'html.front18-hide img, html.front18-hide video, html.front18-hide iframe, html.front18-hide .e-con, html.front18-hide .elementor-section, ' . $locked_tag_selector;
        } else {
            $formatted_selectors .= ', ' . $locked_tag_selector . ', html.front18-hide .e-con, html.front18-hide .elementor-section';
        }

        if ( ! empty( $protected_ids ) ) {
            // Camada 1: seletores por classe WordPress (imagens inseridas via editor)
            $class_selectors = implode( ', ', array_map( function( $id ) {
                return 'html.front18-hide .wp-image-' . (int) $id . ', html.front18-hide .attachment-' . (int) $id;
            }, $protected_ids ) );

            // Camada 2: seletores por src (imagens de tema, widgets, Elementor, carousels)
            // Usa o resolve_protected_urls para pegar os filenames sem dimensões (ex: foto-scaled ao inves de foto-300x200)
            $protected_urls  = $this->resolve_protected_urls( $protected_ids );
            $src_selectors   = '';
            if ( ! empty( $protected_urls ) ) {
                $src_selectors = ', ' . implode( ', ', array_map( function( $name ) {
                    // Escapa aspas e caracteres especiais do CSS
                    $safe = addslashes( $name );
                    return 'html.front18-hide img[src*="' . $safe . '"]';
                }, $protected_urls ) );
            }

            $formatted_selectors .= ', ' . $class_selectors . $src_selectors;
        }
        ?>
        <!-- FRONT18: ANTI-FLICKER EXTREMO (BLINDADO CONTRA MINIFICAÇÃO) -->
        <style id="front18-antiflicker-css" data-rocket-exclude="true" data-no-optimize="1" data-no-minify="1" data-cfasync="false">
            <?php if ( $display_mode === 'granular' || $display_mode === 'blur_media' ): ?>
            <?php if ( $protection_level === 2 || $protection_level === 3 ): ?>
            html.front18-hide {
                background-color: <?php echo esc_attr( $color_bg ); ?> !important;
                transition: background-color 0.3s ease;
            }
            <?php endif; ?>
            html.front18-hide body {
                pointer-events: none !important;
                overflow-x: hidden !important;
            }
            <?php echo $formatted_selectors; ?> {
                <?php if ( $protection_level === 3 ): ?>
                opacity: 0 !important; display: none !important;
                <?php elseif ( $protection_level === 2 ): ?>
                opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; user-select: none !important;
                <?php else: ?>
                filter: blur(<?php echo $blur_amount; ?>px) grayscale(100%) !important;
                user-select: none !important;
                -webkit-user-drag: none !important;
                <?php endif; ?>
            }
            <?php if ( ! empty( $formatted_exclusions ) ) : ?>
            /* ===== FRONT18: SELETORES EXCLUÍDOS (Anti-Ban AdSense + Rodapé) ===== */
            <?php echo $formatted_exclusions; ?> {
                filter: none !important;
                opacity: 1 !important;
                visibility: visible !important;
                display: revert !important;
                pointer-events: auto !important;
                backdrop-filter: none !important;
                -webkit-filter: none !important;
            }
            /* Iframes ocultos do Google injetados no body: consent, tracking, reCAPTCHA.
               NOTA: Não usa 'body > iframe' genérico para não liberar iframes de vídeo/paywall. */
            html.front18-hide body > iframe[src*="google"],
            html.front18-hide body > iframe[src*="googlesyndication"],
            html.front18-hide body > iframe[src*="doubleclick"],
            html.front18-hide body > iframe[src*="fundingchoicesmessages"],
            html.front18-hide body > iframe[src="about:blank"][style*="display:none"],
            html.front18-hide body > iframe[src="about:blank"][style*="display: none"],
            html.front18-hide body > iframe:not([src])[style*="display:none"],
            html.front18-hide body > iframe:not([src])[style*="display: none"],
            html.front18-hide body > div[id*="google"] > iframe,
            html.front18-hide body > div[class*="google"] > iframe {
                filter: none !important;
                opacity: 1 !important;
                visibility: visible !important;
                pointer-events: auto !important;
            }
            <?php endif; ?>
            <?php else: ?>
            html.front18-hide {
                background-color: <?php echo esc_attr( $color_bg ); ?> !important;
                transition: background-color 0.3s ease;
            }
            html.front18-hide body {
                opacity: 0 !important;
                visibility: hidden !important;
                pointer-events: none !important;
                overflow: hidden !important;
                touch-action: none !important;
            }
            <?php endif; ?>
            <?php if ( ! empty( $formatted_exclusions ) ) : ?>
            /* ===== FRONT18: SELETORES EXCLUÍDOS - GLOBAL LOCK (Anti-Ban AdSense) ===== */
            <?php echo $formatted_exclusions; ?> {
                filter: none !important;
                opacity: 1 !important;
                visibility: visible !important;
                display: revert !important;
                backdrop-filter: none !important;
                -webkit-filter: none !important;
            }
            /* Iframes ocultos do Google no body (consent, tracking) — também no modo global_lock */
            html.front18-hide body > iframe[src*="google"],
            html.front18-hide body > iframe[src*="googlesyndication"],
            html.front18-hide body > iframe[src*="doubleclick"],
            html.front18-hide body > iframe[src*="fundingchoicesmessages"],
            html.front18-hide body > iframe[src="about:blank"][style*="display:none"],
            html.front18-hide body > iframe[src="about:blank"][style*="display: none"],
            html.front18-hide body > iframe:not([src])[style*="display:none"],
            html.front18-hide body > iframe:not([src])[style*="display: none"],
            html.front18-hide body > div[id*="google"] > iframe,
            html.front18-hide body > div[class*="google"] > iframe {
                filter: none !important;
                opacity: 1 !important;
                visibility: visible !important;
                pointer-events: auto !important;
            }
            <?php endif; ?>
        </style>

        <script data-no-optimize="1" data-no-minify="1" data-cfasync="false" data-pagespeed-no-defer="1">
            (function(){
                if (!window.__front18_anti_flicker) {
                    window.__front18_anti_flicker = true;
                    document.documentElement.classList.add('front18-hide');
                }
            })();
        </script>

        <noscript>
            <style data-no-optimize="1" data-no-minify="1">
                html.front18-hide { opacity: 1 !important; }
                html.front18-hide body { pointer-events: auto !important; overflow: auto !important; touch-action: auto !important; }
            </style>
        </noscript>
        <?php
    }

    public function inject_sdk_loader() {
        if ( ! $this->should_run() ) return;

        $api_key       = get_option( 'front18_api_key', '' );
        $sdk_url       = get_option( 'front18_sdk_url', 'https://front18.com/public/sdk/front18.js' );
        $global_object = get_option( 'front18_global_object', 'Front18' );
        $token_key     = get_option( 'front18_token_key', 'api-key' );
        $debug_mode    = get_option( 'front18_debug_mode', false );

        $config        = $this->get_synced_config();
        $protected_ids = $this->get_protected_media();

        $display_mode  = ! empty( $config['display_mode'] ) ? $config['display_mode'] : 'global_lock';
        $blur_amount   = isset( $config['blur_amount'] ) ? (int) $config['blur_amount'] : 25;
        $blur_selector = ! empty( $config['blur_selector'] ) ? $config['blur_selector'] : 'img, video, iframe, [data-front18="locked"]';
        $color_bg      = ! empty( $config['color_bg'] )      ? $config['color_bg']      : '#0f172a';
        $color_text    = ! empty( $config['color_text'] )    ? $config['color_text']    : '#f8fafc';
        $color_primary = ! empty( $config['color_primary'] ) ? $config['color_primary'] : '#6366f1';

        $parsed_url     = wp_parse_url( $sdk_url );
        $preconnect_url = ( $parsed_url && isset( $parsed_url['scheme'], $parsed_url['host'] ) )
                            ? $parsed_url['scheme'] . '://' . $parsed_url['host']
                            : 'https://front18.com';

        // Cache buster gerado no servidor (estável e consistente entre múltiplos servidores/cluster)
        $cache_buster = substr( $api_key, 0, 5 ) . '_d' . gmdate( 'Ymd' );

        // URLs protegidas (resolve ghost dict para IDs sem URL na biblioteca do WP)
        $synced_rules   = $this->get_synced_rules();
        $protected_urls = $this->resolve_protected_urls( $protected_ids );
        ?>
        <!-- FRONT18: PRECONNECT -->
        <link rel="preconnect" href="<?php echo esc_url( $preconnect_url ); ?>">
        <link rel="dns-prefetch" href="<?php echo esc_url( $preconnect_url ); ?>">

        <!-- FRONT18: CARREGADOR E METADADOS -->
        <script data-no-optimize="1" data-no-minify="1" data-cfasync="false" data-pagespeed-no-defer="1">
            if (!window.__front18_loaded__) {
                window.__front18_loaded__ = Date.now();

                window.__front18_state__ = Object.assign({
                    locked: true,
                    released: false,
                    releaseReason: null,
                    startedAt: Date.now(),
                    sdkDetected: false,
                    sdkInitialized: false,
                    url: location.href,
                    userAgent: navigator.userAgent
                }, window.__front18_state__ || {});

                window.Front18Config = Object.assign({}, window.Front18Config || {}, {
                    apiKey: '<?php echo esc_js( $api_key ); ?>',
                    mode: '<?php echo esc_js( $display_mode ); ?>',
                    level: <?php echo isset( $config['level'] ) ? (int) $config['level'] : 1; ?>,
                    blur_amount: <?php echo (int) $blur_amount; ?>,
                    blur_selector: '<?php echo esc_js( $blur_selector ); ?>',
                    excluded_selectors: '<?php echo esc_js( ! empty( $config['excluded_selectors'] ) ? $config['excluded_selectors'] : 'ins.adsbygoogle, .adsbygoogle, iframe[src*="googlesyndication.com"], iframe[src*="doubleclick.net"], [id^="google_ads"], .google-auto-placed, footer iframe, .site-footer iframe, .footer iframe, [id^="aswift_"], [id^="google_ads_iframe_"], .adsbygoogle-noablate' ); ?>',
                    theme: {
                        bg: '<?php echo esc_js( $color_bg ); ?>',
                        primary: '<?php echo esc_js( $color_primary ); ?>',
                        text: '<?php echo esc_js( $color_text ); ?>'
                    },
                    preventScroll: true,
                    whitelistRoutes: [],
                    protectRoutes: ['*'],
                    protected_media_ids: <?php echo wp_json_encode( array_map( 'intval', $protected_ids ) ); ?>,
                    protectedMediaNames: <?php echo wp_json_encode( $protected_urls ); ?>,
                    env: 'wordpress',
                    wpContext: {
                        postId: <?php echo intval( ( is_singular() && get_post() ) ? get_post()->ID : 0 ); ?>,
                        postType: '<?php echo esc_js( get_post_type() ? get_post_type() : '' ); ?>',
                        override: '<?php echo esc_js( ( is_singular() && get_post() ) ? ( get_post_meta( get_post()->ID, '_front18_protect', true ) ?: 'default' ) : 'default' ); ?>',
                        globalScope: <?php echo ! empty( $synced_rules['global'] ) ? 'true' : 'false'; ?>
                    }
                });

                <?php
                // Campos extras (módulos estendidos: DPO, Facial, etc.)
                $known_keys   = array( 'level', 'display_mode', 'color_bg', 'color_primary', 'color_text', 'blur_amount', 'blur_selector', 'excluded_selectors', 'protected_media_ids' );
                $extra_config = array();
                if ( is_array( $config ) ) {
                    foreach ( $config as $k => $v ) {
                        if ( ! in_array( $k, $known_keys, true ) ) {
                            $extra_config[ $k ] = $v;
                        }
                    }
                }
                if ( ! empty( $extra_config ) ) :
                ?>
                window.Front18Config = Object.assign(window.Front18Config, <?php echo wp_json_encode( $extra_config ); ?>);
                <?php endif; ?>

                function _front18Unlock(reason) {
                    if (!window.__front18_state__.locked) return;
                    document.documentElement.classList.remove('front18-hide');
                    window.__front18_state__.locked        = false;
                    window.__front18_state__.released      = true;
                    window.__front18_state__.releaseReason = reason;
                    window.__front18_state__.latency       = Date.now() - window.__front18_state__.startedAt;

                    var event;
                    if (typeof CustomEvent === 'function') {
                        event = new CustomEvent('front18:released', { detail: window.__front18_state__ });
                    } else {
                        event = document.createEvent('CustomEvent');
                        event.initCustomEvent('front18:released', true, true, window.__front18_state__);
                    }
                    document.dispatchEvent(event);
                }

                var sdkName      = '<?php echo esc_js( $global_object ); ?>' || 'Front18';
                var sdkReleaseFn = sdkName + 'Release';
                window[sdkReleaseFn] = window.Front18Release = function() {
                    _front18Unlock('sdk');
                    <?php if ( $debug_mode ) : ?>console.log(<?php echo wp_json_encode( __( '[Front18] DOM liberado pelo SDK em ', 'front18' ) ); ?> + window.__front18_state__.latency + 'ms');<?php endif; ?>
                };

                var maxTimeout = (navigator.connection && navigator.connection.effectiveType === '4g') ? 2000 : 3500;
                setTimeout(function() {
                    if (document.documentElement.classList.contains('front18-hide')) {
                        window.__front18_state__.failed = true;
                        if (!window.__front18_state__.sdkDetected) {
                            _front18Unlock('sdk_not_loaded');
                            <?php if ( $debug_mode ) : ?>console.warn(<?php echo wp_json_encode( __( '[Front18 Watchdog] CDN inalcançável. SDK não pôde ser baixado.', 'front18' ) ); ?>);<?php endif; ?>
                        } else if (!window.__front18_state__.sdkInitialized) {
                            _front18Unlock('sdk_fail');
                            <?php if ( $debug_mode ) : ?>console.warn(<?php echo wp_json_encode( __( '[Front18 Watchdog] SDK retornado mas com erro de inicialização.', 'front18' ) ); ?>);<?php endif; ?>
                        } else {
                            _front18Unlock('timeout');
                            <?php if ( $debug_mode ) : ?>console.warn(<?php echo wp_json_encode( __( '[Front18 Watchdog] Timeout adaptativo estourado.', 'front18' ) ); ?>);<?php endif; ?>
                        }
                    }
                }, maxTimeout);

                var sdkScript = document.createElement('script');
                sdkScript.src = '<?php echo esc_url( $sdk_url ); ?>?v=<?php echo esc_attr( $cache_buster ); ?>';
                sdkScript.setAttribute('data-cfasync', 'false');
                sdkScript.setAttribute('data-no-optimize', '1');
                sdkScript.setAttribute('data-auto-init', 'true');
                sdkScript.setAttribute('data-<?php echo esc_attr( strtolower( $token_key ) ); ?>', '<?php echo esc_attr( $api_key ); ?>');
                document.head.appendChild(sdkScript);
            }
        </script>
        <?php
    }

    // =========================================================================
    // Escopo — Deve injetar o SDK nesta página?
    // =========================================================================

    private function should_run() {
        if ( $this->should_run_cached !== null ) {
            return $this->should_run_cached;
        }
        $this->should_run_cached = $this->_calculate_scope();
        return $this->should_run_cached;
    }

    private function _calculate_scope() {
        if ( is_admin() ) return false;
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) return false;
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return false;
        if ( is_feed() ) return false;

        if ( ! get_option( 'front18_enabled', false ) ) return false;

        $api_key = get_option( 'front18_api_key', '' );
        if ( empty( $api_key ) ) return false;

        $current_post = get_post();
        $current_id   = ( is_singular() && $current_post ) ? $current_post->ID : 0;

        // Regra soberana: override por meta box da página
        if ( $current_id > 0 ) {
            $meta_override = get_post_meta( $current_id, '_front18_protect', true );
            if ( $meta_override === 'protect' )   return true;
            if ( $meta_override === 'unprotect' ) return false;
        }

        // Exclusão explícita por IDs
        $exclude_ids = get_option( 'front18_exclude_ids', '' );
        if ( ! empty( $exclude_ids ) && $current_id > 0 ) {
            $exclude_arr = array_map( 'intval', explode( ',', $exclude_ids ) );
            if ( in_array( $current_id, $exclude_arr, true ) ) return false;
        }

        // Inclusão explícita por IDs
        $include_ids = get_option( 'front18_include_ids', '' );
        if ( ! empty( $include_ids ) && $current_id > 0 ) {
            $include_arr = array_map( 'intval', explode( ',', $include_ids ) );
            if ( in_array( $current_id, $include_arr, true ) ) return true;
        }

        $config          = $this->get_synced_config();
        $display_mode    = sanitize_text_field( $config['display_mode'] ?? 'global_lock' );
        $protected_media = $this->get_protected_media();

        // Modo blur_media com imagens selecionadas: protege todo o site
        if ( ! empty( $protected_media ) && $display_mode === 'blur_media' ) {
            return true;
        }

        // Regras sincronizadas pelo SaaS
        $synced_rules = $this->get_synced_rules();

        // NOTA: Migração de versão legada foi movida para o hook upgrader_process_complete
        // no arquivo principal do plugin (seusdk-wp-plugin.php) para não executar em toda request.
        if ( $synced_rules === false ) {
            return false;
        }

        if ( ! empty( $synced_rules['global'] ) ) return true;

        if ( is_front_page() || is_home() ) {
            if ( ! empty( $synced_rules['home'] ) ) return true;
        }

        $cpts = isset( $synced_rules['cpts'] ) && is_array( $synced_rules['cpts'] ) ? $synced_rules['cpts'] : array();

        if ( is_singular() ) {
            $post_type = get_post_type();
            if ( in_array( $post_type, $cpts, true ) ) return true;
        } elseif ( is_archive() || is_post_type_archive() ) {
            $post_type = get_post_type();
            if ( $post_type && in_array( $post_type, $cpts, true ) ) return true;
        }

        return false;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Resolve URLs amigáveis a partir dos IDs de mídias protegidas.
     * Consulta o ghost dict para IDs sem URL na biblioteca do WP.
     */
    private function resolve_protected_urls( array $protected_ids ) {
        if ( empty( $protected_ids ) ) return array();

        $ghost_dict     = get_option( 'front18_ghost_media_dict', array() );
        $protected_urls = array();

        foreach ( $protected_ids as $id ) {
            $url = wp_get_attachment_url( $id );
            if ( ! $url && isset( $ghost_dict[ $id ] ) ) {
                $url = $ghost_dict[ $id ];
            }
            if ( $url ) {
                $filename   = basename( $url );
                $name_only  = pathinfo( $filename, PATHINFO_FILENAME );
                $base_name  = preg_replace( '/-[0-9]+x[0-9]+$/', '', $name_only );
                $protected_urls[] = $base_name;
            }
        }

        return array_values( array_unique( $protected_urls ) );
    }

    // =========================================================================
    // Shortcodes
    // =========================================================================

    public function render_shortcode( $atts ) {
        return '<div id="front18-inline" class="front18-sdk-rendered" style="display:none;"></div>';
    }

    public function render_lock_shortcode( $atts, $content = null ) {
        if ( is_null( $content ) || is_admin() ) return $content;
        return '<div class="Front18-lock" data-front18="locked">' . do_shortcode( $content ) . '</div>';
    }
}
